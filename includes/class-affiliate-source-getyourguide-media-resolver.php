<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_GetYourGuide_Media_Resolver {
    public function resolve($item) {
        $item = is_array($item) ? $item : array();
        $warnings = array();
        $candidates = $this->collect_candidates($item);
        if (empty($candidates)) {
            return array('has_image'=>false,'featured_image_url'=>'','image_source'=>'','caption'=>'','width'=>0,'height'=>0,'images_count'=>0,'warnings'=>array(__('Immagini GetYourGuide non presenti nel payload.', 'affiliate-link-manager-ai')));
        }

        foreach ($candidates as $candidate) {
            $url = esc_url_raw((string)($candidate['url'] ?? ''));
            if ($url !== '' && wp_http_validate_url($url)) {
                return array(
                    'has_image' => true,
                    'featured_image_url' => $url,
                    'image_source' => sanitize_text_field((string)($candidate['source'] ?? 'getyourguide_payload')),
                    'caption' => sanitize_text_field((string)($candidate['caption'] ?? ($item['title'] ?? $item['name'] ?? ''))),
                    'width' => absint($candidate['width'] ?? 0),
                    'height' => absint($candidate['height'] ?? 0),
                    'images_count' => count($candidates),
                    'warnings' => $warnings,
                );
            }
            $warnings[] = __('URL immagine GetYourGuide non valido ignorato.', 'affiliate-link-manager-ai');
        }

        return array('has_image'=>false,'featured_image_url'=>'','image_source'=>'','caption'=>'','width'=>0,'height'=>0,'images_count'=>count($candidates),'warnings'=>array_unique($warnings));
    }

    private function collect_candidates($item) {
        $out = array();
        $this->add_candidate($out, $item['picture_url'] ?? '', 'picture_url', $item);
        $this->add_candidate($out, $item['image_url'] ?? '', 'image_url', $item);
        $this->add_candidate($out, $item['thumbnail_url'] ?? '', 'thumbnail_url', $item);
        $this->add_candidate($out, $item['cover_image_url'] ?? '', 'cover_image_url', $item);

        $images = array();
        foreach (array('pictures','images','photos','media') as $key) {
            if (is_array($item[$key] ?? null)) { $images = array_merge($images, (array)$item[$key]); }
        }
        foreach ($images as $img) {
            if (is_string($img)) { $this->add_candidate($out, $img, 'images[]', $item); continue; }
            if (!is_array($img)) { continue; }
            $url = $img['url'] ?? $img['src'] ?? $img['image_url'] ?? $img['large'] ?? $img['medium'] ?? $img['small'] ?? '';
            if ($url === '' && is_array($img['urls'] ?? null)) {
                $url = $img['urls']['large'] ?? $img['urls']['original'] ?? $img['urls']['medium'] ?? $img['urls']['small'] ?? '';
            }
            $this->add_candidate($out, $url, 'images[]', $item, $img);
        }
        return $out;
    }

    private function add_candidate(&$out, $url, $source, $item, $img = array()) {
        if (!is_scalar($url) || trim((string)$url) === '') { return; }
        $out[] = array(
            'url' => (string)$url,
            'source' => $source,
            'caption' => is_array($img) ? (string)($img['caption'] ?? $img['alt'] ?? ($item['title'] ?? '')) : (string)($item['title'] ?? ''),
            'width' => is_array($img) ? absint($img['width'] ?? 0) : 0,
            'height' => is_array($img) ? absint($img['height'] ?? 0) : 0,
        );
    }
}
