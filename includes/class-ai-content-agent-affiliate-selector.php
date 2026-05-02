<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Affiliate_Selector {
    public static function select_candidates($args = array()) {
        $keywords = sanitize_text_field($args['keyword'] ?? '');
        $theme = sanitize_text_field($args['theme'] ?? '');
        $destination = sanitize_text_field($args['destination'] ?? '');
        $limit = max(1, min(10, absint($args['limit_links'] ?? 5)));

        $posts = get_posts(array('post_type'=>'affiliate_link','post_status'=>'publish','numberposts'=>$limit * 3));
        $items = array(); $warnings = array();
        foreach ($posts as $post) {
            $ctx = (string) get_post_meta($post->ID, '_alma_ai_context', true);
            $blob = strtolower($post->post_title . ' ' . $ctx . ' ' . get_post_meta($post->ID, '_affiliate_description', true));
            $score = 0;
            foreach (array($keywords,$theme,$destination) as $needle) { if ($needle !== '' && str_contains($blob, strtolower($needle))) { $score += 35; } }
            if ($score <= 0) { continue; }
            $items[] = array('link_id'=>$post->ID,'title'=>$post->post_title,'detected'=>$destination ?: $theme ?: 'generic','reason'=>'Match con keyword/tema/destinazione','score_match'=>min(100, $score),'shortcode'=>'[affiliate_link id="'.$post->ID.'"]','warning'=>$ctx === '' ? 'Contesto AI non disponibile' : '');
            if (count($items) >= $limit) { break; }
        }
        if (empty($items)) { $warnings[] = 'Link affiliati insufficienti per match forte.'; }
        return array('items'=>$items,'warnings'=>$warnings);
    }
}
