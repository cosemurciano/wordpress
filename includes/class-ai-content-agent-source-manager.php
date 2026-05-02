<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Source_Manager {
    public static function save_source($data) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('sources');
        $row = array(
            'name'=>sanitize_text_field($data['name'] ?? ''),'source_type'=>sanitize_key($data['source_type'] ?? 'manual'),
            'source_url'=>esc_url_raw($data['source_url'] ?? ''),'language_code'=>sanitize_text_field($data['language_code'] ?? ''),'market'=>sanitize_text_field($data['market'] ?? ''),
            'usage_mode'=>sanitize_key($data['usage_mode'] ?? 'knowledge'),'is_active'=>empty($data['is_active']) ? 0 : 1,'notes'=>sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at'=>current_time('mysql')
        );
        if (!empty($data['id'])) { $wpdb->update($table,$row,array('id'=>(int)$data['id'])); return (int)$data['id']; }
        $row['created_at']=current_time('mysql');
        $wpdb->insert($table,$row);
        return (int)$wpdb->insert_id;
    }
}
