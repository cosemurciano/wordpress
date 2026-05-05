<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Affiliate_Index {
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';

    public static function table_name() { return ALMA_AI_Content_Agent_Store::table('affiliate_index'); }

    public static function batch_size() { return max(10, min(500, (int) apply_filters('alma_ai_affiliate_index_batch_size', 100))); }

    public static function index_batch($args = array()) {
        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : self::batch_size();
        $after_id = isset($args['after_id']) ? absint($args['after_id']) : 0;
        $q = new WP_Query(array('post_type'=>'affiliate_link','post_status'=>array('publish','draft','pending','private','trash'),'posts_per_page'=>$limit,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','post__not_in'=>array(0),'no_found_rows'=>true,'update_post_meta_cache'=>true,'update_post_term_cache'=>true,'date_query'=>array(),'paged'=>1,'ignore_sticky_posts'=>true,'cache_results'=>true,'suppress_filters'=>false,'post_parent'=>0,'meta_query'=>array(),));
        $ids = array_values(array_filter(array_map('absint', (array)$q->posts), function($id) use ($after_id){ return $id > $after_id; }));
        $ids = array_slice($ids, 0, $limit);
        $processed = 0; $indexed = 0; $last_id = $after_id;
        foreach ($ids as $id) { $processed++; $last_id = max($last_id, $id); if (self::index_single($id)) { $indexed++; } }
        update_option('alma_ai_affiliate_index_state', array('last_batch_at'=>current_time('mysql'),'last_processed_id'=>$last_id,'last_batch_processed'=>$processed,'last_batch_indexed'=>$indexed), false);
        return array('processed'=>$processed,'indexed'=>$indexed,'last_id'=>$last_id,'done'=>$processed < $limit);
    }

    public static function sync_incremental($limit = null) {
        global $wpdb;
        $limit = $limit === null ? self::batch_size() : max(1, min(500, absint($limit)));
        $table = self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array('processed'=>0,'indexed'=>0); }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $table i ON i.affiliate_link_id = p.ID WHERE p.post_type='affiliate_link' AND (i.affiliate_link_id IS NULL OR p.post_modified_gmt > IFNULL(i.indexed_at, '1970-01-01 00:00:00')) ORDER BY p.ID ASC LIMIT %d", $limit), ARRAY_A);
        $processed = 0; $indexed = 0;
        foreach ((array)$rows as $r) { $id = absint($r['ID'] ?? 0); if ($id < 1) { continue; } $processed++; if (self::index_single($id, true)) { $indexed++; } }
        return array('processed'=>$processed,'indexed'=>$indexed);
    }

    public static function index_single($post_id, $allow_unpublished = true) {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'affiliate_link') { return false; }
        $row = self::build_row($post);
        $table = self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return false; }
        if (!$allow_unpublished && $row['post_status'] !== 'publish') { return false; }
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE affiliate_link_id=%d", $post_id));
        if ($exists) { return (bool)$wpdb->update($table, $row, array('affiliate_link_id'=>$post_id)); }
        return (bool)$wpdb->insert($table, $row);
    }

    private static function build_row($post) {
        $post_id = (int)$post->ID;
        $affiliate_url = self::affiliate_url($post_id);
        $ctx = (string)get_post_meta($post_id, '_alma_ai_context', true);
        $provider = (string)get_post_meta($post_id, '_alma_source_provider', true);
        if ($provider === '') { $provider = (string)get_post_meta($post_id, '_alma_provider', true); }
        if ($provider === '') { $provider = 'manual'; }
        $type_names = wp_get_object_terms($post_id, 'link_type', array('fields'=>'names'));
        $link_types = is_wp_error($type_names) ? '' : implode(', ', array_map('sanitize_text_field', (array)$type_names));
        $thumb_id = (int)get_post_thumbnail_id($post_id);
        $thumb_url = $thumb_id > 0 ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '';
        $text = trim(implode(' ', array($post->post_title, wp_strip_all_tags((string)$post->post_content), $ctx, $affiliate_url, $provider, $link_types)));
        $normalized = strtolower(preg_replace('/\s+/u', ' ', $text));
        $keywords = implode(' ', self::extract_terms($normalized));
        return array(
            'affiliate_link_id'=>$post_id,
            'post_status'=>sanitize_key((string)$post->post_status),
            'title'=>sanitize_text_field($post->post_title),
            'normalized_text'=>sanitize_textarea_field($normalized),
            'keywords'=>sanitize_text_field($keywords),
            'affiliate_url'=>esc_url_raw($affiliate_url),
            'affiliate_url_present'=>empty($affiliate_url)?0:1,
            'provenance'=>sanitize_text_field($provider),
            'link_types'=>sanitize_text_field($link_types),
            'featured_image_id'=>$thumb_id,
            'featured_image_url'=>esc_url_raw((string)$thumb_url),
            'content_hash'=>hash('sha256', $normalized.'|'.$affiliate_url.'|'.$link_types.'|'.$thumb_id),
            'post_modified_gmt'=>gmdate('Y-m-d H:i:s', strtotime((string)$post->post_modified_gmt ?: 'now')),
            'indexed_at'=>current_time('mysql', true),
            'status'=>($post->post_status === 'publish' && !empty($affiliate_url)) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
        );
    }

    private static function extract_terms($text) { $chunks=preg_split('/[^\p{L}\p{N}]+/u', strtolower((string)$text)); $out=array(); foreach((array)$chunks as $c){ $c=trim($c); if(mb_strlen($c)>=4){$out[$c]=true;} if(count($out)>=30){break;}} return array_keys($out); }
    private static function affiliate_url($post_id) { $url=(string)get_post_meta($post_id,'_affiliate_url',true); if($url===''){ $url=(string)get_post_meta($post_id,'_alma_affiliate_url',true);} return $url; }

    public static function search($query, $limit = 200) {
        global $wpdb; $table=self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array(); }
        $text = sanitize_text_field($query['text'] ?? ''); if ($text==='') { return array(); }
        $like = '%'.$wpdb->esc_like($text).'%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status=%s AND post_status='publish' AND affiliate_url_present=1 AND affiliate_link_id>0 AND (title LIKE %s OR normalized_text LIKE %s OR keywords LIKE %s OR link_types LIKE %s OR provenance LIKE %s OR affiliate_url LIKE %s) ORDER BY indexed_at DESC LIMIT %d", self::STATUS_ACTIVE, $like,$like,$like,$like,$like,$like, max(1,min(300,$limit))), ARRAY_A);
        return is_array($rows)?$rows:array();
    }
}
