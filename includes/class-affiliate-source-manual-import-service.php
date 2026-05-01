<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Manual_Import_Service {
    public function import_selected($source, $selected_external_ids) {
        $preview = new ALMA_Affiliate_Source_Import_Preview_Service();
        $criteria = is_array($selected_external_ids['criteria'] ?? null) ? $selected_external_ids['criteria'] : array();
        $ids = is_array($selected_external_ids['ids'] ?? null) ? $selected_external_ids['ids'] : (array)$selected_external_ids;
        $items = $preview->get_preview_items($source, $criteria);
        if (is_wp_error($items)) return $items;

        $selected_map = array_fill_keys(array_map('strval', (array)$ids), true);
        $settings = json_decode((string)($source['settings'] ?? '{}'), true) ?: array();
        $duplicate_policy = sanitize_key($settings['duplicate_policy'] ?? 'skip_existing');
        $regenerate_ai = !isset($settings['regenerate_ai_context_on_import']) || (string)$settings['regenerate_ai_context_on_import'] === '1';
        $source_link_type_term_ids = array_values(array_unique(array_filter(array_map('absint', array()))));
        $existing = $preview->find_existing_map((int)$source['id'], array_keys($selected_map));

        $importer = new ALMA_Affiliate_Source_Importer();
        $result = array('created'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'processed'=>array());
        foreach ((array)$items as $item) {
            $external_id = (string)($item['external_id'] ?? $item['productCode'] ?? '');
            if ($external_id === '' || !isset($selected_map[$external_id])) continue;
            $exists = !empty($existing[$external_id]);
            if ($exists && $duplicate_policy === 'skip_existing') {
                $result['skipped']++;
                $result['processed'][] = array('external_id'=>$external_id,'status'=>'skipped','post_id'=>(int)$existing[$external_id]);
                continue;
            }
            $normalized = ALMA_Affiliate_Source_Normalizer::normalize($item, $source, array('write_provider_specific_meta'=>false));
            $res = $importer->import_item($normalized, $source, array('build_ai_context'=>$regenerate_ai));
            if (is_wp_error($res)) { $result['errors']++; continue; }
            if (($res['status'] ?? '') === 'updated') $result['updated']++; else $result['created']++;
            if (!$regenerate_ai) {
                // Preserve existing AI context when regeneration is disabled.
            }
            if (empty($source_link_type_term_ids)) { $decoded = json_decode((string)($source['destination_term_ids'] ?? ''), true); if (is_array($decoded)) $source_link_type_term_ids = array_values(array_unique(array_filter(array_map('absint',$decoded)))); if (empty($source_link_type_term_ids) && !empty($source['destination_term_id'])) $source_link_type_term_ids = array((int)$source['destination_term_id']); }
            if (!empty($source_link_type_term_ids)) {
                $existing_terms = wp_get_object_terms((int)$res['post_id'], 'link_type', array('fields' => 'ids'));
                if (is_wp_error($existing_terms) || !is_array($existing_terms)) $existing_terms = array();
                $merged = array_values(array_unique(array_filter(array_map('absint', array_merge($existing_terms, $source_link_type_term_ids)))));
                if (!empty($merged)) {
                    wp_set_object_terms((int)$res['post_id'], $merged, 'link_type', false);
                }
            }
            $result['processed'][] = array('external_id'=>$external_id,'status'=>$res['status'],'post_id'=>(int)$res['post_id']);
        }
        return $result;
    }
}
