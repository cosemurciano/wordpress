<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Custom_API {

    private function safe_substr($text, $limit = 120) {
        $text = (string) $text;
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $limit); }
        return substr($text, 0, $limit);
    }
    private function source_settings($source){ return json_decode((string)($source['settings'] ?? '{}'), true) ?: array(); }
    private function source_credentials($source){ return json_decode((string)($source['credentials'] ?? '{}'), true) ?: array(); }

    private function build_request($source) {
        $settings = $this->source_settings($source);
        $credentials = $this->source_credentials($source);
        $endpoint = esc_url_raw($settings['endpoint'] ?? ($settings['base_url'] ?? ''));
        if (!$endpoint) { return new WP_Error('missing_endpoint', __('Endpoint mancante.', 'affiliate-link-manager-ai')); }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) { return new WP_Error('invalid_endpoint', __('Endpoint non valido.', 'affiliate-link-manager-ai')); }
        $method = strtoupper(sanitize_text_field($settings['method'] ?? 'GET'));
        $headers = array('Accept' => 'application/json');
        if (!empty($settings['user_agent'])) { $headers['User-Agent'] = sanitize_text_field($settings['user_agent']); }
        if (!empty($credentials['api_key'])) { $headers['X-API-Key'] = sanitize_text_field($credentials['api_key']); }
        if (!empty($credentials['access_token'])) { $headers['Authorization'] = 'Bearer ' . sanitize_text_field($credentials['access_token']); }
        if (!empty($credentials['bearer_token'])) { $headers['Authorization'] = 'Bearer ' . sanitize_text_field($credentials['bearer_token']); }
        if (empty($credentials['api_key']) && empty($credentials['access_token']) && empty($credentials['bearer_token'])) {
            return new WP_Error('missing_credentials', __('Credenziali mancanti.', 'affiliate-link-manager-ai'));
        }
        return array('endpoint' => $endpoint, 'args' => array('method' => $method, 'timeout' => 8, 'redirection' => 1, 'headers' => $headers));
    }

    public function test_connection($source) {
        $request = $this->build_request($source);
        if (is_wp_error($request)) { return $request; }
        $response = wp_remote_request($request['endpoint'], $request['args']);
        if (is_wp_error($response)) { return new WP_Error('timeout', __('Timeout o errore di rete.', 'affiliate-link-manager-ai')); }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 400) { return array('success' => true, 'message' => __('Connessione riuscita.', 'affiliate-link-manager-ai')); }
        return new WP_Error('api_error', __('Errore API remoto.', 'affiliate-link-manager-ai'));
    }

    public function discover_fields($source, $force_refresh = false) {
        $cache_key = 'alma_fields_' . md5((int)$source['id'] . '|' . wp_json_encode(array_intersect_key($this->source_settings($source), array_flip(array('endpoint','base_url','method','endpoint_path')))));
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) { return $cached; }
        }
        $request = $this->build_request($source);
        if (is_wp_error($request)) { return $request; }
        $response = wp_remote_request($request['endpoint'], $request['args']);
        if (is_wp_error($response)) { return new WP_Error('timeout', __('Timeout o errore di rete.', 'affiliate-link-manager-ai')); }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') { return new WP_Error('empty_response', __('Risposta API vuota.', 'affiliate-link-manager-ai')); }
        if (strlen($body) > 200000) { return new WP_Error('response_too_large', __('Risposta troppo grande da analizzare.', 'affiliate-link-manager-ai')); }
        $data = json_decode($body, true);
        if (!is_array($data)) { return new WP_Error('invalid_json', __('Risposta non JSON o non interpretabile.', 'affiliate-link-manager-ai')); }

        $fields = array();
        $this->flatten($data, '', $fields);
        $result = array('fields' => array_slice($fields, 0, 300), 'origin' => parse_url($request['endpoint'], PHP_URL_HOST));
        set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
        return $result;
    }

    private function flatten($value, $path, &$fields) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $seg = is_int($k) ? '[' . $k . ']' : (string)$k;
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
    private function sanitize_example($value) {
        $text = is_scalar($value) ? (string)$value : wp_json_encode($value);
        $text = preg_replace('/(bearer\s+[a-z0-9\-\._~\+\/=]+)/i', '[redacted]', $text);
        $text = preg_replace('/([a-z0-9_\-]{20,})/i', '[redacted]', $text);
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $text);
        return $this->safe_substr(sanitize_text_field($text), 120);
    }
    private function mapping_hint($path) {
        $p = strtolower($path);
        $map = array('title'=>'titolo','name'=>'titolo','url'=>'URL affiliato','link'=>'URL affiliato','description'=>'descrizione','image'=>'immagine','price'=>'prezzo','currency'=>'valuta','destination'=>'destinazione','category'=>'categoria','rating'=>'rating','availability'=>'disponibilità','start'=>'data inizio/fine','end'=>'data inizio/fine','id'=>'provider ID');
        foreach ($map as $k=>$v) { if (strpos($p, $k) !== false) return $v; }
        return '—';
    }
}
