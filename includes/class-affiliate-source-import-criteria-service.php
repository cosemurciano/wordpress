<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Criteria_Service {
    public function sanitize($input) {
        $c = is_array($input) ? $input : array();
        $out = array();
        $out['import_search_model'] = in_array(sanitize_key($c['import_search_model'] ?? 'freetext_search'), array('products_search','freetext_search'), true) ? sanitize_key($c['import_search_model']) : 'freetext_search';
        $out['import_search_term'] = sanitize_text_field(wp_unslash($c['import_search_term'] ?? ''));
        $out['import_destination_id'] = sanitize_text_field(wp_unslash($c['import_destination_id'] ?? ''));
        $out['import_limit'] = max(1, min(100, (int)($c['import_limit'] ?? 10)));
        $out['import_start'] = max(1, (int)($c['import_start'] ?? 1));
        $out['next_start'] = max($out['import_start'], (int)($c['next_start'] ?? $out['import_start']));
        foreach (array('import_rating_from','import_rating_to') as $k) { $out[$k] = isset($c[$k]) && $c[$k] !== '' ? max(0, min(5, (float)$c[$k])) : ''; }
        foreach (array('import_price_from','import_price_to') as $k) { $out[$k] = isset($c[$k]) && $c[$k] !== '' ? max(0, (float)$c[$k]) : ''; }
        foreach (array('import_duration_from','import_duration_to') as $k) { $out[$k] = isset($c[$k]) && $c[$k] !== '' ? max(0, (int)$c[$k]) : ''; }
        $tags_raw = sanitize_text_field(wp_unslash($c['import_tag_ids'] ?? ''));
        $tags = array_filter(array_map('absint', preg_split('/\s*,\s*/', $tags_raw)));
        $out['import_tag_ids'] = implode(',', $tags);
        $allowed_flags = array('NEW_ON_VIATOR','FREE_CANCELLATION','SKIP_THE_LINE','PRIVATE_TOUR','SPECIAL_OFFER','LIKELY_TO_SELL_OUT');
        $flags = array_map('sanitize_text_field', (array)($c['import_flags'] ?? array()));
        $out['import_flags'] = array_values(array_intersect($allowed_flags, $flags));
        $out['import_include_automatic_translations'] = (isset($c['import_include_automatic_translations']) && (string)$c['import_include_automatic_translations'] === '0') ? '0' : '1';
        $out['import_confirmation_type'] = in_array(sanitize_text_field($c['import_confirmation_type'] ?? ''), array('','INSTANT'), true) ? sanitize_text_field($c['import_confirmation_type'] ?? '') : '';
        $out['import_availability_range'] = in_array(sanitize_key($c['import_availability_range'] ?? 'none'), array('none','next_30_days','next_90_days','custom'), true) ? sanitize_key($c['import_availability_range']) : 'none';
        $out['import_start_date'] = $this->sanitize_date($c['import_start_date'] ?? '');
        $out['import_end_date'] = $this->sanitize_date($c['import_end_date'] ?? '');
        $allowed_sort = array('DEFAULT','PRICE','TRAVELER_RATING','REVIEW_AVG_RATING','ITINERARY_DURATION','DATE_ADDED');
        $out['import_sort'] = in_array(strtoupper(sanitize_text_field($c['import_sort'] ?? 'DEFAULT')), $allowed_sort, true) ? strtoupper(sanitize_text_field($c['import_sort'] ?? 'DEFAULT')) : 'DEFAULT';
        $out['import_sort_order'] = in_array(strtoupper(sanitize_text_field($c['import_sort_order'] ?? '')), array('','ASCENDING','DESCENDING'), true) ? strtoupper(sanitize_text_field($c['import_sort_order'] ?? '')) : '';
        return $this->normalize_dates_and_sort($out);
    }
    private function sanitize_date($date){ $date=sanitize_text_field(wp_unslash($date)); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)?$date:''; }
    private function normalize_dates_and_sort($out){
        $today = gmdate('Y-m-d');
        if ($out['import_availability_range'] === 'next_30_days') { $out['import_start_date'] = $today; $out['import_end_date'] = gmdate('Y-m-d', strtotime('+30 days')); }
        if ($out['import_availability_range'] === 'next_90_days') { $out['import_start_date'] = $today; $out['import_end_date'] = gmdate('Y-m-d', strtotime('+90 days')); }
        if ($out['import_availability_range'] === 'none') { $out['import_start_date'] = ''; $out['import_end_date'] = ''; }
        if ($out['import_availability_range'] === 'custom') {
            if ($out['import_start_date'] < $today) $out['import_start_date'] = $today;
            if ($out['import_end_date'] !== '' && $out['import_end_date'] < $out['import_start_date']) $out['import_end_date'] = $out['import_start_date'];
        }
        if ($out['import_sort'] === 'DEFAULT') $out['import_sort_order'] = '';
        if (in_array($out['import_sort'], array('TRAVELER_RATING','REVIEW_AVG_RATING'), true)) $out['import_sort_order'] = 'DESCENDING';
        return $out;
    }
}
