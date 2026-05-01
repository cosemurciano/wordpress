<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Normalizer {
    public static function normalize($item, $source, $options = array()) {
        $write_provider_specific_meta = isset($options['write_provider_specific_meta']) ? (bool)$options['write_provider_specific_meta'] : true;
        $meta = array(
            '_alma_provider' => sanitize_key($source['provider'] ?? 'manual'),
            '_alma_provider_preset' => sanitize_key($source['provider_preset'] ?? ''),
            '_alma_source_id' => (string) ($source['id'] ?? ''),
            '_alma_external_id' => sanitize_text_field($item['external_id'] ?? ($item['productCode'] ?? '')),
            '_alma_ai_visibility' => self::sanitize_ai_visibility($item['ai_visibility'] ?? 'available'),
            '_alma_ai_priority' => intval($item['ai_priority'] ?? 0),
        );
        if ($write_provider_specific_meta) {
            $meta['_alma_metadata_json'] = wp_json_encode($item);
        }
        $normalized = array('post_title'=>sanitize_text_field($item['title'] ?? ($item['name'] ?? '')),'post_content'=>wp_kses_post($item['description'] ?? ''),'featured_image_url'=>esc_url_raw($item['image'] ?? ''),'affiliate_url'=>esc_url_raw($item['affiliate_url'] ?? ($item['productUrl'] ?? '')),'original_url'=>esc_url_raw($item['original_url'] ?? ($item['productUrl'] ?? '')),'meta'=>$meta,'raw_item'=>is_array($item)?$item:array());
        $hash_base = !empty($normalized['original_url']) ? $normalized['original_url'] : $normalized['affiliate_url'];
        $normalized['meta']['_alma_sync_hash'] = $hash_base ? wp_hash($hash_base) : '';
        return $normalized;
    }
    public static function sanitize_ai_visibility($value) { $allowed = array('available', 'excluded', 'manual_only'); return in_array($value, $allowed, true) ? $value : 'available'; }
}
