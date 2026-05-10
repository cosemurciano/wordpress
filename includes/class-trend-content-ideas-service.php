<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Service {
    const CRON_HOOK = 'alma_trend_content_ideas_cron';
    const LOCK_KEY = 'alma_trend_content_ideas_lock';
    const WEB_SEARCH_TOOL = 'web_search';
    const MAX_ALLOWED_DOMAINS = 20;

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
        if (get_transient(self::LOCK_KEY)) { return new WP_Error('locked', __('Un’analisi trend è già in corso. Riprova tra qualche minuto.', 'affiliate-link-manager-ai')); }
        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);
        $started = current_time('mysql'); $start_time = microtime(true);
        try {
            if (!self::is_openai_ready()) { return self::store_error_report($sources, $run_type, 'OpenAI non configurato.', $started, 'openai_missing'); }
            $request = self::request_openai_with_fallback($sources, $run_type);
            $res = $request['response'];
            $attempts = $request['attempts'];
            $warnings = $request['warnings'];
            if (empty($res['success'])) {
                $friendly = self::friendly_openai_error($res['error'] ?? 'Errore OpenAI');
                return self::store_error_report($sources, $run_type, $friendly, $started, wp_json_encode(array('attempts'=>$attempts,'error'=>$res)), $res['model'] ?? '');
            }
            $data = json_decode((string)$res['response'], true);
            $valid = self::validate_result($data);
            if (is_wp_error($valid)) { return self::store_error_report($sources, $run_type, $valid->get_error_message(), $started, wp_json_encode(array('attempts'=>$attempts,'response'=>$res['response'])), $res['model'] ?? ''); }
            $data = self::augment_result_sources($data, $res, $warnings);
            $metrics = self::build_metrics($data, $sources);
            $status = self::is_partial($data) ? 'partial' : 'success';
            $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array(
                'report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>gmdate('Y-m-d H:i:s', current_time('timestamp') - 90 * DAY_IN_SECONDS),'period_end'=>current_time('mysql'),'status'=>$status,'summary'=>$data['sintesi_generale'] ?? '',
                'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>$metrics,'model'=>$res['model'] ?? self::model(),'tokens_used'=>self::tokens_used($res['usage'] ?? null),
            ));
            $log_raw = wp_json_encode(array('attempts'=>$attempts,'warnings'=>$warnings,'sources_count'=>count($data['fonti_web_search'] ?? array())));
            foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], true); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, $status, 'Analisi completata in ' . round(microtime(true)-$start_time, 1) . 's. Domini normalizzati: ' . implode(', ', self::allowed_domains(array($src))), $log_raw, $report_id, $started); }
            return array('success'=>true,'status'=>$status,'report_id'=>$report_id,'sources_count'=>count($sources),'duration'=>round(microtime(true)-$start_time,1),'sources'=>wp_list_pluck($sources, 'name'));
        } finally { delete_transient(self::LOCK_KEY); }
    }

    private static function store_error_report($sources, $run_type, $message, $started, $raw='', $model='') {
        $data = array('sintesi_generale'=>$message,'fonti_analizzate'=>array(),'destinazioni_prioritarie'=>array(),'temi_editoriali'=>array(),'piano_editoriale_settimanale'=>array(),'opportunita_affiliate'=>array(),'bisogni_viaggiatori'=>array(),'rischi_e_limiti'=>array(array('messaggio'=>$message)),'dati_per_grafici'=>array(),'livello_confidenza'=>'basso','alert'=>array($message),'fonti_citate'=>array(),'fonti_web_search'=>array(),'errore_tecnico'=>$raw);
        $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array('report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>$started,'period_end'=>current_time('mysql'),'status'=>'error','summary'=>$message,'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>self::build_metrics($data,$sources),'model'=>$model ?: self::model()));
        foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], false); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, 'error', $message, $raw, $report_id, $started); }
        return new WP_Error('trend_run_error', $message, array('report_id'=>$report_id));
    }

    public static function validate_result($data) { $required=array('sintesi_generale','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','dati_per_grafici','livello_confidenza','alert','fonti_citate'); if(!is_array($data)){return new WP_Error('invalid_json', __('JSON OpenAI non valido.', 'affiliate-link-manager-ai'));} foreach($required as $key){ if(!array_key_exists($key,$data)){return new WP_Error('invalid_json', sprintf(__('JSON incompleto: manca %s.', 'affiliate-link-manager-ai'), $key));} } return true; }

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

    public static function build_web_search_tool($domains = array(), $search_context_size = 'medium') {
        $tool = array('type'=>self::WEB_SEARCH_TOOL,'search_context_size'=>sanitize_key($search_context_size));
        $domains = self::normalize_allowed_domains($domains);
        if ($domains) { $tool['filters'] = array('allowed_domains'=>$domains); }
        return $tool;
    }

    public static function build_openai_request_args($sources, $run_type = 'manual', $with_filters = true, $tool_choice = 'required') {
        $domains = $with_filters ? self::allowed_domains($sources) : array();
        return array(
            'model'=>self::model(),
            'system_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::system_prompt(),
            'user_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::build($sources, $run_type),
            'response_format'=>ALMA_Trend_Content_Ideas_Prompt_Builder::response_schema(),
            'max_output_tokens'=>6000,
            'timeout'=>absint(get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90)),
            'tools'=>array(self::build_web_search_tool($domains)),
            'include'=>array('web_search_call.action.sources'),
            'tool_choice'=>$tool_choice,
        );
    }

    private static function request_openai_with_fallback($sources, $run_type) {
        $attempts = array(); $warnings = array();
        $args = self::build_openai_request_args($sources, $run_type, true, 'required');
        $attempts[] = self::attempt_summary('primary', $args);
        $res = ALMA_OpenAI_Service::request($args);
        $warnings = self::merge_openai_warnings($warnings, $res);
        if (!empty($res['success'])) { return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings); }
        if (self::is_tool_choice_unsupported($res)) {
            $warnings[] = 'OpenAI non ha accettato tool_choice required. La ricerca è stata ripetuta con tool_choice auto.';
            $args['tool_choice'] = 'auto';
            $attempts[] = self::attempt_summary('tool_choice_auto', $args);
            $res = ALMA_OpenAI_Service::request($args);
            $warnings = self::merge_openai_warnings($warnings, $res);
            if (!empty($res['success'])) { return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings); }
        }
        if (self::is_filters_unsupported_error($res) && !empty($args['tools'][0]['filters'])) {
            $warnings[] = 'Il filtro domini non è stato accettato dalla chiamata OpenAI. La ricerca è stata ripetuta senza filtro dominio.';
            $args = self::build_openai_request_args($sources, $run_type, false, $args['tool_choice'] ?? 'required');
            $attempts[] = self::attempt_summary('fallback_without_filters', $args);
            $res = ALMA_OpenAI_Service::request($args);
            $warnings = self::merge_openai_warnings($warnings, $res);
        }
        return array('response'=>$res,'attempts'=>$attempts,'warnings'=>$warnings);
    }

    private static function attempt_summary($label, $args) { return array('label'=>$label,'tool'=>$args['tools'][0]['type'] ?? '','tool_choice'=>$args['tool_choice'] ?? '','allowed_domains'=>$args['tools'][0]['filters']['allowed_domains'] ?? array(),'include'=>$args['include'] ?? array()); }
    private static function merge_openai_warnings($warnings, $res) { foreach ((array)($res['warnings'] ?? array()) as $warning) { $warnings[] = sanitize_text_field((string)$warning); } return array_values(array_unique(array_filter($warnings))); }
    private static function is_filters_unsupported_error($res) { $m = strtolower((string)($res['error'] ?? '')); return strpos($m, 'filters') !== false && (strpos($m, 'unsupported parameter') !== false || strpos($m, 'not supported') !== false || strpos($m, 'unknown parameter') !== false); }
    private static function is_tool_choice_unsupported($res) { $m = strtolower((string)($res['error'] ?? '')); return strpos($m, 'tool_choice') !== false && (strpos($m, 'unsupported') !== false || strpos($m, 'not supported') !== false); }
    private static function friendly_openai_error($message) { if (self::is_filters_unsupported_error(array('error'=>$message))) { return __('Errore OpenAI: il parametro filters non è supportato dal tool web search usato. Aggiorna la configurazione o verifica il tool OpenAI.', 'affiliate-link-manager-ai'); } if (self::is_sampling_unsupported_error(array('error'=>$message))) { return __('Errore OpenAI: il modello selezionato non supporta uno o più parametri sampling. Il dettaglio tecnico è disponibile nel report.', 'affiliate-link-manager-ai'); } return sprintf(__('Errore OpenAI: %s', 'affiliate-link-manager-ai'), sanitize_text_field((string)$message)); }
    private static function is_sampling_unsupported_error($res) { $m = strtolower((string)($res['error'] ?? '')); if (strpos($m, 'unsupported parameter') === false && strpos($m, 'not supported') === false && strpos($m, 'unknown parameter') === false) { return false; } foreach (array('temperature','top_p','presence_penalty','frequency_penalty') as $key) { if (strpos($m, $key) !== false) { return true; } } return false; }
    private static function source_snapshot($sources) { return array_map(function($s){ return array('source_key'=>$s['source_key'],'name'=>$s['name'],'priority'=>(int)$s['priority'],'category'=>$s['category'],'allowed_domains'=>ALMA_Trend_Content_Ideas_Store::decode_json($s['allowed_domains']),'normalized_allowed_domains'=>self::normalize_allowed_domains(ALMA_Trend_Content_Ideas_Store::decode_json($s['allowed_domains']))); }, $sources); }
    private static function title_for($run_type, $sources) { return ($run_type === 'test' ? 'Test Trend Idee contenuto' : 'Report Trend Idee contenuto') . ' - ' . count($sources) . ' fonti'; }
    public static function effective_model() { $trend_model = trim((string)get_option(ALMA_Trend_Content_Ideas_Store::OPTION_MODEL, '')); if ($trend_model !== '') { return $trend_model; } $global_model = trim((string)get_option('alma_openai_model', '')); return $global_model !== '' ? $global_model : 'gpt-5.4-mini'; }
    private static function model() { return self::effective_model(); }
    private static function tokens_used($usage) { return is_array($usage) && isset($usage['total_tokens']) ? absint($usage['total_tokens']) : null; }
    private static function is_partial($data) { return !empty($data['alert']) || stripos((string)($data['livello_confidenza'] ?? ''), 'basso') !== false; }

    private static function augment_result_sources($data, $res, $warnings) {
        $sources = self::extract_web_search_sources($res['raw_response'] ?? array());
        if ($sources) { $data['fonti_web_search'] = $sources; }
        foreach ($sources as $source) {
            if (empty($source['url'])) { continue; }
            $exists = false;
            foreach ((array)($data['fonti_citate'] ?? array()) as $cited) { if (($cited['url'] ?? '') === $source['url']) { $exists = true; break; } }
            if (!$exists) { $data['fonti_citate'][] = array('titolo'=>$source['title'] ?? $source['url'],'url'=>$source['url'],'fonte'=>$source['domain'] ?? ''); }
        }
        foreach ($warnings as $warning) { $data['alert'][] = $warning; }
        return $data;
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
