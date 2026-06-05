<?php
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Widget_AI_Rewriter {
    const TASK = 'widget_link_rewrite';
    const DEFAULT_MAX_OUTPUT_TOKENS = 3000;
    const DEFAULT_TIMEOUT = 60;
    const MAX_LINKS = 20;

    public static function default_prompt() {
        return 'Riscrivi il titolo e la descrizione dei Link Affiliati per un widget editoriale SEO oriented. Usa parole diverse rispetto al testo del provider. Mantieni accuratezza, destinazione, tipo esperienza e benefici principali. Non inventare prezzi, disponibilità, sconti, recensioni o condizioni non presenti nel contesto. Scrivi in italiano naturale, chiaro e utile per viaggiatori. Titolo massimo 80 caratteri. Descrizione massimo 320 caratteri. Evita keyword stuffing. Mantieni tono editoriale autorevole e accessibile.';
    }

    public static function is_openai_configured() {
        return trim((string) get_option('alma_openai_api_key', '')) !== '';
    }

    public static function get_prompt() {
        $prompt = trim((string) get_option('alma_widget_ai_rewrite_prompt', ''));
        return $prompt !== '' ? $prompt : self::default_prompt();
    }

    public static function get_max_output_tokens() {
        $tokens = absint(get_option('alma_widget_ai_rewrite_max_output_tokens', self::DEFAULT_MAX_OUTPUT_TOKENS));
        return $tokens > 0 ? $tokens : self::DEFAULT_MAX_OUTPUT_TOKENS;
    }

    public static function get_timeout() {
        $timeout = absint(get_option('alma_widget_ai_rewrite_timeout', self::DEFAULT_TIMEOUT));
        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public static function rewrite_links($link_ids, $args = array()) {
        $link_ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $link_ids)))), 0, self::MAX_LINKS);
        $widget_id = isset($args['widget_id']) ? absint($args['widget_id']) : 0;

        if (empty($link_ids)) {
            return array('success' => true, 'items' => array(), 'model' => '');
        }

        if (!self::is_openai_configured()) {
            $message = __('OpenAI non è configurato. Configura la chiave API nelle impostazioni prima di creare Widget Link con riscrittura AI.', 'affiliate-link-manager-ai');
            self::log_result(false, '', 0, null, $widget_id, $link_ids, $message);
            return array('success' => false, 'error' => $message);
        }

        $prompt = self::get_prompt();
        $prepared = self::prepare_links($link_ids, $prompt);
        if (is_wp_error($prepared)) {
            $message = $prepared->get_error_message();
            self::log_result(false, '', 0, null, $widget_id, $link_ids, $message);
            return array('success' => false, 'error' => $message);
        }

        $system_prompt = implode("\n", array(
            'Sei un editor SEO per widget di Link Affiliati.',
            'Non copiare testo del provider.',
            'Non inventare prezzi, disponibilità, recensioni, rating, sconti o condizioni.',
            'Usa solo i dati disponibili nel Contesto AI e nei dati del link.',
            'Restituisci solo JSON valido, senza Markdown e senza testo extra.',
            'Mantieni un item per ogni link richiesto.',
            'Rispetta la lingua italiana salvo future configurazioni.',
            'Usa uno stile SEO naturale, senza keyword stuffing.',
        ));

        $user_prompt = "Prompt Widget configurato:\n" . $prompt . "\n\n";
        $user_prompt .= "Dati link in JSON compatto. Se ai_context è vuoto o segnala contesto non disponibile, usa solo titolo e descrizione originali senza inventare dettagli.\n";
        $user_prompt .= wp_json_encode(array('items' => $prepared['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $request = array(
            'system_prompt' => $system_prompt,
            'user_prompt' => $user_prompt,
            'max_output_tokens' => self::get_max_output_tokens(),
            'timeout' => self::get_timeout(),
            'temperature' => 0.2,
            'response_format' => self::response_schema(),
        );

        $response = ALMA_OpenAI_Service::request($request);
        $model = sanitize_text_field($response['model'] ?? get_option('alma_openai_model', ''));
        $response_time = isset($response['response_time']) ? (int) $response['response_time'] : 0;
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : null;

        if (empty($response['success'])) {
            $message = sanitize_text_field($response['error'] ?? __('Riscrittura AI widget non riuscita.', 'affiliate-link-manager-ai'));
            self::log_result(false, $model, $response_time, $usage, $widget_id, $link_ids, $message);
            return array('success' => false, 'error' => $message, 'model' => $model);
        }

        $validated = self::validate_response((string) ($response['response'] ?? ''), $link_ids, $prepared['hashes'], $model);
        if (is_wp_error($validated)) {
            $message = $validated->get_error_message();
            self::log_result(false, $model, $response_time, $usage, $widget_id, $link_ids, $message);
            return array('success' => false, 'error' => $message, 'model' => $model);
        }

        self::log_result(true, $model, $response_time, $usage, $widget_id, $link_ids, 'ok');

        return array('success' => true, 'items' => $validated, 'model' => $model);
    }

    private static function prepare_links($link_ids, $prompt) {
        $payload = array();
        $hashes = array();
        foreach ($link_ids as $link_id) {
            $post = get_post($link_id);
            if (!$post || $post->post_type !== 'affiliate_link' || $post->post_status !== 'publish') {
                return new WP_Error('invalid_link', sprintf(__('Link Affiliato non valido: %d', 'affiliate-link-manager-ai'), $link_id));
            }

            $source = self::get_source_data($link_id);
            $original_title = get_the_title($link_id);
            $original_description = wp_strip_all_tags($post->post_excerpt ?: $post->post_content);
            $ai_context = (string) get_post_meta($link_id, '_alma_ai_context', true);
            $source_instructions = (string) ($source['instructions'] ?? '');

            $payload[] = array(
                'link_id' => $link_id,
                'original_title' => self::limit_text($original_title, 200),
                'original_description' => self::limit_text($original_description, 600),
                'ai_context' => self::limit_text($ai_context !== '' ? $ai_context : 'Contesto AI non disponibile.', 1200),
                'source_instructions' => self::limit_text($source_instructions, 800),
                'source_name' => self::limit_text((string) ($source['name'] ?? ''), 160),
            );
            $hashes[(string) $link_id] = self::context_hash($ai_context, $original_title, $original_description, $prompt, $source_instructions);
        }

        return array('payload' => $payload, 'hashes' => $hashes);
    }

    private static function get_source_data($link_id) {
        global $wpdb;
        $source_id = absint(get_post_meta($link_id, '_alma_source_id', true));
        $data = array('id' => $source_id, 'name' => '', 'instructions' => '');

        if ($source_id > 0) {
            $table = $wpdb->prefix . 'alma_affiliate_sources';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                $source = $wpdb->get_row($wpdb->prepare("SELECT name, provider_label, provider, settings FROM {$table} WHERE id = %d", $source_id), ARRAY_A);
                if (is_array($source)) {
                    $provider = (string) (($source['provider_label'] ?? '') ?: ($source['provider'] ?? ''));
                    $data['name'] = trim((string) ($source['name'] ?? '') . ($provider !== '' ? ' · ' . $provider : ''));
                    $settings = json_decode((string) ($source['settings'] ?? ''), true);
                    if (is_array($settings)) {
                        $data['instructions'] = sanitize_textarea_field((string) ($settings['ai_source_instructions'] ?? ''));
                    }
                    return $data;
                }
            }
        }

        $snapshot = (string) get_post_meta($link_id, '_alma_source_name', true);
        $provider = (string) get_post_meta($link_id, '_alma_source_provider_label', true);
        if ($provider === '') {
            $provider = (string) get_post_meta($link_id, '_alma_source_provider', true);
        }
        $data['name'] = trim($snapshot . ($provider !== '' ? ' · ' . $provider : ''));
        return $data;
    }

    private static function response_schema() {
        return array(
            'type' => 'json_schema',
            'name' => 'widget_link_rewrite',
            'strict' => true,
            'schema' => array(
                'type' => 'object',
                'additionalProperties' => false,
                'required' => array('items'),
                'properties' => array(
                    'items' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => array('link_id', 'title', 'description'),
                            'properties' => array(
                                'link_id' => array('type' => 'integer'),
                                'title' => array('type' => 'string'),
                                'description' => array('type' => 'string'),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    private static function validate_response($raw, $requested_ids, $hashes, $model) {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
            return new WP_Error('invalid_json', __('Risposta AI non valida: JSON o items mancanti.', 'affiliate-link-manager-ai'));
        }

        $requested = array_fill_keys(array_map('strval', array_map('absint', $requested_ids)), true);
        $seen = array();
        $items = array();
        foreach ($decoded['items'] as $item) {
            if (!is_array($item) || !isset($item['link_id'], $item['title'], $item['description'])) {
                return new WP_Error('invalid_item', __('Risposta AI non valida: item incompleto.', 'affiliate-link-manager-ai'));
            }
            $link_id = absint($item['link_id']);
            $key = (string) $link_id;
            if (!$link_id || !isset($requested[$key])) {
                return new WP_Error('extra_item', __('Risposta AI non valida: contiene link_id non richiesti.', 'affiliate-link-manager-ai'));
            }
            if (isset($seen[$key])) {
                return new WP_Error('duplicate_item', __('Risposta AI non valida: link_id duplicato.', 'affiliate-link-manager-ai'));
            }
            $title = sanitize_text_field((string) $item['title']);
            $description = sanitize_textarea_field((string) $item['description']);
            if ($title === '' || $description === '') {
                return new WP_Error('empty_item', __('Risposta AI non valida: titolo o descrizione vuoti.', 'affiliate-link-manager-ai'));
            }
            if (self::text_length($title) > 120 || self::text_length($description) > 600) {
                return new WP_Error('too_long', __('Risposta AI non valida: titolo o descrizione troppo lunghi.', 'affiliate-link-manager-ai'));
            }
            $seen[$key] = true;
            $items[$key] = array(
                'title' => $title,
                'description' => $description,
                'context_hash' => sanitize_text_field($hashes[$key] ?? ''),
                'rewritten_at' => current_time('mysql'),
                'model' => sanitize_text_field($model),
            );
        }

        foreach ($requested as $key => $_) {
            if (!isset($seen[$key])) {
                return new WP_Error('missing_item', __('Risposta AI non valida: mancano uno o più link richiesti.', 'affiliate-link-manager-ai'));
            }
        }

        return $items;
    }

    public static function sanitize_rewritten_links($rewritten, $allowed_ids = array()) {
        $allowed = array_fill_keys(array_map('strval', array_map('absint', (array) $allowed_ids)), true);
        $out = array();
        foreach ((array) $rewritten as $link_id => $item) {
            $key = (string) absint($link_id);
            if ($key === '0' || (!empty($allowed) && !isset($allowed[$key])) || !is_array($item)) {
                continue;
            }
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            $description = sanitize_textarea_field((string) ($item['description'] ?? ''));
            if ($title === '' || $description === '') {
                continue;
            }
            $out[$key] = array(
                'title' => self::text_substr($title, 0, 120),
                'description' => self::text_substr($description, 0, 600),
                'context_hash' => sanitize_text_field((string) ($item['context_hash'] ?? '')),
                'rewritten_at' => sanitize_text_field((string) ($item['rewritten_at'] ?? '')),
                'model' => sanitize_text_field((string) ($item['model'] ?? '')),
            );
        }
        return $out;
    }

    private static function context_hash($ai_context, $title, $description, $prompt, $source_instructions) {
        return hash('sha256', implode("\n---\n", array((string) $ai_context, (string) $title, (string) $description, (string) $prompt, (string) $source_instructions)));
    }

    private static function limit_text($text, $limit) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        if (self::text_length($text) > $limit) {
            $text = self::text_substr($text, 0, $limit) . '…';
        }
        return $text;
    }

    private static function text_length($text) {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function text_substr($text, $start, $length) {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length) : substr($text, $start, $length);
    }

    private static function log_result($success, $model, $response_time, $usage, $widget_id, $link_ids, $message) {
        $input_tokens = self::usage_value($usage, array('input_tokens', 'prompt_tokens'));
        $output_tokens = self::usage_value($usage, array('output_tokens', 'completion_tokens'));
        $reference = 'widget:' . absint($widget_id) . ';links:' . implode(',', array_map('absint', (array) $link_ids));
        ALMA_AI_Usage_Logger::log(array(
            'task' => self::TASK,
            'model' => $model,
            'input_tokens' => $input_tokens,
            'output_tokens' => $output_tokens,
            'response_time' => (int) $response_time,
            'success' => $success,
            'error' => $success ? '' : sanitize_text_field($message),
            'reference_id' => $reference,
        ));
        $context = array('task' => self::TASK, 'model' => $model, 'response_time' => (int) $response_time, 'widget_id' => absint($widget_id), 'link_ids' => array_map('absint', (array) $link_ids), 'message' => sanitize_text_field($message));
        if ($success) {
            ALMA_Logger::info('Widget link AI rewrite completed', $context);
        } else {
            ALMA_Logger::warning('Widget link AI rewrite failed', $context);
        }
    }

    private static function usage_value($usage, $keys) {
        if (!is_array($usage)) {
            return null;
        }
        foreach ($keys as $key) {
            if (isset($usage[$key])) {
                return (int) $usage[$key];
            }
        }
        return null;
    }
}
