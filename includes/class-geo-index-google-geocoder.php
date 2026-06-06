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

    public function search_locations($query, $args = array()) {
        $result = $this->geocode($query, $args);
        if (empty($result['success'])) {
            return $result;
        }
        $results = array();
        foreach (array_slice($result['results'] ?? array(), 0, 10) as $item) {
            $results[] = $this->normalize_location_result($item);
        }
        $result['results'] = $results;
        return $result;
    }

    public function normalize_location_result($item) {
        $components = is_array($item['address_components'] ?? null) ? $item['address_components'] : array();
        $type = $this->normalize_google_type(is_array($item['types'] ?? null) ? $item['types'] : array());
        $name = $this->extract_name($components, $item['formatted_address'] ?? '');
        $country = $this->extract_component($components, 'country', 'long_name');
        $country_code = $this->extract_component($components, 'country', 'short_name');
        $region = $this->extract_first_component($components, array('administrative_area_level_1', 'administrative_area_level_2'), 'long_name');
        $city = $this->extract_first_component($components, array('locality', 'postal_town', 'administrative_area_level_3'), 'long_name');
        $area = $type === 'area' ? $name : $this->extract_first_component($components, array('neighborhood', 'sublocality', 'natural_feature'), 'long_name');
        $poi = in_array($type, array('poi', 'airport', 'port'), true) ? $name : '';
        return array(
            'name' => $name,
            'formatted_address' => sanitize_text_field($item['formatted_address'] ?? ''),
            'type' => $type,
            'country' => sanitize_text_field($country),
            'country_code' => strtoupper(sanitize_text_field($country_code)),
            'region' => sanitize_text_field($region),
            'city' => sanitize_text_field($city),
            'area' => sanitize_text_field($area),
            'poi' => sanitize_text_field($poi),
            'lat' => isset($item['lat']) ? (float) $item['lat'] : null,
            'lng' => isset($item['lng']) ? (float) $item['lng'] : null,
            'place_id' => sanitize_text_field($item['place_id'] ?? ''),
            'provider' => 'google_maps',
            'canonical_name' => sanitize_text_field($name),
            'google_types' => array_map('sanitize_key', is_array($item['types'] ?? null) ? $item['types'] : array()),
            'geo_scope' => $this->default_geo_scope_for_type($type),
            'content_type' => $this->default_content_type_for_type($type),
            'commercial_intent' => 'high',
            'widget_eligible' => 'yes',
            'confidence' => 1,
            'match_weight' => 100,
            'source' => 'manual_google_search',
            'import_status' => 'imported',
            'geocoding_status' => (!empty($item['place_id']) && isset($item['lat']) && isset($item['lng'])) ? 'verified' : 'manual_required',
        );
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
            'success' => in_array($status, array('OK', 'ZERO_RESULTS'), true),
            'status' => $status,
            'message' => sanitize_text_field($data['error_message'] ?? ($status === 'ZERO_RESULTS' ? __('Nessun luogo trovato.', 'affiliate-link-manager-ai') : ($status === 'OK' ? '' : sprintf(__('Google Geocoding status: %s.', 'affiliate-link-manager-ai'), $status)))),
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

    private function normalize_google_type($types) {
        $types = array_map('sanitize_key', is_array($types) ? $types : array());
        if (in_array('country', $types, true)) {
            return 'country';
        }
        if (in_array('administrative_area_level_1', $types, true) || in_array('administrative_area_level_2', $types, true)) {
            return 'region';
        }
        if (in_array('locality', $types, true) || in_array('postal_town', $types, true)) {
            return 'city';
        }
        if (in_array('airport', $types, true)) {
            return 'airport';
        }
        if (in_array('port', $types, true) || in_array('transit_station', $types, true)) {
            return 'port';
        }
        if (in_array('route', $types, true)) {
            return 'route';
        }
        if (array_intersect($types, array('tourist_attraction', 'point_of_interest', 'establishment', 'museum', 'church', 'stadium'))) {
            return 'poi';
        }
        if (in_array('park', $types, true) || in_array('natural_feature', $types, true) || in_array('neighborhood', $types, true) || in_array('sublocality', $types, true)) {
            return 'area';
        }
        return 'unknown';
    }

    private function default_geo_scope_for_type($type) {
        if ($type === 'route') {
            return 'itinerary_multi_location';
        }
        return in_array($type, array('country', 'region', 'city', 'area', 'poi'), true) ? $type : 'uncertain';
    }

    private function default_content_type_for_type($type) {
        $map = array(
            'country' => 'country_guide',
            'region' => 'region_guide',
            'city' => 'city_guide',
            'area' => 'area_guide',
            'poi' => 'poi_guide',
            'route' => 'itinerary',
        );
        return $map[$type] ?? 'destination_guide';
    }

    private function extract_name($components, $fallback) {
        foreach ($components as $component) {
            $types = is_array($component['types'] ?? null) ? $component['types'] : array();
            if (array_intersect($types, array('point_of_interest', 'establishment', 'tourist_attraction', 'museum', 'church', 'stadium'))) {
                return sanitize_text_field($component['long_name'] ?? $fallback);
            }
        }
        foreach (array('locality', 'postal_town', 'administrative_area_level_1', 'country', 'natural_feature') as $type) {
            $name = $this->extract_component($components, $type, 'long_name');
            if ($name !== '') {
                return sanitize_text_field($name);
            }
        }
        $parts = explode(',', (string) $fallback);
        return sanitize_text_field(trim($parts[0] ?? $fallback));
    }

    private function extract_first_component($components, $types, $field = 'long_name') {
        foreach ($types as $type) {
            $value = $this->extract_component($components, $type, $field);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function extract_component($components, $type, $field = 'long_name') {
        foreach ($components as $component) {
            $types = is_array($component['types'] ?? null) ? $component['types'] : array();
            if (in_array($type, $types, true) && !empty($component[$field])) {
                return sanitize_text_field($component[$field]);
            }
        }
        return '';
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
