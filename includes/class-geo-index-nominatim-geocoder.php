<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Provider di geocoding OpenStreetMap / Nominatim (v2.110.0).
 *
 * Gratuito e senza API key: coerente con le mappe frontend già basate su
 * Leaflet + tile OpenStreetMap. Espone la STESSA interfaccia del provider
 * Google (geocode / search_locations / normalize_location_result) e produce
 * risultati nello stesso formato intermedio "google-shaped" (place_id,
 * types in vocabolario Google, address_components), così validate_result,
 * lo store e il metabox funzionano senza modifiche.
 *
 * Policy istanza pubblica Nominatim: max 1 richiesta/secondo e User-Agent
 * identificativo — entrambe rispettate qui (throttle interno >= 1.1s).
 */
class ALMA_Geo_Index_Nominatim_Geocoder {
    const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    const PROVIDER_KEY = 'nominatim';
    const MIN_REQUEST_INTERVAL = 1.1; // secondi tra richieste (fair use OSM)

    private $timeout;
    private static $last_request_at = 0.0;

    public function __construct($timeout = 15) {
        $this->timeout = max(1, min(30, absint($timeout)));
    }

    /** Fair use dell'istanza pubblica: mai più di ~1 richiesta al secondo. */
    private function throttle() {
        $elapsed = microtime(true) - self::$last_request_at;
        if (self::$last_request_at > 0 && $elapsed < self::MIN_REQUEST_INTERVAL) {
            usleep((int) round((self::MIN_REQUEST_INTERVAL - $elapsed) * 1000000));
        }
        self::$last_request_at = microtime(true);
    }

    public function build_request_url($query, $args = array()) {
        $params = array(
            'q' => sanitize_text_field($query),
            'format' => 'jsonv2',
            'addressdetails' => '1',
            'limit' => '10',
            'accept-language' => 'it',
        );
        if (!empty($args['region'])) {
            $params['countrycodes'] = strtolower(sanitize_text_field($args['region']));
        }
        return add_query_arg($params, self::ENDPOINT);
    }

