<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Viator_Media_Resolver {
    public function resolve($item) {
        $item = $this->array_from_mixed($item);
        $warnings = array();
        $images = $this->list_from_mixed($item['images'] ?? array());
        $images_count = count($images);
        $variants_count = 0;
        $best = null;

        if ($images_count === 0) {
            $warnings[] = __('Nessuna immagine Viator disponibile.', 'affiliate-link-manager-ai');
        }

        foreach ($images as $image_index => $raw_image) {
            $image = $this->array_from_mixed($raw_image);
            if (empty($image)) {
                $warnings[] = __('Immagine Viator ignorata: struttura non valida.', 'affiliate-link-manager-ai');
                continue;
            }

            $image_source = sanitize_text_field((string)($image['imageSource'] ?? $image['source'] ?? ''));
            $caption = sanitize_text_field((string)($image['caption'] ?? $image['altText'] ?? $image['alt'] ?? ''));
            $is_cover = $this->is_truthy($image['isCover'] ?? false);
            $variants = $this->list_from_mixed($image['variants'] ?? array());
            if (empty($variants) && !empty($image['url'])) {
                $variants = array(array(
                    'url' => $image['url'],
                    'width' => $image['width'] ?? null,
                    'height' => $image['height'] ?? null,
                ));
            }

            $variants_count += count($variants);
            if (empty($variants)) {
                $warnings[] = __('Immagine Viator senza varianti utilizzabili.', 'affiliate-link-manager-ai');
                continue;
            }

            foreach ($variants as $variant_index => $raw_variant) {
                $variant = $this->array_from_mixed($raw_variant);
                $url = $this->sanitize_valid_url($variant['url'] ?? '');
                if ($url === '') {
                    continue;
                }

                $width = isset($variant['width']) ? absint($variant['width']) : 0;
                $height = isset($variant['height']) ? absint($variant['height']) : 0;
                $candidate = array(
                    'has_image' => true,
                    'featured_image_url' => $url,
                    'image_source' => $image_source,
                    'caption' => $caption,
                    'is_cover' => $is_cover,
                    'width' => $width,
                    'height' => $height,
                    'variant_url' => $url,
                    'images_count' => $images_count,
                    'variants_count' => $variants_count,
                    'warnings' => array(),
                    '_score' => $this->score_candidate($is_cover, $image_source, $width, $height, $image_index, $variant_index),
                );

                if ($best === null || $candidate['_score'] > $best['_score']) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null) {
            if ($images_count > 0) {
                $warnings[] = __('Nessuna variante immagine Viator contiene un URL valido.', 'affiliate-link-manager-ai');
            }
            return array(
                'has_image' => false,
                'featured_image_url' => '',
                'image_source' => '',
                'caption' => '',
                'is_cover' => false,
                'width' => 0,
                'height' => 0,
                'variant_url' => '',
                'images_count' => $images_count,
                'variants_count' => $variants_count,
                'warnings' => array_values(array_unique($warnings)),
            );
        }

        unset($best['_score']);
        $best['variants_count'] = $variants_count;
        $best['warnings'] = array_values(array_unique($warnings));
        return $best;
    }

    private function score_candidate($is_cover, $image_source, $width, $height, $image_index, $variant_index) {
        $score = 0;
        if ($is_cover) {
            $score += 1000000;
        }
        if ($this->is_supplier_source($image_source)) {
            $score += 100000;
        }

        if ($width > 0 && $height > 0) {
            $area = $width * $height;
            $score += min($area, 1920 * 1080) / 1000;
            if ($width < 240 || $height < 180) {
                $score -= 5000;
            }
            if ($width >= 640 && $height >= 360) {
                $score += 2000;
            }
            if ($width > 3000 || $height > 3000) {
                $score -= 250;
            }
        } else {
            $score += 100;
        }

        $score -= ((int)$image_index * 10) + (int)$variant_index;
        return $score;
    }

    public function is_supplier_source($image_source) {
        $source = strtoupper(trim((string)$image_source));
        return $source !== '' && (strpos($source, 'SUPPLIER') !== false || strpos($source, 'SUPPLY') !== false);
    }

    private function sanitize_valid_url($url) {
        $url = is_scalar($url) ? trim((string)$url) : '';
        if ($url === '') {
            return '';
        }
        $url = esc_url_raw($url);
        return ($url !== '' && wp_http_validate_url($url)) ? $url : '';
    }

    private function list_from_mixed($value) {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return array();
        }
        if ($this->is_list($value)) {
            return $value;
        }
        return array($value);
    }

    private function array_from_mixed($value) {
        if (is_object($value)) {
            return (array)$value;
        }
        return is_array($value) ? $value : array();
    }

    private function is_list($array) {
        if (!is_array($array)) {
            return false;
        }
        $expected = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }

    private function is_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, array('1', 'true', 'yes', 'y'), true);
    }
}
