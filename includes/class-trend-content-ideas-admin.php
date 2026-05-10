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
        $view_id = absint($_GET['report_id'] ?? 0); $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        echo '<div class="wrap alma-trend-content"><h1>Trend Idee contenuto</h1><p>Analizza fonti pubbliche sui trend di viaggio con OpenAI Web Search e genera report editoriali per Sothra. Nessuna bozza WordPress viene creata automaticamente.</p>';
        if ($notice) { printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message'])); }
        echo '<div class="notice notice-info"><p>Le chiamate OpenAI partono solo dai pulsanti di test/generazione o da WP Cron, mai durante il semplice caricamento della pagina.</p></div>';
        if (!ALMA_Trend_Content_Ideas_Service::is_openai_ready()) { echo '<div class="notice notice-warning"><p>Configura la chiave OpenAI nelle impostazioni del plugin prima di eseguire test AI reali.</p></div>'; }
        if ($view_id) { self::render_report($view_id); echo '</div>'; return; }
        self::render_tabs($tab);
        if ($tab === 'fonti-trend') { self::render_sources_tab(); }
        else { self::render_settings(); self::render_latest(); self::render_history(); }
        echo '</div>';
    }

    private static function render_tabs($active) {
        $tabs = array('dashboard'=>__('Dashboard e report', 'affiliate-link-manager-ai'), 'fonti-trend'=>__('Fonti Trend', 'affiliate-link-manager-ai'));
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key=>$label) { echo '<a class="nav-tab ' . ($active === $key ? 'nav-tab-active' : '') . '" href="' . esc_url(self::url(array('tab'=>$key))) . '">' . esc_html($label) . '</a>'; }
        echo '</h2>';
    }

    private static function render_settings() {
        $model_details = ALMA_Trend_Content_Ideas_Service::effective_model_details();
        $model = !empty($model_details['legacy_ignored']) ? '' : $model_details['trend_model_saved']; $timeout = get_option(ALMA_Trend_Content_Ideas_Store::OPTION_TIMEOUT, 90); $global_model = $model_details['global_model']; $effective_model = $model_details['effective_model']; $legacy_ignored = !empty($model_details['legacy_ignored']);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="alma_trend_content_action"><input type="hidden" name="do" value="save_settings">'; if ($legacy_ignored) { echo '<input type="hidden" name="legacy_model_ignored" value="1">'; } wp_nonce_field('alma_trend_content_action');
        echo '<div class="postbox"><div class="inside"><h2>Configurazione generale</h2><table class="form-table"><tr><th><label for="global_prompt">Prompt globale</label></th><td><textarea id="global_prompt" name="global_prompt" class="large-text" rows="6">' . esc_textarea(get_option(ALMA_Trend_Content_Ideas_Store::OPTION_GLOBAL_PROMPT, ALMA_Trend_Content_Ideas_Prompt_Builder::default_global_prompt())) . '</textarea><p class="description">Questo testo viene combinato con istruzioni non modificabili e prompt specifici fonte.</p></td></tr><tr><th><label for="model">Modello OpenAI</label></th><td><input id="model" name="model" class="regular-text" value="' . esc_attr($model) . '" placeholder="Usa modello globale"><p class="description">Lascia vuoto per usare il modello globale OpenAI del plugin' . ($global_model ? ' (' . esc_html($global_model) . ')' : '') . '. Modello effettivo: <strong>' . esc_html($effective_model) . '</strong>.</p></td></tr><tr><th><label for="timeout">Timeout ricerca</label></th><td><input id="timeout" name="timeout" type="number" min="20" max="180" value="' . esc_attr($timeout) . '"> secondi</td></tr></table><p><button class="button button-primary">Salva configurazione</button> <a class="button" href="' . esc_url(self::action_url(array('do'=>'run_all'))) . '">Esegui test completo adesso</a> <a class="button button-secondary" href="' . esc_url(self::action_url(array('do'=>'generate_plan'))) . '">Genera piano editoriale</a> <a class="button" href="' . esc_url(self::url(array('tab'=>'fonti-trend'))) . '">Gestisci Fonti Trend</a></p></div></div></form>';
        self::render_inline_styles();
    }

    private static function render_sources_tab() {
        $filter = sanitize_key($_GET['source_filter'] ?? 'all');
        $sources = ALMA_Trend_Content_Ideas_Store::get_sources(false, $filter);
        $editing = !empty($_GET['edit_source']) ? ALMA_Trend_Content_Ideas_Store::get_source(sanitize_key($_GET['edit_source'])) : array();
        echo '<div class="postbox"><div class="inside"><h2>Fonti Trend</h2><p>Gestisci le fonti usate dalle run Trend: le fonti disattivate o in revisione non entrano nei test completi e nei piani editoriali automatici.</p>';
        self::render_source_filters($filter);
        echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Stato</th><th>Attiva</th><th>Priorità</th><th>Quantità contenuti</th><th>Categoria</th><th>Area geografica</th><th>Domini</th><th>Ultimo test</th><th>Ultimo esito</th><th>Azioni</th></tr></thead><tbody>';
        if (!$sources) { echo '<tr><td colspan="11">Nessuna fonte trovata.</td></tr>'; }
        foreach ($sources as $src) { self::render_source_row($src); }
        echo '</tbody></table></div></div>';
        self::render_source_form($editing, $editing ? __('Modifica fonte', 'affiliate-link-manager-ai') : __('Aggiungi nuova fonte', 'affiliate-link-manager-ai'));
        if ($editing) { self::render_source_form(array(), __('Aggiungi nuova fonte', 'affiliate-link-manager-ai')); }
        self::render_inline_styles();
    }

    private static function render_source_filters($active) {
        $filters = array('all'=>'Tutte','active'=>'Attive','inactive'=>'Disattivate','needs_review'=>'Da verificare','no_recent_results'=>'Senza risultati','blocked'=>'Non raggiungibili');
        echo '<p class="subsubsub">'; $parts=array();
        foreach ($filters as $key=>$label) { $parts[] = '<a class="' . ($active === $key ? 'current' : '') . '" href="' . esc_url(self::url(array('tab'=>'fonti-trend','source_filter'=>$key))) . '">' . esc_html($label) . '</a>'; }
        echo implode(' | ', $parts) . '</p><div style="clear:both"></div>';
    }

    private static function render_source_row($src) {
        $labels = ALMA_Trend_Content_Ideas_Store::status_labels(); $key = $src['source_key']; $domains = ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains']);
        echo '<tr><td><strong>' . esc_html($src['name']) . '</strong><br><code>' . esc_html($key) . '</code></td><td><span class="alma-badge">' . esc_html($labels[$src['status']] ?? $src['status']) . '</span></td><td>' . esc_html((int)$src['enabled'] ? 'Sì' : 'No') . '</td><td>' . (int)$src['priority'] . '</td><td>' . (int)$src['max_contents_per_run'] . '</td><td>' . esc_html($src['category']) . '</td><td>' . esc_html($src['area_geografica'] ?? '') . '</td><td>' . esc_html(implode(', ', $domains)) . '</td><td>' . esc_html($src['last_tested_at'] ?: 'Mai') . '</td><td>' . esc_html($src['last_test_message'] ?: ($src['last_test_status'] ?: '—')) . '</td><td>';
        echo '<a class="button button-small" href="' . esc_url(self::url(array('tab'=>'fonti-trend','edit_source'=>$key))) . '">Modifica</a> ';
        echo '<a class="button button-small" href="' . esc_url(self::action_url(array('do'=>'duplicate_source','source_key'=>$key))) . '">Duplica</a> ';
        echo '<a class="button button-small" href="' . esc_url(self::action_url(array('do'=>((int)$src['enabled'] ? 'deactivate_source' : 'activate_source'),'source_key'=>$key))) . '">' . esc_html((int)$src['enabled'] ? 'Disattiva' : 'Attiva') . '</a> ';
        echo '<a class="button button-small" href="' . esc_url(self::action_url(array('do'=>'delete_source','source_key'=>$key))) . '" onclick="return confirm(\'Eliminare o disattivare questa fonte?\')">Elimina</a> ';
        echo '<a class="button button-small" href="' . esc_url(self::action_url(array('do'=>'test_accessibility','source_key'=>$key))) . '">Test accessibilità</a> ';
        echo '<a class="button button-small" href="' . esc_url(self::action_url(array('do'=>'run_source','source_key'=>$key))) . '">Test fonte AI</a>';
        echo '</td></tr>';
    }

    private static function render_source_form($src, $title) {
        $is_edit = !empty($src); $statuses = array_intersect_key(ALMA_Trend_Content_Ideas_Store::status_labels(), array_flip(array('active','inactive','needs_review','working','no_recent_results','blocked_or_unreachable','domain_too_broad','domain_mismatch')));
        $domains = $is_edit ? implode("\n", ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains'])) : '';
        echo '<div class="postbox"><div class="inside"><h2>' . esc_html($title) . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="alma_trend_content_action"><input type="hidden" name="do" value="' . ($is_edit ? 'update_source' : 'create_source') . '">'; wp_nonce_field('alma_trend_content_action');
        if ($is_edit) { echo '<input type="hidden" name="source_key_original" value="' . esc_attr($src['source_key']) . '">'; }
        echo '<table class="form-table"><tr><th>Nome fonte</th><td><input class="regular-text" name="source[name]" required value="' . esc_attr($src['name'] ?? '') . '"></td></tr>';
        echo '<tr><th>Chiave fonte</th><td><input class="regular-text" name="source[source_key]" ' . ($is_edit ? 'readonly' : 'required') . ' value="' . esc_attr($src['source_key'] ?? '') . '"><p class="description">Slug univoco, es. ente_turismo_locale.</p></td></tr>';
        echo '<tr><th>Categoria</th><td><input class="regular-text" name="source[category]" value="' . esc_attr($src['category'] ?? '') . '"></td></tr>';
        echo '<tr><th>Area geografica</th><td><input class="regular-text" name="source[area_geografica]" value="' . esc_attr($src['area_geografica'] ?? '') . '"></td></tr>';
        echo '<tr><th>Priorità</th><td><input type="number" min="1" max="3" name="source[priority]" value="' . esc_attr($src['priority'] ?? 2) . '"> <span class="description">1 alta, 3 bassa.</span></td></tr>';
        echo '<tr><th>Quantità contenuti da analizzare</th><td><input type="number" min="1" max="10" name="source[max_contents_per_run]" value="' . esc_attr($src['max_contents_per_run'] ?? 3) . '"></td></tr>';
        echo '<tr><th>Domini consentiti</th><td><textarea class="large-text" rows="3" name="source[allowed_domains]">' . esc_textarea($domains) . '</textarea><p class="description">Un dominio per riga o separato da virgola. Vengono normalizzati automaticamente.</p></td></tr>';
        echo '<tr><th>Prompt specifico</th><td><textarea class="large-text" rows="4" name="source[custom_prompt]">' . esc_textarea($src['custom_prompt'] ?? '') . '</textarea></td></tr>';
        echo '<tr><th>Intervallo minimo analisi</th><td><input type="number" min="1" max="365" name="source[interval_days]" value="' . esc_attr($src['interval_days'] ?? 7) . '"> giorni</td></tr>';
        echo '<tr><th>Stato fonte</th><td><select name="source[status]">'; foreach ($statuses as $key=>$label) { echo '<option value="' . esc_attr($key) . '" ' . selected($src['status'] ?? 'active', $key, false) . '>' . esc_html($label) . '</option>'; } echo '</select> <label><input type="checkbox" name="source[enabled]" value="1" ' . checked((int)($src['enabled'] ?? 1), 1, false) . '> Attiva</label></td></tr>';
        echo '<tr><th>Note interne</th><td><textarea class="large-text" rows="3" name="source[notes]">' . esc_textarea($src['notes'] ?? '') . '</textarea></td></tr>';
        echo '</table><p><button class="button button-primary">' . esc_html($is_edit ? 'Salva modifiche fonte' : 'Aggiungi nuova fonte') . '</button></p></form></div></div>';
    }

    public static function register_dashboard_widget() { if (!current_user_can(self::CAP)) { return; } wp_add_dashboard_widget('alma_trend_content_ideas_dashboard_widget', __('Trend Idee contenuto', 'affiliate-link-manager-ai'), array(__CLASS__, 'dashboard_widget'), null, null, 'normal', 'high'); self::promote_dashboard_widget('alma_trend_content_ideas_dashboard_widget'); }
    public static function dashboard_widget() { self::dashboard_box(false); }
    private static function promote_dashboard_widget($widget_id) { global $wp_meta_boxes; if (empty($wp_meta_boxes['dashboard']['normal']['high'][$widget_id])) { return; } $widget = array($widget_id => $wp_meta_boxes['dashboard']['normal']['high'][$widget_id]); unset($wp_meta_boxes['dashboard']['normal']['high'][$widget_id]); $wp_meta_boxes['dashboard']['normal']['high'] = $widget + (array)$wp_meta_boxes['dashboard']['normal']['high']; }

    public static function dashboard_box($wrap_postbox = true) {
        self::render_inline_styles(); $r = ALMA_Trend_Content_Ideas_Store::latest_report();
        if ($wrap_postbox) { echo '<div class="postbox"><div class="inside"><h2>' . esc_html__('Trend Idee contenuto', 'affiliate-link-manager-ai') . '</h2>'; }
        if (!$r) { echo '<p>' . esc_html__('Nessun report Trend disponibile. Genera il primo report.', 'affiliate-link-manager-ai') . '</p><p><a class="button button-primary" href="' . esc_url(self::url()) . '">' . esc_html__('Vai a Trend Idee contenuto', 'affiliate-link-manager-ai') . '</a></p>'; self::render_dashboard_sources_to_review(); if ($wrap_postbox) { echo '</div></div>'; } return; }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']); $runtime=(array)($data['runtime']??array());
        echo '<p><strong>Data report:</strong> ' . esc_html($r['created_at']) . ' &nbsp; <strong>Stato:</strong> ' . esc_html($r['status']) . ' &nbsp; <strong>Modello:</strong> ' . esc_html($r['model']) . ' &nbsp; <strong>Profilo:</strong> ' . esc_html($runtime['runtime_profile'] ?? $r['report_type']) . '</p>';
        self::render_metric_cards(array('Fonti config.'=>(int)($metrics['count_fonti_configurate']??0),'Citate/interr.'=>(int)($metrics['count_fonti_citate']??0) . '/' . (int)($metrics['count_fonti_interrogate']??0),'Senza risultati'=>(int)($metrics['count_fonti_senza_risultati']??0),'Da verificare'=>(int)($metrics['count_fonti_da_verificare']??0),'Idee'=>(int)($metrics['count_idee_editoriali']??0),'Alert'=>(int)($metrics['count_alert_rischi']??0)));
        echo '<div class="alma-dashboard-lists">';
        self::dashboard_list(__('Top trend', 'affiliate-link-manager-ai'), array_slice((array)($data['trends']??($data['trend_principali']??array())),0,5), array('title','titolo','nome'));
        self::dashboard_list(__('Top destinazioni/città', 'affiliate-link-manager-ai'), array_slice((array)($data['destinazioni_prioritarie']??array()),0,5), array('nome','paese_o_area'));
        self::dashboard_list(__('Top idee editoriali', 'affiliate-link-manager-ai'), array_slice((array)($data['piano_editoriale_settimanale']??($data['content_ideas']??array())),0,5), array('titolo','title'));
        echo '</div>'; self::render_dashboard_sources_to_review();
        echo '<p><a class="button button-primary" href="' . esc_url(self::url(array('report_id'=>(int)$r['id']))) . '">Apri report completo</a> <a class="button" href="' . esc_url(self::url(array('tab'=>'fonti-trend'))) . '">Gestisci fonti</a></p>';
        if ($wrap_postbox) { echo '</div></div>'; }
    }

    private static function render_dashboard_sources_to_review() {
        $items = ALMA_Trend_Content_Ideas_Store::list_sources_to_review(5);
        echo '<div class="alma-dashboard-review"><h3>Fonti da verificare</h3>';
        if (!$items) { echo '<p><em>Nessuna fonte problematica salvata.</em></p></div>'; return; }
        echo '<ul>'; foreach ($items as $src) { echo '<li><a href="' . esc_url(self::url(array('tab'=>'fonti-trend','edit_source'=>$src['source_key']))) . '">' . esc_html($src['name']) . '</a> — ' . esc_html($src['last_test_status'] ?: $src['status']) . '</li>'; } echo '</ul></div>';
    }

    private static function render_metric_cards($metrics) { echo '<div class="alma-metric-cards">'; foreach($metrics as $label=>$value){ echo '<div class="alma-metric-card"><strong>'.esc_html((string)$value).'</strong><span>'.esc_html($label).'</span></div>'; } echo '</div>'; }
    private static function dashboard_list($title, $items, $fields=array()) { echo '<div><h3>'.esc_html($title).'</h3>'; if(!$items){ echo '<p><em>Nessun dato in questa run.</em></p></div>'; return; } echo '<ol>'; foreach(array_slice((array)$items,0,5) as $item){ $text=is_scalar($item)?(string)$item:self::first_field($item,$fields); echo '<li>'.esc_html($text).'</li>'; } echo '</ol></div>'; }
    private static function first_field($item,$fields){ foreach($fields as $f){ if(!empty($item[$f])){ return is_array($item[$f]) ? implode(', ', array_map('strval',$item[$f])) : (string)$item[$f]; } } return wp_json_encode($item, JSON_UNESCAPED_UNICODE); }
    private static function render_latest() { echo '<div id="ultimo-report">'; self::dashboard_box(); echo '</div>'; }
    private static function render_history() { $page=max(1,absint($_GET['paged']??1)); $reports=ALMA_Trend_Content_Ideas_Store::get_reports($page,10); echo '<div class="postbox"><div class="inside"><h2>Storico report</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Titolo</th><th>Tipo</th><th>Stato</th><th>Sintesi</th><th>Azioni</th></tr></thead><tbody>'; if(!$reports){echo '<tr><td colspan="6">Nessun report salvato.</td></tr>';} foreach($reports as $r){ echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['report_type']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html(wp_trim_words($r['summary'],18)).'</td><td><a class="button button-small" href="'.esc_url(self::url(array('report_id'=>(int)$r['id']))).'">Apri</a></td></tr>'; } echo '</tbody></table></div></div>'; }

    private static function render_report($id) {
        $r=ALMA_Trend_Content_Ideas_Store::get_report($id); if(!$r){ echo '<div class="notice notice-error"><p>Report non trovato.</p></div>'; return; }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']); $source_snapshot=ALMA_Trend_Content_Ideas_Store::decode_json($r['sources_json']); $runtime=(array)($data['runtime']??array());
        echo '<p><a class="button" href="'.esc_url(self::url()).'">← Torna a Trend Idee contenuto</a></p><div class="postbox"><div class="inside"><h2>'.esc_html($r['title']).'</h2><p><strong>Data report:</strong> '.esc_html($r['created_at']).' <strong>Stato:</strong> '.esc_html($r['status']).' <strong>Modello:</strong> '.esc_html($r['model']).' <strong>Profilo runtime:</strong> '.esc_html($runtime['runtime_profile'] ?? $r['report_type']).'</p><p>'.esc_html($data['sintesi_generale']??($data['summary']??'')).'</p>';
        if (!empty($data['errore_tecnico'])) { echo '<details><summary>Dettaglio tecnico errore</summary><pre>'.esc_html((string)$data['errore_tecnico']).'</pre></details>'; }
        echo '</div></div><div class="postbox"><div class="inside"><h2>Metriche</h2>'; self::render_metric_cards(array('Fonti configurate'=>(int)($metrics['count_fonti_configurate']??0), 'Fonti interrogate'=>(int)($metrics['count_fonti_interrogate']??0), 'Fonti citate'=>(int)($metrics['count_fonti_citate']??0), 'Fonti saltate'=>(int)($metrics['count_fonti_saltate']??0), 'Fonti senza risultati'=>(int)($metrics['count_fonti_senza_risultati']??0), 'Fonti non raggiungibili'=>(int)($metrics['count_fonti_non_raggiungibili']??0), 'Fonti da verificare'=>(int)($metrics['count_fonti_da_verificare']??0))); echo '</div></div>';
        self::render_report_sources($source_snapshot, $data, $metrics); self::render_source_diagnostics($metrics);
        echo '<div class="alma-report-grid">'; self::object_card('Trend principali',(array)($data['trends']??($data['trend_principali']??array())),array('title','titolo','description','descrizione','confidence')); self::object_card('Destinazioni/città',(array)($data['destinazioni_prioritarie']??array()),array('nome','tipo','paese_o_area','perche_rilevante','trend_collegati','idee_collegate','confidence_score')); self::object_card('Piano editoriale',(array)($data['piano_editoriale_settimanale']??array()),array('giorno_suggerito','titolo','descrizione','categoria_editoriale','intento_ricerca','priorita','opportunita_affiliate','fonti_collegate')); echo '</div>'; self::render_citations_card($data);
    }

    private static function render_citations_card($data) { echo '<div class="postbox"><div class="inside"><h2>Fonti citate</h2>'; $items=(array)($data['fonti_citate']??($data['citations']??array())); if(!$items){ echo '<p>Nessuna fonte citata emersa da questa run.</p>'; } else { echo '<ul>'; foreach($items as $f){ $url=esc_url($f['url']??''); echo '<li>' . ($url?'<a href="'.$url.'" target="_blank" rel="noopener noreferrer">':'') . esc_html($f['titolo'] ?? ($f['title'] ?? ($f['fonte'] ?? $url))) . ($url?'</a>':'') . ' <small>' . esc_html($f['fonte'] ?? ($f['source'] ?? '')) . '</small></li>'; } echo '</ul>'; } echo '</div></div>'; }

    private static function render_report_sources($sources, $data, $metrics=array()) {
        $details=(array)($metrics['fonti_dettaglio']??array()); echo '<div class="postbox"><div class="inside"><h2>Fonti configurate/interrogate</h2><table class="widefat striped"><thead><tr><th>Fonte</th><th>Interrogata</th><th>Citata</th><th>Senza risultati</th><th>Saltata</th><th>Non raggiungibile</th><th>Da verificare</th><th>Dettaglio</th></tr></thead><tbody>';
        foreach((array)$sources as $source){ $detail=self::source_detail_for($details,$source); $domains=(array)($source['normalized_allowed_domains'] ?? $source['allowed_domains'] ?? array()); $matched=(array)($detail['matched_citations'] ?? self::matched_report_sources($domains, $data)); echo '<tr><td><strong>'.esc_html($source['name'] ?? ($source['source_key'] ?? '')).'</strong><br><small>'.esc_html(implode(', ', $domains)).'</small></td><td>'.esc_html(self::yes_no($detail['interrogated'] ?? true)).'</td><td>'.esc_html(self::yes_no($detail['cited'] ?? !empty($matched))).'</td><td>'.esc_html(self::yes_no($detail['without_results'] ?? empty($matched))).'</td><td>'.esc_html(self::yes_no($detail['skipped'] ?? false)).'</td><td>'.esc_html(self::yes_no($detail['unreachable'] ?? false)).'</td><td>'.esc_html(self::yes_no($detail['needs_review'] ?? false)).'</td><td>'; if(!$matched){ echo esc_html($detail['message'] ?? __('Fonte inclusa nella run ma senza citazioni associate. Potrebbe non aver prodotto risultati utili o non essere stata selezionata dalla Web Search.', 'affiliate-link-manager-ai')); } foreach($matched as $item){ $url=esc_url($item['url'] ?? ''); echo '<p>'.($url?'<a href="'.$url.'" target="_blank" rel="noopener noreferrer">':'').esc_html($item['title'] ?? ($item['titolo'] ?? ($item['domain'] ?? $url))).($url?'</a>':'').'</p>'; } echo '</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    private static function render_source_diagnostics($metrics) {
        $problematic = array(); foreach ((array)($metrics['fonti_dettaglio'] ?? array()) as $d) { if (!empty($d['without_results']) || !empty($d['unreachable']) || !empty($d['needs_review'])) { $problematic[] = $d; } }
        echo '<div class="postbox"><div class="inside"><h2>Diagnostica fonti</h2>'; if (!$problematic) { echo '<p>Nessuna fonte problematica nel report.</p>'; } else { echo '<ul>'; foreach ($problematic as $d) { echo '<li><strong>' . esc_html($d['name'] ?? $d['source_key']) . '</strong>: ' . esc_html($d['status'] ?? 'needs_review') . ' — ' . esc_html($d['message'] ?? '') . '</li>'; } echo '</ul>'; } echo '</div></div>';
    }

    private static function source_detail_for($details, $source) { foreach((array)$details as $detail){ if(($detail['source_key']??'') === ($source['source_key']??'')){ return $detail; } } return array(); }
    private static function yes_no($value) { return $value ? __('Sì', 'affiliate-link-manager-ai') : __('No', 'affiliate-link-manager-ai'); }
    private static function matched_report_sources($domains, $data) { $matches = array(); $domains = array_map('strtolower', (array)$domains); foreach (array_merge((array)($data['fonti_web_search'] ?? array()), (array)($data['fonti_citate'] ?? array()), (array)($data['citations'] ?? array())) as $item) { if (!is_array($item)) { continue; } $domain = strtolower((string)($item['domain'] ?? $item['fonte'] ?? '')); if (!$domain && !empty($item['url'])) { $domain = strtolower((string)wp_parse_url((string)$item['url'], PHP_URL_HOST)); } $domain = preg_replace('/^www\./', '', $domain); foreach ($domains as $allowed) { $allowed = preg_replace('/^www\./', '', (string)$allowed); if ($allowed !== '' && ($domain === $allowed || substr($domain, -strlen('.' . $allowed)) === '.' . $allowed)) { $matches[] = $item; break; } } } return array_slice($matches, 0, 5); }
    private static function object_card($title,$items,$fields){ echo '<div class="alma-card"><h3>'.esc_html($title).'</h3>'; if(!$items){echo '<p>Nessun dato emerso da questa run.</p>';} foreach($items as $it){ echo '<div style="border-top:1px solid #dcdcde;padding:8px 0">'; if(is_array($it)){ foreach($fields as $f){ if(isset($it[$f])){ echo '<p><strong>'.esc_html($f).':</strong> '.esc_html(is_array($it[$f]) ? implode(', ', array_map('strval',$it[$f])) : (string)$it[$f]).'</p>'; } } } else { echo '<p>'.esc_html($it).'</p>'; } echo '</div>'; } echo '</div>'; }
    private static function render_inline_styles(){ echo '<style>.alma-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#dcdcde}.alma-metric-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:10px 0}.alma-metric-card{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px}.alma-metric-card strong{display:block;font-size:18px}.alma-dashboard-lists,.alma-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.alma-card{border:1px solid #dcdcde;background:#fff;padding:10px}</style>'; }

    public static function handle_action() {
        if (!current_user_can(self::CAP)) { wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai')); }
        check_admin_referer('alma_trend_content_action'); $do=sanitize_key($_REQUEST['do']??''); $type='success'; $msg='Operazione completata.'; $report_id=0; $redirect=self::url();
        if($do==='save_settings'){ ALMA_Trend_Content_Ideas_Store::save_settings($_POST); $msg='Configurazione Trend Idee contenuto salvata.'; }
        elseif($do==='create_source'){ $r=ALMA_Trend_Content_Ideas_Store::create_source($_POST['source'] ?? array()); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); } else { $msg='Fonte Trend aggiunta.'; $redirect=self::url(array('tab'=>'fonti-trend')); } }
        elseif($do==='update_source'){ $r=ALMA_Trend_Content_Ideas_Store::update_source(sanitize_key($_POST['source_key_original'] ?? ''), $_POST['source'] ?? array()); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); } else { $msg='Fonte Trend aggiornata.'; $redirect=self::url(array('tab'=>'fonti-trend')); } }
        elseif($do==='duplicate_source'){ $r=ALMA_Trend_Content_Ideas_Store::duplicate_source(sanitize_key($_GET['source_key']??'')); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); } else { $msg='Fonte duplicata come disattivata.'; } $redirect=self::url(array('tab'=>'fonti-trend')); }
        elseif($do==='deactivate_source' || $do==='activate_source'){ ALMA_Trend_Content_Ideas_Store::deactivate_source(sanitize_key($_GET['source_key']??''), $do==='activate_source'); $msg=$do==='activate_source'?'Fonte attivata.':'Fonte disattivata.'; $redirect=self::url(array('tab'=>'fonti-trend')); }
        elseif($do==='delete_source'){ $r=ALMA_Trend_Content_Ideas_Store::delete_source(sanitize_key($_GET['source_key']??'')); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); } else { $msg=$r==='deleted'?'Fonte eliminata.':'Fonte disattivata/archiviata perché default o con storico.'; } $redirect=self::url(array('tab'=>'fonti-trend')); }
        elseif($do==='test_accessibility'){ $r=ALMA_Trend_Content_Ideas_Service::test_source_accessibility(sanitize_key($_GET['source_key']??'')); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); } else { $msg='Test accessibilità completato: '.$r['message']; } $redirect=self::url(array('tab'=>'fonti-trend')); }
        elseif($do==='run_source'){ $r=ALMA_Trend_Content_Ideas_Service::run_source(sanitize_key($_GET['source_key']??''), 'test'); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); $report_id=(int)($r->get_error_data()['report_id']??0); } else { $report_id=(int)$r['report_id']; $msg='Test fonte AI completato: '.$r['sources_count'].' fonte, durata '.$r['duration'].'s.'; } }
        elseif($do==='run_all' || $do==='generate_plan'){ $r=ALMA_Trend_Content_Ideas_Service::run_enabled($do==='generate_plan'?'manual':'test'); if(is_wp_error($r)){ $type='error'; $msg=$r->get_error_message(); $report_id=(int)($r->get_error_data()['report_id']??0); } else { $report_id=(int)$r['report_id']; $msg=($do==='generate_plan'?'Piano editoriale generato':'Test completo completato').': '.$r['sources_count'].' fonti, stato '.$r['status'].', durata '.$r['duration'].'s.'; } }
        set_transient('alma_trend_content_notice_' . get_current_user_id(), array('type'=>$type,'message'=>$msg), 120);
        wp_safe_redirect($report_id ? self::url(array('report_id'=>$report_id)) : $redirect); exit;
    }
}
ALMA_Trend_Content_Ideas_Admin::init();
