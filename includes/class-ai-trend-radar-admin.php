<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Trend_Radar_Admin {
    const SLUG = 'alma-ai-trend-radar';
    const CAP = 'manage_options';

    public static function init() {
        add_action('admin_post_alma_trend_radar_action', array(__CLASS__, 'handle_action'));
    }

    public static function render_page() {
        if (!current_user_can(self::CAP)) { wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai')); }
        $tab = sanitize_key($_GET['tab'] ?? 'reports');
        $notice = get_transient('alma_trend_radar_notice_' . get_current_user_id()); delete_transient('alma_trend_radar_notice_' . get_current_user_id());
        echo '<div class="wrap alma-trend-radar"><h1>AI Trend Radar</h1>';
        if ($notice) { printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message'])); }
        echo '<div class="notice notice-info"><p><strong>Nota WP-Cron:</strong> le esecuzioni programmate dipendono dal traffico del sito. Per orari puntuali configura un cron server reale che richiami wp-cron.php.</p></div>';
        if (!ALMA_AI_Trend_Radar_Service::is_openai_ready()) { echo '<div class="notice notice-warning"><p>OpenAI non è configurata: “Esegui ricerca ora” è disabilitato finché non viene salvata una API key nelle impostazioni del plugin.</p></div>'; }
        self::nav($tab);
        if ($tab === 'profiles') { self::render_profiles(); }
        elseif ($tab === 'settings') { self::render_settings(); }
        elseif ($tab === 'logs') { self::render_logs(); }
        else { self::render_reports(); }
        echo '</div>';
    }

    private static function nav($tab) {
        $base = admin_url('edit.php?post_type=affiliate_link&page=' . self::SLUG);
        $tabs = array('reports'=>'Report trend','profiles'=>'Profili ricerca','settings'=>'Pianificazione','logs'=>'Log');
        echo '<nav class="nav-tab-wrapper">'; foreach($tabs as $k=>$label){ printf('<a class="nav-tab %s" href="%s">%s</a>', $tab===$k?'nav-tab-active':'', esc_url($base.'&tab='.$k), esc_html($label)); } echo '</nav>';
    }

    private static function action_url($args=array()) { return wp_nonce_url(add_query_arg(array_merge(array('action'=>'alma_trend_radar_action'), $args), admin_url('admin-post.php')), 'alma_trend_radar_action'); }

    private static function render_reports() {
        $profiles = ALMA_AI_Trend_Radar_Store::get_profiles(); $first = !empty($profiles) ? (int)$profiles[0]['id'] : 0;
        $total_reports = ALMA_AI_Trend_Radar_Store::count_reports();
        $new_reports = ALMA_AI_Trend_Radar_Store::count_reports(array('status'=>'new'));
        $interesting_reports = ALMA_AI_Trend_Radar_Store::count_reports(array('status'=>'interesting'));
        echo '<div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;">';
        foreach (array('Report totali'=>$total_reports, 'Da valutare'=>$new_reports, 'Interessanti'=>$interesting_reports) as $label=>$value) {
            echo '<div class="card" style="max-width:220px;margin:0;"><h3 style="margin-top:0">'.esc_html($label).'</h3><p style="font-size:28px;margin:0"><strong>'.(int)$value.'</strong></p></div>';
        }
        echo '</div>';
        echo '<div style="margin:16px 0;display:flex;gap:12px;align-items:center;">';
        if (ALMA_AI_Trend_Radar_Service::is_openai_ready() && $first) { echo '<a class="button button-primary button-hero" href="'.esc_url(self::action_url(array('do'=>'run','profile_id'=>$first))).'">Esegui ricerca ora</a>'; }
        else { echo '<button class="button button-primary button-hero" disabled>Esegui ricerca ora</button>'; }
        echo '<a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page='.self::SLUG.'&tab=profiles')).'">Gestisci profili</a></div>';
        $current_status = sanitize_key($_GET['status'] ?? '');
        echo '<form method="get" style="margin:12px 0"><input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"><label>Filtro stato: <select name="status"><option value="">Tutti</option>';
        foreach (array('new'=>'Nuovi','interesting'=>'Interessanti','idea_created'=>'Idea creata','draft_created'=>'Bozza creata','discarded'=>'Scartati') as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected($current_status,$key,false).'>'.esc_html($label).'</option>'; }
        echo '</select></label> <button class="button">Filtra</button></form>';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 20;
        $reports = ALMA_AI_Trend_Radar_Store::get_reports(array('page'=>$page, 'per_page'=>$per_page, 'status'=>$current_status));
        echo '<div class="postbox"><div class="inside"><h2>Report trend generati</h2><table class="widefat striped"><thead><tr><th>Trend</th><th>Priorità</th><th>SEO</th><th>Affiliate</th><th>Stato</th><th>Creato</th><th>Azioni rapide</th></tr></thead><tbody>';
        if (!$reports) { echo '<tr><td colspan="7">Nessun report generato.</td></tr>'; }
        foreach ($reports as $r) {
            $priority = self::priority($r); $badge = $priority === 'alta' ? '#d63638' : ($priority === 'media' ? '#dba617' : '#00a32a');
            echo '<tr><td><strong>'.esc_html($r['trend_title']).'</strong><p>'.esc_html(wp_trim_words($r['trend_summary'], 22)).'</p></td><td><span style="display:inline-block;padding:3px 8px;border-radius:10px;background:'.esc_attr($badge).';color:#fff;">'.esc_html($priority).'</span></td><td>'.(int)$r['seo_potential_score'].'/10</td><td>'.(int)$r['affiliate_potential_score'].'/10</td><td>'.esc_html($r['status']).'</td><td>'.esc_html($r['created_at']).'</td><td>';
            echo '<a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'idea','report_id'=>(int)$r['id']))).'">Crea idea contenuto</a> ';
            if (class_exists('ALMA_AI_Content_Agent_Ideas')) { echo '<a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'draft','report_id'=>(int)$r['id']))).'">Crea bozza articolo</a> '; }
            echo '<a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'interesting','report_id'=>(int)$r['id']))).'">Interessante</a> ';
            echo '<a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'discard','report_id'=>(int)$r['id']))).'">Scarta</a>';
            echo '</td></tr><tr><td colspan="7"><details><summary>Dettagli, fonti e link affiliati suggeriti</summary>'.self::report_details($r).'</details></td></tr>';
        }
        echo '</tbody></table>';
        $total = ALMA_AI_Trend_Radar_Store::count_reports(array('status'=>$current_status));
        $pages = max(1, (int)ceil($total / $per_page));
        if ($pages > 1) {
            echo '<p class="tablenav-pages">';
            for ($i=1; $i<=$pages; $i++) {
                $url = admin_url('edit.php?post_type=affiliate_link&page=' . self::SLUG . '&paged=' . $i . ($current_status ? '&status=' . rawurlencode($current_status) : ''));
                echo $i === $page ? ' <span class="button disabled">'.(int)$i.'</span> ' : ' <a class="button" href="'.esc_url($url).'">'.(int)$i.'</a> ';
            }
            echo '</p>';
        }
        echo '</div></div>';
    }

    private static function report_details($r) {
        $fields = array('why_now'=>'Perché ora','destinations'=>'Destinazioni','seasonality'=>'Stagionalità','target_audience'=>'Audience','recommended_article_titles'=>'Titoli','suggested_keywords'=>'Keyword','suggested_outline'=>'Outline','source_urls'=>'Fonti','source_notes'=>'Note fonti','suggested_internal_affiliate_links'=>'Link affiliati suggeriti');
        $html = '<dl>'; foreach($fields as $k=>$label){ $v=$r[$k]??''; $decoded=json_decode((string)$v,true); if(json_last_error()===JSON_ERROR_NONE){$v=wp_json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} $html.='<dt><strong>'.esc_html($label).'</strong></dt><dd><pre style="white-space:pre-wrap">'.esc_html((string)$v).'</pre></dd>'; } return $html.'</dl>';
    }

    private static function priority($r) { $avg=((int)$r['seo_potential_score']+(int)$r['affiliate_potential_score']+(int)$r['urgency_score'])/3; return $avg>=8?'alta':($avg>=5?'media':'bassa'); }

    private static function render_profiles() {
        $edit_id=absint($_GET['profile_id']??0); $profile=$edit_id?ALMA_AI_Trend_Radar_Store::get_profile($edit_id):ALMA_AI_Trend_Radar_Store::defaults();
        echo '<div class="postbox"><div class="inside"><h2>'.($edit_id?'Modifica profilo':'Nuovo profilo').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_trend_radar_action'); echo '<input type="hidden" name="action" value="alma_trend_radar_action"><input type="hidden" name="do" value="save_profile"><input type="hidden" name="profile_id" value="'.(int)$edit_id.'"><table class="form-table"><tbody>';
        self::input('name','Nome profilo',$profile['name'],'text',true); self::checkbox('active','Attivo',$profile['active']); self::input('language','Lingua',$profile['language']); self::input('target_market','Mercato target',$profile['target_market']); self::input('main_theme','Tema principale',$profile['main_theme']); self::textarea('editorial_focus','Descrizione focus editoriale',$profile['editorial_focus']); self::textarea('seed_queries','Query seed',$profile['seed_queries']);
        echo '<tr><th>Impostazioni avanzate</th><td><details><summary>Mostra opzioni avanzate</summary><table class="form-table">'; self::textarea('preferred_sources','Fonti preferite',$profile['preferred_sources']); self::textarea('excluded_sources','Fonti escluse',$profile['excluded_sources']); self::select('frequency','Frequenza esecuzione',$profile['frequency'],array('manual'=>'Manuale','hourly'=>'Ogni ora','twicedaily'=>'Due volte al giorno','daily'=>'Giornaliera','weekly'=>'Settimanale')); self::input('run_time','Orario esecuzione',$profile['run_time'],'time'); self::input('max_trends','Numero massimo trend',$profile['max_trends'],'number'); self::select('analysis_depth','Profondità analisi',$profile['analysis_depth'],array('rapido'=>'Rapido','standard'=>'Standard','approfondito'=>'Approfondito')); self::select('editorial_goal','Obiettivo editoriale',$profile['editorial_goal'],array('guida'=>'Guida','destinazione'=>'Destinazione','esperienza'=>'Esperienza','lista'=>'Lista','news'=>'News','itinerario'=>'Itinerario')); self::checkbox('email_summary','Invio email riepilogo',$profile['email_summary']); self::input('recipient_email','Email destinatario opzionale',$profile['recipient_email'],'email'); echo '</table></details></td></tr>';
        echo '</tbody></table><p><button class="button button-primary">Salva profilo</button></p></form></div></div>';
        $profiles=ALMA_AI_Trend_Radar_Store::get_profiles(); echo '<h2>Profili ricerca</h2><table class="widefat striped"><thead><tr><th>Nome</th><th>Stato</th><th>Frequenza</th><th>Prossima</th><th>Ultima</th><th>Azioni</th></tr></thead><tbody>'; foreach($profiles as $p){ echo '<tr><td>'.esc_html($p['name']).'</td><td>'.(!empty($p['active'])?'Attivo':'Disattivo').'</td><td>'.esc_html($p['frequency']).'</td><td>'.esc_html($p['next_run_at']).'</td><td>'.esc_html($p['last_run_at']).'</td><td><a class="button button-small" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page='.self::SLUG.'&tab=profiles&profile_id='.(int)$p['id'])).'">Modifica</a> <a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'run','profile_id'=>(int)$p['id']))).'">Esegui ora</a> <a class="button button-small" href="'.esc_url(self::action_url(array('do'=>'delete_profile','profile_id'=>(int)$p['id']))).'">Elimina</a></td></tr>'; } echo '</tbody></table>';
    }

    private static function render_settings() { echo '<div class="postbox"><div class="inside"><h2>Pianificazione</h2><p>AI Trend Radar usa eventi singoli WP-Cron per ogni profilo attivo. Ogni profilo viene ripianificato dopo il salvataggio e al termine dell’esecuzione.</p><p>Per maggiore puntualità configura un cron server reale, ad esempio una chiamata periodica a <code>'.esc_html(site_url('wp-cron.php?doing_wp_cron')).'</code>.</p><p><a class="button" href="'.esc_url(self::action_url(array('do'=>'reschedule'))).'">Rigenera pianificazione profili attivi</a></p></div></div>'; }
    private static function render_logs() { $logs=ALMA_AI_Trend_Radar_Store::get_logs(50); echo '<div class="postbox"><div class="inside"><h2>Log ultime esecuzioni</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Profilo</th><th>Livello</th><th>Messaggio</th><th>Contesto</th></tr></thead><tbody>'; foreach($logs as $l){ echo '<tr><td>'.esc_html($l['created_at']).'</td><td>'.esc_html($l['profile_name']?:('#'.$l['profile_id'])).'</td><td>'.esc_html($l['level']).'</td><td>'.esc_html($l['message']).'</td><td><code>'.esc_html($l['context']).'</code></td></tr>'; } echo '</tbody></table></div></div>'; }

    private static function input($name,$label,$value,$type='text',$required=false){ echo '<tr><th><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($name).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.($required?'required':'').'></td></tr>'; }
    private static function textarea($name,$label,$value){ echo '<tr><th><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td><textarea class="large-text" rows="3" id="'.esc_attr($name).'" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea></td></tr>'; }
    private static function checkbox($name,$label,$value){ echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,1,false).'> Sì</label></td></tr>'; }
    private static function select($name,$label,$value,$opts){ echo '<tr><th><label for="'.esc_attr($name).'">'.esc_html($label).'</label></th><td><select id="'.esc_attr($name).'" name="'.esc_attr($name).'">'; foreach($opts as $k=>$v){ echo '<option value="'.esc_attr($k).'" '.selected($value,$k,false).'>'.esc_html($v).'</option>'; } echo '</select></td></tr>'; }

    public static function handle_action() {
        if (!current_user_can(self::CAP)) { wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai')); }
        check_admin_referer('alma_trend_radar_action'); $do=sanitize_key($_REQUEST['do'] ?? ''); $type='success'; $msg='Operazione completata.';
        if ($do==='save_profile') { $id=ALMA_AI_Trend_Radar_Store::save_profile($_POST, absint($_POST['profile_id']??0)); if(is_wp_error($id)){$type='error';$msg=$id->get_error_message();} else { ALMA_AI_Trend_Radar_Service::schedule_profile($id); $msg='Profilo salvato.'; } }
        elseif ($do==='delete_profile') { $id=absint($_GET['profile_id']??0); ALMA_AI_Trend_Radar_Service::clear_profile_schedule($id); ALMA_AI_Trend_Radar_Store::delete_profile($id); $msg='Profilo eliminato.'; }
        elseif ($do==='run') { $r=ALMA_AI_Trend_Radar_Service::run_profile(absint($_GET['profile_id']??0), true); if(is_wp_error($r)){$type='error';$msg=$r->get_error_message();} else {$msg='Ricerca completata: '.(int)$r['created'].' trend salvati.';} }
        elseif ($do==='reschedule') { ALMA_AI_Trend_Radar_Service::reschedule_all(); $msg='Pianificazione rigenerata.'; }
        elseif (in_array($do,array('interesting','discard'),true)) { ALMA_AI_Trend_Radar_Store::update_report_status(absint($_GET['report_id']??0), $do==='interesting'?'interesting':'discarded'); $msg='Trend aggiornato.'; }
        elseif ($do==='idea') { $r=self::create_content_idea(absint($_GET['report_id']??0)); if(is_wp_error($r)){$type='error';$msg=$r->get_error_message();} else {$msg='Idea contenuto creata.';} }
        elseif ($do==='draft') { $r=self::create_draft(absint($_GET['report_id']??0)); if(is_wp_error($r)){$type='error';$msg=$r->get_error_message();} else {$msg='Bozza articolo creata.';} }
        set_transient('alma_trend_radar_notice_' . get_current_user_id(), array('type'=>$type,'message'=>$msg), 120);
        wp_safe_redirect(admin_url('edit.php?post_type=affiliate_link&page='.self::SLUG)); exit;
    }

    private static function create_content_idea($report_id) {
        if (!class_exists('ALMA_AI_Content_Agent_Ideas')) { return new WP_Error('missing_agent', 'AI Content Agent non disponibile.'); }
        $r=ALMA_AI_Trend_Radar_Store::get_report($report_id); if(empty($r)){return new WP_Error('missing_report','Report non trovato.');}
        $idea_id=ALMA_AI_Content_Agent_Ideas::create($r['trend_title']); if(!$idea_id){return new WP_Error('idea_error','Creazione idea non riuscita.');}
        $prompt="Trend Radar Sothra\n\nSintesi: {$r['trend_summary']}\n\nPerché ora: {$r['why_now']}\n\nFonti: {$r['source_urls']}\n\nKeyword: {$r['suggested_keywords']}\n\nOutline: {$r['suggested_outline']}\n\nLink affiliati suggeriti: {$r['suggested_internal_affiliate_links']}";
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_PROMPT, sanitize_textarea_field($prompt));
        update_post_meta($idea_id, '_alma_trend_radar_report_id', $report_id); ALMA_AI_Trend_Radar_Store::update_report_status($report_id, 'idea_created'); return $idea_id;
    }

    private static function create_draft($report_id) {
        $r=ALMA_AI_Trend_Radar_Store::get_report($report_id); if(empty($r)){return new WP_Error('missing_report','Report non trovato.');}
        $content="<!-- wp:paragraph --><p>".esc_html($r['trend_summary'])."</p><!-- /wp:paragraph -->\n<!-- wp:heading --><h2>Perché parlarne ora</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>".esc_html($r['why_now'])."</p><!-- /wp:paragraph -->\n<!-- wp:preformatted --><pre>Outline suggerito:\n".esc_html($r['suggested_outline'])."\n\nKeyword:\n".esc_html($r['suggested_keywords'])."\n\nFonti:\n".esc_html($r['source_urls'])."\n\nLink affiliati suggeriti:\n".esc_html($r['suggested_internal_affiliate_links'])."</pre><!-- /wp:preformatted -->";
        $post_id=wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_title'=>sanitize_text_field($r['trend_title']),'post_content'=>$content,'post_author'=>get_current_user_id()), true); if(is_wp_error($post_id)){return $post_id;} update_post_meta($post_id,'_alma_trend_radar_report_id',$report_id); ALMA_AI_Trend_Radar_Store::update_report_status($report_id,'draft_created'); return $post_id;
    }
}
ALMA_AI_Trend_Radar_Admin::init();
