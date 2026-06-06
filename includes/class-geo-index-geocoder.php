<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Orchestrates admin-only Geo Index geocoding batches.
 */
class ALMA_Geo_Index_Geocoder {
    const PROVIDER_GOOGLE = 'google';
    const LAST_REPORT_OPTION = 'alma_geo_geocoding_last_report';

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
            'timeout' => max(1, min(60, absint(get_option('alma_geo_geocoding_timeout', 15)))),
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

        foreach ($locations as $location) {
            $result = $this->geocode_location((int) $location['id']);
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

        update_option(self::LAST_REPORT_OPTION, $report, false);
        return $report;
    }

    public function geocode_location($location_id) {
        $location = $this->store->get_location(absint($location_id));
        if (!$location) {
            return array('status' => 'failed', 'message' => __('Località non trovata.', 'affiliate-link-manager-ai'));
        }
        $settings = $this->get_settings();
        if (($location['geocoding_status'] ?? '') === 'verified' && $settings['overwrite_verified'] !== 'yes') {
            return array('status' => 'verified', 'skipped_verified' => true, 'message' => __('Località già verificata: sovrascrittura disattivata.', 'affiliate-link-manager-ai'));
        }

        $query = $this->build_location_query($location);
        $provider = $this->get_provider();
        $provider_result = $provider->geocode($query, array('region' => $settings['country_bias']));
        $validated = $this->validate_result($location, $provider_result);
        $this->store->update_location_geocoding($location['id'], $validated);
        if (($validated['status'] ?? '') === 'verified') {
            $sync_report = $this->store->sync_location_to_linked_objects((int) $location['id']);
            $validated['linked_objects_synced'] = (int) ($sync_report['objects_updated'] ?? 0);
            $validated['linked_objects_sync_errors'] = count($sync_report['errors'] ?? array());
            $validated['linked_objects_sync_report'] = $sync_report;
        }
        $this->log_result($location['id'], $query, $validated, $provider_result);
        return $validated;
    }

    public function validate_result($location, $result) {
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

        if ($expected_country !== '' && $actual_country !== '' && $expected_country !== $actual_country) {
            $status = 'manual_required';
            $message = __('Codice paese restituito diverso da quello atteso.', 'affiliate-link-manager-ai');
        } elseif (!empty($first['partial_match'])) {
            $status = 'manual_required';
            $message = __('Google segnala un partial match.', 'affiliate-link-manager-ai');
        } elseif (($location['type'] ?? '') === 'city' && empty(array_intersect($types, array('locality', 'postal_town', 'administrative_area_level_3')))) {
            $status = count($results) > 1 ? 'ambiguous' : 'manual_required';
            $message = __('Tipo risultato non chiaramente coerente con una città.', 'affiliate-link-manager-ai');
        } elseif (count($results) > 1) {
            $status = 'ambiguous';
            $message = __('Google ha restituito più risultati: verifica consigliata.', 'affiliate-link-manager-ai');
        }

        return array(
            'status' => $status,
            'lat' => $first['lat'],
            'lng' => $first['lng'],
            'geo_provider' => 'google_maps',
            'place_id' => $first['place_id'],
            'formatted_address' => $first['formatted_address'],
            'address_components' => $first['address_components'],
            'message' => $message,
        );
    }

    public function build_location_query($location) {
        if (!empty($location['suggested_geocoding_query'])) {
            return sanitize_text_field($location['suggested_geocoding_query']);
        }
        $parts = array($location['poi'] ?? '', $location['area'] ?? '', $location['city'] ?? '', $location['region'] ?? '', $location['country'] ?? '');
        $parts = array_filter(array_map('trim', $parts));
        if (empty($parts)) {
            $parts[] = $location['canonical_name'] ?? '';
        } else {
            array_unshift($parts, $location['canonical_name'] ?? '');
        }
        return sanitize_text_field(implode(', ', array_unique(array_filter($parts))));
    }

    private function get_provider() {
        if (!$this->provider) {
            $settings = $this->get_settings();
            $this->provider = new ALMA_Geo_Index_Google_Geocoder($settings['google_maps_api_key'], $settings['timeout']);
        }
        return $this->provider;
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
