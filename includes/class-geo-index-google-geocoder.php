<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Google Maps Geocoding API provider for Geo Index admin geocoding.
 */
class ALMA_Geo_Index_Google_Geocoder {
    const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    private $api_key;
    private $timeout;

    public function __construct($api_key = '', $timeout = 15) {
        $this->api_key = trim((string) $api_key);
        $this->timeout = max(1, absint($timeout));
    }

    public function geocode($query, $args = array()) {
        $query = sanitize_text_field($query);
        if ($query === '') {
            return array('success' => false, 'status' => 'INVALID_QUERY', 'message' => __('Query geocoding vuota.', 'affiliate-link-manager-ai'));
        }
        if ($this->api_key === '') {
            return array('success' => false, 'status' => 'API_KEY_MISSING', 'message' => __('API key Google Maps non configurata.', 'affiliate-link-manager-ai'));
        }

        $url = $this->build_request_url($query, $args);
        $started = microtime(true);
        $response = wp_remote_get($url, array('timeout' => $this->timeout));
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status' => 'HTTP_ERROR',
                'message' => $response->get_error_message(),
                'response_code' => 0,
                'duration_ms' => $duration_ms,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $normalized = $this->normalize_response($body);
        $normalized['response_code'] = $code;
        $normalized['duration_ms'] = $duration_ms;
        if ($code < 200 || $code >= 300) {
            $normalized['success'] = false;
            $normalized['status'] = 'HTTP_' . $code;
            $normalized['message'] = sprintf(__('Errore HTTP Google Geocoding: %d.', 'affiliate-link-manager-ai'), $code);
        }
        return $normalized;
    }

    public function normalize_response($response) {
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return array('success' => false, 'status' => 'INVALID_JSON', 'message' => __('Risposta Google non valida.', 'affiliate-link-manager-ai'), 'results' => array());
        }

        $status = sanitize_text_field($data['status'] ?? 'UNKNOWN');
        $results = is_array($data['results'] ?? null) ? $data['results'] : array();
        $normalized_results = array();
        foreach ($results as $result) {
            $location = $result['geometry']['location'] ?? array();
            $components = is_array($result['address_components'] ?? null) ? $result['address_components'] : array();
            $normalized_results[] = array(
                'place_id' => sanitize_text_field($result['place_id'] ?? ''),
                'formatted_address' => sanitize_text_field($result['formatted_address'] ?? ''),
                'lat' => isset($location['lat']) ? (float) $location['lat'] : null,
                'lng' => isset($location['lng']) ? (float) $location['lng'] : null,
                'types' => array_map('sanitize_key', is_array($result['types'] ?? null) ? $result['types'] : array()),
                'country_code' => $this->extract_country_code($components),
                'address_components' => $components,
                'partial_match' => !empty($result['partial_match']),
            );
        }

        return array(
            'success' => $status === 'OK',
            'status' => $status,
            'message' => sanitize_text_field($data['error_message'] ?? ($status === 'OK' ? '' : sprintf(__('Google Geocoding status: %s.', 'affiliate-link-manager-ai'), $status))),
            'results' => $normalized_results,
            'raw_status' => $status,
        );
    }

    public function build_request_url($query, $args = array()) {
        $params = array(
            'address' => sanitize_text_field($query),
            'key' => $this->api_key,
            'language' => 'it',
        );
        if (!empty($args['region'])) {
            $params['region'] = strtolower(sanitize_text_field($args['region']));
        }
        return add_query_arg($params, self::ENDPOINT);
    }

    private function extract_country_code($components) {
        foreach ($components as $component) {
            $types = is_array($component['types'] ?? null) ? $component['types'] : array();
            if (in_array('country', $types, true) && !empty($component['short_name'])) {
                return strtoupper(sanitize_text_field($component['short_name']));
            }
        }
        return '';
    }
}
