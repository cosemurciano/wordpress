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
        } elseif ($do === 'index_affiliate_links') {
            $state = get_option('alma_ai_affiliate_index_state', array());
            $batch = ALMA_AI_Content_Agent_Affiliate_Index::index_batch(array('after_id'=>absint($state['last_processed_id'] ?? 0)));
            $result = array('success' => true, 'message' => sprintf('Batch indice link affiliati: processati %d, indicizzati %d.', (int)$batch['processed'], (int)$batch['indexed']));
        } elseif ($do === 'sync_affiliate_links_incremental') {
            $sync = ALMA_AI_Content_Agent_Affiliate_Index::sync_incremental();
            $result = array('success' => true, 'message' => sprintf('Sync incrementale link affiliati: processati %d, indicizzati %d.', (int)$sync['processed'], (int)$sync['indexed']));
        } elseif ($do === 'reset_affiliate_index_state') {
            ALMA_AI_Content_Agent_Affiliate_Index::reset_batch_state();
            $result = array('success' => true, 'message' => 'Stato batch indice link affiliati resettato (azione non distruttiva).');
        } elseif ($do === 'search_knowledge_base' || $do === 'add_new_search') {
            $profile_id = absint($_POST['instruction_profile_id'] ?? 0);
            $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : array();
            $payload = array('max_ideas'=>absint($_POST['max_ideas'] ?? 1),'content_search_query'=>sanitize_text_field($_POST['content_search_query'] ?? ($_POST['search_terms'] ?? '')),'search_terms'=>sanitize_text_field($_POST['search_terms'] ?? ($_POST['content_search_query'] ?? '')),'theme'=>sanitize_text_field($_POST['theme'] ?? ''),'destination'=>sanitize_text_field($_POST['destination'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($_POST['temporary_instructions'] ?? ''),'openai_prompt'=>sanitize_textarea_field($_POST['openai_prompt'] ?? $_POST['temporary_instructions'] ?? ''),'instruction_profile_id'=>$profile_id,'instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ''),'instruction_snapshot_hash'=>sanitize_text_field($profile ? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash(wp_json_encode($profile)) : ''));
            $search = ALMA_AI_Content_Agent_Knowledge_Search::search($payload);
            $stats = ALMA_AI_Content_Agent_Selection_Session::add_search_results($payload, $search);
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id < 1) {
                $idea_title = sanitize_text_field($payload['content_search_query'] ?: 'Nuova idea');
                $active_idea_id = ALMA_AI_Content_Agent_Ideas::create($idea_title);
                if ($active_idea_id > 0) { update_user_meta(get_current_user_id(), '_alma_active_idea_id', $active_idea_id); }
            }
            if ($active_idea_id > 0) {
                ALMA_AI_Content_Agent_Ideas::save_from_request($active_idea_id, array('idea_title'=>sanitize_text_field($payload['content_search_query'] ?: 'Nuova idea'),'idea_status'=>'bozza','instruction_profile_id'=>$profile_id,'openai_prompt'=>$payload['openai_prompt']));
                update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_LAST_QUERY, array('content_search_query'=>$payload['content_search_query']));
                ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($active_idea_id);
            }
            $result = array('success' => true, 'message' => sprintf('Ricerca completata. Trovati %d risultati.', (int)$stats['found'], (int)$stats['added'], (int)$stats['duplicates']));
        } elseif ($do === 'add_selected_to_idea') {
            $selected_result_keys = array_map('sanitize_text_field', (array)($_POST['selected_result_keys'] ?? array()));
            if (empty($selected_result_keys)) { $result = array('success'=>false,'message'=>'Seleziona almeno un risultato da aggiungere all’idea.'); }
            else { $result = ALMA_AI_Content_Agent_Selection_Session::add_selected_results($selected_result_keys); }
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id > 0) { ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($active_idea_id); }
        } elseif ($do === 'clear_selection_session') {
            ALMA_AI_Content_Agent_Selection_Session::clear();
            $result = array('success' => true, 'message' => 'Sessione contenuto svuotata.');
        } elseif ($do === 'clear_content_idea_search') {
            $session = ALMA_AI_Content_Agent_Selection_Session::clear_search_results();
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id > 0) {
                update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_LAST_QUERY, array());
                update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_RESULTS, array());
                update_post_meta($active_idea_id, ALMA_AI_Content_Agent_Ideas::META_SELECTION, array_values((array)($session['selected_results'] ?? array())));
                ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($active_idea_id);
            }
            $result = array('success' => true, 'message' => 'Risultati ricerca svuotati.');
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
        } elseif ($do === 'new_content_idea') {
            $idea_id = ALMA_AI_Content_Agent_Ideas::create('Nuova idea');
            if ($idea_id > 0) { update_user_meta(get_current_user_id(), '_alma_active_idea_id', $idea_id); ALMA_AI_Content_Agent_Selection_Session::clear(); }
            $result = array('success' => $idea_id > 0, 'message' => $idea_id > 0 ? 'Nuova idea creata.' : 'Errore creazione idea.');
        } elseif ($do === 'load_content_idea') {
            $idea_id = absint($_POST['idea_id'] ?? 0);
            update_user_meta(get_current_user_id(), '_alma_active_idea_id', $idea_id);
            $idea = ALMA_AI_Content_Agent_Ideas::get($idea_id);
            ALMA_AI_Content_Agent_Selection_Session::load_from_idea($idea);
            $result = array('success' => !empty($idea), 'message' => !empty($idea) ? 'Idea caricata.' : 'Idea non trovata.');
        } elseif ($do === 'save_content_idea') {
            $idea_id = absint($_POST['idea_id'] ?? 0);
            $ok = ALMA_AI_Content_Agent_Ideas::save_from_request($idea_id, $_POST);
            if ($ok) { ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($idea_id); }
            $result = array('success' => $ok, 'message' => $ok ? 'Idea salvata.' : 'Errore salvataggio idea.');
        } elseif ($do === 'delete_content_idea') {
            $idea_id = absint($_POST['idea_id'] ?? 0); $ok = $idea_id > 0 ? (bool)wp_delete_post($idea_id, true) : false;
            if ($ok) { delete_user_meta(get_current_user_id(), '_alma_active_idea_id'); ALMA_AI_Content_Agent_Selection_Session::clear(); }
            $result = array('success' => $ok, 'message' => $ok ? 'Idea eliminata.' : 'Impossibile eliminare idea.');
        } elseif ($do === 'add_result_to_idea') {
            $k = sanitize_text_field($_POST['result_key'] ?? '');
            $result = ALMA_AI_Content_Agent_Selection_Session::add_single_result($k);
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id > 0) { ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($active_idea_id); }

        } elseif ($do === 'remove_selected_item') {
            $k = sanitize_text_field($_POST['result_key'] ?? '');
            $result = ALMA_AI_Content_Agent_Selection_Session::remove_selected_item($k);
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id > 0) { ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($active_idea_id); }

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
        $redirect_url = wp_get_referer();
        if ($do === 'clear_content_idea_search') {
            $redirect_url = admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=idee');
            $redirect_url = remove_query_arg(array('alma_results_page', 'alma_result_type'), $redirect_url);
        }
        wp_safe_redirect($redirect_url); exit;
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
        self::render_affiliate_index_status_box();
        echo '<div class="alma-agent-grid">';
        echo '<div class="alma-agent-card"><h3>OpenAI</h3><span class="alma-badge '.($openai === 'Configurata' ? 'is-success' : 'is-warning').'">'.esc_html($openai).'</span></div>';
        echo '<div class="alma-agent-card"><h3>Knowledge Base</h3><strong>'.(self::is_table_missing('knowledge_items') ? 'Non disponibile' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items'))).'</strong></div>';
        echo '<div class="alma-agent-card"><h3>Documenti TXT</h3><strong>'.(self::is_table_missing('knowledge_items') ? 'Nessun dato' : (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE source_type='document_txt'")).'</strong></div>';
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
    private static function render_log_tab(){ global $wpdb; $missing=ALMA_AI_Content_Agent_Store::missing_tables(); $jobs=array(); if (!self::is_table_missing('jobs')) { $jobs=$wpdb->get_results("SELECT id,job_type,status,last_error,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('jobs')." ORDER BY id DESC LIMIT 20",ARRAY_A); } $logs=ALMA_AI_Usage_Logger::get_recent_logs(20); echo '<h2>Stato/log</h2>'; self::render_affiliate_index_status_box(); echo '<div class="alma-agent-grid"><div class="alma-agent-card"><h3>Job programmati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job in corso</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job completati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job falliti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Ultime chiamate AI</h3><p>'.(empty($logs)?'Nessun dato':'Disponibili sotto').'</p></div><div class="alma-agent-card"><h3>Errori recenti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Bozze create</h3><p>'.count(ALMA_AI_Content_Agent_Store::get_agent_drafts(50)).'</p></div></div><p><strong>Tabelle mancanti:</strong> '.(empty($missing)?'Nessuna':esc_html(implode(', ',$missing))).'</p>'; if (self::is_table_missing('jobs')) { echo '<p><em>Tabella jobs mancante: sezione job non disponibile.</em></p>'; } echo '<h3>Ultimi job/errori</h3><table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Stato</th><th>Errore</th><th>Aggiornato</th></tr></thead><tbody>'; foreach($jobs as $j){ echo '<tr><td>'.(int)$j['id'].'</td><td>'.esc_html($j['job_type']).'</td><td><span class="alma-badge is-pending">'.esc_html($j['status']).'</span></td><td>'.esc_html($j['last_error']).'</td><td>'.esc_html($j['updated_at']).'</td></tr>'; } echo '</tbody></table>'; echo '<h3>Ultimi log AI</h3><table class="widefat"><thead><tr><th>Data</th><th>Task</th><th>Model</th><th>Successo</th><th>Errore</th></tr></thead><tbody>'; foreach($logs as $l){ echo '<tr><td>'.esc_html($l['created_at']).'</td><td>'.esc_html($l['task']).'</td><td>'.esc_html($l['model']).'</td><td>'.((int)$l['success']?'si':'no').'</td><td>'.esc_html($l['error_message']).'</td></tr>'; } echo '</tbody></table>'; }

    private static function render_affiliate_index_status_box() {
        if (!class_exists('ALMA_AI_Content_Agent_Affiliate_Index')) { return; }
        $stats = ALMA_AI_Content_Agent_Affiliate_Index::get_index_stats();
        $batch = (array)($stats['batch_state'] ?? array());
        $batch_status = !empty($batch['done']) ? 'completato' : (!empty($batch['updated_at']) ? 'in corso' : 'mai avviato');
        echo '<div class="alma-agent-card"><h3>Indice Link affiliati</h3>';
        if (empty($stats['table_exists'])) { echo '<p><em>Tabella indice non disponibile. La ricerca userà fallback WordPress per i Link affiliati.</em></p>'; }
        echo '<ul><li>Totale Link affiliati pubblicati: '.(int)$stats['total_published'].'</li><li>Indicizzati attivi: '.(int)$stats['indexed_active'].'</li><li>Non indicizzati stimati: '.(int)$stats['not_indexed'].'</li><li>Da aggiornare: '.(int)($stats['needs_update'] ?? 0).'</li><li>Link affiliati senza URL affiliato: '.(int)$stats['without_affiliate_url'].'</li><li>Record indice inattivi: '.(int)$stats['inactive_index_records'].'</li><li>Ultimo aggiornamento indice: '.esc_html($stats['last_indexed_at'] ?: 'N/D').'</li><li>Stato batch: '.esc_html($batch_status).'</li><li>Last processed ID: '.(int)($batch['last_processed_id'] ?? 0).'</li>'.(!empty($batch['last_error'])?'<li>Ultimo errore: '.esc_html($batch['last_error']).'</li>':'').'</ul>';
        echo '<div class="alma-actions-inline">';
        self::action_form('index_affiliate_links','Indicizza prossimo batch');
        self::action_form('sync_affiliate_links_incremental','Sync incrementale');
        self::action_form('reset_affiliate_index_state','Reset stato batch');
        echo '</div></div>';
    }

    private static function render_instructions_tab() { $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(50,0); $active = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); $edit_id=absint($_GET['profile_id'] ?? ($active['id'] ?? 0)); $current=$edit_id?ALMA_AI_Content_Agent_Instructions_Manager::get_profile($edit_id):array(); echo '<h2>Istruzioni AI</h2><h3>Profili</h3><table class="widefat"><thead><tr><th>ID</th><th>Nome</th><th>Attivo</th><th>Default</th><th>Azioni</th></tr></thead><tbody>'; foreach($profiles as $p){ echo '<tr><td>'.(int)$p['id'].'</td><td>'.esc_html($p['profile_name']).'</td><td>'.((int)$p['is_active']?'Sì':'No').'</td><td>'.((int)$p['is_default']?'Sì':'No').'</td><td><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id='.(int)$p['id'])).'">Modifica</a> '.self::inline_profile_action_form('activate_instruction_profile','Attiva',(int)$p['id']).' '.self::inline_profile_action_form('deactivate_instruction_profile','Disattiva',(int)$p['id']).'</td></tr>'; } echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id=0')).'">Crea nuovo profilo</a></p>';
        echo '<h3>'.($edit_id>0?'Modifica profilo':'Nuovo profilo').'</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_instruction_profile"><input type="hidden" name="profile_id" value="'.(int)$edit_id.'">';
        $fields=['profile_name'=>'Nome profilo','language_code'=>'Lingua','tone_of_voice'=>'Tono di voce','target_audience'=>'Pubblico target','editorial_style'=>'Stile editoriale','seo_rules'=>'Regole SEO','affiliate_rules'=>'Regole affiliate','image_rules'=>'Regole immagini','source_rules'=>'Regole fonti','anti_duplication_rules'=>'Regole anti-duplicazione','avoid_rules'=>'Cose da evitare','disclosure_policy'=>'Disclosure policy','custom_prompt'=>'Prompt libero personalizzato','internal_notes'=>'Note interne'];
        foreach($fields as $k=>$l){ echo '<p><label><strong>'.esc_html($l).'</strong><br>'; if(in_array($k,['profile_name','language_code'],true)){ echo '<input class="regular-text" name="'.esc_attr($k).'" value="'.esc_attr($current[$k]??'').'">'; } else { echo '<textarea class="large-text" rows="3" name="'.esc_attr($k).'">'.esc_textarea($current[$k]??'').'</textarea>'; } echo '</label></p>'; }
        echo '<p><label><input type="checkbox" name="activate_profile" value="1"> Attiva questo profilo dopo il salvataggio</label></p><p><button class="button button-primary">Salva profilo</button></p></form>';
    }

    private static function render_ideas_tab() {
        $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
        $ideas = get_posts(array('post_type'=>ALMA_AI_Content_Agent_Ideas::CPT,'post_status'=>'publish','numberposts'=>50,'orderby'=>'modified','order'=>'DESC','author'=>get_current_user_id()));
        if ($active_idea_id > 0) {
            $active_post = get_post($active_idea_id);
            if (!$active_post || $active_post->post_type !== ALMA_AI_Content_Agent_Ideas::CPT) {
                delete_user_meta(get_current_user_id(), '_alma_active_idea_id');
                $active_idea_id = 0;
            }
        }
        if ($active_idea_id < 1 && !empty($ideas)) { $active_idea_id = (int)$ideas[0]->ID; update_user_meta(get_current_user_id(), '_alma_active_idea_id', $active_idea_id); }
        $active_idea = $active_idea_id ? ALMA_AI_Content_Agent_Ideas::get($active_idea_id) : array();
        if (!empty($active_idea['ID'])) { ALMA_AI_Content_Agent_Selection_Session::load_from_idea($active_idea); }
        else { ALMA_AI_Content_Agent_Selection_Session::clear(); }
        $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(100,0);
        if (!is_array($profiles)) { $profiles = array(); }
        $session = ALMA_AI_Content_Agent_Selection_Session::get_session();
        $summary = ALMA_AI_Content_Agent_Selection_Session::summary();
        $results_groups = ALMA_AI_Content_Agent_Selection_Session::grouped_results(false);
        $selected_groups = ALMA_AI_Content_Agent_Selection_Session::grouped_results(true);
        $selected_map = array();
        foreach ($selected_groups as $rows) {
            foreach ((array)$rows as $row) {
                $result_key = sanitize_text_field($row['result_key'] ?? '');
                if ($result_key !== '') { $selected_map[$result_key] = true; }
            }
        }
        $labels = array('affiliate_link'=>'Link Affiliati','post'=>'Post','document_txt'=>'File TXT','source_online'=>'Fonti online','page'=>'Pagine','media'=>'Media');
        $usage_keys = array();
        foreach ((array)$session['search_results'] as $row) { if (!is_array($row)) { continue; } $rk = sanitize_text_field($row['result_key'] ?? ''); if ($rk !== '') { $usage_keys[] = $rk; } }
        foreach ((array)$session['selected_results'] as $row) { if (!is_array($row)) { continue; } $rk = sanitize_text_field($row['result_key'] ?? ''); if ($rk !== '') { $usage_keys[] = $rk; } }
        $usage_counts = ALMA_AI_Content_Agent_Result_Usage::get_counts(array_values(array_unique($usage_keys)));

        echo '<div class="alma-ideas-toolbar alma-ideas-card">';
        echo '<div><label for="alma-idea-title"><strong>Titolo idea</strong></label><input id="alma-idea-title" class="regular-text alma-idea-title-input" form="alma-save-idea-form" type="text" name="idea_title" value="'.esc_attr($active_idea['title'] ?? 'Nuova idea').'">';
        echo '<p class="description">'.esc_html(!empty($active_idea['executed_at']) ? ('Eseguita il '.$active_idea['executed_at']) : 'Non eseguita').'</p></div>';
        if (!empty($active_idea['draft_post_id']) && get_post((int)$active_idea['draft_post_id'])) { echo '<a class="button" href="'.esc_url(get_edit_post_link((int)$active_idea['draft_post_id'],'raw')).'">Apri bozza</a>'; }
        echo '<div class="alma-actions-inline">';
        self::action_form('new_content_idea','Crea nuova idea');
        if($active_idea_id){ echo '<form id="alma-save-idea-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_content_idea"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><input type="hidden" name="idea_status" value="bozza"><input type="hidden" name="instruction_profile_id" value="'.(int)($active_idea['profile_id']??0).'"><input type="hidden" name="openai_prompt" value="'.esc_attr($active_idea['prompt']??'').'"><button class="button">Salva idea</button></form>'; }
        if($active_idea_id){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_content_idea"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><button class="button">Elimina</button></form>'; }
        self::action_form('download_ai_payload_json','Scarica JSON payload AI');
        self::action_form('create_draft_from_selection','Crea Bozza con OpenAI');
        echo '</div></div>';

        echo '<div class="alma-ideas-layout">';
        echo '<aside class="alma-ideas-col alma-ideas-col-left"><div class="alma-ideas-card"><h3>Idea attiva</h3><p class="alma-idea-title-lg">'.esc_html($active_idea['title'] ?? 'Nessuna idea attiva').'</p><p>'.esc_html(!empty($active_idea['executed_at']) ? ('Eseguita il '.$active_idea['executed_at']) : 'Non eseguita').'</p>';
        if ($active_idea_id < 1) { echo '<p class="description">Nessuna idea attiva. Usa il pulsante Crea nuova idea per iniziare.</p>'; }
        if (!empty($active_idea['draft_post_id']) && get_post((int)$active_idea['draft_post_id'])) { echo '<p><a href="'.esc_url(get_edit_post_link((int)$active_idea['draft_post_id'],'raw')).'">Apri bozza</a></p>'; }
        if (!empty($active_idea['modified'])) { echo '<p class="description">Ultima modifica: '.esc_html($active_idea['modified']).'</p>'; }
        echo '<h4>Idee create</h4>';
        if (empty($ideas)) { echo '<p class="description">Nessuna idea creata.</p>'; }
        else { echo '<ul class="alma-ideas-list">'; foreach($ideas as $idea_post){ echo '<li><strong>'.esc_html($idea_post->post_title).'</strong><br><span class="description">'.esc_html($idea_post->post_modified).'</span> '.(((int)$idea_post->ID===(int)$active_idea_id)?'<span class="alma-added-badge">Attiva</span>':'').'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="load_content_idea"><input type="hidden" name="idea_id" value="'.(int)$idea_post->ID.'"><button class="button button-small">Carica</button></form></li>'; } echo '</ul>'; }
        echo '<ul><li>Contenuti aggiunti: '.(int)($summary['selected_total'] ?? 0).'</li><li>Profilo istruzioni AI: '.(int)($active_idea['profile_id'] ?? 0).'</li><li>Prompt OpenAI: '.(!empty($active_idea['prompt']) ? 'Presente' : 'Assente').'</li></ul></div></aside>';

        echo '<main class="alma-ideas-col alma-ideas-col-main"><div class="alma-ideas-card"><h3>1. Cerca contenuti</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="search_knowledge_base"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><p><input class="widefat" type="text" name="content_search_query" placeholder="Cerca contenuti" value="'.esc_attr($session['last_query']['content_search_query'] ?? '').'" required></p><p><label>Profilo Istruzioni AI</label><select class="widefat" name="instruction_profile_id"><option value="0">Nessun profilo</option>';
        foreach($profiles as $p){ $sel=selected((int)($active_idea['profile_id'] ?? 0),(int)$p['id'],false); echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.esc_html($p['profile_name']).'</option>'; }
        echo '</select></p><p><label>Prompt per OpenAI</label><textarea class="widefat" rows="4" name="openai_prompt">'.esc_textarea($active_idea['prompt'] ?? ($session['openai_prompt'] ?? '')).'</textarea><span class="description">Questo prompt verrà inviato a OpenAI insieme ai contenuti raccolti e guiderà cosa scrivere nella bozza.</span></p><p><button class="button button-primary">Cerca contenuti</button></p></form></div>';

        $all_rows = array();
        foreach($labels as $k=>$label){ foreach((array)($results_groups[$k]??array()) as $r){ $all_rows[] = array('group'=>$k,'label'=>$label,'row'=>$r); } }
        $available_groups = array();
        foreach ($all_rows as $item) { $available_groups[$item['group']] = true; }
        $active_result_type = sanitize_key($_GET['alma_result_type'] ?? '');
        if ($active_result_type === '' || !isset($available_groups[$active_result_type])) { $active_result_type = 'all'; }
        $filtered_rows = $all_rows;
        if ($active_result_type !== 'all') {
            $filtered_rows = array_values(array_filter($all_rows, function($item) use ($active_result_type){ return ($item['group'] ?? '') === $active_result_type; }));
        }
        $per_page = 10;
        $total_results = count($filtered_rows);
        $total_pages = max(1, (int)ceil($total_results / $per_page));
        $requested_page = absint($_GET['alma_results_page'] ?? 1);
        $filter_in_request = array_key_exists('alma_result_type', $_GET);
        $current_page = $filter_in_request ? 1 : max(1, $requested_page);
        if ($current_page > $total_pages) { $current_page = $total_pages; }
        $paged_rows = array_slice($filtered_rows, ($current_page-1)*$per_page, $per_page);

        echo '<div class="alma-ideas-card"><div class="alma-results-header"><h3>2. Risultati ricerca</h3>';
        if (!empty($all_rows)) {
            echo '<div class="alma-results-actions">';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="clear_content_idea_search"><button class="button" type="submit">Svuota ricerca</button></form>';
            echo '<form method="get" action="'.esc_url(admin_url('edit.php')).'"><input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="alma-ai-content-agent"><input type="hidden" name="tab" value="idee"><label for="alma_result_type" class="screen-reader-text">Tipologia contenuto</label><select id="alma_result_type" name="alma_result_type" onchange="this.form.submit()"><option value="all">Tutte le tipologie</option>';
            foreach(array_keys($available_groups) as $group_key){ echo '<option value="'.esc_attr($group_key).'" '.selected($active_result_type,$group_key,false).'>'.esc_html($labels[$group_key] ?? ucfirst($group_key)).'</option>'; }
            echo '</select></form></div>';
        }
        echo '</div>';

        echo '<p class="description">Totale risultati: '.(int)$total_results.' · Pagina '.(int)$current_page.' di '.(int)$total_pages.'</p>';
        echo '<form id="alma-bulk-add-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="add_selected_to_idea"></form>';
        if (empty($paged_rows)) {
            if ($active_result_type !== 'all' && !empty($all_rows)) { echo '<p class="description">Nessun risultato per questa tipologia. Cambia filtro o svuota la ricerca.</p>'; }
            else { echo '<p class="description">Nessun risultato disponibile. Esegui una ricerca per popolare questa sezione.</p>'; }
        }
        foreach($paged_rows as $item){ $r=(array)$item['row']; $rk=sanitize_text_field($r['result_key'] ?? ''); if($rk===''){continue;} $in=!empty($selected_map[$rk]); $usage=(int)($usage_counts[$rk] ?? 0); echo '<div class="alma-result-item'.($in?' is-already-added':'').'"><p><strong>'.esc_html($r['title']).'</strong> <span class="alma-count-badge">'.esc_html($item['label']).'</span>'; if($in){echo ' <span class="alma-added-badge">Già nell’idea</span>';} echo ' <span class="alma-usage-badge">Utilizzato in bozze: '.$usage.'</span></p><label><input type="checkbox" name="selected_result_keys[]" value="'.esc_attr($rk).'" form="alma-bulk-add-form" '.disabled($in,true,false).'> Seleziona</label><p class="alma-excerpt">'.esc_html(wp_trim_words((string)($r['excerpt'] ?? ''),20,'…')).'</p><p class="description">Score: '.(int)($r['score'] ?? 0).' · '.esc_html($r['reason'] ?? '').'</p>'; if($in){ echo '<button class="button button-small" type="button" disabled>Già aggiunto</button>'; } else { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="add_result_to_idea"><input type="hidden" name="result_key" value="'.esc_attr($rk).'"><button class="button button-small">Aggiungi all’idea</button></form>'; } echo '</div>'; }
        $base = admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=idee');
        $nav_args = array();
        if ($active_result_type !== 'all') { $nav_args['alma_result_type'] = $active_result_type; }
        echo '<p>'; if ($current_page>1){ echo '<a class="button" href="'.esc_url(add_query_arg(array_merge($nav_args, array('alma_results_page'=>$current_page-1)),$base)).'">← Precedente</a> '; } if ($current_page<$total_pages){ echo '<a class="button" href="'.esc_url(add_query_arg(array_merge($nav_args, array('alma_results_page'=>$current_page+1)),$base)).'">Successiva →</a>'; } echo '</p><p><button class="button button-primary" type="submit" form="alma-bulk-add-form">Aggiungi selezionati all’idea</button></p></div></main>';

echo '<aside class="alma-ideas-col alma-ideas-col-right"><div class="alma-ideas-card"><h3>3. Sessione contenuto</h3><p><strong>Totale elementi aggiunti:</strong> '.(int)($summary['selected_total'] ?? 0).'</p>';
        if ((int)($summary['selected_total'] ?? 0) < 1) { echo '<p>Nessun contenuto aggiunto all’idea.</p>'; }
        foreach($labels as $k=>$label){ $rows=(array)($selected_groups[$k]??array()); if (empty($rows)) { continue; } echo '<section class="alma-results-group"><h4>'.esc_html($label).' <span class="alma-count-badge">'.count($rows).'</span></h4>'; foreach($rows as $r){ $rk=sanitize_text_field($r['result_key'] ?? ''); $usage=(int)($usage_counts[$rk] ?? 0); echo '<div class="alma-result-item"><strong>'.esc_html($r['title'] ?? '').'</strong><p class="description">Score: '.(int)($r['score'] ?? 0).' · '.esc_html($r['reason'] ?? '').'</p><span class="alma-usage-badge">Utilizzato in bozze: '.$usage.'</span><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="remove_selected_item"><input type="hidden" name="result_key" value="'.esc_attr($rk).'"><button class="button button-small">Rimuovi</button></form></div>'; }
        echo '</section>'; }
        echo '</div></aside></div>';
    }


    private static function is_table_missing($table_key) { global $wpdb; $map=array('knowledge_items','sources','jobs','media_index','instruction_profiles','content_chunks','content_ideas','editorial_briefs','affiliate_index'); if($table_key==='usage'||$table_key==='alma_ai_usage'){ $table_name=ALMA_AI_Usage_Logger::table_name(); } elseif(!in_array($table_key,$map,true)){ return true; } else { $table_name=ALMA_AI_Content_Agent_Store::table($table_key);} $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table_name)); return $exists!==$table_name; }
    private static function inline_document_actions($document_id, $is_active) { $document_id=absint($document_id); if($document_id<1||!current_user_can('manage_options')){return '';} $toggle=$is_active?'inactive':'active'; return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_txt_document"><input type="hidden" name="document_id" value="'.$document_id.'"><input type="hidden" name="status" value="'.esc_attr($toggle).'"><button class="button button-small">'.($is_active?'Disabilita':'Abilita').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_txt_document"><input type="hidden" name="document_id" value="'.$document_id.'"><button class="button button-small">Elimina</button></form>'; }
    private static function inline_source_actions($source_id, $is_active) { $source_id=absint($source_id); if($source_id<1||!current_user_can('manage_options')){return '';} $next=(int)$is_active?0:1; return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_source"><input type="hidden" name="source_id" value="'.$source_id.'"><input type="hidden" name="is_active" value="'.$next.'"><button class="button button-small">'.($is_active?'Disabilita':'Abilita').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_source"><input type="hidden" name="source_id" value="'.$source_id.'"><button class="button button-small">Elimina</button></form>'; }
    private static function inline_profile_action_form($do, $label, $profile_id) { $profile_id=absint($profile_id); if($profile_id<1||!current_user_can('manage_options')){return '';} return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-left:4px;">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'"><input type="hidden" name="profile_id" value="'.$profile_id.'"><button class="button button-small">'.esc_html($label).'</button></form>'; }

    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
