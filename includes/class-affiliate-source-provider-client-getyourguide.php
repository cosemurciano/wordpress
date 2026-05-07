<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_GetYourGuide {
    const BASE_URL = 'https://api.getyourguide.com/1/';

    private function source_settings($source){ return json_decode((string)($source['settings'] ?? '{}'), true) ?: array(); }
    private function source_credentials($source){ return json_decode((string)($source['credentials'] ?? '{}'), true) ?: array(); }

    private function build_headers($credentials) {
        $token = sanitize_text_field((string)($credentials['access_token'] ?? ''));
        if ($token === '') return new WP_Error('missing_credentials', __('Access token GetYourGuide mancante.', 'affiliate-link-manager-ai'));
        return array('X-ACCESS-TOKEN' => $token, 'Accept' => 'application/json');
    }

    private function map_http_error($code) {
        if ($code >= 200 && $code < 300) return null;
        if ($code === 401) return new WP_Error('unauthorized', __('Access token GetYourGuide non valido o non autorizzato.', 'affiliate-link-manager-ai'));
        if ($code === 403) return new WP_Error('forbidden', __('Account GetYourGuide senza permessi per questo endpoint o livello API insufficiente.', 'affiliate-link-manager-ai'));
        if ($code === 429) return new WP_Error('rate_limited', __('Rate limit GetYourGuide raggiunto. Riprova più tardi.', 'affiliate-link-manager-ai'));
        return new WP_Error('api_error', sprintf(__('Errore API GetYourGuide (HTTP %d).', 'affiliate-link-manager-ai'), (int)$code));
    }

    public function test_connection($source) {
        $settings = $this->source_settings($source);
        $credentials = $this->source_credentials($source);
        $result = $this->request_tours($settings, $credentials, array('q'=>sanitize_text_field((string)($settings['default_query'] ?? 'Rome')), 'limit'=>1, 'offset'=>0));
        if (is_wp_error($result)) return $result;
        return array('success'=>true,'http_status'=>(int)($result['status_code'] ?? 0),'message'=>__('Connessione GetYourGuide riuscita.', 'affiliate-link-manager-ai'));
    }

    public function discover_fields($source, $force_refresh = false) {
        $settings = $this->source_settings($source);
        $credentials = $this->source_credentials($source);
        $cache_key = 'alma_gyg_fields_' . md5(wp_json_encode(array('id'=>(int)($source['id'] ?? 0),'q'=>$settings['default_query'] ?? '')));
        if (!$force_refresh) { $cached = get_transient($cache_key); if (is_array($cached)) return $cached; }
        $result = $this->request_tours($settings, $credentials, array('q'=>sanitize_text_field((string)($settings['default_query'] ?? 'Rome')), 'limit'=>5, 'offset'=>0));
        if (is_wp_error($result)) return $result;
        $fields = array();
        $sample = is_array($result['items'] ?? null) ? $result['items'] : array();
        if (!empty($sample)) $this->flatten($sample, 'tours', $fields);
        $out = array('fields'=>array_slice($fields,0,300),'origin'=>'api.getyourguide.com','endpoint'=>self::BASE_URL . 'tours','generated_at'=>current_time('mysql'));
        if (empty($fields)) $out['message'] = __('Risposta valida, ma nessun tour GetYourGuide trovato con questi criteri.', 'affiliate-link-manager-ai');
        set_transient($cache_key, $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }

    public function fetch_items_for_import_preview($source, $settings, $credentials, $limit, $criteria = array()) {
        $query = sanitize_text_field((string)($criteria['import_search_term'] ?? $criteria['query'] ?? $criteria['destination'] ?? ''));
        if ($query === '') $query = sanitize_text_field((string)($settings['default_query'] ?? ''));
        if ($query === '') return new WP_Error('missing_search_term', __('Inserisci una query o configura una Query predefinita GetYourGuide.', 'affiliate-link-manager-ai'));
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)($criteria['import_start'] ?? 1) - 1);
        $result = $this->request_tours($settings, $credentials, array('q'=>$query,'limit'=>$limit,'offset'=>$offset));
        if (is_wp_error($result)) return $result;
        return is_array($result['items'] ?? null) ? $result['items'] : array();
    }

    private function request_tours($settings, $credentials, $runtime) {
        $headers = $this->build_headers($credentials);
        if (is_wp_error($headers)) return $headers;
        $params = array(
            'q' => sanitize_text_field((string)($runtime['q'] ?? '')),
            'cnt_language' => sanitize_text_field((string)($settings['cnt_language'] ?? $settings['language'] ?? 'it')),
            'currency' => sanitize_text_field((string)($settings['currency'] ?? 'EUR')),
            'limit' => max(1, min(100, (int)($runtime['limit'] ?? ($settings['limit'] ?? 20)))),
            'offset' => max(0, (int)($runtime['offset'] ?? 0)),
        );
        foreach (array('sortfield','sortdirection','category','coordinates','radius') as $key) {
            if (!empty($settings[$key])) $params[$key] = sanitize_text_field((string)$settings[$key]);
        }
        $timeout = max(3, min(30, (int)($settings['timeout'] ?? 10)));
        $response = wp_remote_get(add_query_arg($params, self::BASE_URL . 'tours'), array('timeout'=>$timeout,'redirection'=>1,'headers'=>$headers));
        if (is_wp_error($response)) return new WP_Error('timeout', __('Timeout o errore di rete verso GetYourGuide.', 'affiliate-link-manager-ai'));
        $code = (int)wp_remote_retrieve_response_code($response);
        $err = $this->map_http_error($code); if ($err) return $err;
        $body = (string)wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) return new WP_Error('invalid_json', __('Risposta GetYourGuide non JSON o non interpretabile.', 'affiliate-link-manager-ai'));
        $items = $this->extract_items($data);
        return array('success'=>true,'items'=>$items,'raw'=>$data,'error'=>'','status_code'=>$code,'warnings'=>empty($items) ? array(__('Nessun tour GetYourGuide restituito per questi criteri.', 'affiliate-link-manager-ai')) : array());
    }

    private function extract_items($data) {
        foreach (array('tours','data','results','items') as $key) {
            if (is_array($data[$key] ?? null)) return (array)$data[$key];
        }
        if (isset($data[0]) && is_array($data[0])) return $data;
        return array();
    }

    private function flatten($value, $path, &$fields) {
        if (!is_array($value)) {
            $text = is_scalar($value) ? sanitize_text_field((string)$value) : '';
            $fields[] = array('path'=>(string)$path,'group'=>'Campione API','endpoint'=>'GetYourGuide runtime','type'=>gettype($value),'description'=>'Campo rilevato automaticamente dal payload GetYourGuide.','example'=>substr($text,0,120),'mapping_hint'=>'—','status'=>'runtime','compliance_note'=>'');
            return;
        }
        foreach ($value as $k=>$v) {
            $seg = is_int($k) ? '[]' : (string)$k;
            $next = $path === '' ? $seg : $path . (is_int($k) ? '' : '.') . $seg;
            $this->flatten($v, $next, $fields);
        }
    }
}
