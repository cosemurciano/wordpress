<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Quality_Checker {
    public static function validate_payload($payload, $candidate_affiliate_ids = array(), $candidate_image_ids = array()) {
        $warnings = array();
        $title = sanitize_text_field($payload['title'] ?? '');
        $excerpt = sanitize_textarea_field($payload['excerpt'] ?? '');
        $content = wp_kses_post((string)($payload['content_html'] ?? ''));
        if ($title === '') { $warnings[] = 'Titolo vuoto.'; }
        if (trim(wp_strip_all_tags($content)) === '') { $warnings[] = 'Contenuto vuoto.'; }
        if ($excerpt === '') { $excerpt = wp_trim_words(wp_strip_all_tags($content), 30); $warnings[] = 'Excerpt generato automaticamente.'; }
        if (stripos($content, 'link affiliati') === false) {
            $content = '<p>Questo articolo può contenere link affiliati: se acquisti tramite questi link, potremmo ricevere una commissione senza costi aggiuntivi per te.</p>' . $content;
            $warnings[] = 'Disclosure affiliati aggiunta automaticamente dal plugin.';
        }
        $content = preg_replace('#<(script|iframe|embed)[^>]*>.*?</\1>#is', '', $content);
        $content = preg_replace_callback('/https?:\/\/[^\s<>"\']+/i', function($m) use (&$warnings){
            $url = esc_url_raw($m[0]);
            if (strpos($url, home_url()) === 0) { return $m[0]; }
            $warnings[] = 'URL grezzo rimosso per sicurezza QA.';
            return '';
        }, $content);
        $used = array();
        $content = preg_replace_callback('/\[affiliate_link\b([^\]]*)\]/i', function($matches) use (&$used, &$warnings, $candidate_affiliate_ids){
            $attrs = shortcode_parse_atts(trim((string)$matches[1]));
            $raw_id = $attrs['id'] ?? '';
            $id = is_numeric($raw_id) ? absint($raw_id) : 0;
            if (!$id) { $warnings[] = 'Shortcode affiliato rimosso: ID mancante o non numerico.'; return ''; }
            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link' || !in_array($id, $candidate_affiliate_ids, true) || !in_array($post->post_status, array('publish','private','draft','pending'), true)) { $warnings[] = 'Shortcode affiliato non valido rimosso (ID '.$id.').'; return ''; }
            $used[] = $id;
            return $matches[0];
        }, $content);
        $featured = absint($payload['featured_image_id'] ?? 0);
        if ($featured && (!in_array($featured, $candidate_image_ids, true) || get_post_type($featured) !== 'attachment')) { $warnings[]='Featured image non valida rimossa.'; $featured=0; }
        $inline = array_values(array_filter(array_map('absint', (array)($payload['inline_image_ids'] ?? array()))));
        $inline = array_values(array_filter($inline, function($id) use($candidate_image_ids){ return $id > 0 && in_array($id, $candidate_image_ids, true) && get_post_type($id)==='attachment'; }));
        return array('title'=>$title,'slug'=>sanitize_title($payload['slug'] ?? $title),'excerpt'=>$excerpt,'content'=>$content,'featured_image_id'=>$featured,'inline_image_ids'=>$inline,'affiliate_links_used'=>array_values(array_unique($used)),'warnings'=>$warnings);
    }
}
