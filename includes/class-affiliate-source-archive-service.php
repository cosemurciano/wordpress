<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Archive_Service {
    public function count_associated_links($source_id){
        $source_id = absint($source_id);
        if($source_id<=0) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_alma_source_id' AND pm.meta_value=%s AND p.post_type='affiliate_link' AND p.post_status NOT IN ('trash','auto-draft')",
            (string)$source_id
        ));
    }

    public function archive_source($source_id, $user_id = 0){
        $source_id = absint($source_id);
        if($source_id<=0) return new WP_Error('invalid_source','Source non valida');
        global $wpdb;
        $table = $wpdb->prefix.'alma_affiliate_sources';
        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$source_id), ARRAY_A);
        if(!is_array($source)) return new WP_Error('not_found','Source non trovata');
        $deleted_at = current_time('mysql');
        $safe_credentials = wp_json_encode(array());
        $update = $wpdb->update($table,array(
            'is_active'=>0,
            'credentials'=>$safe_credentials,
            'deleted_at'=>$deleted_at,
            'deleted_by'=>absint($user_id),
            'updated_at'=>$deleted_at,
        ),array('id'=>$source_id));
        if($update===false) return new WP_Error('db_error','Errore aggiornamento source');
        $this->backfill_link_snapshots($source_id,$source,$deleted_at);
        return array('source'=>$source,'deleted_at'=>$deleted_at,'links_count'=>$this->count_associated_links($source_id));
    }

    public function restore_source($source_id){
        $source_id = absint($source_id);
        if($source_id<=0) return new WP_Error('invalid_source','Source non valida');
        global $wpdb;
        $table = $wpdb->prefix.'alma_affiliate_sources';
        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$source_id), ARRAY_A);
        if(!is_array($source)) return new WP_Error('not_found','Source non trovata');
        $ok = $wpdb->update($table,array('deleted_at'=>null,'deleted_by'=>0,'updated_at'=>current_time('mysql')),array('id'=>$source_id),array('%s','%d','%s'),array('%d'));
        if($ok===false) return new WP_Error('db_error','Errore ripristino source');
        return true;
    }

    private function backfill_link_snapshots($source_id,$source,$deleted_at){
        $posts = get_posts(array('post_type'=>'affiliate_link','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'_alma_source_id','meta_value'=>(string)$source_id));
        foreach((array)$posts as $post_id){
            update_post_meta($post_id,'_alma_source_name',sanitize_text_field($source['name'] ?? ''));
            update_post_meta($post_id,'_alma_source_provider',sanitize_text_field($source['provider'] ?? ''));
            update_post_meta($post_id,'_alma_source_provider_label',sanitize_text_field(($source['provider_label'] ?? '') ?: ($source['provider'] ?? '')));
            update_post_meta($post_id,'_alma_source_deleted_at',sanitize_text_field($deleted_at));
        }
    }
}
