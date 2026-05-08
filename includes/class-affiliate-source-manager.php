<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Manager {
    private $registry; private $table_error = '';
    private function make_criteria_token($source_id){ return wp_generate_password(24,false,false).dechex((int)$source_id); }
    private function criteria_transient_key($user_id,$source_id,$token){ return 'alma_import_preview_'.absint($user_id).'_'.absint($source_id).'_'.sanitize_key($token); }
    public function __construct() {
        $this->registry = new ALMA_Affiliate_Source_Provider_Registry();
        $this->registry->bootstrap_native_providers();
        add_action('admin_menu', array($this, 'register_submenu'), 11);
        add_action('add_meta_boxes', array($this, 'register_technical_metabox'));
        add_action('save_post_affiliate_link', array($this, 'save_technical_meta'));
        add_action('wp_ajax_alma_test_source_connection', array($this, 'ajax_test_source_connection'));
        add_action('wp_ajax_alma_gyg_csv_prepare_import', array($this, 'ajax_gyg_csv_prepare_import'));
        add_action('wp_ajax_alma_gyg_csv_import_batch', array($this, 'ajax_gyg_csv_import_batch'));
        add_action('admin_post_alma_retry_affiliate_image', array($this, 'handle_single_image_retry'));
        add_action('admin_notices', array($this, 'render_image_retry_notice'));
    }
    public static function create_tables() { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_affiliate_sources (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL, provider varchar(50) NOT NULL, provider_preset varchar(50) DEFAULT '', provider_label varchar(191) DEFAULT '', is_active tinyint(1) NOT NULL DEFAULT 1, language varchar(20) DEFAULT '', market varchar(20) DEFAULT '', destination_term_id bigint(20) unsigned DEFAULT 0, destination_term_ids longtext NULL, import_mode varchar(30) DEFAULT 'create_update', settings longtext NULL, credentials longtext NULL, last_sync_at datetime NULL, last_sync_status varchar(20) DEFAULT 'manual', created_at datetime NOT NULL, updated_at datetime NOT NULL, deleted_at datetime NULL, deleted_by bigint(20) unsigned DEFAULT 0, PRIMARY KEY  (id)) $c;");
    }
    public function get_provider_presets(){ return ALMA_Affiliate_Source_Provider_Presets::get_schema(); }
    public function register_submenu(){ $page_hook=add_submenu_page('edit.php?post_type=affiliate_link',__('Affiliate Sources','affiliate-link-manager-ai'),__('Affiliate Sources','affiliate-link-manager-ai'),'manage_options','alma-affiliate-sources',array($this,'render_sources_page')); if($page_hook){ add_action('load-'.$page_hook,array($this,'handle_sources_page_load')); } add_submenu_page(null,__('Campi importabili','affiliate-link-manager-ai'),__('Campi importabili','affiliate-link-manager-ai'),'manage_options','alma-importable-fields',array($this,'render_importable_fields_page')); }
    private function parse_json($raw){ $raw=trim((string)$raw); if($raw==='') return array(); $d=json_decode(wp_unslash($raw),true); return is_array($d)?$d:array('__invalid'=>1); }
    private function decode_db_json($raw){ $d=json_decode((string)$raw,true); return is_array($d)?$d:array(); }
    private function norm_provider($label){ $k=sanitize_key($label); return $k!==''?$k:'custom'; }
    private function render_field($name,$label,$value,$required=false,$secret=false,$exists=false){ $type=$secret?'password':'text'; $input_value=$secret?'':(string)$value; $ph=$secret&&$exists?'già salvato':''; echo '<p><label><strong>'.esc_html($label).($required?' *':'').'</strong><br/><input type="'.$type.'" name="'.esc_attr($name).'" value="'.esc_attr($input_value).'" placeholder="'.esc_attr($ph).'" class="regular-text" autocomplete="off"></label></p>'; }
    public function render_sources_page(){ if(!current_user_can('manage_options')) wp_die('Unauthorized'); $this->maybe_ensure_sources_table(); settings_errors('alma_source'); global $wpdb; $terms=get_terms(array('taxonomy'=>'link_type','hide_empty'=>false)); if(is_wp_error($terms)||!is_array($terms))$terms=array(); $presets=$this->get_provider_presets();
        $view=sanitize_key($_GET['alma_view']??'');
        if($view==='save_confirmation'){ $this->render_post_save_confirmation(); return; }
        if($view==='delete_confirmation'){ $this->render_delete_confirmation(); return; }
        if($view==='import_contents'){ $this->render_import_contents_page(); return; }
        if($view==='import_result'){ $this->render_import_result_page(); return; }
        if($view==='ai_behavior'){ $this->render_ai_behavior_page(); return; }
        $editing_id=absint($_GET['edit_source']??0); $editing=$editing_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$editing_id),ARRAY_A):array(); if(!is_array($editing))$editing=array(); $sources_view=sanitize_key($_GET['alma_sources_view']??'active'); $where=($sources_view==='deleted')?' WHERE deleted_at IS NOT NULL':(($sources_view==='all')?'':' WHERE deleted_at IS NULL'); $rows=$this->sources_table_exists()?$wpdb->get_results("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources{$where} ORDER BY id DESC LIMIT 100",ARRAY_A):array();
        $es=$this->decode_db_json($editing['settings']??''); $ec=$this->decode_db_json($editing['credentials']??''); $sel=json_decode($editing['destination_term_ids']??'',true); if(!is_array($sel))$sel=array(); if(empty($sel)&&!empty($editing['destination_term_id']))$sel=array((int)$editing['destination_term_id']);
        $result=sanitize_key($_GET['alma_result']??'');
        if($result==='created'){ echo '<div class="notice notice-success is-dismissible"><p>Source creata correttamente.</p></div>'; }
        elseif($result==='updated'){ echo '<div class="notice notice-success is-dismissible"><p>Source aggiornata correttamente.</p></div>'; }
        elseif($result==='error'){ echo '<div class="notice notice-error is-dismissible"><p>Errore nel salvataggio della source.</p></div>'; }
        elseif($result==='invalid_json'){ echo '<div class="notice notice-error is-dismissible"><p>JSON avanzato non valido.</p></div>'; }
        $base_sources_url=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'));
        echo '<div class="wrap"><h1>Affiliate Sources</h1><p><a href="'.esc_url(add_query_arg('alma_sources_view','active',$base_sources_url)).'">Attive</a> | <a href="'.esc_url(add_query_arg('alma_sources_view','deleted',$base_sources_url)).'">Mostra eliminate</a> | <a href="'.esc_url(add_query_arg('alma_sources_view','all',$base_sources_url)).'">Tutte</a></p><button type="button" class="button button-primary alma-toggle-source-form">Aggiungi nuova source</button><div id="alma-source-form-wrap"'.($editing?'':' style="display:none"').'><h2>'.($editing?'Modifica source':'Nuova source').'</h2><form method="post" id="alma-source-form">'; wp_nonce_field('alma_save_source','alma_source_nonce');
        echo '<input type="hidden" name="action_type" value="save_source"/><input type="hidden" name="source_id" value="'.esc_attr($editing['id']??0).'"/><input type="hidden" id="alma-existing-settings" value="'.esc_attr(wp_json_encode($es)).'"/><input type="hidden" id="alma-existing-credentials-flags" value="'.esc_attr(wp_json_encode(array_fill_keys(array_keys($ec), true))).'"/><div class="alma-sections">';
        echo '<div class="alma-section"><h3>Dati source</h3>'; $this->render_input_row('name','Name',$editing['name']??''); $this->render_input_row('provider_label','Provider',$editing['provider_label']??($editing['provider']??''),true);
        echo '<p><label><input type="checkbox" name="is_active" value="1" '.checked(isset($editing['is_active'])?(int)$editing['is_active']:1,1,false).'/> Source attiva</label></p>';
        echo '<p><label>Preset provider<br/><select name="provider_preset" id="provider_preset"><option value="">—</option>'; foreach($presets as $k=>$p){ echo '<option value="'.esc_attr($k).'"'.selected($k,$editing['provider_preset']??'',false).'>'.esc_html($p['label']).'</option>'; } echo '</select></label></p>';
        echo '</div><div class="alma-section"><h3>Tipologie Link assegnate agli import</h3><select multiple name="destination_term_ids[]" id="destination_term_ids" size="6">'; foreach($terms as $t){ echo '<option value="'.intval($t->term_id).'"'.(in_array((int)$t->term_id,$sel,true)?' selected':'').'>'.esc_html($t->name).'</option>'; } echo '</select></div>';

        echo '<div class="alma-section"><h3>Configurazione provider</h3><div id="alma-guided-settings"></div></div>';
        echo '<div class="alma-section"><h3>Credenziali provider</h3><div id="alma-guided-credentials"></div><p id="alma-viator-credentials-note" class="description" style="display:none">Viator richiede una sola API key. Non servono access token, client ID, client secret, username o password.</p></div>';
        echo '<div class="alma-section" id="alma-advanced-credentials" style="display:none"><h3>Credenziali avanzate</h3><p class="description">Inserisci solo chiavi extra non coperte dai campi guidati.</p><textarea name="credentials_advanced" id="credentials_advanced" rows="4" class="large-text code"></textarea></div></div><p><button class="button button-primary">Salva source</button></p></form></div>';
        echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Provider</th><th>Destination</th><th>Mode</th><th>Stato</th><th>Lingua</th><th>Mercato</th><th>Ultimo Sync</th><th>Stato Sync</th><th>Azioni</th></tr></thead><tbody>';
        foreach((array)$rows as $r){ $s=$this->decode_db_json($r['settings']??''); $ids=json_decode($r['destination_term_ids']??'',true); if(!is_array($ids))$ids=array(); if(empty($ids)&&!empty($r['destination_term_id']))$ids=array((int)$r['destination_term_id']); $names=array(); foreach($ids as $tid){ $term=get_term((int)$tid,'link_type'); if($term&&!is_wp_error($term))$names[]=$term->name; }
            $provider_label=$r['provider_label']?:$r['provider']; $edit_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','edit_source'=>(int)$r['id']),admin_url('edit.php')); $fields_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-importable-fields','source_id'=>(int)$r['id']),admin_url('edit.php'));
            $delete_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'delete_confirmation','source_id'=>(int)$r['id']),admin_url('edit.php'));
            $import_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_contents','source_id'=>(int)$r['id']),admin_url('edit.php'));
            $ai_behavior_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'ai_behavior','source_id'=>(int)$r['id']),admin_url('edit.php'));
            $is_deleted=!empty($r['deleted_at']);
            echo '<tr data-source-id="'.(int)$r['id'].'"><td>'.esc_html($r['name']).'</td><td>'.esc_html($provider_label).'<br/><small>'.esc_html($r['provider']).'</small></td><td>'.esc_html($names?implode(', ',$names):'—').'</td><td>'.esc_html($s['mode']??$s['integration_mode']??'—').'</td><td>'.($is_deleted?'Eliminata':((int)$r['is_active']===1?'Attivo':'Disattivo')).'</td><td>'.esc_html($r['language']).'</td><td>'.esc_html($r['market']).'</td><td>'.esc_html($r['last_sync_at']).'</td><td>'.esc_html($r['last_sync_status']).'</td><td><a class="button button-small" href="'.esc_url($edit_link).'">Modifica</a> <a class="button button-small" href="'.esc_url($delete_link).'">Elimina</a> '.($is_deleted?'':'<button type="button" class="button button-small alma-test-connection" data-source-id="'.(int)$r['id'].'">Testa connessione</button> <a class="button button-small" href="'.esc_url($fields_link).'">Campi importabili</a> <a class="button button-small" href="'.esc_url($import_link).'">Importa contenuti</a> <a class="button button-small" href="'.esc_url($ai_behavior_link).'">Comportamento agente AI</a>').'<div class="alma-inline-result" aria-live="polite"></div></td></tr>'; }
        echo '</tbody></table>';
        $this->render_bulk_image_retry_box($rows);
        echo '</div>'; }
    private function render_options($options, $selected){ $html=''; foreach((array)$options as $value=>$label){ $html .= '<option value="'.esc_attr($value).'"'.selected($selected,$value,false).'>'.esc_html($label).'</option>'; } return $html; }
    private function render_input_row($n,$l,$v,$req=false){ echo '<p><label><strong>'.esc_html($l).($req?' *':'').'</strong><br/><input type="text" id="'.esc_attr($n).'" name="'.esc_attr($n).'" value="'.esc_attr($v).'" class="regular-text"'.($req?' required':'').'></label></p>'; }
    private function maybe_handle_source_form(){ if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_POST['action_type']??'')!=='save_source')return; if(!wp_verify_nonce($_POST['alma_source_nonce']??'','alma_save_source'))wp_die('Nonce non valido'); if(!current_user_can('manage_options'))wp_die('Unauthorized'); global $wpdb;
        $source_id=absint($_POST['source_id']??0); $existing=$source_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A):array();
        $provider_label=sanitize_text_field(wp_unslash($_POST['provider_label']??'')); $provider=$this->norm_provider($provider_label); $provider_preset=sanitize_key($_POST['provider_preset']??'');
        $settings_existing=$this->decode_db_json($existing['settings']??'');
        $settings=$settings_existing;

        // Backward compatibility: accept advanced settings JSON, but never let it override guided fields.
        $sa=$this->parse_json($_POST['settings_advanced']??'');
        $ca=$this->parse_json($_POST['credentials_advanced']??'');
        if(isset($sa['__invalid'])||isset($ca['__invalid'])){ wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'save_confirmation','status'=>'invalid_json'),admin_url('edit.php'))); exit; }

        foreach($sa as $k=>$v){
            $kk=sanitize_key($k);
            if($kk==='') continue;
            $settings[$kk]=is_scalar($v)?sanitize_text_field((string)$v):wp_json_encode($v);
        }
        foreach((array)($_POST['settings_fields']??array()) as $k=>$v){
            $key = sanitize_key($k);
            if ($key === 'import_link_type_term_ids') {
                $settings[$key] = array_values(array_unique(array_filter(array_map('absint', (array) $v))));
                continue;
            }
            if (in_array($key, array('import_limit', 'limit'), true)) {
                $settings[$key] = max(1, min(100, (int) $v));
                continue;
            }
            if ($key === 'batch_size') {
                $settings[$key] = max(1, min(500, (int) $v));
                continue;
            }
            if ($key === 'timeout') {
                $settings[$key] = max(3, min(30, (int) $v));
                continue;
            }
            if ($key === 'regenerate_ai_context_on_import') {
                $settings[$key] = sanitize_text_field(wp_unslash($v)) === '1' ? '1' : '0';
                continue;
            }
            $settings[$key]=sanitize_text_field(wp_unslash($v));
        }

        $credentials_existing=$this->decode_db_json($existing['credentials']??''); $credentials=$credentials_existing; foreach((array)($_POST['credentials_fields']??array()) as $k=>$v){ $k=sanitize_key($k); $v=sanitize_text_field(wp_unslash($v)); if($v!=='')$credentials[$k]=$v; }
        if(!in_array($provider_preset, array('viator','getyourguide','gyg_csv'), true)){ foreach((array)($_POST['credentials_extra_fields']??array()) as $k=>$v){ $k=sanitize_key($k); $v=sanitize_text_field(wp_unslash($v)); if($v!=='')$credentials[$k]=$v; }}
        foreach($ca as $k=>$v){ if($v!==''&&$v!==null){ $credentials[sanitize_key($k)]=is_scalar($v)?sanitize_text_field((string)$v):wp_json_encode($v); } }
        $term_ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['destination_term_ids']??array())))));
        $data=array('name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'provider'=>$provider,'provider_preset'=>$provider_preset,'provider_label'=>$provider_label,'is_active'=>isset($_POST['is_active'])?1:0,'language'=>sanitize_text_field(wp_unslash($_POST['language']??'')),'market'=>sanitize_text_field(wp_unslash($_POST['market']??'')),'import_mode'=>sanitize_key($_POST['import_mode']??'create_update'),'destination_term_id'=>(int)($term_ids[0]??0),'destination_term_ids'=>!empty($term_ids)?wp_json_encode($term_ids):null,'settings'=>wp_json_encode($settings),'credentials'=>wp_json_encode($credentials),'updated_at'=>current_time('mysql'));
        $ok=false; $result='error';
        if($source_id>0){ $ok=$wpdb->update("{$wpdb->prefix}alma_affiliate_sources",$data,array('id'=>$source_id)); $result=($ok!==false)?'updated':'error'; }
        else { $data['created_at']=current_time('mysql'); $ok=$wpdb->insert("{$wpdb->prefix}alma_affiliate_sources",$data); $result=($ok!==false)?'created':'error'; if($ok!==false){ $source_id=(int)$wpdb->insert_id; } }
        $redirect=array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'save_confirmation','status'=>$result);
        if($source_id>0){ $redirect['source_id']=$source_id; }
        wp_safe_redirect(add_query_arg($redirect,admin_url('edit.php'))); exit; }

    public function handle_sources_page_load(){
        if(!current_user_can('manage_options')) wp_die('Unauthorized');
        $this->maybe_ensure_sources_table();
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST') return;
        $action=sanitize_key($_POST['action_type']??'');
        if($action==='save_source'){ $this->maybe_handle_source_form(); return; }
        if($action==='gyg_csv_upload'){ $this->handle_gyg_csv_upload(); return; }
        if($action==='gyg_csv_save_mapping'){ $this->handle_gyg_csv_save_mapping(); return; }
        if($action==='gyg_csv_import_selected'){ $this->handle_gyg_csv_import_selected(); return; }
        if($action==='import_selected_items'){ $this->handle_import_selected_items(); return; }
        if($action==='save_ai_behavior'){ $this->handle_save_ai_behavior(); return; }
        if($action==='affiliate_images_bulk_retry'){ $this->handle_bulk_image_retry(); return; }
        if(!in_array($action,array('archive_source','delete_source'),true)) return;
        if(!wp_verify_nonce($_POST['alma_archive_source_nonce']??'','alma_archive_source')) wp_die('Nonce non valido');
        $source_id=absint($_POST['source_id']??0);
        if(empty($_POST['archive_confirm'])){ wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'delete_confirmation','source_id'=>$source_id,'status'=>'confirm_required'),admin_url('edit.php'))); exit; }
        $svc=new ALMA_Affiliate_Source_Archive_Service();
        $res=$svc->archive_source($source_id,get_current_user_id());
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'delete_confirmation','source_id'=>$source_id,'status'=>is_wp_error($res)?'error':'archived'),admin_url('edit.php'))); exit;
    }
    private function render_import_contents_page(){
        global $wpdb; $source_id=absint($_GET['source_id']??0); $source=$source_id>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A):array();
        $list_url=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'));
        echo '<div class="wrap alma-import-page"><h1>Importa contenuti</h1><p><a class="button" href="'.esc_url($list_url).'">Torna alla lista Sources</a></p>';
        if(!is_array($source)||empty($source)||!empty($source['deleted_at'])){ echo '<div class="notice notice-warning"><p>Source non valida o archiviata.</p></div></div>'; return; }
        if(sanitize_key($source['provider_preset']??'')==='gyg_csv'){ $this->render_gyg_csv_import_page($source); echo '</div>'; return; }
        $settings=$this->decode_db_json($source['settings']??'{}'); $term_ids=array(); $decoded_terms=json_decode((string)($source['destination_term_ids']??''),true); if(is_array($decoded_terms)) $term_ids=array_values(array_unique(array_filter(array_map('absint',$decoded_terms)))); if(empty($term_ids)&&!empty($source['destination_term_id']))$term_ids=array((int)$source['destination_term_id']);
        $criteria_service=new ALMA_Affiliate_Source_Import_Criteria_Service(); $criteria=$criteria_service->sanitize($_GET); $criteria['hide_existing']=isset($_GET['hide_existing'])||!isset($_GET['load_preview'])?'1':'0'; $criteria['show_existing']=isset($_GET['show_existing'])?'1':'0'; $load_preview = isset($_GET['load_preview']) && $_GET['load_preview']==='1';
        echo '<div class="postbox"><h2 class="hndle"><span>Riepilogo Source</span></h2><div class="inside"><div class="alma-grid-3"><p><strong>Nome Source:</strong> '.esc_html($source['name']).'</p><p><strong>Provider:</strong> '.esc_html(($source['provider_label']?:$source['provider'])).'</p><p><strong>Preset:</strong> '.esc_html($source['provider_preset']?:'—').'</p><p><strong>Stato:</strong> '.(((int)$source['is_active']===1)?'Attivo':'Disattivo').'</p><p><strong>Duplicate policy:</strong> '.esc_html($settings['duplicate_policy']??'skip_existing').'</p><p><strong>AI context on import:</strong> '.(((string)($settings['regenerate_ai_context_on_import']??'1')==='1')?'Sì':'No').'</p></div><p><strong>Tipologie Link assegnate agli import:</strong> ';
        if(!empty($term_ids)){
            foreach($term_ids as $tid){
                $term = get_term((int)$tid,'link_type');
                $label = ($term && !is_wp_error($term)) ? $term->name : ('Tipologia non trovata #'.(int)$tid);
                echo '<span class="alma-badge">'.esc_html($label).'</span> ';
            }
        } else {
            echo '<span class="alma-badge-warning">Non configurate</span> <span class="description">I Link saranno creati senza Tipologia Link.</span>';
        }
        echo '</p></div></div>';
        echo '<form method="get" class="postbox"><h2 class="hndle"><span>Ricerca</span></h2><div class="inside alma-import-grid"><input type="hidden" name="post_type" value="affiliate_link"/><input type="hidden" name="page" value="alma-affiliate-sources"/><input type="hidden" name="alma_view" value="import_contents"/><input type="hidden" name="source_id" value="'.(int)$source_id.'"/><input type="hidden" name="load_preview" value="1"/>';
        echo '<p><label>Modalità ricerca<br/><select name="import_search_model" id="import_search_model"><option value="freetext_search"'.selected($criteria['import_search_model'],'freetext_search',false).'>freetext_search</option><option value="products_search"'.selected($criteria['import_search_model'],'products_search',false).'>products_search</option></select></label></p>';
        echo '<p><label>Keyword / città / termine ricerca<br/><input type="text" name="import_search_term" value="'.esc_attr($criteria['import_search_term']).'" placeholder="es. Milano, Lecce, wine tour, Colosseo"/></label></p>';
        echo '<p><label>Destination ID Viator<br/><input type="text" name="import_destination_id" value="'.esc_attr($criteria['import_destination_id']).'"/></label></p>';
        if(($source['provider_preset']??'')==='getyourguide'){ echo '<p class="description">GetYourGuide usa la keyword come parametro q di /1/tours; se vuota viene usata la Query predefinita della Source.</p>'; }
        echo '<p><label>Quantità nuovi item desiderati<br/><input type="number" name="import_limit" min="1" max="100" value="'.(int)$criteria['import_limit'].'"/></label></p>';
        echo '<p><label><input type="checkbox" name="hide_existing" value="1" '.checked($criteria['hide_existing'],'1',false).'/> Solo nuovi nel plugin</label></p>';
        echo '<details class="alma-advanced-filters"><summary>Filtri avanzati Viator</summary><div class="alma-grid-3"><p><label>Rating minimo <input type="number" step="0.1" min="0" max="5" name="import_rating_from" value="'.esc_attr((string)$criteria['import_rating_from']).'"/></label></p><p><label>Rating massimo <input type="number" step="0.1" min="0" max="5" name="import_rating_to" value="'.esc_attr((string)$criteria['import_rating_to']).'"/></label></p><p><label>Tag Viator IDs <input type="text" name="import_tag_ids" value="'.esc_attr($criteria['import_tag_ids']).'"/></label></p></div></details>';
        echo '<div class="alma-import-filters"><h3>Filtri risultati</h3><p><label><input type="checkbox" name="hide_existing" value="1" '.checked($criteria['hide_existing'],'1',false).'/> Solo nuovi nel plugin</label></p><p><label><input type="checkbox" name="show_existing" value="1" '.checked($criteria['show_existing'],'1',false).'/> Mostra anche già importati</label></p><p><label><input type="checkbox" name="auto_fill_new_items" value="1" '.checked($criteria['auto_fill_new_items'],'1',false).'/> Riempi automaticamente preview con nuovi item</label></p></div>';
        echo '<p><button class="button button-primary">Carica anteprima</button></p></div></form>';
        if($load_preview){
            $criteria_token = sanitize_key($_GET['criteria_token'] ?? '');
            if($criteria_token===''){
                $criteria_token = $this->make_criteria_token($source_id);
                set_transient($this->criteria_transient_key(get_current_user_id(),$source_id,$criteria_token), array('criteria'=>$criteria,'source_id'=>$source_id,'user_id'=>get_current_user_id(),'created_at'=>time(),'loaded_starts'=>array((int)$criteria['import_start'])), 15*MINUTE_IN_SECONDS);
            } else {
                $stored=get_transient($this->criteria_transient_key(get_current_user_id(),$source_id,$criteria_token));
                if(is_array($stored) && !empty($stored['criteria'])){ $criteria=array_merge($stored['criteria'],$criteria); }
            }
            $preview_service=new ALMA_Affiliate_Source_Import_Preview_Service(); $items=$preview_service->get_preview_items($source,$criteria); if(is_wp_error($items)){ echo '<div class="notice notice-error"><p>'.esc_html($items->get_error_message()).'</p></div></div>'; return; }
            $dup=sanitize_key($settings['duplicate_policy']??'skip_existing'); $dedupe_map=$preview_service->build_dedupe_map($source,$items,$dup); $new=array(); $existing=array(); foreach((array)$items as $it){$eid=(string)($it['productCode']??$it['external_id']??$it['tour_id']??$it['id']??''); if($eid==='' )continue; if(!empty($dedupe_map[$eid]['post_id'])) $existing[]=$it; else $new[]=$it;}
            $visible = ($criteria['hide_existing']==='1' && $criteria['show_existing']!=='1') ? $new : $items;
            echo '<div class="postbox"><h2 class="hndle"><span>Criteri applicati</span></h2><div class="inside"><p>Modello: '.esc_html($criteria['import_search_model']).' · Keyword: '.esc_html($criteria['import_search_term']).' · Destination: '.esc_html($criteria['import_destination_id']).' · Quantità: '.(int)$criteria['import_limit'].' · Mostra solo nuovi: '.($criteria['hide_existing']==='1'?'Sì':'No').'</p></div></div>';
            $next_start=(int)$criteria['import_start']+max(1,min(50,(int)$criteria['import_limit']));
            $load_more_url=add_query_arg(array_merge($_GET,array('criteria_token'=>$criteria_token,'load_preview'=>'1','import_start'=>$next_start)),admin_url('edit.php'));
            echo '<div class="postbox"><h2 class="hndle"><span>Risultati anteprima</span></h2><div class="inside"><p>Recuperati API: '.count((array)$items).' · Nuovi mostrati: '.count($visible).' · Già importati nascosti: '.count($existing).' · Start corrente: '.(int)$criteria['import_start'].' · Prossimo start: '.$next_start.'</p><p><button type="button" class="button alma-load-more-results" data-href="'.esc_url($load_more_url).'">Carica altri risultati</button></p>';
            echo '<form method="post">'; wp_nonce_field('alma_import_selected','alma_import_selected_nonce'); echo '<input type="hidden" name="action_type" value="import_selected_items"/><input type="hidden" name="source_id" value="'.(int)$source_id.'"/><input type="hidden" name="criteria_token" value="'.esc_attr($criteria_token).'"/><input type="hidden" name="import_search_model" value="'.esc_attr($criteria['import_search_model']).'"/><input type="hidden" name="import_search_term" value="'.esc_attr($criteria['import_search_term']).'"/><input type="hidden" name="import_destination_id" value="'.esc_attr($criteria['import_destination_id']).'"/><input type="hidden" name="import_limit" value="'.(int)$criteria['import_limit'].'"/><input type="hidden" name="import_start" value="'.(int)$criteria['import_start'].'"/><input type="hidden" name="next_start" value="'.(int)$criteria['next_start'].'"/><input type="hidden" name="hide_existing" value="'.esc_attr($criteria['hide_existing']).'"/><input type="hidden" name="show_existing" value="'.esc_attr($criteria['show_existing']).'"/><input type="hidden" name="auto_fill_new_items" value="'.esc_attr($criteria['auto_fill_new_items']).'"/>';
            echo '<p><button type="button" class="button alma-select-all">Seleziona tutti</button> <button type="button" class="button alma-deselect-all">Deseleziona tutti</button> <span class="alma-selected-counter">0 selezionati</span></p><table class="widefat striped alma-import-preview"><thead><tr><th></th><th>Immagine</th><th>Titolo</th><th>Link affiliato</th><th>Prezzo</th><th>Rating</th><th>External ID</th><th>Azione</th></tr></thead><tbody>';
            if(empty($visible)){ echo '<tr><td colspan="8">Nessun risultato disponibile per questi criteri.</td></tr>'; }
            foreach((array)$visible as $it){$eid=(string)($it['productCode']??$it['external_id']??$it['tour_id']??$it['id']??''); if($eid==='')continue; $exists=!empty($dedupe_map[$eid]['post_id']); $product_url=isset($it['productUrl'])?(string)$it['productUrl']:(string)($it['affiliate_url']??$it['url']??$it['marketplace_url']??''); $affiliate_link=$product_url!==''?'<a class="alma-affiliate-link" href="'.esc_url($product_url).'" target="_blank" rel="noopener noreferrer" title="'.esc_attr($product_url).'">Apri</a>':'<span class="alma-affiliate-link-empty">N/D</span>'; $price=trim((string)($it['price']??$it['from_price']??'').' '.(string)($it['currency']??'')); $rating=(string)($it['rating']??$it['average_rating']??''); $image_preview=$this->render_import_media_preview_cell($it); $validation=is_array($it['_alma_validation']??null)?$it['_alma_validation']:array(); $status=(string)($validation['status']??''); echo '<tr'.($exists?' class="alma-row-existing"':'').'><td><input class="alma-select-item" type="checkbox" name="selected_external_ids[]" value="'.esc_attr($eid).'" '.checked(!$exists && $status!=="error",true,false).' '.disabled($status==='error',true,false).'></td><td>'.$image_preview.'</td><td>'.esc_html($it['title']??$it['name']??'—').$this->render_import_validation_notes($validation).'</td><td>'.$affiliate_link.'</td><td>'.esc_html($price!==''?$price:'—').'</td><td>'.esc_html($rating!==''?$rating:'—').'</td><td>'.esc_html($eid).'</td><td>'.($exists?'già importato':($status==='error'?'non importabile':'crea')).'</td></tr>';}
            if(empty($term_ids)){ echo '<p class="description">I Link saranno creati senza Tipologia Link.</p>'; }
            echo '</tbody></table><p><button class="button button-primary">Importa selezionati</button></p></form></div></div>';
        }
        echo '</div>';
    }
    private function render_gyg_csv_import_page($source){
        $source_id=(int)$source['id']; $settings=ALMA_Affiliate_Source_GYG_CSV_Importer::default_settings($this->decode_db_json($source['settings']??'{}')); $svc=new ALMA_Affiliate_Source_GYG_CSV_Importer(); $token=sanitize_key($_GET['gyg_csv_token']??'');
        echo '<div class="notice notice-info"><p><strong>GetYourGuide CSV / Deep Link:</strong> importa CSV locali senza chiamate esterne. La quantità si sceglie nel modale, con massimo 1000 record per importazione e import progressivo.</p></div>';
        echo '<div class="postbox"><h2 class="hndle"><span>Step 1 — Caricamento CSV</span></h2><div class="inside"><form method="post" enctype="multipart/form-data">'; wp_nonce_field('alma_gyg_csv_upload','alma_gyg_csv_nonce'); echo '<input type="hidden" name="action_type" value="gyg_csv_upload"/><input type="hidden" name="source_id" value="'.(int)$source_id.'"/><p><input type="file" name="gyg_csv_file" accept=".csv,text/csv" required/> <button class="button button-primary">Carica CSV</button></p><p class="description">Colonne obbligatorie: URL, Tipologia attività, Descrizione attività. Opzionali: Città, Regione di appartenenza. La quantità viene scelta nel modale (massimo 1000 record per importazione).</p></form></div></div>';
        if($token==='') return;
        $session=$svc->get_session($token,$source_id); if(is_wp_error($session)){ echo '<div class="notice notice-error"><p>'.esc_html($session->get_error_message()).'</p></div>'; return; }
        $headers=$svc->get_headers($session['path']); if(is_wp_error($headers)){ echo '<div class="notice notice-error"><p>'.esc_html($headers->get_error_message()).'</p></div>'; return; }
        $det=$svc->detect_columns($headers); $labels=array('url'=>'URL','city'=>'Città','region'=>'Regione di appartenenza','activity_type'=>'Tipologia attività','description'=>'Descrizione attività');
        echo '<div class="postbox"><h2 class="hndle"><span>Step 2 — Rilevamento colonne</span></h2><div class="inside"><p><strong>File:</strong> '.esc_html($session['name']??'CSV').'</p><p><strong>Colonne rilevate:</strong> '.esc_html(implode(', ', $det['headers'])).'</p><table class="widefat striped"><thead><tr><th>Campo Sothra</th><th>Colonna associata</th><th>Stato</th></tr></thead><tbody>';
        foreach($labels as $key=>$label){ $has=isset($det['columns'][$key]); echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html($has ? ($det['headers'][$det['columns'][$key]]??'') : '—').'</td><td>'.($has?'<span class="alma-badge">ok</span>':'<span class="alma-badge-warning">mancante'.(in_array($key,array('url','activity_type','description'),true)?' obbligatoria':' opzionale').'</span>').'</td></tr>'; }
        echo '</tbody></table>'; if(!$det['valid']){ echo '<div class="notice notice-error inline"><p>Mancano colonne obbligatorie: '.esc_html(implode(', ',$det['missing'])).'. Correggi il CSV e ricaricalo.</p></div></div></div>'; return; } echo '</div></div>';
        $summary=$svc->summarize($session['path'],$det['columns']); $mappings=is_array($settings['type_mappings']??null)?$settings['type_mappings']:array();
        echo '<div class="postbox"><h2 class="hndle"><span>Step 3 — Riepilogo Tipologie attività</span></h2><div class="inside"><table class="widefat striped"><thead><tr><th>Tipologia attività CSV</th><th>Record</th><th>Mapping Sothra</th><th>Azione</th></tr></thead><tbody>';
        foreach($summary['types'] as $type=>$count){ $term_ids=ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids($mappings[$type]??array()); $names=array(); foreach($term_ids as $tid){ $term=get_term($tid,'link_type'); if($term&&!is_wp_error($term)) $names[]=$term->name; } echo '<tr><td>'.esc_html($type).'</td><td>'.(int)$count.'</td><td class="alma-gyg-mapping-cell" data-activity-type="'.esc_attr($type).'">'.esc_html(!empty($names)?implode(', ',$names):'—').'</td><td><button type="button" class="button alma-gyg-open-import" data-source-id="'.(int)$source_id.'" data-token="'.esc_attr($token).'" data-activity-type="'.esc_attr($type).'" data-total="'.(int)$count.'">Importa questa tipologia</button></td></tr>'; }
        echo '</tbody></table><p class="description">Totale righe: '.(int)$summary['total'].' · URL non validi: '.(int)$summary['invalid_urls'].' · record senza città: '.(int)$summary['without_city'].' · record senza regione: '.(int)$summary['without_region'].'</p></div></div>';
        $this->render_gyg_csv_import_modal($source, $token);
    }

    private function render_gyg_csv_import_modal($source, $token){
        $settings=ALMA_Affiliate_Source_GYG_CSV_Importer::default_settings($this->decode_db_json($source['settings']??'{}'));
        $list_url=add_query_arg(array('post_type'=>'affiliate_link','alma_source_filter'=>(int)$source['id']),admin_url('edit.php'));
        echo '<div id="alma-gyg-import-modal" class="alma-modal" aria-hidden="true"><div class="alma-modal-backdrop"></div><div class="alma-modal-panel" role="dialog" aria-modal="true" aria-labelledby="alma-gyg-modal-title"><button type="button" class="button-link alma-modal-close" aria-label="Chiudi">×</button><h2 id="alma-gyg-modal-title">Importazione GetYourGuide CSV</h2><div class="alma-gyg-modal-error notice notice-error inline" style="display:none"><p></p></div><div class="alma-gyg-summary"></div><h3>Associa a Tipologie Link Sothra</h3><div class="alma-gyg-terms"><p class="description">Caricamento tipologie…</p></div><p><label><strong>Quantità da importare</strong><br/><input type="number" id="alma-gyg-quantity" min="1" max="1000" value="100"/> <span class="description">Massimo 1000 record per importazione.</span></label></p><fieldset><legend class="screen-reader-text">Modalità deduplica</legend><p><label><input type="radio" name="alma_gyg_update_existing" value="0" checked> Importa solo nuovi record</label><br/><label><input type="radio" name="alma_gyg_update_existing" value="1"> Aggiorna anche record già importati</label></p></fieldset><h3>Anteprima sintetica</h3><div class="alma-gyg-preview"><p class="description">Apri una tipologia per caricare l’anteprima.</p></div><div class="alma-gyg-progress-wrap" style="display:none"><div class="alma-progress"><div class="alma-progress-bar" style="width:0%"></div></div><p class="alma-gyg-progress-status">Preparazione importazione…</p></div><div class="alma-gyg-report" style="display:none"></div><h3>Log errori</h3><div class="alma-gyg-log"><p class="description">Nessun errore o warning.</p></div><p class="submit"><button type="button" class="button button-primary alma-gyg-start-import">Avvia importazione</button> <button type="button" class="button alma-gyg-import-more" style="display:none">Importa altri record di questa tipologia</button> <a class="button" href="'.esc_url($list_url).'">Vai ai Link affiliati gyg_csv</a> <button type="button" class="button alma-modal-close">Chiudi</button></p><input type="hidden" class="alma-gyg-source-id" value="'.(int)$source['id'].'"/><input type="hidden" class="alma-gyg-token" value="'.esc_attr($token).'"/><input type="hidden" class="alma-gyg-partner" value="'.esc_attr($settings['partner_id']??'').'"/><input type="hidden" class="alma-gyg-utm" value="'.esc_attr($settings['utm_medium']??'online_publisher').'"/></div></div>';
    }

    private function handle_gyg_csv_upload(){
        if(!wp_verify_nonce($_POST['alma_gyg_csv_nonce']??'','alma_gyg_csv_upload')) wp_die('Nonce non valido');
        global $wpdb; $source_id=absint($_POST['source_id']??0); $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        if(!is_array($source)||sanitize_key($source['provider_preset']??'')!=='gyg_csv') wp_die('Source gyg_csv non valida');
        $svc=new ALMA_Affiliate_Source_GYG_CSV_Importer(); $res=$svc->handle_upload($_FILES['gyg_csv_file']??array(),$source_id);
        if(is_wp_error($res)){ set_transient('alma_import_result_'.get_current_user_id(),array('source_id'=>$source_id,'selected'=>0,'result'=>$res),5*MINUTE_IN_SECONDS); wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_result','source_id'=>$source_id),admin_url('edit.php'))); exit; }
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_contents','source_id'=>$source_id,'gyg_csv_token'=>$res['token']),admin_url('edit.php'))); exit;
    }

    private function handle_gyg_csv_save_mapping(){
        if(!wp_verify_nonce($_POST['alma_gyg_csv_mapping_nonce']??'','alma_gyg_csv_mapping')) wp_die('Nonce non valido');
        global $wpdb; $source_id=absint($_POST['source_id']??0); $type=sanitize_text_field(wp_unslash($_POST['gyg_activity_type']??'')); $term_id=absint($_POST['link_type_term_id']??0); $token=sanitize_key($_POST['gyg_csv_token']??''); $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A); if(!is_array($source)) wp_die('Source non valida');
        if(sanitize_key($source['provider_preset']??'')!=='gyg_csv') wp_die('Source gyg_csv non valida');
        $settings=ALMA_Affiliate_Source_GYG_CSV_Importer::default_settings($this->decode_db_json($source['settings']??'{}')); $settings['type_mappings'][$type]=array($term_id); $wpdb->update("{$wpdb->prefix}alma_affiliate_sources",array('settings'=>wp_json_encode($settings),'updated_at'=>current_time('mysql')),array('id'=>$source_id));
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_contents','source_id'=>$source_id,'gyg_csv_token'=>$token,'gyg_activity_type'=>rawurlencode($type)),admin_url('edit.php'))); exit;
    }

    private function handle_gyg_csv_import_selected(){
        if(!wp_verify_nonce($_POST['alma_gyg_csv_import_nonce']??'','alma_gyg_csv_import')) wp_die('Nonce non valido');
        global $wpdb; $source_id=absint($_POST['source_id']??0); $token=sanitize_key($_POST['gyg_csv_token']??''); $type=sanitize_text_field(wp_unslash($_POST['gyg_activity_type']??'')); $term_id=absint($_POST['link_type_term_id']??0); $selected=(array)($_POST['selected_external_ids']??array());
        if(count($selected)>1000){ $selected=array_slice($selected,0,1000); }
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A); $svc=new ALMA_Affiliate_Source_GYG_CSV_Importer();
        if(!is_array($source)){ $result=new WP_Error('invalid_source',__('Source non valida.', 'affiliate-link-manager-ai')); }
        else { $session=$svc->get_session($token,$source_id); if(is_wp_error($session)) $result=$session; else { $headers=$svc->get_headers($session['path']); $det=is_wp_error($headers)?array('valid'=>false,'missing'=>array()):$svc->detect_columns($headers); $result=!empty($det['valid'])?$svc->import_selected($session['path'],$det['columns'],$type,$source,$selected,$term_id):new WP_Error('missing_columns',__('Colonne obbligatorie mancanti nel CSV.', 'affiliate-link-manager-ai')); } }
        set_transient('alma_import_result_'.get_current_user_id(),array('source_id'=>$source_id,'selected'=>count($selected),'result'=>$result),5*MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_result','source_id'=>$source_id),admin_url('edit.php'))); exit;
    }

    private function render_import_validation_notes($validation){
        if(!is_array($validation)) return '';
        $messages=array_merge((array)($validation['errors']??array()),(array)($validation['warnings']??array()));
        $messages=array_values(array_filter(array_map('sanitize_text_field',$messages)));
        return empty($messages)?'':'<div class="alma-validation-notes">'.esc_html(implode(' · ',array_slice($messages,0,3))).'</div>';
    }
    private function render_import_media_preview_cell($item){
        $validation = is_array($item['_alma_validation'] ?? null) ? $item['_alma_validation'] : array();
        $media = is_array($validation['media'] ?? null) ? $validation['media'] : array();
        $url = esc_url_raw((string)($media['featured_image_url'] ?? ''));
        $has_image = !empty($media['has_image']) && $url !== '' && wp_http_validate_url($url);
        $warnings = array_values(array_filter(array_map('sanitize_text_field',(array)($media['warnings'] ?? array()))));
        if(!$has_image){
            $html = '<div class="alma-media-empty">Nessuna immagine</div>';
        } else {
            $alt = sanitize_text_field((string)($media['caption'] ?? ($item['title'] ?? $item['name'] ?? __('Anteprima immagine', 'affiliate-link-manager-ai'))));
            $html = '<div class="alma-media-preview"><img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" loading="lazy"/><div class="alma-media-badges">';
            if(!empty($media['is_cover'])) $html .= '<span class="alma-media-badge">Cover</span>';
            $source = sanitize_text_field((string)($media['image_source'] ?? ''));
            if($source !== '' && stripos($source, 'supplier') !== false) $html .= '<span class="alma-media-badge">Supplier</span>';
            $html .= '</div></div>';
        }
        if(!empty($warnings)){
            $html .= '<div class="alma-media-warning">'.esc_html($warnings[0]).'</div>';
        }
        return $html;
    }
    private function handle_import_selected_items(){
        if(!wp_verify_nonce($_POST['alma_import_selected_nonce']??'','alma_import_selected')) wp_die('Nonce non valido');
        $source_id=absint($_POST['source_id']??0); $selected=array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)($_POST['selected_external_ids']??array()))))); $criteria_token=sanitize_key($_POST['criteria_token']??'');
        global $wpdb; $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A); if(!is_array($source)) wp_die('Source non valida');
        if(!empty($source['deleted_at'])){ $result = new WP_Error('source_archived', __('Source archiviata: import bloccato.', 'affiliate-link-manager-ai')); } else {
            $stored=get_transient($this->criteria_transient_key(get_current_user_id(),$source_id,$criteria_token));
            if(empty($criteria_token) || !is_array($stored) || empty($stored['criteria'])){ $result = new WP_Error('criteria_token_expired', __('Sessione criteri scaduta o non valida. Ricarica anteprima.', 'affiliate-link-manager-ai')); }
            else { $service=new ALMA_Affiliate_Source_Manual_Import_Service(); $result=$service->import_selected($source,array('ids'=>$selected,'criteria'=>$stored['criteria'])); }
        }
        set_transient('alma_import_result_'.get_current_user_id(),array('source_id'=>$source_id,'selected'=>count($selected),'result'=>$result),5*MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'import_result','source_id'=>$source_id),admin_url('edit.php'))); exit;
    }
    private function render_import_result_page(){
        $data=get_transient('alma_import_result_'.get_current_user_id()); echo '<div class="wrap"><h1>Risultato import</h1>';
        if(!is_array($data)){ echo '<div class="notice notice-warning"><p>Nessun risultato disponibile.</p></div></div>'; return; }
        $r=$data['result'];
        if(is_wp_error($r)){ $source_id=(int)$data['source_id']; $base=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php')); echo '<div class="notice notice-error"><p>'.esc_html($r->get_error_message()).'</p></div>'; echo '<p><a class="button" href="'.esc_url(add_query_arg(array('edit_source'=>$source_id),$base)).'">Torna alla Source</a> <a class="button button-primary" href="'.esc_url(add_query_arg(array('alma_view'=>'import_contents','source_id'=>$source_id,'load_preview'=>'1'),$base)).'">Nuova anteprima import</a> <a class="button" href="'.esc_url($base).'">Torna alla lista Sources</a></p></div>'; return; } $source_id=(int)$data['source_id']; $base=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'));
        if(isset($r['imported']) || isset($r['already_present']) || isset($r['invalid_urls'])){ echo '<ul><li>Selezionati: '.intval($data['selected']).'</li><li>Importati: '.intval($r['imported']??0).'</li><li>Aggiornati: '.intval($r['updated']??0).'</li><li>Già presenti: '.intval($r['already_present']??0).'</li><li>Saltati: '.intval($r['skipped']??0).'</li><li>Errori: '.intval($r['errors']??0).'</li><li>URL non validi: '.intval($r['invalid_urls']??0).'</li><li>Record senza città: '.intval($r['without_city']??0).'</li><li>Record senza regione: '.intval($r['without_region']??0).'</li><li>Durata batch: '.esc_html((string)($r['duration']??0)).'s</li></ul>'; } else { echo '<ul><li>Selezionati: '.intval($data['selected']).'</li><li>Creati: '.intval($r['created']??0).'</li><li>Aggiornati: '.intval($r['updated']??0).'</li><li>Saltati: '.intval($r['skipped']??0).'</li><li>Errori: '.intval($r['errors']??0).'</li><li>Immagini importate: '.intval($r['image_imported']??0).'</li><li>Immagini riutilizzate: '.intval($r['image_reused']??0).'</li><li>Immagini non importate: '.intval($r['image_skipped']??0).'</li><li>Warning immagini: '.intval($r['image_failed']??0).'</li></ul>'; }
        echo '<p><a class="button button-primary" href="'.esc_url(add_query_arg(array('alma_view'=>'import_contents','source_id'=>$source_id,'load_preview'=>'1'),$base)).'">Nuova anteprima import</a> <a class="button" href="'.esc_url(add_query_arg('source_id',$source_id,$base)).'">Torna alla lista Sources</a></p></div>';
    }

    private function handle_save_ai_behavior(){
        if(!wp_verify_nonce($_POST['alma_ai_behavior_nonce']??'','alma_save_ai_behavior')) wp_die('Nonce non valido');
        if(!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb; $source_id=absint($_POST['source_id']??0);
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        if(!is_array($source)||!empty($source['deleted_at'])){ wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'ai_behavior','source_id'=>$source_id,'status'=>'error'),admin_url('edit.php'))); exit; }
        $settings=$this->decode_db_json($source['settings']??'{}');
        $settings['ai_source_instructions']=sanitize_textarea_field(wp_unslash($_POST['ai_source_instructions']??''));
        $settings['ai_context_refresh_interval']=sanitize_key($_POST['ai_context_refresh_interval']??'7d');
        $settings['api_sync_interval']=sanitize_key($_POST['api_sync_interval']??'manual');
        $settings['ai_context_regeneration_policy']=sanitize_key($_POST['ai_context_regeneration_policy']??'if_hash_changed_or_expired');
        $wpdb->update("{$wpdb->prefix}alma_affiliate_sources",array('settings'=>wp_json_encode($settings),'updated_at'=>current_time('mysql')),array('id'=>$source_id));
        wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'ai_behavior','source_id'=>$source_id,'status'=>'saved'),admin_url('edit.php'))); exit;
    }
    private function render_ai_behavior_page(){
        global $wpdb; $source_id=absint($_GET['source_id']??0); $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        $list_url=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'));
        if(!is_array($source)||empty($source)){ echo '<div class="wrap"><div class="notice notice-error"><p>Source non trovata.</p></div></div>'; return; }
        $settings=$this->decode_db_json($source['settings']??'{}');
        $instructions=(string)($settings['ai_source_instructions']??ALMA_Affiliate_Link_AI_Context_Builder::DEFAULT_INSTRUCTIONS);
        if(in_array(($source['provider_preset']??''),array('viator','getyourguide'),true) && isset($_GET['reset_provider_defaults']) && $_GET['reset_provider_defaults']==='1'){ $instructions='Non copiare descrizioni originali provider. Non usare recensioni testuali. Usa solo dati aggregati e informazioni sintetiche. Non presentare prezzo/disponibilità come certi. Invita a verificare i dettagli aggiornati tramite link affiliato.'; }
        echo '<div class="wrap"><h1>Comportamento agente AI</h1>';
        if(($_GET['status']??'')==='saved') echo '<div class="notice notice-success"><p>Comportamento AI salvato.</p></div>';
        echo '<p>Queste istruzioni sono regole generali per l’Agente AI quando usa contenuti provenienti da questa Source. Non vengono copiate nel singolo Link Affiliato.</p>';
        echo '<ul><li><strong>Nome Source:</strong> '.esc_html($source['name']).'</li><li><strong>Provider:</strong> '.esc_html(($source['provider_label']?:$source['provider'])).'</li><li><strong>Preset:</strong> '.esc_html($source['provider_preset']?:'—').'</li><li><strong>Stato:</strong> '.(!empty($source['deleted_at'])?'Eliminata':(((int)$source['is_active']===1)?'Attivo':'Disattivo')).'</li></ul>';
        echo '<form method="post">'; wp_nonce_field('alma_save_ai_behavior','alma_ai_behavior_nonce');
        echo '<input type="hidden" name="action_type" value="save_ai_behavior"/><input type="hidden" name="source_id" value="'.(int)$source_id.'"/>';
        echo '<p><textarea name="ai_source_instructions" rows="8" class="large-text">'.esc_textarea($instructions).'</textarea></p>';
        echo '<p><label>AI Context refresh interval <select name="ai_context_refresh_interval">'.$this->render_options(array('manual'=>'manual','24h'=>'24h','72h'=>'72h','7d'=>'7d','30d'=>'30d'),(string)($settings['ai_context_refresh_interval']??'7d')).'</select></label></p>';
        echo '<p><label>API Sync interval <select name="api_sync_interval">'.$this->render_options(array('manual'=>'manual','24h'=>'24h','72h'=>'72h','7d'=>'7d','30d'=>'30d'),(string)($settings['api_sync_interval']??'manual')).'</select></label></p>';
        echo '<p><label>AI context regeneration policy <select name="ai_context_regeneration_policy">'.$this->render_options(array('only_if_hash_changed'=>'only_if_hash_changed','if_hash_changed_or_expired'=>'if_hash_changed_or_expired','always_on_import'=>'always_on_import','manual_only'=>'manual_only'),(string)($settings['ai_context_regeneration_policy']??'if_hash_changed_or_expired')).'</select></label></p>';
        echo '<p><button class="button button-primary">Salva comportamento AI</button> <a class="button" href="'.esc_url($list_url).'">Torna alla lista Sources</a> <a class="button" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','edit_source'=>$source_id),admin_url('edit.php'))).'">Torna alla Source</a> ';
        if(in_array(($source['provider_preset']??''),array('viator','getyourguide'),true)){ echo '<a class="button" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'ai_behavior','source_id'=>$source_id,'reset_provider_defaults'=>'1'),admin_url('edit.php'))).'">Ripristina istruzioni predefinite provider</a>'; }
        echo '</p></form></div>';
    }

    private function render_post_save_confirmation(){
        if(!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb;
        $status=sanitize_key($_GET['status']??'error');
        $source_id=absint($_GET['source_id']??0);
        $source=array();
        if($source_id>0){
            $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
            if(!is_array($source)) $source=array();
        }
        $list_url=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'));
        $edit_url=$source_id>0?add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','edit_source'=>$source_id),admin_url('edit.php')):'';
        $fields_url=$source_id>0?add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-importable-fields','source_id'=>$source_id),admin_url('edit.php')):'';
        $is_success=in_array($status,array('created','updated'),true);
        $title='Errore salvataggio Source';
        $message='Si è verificato un errore durante il salvataggio della source.';
        if($status==='created'){ $title='Source creata'; $message='Source creata correttamente.'; }
        elseif($status==='updated'){ $title='Source aggiornata'; $message='Source aggiornata correttamente.'; }
        elseif($status==='invalid_json'){ $message='JSON avanzato non valido.'; }
        elseif($source_id>0 && empty($source)){ $message='La source non è più disponibile.'; }
        echo '<div class="wrap"><h1>'.esc_html($title).'</h1>';
        echo '<div class="notice '.($is_success?'notice-success':'notice-error').'"><p>'.esc_html($message).'</p></div>';
        echo '<div class="alma-section" style="max-width:820px;">';
        if($source_id>0 && !empty($source)){
            echo '<ul><li><strong>Nome Source:</strong> '.esc_html($source['name']??'').'</li><li><strong>Provider:</strong> '.esc_html(($source['provider_label']??'')?:($source['provider']??'')).'</li><li><strong>Preset:</strong> '.esc_html(($source['provider_preset']??'')!==''?$source['provider_preset']:'—').'</li><li><strong>Stato:</strong> '.(((int)($source['is_active']??0)===1)?'Attivo':'Disattivo').'</li></ul>';
        } else {
            echo '<p>Dettagli source non disponibili.</p>';
        }
        echo '<p><a class="button button-primary" href="'.esc_url($list_url).'">Torna alla lista Sources</a> ';
        if($source_id>0 && !empty($source)){ echo '<a class="button" href="'.esc_url($edit_url).'">Modifica questa Source</a> '; }
        if($source_id>0 && !empty($source)){ echo '<a class="button" href="'.esc_url($fields_url).'">Campi importabili</a> '; }
        if($source_id>0 && !empty($source)){ echo '<span class="alma-source-actions alma-test-connection-wrap"><button type="button" class="button alma-test-connection" data-source-id="'.(int)$source_id.'">Testa connessione</button><span class="alma-inline-result" aria-live="polite"></span></span>'; }
        echo '</p>';
        if(!$is_success){ echo '<p class="description">Puoi tornare alla lista Sources e riprovare la modifica o creare una nuova Source.</p>'; }
        else { echo '<p class="description">Puoi ora testare la connessione o tornare all\'elenco.</p>'; }
        echo '</div></div>';
    }
    private function render_delete_confirmation(){
        global $wpdb; $source_id=absint($_GET['source_id']??0); $status=sanitize_key($_GET['status']??'');
        $source=$source_id>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A):array();
        $svc=new ALMA_Affiliate_Source_Archive_Service(); $links_count=$svc->count_associated_links($source_id);
        echo '<div class="wrap"><h1>Conferma archiviazione Source</h1>';
        if($status==='archived'){ echo '<div class="notice notice-success"><p>Source archiviata correttamente.</p></div>'; }
        if($status==='confirm_required'){ echo '<div class="notice notice-error"><p>Conferma obbligatoria per archiviare la Source.</p></div>'; }
        echo '<ul><li><strong>Nome Source:</strong> '.esc_html($source['name']??'').'</li><li><strong>Provider:</strong> '.esc_html(($source['provider_label']??'')?:($source['provider']??'')).'</li><li><strong>Preset:</strong> '.esc_html(($source['provider_preset']??'')!==''?$source['provider_preset']:'—').'</li><li><strong>Stato:</strong> '.(!empty($source['deleted_at'])?'Eliminata':'Attiva').'</li><li><strong>Link associati:</strong> '.intval($links_count).'</li></ul>';
        echo '<p><strong>Attenzione:</strong> la Source sarà archiviata; i link importati non saranno eliminati; resteranno associati alla Source storica; le credenziali saranno rimosse per sicurezza.</p>';
        echo '<form method="post">'; wp_nonce_field('alma_archive_source','alma_archive_source_nonce');
        echo '<input type="hidden" name="action_type" value="archive_source"/><input type="hidden" name="source_id" value="'.intval($source_id).'"/>';
        echo '<p><label><input type="checkbox" name="archive_confirm" value="1" required/> Confermo di archiviare questa Source senza eliminare i link affiliati importati.</label></p>';
        echo '<p><button class="button button-primary">Archivia Source</button></p></form></div>';
    }
    public function register_technical_metabox(){ add_meta_box('alma_affiliate_source_tech',__('Affiliate Source (tecnico)','affiliate-link-manager-ai'),array($this,'render_technical_metabox'),'affiliate_link','side','default'); }
    public function render_technical_metabox($post){ wp_nonce_field('alma_source_meta','alma_source_meta_nonce'); $provider=get_post_meta($post->ID,'_alma_provider',true)?:'manual'; $external_id=get_post_meta($post->ID,'_alma_external_id',true); $import_status=get_post_meta($post->ID,'_alma_import_status',true); $last_sync=get_post_meta($post->ID,'_alma_last_sync_at',true); $ctx=get_post_meta($post->ID,'_alma_ai_context',true); $ctx_updated=get_post_meta($post->ID,'_alma_ai_context_updated_at',true); $ctx_hash=get_post_meta($post->ID,'_alma_ai_context_hash',true); echo '<p><strong>Source:</strong> '.esc_html($provider).'</p>'; echo '<p><strong>External ID:</strong> '.esc_html($external_id?:'—').'</p>'; echo '<p><strong>Stato import:</strong> '.esc_html($import_status?:'—').'</p>'; echo '<p><strong>Ultimo import:</strong> '.esc_html($last_sync?:'—').'</p>'; $this->render_featured_image_diagnostics($post); echo '<hr/><p><strong>Contesto AI</strong><br/><em>Campo interno non pubblicato, usato dall’Agente AI.</em></p>'; echo '<p><textarea readonly class="widefat" rows="6">'.esc_textarea((string)$ctx).'</textarea></p>'; echo '<p><strong>Aggiornato:</strong> '.esc_html($ctx_updated?:'—').'</p>'; echo '<p><strong>Hash:</strong> '.esc_html($ctx_hash?substr($ctx_hash,0,12).'…':'—').'</p>'; echo '<p><button type="button" class="button" disabled>Rigenera contesto AI (prossimamente)</button></p>'; }
    public function save_technical_meta($post_id){ if(!isset($_POST['alma_source_meta_nonce'])||!wp_verify_nonce($_POST['alma_source_meta_nonce'],'alma_source_meta'))return; }
    private function render_featured_image_diagnostics($post){
        $post_id=absint($post->ID); $status=sanitize_key(get_post_meta($post_id,'_alma_featured_image_import_status',true)); $source_url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_source_url',true)); if($source_url==='') $source_url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_url',true)); $attachment_id=absint(get_post_meta($post_id,'_alma_featured_image_attachment_id',true)); if($attachment_id<1) $attachment_id=(int)get_post_thumbnail_id($post_id); $imported_at=get_post_meta($post_id,'_alma_featured_image_imported_at',true); $last_error=get_post_meta($post_id,'_alma_featured_image_last_error',true); $hash=get_post_meta($post_id,'_alma_featured_image_hash',true);
        echo '<hr/><div class="alma-affiliate-image-box"><p><strong>'.esc_html__('Immagine affiliata','affiliate-link-manager-ai').'</strong></p>';
        if(has_post_thumbnail($post_id)){ echo '<p class="alma-affiliate-image-preview">'.get_the_post_thumbnail($post_id,array(120,80)).'</p>'; }
        echo '<p><strong>'.esc_html__('Stato import immagine:','affiliate-link-manager-ai').'</strong> '.esc_html($this->image_status_label($status)).'</p>';
        echo '<p><strong>'.esc_html__('URL sorgente immagine:','affiliate-link-manager-ai').'</strong><br/>'; echo $source_url!==''?'<a href="'.esc_url($source_url).'" target="_blank" rel="noopener noreferrer">'.esc_html(wp_trim_words($source_url,10,'…')).'</a>':'—'; echo '</p>';
        echo '<p><strong>'.esc_html__('Attachment ID:','affiliate-link-manager-ai').'</strong> '.($attachment_id>0?intval($attachment_id):'—').'</p>';
        echo '<p><strong>'.esc_html__('Ultimo import/tentativo:','affiliate-link-manager-ai').'</strong> '.esc_html($imported_at?:'—').'</p>';
        if($last_error!=='') echo '<p><strong>'.esc_html__('Ultimo errore:','affiliate-link-manager-ai').'</strong> '.esc_html(wp_trim_words((string)$last_error,18,'…')).'</p>';
        echo '<p><strong>'.esc_html__('Hash immagine:','affiliate-link-manager-ai').'</strong> '.esc_html($hash?substr((string)$hash,0,16).'…':'—').'</p>';
        if($attachment_id>0 && current_user_can('edit_post',$attachment_id)){ $media_url=get_edit_post_link($attachment_id,''); if($media_url) echo '<p><a class="button button-small" href="'.esc_url($media_url).'">'.esc_html__('Apri immagine in Media Library','affiliate-link-manager-ai').'</a></p>'; }
        if(current_user_can('edit_post',$post_id)){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_retry_affiliate_image_'.$post_id,'alma_retry_affiliate_image_nonce'); echo '<input type="hidden" name="action" value="alma_retry_affiliate_image"/><input type="hidden" name="post_id" value="'.intval($post_id).'"/>';
            echo '<p><label><input type="checkbox" name="overwrite_existing" value="1"/> '.esc_html__('Sovrascrivi l’immagine in evidenza esistente','affiliate-link-manager-ai').'</label></p>';
            echo '<p><button type="submit" class="button">'.esc_html__('Riprova import immagine','affiliate-link-manager-ai').'</button></p>';
            if($source_url==='') echo '<p class="description">'.esc_html__('Il retry richiede _alma_featured_image_source_url oppure _alma_featured_image_url.','affiliate-link-manager-ai').'</p>';
            echo '</form>';
        }
        echo '</div>';
    }

    public function handle_single_image_retry(){
        $post_id=absint($_POST['post_id']??0); if($post_id<1 || !current_user_can('edit_post',$post_id)) wp_die('Unauthorized'); if(!wp_verify_nonce($_POST['alma_retry_affiliate_image_nonce']??'','alma_retry_affiliate_image_'.$post_id)) wp_die('Nonce non valido');
        $result=$this->retry_featured_image_for_post($post_id,!empty($_POST['overwrite_existing'])); $status=is_array($result)?sanitize_key($result['status']??'error'):'error'; $success=(is_array($result)&&!empty($result['success'])) || in_array($status,array('skipped_existing_thumbnail'),true); do_action('alma_affiliate_source_image_admin_event','single_retry_'.($success?'ok':'failed'),array('post_id'=>$post_id,'status'=>$status));
        $redirect=get_edit_post_link($post_id,''); if(!$redirect) $redirect=admin_url('edit.php?post_type=affiliate_link'); wp_safe_redirect(add_query_arg(array('alma_image_retry'=>($success?'success':'error'),'alma_image_status'=>$status),$redirect)); exit;
    }

    public function render_image_retry_notice(){
        if(empty($_GET['alma_image_retry'])) return; $screen=function_exists('get_current_screen')?get_current_screen():null; if(!$screen || $screen->base!=='post') return; $type=sanitize_key($_GET['alma_image_retry']); $status=sanitize_key($_GET['alma_image_status']??''); $class=$type==='success'?'notice notice-success is-dismissible':'notice notice-error is-dismissible'; $msg=$type==='success'?__('Retry immagine completato.','affiliate-link-manager-ai'):__('Retry immagine non riuscito. Controlla lo stato nella metabox.','affiliate-link-manager-ai'); echo '<div class="'.esc_attr($class).'"><p>'.esc_html($msg).' '.esc_html($this->image_status_label($status)).'</p></div>';
    }

    private function retry_featured_image_for_post($post_id,$overwrite=false){
        $post_id=absint($post_id); $url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_source_url',true)); if($url==='') $url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_url',true)); if($url==='') { $svc=new ALMA_Affiliate_Source_Media_Sideload_Service(); return $svc->import_featured_image($post_id,'',array('overwrite_existing'=>false)); }
        $provider=sanitize_key(get_post_meta($post_id,'_alma_provider',true)); $source_id=(string)get_post_meta($post_id,'_alma_source_id',true); $external_id=(string)get_post_meta($post_id,'_alma_external_id',true); do_action('alma_affiliate_source_image_admin_event','single_retry_started',array('post_id'=>$post_id)); $svc=new ALMA_Affiliate_Source_Media_Sideload_Service(); return $svc->import_featured_image($post_id,$url,array('provider'=>$provider,'source_id'=>$source_id,'external_id'=>$external_id,'overwrite_existing'=>(bool)$overwrite,'context'=>array('admin_retry'=>'single')));
    }

    private function render_bulk_image_retry_box($rows){
        $notice=get_transient('alma_bulk_image_retry_'.get_current_user_id()); if(is_array($notice)){ delete_transient('alma_bulk_image_retry_'.get_current_user_id()); echo '<div class="notice notice-success"><p><strong>'.esc_html__('Immagini affiliate:','affiliate-link-manager-ai').'</strong> '.esc_html(sprintf(__('processati %d, importate %d, riutilizzate %d, saltate %d, fallite %d.','affiliate-link-manager-ai'),$notice['processed'],$notice['downloaded'],$notice['reused'],$notice['skipped'],$notice['failed'])).'</p></div>'; }
        echo '<div class="postbox alma-affiliate-images-tools"><h2 class="hndle"><span>'.esc_html__('Immagini affiliate','affiliate-link-manager-ai').'</span></h2><div class="inside"><p>'.esc_html__('Importa in batch le immagini mancanti o fallite per una source specifica. Il batch è limitato per evitare timeout.','affiliate-link-manager-ai').'</p><form method="post">'; wp_nonce_field('alma_bulk_image_retry','alma_bulk_image_retry_nonce'); echo '<input type="hidden" name="action_type" value="affiliate_images_bulk_retry"/><p><label><strong>'.esc_html__('Source','affiliate-link-manager-ai').'</strong><br/><select name="source_id">'; foreach((array)$rows as $r){ if(!empty($r['deleted_at'])) continue; echo '<option value="'.intval($r['id']).'">'.esc_html($r['name'].' (#'.(int)$r['id'].')').'</option>'; } echo '</select></label></p><p><label><strong>'.esc_html__('Limite batch','affiliate-link-manager-ai').'</strong><br/><input type="number" name="limit" value="20" min="1" max="30"/> <span class="description">'.esc_html__('Massimo 30 per richiesta.','affiliate-link-manager-ai').'</span></label></p><p><button class="button button-primary">'.esc_html__('Importa immagini mancanti/fallite','affiliate-link-manager-ai').'</button></p></form></div></div>';
    }

    private function handle_bulk_image_retry(){
        if(!current_user_can('manage_options')) wp_die('Unauthorized'); if(!wp_verify_nonce($_POST['alma_bulk_image_retry_nonce']??'','alma_bulk_image_retry')) wp_die('Nonce non valido'); $source_id=absint($_POST['source_id']??0); $limit=max(1,min(30,absint($_POST['limit']??20))); if($limit>20) $limit=30; do_action('alma_affiliate_source_image_admin_event','bulk_retry_started',array('source_id'=>$source_id,'limit'=>$limit));
        $q=new WP_Query(array('post_type'=>'affiliate_link','post_status'=>array('publish','draft','pending','private'),'fields'=>'ids','posts_per_page'=>$limit,'no_found_rows'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'_alma_source_id','value'=>(string)$source_id,'compare'=>'='),array('key'=>'_thumbnail_id','compare'=>'NOT EXISTS'),array('relation'=>'OR',array('key'=>'_alma_featured_image_source_url','compare'=>'EXISTS'),array('key'=>'_alma_featured_image_url','compare'=>'EXISTS')))));
        $summary=array('processed'=>0,'downloaded'=>0,'reused'=>0,'skipped'=>0,'failed'=>0); foreach((array)$q->posts as $post_id){ $post_id=absint($post_id); if($post_id<1 || has_post_thumbnail($post_id)) continue; $url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_source_url',true)); if($url==='') $url=esc_url_raw((string)get_post_meta($post_id,'_alma_featured_image_url',true)); if($url==='') continue; $summary['processed']++; $res=$this->retry_featured_image_for_post($post_id,false); $st=is_array($res)?sanitize_key($res['status']??''):'failed'; if($st==='downloaded') $summary['downloaded']++; elseif($st==='reused_existing_attachment') $summary['reused']++; elseif(strpos($st,'failed_')===0) $summary['failed']++; else $summary['skipped']++; }
        do_action('alma_affiliate_source_image_admin_event','bulk_retry_completed',array('source_id'=>$source_id,'summary'=>$summary)); set_transient('alma_bulk_image_retry_'.get_current_user_id(),$summary,5*MINUTE_IN_SECONDS); wp_safe_redirect(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'))); exit;
    }

    private function image_status_label($status){ $map=array('downloaded'=>__('Immagine importata','affiliate-link-manager-ai'),'reused_existing_attachment'=>__('Immagine già presente e riutilizzata','affiliate-link-manager-ai'),'skipped_existing_thumbnail'=>__('Immagine già presente: non sovrascritta','affiliate-link-manager-ai'),'skipped_no_url'=>__('Nessun URL immagine disponibile','affiliate-link-manager-ai'),'failed_validation'=>__('URL immagine non valido','affiliate-link-manager-ai'),'failed_download'=>__('Download immagine fallito','affiliate-link-manager-ai'),'failed_attachment'=>__('Creazione allegato fallita','affiliate-link-manager-ai'),'failed_featured_image'=>__('Associazione immagine in evidenza fallita','affiliate-link-manager-ai')); $status=sanitize_key($status); return $map[$status]??($status!==''?$status:__('Non ancora importata','affiliate-link-manager-ai')); }

    private function sources_table_exists(){ global $wpdb; $t=$wpdb->prefix.'alma_affiliate_sources'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t; }

    private function get_gyg_link_type_taxonomy() { return 'link_type'; }

    private function send_gyg_ajax_context_error($error) {
        $code = is_wp_error($error) ? $error->get_error_code() : 'unknown_error';
        $status = $code === 'forbidden' || $code === 'invalid_nonce' ? 403 : 400;
        wp_send_json_error(array('message'=>is_wp_error($error) ? $error->get_error_message() : __('Errore AJAX gyg_csv.', 'affiliate-link-manager-ai'),'code'=>$code), $status);
    }

    private function get_valid_gyg_ajax_context() {
        if (!current_user_can('manage_options')) return new WP_Error('forbidden', __('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        if (!check_ajax_referer('alma_gyg_csv_import_nonce', 'nonce', false)) return new WP_Error('invalid_nonce', __('Verifica di sicurezza non riuscita. Ricarica la pagina e riprova.', 'affiliate-link-manager-ai'));
        global $wpdb;
        $source_id=absint($_POST['source_id']??0); $token=sanitize_key($_POST['token']??''); $type=sanitize_text_field(wp_unslash($_POST['activity_type']??''));
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        if(!is_array($source) || sanitize_key($source['provider_preset']??'')!=='gyg_csv') return new WP_Error('invalid_source',__('Source gyg_csv non valida.', 'affiliate-link-manager-ai'));
        $svc=new ALMA_Affiliate_Source_GYG_CSV_Importer(); $session=$svc->get_session($token,$source_id); if(is_wp_error($session)) return $session;
        $headers=$svc->get_headers($session['path']); if(is_wp_error($headers)) return $headers;
        $det=$svc->detect_columns($headers); if(empty($det['valid'])) return new WP_Error('missing_columns',__('Colonne obbligatorie mancanti nel CSV.', 'affiliate-link-manager-ai'));
        return array('source_id'=>$source_id,'token'=>$token,'activity_type'=>$type,'source'=>$source,'svc'=>$svc,'session'=>$session,'columns'=>$det['columns']);
    }

    public function ajax_gyg_csv_prepare_import(){
        $ctx=$this->get_valid_gyg_ajax_context(); if(is_wp_error($ctx)) $this->send_gyg_ajax_context_error($ctx);
        $settings=ALMA_Affiliate_Source_GYG_CSV_Importer::default_settings($this->decode_db_json($ctx['source']['settings']??'{}'));
        $taxonomy=$this->get_gyg_link_type_taxonomy();
        if(!taxonomy_exists($taxonomy)) wp_send_json_error(array('message'=>__('Impossibile caricare le Tipologie Link Sothra. Controlla che la tassonomia delle tipologie esista e ricarica la pagina.', 'affiliate-link-manager-ai'),'code'=>'missing_taxonomy'),500);
        $terms=get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false)); if(is_wp_error($terms)) wp_send_json_error(array('message'=>__('Impossibile caricare le Tipologie Link Sothra. Controlla che la tassonomia delle tipologie esista e ricarica la pagina.', 'affiliate-link-manager-ai'),'code'=>$terms->get_error_code()),500); if(!is_array($terms)) $terms=array();
        $term_data=array(); foreach($terms as $t){ $term_data[]=array('id'=>(int)$t->term_id,'name'=>$t->name); }
        $mapped=ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids(($settings['type_mappings'][$ctx['activity_type']]??array()));
        $counts=$ctx['svc']->count_existing_for_type($ctx['session']['path'],$ctx['columns'],$ctx['activity_type'],$ctx['source']);
        if (empty($counts['total'])) wp_send_json_error(array('message'=>__('Tipologia attività CSV non valida o non presente nella sessione.', 'affiliate-link-manager-ai'),'code'=>'invalid_activity_type'),400);
        $preview=$ctx['svc']->preview($ctx['session']['path'],$ctx['columns'],$ctx['activity_type'],$ctx['source'],array('show_existing'=>true,'limit'=>10));
        $preview=array_map(function($it){ return array('original_url'=>$it['original_url'],'affiliate_url'=>$it['affiliate_url'],'city'=>$it['city'],'region'=>$it['region'],'description'=>function_exists('mb_substr')?mb_substr($it['description'],0,140):substr($it['description'],0,140),'status'=>$it['post_id']>0?'già importato':'nuovo'); }, $preview);
        wp_send_json_success(array('activity_type'=>$ctx['activity_type'],'source_id'=>$ctx['source_id'],'token'=>$ctx['token'],'source_name'=>$ctx['source']['name']??'','source_active'=>!empty($ctx['source']['is_active']),'partner_id'=>$settings['partner_id']??'','utm_medium'=>$settings['utm_medium']??'online_publisher','counts'=>$counts,'terms'=>$term_data,'mapped_term_ids'=>$mapped,'preview'=>$preview,'max_quantity'=>ALMA_Affiliate_Source_GYG_CSV_Importer::MAX_IMPORT_QUANTITY));
    }

    public function ajax_gyg_csv_import_batch(){
        $ctx=$this->get_valid_gyg_ajax_context(); if(is_wp_error($ctx)) $this->send_gyg_ajax_context_error($ctx);
        $taxonomy=$this->get_gyg_link_type_taxonomy();
        $quantity=max(1,min(ALMA_Affiliate_Source_GYG_CSV_Importer::MAX_IMPORT_QUANTITY,absint($_POST['quantity']??100)));
        $cursor=max(0,absint($_POST['cursor']??0)); $update_existing=!empty($_POST['update_existing']);
        $term_ids=ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids($_POST['term_ids']??array());
        if(empty($term_ids)) wp_send_json_error(array('message'=>__('Seleziona almeno una Tipologia Link Sothra.', 'affiliate-link-manager-ai'),'code'=>'missing_terms'),400);
        foreach($term_ids as $tid){ $term=get_term($tid,$taxonomy); if(!$term||is_wp_error($term)) wp_send_json_error(array('message'=>__('Tipologia Sothra non valida.', 'affiliate-link-manager-ai'),'code'=>'invalid_term'),400); }
        $counts=$ctx['svc']->count_existing_for_type($ctx['session']['path'],$ctx['columns'],$ctx['activity_type'],$ctx['source']);
        if (empty($counts['total'])) wp_send_json_error(array('message'=>__('Tipologia attività CSV non valida o non presente nella sessione.', 'affiliate-link-manager-ai'),'code'=>'invalid_activity_type'),400);
        global $wpdb; $settings=ALMA_Affiliate_Source_GYG_CSV_Importer::default_settings($this->decode_db_json($ctx['source']['settings']??'{}')); $settings['type_mappings'][$ctx['activity_type']]=$term_ids; $wpdb->update("{$wpdb->prefix}alma_affiliate_sources",array('settings'=>wp_json_encode($settings),'updated_at'=>current_time('mysql')),array('id'=>$ctx['source_id']));
        $result=$ctx['svc']->import_batch($ctx['session']['path'],$ctx['columns'],$ctx['activity_type'],$ctx['source'],$term_ids,$quantity,$cursor,$update_existing);
        if(is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message(),'code'=>$result->get_error_code()),400);
        $names=array(); foreach($term_ids as $tid){ $term=get_term($tid,$taxonomy); if($term&&!is_wp_error($term)) $names[]=$term->name; }
        $result['mapping_label']=implode(', ',$names); $result['max_quantity']=ALMA_Affiliate_Source_GYG_CSV_Importer::MAX_IMPORT_QUANTITY;
        wp_send_json_success($result);
    }

    public function ajax_test_source_connection(){
        if(!current_user_can('manage_options')) wp_send_json_error(array('message'=>'Non autorizzato'),403);
        check_ajax_referer('alma_test_connection_nonce','nonce');
        $source_id = absint($_POST['source_id'] ?? 0);
        if($source_id<=0) wp_send_json_error(array('message'=>'Source non valida'));
        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        if(!is_array($source)) wp_send_json_error(array('message'=>'Source non trovata'));
        if(!empty($source['deleted_at'])) wp_send_json_error(array('message'=>'Source eliminata: ripristinala prima di testare la connessione'));
        if(empty($source['provider']) && empty($source['provider_preset'])) wp_send_json_error(array('message'=>'Provider o preset non valido'));
        $service = new ALMA_Affiliate_Source_Connection_Service();
        $result = $service->test($source);
        if(is_wp_error($result)) wp_send_json_error(array('message'=>$this->map_error($result->get_error_code(),$result->get_error_message())));
        wp_send_json_success(array('message'=>'Connessione riuscita'));
    }
    private function map_error($code,$fallback){
        $map=array('missing_credentials'=>'Credenziali/token mancanti','missing_endpoint'=>'Endpoint mancante','invalid_endpoint'=>'Endpoint non valido','provider_unsupported'=>'Provider non ancora supportato','invalid_environment'=>'Environment non valido','invalid_api_version'=>'Versione API non valida','unauthorized'=>'Credenziale non autorizzata','forbidden'=>'Accesso negato','rate_limited'=>'Rate limit raggiunto','timeout'=>'Timeout','api_error'=>'Errore API','invalid_json'=>'Risposta API non valida','missing_minimum_criteria'=>'Parametri minimi mancanti per discovery','missing_destination_id'=>'Destination ID mancante','missing_search_term'=>'Search term mancante','invalid_environment'=>'Environment non valido','invalid_api_version'=>'Versione API non valida','unauthorized'=>'Credenziale non autorizzata','forbidden'=>'Credenziale senza permessi','rate_limited'=>'Limite richieste raggiunto','empty_response'=>'Risposta vuota','invalid_json'=>'Risposta API non valida','response_too_large'=>'Risposta troppo grande','catalog_unavailable'=>'Catalogo campi documentato non disponibile','internal_error'=>'Errore interno');
        return $map[$code] ?? sanitize_text_field($fallback ?: 'Errore interno');
    }
    public function render_importable_fields_page(){
        if(!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb; $source_id=absint($_GET['source_id']??0); if($source_id<=0) wp_die('Source non valida');
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A); if(!is_array($source)) wp_die('Source non trovata'); if(!empty($source['deleted_at'])){ echo '<div class="wrap"><h1>Campi importabili</h1><div class="notice notice-warning"><p>Source eliminata: ripristinala prima di eseguire discovery.</p></div></div>'; return; }
        $force = isset($_GET['refresh']) && $_GET['refresh']==='1';
        $svc=new ALMA_Affiliate_Source_Field_Discovery_Service(); $result=$svc->discover($source,$force);
        $discovery_endpoint='n/d'; $generated_at='n/d'; $fields=array(); $discovery_info=''; $discovery_error='';
        if(is_array($result)){
            $discovery_endpoint = (string)($result['endpoint'] ?? 'n/d');
            $generated_at = (string)($result['generated_at'] ?? 'n/d');
            $fields = is_array($result['fields'] ?? null) ? $result['fields'] : array();
            $discovery_info = (string)($result['message'] ?? '');
        }elseif(is_wp_error($result)){
            $discovery_error = $this->map_error($result->get_error_code(),$result->get_error_message());
        }else{
            $discovery_error = $this->map_error('internal_error','Risultato discovery non valido.');
        }
        $storage = new ALMA_Affiliate_Source_Connection_Test_Storage(); $storage->maybe_migrate_legacy((int)$source_id); $last_test=$storage->get((int)$source_id);
        echo '<div class="wrap"><h1>Campi importabili</h1><p><a class="button" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'))).'">Torna alle Affiliate Sources</a> <a class="button button-primary" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-importable-fields','source_id'=>$source_id,'refresh'=>'1'),admin_url('edit.php'))).'">Aggiorna campi</a></p>';
        echo '<ul><li><strong>Source:</strong> '.esc_html($source['name']).'</li><li><strong>Provider:</strong> '.esc_html($source['provider_label']).'</li><li><strong>Preset:</strong> '.esc_html(($source['provider_preset'] ?? '') !== '' ? $source['provider_preset'] : (($source['provider'] ?? '') !== '' ? $source['provider'] : '—')).'</li><li><strong>Stato:</strong> '.(((int)$source['is_active'])?'Attivo':'Disattivo').'</li><li><strong>Endpoint discovery:</strong> '.esc_html($discovery_endpoint).'</li><li><strong>Ultimo aggiornamento:</strong> '.esc_html($generated_at).'</li><li><strong>Ultimo test connessione:</strong> '.esc_html(is_array($last_test)?(($last_test['tested_at']??'n/d').' ('.(($last_test['status']??'')==='success'?'ok':'ko').')'):'n/d').'</li></ul>';
        $catalog_provider = sanitize_key(($source['provider_preset'] ?? '') !== '' ? $source['provider_preset'] : ($source['provider'] ?? ''));
        if($catalog_provider==='viator'){
            echo '<div class="notice notice-info"><p><strong>Cos’è il Destination ID Viator:</strong> Destination ID mancante significa che il modello products_search richiede un ID destinazione Viator. Non è una categoria WordPress e non è il nome della città. È un identificativo numerico Viator recuperabile dall’endpoint /destinations.</p></div>';
            echo '<div class="notice notice-info"><p><strong>Recensioni e contenuti protetti:</strong> Le recensioni sono mostrate per ora solo come campi disponibili. Il plugin può leggere dati aggregati come numero recensioni e voto medio. La pubblicazione dei testi recensione richiede regole compliance specifiche Viator, quindi non viene implementata in questa fase.</p></div>';
            echo '<div class="notice notice-warning"><p><strong>Booking e pagamenti non implementati:</strong> Viator supporta booking, checkout, pagamenti e cancellazioni solo per account con livello Full Affiliate + Booking o Merchant. Questa release gestisce il flusso affiliate base: import prodotto e redirect tramite productUrl. Le funzioni transazionali possono essere implementate in futuro, ma richiedono gestione disponibilità, hold, pagamento, stato prenotazione, voucher, cancellazioni e customer support.</p></div>';
        } elseif($catalog_provider==='getyourguide'){
            echo '<div class="notice notice-info"><p><strong>GetYourGuide Partner API:</strong> la discovery usa /1/tours con X-ACCESS-TOKEN, cnt_language e currency. Il livello Basic/Teaser, Reading o Booking può influire sui campi disponibili.</p></div>';
            echo '<div class="notice notice-warning"><p><strong>Booking e checkout non implementati:</strong> questa integrazione importa contenuti e URL affiliati diretti; non usa endpoint booking, cart, availability o purchase flow.</p></div>';
        }
        echo '<div class="notice notice-info"><p><strong>Campi rilevati vs campi documentati:</strong> La tabella “rilevati” dipende dal campione API corrente; la tabella “documentati” mostra il catalogo provider anche quando alcuni campi non compaiono nel campione.</p></div>';
        if($discovery_error!==''){ echo '<div class="notice notice-warning"><p>'.esc_html($discovery_error).'</p></div>'; }
        if($discovery_info!==''){ echo '<div class="notice notice-info"><p>'.esc_html($discovery_info).'</p></div>'; }
        $catalog=array();
        $catalog_label = $catalog_provider === 'getyourguide' ? 'GetYourGuide' : 'Viator';
        if($catalog_provider==='getyourguide' && class_exists('ALMA_Affiliate_Source_GetYourGuide_Field_Catalog') && is_callable(array('ALMA_Affiliate_Source_GetYourGuide_Field_Catalog','get_catalog'))){
            $catalog = ALMA_Affiliate_Source_GetYourGuide_Field_Catalog::get_catalog();
            if(!is_array($catalog)) $catalog=array();
        } elseif(class_exists('ALMA_Affiliate_Source_Viator_Field_Catalog') && is_callable(array('ALMA_Affiliate_Source_Viator_Field_Catalog','get_catalog'))){
            $catalog = ALMA_Affiliate_Source_Viator_Field_Catalog::get_catalog();
            if(!is_array($catalog)) $catalog=array();
        } else {
            echo '<div class="notice notice-warning"><p>'.$this->map_error('catalog_unavailable','').'</p></div>';
        }
        $detected_paths = array(); foreach($fields as $f){ if(!is_array($f)) continue; $path=(string)($f['path']??''); if($path!==''){$detected_paths[$path]=true;} }
        echo '<h2>Campi rilevati nel campione API</h2><table class="widefat striped"><thead><tr><th>Campo/path</th><th>Gruppo</th><th>Endpoint/origine</th><th>Tipo</th><th>Descrizione</th><th>Esempio</th><th>Mapping</th><th>Stato</th></tr></thead><tbody>';
        foreach($fields as $f){ if(!is_array($f)) continue; echo '<tr><td>'.esc_html($f['path']??'').'</td><td>'.esc_html($f['group']??'Campione API').'</td><td>'.esc_html($f['origin']??$discovery_endpoint).'</td><td>'.esc_html($f['type']??'').'</td><td>'.esc_html($f['description']??'Campo rilevato dal payload API.').'</td><td>'.esc_html($f['example']??'—').'</td><td>'.esc_html($f['mapping_hint']??'—').'</td><td>rilevato nel campione</td></tr>'; }
        if(empty($fields)){ echo '<tr><td colspan=\"8\">Nessun campo rilevato dal campione API. Verifica i criteri di ricerca oppure consulta il catalogo documentato.</td></tr>'; }
        echo '</tbody></table>';
        echo '<h2>Catalogo campi '.esc_html($catalog_label).' documentati</h2><table class="widefat striped"><thead><tr><th>Campo/path</th><th>Gruppo</th><th>Endpoint/origine</th><th>Tipo</th><th>Descrizione</th><th>Esempio</th><th>Mapping</th><th>Stato</th></tr></thead><tbody>';
        foreach((array)$catalog as $c){ if(!is_array($c)) continue; $path=(string)($c['path']??''); $status = !empty($detected_paths[$path]) ? 'rilevato nel campione' : (((string)($c['status']??'')==='protected')?'protetto/compliance':'documentato ma non rilevato'); $desc=(string)($c['description']??('Campo documentato '.$catalog_label.'.')); $note=(string)($c['compliance_note']??''); echo '<tr><td>'.esc_html($path).'</td><td>'.esc_html($c['group']??$catalog_label).'</td><td>'.esc_html($c['endpoint']??'').'</td><td>'.esc_html($c['type']??'').'</td><td>'.esc_html($desc).($note!==''?' — '.esc_html($note):'').'</td><td>'.esc_html($c['example']??'—').'</td><td>'.esc_html($c['mapping_hint']??'—').'</td><td>'.esc_html($status).'</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function maybe_ensure_sources_table(){ global $wpdb; if(!$this->sources_table_exists()){ self::create_tables(); } if($this->sources_table_exists()){ $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}alma_affiliate_sources"); if(!in_array('provider_label',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD provider_label varchar(191) DEFAULT ''");}
        if(!in_array('provider_preset',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD provider_preset varchar(50) DEFAULT ''");}
        if(!in_array('destination_term_ids',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD destination_term_ids longtext NULL");}
        if(!in_array('deleted_at',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD deleted_at datetime NULL");}
        if(!in_array('deleted_by',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD deleted_by bigint(20) unsigned DEFAULT 0");}
        $wpdb->query("UPDATE {$wpdb->prefix}alma_affiliate_sources SET provider_label = provider WHERE provider_label = ''");
        $rows=$wpdb->get_results("SELECT id,destination_term_id,destination_term_ids FROM {$wpdb->prefix}alma_affiliate_sources",ARRAY_A); foreach((array)$rows as $r){ if(empty($r['destination_term_ids'])&&!empty($r['destination_term_id']))$wpdb->update("{$wpdb->prefix}alma_affiliate_sources",array('destination_term_ids'=>wp_json_encode(array((int)$r['destination_term_id']))),array('id'=>(int)$r['id'])); }
    }}
}
