<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Media_Selector {
    const DEFAULT_MIN_SCORE = 28;

    public static function select_candidates($args = array()) {
        global $wpdb;
        $featured_limit = max(0, min(5, absint($args['limit_featured'] ?? 5)));
        $media_limit = max(0, min(12, absint($args['limit_media'] ?? 12)));
        $query_terms = self::build_query_terms($args);
        $warnings = array();
        $debug = array('query_terms'=>$query_terms, 'featured_candidates_count'=>0, 'media_candidates_count'=>0);

        if ($featured_limit < 1 && $media_limit < 1) {
            return array('items'=>array(), 'featured_image_candidates'=>array(), 'media_candidates'=>array(), 'debug'=>$debug, 'warnings'=>$warnings);
        }

        $table = ALMA_AI_Content_Agent_Store::table('media_index');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            $warnings[] = 'Indice media non disponibile: candidate immagini editoriali vuote.';
            return array('items'=>array(), 'featured_image_candidates'=>array(), 'media_candidates'=>array(), 'debug'=>$debug, 'warnings'=>$warnings);
        }

        $rows = self::fetch_rows($table, $query_terms, max(40, ($featured_limit + $media_limit) * 5));
        $scored = array();
        foreach ($rows as $row) {
            $candidate = self::score_row($row, $query_terms);
            if (empty($candidate) || (int)$candidate['score'] < (int)apply_filters('alma_ai_media_candidate_min_score', self::DEFAULT_MIN_SCORE, $args)) { continue; }
            $scored[] = $candidate;
        }

        usort($scored, function($a, $b) {
            if ((int)$a['score'] === (int)$b['score']) { return (int)$b['attachment_id'] <=> (int)$a['attachment_id']; }
            return (int)$b['score'] <=> (int)$a['score'];
        });

        $featured = array_slice($scored, 0, $featured_limit);
        $featured_ids = array_fill_keys(array_map('absint', wp_list_pluck($featured, 'attachment_id')), true);
        $media = array();
        foreach ($scored as $candidate) {
            $id = absint($candidate['attachment_id'] ?? 0);
            if ($id < 1 || isset($featured_ids[$id])) { continue; }
            $media[] = $candidate;
            if (count($media) >= $media_limit) { break; }
        }

        $debug['featured_candidates_count'] = count($featured);
        $debug['media_candidates_count'] = count($media);
        if (empty($featured) && empty($media)) { $warnings[] = 'Nessun media editoriale con match sufficiente trovato.'; }

        return array('items'=>$media, 'featured_image_candidates'=>$featured, 'media_candidates'=>$media, 'debug'=>$debug, 'warnings'=>$warnings);
    }

    private static function fetch_rows($table, $terms, $limit) {
        global $wpdb;
        $limit = max(1, min(80, absint($limit)));
        $where = "is_editorial_candidate=1 AND is_affiliate_media=0 AND post_status IN ('inherit','publish','private')";
        $params = array();
        $like_parts = array();
        foreach (array_slice((array)$terms, 0, 10) as $term) {
            $term = sanitize_text_field((string)$term);
            if ($term === '' || mb_strlen($term) < 3) { continue; }
            $like = '%' . $wpdb->esc_like($term) . '%';
            $like_parts[] = '(title LIKE %s OR alt_text LIKE %s OR caption LIKE %s OR file_name LIKE %s OR search_text LIKE %s OR attached_post_title LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (!empty($like_parts)) { $where .= ' AND (' . implode(' OR ', $like_parts) . ')'; }
        $params[] = $limit;
        $sql = "SELECT attachment_id,file_name,title,alt_text,caption,url_full,url_large,width,height,post_parent,attached_post_title,search_text,is_affiliate_media,is_editorial_candidate FROM $table WHERE $where ORDER BY indexed_at DESC LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: array();
    }

    private static function build_query_terms($args) {
        $parts = array(
            $args['keyword_or_topic'] ?? ($args['keyword'] ?? ''),
            $args['idea_title'] ?? ($args['idea'] ?? ''),
            $args['prompt'] ?? ($args['openai_prompt'] ?? ($args['idea_prompt'] ?? '')),
            $args['content_search_query'] ?? ($args['search_query'] ?? ''),
            $args['destination'] ?? '',
            $args['theme'] ?? '',
        );
        foreach (array('category_candidates','categories','tag_candidates','tags','internal_links') as $key) {
            foreach ((array)($args[$key] ?? array()) as $item) {
                if (is_array($item)) { $parts[] = implode(' ', array_filter(array($item['name'] ?? '', $item['title'] ?? '', $item['url'] ?? '', implode(' ', (array)($item['tags'] ?? array())), implode(' ', (array)($item['categories'] ?? array()))))); }
                else { $parts[] = (string)$item; }
            }
        }
        $text = remove_accents(strtolower(wp_strip_all_tags(implode(' ', array_map('strval', $parts)))));
        $tokens = preg_split('/[^a-z0-9]+/u', $text);
        $stop = array_fill_keys(array('che','con','per','del','della','dello','dei','degli','delle','una','uno','gli','le','la','il','lo','di','da','in','su','a','e','o','un','the','and','or','to','of','cosa','vedere','guida','consigli'), true);
        $terms = array();
        foreach ((array)$tokens as $token) {
            $token = trim((string)$token);
            if (mb_strlen($token) < 3 || isset($stop[$token]) || is_numeric($token)) { continue; }
            $terms[$token] = $token;
            if (count($terms) >= 18) { break; }
        }
        return array_values($terms);
    }

    private static function score_row($row, $terms) {
        if ((int)($row['is_affiliate_media'] ?? 0) === 1 || (int)($row['is_editorial_candidate'] ?? 0) !== 1) { return null; }
        $url = esc_url_raw((string)($row['url_large'] ?: ($row['url_full'] ?? '')));
        if ($url === '' || !wp_http_validate_url($url)) { return null; }
        $fields = array(
            'title'=>remove_accents(strtolower((string)($row['title'] ?? ''))),
            'alt'=>remove_accents(strtolower((string)($row['alt_text'] ?? ''))),
            'caption'=>remove_accents(strtolower((string)($row['caption'] ?? ''))),
            'filename'=>remove_accents(strtolower((string)($row['file_name'] ?? ''))),
            'search'=>remove_accents(strtolower((string)($row['search_text'] ?? ''))),
            'parent'=>remove_accents(strtolower((string)($row['attached_post_title'] ?? ''))),
        );
        $score = 0; $matches = array();
        foreach ((array)$terms as $term) {
            $term = remove_accents(strtolower((string)$term));
            if ($term === '') { continue; }
            $matched = false;
            foreach (array('title'=>22,'alt'=>24,'caption'=>16,'filename'=>14,'search'=>10,'parent'=>12) as $field=>$weight) {
                if ($fields[$field] !== '' && strpos($fields[$field], $term) !== false) { $score += $weight; $matched = true; }
            }
            if ($matched) { $matches[] = $term; }
        }
        if (!empty($row['alt_text'])) { $score += 6; }
        if (!empty($row['caption'])) { $score += 3; }
        $width = absint($row['width'] ?? 0); $height = absint($row['height'] ?? 0);
        if ($width >= 1000 && $height >= 650) { $score += 8; }
        elseif (($width > 0 && $width < 700) || ($height > 0 && $height < 450)) { $score -= 14; }
        if (absint($row['post_parent'] ?? 0) > 0 && !empty($matches)) { $score += 5; }
        if (preg_match('/^(?:img|image|dsc|photo|screenshot|whatsapp[-_ ]?image)[-_ ]?\d+/i', (string)($row['file_name'] ?? ''))) { $score -= 10; }
        $matches = array_values(array_unique(array_slice($matches, 0, 4)));
        if (empty($matches) || $score < self::DEFAULT_MIN_SCORE) { return null; }
        return array(
            'attachment_id'=>absint($row['attachment_id'] ?? 0),
            'url'=>$url,
            'title'=>sanitize_text_field((string)($row['title'] ?? '')),
            'alt'=>sanitize_text_field((string)($row['alt_text'] ?? '')),
            'caption'=>sanitize_text_field((string)($row['caption'] ?? '')),
            'width'=>$width,
            'height'=>$height,
            'score'=>max(0, min(100, (int)$score)),
            'reason'=>sanitize_text_field('Match ' . implode('/', $matches) . ' in indice media'),
        );
    }
}
