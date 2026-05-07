<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Quality_Checker {
    private static function allowed_content_html() {
        $allowed = function_exists('wp_kses_allowed_html') ? wp_kses_allowed_html('post') : array();
        if (!is_array($allowed)) { $allowed = array(); }
        $allowed['a'] = array_merge((array)($allowed['a'] ?? array()), array(
            'href' => true,
            'target' => true,
            'rel' => true,
            'class' => true,
        ));
        $allowed['img'] = array_merge((array)($allowed['img'] ?? array()), array(
            'src' => true,
            'alt' => true,
            'class' => true,
            'width' => true,
            'height' => true,
            'loading' => true,
        ));
        $allowed['figure'] = array_merge((array)($allowed['figure'] ?? array()), array(
            'class' => true,
        ));
        return $allowed;
    }

    private static function sanitize_content_html($content) {
        return wp_kses((string)$content, self::allowed_content_html(), wp_allowed_protocols());
    }

    private static function normalize_lookup_text($text) {
        $text = html_entity_decode(wp_strip_all_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = remove_accents($text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string)$text));
    }

    private static function normalize_url_key($url) {
        $url = html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = esc_url_raw(trim($url));
        if ($url === '') { return ''; }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) { return rtrim($url, '/'); }
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) ? preg_replace('#/+#', '/', (string)$parts['path']) : '';
        $query = isset($parts['query']) ? '?' . (string)$parts['query'] : '';
        return rtrim($scheme . '://' . $host . $port . $path . $query, '/');
    }

    private static function build_affiliate_link_index($candidate_affiliate_ids, $candidate_affiliate_images) {
        $index = array(
            'ids' => array_values(array_unique(array_filter(array_map('absint', (array)$candidate_affiliate_ids)))),
            'by_image_url' => array(),
            'by_image_key' => array(),
            'by_title' => array(),
            'by_affiliate_url' => array(),
            'image_urls' => array(),
        );

        foreach ((array)$candidate_affiliate_images as $image) {
            if (!is_array($image)) { continue; }
            $image_url = esc_url_raw((string)($image['image_url'] ?? ($image['url'] ?? '')));
            $affiliate_url = esc_url_raw((string)($image['affiliate_url'] ?? ''));
            $id = absint($image['affiliate_link_id'] ?? ($image['id'] ?? 0));
            $title = sanitize_text_field((string)($image['title'] ?? ''));
            if ($affiliate_url === '' || !wp_http_validate_url($affiliate_url)) { continue; }

            $record = array(
                'id' => $id,
                'affiliate_link_id' => $id,
                'attachment_id' => absint($image['attachment_id'] ?? 0),
                'title' => $title,
                'affiliate_url' => $affiliate_url,
                'image_url' => $image_url,
                'alt' => sanitize_text_field((string)($image['alt'] ?? ($image['image_alt'] ?? ''))),
                'source' => sanitize_text_field((string)($image['source'] ?? ($image['image_source'] ?? ''))),
            );

            $affiliate_key = self::normalize_url_key($affiliate_url);
            if ($affiliate_key !== '') { $index['by_affiliate_url'][$affiliate_key] = $record; }
            if ($image_url !== '' && wp_http_validate_url($image_url)) {
                $index['image_urls'][$image_url] = $image_url;
                $index['by_image_url'][$image_url] = $record;
                $image_key = self::normalize_url_key($image_url);
                if ($image_key !== '') { $index['by_image_key'][$image_key] = $record; }
            }
            $title_key = self::normalize_lookup_text($title);
            if ($title_key !== '') { $index['by_title'][$title_key] = $record; }
        }

        return $index;
    }

    private static function remove_generic_affiliate_disclosures($content, &$warnings) {
        $before = (string)$content;
        $patterns = array(
            '#<(?:p|div|aside|section)\b[^>]*>\s*(?:<[^>]+>\s*)*(?:Questo\s+articolo\s+(?:pu[oò]\s+contenere|contiene)\s+link\s+(?:affiliati|di\s+affiliazione)[\s\S]{0,260}?(?:commissione|senza\s+costi\s+aggiuntivi)[\s\S]{0,160}?)\s*</(?:p|div|aside|section)>#iu',
            '#<(?:p|div|aside|section)\b[^>]*>\s*(?:<[^>]+>\s*)*(?:Se\s+acquisti\s+tramite\s+questi\s+link[\s\S]{0,220}?commissione[\s\S]{0,160}?)\s*</(?:p|div|aside|section)>#iu',
            '#<(?:p|div|aside|section)\b[^>]*>\s*(?:<[^>]+>\s*)*(?:Potremmo\s+ricevere\s+una\s+commissione[\s\S]{0,180}?senza\s+costi\s+aggiuntivi[\s\S]{0,120}?)\s*</(?:p|div|aside|section)>#iu',
            '#\bQuesto\s+articolo\s+(?:pu[oò]\s+contenere|contiene)\s+link\s+(?:affiliati|di\s+affiliazione)[^<\r\n.!?]*(?:[.!?]|$)#iu',
            '#\bSe\s+acquisti\s+tramite\s+questi\s+link[^<\r\n.!?]*commissione[^<\r\n.!?]*(?:[.!?]|$)#iu',
            '#\bPotremmo\s+ricevere\s+una\s+commissione[^<\r\n.!?]*senza\s+costi\s+aggiuntivi[^<\r\n.!?]*(?:[.!?]|$)#iu',
        );
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', (string)$content);
        }
        if ((string)$content !== $before) {
            $warnings[] = 'Disclosure affiliata generica rimossa dal contenuto.';
        }
        return $content;
    }

    private static function find_record_by_image_src($src, $index) {
        $src = esc_url_raw(html_entity_decode((string)$src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '') { return array(); }
        if (isset($index['by_image_url'][$src])) { return $index['by_image_url'][$src]; }
        $key = self::normalize_url_key($src);
        return $key !== '' && isset($index['by_image_key'][$key]) ? $index['by_image_key'][$key] : array();
    }

    private static function set_safe_affiliate_anchor_attrs($anchor, $href) {
        $anchor->setAttribute('href', $href);
        $anchor->setAttribute('target', '_blank');
        $anchor->setAttribute('rel', 'nofollow sponsored noopener');
    }

    private static function unwrap_node($node) {
        $parent = $node->parentNode;
        if (!$parent) { return; }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function dom_inner_html($dom) {
        $html = '';
        if (!$dom->documentElement) { return $html; }
        foreach ($dom->documentElement->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        return $html;
    }

    private static function repair_affiliate_anchors_with_dom($content, $index, &$warnings, &$added_urls) {
        if (!class_exists('DOMDocument')) { return self::repair_affiliate_anchors_with_regex($content, $index, $warnings, $added_urls); }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $flags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) { $flags |= LIBXML_HTML_NOIMPLIED; }
        if (defined('LIBXML_HTML_NODEFDTD')) { $flags |= LIBXML_HTML_NODEFDTD; }
        $loaded = $dom->loadHTML('<div>' . mb_convert_encoding((string)$content, 'HTML-ENTITIES', 'UTF-8') . '</div>', $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) { return $content; }

        $anchors = array();
        foreach ($dom->getElementsByTagName('a') as $anchor) { $anchors[] = $anchor; }
        foreach ($anchors as $anchor) {
            $href = trim((string)$anchor->getAttribute('href'));
            if ($href !== '') { continue; }

            $imgs = $anchor->getElementsByTagName('img');
            if ($imgs->length > 0) {
                $img = $imgs->item(0);
                $record = self::find_record_by_image_src($img ? $img->getAttribute('src') : '', $index);
                if (!empty($record['affiliate_url'])) {
                    self::set_safe_affiliate_anchor_attrs($anchor, $record['affiliate_url']);
                    $added_urls[] = $record['affiliate_url'];
                    $warnings[] = 'Href affiliato aggiunto a immagine affiliata senza link.';
                    continue;
                }
                self::unwrap_node($anchor);
                $warnings[] = 'Link senza href rimosso perché non associabile a un link affiliato del payload.';
                continue;
            }

            $text_key = self::normalize_lookup_text($anchor->textContent);
            if ($text_key !== '' && isset($index['by_title'][$text_key])) {
                $record = $index['by_title'][$text_key];
                self::set_safe_affiliate_anchor_attrs($anchor, $record['affiliate_url']);
                $added_urls[] = $record['affiliate_url'];
                $warnings[] = 'Href affiliato aggiunto a link testuale senza href.';
                continue;
            }
            self::unwrap_node($anchor);
            $warnings[] = 'Link senza href rimosso perché non associabile a un link affiliato del payload.';
        }

        return self::dom_inner_html($dom);
    }

    private static function repair_affiliate_anchors_with_regex($content, $index, &$warnings, &$added_urls) {
        // Fallback mirato: usato solo se DOMDocument non è disponibile nell'ambiente PHP.
        $content = preg_replace_callback('#<a\b(?![^>]*\shref=)[^>]*>(\s*<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>\s*)</a>#isu', function($matches) use ($index, &$warnings, &$added_urls) {
            $record = self::find_record_by_image_src($matches[2], $index);
            if (empty($record['affiliate_url'])) { $warnings[] = 'Link senza href rimosso perché non associabile a un link affiliato del payload.'; return $matches[1]; }
            $added_urls[] = $record['affiliate_url'];
            $warnings[] = 'Href affiliato aggiunto a immagine affiliata senza link.';
            return '<a href="' . esc_url($record['affiliate_url']) . '" target="_blank" rel="nofollow sponsored noopener">' . $matches[1] . '</a>';
        }, (string)$content);
        $content = preg_replace_callback('#<a\b(?![^>]*\shref=)[^>]*>(.*?)</a>#isu', function($matches) use ($index, &$warnings, &$added_urls) {
            $text_key = self::normalize_lookup_text($matches[1]);
            if ($text_key !== '' && isset($index['by_title'][$text_key])) {
                $record = $index['by_title'][$text_key];
                $added_urls[] = $record['affiliate_url'];
                $warnings[] = 'Href affiliato aggiunto a link testuale senza href.';
                return '<a href="' . esc_url($record['affiliate_url']) . '" target="_blank" rel="nofollow sponsored noopener">' . $matches[1] . '</a>';
            }
            $warnings[] = 'Link senza href rimosso perché non associabile a un link affiliato del payload.';
            return $matches[1];
        }, (string)$content);
        return $content;
    }

    private static function remove_bare_external_text_urls($content, $candidate_image_urls, $affiliate_url_index, &$warnings) {
        $parts = preg_split('/(<[^>]+>)/', (string)$content, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') { continue; }
            $parts[$i] = preg_replace_callback('/https?:\/\/[^\s<>"\']+/i', function($m) use ($candidate_image_urls, $affiliate_url_index, &$warnings) {
                $url = esc_url_raw($m[0]);
                $key = self::normalize_url_key($url);
                if (strpos($url, home_url()) === 0 || isset($candidate_image_urls[$url]) || ($key !== '' && isset($affiliate_url_index[$key]))) { return $m[0]; }
                $warnings[] = 'URL grezzo rimosso per sicurezza QA.';
                return '';
            }, $part);
        }
        return implode('', $parts);
    }

    private static function collect_final_affiliate_usage($content, $index) {
        $urls = array();
        $media = array();
        $images_used = array();
        if (!class_exists('DOMDocument')) { return array($urls, $media, $images_used); }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $flags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) { $flags |= LIBXML_HTML_NOIMPLIED; }
        if (defined('LIBXML_HTML_NODEFDTD')) { $flags |= LIBXML_HTML_NODEFDTD; }
        $loaded = $dom->loadHTML('<div>' . mb_convert_encoding((string)$content, 'HTML-ENTITIES', 'UTF-8') . '</div>', $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) { return array($urls, $media, $images_used); }

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = esc_url_raw(html_entity_decode((string)$anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $href_key = self::normalize_url_key($href);
            if ($href_key === '' || !isset($index['by_affiliate_url'][$href_key])) { continue; }
            $record = $index['by_affiliate_url'][$href_key];
            $urls[$record['affiliate_url']] = $record['affiliate_url'];

            foreach ($anchor->getElementsByTagName('img') as $img) {
                $image_record = self::find_record_by_image_src($img->getAttribute('src'), $index);
                if (empty($image_record['image_url']) || self::normalize_url_key($image_record['affiliate_url']) !== $href_key) { continue; }
                $alt = sanitize_text_field((string)$img->getAttribute('alt'));
                $entry = array(
                    'image_url' => $image_record['image_url'],
                    'affiliate_link_id' => absint($image_record['affiliate_link_id'] ?? 0),
                    'id' => absint($image_record['id'] ?? ($image_record['affiliate_link_id'] ?? 0)),
                    'affiliate_url' => $image_record['affiliate_url'],
                    'alt' => $alt !== '' ? $alt : sanitize_text_field((string)($image_record['alt'] ?? '')),
                    'source' => sanitize_text_field((string)($image_record['source'] ?? '')),
                );
                $media[$entry['image_url']] = $entry;
                $images_used[$entry['image_url']] = array(
                    'affiliate_link_id' => $entry['affiliate_link_id'],
                    'attachment_id' => absint($image_record['attachment_id'] ?? 0),
                    'url' => $entry['image_url'],
                    'affiliate_url' => $entry['affiliate_url'],
                    'alt' => $entry['alt'],
                    'source' => $entry['source'],
                );
            }
        }
        return array(array_values($urls), array_values($media), array_values($images_used));
    }

    private static function dedupe_strings($items) {
        $out = array();
        foreach ((array)$items as $item) {
            $item = is_scalar($item) ? trim((string)$item) : '';
            if ($item !== '') { $out[$item] = $item; }
        }
        return array_values($out);
    }

    public static function validate_payload($payload, $candidate_affiliate_ids = array(), $candidate_image_ids = array(), $candidate_affiliate_images = array()) {
        $warnings = array();
        $title = sanitize_text_field($payload['title'] ?? '');
        $excerpt = sanitize_textarea_field($payload['excerpt'] ?? '');
        $content = self::sanitize_content_html((string)($payload['content_html'] ?? ($payload['content'] ?? '')));
        if ($title === '') { $warnings[] = 'Titolo vuoto.'; }
        if (trim(wp_strip_all_tags($content)) === '') { $warnings[] = 'Contenuto vuoto.'; }
        if ($excerpt === '') { $excerpt = wp_trim_words(wp_strip_all_tags($content), 30); $warnings[] = 'Excerpt generato automaticamente.'; }

        $content = self::remove_generic_affiliate_disclosures($content, $warnings);
        $content = preg_replace('#<(script|iframe|embed)[^>]*>.*?</\1>#is', '', $content);

        $index = self::build_affiliate_link_index($candidate_affiliate_ids, $candidate_affiliate_images);
        $candidate_image_urls = $index['image_urls'];
        foreach ((array)$payload['media_used'] as $media_item) {
            $url = is_array($media_item) ? ($media_item['image_url'] ?? ($media_item['url'] ?? '')) : $media_item;
            $url = esc_url_raw((string)$url);
            if ($url !== '' && isset($candidate_image_urls[$url])) { $candidate_image_urls[$url] = $url; }
        }

        $added_urls = array();
        $content = self::repair_affiliate_anchors_with_dom($content, $index, $warnings, $added_urls);
        $content = self::sanitize_content_html($content);

        $content = preg_replace_callback('/<img\b[^>]*>/i', function($matches) use (&$warnings, $candidate_image_urls){
            $tag = $matches[0];
            if (!preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag, $m)) { $warnings[] = 'Immagine senza src rimossa.'; return ''; }
            $src = esc_url_raw(html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($src === '' || !isset($candidate_image_urls[$src])) { $warnings[] = 'Immagine non presente tra candidate rimossa.'; return ''; }
            if (preg_match('/\salt=["\']([^"\']*)["\']/i', $tag)) { return $tag; }
            return preg_replace('/<img\b/i', '<img alt=""', $tag, 1);
        }, $content);

        $content = self::remove_bare_external_text_urls($content, $candidate_image_urls, $index['by_affiliate_url'], $warnings);

        $used = array();
        $shortcodes_used = array();
        $content = preg_replace_callback('/\[affiliate_link\b([^\]]*)\]/i', function($matches) use (&$used, &$shortcodes_used, &$warnings, $candidate_affiliate_ids){
            $attrs = shortcode_parse_atts(trim((string)$matches[1]));
            $raw_id = $attrs['id'] ?? '';
            $id = is_numeric($raw_id) ? absint($raw_id) : 0;
            if (!$id) { $warnings[] = 'Shortcode affiliato rimosso: ID mancante o non numerico.'; return ''; }
            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link' || !in_array($id, $candidate_affiliate_ids, true) || !in_array($post->post_status, array('publish','private','draft','pending'), true)) { $warnings[] = 'Shortcode affiliato non valido rimosso (ID '.$id.').'; return ''; }
            $used[] = $id;
            $shortcodes_used[] = $matches[0];
            return $matches[0];
        }, $content);
        $featured = absint($payload['featured_image_id'] ?? 0);
        if ($featured && (!in_array($featured, $candidate_image_ids, true) || get_post_type($featured) !== 'attachment')) { $warnings[]='Featured image non valida rimossa.'; $featured=0; }
        $inline = array_values(array_filter(array_map('absint', (array)($payload['inline_image_ids'] ?? array()))));
        $inline = array_values(array_filter($inline, function($id) use($candidate_image_ids){ return $id > 0 && in_array($id, $candidate_image_ids, true) && get_post_type($id)==='attachment'; }));

        if (preg_match('/placeholder(?:\.com|\.it)|via\.placeholder|placehold\.co|loremflickr|dummyimage/i', $content)) {
            $warnings[] = 'Placeholder immagine rilevato e rimosso.';
            $content = preg_replace('/https?:\/\/(?:[^\s<>"\']*(?:placeholder|placehold|dummyimage|loremflickr)[^\s<>"\']*)/i', '', $content);
        }

        $content = self::sanitize_content_html($content);
        list($affiliate_urls_used, $media_used, $affiliate_images_used) = self::collect_final_affiliate_usage($content, $index);
        $affiliate_urls_used = self::dedupe_strings($affiliate_urls_used);
        $shortcodes_used = self::dedupe_strings(array_merge((array)($payload['affiliate_shortcodes_used'] ?? array()), $shortcodes_used));

        return array(
            'title'=>$title,
            'slug'=>sanitize_title($payload['slug'] ?? $title),
            'excerpt'=>$excerpt,
            'content'=>$content,
            'featured_image_id'=>$featured,
            'inline_image_ids'=>$inline,
            'affiliate_links_used'=>array_values(array_unique($used)),
            'affiliate_shortcodes_used'=>$shortcodes_used,
            'affiliate_urls_used'=>$affiliate_urls_used,
            'media_used'=>array_values($media_used),
            'affiliate_images_used'=>array_values($affiliate_images_used),
            'warnings'=>array_values(array_unique($warnings)),
        );
    }
}
