<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Store {
    const OPTION_GLOBAL_PROMPT = 'alma_trend_content_global_prompt';
    const OPTION_MODEL = 'alma_trend_content_model';
    const OPTION_TIMEOUT = 'alma_trend_content_timeout';
    const OPTION_LEGACY_MODEL_MIGRATION = 'alma_trend_content_legacy_model_migration_2323';
    const OPTION_MODEL_MANUAL = 'alma_trend_content_model_manual';

    public static function table($name) { global $wpdb; return $wpdb->prefix . 'alma_trend_content_' . $name; }

    public static function allowed_statuses() {
        return array('active','inactive','needs_review','working','no_recent_results','blocked_or_unreachable','domain_too_broad','domain_mismatch');
    }

    public static function status_labels() {
        return array(
            'active'=>__('Attiva', 'affiliate-link-manager-ai'),
            'inactive'=>__('Disattivata', 'affiliate-link-manager-ai'),
            'needs_review'=>__('Da verificare', 'affiliate-link-manager-ai'),
            'working'=>__('Funzionante', 'affiliate-link-manager-ai'),
            'no_recent_results'=>__('Nessun risultato recente', 'affiliate-link-manager-ai'),
            'blocked_or_unreachable'=>__('Non raggiungibile/bloccata', 'affiliate-link-manager-ai'),
            'domain_too_broad'=>__('Dominio troppo generico', 'affiliate-link-manager-ai'),
            'domain_mismatch'=>__('Dominio non coerente', 'affiliate-link-manager-ai'),
        );
    }

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
            area_geografica VARCHAR(120) NOT NULL DEFAULT '',
            allowed_domains LONGTEXT NULL,
            interval_days INT UNSIGNED NOT NULL DEFAULT 7,
            last_run_at DATETIME NULL,
            next_run_at DATETIME NULL,
            custom_prompt LONGTEXT NULL,
            description TEXT NULL,
            notes TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            last_tested_at DATETIME NULL,
            last_test_status VARCHAR(30) NOT NULL DEFAULT '',
            last_test_message TEXT NULL,
            last_result_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_citation_url VARCHAR(255) NOT NULL DEFAULT '',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY source_key (source_key), KEY enabled_next (enabled,next_run_at), KEY priority (priority), KEY status_enabled (status,enabled), KEY deleted_at (deleted_at)
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
        if (get_option(self::OPTION_GLOBAL_PROMPT, null) === null) { update_option(self::OPTION_GLOBAL_PROMPT, ALMA_Trend_Content_Ideas_Prompt_Builder::default_global_prompt()); }
        if (get_option(self::OPTION_TIMEOUT, null) === null) { update_option(self::OPTION_TIMEOUT, 90); }
        self::migrate_legacy_model_seed();
    }

    public static function migrate_legacy_model_seed() {
        if (get_option(self::OPTION_LEGACY_MODEL_MIGRATION, null) !== null) { return; }
        $trend_model = trim((string)get_option(self::OPTION_MODEL, ''));
        update_option(self::OPTION_LEGACY_MODEL_MIGRATION, array('evaluated_at'=>current_time('mysql'),'legacy_value_present'=>$trend_model === 'gpt-5.5' ? '1' : '0','action'=>'left_saved_value_in_place_effective_model_ignores_unmarked_legacy_seed'));
    }

    public static function seed_sources() {
        global $wpdb; $now = current_time('mysql');
        foreach (ALMA_Trend_Content_Ideas_Registry::defaults() as $src) {
            $exists = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table('sources') . ' WHERE source_key=%s', $src['key']));
            if ($exists) {
                $wpdb->update(self::table('sources'), array('is_default'=>1), array('source_key'=>sanitize_key($src['key'])));
                continue;
            }
            $status = in_array($src['key'], array('google_travel','google_flights'), true) ? 'domain_too_broad' : 'active';
            $notes = $status === 'domain_too_broad' ? __('Dominio google.com molto generico: verifica citazioni e valuta una fonte più specifica.', 'affiliate-link-manager-ai') : '';
            $wpdb->insert(self::table('sources'), array(
                'source_key'=>sanitize_key($src['key']),'name'=>sanitize_text_field($src['name']),'enabled'=>(int)$src['enabled'],
                'priority'=>self::normalize_priority($src['priority']),'max_contents_per_run'=>self::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3),'category'=>sanitize_text_field($src['category']),'area_geografica'=>'','allowed_domains'=>wp_json_encode(self::normalize_domains($src['domains'])),
                'interval_days'=>max(1, absint($src['days'])),'next_run_at'=>gmdate('Y-m-d H:i:s', current_time('timestamp') + DAY_IN_SECONDS * absint($src['days'])),
                'custom_prompt'=>sanitize_textarea_field($src['prompt']),'description'=>sanitize_text_field($src['description']),'notes'=>$notes,'status'=>$status,'is_default'=>1,'created_by'=>get_current_user_id() ?: null,'created_at'=>$now,'updated_at'=>$now,
            ));
        }
    }

    public static function get_sources($enabled_only = false, $filter = 'all') {
        global $wpdb; $where = array('deleted_at IS NULL');
        if ($enabled_only) { $where[] = 'enabled=1'; $where[] = "status NOT IN ('inactive','needs_review','archived')"; }
        if (!$enabled_only) {
            $filter = sanitize_key($filter);
            if ($filter === 'active') { $where[] = 'enabled=1'; $where[] = "status NOT IN ('inactive','archived')"; }
            elseif ($filter === 'inactive') { $where[] = '(enabled=0 OR status="inactive")'; }
            elseif ($filter === 'needs_review') { $where[] = 'status="needs_review"'; }
            elseif ($filter === 'no_recent_results') { $where[] = 'status="no_recent_results"'; }
            elseif ($filter === 'blocked') { $where[] = 'status="blocked_or_unreachable"'; }
        }
        return $wpdb->get_results('SELECT * FROM ' . self::table('sources') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY enabled DESC, priority ASC, name ASC', ARRAY_A);
    }

    public static function get_due_sources() {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('sources') . ' WHERE enabled=1 AND deleted_at IS NULL AND status NOT IN ("inactive","needs_review","archived") AND (next_run_at IS NULL OR next_run_at <= %s) ORDER BY priority ASC, next_run_at ASC LIMIT 8', current_time('mysql')), ARRAY_A);
    }

    public static function get_source($key_or_id) {
        global $wpdb;
        if (is_numeric($key_or_id)) { $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sources').' WHERE id=%d', absint($key_or_id)), ARRAY_A); }
        else { $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('sources').' WHERE source_key=%s', sanitize_key($key_or_id)), ARRAY_A); }
        return is_array($r) ? $r : array();
    }

    public static function create_source($data) {
        global $wpdb; $row = self::sanitize_source_data($data, array(), true); if (is_wp_error($row)) { return $row; }
        $now=current_time('mysql'); $row['created_at']=$now; $row['updated_at']=$now; $row['created_by']=get_current_user_id() ?: null; $row['is_default']=0; $row['deleted_at']=null;
        $ok = $wpdb->insert(self::table('sources'), $row);
        if (!$ok) { return new WP_Error('source_insert_failed', __('Impossibile creare la fonte. Verifica che la chiave sia unica.', 'affiliate-link-manager-ai')); }
        return (int)$wpdb->insert_id;
    }

    public static function update_source($key_or_id, $data) {
        global $wpdb; $existing=self::get_source($key_or_id); if (!$existing) { return new WP_Error('source_missing', __('Fonte non trovata.', 'affiliate-link-manager-ai')); }
        $row = self::sanitize_source_data($data, $existing, false); if (is_wp_error($row)) { return $row; }
        $row['updated_at']=current_time('mysql'); unset($row['source_key']);
        $wpdb->update(self::table('sources'), $row, array('id'=>(int)$existing['id']));
        return true;
    }

    public static function duplicate_source($key_or_id) {
        $src=self::get_source($key_or_id); if (!$src) { return new WP_Error('source_missing', __('Fonte non trovata.', 'affiliate-link-manager-ai')); }
        $src['name'] = sprintf(__('Copia di %s', 'affiliate-link-manager-ai'), $src['name']);
        $src['source_key'] = sanitize_key($src['source_key'] . '-copy-' . time());
        $src['enabled'] = 0; $src['status'] = 'inactive';
        return self::create_source($src);
    }

    public static function deactivate_source($key_or_id, $enabled = false) {
        global $wpdb; $src=self::get_source($key_or_id); if (!$src) { return false; }
        $status = $enabled ? 'active' : 'inactive';
        return (bool)$wpdb->update(self::table('sources'), array('enabled'=>$enabled ? 1 : 0,'status'=>$status,'updated_at'=>current_time('mysql')), array('id'=>(int)$src['id']));
    }

    public static function delete_source($key_or_id) {
        global $wpdb; $src=self::get_source($key_or_id); if (!$src) { return new WP_Error('source_missing', __('Fonte non trovata.', 'affiliate-link-manager-ai')); }
        if ((int)($src['is_default'] ?? 0) || self::source_has_history($src['source_key'])) {
            $wpdb->update(self::table('sources'), array('enabled'=>0,'status'=>'inactive','deleted_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('id'=>(int)$src['id']));
            return 'soft_deleted';
        }
        $wpdb->delete(self::table('sources'), array('id'=>(int)$src['id']));
        return 'deleted';
    }

    public static function source_has_history($source_key) {
        global $wpdb; $key=sanitize_key($source_key);
        $logs=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('logs').' WHERE source_key=%s', $key));
        if ($logs > 0) { return true; }
        $like='%' . $wpdb->esc_like('"source_key":"' . $key . '"') . '%';
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table('reports').' WHERE sources_json LIKE %s', $like)) > 0;
    }

    public static function update_source_diagnostics($key_or_id, $status, $message='', $result_count=0, $citation_url='') {
        global $wpdb; $src=self::get_source($key_or_id); if (!$src) { return false; }
        $status = self::normalize_status($status, 'needs_review');
        return (bool)$wpdb->update(self::table('sources'), array(
            'status'=>$status,
            'last_tested_at'=>current_time('mysql'),
            'last_test_status'=>$status,
            'last_test_message'=>sanitize_textarea_field($message),
            'last_result_count'=>absint($result_count),
            'last_citation_url'=>esc_url_raw($citation_url),
            'updated_at'=>current_time('mysql'),
        ), array('id'=>(int)$src['id']));
    }

    public static function list_active_sources() { return self::get_sources(true); }
    public static function list_disabled_sources() { return self::get_sources(false, 'inactive'); }
    public static function list_sources_to_review($limit = 0) {
        global $wpdb; $limit=absint($limit);
        $sql='SELECT * FROM '.self::table('sources').' WHERE deleted_at IS NULL AND status IN ("needs_review","no_recent_results","blocked_or_unreachable","domain_too_broad","domain_mismatch","error") ORDER BY updated_at DESC';
        if ($limit) { $sql .= ' LIMIT ' . $limit; }
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function save_settings($post) {
        update_option(self::OPTION_GLOBAL_PROMPT, sanitize_textarea_field($post['global_prompt'] ?? ''));
        $model = sanitize_text_field($post['model'] ?? '');
        $current_model = trim((string)get_option(self::OPTION_MODEL, ''));
        $current_manual = get_option(self::OPTION_MODEL_MANUAL, '') === '1';
        $legacy_ignored_rendered = !empty($post['legacy_model_ignored']);
        $legacy_seed_still_unclaimed = ($current_model === ALMA_Trend_Content_Ideas_Service::LEGACY_SEEDED_MODEL && !$current_manual);
        update_option(self::OPTION_MODEL, $model);
        update_option(self::OPTION_MODEL_MANUAL, ($model === '' || ($legacy_seed_still_unclaimed && $model === ALMA_Trend_Content_Ideas_Service::LEGACY_SEEDED_MODEL && !$legacy_ignored_rendered)) ? '0' : '1');
        update_option(self::OPTION_TIMEOUT, max(20, min(180, absint($post['timeout'] ?? 90))));
    }

    public static function sanitize_source_data($data, $existing=array(), $creating=false) {
        $name = sanitize_text_field($data['name'] ?? ($existing['name'] ?? ''));
        if ($name === '') { return new WP_Error('source_name_required', __('Nome fonte obbligatorio.', 'affiliate-link-manager-ai')); }
        $key = sanitize_key($data['source_key'] ?? ($existing['source_key'] ?? ''));
        if ($key === '') { return new WP_Error('source_key_required', __('Chiave fonte obbligatoria.', 'affiliate-link-manager-ai')); }
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $status = self::normalize_status($data['status'] ?? ($enabled ? 'active' : 'inactive'), $enabled ? 'active' : 'inactive');
        if (!$enabled && $status === 'active') { $status = 'inactive'; }
        $domains = self::normalize_domains($data['allowed_domains'] ?? ($existing['allowed_domains'] ?? array()));
        return array(
            'source_key'=>$key,
            'name'=>$name,
            'enabled'=>$enabled,
            'priority'=>self::normalize_priority($data['priority'] ?? ($existing['priority'] ?? 2)),
            'max_contents_per_run'=>self::normalize_max_contents_per_run($data['max_contents_per_run'] ?? ($existing['max_contents_per_run'] ?? 3)),
            'category'=>sanitize_text_field($data['category'] ?? ($existing['category'] ?? '')),
            'area_geografica'=>sanitize_text_field($data['area_geografica'] ?? ($existing['area_geografica'] ?? '')),
            'allowed_domains'=>wp_json_encode($domains),
            'interval_days'=>max(1, min(365, absint($data['interval_days'] ?? ($existing['interval_days'] ?? 7)))),
            'custom_prompt'=>sanitize_textarea_field($data['custom_prompt'] ?? ($existing['custom_prompt'] ?? '')),
            'description'=>sanitize_text_field($data['description'] ?? ($existing['description'] ?? '')),
            'notes'=>sanitize_textarea_field($data['notes'] ?? ($existing['notes'] ?? '')),
            'status'=>$status,
        );
    }

    public static function normalize_status($status, $fallback = 'needs_review') {
        $status = sanitize_key($status);
        $public = array('active','inactive','needs_review','working','no_recent_results','blocked_or_unreachable','domain_too_broad','domain_mismatch');
        if (in_array($status, $public, true)) { return $status; }
        $fallback = sanitize_key($fallback);
        return in_array($fallback, $public, true) ? $fallback : 'needs_review';
    }

    public static function normalize_priority($value, $fallback = 2) {
        $priority = absint($value);
        if ($priority < 1) { $priority = absint($fallback); }
        return max(1, min(3, $priority ?: 2));
    }

    public static function normalize_max_contents_per_run($value, $fallback = 3) {
        $max = absint($value);
        if ($max < 1) { $max = absint($fallback); }
        return max(1, min(10, $max ?: 3));
    }

    private static function normalize_existing_sources() {
        global $wpdb; $table = self::table('sources'); $now = current_time('mysql');
        $wpdb->query("UPDATE $table SET priority = 2, updated_at = '$now' WHERE priority IS NULL OR priority < 1 OR priority > 3");
        $wpdb->query("UPDATE $table SET max_contents_per_run = 3, updated_at = '$now' WHERE max_contents_per_run IS NULL OR max_contents_per_run < 1");
        $wpdb->query("UPDATE $table SET max_contents_per_run = 10, updated_at = '$now' WHERE max_contents_per_run > 10");
        $wpdb->query("UPDATE $table SET status = 'domain_too_broad', notes = CONCAT(COALESCE(notes,''), ' Dominio google.com molto generico: verificare o sostituire fonte.') WHERE deleted_at IS NULL AND allowed_domains LIKE '%google.com%' AND status IN ('active','working')");
    }

    public static function mark_source_ran($key, $ok = true) {
        global $wpdb; $src=self::get_source($key); if (!$src) { return; }
        $now=current_time('mysql'); $next=gmdate('Y-m-d H:i:s', current_time('timestamp') + DAY_IN_SECONDS * max(1, absint($src['interval_days'])));
        $status = $ok ? (($src['status'] === 'inactive') ? 'inactive' : $src['status']) : 'needs_review';
        $wpdb->update(self::table('sources'), array('last_run_at'=>$now,'next_run_at'=>$next,'status'=>$status,'updated_at'=>$now), array('source_key'=>sanitize_key($key)));
    }

    public static function insert_report($row) { global $wpdb; $data=array('report_type'=>sanitize_key($row['report_type']??'scheduled'),'title'=>sanitize_text_field($row['title']??''),'period_start'=>sanitize_text_field($row['period_start']??''),'period_end'=>sanitize_text_field($row['period_end']??''),'status'=>sanitize_key($row['status']??'success'),'summary'=>sanitize_textarea_field($row['summary']??''),'result_json'=>wp_json_encode($row['result']??array()),'sources_json'=>wp_json_encode($row['sources']??array()),'metrics_json'=>wp_json_encode($row['metrics']??array()),'model'=>sanitize_text_field($row['model']??''),'tokens_used'=>isset($row['tokens_used'])?absint($row['tokens_used']):null,'created_at'=>current_time('mysql')); $wpdb->insert(self::table('reports'), $data); return (int)$wpdb->insert_id; }
    public static function get_reports($page=1,$per=10) { global $wpdb; $page=max(1,absint($page)); $per=max(1,min(50,absint($per))); return $wpdb->get_results($wpdb->prepare('SELECT id,report_type,title,status,summary,metrics_json,model,tokens_used,created_at FROM '.self::table('reports').' ORDER BY created_at DESC LIMIT %d OFFSET %d', $per, ($page-1)*$per), ARRAY_A); }
    public static function get_report($id) { global $wpdb; $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('reports').' WHERE id=%d', absint($id)), ARRAY_A); return is_array($r)?$r:array(); }
    public static function latest_report() { global $wpdb; $r=$wpdb->get_row('SELECT * FROM '.self::table('reports').' ORDER BY created_at DESC LIMIT 1', ARRAY_A); return is_array($r)?$r:array(); }
    public static function log($source_key,$run_type,$status,$message,$raw_error='',$report_id=null,$started_at='') { global $wpdb; $wpdb->insert(self::table('logs'), array('source_key'=>sanitize_key($source_key),'report_id'=>$report_id?absint($report_id):null,'run_type'=>sanitize_key($run_type),'status'=>sanitize_key($status),'message'=>sanitize_text_field($message),'raw_error'=>$raw_error?wp_strip_all_tags((string)$raw_error):null,'started_at'=>$started_at?:current_time('mysql'),'completed_at'=>current_time('mysql'))); }
    public static function domains_to_array($domains) { return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$domains)))); }
    public static function normalize_domains($domains) { return ALMA_Trend_Content_Ideas_Service::normalize_allowed_domains(is_string($domains) && strlen($domains) && $domains[0] === '[' ? self::decode_json($domains) : (is_array($domains) ? $domains : self::domains_to_array($domains))); }
    public static function decode_json($json) { $d=json_decode((string)$json,true); return is_array($d)?$d:array(); }
}
