<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Trend_Radar_Store {
    public static function table($name) { global $wpdb; return $wpdb->prefix . 'alma_trend_radar_' . $name; }

    public static function install() {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('profiles') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            language VARCHAR(20) NOT NULL DEFAULT 'it',
            target_market VARCHAR(120) NOT NULL DEFAULT 'Italia',
            main_theme VARCHAR(190) NOT NULL DEFAULT '',
            editorial_focus TEXT NULL,
            seed_queries TEXT NULL,
            preferred_sources TEXT NULL,
            excluded_sources TEXT NULL,
            frequency VARCHAR(40) NOT NULL DEFAULT 'weekly',
            run_time VARCHAR(5) NOT NULL DEFAULT '09:00',
            max_trends INT UNSIGNED NOT NULL DEFAULT 5,
            analysis_depth VARCHAR(20) NOT NULL DEFAULT 'standard',
            editorial_goal VARCHAR(30) NOT NULL DEFAULT 'guida',
            email_summary TINYINT(1) NOT NULL DEFAULT 0,
            recipient_email VARCHAR(190) NOT NULL DEFAULT '',
            next_run_at DATETIME NULL,
            last_run_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY active_next (active,next_run_at)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('reports') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            trend_title TEXT NOT NULL,
            trend_summary LONGTEXT NULL,
            why_now LONGTEXT NULL,
            destinations TEXT NULL,
            seasonality VARCHAR(190) NOT NULL DEFAULT '',
            target_audience TEXT NULL,
            seo_potential_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
            affiliate_potential_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
            urgency_score TINYINT UNSIGNED NOT NULL DEFAULT 1,
            recommended_article_titles LONGTEXT NULL,
            suggested_keywords LONGTEXT NULL,
            suggested_outline LONGTEXT NULL,
            source_urls LONGTEXT NULL,
            source_notes LONGTEXT NULL,
            suggested_internal_affiliate_links LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            raw_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY profile_created (profile_id,created_at), KEY status_created (status,created_at)
        ) $c;");
        dbDelta("CREATE TABLE " . self::table('logs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY profile_created (profile_id,created_at), KEY level_created (level,created_at)
        ) $c;");
    }

    public static function defaults() {
        return array('id'=>0,'name'=>'','active'=>1,'language'=>'it','target_market'=>'Italia','main_theme'=>'','editorial_focus'=>'','seed_queries'=>'','preferred_sources'=>'','excluded_sources'=>'','frequency'=>'weekly','run_time'=>'09:00','max_trends'=>5,'analysis_depth'=>'standard','editorial_goal'=>'guida','email_summary'=>0,'recipient_email'=>'');
    }

    public static function sanitize_profile($data) {
        $allowed_depth = array('rapido','standard','approfondito');
        $allowed_goal = array('guida','destinazione','esperienza','lista','news','itinerario');
        $allowed_freq = array('manual','hourly','daily','twicedaily','weekly');
        $run_time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($data['run_time'] ?? '')) ? $data['run_time'] : '09:00';
        return array(
            'name'=>sanitize_text_field($data['name'] ?? ''),
            'active'=>empty($data['active']) ? 0 : 1,
            'language'=>sanitize_text_field($data['language'] ?? 'it'),
            'target_market'=>sanitize_text_field($data['target_market'] ?? 'Italia'),
            'main_theme'=>sanitize_text_field($data['main_theme'] ?? ''),
            'editorial_focus'=>sanitize_textarea_field($data['editorial_focus'] ?? ''),
            'seed_queries'=>sanitize_textarea_field($data['seed_queries'] ?? ''),
            'preferred_sources'=>sanitize_textarea_field($data['preferred_sources'] ?? ''),
            'excluded_sources'=>sanitize_textarea_field($data['excluded_sources'] ?? ''),
            'frequency'=>in_array(($data['frequency'] ?? 'weekly'), $allowed_freq, true) ? $data['frequency'] : 'weekly',
            'run_time'=>$run_time,
            'max_trends'=>max(1, min(10, absint($data['max_trends'] ?? 5))),
            'analysis_depth'=>in_array(($data['analysis_depth'] ?? 'standard'), $allowed_depth, true) ? $data['analysis_depth'] : 'standard',
            'editorial_goal'=>in_array(($data['editorial_goal'] ?? 'guida'), $allowed_goal, true) ? $data['editorial_goal'] : 'guida',
            'email_summary'=>empty($data['email_summary']) ? 0 : 1,
            'recipient_email'=>sanitize_email($data['recipient_email'] ?? ''),
        );
    }

    public static function save_profile($data, $id = 0) {
        global $wpdb; $now = current_time('mysql'); $row = self::sanitize_profile($data); $row['updated_at'] = $now;
        if ($row['name'] === '') { return new WP_Error('missing_name', __('Nome profilo obbligatorio.', 'affiliate-link-manager-ai')); }
        if ($row['email_summary'] && $row['recipient_email'] !== '' && !is_email($row['recipient_email'])) { return new WP_Error('bad_email', __('Email destinatario non valida.', 'affiliate-link-manager-ai')); }
        if ($id > 0) { $wpdb->update(self::table('profiles'), $row, array('id'=>absint($id))); return absint($id); }
        $row['created_at'] = $now; $wpdb->insert(self::table('profiles'), $row); return (int)$wpdb->insert_id;
    }

    public static function get_profile($id) { global $wpdb; $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('profiles').' WHERE id=%d', absint($id)), ARRAY_A); return is_array($r) ? array_merge(self::defaults(), $r) : array(); }
    public static function get_profiles($active_only = false) { global $wpdb; $sql = 'SELECT * FROM '.self::table('profiles') . ($active_only ? ' WHERE active=1' : '') . ' ORDER BY active DESC, name ASC'; return $wpdb->get_results($sql, ARRAY_A); }
    public static function delete_profile($id) { global $wpdb; return (bool)$wpdb->delete(self::table('profiles'), array('id'=>absint($id))); }
    public static function update_next_run($id, $next) { global $wpdb; $wpdb->update(self::table('profiles'), array('next_run_at'=>$next, 'updated_at'=>current_time('mysql')), array('id'=>absint($id))); }
    public static function mark_profile_ran($id) { global $wpdb; $wpdb->update(self::table('profiles'), array('last_run_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')), array('id'=>absint($id))); }

    public static function insert_report($profile_id, $trend) {
        global $wpdb; $now = current_time('mysql');
        $row = self::sanitize_trend($trend); $row['profile_id'] = absint($profile_id); $row['created_at'] = $now; $row['updated_at'] = $now; $row['raw_json'] = wp_json_encode($trend);
        $wpdb->insert(self::table('reports'), $row); return (int)$wpdb->insert_id;
    }

    public static function sanitize_trend($trend) {
        $arr = is_array($trend) ? $trend : array();
        $json_fields = array('destinations','recommended_article_titles','suggested_keywords','suggested_outline','source_urls','source_notes','suggested_internal_affiliate_links');
        $row = array(
            'trend_title'=>sanitize_text_field($arr['trend_title'] ?? ''),
            'trend_summary'=>sanitize_textarea_field($arr['trend_summary'] ?? ''),
            'why_now'=>sanitize_textarea_field($arr['why_now'] ?? ''),
            'seasonality'=>sanitize_text_field($arr['seasonality'] ?? ''),
            'target_audience'=>sanitize_textarea_field($arr['target_audience'] ?? ''),
            'seo_potential_score'=>self::score($arr['seo_potential_score'] ?? 1),
            'affiliate_potential_score'=>self::score($arr['affiliate_potential_score'] ?? 1),
            'urgency_score'=>self::score($arr['urgency_score'] ?? 1),
            'status'=>sanitize_key($arr['status'] ?? 'new'),
        );
        foreach ($json_fields as $f) { $row[$f] = wp_json_encode(self::sanitize_deep($arr[$f] ?? array())); }
        return $row;
    }

    private static function score($v) { return max(1, min(10, absint($v))); }
    private static function sanitize_deep($v) { if (is_array($v)) { return array_map(array(__CLASS__, 'sanitize_deep'), $v); } return is_scalar($v) ? sanitize_text_field((string)$v) : ''; }

    public static function get_reports($args = array()) {
        global $wpdb; $page=max(1,absint($args['page']??1)); $per=max(1,min(50,absint($args['per_page']??20))); $offset=($page-1)*$per; $where='WHERE 1=1'; $params=array();
        if (!empty($args['status'])) { $where.=' AND status=%s'; $params[]=sanitize_key($args['status']); }
        if (!empty($args['profile_id'])) { $where.=' AND profile_id=%d'; $params[]=absint($args['profile_id']); }
        $sql='SELECT * FROM '.self::table('reports')." $where ORDER BY created_at DESC LIMIT %d OFFSET %d"; $params[]=$per; $params[]=$offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }
    public static function count_reports($args = array()) { global $wpdb; $where='WHERE 1=1'; $params=array(); if(!empty($args['status'])){$where.=' AND status=%s';$params[]=sanitize_key($args['status']);} $sql='SELECT COUNT(*) FROM '.self::table('reports')." $where"; return (int)($params ? $wpdb->get_var($wpdb->prepare($sql,$params)) : $wpdb->get_var($sql)); }
    public static function get_report($id) { global $wpdb; $r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('reports').' WHERE id=%d',absint($id)),ARRAY_A); return is_array($r)?$r:array(); }
    public static function update_report_status($id, $status) { global $wpdb; $allowed=array('new','interesting','discarded','idea_created','draft_created'); if(!in_array($status,$allowed,true)){return false;} return (bool)$wpdb->update(self::table('reports'), array('status'=>$status,'updated_at'=>current_time('mysql')), array('id'=>absint($id))); }

    public static function log($profile_id, $level, $message, $context = array()) {
        global $wpdb; unset($context['api_key'], $context['Authorization']);
        $wpdb->insert(self::table('logs'), array('profile_id'=>absint($profile_id),'level'=>sanitize_key($level),'message'=>sanitize_text_field($message),'context'=>wp_json_encode(self::sanitize_deep($context)),'created_at'=>current_time('mysql')));
    }
    public static function get_logs($limit = 30) { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT l.*, p.name AS profile_name FROM '.self::table('logs').' l LEFT JOIN '.self::table('profiles').' p ON p.id=l.profile_id ORDER BY l.created_at DESC LIMIT %d', max(1,min(100,absint($limit)))), ARRAY_A); }
}
