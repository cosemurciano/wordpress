<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Admin {
    const SLUG = 'alma-trend-content-ideas';
    const CAP = 'manage_options';

    public static function init() { add_action('admin_post_alma_trend_content_action', array(__CLASS__, 'handle_action')); add_action('wp_dashboard_setup', array(__CLASS__, 'register_dashboard_widget'), 1); }
    private static function url($args=array()) { return admin_url('edit.php?post_type=affiliate_link&page=' . self::SLUG . (empty($args)?'':'&' . http_build_query($args))); }
    private static function action_url($args=array()) { return wp_nonce_url(add_query_arg(array_merge(array('action'=>'alma_trend_content_action'), $args), admin_url('admin-post.php')), 'alma_trend_content_action'); }

    public static function render_page() {
        if (!current_user_can(self::CAP)) { wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai')); }
        $notice = get_transient('alma_trend_content_notice_' . get_current_user_id()); delete_transient('alma_trend_content_notice_' . get_current_user_id());
        $view_id = absint($_GET['report_id'] ?? 0);
        echo '<div class="wrap alma-trend-content"><h1>Trend Idee contenuto</h1><p>Analizza fonti pubbliche sui trend di viaggio con OpenAI Web Search e genera report editoriali per Sothra. Nessuna bozza WordPress viene creata automaticamente.</p>';
        if ($notice) { printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message'])); }
        echo '<div class="notice notice-info"><p>Le chiamate OpenAI partono solo dai pulsanti di test/generazione o da WP Cron, mai durante il semplice caricamento della pagina.</p></div>';
        if (!ALMA_Trend_Content_Ideas_Service::is_openai_ready()) { echo '<div class="notice notice-warning"><p>Configura la chiave OpenAI nelle impostazioni del plugin prima di eseguire test reali.</p></div>'; }
        if ($view_id) { self::render_report($view_id); echo '</div>'; return; }
        self::render_settings(); self::render_latest(); self::render_history(); echo '</div>';
    }

    private static function render_settings() {
        $sources = ALMA_Trend_Content_Ideas_Store::get_sources(false);
        $model_details = ALMA_Trend_Content_Ideas_Service::effective_model_details();
        $model = !empty($model_details['legacy_ignored']) ? '' : $model_details['trend_model_saved']; $timeout = get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90); $global_model = $model_details['global_model']; $effective_model = $model_details['effective_model']; $legacy_ignored = !empty($model_details['legacy_ignored']);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="alma_trend_content_action"><input type="hidden" name="do" value="save_settings">'; if ($legacy_ignored) { echo '<input type="hidden" name="legacy_model_ignored" value="1">'; } wp_nonce_field('alma_trend_content_action');
        echo '<div class="postbox"><div class="inside"><h2>Configurazione generale</h2><table class="form-table"><tr><th><label for="global_prompt">Prompt globale</label></th><td><textarea id="global_prompt" name="global_prompt" class="large-text" rows="6">' . esc_textarea(get_option(ALMA_Trend_Content_Ideas_Store::OPTION_GLOBAL_PROMPT, ALMA_Trend_Content_Ideas_Prompt_Builder::default_global_prompt())) . '</textarea><p class="description">Questo testo viene combinato con istruzioni non modificabili e prompt specifici fonte.</p></td></tr><tr><th><label for="model">Modello OpenAI</label></th><td><input id="model" name="model" class="regular-text" value="' . esc_attr($model) . '" placeholder="Usa modello globale"><p class="description">Lascia vuoto per usare il modello globale OpenAI del plugin' . ($global_model ? ' (' . esc_html($global_model) . ')' : '') . '. Modello effettivo: <strong>' . esc_html($effective_model) . '</strong>' . ($model === '' ? ' (impostazione Trend vuota, fallback globale attivo)' : '') . '.</p>' . ($legacy_ignored ? '<p class="description"><strong>' . esc_html__('Il vecchio modello automatico gpt-5.5 è stato ignorato. Il modulo usa il modello globale OpenAI finché non imposti manualmente un modello Trend.', 'affiliate-link-manager-ai') . '</strong></p>' : '') . '<p class="description">Modello consigliato per nuove configurazioni: gpt-5.5. Con alcuni modelli GPT-5.x/reasoning, parametri come temperature vengono omessi automaticamente.</p></td></tr><tr><th><label for="timeout">Timeout ricerca</label></th><td><input id="timeout" name="timeout" type="number" min="20" max="180" value="' . esc_attr($timeout) . '"> secondi</td></tr></table></div></div>';
        echo '<div class="postbox"><div class="inside"><h2>Fonti trend</h2><p>Abilita le fonti da analizzare, scegli priorità, limite contenuti per singola analisi, frequenza e prompt fonte.</p><p class="description"><strong>Priorità:</strong> La priorità determina l’ordine e il peso della fonte nelle analisi Trend. 1 = alta, 2 = media, 3 = bassa.</p><p class="description"><strong>Quantità contenuti da analizzare:</strong> Numero massimo di contenuti informativi che l’AI può consultare o sintetizzare da questa fonte durante una singola analisi. Per contenuti si intendono pagine, articoli, comunicati, report o documenti trovati dalla ricerca web. Non indica il numero di articoli WordPress generati.</p><table class="widefat striped"><thead><tr><th>Abilitata</th><th>Fonte</th><th>Priorità</th><th>Quantità contenuti da analizzare</th><th>Categoria</th><th>Intervallo</th><th>Ultima analisi</th><th>Prossima</th><th>Stato</th><th>Azioni</th></tr></thead><tbody>';
        foreach ($sources as $src) {
            $key = $src['source_key']; $badge = $src['enabled'] ? 'success' : 'muted';
            $priority = ALMA_Trend_Content_Ideas_Store::normalize_priority($src['priority'] ?? 2);
            $max_contents = ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3);
            echo '<tr><td><input type="checkbox" name="sources[' . esc_attr($key) . '][enabled]" value="1" ' . checked((int)$src['enabled'],1,false) . '></td><td><strong>' . esc_html($src['name']) . '</strong><p class="description">' . esc_html($src['description']) . '</p><details><summary>Modifica prompt fonte</summary><textarea class="large-text" rows="4" name="sources[' . esc_attr($key) . '][custom_prompt]">' . esc_textarea($src['custom_prompt']) . '</textarea></details></td><td><input type="number" min="1" max="3" name="sources[' . esc_attr($key) . '][priority]" value="' . esc_attr($priority) . '" style="width:70px"><p class="description">1 alta, 2 media, 3 bassa.</p></td><td><input type="number" min="1" max="10" name="sources[' . esc_attr($key) . '][max_contents_per_run]" value="' . esc_attr($max_contents) . '" style="width:90px"><p class="description">Limite per fonte e per singola run.</p></td><td>' . esc_html($src['category']) . '</td><td><input type="number" min="1" max="365" name="sources[' . esc_attr($key) . '][interval_days]" value="' . (int)$src['interval_days'] . '" style="width:80px"> giorni</td><td>' . esc_html($src['last_run_at'] ?: 'Mai') . '</td><td>' . esc_html($src['next_run_at'] ?: 'Non pianificata') . '</td><td><span class="alma-badge alma-badge-' . esc_attr($badge) . '">' . esc_html($src['status']) . '</span></td><td><a class="button button-small" href="' . esc_url(self::action_url(array('do'=>'run_source','source_key'=>$key))) . '">Testa fonte</a></td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Salva configurazione</button> <a class="button" href="' . esc_url(self::action_url(array('do'=>'run_all'))) . '">Esegui test completo adesso</a> <a class="button button-secondary" href="' . esc_url(self::action_url(array('do'=>'generate_plan'))) . '">Genera piano editoriale ora</a> <a class="button" href="#ultimo-report">Visualizza ultimo report</a></p></div></div></form>';
        echo '<style>.alma-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#dcdcde}.alma-badge-success{background:#00a32a;color:#fff}.alma-badge-muted{background:#646970;color:#fff}.alma-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.alma-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.alma-card h3{margin-top:0}.alma-pill{display:inline-block;padding:2px 7px;border-radius:999px;background:#f0f0f1;margin:2px}.alma-metric-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin:10px 0}.alma-metric-card{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px}.alma-metric-card strong{display:block;font-size:18px}.alma-dashboard-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.alma-dashboard-lists h3{margin-bottom:4px}</style>';
    }

    public static function register_dashboard_widget() {
        if (!current_user_can(self::CAP)) { return; }
        wp_add_dashboard_widget('alma_trend_content_ideas_dashboard_widget', __('Trend Idee contenuto', 'affiliate-link-manager-ai'), array(__CLASS__, 'dashboard_widget'), null, null, 'normal', 'high');
        self::promote_dashboard_widget('alma_trend_content_ideas_dashboard_widget');
    }

    public static function dashboard_widget() { self::dashboard_box(false); }

    private static function promote_dashboard_widget($widget_id) {
        global $wp_meta_boxes;
        if (empty($wp_meta_boxes['dashboard']['normal']['high'][$widget_id])) { return; }
        $widget = array($widget_id => $wp_meta_boxes['dashboard']['normal']['high'][$widget_id]);
        unset($wp_meta_boxes['dashboard']['normal']['high'][$widget_id]);
        $wp_meta_boxes['dashboard']['normal']['high'] = $widget + (array)$wp_meta_boxes['dashboard']['normal']['high'];
    }

    public static function dashboard_box($wrap_postbox = true) {
        echo '<style>.alma-metric-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:10px 0}.alma-metric-card{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px}.alma-metric-card strong{display:block;font-size:18px}.alma-dashboard-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.alma-dashboard-lists h3{margin-bottom:4px}</style>';
        $r = ALMA_Trend_Content_Ideas_Store::latest_report();
        if ($wrap_postbox) { echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('Trend Idee contenuto', 'affiliate-link-manager-ai') . '</h2>'; }
        if (!$r) {
            echo '<p>' . esc_html__('Nessun report Trend disponibile. Genera il primo report.', 'affiliate-link-manager-ai') . '</p><p><a class="button button-primary" href="' . esc_url(self::url()) . '">' . esc_html__('Vai a Trend Idee contenuto', 'affiliate-link-manager-ai') . '</a></p>';
            if ($wrap_postbox) { echo '</div></div>'; }
            return;
        }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']);
        $runtime=(array)($data['runtime']??array());
        $trends=array_slice((array)($data['trends']??($data['trend_principali']??array())),0,5);
        $dest=array_slice((array)($data['destinazioni_prioritarie']??array()),0,5);
        $ideas=array_slice((array)($data['piano_editoriale_settimanale']??($data['content_ideas']??array())),0,5);
        $affiliate=array_slice((array)($data['opportunita_affiliate']??array()),0,5);
        $needs=array_slice((array)($data['bisogni_viaggiatori']??array()),0,5);
        $alerts=array_slice((array)($data['alert']??($data['warnings']??array())),0,5);
        echo '<p><strong>' . esc_html__('Data report:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($r['created_at']) . ' &nbsp; <strong>' . esc_html__('Stato:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($r['status']) . ' &nbsp; <strong>' . esc_html__('Modello:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($r['model']) . ' &nbsp; <strong>' . esc_html__('Profilo:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($runtime['runtime_profile'] ?? $r['report_type']) . '</p>';
        self::render_metric_cards(array(
            __('Fonti config.', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_configurate']??0),
            __('Citate/interr.', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_citate']??0) . '/' . (int)($metrics['count_fonti_interrogate']??0),
            __('Idee', 'affiliate-link-manager-ai')=>(int)($metrics['count_idee_editoriali']??0),
            __('Destinazioni', 'affiliate-link-manager-ai')=>(int)($metrics['count_destinazioni']??0),
            __('Affiliate', 'affiliate-link-manager-ai')=>(int)($metrics['count_opportunita_affiliate']??0),
            __('Alert', 'affiliate-link-manager-ai')=>(int)($metrics['count_alert_rischi']??0),
        ));
        echo '<div class="alma-dashboard-lists">';
        self::dashboard_list(__('Top trend', 'affiliate-link-manager-ai'), $trends, array('title','titolo','nome'));
        self::dashboard_list(__('Top destinazioni/città', 'affiliate-link-manager-ai'), $dest, array('nome','paese_o_area'));
        self::dashboard_list(__('Top idee editoriali', 'affiliate-link-manager-ai'), $ideas, array('titolo','title'));
        self::dashboard_list(__('Opportunità affiliate', 'affiliate-link-manager-ai'), $affiliate, array('categoria','priorita'));
        self::dashboard_list(__('Bisogni viaggiatori', 'affiliate-link-manager-ai'), $needs, array('bisogno','descrizione'));
        self::dashboard_list(__('Alert principali', 'affiliate-link-manager-ai'), $alerts, array());
        echo '</div>';
        echo '<p><a class="button button-primary" href="' . esc_url(self::url(array('report_id'=>(int)$r['id']))) . '">' . esc_html__('Apri report completo', 'affiliate-link-manager-ai') . '</a> <a class="button" href="' . esc_url(self::action_url(array('do'=>'generate_plan'))) . '">' . esc_html__('Genera nuovo piano editoriale', 'affiliate-link-manager-ai') . '</a> <a class="button" href="' . esc_url(self::url()) . '">' . esc_html__('Vai a Trend Idee contenuto', 'affiliate-link-manager-ai') . '</a></p>';
        if ($wrap_postbox) { echo '</div></div>'; }
    }

    private static function render_metric_cards($metrics) { echo '<div class="alma-metric-cards">'; foreach($metrics as $label=>$value){ echo '<div class="alma-metric-card"><strong>'.esc_html((string)$value).'</strong><span>'.esc_html($label).'</span></div>'; } echo '</div>'; }

    private static function dashboard_list($title, $items, $fields=array()) { echo '<div><h3>'.esc_html($title).'</h3>'; if(!$items){ echo '<p><em>'.esc_html__('Nessun dato in questa run.', 'affiliate-link-manager-ai').'</em></p></div>'; return; } echo '<ol>'; foreach(array_slice((array)$items,0,5) as $item){ if(is_array($item)){ $parts=array(); foreach($fields as $field){ if(!empty($item[$field])){ $parts[]=self::stringify($item[$field]); } } $label=$parts?implode(' — ', $parts):self::stringify($item); } else { $label=(string)$item; } echo '<li>'.esc_html(wp_trim_words($label, 16)).'</li>'; } echo '</ol></div>'; }

    private static function render_latest() { echo '<div id="ultimo-report">'; self::dashboard_box(); echo '</div>'; }
    private static function render_history() { $page=max(1,absint($_GET['paged']??1)); $reports=ALMA_Trend_Content_Ideas_Store::get_reports($page,10); echo '<div class="postbox"><div class="inside"><h2>Storico report</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Titolo</th><th>Tipo</th><th>Stato</th><th>Sintesi</th><th>Azioni</th></tr></thead><tbody>'; if(!$reports){echo '<tr><td colspan="6">Nessun report salvato.</td></tr>';} foreach($reports as $r){ echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['report_type']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html(wp_trim_words($r['summary'],18)).'</td><td><a class="button button-small" href="'.esc_url(self::url(array('report_id'=>(int)$r['id']))).'">Apri</a></td></tr>'; } echo '</tbody></table></div></div>'; }

    private static function render_report($id) {
        $r=ALMA_Trend_Content_Ideas_Store::get_report($id); if(!$r){ echo '<div class="notice notice-error"><p>' . esc_html__('Report non trovato.', 'affiliate-link-manager-ai') . '</p></div>'; return; }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']); $source_snapshot=ALMA_Trend_Content_Ideas_Store::decode_json($r['sources_json']); $runtime=(array)($data['runtime']??array());
        echo '<p><a class="button" href="'.esc_url(self::url()).'">' . esc_html__('← Torna a Trend Idee contenuto', 'affiliate-link-manager-ai') . '</a></p><div class="postbox"><div class="inside"><h2>'.esc_html($r['title']).'</h2><p><strong>' . esc_html__('Data report:', 'affiliate-link-manager-ai') . '</strong> '.esc_html($r['created_at']).' <strong>' . esc_html__('Stato:', 'affiliate-link-manager-ai') . '</strong> '.esc_html($r['status']).' <strong>' . esc_html__('Modello:', 'affiliate-link-manager-ai') . '</strong> '.esc_html($r['model']).' <strong>' . esc_html__('Profilo runtime:', 'affiliate-link-manager-ai') . '</strong> '.esc_html($runtime['runtime_profile'] ?? $r['report_type']).'</p><p>'.esc_html($data['sintesi_generale']??($data['summary']??'')).'</p>';
        if (!empty($data['errore_tecnico'])) { echo '<details><summary>' . esc_html__('Dettaglio tecnico errore', 'affiliate-link-manager-ai') . '</summary><pre>'.esc_html((string)$data['errore_tecnico']).'</pre></details>'; }
        echo '</div></div>';
        echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('Metriche', 'affiliate-link-manager-ai') . '</h2>'; self::render_metric_cards(array(
            __('Fonti configurate', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_configurate']??0), __('Fonti interrogate', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_interrogate']??0), __('Fonti citate', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_citate']??0), __('Fonti saltate', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_saltate']??0), __('Fonti senza dati', 'affiliate-link-manager-ai')=>(int)($metrics['count_fonti_senza_risultati']??0), __('Trend', 'affiliate-link-manager-ai')=>(int)($metrics['count_trend']??0), __('Idee', 'affiliate-link-manager-ai')=>(int)($metrics['count_idee_editoriali']??0), __('Destinazioni', 'affiliate-link-manager-ai')=>(int)($metrics['count_destinazioni']??0), __('Bisogni', 'affiliate-link-manager-ai')=>(int)($metrics['count_bisogni_viaggiatori']??0), __('Affiliate', 'affiliate-link-manager-ai')=>(int)($metrics['count_opportunita_affiliate']??0), __('Alert', 'affiliate-link-manager-ai')=>(int)($metrics['count_alert_rischi']??0))); echo '</div></div>';
        self::render_report_sources($source_snapshot, $data, $metrics);
        echo '<div class="alma-report-grid">';
        self::object_card(__('Trend principali', 'affiliate-link-manager-ai'),(array)($data['trends']??($data['trend_principali']??array())),array('title','titolo','description','descrizione','confidence'));
        self::object_card(__('Destinazioni/città', 'affiliate-link-manager-ai'),(array)($data['destinazioni_prioritarie']??array()),array('nome','tipo','paese_o_area','perche_rilevante','trend_collegati','idee_collegate','confidence_score'));
        self::object_card(__('Piano editoriale', 'affiliate-link-manager-ai'),(array)($data['piano_editoriale_settimanale']??array()),array('giorno_suggerito','titolo','descrizione','categoria_editoriale','intento_ricerca','priorita','priorita_editoriale','suggerimento_cta_link_affiliato','opportunita_affiliate','fonti_collegate'));
        self::object_card(__('Idee editoriali extra', 'affiliate-link-manager-ai'),(array)($data['idee_editoriali_extra']??$data['content_ideas']??array()),array('titolo','title','descrizione','description','categoria_editoriale','intento_ricerca','priorita','opportunita_affiliate','fonti_collegate','note_per_sothra'));
        self::object_card(__('Bisogni viaggiatori', 'affiliate-link-manager-ai'),(array)($data['bisogni_viaggiatori']??array()),array('bisogno','descrizione','contenuti_utili','affiliate_possibili'));
        self::object_card(__('Opportunità affiliate', 'affiliate-link-manager-ai'),(array)($data['opportunita_affiliate']??array()),array('categoria','esempi_servizi','idee_collegate','priorita','rischio_claim','nota_editoriale'));
        self::object_card(__('Rischi e limiti', 'affiliate-link-manager-ai'),(array)($data['rischi_e_limiti']??array()),array());
        echo '</div>';
        self::render_citations_card($data);
        echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('JSON tecnico', 'affiliate-link-manager-ai') . '</h2><details open><summary>' . esc_html__('Metriche per grafici', 'affiliate-link-manager-ai') . '</summary><pre>'.esc_html(wp_json_encode($metrics, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details><details><summary>' . esc_html__('Risposta JSON normalizzata', 'affiliate-link-manager-ai') . '</summary><pre>'.esc_html(wp_json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></div></div>';
    }

    private static function render_citations_card($data) { echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('Fonti citate', 'affiliate-link-manager-ai') . '</h2>'; $items=(array)($data['fonti_citate']??($data['citations']??array())); if(!$items){ echo '<p>'.esc_html__('Nessuna fonte citata emersa da questa run. Prova un piano editoriale completo o aumenta le fonti prioritarie.', 'affiliate-link-manager-ai').'</p>'; } else { echo '<ul>'; foreach($items as $f){ $url=esc_url($f['url']??''); echo '<li>' . ($url?'<a href="'.$url.'" target="_blank" rel="noopener noreferrer">':'') . esc_html($f['titolo'] ?? ($f['title'] ?? ($f['fonte'] ?? $url))) . ($url?'</a>':'') . ' <small>' . esc_html($f['fonte'] ?? ($f['source'] ?? '')) . '</small></li>'; } echo '</ul>'; } echo '</div></div>'; }

    private static function render_report_sources($sources, $data, $metrics=array()) {
        $details = (array)($metrics['fonti_dettaglio'] ?? array());
        echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('Fonti usate', 'affiliate-link-manager-ai') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Fonte configurata', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Interrogata', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Citata', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Senza dati', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Saltata', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Fonti consultate/citate', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
        if (!$sources) { echo '<tr><td colspan="6">' . esc_html__('Nessuna configurazione fonte salvata nel report.', 'affiliate-link-manager-ai') . '</td></tr>'; }
        foreach ((array)$sources as $source) {
            $domains = (array)($source['normalized_allowed_domains'] ?? $source['allowed_domains'] ?? array());
            $detail = self::source_detail_for($details, $source);
            $matched = $detail && !empty($detail['matched_citations']) ? (array)$detail['matched_citations'] : self::matched_report_sources($domains, $data);
            echo '<tr><td><strong>' . esc_html($source['name'] ?? ($source['source_key'] ?? '')) . '</strong><br><small>' . esc_html(implode(', ', $domains)) . '</small></td><td>' . esc_html(self::yes_no($detail['interrogated'] ?? true)) . '</td><td>' . esc_html(self::yes_no($detail['cited'] ?? !empty($matched))) . '</td><td>' . esc_html(self::yes_no($detail['without_results'] ?? empty($matched))) . '</td><td>' . esc_html(self::yes_no($detail['skipped'] ?? false)) . '</td><td>';
            if (!$matched) { echo esc_html__('Nessuna citazione associata a questa fonte. Controlla le fonti citate globali sotto.', 'affiliate-link-manager-ai'); }
            foreach ($matched as $item) {
                $url = esc_url($item['url'] ?? '');
                echo '<p>' . ($url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' : '') . esc_html($item['title'] ?? ($item['titolo'] ?? ($item['domain'] ?? $url))) . ($url ? '</a>' : '') . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function source_detail_for($details, $source) { foreach((array)$details as $detail){ if(($detail['source_key']??'') === ($source['source_key']??'')){ return $detail; } } return array(); }
    private static function yes_no($value) { return $value ? __('Sì', 'affiliate-link-manager-ai') : __('No', 'affiliate-link-manager-ai'); }

    private static function matched_report_sources($domains, $data) {
        $matches = array(); $domains = array_map('strtolower', (array)$domains);
        foreach (array_merge((array)($data['fonti_web_search'] ?? array()), (array)($data['fonti_citate'] ?? array()), (array)($data['citations'] ?? array())) as $item) {
            if (!is_array($item)) { continue; }
            $domain = strtolower((string)($item['domain'] ?? $item['fonte'] ?? ''));
            if (!$domain && !empty($item['url'])) { $host = wp_parse_url((string)$item['url'], PHP_URL_HOST); $domain = strtolower((string)$host); }
            $domain = preg_replace('/^www\./', '', $domain);
            foreach ($domains as $allowed) {
                $allowed = preg_replace('/^www\./', '', (string)$allowed);
                if ($allowed !== '' && ($domain === $allowed || substr($domain, -strlen('.' . $allowed)) === '.' . $allowed)) { $matches[] = $item; break; }
            }
        }
        return array_slice($matches, 0, 5);
    }

    private static function list_card($title,$items){ echo '<div class="alma-card"><h3>'.esc_html($title).'</h3><ul>'; foreach($items as $it){ echo '<li>'.esc_html(is_scalar($it)?$it:wp_json_encode($it)).'</li>'; } echo '</ul></div>'; }
    private static function empty_section_message($title){ $title=wp_strip_all_tags((string)$title); if(stripos($title, 'Opportun')!==false){ return __('Nessuna opportunità affiliata emersa da questa run. Prova un piano editoriale completo o aumenta le fonti prioritarie.', 'affiliate-link-manager-ai'); } if(stripos($title, 'Bisogni')!==false){ return __('Nessun bisogno viaggiatore specifico emerso da questa run. Prova un piano editoriale completo o fonti più dettagliate.', 'affiliate-link-manager-ai'); } if(stripos($title, 'Destin')!==false){ return __('Nessuna destinazione reale emersa dalle fonti: non vengono creati luoghi inventati.', 'affiliate-link-manager-ai'); } return __('Nessun dato emerso da questa run. Prova un profilo più completo o aumenta le fonti prioritarie.', 'affiliate-link-manager-ai'); }
    private static function stringify($value){ if (is_scalar($value) || $value === null) { return (string)$value; } $flat = array(); foreach ((array)$value as $item) { $flat[] = is_scalar($item) ? (string)$item : wp_json_encode($item, JSON_UNESCAPED_UNICODE); } return implode(', ', $flat); }
    private static function object_card($title,$items,$fields){ echo '<div class="alma-card"><h3>'.esc_html($title).'</h3>'; if(!$items){echo '<p>'.esc_html(self::empty_section_message($title)).'</p>';} foreach($items as $it){ echo '<div style="border-top:1px solid #dcdcde;padding:8px 0">'; if(is_array($it)){ $show=$fields?:array_keys($it); foreach($show as $f){ if(isset($it[$f])){ echo '<p><strong>'.esc_html($f).':</strong> '.esc_html(self::stringify($it[$f])).'</p>'; } } } else { echo '<p>'.esc_html($it).'</p>'; } echo '</div>'; } echo '</div>'; }

    public static function handle_action() {
        if (!current_user_can(self::CAP)) { wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai')); }
        check_admin_referer('alma_trend_content_action'); $do=sanitize_key($_REQUEST['do']??''); $type='success'; $msg='Operazione completata.'; $report_id=0;
        if($do==='save_settings'){ ALMA_Trend_Content_Ideas_Store::save_settings($_POST); $msg='Configurazione Trend Idee contenuto salvata.'; }
        elseif($do==='run_source'){ $r=ALMA_Trend_Content_Ideas_Service::run_source(sanitize_key($_GET['source_key']??''), 'test'); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); $report_id=(int)($r->get_error_data()['report_id']??0); } else { $report_id=(int)$r['report_id']; $msg='Test fonte completato: '.$r['sources_count'].' fonte, durata '.$r['duration'].'s.'; } }
        elseif($do==='run_all' || $do==='generate_plan'){ $r=ALMA_Trend_Content_Ideas_Service::run_enabled($do==='generate_plan'?'manual':'test'); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); $report_id=(int)($r->get_error_data()['report_id']??0); } else { $report_id=(int)$r['report_id']; $msg=($do==='generate_plan'?'Piano editoriale generato':'Test completo completato').': '.$r['sources_count'].' fonti, stato '.$r['status'].', durata '.$r['duration'].'s.'; } }
        set_transient('alma_trend_content_notice_' . get_current_user_id(), array('type'=>$type,'message'=>$msg), 120);
        wp_safe_redirect($report_id ? self::url(array('report_id'=>$report_id)) : self::url()); exit;
    }
}
ALMA_Trend_Content_Ideas_Admin::init();
