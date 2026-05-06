<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Quality_Checker {
    public static function validate_payload($payload, $candidate_affiliate_ids = array(), $candidate_image_ids = array(), $candidate_affiliate_images = array()) {
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

        $candidate_image_urls = array();
        $candidate_images_by_url = array();
        foreach ((array)$candidate_affiliate_images as $image) {
            if (!is_array($image)) { continue; }
            $url = esc_url_raw((string)($image['url'] ?? ''));
            if ($url === '' || !wp_http_validate_url($url)) { continue; }
            $candidate_image_urls[$url] = $url;
            $candidate_images_by_url[$url] = array(
                'affiliate_link_id' => absint($image['affiliate_link_id'] ?? 0),
                'attachment_id' => absint($image['attachment_id'] ?? 0),
                'url' => $url,
            );
        }
        foreach ((array)$payload['media_used'] as $media_url) {
            $url = esc_url_raw((string)$media_url);
            if ($url !== '' && isset($candidate_image_urls[$url])) { $candidate_image_urls[$url] = $url; }
        }

        $content = preg_replace_callback('/<img\b[^>]*>/i', function($matches) use (&$warnings, $candidate_image_urls){
            $tag = $matches[0];
            if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $m)) { $warnings[] = 'Immagine senza src rimossa.'; return ''; }
            $src = esc_url_raw(html_entity_decode((string)$m[1]));
            if ($src === '' || !isset($candidate_image_urls[$src])) { $warnings[] = 'Immagine non presente tra candidate rimossa.'; return ''; }
            if (preg_match('/\salt=["\']([^"\']*)["\']/i', $tag)) { return $tag; }
            return preg_replace('/<img\b/i', '<img alt=""', $tag, 1);
        }, $content);

        $content = preg_replace_callback('/https?:\/\/[^\s<>"\']+/i', function($m) use (&$warnings, $candidate_image_urls){
            $url = esc_url_raw($m[0]);
            if (strpos($url, home_url()) === 0 || isset($candidate_image_urls[$url])) { return $m[0]; }
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

        $affiliate_images_used = array();
        preg_match_all('/<img\b[^>]*\ssrc=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
        $image_use_counts = array_count_values(array_map('esc_url_raw', (array)($matches[1] ?? array())));
        foreach ($image_use_counts as $url => $count) {
            if (!isset($candidate_images_by_url[$url])) { continue; }
            if ($count > 1) { $warnings[] = 'Immagine affiliata duplicata più volte: '.$url; }
            $affiliate_images_used[] = $candidate_images_by_url[$url];
        }

        if (preg_match('/placeholder(?:\.com|\.it)|via\.placeholder|placehold\.co|loremflickr|dummyimage/i', $content)) {
            $warnings[] = 'Placeholder immagine rilevato e rimosso.';
            $content = preg_replace('/https?:\/\/(?:[^\s<>"\']*(?:placeholder|placehold|dummyimage|loremflickr)[^\s<>"\']*)/i', '', $content);
        }

        return array('title'=>$title,'slug'=>sanitize_title($payload['slug'] ?? $title),'excerpt'=>$excerpt,'content'=>$content,'featured_image_id'=>$featured,'inline_image_ids'=>$inline,'affiliate_links_used'=>array_values(array_unique($used)),'affiliate_images_used'=>array_values($affiliate_images_used),'warnings'=>array_values(array_unique($warnings)));
    }
}
