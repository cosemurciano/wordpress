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
            $image = class_exists('ALMA_AI_Content_Agent_Affiliate_Index') ? ALMA_AI_Content_Agent_Affiliate_Index::get_image_data($post->ID) : array();
            $image_url = esc_url_raw((string)($image['featured_image_url'] ?? ''));
            if ($image_url !== '' && !wp_http_validate_url($image_url)) { $image_url = ''; }
            $image_block = $image_url !== '' ? array(
                'has_image' => true,
                'image_url' => $image_url,
                'image_alt' => sanitize_text_field((string)($image['featured_image_alt'] ?? $post->post_title)),
                'image_caption' => sanitize_text_field((string)($image['featured_image_caption'] ?? '')),
                'image_source' => sanitize_text_field((string)($image['image_source'] ?? '')),
                'can_use_in_content' => true,
            ) : array('has_image'=>false,'image_url'=>'','image_alt'=>'','image_caption'=>'','image_source'=>'','can_use_in_content'=>false);
            $items[] = array_merge(array('link_id'=>$post->ID,'title'=>$post->post_title,'detected'=>$destination ?: $theme ?: 'generic','reason'=>'Match con keyword/tema/destinazione','score_match'=>min(100, $score),'shortcode'=>'[affiliate_link id="'.$post->ID.'"]','warning'=>$ctx === '' ? 'Contesto AI non disponibile' : '', 'image'=>$image_block), $image);
            if (count($items) >= $limit) { break; }
        }
        if (empty($items)) { $warnings[] = 'Link affiliati insufficienti per match forte.'; }
        return array('items'=>$items,'warnings'=>$warnings);
    }
}
