<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Normalizer {
    public static function normalize($item, $source, $options = array()) {
        $item = is_array($item) ? $item : array();
        $source = is_array($source) ? $source : array();
        $write_provider_specific_meta = isset($options['write_provider_specific_meta']) ? (bool)$options['write_provider_specific_meta'] : true;
        $provider = sanitize_key($source['provider'] ?? 'manual');
        $provider_preset = sanitize_key($source['provider_preset'] ?? '');
        $effective_provider = $provider_preset !== '' ? $provider_preset : $provider;
        $meta = array(
            '_alma_provider' => $provider,
            '_alma_provider_preset' => $provider_preset,
            '_alma_source_id' => (string) ($source['id'] ?? ''),
            '_alma_external_id' => sanitize_text_field($item['external_id'] ?? ($item['productCode'] ?? '')),
            '_alma_ai_visibility' => self::sanitize_ai_visibility($item['ai_visibility'] ?? 'available'),
            '_alma_ai_priority' => intval($item['ai_priority'] ?? 0),
        );
        if ($write_provider_specific_meta) {
            $meta['_alma_metadata_json'] = wp_json_encode($item);
        }

        $featured_image_url = esc_url_raw($item['image'] ?? '');
        if ($effective_provider === 'viator') {
            $media = self::resolve_viator_media($item);
            $featured_image_url = !empty($media['has_image']) ? esc_url_raw((string)$media['featured_image_url']) : '';
            self::add_viator_media_meta($meta, $media);
        }

        $normalized = array('post_title'=>sanitize_text_field($item['title'] ?? ($item['name'] ?? '')),'post_content'=>wp_kses_post($item['description'] ?? ''),'featured_image_url'=>$featured_image_url,'affiliate_url'=>esc_url_raw($item['affiliate_url'] ?? ($item['productUrl'] ?? '')),'original_url'=>esc_url_raw($item['original_url'] ?? ($item['productUrl'] ?? '')),'meta'=>$meta,'raw_item'=>$item);
        $hash_base = !empty($normalized['original_url']) ? $normalized['original_url'] : $normalized['affiliate_url'];
        $normalized['meta']['_alma_sync_hash'] = $hash_base ? wp_hash($hash_base) : '';
        return $normalized;
    }

    private static function resolve_viator_media($item) {
        if (!class_exists('ALMA_Affiliate_Source_Viator_Media_Resolver')) {
            return array('has_image'=>false,'featured_image_url'=>'','image_source'=>'','caption'=>'','is_cover'=>false,'width'=>0,'height'=>0,'variant_url'=>'','images_count'=>0,'variants_count'=>0,'warnings'=>array());
        }
        $resolver = new ALMA_Affiliate_Source_Viator_Media_Resolver();
        return $resolver->resolve($item);
    }

    private static function add_viator_media_meta(&$meta, $media) {
        $media = is_array($media) ? $media : array();
        $safe_media = array(
            'has_image' => !empty($media['has_image']),
            'featured_image_url' => esc_url_raw((string)($media['featured_image_url'] ?? '')),
            'image_source' => sanitize_text_field((string)($media['image_source'] ?? '')),
            'caption' => sanitize_text_field((string)($media['caption'] ?? '')),
            'is_cover' => !empty($media['is_cover']),
            'width' => absint($media['width'] ?? 0),
            'height' => absint($media['height'] ?? 0),
            'variant_url' => esc_url_raw((string)($media['variant_url'] ?? '')),
            'images_count' => absint($media['images_count'] ?? 0),
            'variants_count' => absint($media['variants_count'] ?? 0),
            'warnings' => array_values(array_filter(array_map('sanitize_text_field', (array)($media['warnings'] ?? array())))),
        );

        $meta['_alma_featured_image_url'] = $safe_media['featured_image_url'];
        $meta['_alma_media_provider'] = 'viator';
        $meta['_alma_media_source'] = $safe_media['image_source'];
        $meta['_alma_media_caption'] = $safe_media['caption'];
        $meta['_alma_media_width'] = (string)$safe_media['width'];
        $meta['_alma_media_height'] = (string)$safe_media['height'];
        $meta['_alma_media_is_cover'] = $safe_media['is_cover'] ? '1' : '0';
        $meta['_alma_viator_images_count'] = (string)$safe_media['images_count'];
        $meta['_alma_viator_image_variants_count'] = (string)$safe_media['variants_count'];
        $meta['_alma_viator_media_json'] = wp_json_encode($safe_media);
    }

    public static function sanitize_ai_visibility($value) { $allowed = array('available', 'excluded', 'manual_only'); return in_array($value, $allowed, true) ? $value : 'available'; }
}
