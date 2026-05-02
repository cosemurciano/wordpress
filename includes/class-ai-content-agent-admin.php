<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    const NOTICE_TRANSIENT_KEY = 'alma_ai_agent_admin_notice_';

    public static function init() { add_action('admin_post_alma_ai_agent_action', array(__CLASS__, 'handle_action')); }

    public static function handle_action() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_agent_action');
        $do = sanitize_key($_POST['do'] ?? '');
        $result = array('success' => true, 'message' => 'Azione completata.');

        if ($do === 'generate_ideas') {
            $result = ALMA_AI_Content_Agent_Planner::generate_ideas(array('max_ideas'=>absint($_POST['max_ideas'] ?? 3),'theme'=>sanitize_text_field($_POST['theme'] ?? ''),'destination'=>sanitize_text_field($_POST['destination'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($_POST['temporary_instructions'] ?? '')));
            $result['message'] = empty($result['success']) ? ($result['error'] ?? 'Errore generazione idee.') : sprintf('Idee generate e salvate: %d', (int)($result['saved'] ?? 0));
        } elseif ($do === 'set_idea_status') {
            $idea_id = absint($_POST['idea_id'] ?? 0); $status = sanitize_key($_POST['status'] ?? '');
            $ok = ($idea_id > 0) && in_array($status, ALMA_AI_Content_Agent_Store::allowed_idea_statuses(), true) && ALMA_AI_Content_Agent_Store::update_idea_status($idea_id, $status);
            $result = array('success' => $ok, 'message' => $ok ? 'Stato idea aggiornato.' : 'Impossibile aggiornare lo stato idea.');
        } elseif ($do === 'generate_brief') {
            $result = ALMA_AI_Content_Agent_Brief_Builder::generate_for_idea(absint($_POST['idea_id'] ?? 0));
            $result['message'] = empty($result['success']) ? ($result['error'] ?? 'Errore generazione brief.') : 'Brief generato.';
        } elseif ($do === 'generate_draft') {
            $result = ALMA_AI_Content_Agent_Draft_Builder::generate_for_idea(absint($_POST['idea_id'] ?? 0));
            $result['message'] = empty($result['success']) ? ($result['error'] ?? 'Errore generazione bozza.') : 'Bozza generata.';
        } elseif ($do === 'save_instruction_profile') {
            $id = absint($_POST['profile_id'] ?? 0);
            $new_id = ALMA_AI_Content_Agent_Instructions_Manager::save_profile($_POST, $id);
            if (!empty($_POST['activate_profile'])) { ALMA_AI_Content_Agent_Instructions_Manager::set_active($new_id); }
            $result = array('success' => $new_id > 0, 'message' => $new_id > 0 ? 'Profilo istruzioni salvato.' : 'Errore salvataggio profilo.');
        } elseif ($do === 'activate_instruction_profile') {
            $ok = ALMA_AI_Content_Agent_Instructions_Manager::set_active(absint($_POST['profile_id'] ?? 0));
            $result = array('success' => (bool)$ok, 'message' => $ok ? 'Profilo attivato.' : 'Impossibile attivare profilo.');
        } elseif ($do === 'deactivate_instruction_profile') {
            $ok = ALMA_AI_Content_Agent_Instructions_Manager::set_inactive(absint($_POST['profile_id'] ?? 0));
            $result = array('success' => (bool)$ok, 'message' => $ok ? 'Profilo disattivato.' : 'Impossibile disattivare profilo.');
        } elseif ($do === 'reindex_knowledge') {
            $count = ALMA_AI_Content_Agent_Knowledge_Indexer::reindex_batch();
            $result = array('success' => true, 'message' => sprintf('Reindex knowledge completato: %d elementi.', (int)$count));
        } elseif ($do === 'reindex_media') {
            $count = ALMA_AI_Content_Agent_Media_Indexer::reindex_batch(30);
            $result = array('success' => true, 'message' => sprintf('Reindex media completato: %d elementi.', (int)$count));
        } elseif ($do === 'index_document') {
            ALMA_AI_Content_Agent_Document_Manager::index_attachment(absint($_POST['attachment_id'] ?? 0), sanitize_textarea_field($_POST['manual_text'] ?? ''), sanitize_key($_POST['usage_mode'] ?? 'knowledge'), sanitize_text_field($_POST['language_code'] ?? ''));
            $result = array('success' => true, 'message' => 'Documento indicizzato.');
        } elseif ($do === 'save_note') {
            ALMA_AI_Content_Agent_Document_Manager::save_manual_note($_POST);
            $result = array('success' => true, 'message' => 'Nota salvata.');
        } elseif ($do === 'save_source') {
            $url = esc_url_raw($_POST['source_url'] ?? '');
            if (empty($url) || !wp_http_validate_url($url)) { $result = array('success' => false, 'message' => 'URL fonte non valido.'); }
            else { ALMA_AI_Content_Agent_Source_Manager::save_source($_POST); $result = array('success' => true, 'message' => 'Fonte salvata.'); }
        }

        self::set_notice($result);
        wp_safe_redirect(wp_get_referer()); exit;
    }

    private static function set_notice($result) {
        set_transient(self::NOTICE_TRANSIENT_KEY . get_current_user_id(), array('type' => !empty($result['success']) ? 'success' : 'error', 'message' => sanitize_text_field($result['message'] ?? 'Operazione completata.')), 120);
    }
    private static function render_notice() {
        $n = get_transient(self::NOTICE_TRANSIENT_KEY . get_current_user_id()); if (!$n) { return; }
        delete_transient(self::NOTICE_TRANSIENT_KEY . get_current_user_id());
        echo '<div class="notice notice-' . esc_attr($n['type']) . ' is-dismissible"><p>' . esc_html($n['message']) . '</p></div>';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { return; }
        $tabs = array('overview'=>'Overview','istruzioni-ai'=>'Istruzioni AI','documenti'=>'Documenti','fonti'=>'Fonti','knowledge'=>'Knowledge Base','media'=>'Media Library','reindex'=>'Reindicizzazione','log'=>'Stato/Log','idee'=>'Idee contenuto','bozze'=>'Bozze','programmazione'=>'Programmazione');
        $tab = sanitize_key($_GET['tab'] ?? 'overview'); if (!isset($tabs[$tab])) { $tab = 'overview'; }
        echo '<div class="wrap"><h1>AI Content Agent</h1>'; self::render_notice();
        echo '<h2 class="nav-tab-wrapper">'; foreach ($tabs as $tk=>$tl) { echo '<a class="nav-tab '.($tk===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$tk)).'">'.esc_html($tl).'</a>'; } echo '</h2>';
        if ($tab === 'overview') { self::render_overview_tab(); }
        elseif ($tab === 'istruzioni-ai') { self::render_instructions_tab(); }
        elseif ($tab === 'documenti') { self::render_documents_tab(); }
        elseif ($tab === 'fonti') { self::render_sources_tab(); }
        elseif ($tab === 'knowledge') { self::render_knowledge_tab(); }
        elseif ($tab === 'media') { self::render_media_tab(); }
        elseif ($tab === 'reindex') { self::render_reindex_tab(); }
        elseif ($tab === 'log') { self::render_log_tab(); }
        elseif ($tab === 'idee') { self::render_ideas_tab(); }
        elseif ($tab === 'bozze') { self::render_drafts_tab(); } else { echo '<p>Tab futura: non operativa in questo step.</p>'; }
        echo '</div>';
    }

    private static function render_overview_tab() { global $wpdb;
        $missing = ALMA_AI_Content_Agent_Store::missing_tables();
        echo '<h2>Overview</h2><ul>';
        echo empty($missing) || !in_array(ALMA_AI_Content_Agent_Store::table('knowledge_items'), $missing, true) ? '<li>Knowledge items: '.(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')).'</li>' : '<li>Knowledge items: n/d (tabella mancante)</li>';
        echo empty($missing) || !in_array(ALMA_AI_Content_Agent_Store::table('content_chunks'), $missing, true) ? '<li>Chunks: '.(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('content_chunks')).'</li>' : '<li>Chunks: n/d (tabella mancante)</li>';
        echo empty($missing) || !in_array(ALMA_AI_Content_Agent_Store::table('sources'), $missing, true) ? '<li>Fonti: '.(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('sources')).'</li>' : '<li>Fonti: n/d (tabella mancante)</li>';
        echo empty($missing) || !in_array(ALMA_AI_Content_Agent_Store::table('media_index'), $missing, true) ? '<li>Immagini indicizzate: '.(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('media_index')).'</li>' : '<li>Immagini indicizzate: n/d (tabella mancante)</li>';
        echo '<li>OpenAI: '.(empty(get_option('alma_openai_api_key',''))?'Non configurato':'Configurato').'</li>';
        echo '<li>Tabelle mancanti: '.(empty($missing)?'Nessuna':esc_html(implode(', ', $missing))).'</li></ul>';
    }

    private static function render_documents_tab() { global $wpdb; echo '<h2>Documenti</h2>'; self::action_form('index_document','Indicizza attachment','<input type="number" name="attachment_id" placeholder="Attachment ID" required> <input type="text" name="language_code" placeholder="Lingua (it/en)"> <input type="text" name="usage_mode" value="knowledge"><br><textarea name="manual_text" rows="2" class="large-text" placeholder="Note manuali"></textarea>'); self::action_form('save_note','Salva nota manuale','<input type="text" name="title" placeholder="Titolo nota" required> <input type="text" name="language_code" placeholder="Lingua"> <input type="text" name="usage_mode" value="knowledge"><input type="text" name="status" value="active"><br><textarea name="content" rows="2" class="large-text" placeholder="Contenuto nota"></textarea>');
        $rows = $wpdb->get_results("SELECT id,source_type,title,indexed_at FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE source_type IN ('document_attachment','manual_note') ORDER BY id DESC LIMIT 20", ARRAY_A);
        echo '<h3>Ultimi documenti/note</h3><table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Titolo</th><th>Indicizzato</th></tr></thead><tbody>';
        foreach ($rows as $r) { echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['source_type']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['indexed_at']).'</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function render_sources_tab(){ global $wpdb; echo '<h2>Fonti</h2>'; self::action_form('save_source','Salva fonte','<input type="text" name="name" placeholder="Nome" required> <input type="url" name="source_url" placeholder="https://..." required><input type="text" name="source_type" value="manual"><input type="text" name="language_code" placeholder="Lingua"><input type="text" name="market" placeholder="Mercato"><input type="text" name="usage_mode" value="knowledge"><label><input type="checkbox" name="is_active" value="1" checked> Attiva</label><textarea name="notes" class="large-text" rows="2" placeholder="Note"></textarea>');
        $rows = $wpdb->get_results("SELECT id,name,source_type,source_url,is_active,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('sources')." ORDER BY id DESC LIMIT 20", ARRAY_A);
        echo '<h3>Fonti recenti</h3><table class="widefat"><thead><tr><th>ID</th><th>Nome</th><th>Tipo</th><th>URL</th><th>Stato</th><th>Aggiornata</th></tr></thead><tbody>';
        foreach($rows as $r){ echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['source_type']).'</td><td><a href="'.esc_url($r['source_url']).'" target="_blank" rel="noopener">'.esc_html($r['source_url']).'</a></td><td>'.((int)$r['is_active']?'attiva':'disattiva').'</td><td>'.esc_html($r['updated_at']).'</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function render_knowledge_tab(){ global $wpdb; $rows=$wpdb->get_results("SELECT id,source_type,title,usage_mode,status FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." ORDER BY id DESC LIMIT 30",ARRAY_A); echo '<h2>Knowledge Base</h2><table class="widefat"><thead><tr><th>ID</th><th>Tipo Fonte</th><th>Titolo</th><th>Usage mode</th><th>Stato</th></tr></thead><tbody>'; foreach($rows as $r){ echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['source_type']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['usage_mode']).'</td><td>'.esc_html($r['status']).'</td></tr>'; } echo '</tbody></table>'; }
    private static function render_media_tab(){ global $wpdb; $rows=$wpdb->get_results("SELECT id,attachment_id,filename,alt_text FROM ".ALMA_AI_Content_Agent_Store::table('media_index')." ORDER BY id DESC LIMIT 20",ARRAY_A); echo '<h2>Media Library</h2><table class="widefat"><thead><tr><th>ID</th><th>Attachment ID</th><th>Filename</th><th>Alt text</th><th>Preview</th></tr></thead><tbody>'; foreach($rows as $r){ $thumb=wp_get_attachment_image((int)$r['attachment_id'],array(60,60)); echo '<tr><td>'.(int)$r['id'].'</td><td>'.(int)$r['attachment_id'].'</td><td>'.esc_html($r['filename']).'</td><td>'.esc_html($r['alt_text']).'</td><td>'.($thumb?$thumb:'-').'</td></tr>'; } echo '</tbody></table>'; }
    private static function render_reindex_tab(){ echo '<h2>Reindicizzazione</h2>'; self::action_form('reindex_knowledge','Reindex knowledge'); self::action_form('reindex_media','Reindex media'); }
    private static function render_log_tab(){ global $wpdb; $missing=ALMA_AI_Content_Agent_Store::missing_tables(); $jobs=$wpdb->get_results("SELECT id,job_type,status,last_error,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('jobs')." ORDER BY id DESC LIMIT 20",ARRAY_A); $logs=ALMA_AI_Usage_Logger::get_recent_logs(20); echo '<h2>Stato/Log</h2><p><strong>Tabelle mancanti:</strong> '.(empty($missing)?'Nessuna':esc_html(implode(', ',$missing))).'</p>'; echo '<h3>Ultimi job/errori</h3><table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Stato</th><th>Errore</th><th>Aggiornato</th></tr></thead><tbody>'; foreach($jobs as $j){ echo '<tr><td>'.(int)$j['id'].'</td><td>'.esc_html($j['job_type']).'</td><td>'.esc_html($j['status']).'</td><td>'.esc_html($j['last_error']).'</td><td>'.esc_html($j['updated_at']).'</td></tr>'; } echo '</tbody></table>'; echo '<h3>Ultimi log AI</h3><table class="widefat"><thead><tr><th>Data</th><th>Task</th><th>Model</th><th>Successo</th><th>Errore</th></tr></thead><tbody>'; foreach($logs as $l){ echo '<tr><td>'.esc_html($l['created_at']).'</td><td>'.esc_html($l['task']).'</td><td>'.esc_html($l['model']).'</td><td>'.((int)$l['success']?'si':'no').'</td><td>'.esc_html($l['error_message']).'</td></tr>'; } echo '</tbody></table>'; }

    private static function render_instructions_tab() { $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(50,0); $active = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); $edit_id=absint($_GET['profile_id'] ?? ($active['id'] ?? 0)); $current=$edit_id?ALMA_AI_Content_Agent_Instructions_Manager::get_profile($edit_id):array(); echo '<h2>Istruzioni AI</h2><h3>Profili</h3><table class="widefat"><thead><tr><th>ID</th><th>Nome</th><th>Attivo</th><th>Default</th><th>Azioni</th></tr></thead><tbody>'; foreach($profiles as $p){ echo '<tr><td>'.(int)$p['id'].'</td><td>'.esc_html($p['profile_name']).'</td><td>'.((int)$p['is_active']?'Sì':'No').'</td><td>'.((int)$p['is_default']?'Sì':'No').'</td><td><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id='.(int)$p['id'])).'">Modifica</a> '.self::inline_profile_action_form('activate_instruction_profile','Attiva',(int)$p['id']).' '.self::inline_profile_action_form('deactivate_instruction_profile','Disattiva',(int)$p['id']).'</td></tr>'; } echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id=0')).'">Crea nuovo profilo</a></p>';
        echo '<h3>'.($edit_id>0?'Modifica profilo':'Nuovo profilo').'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_instruction_profile"><input type="hidden" name="profile_id" value="'.(int)$edit_id.'">';
        $fields=['profile_name'=>'Nome profilo','language_code'=>'Lingua','tone_of_voice'=>'Tono di voce','target_audience'=>'Pubblico target','editorial_style'=>'Stile editoriale','seo_rules'=>'Regole SEO','affiliate_rules'=>'Regole affiliate','image_rules'=>'Regole immagini','source_rules'=>'Regole fonti','anti_duplication_rules'=>'Regole anti-duplicazione','avoid_rules'=>'Cose da evitare','disclosure_policy'=>'Disclosure policy','custom_prompt'=>'Prompt libero personalizzato','internal_notes'=>'Note interne'];
        foreach($fields as $k=>$l){ echo '<p><label><strong>'.esc_html($l).'</strong><br>'; if(in_array($k,['profile_name','language_code'],true)){ echo '<input class="regular-text" name="'.esc_attr($k).'" value="'.esc_attr($current[$k]??'').'">'; } else { echo '<textarea class="large-text" rows="3" name="'.esc_attr($k).'">'.esc_textarea($current[$k]??'').'</textarea>'; } echo '</label></p>'; }
        echo '<p><label><input type="checkbox" name="activate_profile" value="1"> Attiva questo profilo dopo il salvataggio</label></p><p><button class="button button-primary">Salva profilo</button></p></form>';
    }

    private static function render_ideas_tab() { $status = sanitize_key($_GET['idea_status'] ?? ''); echo '<h2>Genera idee</h2>'; self::action_form('generate_ideas','Genera idee','<input type="number" min="1" max="10" name="max_ideas" value="3"/> <input name="theme" placeholder="Tema"> <input name="destination" placeholder="Destinazione"><br><textarea name="temporary_instructions" class="large-text" rows="2" placeholder="Istruzioni aggiuntive per questa generazione (opzionale)"></textarea>');
        echo '<form method="get"><input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="alma-ai-content-agent"><input type="hidden" name="tab" value="idee"><select name="idea_status"><option value="">Tutti gli stati</option>'; foreach(ALMA_AI_Content_Agent_Store::allowed_idea_statuses() as $st){ echo '<option '.selected($status,$st,false).' value="'.esc_attr($st).'">'.esc_html($st).'</option>'; } echo '</select> <button class="button">Filtra</button></form>';
        $ideas = ALMA_AI_Content_Agent_Store::get_ideas($status); echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Titolo</th><th>Motivazione</th><th>Stato</th><th>SEO</th><th>Priority</th><th>Affiliati</th><th>Immagini</th><th>Model</th><th>Profile</th><th>Snapshot</th><th>Warning</th><th>Azioni</th></tr></thead><tbody>';
        foreach($ideas as $i){ $warnings=implode(', ',(array)json_decode((string)($i['warnings']??'[]'),true)); echo '<tr><td>'.(int)$i['id'].'</td><td>'.esc_html($i['proposed_title']).'</td><td>'.esc_html($i['rationale']).'</td><td>'.esc_html($i['status']).'</td><td>'.esc_html($i['seo_score']).'</td><td>'.esc_html($i['priority_score']).'</td><td>'.count((array)json_decode((string)$i['affiliate_candidates'],true)).'</td><td>'.count((array)json_decode((string)$i['image_candidates'],true)).'</td><td>'.esc_html($i['ai_model']).'</td><td>'.(int)$i['instruction_profile_id'].'</td><td>'.esc_html(substr((string)$i['instruction_snapshot_hash'],0,12)).'</td><td>'.esc_html($warnings).'</td><td>'.self::inline_status_form((int)$i['id'],'approved','Approva').' '.self::inline_status_form((int)$i['id'],'rejected','Scarta').' '.self::inline_status_form((int)$i['id'],'archived','Archivia').' '.self::inline_brief_form((int)$i['id']).' '.self::inline_draft_form((int)$i['id'], (string)$i['status']).'</td></tr';
            $brief=ALMA_AI_Content_Agent_Store::get_brief_by_idea((int)$i['id']); if($brief){ echo '<tr><td colspan="13"><details><summary>Brief esistente per idea #'.(int)$i['id'].'</summary><pre style="white-space:pre-wrap;">'.esc_html(wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></details></td></tr>'; }
        }
        echo '</tbody></table>';
    }

    private static function inline_status_form($idea_id,$status,$label){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="set_idea_status"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button button-small">'.esc_html($label).'</button></form>'; }
    private static function inline_brief_form($idea_id){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="generate_brief"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><button class="button button-small button-primary">Genera brief</button></form>'; }
    private static function inline_profile_action_form($do,$label,$profile_id){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'"><input type="hidden" name="profile_id" value="'.(int)$profile_id.'"><button class="button button-small">'.esc_html($label).'</button></form>'; }
    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
