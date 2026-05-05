<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Affiliate_Index {
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';

    public static function table_name() { return ALMA_AI_Content_Agent_Store::table('affiliate_index'); }

    public static function batch_size() { return max(10, min(500, (int) apply_filters('alma_ai_affiliate_index_batch_size', 100))); }

    public static function index_batch($args = array()) {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(500, absint($args['limit']))) : self::batch_size();
        $state = self::get_batch_state();
        $after_id = isset($args['after_id']) ? absint($args['after_id']) : (int)$state['last_processed_id'];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private','trash') AND ID > %d ORDER BY ID ASC LIMIT %d",
                'affiliate_link',
                $after_id,
                $limit
            ),
            ARRAY_A
        );
        $ids = array_map('absint', wp_list_pluck((array) $rows, 'ID'));
        $processed = 0; $indexed = 0; $last_id = $after_id;
        foreach ($ids as $id) { $processed++; $last_id = max($last_id, $id); if (self::index_single($id)) { $indexed++; } }
        $updated = array(
            'last_processed_id' => $last_id,
            'processed' => (int)$state['processed'] + $processed,
            'indexed' => (int)$state['indexed'] + $indexed,
            'skipped' => max(0, ((int)$state['skipped'] + $processed) - ((int)$state['indexed'] + $indexed)),
            'done' => $processed < $limit,
            'started_at' => $state['started_at'] ?: current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'last_error' => '',
        );
        update_option('alma_ai_affiliate_index_state', $updated, false);
        return array('processed'=>$processed,'indexed'=>$indexed,'last_id'=>$last_id,'done'=>(bool)$updated['done']);
    }

    public static function get_batch_state() {
        $state = get_option('alma_ai_affiliate_index_state', array());
        if (!is_array($state)) { $state = array(); }
        return array(
            'last_processed_id' => isset($state['last_processed_id']) ? max(0, absint($state['last_processed_id'])) : 0,
            'processed' => isset($state['processed']) ? max(0, absint($state['processed'])) : 0,
            'indexed' => isset($state['indexed']) ? max(0, absint($state['indexed'])) : 0,
            'skipped' => isset($state['skipped']) ? max(0, absint($state['skipped'])) : 0,
            'done' => !empty($state['done']),
            'started_at' => sanitize_text_field($state['started_at'] ?? ''),
            'updated_at' => sanitize_text_field($state['updated_at'] ?? ($state['last_batch_at'] ?? '')),
            'last_error' => sanitize_text_field($state['last_error'] ?? ''),
        );
    }

    public static function reset_batch_state() {
        update_option('alma_ai_affiliate_index_state', self::get_batch_state_defaults(), false);
    }

    private static function get_batch_state_defaults() {
        return array('last_processed_id'=>0,'processed'=>0,'indexed'=>0,'skipped'=>0,'done'=>false,'started_at'=>'','updated_at'=>'','last_error'=>'');
    }


    public static function clear_index() {
        global $wpdb;
        $table = self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) {
            self::reset_batch_state();
            return array('success' => true, 'deleted' => 0, 'table_exists' => false);
        }
        $deleted = $wpdb->query("DELETE FROM {$table}");
        if ($deleted === false) {
            self::reset_batch_state();
            return array('success' => false, 'deleted' => 0, 'table_exists' => true, 'error' => sanitize_text_field($wpdb->last_error));
        }
        self::reset_batch_state();
        return array('success' => true, 'deleted' => (int) $deleted, 'table_exists' => true);
    }

    public static function get_index_stats() {
        global $wpdb;
        $stats = array(
            'table_exists' => true,
            'total_published' => 0,
            'indexed_active' => 0,
            'missing_index' => 0,
            'not_indexed' => 0,
            'without_affiliate_url' => 0,
            'inactive_index_records' => 0,
            'orphan_index_records' => 0,
            'active_invalid_records' => 0,
            'needs_update' => 0,
            'last_indexed_at' => '',
            'batch_state' => self::get_batch_state(),
        );
        $table = self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) { $stats['table_exists'] = false; return $stats; }
        $stats['total_published'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish'", 'affiliate_link'));
        $stats['without_affiliate_url'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} p WHERE p.post_type=%s AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key IN (%s, %s) AND TRIM(pm.meta_value) <> '')", 'affiliate_link', '_affiliate_url', '_alma_affiliate_url'));
        $stats['indexed_active'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $table WHERE status=%s AND post_status='publish' AND affiliate_url_present=1", self::STATUS_ACTIVE));
        $stats['inactive_index_records'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $table WHERE status=%s", self::STATUS_INACTIVE));
        $stats['last_indexed_at'] = (string)$wpdb->get_var("SELECT MAX(indexed_at) FROM $table");
        $stats['needs_update'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN $table i ON i.affiliate_link_id = p.ID AND i.status = %s WHERE p.post_type=%s AND p.post_status='publish' AND (i.affiliate_link_id IS NULL OR i.post_modified_gmt IS NULL OR p.post_modified_gmt > i.post_modified_gmt)", self::STATUS_ACTIVE, 'affiliate_link'));
        $stats['missing_index'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} p LEFT JOIN $table i ON i.affiliate_link_id = p.ID WHERE p.post_type=%s AND p.post_status='publish' AND i.affiliate_link_id IS NULL AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key IN (%s, %s) AND TRIM(pm.meta_value) <> '')", 'affiliate_link', '_affiliate_url', '_alma_affiliate_url'));
        $stats['orphan_index_records'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.affiliate_link_id WHERE p.ID IS NULL OR p.post_type <> %s", 'affiliate_link'));
        $stats['active_invalid_records'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.affiliate_link_id WHERE i.status = %s AND (p.ID IS NULL OR p.post_type <> %s OR p.post_status <> 'publish' OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key IN (%s, %s) AND TRIM(pm.meta_value) <> ''))", self::STATUS_ACTIVE, 'affiliate_link', '_affiliate_url', '_alma_affiliate_url'));
        $stats['not_indexed'] = max(0, (int) $stats['missing_index']);
        return $stats;
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
        $affiliate = self::get_affiliate_url_data($post_id);
        $affiliate_url = $affiliate['url'];
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
            'affiliate_url_present'=>$affiliate['valid'] ? 1 : 0,
            'provenance'=>sanitize_text_field($provider),
            'link_types'=>sanitize_text_field($link_types),
            'featured_image_id'=>$thumb_id,
            'featured_image_url'=>esc_url_raw((string)$thumb_url),
            'content_hash'=>hash('sha256', $normalized.'|'.$affiliate_url.'|'.$link_types.'|'.$thumb_id),
            'post_modified_gmt'=>gmdate('Y-m-d H:i:s', strtotime((string)$post->post_modified_gmt ?: 'now')),
            'indexed_at'=>current_time('mysql', true),
            'status'=>($post->post_status === 'publish' && $affiliate['valid']) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
        );
    }

    private static function extract_terms($text) { $chunks=preg_split('/[^\p{L}\p{N}]+/u', strtolower((string)$text)); $out=array(); foreach((array)$chunks as $c){ $c=trim($c); if(mb_strlen($c)>=4){$out[$c]=true;} if(count($out)>=30){break;}} return array_keys($out); }
    public static function get_affiliate_url_data($post_id) {
        $url = trim((string)get_post_meta($post_id, '_affiliate_url', true));
        if ($url === '') { $url = trim((string)get_post_meta($post_id, '_alma_affiliate_url', true)); }
        $url = esc_url_raw($url);
        return array('url' => $url, 'valid' => !empty($url) && (bool)wp_http_validate_url($url));
    }

    public static function affiliate_url($post_id) {
        $data = self::get_affiliate_url_data($post_id);
        return (string)$data['url'];
    }

    public static function search($query, $limit = 200) {
        global $wpdb; $table=self::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array(); }
        $text = sanitize_text_field($query['text'] ?? ''); if ($text==='') { return array(); }
        $like = '%'.$wpdb->esc_like($text).'%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status=%s AND post_status='publish' AND affiliate_url_present=1 AND affiliate_link_id>0 AND (title LIKE %s OR normalized_text LIKE %s OR keywords LIKE %s OR link_types LIKE %s OR provenance LIKE %s OR affiliate_url LIKE %s) ORDER BY indexed_at DESC LIMIT %d", self::STATUS_ACTIVE, $like,$like,$like,$like,$like,$like, max(1,min(300,$limit))), ARRAY_A);
        return is_array($rows)?$rows:array();
    }
}


add_action('save_post_affiliate_link', function($post_id, $post, $update){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) { return; }
    if (!($post instanceof WP_Post) || $post->post_type !== 'affiliate_link') { return; }
    if (apply_filters('alma_ai_affiliate_index_disable_autosync', false, $post_id, $post, $update)) { return; }
    ALMA_AI_Content_Agent_Affiliate_Index::index_single($post_id, true);
}, 100, 3);

add_action('before_delete_post', function($post_id){
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'affiliate_link') { return; }
    ALMA_AI_Content_Agent_Affiliate_Index::index_single($post_id, true);
});
