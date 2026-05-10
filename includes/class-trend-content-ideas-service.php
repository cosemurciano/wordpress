<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Service {
    const CRON_HOOK = 'alma_trend_content_ideas_cron';
    const LOCK_KEY = 'alma_trend_content_ideas_lock';
    const WEB_SEARCH_TOOL = 'web_search';
    const MAX_ALLOWED_DOMAINS = 20;
    const FALLBACK_MODEL = 'gpt-5.4-mini';
    const LEGACY_SEEDED_MODEL = 'gpt-5.5';
    const LEGACY_MODEL_WARNING = 'Il modello Trend gpt-5.5 salvato da una versione precedente è stato ignorato: viene usato il modello globale OpenAI.';
    const TIMEOUT_RETRY_WARNING = 'Retry OpenAI alleggerito dopo timeout della ricerca web.';
    const JSON_RETRY_WARNING = 'Retry OpenAI eseguito perché la risposta precedente non era JSON valido.';
    const TRUNCATED_OUTPUT_WARNING = 'La risposta AI è stata troncata per limite max_output_tokens.';
    const TRUNCATED_OUTPUT_RETRY_WARNING = 'Retry OpenAI compatto eseguito dopo output troncato.';

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_due_sources'));
        if (!wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK); }
    }

    public static function is_openai_ready() { return trim((string)get_option('alma_openai_api_key', '')) !== ''; }

    public static function run_due_sources() {
        $sources = ALMA_Trend_Content_Ideas_Store::get_due_sources();
        if (!$sources) { return array('status'=>'empty','message'=>'Nessuna fonte scaduta.'); }
        return self::run_sources($sources, 'scheduled');
    }

    public static function run_source($source_key, $run_type = 'test') {
        $src = ALMA_Trend_Content_Ideas_Store::get_source($source_key);
        if (!$src) { return new WP_Error('missing_source', __('Fonte non trovata.', 'affiliate-link-manager-ai')); }
        return self::run_sources(array($src), $run_type);
    }

    public static function run_enabled($run_type = 'manual') {
        $sources = ALMA_Trend_Content_Ideas_Store::get_sources(true);
        if (!$sources) { return new WP_Error('no_sources', __('Nessuna fonte abilitata.', 'affiliate-link-manager-ai')); }
        return self::run_sources($sources, $run_type);
    }

    private static function run_sources($sources, $run_type) {
        $sources = self::normalize_sources($sources);
        if (get_transient(self::LOCK_KEY)) { return new WP_Error('locked', __('Un’analisi trend è già in corso. Riprova tra qualche minuto.', 'affiliate-link-manager-ai')); }
        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);
        $started = current_time('mysql'); $start_time = microtime(true);
        try {
            if (!self::is_openai_ready()) { return self::store_error_report($sources, $run_type, 'OpenAI non configurato.', $started, 'openai_missing'); }
            $request = self::request_openai_with_fallback($sources, $run_type);
            $res = $request['response'];
            $attempts = $request['attempts'];
            $warnings = $request['warnings'];
            $runtime = $request['runtime'];
            if (empty($res['success'])) {
                $friendly = self::friendly_openai_error($res, $attempts, $runtime);
                return self::store_error_report($sources, $run_type, $friendly, $started, wp_json_encode(array('attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>$runtime,'error'=>$res)), $res['model'] ?? '', $runtime, $warnings);
            }
            $parsed = self::parse_openai_json_response($res, end($attempts));
            if (is_wp_error($parsed)) {
                $retry = self::retry_invalid_json_response($sources, $run_type, $request, $parsed);
                if (!empty($retry['executed'])) {
                    $res = $retry['response'];
                    $attempts = $retry['attempts'];
                    $warnings = array_values(array_unique(array_merge($warnings, $retry['warnings'])));
                    $runtime = self::finalize_runtime($runtime, $attempts);
                    $parsed = !empty($res['success']) ? self::parse_openai_json_response($res, end($attempts)) : new WP_Error('openai_retry_error', self::friendly_openai_error($res, $attempts, $runtime), self::json_error_report($res, end($attempts), 'api_error'));
                }
                if (is_wp_error($parsed)) {
                    $report = $parsed->get_error_data();
                    $message = self::json_error_user_message($report, $warnings);
                    return self::store_error_report($sources, $run_type, $message, $started, wp_json_encode(array('attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>$runtime,'json_error'=>$report)), $res['model'] ?? '', $runtime, $warnings);
                }
            }
            $data = $parsed;
            $data = self::augment_result_sources($data, $res, $warnings, $runtime, $attempts);
            $metrics = self::build_metrics($data, $sources);
            $status = self::is_partial($data) ? 'partial' : 'success';
            $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array(
                'report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>gmdate('Y-m-d H:i:s', current_time('timestamp') - 90 * DAY_IN_SECONDS),'period_end'=>current_time('mysql'),'status'=>$status,'summary'=>$data['sintesi_generale'] ?? '',
                'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>$metrics,'model'=>$res['model'] ?? self::model(),'tokens_used'=>self::tokens_used($res['usage'] ?? null),
            ));
            $log_raw = wp_json_encode(array('attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>$runtime,'sources_count'=>count($data['fonti_web_search'] ?? array())));
            foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], true); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, $status, 'Analisi completata in ' . round(microtime(true)-$start_time, 1) . 's. Domini normalizzati: ' . implode(', ', self::allowed_domains(array($src))), $log_raw, $report_id, $started); }
            return array('success'=>true,'status'=>$status,'report_id'=>$report_id,'sources_count'=>count($sources),'duration'=>round(microtime(true)-$start_time,1),'sources'=>wp_list_pluck($sources, 'name'));
        } finally { delete_transient(self::LOCK_KEY); }
    }

    private static function store_error_report($sources, $run_type, $message, $started, $raw='', $model='', $runtime=array(), $warnings=array()) {
        $data = array('status'=>'error','summary'=>$message,'trends'=>array(),'content_ideas'=>array(),'citations'=>array(),'warnings'=>array_values(array_unique(array_merge(array($message), (array)$warnings))),'sintesi_generale'=>$message,'fonti_analizzate'=>array(),'destinazioni_prioritarie'=>array(),'temi_editoriali'=>array(),'piano_editoriale_settimanale'=>array(),'opportunita_affiliate'=>array(),'bisogni_viaggiatori'=>array(),'rischi_e_limiti'=>array(array('messaggio'=>$message)),'dati_per_grafici'=>array(),'livello_confidenza'=>'basso','alert'=>array_values(array_unique(array_merge(array($message), (array)$warnings))),'fonti_citate'=>array(),'fonti_web_search'=>array(),'errore_tecnico'=>$raw,'runtime'=>self::runtime_report($runtime, array()));
        $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array('report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>$started,'period_end'=>current_time('mysql'),'status'=>'error','summary'=>$message,'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>self::build_metrics($data,$sources),'model'=>$model ?: ($runtime['effective_model'] ?? self::model())));
        foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], false); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, 'error', $message, $raw, $report_id, $started); }
        return new WP_Error('trend_run_error', $message, array('report_id'=>$report_id));
    }

    public static function validate_result($data, $schema_profile = 'editorial_plan') {
        $is_compact = in_array($schema_profile, array('source_test','json_invalid_retry'), true);
        $required = $is_compact ? array('status','summary','source_quality','trends','content_ideas','citations','warnings') : array('status','summary','trends','content_ideas','citations','warnings','sintesi_generale','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','dati_per_grafici','livello_confidenza','alert','fonti_citate');
        if (!is_array($data)) { return new WP_Error('invalid_json', __('JSON OpenAI non valido.', 'affiliate-link-manager-ai')); }
        foreach ($required as $key) { if (!array_key_exists($key, $data)) { return new WP_Error('incomplete_json', sprintf(__('JSON incompleto: manca %s.', 'affiliate-link-manager-ai'), $key)); } }
        $array_keys = $is_compact ? array('trends','content_ideas','citations','warnings') : array('trends','content_ideas','citations','warnings','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','alert','fonti_citate');
        foreach ($array_keys as $key) { if (!is_array($data[$key])) { return new WP_Error('incomplete_json', sprintf(__('JSON incompleto: %s deve essere un array.', 'affiliate-link-manager-ai'), $key)); } }
        return true;
    }

    public static function parse_openai_json_response($res, $attempt=array()) {
        $text = self::extract_response_text($res);
        if (trim($text) === '') { return new WP_Error('empty_response', __('Risposta OpenAI vuota.', 'affiliate-link-manager-ai'), self::json_error_report($res, $attempt, 'empty_response')); }
        $decoded = json_decode($text, true);
        $json_error = json_last_error_msg();
        if (!is_array($decoded)) {
            $stripped = self::strip_json_markdown_wrapper($text);
            if ($stripped !== $text) { $decoded = json_decode($stripped, true); $json_error = json_last_error_msg(); $text = $stripped; }
        }
        if (!is_array($decoded)) {
            $category = self::looks_truncated($res, $text) ? 'truncated_output' : 'malformed_json';
            return new WP_Error('invalid_json', __('JSON OpenAI non valido.', 'affiliate-link-manager-ai'), self::json_error_report($res, $attempt, $category, $text, $json_error));
        }
        $schema_profile = sanitize_key((string)($attempt['schema_profile'] ?? 'editorial_plan'));
        $valid = self::validate_result($decoded, $schema_profile);
        if (is_wp_error($valid)) { return new WP_Error($valid->get_error_code(), $valid->get_error_message(), self::json_error_report($res, $attempt, 'incomplete_schema', $text, $valid->get_error_message())); }
        return self::normalize_result_for_storage($decoded, $schema_profile);
    }

    private static function extract_response_text($res) {
        if (!empty($res['response'])) { return (string)$res['response']; }
        $raw = $res['raw_response'] ?? array();
        if (!is_array($raw)) { return ''; }
        if (!empty($raw['output_text'])) { return (string)$raw['output_text']; }
        $text = '';
        foreach ((array)($raw['output'] ?? array()) as $out) {
            foreach ((array)($out['content'] ?? array()) as $content) {
                if (isset($content['text']) && (($content['type'] ?? '') === 'output_text' || is_string($content['text']))) { $text .= (string)$content['text']; }
            }
        }
        return $text;
    }

    private static function strip_json_markdown_wrapper($text) {
        $trimmed = trim((string)$text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $m)) { return trim($m[1]); }
        if (preg_match('/(\{.*\})/s', $trimmed, $m)) { return trim($m[1]); }
        return $trimmed;
    }

    private static function looks_truncated($res, $text) {
        $raw = $res['raw_response'] ?? array();
        $status = strtolower((string)($raw['status'] ?? $res['status'] ?? ''));
        $reason = strtolower((string)($raw['incomplete_details']['reason'] ?? $raw['finish_reason'] ?? $res['finish_reason'] ?? ''));
        return strpos($status, 'incomplete') !== false || strpos($reason, 'max_output_tokens') !== false || strpos($reason, 'token') !== false || strpos($reason, 'length') !== false || (trim((string)$text) !== '' && !preg_match('/[}\]]\s*$/', trim((string)$text)));
    }

    private static function json_error_report($res, $attempt=array(), $category='malformed_json', $text='', $warning='') {
        $raw = $res['raw_response'] ?? array();
        if ($category !== 'truncated_output' && self::looks_truncated($res, $text !== '' ? $text : self::extract_response_text($res))) { $category = 'truncated_output'; }
        return array(
            'category'=>sanitize_key($category),
            'warning'=>sanitize_text_field((string)$warning),
            'raw_excerpt'=>self::safe_raw_excerpt($text !== '' ? $text : self::extract_response_text($res), 1500),
            'model'=>sanitize_text_field((string)($res['model'] ?? ($raw['model'] ?? ''))),
            'response_id'=>sanitize_text_field((string)($raw['id'] ?? '')),
            'status'=>sanitize_text_field((string)($raw['status'] ?? ($res['status'] ?? ''))),
            'finish_reason'=>sanitize_text_field((string)($raw['incomplete_details']['reason'] ?? $raw['finish_reason'] ?? ($res['finish_reason'] ?? ''))),
            'attempt_label'=>sanitize_text_field((string)($attempt['label'] ?? '')),
            'max_output_tokens'=>absint($attempt['max_output_tokens'] ?? ($res['max_output_tokens'] ?? 0)),
            'tool_choice'=>sanitize_text_field((string)($attempt['tool_choice'] ?? '')),
            'include'=>array_values(array_map('sanitize_text_field', (array)($attempt['include'] ?? array()))),
            'profile'=>sanitize_key((string)($attempt['profile'] ?? '')),
            'response_format_used'=>sanitize_text_field((string)($res['response_format_used'] ?? '')),
            'suggestion'=>self::json_error_suggestion($category),
        );
    }

    private static function safe_raw_excerpt($text, $limit=1500) {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', (string)$text);
        $text = preg_replace('/sk-[A-Za-z0-9_\-]+/i', 'sk-[redacted]', $text);
        $text = wp_strip_all_tags($text);
        return mb_substr($text, 0, max(1, absint($limit)));
    }


    private static function normalize_result_for_storage($data, $schema_profile = 'editorial_plan') {
        if (!in_array($schema_profile, array('source_test','json_invalid_retry'), true)) { return $data; }
        $data['sintesi_generale'] = $data['sintesi_generale'] ?? ($data['summary'] ?? '');
        $data['fonti_analizzate'] = $data['fonti_analizzate'] ?? array();
        $data['destinazioni_prioritarie'] = $data['destinazioni_prioritarie'] ?? self::compact_trends_to_destinations($data['trends'] ?? array());
        $data['temi_editoriali'] = $data['temi_editoriali'] ?? array();
        $data['piano_editoriale_settimanale'] = $data['piano_editoriale_settimanale'] ?? self::compact_ideas_to_plan($data['content_ideas'] ?? array());
        $data['opportunita_affiliate'] = $data['opportunita_affiliate'] ?? array();
        $data['bisogni_viaggiatori'] = $data['bisogni_viaggiatori'] ?? array();
        $data['rischi_e_limiti'] = $data['rischi_e_limiti'] ?? array();
        $data['dati_per_grafici'] = $data['dati_per_grafici'] ?? array();
        $data['livello_confidenza'] = $data['livello_confidenza'] ?? '';
        $data['alert'] = $data['alert'] ?? ($data['warnings'] ?? array());
        $data['fonti_citate'] = $data['fonti_citate'] ?? self::compact_citations_to_fonti_citate($data['citations'] ?? array());
        return $data;
    }

    private static function compact_trends_to_destinations($trends) {
        $out = array();
        foreach ((array)$trends as $trend) {
            if (!is_array($trend)) { continue; }
            $out[] = array(
                'nome'=>sanitize_text_field((string)($trend['title'] ?? '')),
                'paese_o_area'=>'',
                'trend_score'=>0,
                'confidence_score'=>0,
                'motivazione'=>sanitize_text_field((string)($trend['description'] ?? '')),
            );
        }
        return $out;
    }

    private static function compact_ideas_to_plan($ideas) {
        $out = array();
        foreach ((array)$ideas as $idea) {
            if (!is_array($idea)) { continue; }
            $out[] = array(
                'titolo'=>sanitize_text_field((string)($idea['title'] ?? '')),
                'motivazione_trend'=>sanitize_text_field((string)($idea['description'] ?? '')),
                'intento_ricerca'=>sanitize_text_field((string)($idea['intent'] ?? '')),
            );
        }
        return $out;
    }

    private static function compact_citations_to_fonti_citate($citations) {
        $out = array();
        foreach ((array)$citations as $citation) {
            if (!is_array($citation)) { continue; }
            $out[] = array(
                'titolo'=>sanitize_text_field((string)($citation['title'] ?? '')),
                'url'=>esc_url_raw((string)($citation['url'] ?? '')),
                'fonte'=>sanitize_text_field((string)($citation['source'] ?? '')),
            );
        }
        return $out;
    }

    private static function is_truncated_error_report($report) {
        if (!is_array($report)) { return false; }
        return sanitize_key((string)($report['category'] ?? '')) === 'truncated_output'
            || stripos((string)($report['status'] ?? ''), 'incomplete') !== false
            || stripos((string)($report['finish_reason'] ?? ''), 'max_output_tokens') !== false;
    }

    private static function json_error_user_message($report, $warnings = array()) {
        if (self::is_truncated_error_report($report) || in_array(self::TRUNCATED_OUTPUT_WARNING, (array)$warnings, true)) {
            return __('La risposta AI è stata troncata perché troppo lunga per il limite token configurato. È stato tentato un retry compatto.', 'affiliate-link-manager-ai');
        }
        return __('La risposta AI non era un JSON valido. Controlla il report tecnico per dettagli.', 'affiliate-link-manager-ai');
    }

    private static function json_error_suggestion($category) {
        if (sanitize_key((string)$category) !== 'truncated_output') { return ''; }
        return __('Per ridurre il rischio di troncamento, abbassa la quantità contenuti della fonte, usa un modello più leggero o aumenta leggermente max output token.', 'affiliate-link-manager-ai');
    }

    public static function normalize_allowed_domains($domains, $limit = self::MAX_ALLOWED_DOMAINS) {
        $normalized = array();
        foreach ((array)$domains as $domain) {
            $domain = trim((string)$domain);
            if ($domain === '') { continue; }
            $domain = preg_replace('/^https?:\/\//i', '', $domain);
            $domain = preg_replace('/^[\/]+/', '', $domain);
            $domain = preg_split('/[\/?#]/', $domain, 2)[0];
            $domain = rtrim(strtolower(trim($domain)), '.: /');
            if (strpos($domain, '@') !== false) { $domain = substr(strrchr($domain, '@'), 1); }
            if ($domain === '' || strpos($domain, ' ') !== false) { continue; }
            $normalized[$domain] = $domain;
            if (count($normalized) >= max(1, absint($limit))) { break; }
        }
        foreach (array_keys($normalized) as $domain) {
            if (strpos($domain, 'www.') === 0) {
                $without_www = substr($domain, 4);
                if (isset($normalized[$without_www])) { unset($normalized[$domain]); }
            }
        }
        return array_values($normalized);
    }

    private static function allowed_domains($sources) { $out=array(); foreach($sources as $src){ $out=array_merge($out, ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains'] ?? '[]')); } return self::normalize_allowed_domains($out); }

    private static function normalize_sources($sources) { return array_map(function($s){ $s['priority'] = ALMA_Trend_Content_Ideas_Store::normalize_priority($s['priority'] ?? 2); $s['max_contents_per_run'] = ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($s['max_contents_per_run'] ?? 3); return $s; }, (array)$sources); }

    public static function build_web_search_tool($domains = array(), $search_context_size = 'medium') {
        $tool = array('type'=>self::WEB_SEARCH_TOOL,'search_context_size'=>sanitize_key($search_context_size));
        $domains = self::normalize_allowed_domains($domains);
        if ($domains) { $tool['filters'] = array('allowed_domains'=>$domains); }
        return $tool;
    }

    public static function runtime_profile($run_type, $sources = array()) {
        if ($run_type === 'test' && count((array)$sources) <= 1) {
            return array('name'=>'source_test','max_output_tokens'=>2200,'retry_max_output_tokens'=>1600,'search_context_size'=>'low','include_sources'=>true,'tool_choice'=>'required');
        }
        if ($run_type === 'test') {
            return array('name'=>'full_test','max_output_tokens'=>3800,'retry_max_output_tokens'=>1800,'search_context_size'=>'medium','include_sources'=>true,'tool_choice'=>'required');
        }
        return array('name'=>'editorial_plan','max_output_tokens'=>5200,'retry_max_output_tokens'=>2200,'search_context_size'=>'medium','include_sources'=>true,'tool_choice'=>'required');
    }

    public static function build_openai_request_args($sources, $run_type = 'manual', $with_filters = true, $tool_choice = 'required', $profile = null, $include_sources = null) {
        $profile = is_array($profile) ? $profile : self::runtime_profile($run_type, $sources);
        $domains = $with_filters ? self::allowed_domains($sources) : array();
        $include_sources = ($include_sources === null) ? !empty($profile['include_sources']) : (bool)$include_sources;
        $args = array(
            'model'=>self::model(),
            'system_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::system_prompt(),
            'user_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::build($sources, $run_type),
            'response_format'=>ALMA_Trend_Content_Ideas_Prompt_Builder::response_schema($profile['name'] ?? 'editorial_plan'),
            'max_output_tokens'=>absint($profile['max_output_tokens'] ?? 3500),
            'timeout'=>absint(get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90)),
            'tools'=>array(self::build_web_search_tool($domains, $profile['search_context_size'] ?? 'medium')),
            'tool_choice'=>$tool_choice,
            'runtime_profile'=>sanitize_key((string)($profile['name'] ?? 'editorial_plan')),
        );
        if ($include_sources) { $args['include'] = array('web_search_call.action.sources'); }
        return $args;
    }

    private static function request_openai_with_fallback($sources, $run_type) {
        $attempts = array(); $warnings = array(); $timeout_retry_used = false;
        $profile = self::runtime_profile($run_type, $sources);
        $model_details = self::effective_model_details();
        if (!empty($model_details['legacy_ignored'])) { $warnings[] = self::LEGACY_MODEL_WARNING; }
        $runtime = array_merge($model_details, array('profile'=>$profile['name'],'timeout_retry_used'=>false,'web_search_sources_include_omitted_for_timeout'=>false));
        $args = self::build_openai_request_args($sources, $run_type, true, $profile['tool_choice'] ?? 'required', $profile);
        $attempts[] = self::attempt_summary('primary', $args);
        $res = ALMA_OpenAI_Service::request($args);
        $warnings = self::merge_openai_warnings($warnings, $res);
        if (!empty($res['success'])) { return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>self::finalize_runtime($runtime, $attempts),'last_args'=>$args); }
        if (self::is_timeout_or_connection_error($res, $args)) {
            $retry = self::build_light_timeout_retry_args($args, $profile);
            $warnings[] = self::TIMEOUT_RETRY_WARNING;
            $attempts[] = self::attempt_summary('timeout_light_retry', $retry);
            $timeout_retry_used = true;
            $runtime['timeout_retry_used'] = true;
            $runtime['web_search_sources_include_omitted_for_timeout'] = empty($retry['include']);
            $res = ALMA_OpenAI_Service::request($retry);
            $warnings = self::merge_openai_warnings($warnings, $res);
            if (!empty($res['success'])) { return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>self::finalize_runtime($runtime, $attempts),'last_args'=>$retry); }
            $args = $retry;
        }
        if (self::is_tool_choice_unsupported($res)) {
            $warnings[] = 'OpenAI non ha accettato tool_choice required. La ricerca è stata ripetuta con tool_choice auto.';
            $args['tool_choice'] = 'auto';
            $attempts[] = self::attempt_summary('tool_choice_auto', $args);
            $res = ALMA_OpenAI_Service::request($args);
            $warnings = self::merge_openai_warnings($warnings, $res);
            if (!empty($res['success'])) { return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>self::finalize_runtime($runtime, $attempts),'last_args'=>$args); }
        }
        if (self::is_filters_unsupported_error($res) && !empty($args['tools'][0]['filters'])) {
            $warnings[] = 'Il filtro domini non è stato accettato dalla chiamata OpenAI. La ricerca è stata ripetuta senza filtro dominio.';
            $args = self::build_openai_request_args($sources, $run_type, false, $args['tool_choice'] ?? 'required', $profile, !empty($args['include']));
            $attempts[] = self::attempt_summary('fallback_without_filters', $args);
            $res = ALMA_OpenAI_Service::request($args);
            $warnings = self::merge_openai_warnings($warnings, $res);
        }
        if (!$timeout_retry_used && self::is_timeout_or_connection_error($res, $args)) {
            $retry = self::build_light_timeout_retry_args($args, $profile);
            $warnings[] = self::TIMEOUT_RETRY_WARNING;
            $attempts[] = self::attempt_summary('timeout_light_retry', $retry);
            $runtime['timeout_retry_used'] = true;
            $runtime['web_search_sources_include_omitted_for_timeout'] = empty($retry['include']);
            $res = ALMA_OpenAI_Service::request($retry);
            $warnings = self::merge_openai_warnings($warnings, $res);
            $args = $retry;
        }
        return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings,'runtime'=>self::finalize_runtime($runtime, $attempts),'last_args'=>$args);
    }

    private static function retry_invalid_json_response($sources, $run_type, $request, $parsed_error) {
        $base = is_array($request['last_args'] ?? null) ? $request['last_args'] : self::build_openai_request_args($sources, $run_type);
        $retry = $base;
        $is_truncated = self::is_truncated_error_report($parsed_error instanceof WP_Error ? $parsed_error->get_error_data() : array());
        $retry['system_prompt'] = ALMA_Trend_Content_Ideas_Prompt_Builder::system_prompt() . ' ' . ($is_truncated ? self::TRUNCATED_OUTPUT_RETRY_WARNING : self::JSON_RETRY_WARNING) . ' Rispondi solo con JSON valido compatto conforme allo schema source_test, senza markdown o testo esterno.';
        $retry['user_prompt'] = ALMA_Trend_Content_Ideas_Prompt_Builder::build_json_retry($sources, $run_type);
        $retry['response_format'] = ALMA_Trend_Content_Ideas_Prompt_Builder::response_schema('json_invalid_retry');
        $retry['tool_choice'] = 'auto';
        $retry['runtime_profile'] = 'json_invalid_retry';
        $retry['max_output_tokens'] = max(1400, min(absint($base['max_output_tokens'] ?? 2200), 2200));
        unset($retry['include']);
        $attempts = (array)($request['attempts'] ?? array());
        $attempts[] = self::attempt_summary('json_invalid_retry', $retry);
        $retry_warning = $is_truncated ? self::TRUNCATED_OUTPUT_RETRY_WARNING : self::JSON_RETRY_WARNING;
        $warning_set = $is_truncated ? array(self::TRUNCATED_OUTPUT_WARNING, $retry_warning) : array($retry_warning);
        $warnings = array_values(array_unique(array_merge((array)($request['warnings'] ?? array()), $warning_set)));
        $res = ALMA_OpenAI_Service::request($retry);
        $warnings = self::merge_openai_warnings($warnings, $res);
        return array('executed'=>true,'response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings);
    }

    private static function build_light_timeout_retry_args($args, $profile) {
        $args['tool_choice'] = 'auto';
        $args['max_output_tokens'] = max(600, absint($profile['retry_max_output_tokens'] ?? floor(absint($args['max_output_tokens'] ?? 3000) / 2)));
        unset($args['include']);
        return $args;
    }

    public static function is_timeout_or_connection_error($res, $args = array()) {
        $code = sanitize_key((string)($res['error_code'] ?? ''));
        $message = strtolower((string)($res['error'] ?? ''));
        $response_time = absint($res['response_time'] ?? 0);
        $timeout_ms = absint($args['timeout'] ?? get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90)) * 1000;
        if ($code === 'api_connection_error' || $code === 'timeout') { return true; }
        if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false || strpos($message, 'errore connessione ai') !== false) { return true; }
        return $timeout_ms > 0 && $response_time >= max(1000, $timeout_ms - 2500);
    }

    private static function attempt_summary($label, $args) { return array('label'=>$label,'tool'=>$args['tools'][0]['type'] ?? '','tool_choice'=>$args['tool_choice'] ?? '','allowed_domains'=>$args['tools'][0]['filters']['allowed_domains'] ?? array(),'include'=>$args['include'] ?? array(),'max_output_tokens'=>absint($args['max_output_tokens'] ?? 0),'sources_include_enabled'=>!empty($args['include']),'schema_profile'=>$label === 'json_invalid_retry' ? 'json_invalid_retry' : sanitize_key((string)($args['runtime_profile'] ?? 'editorial_plan')),'profile'=>sanitize_key((string)($args['runtime_profile'] ?? ''))); }
    private static function merge_openai_warnings($warnings, $res) { foreach ((array)($res['warnings'] ?? array()) as $warning) { $warnings[] = sanitize_text_field((string)$warning); } return array_values(array_unique(array_filter($warnings))); }
    private static function is_filters_unsupported_error($res) { $m = strtolower((string)($res['error'] ?? '')); return strpos($m, 'filters') !== false && (strpos($m, 'unsupported parameter') !== false || strpos($m, 'not supported') !== false || strpos($m, 'unknown parameter') !== false); }
    private static function is_tool_choice_unsupported($res) { $m = strtolower((string)($res['error'] ?? '')); return strpos($m, 'tool_choice') !== false && (strpos($m, 'unsupported') !== false || strpos($m, 'not supported') !== false); }
    private static function friendly_openai_error($res, $attempts=array(), $runtime=array()) { $message = is_array($res) ? ($res['error'] ?? 'Errore OpenAI') : $res; if (self::is_timeout_or_connection_error(is_array($res) ? $res : array('error'=>$message))) { $retry = !empty($runtime['timeout_retry_used']) ? ' È stato tentato un retry alleggerito.' : ''; return sprintf(__('La chiamata OpenAI è andata in timeout o ha avuto un errore di connessione.%s Modello effettivo usato: %s. Dettaglio tecnico disponibile nel report.', 'affiliate-link-manager-ai'), $retry, sanitize_text_field((string)($runtime['effective_model'] ?? self::model()))); } if (self::is_filters_unsupported_error(array('error'=>$message))) { return __('Errore OpenAI: il parametro filters non è supportato dal tool web search usato. Aggiorna la configurazione o verifica il tool OpenAI.', 'affiliate-link-manager-ai'); } if (self::is_sampling_unsupported_error(array('error'=>$message))) { return __('Errore OpenAI: il modello selezionato non supporta uno o più parametri sampling. Il dettaglio tecnico è disponibile nel report.', 'affiliate-link-manager-ai'); } return sprintf(__('Errore OpenAI: %s', 'affiliate-link-manager-ai'), sanitize_text_field((string)$message)); }
    private static function is_sampling_unsupported_error($res) { $m = strtolower((string)($res['error'] ?? '')); if (strpos($m, 'unsupported parameter') === false && strpos($m, 'not supported') === false && strpos($m, 'unknown parameter') === false) { return false; } foreach (array('temperature','top_p','presence_penalty','frequency_penalty') as $key) { if (strpos($m, $key) !== false) { return true; } } return false; }
    private static function source_snapshot($sources) { return array_map(function($s){ return array('source_key'=>$s['source_key'],'name'=>$s['name'],'priority'=>ALMA_Trend_Content_Ideas_Store::normalize_priority($s['priority'] ?? 2),'max_contents_per_run'=>ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($s['max_contents_per_run'] ?? 3),'category'=>$s['category'],'allowed_domains'=>ALMA_Trend_Content_Ideas_Store::decode_json($s['allowed_domains']),'normalized_allowed_domains'=>self::normalize_allowed_domains(ALMA_Trend_Content_Ideas_Store::decode_json($s['allowed_domains']))); }, self::normalize_sources($sources)); }
    private static function title_for($run_type, $sources) { return ($run_type === 'test' ? 'Test Trend Idee contenuto' : 'Report Trend Idee contenuto') . ' - ' . count($sources) . ' fonti'; }
    public static function effective_model() { $details = self::effective_model_details(); return $details['effective_model']; }
    public static function effective_model_details() { $trend_model = trim((string)get_option(ALMA_Trend_Content_Ideas_Store::OPTION_MODEL, '')); $global_model = trim((string)get_option('alma_openai_model', '')); $manual = get_option(ALMA_Trend_Content_Ideas_Store::OPTION_MODEL_MANUAL, '') === '1'; $legacy_ignored = ($trend_model === self::LEGACY_SEEDED_MODEL && !$manual); if ($legacy_ignored) { return array('trend_model_saved'=>$trend_model,'global_model'=>$global_model,'effective_model'=>$global_model !== '' ? $global_model : self::FALLBACK_MODEL,'using_global_model'=>$global_model !== '','using_fallback'=>$global_model === '','legacy_ignored'=>true,'legacy_warning'=>self::LEGACY_MODEL_WARNING); } if ($trend_model !== '') { return array('trend_model_saved'=>$trend_model,'global_model'=>$global_model,'effective_model'=>$trend_model,'using_global_model'=>false,'using_fallback'=>false,'legacy_ignored'=>false,'legacy_warning'=>''); } return array('trend_model_saved'=>$trend_model,'global_model'=>$global_model,'effective_model'=>$global_model !== '' ? $global_model : self::FALLBACK_MODEL,'using_global_model'=>$global_model !== '','using_fallback'=>$global_model === '','legacy_ignored'=>false,'legacy_warning'=>''); }
    private static function model() { return self::effective_model(); }
    private static function tokens_used($usage) { return is_array($usage) && isset($usage['total_tokens']) ? absint($usage['total_tokens']) : null; }
    private static function is_partial($data) { return !empty($data['alert']) || stripos((string)($data['livello_confidenza'] ?? ''), 'basso') !== false; }

    private static function augment_result_sources($data, $res, $warnings, $runtime=array(), $attempts=array()) {
        $sources = self::extract_web_search_sources($res['raw_response'] ?? array());
        if ($sources) { $data['fonti_web_search'] = $sources; }
        foreach ($sources as $source) {
            if (empty($source['url'])) { continue; }
            $exists = false;
            foreach ((array)($data['fonti_citate'] ?? array()) as $cited) { if (($cited['url'] ?? '') === $source['url']) { $exists = true; break; } }
            if (!$exists) { $data['fonti_citate'][] = array('titolo'=>$source['title'] ?? $source['url'],'url'=>$source['url'],'fonte'=>$source['domain'] ?? ''); }
        }
        foreach ($warnings as $warning) { $data['alert'][] = $warning; }
        $data['runtime'] = self::runtime_report($runtime, $attempts);
        return $data;
    }


    private static function finalize_runtime($runtime, $attempts) {
        $last = end($attempts);
        if (is_array($last)) {
            $runtime['max_output_tokens_used'] = absint($last['max_output_tokens'] ?? 0);
            $runtime['tool_choice_used'] = sanitize_text_field((string)($last['tool_choice'] ?? ''));
            $runtime['web_search_sources_include_enabled'] = !empty($last['include']);
        }
        reset($attempts);
        return $runtime;
    }

    private static function runtime_report($runtime, $attempts) {
        if (empty($runtime) || !is_array($runtime)) { $runtime = self::effective_model_details(); }
        if (!empty($attempts)) { $runtime = self::finalize_runtime($runtime, $attempts); }
        return array(
            'trend_model_saved'=>$runtime['trend_model_saved'] ?? '',
            'global_model'=>$runtime['global_model'] ?? '',
            'effective_model'=>$runtime['effective_model'] ?? self::model(),
            'using_global_model'=>!empty($runtime['using_global_model']),
            'using_fallback'=>!empty($runtime['using_fallback']),
            'legacy_ignored'=>!empty($runtime['legacy_ignored']),
            'legacy_warning'=>$runtime['legacy_warning'] ?? '',
            'runtime_profile'=>$runtime['profile'] ?? '',
            'max_output_tokens_used'=>absint($runtime['max_output_tokens_used'] ?? 0),
            'tool_choice_used'=>$runtime['tool_choice_used'] ?? '',
            'timeout_light_retry_executed'=>!empty($runtime['timeout_retry_used']),
            'web_search_sources_include_enabled'=>!empty($runtime['web_search_sources_include_enabled']),
            'web_search_sources_include_omitted_for_timeout'=>!empty($runtime['web_search_sources_include_omitted_for_timeout']),
        );
    }

    public static function extract_web_search_sources($payload) {
        $sources = array(); self::collect_web_search_sources($payload, $sources); return array_values($sources);
    }

    private static function collect_web_search_sources($value, &$sources) {
        if (!is_array($value)) { return; }
        if (isset($value['url']) && is_scalar($value['url'])) {
            $url = esc_url_raw((string)$value['url']);
            if ($url) { $sources[$url] = array('title'=>sanitize_text_field((string)($value['title'] ?? $value['name'] ?? $url)),'url'=>$url,'domain'=>self::normalize_allowed_domains(array($url))[0] ?? ''); }
        }
        foreach ($value as $child) { if (is_array($child)) { self::collect_web_search_sources($child, $sources); } }
    }
    public static function build_metrics($data, $sources) {
        $dest = (array)($data['destinazioni_prioritarie'] ?? array());
        $ideas = (array)($data['piano_editoriale_settimanale'] ?? array());
        $affiliate = (array)($data['opportunita_affiliate'] ?? array());
        $alerts = (array)($data['alert'] ?? array());
        $risks = (array)($data['rischi_e_limiti'] ?? array());
        $cat = array();
        foreach ($sources as $s) { $cat[$s['category']] = ($cat[$s['category']] ?? 0) + 1; }
        $areas = array(); $trend_scores = array(); $conf_scores = array();
        foreach ($dest as $d) {
            if (!is_array($d)) { continue; }
            if (!empty($d['paese_o_area'])) { $areas[$d['paese_o_area']] = ($areas[$d['paese_o_area']] ?? 0) + 1; }
            if (isset($d['trend_score'])) { $trend_scores[] = (float)$d['trend_score']; }
            if (isset($d['confidence_score'])) { $conf_scores[] = (float)$d['confidence_score']; }
        }
        return array(
            'count_fonti_analizzate'=>count($sources),
            'count_idee_editoriali'=>count($ideas),
            'count_destinazioni'=>count($dest),
            'distribuzione_categoria_fonte'=>$cat,
            'distribuzione_area_geografica'=>$areas,
            'media_trend_score'=>$trend_scores ? round(array_sum($trend_scores) / count($trend_scores), 2) : 0,
            'media_confidence_score'=>$conf_scores ? round(array_sum($conf_scores) / count($conf_scores), 2) : 0,
            'count_opportunita_affiliate'=>count($affiliate),
            'count_alert_rischi'=>count($alerts) + count($risks),
        );
    }
}
ALMA_Trend_Content_Ideas_Service::init();
