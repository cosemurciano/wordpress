<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    public static function init() { add_action('admin_post_alma_ai_agent_action', array(__CLASS__,'handle_action')); }
    public static function handle_action(){ if(!current_user_can('manage_options')) wp_die('forbidden'); check_admin_referer('alma_ai_agent_action'); $do=sanitize_key($_POST['do']??'');
        if($do==='save_source'){ ALMA_AI_Content_Agent_Source_Manager::save_source($_POST); }
        if($do==='reindex_knowledge'){ ALMA_AI_Content_Agent_Knowledge_Indexer::reindex_batch(); }
        if($do==='reindex_media'){ ALMA_AI_Content_Agent_Media_Indexer::reindex_batch(); }
        if($do==='save_note'){ ALMA_AI_Content_Agent_Document_Manager::save_manual_note($_POST); }
        if($do==='index_document' && !empty($_POST['attachment_id'])){ ALMA_AI_Content_Agent_Document_Manager::index_attachment((int)$_POST['attachment_id'], sanitize_textarea_field($_POST['manual_text']??''), sanitize_key($_POST['usage_mode']??'knowledge')); }
        wp_safe_redirect(wp_get_referer()); exit;
    }

    public static function render_page(){ global $wpdb; if(!current_user_can('manage_options')) return;
        $tabs=array('overview'=>'Overview','documenti'=>'Documenti','fonti'=>'Fonti','knowledge'=>'Knowledge Base','media'=>'Media Library','reindex'=>'Reindicizzazione','log'=>'Stato/Log','idee'=>'Idee contenuto','bozze'=>'Bozze','programmazione'=>'Programmazione');
        $tab=sanitize_key($_GET['tab']??'overview'); if(!isset($tabs[$tab])) $tab='overview';
        $missing_tables = ALMA_AI_Content_Agent_Store::missing_tables();
        $k=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items'));  $c=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('content_chunks')); $s=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('sources')); $m=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".ALMA_AI_Content_Agent_Store::table('media_index'));
        echo '<div class="wrap"><h1>AI Content Agent</h1><h2 class="nav-tab-wrapper">'; foreach($tabs as $tk=>$tl){ echo '<a class="nav-tab '.($tk===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$tk)).'">'.esc_html($tl).'</a>'; } echo '</h2>';
        if(in_array($tab,array('idee','bozze','programmazione'),true)){ echo '<p>Tab futura: non operativa in questo step.</p></div>'; return; }
        if($tab==='overview'){ echo '<ul><li>Knowledge items: '.esc_html($k).'</li><li>Chunks: '.esc_html($c).'</li><li>Fonti: '.esc_html($s).'</li><li>Immagini indicizzate: '.esc_html($m).'</li><li>OpenAI: '.(get_option('alma_openai_api_key','')!==''?'Configurato':'Non configurato').'</li></ul>'; if(!empty($missing_tables)){ echo '<div class="notice notice-error"><p>Tabelle AI mancanti: '.esc_html(implode(', ', $missing_tables)).'</p></div>'; }}
        if($tab==='reindex'){ self::action_form('reindex_knowledge','Reindicizza Knowledge'); self::action_form('reindex_media','Reindicizza Media'); }
        if($tab==='fonti'){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_source"><input name="name" placeholder="Nome"> <input name="source_url" placeholder="URL"> <select name="source_type"><option>website</option><option>rss</option><option>sitemap</option><option>social</option><option>sothra_external</option><option>manual</option></select> <button class="button button-primary">Salva fonte</button></form>'; }
        if($tab==='documenti'){ echo '<p>Caricare documenti via Media Library nativa e incollare ID attachment:</p>'; self::action_form('index_document','Indicizza documento', '<input name="attachment_id" placeholder="Attachment ID"><textarea name="manual_text" placeholder="Sintesi/testo manuale"></textarea><select name="usage_mode"><option>knowledge</option><option>style_reference</option><option>internal_linking</option><option>trend_signal_future</option><option>exclude_from_generation</option></select>'); self::action_form('save_note','Salva nota manuale','<input name="title" placeholder="Titolo"><textarea name="content"></textarea><select name="usage_mode"><option>knowledge</option><option>exclude_from_generation</option></select>'); }
        if($tab==='knowledge'){ $rows=$wpdb->get_results("SELECT * FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." ORDER BY id DESC LIMIT 20", ARRAY_A); echo '<table class="widefat"><tr><th>ID</th><th>Tipo</th><th>Titolo</th><th>Usage</th></tr>'; foreach($rows as $r){ echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['source_type']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['usage_mode']).'</td></tr>'; } echo '</table>'; }
        if($tab==='media'){ $rows=$wpdb->get_results("SELECT * FROM ".ALMA_AI_Content_Agent_Store::table('media_index')." ORDER BY id DESC LIMIT 20", ARRAY_A); echo '<table class="widefat"><tr><th>Attachment</th><th>Filename</th><th>Alt</th></tr>'; foreach($rows as $r){ echo '<tr><td>'.(int)$r['attachment_id'].'</td><td>'.esc_html($r['filename']).'</td><td>'.esc_html($r['alt_text']).'</td></tr>'; } echo '</table>'; }
        if($tab==='log'){ if(empty($missing_tables)){ echo '<p>Schema DB AI: OK</p>'; } else { echo '<div class="notice notice-error"><p>Schema DB AI incompleto. Tabelle mancanti: '.esc_html(implode(', ', $missing_tables)).'</p></div>'; }}
        echo '</div>';
    }
    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
