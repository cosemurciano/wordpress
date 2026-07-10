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
        $required = array('title','slug','content','excerpt','seo_title','seo_description','featured_image_id','affiliate_shortcodes_used','affiliate_urls_used','internal_urls_used','media_used','category_ids','tag_ids','new_tags','warnings');
        $missing = array();
        foreach ($required as $key) { if (!array_key_exists($key, $parsed)) { $missing[] = $key; } }

        $out = $parsed;
        foreach (array('excerpt','seo_title','seo_description','slug') as $k) { if (!isset($out[$k]) || !is_string($out[$k])) { $out[$k] = ''; } }
        foreach (array('affiliate_shortcodes_used','affiliate_urls_used','internal_urls_used','media_used','category_ids','tag_ids','new_tags','warnings') as $k) { if (!isset($out[$k]) || !is_array($out[$k])) { $out[$k] = array(); } }
        foreach ((array)($out['_alma_parse_warnings'] ?? array()) as $parse_warning) {
            if ($parse_warning === 'text_outside_json_object') { $out['warnings'][] = 'Risposta OpenAI con testo fuori dall’oggetto JSON: oggetto JSON estratto automaticamente.'; }
            if ($parse_warning === 'json_wrapped_in_markdown') { $out['warnings'][] = 'Risposta OpenAI con wrapper Markdown: JSON ripulito automaticamente.'; }
        }
        $out['featured_image_id'] = absint($out['featured_image_id'] ?? 0);
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
        return 'Rispondi esclusivamente con un singolo oggetto JSON valido. Non usare Markdown. Non usare blocchi ```json. Non aggiungere spiegazioni o testo prima/dopo il JSON. Includi sempre: title, slug, excerpt, content, seo_title, seo_description, featured_image_id, affiliate_shortcodes_used, affiliate_urls_used, internal_urls_used, media_used, category_ids, tag_ids, new_tags, warnings. content deve essere stringa JSON valida. Non generare disclosure affiliate generiche e non inserire frasi tipo “Questo articolo può contenere link affiliati...”. Non creare tag <a> senza href. Non usare shortcode come href. Se usi shortcode, inseriscili sia in content sia in affiliate_shortcodes_used. Se usi URL affiliati diretti, ogni href deve coincidere esattamente con affiliate_links[].affiliate_url e devi inserirlo in affiliate_urls_used; se non usi URL diretti, affiliate_urls_used deve essere vuoto. Usa immagini affiliate solo se pertinenti alla sezione: non inventare URL immagini, usa solo image_url presenti in affiliate_links[].image con can_use_in_content=true, non duplicare la stessa immagine e non forzare immagini dove non servono. Ogni immagine affiliata deve essere cliccabile con href uguale al relativo affiliate_url. Esempio immagine: <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{image.image_alt}" /></a>. Fallback alt: <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{title}" /></a>. Compila internal_urls_used con gli URL interni realmente usati nel contenuto finale. Registra in media_used le immagini editoriali da media_candidates effettivamente usate nel contenuto con attachment_id, url e placement; le immagini affiliate restano valide tramite affiliate_links[].image e, se usate, mantieni image_url, affiliate_link_id o id, affiliate_url, alt e source se disponibile. Se non usi shortcode/URL/media/link interni/tassonomie usa array vuoti. Usa category_ids solo da category_candidates, tag_ids preferibilmente da tag_candidates e massimo 3 new_tags specifici. Non inventare campi.';
    }

    private static function build_draft_response_format() {
        return array('type'=>'json_schema','name'=>'content_draft_generation','schema'=>array('type'=>'object','additionalProperties'=>false,'required'=>array('title','slug','excerpt','content','seo_title','seo_description','featured_image_id','affiliate_shortcodes_used','affiliate_urls_used','internal_urls_used','media_used','category_ids','tag_ids','new_tags','warnings'),'properties'=>array('title'=>array('type'=>'string'),'slug'=>array('type'=>'string'),'excerpt'=>array('type'=>'string'),'content'=>array('type'=>'string'),'seo_title'=>array('type'=>'string'),'seo_description'=>array('type'=>'string'),'featured_image_id'=>array('type'=>'integer'),'affiliate_shortcodes_used'=>array('type'=>'array','items'=>array('type'=>'string')),'affiliate_urls_used'=>array('type'=>'array','items'=>array('type'=>'string')),'internal_urls_used'=>array('type'=>'array','items'=>array('type'=>'string')),'media_used'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('attachment_id'=>array('type'=>'integer'),'url'=>array('type'=>'string'),'placement'=>array('type'=>'string'),'image_url'=>array('type'=>'string'),'affiliate_link_id'=>array('type'=>'integer'),'id'=>array('type'=>'integer'),'affiliate_url'=>array('type'=>'string'),'alt'=>array('type'=>'string'),'source'=>array('type'=>'string')))),'category_ids'=>array('type'=>'array','items'=>array('type'=>'integer')),'tag_ids'=>array('type'=>'array','items'=>array('type'=>'integer')),'new_tags'=>array('type'=>'array','items'=>array('type'=>'string')),'warnings'=>array('type'=>'array','items'=>array('type'=>'string')))));
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
            'seo_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['seo_rules'] ?? '')),
            'affiliate_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['affiliate_rules'] ?? '')),
            'image_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['image_rules'] ?? '')),
            'source_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['source_rules'] ?? '')),
            'anti_duplication_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['anti_duplication_rules'] ?? '')),
            'avoid_rules' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['avoid_rules'] ?? '')),
            'disclosure_policy' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['disclosure_policy'] ?? '')),
            'custom_prompt' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['custom_prompt'] ?? '')),
        );

        $snapshot = ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($session['instruction_snapshot'] ?? ''));
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

    private static function compact_instruction_profile_payload($payload) {
        $payload = is_array($payload) ? $payload : array();
        $profile_id = absint($payload['instruction_profile_id'] ?? 0);
        if ($profile_id < 1) { return null; }
        $profile = is_array($payload['instruction_profile'] ?? null) ? $payload['instruction_profile'] : array();
        $name = sanitize_text_field((string)($payload['instruction_profile_name'] ?? ($profile['profile_name'] ?? '')));
        if ($name === '') { $name = 'Profilo #' . $profile_id; }
        $snapshot_hash = sanitize_text_field((string)($payload['instruction_snapshot_hash'] ?? ''));
        if ($snapshot_hash === '' && !empty($payload['instruction_snapshot'])) {
            $snapshot_hash = ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash((string)$payload['instruction_snapshot']);
        }
        return array('id'=>$profile_id, 'name'=>$name, 'snapshot_hash'=>$snapshot_hash);
    }

    private static function compact_affiliate_image_payload($image, $fallback_title = '') {
        $image = is_array($image) ? $image : array();
        $url = esc_url_raw((string)($image['image_url'] ?? ($image['featured_image_url'] ?? '')));
        if ($url !== '' && !wp_http_validate_url($url)) { $url = ''; }
        if ($url === '') {
            return array('has_image'=>false,'image_url'=>'','image_alt'=>'','image_caption'=>'','image_source'=>'','can_use_in_content'=>false);
        }
        return array(
            'has_image' => true,
            'image_url' => $url,
            'image_alt' => sanitize_text_field((string)($image['image_alt'] ?? ($image['featured_image_alt'] ?? $fallback_title))),
            'image_caption' => sanitize_text_field((string)($image['image_caption'] ?? ($image['featured_image_caption'] ?? ''))),
            'image_source' => sanitize_text_field((string)($image['image_source'] ?? '')),
            'can_use_in_content' => true,
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
        $rule = ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea($rule);
        if ($rule === '') { return array(); }

        $items = array();
        foreach (preg_split('/\n/', $rule) as $line) {
            $line = trim((string)$line);
            if ($line === '') { continue; }
            $items[] = $line;
        }
        return $items;
    }


    private static function is_viator_context($provider, $source, $context) {
        $haystack = mb_strtolower((string)$provider . ' ' . (string)$source . ' ' . (string)$context);
        return strpos($haystack, 'viator') !== false || strpos($haystack, 'productcode') !== false || strpos($haystack, 'codice prodotto') !== false || strpos($haystack, 'destination id') !== false;
    }

    private static function is_affiliate_context_placeholder($value) {
        $value = trim(wp_strip_all_tags((string)$value));
        if ($value === '') { return true; }
        return (bool)preg_match('/^(?:array|object|stdclass|n\/?d|n\.?a\.?|null|none|non disponibile|disponibile nel campo dedicato|-|—|\[\]|\{\})\.?$/iu', $value);
    }

    private static function is_technical_affiliate_context_line($line) {
        $line = trim(wp_strip_all_tags((string)$line));
        if ($line === '') { return true; }
        if (preg_match('/^(?:fonte|source|source key|provider|fornitore|titolo provider|codice prodotto|product\s*code|productcode|url affiliato|affiliate url|destinazione|destination(?: id)?|destination id|tag(?: id)?|categorie\/?tag provider|lingue disponibili|prompt source|note operative)\s*:/iu', $line)) { return true; }
        if (preg_match('/\b(?:source[_ -]?key|product[_ -]?code|productcode|destination[_ -]?id|tag[_ -]?id|provider[_ -]?id)\b/iu', $line)) { return true; }
        if (preg_match('/\b(?:destination|tag)\s*id\b/iu', $line)) { return true; }
        if (preg_match('/^durata\s*:\s*(?:array|object|n\/?d|null|non disponibile|\[\]|\{\})?\.?$/iu', $line)) { return true; }
        if (preg_match('/^durata\s*:/iu', $line) && preg_match('/(?:array|object|[{}\[\]])/iu', $line)) { return true; }
        if (preg_match('/:\s*(?:array|object|stdclass|n\/?d|null|non disponibile|disponibile nel campo dedicato|\[\]|\{\})\.?$/iu', $line)) { return true; }
        return false;
    }

    private static function clean_affiliate_context_line($line) {
        $line = trim(wp_strip_all_tags((string)$line));
        $line = preg_replace('/(?:^|\s+)durata\s*:\s*array\.?/iu', ' ', $line);
        $line = preg_replace('/\b(?:source[_ -]?key|product[_ -]?code|productcode|destination[_ -]?id|tag[_ -]?id|provider[_ -]?id)\b\s*[:=]\s*[^;,.]+/iu', ' ', $line);
        $line = trim(preg_replace('/\s+/', ' ', (string)$line));
        if (self::is_technical_affiliate_context_line($line)) { return ''; }
        if (strpos($line, ':') !== false) {
            list($label, $value) = array_map('trim', explode(':', $line, 2));
            if (self::is_affiliate_context_placeholder($value)) { return ''; }
        }
        return $line;
    }

    private static function summarize_affiliate_context_for_ai($context, $provider = '', $source = '', $title = '', $description = '') {
        $context = sanitize_textarea_field((string)$context);
        $context = preg_replace('/(?:^|\s+)durata\s*:\s*array\.?/iu', ' ', $context);
        $context = trim((string)$context);

        $allowed = array();
        $editorial_patterns = array('/\btipo\b/iu','/\bdescrizione\b/iu','/\bprezzo\b/iu','/\bvaluta\b/iu','/\bdurata\b/iu','/cancellazione/iu','/privat/iu','/inclus/iu','/esclus/iu','/caratteristiche/iu','/flag/iu','/tour/iu','/esperienza/iu','/attività/iu','/servizio/iu');
        foreach (preg_split('/\r\n|\r|\n|;|\|/', $context) as $line) {
            $line = self::clean_affiliate_context_line($line);
            if ($line === '') { continue; }
            if (self::is_viator_context($provider, $source, $context)) {
                $matches_editorial_pattern = false;
                foreach ($editorial_patterns as $pattern) {
                    if (preg_match($pattern, $line)) { $matches_editorial_pattern = true; break; }
                }
                if (!$matches_editorial_pattern) { continue; }
            }
            $allowed[] = self::compact_text($line, 28);
            if (count($allowed) >= 8) { break; }
        }

        if (empty($allowed)) {
            $base = self::clean_affiliate_context_line($description !== '' ? $description : $context);
            if ($base !== '') { $allowed[] = 'Esperienza/tour: ' . self::compact_text($base, 45); }
        }
        $allowed[] = 'Nota: non copiare testo provider e non inventare prezzo, disponibilità o condizioni.';
        return implode(' ', self::compact_rule_list($allowed));
    }

    private static function normalize_payload_for_openai($payload) {
        $payload = is_array($payload) ? $payload : array();
        $profile = is_array($payload['instruction_profile'] ?? null) ? $payload['instruction_profile'] : array();
        $compact_instruction_profile = self::compact_instruction_profile_payload($payload);
        $profile_rules = is_array($payload['instruction_profile_rules'] ?? null) ? $payload['instruction_profile_rules'] : array();
        $user_prompt = ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($payload['openai_prompt'] ?? ($payload['user_inputs']['openai_prompt'] ?? '')));
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
            $image = is_array($link['image'] ?? null) ? $link['image'] : array();
            if (empty($image['image_url']) && !empty($link['featured_image_url'])) { $image['image_url'] = $link['featured_image_url']; }
            if (empty($image['image_alt']) && !empty($link['featured_image_alt'])) { $image['image_alt'] = $link['featured_image_alt']; }
            if (empty($image['image_caption']) && !empty($link['featured_image_caption'])) { $image['image_caption'] = $link['featured_image_caption']; }
            if (empty($image['image_source']) && !empty($link['image_source'])) { $image['image_source'] = $link['image_source']; }
            $compact_image = self::compact_affiliate_image_payload($image, (string)($link['title'] ?? ''));
            $item = array(
                'id' => absint($link['id'] ?? 0),
                'title' => sanitize_text_field((string)($link['title'] ?? '')),
                'description' => self::compact_text($description !== '' ? $description : ($link['content'] ?? ''), 45),
                'affiliate_url' => esc_url_raw((string)($link['affiliate_url'] ?? '')),
                'shortcode' => sanitize_text_field((string)($link['shortcode'] ?? '')),
                'link_types' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($link['link_types'] ?? array()))))),
                'image' => $compact_image,
            );
            if ($context !== '') { $item['context'] = $context; }
            $affiliate_links[] = $item;
        }

        $internal_links = array();
        foreach ((array)($payload['internal_links'] ?? array()) as $link) {
            if (!is_array($link)) { continue; }
            $url = esc_url_raw((string)($link['url'] ?? ''));
            if ($url === '') { continue; }
            $internal_links[] = array(
                'id' => absint($link['id'] ?? 0),
                'title' => sanitize_text_field((string)($link['title'] ?? '')),
                'url' => $url,
                'excerpt' => self::compact_text((string)($link['excerpt'] ?? ''), 28),
                'categories' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($link['categories'] ?? array()))))),
                'tags' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($link['tags'] ?? array()))))),
                'score' => absint($link['score'] ?? 0),
                'matched_terms' => array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($link['matched_terms'] ?? array()))))),
                'reason' => sanitize_text_field((string)($link['reason'] ?? '')),
            );
            if (count($internal_links) >= 8) { break; }
        }
        $internal_link_rules = self::compact_rule_list((array)($payload['internal_link_rules'] ?? self::default_internal_link_rules()));
        $category_candidates = array_slice((array)($payload['category_candidates'] ?? array()), 0, 8);
        $tag_candidates = array_slice((array)($payload['tag_candidates'] ?? array()), 0, 15);
        $taxonomy_rules = self::compact_rule_list((array)($payload['taxonomy_rules'] ?? self::taxonomy_rules()));

        $core_rules = array(
            'Rispondi solo con JSON valido conforme al contratto.',
            'Non usare Markdown o testo fuori dall’oggetto JSON.',
            'Non inventare fatti, prezzi, disponibilità, condizioni o link affiliati.',
            'Usa solo i link affiliati presenti in affiliate_links.',
            'Non generare disclosure affiliate generiche e non inserire frasi tipo “Questo articolo può contenere link affiliati...” o “Se acquisti tramite questi link, potremmo ricevere una commissione...”.',
            'Non creare tag <a> senza href.',
            'Ogni link testuale affiliato diretto deve avere href uguale esattamente al relativo affiliate_url.',
            'Ogni immagine affiliata deve essere racchiusa in un tag <a href="{affiliate_url}"> con target="_blank" e rel="nofollow sponsored noopener".',
            'Non usare mai lo shortcode come href.',
            'affiliate_urls_used deve includere solo gli URL affiliati diretti effettivamente usati negli href; se non usi URL diretti deve essere vuoto.',
            'Se usi shortcode affiliati, compila affiliate_shortcodes_used e mantienilo separato da affiliate_urls_used.',
            'Esempio HTML esatto per immagini affiliate: <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{image.image_alt}" /></a>',
            'Fallback HTML esatto per immagini affiliate: <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{title}" /></a>',
            'Usa solo link interni presenti in internal_links e compila internal_urls_used.',
            'Inserisci da 2 a 5 link interni pertinenti (se internal_links ne contiene): sono utili per la navigazione e la SEO; non forzarne di non pertinenti.',
            'Usa category_ids, tag_ids e new_tags secondo taxonomy_rules.',
            'Formatta il testo per la lettura sul web: evidenzia in <strong> i concetti chiave, i nomi di luoghi/attrazioni e i dati pratici (prezzi, periodi consigliati, durate) — con misura, indicativamente una-due evidenziazioni per paragrafo, mai interi periodi.',
            'I link affiliati di tipologia universale (assicurazione viaggio, eSIM, ecc.) sono pertinenti in QUALSIASI articolo di viaggio, anche multi-destinazione: la coerenza geografica NON si applica a loro. Se ne hai uno tra affiliate_links, inseriscilo nel punto più naturale (consigli pratici, preparativi).',
            'Se è presente location_facts, integra nel testo i DATI REALI della scheda località — mesi migliori/da evitare e clima (temperature, piogge), attrazioni verificate, patrimonio UNESCO, elementi del territorio — citandoli con naturalezza per rendere l\'articolo concreto e autorevole. NON inventare numeri o fatti non presenti in location_facts.',
            'REGOLA CRITICA di monetizzazione: OGNI link affiliato pertinente in affiliate_links va SEMPRE inserito come vero link cliccabile, non solo come fonte di foto o descrizione. Puoi usarlo anche come fonte, ma DEVI comunque linkarlo. Scegli per ogni link la modalità che converte di più e sfrutta tutto l\'arsenale: nome/anchor nel testo con lo shortcode [affiliate_link id="ID" text="…"], immagine avvolta in <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener">, bottone CTA (button="yes"), card e, per raccolte di più strutture/esperienze, il widget [[ALMA_WIDGET]]. Per una struttura/prodotto presentato con foto (es. un hotel) usa la CARD con lo shortcode nella forma: [affiliate_link id="ID" img="yes" fields="title,content" button="yes" button_size="medium"] — mostra immagine, titolo, descrizione e pulsante. MAI mostrare foto o descrizione di un affiliato senza il suo link: è guadagno perso. Usa le link_types (es. "Hotel e Resort") per capire che è una struttura prenotabile e proporre la giusta call to action.',
            'Varia l\'offerta: usa i DIVERSI link affiliati pertinenti disponibili, non concentrarti su uno solo. In un articolo che elenca più strutture/esperienze, linka CIASCUNA al suo affiliate_link corrispondente.',
        );
        $affiliate_rules = self::compact_rule_list(array_merge((array)($payload['affiliate_rules'] ?? array()), array($profile_rules['affiliate_rules'] ?? '')));
        $seo_rules = self::compact_rule_list(array_merge((array)($payload['seo_rules'] ?? array()), array($profile_rules['seo_rules'] ?? '')));
        $source_rules = self::compact_rule_list(array($profile_rules['source_rules'] ?? '', $profile_rules['anti_duplication_rules'] ?? '', $profile_rules['avoid_rules'] ?? '', $profile_rules['disclosure_policy'] ?? ''));
        $location_facts = self::build_writer_location_facts($payload);

        return array(
            'task' => 'create_article_draft_from_selected_sources',
            'site' => array('site_name'=>sanitize_text_field((string)($payload['site_context']['site_name'] ?? get_bloginfo('name'))), 'language'=>$language),
            'article_request' => array('prompt'=>$user_prompt, 'idea_title'=>$idea_title, 'keyword_or_topic'=>$search_query),
            'instruction_profile' => $compact_instruction_profile,
            'editorial_instructions' => array(
                'custom_prompt' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['custom_prompt'] ?? ($profile_rules['custom_prompt'] ?? ''))),
                'tone_of_voice' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['tone_of_voice'] ?? '')),
                'target_audience' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['target_audience'] ?? '')),
                'editorial_style' => ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea((string)($profile['editorial_style'] ?? '')),
                'operational_rules' => self::compact_rule_list($core_rules),
            ),
            'output_requirements' => array('required_fields'=>array('title','slug','excerpt','content','seo_title','seo_description','featured_image_id','affiliate_shortcodes_used','affiliate_urls_used','internal_urls_used','media_used','category_ids','tag_ids','new_tags','warnings'), 'slug_required'=>true, 'content_format'=>'HTML string in JSON', 'featured_image_id_default'=>0, 'media_used_default'=>array()),
            'category_candidates' => $category_candidates,
            'tag_candidates' => $tag_candidates,
            'taxonomy_rules' => $taxonomy_rules,
            'affiliate_links' => $affiliate_links,
            'internal_links' => $internal_links,
            'internal_link_rules' => $internal_link_rules,
            'affiliate_rules' => $affiliate_rules,
            'seo_rules' => $seo_rules,
            'media_rules' => self::compact_rule_list(array_merge((array)($payload['media_rules'] ?? array()), array($profile_rules['image_rules'] ?? ''))),
            'media_candidate_rules' => self::compact_rule_list((array)($payload['media_candidate_rules'] ?? array())),
            'featured_image_candidates' => self::compact_media_candidates((array)($payload['featured_image_candidates'] ?? array()), 5),
            'media_candidates' => self::compact_media_candidates((array)($payload['media_candidates'] ?? array()), 12),
            'max_editorial_media_used' => self::max_editorial_media_used($payload),
            'source_policies' => $source_rules,
            'location_facts' => $location_facts,
            'warnings' => self::compact_rule_list((array)($payload['warnings'] ?? array())),
        );
    }

    /**
     * Scheda località compatta (clima, fatti Wikidata, territorio) per il
     * writer: gli stessi dati del tool scheda_localita dell'agente di
     * ideazione, così l'articolo cita mesi consigliati, temperature,
     * attrazioni verificate e patrimonio UNESCO invece di restare generico.
     * Località dedotta dall'idea attiva; mai bloccante (guardie ovunque).
     *
     * @return array Vuoto se non c'è località o la scheda non è disponibile.
     */
    private static function build_writer_location_facts($payload) {
        if (!apply_filters('alma_ai_writer_use_location_facts', true)) { return array(); }
        if (!class_exists('ALMA_Geo_Facts') || !class_exists('ALMA_AI_Content_Agent_Ideas')) { return array(); }

        $label = '';
        if (is_array($payload['geo_context'] ?? null)) {
            $label = sanitize_text_field((string) ($payload['geo_context']['location'] ?? ''));
        }
        if ($label === '') {
            $idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($idea_id > 0) {
                $label = sanitize_text_field((string) get_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_LABEL, true));
            }
        }
        if ($label === '') { return array(); }

        $card = ALMA_Geo_Facts::agent_payload($label);
        if (!is_array($card) || !empty($card['error'])) { return array(); }
        return self::compact_location_facts($card);
    }

    /**
     * Proietta la scheda località completa (agent_payload) su un sottoinsieme
     * utile alla scrittura: niente serie mensili grezze né dati interni,
     * solo sintesi e liste brevi che l'AI può citare.
     */
    private static function compact_location_facts($card) {
        $out = array('localita' => sanitize_text_field((string) ($card['localita'] ?? '')));
        if (($card['paese'] ?? '') !== '') { $out['paese'] = sanitize_text_field((string) $card['paese']); }

        if (is_array($card['clima'] ?? null)) {
            $clima = $card['clima'];
            $out['clima'] = array_filter(array(
                'mesi_migliori' => sanitize_text_field((string) ($clima['mesi_migliori'] ?? '')),
                'mesi_da_evitare' => sanitize_text_field((string) ($clima['mesi_da_evitare'] ?? '')),
                'sintesi' => sanitize_text_field((string) ($clima['sintesi'] ?? '')),
            ), function ($v) { return $v !== ''; });
        }
        if (is_array($card['fatti'] ?? null)) {
            $fatti = $card['fatti'];
            $attrazioni = array();
            foreach ((array) ($fatti['attrazioni'] ?? array()) as $a) {
                $name = is_array($a) ? (string) ($a['nome'] ?? ($a['name'] ?? '')) : (string) $a;
                $name = sanitize_text_field($name);
                if ($name !== '') { $attrazioni[] = $name; }
                if (count($attrazioni) >= 6) { break; }
            }
            $out['fatti'] = array_filter(array(
                'sintesi' => sanitize_text_field((string) ($fatti['sintesi'] ?? '')),
                'popolazione' => absint($fatti['popolazione'] ?? 0),
                'patrimonio_unesco' => sanitize_text_field((string) ($fatti['patrimonio_unesco'] ?? '')),
                'attrazioni' => $attrazioni,
            ), function ($v) { return $v !== '' && $v !== 0 && $v !== array(); });
        }
        if (is_array($card['territorio'] ?? null)) {
            $sintesi = sanitize_text_field((string) ($card['territorio']['sintesi'] ?? ''));
            if ($sintesi !== '') { $out['territorio'] = array('sintesi' => $sintesi); }
        }
        return $out;
    }


    /**
     * Garanzia di monetizzazione: ogni link affiliato SELEZIONATO ma usato
     * senza renderlo cliccabile (solo foto o solo nome/descrizione) viene
     * reso un vero link affiliato con tracking. Due livelli, nell'ordine:
     *   1) avvolge l'IMMAGINE del link (anche in variante ridimensionata);
     *   2) se non c'è immagine da avvolgere, avvolge il NOME del link se
     *      compare in grassetto nel testo (es. "<strong>Signature Hotel</strong>").
     * Salta i link già presenti (href o shortcode) e ciò che è già dentro
     * un anchor: mai doppioni.
     *
     * @return array{content:string,linked:int[]}
     */
    private static function link_used_affiliate_images($content, $affiliate_links) {
        $content = (string) $content;
        $linked = array();
        foreach ((array) $affiliate_links as $link) {
            if (!is_array($link)) { continue; }
            $url = esc_url_raw((string) ($link['affiliate_url'] ?? ''));
            $id = absint($link['id'] ?? 0);
            if ($url === '' || $id < 1) { continue; }
            // Già monetizzato (href con questo URL o shortcode con questo id)?
            if (strpos($content, $url) !== false) { continue; }
            if (preg_match('/\[affiliate_link[^\]]*\bid="?' . $id . '"?[\s"\]]/', $content)) { continue; }

            $anchor_open = '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow sponsored noopener" data-link-id="' . $id . '" data-track="1" data-source="agent_content">';
            $wrapped = false;

            // Livello 1: immagine del link.
            $img_url = (string) ($link['featured_image_url'] ?? '');
            if ($img_url === '' && is_array($link['image'] ?? null)) { $img_url = (string) ($link['image']['image_url'] ?? ''); }
            $base = self::image_base_token($img_url);
            if ($base !== '') {
                // L'alternanza cattura PRIMA gli anchor completi (le immagini
                // già linkate vengono così saltate) e poi le <img> isolate.
                $new = preg_replace_callback('/<a\b[^>]*>.*?<\/a>|<img\b[^>]*>/is', function ($m) use (&$wrapped, $base, $anchor_open) {
                    $tag = $m[0];
                    if ($wrapped || strncasecmp($tag, '<a', 2) === 0) { return $tag; }
                    if (stripos($tag, $base) === false) { return $tag; }
                    $wrapped = true;
                    return $anchor_open . $tag . '</a>';
                }, $content);
                if ($wrapped && $new !== null) { $content = $new; }
            }

            // Livello 2: nome del link in grassetto (per gli affiliati senza
            // immagine ma citati per nome — "non solo link all'immagine").
            if (!$wrapped) {
                $title = trim((string) ($link['title'] ?? ''));
                if ($title !== '' && mb_strlen($title) >= 4) {
                    $qt = preg_quote($title, '/');
                    $new = preg_replace_callback('/<strong>\s*(' . $qt . ')\s*<\/strong>/iu', function ($m) use (&$wrapped, $anchor_open) {
                        if ($wrapped) { return $m[0]; }
                        $wrapped = true;
                        return '<strong>' . $anchor_open . $m[1] . '</a></strong>';
                    }, $content, 1);
                    if ($wrapped && $new !== null) { $content = $new; }
                }
            }

            if ($wrapped) { $linked[] = $id; }
        }
        return array('content' => $content, 'linked' => $linked);
    }

    /**
     * Token identificativo di un'immagine (nome file senza dimensione né
     * estensione) per riconoscere la stessa immagine anche in una variante
     * ridimensionata: es. ".../ai-signature-hotel-37104-1024x683.webp" e
     * ".../ai-signature-hotel-37104.webp" → "ai-signature-hotel-37104".
     */
    private static function image_base_token($url) {
        $url = (string) $url;
        $path = function_exists('wp_parse_url') ? wp_parse_url($url, PHP_URL_PATH) : parse_url($url, PHP_URL_PATH);
        $file = basename((string) ($path ?: $url));
        $file = preg_replace('/\.(webp|jpe?g|png|gif|avif)$/i', '', $file);
        $file = preg_replace('/-\d+x\d+$/', '', (string) $file);
        return sanitize_text_field((string) $file);
    }

    private static function max_editorial_media_used($payload = array()) {
        $payload = is_array($payload) ? $payload : array();
        $profile_rules = is_array($payload['instruction_profile_rules'] ?? null) ? $payload['instruction_profile_rules'] : array();
        $limit = 5;
        $text = implode(' ', array(
            (string)($profile_rules['image_rules'] ?? ''),
            (string)($payload['instruction_snapshot'] ?? ''),
            (string)($payload['media_rules_text'] ?? ''),
        ));
        if (preg_match_all('/(?:massimo|max|non\s+pi[uù]\s+di|limite)\D{0,24}(\d{1,2})\D{0,24}(?:immagini|media)/iu', $text, $matches)) {
            foreach ((array)$matches[1] as $raw) {
                $found = absint($raw);
                if ($found > 0) { $limit = min($limit, $found); }
            }
        }
        $limit = max(0, min(5, absint($limit)));
        return (int)apply_filters('alma_ai_max_editorial_media_used', $limit, $payload);
    }

    private static function compact_media_candidates($candidates, $limit = 12) {
        $items = array();
        foreach ((array)$candidates as $candidate) {
            if (!is_array($candidate)) { continue; }
            $id = absint($candidate['attachment_id'] ?? 0);
            $url = esc_url_raw((string)($candidate['url'] ?? ''));
            if ($id < 1 || $url === '' || !wp_http_validate_url($url)) { continue; }
            $items[] = array(
                'attachment_id' => $id,
                'url' => $url,
                'title' => sanitize_text_field((string)($candidate['title'] ?? '')),
                'alt' => sanitize_text_field((string)($candidate['alt'] ?? ($candidate['alt_text'] ?? ''))),
                'caption' => sanitize_text_field((string)($candidate['caption'] ?? '')),
                'width' => absint($candidate['width'] ?? 0),
                'height' => absint($candidate['height'] ?? 0),
                'score' => max(0, min(100, absint($candidate['score'] ?? ($candidate['score_match'] ?? 0)))),
                'reason' => sanitize_text_field((string)($candidate['reason'] ?? '')),
            );
            if (count($items) >= max(0, absint($limit))) { break; }
        }
        return $items;
    }

    private static function media_candidate_rules($max_editorial_media_used) {
        $max = max(0, min(5, absint($max_editorial_media_used)));
        return array(
            'Scegli featured_image_id solo da featured_image_candidates.',
            'Se featured_image_candidates contiene candidati, scegli una featured image pertinente.',
            'Se featured_image_candidates è vuoto, imposta featured_image_id a 0.',
            'Usa massimo ' . $max . ' immagini editoriali da media_candidates nel corpo articolo.',
            'Non usare immagini non presenti nel payload.',
            'Non inventare URL immagini.',
            'Non usare immagini affiliate come immagini editoriali.',
            'Le immagini affiliate restano gestite tramite affiliate_links[].image.',
            'Compila media_used solo con immagini effettivamente usate nel contenuto.',
        );
    }


    private static function default_internal_link_rules() {
        return array(
            'Usa solo link interni presenti in internal_links.',
            'Inserisci da 2 a 5 link interni se pertinenti; per articoli lunghi massimo 8.',
            'Non inventare URL interni.',
            'Non mostrare URL visibili nel testo.',
            'Usa anchor text naturale, descrittivo e coerente con il contesto.',
            'Non usare anchor generiche come clicca qui, leggi di più o qui.',
            'Non inserire blocchi Leggi anche salvo richiesta esplicita.',
            'Non linkare due volte lo stesso URL.',
            'Non forzare link non pertinenti.',
            'Compila internal_urls_used con gli URL interni realmente usati nel contenuto.',
        );
    }

    /**
     * Garantisce un link di tipologia universale (assicurazioni/eSIM) nel
     * contenuto: se non ne contiene già uno e ne esiste uno pubblicato, lo
     * inserisce come card (immagine + titolo + descrizione) a circa due terzi
     * dell'articolo. Deterministico, indipendente dai candidati selezionati:
     * gli universali valgono per qualsiasi articolo di viaggio.
     *
     * @return array{content:string,added:bool,link_id:int}
     */
    private static function guarantee_universal_link($content) {
        $result = array('content' => $content, 'added' => false, 'link_id' => 0);
        if (!class_exists('ALMA_Universal_Link_Types') || !class_exists('ALMA_AI_Post_Optimizer')) {
            return $result;
        }
        $universal_ids = ALMA_Universal_Link_Types::top_universal_links(3);
        if (empty($universal_ids)) { return $result; }
        // Già presente nel contenuto (shortcode con uno degli ID universali)?
        foreach ($universal_ids as $uid) {
            if (preg_match('/\[affiliate_link[^\]]*\bid="?' . (int) $uid . '"?[\s"\]]/', $content)) {
                return $result;
            }
        }
        $link_id = (int) $universal_ids[0];
        $paragraphs = ALMA_AI_Post_Optimizer::paragraph_texts($content);
        $count = count($paragraphs);
        if ($count < 3) { return $result; }
        $rules = class_exists('ALMA_AI_Insertion_Rules') ? ALMA_AI_Insertion_Rules::get_rules() : array('min_paragraphs_before' => 2, 'button_text' => __('Scopri di più', 'affiliate-link-manager-ai'));
        $min_paragraph = (int) ($rules['min_paragraphs_before'] ?? 2);
        $paragraph = max($min_paragraph, (int) floor($count * 0.7));
        $paragraph = min($paragraph, $count - 1);
        $button_text = sanitize_text_field((string) ($rules['button_text'] ?? __('Scopri di più', 'affiliate-link-manager-ai')));
        $card = '[affiliate_link id="' . $link_id . '" img="yes" fields="title,content" button="yes" button_text="' . esc_attr($button_text) . '"]';
        $result['content'] = ALMA_AI_Post_Optimizer::insert_after_paragraph($content, $paragraph, $card, false);
        $result['added'] = true;
        $result['link_id'] = $link_id;
        return $result;
    }

    /**
     * Sceglie il layout del widget di fallback in base alla tipologia
     * prevalente dei link: un solo link → hero_spotlight; alloggi/mete
     * (hotel, resort, destinazioni) → destination_cards; altrimenti (tour,
     * attività, esperienze) → experience_cards. Evita di usare sempre lo
     * stesso layout.
     */
    private static function pick_widget_layout_for_links($link_ids) {
        $link_ids = array_values(array_filter(array_map('absint', (array) $link_ids)));
        if (count($link_ids) <= 1) { return 'hero_spotlight'; }
        $accommodation = 0; $total = 0;
        foreach ($link_ids as $id) {
            $terms = get_the_terms($id, 'link_type');
            if (is_wp_error($terms) || empty($terms)) { continue; }
            foreach ($terms as $term) {
                $total++;
                if (preg_match('/hotel|resort|alloggi|ostell|b&b|appartament|destinazion|met[ae]|citt/i', (string) $term->name)) { $accommodation++; }
            }
        }
        // Se almeno metà dei link sono strutture/mete, la griglia destinazioni
        // valorizza meglio l'immagine; altrimenti le card esperienze.
        return ($total > 0 && $accommodation * 2 >= $total) ? 'destination_cards' : 'experience_cards';
    }

    private static function candidate_affiliate_images($affiliate_links) {
        $images = array();
        foreach ((array)$affiliate_links as $link) {
            if (!is_array($link)) { continue; }
            $image = is_array($link['image'] ?? null) ? $link['image'] : array();
            $url = esc_url_raw((string)($image['image_url'] ?? ($link['featured_image_url'] ?? '')));
            if ($url !== '' && !wp_http_validate_url($url)) { $url = ''; }
            $affiliate_url = esc_url_raw((string)($link['affiliate_url'] ?? ''));
            if (($url === '' && $affiliate_url === '') || ($affiliate_url !== '' && !wp_http_validate_url($affiliate_url))) { continue; }
            $images[] = array(
                'affiliate_link_id' => absint($link['id'] ?? ($link['affiliate_link_post_id'] ?? 0)),
                'id' => absint($link['id'] ?? ($link['affiliate_link_post_id'] ?? 0)),
                'attachment_id' => absint($link['featured_image_id'] ?? 0),
                'title' => sanitize_text_field((string)($link['title'] ?? '')),
                'affiliate_url' => $affiliate_url,
                'url' => $url,
                'image_url' => $url,
                'alt' => sanitize_text_field((string)($image['image_alt'] ?? ($link['featured_image_alt'] ?? ''))),
                'caption' => sanitize_text_field((string)($image['image_caption'] ?? ($link['featured_image_caption'] ?? ''))),
                'source' => sanitize_text_field((string)($image['image_source'] ?? ($link['image_source'] ?? ''))),
            );
        }
        return $images;
    }

    private static function candidate_affiliate_records_from_ids($affiliate_ids) {
        $records = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', (array)$affiliate_ids)))) as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link') { continue; }
            $affiliate_url = self::get_affiliate_url($id);
            if ($affiliate_url === '') { continue; }
            $image = class_exists('ALMA_AI_Content_Agent_Affiliate_Index') ? ALMA_AI_Content_Agent_Affiliate_Index::get_image_data($id) : array();
            $image_url = esc_url_raw((string)($image['featured_image_url'] ?? ''));
            if ($image_url !== '' && !wp_http_validate_url($image_url)) { $image_url = ''; }
            $records[] = array(
                'affiliate_link_id' => $id,
                'id' => $id,
                'attachment_id' => absint($image['featured_image_id'] ?? 0),
                'title' => sanitize_text_field((string)$post->post_title),
                'affiliate_url' => $affiliate_url,
                'url' => $image_url,
                'image_url' => $image_url,
                'alt' => sanitize_text_field((string)($image['featured_image_alt'] ?? $post->post_title)),
                'caption' => sanitize_text_field((string)($image['featured_image_caption'] ?? '')),
                'source' => sanitize_text_field((string)($image['image_source'] ?? '')),
            );
        }
        return $records;
    }

    private static function fetch_document_chunks($knowledge_item_id, $limit = 3) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $limit = max(1, absint($limit));
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_text'));
        if (!$col) { return array(); }
        return (array)$wpdb->get_col($wpdb->prepare("SELECT normalized_text FROM $table WHERE knowledge_item_id=%d ORDER BY id ASC LIMIT %d", absint($knowledge_item_id), $limit));
    }

    private static function taxonomy_rules() {
        return array(
            'Scegli da 1 a 3 category_ids usando solo category_candidates.',
            'Non inventare categorie.',
            'Scegli tag_ids usando tag_candidates quando pertinenti.',
            'Puoi proporre massimo 3 new_tags solo se specifici e utili.',
            'Non proporre tag generici come viaggio, tour, esperienza, consigli o cosa vedere.',
            'Non superare 8 tag totali.',
        );
    }

    private static function taxonomy_match_text($payload) {
        $parts = array(
            $payload['openai_prompt'] ?? '',
            $payload['idea_context']['idea_title'] ?? '',
            $payload['idea_context']['search_query'] ?? '',
            $payload['user_inputs']['destination'] ?? '',
            $payload['user_inputs']['theme'] ?? '',
        );
        foreach ((array)($payload['internal_links'] ?? array()) as $link) {
            if (!is_array($link)) { continue; }
            $parts[] = $link['title'] ?? '';
            $parts[] = implode(' ', (array)($link['categories'] ?? array()));
            $parts[] = implode(' ', (array)($link['tags'] ?? array()));
        }
        foreach ((array)($payload['affiliate_links'] ?? array()) as $link) {
            if (!is_array($link)) { continue; }
            $parts[] = $link['title'] ?? '';
            $parts[] = $link['description'] ?? ($link['excerpt'] ?? '');
        }
        return self::normalize_taxonomy_text(implode(' ', $parts));
    }

    private static function normalize_taxonomy_text($text) {
        $text = remove_accents(strtolower(wp_strip_all_tags((string)$text)));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string)$text));
    }

    private static function taxonomy_term_score($term, $match_text, $taxonomy, $payload) {
        $name = self::normalize_taxonomy_text($term->name ?? '');
        $slug = self::normalize_taxonomy_text($term->slug ?? '');
        if ($name === '' && $slug === '') { return array(0, array()); }
        $score = 0; $reasons = array();
        if ($name !== '' && strpos(' '.$match_text.' ', ' '.$name.' ') !== false) { $score += 60; $reasons[] = 'Match nome termine'; }
        if ($slug !== '' && strpos(' '.$match_text.' ', ' '.$slug.' ') !== false) { $score += 45; $reasons[] = 'Match slug termine'; }
        foreach ((array)($payload['internal_links'] ?? array()) as $link) {
            $items = $taxonomy === 'category' ? (array)($link['categories'] ?? array()) : (array)($link['tags'] ?? array());
            foreach ($items as $item) {
                if (self::normalize_taxonomy_text($item) === $name && $name !== '') { $score += 25; $reasons[] = 'Ricorrente nei link interni candidati'; }
            }
        }
        if ((int)($term->parent ?? 0) > 0) { $score += 6; $reasons[] = 'Categoria specifica'; }
        if ((int)($term->count ?? 0) > 0) { $score += min(12, (int)$term->count); }
        return array($score, array_values(array_unique($reasons)));
    }

    private static function build_taxonomy_candidates($payload, $taxonomy, $max) {
        if ($taxonomy === 'category') {
            $terms = get_categories(array('hide_empty'=>false, 'number'=>200));
        } else {
            $terms = get_terms(array('taxonomy'=>$taxonomy, 'hide_empty'=>false, 'number'=>200));
        }
        if (is_wp_error($terms) || !is_array($terms)) { return array(); }
        $match_text = self::taxonomy_match_text($payload);
        $scored = array();
        foreach ($terms as $term) {
            list($score, $reasons) = self::taxonomy_term_score($term, $match_text, $taxonomy, $payload);
            if ($score < 45) { continue; }
            $parent = '';
            if ($taxonomy === 'category' && !empty($term->parent)) {
                $parent_term = get_term((int)$term->parent, 'category');
                if ($parent_term && !is_wp_error($parent_term)) { $parent = sanitize_text_field($parent_term->name); }
            }
            $item = array('id'=>(int)$term->term_id, 'name'=>sanitize_text_field($term->name), 'slug'=>sanitize_title($term->slug));
            if ($taxonomy === 'category') { $item['parent'] = $parent; }
            $item['reason'] = !empty($reasons) ? implode('; ', array_slice($reasons, 0, 2)) : 'Match editoriale';
            $item['_score'] = (int)$score;
            $scored[] = $item;
        }
        usort($scored, function($a, $b) { return ((int)$b['_score']) <=> ((int)$a['_score']); });
        $out = array();
        foreach (array_slice($scored, 0, max(1, (int)$max)) as $item) {
            unset($item['_score']);
            $out[] = $item;
        }
        return $out;
    }

    private static function validate_taxonomies($parsed, $category_candidates, $tag_candidates) {
        $warnings = array();
        $candidate_category_ids = array_map('absint', wp_list_pluck((array)$category_candidates, 'id'));
        $candidate_tag_ids = array_map('absint', wp_list_pluck((array)$tag_candidates, 'id'));
        $category_ids = array();
        foreach ((array)($parsed['category_ids'] ?? array()) as $id) {
            $id = absint($id);
            if (!$id || !in_array($id, $candidate_category_ids, true)) { $warnings[] = 'Categoria AI scartata perché non candidata.'; continue; }
            $term = get_term($id, 'category');
            if (!$term || is_wp_error($term)) { $warnings[] = 'Categoria AI scartata perché inesistente.'; continue; }
            $category_ids[$id] = $id;
            if (count($category_ids) >= 3) { break; }
        }
        if (empty($category_ids) && !empty($candidate_category_ids)) {
            $category_ids[$candidate_category_ids[0]] = $candidate_category_ids[0];
            $warnings[] = 'Categoria fallback applicata dal primo candidato coerente.';
        }

        $tag_ids = array();
        foreach ((array)($parsed['tag_ids'] ?? array()) as $id) {
            $id = absint($id);
            if (!$id) { continue; }
            if (!in_array($id, $candidate_tag_ids, true)) { $warnings[] = 'Tag esistente scartato perché non candidato.'; continue; }
            $term = get_term($id, 'post_tag');
            if (!$term || is_wp_error($term)) { $warnings[] = 'Tag esistente scartato perché inesistente.'; continue; }
            $tag_ids[$id] = $id;
            if (count($tag_ids) >= 8) { break; }
        }

        $blocked = array('viaggio','tour','esperienza','consigli','cosa vedere','da non perdere','italia','vacanze');
        $blocked = array_merge($blocked, (array)apply_filters('alma_ai_blocked_new_tag_terms', array()));
        $allowed = (array)apply_filters('alma_ai_allowed_new_tag_terms', array());
        $max_new = max(0, absint(apply_filters('alma_ai_max_new_tags', 3)));
        $max_total = max(1, absint(apply_filters('alma_ai_max_total_tags', 8)));
        $new_tags = array();
        foreach ((array)($parsed['new_tags'] ?? array()) as $tag) {
            if (count($new_tags) >= $max_new || (count($tag_ids) + count($new_tags)) >= $max_total) { break; }
            $tag = sanitize_text_field(wp_strip_all_tags((string)$tag));
            $tag = trim(preg_replace('/\s+/', ' ', $tag));
            if ($tag === '' || mb_strlen($tag) > 40) { $warnings[] = 'Nuovo tag scartato perché vuoto o troppo lungo.'; continue; }
            $norm = self::normalize_taxonomy_text($tag);
            $is_allowed = in_array($norm, array_map(array(__CLASS__, 'normalize_taxonomy_text'), $allowed), true);
            if (!$is_allowed && in_array($norm, array_map(array(__CLASS__, 'normalize_taxonomy_text'), $blocked), true)) { $warnings[] = 'Nuovo tag generico scartato: '.$tag.'.'; continue; }
            if (!$is_allowed && strpos($norm, ' ') === false && in_array($norm, array('viaggio','tour','esperienza','consigli','vacanze','italia'), true)) { $warnings[] = 'Nuovo tag mon parola generico scartato: '.$tag.'.'; continue; }
            $existing = term_exists($tag, 'post_tag');
            if (is_array($existing) && !empty($existing['term_id'])) { $tag_ids[absint($existing['term_id'])] = absint($existing['term_id']); continue; }
            if ($existing && is_numeric($existing)) { $tag_ids[absint($existing)] = absint($existing); continue; }
            $key = self::normalize_taxonomy_text($tag);
            $new_tags[$key] = $tag;
        }
        $tag_ids = array_slice(array_values($tag_ids), 0, $max_total);
        if (count($tag_ids) + count($new_tags) > $max_total) { $new_tags = array_slice($new_tags, 0, max(0, $max_total - count($tag_ids)), true); }
        return array('category_ids'=>array_values($category_ids), 'tag_ids'=>$tag_ids, 'new_tags'=>array_values($new_tags), 'warnings'=>array_values(array_unique($warnings)));
    }

    private static function apply_taxonomies_to_post($post_id, $validated) {
        $warnings = array();
        $category_ids = array_values(array_filter(array_map('absint', (array)($validated['category_ids'] ?? array()))));
        $tag_ids = array_values(array_filter(array_map('absint', (array)($validated['tag_ids'] ?? array()))));
        $new_tags = (array)($validated['new_tags'] ?? array());
        if (!empty($category_ids)) {
            $res = wp_set_post_terms($post_id, $category_ids, 'category', false);
            if (is_wp_error($res)) { $warnings[] = 'Errore applicazione categorie: '.$res->get_error_message(); $category_ids = array(); }
        }
        $tag_terms = $tag_ids;
        $created = array();
        if (!empty($new_tags) && current_user_can('manage_categories')) {
            foreach ($new_tags as $tag) {
                $tag = sanitize_text_field(wp_strip_all_tags((string)$tag));
                if ($tag === '') { continue; }
                $exists = term_exists($tag, 'post_tag');
                if (!$exists) { $exists = wp_insert_term($tag, 'post_tag'); }
                if (is_wp_error($exists)) { $warnings[] = 'Errore creazione tag '.$tag.': '.$exists->get_error_message(); continue; }
                $term_id = is_array($exists) ? absint($exists['term_id'] ?? 0) : absint($exists);
                if ($term_id) { $tag_terms[] = $term_id; $created[] = $tag; }
            }
        } elseif (!empty($new_tags)) {
            $warnings[] = 'Nuovi tag non creati: capability manage_categories mancante.';
        }
        $tag_terms = array_values(array_unique(array_filter(array_map('absint', $tag_terms))));
        if (!empty($tag_terms)) {
            $res = wp_set_post_terms($post_id, $tag_terms, 'post_tag', false);
            if (is_wp_error($res)) { $warnings[] = 'Errore applicazione tag: '.$res->get_error_message(); $tag_terms = array(); }
        }
        return array('category_ids'=>$category_ids, 'tag_ids'=>$tag_terms, 'new_tags'=>$created, 'warnings'=>$warnings);
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
                'image' => self::compact_affiliate_image_payload(is_array($row['image'] ?? null) ? $row['image'] : array(
                    'image_url' => $row['featured_image_url'] ?? '',
                    'image_alt' => $row['featured_image_alt'] ?? ($row['title'] ?? ''),
                    'image_caption' => $row['featured_image_caption'] ?? '',
                    'image_source' => $row['image_source'] ?? '',
                ), (string)($row['title'] ?? '')),
            );

            if ($source_group !== 'affiliate_link' && sanitize_key($row['source_type'] ?? '') !== 'affiliate_link') { $selection_context[] = $entry; continue; }
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

            $image = ALMA_AI_Content_Agent_Affiliate_Index::get_image_data($p->ID);
            $has_image = !empty($image['has_featured_image']) && !empty($image['featured_image_url']) && wp_http_validate_url((string)$image['featured_image_url']);
            $compact_image = self::compact_affiliate_image_payload(array(
                'image_url' => $has_image ? (string)$image['featured_image_url'] : '',
                'image_alt' => $image['featured_image_alt'] ?? $p->post_title,
                'image_caption' => $image['featured_image_caption'] ?? '',
                'image_source' => $image['image_source'] ?? '',
            ), (string)$p->post_title);
            $entry['image'] = $compact_image;
            $selection_context[] = $entry;
            $image_debug = class_exists('ALMA_AI_Content_Agent_Affiliate_Index') ? ALMA_AI_Content_Agent_Affiliate_Index::get_image_debug_data($p->ID) : array();

            // Tipologie AUTOREVOLI dalla tassonomia: i risultati di ricerca
            // (row) non le portano per i link appena importati, così l'agent
            // non sapeva che fossero "Hotel e Resort" prenotabili e li usava
            // solo come fonte di immagine/descrizione. La tassonomia è la
            // fonte di verità; row come fallback.
            $term_names = get_the_terms($p->ID, 'link_type');
            $link_types = (is_array($term_names) && !is_wp_error($term_names)) ? array_values(array_map('sanitize_text_field', wp_list_pluck($term_names, 'name'))) : array();
            if (empty($link_types)) { $link_types = array_values(array_map('sanitize_text_field', (array)($row['link_types'] ?? array()))); }

            $affiliate_links[] = array(
                'id' => (int)$p->ID,
                'title' => sanitize_text_field($p->post_title),
                'content' => $content,
                'excerpt' => $excerpt,
                'affiliate_url' => $affiliate_url,
                'ai_context' => $ai_context,
                'shortcode' => $shortcode,
                'link_types' => $link_types,
                'provider' => $source_provider,
                'source' => $source_name !== '' ? $source_name : sanitize_text_field($row['source'] ?? ''),
                'provenance' => sanitize_text_field($row['provenance'] ?? ''),
                'score' => (int)($row['score'] ?? 0),
                'reason' => sanitize_text_field($row['reason'] ?? ''),
                'affiliate_link_post_id' => (int)$p->ID,
                'source_agent_prompt_key' => $source_prompt_key,
                'source_agent_prompt_available' => $source_prompt !== '',
                'source_agent_prompt' => $source_prompt,
                'featured_image_id' => absint($image['featured_image_id'] ?? 0),
                'featured_image_url' => esc_url_raw((string)($image['featured_image_url'] ?? '')),
                'featured_image_alt' => sanitize_text_field((string)($image['featured_image_alt'] ?? '')),
                'featured_image_caption' => sanitize_text_field((string)($image['featured_image_caption'] ?? '')),
                'has_featured_image' => (bool)$has_image,
                'image_source' => sanitize_text_field((string)($image['image_source'] ?? '')),
                'image_import_status' => sanitize_text_field((string)($image['image_import_status'] ?? '')),
                'image' => $compact_image,
                'image_debug' => $image_debug,
            );
        }

        if (empty($selection_context)) { $warnings[] = 'Nessun selected result nella sessione contenuto.'; }
        if (empty($affiliate_links)) { $warnings[] = 'Nessun affiliate link selezionato.'; }
        if (empty($session['openai_prompt']) && empty($session['last_query']['temporary_instructions'])) { $warnings[] = 'Prompt OpenAI assente nella idea/sessione.'; }

        $active_idea_title = '';
        $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
        if ($active_idea_id > 0 && class_exists('ALMA_AI_Content_Agent_Ideas')) {
            $active_idea = ALMA_AI_Content_Agent_Ideas::get($active_idea_id);
            $active_idea_title = sanitize_text_field((string)($active_idea['title'] ?? ''));
        }
        $content_search_query = sanitize_text_field($session['last_query']['content_search_query'] ?? ($session['last_query']['search_terms'] ?? ''));
        // Il titolo dell'articolo nasce dal Titolo idea; la query di ricerca è
        // solo il fallback quando il titolo è rimasto quello di default.
        $idea_title = ($active_idea_title !== '' && strcasecmp($active_idea_title, 'Nuova idea') !== 0) ? $active_idea_title : $content_search_query;
        $openai_prompt = sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['openai_prompt'] ?? ($session['last_query']['temporary_instructions'] ?? '')));

        $internal_link_selection = ALMA_AI_Content_Agent_Internal_Link_Selector::select_candidates(array(
            'content_search_query' => $content_search_query,
            'search_query' => $content_search_query,
            'idea' => $content_search_query,
            'keyword' => $session['last_query']['search_terms'] ?? $content_search_query,
            'destination' => $session['last_query']['destination'] ?? '',
            'openai_prompt' => $openai_prompt,
            'idea_prompt' => $openai_prompt,
            'prompt' => $openai_prompt,
            'idea_title' => $idea_title,
        ));

        $taxonomy_seed_payload = array('openai_prompt'=>$openai_prompt,'idea_context'=>array('idea_title'=>$idea_title,'search_query'=>sanitize_text_field($session['last_query']['search_terms'] ?? $content_search_query)),'user_inputs'=>array('destination'=>sanitize_text_field($session['last_query']['destination'] ?? ''),'theme'=>sanitize_text_field($session['last_query']['theme'] ?? '')),'internal_links'=>(array)($internal_link_selection['items'] ?? array()),'affiliate_links'=>$affiliate_links);
        $category_candidates = self::build_taxonomy_candidates($taxonomy_seed_payload, 'category', 8);
        $tag_candidates = self::build_taxonomy_candidates($taxonomy_seed_payload, 'post_tag', 15);

        $profile_payload = self::build_instruction_profile_payload($session, $warnings);
        $max_editorial_media_used = self::max_editorial_media_used($profile_payload);
        $media_selection = ALMA_AI_Content_Agent_Media_Selector::select_candidates(array(
            'content_search_query' => $content_search_query,
            'search_query' => $content_search_query,
            'keyword_or_topic' => $session['last_query']['search_terms'] ?? $content_search_query,
            'destination' => $session['last_query']['destination'] ?? '',
            'theme' => $session['last_query']['theme'] ?? '',
            'prompt' => $openai_prompt,
            'openai_prompt' => $openai_prompt,
            'idea_title' => $idea_title,
            'category_candidates' => $category_candidates,
            'tag_candidates' => $tag_candidates,
            'internal_links' => (array)($internal_link_selection['items'] ?? array()),
            'limit_featured' => 5,
            'limit_media' => 12,
        ));
        $warnings = array_values(array_unique(array_merge($warnings, (array)($media_selection['warnings'] ?? array()))));
        $affiliate_rules = array(
            'Usare solo i link affiliati selezionati nel payload.',
            'Non inventare link affiliati.',
            'Non generare disclosure affiliate generiche; vietate frasi tipo “Questo articolo può contenere link affiliati...” o varianti su commissione/senza costi aggiuntivi.',
            'Preferire shortcode WordPress per box/link affiliati.',
            'Usare affiliate_url solo per link testuali diretti quando necessario.',
            'Non creare tag <a> senza href e non usare mai shortcode come href.',
            'Ogni link testuale affiliato diretto deve avere href uguale al relativo affiliate_url.',
            'Ogni immagine affiliata deve usare <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{image.image_alt}" /></a>.',
            'Fallback immagine affiliata: <a href="{affiliate_url}" target="_blank" rel="nofollow sponsored noopener"><img class="aligncenter size-full" src="{image.image_url}" alt="{title}" /></a>.',
            'Compilare affiliate_shortcodes_used con gli shortcode realmente usati.',
            'Se usi URL diretti, compilare affiliate_urls_used con gli URL realmente usati negli href; se non usi URL diretti, affiliate_urls_used deve essere vuoto.',
            'Non usare link non presenti nel payload.',
        );
        if (!empty($profile_payload['instruction_profile_rules']['affiliate_rules'])) { $affiliate_rules[] = $profile_payload['instruction_profile_rules']['affiliate_rules']; }
        // Fase 4: vocabolario dei pattern di inserimento e regole di densità
        // dalla tab "Regole inserimento" (poi applicate anche dal QA).
        if (class_exists('ALMA_AI_Insertion_Rules')) {
            $affiliate_rules = array_merge($affiliate_rules, ALMA_AI_Insertion_Rules::payload_rules());
        }
        $seo_rules = array(
            'Il titolo dell\'articolo deve ispirarsi a idea_context.idea_title (e al prompt); content_search_query è servita SOLO a selezionare i link affiliati, non usarla come argomento del pezzo.',
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
            'user_inputs'=>array('content_search_query'=>$content_search_query,'theme'=>sanitize_text_field($session['last_query']['theme'] ?? ''),'destination'=>sanitize_text_field($session['last_query']['destination'] ?? ''),'openai_prompt'=>$openai_prompt),
            'idea_context'=>array('idea_title'=>$idea_title,'idea_prompt'=>$openai_prompt,'search_query'=>sanitize_text_field($session['last_query']['search_terms'] ?? $content_search_query),'selected_results_count'=>count($selection_context)),
            'openai_prompt'=>$openai_prompt,
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
                'use_only_payload_affiliate_images'=>true,
                'use_only_payload_internal_links'=>true,
                'max_one_image_per_affiliate_link'=>true,
                'max_editorial_media_used'=>$max_editorial_media_used,
            ),
            'selection_context'=>$selection_context,
            'affiliate_links'=>$affiliate_links,
            'internal_links'=>(array)($internal_link_selection['items'] ?? array()),
            'internal_link_rules'=>self::default_internal_link_rules(),
            'category_candidates'=>$category_candidates,
            'tag_candidates'=>$tag_candidates,
            'taxonomy_rules'=>self::taxonomy_rules(),
            'internal_link_diagnostics'=>(array)($internal_link_selection['diagnostics'] ?? array()),
            'internal_link_debug'=>(array)($internal_link_selection['diagnostics'] ?? array()),
            'featured_image_candidates'=>self::compact_media_candidates((array)($media_selection['featured_image_candidates'] ?? array()), 5),
            'media_candidates'=>self::compact_media_candidates((array)($media_selection['media_candidates'] ?? array()), 12),
            'media_candidate_rules'=>self::media_candidate_rules($max_editorial_media_used),
            'media_candidate_debug'=>array_merge((array)($media_selection['debug'] ?? array()), array('max_editorial_media_used'=>$max_editorial_media_used)),
            'max_editorial_media_used'=>$max_editorial_media_used,
            'source_agent_prompts'=>$source_agent_prompts,
            'posts'=>array(),'documents'=>array(),'sources_online'=>array(),'pages'=>array(),'media'=>array(),
            'affiliate_rules'=>$affiliate_rules,
            'seo_rules'=>$seo_rules,
            'media_rules'=>array_filter(array('Usa massimo '.$max_editorial_media_used.' immagini editoriali dalla Media Library nel corpo dell’articolo.','Usare immagini affiliate solo se pertinenti alla sezione.','Non inventare URL immagini.','Usare solo immagini presenti nei link affiliati selezionati.','Non duplicare troppe volte la stessa immagine.','Non scaricare immagini durante la generazione bozza.','Se nessuna immagine della Media Library è adatta a una sezione, puoi inserire su una riga a sé un segnaposto [Immagine: descrizione fotografica dettagliata della scena] (massimo 3 per articolo): verrà generato dall\'AI e sostituito automaticamente dopo la creazione.', $profile_payload['instruction_profile_rules']['image_rules'] ?? '')),
            'output_contract'=>array('title','slug','excerpt','content','seo_title','seo_description','featured_image_id','affiliate_shortcodes_used','affiliate_urls_used','internal_urls_used','media_used','category_ids','tag_ids','new_tags','warnings'),
            'widget_request_contract'=>'Campo OPZIONALE widget_request nell\'output JSON: {"title":string,"layout":string,"link_ids":[int],"button_text":string,"rewritten":[{"id":int,"title":string,"description":string}]}. Scegli il layout adatto al contesto dell\'articolo: "destination_cards" = griglia di mete/destinazioni con titolo e località sull\'immagine (2-6 link); "experience_cards" = card compatte per tour e attività specifiche con pulsante, carosello su mobile (2-8 link); "hero_spotlight" = UNA sola esperienza di punta in grande evidenza con testo e pulsante (esattamente 1 link). SCEGLI il layout in base al CONTENUTO, non usare sempre lo stesso: per hotel/alloggi e mete usa "destination_cards", per tour/attività "experience_cards", per una singola proposta di punta "hero_spotlight". Compilalo SOLO se hai inserito il segnaposto [[ALMA_WIDGET]] nel content; il sistema creerà il widget reale e sostituirà il segnaposto. Posiziona il segnaposto [[ALMA_WIDGET]] in un punto INTERMEDIO dell\'articolo (dopo una sezione centrale pertinente) per spezzare visivamente il testo — NON alla fine, dove è meno efficace.',
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
                ALMA_Logger::warning('ALMA payload download error', array('error' => $e->getMessage()));
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
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress. Output solo JSON valido.', 'user_prompt'=>$prompt.' CONTEXT: '.ALMA_OpenAI_Service::encode_context($context), 'json_output'=>true, 'max_output_tokens'=>1800));
        if (empty($res['success'])) { return self::fail($res['error'] ?? 'Risposta OpenAI fallita.', $res['model'] ?? '', 'idea:'.$idea_id); }
        $parsed = json_decode($res['response'], true); if (!is_array($parsed)) $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json($res['response']), true);
        if (!is_array($parsed)) { return self::fail('Draft JSON non valido', $res['model'] ?? '', 'idea:'.$idea_id); }
        $candidate_affiliate_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_affiliate_links'], 'link_id'))));
        $candidate_image_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_images'], 'attachment_id'))));
        $candidate_affiliate_records = self::candidate_affiliate_records_from_ids($candidate_affiliate_ids);
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids, $candidate_affiliate_records, array());
        if (!is_array($clean) || !array_key_exists('title', $clean) || !array_key_exists('content', $clean)) return self::fail('QA output non valido.', $res['model'] ?? '', 'idea:'.$idea_id);
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') return self::fail('Output draft non valido dopo QA.', $res['model'] ?? '', 'idea:'.$idea_id);
        $post_id = wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'draft','post_author'=>get_current_user_id(),'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content'])), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'idea:'.$idea_id); }
        if (!empty($clean['featured_image_id'])) set_post_thumbnail($post_id, $clean['featured_image_id']);
        if (class_exists('ALMA_AI_Seo_Bridge')) { ALMA_AI_Seo_Bridge::apply($post_id, (string)($clean['seo_title'] ?? ''), (string)($clean['seo_description'] ?? '')); }
        update_post_meta($post_id, '_alma_ai_agent_generated', 1); update_post_meta($post_id, '_alma_ai_agent_idea_id', $idea_id); update_post_meta($post_id, '_alma_ai_agent_brief_id', absint($brief['id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_task', 'content_draft_generation'); update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($brief['instruction_profile_id'] ?? $idea['instruction_profile_id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($brief['instruction_snapshot_hash'] ?? $idea['instruction_snapshot_hash'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_affiliate_links_used', wp_json_encode($clean['affiliate_links_used'])); update_post_meta($post_id, '_alma_ai_agent_affiliate_shortcodes_used', wp_json_encode((array)($clean['affiliate_shortcodes_used'] ?? array()))); update_post_meta($post_id, '_alma_ai_agent_affiliate_urls_used', wp_json_encode((array)($clean['affiliate_urls_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_internal_urls_used', wp_json_encode((array)($clean['internal_urls_used'] ?? array()))); update_post_meta($post_id, '_alma_ai_agent_media_used', wp_json_encode((array)($clean['media_used'] ?? array()))); update_post_meta($post_id, '_alma_ai_agent_image_ids_used', wp_json_encode($clean['inline_image_ids'])); update_post_meta($post_id, '_alma_ai_agent_featured_image_id', absint($clean['featured_image_id'])); update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_merge((array)$clean['warnings'], (array)($parsed['warnings'] ?? array())))); update_post_meta($post_id, '_alma_ai_seo_title', sanitize_text_field($parsed['seo_title'] ?? '')); update_post_meta($post_id, '_alma_ai_meta_description', sanitize_text_field($parsed['meta_description'] ?? '')); update_post_meta($post_id, '_alma_ai_focus_keyword', sanitize_text_field($parsed['focus_keyword'] ?? '')); update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql')); update_post_meta($post_id, '_alma_ai_suggested_tags', wp_json_encode((array)($parsed['suggested_tags'] ?? array())));
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'estimated_cost'=>$res['estimated_cost'] ?? null,'reference_id'=>'post:'.$post_id));
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

        // Fase 2: blocco geografico esplicito nel payload. Se l'idea attiva ha
        // una località, l'AI deve restare geograficamente coerente.
        $geo_idea_id = absint(get_user_meta($user_id, '_alma_active_idea_id', true));
        if ($geo_idea_id > 0) {
            $geo_label = sanitize_text_field((string) get_post_meta($geo_idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_LABEL, true));
            if ($geo_label !== '') {
                $ctx['geo_context'] = array(
                    'location' => $geo_label,
                    'rules' => 'L\'articolo riguarda la località "' . $geo_label . '". Usa link affiliati e link interni geograficamente coerenti con questa destinazione; non citare né linkare destinazioni diverse, se non per confronti esplicitamente richiesti dal prompt. ECCEZIONE: i link affiliati di tipologia universale (assicurazione viaggio, eSIM, ecc.) valgono per qualsiasi destinazione e vanno inseriti a prescindere dalla località.',
                );
            }
        }
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
                $image = class_exists('ALMA_AI_Content_Agent_Affiliate_Index') ? ALMA_AI_Content_Agent_Affiliate_Index::get_image_data($p->ID) : array();
                $ctx['affiliate_links'][] = array('id'=>$p->ID,'title'=>sanitize_text_field($p->post_title),'description'=>sanitize_text_field($p->post_excerpt),'affiliate_url'=>esc_url_raw((string)get_post_meta($p->ID,'_affiliate_url',true)),'shortcode'=>'[affiliate_link id="'.$p->ID.'"]','ai_context'=>sanitize_textarea_field((string)get_post_meta($p->ID,'_alma_ai_context',true)),'source_id'=>$source_id,'source_name'=>sanitize_text_field($source['name'] ?? ''),'provider'=>sanitize_key($source['provider'] ?? ''),'source_ai_behavior_prompt'=>$source_prompt,'usage_rules'=>'Usa solo shortcode autorizzato; non inventare link affiliati.','featured_image_id'=>absint($image['featured_image_id'] ?? 0),'featured_image_url'=>esc_url_raw((string)($image['featured_image_url'] ?? '')),'featured_image_alt'=>sanitize_text_field((string)($image['featured_image_alt'] ?? '')),'featured_image_caption'=>sanitize_text_field((string)($image['featured_image_caption'] ?? '')),'image_source'=>sanitize_text_field((string)($image['image_source'] ?? '')),'image'=>self::compact_affiliate_image_payload(array('image_url'=>$image['featured_image_url'] ?? '', 'image_alt'=>$image['featured_image_alt'] ?? $p->post_title, 'image_caption'=>$image['featured_image_caption'] ?? '', 'image_source'=>$image['image_source'] ?? ''), (string)$p->post_title));
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
        $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : array();
        $payload = self::build_payload_from_selection_session($user_id);
        if ($profile_id > 0 && !empty($profile)) { $payload['instruction_profile'] = $profile; }
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
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress per output strutturato.', 'user_prompt'=>$prompt.' CONTEXT: '.ALMA_OpenAI_Service::encode_context($ai_payload), 'response_format'=>$response_format, 'json_output'=>true, 'max_output_tokens'=>$max_output_tokens, 'timeout'=>absint(get_option('alma_openai_timeout', 120))));
        if (empty($res['success']) && (($res['error_code'] ?? '') === 'response_format_unsupported' || strpos(strtolower((string)($res['error'] ?? '')), 'response_format') !== false)) {
            $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress per output strutturato.', 'user_prompt'=>$prompt.' CONTEXT: '.ALMA_OpenAI_Service::encode_context($ai_payload), 'json_output'=>true, 'max_output_tokens'=>$max_output_tokens, 'timeout'=>absint(get_option('alma_openai_timeout', 120))));
            $res['response_format_used'] = 'fallback_json_object';
        }
        if (empty($res['success'])) {
            return self::fail(self::map_openai_error_to_admin_message($res), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'api','error_code'=>$res['error_code'] ?? 'openai_error'));
        }
        $parsed = self::parse_ai_json_response((string)($res['response'] ?? ''));
        if (is_wp_error($parsed)) {
            $diag = array('task'=>self::TASK_SELECTION,'model'=>$res['model'] ?? '','error_category'=>'json','error_code'=>$parsed->get_error_code(),'json_error'=>sanitize_text_field((string)$parsed->get_error_data('json_error')),'response_length'=>strlen((string)($res['response'] ?? '')),'response_preview'=>self::sanitize_response_preview((string)($res['response'] ?? ''), 1000),'response_format_used'=>sanitize_text_field((string)($res['response_format_used'] ?? 'none')),'max_output_tokens'=>absint($res['max_output_tokens'] ?? $max_output_tokens));
            ALMA_AI_Usage_Logger::log(array('task'=>self::TASK_SELECTION,'success'=>false,'error'=>'JSON error ['.$diag['error_code'].']: '.$parsed->get_error_message(),'model'=>$res['model'] ?? '','reference_id'=>'session:user:'.$user_id));
            ALMA_Logger::debug('ALMA Draft JSON diagnostic', array('diagnostic' => $diag));
            return self::fail($parsed->get_error_message(), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'json','error_code'=>$parsed->get_error_code()));
        }
        $validated = self::validate_output_contract($parsed);
        if (is_wp_error($validated)) {
            $missing_fields = (array)$validated->get_error_data('missing_fields');
            $diag = array('task'=>self::TASK_SELECTION,'model'=>$res['model'] ?? '','error_category'=>'json_contract','error_code'=>$validated->get_error_code(),'missing_fields'=>$missing_fields,'response_length'=>strlen((string)($res['response'] ?? '')),'response_preview'=>self::sanitize_response_preview((string)($res['response'] ?? ''), 500),'response_format_used'=>sanitize_text_field((string)($res['response_format_used'] ?? 'none')));
            ALMA_Logger::debug('ALMA Draft contract diagnostic', array('diagnostic' => $diag));
            return self::fail($validated->get_error_message(), $res['model'] ?? '', 'session:user:'.$user_id, array('error_category'=>'json_contract','error_code'=>$validated->get_error_code(),'missing_fields'=>$missing_fields));
        }
        $parsed = $validated;
        $parsed['content_html'] = (string)($parsed['content'] ?? '');
        $candidate_affiliate_ids = array_values(array_map('absint', wp_list_pluck((array)$ctx['affiliate_links'], 'id')));
        $featured_candidates = (array)($payload['featured_image_candidates'] ?? array());
        $editorial_media_candidates = (array)($payload['media_candidates'] ?? array());
        $candidate_image_ids = array_values(array_unique(array_map('absint', array_merge(wp_list_pluck((array)$ctx['media'], 'attachment_id'), wp_list_pluck($featured_candidates, 'attachment_id'), wp_list_pluck($editorial_media_candidates, 'attachment_id')))));
        $candidate_affiliate_images = self::candidate_affiliate_images($ctx['affiliate_links']);
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids, $candidate_affiliate_images, (array)($payload['internal_links'] ?? array()), $featured_candidates, $editorial_media_candidates, self::max_editorial_media_used($payload));
        $taxonomy_clean = self::validate_taxonomies($parsed, (array)($payload['category_candidates'] ?? array()), (array)($payload['tag_candidates'] ?? array()));
        $clean['warnings'] = array_values(array_unique(array_merge((array)$clean['warnings'], (array)$taxonomy_clean['warnings'])));
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') { return self::fail('Titolo o contenuto non validi dopo QA.', $res['model'] ?? '', 'session:user:'.$user_id); }

        // Fase 4: widget richiesto dall'AI (creazione istanza reale + shortcode)
        // e enforcement deterministico delle regole di inserimento.
        $ai_widget_id = 0;
        if (class_exists('ALMA_AI_Insertion_Rules')) {
            $widget_request = is_array($parsed['widget_request'] ?? null) ? $parsed['widget_request'] : array();
            $widget_result = ALMA_AI_Insertion_Rules::apply_widget_request($clean['content'], $widget_request, $candidate_affiliate_ids);
            $ai_widget_id = (int) $widget_result['widget_id'];
            $clean['content'] = $widget_result['content'];
            // Garanzia: almeno UN widget per articolo. Se l'AI non lo ha
            // richiesto (o la richiesta era invalida) e il pattern widget è
            // abilitato, se ne crea uno deterministico dai migliori candidati.
            $rules_snapshot = ALMA_AI_Insertion_Rules::get_rules();
            if ($ai_widget_id < 1 && in_array('widget', (array) $rules_snapshot['patterns'], true) && !empty($candidate_affiliate_ids)) {
                $fallback_ids = array_slice(array_map('absint', (array) $candidate_affiliate_ids), 0, 4);
                // Layout scelto in base alla tipologia prevalente dei link
                // (non sempre lo stesso): alloggi/mete → destination_cards,
                // tour/attività → experience_cards, singolo → hero_spotlight.
                $fallback_layout = self::pick_widget_layout_for_links($fallback_ids);
                $fallback_request = array(
                    'title' => __('Le migliori proposte per te', 'affiliate-link-manager-ai'),
                    'layout' => $fallback_layout,
                    'link_ids' => $fallback_ids,
                );
                $fallback_result = ALMA_AI_Insertion_Rules::apply_widget_request($clean['content'], $fallback_request, $candidate_affiliate_ids);
                if ((int) $fallback_result['widget_id'] > 0) {
                    $ai_widget_id = (int) $fallback_result['widget_id'];
                    $clean['content'] = $fallback_result['content'];
                    $widget_result['warnings'][] = 'Widget non richiesto dall\'AI: aggiunto automaticamente con i migliori candidati.';
                }
            }
            $enforced = ALMA_AI_Insertion_Rules::enforce($clean['content'], $rules_snapshot);
            $clean['content'] = $enforced['content'];
            $clean['warnings'] = array_values(array_unique(array_merge((array)$clean['warnings'], (array)$widget_result['warnings'], (array)$enforced['warnings'])));
        }
        // Garanzia di monetizzazione: gli affiliati (es. hotel) mostrati con
        // foto o solo nome/descrizione ma senza link vengono resi cliccabili
        // (immagine o nome in grassetto avvolti nel link affiliato con tracking).
        $img_link_guarantee = self::link_used_affiliate_images((string) $clean['content'], (array) $ctx['affiliate_links']);
        if (!empty($img_link_guarantee['linked'])) {
            $clean['content'] = $img_link_guarantee['content'];
            $clean['warnings'][] = sprintf('%d link affiliati resi cliccabili automaticamente (usati senza link dall\'AI).', count($img_link_guarantee['linked']));
            if (class_exists('ALMA_AI_Image_Generator')) { ALMA_AI_Image_Generator::queue_links($img_link_guarantee['linked']); }
        }
        // Garanzia deterministica del link universale (assicurazioni/eSIM):
        // se l'AI non ne ha inserito uno e il contenuto non lo contiene già,
        // lo aggiunge il sistema come card a ~70% dell'articolo — stessa
        // ricetta dell'arricchimento, così ogni articolo lo propone davvero.
        $universal_guarantee = self::guarantee_universal_link((string) $clean['content']);
        if ($universal_guarantee['added']) {
            $clean['content'] = $universal_guarantee['content'];
            $clean['warnings'][] = 'Link universale (assicurazione/eSIM) aggiunto automaticamente: l\'AI non l\'aveva inserito.';
        }
        // Segnalazione qualità: nessun link interno (utile per SEO e navigazione).
        if (empty($clean['internal_urls_used'])) {
            $clean['warnings'][] = 'Nessun link interno inserito: verifica che esistano articoli correlati indicizzati per questa destinazione.';
        }
        // Difesa: gli URL GYG con parametri di sessione (deeplink_id/page_id
        // dai CSV) non devono entrare negli articoli — l'AI può scrivere
        // href diretti prendendoli dal payload dei candidati.
        if (class_exists('ALMA_Affiliate_Source_GYG_CSV_Importer')) {
            $clean['content'] = ALMA_Affiliate_Source_GYG_CSV_Importer::strip_gyg_session_params_from_content((string) $clean['content']);
        }
        // Pubblicazione diretta opzionale (Impostazioni → Generale): di
        // default resta bozza da revisionare.
        $auto_publish = get_option('alma_ai_auto_publish', 'no') === 'yes';
        $post_status = $auto_publish ? 'publish' : 'draft';
        $post_id = wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>$post_status,'post_author'=>$user_id,'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content'])), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'session:user:'.$user_id); }
        if (!empty($ai_widget_id)) { update_post_meta($post_id, '_alma_ai_agent_widget_id', (int) $ai_widget_id); }
        // I link affiliati usati nell'articolo senza immagine in evidenza vanno
        // nella coda prioritaria di generazione immagini AI (quelli del widget
        // sono già accodati alla creazione del widget).
        if (class_exists('ALMA_AI_Image_Generator') && preg_match_all('/\[affiliate_link[^\]]*\bid="?(\d+)/', (string) $clean['content'], $used_link_matches)) {
            ALMA_AI_Image_Generator::queue_links(array_map('absint', $used_link_matches[1]));
        }
        // Segnaposto [Immagine: …] scritti dal modello: generazione AI in
        // background e sostituzione nel contenuto (max 3 per articolo).
        if (class_exists('ALMA_AI_Image_Generator')) {
            ALMA_AI_Image_Generator::queue_editorial_images($post_id);
        }
        $taxonomy_applied = self::apply_taxonomies_to_post($post_id, $taxonomy_clean);
        $taxonomy_warnings = array_values(array_unique(array_merge((array)$taxonomy_clean['warnings'], (array)$taxonomy_applied['warnings'])));

        update_post_meta($post_id, '_alma_ai_agent_generated', 1);
        update_post_meta($post_id, '_alma_ai_agent_task', self::TASK_SELECTION);
        update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? ''));
        update_post_meta($post_id, '_alma_ai_agent_selected_post_ids', wp_json_encode(wp_list_pluck($ctx['posts'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_link_ids', wp_json_encode(wp_list_pluck($ctx['affiliate_links'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_source_ids', wp_json_encode(array_values(array_unique(array_filter(array_map('absint', wp_list_pluck($ctx['affiliate_links'], 'source_id')))))));
        update_post_meta($post_id, '_alma_ai_agent_selected_document_txt_ids', wp_json_encode(wp_list_pluck($ctx['documents'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_source_online_ids', wp_json_encode(wp_list_pluck($ctx['sources_online'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_media_ids', wp_json_encode($candidate_image_ids));
        update_post_meta($post_id, '_alma_ai_agent_featured_image_candidates', wp_json_encode($featured_candidates));
        update_post_meta($post_id, '_alma_ai_agent_media_candidates', wp_json_encode($editorial_media_candidates));
        // Immagine in evidenza: SOLO quella scelta esplicitamente dall'AI.
        // Il vecchio fallback "prima candidata della Media Library" produceva
        // featured incoerenti (es. Torre Eiffel su un articolo su Dubai):
        // se l'AI non sceglie, l'immagine viene GENERATA dall'AI sul
        // titolo/località dell'articolo (coda editoriale in background).
        $selected_featured_id = absint($clean['featured_image_id'] ?? 0);
        if ($selected_featured_id > 0) {
            set_post_thumbnail($post_id, $selected_featured_id);
        } elseif (class_exists('ALMA_AI_Image_Generator') && ALMA_AI_Image_Generator::is_editorial_enabled()) {
            ALMA_AI_Image_Generator::queue_featured_generation($post_id, (string) $clean['title']);
            $clean['warnings'][] = 'Immagine in evidenza non indicata dall\'AI: verrà generata dall\'AI in background (coda editoriale).';
        } else {
            $clean['warnings'][] = 'Immagine in evidenza non indicata dall\'AI e generazione immagini AI disattivata: bozza senza immagine in evidenza.';
        }
        update_post_meta($post_id, '_alma_ai_agent_selected_featured_image_id', $selected_featured_id);
        if ($selected_featured_id > 0) {
            $selected_featured_url = function_exists('wp_get_attachment_image_url') ? wp_get_attachment_image_url($selected_featured_id, 'full') : '';
            if (!$selected_featured_url && function_exists('wp_get_attachment_url')) { $selected_featured_url = wp_get_attachment_url($selected_featured_id); }
            $selected_featured_source = '';
            foreach (array_merge($featured_candidates, $editorial_media_candidates) as $featured_candidate) {
                if (!is_array($featured_candidate) || absint($featured_candidate['attachment_id'] ?? 0) !== $selected_featured_id) { continue; }
                $selected_featured_source = sanitize_text_field((string)($featured_candidate['source'] ?? ($featured_candidate['filename'] ?? '')));
                break;
            }
            if ($selected_featured_url) { update_post_meta($post_id, '_alma_ai_agent_selected_featured_image_url', esc_url_raw($selected_featured_url)); }
            else { delete_post_meta($post_id, '_alma_ai_agent_selected_featured_image_url'); }
            if ($selected_featured_source !== '') { update_post_meta($post_id, '_alma_ai_agent_selected_featured_image_source', $selected_featured_source); }
            else { delete_post_meta($post_id, '_alma_ai_agent_selected_featured_image_source'); }
        } else {
            delete_post_meta($post_id, '_alma_ai_agent_selected_featured_image_url');
            delete_post_meta($post_id, '_alma_ai_agent_selected_featured_image_source');
        }
        update_post_meta($post_id, '_alma_ai_agent_affiliate_images_available', wp_json_encode($candidate_affiliate_images));
        update_post_meta($post_id, '_alma_ai_agent_affiliate_images_used', wp_json_encode((array)($clean['affiliate_images_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_affiliate_shortcodes_used', wp_json_encode((array)($clean['affiliate_shortcodes_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_affiliate_urls_used', wp_json_encode((array)($clean['affiliate_urls_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_internal_urls_used', wp_json_encode((array)($clean['internal_urls_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_media_used', wp_json_encode((array)($clean['media_used'] ?? array())));
        update_post_meta($post_id, '_alma_ai_agent_media_warnings', wp_json_encode((array)($clean['warnings'] ?? array())));
        update_post_meta($post_id, '_alma_ai_category_ids', wp_json_encode((array)($taxonomy_applied['category_ids'] ?? array())));
        update_post_meta($post_id, '_alma_ai_tag_ids', wp_json_encode((array)($taxonomy_applied['tag_ids'] ?? array())));
        update_post_meta($post_id, '_alma_ai_new_tags', wp_json_encode((array)($taxonomy_applied['new_tags'] ?? array())));
        update_post_meta($post_id, '_alma_ai_taxonomy_warnings', wp_json_encode($taxonomy_warnings));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($session['instruction_profile_id'] ?? ($profile['id'] ?? 0)));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_name', sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')));
        update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($session['instruction_snapshot_hash'] ?? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash(wp_json_encode($profile))));
        update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], $taxonomy_warnings, (array)($parsed['warnings'] ?? array()))))));
        update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql'));

        // Meta title/description per ricerca e social via All in One SEO.
        if (class_exists('ALMA_AI_Seo_Bridge')) {
            $seo_status = ALMA_AI_Seo_Bridge::apply($post_id, (string)($clean['seo_title'] ?? ''), (string)($clean['seo_description'] ?? ''));
            if ($seo_status === 'meta_only') {
                $clean['warnings'][] = 'All in One SEO non rilevato: meta title/description salvati solo nei meta del plugin.';
            }
        }
        $active_idea_id = absint(get_user_meta($user_id, '_alma_active_idea_id', true));
        if ($active_idea_id > 0) {
            update_post_meta($post_id, '_alma_ai_agent_idea_id', $active_idea_id);
            update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, current_time('mysql'));
            update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_DRAFT_POST_ID, $post_id);
        }
        ALMA_AI_Content_Agent_Result_Usage::increment_for_results($selected, $post_id);
        ALMA_AI_Usage_Logger::log(array('task'=>self::TASK_SELECTION,'success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'reference_id'=>'post:'.$post_id));
        // Regia Telegram: la nuova bozza arriva in chat con i pulsanti di revisione.
        if (class_exists('ALMA_Telegram_Bot')) { ALMA_Telegram_Bot::notify_draft_created($post_id, $post_status); }
        return array(
            'success'=>true,
            'post_id'=>$post_id,
            'title'=>$clean['title'],
            'edit_url'=>get_edit_post_link($post_id, 'raw'),
            'preview_url'=>get_preview_post_link($post_id),
            'warnings'=>array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], $taxonomy_warnings, (array)($parsed['warnings'] ?? array())))),
            'model'=>$res['model'] ?? '',
            'usage'=>$res['usage'] ?? array(),
            'summary'=>array(
                'status'=>$post_status,
                'instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')),
                'source_counts'=>array(
                    'post'=>count((array)$ctx['posts']),
                    'page'=>count((array)$ctx['pages']),
                    'affiliate_link'=>count((array)$ctx['affiliate_links']),
                    'document_txt'=>count((array)$ctx['documents']),
                    'source_online'=>count((array)$ctx['sources_online']),
                    'media'=>count((array)$ctx['media']),
                    'internal_link'=>count((array)($payload['internal_links'] ?? array())),
                    'category_candidate'=>count((array)($payload['category_candidates'] ?? array())),
                    'tag_candidate'=>count((array)($payload['tag_candidates'] ?? array())),
                ),
                'taxonomies'=>array(
                    'category_ids'=>(array)($taxonomy_applied['category_ids'] ?? array()),
                    'tag_ids'=>(array)($taxonomy_applied['tag_ids'] ?? array()),
                    'new_tags'=>(array)($taxonomy_applied['new_tags'] ?? array()),
                    'warnings'=>$taxonomy_warnings,
                ),
                'affiliate_images'=>array(
                    'candidates'=>count($candidate_affiliate_images),
                    'used'=>count((array)($clean['affiliate_images_used'] ?? array())),
                    'discarded'=>max(0, count($candidate_affiliate_images)-count((array)($clean['affiliate_images_used'] ?? array()))),
                    'warning'=>empty($candidate_affiliate_images)?'Nessun link affiliato selezionato aveva immagini disponibili.':'',
                ),
            ),
        );
    }
}
