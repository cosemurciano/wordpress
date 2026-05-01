<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Importer {
    public function import_item($normalized, $source, $options = array()) {
        $build_ai_context = !isset($options['build_ai_context']) || (bool)$options['build_ai_context'];
        $mode = $source['import_mode'] ?? 'create_update';
        $existing_id = $this->find_existing_link($normalized);
        if ($existing_id && $mode === 'create_only') return array('status' => 'skipped', 'post_id' => $existing_id);
        if (!$existing_id && $mode === 'update_existing') return array('status' => 'skipped', 'post_id' => 0);
        $postarr = array('post_type'=>'affiliate_link','post_status'=>'publish','post_title'=>$normalized['post_title'] ?: __('Senza titolo','affiliate-link-manager-ai'),'post_content'=>$normalized['post_content']);
        $post_id = $existing_id ? wp_update_post(array_merge($postarr, array('ID'=>$existing_id)), true) : wp_insert_post($postarr, true);
        $status = $existing_id ? 'updated' : 'imported';
        if (is_wp_error($post_id)) return $post_id;
        if (!empty($normalized['affiliate_url'])) { update_post_meta($post_id, '_affiliate_url', $normalized['affiliate_url']); update_post_meta($post_id, '_alma_affiliate_url', $normalized['affiliate_url']); }
        update_post_meta($post_id, '_alma_original_url', $normalized['original_url']); update_post_meta($post_id, '_alma_import_status', $status); update_post_meta($post_id, '_alma_last_sync_at', current_time('mysql')); update_post_meta($post_id, '_alma_import_mode', sanitize_text_field($mode));
        foreach ($normalized['meta'] as $key => $value) update_post_meta($post_id, $key, $value);
        if ($build_ai_context) { $builder = new ALMA_Affiliate_Link_AI_Context_Builder(); $builder->maybe_build_and_store($post_id, $normalized, $source); }
        if (empty(get_post_meta($post_id, '_alma_ai_visibility', true))) update_post_meta($post_id, '_alma_ai_visibility', 'available');
        $term_ids = array(); $decoded = json_decode((string)($source['destination_term_ids'] ?? ''), true); if (is_array($decoded)) $term_ids = array_values(array_unique(array_filter(array_map('absint', $decoded))));
        if (empty($term_ids) && !empty($source['destination_term_id'])) $term_ids = array(absint($source['destination_term_id']));
        if (!empty($term_ids)) wp_set_object_terms($post_id, $term_ids, 'link_type', true);
        return array('status' => $status, 'post_id' => $post_id);
    }
    private function find_existing_link($normalized) { global $wpdb; $provider=$normalized['meta']['_alma_provider']??''; $external_id=$normalized['meta']['_alma_external_id']??'';
        if ($provider && $external_id) { $post_id = $wpdb->get_var($wpdb->prepare("SELECT pm1.post_id FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id=pm2.post_id WHERE pm1.meta_key='_alma_provider' AND pm1.meta_value=%s AND pm2.meta_key='_alma_external_id' AND pm2.meta_value=%s LIMIT 1",$provider,$external_id)); if ($post_id) return (int)$post_id; }
        return 0; }
}
