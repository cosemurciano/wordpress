<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Preview_Service {
    public function get_preview_items($source, $criteria = array()) {
        $settings = json_decode((string)($source['settings'] ?? '{}'), true) ?: array();
        $credentials = json_decode((string)($source['credentials'] ?? '{}'), true) ?: array();
        $limit = isset($criteria['import_limit']) ? (int)$criteria['import_limit'] : (isset($settings['import_limit']) ? (int)$settings['import_limit'] : 10);
        $limit = max(1, min(100, $limit));

        $provider = sanitize_key($source['provider_preset'] ?: $source['provider']);
        if ($provider !== 'viator') {
            return new WP_Error('provider_not_supported', __('Anteprima import non supportata per questo provider.', 'affiliate-link-manager-ai'));
        }

        $client = new ALMA_Affiliate_Source_Provider_Client_Viator();
        $items = $client->fetch_items_for_import_preview($source, $settings, $credentials, $limit, $criteria);
        if (is_wp_error($items)) return $items;

        $out = array();
        foreach ((array)$items as $item) {
            $out[] = $this->annotate_viator_item($item);
        }
        return $out;
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

        $item['_alma_validation'] = array(
            'external_id' => $external_id,
            'url_origin' => 'productUrl',
            'has_affiliate_url' => $affiliate_url_valid ? 'yes' : 'no',
            'product_code_in_url' => $contains_code,
            'errors' => $errors,
            'warnings' => $warnings,
            'status' => empty($errors) ? (empty($warnings) ? 'ok' : 'warning') : 'error',
            'selectable_default' => empty($errors),
        );
        return $item;
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
