<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_OpenAI_Service {
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

        $input = array();
        if (!empty($args['system_prompt'])) {
            $input[] = array('role'=>'system','content'=>array(array('type'=>'input_text','text'=>(string)$args['system_prompt'])));
        }
        foreach ((array)($args['conversation'] ?? array()) as $msg) {
            if (empty($msg['role']) || !isset($msg['content'])) { continue; }
            $input[] = array('role'=>sanitize_key($msg['role']),'content'=>array(array('type'=>'input_text','text'=>(string)$msg['content'])));
        }
        $input[] = array('role'=>'user','content'=>array(array('type'=>'input_text','text'=>(string)($args['user_prompt'] ?? ''))));

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
        $rt = round((microtime(true)-$start)*1000);

        if (is_wp_error($res)) return array('success'=>false,'error'=>__('Errore connessione AI', 'affiliate-link-manager-ai'),'error_code'=>'api_connection_error','error_category'=>'api','response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings);
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $retry_body = self::maybe_build_sampling_retry_body($body, $data);
            if ($retry_body !== null) {
                $warnings[] = __('Retry OpenAI eseguito senza temperature dopo errore di compatibilità modello.', 'affiliate-link-manager-ai');
                $initial_error = $data;
                $res = self::post_responses_api($api_key, $retry_body, $timeout);
                $rt = round((microtime(true)-$start)*1000);
                if (is_wp_error($res)) return array('success'=>false,'error'=>__('Errore connessione AI', 'affiliate-link-manager-ai'),'error_code'=>'api_connection_error','error_category'=>'api','response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings,'raw_response'=>array('initial_error'=>$initial_error));
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
            return array('success'=>false,'error'=>sanitize_text_field($err),'error_code'=>$error_code,'error_type'=>$error_type,'error_category'=>'api','http_status'=>$code,'response_time'=>$rt,'model'=>$model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'raw_response'=>$data,'warnings'=>$warnings);
        }
        $text = '';
        if (!empty($data['output_text'])) { $text = (string)$data['output_text']; }
        if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                foreach ((array)($out['content'] ?? array()) as $c) { if (($c['type'] ?? '') === 'output_text' && !empty($c['text'])) { $text .= $c['text']; } }
            }
        }
        if (trim($text) === '') return array('success'=>false,'error'=>__('Risposta AI vuota', 'affiliate-link-manager-ai'),'error_code'=>'empty_response','error_category'=>'api','response_time'=>$rt,'model'=>$data['model'] ?? $model,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'warnings'=>$warnings,'raw_response'=>$data);
        return array('success'=>true,'response'=>$text,'model'=>$data['model'] ?? $model,'response_time'=>$rt,'usage'=>$data['usage'] ?? null,'max_output_tokens'=>$max_output_tokens,'response_format_used'=>$response_format_used,'raw_response'=>$data,'warnings'=>$warnings);
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
