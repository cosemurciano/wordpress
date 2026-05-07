<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Internal_Link_Selector {
    const MAX_CANDIDATES = 8;

    public static function select_candidates($args = array()) {
        global $wpdb;
        $args = is_array($args) ? $args : array();
        $table = ALMA_AI_Content_Agent_Internal_Link_Index::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            $empty_analysis = self::analyze_terms($args);
            return array('items'=>array(),'diagnostics'=>array_merge(array('table_exists'=>false,'query_terms'=>array(),'candidates_found'=>0,'candidates_sent'=>0,'candidates_passed'=>0,'min_score'=>self::min_score()), $empty_analysis));
        }

        $analysis = self::analyze_terms($args);
        $exclude_post_id = absint($args['exclude_post_id'] ?? 0);
        $min_score = self::min_score();
        $limit_scan = 120;
        $rows = array();
        $query_terms = !empty($analysis['strong_terms']) ? array_merge($analysis['strong_terms'], $analysis['related_terms']) : $analysis['raw_terms'];
        $query_terms = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$query_terms))));

        if (!empty($query_terms)) {
            $likes = array();
            $params = array();
            foreach (array_slice($query_terms, 0, 16) as $term) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $likes[] = '(search_text LIKE %s OR post_title LIKE %s OR post_slug LIKE %s)';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }
            $where = implode(' OR ', $likes);
            $sql = "SELECT * FROM $table WHERE post_status='publish'" . ($exclude_post_id > 0 ? ' AND post_id <> %d' : '') . " AND ($where) ORDER BY modified_at DESC LIMIT %d";
            if ($exclude_post_id > 0) { array_unshift($params, $exclude_post_id); }
            $params[] = $limit_scan;
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        } elseif ($exclude_post_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_status='publish' AND post_id <> %d ORDER BY modified_at DESC LIMIT %d", $exclude_post_id, 30), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE post_status='publish' ORDER BY modified_at DESC LIMIT %d", 30), ARRAY_A);
        }

        $items = array();
        $seen = array();
        foreach ((array)$rows as $row) {
            $url = esc_url_raw((string)($row['permalink'] ?? ''));
            if ($url === '' || isset($seen[self::url_key($url)])) { continue; }
            $score = self::score_row($row, $analysis);
            if (empty($score['passes']) || $score['score'] < $min_score) { continue; }
            $seen[self::url_key($url)] = true;
            $items[] = array(
                'id' => absint($row['post_id'] ?? 0),
                'title' => sanitize_text_field((string)($row['post_title'] ?? '')),
                'url' => $url,
                'excerpt' => wp_trim_words(sanitize_text_field((string)($row['post_excerpt'] ?? '')), 24, '…'),
                'categories' => self::decode_string_list($row['categories_json'] ?? '[]'),
                'tags' => self::decode_string_list($row['tags_json'] ?? '[]'),
                'score' => (int)$score['score'],
                'matched_terms' => array_values(array_unique(array_map('sanitize_text_field', (array)$score['matched_terms']))),
                'reason' => sanitize_text_field($score['reason']),
            );
        }
        usort($items, function($a, $b) {
            $s = ((int)$b['score']) <=> ((int)$a['score']);
            if ($s !== 0) { return $s; }
            return strcasecmp((string)$a['title'], (string)$b['title']);
        });
        $passed_count = count($items);
        $items = array_slice($items, 0, self::MAX_CANDIDATES);
        $diagnostics = array_merge(array(
            'table_exists' => true,
            'query_terms' => $query_terms,
            'candidates_found' => count($rows),
            'candidates_sent' => count($items),
            'candidates_passed' => $passed_count,
            'min_score' => $min_score,
            'reasons' => wp_list_pluck($items, 'reason'),
        ), $analysis);
        return array('items'=>$items,'diagnostics'=>$diagnostics);
    }

    private static function analyze_terms($args) {
        $sources = array(
            $args['content_search_query'] ?? ($args['search_query'] ?? ($args['idea'] ?? '')),
            $args['idea_title'] ?? '',
            $args['openai_prompt'] ?? ($args['idea_prompt'] ?? ($args['prompt'] ?? '')),
            $args['keyword'] ?? '',
            $args['destination'] ?? '',
        );
        $source_text = implode(' ', $sources);
        $text = self::norm($source_text);
        preg_match_all('/[a-z0-9]{3,}/u', $text, $m);
        $stop_terms = self::stop_terms();
        $raw = array();
        $strong = array();
        $weak = array();
        foreach ((array)($m[0] ?? array()) as $term) {
            if ($term === '') { continue; }
            $raw[$term] = $term;
            if (isset($stop_terms[$term])) { $weak[$term] = $term; continue; }
            $strong[$term] = $term;
        }
        foreach (self::weak_phrases() as $phrase) {
            if ($phrase !== '' && strpos(' ' . $text . ' ', ' ' . $phrase . ' ') !== false) { $weak[$phrase] = $phrase; }
        }
        $raw = array_slice(array_values($raw), 0, 24);
        $strong = array_slice(array_values($strong), 0, 12);
        $weak = array_slice(array_values($weak), 0, 18);
        $related = self::related_terms($strong);
        return array_merge(array('raw_terms'=>$raw,'strong_terms'=>$strong,'weak_terms'=>$weak,'related_terms'=>$related), self::analyze_time_sensitivity($source_text));
    }


    private static function time_sensitive_terms() {
        $terms = array(
            'gennaio','febbraio','marzo','aprile','maggio','giugno','luglio','agosto','settembre','ottobre','novembre','dicembre',
            'lunedi','lunedì','martedi','martedì','mercoledi','mercoledì','giovedi','giovedì','venerdi','venerdì','sabato','domenica'
        );
        $terms = (array) apply_filters('alma_ai_internal_link_time_sensitive_terms', $terms);
        $out = array();
        foreach ($terms as $term) {
            $term = self::norm($term);
            if ($term !== '') { $out[$term] = $term; }
        }
        return array_values($out);
    }

    private static function analyze_time_sensitivity($text) {
        $normalized = self::norm($text);
        $terms = self::time_sensitive_terms();
        $found = array();
        foreach ($terms as $term) {
            if (self::contains_term($normalized, $term)) { $found[$term] = $term; }
        }
        if (preg_match_all('/\b(?:dal\s+)?\d{1,2}(?:\s+(?:al|-)\s+\d{1,2})?\s+(?:gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\b|\b20\d{2}\b/iu', (string)$text, $m)) {
            foreach ((array)($m[0] ?? array()) as $match) {
                $key = self::norm($match);
                if ($key !== '') { $found[$key] = $key; }
            }
        }
        return array('time_sensitive_query'=>!empty($found), 'time_terms'=>array_values($found));
    }

    private static function row_time_terms($row) {
        $text = implode(' ', array($row['post_title'] ?? '', $row['post_slug'] ?? ''));
        return self::analyze_time_sensitivity($text);
    }

    private static function has_compatible_time_terms($row_terms, $query_terms) {
        foreach ((array)$row_terms as $row_term) {
            foreach ((array)$query_terms as $query_term) {
                if ($row_term === $query_term || strpos($row_term, $query_term) !== false || strpos($query_term, $row_term) !== false) { return true; }
            }
        }
        return false;
    }

    private static function stop_terms() {
        $terms = array('con','per','una','uno','del','della','delle','degli','dei','nel','nella','sul','sulla','tra','fra','che','questo','questa','questi','queste','come','cosa','fare','vedere','visitare','guida','guide','articolo','scrivi','crea','migliori','migliore','viaggio','viaggi','mete','meta','itinerario','itinerari','tour','esperienza','esperienze','destinazione','destinazioni','mese','periodo','estate','primavera','autunno','inverno','consigli','pratici','pratico','non','perdere','imperdibili','luglio','agosto','settembre','ottobre','novembre','dicembre','gennaio','febbraio','marzo','aprile','maggio','giugno');
        $terms = (array) apply_filters('alma_ai_internal_link_stop_terms', $terms);
        $normalized = array();
        foreach ($terms as $term) {
            $term = self::norm($term);
            if ($term !== '') { $normalized[$term] = true; }
        }
        return $normalized;
    }

    private static function weak_phrases() {
        return array('da non perdere','cosa vedere','cosa fare','consigli pratici');
    }

    private static function related_terms($strong_terms) {
        $map = array('lecce'=>array('salento','puglia','otranto','gallipoli','galatina','leuca'));
        $related = array();
        foreach ((array)$strong_terms as $term) {
            if (isset($map[$term])) {
                foreach ($map[$term] as $rel) { $related[$rel] = $rel; }
            }
        }
        $filtered = apply_filters('alma_ai_internal_link_related_terms', array_values($related), array_values((array)$strong_terms));
        $out = array();
        foreach ((array)$filtered as $term) {
            $term = self::norm($term);
            if ($term !== '' && !in_array($term, (array)$strong_terms, true)) { $out[$term] = $term; }
        }
        return array_slice(array_values($out), 0, 16);
    }

    private static function min_score() {
        return max(1, absint(apply_filters('alma_ai_internal_link_min_score', 30)));
    }

    private static function score_row($row, $analysis) {
        $fields = array(
            'title' => self::norm($row['post_title'] ?? ''),
            'slug' => self::norm($row['post_slug'] ?? ''),
            'excerpt' => self::norm($row['post_excerpt'] ?? ''),
            'tax' => self::norm(implode(' ', array_merge(self::decode_string_list($row['categories_json'] ?? '[]'), self::decode_string_list($row['tags_json'] ?? '[]')))),
        );
        $score = 0; $reasons = array(); $matched = array(); $has_required_match = false; $weak_matches = 0;
        foreach ((array)($analysis['strong_terms'] ?? array()) as $term) {
            $hit = false;
            if (self::contains_term($fields['title'], $term)) { $score += 45; $reasons[] = 'Match forte nel titolo'; $hit = true; }
            if (self::contains_term($fields['slug'], $term)) { $score += 35; $reasons[] = 'Match forte nello slug'; $hit = true; }
            if (self::contains_term($fields['tax'], $term)) { $score += 35; $reasons[] = 'Match forte in categorie/tag'; $hit = true; }
            if (self::contains_term($fields['excerpt'], $term)) { $score += 20; $reasons[] = 'Match forte nell’excerpt'; $hit = true; }
            if ($hit) { $matched[$term] = $term; $has_required_match = true; }
        }
        foreach ((array)($analysis['related_terms'] ?? array()) as $term) {
            $hit = false;
            if (self::contains_term($fields['title'], $term)) { $score += 34; $reasons[] = 'Match correlato geografico nel titolo'; $hit = true; }
            if (self::contains_term($fields['slug'], $term)) { $score += 28; $reasons[] = 'Match correlato geografico nello slug'; $hit = true; }
            if (self::contains_term($fields['tax'], $term)) { $score += 30; $reasons[] = 'Match correlato geografico in categorie/tag'; $hit = true; }
            if (self::contains_term($fields['excerpt'], $term)) { $score += 18; $reasons[] = 'Match correlato geografico nell’excerpt'; $hit = true; }
            if ($hit) { $matched[$term] = $term; $has_required_match = true; }
        }
        foreach ((array)($analysis['weak_terms'] ?? array()) as $term) {
            foreach ($fields as $field_text) {
                if (self::contains_term($field_text, $term)) { $weak_matches++; break; }
            }
        }
        if (empty($analysis['strong_terms']) && $weak_matches >= 2) {
            $score += min(12, $weak_matches * 3);
            $reasons[] = 'Match multipli su termini generici';
        }
        $row_time = self::row_time_terms($row);
        if (!empty($row_time['time_sensitive_query']) && empty($analysis['time_sensitive_query']) && $score > 0) {
            $penalty = max(0, absint(apply_filters('alma_ai_internal_link_time_sensitive_penalty', 38, $row, $analysis)));
            $score -= $penalty;
            $reasons[] = 'Penalità contenuto stagionale/datato per query evergreen';
        } elseif (!empty($row_time['time_sensitive_query']) && !self::has_compatible_time_terms((array)$row_time['time_terms'], (array)($analysis['time_terms'] ?? array())) && $score > 0) {
            $penalty = max(0, absint(apply_filters('alma_ai_internal_link_time_sensitive_penalty', 20, $row, $analysis)));
            $score -= $penalty;
            $reasons[] = 'Penalità data non coerente con il prompt';
        }
        $score = max(0, $score);
        $modified = strtotime((string)($row['modified_at'] ?? ''));
        if ($modified && $modified > strtotime('-18 months') && $score > 0) { $score += 2; $reasons[] = 'Post recente/modificato'; }
        $passes = !empty($analysis['strong_terms']) ? $has_required_match : ($score >= self::min_score());
        $reasons = array_values(array_unique($reasons));
        return array('score'=>min(100, $score), 'passes'=>$passes, 'matched_terms'=>array_values($matched), 'reason'=>implode(' e ', array_slice($reasons, 0, 3)) ?: 'Nessun match pertinente');
    }

    private static function contains_term($haystack, $term) {
        $haystack = ' ' . self::norm($haystack) . ' ';
        $term = self::norm($term);
        if ($term === '') { return false; }
        return strpos($haystack, ' ' . $term . ' ') !== false;
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
