<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Orchestrates admin-only Geo Index geocoding batches.
 */
class ALMA_Geo_Index_Geocoder {
    const PROVIDER_GOOGLE = 'google';
    const LAST_REPORT_OPTION = 'alma_geo_geocoding_last_report';
    const BATCH_LOCK_OPTION = 'alma_geo_geocoding_batch_lock';
    const BATCH_LOCK_TTL = 600;

    private $store;
    private $provider;

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
    }

    public function get_settings() {
        return array(
            'provider' => sanitize_key(get_option('alma_geo_geocoding_provider', self::PROVIDER_GOOGLE)),
            'google_maps_api_key' => (string) get_option('alma_geo_google_maps_api_key', ''),
            'batch_size' => max(1, min(50, absint(get_option('alma_geo_geocoding_batch_size', 20)))),
            'timeout' => max(1, min(30, absint(get_option('alma_geo_geocoding_timeout', 15)))),
            'delay_ms' => max(0, min(5000, absint(get_option('alma_geo_geocoding_delay_ms', 200)))),
            'overwrite_verified' => get_option('alma_geo_geocoding_overwrite_verified', 'no') === 'yes' ? 'yes' : 'no',
            'country_bias' => sanitize_text_field(get_option('alma_geo_geocoding_country_bias', '')),
        );
    }

    public function is_enabled() {
        $settings = $this->get_settings();
        return $settings['provider'] === self::PROVIDER_GOOGLE && trim($settings['google_maps_api_key']) !== '';
    }

    public function get_pending_locations($limit = 20) {
        return $this->store->get_locations_by_geocoding_status('pending', $limit);
    }

    /**
     * Lock anti-concorrenza per i batch: due tab/utenti che avviano il geocoding
     * in parallelo processerebbero le stesse località duplicando le chiamate Google.
     * add_option è atomica (indice UNIQUE su option_name); il TTL evita lock orfani.
     */
    private function acquire_batch_lock() {
        $now = time();
        if (add_option(self::BATCH_LOCK_OPTION, $now, '', 'no')) {
            return true;
        }
        $existing = (int) get_option(self::BATCH_LOCK_OPTION, 0);
        if ($existing && ($now - $existing) > self::BATCH_LOCK_TTL) {
            update_option(self::BATCH_LOCK_OPTION, $now, false);
            return true;
        }
        return false;
    }

    private function release_batch_lock() {
        delete_option(self::BATCH_LOCK_OPTION);
    }

    private function locked_report_stub($report, $errors_key = 'api_errors') {
        $message = __('Un\'altra elaborazione di geocoding è già in corso: riprova tra qualche minuto.', 'affiliate-link-manager-ai');
        if ($errors_key === 'errors') {
            // Il report generico usa righe-array {location_id, status, message}.
            $report[$errors_key][] = array('location_id' => 0, 'status' => 'locked', 'message' => $message);
        } else {
            $report[$errors_key][] = $message;
        }
        $report['locked'] = true;
        if (array_key_exists('remaining_pending', $report)) {
            // Senza il valore reale la UI mostrerebbe 0 pending e progresso 100%
            // mentre l'altra elaborazione è ancora in corso.
            $remaining = $this->store->get_geocoding_status_counts_by_object_type(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
            $report['remaining_pending'] = (int) ($remaining['pending'] ?? 0);
        }
        return $report;
    }

    public function geocode_batch($limit = 20, $status = 'pending') {
        $limit = max(1, min(50, absint($limit)));
        $status = sanitize_key($status);
        $locations = $this->store->get_locations_by_geocoding_status($status === 'failed' ? 'failed' : 'pending', $limit);
        $settings = $this->get_settings();
        $report = array(
            'date' => current_time('mysql'),
            'provider' => $settings['provider'],
            'batch_size' => $limit,
            'processed' => 0,
            'verified' => 0,
            'ambiguous' => 0,
            'manual_required' => 0,
            'failed' => 0,
            'skipped_verified' => 0,
            'linked_objects_synced' => 0,
            'linked_objects_sync_errors' => 0,
            'errors' => array(),
        );

        if (!$this->acquire_batch_lock()) {
            return $this->locked_report_stub($report, 'errors');
        }

        try {
            foreach ($locations as $location) {
                $result = $this->geocode_location((int) $location['id']);
                if (!empty($result['configuration_error'])) {
                    // API key non valida / API non abilitata: continuare marcherebbe
                    // failed l'intero batch per un errore permanente di configurazione.
                    $report['processed']++;
                    $report['failed']++;
                    $report['errors'][] = array('location_id' => (int) $location['id'], 'status' => 'failed', 'message' => sanitize_text_field($result['message'] ?? ''));
                    $report['configuration_error'] = true;
                    break;
                }
                if (!empty($result['skipped_verified'])) {
                    $report['skipped_verified']++;
                } else {
                    $report['processed']++;
                    $result_status = sanitize_key($result['status'] ?? 'failed');
                    if (isset($report[$result_status])) {
                        $report[$result_status]++;
                    }
                    if (!empty($result['message']) && $result_status !== 'verified') {
                        $report['errors'][] = array('location_id' => (int) $location['id'], 'status' => $result_status, 'message' => sanitize_text_field($result['message']));
                    }
                    $report['linked_objects_synced'] += (int) ($result['linked_objects_synced'] ?? 0);
                    $report['linked_objects_sync_errors'] += (int) ($result['linked_objects_sync_errors'] ?? 0);
                }
                if ($settings['delay_ms'] > 0) {
                    usleep($settings['delay_ms'] * 1000);
                }
            }
        } finally {
            $this->release_batch_lock();
        }

        update_option(self::LAST_REPORT_OPTION, $report, false);
        return $report;
    }

    public function geocode_location($location_id, $options = array()) {
        $location = $this->store->get_location(absint($location_id));
        if (!$location) {
            return array('status' => 'failed', 'message' => __('Località non trovata.', 'affiliate-link-manager-ai'));
        }
        $settings = array_merge($this->get_settings(), is_array($options) ? $options : array());
        $settings['timeout'] = max(1, min(30, absint($settings['timeout'] ?? 15)));
        if (($location['geocoding_status'] ?? '') === 'verified' && $settings['overwrite_verified'] !== 'yes') {
            return array('status' => 'verified', 'skipped_verified' => true, 'message' => __('Località già verificata: sovrascrittura disattivata.', 'affiliate-link-manager-ai'));
        }

        $query = $this->build_location_query($location);
        $provider = !empty($settings['provider_instance']) ? $settings['provider_instance'] : $this->get_provider($settings);
        $provider_result = $provider->geocode($query, array('region' => $settings['country_bias']));
        $validated = $this->validate_result($location, $provider_result);
        $this->store->update_location_geocoding($location['id'], $validated);
        // Sincronizza SEMPRE l'esito sui contenuti collegati (non solo verified):
        // lo stato geocoding di link e post deve riflettere quello della località
        // (ambiguous, failed, retry_later inclusi) invece di restare "In attesa".
        $sync_report = $this->store->sync_location_to_linked_objects((int) $location['id']);
        $validated['linked_objects_synced'] = (int) ($sync_report['objects_updated'] ?? 0);
        $validated['linked_objects_sync_errors'] = count($sync_report['errors'] ?? array());
        $validated['linked_objects_sync_report'] = $sync_report;
        $validated['query'] = $query;
        $validated['provider_status'] = sanitize_text_field($provider_result['status'] ?? '');
        $validated['rate_limit_detected'] = $this->is_rate_limit_result($provider_result);
        $validated['configuration_error'] = $this->is_configuration_error_result($provider_result);
        $this->log_result($location['id'], $query, $validated, $provider_result);
        return $validated;
    }

    public function geocode_affiliate_locations_batch($args = array()) {
        $settings = $this->get_settings();
        $limit = max(1, min(50, absint($args['batch_size'] ?? $settings['batch_size'])));
        $timeout = max(1, min(30, absint($args['timeout'] ?? $settings['timeout'])));
        $include_ambiguous = !empty($args['include_ambiguous']);
        $retry_failed = !empty($args['retry_failed']);
        $statuses = array('pending');
        if ($include_ambiguous) {
            $statuses[] = 'ambiguous';
        }
        if ($retry_failed) {
            $statuses[] = 'failed';
            $statuses[] = 'retry_later';
        }
        $locations = $this->store->get_locations_by_geocoding_statuses_for_object_type($statuses, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, $limit);
        $report = array(
            'date' => current_time('mysql'),
            'provider' => $settings['provider'],
            'source_filter' => 'affiliate_links',
            'batch_size' => $limit,
            'timeout' => $timeout,
            'processed' => 0,
            'verified' => 0,
            'ambiguous' => 0,
            'failed' => 0,
            'skipped' => 0,
            'retry_later' => 0,
            'remaining_pending' => 0,
            'api_errors' => array(),
            'rate_limit_detected' => false,
            'examples' => array(),
            'rows' => array(),
        );
        if (trim((string) $settings['google_maps_api_key']) === '') {
            $report['api_errors'][] = __('API key Google Maps non configurata.', 'affiliate-link-manager-ai');
            $report['rate_limit_detected'] = true;
            update_option(self::LAST_REPORT_OPTION, $report, false);
            return $report;
        }
        if (!$this->acquire_batch_lock()) {
            return $this->locked_report_stub($report);
        }
        try {
        $provider = new ALMA_Geo_Index_Google_Geocoder($settings['google_maps_api_key'], $timeout);
        foreach ($locations as $location) {
            $previous_status = sanitize_key($location['geocoding_status'] ?? 'pending');
            if ($previous_status === 'verified') {
                $report['skipped']++;
                continue;
            }
            if ($previous_status === 'ambiguous' && !$include_ambiguous) {
                $report['skipped']++;
                continue;
            }
            if (in_array($previous_status, array('failed','retry_later'), true) && !$retry_failed) {
                $report['skipped']++;
                continue;
            }
            $result = $this->geocode_location((int) $location['id'], array('timeout' => $timeout, 'provider_instance' => $provider));
            $new_status = sanitize_key($result['status'] ?? 'failed');
            $report['processed']++;
            if (isset($report[$new_status])) {
                $report[$new_status]++;
            }
            if (!empty($result['message']) && $new_status !== 'verified') {
                $report['api_errors'][] = sanitize_text_field($result['message']);
            }
            $row = array(
                'location_id' => (int) $location['id'],
                'name' => sanitize_text_field($location['canonical_name'] ?? ''),
                'canonical_name' => sanitize_text_field($location['canonical_name'] ?? ''),
                'query' => sanitize_text_field($result['query'] ?? $this->build_location_query($location)),
                'previous_status' => $previous_status,
                'new_status' => $new_status,
                'lat' => isset($result['lat']) ? (string) $result['lat'] : '',
                'lng' => isset($result['lng']) ? (string) $result['lng'] : '',
                'place_id' => sanitize_text_field($result['place_id'] ?? ''),
                'formatted_address' => sanitize_text_field($result['formatted_address'] ?? ''),
                'confidence' => isset($result['confidence']) ? (string) $result['confidence'] : '',
                'message' => sanitize_text_field($result['message'] ?? ''),
                'affiliate_link_count' => (int) ($location['affiliate_link_count'] ?? $this->store->count_linked_objects_for_location((int) $location['id'], ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK)),
            );
            $report['rows'][] = $row;
            $report['examples'][] = $row;
            if (count($report['examples']) > 5) {
                array_shift($report['examples']);
            }
            if (!empty($result['rate_limit_detected'])) {
                $report['rate_limit_detected'] = true;
                break;
            }
            if (!empty($result['configuration_error'])) {
                // Errore permanente (API key non valida / API non abilitata): inutile
                // continuare il batch. rate_limit_detected ferma anche il loop client.
                $report['configuration_error'] = true;
                $report['rate_limit_detected'] = true;
                break;
            }
            if ($settings['delay_ms'] > 0) {
                usleep($settings['delay_ms'] * 1000);
            }
        }
        } finally {
            $this->release_batch_lock();
        }
        $remaining = $this->store->get_geocoding_status_counts_by_object_type(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
        $report['remaining_pending'] = (int) ($remaining['pending'] ?? 0);
        update_option(self::LAST_REPORT_OPTION, $report, false);
        return $report;
    }

    public function validate_result($location, $result) {
        if ($this->is_configuration_error_result($result)) {
            return array('status' => 'failed', 'message' => __('Google ha rifiutato la richiesta (REQUEST_DENIED): verifica che la API key sia valida e che la Geocoding API sia abilitata.', 'affiliate-link-manager-ai'));
        }
        if ($this->is_rate_limit_result($result)) {
            return array('status' => 'retry_later', 'message' => sanitize_text_field($result['message'] ?? $result['status'] ?? __('Quota o rate limit Google rilevato.', 'affiliate-link-manager-ai')));
        }
        if (empty($result['success'])) {
            return array('status' => 'failed', 'message' => sanitize_text_field($result['message'] ?? $result['status'] ?? __('Geocoding fallito.', 'affiliate-link-manager-ai')));
        }
        $results = is_array($result['results'] ?? null) ? $result['results'] : array();
        if (empty($results)) {
            return array('status' => 'failed', 'message' => __('Nessun risultato Google Geocoding.', 'affiliate-link-manager-ai'));
        }
        $first = $results[0];
        if ($first['lat'] === null || $first['lng'] === null || empty($first['place_id'])) {
            return array('status' => 'failed', 'message' => __('Risultato senza coordinate o Place ID.', 'affiliate-link-manager-ai'));
        }

        $expected_country = strtoupper(sanitize_text_field($location['country_code'] ?? ''));
        $actual_country = strtoupper(sanitize_text_field($first['country_code'] ?? ''));
        $types = is_array($first['types'] ?? null) ? $first['types'] : array();
        $status = 'verified';
        $message = '';
        $confidence = 0.95;

        if ($expected_country !== '' && $actual_country !== '' && $expected_country !== $actual_country) {
            $status = 'ambiguous';
            $message = __('Codice paese restituito diverso da quello atteso.', 'affiliate-link-manager-ai');
            $confidence = 0.35;
        } elseif (!empty($first['partial_match'])) {
            $status = 'ambiguous';
            $message = __('Google segnala un partial match.', 'affiliate-link-manager-ai');
            $confidence = 0.45;
        } elseif (($location['type'] ?? '') === 'city' && empty(array_intersect($types, array('locality', 'postal_town', 'administrative_area_level_3')))) {
            $status = count($results) > 1 ? 'ambiguous' : 'manual_required';
            $message = __('Tipo risultato non chiaramente coerente con una città.', 'affiliate-link-manager-ai');
            $confidence = 0.55;
        } elseif (in_array(($location['type'] ?? ''), array('airport', 'port', 'poi', 'area'), true) && !$this->google_types_match_location_type($location['type'], $types)) {
            $status = count($results) > 1 ? 'ambiguous' : 'manual_required';
            $message = __('Tipo risultato non chiaramente coerente con il tipo località atteso.', 'affiliate-link-manager-ai');
            $confidence = 0.55;
        } elseif (count($results) > 1) {
            $status = 'ambiguous';
            $message = __('Google ha restituito più risultati: verifica consigliata.', 'affiliate-link-manager-ai');
            $confidence = 0.6;
        }

        return array(
            'status' => $status,
            'lat' => $first['lat'],
            'lng' => $first['lng'],
            'geo_provider' => 'google_maps',
            'place_id' => $first['place_id'],
            'formatted_address' => $first['formatted_address'],
            'address_components' => $first['address_components'],
            'confidence' => $confidence,
            'message' => $message,
        );
    }

    public function build_location_query($location) {
        foreach (array('primary_suggested_geocoding_query', 'suggested_geocoding_query') as $query_key) {
            if (!empty($location[$query_key])) {
                return sanitize_text_field($location[$query_key]);
            }
        }
        $canonical = trim((string) ($location['canonical_name'] ?? ''));
        $name = trim((string) ($location['name'] ?? $canonical));
        $region = trim((string) ($location['region'] ?? ''));
        $country = trim((string) ($location['country'] ?? ''));
        $candidates = array(
            array($canonical, $region, $country),
            array($name, $region, $country),
            array($name, $country),
        );
        foreach ($candidates as $parts) {
            $query = implode(', ', array_unique(array_filter(array_map('trim', $parts))));
            if ($query !== '') {
                return sanitize_text_field($query);
            }
        }
        return sanitize_text_field($canonical);
    }

    private function get_provider($settings = array()) {
        if (!$this->provider) {
            $settings = array_merge($this->get_settings(), is_array($settings) ? $settings : array());
            $this->provider = new ALMA_Geo_Index_Google_Geocoder($settings['google_maps_api_key'], $settings['timeout']);
        }
        return $this->provider;
    }

    private function is_rate_limit_result($result) {
        // REQUEST_DENIED non è un rate limit: indica API key non valida o API non
        // abilitata, un errore permanente di configurazione (vedi is_configuration_error_result).
        $status = strtoupper(sanitize_text_field($result['status'] ?? $result['raw_status'] ?? ''));
        $code = absint($result['response_code'] ?? 0);
        return in_array($status, array('OVER_QUERY_LIMIT', 'RESOURCE_EXHAUSTED'), true) || $code === 429;
    }

    private function is_configuration_error_result($result) {
        $status = strtoupper(sanitize_text_field($result['status'] ?? $result['raw_status'] ?? ''));
        return $status === 'REQUEST_DENIED';
    }

    private function google_types_match_location_type($type, $types) {
        $type = sanitize_key($type);
        $types = array_map('sanitize_key', is_array($types) ? $types : array());
        $map = array(
            'airport' => array('airport'),
            'port' => array('transit_station', 'point_of_interest', 'establishment'),
            'poi' => array('tourist_attraction', 'point_of_interest', 'establishment', 'museum', 'church', 'stadium', 'lodging', 'restaurant', 'premise', 'park'),
            'area' => array('natural_feature', 'neighborhood', 'sublocality', 'administrative_area_level_2', 'administrative_area_level_3'),
        );
        return empty($map[$type]) || (bool) array_intersect($types, $map[$type]);
    }

    private function log_result($location_id, $query, $validated, $provider_result) {
        if (!class_exists('ALMA_Logger')) {
            return;
        }
        $context = array(
            'location_id' => absint($location_id),
            'query' => sanitize_text_field($query),
            'status' => sanitize_key($validated['status'] ?? ''),
            'error' => sanitize_text_field($validated['message'] ?? ''),
            'response_code' => absint($provider_result['response_code'] ?? 0),
            'provider' => 'google_maps',
            'duration_ms' => absint($provider_result['duration_ms'] ?? 0),
        );
        if (($validated['status'] ?? '') === 'verified') {
            ALMA_Logger::info('Geo Index geocoding completed.', $context);
        } else {
            ALMA_Logger::warning('Geo Index geocoding needs attention.', $context);
        }
    }
}
