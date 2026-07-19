<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_OpenAI_Service {
    /**
     * Serializza il contesto per i prompt SENZA escape unicode: con il
     * default di wp_json_encode ogni accento diventava \u00e8/\u00f9 e il
     * modello, imitando il contesto, riproduceva gli escape nel contenuto
     * che poi arrivavano mutilati nel post ("più" -> "pif9"). Con
     * JSON_UNESCAPED_UNICODE il modello vede e restituisce testo reale.
     */
    public static function encode_context($data) {
        return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function request($args = array()) {
        $api_key = trim((string) get_option('alma_openai_api_key', ''));
        if ($api_key === '') {
            return array('success'=>false,'error'=>__('OpenAI non configurato', 'affiliate-link-manager-ai'));
        }

        $model = self::resolve_model($args['model'] ?? null);
        $temperature = isset($args['temperature']) ? (float)$args['temperature'] : (float)get_option('alma_openai_temperature', 0.7);
        $max_output_tokens = isset($args['max_output_tokens']) ? absint($args['max_output_tokens']) : absint(get_option('alma_openai_max_output_tokens', 600));
        $timeout = isset($args['timeout']) ? absint($args['timeout']) : absint(get_option('alma_openai_timeout', 30));
        $warnings = array();

        if (!empty($args['input_items']) && is_array($args['input_items'])) {
            // Loop agente (tool calling): il chiamante fornisce l'input completo
            // della Responses API, inclusi i function_call e i
            // function_call_output dei turni precedenti.
            $input = array_values($args['input_items']);
        } else {
            $input = array();
            if (!empty($args['system_prompt'])) {
                $input[] = array('role'=>'system','content'=>array(array('type'=>'input_text','text'=>(string)$args['system_prompt'])));
            }
            foreach ((array)($args['conversation'] ?? array()) as $msg) {
                if (empty($msg['role']) || !isset($msg['content'])) { continue; }
                $input[] = array('role'=>sanitize_key($msg['role']),'content'=>array(array('type'=>'input_text','text'=>(string)$msg['content'])));
            }
            $input[] = array('role'=>'user','content'=>array(array('type'=>'input_text','text'=>(string)($args['user_prompt'] ?? ''))));
        }

        $body = array('model'=>$model,'input'=>$input,'max_output_tokens'=>$max_output_tokens);
        if (!empty($args['tools']) && is_array($args['tools'])) {
            $body['tools'] = $args['tools'];
        }
        if (!empty($args['include']) && is_array($args['include'])) {
            $body['include'] = array_values(array_map('sanitize_text_field', $args['include']));
        }
        if (!empty($args['tool_choice'])) {
            $body['tool_choice'] = is_array($args['tool_choice']) ? $args['tool_choice'] : sanitize_text_field((string)$args['tool_choice']);
        }
        if (!empty($args['reasoning']) && is_array($args['reasoning']) && self::supports_reasoning($model)) {
            $body['reasoning'] = $args['reasoning'];
        }
        if ($temperature >= 0 && $temperature <= 2) { $body['temperature'] = $temperature; }
        foreach (array('top_p','presence_penalty','frequency_penalty') as $sampling_key) {
            if (isset($args[$sampling_key]) && is_numeric($args[$sampling_key])) { $body[$sampling_key] = (float)$args[$sampling_key]; }
        }

        $body = self::normalize_request_payload($body, $warnings);

        $response_format_used = 'none';
        if (!empty($args['response_format']) && is_array($args['response_format'])) {
            $format = self::normalize_responses_text_format($args['response_format']);
            $body['text'] = array('format'=>$format);
            $response_format_used = sanitize_key((string)($format['type'] ?? 'custom'));
        } elseif (!empty($args['json_output'])) {
            $body['text'] = array('format'=>array('type'=>'json_object'));
            $response_format_used = 'json_object';
        }

        $start = microtime(true);
        $res = self::post_responses_api($api_key, $body, $timeout);
        // Retry automatico sugli errori TRANSITORI (5xx del server OpenAI,
        // 429 rate limit, errori di connessione): un singolo hiccup faceva
        // fallire l'intero run dell'agente ("An error occurred while
        // processing your request"). Gli errori 4xx applicativi non vengono
        // ritentati.
        for ($transient_attempt = 1; $transient_attempt <= 2; $transient_attempt++) {
            $is_transient = is_wp_error($res) || in_array((int) wp_remote_retrieve_response_code($res), array(429, 500, 502, 503, 520, 524), true);
            if (!$is_transient) { break; }
            sleep(2 * $transient_attempt);
            $warnings[] = 'Errore transitorio OpenAI: tentativo automatico ' . $transient_attempt . '/2.';
            $res = self::post_responses_api($api_key, $body, $timeout);
        }
        $rt = round((microtime(true)-$start)*1000);

        if (is_wp_error($res)) {
            ALMA_Logger::error('OpenAI connection error', array('error' => $res->get_error_message(), 'model' => $model, 'response_time' => $rt));
            return array('success'=>false,'error'=>__('Errore connessione AI', 'affiliate-link-manager-ai'),'error_code'=>'api_connection_error','error_category'=>'api','response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings);
        }
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $retry_body = self::maybe_build_sampling_retry_body($body, $data);
            if ($retry_body !== null) {
                $warnings[] = __('Retry OpenAI eseguito senza temperature dopo errore di compatibilità modello.', 'affiliate-link-manager-ai');
                $initial_error = $data;
                $res = self::post_responses_api($api_key, $retry_body, $timeout);
                $rt = round((microtime(true)-$start)*1000);
                if (is_wp_error($res)) {
                    ALMA_Logger::error('OpenAI retry connection error', array('error' => $res->get_error_message(), 'model' => $model, 'response_time' => $rt, 'raw_response' => array('initial_error' => $initial_error)));
                    return array('success'=>false,'error'=>__('Errore connessione AI', 'affiliate-link-manager-ai'),'error_code'=>'api_connection_error','error_category'=>'api','response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings,'raw_response'=>array('initial_error'=>$initial_error));
                }
                $code = wp_remote_retrieve_response_code($res);
                $data = json_decode(wp_remote_retrieve_body($res), true);
                if (is_array($data)) {
                    $removed_sampling = array();
                    foreach (array('temperature','top_p','presence_penalty','frequency_penalty') as $key) { if (array_key_exists($key, $body) && !array_key_exists($key, $retry_body)) { $removed_sampling[] = $key; } }
                    $data['_alma_retry'] = array('removed_sampling_parameters'=>$removed_sampling,'initial_error'=>$initial_error);
                }
            }
        }
        if ($code < 200 || $code >= 300) {
            $err = $data['error']['message'] ?? __('Errore OpenAI', 'affiliate-link-manager-ai');
            $error_code = 'openai_http_error';
            $error_type = sanitize_key((string)($data['error']['type'] ?? ''));
            $error_msg_l = strtolower((string)($data['error']['message'] ?? ''));
            if ($code === 401 || $code === 403) { $error_code = 'auth_error'; }
            elseif ($code === 429) { $error_code = 'rate_limit'; }
            elseif ($code === 408) { $error_code = 'timeout'; }
            elseif (strpos($error_msg_l, 'response_format') !== false) { $error_code = 'response_format_unsupported'; }
            elseif (strpos($error_msg_l, 'model') !== false && strpos($error_msg_l, 'support') !== false) { $error_code = 'model_unsupported'; }
            ALMA_Logger::warning('OpenAI HTTP error', array('http_status' => $code, 'error_code' => $error_code, 'error_type' => $error_type, 'error' => $err, 'model' => $model, 'response_time' => $rt, 'raw_response' => $data));
            return array('success'=>false,'error'=>sanitize_text_field($err),'error_code'=>$error_code,'error_type'=>$error_type,'error_category'=>'api','http_status'=>$code,'response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'raw_response'=>$data,'warnings'=>$warnings);
        }
        $text = '';
        if (!empty($data['output_text'])) { $text = (string)$data['output_text']; }
        if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                foreach ((array)($out['content'] ?? array()) as $c) { if (($c['type'] ?? '') === 'output_text' && !empty($c['text'])) { $text .= $c['text']; } }
            }
        }
        // Function calls richieste dal modello (tool calling): con chiamate in
        // sospeso una risposta senza testo è legittima, non un errore.
        $function_calls = array();
        foreach ((array)($data['output'] ?? array()) as $out) {
            if (($out['type'] ?? '') === 'function_call') {
                $function_calls[] = array(
                    'call_id' => sanitize_text_field((string)($out['call_id'] ?? '')),
                    'name' => sanitize_key((string)($out['name'] ?? '')),
                    'arguments' => (string)($out['arguments'] ?? '{}'),
                );
            }
        }
        if (trim($text) === '' && empty($function_calls)) {
            ALMA_Logger::warning('OpenAI empty response', array('model' => $data['model'] ?? $model, 'response_time' => $rt, 'raw_response' => $data));
            return array('success'=>false,'error'=>__('Risposta AI vuota', 'affiliate-link-manager-ai'),'error_code'=>'empty_response','error_category'=>'api','response_time'=>$rt,'model'=>$data['model'] ?? $model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings,'raw_response'=>$data);
        }
        $usage = $data['usage'] ?? null;
        return array('success'=>true,'response'=>$text,'function_calls'=>$function_calls,'model'=>$data['model'] ?? $model,'response_time'=>$rt,'usage'=>$usage,'estimated_cost'=>self::estimate_cost_usd($data['model'] ?? $model, $usage),'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'raw_response'=>$data,'warnings'=>$warnings);
    }

    /**
     * Stima il costo in USD di una chiamata a partire dai token di usage.
     * Ritorna null se il prezzo del modello non è noto (meglio nessun dato che
     * un dato sbagliato: in precedenza veniva salvato il numero di token come costo).
     */
    public static function estimate_cost_usd($model, $usage) {
        if (!is_array($usage)) { return null; }
        $input_tokens = absint($usage['input_tokens'] ?? ($usage['prompt_tokens'] ?? 0));
        $output_tokens = absint($usage['output_tokens'] ?? ($usage['completion_tokens'] ?? 0));
        if ($input_tokens === 0 && $output_tokens === 0) { return null; }
        $prices = self::get_model_prices();
        $price = self::match_model_price(strtolower(trim((string)$model)), $prices);
        if (!$price) { return null; }
        $cost = ($input_tokens * $price['input'] + $output_tokens * $price['output']) / 1000000;
        return round($cost, 6);
    }

    /**
     * Prezzi USD per 1M token (input/output). Estendibili o sovrascrivibili con il
     * filtro `alma_openai_model_prices` (chiave = modello o prefisso di famiglia).
     */
    private static function get_model_prices() {
        $prices = array(
            'gpt-5-nano'   => array('input' => 0.05, 'output' => 0.40),
            'gpt-5-mini'   => array('input' => 0.25, 'output' => 2.00),
            'gpt-5'        => array('input' => 1.25, 'output' => 10.00),
            'gpt-4.1-nano' => array('input' => 0.10, 'output' => 0.40),
            'gpt-4.1-mini' => array('input' => 0.40, 'output' => 1.60),
            'gpt-4.1'      => array('input' => 2.00, 'output' => 8.00),
            'gpt-4o-mini'  => array('input' => 0.15, 'output' => 0.60),
            'gpt-4o'       => array('input' => 2.50, 'output' => 10.00),
            'o4-mini'      => array('input' => 1.10, 'output' => 4.40),
            'o3'           => array('input' => 2.00, 'output' => 8.00),
        );
        $filtered = apply_filters('alma_openai_model_prices', $prices);
        return is_array($filtered) ? $filtered : $prices;
    }

    private static function match_model_price($model, $prices) {
        if ($model === '') { return null; }
        if (isset($prices[$model])) { return $prices[$model]; }
        // Match per famiglia: la chiave più lunga che è prefisso del modello e ne
        // condivide la variante (mini/nano), così "gpt-5.4-mini" usa i prezzi "gpt-5-mini".
        $model_variant = self::model_variant($model);
        $best = null;
        $best_len = 0;
        foreach ($prices as $key => $price) {
            if (self::model_variant($key) !== $model_variant) { continue; }
            $family = (string) preg_replace('/-(mini|nano)$/', '', $key);
            if (strpos($model, $family) === 0 && strlen($family) > $best_len) {
                $best = $price;
                $best_len = strlen($family);
            }
        }
        return $best;
    }

    private static function model_variant($model) {
        if (substr($model, -5) === '-mini' || strpos($model, '-mini-') !== false) { return 'mini'; }
        if (substr($model, -5) === '-nano' || strpos($model, '-nano-') !== false) { return 'nano'; }
        return '';
    }

    public static function normalize_responses_text_format($format) {
        $format = is_array($format) ? $format : array();
        if (($format['type'] ?? '') === 'json_schema') {
            return $format;
        }
        if (($format['response_format_used'] ?? '') === 'json_schema' || isset($format['schema'])) {
            return array(
                'type'=>'json_schema',
                'name'=>sanitize_key((string)($format['name'] ?? 'alma_json_schema')),
                'strict'=>!empty($format['strict']),
                'schema'=>is_array($format['schema'] ?? null) ? $format['schema'] : array('type'=>'object','additionalProperties'=>true),
            );
        }
        if (($format['type'] ?? '') === 'json_object') { return array('type'=>'json_object'); }
        return $format;
    }

    public static function normalize_request_payload($body, &$warnings = array()) {
        $model = sanitize_text_field((string)($body['model'] ?? ''));
        if (self::uses_reasoning_sampling_rules($model)) {
            foreach (array('temperature','top_p','presence_penalty','frequency_penalty') as $key) {
                if (array_key_exists($key, $body)) {
                    unset($body[$key]);
                    if ($key === 'temperature') { $warnings[] = __('Parametro temperature omesso perché non supportato dal modello selezionato.', 'affiliate-link-manager-ai'); }
                }
            }
        }
        if (isset($body['reasoning']) && !self::supports_reasoning($model)) { unset($body['reasoning']); }
        return $body;
    }

    public static function uses_reasoning_sampling_rules($model) {
        $model = strtolower(trim((string)$model));
        return (bool)preg_match('/^(gpt-5(?:[.\-]|$)|o[134](?:[\-]|$))/', $model) || strpos($model, 'reasoning') !== false;
    }

    public static function supports_reasoning($model) { return self::uses_reasoning_sampling_rules($model); }

    private static function resolve_model($model = null) {
        $model = trim((string)($model ?? ''));
        if ($model === '') { $model = trim((string)get_option('alma_openai_model', '')); }
        if ($model === '') { $model = 'gpt-5.4-mini'; }
        return sanitize_text_field($model);
    }

    private static function post_responses_api($api_key, $body, $timeout) {
        return wp_remote_post('https://api.openai.com/v1/responses', array(
            'headers'=>array('Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($body),
            'timeout'=>$timeout > 0 ? $timeout : 30,
        ));
    }

    private static function maybe_build_sampling_retry_body($body, $data) {
        $message = strtolower((string)($data['error']['message'] ?? ''));
        if (strpos($message, 'unsupported parameter') === false && strpos($message, 'not supported') === false && strpos($message, 'unknown parameter') === false) { return null; }
        $sampling = array('temperature','top_p','presence_penalty','frequency_penalty');
        $matched = array();
        foreach ($sampling as $key) { if (strpos($message, $key) !== false && array_key_exists($key, $body)) { $matched[] = $key; } }
        if (!$matched) { return null; }
        $retry = $body;
        foreach ($matched as $key) { unset($retry[$key]); }
        return $retry;
    }
}
