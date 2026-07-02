<?php
/**
 * Risolve i ref numerici delle destinazioni Viator in nomi di località.
 *
 * I prodotti Viator (products/search) contengono solo `destinations[].ref`
 * (es. "684"): senza risoluzione i link importati non hanno alcun nome di
 * località e la geolocalizzazione automatica non può agganciarli. Il catalogo
 * completo delle destinazioni (GET /destinations) viene scaricato una volta e
 * cachato in option per 30 giorni.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Source_Viator_Destination_Resolver {
    const CATALOG_OPTION = 'alma_viator_destinations_catalog';
    const CATALOG_TTL = 30 * DAY_IN_SECONDS;

    /** Memoizzazione per request. */
    private static $catalog_cache = null;

    /**
     * Risolve la destinazione primaria di un item Viator in una località
     * pronta per il Geo Index.
     *
     * @param array $item   Item prodotto Viator (con destinations[].ref).
     * @param array $source Riga source (per credenziali/ambiente).
     * @return array array vuoto oppure {name, type, city, region, country}
     */
    public static function resolve_primary_location($item, $source) {
        $refs = self::extract_refs($item);
        if (empty($refs)) {
            return array();
        }
        $catalog = self::get_catalog($source);
        if (empty($catalog)) {
            return array();
        }

        $primary_ref = $refs['primary'] !== '' ? $refs['primary'] : ($refs['all'][0] ?? '');
        if ($primary_ref === '' || !isset($catalog[$primary_ref])) {
            return array();
        }

        $node = $catalog[$primary_ref];
        $location = array(
            'name' => $node['name'],
            'type' => self::map_type($node['type']),
            'city' => self::map_type($node['type']) === 'city' ? $node['name'] : '',
            'region' => '',
            'country' => '',
        );

        // Risali l'albero delle destinazioni per regione e paese.
        $parent_id = $node['parent'];
        $depth = 0;
        while ($parent_id !== '' && isset($catalog[$parent_id]) && $depth < 6) {
            $parent = $catalog[$parent_id];
            $parent_type = strtoupper($parent['type']);
            if ($location['region'] === '' && in_array($parent_type, array('REGION', 'STATE', 'PROVINCE'), true)) {
                $location['region'] = $parent['name'];
            }
            if ($location['country'] === '' && $parent_type === 'COUNTRY') {
                $location['country'] = $parent['name'];
            }
            $parent_id = $parent['parent'];
            $depth++;
        }
        return $location;
    }

    public static function extract_refs($item) {
        $refs = array('primary' => '', 'all' => array());
        foreach ((array) ($item['destinations'] ?? array()) as $destination) {
            if (!is_array($destination)) {
                continue;
            }
            $ref = sanitize_text_field((string) ($destination['ref'] ?? ($destination['destinationId'] ?? '')));
            if ($ref === '') {
                continue;
            }
            $refs['all'][] = $ref;
            if (!empty($destination['primary']) && $refs['primary'] === '') {
                $refs['primary'] = $ref;
            }
        }
        $refs['all'] = array_values(array_unique($refs['all']));
        return empty($refs['all']) ? array() : $refs;
    }

    /**
     * Catalogo destinationId => {name, type, parent}. Cachato in option;
     * scaricato via API con le credenziali della source solo se assente/scaduto.
     */
    public static function get_catalog($source) {
        if (self::$catalog_cache !== null) {
            return self::$catalog_cache;
        }
        $stored = get_option(self::CATALOG_OPTION, array());
        if (is_array($stored) && !empty($stored['map']) && (time() - (int) ($stored['fetched_at'] ?? 0)) < self::CATALOG_TTL) {
            self::$catalog_cache = $stored['map'];
            return self::$catalog_cache;
        }

        $map = self::fetch_catalog($source);
        if (empty($map)) {
            // Fetch fallito: riusa un catalogo scaduto se disponibile (meglio
            // di niente), senza sovrascrivere l'option.
            self::$catalog_cache = is_array($stored['map'] ?? null) ? $stored['map'] : array();
            return self::$catalog_cache;
        }
        update_option(self::CATALOG_OPTION, array('map' => $map, 'fetched_at' => time()), false);
        self::$catalog_cache = $map;
        return self::$catalog_cache;
    }

    private static function fetch_catalog($source) {
        $settings = json_decode((string) ($source['settings'] ?? '{}'), true) ?: array();
        $credentials = json_decode((string) ($source['credentials'] ?? '{}'), true) ?: array();
        $api_key = sanitize_text_field($credentials['api_key'] ?? '');
        if ($api_key === '') {
            return array();
        }
        $environment = sanitize_key($settings['environment'] ?? ALMA_Affiliate_Source_Provider_Client_Viator::ENV_SANDBOX);
        $base = $environment === ALMA_Affiliate_Source_Provider_Client_Viator::ENV_PRODUCTION ? 'https://api.viator.com/partner' : 'https://api.sandbox.viator.com/partner';
        $api_version = sanitize_text_field($settings['api_version'] ?? '2.0');
        $lang = sanitize_text_field($settings['accept_language'] ?? 'it');

        $response = wp_remote_get($base . '/destinations', array(
            'headers' => array(
                'exp-api-key' => $api_key,
                'Accept' => 'application/json;version=' . $api_version,
                'Accept-Language' => $lang !== '' ? $lang : 'it',
            ),
            'timeout' => 30,
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (class_exists('ALMA_Logger')) {
                ALMA_Logger::warning('Viator destinations catalog fetch failed.', array(
                    'error' => is_wp_error($response) ? $response->get_error_message() : ('HTTP ' . wp_remote_retrieve_response_code($response)),
                ));
            }
            return array();
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['destinations']) || !is_array($data['destinations'])) {
            return array();
        }

        $map = array();
        foreach ($data['destinations'] as $destination) {
            if (!is_array($destination)) {
                continue;
            }
            $id = sanitize_text_field((string) ($destination['destinationId'] ?? ($destination['ref'] ?? '')));
            $name = sanitize_text_field((string) ($destination['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $map[$id] = array(
                'name' => $name,
                'type' => sanitize_text_field((string) ($destination['type'] ?? '')),
                'parent' => sanitize_text_field((string) ($destination['parentDestinationId'] ?? '')),
            );
        }
        return $map;
    }

    private static function map_type($viator_type) {
        $viator_type = strtoupper((string) $viator_type);
        $map = array(
            'CITY' => 'city',
            'TOWN' => 'city',
            'VILLAGE' => 'city',
            'REGION' => 'region',
            'STATE' => 'region',
            'PROVINCE' => 'region',
            'COUNTRY' => 'country',
            'ISLAND' => 'area',
            'NATIONAL_PARK' => 'area',
            'DISTRICT' => 'area',
            'NEIGHBORHOOD' => 'area',
        );
        return $map[$viator_type] ?? 'city';
    }
}
