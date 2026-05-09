<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Record_Filter {
    const STATUS_NEW = 'new';
    const STATUS_IMPORTED = 'imported';

    public static function normalize_text($value) {
        $text = strtolower(wp_strip_all_tags((string)$value));
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        } else {
            $text = strtr($text, array('à'=>'a','á'=>'a','â'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c'));
        }
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string)$text);
    }

    public static function normalize_keywords($keywords, $mode = 'any') {
        $keywords = self::normalize_text($keywords);
        if ($keywords === '') return array();
        if ($mode === 'phrase') return array($keywords);
        $parts = preg_split('/[\s,]+/', $keywords);
        return array_values(array_unique(array_filter(array_map('trim', (array)$parts))));
    }

    public static function sanitize_filters($filters) {
        $search_in_allowed = array('all','title','description','city','region','activity_type','affiliate_url');
        $mode_allowed = array('any','all','phrase');
        $status_allowed = array('new_only','with_imported','imported_only');
        $per_page = absint($filters['per_page'] ?? 50);
        if (!in_array($per_page, array(25, 50), true)) $per_page = 50;
        return array(
            'keywords' => sanitize_text_field(wp_unslash((string)($filters['keywords'] ?? ''))),
            'search_in' => in_array(sanitize_key((string)($filters['search_in'] ?? 'all')), $search_in_allowed, true) ? sanitize_key((string)$filters['search_in']) : 'all',
            'keyword_mode' => in_array(sanitize_key((string)($filters['keyword_mode'] ?? 'any')), $mode_allowed, true) ? sanitize_key((string)$filters['keyword_mode']) : 'any',
            'city' => sanitize_text_field(wp_unslash((string)($filters['city'] ?? ''))),
            'region' => sanitize_text_field(wp_unslash((string)($filters['region'] ?? ''))),
            'activity_type' => sanitize_text_field(wp_unslash((string)($filters['activity_type'] ?? ''))),
            'status' => in_array(sanitize_key((string)($filters['status'] ?? 'new_only')), $status_allowed, true) ? sanitize_key((string)$filters['status']) : 'new_only',
            'page' => max(1, absint($filters['page'] ?? 1)),
            'per_page' => $per_page,
        );
    }

    public static function record_matches_text($item, $filters) {
        $mode = (string)($filters['keyword_mode'] ?? 'any');
        $keywords = self::normalize_keywords($filters['keywords'] ?? '', $mode);
        if (empty($keywords)) return true;
        $search_in = (string)($filters['search_in'] ?? 'all');
        $fields = array(
            'title' => $item['title'] ?? '',
            'description' => $item['description'] ?? '',
            'city' => $item['city'] ?? '',
            'region' => $item['region'] ?? '',
            'activity_type' => $item['activity_type'] ?? '',
            'affiliate_url' => trim((string)($item['affiliate_url'] ?? '') . ' ' . (string)($item['original_url'] ?? '')),
        );
        $haystack = $search_in === 'all' ? implode(' ', $fields) : (string)($fields[$search_in] ?? '');
        $haystack = self::normalize_text($haystack);
        if ($mode === 'phrase') return $keywords[0] === '' || strpos($haystack, $keywords[0]) !== false;
        $matches = 0;
        foreach ($keywords as $word) {
            if ($word !== '' && strpos($haystack, $word) !== false) $matches++;
        }
        return $mode === 'all' ? $matches === count($keywords) : $matches > 0;
    }

    public static function record_matches_location_and_type($item, $filters) {
        $city = self::normalize_text($filters['city'] ?? '');
        if ($city !== '' && strpos(self::normalize_text($item['city'] ?? ''), $city) === false && strpos(self::normalize_text($item['destination'] ?? ''), $city) === false) return false;
        $region = self::normalize_text($filters['region'] ?? '');
        if ($region !== '' && strpos(self::normalize_text($item['region'] ?? ''), $region) === false && strpos(self::normalize_text($item['country_area'] ?? ''), $region) === false) return false;
        $type = self::normalize_text($filters['activity_type'] ?? '');
        if ($type !== '' && self::normalize_text($item['activity_type'] ?? '') !== $type) return false;
        return true;
    }

    public static function paginate($items, $page, $per_page) {
        $total = count($items);
        $pages = max(1, (int)ceil($total / max(1, $per_page)));
        $page = min(max(1, (int)$page), $pages);
        return array(
            'items' => array_slice($items, ($page - 1) * $per_page, $per_page),
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $pages,
        );
    }
}
