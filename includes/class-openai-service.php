<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_OpenAI_Service {
    public static function request($args = array()) {
        $api_key = trim((string) get_option('alma_openai_api_key', ''));
        if ($api_key === '') {
            return array('success'=>false,'error'=>__('OpenAI non configurato', 'affiliate-link-manager-ai'));
        }

        $model = sanitize_text_field($args['model'] ?? get_option('alma_openai_model', 'gpt-5.4-mini'));
        $temperature = isset($args['temperature']) ? (float)$args['temperature'] : (float)get_option('alma_openai_temperature', 0.7);
        $max_output_tokens = isset($args['max_output_tokens']) ? absint($args['max_output_tokens']) : absint(get_option('alma_openai_max_output_tokens', 600));
        $timeout = isset($args['timeout']) ? absint($args['timeout']) : absint(get_option('alma_openai_timeout', 30));

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
        if ($temperature >= 0 && $temperature <= 2) { $body['temperature'] = $temperature; }
        if (!empty($args['json_output'])) { $body['text'] = array('format'=>array('type'=>'json_object')); }

        $start = microtime(true);
        $res = wp_remote_post('https://api.openai.com/v1/responses', array(
            'headers'=>array('Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'),
            'body'=>wp_json_encode($body),
            'timeout'=>$timeout > 0 ? $timeout : 30,
        ));
        $rt = round((microtime(true)-$start)*1000);

        if (is_wp_error($res)) return array('success'=>false,'error'=>__('Errore connessione AI', 'affiliate-link-manager-ai'),'response_time'=>$rt);
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $err = $data['error']['message'] ?? __('Errore OpenAI', 'affiliate-link-manager-ai');
            return array('success'=>false,'error'=>sanitize_text_field($err),'response_time'=>$rt,'model'=>$model);
        }
        $text = '';
        if (!empty($data['output_text'])) { $text = (string)$data['output_text']; }
        if ($text === '' && !empty($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                foreach ((array)($out['content'] ?? array()) as $c) { if (($c['type'] ?? '') === 'output_text' && !empty($c['text'])) { $text .= $c['text']; } }
            }
        }
        if (trim($text) === '') return array('success'=>false,'error'=>__('Risposta AI non valida', 'affiliate-link-manager-ai'),'response_time'=>$rt,'model'=>$data['model'] ?? $model);
        return array('success'=>true,'response'=>$text,'model'=>$data['model'] ?? $model,'response_time'=>$rt,'usage'=>$data['usage'] ?? null);
    }
}
