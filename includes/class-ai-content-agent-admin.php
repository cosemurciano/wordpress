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
        if($do==='generate_ideas'){ ALMA_AI_Content_Agent_Planner::generate_ideas(array('max_ideas'=>absint($_POST['max_ideas']??3),'theme'=>sanitize_text_field($_POST['theme']??''),'destination'=>sanitize_text_field($_POST['destination']??''))); }
        if($do==='set_idea_status'){ ALMA_AI_Content_Agent_Store::update_idea_status(absint($_POST['idea_id']??0), sanitize_key($_POST['status']??'')); }
        if($do==='generate_brief'){ ALMA_AI_Content_Agent_Brief_Builder::generate_for_idea(absint($_POST['idea_id']??0)); }
        wp_safe_redirect(wp_get_referer()); exit;
    }
    public static function render_page(){ global $wpdb; if(!current_user_can('manage_options')) return;
        $tabs=array('overview'=>'Overview','documenti'=>'Documenti','fonti'=>'Fonti','knowledge'=>'Knowledge Base','media'=>'Media Library','reindex'=>'Reindicizzazione','log'=>'Stato/Log','idee'=>'Idee contenuto','bozze'=>'Bozze','programmazione'=>'Programmazione');
        $tab=sanitize_key($_GET['tab']??'overview'); if(!isset($tabs[$tab])) $tab='overview';
        echo '<div class="wrap"><h1>AI Content Agent</h1><h2 class="nav-tab-wrapper">'; foreach($tabs as $tk=>$tl){ echo '<a class="nav-tab '.($tk===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$tk)).'">'.esc_html($tl).'</a>'; } echo '</h2>';
        if(in_array($tab,array('bozze','programmazione'),true)){ echo '<p>Tab futura: non operativa in questo step.</p></div>'; return; }
        if($tab==='idee'){ self::render_ideas_tab(); echo '</div>'; return; }
        echo '<p>Sezioni Step 1/2 invariate.</p></div>';
    }
    private static function render_ideas_tab(){
        $status = sanitize_key($_GET['idea_status'] ?? '');
        echo '<h2>Genera idee</h2>'; self::action_form('generate_ideas','Genera idee','<input type="number" min="1" max="10" name="max_ideas" value="3"/> <input name="theme" placeholder="Tema"> <input name="destination" placeholder="Destinazione">');
        echo '<form method="get"><input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="alma-ai-content-agent"><input type="hidden" name="tab" value="idee"><select name="idea_status"><option value="">Tutti gli stati</option>';
        foreach(ALMA_AI_Content_Agent_Store::allowed_idea_statuses() as $st){ echo '<option '.selected($status,$st,false).' value="'.esc_attr($st).'">'.esc_html($st).'</option>'; }
        echo '</select> <button class="button">Filtra</button></form>';
        $ideas=ALMA_AI_Content_Agent_Store::get_ideas($status);
        echo '<table class="widefat"><tr><th>ID</th><th>Titolo</th><th>Stato</th><th>Score</th><th>Affiliati/Media</th><th>AI</th><th>Azioni</th></tr>';
        foreach($ideas as $i){ $brief=ALMA_AI_Content_Agent_Store::get_brief_by_idea($i['id']);
            echo '<tr><td>'.(int)$i['id'].'</td><td>'.esc_html($i['proposed_title']).'<br><small>'.esc_html($i['rationale']).'</small></td><td>'.esc_html($i['status']).'</td><td>SEO '.esc_html($i['seo_score']).' / Priority '.esc_html($i['priority_score']).'</td><td><details><summary>Candidati</summary><pre>'.esc_html($i['affiliate_candidates'])."\n".esc_html($i['image_candidates']).'</pre></details></td><td>'.esc_html($i['ai_model']).'</td><td>';
            self::inline_status_form((int)$i['id'],'approved','Approva'); self::inline_status_form((int)$i['id'],'rejected','Scarta'); self::inline_status_form((int)$i['id'],'archived','Archivia'); self::inline_brief_form((int)$i['id']);
            if($brief){ echo '<details><summary>Brief</summary><pre>'.esc_html(wp_json_encode($brief, JSON_PRETTY_PRINT)).'</pre></details>'; }
            echo '</td></tr>';
        }
        echo '</table>';
    }
    private static function inline_status_form($idea_id,$status,$label){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:4px;">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="set_idea_status"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><input type="hidden" name="status" value="'.esc_attr($status).'"><button class="button button-small">'.esc_html($label).'</button></form>'; }
    private static function inline_brief_form($idea_id){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="generate_brief"><input type="hidden" name="idea_id" value="'.(int)$idea_id.'"><button class="button button-small">Genera brief</button></form>'; }
    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
