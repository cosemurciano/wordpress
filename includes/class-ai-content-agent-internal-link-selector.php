<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Internal_Link_Selector {
    const MAX_CANDIDATES = 8;

    public static function select_candidates($args = array()) {
        global $wpdb;
        $args = is_array($args) ? $args : array();
        $table = ALMA_AI_Content_Agent_Internal_Link_Index::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return array('items'=>array(),'diagnostics'=>array('table_exists'=>false,'query_terms'=>array(),'candidates_found'=>0,'candidates_sent'=>0)); }

        $terms = self::extract_terms($args);
        $exclude_post_id = absint($args['exclude_post_id'] ?? 0);
        $limit_scan = 80;
        $rows = array();
        if (!empty($terms)) {
            $likes = array();
            $params = array();
            foreach (array_slice($terms, 0, 8) as $term) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $likes[] = '(search_text LIKE %s OR post_title LIKE %s OR post_slug LIKE %s)';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }
            $where = implode(' OR ', $likes);
            $sql = "SELECT * FROM $table WHERE post_status='publish'" . ($exclude_post_id > 0 ? ' AND post_id <> %d' : '') . " AND ($where) ORDER BY modified_at DESC LIMIT %d";
            if ($exclude_post_id > 0) { array_unshift($params, $exclude_post_id); }
            $params[] = $limit_scan;
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        }
        if (empty($rows)) {
            if ($exclude_post_id > 0) {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_status='publish' AND post_id <> %d ORDER BY modified_at DESC LIMIT %d", $exclude_post_id, 30), ARRAY_A);
            } else {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_status='publish' ORDER BY modified_at DESC LIMIT %d", 30), ARRAY_A);
            }
        }
        $items = array();
        $seen = array();
        foreach ((array)$rows as $row) {
            $url = esc_url_raw((string)($row['permalink'] ?? ''));
            if ($url === '' || isset($seen[self::url_key($url)])) { continue; }
            $score = self::score_row($row, $args, $terms);
            if ($score['score'] < 8 && !empty($terms)) { continue; }
            $seen[self::url_key($url)] = true;
            $items[] = array(
                'id' => absint($row['post_id'] ?? 0),
                'title' => sanitize_text_field((string)($row['post_title'] ?? '')),
                'url' => $url,
                'excerpt' => wp_trim_words(sanitize_text_field((string)($row['post_excerpt'] ?? '')), 24, '…'),
                'categories' => self::decode_string_list($row['categories_json'] ?? '[]'),
                'tags' => self::decode_string_list($row['tags_json'] ?? '[]'),
                'score' => (int)$score['score'],
                'reason' => sanitize_text_field($score['reason']),
            );
        }
        usort($items, function($a, $b) {
            $s = ((int)$b['score']) <=> ((int)$a['score']);
            if ($s !== 0) { return $s; }
            return strcasecmp((string)$a['title'], (string)$b['title']);
        });
        $items = array_slice($items, 0, self::MAX_CANDIDATES);
        return array('items'=>$items,'diagnostics'=>array('table_exists'=>true,'query_terms'=>$terms,'candidates_found'=>count($rows),'candidates_sent'=>count($items),'reasons'=>wp_list_pluck($items, 'reason')));
    }

    private static function extract_terms($args) {
        $text = implode(' ', array(
            $args['idea'] ?? '', $args['keyword'] ?? '', $args['destination'] ?? '', $args['prompt'] ?? '', $args['idea_title'] ?? '', $args['context'] ?? '',
        ));
        $text = remove_accents(strtolower(wp_strip_all_tags((string)$text)));
        preg_match_all('/[a-z0-9]{3,}/u', $text, $m);
        $stop = array_flip(array('con','per','una','uno','del','della','delle','degli','nel','nella','sul','sulla','come','cosa','vedere','guida','articolo','scrivi','crea','migliori','itinerario','visitare'));
        $terms = array();
        foreach ((array)($m[0] ?? array()) as $term) {
            if (isset($stop[$term])) { continue; }
            $terms[$term] = $term;
            if (count($terms) >= 12) { break; }
        }
        return array_values($terms);
    }

    private static function score_row($row, $args, $terms) {
        $title = self::norm($row['post_title'] ?? '');
        $slug = self::norm($row['post_slug'] ?? '');
        $excerpt = self::norm($row['post_excerpt'] ?? '');
        $cats = self::norm(implode(' ', self::decode_string_list($row['categories_json'] ?? '[]')));
        $tags = self::norm(implode(' ', self::decode_string_list($row['tags_json'] ?? '[]')));
        $destination = self::norm($args['destination'] ?? '');
        $keyword = self::norm($args['keyword'] ?? ($args['idea_title'] ?? ''));
        $score = 0; $reasons = array();
        if ($destination !== '' && strpos($title, $destination) !== false) { $score += 35; $reasons[] = 'Match destinazione nel titolo'; }
        if ($keyword !== '' && strpos($title, $keyword) !== false) { $score += 30; $reasons[] = 'Match keyword nel titolo'; }
        foreach ((array)$terms as $term) {
            if ($term !== '' && strpos($title, $term) !== false) { $score += 18; $reasons[] = 'Match nel titolo'; }
            if ($term !== '' && strpos($slug, $term) !== false) { $score += 10; $reasons[] = 'Match nello slug'; }
            if ($term !== '' && (strpos($cats, $term) !== false || strpos($tags, $term) !== false)) { $score += 14; $reasons[] = 'Match categorie/tag'; }
            if ($term !== '' && strpos($excerpt, $term) !== false) { $score += 7; $reasons[] = 'Match nell’excerpt'; }
        }
        $modified = strtotime((string)($row['modified_at'] ?? ''));
        if ($modified && $modified > strtotime('-18 months')) { $score += 3; $reasons[] = 'Post recente/modificato'; }
        $reasons = array_values(array_unique($reasons));
        return array('score'=>min(100, $score), 'reason'=>implode(' e ', array_slice($reasons, 0, 3)) ?: 'Post pubblicato recente');
    }

    private static function decode_string_list($json) {
        $items = json_decode((string)$json, true);
        if (!is_array($items)) { return array(); }
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $items))));
    }

    private static function norm($text) {
        $text = remove_accents(strtolower(wp_strip_all_tags((string)$text)));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string)$text));
    }

    private static function url_key($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) { return rtrim((string)$url, '/'); }
        return strtolower($parts['host']) . rtrim((string)($parts['path'] ?? ''), '/');
    }
}
