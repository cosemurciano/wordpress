<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Manual_Import_Service {
    public function import_selected($source, $selected_external_ids) {
        $preview = new ALMA_Affiliate_Source_Import_Preview_Service();
        $items = $preview->get_preview_items($source);
        if (is_wp_error($items)) return $items;

        $selected_map = array_fill_keys(array_map('strval', (array)$selected_external_ids), true);
        $settings = json_decode((string)($source['settings'] ?? '{}'), true) ?: array();
        $duplicate_policy = sanitize_key($settings['duplicate_policy'] ?? 'skip_existing');
        $regenerate_ai = !isset($settings['regenerate_ai_context_on_import']) || (string)$settings['regenerate_ai_context_on_import'] === '1';
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
            $normalized = ALMA_Affiliate_Source_Normalizer::normalize($item, $source);
            $res = $importer->import_item($normalized, $source);
            if (is_wp_error($res)) { $result['errors']++; continue; }
            if (($res['status'] ?? '') === 'updated') $result['updated']++; else $result['created']++;
            if (!$regenerate_ai) {
                delete_post_meta((int)$res['post_id'], '_alma_ai_context');
            }
            $result['processed'][] = array('external_id'=>$external_id,'status'=>$res['status'],'post_id'=>(int)$res['post_id']);
        }
        return $result;
    }
}
