<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Preview_Service {
    public function get_preview_items($source, $criteria = array()) {
        $settings = json_decode((string)($source['settings'] ?? '{}'), true) ?: array();
        $credentials = json_decode((string)($source['credentials'] ?? '{}'), true) ?: array();
        $limit = isset($criteria['import_limit']) ? (int)$criteria['import_limit'] : (isset($settings['import_limit']) ? (int)$settings['import_limit'] : 10);
        $limit = max(1, min(100, $limit));

        $provider = sanitize_key($source['provider_preset'] ?: $source['provider']);
        if (!in_array($provider, array('viator','getyourguide'), true)) {
            return new WP_Error('provider_not_supported', __('Anteprima import non supportata per questo provider.', 'affiliate-link-manager-ai'));
        }

        $client = $provider === 'getyourguide' ? new ALMA_Affiliate_Source_Provider_Client_GetYourGuide() : new ALMA_Affiliate_Source_Provider_Client_Viator();
        $items = $client->fetch_items_for_import_preview($source, $settings, $credentials, $limit, $criteria);
        if (is_wp_error($items)) return $items;

        $out = array();
        foreach ((array)$items as $item) {
            $out[] = $provider === 'getyourguide' ? $this->annotate_getyourguide_item($item) : $this->annotate_viator_item($item);
        }
        return $out;
    }

    private function annotate_getyourguide_item($item) {
        $item = is_array($item) ? $item : array();
        $external_id = sanitize_text_field((string)($item['tour_id'] ?? $item['id'] ?? $item['tourId'] ?? $item['external_id'] ?? ''));
        $url = esc_url_raw((string)($item['url'] ?? $item['marketplace_url'] ?? $item['product_url'] ?? $item['affiliate_url'] ?? ''));
        $item['external_id'] = $external_id;
        $item['productCode'] = $external_id;
        $item['productUrl'] = $url;
        $item['affiliate_url'] = $url;
        $item['title'] = sanitize_text_field((string)($item['title'] ?? $item['name'] ?? ''));
        $item['description'] = sanitize_text_field((string)($item['description'] ?? $item['abstract'] ?? $item['summary'] ?? ''));
        $errors = array();
        $warnings = array();
        if ($external_id === '') $errors[] = __('Tour ID GetYourGuide mancante.', 'affiliate-link-manager-ai');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = __('URL prodotto GetYourGuide mancante o non valido.', 'affiliate-link-manager-ai');
        if ($item['title'] === '') $warnings[] = __('Titolo GetYourGuide mancante: verrà usato fallback controllato.', 'affiliate-link-manager-ai');
        $media = $this->resolve_getyourguide_media($item);
        if (empty($media['has_image'])) $warnings[] = __('Immagine GetYourGuide mancante: import consentito senza immagine.', 'affiliate-link-manager-ai');
        $item['_alma_validation'] = array('external_id'=>$external_id,'url_origin'=>'url/marketplace_url','has_affiliate_url'=>($url !== '' ? 'yes' : 'no'),'errors'=>$errors,'warnings'=>$warnings,'media'=>$media,'status'=>empty($errors) ? (empty($warnings) ? 'ok' : 'warning') : 'error','selectable_default'=>empty($errors));
        return $item;
    }

    private function annotate_viator_item($item) {
        $item = is_array($item) ? $item : array();
        $external_id = sanitize_text_field((string)($item['productCode'] ?? $item['external_id'] ?? ''));
        $title = sanitize_text_field((string)($item['title'] ?? $item['name'] ?? ''));
        $affiliate_url = (string)($item['productUrl'] ?? '');
        $affiliate_url_valid = $affiliate_url !== '' && filter_var($affiliate_url, FILTER_VALIDATE_URL);
        $contains_code = ($external_id !== '' && $affiliate_url !== '' && stripos($affiliate_url, $external_id) !== false);

        $errors = array();
        $warnings = array();
        if ($external_id === '') $errors[] = __('productCode mancante.', 'affiliate-link-manager-ai');
        if (!$affiliate_url_valid) $errors[] = __('productUrl mancante o non valido.', 'affiliate-link-manager-ai');
        if ($title === '') $warnings[] = __('Titolo mancante: verrà usato fallback controllato.', 'affiliate-link-manager-ai');
        if ($affiliate_url_valid && !$contains_code) $warnings[] = __('URL affiliato sospetto: productCode non riconoscibile nel link.', 'affiliate-link-manager-ai');

        $media = $this->resolve_viator_media($item);
        if (empty($media['has_image'])) {
            $warnings[] = __('Immagine Viator mancante o senza URL valido: import consentito senza immagine.', 'affiliate-link-manager-ai');
        }

        $item['_alma_validation'] = array(
            'external_id' => $external_id,
            'url_origin' => 'productUrl',
            'has_affiliate_url' => $affiliate_url_valid ? 'yes' : 'no',
            'product_code_in_url' => $contains_code,
            'errors' => $errors,
            'warnings' => $warnings,
            'media' => $media,
            'status' => empty($errors) ? (empty($warnings) ? 'ok' : 'warning') : 'error',
            'selectable_default' => empty($errors),
        );
        return $item;
    }

    private function resolve_viator_media($item) {
        if (!class_exists('ALMA_Affiliate_Source_Viator_Media_Resolver')) {
            return array('has_image'=>false,'featured_image_url'=>'','image_source'=>'','caption'=>'','is_cover'=>false,'width'=>0,'height'=>0,'warnings'=>array(__('Resolver media Viator non disponibile.', 'affiliate-link-manager-ai')));
        }
        $resolver = new ALMA_Affiliate_Source_Viator_Media_Resolver();
        $media = $resolver->resolve($item);
        return array(
            'has_image' => !empty($media['has_image']),
            'featured_image_url' => esc_url_raw((string)($media['featured_image_url'] ?? '')),
            'image_source' => sanitize_text_field((string)($media['image_source'] ?? '')),
            'caption' => sanitize_text_field((string)($media['caption'] ?? '')),
            'is_cover' => !empty($media['is_cover']),
            'width' => absint($media['width'] ?? 0),
            'height' => absint($media['height'] ?? 0),
            'warnings' => array_values(array_filter(array_map('sanitize_text_field', (array)($media['warnings'] ?? array())))),
        );
    }


    private function resolve_getyourguide_media($item) {
        if (!class_exists('ALMA_Affiliate_Source_GetYourGuide_Media_Resolver')) {
            return array('has_image'=>false,'featured_image_url'=>'','image_source'=>'','caption'=>'','width'=>0,'height'=>0,'warnings'=>array(__('Resolver media GetYourGuide non disponibile.', 'affiliate-link-manager-ai')));
        }
        $resolver = new ALMA_Affiliate_Source_GetYourGuide_Media_Resolver();
        $media = $resolver->resolve($item);
        return array(
            'has_image' => !empty($media['has_image']),
            'featured_image_url' => esc_url_raw((string)($media['featured_image_url'] ?? '')),
            'image_source' => sanitize_text_field((string)($media['image_source'] ?? '')),
            'caption' => sanitize_text_field((string)($media['caption'] ?? '')),
            'width' => absint($media['width'] ?? 0),
            'height' => absint($media['height'] ?? 0),
            'images_count' => absint($media['images_count'] ?? 0),
            'warnings' => array_values(array_filter(array_map('sanitize_text_field', (array)($media['warnings'] ?? array())))),
        );
    }


    public function build_dedupe_map($source, $items, $duplicate_policy = 'skip_existing') {
        $map = array();
        $dedupe = new ALMA_Affiliate_Source_Import_Dedupe_Service();
        foreach ((array)$items as $item) {
            $eid = sanitize_text_field((string)($item['productCode'] ?? $item['external_id'] ?? $item['tour_id'] ?? $item['id'] ?? ''));
            if ($eid === '') continue;
            $normalized = ALMA_Affiliate_Source_Normalizer::normalize($item, $source, array('write_provider_specific_meta'=>false));
            $map[$eid] = $dedupe->find_match($normalized, $duplicate_policy);
        }
        return $map;
    }

    public function find_existing_map($source_id, $external_ids) {
        global $wpdb;
        $source_id = (string) absint($source_id);
        $external_ids = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$external_ids))));
        if (empty($external_ids)) return array();

        $in_placeholders = implode(',', array_fill(0, count($external_ids), '%s'));
        $sql = "SELECT pm_ext.meta_value AS external_id, pm_ext.post_id
                FROM {$wpdb->postmeta} pm_src
                INNER JOIN {$wpdb->postmeta} pm_ext ON pm_src.post_id = pm_ext.post_id
                WHERE pm_src.meta_key = '_alma_source_id' AND pm_src.meta_value = %s
                AND pm_ext.meta_key = '_alma_external_id' AND pm_ext.meta_value IN ($in_placeholders)";
        $args = array_merge(array($source_id), $external_ids);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
        $map = array();
        foreach ((array)$rows as $row) {
            $map[(string)$row['external_id']] = (int)$row['post_id'];
        }
        return $map;
    }
}
