<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Trend_Radar_Service {
    const CRON_HOOK = 'alma_ai_trend_radar_run_profile';
    const LOCK_PREFIX = 'alma_trend_radar_lock_';

    public static function init() { add_action(self::CRON_HOOK, array(__CLASS__, 'run_profile'), 10, 1); }

    public static function is_openai_ready() { return trim((string)get_option('alma_openai_api_key', '')) !== '' && class_exists('ALMA_OpenAI_Service'); }

    public static function reschedule_all() { foreach (ALMA_AI_Trend_Radar_Store::get_profiles(true) as $p) { self::schedule_profile((int)$p['id']); } }

    public static function schedule_profile($profile_id) {
        $profile = ALMA_AI_Trend_Radar_Store::get_profile($profile_id); self::clear_profile_schedule($profile_id);
        if (empty($profile) || empty($profile['active']) || $profile['frequency'] === 'manual') { ALMA_AI_Trend_Radar_Store::update_next_run($profile_id, null); return; }
        $ts = self::next_timestamp($profile); wp_schedule_single_event($ts, self::CRON_HOOK, array((int)$profile_id)); ALMA_AI_Trend_Radar_Store::update_next_run($profile_id, gmdate('Y-m-d H:i:s', $ts + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS)));
    }

    public static function clear_profile_schedule($profile_id) { wp_clear_scheduled_hook(self::CRON_HOOK, array((int)$profile_id)); }

    private static function next_timestamp($profile) {
        $now = current_time('timestamp'); $time = $profile['run_time'] ?: '09:00'; list($h,$m)=array_map('intval', explode(':', $time));
        $candidate = mktime($h, $m, 0, (int)wp_date('n', $now), (int)wp_date('j', $now), (int)wp_date('Y', $now));
        $freq = $profile['frequency'];
        if ($freq === 'hourly') { return $now + HOUR_IN_SECONDS; }
        if ($freq === 'twicedaily') { return $now + 12 * HOUR_IN_SECONDS; }
        if ($candidate <= $now) { $candidate += DAY_IN_SECONDS; }
        if ($freq === 'weekly') { while ((int)wp_date('N', $candidate) !== 1) { $candidate += DAY_IN_SECONDS; } }
        return $candidate;
    }

    public static function run_profile($profile_id, $manual = false) {
        $profile_id = absint($profile_id); $profile = ALMA_AI_Trend_Radar_Store::get_profile($profile_id);
        if (empty($profile)) { return new WP_Error('profile_missing', __('Profilo Trend Radar non trovato.', 'affiliate-link-manager-ai')); }
        if (empty($profile['active']) && !$manual) { return new WP_Error('profile_inactive', __('Profilo non attivo.', 'affiliate-link-manager-ai')); }
        $lock_key = self::LOCK_PREFIX . $profile_id;
        if (get_transient($lock_key)) { ALMA_AI_Trend_Radar_Store::log($profile_id, 'warning', 'Esecuzione saltata: lock profilo già attivo.'); return new WP_Error('locked', __('Esecuzione già in corso per questo profilo.', 'affiliate-link-manager-ai')); }
        set_transient($lock_key, 1, 15 * MINUTE_IN_SECONDS);
        try {
            if (!self::is_openai_ready()) { ALMA_AI_Trend_Radar_Store::log($profile_id, 'error', 'OpenAI non configurata.'); return new WP_Error('openai_missing', __('OpenAI non è configurata.', 'affiliate-link-manager-ai')); }
            ALMA_AI_Trend_Radar_Store::log($profile_id, 'info', 'Avvio ricerca trend.', array('manual'=>$manual));
            $affiliate_context = self::affiliate_context($profile, 12);
            $ai = self::call_openai($profile, $affiliate_context);
            if (is_wp_error($ai)) { ALMA_AI_Trend_Radar_Store::log($profile_id, 'error', $ai->get_error_message(), array('code'=>$ai->get_error_code())); return $ai; }
            $created = 0;
            foreach ($ai as $trend) {
                $trend = self::normalize_trend($trend);
                if (empty($trend['trend_title'])) { continue; }
                $trend['suggested_internal_affiliate_links'] = self::match_affiliate_links($trend, 8);
                ALMA_AI_Trend_Radar_Store::insert_report($profile_id, $trend); $created++;
            }
            ALMA_AI_Trend_Radar_Store::mark_profile_ran($profile_id);
            ALMA_AI_Trend_Radar_Store::log($profile_id, 'success', sprintf('Ricerca completata: %d trend salvati.', $created));
            if (!empty($profile['email_summary'])) { self::send_summary($profile, $created); }
            return array('success'=>true,'created'=>$created);
        } finally {
            delete_transient($lock_key);
            if (!$manual) { self::schedule_profile($profile_id); }
        }
    }

    private static function call_openai($profile, $affiliate_context) {
        $schema = array('type'=>'json_schema','name'=>'trend_radar_report','schema'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('trends'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true))),'required'=>array('trends')));
        $system = 'Sei AI Trend Radar per Sothra, magazine travel/turismo. Usa web search per trovare trend editoriali attuali. Rispondi solo JSON valido secondo schema. Punteggi sempre interi 1-10. Non inventare URL fonti.';
        $prompt = "Genera fino a {$profile['max_trends']} trend editoriali travel/turismo.\nLingua: {$profile['language']}\nMercato target: {$profile['target_market']}\nTema: {$profile['main_theme']}\nFocus: {$profile['editorial_focus']}\nQuery seed: {$profile['seed_queries']}\nFonti preferite: {$profile['preferred_sources']}\nFonti escluse: {$profile['excluded_sources']}\nProfondità: {$profile['analysis_depth']}\nObiettivo editoriale: {$profile['editorial_goal']}\n\nPer ogni trend includi: trend_title, trend_summary, why_now, destinations, seasonality, target_audience, seo_potential_score, affiliate_potential_score, urgency_score, recommended_article_titles, suggested_keywords, suggested_outline, source_urls, source_notes, suggested_internal_affiliate_links, status, created_at.\n\nContesto compatto link affiliati locali candidati (usa solo se rilevante, non inventare link):\n" . wp_json_encode($affiliate_context);
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>$system,'user_prompt'=>$prompt,'json_output'=>true,'response_format'=>$schema,'max_output_tokens'=>3500,'timeout'=>60,'tools'=>array(array('type'=>'web_search_preview'))));
        if (empty($res['success'])) {
            $code = $res['error_code'] ?? 'openai_error';
            if ($code === 'response_format_unsupported' || $code === 'model_unsupported') { return new WP_Error($code, __('Web search/Responses API non disponibile per la configurazione corrente.', 'affiliate-link-manager-ai')); }
            return new WP_Error($code, sanitize_text_field($res['error'] ?? __('Errore OpenAI.', 'affiliate-link-manager-ai')));
        }
        $data = json_decode((string)$res['response'], true);
        if (!is_array($data) || !isset($data['trends']) || !is_array($data['trends'])) { return new WP_Error('invalid_json', __('JSON OpenAI non valido o incompleto.', 'affiliate-link-manager-ai')); }
        return $data['trends'];
    }

    private static function normalize_trend($trend) {
        $trend = is_array($trend) ? $trend : array();
        foreach (array('seo_potential_score','affiliate_potential_score','urgency_score') as $s) { $trend[$s] = max(1, min(10, absint($trend[$s] ?? 1))); }
        $trend['status'] = sanitize_key($trend['status'] ?? 'new') ?: 'new';
        $trend['created_at'] = sanitize_text_field($trend['created_at'] ?? current_time('mysql'));
        return $trend;
    }

    private static function affiliate_context($profile, $limit) {
        $query = trim(implode(' ', array($profile['main_theme'], $profile['target_market'], $profile['seed_queries'])));
        return self::find_affiliate_candidates($query, $limit);
    }

    private static function match_affiliate_links($trend, $limit) {
        $parts = array($trend['trend_title'] ?? '', $trend['trend_summary'] ?? '', $trend['destinations'] ?? '', $trend['suggested_keywords'] ?? '');
        return self::find_affiliate_candidates(wp_json_encode($parts), $limit);
    }

    private static function find_affiliate_candidates($query, $limit) {
        global $wpdb; $terms = preg_split('/\s+/', strtolower(wp_strip_all_tags((string)$query))); $terms = array_values(array_filter(array_unique(array_map('sanitize_text_field', $terms)), function($t){ return strlen($t) > 3; })); $terms = array_slice($terms, 0, 8);
        $where = "p.post_type='affiliate_link' AND p.post_status IN ('publish','draft')"; $params = array();
        if ($terms) { $likes=array(); foreach($terms as $t){ $like='%'.$wpdb->esc_like($t).'%'; $likes[]='(p.post_title LIKE %s OR p.post_content LIKE %s OR pm.meta_value LIKE %s)'; array_push($params,$like,$like,$like); } $where .= ' AND (' . implode(' OR ', $likes) . ')'; }
        $sql = "SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE $where ORDER BY p.post_modified DESC LIMIT %d"; $params[] = max(1,min(20,absint($limit)));
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A); $out=array();
        foreach ((array)$rows as $r) {
            $id=(int)$r['ID']; $types=wp_get_object_terms($id,'link_type',array('fields'=>'names')); $url=get_post_meta($id,'_affiliate_url',true); $provider=get_post_meta($id,'_alma_provider',true); if($provider===''){$provider=get_post_meta($id,'_alma_source_provider',true);} 
            $out[] = array('id'=>$id,'title'=>sanitize_text_field($r['post_title']),'url'=>esc_url_raw($url),'types'=>is_wp_error($types)?array():array_values($types),'provider'=>sanitize_text_field($provider));
        }
        return $out;
    }

    private static function send_summary($profile, $created) {
        $to = is_email($profile['recipient_email']) ? $profile['recipient_email'] : get_option('admin_email'); if (!$to) { return; }
        wp_mail($to, 'AI Trend Radar - riepilogo', sprintf("Profilo: %s\nTrend generati: %d\nData: %s", $profile['name'], $created, current_time('mysql')));
    }
}
ALMA_AI_Trend_Radar_Service::init();
