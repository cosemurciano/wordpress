<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Knowledge_Search {
    const MAX_PER_GROUP = 10;

    public static function search($input = array()) {
        $query = self::normalize_input($input);
        $results = array();

        $results = array_merge($results, self::search_wordpress($query));
        $results = array_merge($results, self::search_knowledge_items($query));
        $results = array_merge($results, self::search_sources($query));
        $results = array_merge($results, self::search_media($query));

        $results = self::dedupe($results);
        $grouped = self::group_and_rank($results);

        return array('query' => $query, 'groups' => $grouped, 'generated_at' => current_time('mysql'));
    }

    private static function normalize_input($input) {
        $theme = sanitize_text_field($input['theme'] ?? '');
        $destination = sanitize_text_field($input['destination'] ?? '');
        $instructions = sanitize_textarea_field($input['temporary_instructions'] ?? '');
        $text = trim($theme . ' ' . $destination . ' ' . $instructions);
        return array('theme'=>$theme,'destination'=>$destination,'temporary_instructions'=>$instructions,'text'=>$text,'terms'=>self::extract_terms($text));
    }

    private static function extract_terms($text) {
        $text = strtolower((string) $text);
        $chunks = preg_split('/[^\p{L}\p{N}]+/u', $text);
        $out = array();
        foreach ((array) $chunks as $term) {
            $term = trim($term);
            if (mb_strlen($term) >= 3) { $out[$term] = true; }
            if (count($out) >= 12) { break; }
        }
        return array_keys($out);
    }

    private static function search_wordpress($query) {
        $items = array();
        $posts = get_posts(array('post_type'=>array('post','page','affiliate_link'),'post_status'=>'publish','s'=>$query['text'],'numberposts'=>20,'orderby'=>'date','order'=>'DESC','suppress_filters'=>false));
        foreach ($posts as $p) {
            $source_type = $p->post_type === 'page' ? 'page' : ($p->post_type === 'affiliate_link' ? 'affiliate_link' : 'post');
            $score = self::score_text($query, $p->post_title . ' ' . wp_strip_all_tags((string)$p->post_excerpt));
            $items[] = self::result(array(
                'key' => 'wp:' . $source_type . ':' . (int)$p->ID,
                'source_type' => $source_type,
                'source_id' => (int)$p->ID,
                'title' => get_the_title($p),
                'excerpt' => wp_trim_words(wp_strip_all_tags((string)($p->post_excerpt ?: $p->post_content)), 18),
                'score' => $score + 10,
                'reason' => 'Match ricerca WordPress',
                'edit_url' => get_edit_post_link((int)$p->ID, 'raw'),
            ));
        }
        return $items;
    }

    private static function search_knowledge_items($query) {
        global $wpdb;
        if (in_array(ALMA_AI_Content_Agent_Store::table('knowledge_items'), ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array(); }
        $table = ALMA_AI_Content_Agent_Store::table('knowledge_items');
        $chunk_table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $like = '%' . $wpdb->esc_like($query['text']) . '%';
        $sql = $wpdb->prepare("SELECT id,source_type,source_id,title,normalized_excerpt,keywords,destination,travel_theme,usage_mode,status FROM $table WHERE status='active' AND (title LIKE %s OR normalized_excerpt LIKE %s OR keywords LIKE %s OR destination LIKE %s OR travel_theme LIKE %s) ORDER BY indexed_at DESC LIMIT 60", $like,$like,$like,$like,$like);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $chunk_map = array();
        if (!empty($rows) && !in_array(ALMA_AI_Content_Agent_Store::table('content_chunks'), ALMA_AI_Content_Agent_Store::missing_tables(), true)) {
            $ids = array_map('absint', wp_list_pluck($rows, 'id'));
            $in = implode(',', array_fill(0, count($ids), '%d'));
            $chunk_like = '%' . $wpdb->esc_like($query['text']) . '%';
            $chunks = $wpdb->get_results($wpdb->prepare("SELECT knowledge_item_id, normalized_text FROM $chunk_table WHERE knowledge_item_id IN ($in) AND normalized_text LIKE %s LIMIT 120", array_merge($ids, array($chunk_like))), ARRAY_A);
            foreach ($chunks as $c) { $chunk_map[(int)$c['knowledge_item_id']][] = (string)$c['normalized_text']; }
        }
        $items = array();
        foreach ($rows as $r) {
            $stype = (string)$r['source_type'];
            $group = in_array($stype, array('post','page','affiliate_link','document_txt','document_attachment'), true) ? $stype : 'other';
            $text = implode(' ', array($r['title'],$r['normalized_excerpt'],$r['keywords'],$r['destination'],$r['travel_theme']));
            $score = self::score_text($query, $text);
            if (!empty($chunk_map[(int)$r['id']])) { $score += 12; }
            if (($r['usage_mode'] ?? '') === 'knowledge') { $score += 5; }
            $items[] = self::result(array(
                'key' => 'kb:' . $stype . ':' . (int)$r['id'],
                'source_type' => $group,
                'source_id' => (int)$r['source_id'],
                'title' => sanitize_text_field($r['title']),
                'excerpt' => wp_trim_words(wp_strip_all_tags((string)$r['normalized_excerpt']), 20),
                'score' => $score,
                'reason' => !empty($chunk_map[(int)$r['id']]) ? 'Match su knowledge e chunk' : 'Match su knowledge item',
                'edit_url' => '',
                'dedupe_ref' => self::build_dedupe_ref($stype, $r),
            ));
        }
        return $items;
    }

    private static function search_sources($query) { global $wpdb; if (in_array(ALMA_AI_Content_Agent_Store::table('sources'), ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array(); }
        $table = ALMA_AI_Content_Agent_Store::table('sources'); $like = '%' . $wpdb->esc_like($query['text']) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,name,source_type,source_url,notes,is_active,last_test_at,last_error FROM $table WHERE is_active=1 AND (name LIKE %s OR source_url LIKE %s OR source_type LIKE %s OR notes LIKE %s) ORDER BY updated_at DESC LIMIT 20",$like,$like,$like,$like), ARRAY_A);
        $items=array(); foreach($rows as $r){ $score=self::score_text($query, implode(' ', array($r['name'],$r['source_type'],$r['notes']))) + 8; $items[]=self::result(array('key'=>'src:'.$r['id'],'source_type'=>'source_online','source_id'=>(int)$r['id'],'title'=>sanitize_text_field($r['name']),'excerpt'=>wp_trim_words((string)($r['notes']?:$r['source_url']),16),'score'=>$score,'reason'=>'Fonte online attiva coerente','edit_url'=>esc_url_raw($r['source_url']))); }
        return $items; }

    private static function search_media($query) { global $wpdb; if (in_array(ALMA_AI_Content_Agent_Store::table('media_index'), ALMA_AI_Content_Agent_Store::missing_tables(), true)) { return array(); }
        $table = ALMA_AI_Content_Agent_Store::table('media_index'); $like = '%' . $wpdb->esc_like($query['text']) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,attachment_id,filename,title,alt_text,caption,description,keywords,destinations,manual_notes FROM $table WHERE filename LIKE %s OR title LIKE %s OR alt_text LIKE %s OR caption LIKE %s OR description LIKE %s OR keywords LIKE %s OR destinations LIKE %s OR manual_notes LIKE %s ORDER BY indexed_at DESC LIMIT 30",$like,$like,$like,$like,$like,$like,$like,$like), ARRAY_A);
        if (!is_array($rows)) { return array(); }
        $items=array(); foreach($rows as $r){ $score=self::score_text($query, implode(' ', $r)) + 6; $items[]=self::result(array('key'=>'media:'.$r['id'],'source_type'=>'media','source_id'=>(int)$r['attachment_id'],'title'=>sanitize_text_field($r['title']?:$r['filename']),'excerpt'=>wp_trim_words((string)($r['alt_text']?:$r['caption']),16),'score'=>$score,'reason'=>'Match su metadata media','edit_url'=>get_edit_post_link((int)$r['attachment_id'], 'raw'))); }
        return $items; }

    private static function build_dedupe_ref($stype, $row) {
        $source_id = isset($row['source_id']) ? (int)$row['source_id'] : 0;
        if (($stype === 'document_txt' || $stype === 'document_attachment') && !empty($row['id'])) {
            return $stype . ':kb:' . (int)$row['id'];
        }
        return $stype . ':' . $source_id;
    }

    private static function score_text($query, $text) {
        $text = strtolower((string)$text); $score = 0;
        if (!empty($query['destination']) && strpos($text, strtolower($query['destination'])) !== false) { $score += 30; }
        if (!empty($query['theme']) && strpos($text, strtolower($query['theme'])) !== false) { $score += 25; }
        foreach ($query['terms'] as $t) { if (strpos($text, $t) !== false) { $score += 6; } }
        return min(100, $score);
    }

    private static function dedupe($results) {
        $seen=array(); foreach($results as $r){ $k = !empty($r['dedupe_ref']) ? $r['dedupe_ref'] : $r['source_type'].':'.$r['source_id']; if (!isset($seen[$k]) || $seen[$k]['score'] < $r['score']) { $seen[$k] = $r; } }
        return array_values($seen);
    }

    private static function group_and_rank($results) {
        $groups = array('post'=>array(),'page'=>array(),'affiliate_link'=>array(),'document_txt'=>array(),'source_online'=>array(),'media'=>array(),'other'=>array());
        foreach ($results as $r) { $k = isset($groups[$r['source_type']]) ? $r['source_type'] : 'other'; $groups[$k][] = $r; }
        foreach ($groups as $k => $rows) {
            usort($rows, function($a,$b){ return $b['score'] <=> $a['score']; });
            $rows = array_slice($rows, 0, self::MAX_PER_GROUP);
            $postSelected = 0;
            foreach ($rows as &$row) {
                $row['preselected'] = $row['score'] >= 24;
                if ($k === 'post') { if ($row['preselected'] && $postSelected >= 3) { $row['preselected'] = false; } if ($row['preselected']) { $postSelected++; } }
                $row['selectable'] = true;
                $row['score_level'] = $row['score'] >= 60 ? 'Alta' : ($row['score'] >= 30 ? 'Media' : 'Bassa');
            }
            $groups[$k] = $rows;
        }
        return $groups;
    }

    private static function result($data) {
        $labels = array('post'=>'Post','page'=>'Pagine','affiliate_link'=>'Affiliate Links','document_txt'=>'Documenti TXT','source_online'=>'Fonti online AI','media'=>'Media','other'=>'Altro');
        $safe_key = sanitize_key(str_replace(':','_', (string)$data['key']));
        return array('result_id'=>$safe_key,'key'=>$data['key'],'source_type'=>$data['source_type'],'source_label'=>$labels[$data['source_type']] ?? 'Altro','source_id'=>(int)($data['source_id'] ?? 0),'title'=>sanitize_text_field($data['title'] ?? ''),'excerpt'=>sanitize_textarea_field($data['excerpt'] ?? ''),'score'=>(int)($data['score'] ?? 0),'reason'=>sanitize_text_field($data['reason'] ?? ''),'edit_url'=>esc_url_raw($data['edit_url'] ?? ''),'selectable'=>true,'preselected'=>false,'dedupe_ref'=>sanitize_text_field($data['dedupe_ref'] ?? ''));
    }
}
