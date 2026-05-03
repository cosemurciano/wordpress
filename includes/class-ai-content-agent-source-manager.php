<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Source_Manager {
    public static function save_source($data) {
        global $wpdb;
        $tech = sanitize_key($data['source_type'] ?? 'other');
        if (!ALMA_AI_Content_Agent_Source_Tech_Registry::is_valid($tech)) { return array('success'=>false,'message'=>'Tecnologia non valida.'); }
        $url = esc_url_raw($data['source_url'] ?? '');
        if (empty($url) || !wp_http_validate_url($url)) { return array('success'=>false,'message'=>'URL non valido.'); }
        $table = ALMA_AI_Content_Agent_Store::table('sources');
        $row = array(
            'name'=>sanitize_text_field($data['name'] ?? ''),'source_type'=>$tech,
            'source_url'=>$url,'language_code'=>'','market'=>'','usage_mode'=>'knowledge','is_active'=>empty($data['is_active']) ? 0 : 1,
            'notes'=>'','updated_at'=>current_time('mysql')
        );
        if (!empty($data['id'])) { $wpdb->update($table,$row,array('id'=>(int)$data['id'])); return array('success'=>true,'message'=>'Fonte aggiornata.'); }
        $row['created_at']=current_time('mysql');
        $wpdb->insert($table,$row);
        return array('success'=>true,'message'=>'Fonte creata.');
    }
}
