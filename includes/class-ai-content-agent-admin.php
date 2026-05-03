<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    const NOTICE_TRANSIENT_KEY = 'alma_ai_agent_admin_notice_';
    const RESULT_TRANSIENT_KEY = 'alma_ai_agent_admin_result_';

    public static function init() { add_action('admin_post_alma_ai_agent_action', array(__CLASS__, 'handle_action')); }

    public static function handle_action() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_agent_action');
        $do = sanitize_key($_POST['do'] ?? '');
        $result = array('success' => true, 'message' => 'Azione completata.');

        if ($do === 'generate_ideas') {
            $result = array('success'=>false,'message'=>'Generazione idee AI disattivata nel workflow corrente.');
        } elseif ($do === 'set_idea_status') {
            $idea_id = absint($_POST['idea_id'] ?? 0); $status = sanitize_key($_POST['status'] ?? '');
            $ok = ($idea_id > 0) && in_array($status, ALMA_AI_Content_Agent_Store::allowed_idea_statuses(), true) && ALMA_AI_Content_Agent_Store::update_idea_status($idea_id, $status);
            $result = array('success' => $ok, 'message' => $ok ? 'Stato idea aggiornato.' : 'Impossibile aggiornare lo stato idea.');
        } elseif ($do === 'generate_brief') {
            $result = array('success'=>false,'message'=>'Step brief AI separato disattivato nel workflow corrente.');
        } elseif ($do === 'generate_draft') {
            $result = ALMA_AI_Content_Agent_Draft_Builder::generate_for_idea(absint($_POST['idea_id'] ?? 0));
            $result['message'] = empty($result['success']) ? ($result['error'] ?? 'Errore generazione bozza.') : 'Bozza generata: '.esc_url_raw($result['edit_url'] ?? '');
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
        } elseif ($do === 'search_knowledge_base' || $do === 'add_new_search') {
            $profile_id = absint($_POST['instruction_profile_id'] ?? 0);
            $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : array();
            $payload = array('max_ideas'=>absint($_POST['max_ideas'] ?? 1),'content_search_query'=>sanitize_text_field($_POST['content_search_query'] ?? ($_POST['search_terms'] ?? '')),'search_terms'=>sanitize_text_field($_POST['search_terms'] ?? ($_POST['content_search_query'] ?? '')),'theme'=>sanitize_text_field($_POST['theme'] ?? ''),'destination'=>sanitize_text_field($_POST['destination'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($_POST['temporary_instructions'] ?? ''),'instruction_profile_id'=>$profile_id,'instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ''),'instruction_snapshot_hash'=>sanitize_text_field($profile ? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash(wp_json_encode($profile)) : ''));
            $search = ALMA_AI_Content_Agent_Knowledge_Search::search($payload);
            $stats = ALMA_AI_Content_Agent_Selection_Session::add_search_results($payload, $search);
            $result = array('success' => true, 'message' => sprintf('Ricerca completata. Trovati: %d, aggiunti: %d, duplicati ignorati: %d.', (int)$stats['found'], (int)$stats['added'], (int)$stats['duplicates']));
        } elseif ($do === 'save_selection') {
            $result = ALMA_AI_Content_Agent_Selection_Session::save_selection($_POST['selected_result_keys'] ?? array());
        } elseif ($do === 'clear_selection_session') {
            ALMA_AI_Content_Agent_Selection_Session::clear();
            $result = array('success' => true, 'message' => 'Sessione contenuto svuotata.');
        } elseif ($do === 'download_ai_payload_json') {
            $summary = ALMA_AI_Content_Agent_Selection_Session::summary();
            if ((int)($summary['selected_total'] ?? 0) < 1) { $result = array('success'=>false,'message'=>'Seleziona almeno una fonte prima di scaricare il JSON payload AI.'); }
            else { ALMA_AI_Content_Agent_Draft_Builder::download_payload_json_from_selection_session(get_current_user_id()); }
        } elseif ($do === 'create_draft_from_selection') {
            $summary = ALMA_AI_Content_Agent_Selection_Session::summary();
            if (($summary['status'] ?? 'empty') === 'empty') { $result = array('success'=>false,'message'=>'Nessuna sessione contenuto attiva.'); }
            elseif ((int)($summary['selected_total'] ?? 0) < 1) { $result = array('success'=>false,'message'=>'Seleziona almeno una fonte prima di creare la bozza.'); }
            elseif ((int)($summary['selected_post'] ?? 0) > ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_POSTS) { $result = array('success'=>false,'message'=>'Puoi selezionare massimo 3 Post.'); }
            elseif (empty(get_option('alma_openai_api_key', ''))) { $result = array('success'=>false,'message'=>'OpenAI non è configurata.'); }
            else {
                $result = ALMA_AI_Content_Agent_Draft_Builder::generate_from_selection_session(get_current_user_id());
                $result['message'] = empty($result['success']) ? ($result['error'] ?? 'Errore creazione bozza da sessione.') : 'Bozza articolo creata.';
                set_transient(self::RESULT_TRANSIENT_KEY . get_current_user_id(), $result, 120);
            }
        } elseif ($do === 'upload_txt_document') {
            $result = ALMA_AI_Content_Agent_Document_Manager::handle_upload(sanitize_text_field($_POST['document_name'] ?? ''), $_FILES['document_file'] ?? array());
        } elseif ($do === 'rename_txt_document') {
            global $wpdb; $id = absint($_POST['document_id'] ?? 0);
            $ok = $wpdb->update(ALMA_AI_Content_Agent_Store::table('knowledge_items'), array('title'=>sanitize_text_field($_POST['document_name'] ?? ''),'updated_at'=>current_time('mysql')), array('id'=>$id,'source_type'=>'document_txt'));
            $result = array('success'=>(bool)$ok,'message'=>$ok?'Documento TXT aggiornato.':'Errore aggiornamento documento TXT.');
        } elseif ($do === 'toggle_txt_document') {
            global $wpdb; $id = absint($_POST['document_id'] ?? 0); $status = sanitize_key($_POST['status'] ?? 'inactive');
            $ok = $wpdb->update(ALMA_AI_Content_Agent_Store::table('knowledge_items'), array('status'=>$status,'updated_at'=>current_time('mysql')), array('id'=>$id,'source_type'=>'document_txt'));
            $result = array('success'=>(bool)$ok,'message'=>$ok?($status==='active'?'Documento TXT riabilitato.':'Documento TXT disabilitato.'):'Errore cambio stato documento TXT.');
        } elseif ($do === 'delete_txt_document') {
            global $wpdb; $id = absint($_POST['document_id'] ?? 0);
            $wpdb->delete(ALMA_AI_Content_Agent_Store::table('content_chunks'), array('knowledge_item_id'=>$id));
            $ok = $wpdb->delete(ALMA_AI_Content_Agent_Store::table('knowledge_items'), array('id'=>$id,'source_type'=>'document_txt'));
            $result = array('success'=>(bool)$ok,'message'=>$ok?'Documento TXT eliminato dal Knowledge Base.':'Errore eliminazione documento TXT.');
        } elseif ($do === 'save_source') {
            $result = ALMA_AI_Content_Agent_Source_Manager::save_source($_POST);
        } elseif ($do === 'toggle_source') {
            global $wpdb; $id = absint($_POST['source_id'] ?? 0); $active = absint($_POST['is_active'] ?? 0);
            $ok = $wpdb->update(ALMA_AI_Content_Agent_Store::table('sources'), array('is_active'=>$active,'updated_at'=>current_time('mysql')), array('id'=>$id));
            $result = array('success'=>(bool)$ok,'message'=>$ok?($active?'Fonte riabilitata.':'Fonte disabilitata.'):'Errore cambio stato fonte.');
        } elseif ($do === 'delete_source') {
            global $wpdb; $id = absint($_POST['source_id'] ?? 0);
            $ok = $wpdb->delete(ALMA_AI_Content_Agent_Store::table('sources'), array('id'=>$id));
            $result = array('success'=>(bool)$ok,'message'=>$ok?'Fonte eliminata.':'Errore eliminazione fonte.');
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
        $r = get_transient(self::RESULT_TRANSIENT_KEY . get_current_user_id());
        if (!empty($r) && !empty($r['success'])) {
            delete_transient(self::RESULT_TRANSIENT_KEY . get_current_user_id());
            $summary = (array)($r['summary'] ?? array());
            $counts = (array)($summary['source_counts'] ?? array());
            echo '<div class="notice notice-success"><h3 style="margin-top:0;">Bozza articolo creata</h3>';
            echo '<p><strong>Titolo:</strong> '.esc_html($r['title'] ?? '').'<br><strong>Stato:</strong> Bozza</p><p>';
            if (!empty($r['edit_url'])) { echo '<a class="button button-primary" href="'.esc_url($r['edit_url']).'">Modifica articolo</a> '; }
            if (!empty($r['preview_url'])) { echo '<a class="button" href="'.esc_url($r['preview_url']).'" target="_blank" rel="noopener">Anteprima articolo</a>'; }
            echo '</p><ul>';
            if (!empty($summary['instruction_profile_name'])) { echo '<li><strong>Profilo istruzioni:</strong> '.esc_html($summary['instruction_profile_name']).'</li>'; }
            if (!empty($r['model'])) { echo '<li><strong>Modello AI:</strong> '.esc_html($r['model']).'</li>'; }
            echo '<li><strong>Post:</strong> '.(int)($counts['post'] ?? 0).'</li>';
            echo '<li><strong>Pagine:</strong> '.(int)($counts['page'] ?? 0).'</li>';
            echo '<li><strong>Affiliate Links:</strong> '.(int)($counts['affiliate_link'] ?? 0).'</li>';
            echo '<li><strong>Documenti TXT:</strong> '.(int)($counts['document_txt'] ?? 0).'</li>';
            echo '<li><strong>Fonti online AI:</strong> '.(int)($counts['source_online'] ?? 0).'</li>';
            echo '<li><strong>Media:</strong> '.(int)($counts['media'] ?? 0).'</li>';
            echo '</ul>';
            if (!empty($r['warnings'])) { echo '<p><strong>Warning QA:</strong> '.esc_html(implode(' | ', array_map('sanitize_text_field', (array)$r['warnings']))).'</p>'; }
            echo '<p>Puoi revisionare l’articolo in WordPress prima della pubblicazione.</p></div>';
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { return; }
        $tabs = array('dashboard'=>'Dashboard','idee'=>'Idee contenuto','istruzioni-ai'=>'Istruzioni AI','documenti'=>'Documenti TXT','fonti'=>'Fonti online AI','reindex'=>'Reindicizza','log'=>'Stato/log');
        $legacy_map = array('overview'=>'dashboard','reindirizza'=>'reindex','knowledge'=>'dashboard','media'=>'dashboard','bozze'=>'log','programmazione'=>'log',);
        $tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        if (isset($legacy_map[$tab])) { $tab = $legacy_map[$tab]; }
        if (!isset($tabs[$tab])) { $tab = 'dashboard'; }
        echo '<div class="wrap alma-ai-agent-admin"><h1>AI Content Agent</h1>'; self::render_notice();
        echo '<h2 class="nav-tab-wrapper">'; foreach ($tabs as $tk=>$tl) { echo '<a class="nav-tab '.($tk===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$tk)).'">'.esc_html($tl).'</a>'; } echo '</h2>';
        if ($tab === 'dashboard') { self::render_overview_tab(); }
        elseif ($tab === 'istruzioni-ai') { self::render_instructions_tab(); }
        elseif ($tab === 'documenti') { self::render_documents_tab(); }
        elseif ($tab === 'fonti') { self::render_sources_tab(); }
        elseif ($tab === 'reindex') { self::render_reindex_tab(); }
        elseif ($tab === 'log') { self::render_log_tab(); }
        else { self::render_ideas_tab(); }
        echo '</div>';
    }

    private static function render_overview_tab() { global $wpdb;
        $missing = ALMA_AI_Content_Agent_Store::missing_tables();
        $openai = empty(get_option('alma_openai_api_key','')) ? 'Non configurata' : 'Configurata';
        echo '<div class="alma-agent-hero"><h2>Dashboard</h2><p>Panoramica operativa del nuovo workflow AI Content Agent.</p></div>';
        if ($openai !== 'Configurata') { echo '<div class="notice notice-warning inline"><p>OpenAI non è configurata.</p></div>'; }
        echo '<div class="alma-agent-grid">';
        echo '<div class="alma-agent-card"><h3>OpenAI</h3><span class="alma-badge '.($openai === 'Configurata' ? 'is-success' : 'is-warning').'">'.esc_html($openai).'</span></div>';
        echo '<div class="alma-agent-card"><h3>Knowledge Base</h3><strong>'.(self::is_table_missing('knowledge_items') ? 'Non disponibile' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items'))).'</strong></div>';
        echo '<div class="alma-agent-card"><h3>Documenti TXT</h3><strong>'.(self::is_table_missing('knowledge_items') ? 'Nessun dato' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE source_type='document_attachment'")).'</strong></div>';
        echo '<div class="alma-agent-card"><h3>Fonti online AI</h3><strong>'.(self::is_table_missing('sources') ? '0' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('sources'))).'</strong></div>';
        echo '<div class="alma-agent-card"><h3>Media indicizzati</h3><strong>'.(self::is_table_missing('media_index') ? '0' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('media_index'))).'</strong></div>';
        echo '<div class="alma-agent-card"><h3>Ultimi errori</h3><strong>'.esc_html(empty(ALMA_AI_Usage_Logger::get_recent_logs(1)) ? 'Nessun dato' : 'Disponibili in Stato/log').'</strong></div>';
        echo '</div><p><a class="button button-primary" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=idee')).'">Vai a Idee contenuto</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=reindex')).'">Reindicizza fonti</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=reindex')).'">Reindicizza media</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=log')).'">Apri Stato/log</a></p>';
    }

    private static function render_documents_tab() { global $wpdb; echo '<h2>Documenti TXT</h2><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="upload_txt_document"><p><input type="text" name="document_name" class="regular-text" placeholder="Nome documento" required> <input type="file" name="document_file" accept=".txt,text/plain" required> <button class="button button-primary">Carica documento TXT</button></p></form>';
        $rows = $wpdb->get_results("SELECT id,title,status,keywords,indexed_at FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE source_type='document_txt' ORDER BY updated_at DESC", ARRAY_A);
        echo '<h3>Documenti caricati</h3><table class="widefat"><thead><tr><th>Nome</th><th>File</th><th>Stato</th><th>Dimensione</th><th>Ultima indicizzazione</th><th>Azioni</th></tr></thead><tbody>'; if(empty($rows)){ echo '<tr><td colspan="6"><em>Nessun documento TXT nel Knowledge Base.</em></td></tr>'; }
        foreach ($rows as $r) { $meta=(array)json_decode((string)$r['keywords'],true); $file=$meta['file_name']??'-'; $size=isset($meta['file_size'])?size_format((int)$meta['file_size']):'-'; $active=$r['status']==='active'; echo '<tr><td>'.esc_html($r['title']).'</td><td>'.esc_html($file).'</td><td><span class="alma-badge '.($active?'is-success':'is-warning').'">'.esc_html($r['status']).'</span></td><td>'.esc_html($size).'</td><td>'.esc_html($r['indexed_at']).'</td><td>'.self::inline_document_actions((int)$r['id'],$active).'</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function render_sources_tab(){ global $wpdb; echo '<h2>Fonti online AI</h2>'; $choices=ALMA_AI_Content_Agent_Source_Tech_Registry::choices(); echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_source"><p><input type="text" name="name" placeholder="Nome fonte" required> <input type="url" name="source_url" placeholder="URL" required> <select name="source_type">'; foreach($choices as $k=>$l){ echo '<option value="'.esc_attr($k).'">'.esc_html($l).'</option>'; } echo '</select> <label><input type="checkbox" name="is_active" value="1" checked> Attiva</label> <button class="button button-primary">Salva fonte</button></p></form>';
        if (self::is_table_missing('sources')) { echo '<p><em>Tabella sources mancante: elenco fonti non disponibile.</em></p>'; return; }
        $rows = $wpdb->get_results("SELECT id,name,source_type,source_url,is_active,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('sources')." ORDER BY id DESC LIMIT 20", ARRAY_A);
        echo '<h3>Elenco fonti</h3><table class="widefat"><thead><tr><th>Nome</th><th>URL</th><th>Tecnologia</th><th>Stato</th><th>Ultimo uso / ultima indicizzazione</th><th>Ultimo errore</th><th>Azioni</th></tr></thead><tbody>';
        foreach($rows as $r){ echo '<tr><td>'.esc_html($r['name']).'</td><td><a href="'.esc_url($r['source_url']).'" target="_blank" rel="noopener">'.esc_html($r['source_url']).'</a></td><td>'.esc_html($choices[$r['source_type']] ?? $r['source_type']).'</td><td><span class="alma-badge '.((int)$r['is_active']?'is-success':'is-warning').'">'.((int)$r['is_active']?'active':'inactive').'</span></td><td>'.esc_html($r['updated_at']).'</td><td>'.esc_html($r['last_error'] ?? '').'</td><td>'.self::inline_source_actions((int)$r['id'],(int)$r['is_active']).'</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function render_knowledge_tab(){ global $wpdb; echo '<h2>Knowledge Base</h2>'; if (self::is_table_missing('knowledge_items')) { echo '<p><em>Tabella knowledge_items mancante.</em></p>'; return; } $rows=$wpdb->get_results("SELECT id,source_type,title,usage_mode,status FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." ORDER BY id DESC LIMIT 30",ARRAY_A); echo '<table class="widefat"><thead><tr><th>ID</th><th>Tipo Fonte</th><th>Titolo</th><th>Usage mode</th><th>Stato</th></tr></thead><tbody>'; foreach($rows as $r){ echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['source_type']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['usage_mode']).'</td><td>'.esc_html($r['status']).'</td></tr>'; } echo '</tbody></table>'; }
    private static function render_media_tab(){ global $wpdb; echo '<h2>Media Library</h2>'; if (self::is_table_missing('media_index')) { echo '<p><em>Tabella media_index mancante.</em></p>'; return; } $rows=$wpdb->get_results("SELECT id,attachment_id,filename,alt_text FROM ".ALMA_AI_Content_Agent_Store::table('media_index')." ORDER BY id DESC LIMIT 20",ARRAY_A); echo '<table class="widefat"><thead><tr><th>ID</th><th>Attachment ID</th><th>Filename</th><th>Alt text</th><th>Preview</th></tr></thead><tbody>'; foreach($rows as $r){ $thumb=wp_get_attachment_image((int)$r['attachment_id'],array(60,60)); echo '<tr><td>'.(int)$r['id'].'</td><td>'.(int)$r['attachment_id'].'</td><td>'.esc_html($r['filename']).'</td><td>'.esc_html($r['alt_text']).'</td><td>'.($thumb?$thumb:'-').'</td></tr>'; } echo '</tbody></table>'; }
    private static function render_reindex_tab(){ echo '<h2>Reindicizza</h2><div class="alma-agent-card"><p><label><input type="checkbox"> Articoli</label> <label><input type="checkbox"> Pagine</label> <label><input type="checkbox"> Affiliate Links</label> <label><input type="checkbox"> Documenti TXT</label> <label><input type="checkbox"> Fonti online AI</label> <label><input type="checkbox"> Media</label></p><p><button class="button button-primary" disabled>Reindicizza selezionati</button> Disponibile nella prossima fase.</p><ul><li>Elementi analizzati: Nessun dato</li><li>Elementi aggiornati: Nessun dato</li><li>Elementi saltati: Nessun dato</li><li>Errori: Nessun dato</li><li>Durata: Non disponibile</li><li>Link al log: vai a Stato/log</li></ul></div>'; self::action_form('reindex_knowledge','Reindicizza knowledge base'); self::action_form('reindex_media','Reindicizza media'); }
    private static function render_log_tab(){ global $wpdb; $missing=ALMA_AI_Content_Agent_Store::missing_tables(); $jobs=array(); if (!self::is_table_missing('jobs')) { $jobs=$wpdb->get_results("SELECT id,job_type,status,last_error,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('jobs')." ORDER BY id DESC LIMIT 20",ARRAY_A); } $logs=ALMA_AI_Usage_Logger::get_recent_logs(20); echo '<h2>Stato/log</h2><div class="alma-agent-grid"><div class="alma-agent-card"><h3>Job programmati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job in corso</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job completati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job falliti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Ultime chiamate AI</h3><p>'.(empty($logs)?'Nessun dato':'Disponibili sotto').'</p></div><div class="alma-agent-card"><h3>Errori recenti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Bozze create</h3><p>'.count(ALMA_AI_Content_Agent_Store::get_agent_drafts(50)).'</p></div></div><p><strong>Tabelle mancanti:</strong> '.(empty($missing)?'Nessuna':esc_html(implode(', ',$missing))).'</p>'; if (self::is_table_missing('jobs')) { echo '<p><em>Tabella jobs mancante: sezione job non disponibile.</em></p>'; } echo '<h3>Ultimi job/errori</h3><table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Stato</th><th>Errore</th><th>Aggiornato</th></tr></thead><tbody>'; foreach($jobs as $j){ echo '<tr><td>'.(int)$j['id'].'</td><td>'.esc_html($j['job_type']).'</td><td><span class="alma-badge is-pending">'.esc_html($j['status']).'</span></td><td>'.esc_html($j['last_error']).'</td><td>'.esc_html($j['updated_at']).'</td></tr>'; } echo '</tbody></table>'; echo '<h3>Ultimi log AI</h3><table class="widefat"><thead><tr><th>Data</th><th>Task</th><th>Model</th><th>Successo</th><th>Errore</th></tr></thead><tbody>'; foreach($logs as $l){ echo '<tr><td>'.esc_html($l['created_at']).'</td><td>'.esc_html($l['task']).'</td><td>'.esc_html($l['model']).'</td><td>'.((int)$l['success']?'si':'no').'</td><td>'.esc_html($l['error_message']).'</td></tr>'; } echo '</tbody></table>'; }

    private static function render_instructions_tab() { $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(50,0); $active = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); $edit_id=absint($_GET['profile_id'] ?? ($active['id'] ?? 0)); $current=$edit_id?ALMA_AI_Content_Agent_Instructions_Manager::get_profile($edit_id):array(); echo '<h2>Istruzioni AI</h2><h3>Profili</h3><table class="widefat"><thead><tr><th>ID</th><th>Nome</th><th>Attivo</th><th>Default</th><th>Azioni</th></tr></thead><tbody>'; foreach($profiles as $p){ echo '<tr><td>'.(int)$p['id'].'</td><td>'.esc_html($p['profile_name']).'</td><td>'.((int)$p['is_active']?'Sì':'No').'</td><td>'.((int)$p['is_default']?'Sì':'No').'</td><td><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id='.(int)$p['id'])).'">Modifica</a> '.self::inline_profile_action_form('activate_instruction_profile','Attiva',(int)$p['id']).' '.self::inline_profile_action_form('deactivate_instruction_profile','Disattiva',(int)$p['id']).'</td></tr>'; } echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id=0')).'">Crea nuovo profilo</a></p>';
        echo '<h3>'.($edit_id>0?'Modifica profilo':'Nuovo profilo').'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_instruction_profile"><input type="hidden" name="profile_id" value="'.(int)$edit_id.'">';
        $fields=['profile_name'=>'Nome profilo','language_code'=>'Lingua','tone_of_voice'=>'Tono di voce','target_audience'=>'Pubblico target','editorial_style'=>'Stile editoriale','seo_rules'=>'Regole SEO','affiliate_rules'=>'Regole affiliate','image_rules'=>'Regole immagini','source_rules'=>'Regole fonti','anti_duplication_rules'=>'Regole anti-duplicazione','avoid_rules'=>'Cose da evitare','disclosure_policy'=>'Disclosure policy','custom_prompt'=>'Prompt libero personalizzato','internal_notes'=>'Note interne'];
        foreach($fields as $k=>$l){ echo '<p><label><strong>'.esc_html($l).'</strong><br>'; if(in_array($k,['profile_name','language_code'],true)){ echo '<input class="regular-text" name="'.esc_attr($k).'" value="'.esc_attr($current[$k]??'').'">'; } else { echo '<textarea class="large-text" rows="3" name="'.esc_attr($k).'">'.esc_textarea($current[$k]??'').'</textarea>'; } echo '</label></p>'; }
        echo '<p><label><input type="checkbox" name="activate_profile" value="1"> Attiva questo profilo dopo il salvataggio</label></p><p><button class="button button-primary">Salva profilo</button></p></form>';
    }

    private static function render_ideas_tab() {
        echo '<h2>Idee contenuto</h2>';
        $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(100,0);
        $active = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile();
        $profile_options = '<option value="0">Seleziona Profilo Istruzioni AI</option>';
        foreach($profiles as $pp){ if((int)($pp['is_active']??0)!==1){ continue; } $profile_options .= '<option value="'.(int)$pp['id'].'" '.selected((int)($active['id']??0),(int)$pp['id'],false).'>'.esc_html($pp['profile_name']).'</option>'; }
        self::action_form('search_knowledge_base','Cerca contenuti','<p><label><strong>Cerca contenuti per la bozza</strong><br><input class="large-text" name="content_search_query" placeholder="Es. tour Lecce barocco, itinerario Salento, museo ebraico, spiagge Otranto..."></label></p><p><label><strong>Profilo Istruzioni AI</strong> <select name="instruction_profile_id">'.$profile_options.'</select></label> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai')).'">Gestisci Profili Istruzioni AI</a></p><p><textarea name="temporary_instructions" class="large-text" rows="2" placeholder="Istruzioni temporanee per questa bozza"></textarea></p><p><button type="submit" class="button button-secondary" name="do" value="search_knowledge_base">Cerca contenuti</button> <button type="submit" class="button" name="do" value="add_new_search">Aggiungi nuova ricerca</button></p>');

        $summary = ALMA_AI_Content_Agent_Selection_Session::summary();
        $results_groups = ALMA_AI_Content_Agent_Selection_Session::grouped_results(false);
        $selected_groups = ALMA_AI_Content_Agent_Selection_Session::grouped_results(true);
        $labels = array('affiliate_link'=>'Link Affiliati','post'=>'Post','document_txt'=>'File TXT','source_online'=>'Fonti online','page'=>'Pagine','media'=>'Media');
        $limits = array('affiliate_link'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_AFFILIATE_LINKS,'post'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_POSTS,'document_txt'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_DOCUMENT_TXT,'source_online'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_SOURCE_ONLINE,'page'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_PAGES,'media'=>ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_MEDIA);

        echo '<h3>Risultati ricerca</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_selection">';
        foreach ($labels as $groupKey => $groupLabel) { $rows = (array)($results_groups[$groupKey] ?? array()); if (empty($rows)) { continue; } $sel=0; foreach($rows as $r){ if(!empty($r['selected'])){$sel++;}}
            echo '<h4>' . esc_html($groupLabel) . ' (' . count($rows) . ') — max selezionabili '.(int)$limits[$groupKey].' — selezionati '.$sel.'</h4><table class="widefat striped"><tbody>';
            foreach ($rows as $r) { $checked = !empty($r['selected']) ? 'checked' : '';
                echo '<tr><td style="width:40px;"><input name="selected_result_keys[]" type="checkbox" value="' . esc_attr($r['result_key']) . '" ' . $checked . '></td><td><strong>' . esc_html($r['title']) . '</strong><br><small>' . esc_html($r['excerpt']) . '</small><br><span class="alma-badge is-pending">Score ' . (int)$r['score'] . '</span> <small>' . esc_html($r['reason']) . '</small>' . (!empty($r['admin_url']) ? ' <a href="'.esc_url($r['admin_url']).'" target="_blank" rel="noopener">Apri</a>' : '') . '</td></tr>'; }
            echo '</tbody></table>'; }
        echo '<p><button type="submit" class="button button-primary">Salva selezione</button></p></form>';

        echo '<h3>Sessione contenuto</h3><p>Elementi selezionati persistenti usati per JSON payload e Crea Bozza.</p>';
        if ((int)($summary['selected_total'] ?? 0) < 1) { echo '<p><em>Nessun elemento selezionato.</em></p>'; }
        foreach ($labels as $groupKey => $groupLabel) { $rows = (array)($selected_groups[$groupKey] ?? array()); if (empty($rows)) { continue; }
            echo '<h4>'.esc_html($groupLabel).' ('.count($rows).')</h4><ul>';
            foreach($rows as $r){ echo '<li><strong>'.esc_html($r['title']).'</strong> — score '.(int)$r['score'].' — '.esc_html($r['reason']).'</li>'; }
            echo '</ul>'; }

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="download_ai_payload_json"><p><button type="submit" class="button">Scarica JSON payload AI</button></p></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="create_draft_from_selection"><p><button type="submit" class="button button-primary">Crea Bozza con OpenAI</button></p></form>';
    }

    private static function inline_status_form($idea_id,$status,$label){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="set_idea_status"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button button-small">'.esc_html($label).'</button></form>'; }
    private static function inline_brief_form($idea_id){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="generate_brief"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><button class="button button-small button-primary">Brief legacy</button></form>'; }
    private static function inline_draft_form($idea_id, $idea_status){
        $idea_id = absint($idea_id); $idea_status = sanitize_key($idea_status);
        if (in_array($idea_status, array('rejected', 'archived'), true)) { return ''; }
        $brief = ALMA_AI_Content_Agent_Store::get_brief_by_idea($idea_id); if (empty($brief)) { return ''; }
        $existing = ALMA_AI_Content_Agent_Store::get_draft_post_by_idea($idea_id);
        if ($existing) { return 'Bozza #'.(int)$existing->ID.' <a class="button button-small" href="'.esc_url(get_edit_post_link((int)$existing->ID, 'raw')).'">Apri bozza</a>'; }
        return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="generate_draft"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><button class="button button-small button-primary">Genera bozza</button></form>';
    }
    private static function render_drafts_tab(){ $drafts = ALMA_AI_Content_Agent_Store::get_agent_drafts(50); echo '<h2>Bozze</h2><div class="notice notice-info"><p><strong>Workflow base</strong>: genera idee → genera brief → genera bozza → apri in editor e revisiona.</p></div>'; if (empty($drafts)) { echo '<p>Nessuna bozza generata dall’agente al momento.</p>'; return; } echo '<table class="widefat striped"><thead><tr><th>Post ID</th><th>Titolo</th><th>Stato</th><th>Idea ID</th><th>Brief ID</th><th>Modello AI</th><th>Featured image</th><th>Link affiliati</th><th>Warning QA</th><th>Meta SEO</th><th>Data</th><th>Editor</th></tr></thead><tbody>'; foreach($drafts as $p){ $aff=(array)json_decode((string)get_post_meta($p->ID,'_alma_ai_agent_affiliate_links_used',true),true); $warn=(array)json_decode((string)get_post_meta($p->ID,'_alma_ai_agent_qa_warnings',true),true); echo '<tr><td>'.(int)$p->ID.'</td><td>'.esc_html($p->post_title).'</td><td>'.esc_html($p->post_status).'</td><td>'.(int)get_post_meta($p->ID,'_alma_ai_agent_idea_id',true).'</td><td>'.(int)get_post_meta($p->ID,'_alma_ai_agent_brief_id',true).'</td><td>'.esc_html((string)get_post_meta($p->ID,'_alma_ai_agent_model',true)).'</td><td>'.(int)get_post_meta($p->ID,'_alma_ai_agent_featured_image_id',true).'</td><td>'.esc_html(implode(', ',array_map('absint',$aff))).'</td><td>'.esc_html(implode(' | ',array_map('sanitize_text_field',$warn))).'</td><td>'.esc_html((string)get_post_meta($p->ID,'_alma_ai_seo_title',true)).'</td><td>'.esc_html((string)get_post_meta($p->ID,'_alma_ai_generated_at',true)).'</td><td><a class="button button-small" href="'.esc_url(get_edit_post_link((int)$p->ID,'raw')).'">Apri in editor</a></td></tr>'; } echo '</tbody></table>'; }
    private static function is_table_missing($name){ return in_array(ALMA_AI_Content_Agent_Store::table($name), ALMA_AI_Content_Agent_Store::missing_tables(), true); }
    private static function inline_profile_action_form($do,$label,$profile_id){ return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'"><input type="hidden" name="profile_id" value="'.(int)$profile_id.'"><button class="button button-small">'.esc_html($label).'</button></form>'; }

    private static function inline_document_actions($id,$active){ $toggle=$active?0:1; $label=$active?'Disabilita':'Riabilita'; return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_txt_document"><input type="hidden" name="document_id" value="'.(int)$id.'"><input type="hidden" name="status" value="'.($toggle?'active':'inactive').'"><button class="button button-small">'.esc_html($label).'</button></form> <form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_txt_document"><input type="hidden" name="document_id" value="'.(int)$id.'"><button class="button button-small">Elimina dal Knowledge Base</button></form>'; }
    private static function inline_source_actions($id,$active){ $toggle=$active?0:1; return '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_source"><input type="hidden" name="source_id" value="'.(int)$id.'"><input type="hidden" name="is_active" value="'.(int)$toggle.'"><button class="button button-small">'.($active?'Disabilita':'Riabilita').'</button></form> <form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_source"><input type="hidden" name="source_id" value="'.(int)$id.'"><button class="button button-small">Elimina</button></form>'; }
    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
