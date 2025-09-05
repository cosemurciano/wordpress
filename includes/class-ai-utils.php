<?php
// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Funzioni di utilità per interagire con i servizi AI
 */
class ALMA_AI_Utils {
    /**
     * Effettua una chiamata alla Claude API
     *
     * @param string $user_prompt   Messaggio dell'utente
     * @param string $system_prompt Istruzioni di sistema opzionali
     * @param array  $conversation  Array di messaggi precedenti per mantenere il contesto
     * @return array Risultato con chiavi success, response, model, response_time
     */
    public static function call_claude_api($user_prompt, $system_prompt = '', $conversation = array()) {
        $api_key = trim(get_option('alma_claude_api_key'));
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'API Key non configurata');
        }

        $model       = get_option('alma_claude_model', 'claude-3-haiku-20240307');
        $temperature = (float) get_option('alma_claude_temperature', 0.7);

        $body = array(
            'model'       => $model,
            'max_tokens'  => 300,
            'temperature' => $temperature,
            'messages'    => array()
        );

        foreach ($conversation as $msg) {
            $body['messages'][] = array(
                'role'    => $msg['role'],
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => $msg['content'],
                    )
                )
            );
        }

        $body['messages'][] = array(
            'role'    => 'user',
            'content' => array(
                array(
                    'type' => 'text',
                    'text' => $user_prompt,
                )
            )
        );

        if (!empty($system_prompt)) {
            $body['system'] = $system_prompt;
        }
        $start_time = microtime(true);
        $response   = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'Accept'            => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ));
        $response_time = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message(), 'response_time' => $response_time);
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            $error = $data['error']['message'] ?? 'Errore di connessione a Claude';
            return array('success' => false, 'error' => $error, 'response_time' => $response_time);
        }

        if (empty($data['content'][0]['text'])) {
            return array('success' => false, 'error' => 'Risposta non valida da Claude', 'response_time' => $response_time);
        }

        return array(
            'success'       => true,
            'response'      => $data['content'][0]['text'],
            'model'         => $data['model'] ?? $model,
            'response_time' => $response_time,
        );
    }

    /**
     * Estrae il primo blocco JSON valido da una stringa
     *
     * @param string $text Testo da analizzare
     * @return string JSON individuato o stringa vuota
     */
    public static function extract_first_json($text) {
        $text = preg_replace('/```json\s*(.+?)\s*```/is', '$1', $text);
        $text = preg_replace('/```\s*(.+?)\s*```/is', '$1', $text);

        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            if ($char !== '{' && $char !== '[') {
                continue;
            }

            $open  = $char;
            $close = $char === '{' ? '}' : ']';
            $depth = 0;
            $in_string = false;
            $escape = false;

            for ($j = $i; $j < $len; $j++) {
                $c = $text[$j];

                if ($in_string) {
                    if ($c === '\\' && !$escape) {
                        $escape = true;
                        continue;
                    }
                    if ($c === '"' && !$escape) {
                        $in_string = false;
                    }
                    $escape = false;
                    continue;
                }

                if ($c === '"') {
                    $in_string = true;
                    continue;
                }

                if ($c === $open) {
                    $depth++;
                } elseif ($c === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($text, $i, $j - $i + 1);
                    }
                }
            }
        }

        return '';
    }
}
