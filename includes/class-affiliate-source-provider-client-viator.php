<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Viator {
    const ENV_SANDBOX = 'sandbox';
    const ENV_PRODUCTION = 'production';

    private function source_settings($source){ return json_decode((string)($source['settings'] ?? '{}'), true) ?: array(); }
    private function source_credentials($source){ return json_decode((string)($source['credentials'] ?? '{}'), true) ?: array(); }

    private function resolve_environment($settings) {
        $env = sanitize_key($settings['environment'] ?? self::ENV_SANDBOX);
        if (!in_array($env, array(self::ENV_SANDBOX, self::ENV_PRODUCTION), true)) {
            return '';
        }
        return $env;
    }

    private function base_url_for_environment($environment) {
        if ($environment === self::ENV_PRODUCTION) {
            return 'https://api.viator.com/partner';
        }
        return 'https://api.sandbox.viator.com/partner';
    }

    private function build_headers($settings, $credentials, $include_content_type = false) {
        $api_key = sanitize_text_field($credentials['api_key'] ?? '');
        if ($api_key === '') {
            return new WP_Error('missing_credentials', __('API key Viator mancante.', 'affiliate-link-manager-ai'));
        }

        $api_version = sanitize_text_field($settings['api_version'] ?? '2.0');
        if ($api_version === '') {
            return new WP_Error('invalid_api_version', __('Versione API Viator non valida.', 'affiliate-link-manager-ai'));
        }

        $headers = array(
            'exp-api-key' => $api_key,
            'Accept' => 'application/json;version=' . $api_version,
        );

        $language = sanitize_text_field($settings['accept_language'] ?? 'it');
        if ($language !== '') {
            $headers['Accept-Language'] = $language;
        }

        if ($include_content_type) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function sanitize_example($value) {
        $text = is_scalar($value) ? (string)$value : wp_json_encode($value);
        return mb_substr(sanitize_text_field($text), 0, 120);
    }

    private function flatten($value, $path, &$fields) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $seg = is_int($k) ? '[]' : (string)$k;
                $next = $path === '' ? $seg : $path . (is_int($k) ? '' : '.') . $seg;
                $this->flatten($v, $next, $fields);
            }
            return;
        }

        $fields[] = array(
            'path' => $path,
            'label' => ucwords(str_replace(array('.', '_', '[', ']'), ' ', $path)),
            'type' => gettype($value),
            'example' => $this->sanitize_example($value),
            'mapping_hint' => $this->mapping_hint($path),
        );
    }

    private function mapping_hint($path) {
        $normalized = strtolower(str_replace('[]', '', $path));
        $map = array(
            'productcode' => 'provider ID / external_id',
            'title' => 'titolo',
            'description' => 'descrizione',
            'producturl' => 'URL affiliato',
            'images.variants.url' => 'immagine',
            'pricing.summary.fromprice' => 'prezzo',
            'pricing.currency' => 'valuta',
            'reviews.combinedaveragerating' => 'rating',
            'destinations.ref' => 'destinazione',
            'tags' => 'categoria/tag',
            'flags' => 'metadata',
            'duration' => 'durata',
            'itinerarytype' => 'tipo itinerario',
        );

        foreach ($map as $needle => $hint) {
            if (strpos($normalized, $needle) !== false) {
                return $hint;
            }
        }

        return '—';
    }

    public function test_connection($source) {
        $settings = $this->source_settings($source);
        $credentials = $this->source_credentials($source);
        $environment = $this->resolve_environment($settings);

        if ($environment === '') {
            return new WP_Error('invalid_environment', __('Environment Viator non valido.', 'affiliate-link-manager-ai'));
        }

        $headers = $this->build_headers($settings, $credentials, false);
        if (is_wp_error($headers)) { return $headers; }

        $endpoint = $this->base_url_for_environment($environment) . '/products/tags';
        $response = wp_remote_get($endpoint, array('timeout' => 8, 'redirection' => 1, 'headers' => $headers));

        if (is_wp_error($response)) {
            return new WP_Error('timeout', __('Timeout o errore di rete verso Viator.', 'affiliate-link-manager-ai'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array('success' => true, 'http_status' => $code, 'message' => __('Connessione Viator riuscita.', 'affiliate-link-manager-ai'));
        }
        if ($code === 401) { return new WP_Error('unauthorized', __('API key Viator non autorizzata.', 'affiliate-link-manager-ai')); }
        if ($code === 403) { return new WP_Error('forbidden', __('Accesso Viator negato per questo account.', 'affiliate-link-manager-ai')); }
        if ($code === 429) { return new WP_Error('rate_limited', __('Rate limit Viator raggiunto.', 'affiliate-link-manager-ai')); }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!empty(wp_remote_retrieve_body($response)) && !is_array($body)) {
            return new WP_Error('invalid_json', __('Risposta Viator non valida.', 'affiliate-link-manager-ai'));
        }

        return new WP_Error('api_error', sprintf(__('Errore API Viator (HTTP %d).', 'affiliate-link-manager-ai'), $code));
    }

    public function discover_fields($source, $force_refresh = false) {
        $settings = $this->source_settings($source);
        $credentials = $this->source_credentials($source);
        $environment = $this->resolve_environment($settings);
        if ($environment === '') {
            return new WP_Error('invalid_environment', __('Environment Viator non valido.', 'affiliate-link-manager-ai'));
        }

        $search_model = sanitize_key($settings['search_model'] ?? 'products_search');
        $query = array(
            'count' => max(1, min(10, (int)($settings['result_count'] ?? 5))),
            'currency' => sanitize_text_field($settings['currency'] ?? 'EUR'),
        );

        if (!empty($settings['campaign_value'])) { $query['campaignValue'] = sanitize_text_field($settings['campaign_value']); }
        if (!empty($settings['target_lander'])) { $query['targetLander'] = sanitize_text_field($settings['target_lander']); }

        if ($search_model === 'freetext_search') {
            $term = sanitize_text_field($settings['default_search_term'] ?? '');
            if ($term === '') {
                return new WP_Error('missing_minimum_criteria', __('Inserisci default_search_term per la discovery freetext.', 'affiliate-link-manager-ai'));
            }
            $endpoint = $this->base_url_for_environment($environment) . '/search/freetext';
            $query['searchTerm'] = $term;
        } else {
            $destination_id = sanitize_text_field($settings['default_destination_id'] ?? '');
            if ($destination_id === '') {
                return new WP_Error('missing_minimum_criteria', __('Inserisci default_destination_id per la discovery products/search.', 'affiliate-link-manager-ai'));
            }
            $endpoint = $this->base_url_for_environment($environment) . '/products/search';
            $query['destId'] = $destination_id;
        }

        $cache_key = 'alma_viator_fields_' . md5((int)($source['id'] ?? 0) . '|' . $environment . '|' . $search_model . '|' . wp_json_encode($query));
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) { return $cached; }
        }

        $headers = $this->build_headers($settings, $credentials, true);
        if (is_wp_error($headers)) { return $headers; }

        $response = wp_remote_post(add_query_arg($query, $endpoint), array('timeout' => 10, 'redirection' => 1, 'headers' => $headers, 'body' => wp_json_encode(array())));
        if (is_wp_error($response)) {
            return new WP_Error('timeout', __('Timeout o errore di rete verso Viator.', 'affiliate-link-manager-ai'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            if ($code === 401) { return new WP_Error('unauthorized', __('API key Viator non autorizzata.', 'affiliate-link-manager-ai')); }
            if ($code === 403) { return new WP_Error('forbidden', __('Accesso Viator negato per questo account.', 'affiliate-link-manager-ai')); }
            if ($code === 429) { return new WP_Error('rate_limited', __('Rate limit Viator raggiunto.', 'affiliate-link-manager-ai')); }
            return new WP_Error('api_error', sprintf(__('Errore API Viator (HTTP %d).', 'affiliate-link-manager-ai'), $code));
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('invalid_json', __('Risposta Viator non JSON o non interpretabile.', 'affiliate-link-manager-ai'));
        }

        $fields = array();
        $this->flatten($data, '', $fields);
        $result = array('fields' => array_slice($fields, 0, 300), 'origin' => parse_url($endpoint, PHP_URL_HOST));
        set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }
}
