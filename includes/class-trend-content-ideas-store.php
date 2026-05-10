<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Store {
    const OPTION_GLOBAL_PROMPT = 'alma_trend_content_global_prompt';
    const OPTION_MODEL = 'alma_trend_content_model';
    const OPTION_TIMEOUT = 'alma_trend_content_timeout';
    const OPTION_LEGACY_MODEL_MIGRATION = 'alma_trend_content_legacy_model_migration_2323';
    const OPTION_MODEL_MANUAL = 'alma_trend_content_model_manual';

    public static function table($name) { global $wpdb; return $wpdb->prefix . 'alma_trend_content_' . $name; }

    public static function install() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('sources') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_key VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 1,
            max_contents_per_run TINYINT UNSIGNED NOT NULL DEFAULT 3,
            category VARCHAR(120) NOT NULL DEFAULT '',
            allowed_domains LONGTEXT NULL,
            interval_days INT UNSIGNED NOT NULL DEFAULT 7,
            last_run_at DATETIME NULL,
            next_run_at DATETIME NULL,
            custom_prompt LONGTEXT NULL,
            description TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY source_key (source_key), KEY enabled_next (enabled,next_run_at), KEY priority (priority)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('reports') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_type VARCHAR(30) NOT NULL DEFAULT 'scheduled',
            title VARCHAR(255) NOT NULL DEFAULT '',
            period_start DATETIME NULL,
            period_end DATETIME NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'success',
            summary LONGTEXT NULL,
            result_json LONGTEXT NULL,
            sources_json LONGTEXT NULL,
            metrics_json LONGTEXT NULL,
            model VARCHAR(120) NOT NULL DEFAULT '',
            tokens_used INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY type_created (report_type,created_at), KEY status_created (status,created_at)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_key VARCHAR(80) NOT NULL DEFAULT '',
            report_id BIGINT UNSIGNED NULL,
            run_type VARCHAR(30) NOT NULL DEFAULT 'scheduled',
            status VARCHAR(30) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            raw_error LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id), KEY source_started (source_key,started_at), KEY report_id (report_id), KEY status_started (status,started_at)
        ) $c;");
        self::seed_sources();
        self::normalize_existing_sources();
        if (get_option(self::OPTION_GLOBAL_PROMPT, null) === null) {
            update_option(self::OPTION_GLOBAL_PROMPT, ALMA_Trend_Content_Ideas_Prompt_Builder::default_global_prompt());
        }
        if (get_option(self::OPTION_TIMEOUT, null) === null) { update_option(self::OPTION_TIMEOUT, 90); }
        self::migrate_legacy_model_seed();
    }


    /**
     * Idempotently records that the historical gpt-5.5 Trend seed has been evaluated.
     *
     * The old installer wrote alma_trend_content_model=gpt-5.5 automatically. There is no
     * reliable marker to distinguish that seed from a pre-existing manual value, so the
     * migration deliberately does not delete the option. effective_model_details() ignores
     * this exact value unless a later settings save marks the Trend model as manual.
     */
    public static function migrate_legacy_model_seed() {
        if (get_option(self::OPTION_LEGACY_MODEL_MIGRATION, null) !== null) { return; }
        $trend_model = trim((string)get_option(self::OPTION_MODEL, ''));
        update_option(self::OPTION_LEGACY_MODEL_MIGRATION, array(
            'evaluated_at'=>current_time('mysql'),
            'legacy_value_present'=>$trend_model === 'gpt-5.5' ? '1' : '0',
            'action'=>'left_saved_value_in_place_effective_model_ignores_unmarked_legacy_seed',
        ));
    }

    public static function seed_sources() {
        global $wpdb; $now = current_time('mysql');
        foreach (ALMA_Trend_Content_Ideas_Registry::defaults() as $src) {
            $exists = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('sources') . ' WHERE source_key=%s', $src['key']));
            if ($exists) { continue; }
            $wpdb->insert(self::table('sources'), array(
                'source_key'=>sanitize_key($src['key']),'name'=>sanitize_text_field($src['name']),'enabled'=>(int)$src['enabled'],
                'priority'=>self::normalize_priority($src['priority']),'max_contents_per_run'=>self::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3),'category'=>sanitize_text_field($src['category']),'allowed_domains'=>wp_json_encode(self::domains_to_array($src['domains'])),
                'interval_days'=>absint($src['days']),'next_run_at'=>gmdate('Y-m-d H:i:s', current_time('timestamp') + DAY_IN_SECONDS * absint($src['days'])),
                'custom_prompt'=>sanitize_textarea_field($src['prompt']),'description'=>sanitize_text_field($src['description']),'status'=>'active','created_at'=>$now,'updated_at'=>$now,
            ));
        }
    }

    public static function get_sources($enabled_only = false) { global $wpdb; $where = $enabled_only ? ' WHERE enabled=1 AND status != "archived"' : ''; return $wpdb->get_results('SELECT * FROM ' . self::table('sources') . $where . ' ORDER BY priority ASC, name ASC', ARRAY_A); }
    public static function get_due_sources() { global $wpdb; $now=current_time('mysql'); return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('sources').' WHERE enabled=1 AND status != %s AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY priority ASC, next_run_at ASC', 'archived', $now), ARRAY_A); }
    public static function get_source($key) { global $wpdb; $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sources').' WHERE source_key=%s', sanitize_key($key)), ARRAY_A); return is_array($r)?$r:array(); }

    public static function save_settings($post) {
        update_option(self::OPTION_GLOBAL_PROMPT, sanitize_textarea_field($post['global_prompt'] ?? ''));
        $model = sanitize_text_field($post['model'] ?? '');
        $current_model = trim((string)get_option(self::OPTION_MODEL, ''));
        $current_manual = get_option(self::OPTION_MODEL_MANUAL, '') === '1';
        $legacy_ignored_rendered = !empty($post['legacy_model_ignored']);
        $legacy_seed_still_unclaimed = ($current_model === ALMA_Trend_Content_Ideas_Service::LEGACY_SEEDED_MODEL && !$current_manual);

        update_option(self::OPTION_MODEL, $model);

        if ($model === '') {
            update_option(self::OPTION_MODEL_MANUAL, '0');
        } elseif ($legacy_seed_still_unclaimed && $model === ALMA_Trend_Content_Ideas_Service::LEGACY_SEEDED_MODEL && !$legacy_ignored_rendered) {
            update_option(self::OPTION_MODEL_MANUAL, '0');
        } else {
            update_option(self::OPTION_MODEL_MANUAL, '1');
        }

        update_option(self::OPTION_TIMEOUT, max(20, min(180, absint($post['timeout'] ?? 90))));
        global $wpdb; $sources = self::get_sources(false); $now=current_time('mysql');
        foreach ($sources as $src) {
            $key = $src['source_key']; $enabled = isset($post['sources'][$key]['enabled']) ? 1 : 0;
            $interval = max(1, min(365, absint($post['sources'][$key]['interval_days'] ?? $src['interval_days'])));
            $priority = self::normalize_priority($post['sources'][$key]['priority'] ?? null, self::normalize_priority($src['priority'] ?? 2));
            $max_contents = self::normalize_max_contents_per_run($post['sources'][$key]['max_contents_per_run'] ?? null, self::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3));
            $prompt = sanitize_textarea_field($post['sources'][$key]['custom_prompt'] ?? $src['custom_prompt']);
            $wpdb->update(self::table('sources'), array('enabled'=>$enabled,'priority'=>$priority,'max_contents_per_run'=>$max_contents,'interval_days'=>$interval,'custom_prompt'=>$prompt,'updated_at'=>$now), array('source_key'=>$key));
        }
    }


    public static function normalize_priority($value, $fallback = 2) {
        $priority = absint($value);
        if ($priority >= 1 && $priority <= 3) { return $priority; }
        $fallback = absint($fallback);
        return ($fallback >= 1 && $fallback <= 3) ? $fallback : 2;
    }

    public static function normalize_max_contents_per_run($value, $fallback = 3) {
        $max = absint($value);
        if ($max >= 1 && $max <= 10) { return $max; }
        if ($max > 10) { return 10; }
        $fallback = absint($fallback);
        return ($fallback >= 1 && $fallback <= 10) ? $fallback : 3;
    }

    private static function normalize_existing_sources() {
        global $wpdb; $table = self::table('sources'); $now = current_time('mysql');
        $wpdb->query("UPDATE $table SET priority = 2, updated_at = '$now' WHERE priority IS NULL OR priority < 1 OR priority > 3");
        $wpdb->query("UPDATE $table SET max_contents_per_run = 3, updated_at = '$now' WHERE max_contents_per_run IS NULL OR max_contents_per_run < 1");
        $wpdb->query("UPDATE $table SET max_contents_per_run = 10, updated_at = '$now' WHERE max_contents_per_run > 10");
    }

    public static function mark_source_ran($key, $ok = true) { global $wpdb; $src=self::get_source($key); if (!$src) { return; } $now=current_time('mysql'); $next=gmdate('Y-m-d H:i:s', current_time('timestamp') + DAY_IN_SECONDS * max(1, absint($src['interval_days']))); $wpdb->update(self::table('sources'), array('last_run_at'=>$now,'next_run_at'=>$next,'status'=>$ok?'active':'error','updated_at'=>$now), array('source_key'=>sanitize_key($key))); }
    public static function insert_report($row) { global $wpdb; $data=array('report_type'=>sanitize_key($row['report_type']??'scheduled'),'title'=>sanitize_text_field($row['title']??''),'period_start'=>sanitize_text_field($row['period_start']??''),'period_end'=>sanitize_text_field($row['period_end']??''),'status'=>sanitize_key($row['status']??'success'),'summary'=>sanitize_textarea_field($row['summary']??''),'result_json'=>wp_json_encode($row['result']??array()),'sources_json'=>wp_json_encode($row['sources']??array()),'metrics_json'=>wp_json_encode($row['metrics']??array()),'model'=>sanitize_text_field($row['model']??''),'tokens_used'=>isset($row['tokens_used'])?absint($row['tokens_used']):null,'created_at'=>current_time('mysql')); $wpdb->insert(self::table('reports'), $data); return (int)$wpdb->insert_id; }
    public static function get_reports($page=1,$per=10) { global $wpdb; $page=max(1,absint($page)); $per=max(1,min(50,absint($per))); return $wpdb->get_results($wpdb->prepare('SELECT id,report_type,title,status,summary,metrics_json,model,tokens_used,created_at FROM '.self::table('reports').' ORDER BY created_at DESC LIMIT %d OFFSET %d', $per, ($page-1)*$per), ARRAY_A); }
    public static function get_report($id) { global $wpdb; $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('reports').' WHERE id=%d', absint($id)), ARRAY_A); return is_array($r)?$r:array(); }
    public static function latest_report() { global $wpdb; $r=$wpdb->get_row('SELECT * FROM '.self::table('reports').' ORDER BY created_at DESC LIMIT 1', ARRAY_A); return is_array($r)?$r:array(); }
    public static function log($source_key,$run_type,$status,$message,$raw_error='',$report_id=null,$started_at='') { global $wpdb; $wpdb->insert(self::table('logs'), array('source_key'=>sanitize_key($source_key),'report_id'=>$report_id?absint($report_id):null,'run_type'=>sanitize_key($run_type),'status'=>sanitize_key($status),'message'=>sanitize_text_field($message),'raw_error'=>$raw_error?wp_strip_all_tags((string)$raw_error):null,'started_at'=>$started_at?:current_time('mysql'),'completed_at'=>current_time('mysql'))); }
    public static function domains_to_array($domains) { return array_values(array_filter(array_map('trim', explode(',', (string)$domains)))); }
    public static function decode_json($json) { $d=json_decode((string)$json,true); return is_array($d)?$d:array(); }
}
