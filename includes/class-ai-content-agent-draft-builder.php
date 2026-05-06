<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Builder {
    const TASK_SELECTION = 'content_agent_draft_from_selection';
    private static function fail($message, $model = '', $reference_id = '', $extra = array()) {
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>false,'error'=>sanitize_text_field($message),'model'=>sanitize_text_field($model),'reference_id'=>sanitize_text_field($reference_id)));
        return array_merge(array('success'=>false,'error'=>sanitize_text_field($message),'warnings'=>array()), $extra);
    }


    private static function sanitize_response_preview($text, $max = 800) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$text)));
        if (strlen($text) > $max) { $text = substr($text, 0, $max) . '…'; }
        return $text;
    }

    private static function parse_ai_json_response($response_text) {
        $raw = (string)$response_text;
        if (trim($raw) === '') {
            return new WP_Error('empty_response', 'OpenAI ha restituito una risposta vuota.');
        }

        $attempt = json_decode($raw, true);
        if (is_array($attempt)) { return $attempt; }
        $first_error = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_decode_failed';

        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```$/', '', (string)$clean);
        $attempt = json_decode((string)$clean, true);
        if (is_array($attempt)) {
            if ((string)$clean !== trim($raw)) { $attempt['_alma_parse_warnings'][] = 'json_wrapped_in_markdown'; }
            return $attempt;
        }

        $extracted = ALMA_AI_Content_Agent_Text_Utils::extract_first_json((string)$clean);
        $attempt = json_decode((string)$extracted, true);
        if (is_array($attempt)) {
            if (trim((string)$extracted) !== trim((string)$clean)) { $attempt['_alma_parse_warnings'][] = 'text_outside_json_object'; }
            return $attempt;
        }

        $second_error = function_exists('json_last_error_msg') ? json_last_error_msg() : 'json_decode_failed';
        $trimmed = trim((string)$clean);
        $open_braces = substr_count($trimmed, '{');
        $close_braces = substr_count($trimmed, '}');
        $code = 'json_decode_failed';
        $message = 'OpenAI ha restituito JSON non parsabile.';
        if (strpos((string)$clean, '```') !== false || strpos((string)$raw, '```') !== false) {
            $code = 'markdown_wrapper';
            $message = 'OpenAI ha restituito JSON con Markdown o testo non conforme.';
        } elseif ($open_braces > $close_braces || ($trimmed !== '' && !preg_match('/[}\]]\s*$/', $trimmed))) {
            $code = 'json_truncated';
            $message = 'OpenAI ha restituito una risposta probabilmente troncata.';
        }
        return new WP_Error($code, $message, array('json_error'=>$second_error ?: $first_error));
    }

    private static function validate_output_contract($parsed) {
        if (!is_array($parsed)) { return new WP_Error('json_not_object', 'La risposta JSON non contiene un oggetto valido.'); }
        $required = array('title','slug','content','excerpt','seo_title','seo_description','affiliate_shortcodes_used','affiliate_urls_used','media_used','warnings');
        $missing = array();
        foreach ($required as $key) { if (!array_key_exists($key, $parsed)) { $missing[] = $key; } }

        $out = $parsed;
        foreach (array('excerpt','seo_title','seo_description','slug') as $k) { if (!isset($out[$k]) || !is_string($out[$k])) { $out[$k] = ''; } }
        foreach (array('affiliate_shortcodes_used','affiliate_urls_used','media_used','warnings') as $k) { if (!isset($out[$k]) || !is_array($out[$k])) { $out[$k] = array(); } }
        foreach ((array)($out['_alma_parse_warnings'] ?? array()) as $parse_warning) {
            if ($parse_warning === 'text_outside_json_object') { $out['warnings'][] = 'Risposta OpenAI con testo fuori dall’oggetto JSON: oggetto JSON estratto automaticamente.'; }
            if ($parse_warning === 'json_wrapped_in_markdown') { $out['warnings'][] = 'Risposta OpenAI con wrapper Markdown: JSON ripulito automaticamente.'; }
        }
        $out['title'] = isset($out['title']) ? (string)$out['title'] : '';
        $out['content'] = isset($out['content']) ? (string)$out['content'] : '';
        if (trim($out['title']) === '') { return new WP_Error('title_empty', 'La risposta JSON è valida ma il campo title è vuoto o mancante.', array('missing_fields'=>$missing)); }
        if (trim(wp_strip_all_tags($out['content'])) === '') { return new WP_Error('content_empty', 'La risposta JSON è valida ma il campo content è vuoto o mancante.', array('missing_fields'=>$missing)); }
        if (mb_strlen(trim(wp_strip_all_tags($out['content']))) < 80) { return new WP_Error('content_too_short', 'La risposta JSON contiene un contenuto troppo corto o non utilizzabile.', array('missing_fields'=>$missing)); }
        if (trim($out['slug']) === '') {
            $out['slug'] = sanitize_title($out['title']);
            $out['warnings'][] = 'Slug mancante nell’output AI: fallback locale generato dal titolo.';
        } else {
            $sanitized_slug = sanitize_title($out['slug']);
            if ($sanitized_slug !== $out['slug']) {
                $out['warnings'][] = 'Slug AI non valido: sanificato localmente.';
                $out['slug'] = $sanitized_slug;
            }
        }
        $blocking_missing = array_values(array_diff($missing, array('slug')));
        if (!empty($blocking_missing)) { return new WP_Error('contract_missing_fields', 'Output OpenAI non conforme al contratto: campi obbligatori mancanti.', array('missing_fields'=>$blocking_missing)); }
        if (!empty($missing)) { $out['_missing_fields'] = $missing; }
        return $out;
    }

    private static function build_draft_generation_prompt() {
        return 'Rispondi esclusivamente con un singolo oggetto JSON valido. Non usare Markdown. Non usare blocchi ```json. Non aggiungere spiegazioni o testo prima/dopo il JSON. Includi sempre: title, slug, excerpt, content, seo_title, seo_description, affiliate_shortcodes_used, affiliate_urls_used, media_used, warnings. content deve essere stringa JSON valida. Se usi shortcode, inseriscili sia in content sia in affiliate_shortcodes_used. Se usi URL affiliati diretti, inseriscili in affiliate_urls_used. Se non usi shortcode/URL/media usa array vuoti. Non inventare campi.';
    }

    private static function build_draft_response_format() {
        return array('type'=>'json_schema','name'=>'content_draft_generation','schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('title','slug','excerpt','content','seo_title','seo_description','affiliate_shortcodes_used','affiliate_urls_used','media_used','warnings'),'properties'=>array('title'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'excerpt'=>array('type'=>'string'),'content'=>array('type'=>'string'),'seo_title'=>array('type'=>'string'),'seo_description'=>array('type'=>'string'),'affiliate_shortcodes_used'=>array('type'=>'array','items'=>array('type'=>'string')),'affiliate_urls_used'=>array('type'=>'array','items'=>array('type'=>'string')),'media_used'=>array('type'=>'array','items'=>array('type'=>'string')),'warnings'=>array('type'=>'array','items'=>array('type'=>'string')))));
    }

    private static function map_openai_error_to_admin_message($res, $fallback='Risposta OpenAI fallita.') {
        $code = sanitize_key((string)($res['error_code'] ?? ''));
        if ($code === 'empty_response') return 'OpenAI ha restituito una risposta vuota.';
        if ($code === 'response_format_unsupported') return 'Il modello non supporta response_format: fallback attivo.';
        if ($code === 'timeout') return 'Timeout OpenAI: aumenta timeout o riduci il payload.';
        if ($code === 'rate_limit') return 'Rate limit OpenAI raggiunto: riprova tra poco.';
        if ($code === 'auth_error') return 'Errore autenticazione OpenAI: verifica API key.';
        return sanitize_text_field((string)($res['error'] ?? $fallback));
    }
    private static function resolve_document_knowledge_item_id($row) {
        $candidates = array(
            $row['knowledge_item_id'] ?? '',
            $row['result_key'] ?? '',
            $row['result_id'] ?? '',
            $row['key'] ?? '',
        );
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $kid = absint($candidate);
                if ($kid > 0) { return $kid; }
            }
            $value = sanitize_text_field((string)$candidate);
            if ($value === '') { continue; }
            if (preg_match('/^(?:kb:)?document_txt:(\d+)$/', $value, $m)) { return absint($m[1]); }
            if (preg_match('/^(?:document_txt:)?kb_document_txt_(\d+)$/', $value, $m)) { return absint($m[1]); }
            if (preg_match('/^kb:document_txt:kb_document_txt_(\d+)$/', $value, $m)) { return absint($m[1]); }
        }
        return 0;
    }

    private static function get_affiliate_url($post_id) {
        $post_id = absint($post_id);
        if ($post_id < 1) { return ''; }

        $raw = get_post_meta($post_id, '_affiliate_url', true);
        if (!is_string($raw) || $raw == '') {
            $raw = get_post_meta($post_id, '_alma_affiliate_url', true);
        }

        $url = esc_url_raw((string)$raw);
        if (!is_string($url) || $url === '' || !wp_http_validate_url($url)) {
            return '';
        }
        return $url;
    }

    private static function build_instruction_profile_payload($session, &$warnings) {
        $session = is_array($session) ? $session : array();
        $warnings = is_array($warnings) ? $warnings : array();
        $profile_id = absint($session['instruction_profile_id'] ?? 0);
        $profile = array();

        if ($profile_id > 0) {
            $profile = ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id);
            if (empty($profile)) {
                $warnings[] = 'Profilo istruzioni non trovato: #' . $profile_id;
            }
        } else {
            $warnings[] = 'Nessun profilo istruzioni associato alla sessione.';
        }

        if (!is_array($profile)) { $profile = array(); }

        $profile_name = sanitize_text_field($session['instruction_profile_name'] ?? ($profile['profile_name'] ?? ''));
        if ($profile_id > 0 && $profile_name === '') {
            $profile_name = 'Profilo #' . $profile_id;
        }

        $rules = array(
            'seo_rules' => sanitize_textarea_field((string)($profile['seo_rules'] ?? '')),
            'affiliate_rules' => sanitize_textarea_field((string)($profile['affiliate_rules'] ?? '')),
            'image_rules' => sanitize_textarea_field((string)($profile['image_rules'] ?? '')),
            'source_rules' => sanitize_textarea_field((string)($profile['source_rules'] ?? '')),
            'anti_duplication_rules' => sanitize_textarea_field((string)($profile['anti_duplication_rules'] ?? '')),
            'avoid_rules' => sanitize_textarea_field((string)($profile['avoid_rules'] ?? '')),
            'disclosure_policy' => sanitize_textarea_field((string)($profile['disclosure_policy'] ?? '')),
            'custom_prompt' => sanitize_textarea_field((string)($profile['custom_prompt'] ?? '')),
        );

        $snapshot = sanitize_textarea_field((string)($session['instruction_snapshot'] ?? ''));
        if ($snapshot === '' && !empty($profile)) {
            $snapshot = ALMA_AI_Content_Agent_Instructions_Manager::build_compact_instruction_block($profile, (string)($session['last_query']['temporary_instructions'] ?? ''));
        }
        $snapshot_hash = sanitize_text_field((string)($session['instruction_snapshot_hash'] ?? ''));
        if ($snapshot_hash === '' && $snapshot !== '') {
            $snapshot_hash = ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash($snapshot);
        }

        if ($profile_id > 0 && empty($profile)) {
            $warnings[] = 'Instruction snapshot non disponibile per profilo mancante.';
        }

        return array(
            'instruction_profile_id' => $profile_id,
            'instruction_profile_name' => $profile_name,
            'instruction_profile' => $profile,
            'instruction_profile_rules' => $rules,
            'instruction_snapshot_hash' => $snapshot_hash,
            'instruction_snapshot' => $snapshot,
        );
    }
    private static function compact_text($text, $words = 80) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$text)));
        if ($text === '') { return ''; }
        return wp_trim_words($text, max(1, absint($words)), '…');
    }

    private static function compact_rule_list($rules) {
        $out = array();
        $seen = array();
        foreach ((array)$rules as $rule) {
            foreach (self::normalize_rule_items($rule) as $rule_item) {
                $key = md5(mb_strtolower($rule_item));
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $out[] = $rule_item;
            }
        }
        return $out;
    }

    private static function normalize_rule_items($rule) {
        $rule = wp_strip_all_tags((string)$rule);
        $rule = preg_replace('/^[ \t]*(?:[•*]|-|–|—)[ \t]+/m', '', $rule);
        $rule = trim($rule);
        if ($rule === '') { return array(); }

        $parts = preg_split('/\r\n|\r|\n/', $rule, -1, PREG_SPLIT_NO_EMPTY);
        $items = array();
        foreach ((array)$parts as $part) {
            $part = trim(preg_replace('/\s+/', ' ', $part));
            if ($part === '') { continue; }
            $sentences = preg_split('/(?<=[.!?。！？])\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY);
            foreach ((array)$sentences as $sentence) {
                $sentence = self::normalize_complete_rule_sentence($sentence);
                if ($sentence !== '') { $items[] = $sentence; }
            }
        }
        return $items;
    }

    private static function normalize_complete_rule_sentence($sentence) {
        $sentence = trim(preg_replace('/\s+/', ' ', (string)$sentence));
        $sentence = preg_replace('/(?:\.\.\.|…)+$/u', '', $sentence);
        $sentence = trim($sentence, " \t\n\r\0\x0B-–—:;,");
        if ($sentence === '') { return ''; }
        if (!preg_match('/[.!?。！？]$/u', $sentence)) { $sentence .= '.'; }
        return $sentence;
    }

    private static function is_viator_context($provider, $source, $context) {
        $haystack = mb_strtolower((string)$provider . ' ' . (string)$source . ' ' . (string)$context);
        return strpos($haystack, 'viator') !== false || strpos($haystack, 'productcode') !== false || strpos($haystack, 'codice prodotto') !== false || strpos($haystack, 'destination id') !== false;
    }

    private static function summarize_affiliate_context_for_ai($context, $provider = '', $source = '', $title = '', $description = '') {
        $context = sanitize_textarea_field((string)$context);
        if ($context === '') { return self::compact_text($description, 50); }
        if (!self::is_viator_context($provider, $source, $context)) { return self::compact_text($context, 90); }

        $allowed = array();
        $patterns = array('/\btipo\b/i','/\bdescrizione\b/i','/\bprezzo\b/i','/\bvaluta\b/i','/\bdurata\b/i','/cancellazione/i','/privat/i','/inclus/i','/esclus/i','/caratteristiche/i','/tour/i','/esperienza/i');
        foreach (preg_split('/\r\n|\r|\n|;/', $context) as $line) {
            $line = trim(wp_strip_all_tags((string)$line));
            if ($line === '') { continue; }
            if (preg_match('/fonte\s*:\s*viator|codice prodotto|product\s*code|url affiliato disponibile|rating|recension|destination id|tag id|source key|prompt source|note operative|provider|fornitore|source/i', $line)) { continue; }
            if (preg_match('/^durata\s*:\s*(array|n\/?d|non disponibile)?\.?$/i', $line)) { continue; }
            if (preg_match('/^durata\s*:/i', $line) && preg_match('/array|[{}\[\]]/i', $line)) { continue; }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $allowed[] = self::compact_text($line, 28);
                    break;
                }
            }
            if (count($allowed) >= 8) { break; }
        }
        if (empty($allowed)) {
            $base = $description !== '' ? $description : $context;
            $allowed[] = 'Esperienza/tour: ' . self::compact_text($base, 45);
        }
        $allowed[] = 'Nota: non copiare testo provider e non inventare prezzo, disponibilità o condizioni.';
        return implode(' ', self::compact_rule_list($allowed));
    }

    private static function normalize_payload_for_openai($payload) {
        $payload = is_array($payload) ? $payload : array();
        $profile = is_array($payload['instruction_profile'] ?? null) ? $payload['instruction_profile'] : array();
        $profile_rules = is_array($payload['instruction_profile_rules'] ?? null) ? $payload['instruction_profile_rules'] : array();
        $user_prompt = sanitize_textarea_field((string)($payload['openai_prompt'] ?? ($payload['user_inputs']['openai_prompt'] ?? '')));
        $idea_title = sanitize_text_field((string)($payload['idea_context']['idea_title'] ?? ''));
        $search_query = sanitize_text_field((string)($payload['idea_context']['search_query'] ?? ''));
        $language = sanitize_text_field((string)($profile['language_code'] ?? ($payload['site_context']['language'] ?? get_bloginfo('language'))));

        $affiliate_links = array();
        foreach ((array)($payload['affiliate_links'] ?? array()) as $link) {
            if (!is_array($link)) { continue; }
            $description = sanitize_text_field((string)($link['description'] ?? ($link['excerpt'] ?? '')));
            $context = self::summarize_affiliate_context_for_ai(
                (string)($link['ai_context'] ?? ($link['content'] ?? '')),
                (string)($link['provider'] ?? ''),
                (string)($link['source'] ?? ''),
                (string)($link['title'] ?? ''),
                $description
            );
            $item = array(
                'id' => absint($link['id'] ?? 0),
                'title' => sanitize_text_field((string)($link['title'] ?? '')),
                'description' => self::compact_text($description !== '' ? $description : ($link['content'] ?? ''), 45),
                'affiliate_url' => esc_url_raw((string)($link['affiliate_url'] ?? '')),
                'shortcode' => sanitize_text_field((string)($link['shortcode'] ?? '')),
                'link_types' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($link['link_types'] ?? array()))))),
            );
            if ($context !== '') { $item['context'] = $context; }
            $affiliate_links[] = $item;
        }

        $core_rules = array(
            'Rispondi solo con JSON valido conforme al contratto.',
            'Non usare Markdown o testo fuori dall’oggetto JSON.',
            'Non inventare fatti, prezzi, disponibilità, condizioni o link affiliati.',
            'Usa solo i link affiliati presenti in affiliate_links.',
        );
        $affiliate_rules = self::compact_rule_list(array_merge((array)($payload['affiliate_rules'] ?? array()), array($profile_rules['affiliate_rules'] ?? '')));
        $seo_rules = self::compact_rule_list(array_merge((array)($payload['seo_rules'] ?? array()), array($profile_rules['seo_rules'] ?? '')));
        $source_rules = self::compact_rule_list(array($profile_rules['source_rules'] ?? '', $profile_rules['anti_duplication_rules'] ?? '', $profile_rules['avoid_rules'] ?? '', $profile_rules['disclosure_policy'] ?? ''));

        return array(
            'task' => 'create_article_draft_from_selected_sources',
            'site' => array('site_name'=>sanitize_text_field((string)($payload['site_context']['site_name'] ?? get_bloginfo('name'))), 'language'=>$language),
            'article_request' => array('prompt'=>$user_prompt, 'idea_title'=>$idea_title, 'keyword_or_topic'=>$search_query),
            'editorial_instructions' => array(
                'custom_prompt' => sanitize_textarea_field((string)($profile['custom_prompt'] ?? ($profile_rules['custom_prompt'] ?? ''))),
                'tone_of_voice' => sanitize_textarea_field((string)($profile['tone_of_voice'] ?? '')),
                'target_audience' => sanitize_textarea_field((string)($profile['target_audience'] ?? '')),
                'editorial_style' => sanitize_textarea_field((string)($profile['editorial_style'] ?? '')),
                'operational_rules' => self::compact_rule_list($core_rules),
            ),
            'output_requirements' => array('required_fields'=>array('title','slug','excerpt','content','seo_title','seo_description','affiliate_shortcodes_used','affiliate_urls_used','media_used','warnings'), 'slug_required'=>true, 'content_format'=>'HTML string in JSON'),
            'affiliate_links' => $affiliate_links,
            'affiliate_rules' => $affiliate_rules,
            'seo_rules' => $seo_rules,
            'source_policies' => $source_rules,
            'warnings' => self::compact_rule_list((array)($payload['warnings'] ?? array())),
        );
    }

    private static function fetch_document_chunks($knowledge_item_id, $limit = 3) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $limit = max(1, absint($limit));
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_text'));
        if (!$col) { return array(); }
        return (array)$wpdb->get_col($wpdb->prepare("SELECT normalized_text FROM $table WHERE knowledge_item_id=%d ORDER BY id ASC LIMIT %d", absint($knowledge_item_id), $limit));
    }
    private static function build_payload_from_selection_session($user_id = 0) {
        global $wpdb;
        $user_id = absint($user_id ?: get_current_user_id());
        $session = ALMA_AI_Content_Agent_Selection_Session::build_context_package();
        $warnings = array();
        $selection_context = array();
        $affiliate_links = array();
        $source_agent_prompts_map = array();
        $has_any_source_prompt = false;
        $missing_source_prompt_count = 0;
        $selected = array_values((array)($session['selected_results'] ?? array()));

        foreach ($selected as $row) {
            if (!is_array($row)) { continue; }
            $source_group = sanitize_key($row['source_group'] ?? 'other');
            $source_id = absint($row['source_id'] ?? 0);
            $entry = array(
                'source_type' => sanitize_text_field($row['source_type'] ?? $source_group),
                'source_group' => $source_group,
                'source_id' => $source_id,
                'title' => sanitize_text_field($row['title'] ?? ''),
                'excerpt' => sanitize_text_field($row['excerpt'] ?? ''),
                'score' => (int)($row['score'] ?? 0),
                'reason' => sanitize_text_field($row['reason'] ?? ''),
                'provider' => sanitize_text_field($row['provider'] ?? ''),
                'source' => sanitize_text_field($row['source'] ?? ''),
                'provenance' => sanitize_text_field($row['provenance'] ?? ''),
                'link_types' => array_values(array_map('sanitize_text_field', (array)($row['link_types'] ?? array()))),
            );
            $selection_context[] = $entry;

            if ($source_group !== 'affiliate_link' && sanitize_key($row['source_type'] ?? '') !== 'affiliate_link') { continue; }
            $p = $source_id > 0 ? get_post($source_id) : null;
            if (!$p || $p->post_type !== 'affiliate_link') {
                $warnings[] = 'Affiliate link selezionato non disponibile: #' . $source_id;
                continue;
            }
            $affiliate_url = self::get_affiliate_url($p->ID);
            $shortcode = '[affiliate_link id="' . $p->ID . '"]';
            $ai_context = sanitize_textarea_field((string)get_post_meta($p->ID, '_alma_ai_context', true));
            $content = sanitize_textarea_field((string)$p->post_content);
            $excerpt = sanitize_text_field((string)$p->post_excerpt);
            if ($ai_context === '') { $ai_context = $excerpt !== '' ? $excerpt : $content; }
            if ($affiliate_url === '') { $warnings[] = 'Affiliate link #' . $p->ID . ' senza URL affiliato.'; }
            if ($shortcode === '') { $warnings[] = 'Affiliate link #' . $p->ID . ' senza shortcode.'; }

            $source_meta_id = absint(get_post_meta($p->ID, '_alma_source_id', true));
            $source_name = '';
            $source_provider = sanitize_text_field($row['provider'] ?? '');
            $source_prompt = '';
            if ($source_meta_id > 0) {
                $src = $wpdb->get_row($wpdb->prepare("SELECT name,provider,settings FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d", $source_meta_id), ARRAY_A);
                if (is_array($src)) {
                    $source_name = sanitize_text_field($src['name'] ?? '');
                    if ($source_provider === '') { $source_provider = sanitize_key($src['provider'] ?? ''); }
                    $src_settings = json_decode((string)($src['settings'] ?? '{}'), true);
                    if (is_array($src_settings)) { $source_prompt = sanitize_textarea_field((string)($src_settings['ai_source_instructions'] ?? '')); }
                }
            }
            if ($source_prompt !== '') {
                $has_any_source_prompt = true;
                $prompt_key_parts = array(
                    sanitize_key($source_provider !== '' ? $source_provider : 'unknown'),
                    sanitize_title($source_name !== '' ? $source_name : (string)($row['source'] ?? '')),
                    (string)$source_meta_id,
                    md5($source_prompt),
                );
                $source_prompt_key = implode(':', $prompt_key_parts);
                if (!isset($source_agent_prompts_map[$source_prompt_key])) {
                    $source_agent_prompts_map[$source_prompt_key] = array(
                        'source_agent_prompt_key' => $source_prompt_key,
                        'provider' => sanitize_text_field($source_provider),
                        'source' => sanitize_text_field($source_name !== '' ? $source_name : ($row['source'] ?? '')),
                        'source_id' => $source_meta_id,
                        'label' => sanitize_text_field($source_name !== '' ? $source_name : ($row['source'] ?? '')),
                        'prompt' => $source_prompt,
                        'link_ids' => array(),
                    );
                }
                $source_agent_prompts_map[$source_prompt_key]['link_ids'][] = (int)$p->ID;
            } else {
                $missing_source_prompt_count++;
                $source_prompt_key = '';
            }

            $affiliate_links[] = array(
                'id' => (int)$p->ID,
                'title' => sanitize_text_field($p->post_title),
                'content' => $content,
                'excerpt' => $excerpt,
                'affiliate_url' => $affiliate_url,
                'ai_context' => $ai_context,
                'shortcode' => $shortcode,
                'link_types' => array_values(array_map('sanitize_text_field', (array)($row['link_types'] ?? array()))),
                'provider' => $source_provider,
                'source' => $source_name !== '' ? $source_name : sanitize_text_field($row['source'] ?? ''),
                'provenance' => sanitize_text_field($row['provenance'] ?? ''),
                'score' => (int)($row['score'] ?? 0),
                'reason' => sanitize_text_field($row['reason'] ?? ''),
                'affiliate_link_post_id' => (int)$p->ID,
                'source_agent_prompt_key' => $source_prompt_key,
                'source_agent_prompt_available' => $source_prompt !== '',
                'source_agent_prompt' => $source_prompt,
            );
        }

        if (empty($selection_context)) { $warnings[] = 'Nessun selected result nella sessione contenuto.'; }
        if (empty($affiliate_links)) { $warnings[] = 'Nessun affiliate link selezionato.'; }
        if (empty($session['openai_prompt']) && empty($session['last_query']['temporary_instructions'])) { $warnings[] = 'Prompt OpenAI assente nella idea/sessione.'; }

        $profile_payload = self::build_instruction_profile_payload($session, $warnings);
        $affiliate_rules = array(
            'Usare solo i link affiliati selezionati nel payload.',
            'Non inventare link affiliati.',
            'Preferire shortcode WordPress per box/link affiliati.',
            'Usare affiliate_url solo per link testuali diretti quando necessario.',
            'Compilare affiliate_shortcodes_used con gli shortcode realmente usati.',
            'Se usi URL diretti, compilare affiliate_urls_used con gli URL realmente usati.',
            'Non usare link non presenti nel payload.',
        );
        if (!empty($profile_payload['instruction_profile_rules']['affiliate_rules'])) { $affiliate_rules[] = $profile_payload['instruction_profile_rules']['affiliate_rules']; }
        $seo_rules = array(
            'Produrre seo_title coerente con titolo idea e prompt.',
            'Produrre seo_description chiara e pertinente.',
            'Evitare keyword stuffing.',
            'Usare struttura H2/H3 chiara.',
            'Scrivere in italiano.',
        );
        if (!empty($profile_payload['instruction_profile_rules']['seo_rules'])) { $seo_rules[] = $profile_payload['instruction_profile_rules']['seo_rules']; }

        $source_agent_prompts = array_values(array_map(function ($entry) {
            $entry['link_ids'] = array_values(array_unique(array_map('absint', (array)($entry['link_ids'] ?? array()))));
            return $entry;
        }, $source_agent_prompts_map));

        $agent_behavior = '';
        if ($agent_behavior === '' && $has_any_source_prompt) {
            $warnings[] = 'Comportamento agente globale non configurato; presenti istruzioni Source per alcuni link.';
        } elseif ($agent_behavior === '' && empty($source_agent_prompts)) {
            $warnings[] = 'Comportamento agente globale e istruzioni Source non configurati.';
        }
        if ($missing_source_prompt_count > 0 && $has_any_source_prompt) {
            $warnings[] = 'Alcuni link manuali o legacy non hanno istruzioni Source dedicate.';
        }

        return array_merge(array(
            'task'=>'create_article_draft_from_selected_sources',
            'site_context'=>array('site_name'=>get_bloginfo('name'),'language'=>get_bloginfo('language'),'generated_at'=>current_time('mysql')),
            'user_inputs'=>array('content_search_query'=>sanitize_text_field($session['last_query']['content_search_query'] ?? ($session['last_query']['search_terms'] ?? '')),'theme'=>sanitize_text_field($session['last_query']['theme'] ?? ''),'destination'=>sanitize_text_field($session['last_query']['destination'] ?? ''),'openai_prompt'=>sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['temporary_instructions'] ?? ''))),
            'idea_context'=>array('idea_title'=>sanitize_text_field($session['last_query']['content_search_query'] ?? ''),'idea_prompt'=>sanitize_textarea_field($session['openai_prompt'] ?? ''),'search_query'=>sanitize_text_field($session['last_query']['search_terms'] ?? ($session['last_query']['content_search_query'] ?? '')),'selected_results_count'=>count($selection_context)),
            'openai_prompt'=>sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['temporary_instructions'] ?? '')),
            'temporary_instructions'=>sanitize_textarea_field($session['last_query']['temporary_instructions'] ?? ''),
            'rules'=>array(
                'output_json'=>true,
                'title_required'=>true,
                'content_required'=>true,
                'slug_required'=>true,
                'prefer_affiliate_shortcodes'=>true,
                'allow_affiliate_urls_for_text_links'=>true,
                'do_not_invent_affiliate_urls'=>true,
                'use_only_payload_affiliate_links'=>true,
            ),
            'selection_context'=>$selection_context,
            'affiliate_links'=>$affiliate_links,
            'source_agent_prompts'=>$source_agent_prompts,
            'posts'=>array(),'documents'=>array(),'sources_online'=>array(),'pages'=>array(),'media'=>array(),
            'affiliate_rules'=>$affiliate_rules,
            'seo_rules'=>$seo_rules,
            'media_rules'=>array(),
            'output_contract'=>array('title','slug','excerpt','content','seo_title','seo_description','affiliate_shortcodes_used','affiliate_urls_used','media_used','warnings'),
            'warnings'=>array_values(array_unique($warnings)),
            'agent_behavior'=>$agent_behavior,
        ), $profile_payload);
    }

    private static function build_payload_download_document($full_payload, $mode = 'openai') {
        $full_payload = is_array($full_payload) ? $full_payload : array();
        $normalized_payload = self::normalize_payload_for_openai($full_payload);
        $mode = sanitize_key($mode);

        if ($mode === 'debug') {
            return array(
                'payload_type' => 'debug_payload_full_with_openai_payload_normalized',
                'description' => 'debug_payload_full contiene il contesto diagnostico completo; openai_payload_normalized è il payload compatto realmente inviato a OpenAI.',
                'debug_payload_full' => $full_payload,
                'openai_payload_normalized' => $normalized_payload,
            );
        }

        return $normalized_payload;
    }

    public static function download_payload_json_from_selection_session($user_id = 0, $idea_id = 0, $mode = 'openai') {
        if (!current_user_can('manage_options')) { return new WP_Error('alma_forbidden', 'Operazione non autorizzata.'); }
        $mode = sanitize_key($mode);
        if (!in_array($mode, array('openai', 'debug'), true)) { $mode = 'openai'; }
        try {
            $payload = self::build_payload_from_selection_session($user_id);
            $download_payload = self::build_payload_download_document($payload, $mode);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('ALMA payload download error: ' . $e->getMessage());
            }
            return new WP_Error('alma_payload_exception', 'Errore durante la costruzione del payload JSON.');
        }
        if (!is_array($download_payload) || empty($download_payload)) {
            return new WP_Error('alma_payload_unavailable', 'Impossibile costruire il payload JSON.');
        }

        $json = wp_json_encode($download_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return new WP_Error('alma_payload_encoding_failed', 'Impossibile codificare il payload JSON.');
        }

        while (ob_get_level() > 0) {
            $status = ob_get_status();
            if (empty($status['del']) || !ob_end_clean()) {
                break;
            }
        }
        nocache_headers();
        $safe_idea_id = max(0, absint($idea_id));
        $prefix = $mode === 'debug' ? 'alma-ai-debug-payload-idea-' : 'alma-ai-openai-payload-idea-';
        $filename = $prefix . $safe_idea_id . '-' . gmdate('Y-m-d-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    public static function generate_for_idea($idea_id) {
        $idea_id = absint($idea_id); $idea = ALMA_AI_Content_Agent_Store::get_idea($idea_id);
        if (!$idea) return self::fail('Idea non trovata.', '', 'idea:'.$idea_id);
        if (in_array($idea['status'], array('rejected','archived'), true)) return self::fail('Idea non generabile.', '', 'idea:'.$idea_id);
        if (empty(get_option('alma_openai_api_key', ''))) return self::fail('OpenAI non configurato.', '', 'idea:'.$idea_id);
        $existing = ALMA_AI_Content_Agent_Store::get_draft_post_by_idea($idea_id); if ($existing) return self::fail('Bozza già esistente.', '', 'idea:'.$idea_id, array('post_id'=>(int)$existing->ID,'edit_url'=>get_edit_post_link((int)$existing->ID, 'raw')));
        $brief = ALMA_AI_Content_Agent_Store::get_brief_by_idea($idea_id); if (!$brief) return self::fail('Brief non trovato.', '', 'idea:'.$idea_id);
        $context = array('idea'=>$idea,'brief'=>$brief,'instruction_snapshot'=>$brief['instruction_snapshot'] ?? ($idea['instruction_snapshot'] ?? ''),'candidate_affiliate_links'=>json_decode((string)($brief['candidate_affiliate_links'] ?? '[]'), true),'candidate_images'=>json_decode((string)($brief['candidate_images'] ?? '[]'), true),'knowledge_suggestions'=>json_decode((string)($brief['suggested_knowledge_sources'] ?? '[]'), true),'warnings'=>json_decode((string)($brief['warnings'] ?? '[]'), true));
        $prompt = 'Genera JSON con: title,slug,excerpt,content_html,seo_title,meta_description,focus_keyword,suggested_tags,affiliate_links_used,featured_image_id,inline_image_ids,qa_notes,warnings. Usa solo shortcode [affiliate_link id="ID" text="anchor"]. Non inventare ID.';
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress. Output solo JSON valido.', 'user_prompt'=>$prompt.' CONTEXT: '.wp_json_encode($context), 'json_output'=>true, 'max_output_tokens'=>1800));
        if (empty($res['success'])) { return self::fail($res['error'] ?? 'Risposta OpenAI fallita.', $res['model'] ?? '', 'idea:'.$idea_id); }
        $parsed = json_decode($res['response'], true); if (!is_array($parsed)) $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json($res['response']), true);
        if (!is_array($parsed)) { return self::fail('Draft JSON non valido', $res['model'] ?? '', 'idea:'.$idea_id); }
        $candidate_affiliate_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_affiliate_links'], 'link_id'))));
        $candidate_image_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_images'], 'attachment_id'))));
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids);
        if (!is_array($clean) || !array_key_exists('title', $clean) || !array_key_exists('content', $clean)) return self::fail('QA output non valido.', $res['model'] ?? '', 'idea:'.$idea_id);
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') return self::fail('Output draft non valido dopo QA.', $res['model'] ?? '', 'idea:'.$idea_id);
        $post_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_author'=>get_current_user_id(),'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content']), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'idea:'.$idea_id); }
        if (!empty($clean['featured_image_id'])) set_post_thumbnail($post_id, $clean['featured_image_id']);
        update_post_meta($post_id, '_alma_ai_agent_generated', 1); update_post_meta($post_id, '_alma_ai_agent_idea_id', $idea_id); update_post_meta($post_id, '_alma_ai_agent_brief_id', absint($brief['id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_task', 'content_draft_generation'); update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($brief['instruction_profile_id'] ?? $idea['instruction_profile_id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($brief['instruction_snapshot_hash'] ?? $idea['instruction_snapshot_hash'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_affiliate_links_used', wp_json_encode($clean['affiliate_links_used'])); update_post_meta($post_id, '_alma_ai_agent_image_ids_used', wp_json_encode($clean['inline_image_ids'])); update_post_meta($post_id, '_alma_ai_agent_featured_image_id', absint($clean['featured_image_id'])); update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_merge((array)$clean['warnings'], (array)($parsed['warnings'] ?? array())))); update_post_meta($post_id, '_alma_ai_seo_title', sanitize_text_field($parsed['seo_title'] ?? '')); update_post_meta($post_id, '_alma_ai_meta_description', sanitize_text_field($parsed['meta_description'] ?? '')); update_post_meta($post_id, '_alma_ai_focus_keyword', sanitize_text_field($parsed['focus_keyword'] ?? '')); update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql')); update_post_meta($post_id, '_alma_ai_suggested_tags', wp_json_encode((array)($parsed['suggested_tags'] ?? array())));
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'estimated_cost'=>$res['usage']['total_tokens'] ?? null,'reference_id'=>'post:'.$post_id));
        return array('success'=>true,'post_id'=>$post_id,'edit_url'=>get_edit_post_link($post_id, 'raw'),'warnings'=>array_values(array_merge((array)$clean['warnings'], (array)($parsed['warnings'] ?? array()))));
    }

    public static function generate_from_selection_session($user_id = 0) {
        global $wpdb;
        $user_id = absint($user_id ?: get_current_user_id());
        $session = ALMA_AI_Content_Agent_Selection_Session::build_context_package();
        $selected = array_values(array_filter((array)($session['selected_results'] ?? array()), function($r){ return !empty($r['selected']); }));
        if (empty($selected)) { return self::fail('Seleziona almeno una fonte prima di creare la bozza.'); }
        $selected_posts = array_values(array_filter($selected, function($r){ return ($r['source_group'] ?? '') === 'post'; }));
        if (count($selected_posts) > ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_POSTS) { return self::fail('Puoi selezionare massimo 3 Post.'); }
        if (empty(get_option('alma_openai_api_key', ''))) { return self::fail('OpenAI non è configurata.'); }

        $ctx = array('posts'=>array(),'pages'=>array(),'affiliate_links'=>array(),'documents'=>array(),'sources_online'=>array(),'media'=>array());
        $warnings = array();
        foreach ($selected as $row) {
            $group = sanitize_key($row['source_group'] ?? '');
            $sid = absint($row['source_id'] ?? 0);
            if ($group === 'post' || $group === 'page') {
                $p = get_post($sid);
                if (!$p || $p->post_type !== $group) { $warnings[] = 'Elemento selezionato non più disponibile: '.$group.'#'.$sid; continue; }
                $ctx[$group === 'post' ? 'posts' : 'pages'][] = array('id'=>$p->ID,'title'=>sanitize_text_field($p->post_title),'excerpt'=>wp_trim_words(wp_strip_all_tags($p->post_excerpt ?: $p->post_content), 40),'content'=>mb_substr(wp_strip_all_tags($p->post_content),0,1200),'permalink'=>get_permalink($p->ID));
            } elseif ($group === 'affiliate_link') {
                $p = get_post($sid);
                if (!$p || $p->post_type !== 'affiliate_link') { $warnings[] = 'Affiliate link non disponibile: #'.$sid; continue; }
                $source_id = absint(get_post_meta($p->ID, '_alma_source_id', true));
                $source = $source_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT id,name,provider,settings FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d", $source_id), ARRAY_A) : array();
                $source_settings = is_array($source) ? json_decode((string)($source['settings'] ?? '{}'), true) : array();
                $source_prompt = sanitize_textarea_field((string)($source_settings['ai_source_instructions'] ?? ''));
                if ($source_id > 0 && $source_prompt === '') { $warnings[] = 'Comportamento AI source non trovato per affiliate link #'.$p->ID; }
                $ctx['affiliate_links'][] = array('id'=>$p->ID,'title'=>sanitize_text_field($p->post_title),'description'=>sanitize_text_field($p->post_excerpt),'affiliate_url'=>esc_url_raw((string)get_post_meta($p->ID,'_affiliate_url',true)),'shortcode'=>'[affiliate_link id="'.$p->ID.'"]','ai_context'=>sanitize_textarea_field((string)get_post_meta($p->ID,'_alma_ai_context',true)),'source_id'=>$source_id,'source_name'=>sanitize_text_field($source['name'] ?? ''),'provider'=>sanitize_key($source['provider'] ?? ''),'source_ai_behavior_prompt'=>$source_prompt,'usage_rules'=>'Usa solo shortcode autorizzato; non inventare link affiliati.');
            } elseif ($group === 'document_txt') {
                $kid = self::resolve_document_knowledge_item_id($row);
                if ($kid <= 0) { $warnings[] = 'Documento TXT senza knowledge item id stabile.'; continue; }
                $item = $wpdb->get_row($wpdb->prepare("SELECT id,title,status FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE id=%d AND source_type='document_txt'", $kid), ARRAY_A);
                if (!$item || ($item['status'] ?? '') !== 'active') { $warnings[] = 'Documento TXT non disponibile: #'.$kid; continue; }
                $chunks = self::fetch_document_chunks($kid, 3);
                if (empty($chunks)) { $warnings[] = 'Documento TXT senza chunk validi: #'.$kid; }
                $ctx['documents'][] = array('id'=>(int)$item['id'],'title'=>sanitize_text_field($item['title']),'status'=>sanitize_text_field($item['status']),'normalized_text'=>implode("\n", array_map(function($c){ return mb_substr(wp_strip_all_tags((string)$c),0,500); }, (array)$chunks)));
            } elseif ($group === 'source_online') {
                $src = $wpdb->get_row($wpdb->prepare("SELECT id,name,source_url,source_type,is_active FROM ".ALMA_AI_Content_Agent_Store::table('sources')." WHERE id=%d", $sid), ARRAY_A);
                if (!$src || (int)$src['is_active'] !== 1) { $warnings[] = 'Fonte online non disponibile: #'.$sid; continue; }
                $ctx['sources_online'][] = array('id'=>(int)$src['id'],'name'=>sanitize_text_field($src['name']),'url'=>esc_url_raw($src['source_url']),'technology'=>sanitize_text_field($src['source_type']),'status'=>'active');
            } elseif ($group === 'media') {
                $att = get_post($sid);
                if (!$att || $att->post_type !== 'attachment') { $warnings[] = 'Media non disponibile: #'.$sid; continue; }
                $ctx['media'][] = array('attachment_id'=>$att->ID,'title'=>sanitize_text_field($att->post_title),'alt_text'=>sanitize_text_field(get_post_meta($att->ID,'_wp_attachment_image_alt',true)),'caption'=>sanitize_text_field($att->post_excerpt),'description'=>sanitize_textarea_field($att->post_content),'url'=>wp_get_attachment_url($att->ID));
            }
        }
        if (empty($ctx['posts']) && empty($ctx['pages']) && empty($ctx['documents']) && empty($ctx['affiliate_links']) && empty($ctx['sources_online']) && empty($ctx['media'])) {
            return self::fail('Nessuna fonte valida disponibile nella sessione selezionata.');
        }
        $profile_id = absint($session['instruction_profile_id'] ?? 0);
        $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile();
        $payload = self::build_payload_from_selection_session($user_id);
        $payload['instruction_profile'] = $profile;
        $payload['selection_context'] = $ctx;
        $payload['affiliate_links'] = $ctx['affiliate_links'];
        $payload['posts'] = $ctx['posts'];
        $payload['documents'] = $ctx['documents'];
        $payload['sources_online'] = $ctx['sources_online'];
        $payload['pages'] = $ctx['pages'];
        $payload['media'] = $ctx['media'];
        $ai_payload = self::normalize_payload_for_openai($payload);
        $prompt = self::build_draft_generation_prompt();
        $configured_max_tokens = absint(get_option('alma_openai_max_output_tokens', 1800));
        $max_output_tokens = $configured_max_tokens > 0 ? $configured_max_tokens : 1800;
        $response_format = self::build_draft_response_format();
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress per output strutturato.', 'user_prompt'=>$prompt.' CONTEXT: '.wp_json_encode($ai_payload), 'response_format'=>$response_format, 'json_output'=>true, 'max_output_tokens'=>$max_output_tokens, 'timeout'=>absint(get_option('alma_openai_timeout', 120))));
        if (empty($res['success']) && (($res['error_code'] ?? '') === 'response_format_unsupported' || strpos(strtolower((string)($res['error'] ?? '')), 'response_format') !== false)) {
            $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress per output strutturato.', 'user_prompt'=>$prompt.' CONTEXT: '.wp_json_encode($ai_payload), 'json_output'=>true, 'max_output_tokens'=>$max_output_tokens, 'timeout'=>absint(get_option('alma_openai_timeout', 120))));
            $res['response_format_used'] = 'fallback_json_object';
        }
        if (empty($res['success'])) {
            return self::fail(self::map_openai_error_to_admin_message($res), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'api','error_code'=>$res['error_code'] ?? 'openai_error'));
        }
        $parsed = self::parse_ai_json_response((string)($res['response'] ?? ''));
        if (is_wp_error($parsed)) {
            $diag = array('task'=>self::TASK_SELECTION,'model'=>$res['model'] ?? '','error_category'=>'json','error_code'=>$parsed->get_error_code(),'json_error'=>sanitize_text_field((string)$parsed->get_error_data('json_error')),'response_length'=>strlen((string)($res['response'] ?? '')),'response_preview'=>self::sanitize_response_preview((string)($res['response'] ?? ''), 1000),'response_format_used'=>sanitize_text_field((string)($res['response_format_used'] ?? 'none')),'max_output_tokens'=>absint($res['max_output_tokens'] ?? $max_output_tokens));
            ALMA_AI_Usage_Logger::log(array('task'=>self::TASK_SELECTION,'success'=>false,'error'=>'JSON error ['.$diag['error_code'].']: '.$parsed->get_error_message(),'model'=>$res['model'] ?? '','reference_id'=>'session:user:'.$user_id));
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('ALMA Draft JSON diagnostic: '.wp_json_encode($diag)); }
            return self::fail($parsed->get_error_message(), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'json','error_code'=>$parsed->get_error_code()));
        }
        $validated = self::validate_output_contract($parsed);
        if (is_wp_error($validated)) {
            $missing_fields = (array)$validated->get_error_data('missing_fields');
            $diag = array('task'=>self::TASK_SELECTION,'model'=>$res['model'] ?? '','error_category'=>'json_contract','error_code'=>$validated->get_error_code(),'missing_fields'=>$missing_fields,'response_length'=>strlen((string)($res['response'] ?? '')),'response_preview'=>self::sanitize_response_preview((string)($res['response'] ?? ''), 500),'response_format_used'=>sanitize_text_field((string)($res['response_format_used'] ?? 'none')));
            if (defined('WP_DEBUG') && WP_DEBUG) { error_log('ALMA Draft contract diagnostic: '.wp_json_encode($diag)); }
            return self::fail($validated->get_error_message(), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'json_contract','error_code'=>$validated->get_error_code(),'missing_fields'=>$missing_fields));
        }
        $parsed = $validated;
        $parsed['content_html'] = (string)($parsed['content'] ?? '');
        $candidate_affiliate_ids = array_values(array_map('absint', wp_list_pluck((array)$ctx['affiliate_links'], 'id')));
        $candidate_image_ids = array_values(array_map('absint', wp_list_pluck((array)$ctx['media'], 'attachment_id')));
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids);
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') { return self::fail('Titolo o contenuto non validi dopo QA.', $res['model'] ?? '', 'session:user:'.$user_id); }
        $post_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_author'=>$user_id,'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content']), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'session:user:'.$user_id); }
        update_post_meta($post_id, '_alma_ai_agent_generated', 1);
        update_post_meta($post_id, '_alma_ai_agent_task', self::TASK_SELECTION);
        update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? ''));
        update_post_meta($post_id, '_alma_ai_agent_selected_post_ids', wp_json_encode(wp_list_pluck($ctx['posts'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_link_ids', wp_json_encode(wp_list_pluck($ctx['affiliate_links'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_source_ids', wp_json_encode(array_values(array_unique(array_filter(array_map('absint', wp_list_pluck($ctx['affiliate_links'], 'source_id')))))));
        update_post_meta($post_id, '_alma_ai_agent_selected_document_txt_ids', wp_json_encode(wp_list_pluck($ctx['documents'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_source_online_ids', wp_json_encode(wp_list_pluck($ctx['sources_online'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_media_ids', wp_json_encode(wp_list_pluck($ctx['media'], 'attachment_id')));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($session['instruction_profile_id'] ?? ($profile['id'] ?? 0)));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_name', sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')));
        update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($session['instruction_snapshot_hash'] ?? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash(wp_json_encode($profile))));
        update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], (array)($parsed['warnings'] ?? array()))))));
        update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql'));
        $active_idea_id = absint(get_user_meta($user_id, '_alma_active_idea_id', true));
        if ($active_idea_id > 0) {
            update_post_meta($post_id, '_alma_ai_agent_idea_id', $active_idea_id);
            update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, current_time('mysql'));
            update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_DRAFT_POST_ID, $post_id);
        }
        ALMA_AI_Content_Agent_Result_Usage::increment_for_results($selected, $post_id);
        ALMA_AI_Usage_Logger::log(array('task'=>self::TASK_SELECTION,'success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'reference_id'=>'post:'.$post_id));
        return array('success'=>true,'post_id'=>$post_id,'title'=>$clean['title'],'edit_url'=>get_edit_post_link($post_id, 'raw'),'preview_url'=>get_preview_post_link($post_id),'warnings'=>array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], (array)($parsed['warnings'] ?? array())))),'model'=>$res['model'] ?? '','usage'=>$res['usage'] ?? array(),'summary'=>array('status'=>'draft','instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')),'source_counts'=>array('post'=>count((array)$ctx['posts']),'page'=>count((array)$ctx['pages']),'affiliate_link'=>count((array)$ctx['affiliate_links']),'document_txt'=>count((array)$ctx['documents']),'source_online'=>count((array)$ctx['sources_online']),'media'=>count((array)$ctx['media']))));
    }
}
