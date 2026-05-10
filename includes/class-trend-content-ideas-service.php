<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Service {
    const CRON_HOOK = 'alma_trend_content_ideas_cron';
    const LOCK_KEY = 'alma_trend_content_ideas_lock';

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
            $domains = self::allowed_domains($sources);
            $tools = array(array('type'=>'web_search_preview'));
            if ($domains) { $tools[0]['filters'] = array('allowed_domains'=>$domains); }
            $model = trim((string)get_option(ALMA_Trend_Content_Ideas_Store::OPTION_MODEL, '')) ?: get_option('alma_openai_model', 'gpt-5.4-mini');
            $res = ALMA_OpenAI_Service::request(array(
                'model'=>$model,
                'system_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::system_prompt(),
                'user_prompt'=>ALMA_Trend_Content_Ideas_Prompt_Builder::build($sources, $run_type),
                'response_format'=>ALMA_Trend_Content_Ideas_Prompt_Builder::response_schema(),
                'max_output_tokens'=>6000,
                'timeout'=>absint(get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90)),
                'tools'=>$tools,
            ));
            if (empty($res['success'])) { return self::store_error_report($sources, $run_type, $res['error'] ?? 'Errore OpenAI', $started, wp_json_encode($res)); }
            $data = json_decode((string)$res['response'], true);
            $valid = self::validate_result($data);
            if (is_wp_error($valid)) { return self::store_error_report($sources, $run_type, $valid->get_error_message(), $started, $res['response']); }
            $metrics = self::build_metrics($data, $sources);
            $status = self::is_partial($data) ? 'partial' : 'success';
            $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array(
                'report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>gmdate('Y-m-d H:i:s', current_time('timestamp') - 90 * DAY_IN_SECONDS),'period_end'=>current_time('mysql'),'status'=>$status,'summary'=>$data['sintesi_generale'] ?? '',
                'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>$metrics,'model'=>$res['model'] ?? $model,'tokens_used'=>self::tokens_used($res['usage'] ?? null),
            ));
            foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], true); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, $status, 'Analisi completata in ' . round(microtime(true)-$start_time, 1) . 's.', '', $report_id, $started); }
            return array('success'=>true,'status'=>$status,'report_id'=>$report_id,'sources_count'=>count($sources),'duration'=>round(microtime(true)-$start_time,1),'sources'=>wp_list_pluck($sources, 'name'));
        } finally { delete_transient(self::LOCK_KEY); }
    }

    private static function store_error_report($sources, $run_type, $message, $started, $raw='') {
        $data = array('sintesi_generale'=>$message,'fonti_analizzate'=>array(),'destinazioni_prioritarie'=>array(),'temi_editoriali'=>array(),'piano_editoriale_settimanale'=>array(),'opportunita_affiliate'=>array(),'bisogni_viaggiatori'=>array(),'rischi_e_limiti'=>array(array('messaggio'=>$message)),'dati_per_grafici'=>array(),'livello_confidenza'=>'basso','alert'=>array($message),'fonti_citate'=>array());
        $report_id = ALMA_Trend_Content_Ideas_Store::insert_report(array('report_type'=>$run_type,'title'=>self::title_for($run_type, $sources),'period_start'=>$started,'period_end'=>current_time('mysql'),'status'=>'error','summary'=>$message,'result'=>$data,'sources'=>self::source_snapshot($sources),'metrics'=>self::build_metrics($data,$sources),'model'=>get_option('alma_openai_model','')));
        foreach ($sources as $src) { ALMA_Trend_Content_Ideas_Store::mark_source_ran($src['source_key'], false); ALMA_Trend_Content_Ideas_Store::log($src['source_key'], $run_type, 'error', $message, $raw, $report_id, $started); }
        return new WP_Error('trend_run_error', $message, array('report_id'=>$report_id));
    }

    public static function validate_result($data) { $required=array('sintesi_generale','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','dati_per_grafici','livello_confidenza','alert','fonti_citate'); if(!is_array($data)){return new WP_Error('invalid_json', __('JSON OpenAI non valido.', 'affiliate-link-manager-ai'));} foreach($required as $key){ if(!array_key_exists($key,$data)){return new WP_Error('invalid_json', sprintf(__('JSON incompleto: manca %s.', 'affiliate-link-manager-ai'), $key));} } return true; }
    private static function allowed_domains($sources) { $out=array(); foreach($sources as $src){ $out=array_merge($out, ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains'] ?? '[]')); } return array_values(array_unique(array_filter(array_map('sanitize_text_field',$out)))); }
    private static function source_snapshot($sources) { return array_map(function($s){ return array('source_key'=>$s['source_key'],'name'=>$s['name'],'priority'=>(int)$s['priority'],'category'=>$s['category'],'allowed_domains'=>ALMA_Trend_Content_Ideas_Store::decode_json($s['allowed_domains'])); }, $sources); }
    private static function title_for($run_type, $sources) { return ($run_type === 'test' ? 'Test Trend Idee contenuto' : 'Report Trend Idee contenuto') . ' - ' . count($sources) . ' fonti'; }
    private static function tokens_used($usage) { return is_array($usage) && isset($usage['total_tokens']) ? absint($usage['total_tokens']) : null; }
    private static function is_partial($data) { return !empty($data['alert']) || stripos((string)($data['livello_confidenza'] ?? ''), 'basso') !== false; }
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