    public function geocode($query, $args = array()) {
        $query = sanitize_text_field($query);
        if ($query === '') {
            return array('success' => false, 'status' => 'INVALID_QUERY', 'message' => __('Query geocoding vuota.', 'affiliate-link-manager-ai'), 'results' => array());
        }
        $this->throttle();
        $started = microtime(true);
        $response = wp_remote_get($this->build_request_url($query, $args), array(
            'timeout' => $this->timeout,
            'user-agent' => 'AffiliateLinkManagerAI/' . (defined('ALMA_VERSION') ? ALMA_VERSION : '0') . ' (+' . home_url('/') . ')',
            'headers' => array('Accept' => 'application/json'),
        ));
        $duration_ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            return array('success' => false, 'status' => 'HTTP_ERROR', 'message' => $response->get_error_message(), 'response_code' => 0, 'duration_ms' => $duration_ms, 'results' => array());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            // 429/403: fair use superato — il chiamante lo tratta come rate limit.
            return array('success' => false, 'status' => 'HTTP_' . $code, 'raw_status' => 'HTTP_' . $code, 'message' => sprintf(__('Errore HTTP Nominatim: %d.', 'affiliate-link-manager-ai'), $code), 'response_code' => $code, 'duration_ms' => $duration_ms, 'results' => array());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return array('success' => false, 'status' => 'INVALID_JSON', 'message' => __('Risposta Nominatim non valida.', 'affiliate-link-manager-ai'), 'response_code' => $code, 'duration_ms' => $duration_ms, 'results' => array());
        }
        $results = array();
        foreach ($data as $row) {
            if (!is_array($row)) { continue; }
            $shaped = self::to_google_shape($row);
            if (!empty($shaped['place_id'])) { $results[] = $shaped; }
        }
        $status = empty($results) ? 'ZERO_RESULTS' : 'OK';
        return array(
            'success' => true,
            'status' => $status,
            'raw_status' => $status,
            'message' => $status === 'ZERO_RESULTS' ? __('Nessun luogo trovato.', 'affiliate-link-manager-ai') : '',
            'results' => $results,
            'response_code' => $code,
            'duration_ms' => $duration_ms,
        );
    }

    public function search_locations($query, $args = array()) {
        $result = $this->geocode($query, $args);
        if (empty($result['success'])) {
            return $result;
        }
        $normalized = array();
        foreach (array_slice((array) ($result['results'] ?? array()), 0, 10) as $item) {
            $normalized[] = $this->normalize_location_result($item);
        }
        $result['results'] = $normalized;
        return $result;
    }

    /**
     * Normalizzazione finale identica a Google (il formato intermedio è già
     * google-shaped): si riusa il normalizzatore pubblico del provider Google
     * e si marcano provider e fonte come Nominatim.
     */
    public function normalize_location_result($item) {
        $google = new ALMA_Geo_Index_Google_Geocoder('');
        $normalized = $google->normalize_location_result($item);
        $normalized['provider'] = self::PROVIDER_KEY;
        $normalized['source'] = 'manual_nominatim_search';
        return $normalized;
    }

    /**
     * Converte una riga Nominatim (jsonv2 + addressdetails) nel formato
     * intermedio google-shaped consumato da validate_result e dal resto
     * della pipeline. Pura (testabile con shim).
     */
    public static function to_google_shape($row) {
        $row = is_array($row) ? $row : array();
        $address = is_array($row['address'] ?? null) ? $row['address'] : array();
        $osm_type = sanitize_key((string) ($row['osm_type'] ?? ''));
        $osm_id = preg_replace('/[^0-9]/', '', (string) ($row['osm_id'] ?? ''));
        $place_id = ($osm_type !== '' && $osm_id !== '')
            ? 'osm:' . $osm_type . '/' . $osm_id
            : (($row['place_id'] ?? '') !== '' ? 'nominatim:' . preg_replace('/[^0-9]/', '', (string) $row['place_id']) : '');
        $display_name = sanitize_text_field((string) ($row['display_name'] ?? ''));
        $name = sanitize_text_field((string) ($row['name'] ?? ''));
        if ($name === '' && $display_name !== '') {
            $segments = explode(',', $display_name);
            $name = trim((string) $segments[0]);
        }
        return array(
            'place_id' => $place_id,
            'formatted_address' => $display_name,
            'name' => $name,
            'lat' => isset($row['lat']) && $row['lat'] !== '' ? (float) $row['lat'] : null,
            'lng' => isset($row['lon']) && $row['lon'] !== '' ? (float) $row['lon'] : null,
            'types' => self::map_osm_types((string) ($row['class'] ?? ($row['category'] ?? '')), (string) ($row['type'] ?? ''), (string) ($row['addresstype'] ?? '')),
            'country_code' => strtoupper(sanitize_text_field((string) ($address['country_code'] ?? ''))),
            'address_components' => self::build_address_components($address),
            // Nominatim non ha il concetto di partial match di Google.
            'partial_match' => false,
        );
    }

    /**
     * Mappa class/type/addresstype OSM nel vocabolario dei tipi Google usato
     * da tutta la pipeline (normalize_google_type, match per tipo, scope).
     */
    public static function map_osm_types($class, $type, $addresstype) {
        $class = sanitize_key($class);
        $type = sanitize_key($type);
        $addresstype = sanitize_key($addresstype);
        if ($addresstype === 'country' || $type === 'country') {
            return array('country', 'political');
        }
        if (in_array($addresstype, array('state', 'region', 'province'), true) || in_array($type, array('state', 'region', 'province'), true)) {
            return array('administrative_area_level_1', 'political');
        }
        if (in_array($addresstype, array('county', 'state_district', 'district'), true) || in_array($type, array('county', 'state_district'), true)) {
            return array('administrative_area_level_2', 'political');
        }
        if (in_array($addresstype, array('city', 'town', 'village', 'hamlet', 'municipality'), true) || ($class === 'place' && in_array($type, array('city', 'town', 'village', 'hamlet', 'municipality'), true))) {
            return array('locality', 'political');
        }
        if (in_array($addresstype, array('suburb', 'neighbourhood', 'quarter', 'city_district', 'borough', 'city_block'), true) || ($class === 'place' && in_array($type, array('suburb', 'neighbourhood', 'quarter', 'city_block', 'borough'), true))) {
            return array('sublocality', 'neighborhood', 'political');
        }
        if ($type === 'aerodrome' || $class === 'aeroway' || $addresstype === 'aerodrome') {
            return array('airport', 'point_of_interest', 'establishment');
        }
        if (in_array($type, array('harbour', 'port', 'ferry_terminal'), true) || $addresstype === 'harbour') {
            return array('port', 'point_of_interest', 'establishment');
        }
        if ($class === 'highway' || $type === 'route') {
            return array('route');
        }
        if (in_array($class, array('natural', 'waterway'), true) || in_array($type, array('island', 'islet', 'peninsula', 'bay', 'beach', 'archipelago', 'water', 'sea'), true) || in_array($addresstype, array('island', 'islet', 'peninsula', 'water'), true)) {
            return array('natural_feature');
        }
        if ($class === 'tourism' || $class === 'historic' || in_array($type, array('attraction', 'museum', 'castle', 'monument', 'memorial', 'archaeological_site', 'viewpoint', 'theme_park', 'zoo', 'aquarium', 'artwork', 'ruins', 'fort'), true)) {
            return array('tourist_attraction', 'point_of_interest', 'establishment');
        }
        if ($class === 'boundary' && $type === 'administrative') {
            // Confine amministrativo senza addresstype specifico: area generica.
            return array('administrative_area_level_1', 'political');
        }
        if (in_array($class, array('amenity', 'leisure', 'building', 'shop', 'man_made'), true)) {
            return array('point_of_interest', 'establishment');
        }
        return array('point_of_interest', 'establishment');
    }

    /**
     * Costruisce address_components in formato Google dal blocco address di
     * Nominatim, così l'estrazione di paese/regione/città resta identica.
     */
    public static function build_address_components($address) {
        $address = is_array($address) ? $address : array();
        $components = array();
        $country = sanitize_text_field((string) ($address['country'] ?? ''));
        if ($country !== '') {
            $components[] = array('long_name' => $country, 'short_name' => strtoupper(sanitize_text_field((string) ($address['country_code'] ?? ''))), 'types' => array('country', 'political'));
        }
        $state = sanitize_text_field((string) ($address['state'] ?? ($address['region'] ?? ($address['province'] ?? ''))));
        if ($state !== '') {
            $components[] = array('long_name' => $state, 'short_name' => $state, 'types' => array('administrative_area_level_1', 'political'));
        }
        $county = sanitize_text_field((string) ($address['county'] ?? ($address['state_district'] ?? '')));
        if ($county !== '') {
            $components[] = array('long_name' => $county, 'short_name' => $county, 'types' => array('administrative_area_level_2', 'political'));
        }
        $city = sanitize_text_field((string) ($address['city'] ?? ($address['town'] ?? ($address['village'] ?? ($address['municipality'] ?? ($address['hamlet'] ?? ''))))));
        if ($city !== '') {
            $components[] = array('long_name' => $city, 'short_name' => $city, 'types' => array('locality', 'political'));
        }
        $neighbourhood = sanitize_text_field((string) ($address['suburb'] ?? ($address['neighbourhood'] ?? ($address['quarter'] ?? ($address['city_district'] ?? '')))));
        if ($neighbourhood !== '') {
            $components[] = array('long_name' => $neighbourhood, 'short_name' => $neighbourhood, 'types' => array('neighborhood', 'sublocality', 'political'));
        }
        $postcode = sanitize_text_field((string) ($address['postcode'] ?? ''));
        if ($postcode !== '') {
            $components[] = array('long_name' => $postcode, 'short_name' => $postcode, 'types' => array('postal_code'));
        }
        return $components;
    }
}
