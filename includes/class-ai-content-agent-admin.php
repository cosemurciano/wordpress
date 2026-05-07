<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    const NOTICE_TRANSIENT_KEY = 'alma_ai_agent_admin_notice_';
    const RESULT_TRANSIENT_KEY = 'alma_ai_agent_admin_result_';

    public static function init() { add_action('admin_post_alma_ai_agent_action', array(__CLASS__, 'handle_action')); }

    public static function handle_action() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $do = sanitize_key($_POST['do'] ?? '');
        if (in_array($do, array('activate_instruction_profile','deactivate_instruction_profile'), true)) {
            $profile_id_for_nonce = absint($_POST['profile_id'] ?? 0);
            check_admin_referer('alma_ai_instruction_profile_toggle_' . $profile_id_for_nonce, '_alma_profile_nonce');
        } else {
            check_admin_referer('alma_ai_agent_action');
        }
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
            $profile_data = wp_unslash($_POST);
            $new_id = ALMA_AI_Content_Agent_Instructions_Manager::save_profile($profile_data, $id);
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
            $stats = ALMA_AI_Content_Agent_Media_Index::rebuild_index(100);
            $message = sprintf(
                'Indice media ricostruito: processati %d attachment, immagini rilevate %d, indicizzati %d, non immagini saltati %d, senza URL %d, errori %d, rimossi %d record non validi.',
                (int)($stats['processed'] ?? 0),
                (int)($stats['detected_images'] ?? 0),
                (int)($stats['indexed'] ?? 0),
                (int)($stats['non_images_skipped'] ?? 0),
                (int)($stats['missing_url'] ?? 0),
                (int)($stats['errors'] ?? 0),
                (int)($stats['deleted'] ?? 0)
            );
            if (!empty($stats['error_messages'])) {
                $message .= ' Warning DB: ' . implode(' | ', array_map('sanitize_text_field', (array)$stats['error_messages']));
            }
            $result = array('success' => empty($stats['errors']), 'message' => $message);
        } elseif ($do === 'rebuild_internal_link_index') {
            $stats = ALMA_AI_Content_Agent_Internal_Link_Index::rebuild_index(200);
            $result = array('success' => true, 'message' => sprintf('Indice link interni ricostruito: processati %d, indicizzati %d post pubblicati.', (int)$stats['processed'], (int)$stats['indexed']));
        } elseif ($do === 'index_affiliate_links') {
            $state = get_option('alma_ai_affiliate_index_state', array());
            $batch = ALMA_AI_Content_Agent_Affiliate_Index::index_batch(array('after_id'=>absint($state['last_processed_id'] ?? 0)));
            $result = array('success' => true, 'message' => sprintf('Batch indicizzazione eseguito: processati %d, indicizzati %d. %s', (int)$batch['processed'], (int)$batch['indexed'], !empty($batch['done']) ? 'Indicizzazione completata.' : 'Continua con il prossimo batch per completare l’indice.'));
        } elseif ($do === 'sync_affiliate_links_incremental') {
            $sync = ALMA_AI_Content_Agent_Affiliate_Index::sync_incremental();
            $stats_after_sync = ALMA_AI_Content_Agent_Affiliate_Index::get_index_stats();
            $pending_after_sync = max(0, (int)($stats_after_sync['needs_update'] ?? 0));
            $sync_tail_message = (int)$sync['processed'] > 0
                ? 'Indice tecnico aggiornato su record mancanti, obsoleti e candidabili non attivi rilevati.'
                : ($pending_after_sync > 0
                    ? 'Nessun record processato in questo batch: restano candidabili da lavorare. Verifica indice tecnico e ripeti sync incrementale o batch indicizzazione se presenti mancanti.'
                    : 'Nessun record candidabile da aggiornare in questo batch.');
            $result = array('success' => true, 'message' => sprintf('Sync incrementale completata: processati %d, aggiornati %d. %s', (int)$sync['processed'], (int)$sync['indexed'], $sync_tail_message));
        } elseif ($do === 'reset_affiliate_index_state') {
            ALMA_AI_Content_Agent_Affiliate_Index::reset_batch_state();
            $result = array('success' => true, 'message' => 'Stato batch resettato: nessun Link affiliato è stato eliminato e l’indice tecnico resta disponibile.');
        } elseif ($do === 'clear_affiliate_index') {
            $clear = ALMA_AI_Content_Agent_Affiliate_Index::clear_index();
            if (empty($clear['table_exists'])) {
                $result = array('success' => true, 'message' => 'Indice tecnico non presente: stato batch resettato.');
            } elseif (!empty($clear['success'])) {
                $result = array('success' => true, 'message' => sprintf('Indice tecnico svuotato: %d record rimossi e stato batch azzerato. Operazione non elimina i Link affiliati.', (int) ($clear['deleted'] ?? 0)));
            } else {
                $result = array('success' => false, 'message' => 'Errore durante lo svuotamento dell\'indice tecnico Link affiliati.');
            }
        } elseif ($do === 'search_knowledge_base' || $do === 'add_new_search') {
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            $active_idea = $active_idea_id > 0 ? ALMA_AI_Content_Agent_Ideas::get($active_idea_id) : array();
            $current_profile_id = absint($active_idea['instruction_profile_id'] ?? ($active_idea['profile_id'] ?? 0));
            $profile_id = $current_profile_id;
            if (array_key_exists('instruction_profile_id', $_POST)) {
                $raw_profile_id = sanitize_text_field((string) $_POST['instruction_profile_id']);
                if ($raw_profile_id === '0' || $raw_profile_id === '') {
                    $profile_id = 0;
                } else {
                    $candidate_profile_id = absint($raw_profile_id);
                    $candidate_profile = $candidate_profile_id > 0 ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($candidate_profile_id) : array();
                    if (!empty($candidate_profile['id'])) {
                        $profile_id = (int) $candidate_profile['id'];
                    }
                }
            }
            $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : array();
            $temporary_instructions = ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea(wp_unslash($_POST['temporary_instructions'] ?? ''));
            $instruction_snapshot = !empty($profile) ? ALMA_AI_Content_Agent_Instructions_Manager::build_compact_instruction_block($profile, $temporary_instructions) : '';
            $payload = array('max_ideas'=>absint($_POST['max_ideas'] ?? 1),'content_search_query'=>sanitize_text_field($_POST['content_search_query'] ?? ($_POST['search_terms'] ?? '')),'search_terms'=>sanitize_text_field($_POST['search_terms'] ?? ($_POST['content_search_query'] ?? '')),'theme'=>sanitize_text_field($_POST['theme'] ?? ''),'destination'=>sanitize_text_field($_POST['destination'] ?? ''),'temporary_instructions'=>$temporary_instructions,'openai_prompt'=>ALMA_AI_Content_Agent_Instructions_Manager::sanitize_profile_textarea(wp_unslash($_POST['openai_prompt'] ?? $_POST['temporary_instructions'] ?? '')),'instruction_profile_id'=>$profile_id,'instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ''),'instruction_snapshot_hash'=>sanitize_text_field($instruction_snapshot !== '' ? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash($instruction_snapshot) : ''),'instruction_snapshot'=>$instruction_snapshot,'search_scope'=>'affiliate_links_only');
            $search = ALMA_AI_Content_Agent_Knowledge_Search::search($payload);
            $stats = ALMA_AI_Content_Agent_Selection_Session::add_search_results($payload, $search);
            $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
            if ($active_idea_id < 1) {
                $idea_title = sanitize_text_field($payload['content_search_query'] ?: 'Nuova idea');
                $active_idea_id = ALMA_AI_Content_Agent_Ideas::create($idea_title, $profile_id);
                if ($active_idea_id > 0) { update_user_meta(get_current_user_id(), '_alma_active_idea_id', $active_idea_id); }
            }
            if ($active_idea_id > 0) {
                ALMA_AI_Content_Agent_Ideas::save_from_request($active_idea_id, array('idea_status'=>'bozza','instruction_profile_id'=>$profile_id,'openai_prompt'=>$payload['openai_prompt']));
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
        } elseif ($do === 'download_ai_payload_json' || $do === 'download_ai_debug_payload_json') {
            $idea_id = absint($_POST['idea_id'] ?? 0);
            $payload_mode = $do === 'download_ai_debug_payload_json' ? 'debug' : 'openai';
            if ($idea_id < 1) {
                $result = array('success'=>false,'message'=>'Idea non valida per il download JSON.');
            } else {
                $idea = ALMA_AI_Content_Agent_Ideas::get($idea_id);
                if (empty($idea)) {
                    $result = array('success'=>false,'message'=>'Idea non trovata: impossibile scaricare il JSON payload AI.');
                } else {
                    ALMA_AI_Content_Agent_Selection_Session::load_from_idea($idea);
                    $download = ALMA_AI_Content_Agent_Draft_Builder::download_payload_json_from_selection_session(get_current_user_id(), $idea_id, $payload_mode);
                    if (is_wp_error($download)) {
                        $result = array('success'=>false,'message'=>$download->get_error_message());
                    }
                }
            }
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
            if ($ok) {
                ALMA_AI_Content_Agent_Selection_Session::persist_to_idea($idea_id);
                $updated_idea = ALMA_AI_Content_Agent_Ideas::get($idea_id);
                if (!empty($updated_idea)) {
                    ALMA_AI_Content_Agent_Selection_Session::load_from_idea($updated_idea);
                }
            }
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
        if (in_array($do, array('new_content_idea', 'save_content_idea'), true)) {
            $redirect_url = add_query_arg('ai_ideas_page', 1, $redirect_url);
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
            $affiliate_images = (array)($summary['affiliate_images'] ?? array());
            $taxonomies = (array)($summary['taxonomies'] ?? array());
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
            echo '<li><strong>Link interni candidati:</strong> '.(int)($counts['internal_link'] ?? 0).'</li>';
            echo '<li><strong>Categorie candidate:</strong> '.(int)($counts['category_candidate'] ?? 0).'</li>';
            echo '<li><strong>Tag candidati:</strong> '.(int)($counts['tag_candidate'] ?? 0).'</li>';
            if (!empty($taxonomies)) {
                echo '<li><strong>Categorie applicate:</strong> '.esc_html(implode(', ', array_map('absint', (array)($taxonomies['category_ids'] ?? array())))).'</li>';
                echo '<li><strong>Tag applicati:</strong> '.esc_html(implode(', ', array_map('absint', (array)($taxonomies['tag_ids'] ?? array())))).'</li>';
                echo '<li><strong>Nuovi tag creati:</strong> '.esc_html(implode(', ', array_map('sanitize_text_field', (array)($taxonomies['new_tags'] ?? array())))).'</li>';
                if (!empty($taxonomies['warnings'])) { echo '<li><strong>Warning tassonomie:</strong> '.esc_html(implode(' | ', array_map('sanitize_text_field', (array)$taxonomies['warnings']))).'</li>'; }
            }
            if (!empty($affiliate_images)) {
                echo '<li><strong>Immagini affiliate candidate:</strong> '.(int)($affiliate_images['candidates'] ?? 0).'</li>';
                echo '<li><strong>Immagini affiliate usate:</strong> '.(int)($affiliate_images['used'] ?? 0).'</li>';
                echo '<li><strong>Immagini affiliate scartate/non usate:</strong> '.(int)($affiliate_images['discarded'] ?? 0).'</li>';
                if (!empty($affiliate_images['warning'])) { echo '<li><strong>Warning immagini:</strong> '.esc_html($affiliate_images['warning']).'</li>'; }
            }
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
        self::render_internal_link_index_status_box();
        self::render_media_index_status_box();
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
    private static function render_media_tab(){ global $wpdb; echo '<h2>Media Library</h2>'; if (self::is_table_missing('media_index')) { echo '<p><em>Tabella media_index mancante.</em></p>'; return; } $rows=$wpdb->get_results("SELECT id,attachment_id,file_name,alt_text,is_affiliate_media,is_editorial_candidate FROM ".ALMA_AI_Content_Agent_Store::table('media_index')." ORDER BY id DESC LIMIT 20",ARRAY_A); echo '<table class="widefat"><thead><tr><th>ID</th><th>Attachment ID</th><th>Filename</th><th>Alt text</th><th>Tipo</th><th>Preview</th></tr></thead><tbody>'; foreach($rows as $r){ $thumb=wp_get_attachment_image((int)$r['attachment_id'],array(60,60)); echo '<tr><td>'.(int)$r['id'].'</td><td>'.(int)$r['attachment_id'].'</td><td>'.esc_html($r['file_name']).'</td><td>'.esc_html($r['alt_text']).'</td><td>'.((int)$r['is_affiliate_media']?'Affiliato':((int)$r['is_editorial_candidate']?'Editoriale':'Escluso')).'</td><td>'.($thumb?$thumb:'-').'</td></tr>'; } echo '</tbody></table>'; }
    private static function render_reindex_tab(){ echo '<h2>Reindicizza</h2><div class="alma-agent-card"><p><label><input type="checkbox"> Articoli</label> <label><input type="checkbox"> Pagine</label> <label><input type="checkbox"> Affiliate Links</label> <label><input type="checkbox"> Documenti TXT</label> <label><input type="checkbox"> Fonti online AI</label> <label><input type="checkbox"> Media</label></p><p><button class="button button-primary" disabled>Reindicizza selezionati</button> Disponibile nella prossima fase.</p><ul><li>Elementi analizzati: Nessun dato</li><li>Elementi aggiornati: Nessun dato</li><li>Elementi saltati: Nessun dato</li><li>Errori: Nessun dato</li><li>Durata: Non disponibile</li><li>Link al log: vai a Stato/log</li></ul></div>'; self::action_form('reindex_knowledge','Reindicizza knowledge base'); self::render_media_index_status_box(); }
    private static function render_log_tab(){ global $wpdb; $missing=ALMA_AI_Content_Agent_Store::missing_tables(); $jobs=array(); if (!self::is_table_missing('jobs')) { $jobs=$wpdb->get_results("SELECT id,job_type,status,last_error,updated_at FROM ".ALMA_AI_Content_Agent_Store::table('jobs')." ORDER BY id DESC LIMIT 20",ARRAY_A); } $logs=ALMA_AI_Usage_Logger::get_recent_logs(20); echo '<h2>Stato/log</h2>'; self::render_affiliate_index_status_box(); self::render_media_index_status_box(); echo '<div class="alma-agent-grid"><div class="alma-agent-card"><h3>Job programmati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job in corso</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job completati</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Job falliti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Ultime chiamate AI</h3><p>'.(empty($logs)?'Nessun dato':'Disponibili sotto').'</p></div><div class="alma-agent-card"><h3>Errori recenti</h3><p>Nessun dato</p></div><div class="alma-agent-card"><h3>Bozze create</h3><p>'.count(ALMA_AI_Content_Agent_Store::get_agent_drafts(50)).'</p></div></div><p><strong>Tabelle mancanti:</strong> '.(empty($missing)?'Nessuna':esc_html(implode(', ',$missing))).'</p>'; if (self::is_table_missing('jobs')) { echo '<p><em>Tabella jobs mancante: sezione job non disponibile.</em></p>'; } echo '<h3>Ultimi job/errori</h3><table class="widefat"><thead><tr><th>ID</th><th>Tipo</th><th>Stato</th><th>Errore</th><th>Aggiornato</th></tr></thead><tbody>'; foreach($jobs as $j){ echo '<tr><td>'.(int)$j['id'].'</td><td>'.esc_html($j['job_type']).'</td><td><span class="alma-badge is-pending">'.esc_html($j['status']).'</span></td><td>'.esc_html($j['last_error']).'</td><td>'.esc_html($j['updated_at']).'</td></tr>'; } echo '</tbody></table>'; echo '<h3>Ultimi log AI</h3><table class="widefat"><thead><tr><th>Data</th><th>Task</th><th>Model</th><th>Successo</th><th>Errore</th></tr></thead><tbody>'; foreach($logs as $l){ echo '<tr><td>'.esc_html($l['created_at']).'</td><td>'.esc_html($l['task']).'</td><td>'.esc_html($l['model']).'</td><td>'.((int)$l['success']?'si':'no').'</td><td>'.esc_html($l['error_message']).'</td></tr>'; } echo '</tbody></table>'; }

    private static function render_affiliate_index_status_box() {
        if (!class_exists('ALMA_AI_Content_Agent_Affiliate_Index')) { return; }
        $stats = ALMA_AI_Content_Agent_Affiliate_Index::get_index_stats();
        $batch = (array)($stats['batch_state'] ?? array());
        $batch_status = !empty($batch['done']) ? 'completato' : (!empty($batch['updated_at']) ? 'in corso' : 'mai avviato');

        $total_published = (int)$stats['total_published'];
        $without_affiliate_url = (int)$stats['without_affiliate_url'];
        $indexed_active = (int)$stats['indexed_active'];
        $missing_index = (int)$stats['missing_index'];
        $stale_index_records = (int)($stats['stale_index_records'] ?? 0);
        $non_active_candidate_records = (int)($stats['non_active_candidate_records'] ?? 0);
        $needs_update = max(0, (int)$stats['needs_update']);
        $active_invalid_records = (int)$stats['active_invalid_records'];
        $orphan_index_records = (int)$stats['orphan_index_records'];

        $eligible_total = max(0, $total_published - $without_affiliate_url);
        $pending_total = max(0, $missing_index + $stale_index_records + $non_active_candidate_records);
        if ($needs_update !== $pending_total) { $needs_update = $pending_total; }
        $pending_total = min($eligible_total, $pending_total);
        $progress_percent = 0;
        if ($eligible_total > 0) {
            if ($pending_total < 1 && $indexed_active >= $eligible_total) {
                $progress_percent = 100;
            } else {
                $completed_estimate = max(0, min($eligible_total, $eligible_total - $pending_total));
                $progress_percent = (int) round(($completed_estimate / $eligible_total) * 100);
                $progress_percent = max(0, min(100, $progress_percent));
            }
        }

        $operational_state = 'Richiede verifica';
        if ($total_published < 1) { $operational_state = 'Nessun Link affiliato pubblicato'; }
        elseif (empty($stats['table_exists'])) { $operational_state = 'Indice non ancora creato'; }
        elseif ($eligible_total > 0 && $indexed_active < 1 && $missing_index > 0) { $operational_state = 'Pronto per primo batch'; }
        elseif ($active_invalid_records > 0 || $orphan_index_records > 0) { $operational_state = 'Richiede verifica'; }
        elseif ($missing_index > 0) { $operational_state = !empty($batch['updated_at']) && empty($batch['done']) ? 'Indicizzazione in corso' : 'Pronto per primo batch'; }
        elseif ($stale_index_records > 0 || $non_active_candidate_records > 0) { $operational_state = 'Indice da aggiornare'; }
        elseif ($eligible_total > 0) { $operational_state = 'Indice aggiornato'; }

        $next_action_text = 'Verifica i conteggi dell’indice tecnico.';
        $primary_do = '';
        $primary_label = '';
        if ($eligible_total > 0 && $missing_index > 0 && $indexed_active < 1) {
            $next_action_text = 'Avvia il primo batch di indicizzazione.';
            $primary_do = 'index_affiliate_links';
            $primary_label = 'Avvia primo batch';
        } elseif ($missing_index > 0) {
            $next_action_text = !empty($batch['updated_at']) && empty($batch['done']) ? 'Continua con il prossimo batch di indicizzazione.' : 'Completa il primo batch di indicizzazione prima della sync incrementale.';
            $primary_do = 'index_affiliate_links';
            $primary_label = !empty($batch['updated_at']) && empty($batch['done']) ? 'Continua indicizzazione' : 'Avvia primo batch';
        } elseif ($stale_index_records > 0 || $non_active_candidate_records > 0) {
            $next_action_text = $stale_index_records > 0
                ? 'Esegui sync incrementale per aggiornare record obsoleti, riattivare candidabili non attivi e recuperare eventuali mancanti residui.'
                : 'Esegui sync incrementale per riattivare candidabili non attivi e recuperare eventuali mancanti residui.';
            $primary_do = 'sync_affiliate_links_incremental';
            $primary_label = 'Esegui sync incrementale';
        } elseif ($active_invalid_records > 0 || $orphan_index_records > 0) {
            $next_action_text = 'Verifica record orfani/non validi e riesegui la sync incrementale.';
            $primary_do = 'sync_affiliate_links_incremental';
            $primary_label = 'Verifica e sync incrementale';
        } elseif ($eligible_total > 0) {
            $next_action_text = 'Nessuna azione necessaria.';
        }

        echo '<div class="alma-agent-card alma-affiliate-index-card"><h3>Indice Link affiliati</h3>';
        if (empty($stats['table_exists'])) { echo '<p class="description"><em>Tabella indice non disponibile. La ricerca userà fallback WordPress per i Link affiliati.</em></p>'; }
        echo '<p class="alma-affiliate-index-warning"><strong>Questa operazione non elimina i Link affiliati. Cancella solo l’indice tecnico rigenerabile.</strong></p>';
        echo '<div class="alma-affiliate-operational-state"><strong>Stato operativo:</strong> '.esc_html($operational_state).'</div>';
        echo '<div class="alma-affiliate-progress" role="status" aria-live="polite"><div class="alma-affiliate-progress-head"><strong>Progresso indicizzazione</strong> <span>'.(int)$progress_percent.'%</span></div><div class="alma-affiliate-progress-bar" aria-hidden="true"><span style="width: '.(int)$progress_percent.'%;"></span></div><p class="description">Candidabili: '.(int)$eligible_total.' · Da lavorare totale: '.(int)$pending_total.'</p></div>';
        echo '<div class="alma-affiliate-next-action"><strong>Prossima azione consigliata</strong><p>'.esc_html($next_action_text).'</p></div>';
        echo '<ul><li>Totale Link affiliati pubblicati: '.$total_published.'</li><li>Indicizzati attivi: '.$indexed_active.'</li><li>Mancanti dall’indice: '.$missing_index.'</li><li>Da aggiornare: '.$stale_index_records.'</li><li>Candidabili non attivi: '.$non_active_candidate_records.'</li><li>Da lavorare totale: '.$pending_total.'</li><li>Link affiliati senza URL affiliato: '.$without_affiliate_url.'</li><li>Record indice inattivi: '.(int)$stats['inactive_index_records'].'</li><li>Record indice orfani: '.$orphan_index_records.'</li><li>Record attivi non validi: '.$active_invalid_records.'</li><li>Ultimo aggiornamento indice: '.esc_html($stats['last_indexed_at'] ?: 'N/D').'</li><li>Stato batch: '.esc_html($batch_status).'</li><li>Last processed ID: '.(int)($batch['last_processed_id'] ?? 0).'</li>'.(!empty($batch['last_error'])?'<li>Ultimo errore: '.esc_html($batch['last_error']).'</li>':'').'</ul>';

        echo '<div class="alma-affiliate-actions-group"><h4>Azioni principali</h4><div class="alma-actions-inline alma-affiliate-primary-actions">';
        if ($primary_do !== '' && $primary_label !== '') {
            self::action_form($primary_do, $primary_label);
            echo '<p class="description alma-affiliate-action-description">Azione consigliata in base allo stato attuale dell’indice tecnico.</p>';
        } else {
            echo '<button class="button" type="button" disabled>Indice aggiornato</button><p class="description alma-affiliate-action-description">Nessuna azione operativa necessaria in questo momento.</p>';
        }
        self::action_form('index_affiliate_links','Indicizza prossimo batch','<p class="description alma-affiliate-action-description">Indicizza un blocco di Link affiliati alla volta. Usalo per costruire progressivamente l’indice senza sovraccaricare il sito.</p>');
        self::action_form('sync_affiliate_links_incremental','Sync incrementale','<p class="description alma-affiliate-action-description">Aggiorna solo i Link affiliati mancanti, modificati, obsoleti o non attivi nell’indice.</p>');
        echo '</div></div>';

        echo '<div class="alma-affiliate-advanced-maintenance"><h4>Manutenzione avanzata</h4><p class="description">Usa queste azioni solo per manutenzione tecnica dell’indice.</p><div class="alma-actions-inline">';
        self::action_form('reset_affiliate_index_state','Reset stato batch','<p class="description alma-affiliate-action-description">Azzera solo il punto di avanzamento del batch. Non elimina l’indice e non elimina i Link affiliati.</p>');
        self::action_form('clear_affiliate_index','Svuota indice e ricomincia','<p class="description alma-affiliate-action-description">Cancella solo l’indice tecnico rigenerabile. Non elimina i Link affiliati reali.</p>');
        echo '</div></div></div>';
    }


    private static function render_media_index_status_box() {
        $stats = ALMA_AI_Content_Agent_Media_Index::get_status();
        echo '<div class="alma-agent-card alma-media-index-card"><h3>Indice Media</h3>';
        echo '<ul><li>Totale immagini indicizzate: '.(int)$stats['total'].'</li><li>Immagini editoriali candidate: '.(int)$stats['editorial'].'</li><li>Immagini affiliate riconosciute: '.(int)$stats['affiliate'].'</li><li>Ultima ricostruzione: '.esc_html($stats['last_rebuild_at'] ?: 'N/D').'</li></ul>';
        self::action_form('reindex_media', 'Ricostruisci indice media', '<p class="description">Ricostruisce in modo sincrono e paginato l’indice leggero degli attachment immagine. Non legge file binari, non scarica immagini e non invia immagini a OpenAI.</p>');
        echo '</div>';
    }

    private static function render_internal_link_index_status_box() {
        $stats = ALMA_AI_Content_Agent_Internal_Link_Index::get_stats();
        $state = empty($stats['table_exists']) ? 'Tabella non disponibile' : ((int)$stats['indexed_count'] > 0 ? 'Indice disponibile' : 'Indice vuoto');
        echo '<div class="alma-agent-card alma-internal-link-index-card"><h3>Link interni</h3>';
        echo '<ul><li>Stato indice: '.esc_html($state).'</li><li>Post indicizzati: '.(int)($stats['indexed_count'] ?? 0).'</li><li>Ultima ricostruzione: '.esc_html($stats['last_rebuild_at'] ?: 'N/D').'</li><li>Ultimo aggiornamento record: '.esc_html($stats['last_indexed_at'] ?: 'N/D').'</li></ul>';
        self::action_form('rebuild_internal_link_index', 'Ricostruisci indice link interni', '<p class="description">Ricostruisce sincronicamente l’indice leggero dei post pubblicati: titolo, permalink, excerpt, categorie e tag. Non indicizza il contenuto completo.</p>');
        echo '</div>';
    }

    private static function render_instructions_tab() {
        $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(50,0);
        $active = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile();
        $edit_id = absint($_GET['profile_id'] ?? ($active['id'] ?? 0));
        $current = $edit_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($edit_id) : array();
        $is_current_active = $edit_id > 0 && !empty($current['is_active']);

        echo '<h2>Istruzioni AI</h2><h3>Profili</h3><table class="widefat"><thead><tr><th>ID</th><th>Nome</th><th>Stato</th><th>Default</th><th>Azioni</th></tr></thead><tbody>';
        foreach($profiles as $p){
            $is_active = (int)($p['is_active'] ?? 0) === 1;
            $toggle_do = $is_active ? 'deactivate_instruction_profile' : 'activate_instruction_profile';
            $toggle_label = $is_active ? 'Disattiva' : 'Attiva';
            $status_badge = $is_active ? 'Attivo' : 'Non attivo';
            echo '<tr><td>'.(int)$p['id'].'</td><td>'.esc_html($p['profile_name']).'</td><td><span class="alma-badge '.($is_active?'is-active':'is-inactive').'">'.esc_html($status_badge).'</span></td><td>'.((int)$p['is_default']?'Sì':'No').'</td><td><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id='.(int)$p['id'])).'">Modifica</a> '.self::inline_profile_action_form($toggle_do,$toggle_label,(int)$p['id']).'</td></tr>';
        }
        echo '</tbody></table><p><a class="button" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=istruzioni-ai&profile_id=0')).'">Crea nuovo profilo</a></p>';

        echo '<h3>'.($edit_id>0?'Modifica profilo':'Nuovo profilo').'</h3><form class="alma-instructions-profile-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_instruction_profile"><input type="hidden" name="profile_id" value="'.(int)$edit_id.'">';

        echo '<div class="alma-instructions-profile-top">';
        echo '<p class="alma-instructions-field"><label><strong>Nome profilo</strong><br><input class="regular-text" name="profile_name" value="'.esc_attr($current['profile_name']??'').'"></label></p>';
        echo '<p class="alma-instructions-field"><label><strong>Lingua</strong><br><input class="regular-text" name="language_code" value="'.esc_attr($current['language_code']??'it').'"></label></p>';
        echo '</div>';

        $main_fields = array('tone_of_voice'=>'Tono di voce','target_audience'=>'Pubblico target','editorial_style'=>'Stile editoriale','seo_rules'=>'Regole SEO','affiliate_rules'=>'Regole affiliate','image_rules'=>'Regole immagini','source_rules'=>'Regole fonti','anti_duplication_rules'=>'Regole anti-duplicazione','avoid_rules'=>'Cose da evitare','disclosure_policy'=>'Disclosure policy');
        echo '<div class="alma-instructions-profile-grid">';
        foreach($main_fields as $k=>$l){
            echo '<p class="alma-instructions-field"><label><strong>'.esc_html($l).'</strong><br><textarea class="large-text" rows="5" name="'.esc_attr($k).'">'.esc_textarea($current[$k]??'').'</textarea></label></p>';
        }
        echo '</div>';

        echo '<div class="alma-instructions-profile-wide alma-instructions-custom-prompt">';
        echo '<p class="alma-instructions-field"><label><strong>Prompt libero personalizzato</strong><br><textarea class="large-text" rows="12" name="custom_prompt">'.esc_textarea($current['custom_prompt']??'').'</textarea></label></p>';
        echo '<p class="description">Campo principale per istruzioni editoriali aggiuntive specifiche del profilo.</p>';
        echo '</div>';

        echo '<div class="alma-instructions-profile-wide alma-instructions-internal-notes">';
        echo '<p class="alma-instructions-field"><label><strong>Note interne</strong><br><textarea class="large-text" rows="4" name="internal_notes">'.esc_textarea($current['internal_notes']??'').'</textarea></label></p>';
        echo '<p class="description">Note amministrative interne: non vengono esposte nel payload OpenAI normalizzato.</p>';
        echo '</div>';

        echo '<p class="alma-instructions-activate"><label><input type="checkbox" name="activate_profile" value="1" '.checked($is_current_active, true, false).'> Attiva questo profilo dopo il salvataggio</label></p><p><button class="button button-primary">Salva profilo</button></p></form>';
    }

    private static function render_ideas_tab() {
        $active_idea_id = absint(get_user_meta(get_current_user_id(), '_alma_active_idea_id', true));
        $ideas_per_page = 10;
        $ideas_page_requested = isset($_GET['ai_ideas_page']);
        $current_ideas_page = max(1, absint($_GET['ai_ideas_page'] ?? 1));
        $all_idea_ids = get_posts(array(
            'post_type' => ALMA_AI_Content_Agent_Ideas::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
            'author' => get_current_user_id(),
            'no_found_rows' => true,
        ));
        if (!is_array($all_idea_ids)) { $all_idea_ids = array(); }
        $total_ideas = count($all_idea_ids);
        $total_ideas_pages = max(1, (int)ceil($total_ideas / $ideas_per_page));
        if ($current_ideas_page > $total_ideas_pages) { $current_ideas_page = $total_ideas_pages; }
        if (!$ideas_page_requested && $active_idea_id > 0 && in_array($active_idea_id, array_map('intval', $all_idea_ids), true)) {
            $active_idea_index = array_search($active_idea_id, array_map('intval', $all_idea_ids), true);
            if ($active_idea_index !== false) {
                $current_ideas_page = (int)floor($active_idea_index / $ideas_per_page) + 1;
            }
        }
        $ideas = get_posts(array(
            'post_type' => ALMA_AI_Content_Agent_Ideas::CPT,
            'post_status' => 'publish',
            'posts_per_page' => $ideas_per_page,
            'paged' => $current_ideas_page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'author' => get_current_user_id(),
        ));
        if ($active_idea_id > 0) {
            $active_post = get_post($active_idea_id);
            if (!$active_post || $active_post->post_type !== ALMA_AI_Content_Agent_Ideas::CPT) {
                delete_user_meta(get_current_user_id(), '_alma_active_idea_id');
                $active_idea_id = 0;
            }
        }
        if ($active_idea_id < 1 && !empty($all_idea_ids)) {
            $active_idea_id = (int)$all_idea_ids[0];
            update_user_meta(get_current_user_id(), '_alma_active_idea_id', $active_idea_id);
            $current_ideas_page = 1;
            $ideas = get_posts(array(
                'post_type' => ALMA_AI_Content_Agent_Ideas::CPT,
                'post_status' => 'publish',
                'posts_per_page' => $ideas_per_page,
                'paged' => $current_ideas_page,
                'orderby' => 'modified',
                'order' => 'DESC',
                'author' => get_current_user_id(),
            ));
        }
        $active_idea = $active_idea_id ? ALMA_AI_Content_Agent_Ideas::get($active_idea_id) : array();
        if (!empty($active_idea['ID'])) { ALMA_AI_Content_Agent_Selection_Session::load_from_idea($active_idea); }
        else { ALMA_AI_Content_Agent_Selection_Session::clear(); }
        $all_profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(100,0);
        $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profiles(100,0);
        if (!is_array($all_profiles)) { $all_profiles = array(); }
        if (!is_array($profiles)) { $profiles = array(); }
        $session = ALMA_AI_Content_Agent_Selection_Session::get_session();
        $idea_profile_id = absint($active_idea['instruction_profile_id'] ?? ($active_idea['profile_id'] ?? 0));
        $idea_profile_valid = false;
        foreach ($all_profiles as $profile_row) {
            if ((int)($profile_row['id'] ?? 0) === $idea_profile_id) {
                $idea_profile_valid = true;
                break;
            }
        }
        $idea_has_profile_meta = !empty($active_idea['has_instruction_profile_meta']);
        if ($idea_profile_valid || ($idea_has_profile_meta && $idea_profile_id === 0)) {
            $selected_profile_id = $idea_profile_id;
        } else {
            $selected_profile_id = 0;
        }
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
        echo '<div class="alma-actions-inline alma-idea-main-actions">';
        self::action_form('new_content_idea','Crea nuova idea','', 'button alma-action-button alma-action-primary', 'dashicons-lightbulb');
        if($active_idea_id){ echo '<form id="alma-save-idea-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_content_idea"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><input type="hidden" name="idea_status" value="bozza"><input type="hidden" id="alma-save-idea-openai-prompt" name="openai_prompt" value="'.esc_attr($active_idea['prompt']??'').'"><input type="hidden" id="alma-save-idea-instruction-profile-id" name="instruction_profile_id" value="'.(int)$selected_profile_id.'"><button class="button alma-action-button alma-action-success"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span>Salva idea</span></button></form>'; }
        if($active_idea_id){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_content_idea"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><button class="button alma-action-button alma-action-danger"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span>Elimina</span></button></form>'; }
        self::action_form('create_draft_from_selection','Crea bozza','', 'button alma-action-button alma-action-primary', 'dashicons-edit-page');
        self::action_form('download_ai_payload_json','Scarica JSON payload OpenAI',$active_idea_id ? '<input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'">' : '', 'button alma-action-button alma-action-secondary', 'dashicons-media-code');
        echo '</div>';
        if (current_user_can('manage_options')) {
            echo '<details class="alma-idea-advanced-tools"><summary>Strumenti avanzati</summary><p class="description">Download tecnico per diagnostica: utile per confrontare il payload completo di debug con il payload OpenAI normalizzato. Non usare come payload inviato a OpenAI.</p><div class="alma-actions-inline">';
            self::action_form('download_ai_debug_payload_json','Scarica JSON debug completo (solo diagnostica, non inviato a OpenAI)',$active_idea_id ? '<input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'">' : '', 'button alma-action-button alma-action-secondary', 'dashicons-media-code');
            echo '</div></details>';
        }
        echo '</div>';

        echo '<div class="alma-ideas-layout">';
        echo '<aside class="alma-ideas-col alma-ideas-col-left"><div class="alma-ideas-card alma-active-idea-box">';
        echo '<section class="alma-idea-section"><h3 class="alma-idea-section-title">Idea attiva</h3><p class="alma-idea-title-lg">'.esc_html($active_idea['title'] ?? 'Nessuna idea attiva').'</p><div class="alma-idea-meta"><p><strong>Stato:</strong> '.esc_html(!empty($active_idea['executed_at']) ? 'Eseguita' : 'Non eseguita').'</p><p><strong>Ultima modifica:</strong> '.esc_html(!empty($active_idea['modified']) ? $active_idea['modified'] : 'N/D').'</p></div>';
        if ($active_idea_id < 1) { echo '<p class="description">Nessuna idea attiva. Usa il pulsante Crea nuova idea per iniziare.</p>'; }
        if (!empty($active_idea['draft_post_id']) && get_post((int)$active_idea['draft_post_id'])) { echo '<p><a href="'.esc_url(get_edit_post_link((int)$active_idea['draft_post_id'],'raw')).'">Apri bozza</a></p>'; }
        echo '</section>';

        echo '<section class="alma-idea-section"><h4 class="alma-idea-section-title">Idee create</h4>';
        if (empty($ideas)) { echo '<p class="description">Nessuna idea creata.</p>'; }
        else {
            echo '<div class="alma-ideas-list">';
            foreach($ideas as $idea_post){
                $is_active=((int)$idea_post->ID===(int)$active_idea_id);
                $record_class = 'alma-idea-record'.($is_active ? ' is-active' : '');
                echo '<article class="'.esc_attr($record_class).'"'.($is_active ? ' aria-current="true"' : '').'><p class="alma-idea-record-title"><strong>'.esc_html($idea_post->post_title).'</strong>'.($is_active?' <span class="alma-active-idea-badge">Idea attiva</span>':'').'</p><p class="description alma-idea-record-meta">Ultima modifica: '.esc_html($idea_post->post_modified).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="load_content_idea"><input type="hidden" name="idea_id" value="'.(int)$idea_post->ID.'"><button class="button button-small">Carica</button></form></article>';
            }
            echo '</div>';
            if ($total_ideas > $ideas_per_page) {
                $pagination_args = array();
                foreach (wp_unslash($_GET) as $query_key => $query_value) {
                    if (is_array($query_value)) { continue; }
                    $pagination_args[sanitize_key($query_key)] = sanitize_text_field($query_value);
                }
                unset($pagination_args['ai_ideas_page']);
                $prev_url = add_query_arg(array_merge($pagination_args, array('ai_ideas_page' => max(1, $current_ideas_page - 1))), admin_url('edit.php'));
                $next_url = add_query_arg(array_merge($pagination_args, array('ai_ideas_page' => min($total_ideas_pages, $current_ideas_page + 1))), admin_url('edit.php'));
                echo '<nav class="alma-ideas-pagination" aria-label="Paginazione idee create">';
                if ($current_ideas_page > 1) { echo '<a class="button button-small" href="'.esc_url($prev_url).'">Precedente</a>'; } else { echo '<span class="button button-small disabled" aria-disabled="true">Precedente</span>'; }
                echo '<span class="alma-ideas-page-indicator">Pagina '.esc_html((string)$current_ideas_page).' di '.esc_html((string)$total_ideas_pages).'</span>';
                if ($current_ideas_page < $total_ideas_pages) { echo '<a class="button button-small" href="'.esc_url($next_url).'">Successiva</a>'; } else { echo '<span class="button button-small disabled" aria-disabled="true">Successiva</span>'; }
                echo '</nav>';
            }
        }
        echo '</section>';

        echo '<section class="alma-idea-section alma-idea-details"><h4 class="alma-idea-section-title">Dettagli idea</h4><ul><li><strong>Contenuti aggiunti:</strong> '.(int)($summary['selected_total'] ?? 0).'</li><li><strong>Profilo istruzioni AI:</strong> '.(int)($active_idea['profile_id'] ?? 0).'</li><li><strong>Prompt OpenAI:</strong> '.(!empty($active_idea['prompt']) ? 'Presente' : 'Assente').'</li></ul></section></div></aside>';

        echo '<main class="alma-ideas-col alma-ideas-col-main"><div class="alma-ideas-card"><h3>1. Cerca contenuti</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="search_knowledge_base"><input type="hidden" name="idea_id" value="'.(int)$active_idea_id.'"><p><input class="widefat" type="text" name="content_search_query" placeholder="Cerca contenuti" value="'.esc_attr($session['last_query']['content_search_query'] ?? '').'" required></p><p><label for="alma-idea-instruction-profile">Profilo istruzioni AI</label><select id="alma-idea-instruction-profile" class="widefat" name="instruction_profile_id"><option value="0">Nessun profilo</option>';
        $rendered_profile_ids = array();
        foreach($profiles as $p){ $rendered_profile_ids[(int)$p['id']] = true; $sel=selected($selected_profile_id,(int)$p['id'],false); echo '<option value="'.(int)$p['id'].'" '.$sel.'>'.esc_html($p['profile_name']).'</option>'; }
        if ($selected_profile_id > 0 && empty($rendered_profile_ids[$selected_profile_id])) {
            $selected_inactive_profile = ALMA_AI_Content_Agent_Instructions_Manager::get_profile($selected_profile_id);
            if (!empty($selected_inactive_profile['id'])) { echo '<option value="'.(int)$selected_inactive_profile['id'].'" selected>'.esc_html($selected_inactive_profile['profile_name']).' (non attivo, già scelto)</option>'; }
        }
        echo '</select></p>';
        if (empty($profiles)) { echo '<p class="description"><strong>Nessun profilo istruzioni AI attivo.</strong> Attiva almeno un profilo nella tab Istruzioni AI oppure scegli esplicitamente Nessun profilo.</p>'; }
        echo '<p><label for="alma-openai-prompt">Prompt per OpenAI</label><textarea id="alma-openai-prompt" class="widefat" rows="4" name="openai_prompt">'.esc_textarea($active_idea['prompt'] ?? ($session['openai_prompt'] ?? '')).'</textarea><span class="description">Questo prompt verrà inviato a OpenAI insieme ai contenuti raccolti e guiderà cosa scrivere nella bozza.</span></p><p><button class="button button-primary">Cerca contenuti</button></p></form></div>';


        $all_rows = array();
        foreach($labels as $k=>$label){ foreach((array)($results_groups[$k]??array()) as $r){ $all_rows[] = array('group'=>$k,'label'=>$label,'row'=>$r); } }

        $link_type_options = array();
        $source_options = array();
        foreach ($all_rows as $item) {
            $row = (array)($item['row'] ?? array());
            $types_raw = $row['link_types'] ?? array();
            $types = is_array($types_raw) ? $types_raw : array_filter(array_map('trim', explode(',', (string)$types_raw)));
            foreach ($types as $type_name) {
                $safe_type = sanitize_text_field((string)$type_name);
                if ($safe_type !== '') { $link_type_options[$safe_type] = $safe_type; }
            }
            $source_value = sanitize_text_field((string)($row['provenance'] ?? ($row['provider'] ?? ($row['source'] ?? ''))));
            if ($source_value !== '') { $source_options[$source_value] = $source_value; }
        }
        natcasesort($link_type_options);
        natcasesort($source_options);

        $active_link_type_filter = sanitize_text_field((string)($_GET['alma_link_type_filter'] ?? 'all'));
        $active_source_filter = sanitize_text_field((string)($_GET['alma_source_filter'] ?? 'all'));
        if ($active_link_type_filter !== 'all' && !isset($link_type_options[$active_link_type_filter])) { $active_link_type_filter = 'all'; }
        if ($active_source_filter !== 'all' && !isset($source_options[$active_source_filter])) { $active_source_filter = 'all'; }

        $filtered_rows = array();
        foreach ($all_rows as $item) {
            $row = (array)($item['row'] ?? array());
            $types_raw = $row['link_types'] ?? array();
            $types = is_array($types_raw) ? $types_raw : array_filter(array_map('trim', explode(',', (string)$types_raw)));
            $types = array_values(array_filter(array_map('sanitize_text_field', $types)));
            $row_source = sanitize_text_field((string)($row['provenance'] ?? ($row['provider'] ?? ($row['source'] ?? ''))));

            $matches_link_type = ($active_link_type_filter === 'all') ? true : in_array($active_link_type_filter, $types, true);
            $matches_source = ($active_source_filter === 'all') ? true : ($row_source !== '' && $row_source === $active_source_filter);
            if ($matches_link_type && $matches_source) { $filtered_rows[] = $item; }
        }

        $per_page = 10;
        $total_results = count($filtered_rows);
        $total_pages = max(1, (int)ceil($total_results / $per_page));
        $requested_page = absint($_GET['alma_results_page'] ?? 1);
        $current_page = max(1, min($requested_page, $total_pages));
        $paged_rows = array_slice($filtered_rows, ($current_page-1)*$per_page, $per_page);

        echo '<div class="alma-ideas-card"><div class="alma-results-header"><h3>2. Risultati ricerca</h3></div><p class="description">In questa fase la ricerca mostra solo Link affiliati indicizzati.</p>';
        echo '<form class="alma-results-filters" method="get" action="'.esc_url(admin_url('edit.php')).'">';
        echo '<input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="alma-ai-content-agent"><input type="hidden" name="tab" value="idee"><input type="hidden" name="alma_results_page" value="1">';
        echo '<div class="alma-results-filter-grid"><p><label for="alma-link-type-filter"><strong>Tipologie Link</strong></label><select id="alma-link-type-filter" name="alma_link_type_filter" class="widefat"><option value="all">Tutte le tipologie</option>';
        foreach ($link_type_options as $option_value => $option_label) { echo '<option value="'.esc_attr($option_value).'" '.selected($active_link_type_filter, $option_value, false).'>'.esc_html($option_label).'</option>'; }
        echo '</select></p><p><label for="alma-source-filter"><strong>Fonte / Source / Provider</strong></label><select id="alma-source-filter" name="alma_source_filter" class="widefat"><option value="all">Tutte le fonti</option>';
        foreach ($source_options as $option_value => $option_label) { echo '<option value="'.esc_attr($option_value).'" '.selected($active_source_filter, $option_value, false).'>'.esc_html($option_label).'</option>'; }
        echo '</select></p></div>';
        $reset_url = add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-ai-content-agent','tab'=>'idee','alma_results_page'=>1), admin_url('edit.php'));
        echo '<p class="alma-results-filter-actions"><button class="button button-primary" type="submit">Applica filtri</button> <a class="button" href="'.esc_url($reset_url).'">Reset filtri</a></p></form>';

        echo '<div class="tablenav top"><div class="tablenav-pages">';
        echo '<span class="displaying-num">'.(int)$total_results.' elementi</span>';
        echo '<span class="pagination-links">';
        $base = add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-ai-content-agent','tab'=>'idee','alma_link_type_filter'=>$active_link_type_filter,'alma_source_filter'=>$active_source_filter), admin_url('edit.php'));
        $is_first = ($current_page <= 1);
        $is_last = ($current_page >= $total_pages);
        if ($is_first) { echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span><span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>'; }
        else {
            echo '<a class="first-page button" href="'.esc_url(add_query_arg(array('alma_results_page'=>1), $base)).'"><span class="screen-reader-text">Prima pagina</span><span aria-hidden="true">&laquo;</span></a>';
            echo '<a class="prev-page button" href="'.esc_url(add_query_arg(array('alma_results_page'=>$current_page-1), $base)).'"><span class="screen-reader-text">Pagina precedente</span><span aria-hidden="true">&lsaquo;</span></a>';
        }
        echo '<span class="paging-input"><span class="tablenav-paging-text">'.(int)$current_page.' di <span class="total-pages">'.(int)$total_pages.'</span></span></span>';
        if ($is_last) { echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span><span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>'; }
        else {
            echo '<a class="next-page button" href="'.esc_url(add_query_arg(array('alma_results_page'=>$current_page+1), $base)).'"><span class="screen-reader-text">Pagina successiva</span><span aria-hidden="true">&rsaquo;</span></a>';
            echo '<a class="last-page button" href="'.esc_url(add_query_arg(array('alma_results_page'=>$total_pages), $base)).'"><span class="screen-reader-text">Ultima pagina</span><span aria-hidden="true">&raquo;</span></a>';
        }
        echo '</span></div></div>';

        echo '<form id="alma-bulk-add-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="add_selected_to_idea"></form>';
        if (empty($paged_rows)) {
            if (!empty($all_rows) && $total_results === 0) { echo '<p class="description">Nessun Link affiliato corrisponde ai filtri selezionati.</p>'; } else { echo '<p class="description">Nessun risultato disponibile. Esegui una ricerca per popolare questa sezione.</p>'; }
        }
        foreach($paged_rows as $item){
            $r=(array)$item['row'];
            $rk=sanitize_text_field($r['result_key'] ?? '');
            if($rk===''){continue;}
            $in=!empty($selected_map[$rk]);
            $usage=(int)($usage_counts[$rk] ?? 0);
            $thumbnail_html = self::render_affiliate_result_thumbnail($r);
            echo '<div class="alma-result-item alma-result-item-with-thumbnail'.($in?' is-already-added':'').'"><div class="alma-result-body">';
            echo '<div class="alma-result-copy"><p><strong>'.esc_html($r['title']).'</strong> <span class="alma-count-badge">'.esc_html($item['label']).'</span>';
            if($in){echo ' <span class="alma-added-badge">Già nell’idea</span>';}
            echo ' <span class="alma-usage-badge">Utilizzato in bozze: '.$usage.'</span></p><label><input type="checkbox" name="selected_result_keys[]" value="'.esc_attr($rk).'" form="alma-bulk-add-form" '.disabled($in,true,false).'> Seleziona</label><p class="alma-excerpt">'.esc_html(wp_trim_words((string)($r['excerpt'] ?? ''),20,'…')).'</p><p class="description">Score: <span class="alma-score-value">'.(int)($r['score'] ?? 0).'</span> · '.esc_html($r['reason'] ?? '').'</p>';
            if($in){ echo '<button class="button button-small" type="button" disabled>Già aggiunto</button>'; }
            else { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="add_result_to_idea"><input type="hidden" name="result_key" value="'.esc_attr($rk).'"><button class="button button-small">Aggiungi all’idea</button></form>'; }
            echo '</div>'.$thumbnail_html.'</div></div>';
        }
        echo '<p><button class="button button-primary" type="submit" form="alma-bulk-add-form">Aggiungi selezionati all’idea</button></p></div></main>';

echo '<aside class="alma-ideas-col alma-ideas-col-right"><div class="alma-ideas-card"><h3>3. Sessione contenuto</h3><p><strong>Totale elementi aggiunti:</strong> '.(int)($summary['selected_total'] ?? 0).'</p>';
        if ((int)($summary['selected_total'] ?? 0) < 1) { echo '<p>Nessun contenuto aggiunto all’idea.</p>'; }
        foreach($labels as $k=>$label){ $rows=(array)($selected_groups[$k]??array()); if (empty($rows)) { continue; } echo '<section class="alma-results-group"><h4>'.esc_html($label).' <span class="alma-count-badge">'.count($rows).'</span></h4>'; foreach($rows as $r){ $rk=sanitize_text_field($r['result_key'] ?? ''); $usage=(int)($usage_counts[$rk] ?? 0); echo '<div class="alma-result-item"><strong>'.esc_html($r['title'] ?? '').'</strong><p class="description">Score: <span class="alma-score-value">'.(int)($r['score'] ?? 0).'</span> · '.esc_html($r['reason'] ?? '').'</p><span class="alma-usage-badge">Utilizzato in bozze: '.$usage.'</span><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="remove_selected_item"><input type="hidden" name="result_key" value="'.esc_attr($rk).'"><button class="button button-small">Rimuovi</button></form></div>'; }
        echo '</section>'; }
        echo '</div></aside></div>';
    }


    private static function is_table_missing($table_key) { global $wpdb; $map=array('knowledge_items','sources','jobs','media_index','instruction_profiles','content_chunks','content_ideas','editorial_briefs','affiliate_index','internal_link_index'); if($table_key==='usage'||$table_key==='alma_ai_usage'){ $table_name=ALMA_AI_Usage_Logger::table_name(); } elseif(!in_array($table_key,$map,true)){ return true; } else { $table_name=ALMA_AI_Content_Agent_Store::table($table_key);} $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table_name)); return $exists!==$table_name; }
    private static function inline_document_actions($document_id, $is_active) { $document_id=absint($document_id); if($document_id<1||!current_user_can('manage_options')){return '';} $toggle=$is_active?'inactive':'active'; return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_txt_document"><input type="hidden" name="document_id" value="'.$document_id.'"><input type="hidden" name="status" value="'.esc_attr($toggle).'"><button class="button button-small">'.($is_active?'Disabilita':'Abilita').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_txt_document"><input type="hidden" name="document_id" value="'.$document_id.'"><button class="button button-small">Elimina</button></form>'; }
    private static function inline_source_actions($source_id, $is_active) { $source_id=absint($source_id); if($source_id<1||!current_user_can('manage_options')){return '';} $next=(int)$is_active?0:1; return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="toggle_source"><input type="hidden" name="source_id" value="'.$source_id.'"><input type="hidden" name="is_active" value="'.$next.'"><button class="button button-small">'.($is_active?'Disabilita':'Abilita').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('alma_ai_agent_action','_wpnonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="delete_source"><input type="hidden" name="source_id" value="'.$source_id.'"><button class="button button-small">Elimina</button></form>'; }
    private static function inline_profile_action_form($do, $label, $profile_id) { $profile_id=absint($profile_id); if($profile_id<1||!current_user_can('manage_options')){return '';} return '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-left:4px;">'.wp_nonce_field('alma_ai_instruction_profile_toggle_'.$profile_id,'_alma_profile_nonce',true,false).'<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'"><input type="hidden" name="profile_id" value="'.$profile_id.'"><button class="button button-small">'.esc_html($label).'</button></form>'; }


    private static function render_affiliate_result_thumbnail($row) {
        if (!is_array($row) || sanitize_key((string)($row['source_group'] ?? '')) !== 'affiliate_link') { return ''; }
        $image = is_array($row['image'] ?? null) ? $row['image'] : array();
        $url = esc_url_raw((string)($image['image_url'] ?? ($row['featured_image_url'] ?? ($row['image_url'] ?? ''))));
        $attachment_id = absint($row['featured_image_id'] ?? 0);
        if ($attachment_id > 0) {
            $attachment_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            if (!$attachment_url) { $attachment_url = wp_get_attachment_image_url($attachment_id, 'large'); }
            if ($attachment_url) { $url = esc_url_raw((string)$attachment_url); }
        }
        if ($url === '' || !wp_http_validate_url($url)) { return ''; }
        $alt = sanitize_text_field((string)($image['image_alt'] ?? ($row['featured_image_alt'] ?? ($row['title'] ?? ''))));
        if ($alt === '') { $alt = sanitize_text_field((string)($row['title'] ?? '')); }
        return '<div class="alma-result-thumbnail" aria-label="'.esc_attr__('Immagine link affiliato', 'affiliate-link-manager-ai').'"><img class="alma-result-thumbnail-img" src="'.esc_url($url).'" alt="'.esc_attr($alt).'" loading="lazy" decoding="async"></div>';
    }

    private static function action_form($do,$label,$extra='',$button_class='button',$icon_class=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); $button_content = $icon_class !== '' ? '<span class="dashicons '.esc_attr($icon_class).'" aria-hidden="true"></span><span>'.esc_html($label).'</span>' : esc_html($label); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="'.esc_attr($button_class).'">'.$button_content.'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
