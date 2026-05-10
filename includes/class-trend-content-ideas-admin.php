<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Admin {
    const SLUG = 'alma-trend-content-ideas';
    const CAP = 'manage_options';

    public static function init() { add_action('admin_post_alma_trend_content_action', array(__CLASS__, 'handle_action')); }
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
        echo '<style>.alma-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#dcdcde}.alma-badge-success{background:#00a32a;color:#fff}.alma-badge-muted{background:#646970;color:#fff}.alma-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.alma-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.alma-card h3{margin-top:0}.alma-pill{display:inline-block;padding:2px 7px;border-radius:999px;background:#f0f0f1;margin:2px}</style>';
    }

    public static function dashboard_box() {
        $r = ALMA_Trend_Content_Ideas_Store::latest_report();
        echo '<div class="postbox"><div class="inside"><h2>Trend Idee contenuto</h2>';
        if (!$r) { echo '<p>Nessun report ancora generato.</p><p><a class="button" href="' . esc_url(self::url()) . '">Configura fonti</a></p></div></div>'; return; }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']); $source_snapshot=ALMA_Trend_Content_Ideas_Store::decode_json($r['sources_json']); $dest=array_slice((array)($data['destinazioni_prioritarie']??array()),0,5); $ideas=array_slice((array)($data['piano_editoriale_settimanale']??array()),0,5); $alerts=array_slice((array)($data['alert']??array()),0,5);
        echo '<p><strong>Ultimo report:</strong> ' . esc_html($r['created_at']) . ' — <strong>Stato:</strong> ' . esc_html($r['status']) . '</p><ul><li>Fonti analizzate: ' . (int)($metrics['count_fonti_analizzate']??0) . '</li><li>Destinazioni individuate: ' . (int)($metrics['count_destinazioni']??0) . '</li><li>Idee editoriali generate: ' . (int)($metrics['count_idee_editoriali']??0) . '</li></ul>';
        echo '<h3>Top destinazioni</h3><ol>'; foreach($dest as $d){ echo '<li>' . esc_html($d['nome'] ?? '') . ' <small>' . esc_html($d['paese_o_area'] ?? '') . '</small></li>'; } echo '</ol><h3>Top idee contenuto</h3><ol>'; foreach($ideas as $i){ echo '<li>' . esc_html($i['titolo'] ?? '') . '</li>'; } echo '</ol>';
        if($alerts){ echo '<h3>Alert principali</h3><ul>'; foreach($alerts as $a){ echo '<li>' . esc_html(is_scalar($a)?$a:wp_json_encode($a)) . '</li>'; } echo '</ul>'; }
        echo '<p><a class="button button-primary" href="' . esc_url(self::url(array('report_id'=>(int)$r['id']))) . '">Apri report completo</a> <a class="button" href="' . esc_url(self::url()) . '">Configura fonti</a> <a class="button" href="' . esc_url(self::action_url(array('do'=>'run_all'))) . '">Esegui test ora</a></p></div></div>';
    }

    private static function render_latest() { echo '<div id="ultimo-report">'; self::dashboard_box(); echo '</div>'; }
    private static function render_history() { $page=max(1,absint($_GET['paged']??1)); $reports=ALMA_Trend_Content_Ideas_Store::get_reports($page,10); echo '<div class="postbox"><div class="inside"><h2>Storico report</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Titolo</th><th>Tipo</th><th>Stato</th><th>Sintesi</th><th>Azioni</th></tr></thead><tbody>'; if(!$reports){echo '<tr><td colspan="6">Nessun report salvato.</td></tr>';} foreach($reports as $r){ echo '<tr><td>'.esc_html($r['created_at']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['report_type']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html(wp_trim_words($r['summary'],18)).'</td><td><a class="button button-small" href="'.esc_url(self::url(array('report_id'=>(int)$r['id']))).'">Apri</a></td></tr>'; } echo '</tbody></table></div></div>'; }

    private static function render_report($id) {
        $r=ALMA_Trend_Content_Ideas_Store::get_report($id); if(!$r){ echo '<div class="notice notice-error"><p>Report non trovato.</p></div>'; return; }
        $data=ALMA_Trend_Content_Ideas_Store::decode_json($r['result_json']); $metrics=ALMA_Trend_Content_Ideas_Store::decode_json($r['metrics_json']); $source_snapshot=ALMA_Trend_Content_Ideas_Store::decode_json($r['sources_json']);
        echo '<p><a class="button" href="'.esc_url(self::url()).'">← Torna a Trend Idee contenuto</a></p><div class="postbox"><div class="inside"><h2>'.esc_html($r['title']).'</h2><p><strong>Data:</strong> '.esc_html($r['created_at']).' <strong>Stato:</strong> '.esc_html($r['status']).' <strong>Modello:</strong> '.esc_html($r['model']).'</p><p>'.esc_html($data['sintesi_generale']??'').'</p>';
        if (!empty($data['errore_tecnico'])) { echo '<details><summary>Dettaglio tecnico errore</summary><pre>'.esc_html((string)$data['errore_tecnico']).'</pre></details>'; }
        echo '</div></div>';
        self::render_report_sources($source_snapshot, $data);
        echo '<div class="alma-report-grid">'; self::list_card('Fonti analizzate',(array)($data['fonti_analizzate']??array())); self::object_card('Destinazioni prioritarie',(array)($data['destinazioni_prioritarie']??array()),array('nome','paese_o_area','trend_score','confidence_score','motivazione')); self::object_card('Piano editoriale settimanale',(array)($data['piano_editoriale_settimanale']??array()),array('giorno_suggerito','titolo','tipo_contenuto','destinazione','priorita_editoriale','livello_confidenza','azione_consigliata')); self::object_card('Bisogni viaggiatori',(array)($data['bisogni_viaggiatori']??array()),array()); self::object_card('Opportunità affiliate',(array)($data['opportunita_affiliate']??array()),array()); self::object_card('Rischi e limiti',(array)($data['rischi_e_limiti']??array()),array()); echo '</div>';
        echo '<div class="postbox"><div class="inside"><h2>Fonti citate</h2><ul>'; foreach((array)($data['fonti_citate']??array()) as $f){ $url=esc_url($f['url']??''); echo '<li>' . ($url?'<a href="'.$url.'" target="_blank" rel="noopener noreferrer">':'') . esc_html($f['titolo'] ?? ($f['fonte'] ?? $url)) . ($url?'</a>':'') . '</li>'; } echo '</ul></div></div>';
        if (!empty($data['fonti_web_search'])) { echo '<div class="postbox"><div class="inside"><h2>Fonti consultate da Web Search</h2><ul>'; foreach((array)$data['fonti_web_search'] as $f){ $url=esc_url($f['url']??''); echo '<li>' . ($url?'<a href="'.$url.'" target="_blank" rel="noopener noreferrer">':'') . esc_html($f['title'] ?? ($f['domain'] ?? $url)) . ($url?'</a>':'') . ' <small>' . esc_html($f['domain'] ?? '') . '</small></li>'; } echo '</ul></div></div>'; }
        echo '<div class="postbox"><div class="inside"><h2>Metriche per grafici</h2><pre>'.esc_html(wp_json_encode($metrics, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><details><summary>Sezione tecnica JSON</summary><pre>'.esc_html(wp_json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details></div></div>';
    }


    private static function render_report_sources($sources, $data) {
        echo '<div class="postbox"><div class="inside"><h2>Fonti configurate nell’analisi</h2><table class="widefat striped"><thead><tr><th>Fonte</th><th>Priorità</th><th>Quantità contenuti configurata</th><th>Domini consentiti</th><th>Fonti consultate/citate</th></tr></thead><tbody>';
        if (!$sources) { echo '<tr><td colspan="5">Nessuna configurazione fonte salvata nel report.</td></tr>'; }
        foreach ((array)$sources as $source) {
            $domains = (array)($source['normalized_allowed_domains'] ?? $source['allowed_domains'] ?? array());
            $matched = self::matched_report_sources($domains, $data);
            echo '<tr><td>' . esc_html($source['name'] ?? ($source['source_key'] ?? '')) . '</td><td>' . esc_html((string)ALMA_Trend_Content_Ideas_Store::normalize_priority($source['priority'] ?? 2)) . '</td><td>' . esc_html((string)ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($source['max_contents_per_run'] ?? 3)) . '</td><td>' . esc_html(implode(', ', $domains)) . '</td><td>';
            if (!$matched) { echo 'Non disponibili'; }
            foreach ($matched as $item) {
                $url = esc_url($item['url'] ?? '');
                echo '<p>' . ($url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' : '') . esc_html($item['title'] ?? ($item['titolo'] ?? ($item['domain'] ?? $url))) . ($url ? '</a>' : '') . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function matched_report_sources($domains, $data) {
        $matches = array(); $domains = array_map('strtolower', (array)$domains);
        foreach (array_merge((array)($data['fonti_web_search'] ?? array()), (array)($data['fonti_citate'] ?? array())) as $item) {
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
    private static function stringify($value){ if (is_scalar($value) || $value === null) { return (string)$value; } $flat = array(); foreach ((array)$value as $item) { $flat[] = is_scalar($item) ? (string)$item : wp_json_encode($item, JSON_UNESCAPED_UNICODE); } return implode(', ', $flat); }
    private static function object_card($title,$items,$fields){ echo '<div class="alma-card"><h3>'.esc_html($title).'</h3>'; if(!$items){echo '<p>Nessun dato.</p>';} foreach($items as $it){ echo '<div style="border-top:1px solid #dcdcde;padding:8px 0">'; if(is_array($it)){ $show=$fields?:array_keys($it); foreach($show as $f){ if(isset($it[$f])){ echo '<p><strong>'.esc_html($f).':</strong> '.esc_html(self::stringify($it[$f])).'</p>'; } } } else { echo '<p>'.esc_html($it).'</p>'; } echo '</div>'; } echo '</div>'; }

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
