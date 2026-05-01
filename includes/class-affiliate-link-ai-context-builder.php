<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Link_AI_Context_Builder {
    const DEFAULT_INSTRUCTIONS = 'Usa le informazioni solo come base informativa. Non copiare descrizioni originali del provider. Non usare testi di recensioni. Puoi usare dati aggregati come prezzo indicativo, rating medio, durata, destinazione, inclusioni, esclusioni e policy. Non presentare prezzo o disponibilità come certi. Invita l’utente a verificare i dettagli aggiornati tramite il link affiliato.';

    public function maybe_build_and_store($post_id, $normalized, $source, $force = false) {
        $source_settings = $this->decode_settings($source);
        $policy = $source_settings['ai_context_regeneration_policy'] ?? 'if_hash_changed_or_expired';
        $new_hash = $this->calculate_source_hash($normalized);
        $old_hash = (string) get_post_meta($post_id, '_alma_ai_context_hash', true);

        $expired = $this->is_expired($post_id, $source_settings['ai_context_refresh_interval'] ?? '7d');
        $should_regenerate = $force || $this->should_regenerate($policy, $old_hash, $new_hash, $expired);

        if (!$should_regenerate) {
            return false;
        }

        $context = $this->build_context_text($normalized, $source, $source_settings);
        update_post_meta($post_id, '_alma_ai_context', $context);
        update_post_meta($post_id, '_alma_ai_context_hash', $new_hash);
        update_post_meta($post_id, '_alma_ai_context_updated_at', current_time('mysql'));

        return true;
    }

    private function should_regenerate($policy, $old_hash, $new_hash, $expired) {
        switch ($policy) {
            case 'manual_only':
                return false;
            case 'always_on_import':
                return true;
            case 'only_if_hash_changed':
                return $old_hash !== $new_hash;
            case 'if_hash_changed_or_expired':
            default:
                return ($old_hash !== $new_hash) || $expired;
        }
    }

    private function is_expired($post_id, $interval) {
        if ($interval === 'manual') { return false; }
        $updated_at = get_post_meta($post_id, '_alma_ai_context_updated_at', true);
        if (empty($updated_at)) { return true; }

        $seconds = $this->interval_to_seconds($interval);
        if ($seconds <= 0) { return false; }

        $updated_ts = strtotime($updated_at);
        if (!$updated_ts) { return true; }

        return (time() - $updated_ts) >= $seconds;
    }

    private function interval_to_seconds($interval) {
        $map = array('24h' => DAY_IN_SECONDS, '72h' => 3 * DAY_IN_SECONDS, '7d' => 7 * DAY_IN_SECONDS, '30d' => 30 * DAY_IN_SECONDS);
        return isset($map[$interval]) ? (int) $map[$interval] : 0;
    }

    private function build_context_text($normalized, $source, $source_settings) {
        $item = is_array($normalized['raw_item'] ?? null) ? $normalized['raw_item'] : array();
        $meta = is_array($normalized['meta'] ?? null) ? $normalized['meta'] : array();

        $description = wp_strip_all_tags((string)($item['description'] ?? $normalized['post_content'] ?? ''));
        if (strlen($description) > 400) { $description = substr($description, 0, 400) . '…'; }

        $lines = array(
            'Fonte: ' . ($source['provider_label'] ?? $source['provider'] ?? 'N/D') . '.',
            'Tipo contenuto: esperienza/tour.',
            'Titolo provider: ' . ($normalized['post_title'] ?? 'N/D') . '.',
            'Codice prodotto: ' . ($meta['_alma_external_id'] ?? 'N/D') . '.',
            'URL affiliato: disponibile nel campo dedicato.',
            'Descrizione sintetica: ' . ($description ?: 'N/D') . '.',
            $this->format_destination($item) . '.',
            'Durata: ' . $this->format_duration($item['duration'] ?? 'N/D') . '.',
            'Prezzo indicativo: ' . $this->format_price($item) . '.',
            'Rating aggregato: ' . sanitize_text_field((string)($item['reviews']['combinedAverageRating'] ?? $item['rating'] ?? 'N/D')) . '.',
            'Numero recensioni: ' . sanitize_text_field((string)($item['reviews']['totalReviews'] ?? 'N/D')) . '.',
            'Tag provider IDs: ' . $this->join_list($item['tags'] ?? array()) . '.',
            'Flag rilevanti: ' . $this->join_list($item['flags'] ?? array()) . '.',
            'Incluso: ' . $this->join_list($item['inclusions'] ?? array()) . '.',
            'Escluso: ' . $this->join_list($item['exclusions'] ?? array()) . '.',
            'Policy cancellazione: ' . $this->short_text($item['cancellationPolicy']['description'] ?? '') . '.',
            'Traduzione automatica: ' . $this->format_translation_auto($item['translationInfo'] ?? array()) . '.',
            'Fonte traduzione: ' . $this->format_translation_source($item['translationInfo'] ?? array()) . '.',
            'Fornitore: ' . sanitize_text_field((string)($item['supplier']['name'] ?? 'N/D')) . '.',
            'Nota: contesto item-only. Le source instructions sono separate nella configurazione Source.',
        );

        return implode("\n", array_filter($lines));
    }

    public function calculate_source_hash($normalized) {
        $item = is_array($normalized['raw_item'] ?? null) ? $normalized['raw_item'] : array();
        $hash_payload = array(
            'external_id' => $normalized['meta']['_alma_external_id'] ?? '',
            'title' => $normalized['post_title'] ?? '',
            'description' => $this->short_text($item['description'] ?? ($normalized['post_content'] ?? '')),
            'affiliate_url' => $normalized['affiliate_url'] ?? '',
            'price' => $item['pricing']['summary']['fromPrice'] ?? ($item['price'] ?? ''),
            'currency' => $item['pricing']['currency'] ?? ($item['currency'] ?? ''),
            'rating' => $item['reviews']['combinedAverageRating'] ?? ($item['rating'] ?? ''),
            'reviews_total' => $item['reviews']['totalReviews'] ?? '',
            'duration' => $item['duration'] ?? '',
            'destination' => $item['destinations'] ?? ($item['destination'] ?? ''),
            'tags' => $item['tags'] ?? array(),
            'flags' => $item['flags'] ?? array(),
            'cancellation_policy' => $item['cancellationPolicy']['description'] ?? '',
            'inclusions' => $item['inclusions'] ?? array(),
            'exclusions' => $item['exclusions'] ?? array(),
            'supplier' => $item['supplier']['name'] ?? '',
            'language' => $item['languageGuides'] ?? ($item['translationInfo'] ?? array()),
        );

        return hash('sha256', wp_json_encode($hash_payload));
    }

    private function decode_settings($source) {
        $settings = json_decode((string)($source['settings'] ?? ''), true);
        if (!is_array($settings)) { $settings = array(); }

        $settings['ai_source_instructions'] = $settings['ai_source_instructions'] ?? self::DEFAULT_INSTRUCTIONS;
        $settings['ai_context_refresh_interval'] = $settings['ai_context_refresh_interval'] ?? '7d';
        $settings['api_sync_interval'] = $settings['api_sync_interval'] ?? 'manual';
        $settings['ai_context_regeneration_policy'] = $settings['ai_context_regeneration_policy'] ?? 'if_hash_changed_or_expired';

        return $settings;
    }

    private function format_price($item) {
        $price = $item['pricing']['summary']['fromPrice'] ?? ($item['price'] ?? 'N/D');
        $currency = $item['pricing']['currency'] ?? ($item['currency'] ?? '');
        return trim(sanitize_text_field((string)$price . ' ' . (string)$currency));
    }

    private function short_text($text) {
        $clean = sanitize_text_field(wp_strip_all_tags((string)$text));
        if (strlen($clean) > 300) { return substr($clean, 0, 300) . '…'; }
        return $clean !== '' ? $clean : 'N/D';
    }

    private function join_list($value) {
        if (is_string($value)) { return $value !== '' ? sanitize_text_field($value) : 'N/D'; }
        if (!is_array($value)) { return 'N/D'; }
        $flat = array();
        foreach ($value as $entry) {
            if (is_scalar($entry)) { $flat[] = sanitize_text_field((string)$entry); }
            elseif (is_array($entry)) {
                $flat[] = sanitize_text_field((string)($entry['ref'] ?? $entry['name'] ?? $entry['code'] ?? ''));
            }
        }
        $flat = array_filter($flat);
        return !empty($flat) ? implode(', ', array_slice($flat, 0, 20)) : 'N/D';
    }
    private function format_duration($duration) {
        if (is_array($duration)) {
            $fixed = (int)($duration['fixedDurationInMinutes'] ?? 0);
            if ($fixed > 0) return $fixed . ' minuti';
            $from = (int)($duration['minDurationInMinutes'] ?? 0);
            $to = (int)($duration['maxDurationInMinutes'] ?? 0);
            if ($from > 0 && $to > 0) return 'da ' . $from . ' a ' . $to . ' minuti';
            return $this->short_text(wp_json_encode($duration));
        }
        return $this->short_text((string)$duration);
    }
    private function format_translation_auto($translation_info) { return (is_array($translation_info) && !empty($translation_info['containsMachineTranslatedText'])) ? 'sì' : 'no'; }
    private function format_translation_source($translation_info) { return is_array($translation_info) ? sanitize_text_field((string)($translation_info['translationSource'] ?? 'N/D')) : 'N/D'; }
    private function format_destination($item) {
        $destination = $item['destination'] ?? '';
        if (is_scalar($destination) && preg_match('/^\d+$/', (string)$destination)) return 'Destination ID Viator: ' . sanitize_text_field((string)$destination);
        return 'Destinazione: ' . $this->join_list($item['destinations'] ?? $destination);
    }
}
