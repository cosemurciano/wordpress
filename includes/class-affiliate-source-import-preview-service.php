<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Preview_Service {
    public function get_preview_items($source) {
        $settings = json_decode((string)($source['settings'] ?? '{}'), true) ?: array();
        $credentials = json_decode((string)($source['credentials'] ?? '{}'), true) ?: array();
        $limit = isset($settings['import_limit']) ? (int)$settings['import_limit'] : 10;
        $limit = max(1, min(100, $limit));

        $provider = sanitize_key($source['provider_preset'] ?: $source['provider']);
        if ($provider !== 'viator') {
            return new WP_Error('provider_not_supported', __('Anteprima import non supportata per questo provider.', 'affiliate-link-manager-ai'));
        }

        $client = new ALMA_Affiliate_Source_Provider_Client_Viator();
        return $client->fetch_items_for_import_preview($source, $settings, $credentials, $limit);
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
