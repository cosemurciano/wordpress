<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Viator {
    const ENV_SANDBOX = 'sandbox';
    const ENV_PRODUCTION = 'production';

    private function source_settings($source){ return json_decode((string)($source['settings'] ?? '{}'), true) ?: array(); }
    private function source_credentials($source){ return json_decode((string)($source['credentials'] ?? '{}'), true) ?: array(); }

    private function safe_substr($text, $limit = 120) {
        $text = (string) $text;
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $limit); }
        return substr($text, 0, $limit);
    }

    private function resolve_environment($settings) {
        $env = sanitize_key($settings['environment'] ?? self::ENV_SANDBOX);
        return in_array($env, array(self::ENV_SANDBOX, self::ENV_PRODUCTION), true) ? $env : '';
    }
    private function base_url_for_environment($environment) { return $environment === self::ENV_PRODUCTION ? 'https://api.viator.com/partner' : 'https://api.sandbox.viator.com/partner'; }

    private function build_headers($settings, $credentials, $include_content_type = false) {
        $api_key = sanitize_text_field($credentials['api_key'] ?? '');
        if ($api_key === '') return new WP_Error('missing_credentials', __('API key Viator mancante.', 'affiliate-link-manager-ai'));
        $api_version = sanitize_text_field($settings['api_version'] ?? '2.0');
        if ($api_version === '') return new WP_Error('invalid_api_version', __('Versione API Viator non valida.', 'affiliate-link-manager-ai'));
        $headers = array('exp-api-key' => $api_key, 'Accept' => 'application/json;version=' . $api_version);
        $lang = sanitize_text_field($settings['accept_language'] ?? 'it');
        if ($lang !== '') $headers['Accept-Language'] = $lang;
        if ($include_content_type) $headers['Content-Type'] = 'application/json';
        return $headers;
    }

    private function build_query_params($settings) {
        $query = array();
        if (!empty($settings['campaign_value'])) $query['campaign-value'] = sanitize_text_field($settings['campaign_value']);
        if (!empty($settings['target_lander'])) $query['target-lander'] = sanitize_text_field($settings['target_lander']);
        return $query;
    }

    private function normalize_sort_order($settings) {
        $raw = strtoupper(sanitize_text_field($settings['sort_order'] ?? ''));
        if ($raw === '' && isset($settings['order'])) $raw = strtoupper(sanitize_text_field($settings['order']));
        if ($raw === 'ASC') return 'ASCENDING';
        if ($raw === 'DESC') return 'DESCENDING';
        if (in_array($raw, array('ASCENDING', 'DESCENDING'), true)) return $raw;
        return '';
    }

    private function sort_supports_custom_order($sort) {
        $sort = strtoupper((string) $sort);
        return $sort !== '' && $sort !== 'DEFAULT';
    }

    private function build_products_search_body($settings) {
        $destination_id = sanitize_text_field($settings['default_destination_id'] ?? '');
        if ($destination_id === '') return new WP_Error('missing_destination_id', __('Destination ID mancante per products_search.', 'affiliate-link-manager-ai'));
        $result_count = max(1, min(10, (int) ($settings['result_count'] ?? 5)));
        $currency = sanitize_text_field($settings['currency'] ?? 'EUR');
        $body = array('filtering' => array('destination' => (string) $destination_id), 'pagination' => array('start' => 1, 'count' => $result_count), 'currency' => $currency);
        $sort = strtoupper(sanitize_text_field($settings['sort'] ?? 'DEFAULT'));
        $sort_order = $this->normalize_sort_order($settings);
        if ($sort !== '' && $sort !== 'DEFAULT') {
            $body['sorting'] = array('sort' => $sort);
            if ($this->sort_supports_custom_order($sort) && $sort_order !== '') $body['sorting']['order'] = $sort_order;
        }
        return $body;
    }

    private function build_freetext_search_body($settings) {
        $search_term = sanitize_text_field($settings['default_search_term'] ?? '');
        if ($search_term === '') return new WP_Error('missing_search_term', __('Search term mancante per freetext_search.', 'affiliate-link-manager-ai'));
        $result_count = max(1, min(10, (int) ($settings['result_count'] ?? 5)));
        $currency = sanitize_text_field($settings['currency'] ?? 'EUR');
        $body = array('searchTerm' => $search_term, 'searchTypes' => array(array('searchType' => 'PRODUCTS', 'pagination' => array('start' => 1, 'count' => $result_count))), 'currency' => $currency);
        $destination_id = sanitize_text_field($settings['default_destination_id'] ?? '');
        if ($destination_id !== '') $body['productFiltering'] = array('destination' => (string) $destination_id);
        $sort = strtoupper(sanitize_text_field($settings['sort'] ?? 'DEFAULT'));
        $sort_order = $this->normalize_sort_order($settings);
        if ($sort !== '' && $sort !== 'DEFAULT') {
            $body['productSorting'] = array('sort' => $sort);
            if ($this->sort_supports_custom_order($sort) && $sort_order !== '') $body['productSorting']['order'] = $sort_order;
        }
        return $body;
    }

    private function send_json_post($endpoint, $query, $body, $headers) {
        return wp_remote_post(add_query_arg($query, $endpoint), array('timeout' => 10, 'redirection' => 1, 'headers' => $headers, 'body' => wp_json_encode($body)));
    }

    private function map_http_error($code) {
        if ($code >= 200 && $code < 300) return null;
        if ($code === 400) return new WP_Error('api_error', __('Richiesta Viator non valida. Verifica destination ID, search term e parametri.', 'affiliate-link-manager-ai'));
        if ($code === 401) return new WP_Error('unauthorized', __('API key Viator non valida o non autorizzata.', 'affiliate-link-manager-ai'));
        if ($code === 403) return new WP_Error('forbidden', __('API key Viator senza permessi per questo endpoint.', 'affiliate-link-manager-ai'));
        if ($code === 429) return new WP_Error('rate_limited', __('Limite richieste Viator raggiunto. Riprova più tardi.', 'affiliate-link-manager-ai'));
        if ($code === 500 || $code === 503) return new WP_Error('api_error', __('Errore temporaneo API Viator.', 'affiliate-link-manager-ai'));
        return new WP_Error('api_error', __('Errore API Viator.', 'affiliate-link-manager-ai'));
    }

    private function sanitize_example($value) { $text = is_scalar($value) ? (string) $value : wp_json_encode($value); return $this->safe_substr(sanitize_text_field($text), 120); }
    private function flatten($value, $path, &$fields) {
        if (!is_array($value)) {
            $fields[] = array('path' => (string) $path, 'label' => ucwords(str_replace(array('.', '_', '[', ']'), ' ', (string) $path)), 'group' => 'Campione API', 'endpoint' => 'Viator runtime', 'type' => gettype($value), 'description' => 'Campo rilevato automaticamente dal payload Viator.', 'example' => $this->sanitize_example($value), 'mapping_hint' => '—', 'status' => 'runtime', 'compliance_note' => '');
            return;
        }
        foreach ($value as $k => $v) {
            $seg = is_int($k) ? '[]' : (string) $k;
            $next = $path === '' ? $seg : $path . (is_int($k) ? '' : '.') . $seg;
            $this->flatten($v, $next, $fields);
        }
    }

    public function test_connection($source) { /* unchanged behavior with robust mapping */
        $settings = $this->source_settings($source); $credentials = $this->source_credentials($source); $environment = $this->resolve_environment($settings);
        if ($environment === '') return new WP_Error('invalid_environment', __('Environment Viator non valido.', 'affiliate-link-manager-ai'));
        $headers = $this->build_headers($settings, $credentials, false); if (is_wp_error($headers)) return $headers;
        $response = wp_remote_get($this->base_url_for_environment($environment) . '/products/tags', array('timeout' => 8, 'redirection' => 1, 'headers' => $headers));
        if (is_wp_error($response)) return new WP_Error('timeout', __('Timeout o errore di rete verso Viator.', 'affiliate-link-manager-ai'));
        $err = $this->map_http_error((int) wp_remote_retrieve_response_code($response));
        return $err ? $err : array('success' => true, 'message' => __('Connessione Viator riuscita.', 'affiliate-link-manager-ai'));
    }

    public function discover_fields($source, $force_refresh = false) {
        $settings = $this->source_settings($source); $credentials = $this->source_credentials($source); $environment = $this->resolve_environment($settings);
        if ($environment === '') return new WP_Error('invalid_environment', __('Environment Viator non valido.', 'affiliate-link-manager-ai'));
        $search_model = sanitize_key($settings['search_model'] ?? 'products_search');
        if (!in_array($search_model, array('products_search', 'freetext_search'), true)) return new WP_Error('missing_minimum_criteria', __('Modello di ricerca Viator non supportato.', 'affiliate-link-manager-ai'));

        $cache_basis = array('id' => (int) ($source['id'] ?? 0), 'env' => $environment, 'model' => $search_model, 'destination' => sanitize_text_field($settings['default_destination_id'] ?? ''), 'term' => sanitize_text_field($settings['default_search_term'] ?? ''), 'currency' => sanitize_text_field($settings['currency'] ?? 'EUR'), 'count' => (int) ($settings['result_count'] ?? 5), 'sort' => sanitize_text_field($settings['sort'] ?? ''), 'sort_order' => sanitize_text_field($settings['sort_order'] ?? ($settings['order'] ?? '')), 'legacy_order' => sanitize_text_field($settings['order'] ?? ''));
        $cache_key = 'alma_viator_fields_' . md5(wp_json_encode($cache_basis));
        if (!$force_refresh) { $cached = get_transient($cache_key); if (is_array($cached)) return $cached; }

        $headers = $this->build_headers($settings, $credentials, true); if (is_wp_error($headers)) return $headers;
        $query = $this->build_query_params($settings);
        $endpoint = $this->base_url_for_environment($environment) . ($search_model === 'freetext_search' ? '/search/freetext' : '/products/search');
        $body = $search_model === 'freetext_search' ? $this->build_freetext_search_body($settings) : $this->build_products_search_body($settings);
        if (is_wp_error($body)) return $body;

        $response = $this->send_json_post($endpoint, $query, $body, $headers);
        if (is_wp_error($response)) return new WP_Error('timeout', __('Timeout o errore di rete verso Viator.', 'affiliate-link-manager-ai'));
        $code = (int) wp_remote_retrieve_response_code($response); $err = $this->map_http_error($code); if ($err) return $err;

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return new WP_Error('invalid_json', __('Risposta Viator non JSON o non interpretabile.', 'affiliate-link-manager-ai'));
        $sample = array();
        if ($search_model === 'products_search') $sample = is_array($data['products'] ?? null) ? $data['products'] : array();
        if ($search_model === 'freetext_search') $sample = is_array($data['products']['results'] ?? null) ? $data['products']['results'] : array();

        $fields = array();
        if (is_array($sample) && !empty($sample)) $this->flatten($sample, 'products', $fields);
        $result = array('fields' => array_slice($fields, 0, 300), 'origin' => parse_url($endpoint, PHP_URL_HOST), 'endpoint' => $endpoint, 'generated_at' => current_time('mysql'));
        if (empty($fields)) $result['message'] = __('Risposta valida, ma nessun prodotto trovato con questi criteri.', 'affiliate-link-manager-ai');
        set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }
}
