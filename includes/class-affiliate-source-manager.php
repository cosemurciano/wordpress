<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Manager {
    private $registry; private $table_error = '';
    public function __construct() {
        $this->registry = new ALMA_Affiliate_Source_Provider_Registry();
        $this->registry->bootstrap_native_providers();
        add_action('admin_menu', array($this, 'register_submenu'), 11);
        add_action('add_meta_boxes', array($this, 'register_technical_metabox'));
        add_action('save_post_affiliate_link', array($this, 'save_technical_meta'));
        add_action('wp_ajax_alma_test_source_connection', array($this, 'ajax_test_source_connection'));
    }
    public static function create_tables() { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_affiliate_sources (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL, provider varchar(50) NOT NULL, provider_preset varchar(50) DEFAULT '', provider_label varchar(191) DEFAULT '', is_active tinyint(1) NOT NULL DEFAULT 1, language varchar(20) DEFAULT '', market varchar(20) DEFAULT '', destination_term_id bigint(20) unsigned DEFAULT 0, destination_term_ids longtext NULL, import_mode varchar(30) DEFAULT 'create_update', settings longtext NULL, credentials longtext NULL, last_sync_at datetime NULL, last_sync_status varchar(20) DEFAULT 'manual', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id)) $c;");
    }
    public function get_provider_presets(){ return ALMA_Affiliate_Source_Provider_Presets::get_schema(); }
    public function register_submenu(){ add_submenu_page('edit.php?post_type=affiliate_link',__('Affiliate Sources','affiliate-link-manager-ai'),__('Affiliate Sources','affiliate-link-manager-ai'),'manage_options','alma-affiliate-sources',array($this,'render_sources_page')); add_submenu_page(null,__('Campi importabili','affiliate-link-manager-ai'),__('Campi importabili','affiliate-link-manager-ai'),'manage_options','alma-importable-fields',array($this,'render_importable_fields_page')); }
    private function parse_json($raw){ $raw=trim((string)$raw); if($raw==='') return array(); $d=json_decode(wp_unslash($raw),true); return is_array($d)?$d:array('__invalid'=>1); }
    private function decode_db_json($raw){ $d=json_decode((string)$raw,true); return is_array($d)?$d:array(); }
    private function norm_provider($label){ $k=sanitize_key($label); return $k!==''?$k:'custom'; }
    private function render_field($name,$label,$value,$required=false,$secret=false,$exists=false){ $type=$secret?'password':'text'; $input_value=$secret?'':(string)$value; $ph=$secret&&$exists?'già salvato':''; echo '<p><label><strong>'.esc_html($label).($required?' *':'').'</strong><br/><input type="'.$type.'" name="'.esc_attr($name).'" value="'.esc_attr($input_value).'" placeholder="'.esc_attr($ph).'" class="regular-text" autocomplete="off"></label></p>'; }
    public function render_sources_page(){ if(!current_user_can('manage_options')) wp_die('Unauthorized'); $this->maybe_ensure_sources_table(); $this->maybe_handle_source_form(); settings_errors('alma_source'); global $wpdb; $terms=get_terms(array('taxonomy'=>'link_type','hide_empty'=>false)); if(is_wp_error($terms)||!is_array($terms))$terms=array(); $presets=$this->get_provider_presets();
        $view=sanitize_key($_GET['alma_view']??'');
        if($view==='save_confirmation'){ $this->render_post_save_confirmation(); return; }
        $editing_id=absint($_GET['edit_source']??0); $editing=$editing_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$editing_id),ARRAY_A):array(); if(!is_array($editing))$editing=array(); $rows=$this->sources_table_exists()?$wpdb->get_results("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources ORDER BY id DESC LIMIT 100",ARRAY_A):array();
        $es=$this->decode_db_json($editing['settings']??''); $ec=$this->decode_db_json($editing['credentials']??''); $sel=json_decode($editing['destination_term_ids']??'',true); if(!is_array($sel))$sel=array(); if(empty($sel)&&!empty($editing['destination_term_id']))$sel=array((int)$editing['destination_term_id']);
        $result=sanitize_key($_GET['alma_result']??'');
        if($result==='created'){ echo '<div class="notice notice-success is-dismissible"><p>Source creata correttamente.</p></div>'; }
        elseif($result==='updated'){ echo '<div class="notice notice-success is-dismissible"><p>Source aggiornata correttamente.</p></div>'; }
        elseif($result==='error'){ echo '<div class="notice notice-error is-dismissible"><p>Errore nel salvataggio della source.</p></div>'; }
        elseif($result==='invalid_json'){ echo '<div class="notice notice-error is-dismissible"><p>JSON avanzato non valido.</p></div>'; }
        echo '<div class="wrap"><h1>Affiliate Sources</h1><button type="button" class="button button-primary alma-toggle-source-form">Aggiungi nuova source</button><div id="alma-source-form-wrap"'.($editing?'':' style="display:none"').'><h2>'.($editing?'Modifica source':'Nuova source').'</h2><form method="post" id="alma-source-form">'; wp_nonce_field('alma_save_source','alma_source_nonce');
        echo '<input type="hidden" name="action_type" value="save_source"/><input type="hidden" name="source_id" value="'.esc_attr($editing['id']??0).'"/><input type="hidden" id="alma-existing-settings" value="'.esc_attr(wp_json_encode($es)).'"/><input type="hidden" id="alma-existing-credentials-flags" value="'.esc_attr(wp_json_encode(array_fill_keys(array_keys($ec), true))).'"/><div class="alma-sections">';
        echo '<div class="alma-section"><h3>Dati source</h3>'; $this->render_input_row('name','Name',$editing['name']??''); $this->render_input_row('provider_label','Provider',$editing['provider_label']??($editing['provider']??''),true);
        echo '<p><label><input type="checkbox" name="is_active" value="1" '.checked(isset($editing['is_active'])?(int)$editing['is_active']:1,1,false).'/> Source attiva</label></p>';
        echo '<p><label>Preset provider<br/><select name="provider_preset" id="provider_preset"><option value="">—</option>'; foreach($presets as $k=>$p){ echo '<option value="'.esc_attr($k).'"'.selected($k,$editing['provider_preset']??'',false).'>'.esc_html($p['label']).'</option>'; } echo '</select></label></p>';
        echo '</div><div class="alma-section"><h3>Destination terms</h3><select multiple name="destination_term_ids[]" id="destination_term_ids" size="6">'; foreach($terms as $t){ echo '<option value="'.intval($t->term_id).'"'.(in_array((int)$t->term_id,$sel,true)?' selected':'').'>'.esc_html($t->name).'</option>'; } echo '</select></div>';
        echo '<div class="alma-section"><h3>Configurazione provider</h3><div id="alma-guided-settings"></div></div><div class="alma-section"><h3>Credenziali</h3><div id="alma-guided-credentials"></div>';
        foreach(array('api_key','access_token','bearer_token','affiliate_id','site_id','client_id','client_secret','username','password','marker','partner_id','referral_url','tracking_code','xml_api_key','xml_username','xml_password') as $f){ $this->render_field('credentials_extra_fields['.$f.']',$f,'',false,true,!empty($ec[$f])); }
        echo '</div><div class="alma-section"><h3>Credenziali avanzate (opzionale)</h3><p class="description">Inserisci solo chiavi extra non coperte dai campi guidati.</p><textarea name="credentials_advanced" id="credentials_advanced" rows="4" class="large-text code"></textarea></div></div><p><button class="button button-primary">Salva source</button></p></form></div>';
        echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Provider</th><th>Destination</th><th>Mode</th><th>Stato</th><th>Lingua</th><th>Mercato</th><th>Ultimo Sync</th><th>Stato Sync</th><th>Azioni</th></tr></thead><tbody>';
        foreach((array)$rows as $r){ $s=$this->decode_db_json($r['settings']??''); $ids=json_decode($r['destination_term_ids']??'',true); if(!is_array($ids))$ids=array(); if(empty($ids)&&!empty($r['destination_term_id']))$ids=array((int)$r['destination_term_id']); $names=array(); foreach($ids as $tid){ $term=get_term((int)$tid,'link_type'); if($term&&!is_wp_error($term))$names[]=$term->name; }
            $provider_label=$r['provider_label']?:$r['provider']; $edit_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','edit_source'=>(int)$r['id']),admin_url('edit.php')); $fields_link=add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-importable-fields','source_id'=>(int)$r['id']),admin_url('edit.php'));
            echo '<tr data-source-id="'.(int)$r['id'].'"><td>'.esc_html($r['name']).'</td><td>'.esc_html($provider_label).'<br/><small>'.esc_html($r['provider']).'</small></td><td>'.esc_html($names?implode(', ',$names):'—').'</td><td>'.esc_html($s['mode']??$s['integration_mode']??'—').'</td><td>'.((int)$r['is_active']===1?'Attivo':'Disattivo').'</td><td>'.esc_html($r['language']).'</td><td>'.esc_html($r['market']).'</td><td>'.esc_html($r['last_sync_at']).'</td><td>'.esc_html($r['last_sync_status']).'</td><td><a class="button button-small" href="'.esc_url($edit_link).'">Modifica</a> <button type="button" class="button button-small alma-test-connection" data-source-id="'.(int)$r['id'].'">Testa connessione</button> <a class="button button-small" href="'.esc_url($fields_link).'">Campi importabili</a><div class="alma-inline-result" aria-live="polite"></div></td></tr>'; }
        echo '</tbody></table></div>'; }
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
            $settings[sanitize_key($k)]=sanitize_text_field(wp_unslash($v));
        }

        $credentials_existing=$this->decode_db_json($existing['credentials']??''); $credentials=$credentials_existing; foreach((array)($_POST['credentials_fields']??array()) as $k=>$v){ $k=sanitize_key($k); $v=sanitize_text_field(wp_unslash($v)); if($v!=='')$credentials[$k]=$v; }
        foreach((array)($_POST['credentials_extra_fields']??array()) as $k=>$v){ $k=sanitize_key($k); $v=sanitize_text_field(wp_unslash($v)); if($v!=='')$credentials[$k]=$v; }
        foreach($ca as $k=>$v){ if($v!==''&&$v!==null){ $credentials[sanitize_key($k)]=is_scalar($v)?sanitize_text_field((string)$v):wp_json_encode($v); } }
        $term_ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['destination_term_ids']??array())))));
        $data=array('name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'provider'=>$provider,'provider_preset'=>$provider_preset,'provider_label'=>$provider_label,'is_active'=>isset($_POST['is_active'])?1:0,'language'=>sanitize_text_field(wp_unslash($_POST['language']??'')),'market'=>sanitize_text_field(wp_unslash($_POST['market']??'')),'import_mode'=>sanitize_key($_POST['import_mode']??'create_update'),'destination_term_id'=>(int)($term_ids[0]??0),'destination_term_ids'=>!empty($term_ids)?wp_json_encode($term_ids):null,'settings'=>wp_json_encode($settings),'credentials'=>wp_json_encode($credentials),'updated_at'=>current_time('mysql'));
        $ok=false; $result='error';
        if($source_id>0){ $ok=$wpdb->update("{$wpdb->prefix}alma_affiliate_sources",$data,array('id'=>$source_id)); $result=($ok!==false)?'updated':'error'; }
        else { $data['created_at']=current_time('mysql'); $ok=$wpdb->insert("{$wpdb->prefix}alma_affiliate_sources",$data); $result=($ok!==false)?'created':'error'; if($ok!==false){ $source_id=(int)$wpdb->insert_id; } }
        $redirect=array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources','alma_view'=>'save_confirmation','status'=>$result);
        if($source_id>0){ $redirect['source_id']=$source_id; }
        wp_safe_redirect(add_query_arg($redirect,admin_url('edit.php'))); exit; }

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
    public function register_technical_metabox(){ add_meta_box('alma_affiliate_source_tech',__('Affiliate Source (tecnico)','affiliate-link-manager-ai'),array($this,'render_technical_metabox'),'affiliate_link','side','default'); }
    public function render_technical_metabox($post){ $provider=get_post_meta($post->ID,'_alma_provider',true)?:'manual'; echo '<p><strong>Provider:</strong> '.esc_html($provider).'</p>'; }
    public function save_technical_meta($post_id){ if(!isset($_POST['alma_source_meta_nonce'])||!wp_verify_nonce($_POST['alma_source_meta_nonce'],'alma_source_meta'))return; }
    private function sources_table_exists(){ global $wpdb; $t=$wpdb->prefix.'alma_affiliate_sources'; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t; }

    public function ajax_test_source_connection(){
        if(!current_user_can('manage_options')) wp_send_json_error(array('message'=>'Non autorizzato'),403);
        check_ajax_referer('alma_test_connection_nonce','nonce');
        $source_id = absint($_POST['source_id'] ?? 0);
        if($source_id<=0) wp_send_json_error(array('message'=>'Source non valida'));
        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A);
        if(!is_array($source)) wp_send_json_error(array('message'=>'Source non trovata'));
        if(empty($source['provider']) && empty($source['provider_preset'])) wp_send_json_error(array('message'=>'Provider o preset non valido'));
        $service = new ALMA_Affiliate_Source_Connection_Service();
        $result = $service->test($source);
        if(is_wp_error($result)) wp_send_json_error(array('message'=>$this->map_error($result->get_error_code(),$result->get_error_message())));
        wp_send_json_success(array('message'=>'Connessione riuscita'));
    }
    private function map_error($code,$fallback){
        $map=array('missing_credentials'=>'Credenziali mancanti','missing_endpoint'=>'Endpoint mancante','invalid_endpoint'=>'Endpoint non valido','provider_unsupported'=>'Provider non ancora supportato','timeout'=>'Timeout','api_error'=>'Errore API','internal_error'=>'Errore interno');
        return $map[$code] ?? sanitize_text_field($fallback ?: 'Errore interno');
    }
    public function render_importable_fields_page(){
        if(!current_user_can('manage_options')) wp_die('Unauthorized');
        global $wpdb; $source_id=absint($_GET['source_id']??0); if($source_id<=0) wp_die('Source non valida');
        $source=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d",$source_id),ARRAY_A); if(!is_array($source)) wp_die('Source non trovata');
        $force = isset($_GET['refresh']) && $_GET['refresh']==='1';
        $svc=new ALMA_Affiliate_Source_Field_Discovery_Service(); $result=$svc->discover($source,$force);
        $storage = new ALMA_Affiliate_Source_Connection_Test_Storage(); $storage->maybe_migrate_legacy((int)$source_id); $last_test=$storage->get((int)$source_id);
        echo '<div class="wrap"><h1>Campi importabili</h1><p><a class="button" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-affiliate-sources'),admin_url('edit.php'))).'">Torna alle Affiliate Sources</a> <a class="button button-primary" href="'.esc_url(add_query_arg(array('post_type'=>'affiliate_link','page'=>'alma-importable-fields','source_id'=>$source_id,'refresh'=>'1'),admin_url('edit.php'))).'">Aggiorna campi</a></p>';
        echo '<ul><li><strong>Source:</strong> '.esc_html($source['name']).'</li><li><strong>Provider:</strong> '.esc_html($source['provider_label']).'</li><li><strong>Preset:</strong> '.esc_html(($source['provider_preset'] ?? '') !== '' ? $source['provider_preset'] : (($source['provider'] ?? '') !== '' ? $source['provider'] : '—')).'</li><li><strong>Stato:</strong> '.(((int)$source['is_active'])?'Attivo':'Disattivo').'</li><li><strong>Ultimo test connessione:</strong> '.esc_html(is_array($last_test)?(($last_test['tested_at']??'n/d').' ('.(($last_test['status']??'')==='success'?'ok':'ko').')'):'n/d').'</li></ul>';
        if(is_wp_error($result)){ echo '<div class="notice notice-warning"><p>'.esc_html($this->map_error($result->get_error_code(),$result->get_error_message())).'</p></div></div>'; return; }
        $fields=(array)($result['fields']??array()); if(empty($fields)){ echo '<p>Nessun campo rilevato.</p></div>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Path</th><th>Label</th><th>Tipo</th><th>Esempio</th><th>Origine</th><th>Suggerimento mapping</th></tr></thead><tbody>';
        foreach($fields as $f){ echo '<tr><td>'.esc_html($f['path']??'').'</td><td>'.esc_html($f['label']??'').'</td><td>'.esc_html($f['type']??'').'</td><td>'.esc_html($f['example']??'').'</td><td>'.esc_html($result['origin']??'').'</td><td>'.esc_html($f['mapping_hint']??'—').'</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function maybe_ensure_sources_table(){ global $wpdb; if(!$this->sources_table_exists()){ self::create_tables(); } if($this->sources_table_exists()){ $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}alma_affiliate_sources"); if(!in_array('provider_label',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD provider_label varchar(191) DEFAULT ''");}
        if(!in_array('provider_preset',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD provider_preset varchar(50) DEFAULT ''");}
        if(!in_array('destination_term_ids',$cols,true)){$wpdb->query("ALTER TABLE {$wpdb->prefix}alma_affiliate_sources ADD destination_term_ids longtext NULL");}
        $wpdb->query("UPDATE {$wpdb->prefix}alma_affiliate_sources SET provider_label = provider WHERE provider_label = ''");
        $rows=$wpdb->get_results("SELECT id,destination_term_id,destination_term_ids FROM {$wpdb->prefix}alma_affiliate_sources",ARRAY_A); foreach((array)$rows as $r){ if(empty($r['destination_term_ids'])&&!empty($r['destination_term_id']))$wpdb->update("{$wpdb->prefix}alma_affiliate_sources",array('destination_term_ids'=>wp_json_encode(array((int)$r['destination_term_id']))),array('id'=>(int)$r['id'])); }
    }}
}
