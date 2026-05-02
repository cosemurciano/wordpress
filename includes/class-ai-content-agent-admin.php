<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    public static function init() { add_action('admin_post_alma_ai_agent_action', array(__CLASS__,'handle_action')); }
    public static function handle_action(){ if(!current_user_can('manage_options')) wp_die('forbidden'); check_admin_referer('alma_ai_agent_action'); $do=sanitize_key($_POST['do']??'');
        if($do==='generate_ideas'){ ALMA_AI_Content_Agent_Planner::generate_ideas(array('max_ideas'=>absint($_POST['max_ideas']??3),'theme'=>sanitize_text_field($_POST['theme']??''),'destination'=>sanitize_text_field($_POST['destination']??''),'temporary_instructions'=>sanitize_textarea_field($_POST['temporary_instructions']??''))); }
        if($do==='set_idea_status'){ ALMA_AI_Content_Agent_Store::update_idea_status(absint($_POST['idea_id']??0), sanitize_key($_POST['status']??'')); }
        if($do==='generate_brief'){ ALMA_AI_Content_Agent_Brief_Builder::generate_for_idea(absint($_POST['idea_id']??0)); }
        if($do==='save_instruction_profile'){ $id=absint($_POST['profile_id']??0); ALMA_AI_Content_Agent_Instructions_Manager::save_profile($_POST,$id); }
        if($do==='activate_instruction_profile'){ ALMA_AI_Content_Agent_Instructions_Manager::set_active(absint($_POST['profile_id']??0)); }
        if($do==='deactivate_instruction_profile'){ ALMA_AI_Content_Agent_Instructions_Manager::set_inactive(absint($_POST['profile_id']??0)); }
        wp_safe_redirect(wp_get_referer()); exit;
    }
    public static function render_page(){ if(!current_user_can('manage_options')) return;
        $tabs=array('overview'=>'Overview','istruzioni-ai'=>'Istruzioni AI','documenti'=>'Documenti','fonti'=>'Fonti','knowledge'=>'Knowledge Base','media'=>'Media Library','reindex'=>'Reindicizzazione','log'=>'Stato/Log','idee'=>'Idee contenuto','bozze'=>'Bozze','programmazione'=>'Programmazione');
        $tab=sanitize_key($_GET['tab']??'overview'); if(!isset($tabs[$tab])) $tab='overview';
        echo '<div class="wrap"><h1>AI Content Agent</h1><h2 class="nav-tab-wrapper">'; foreach($tabs as $tk=>$tl){ echo '<a class="nav-tab '.($tk===$tab?'nav-tab-active':'').'" href="'.esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$tk)).'">'.esc_html($tl).'</a>'; } echo '</h2>';
        if($tab==='istruzioni-ai'){ self::render_instructions_tab(); echo '</div>'; return; }
        if($tab==='idee'){ self::render_ideas_tab(); echo '</div>'; return; }
        if(in_array($tab,array('bozze','programmazione'),true)){ echo '<p>Tab futura: non operativa in questo step.</p></div>'; return; }
        echo '<p>Sezioni Step 1/2 invariate.</p></div>';
    }
    private static function render_instructions_tab(){ $p=ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); if(!$p){ ALMA_AI_Content_Agent_Instructions_Manager::ensure_default_profile(); $p=ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); }
        echo '<h2>Istruzioni AI</h2><p>Queste istruzioni influenzano Planner, Brief e Draft futuri. Non configurano API OpenAI.</p><p><strong>Profilo attivo:</strong> '.esc_html($p['profile_name']??'Nessuno').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_instruction_profile"><input type="hidden" name="profile_id" value="'.(int)($p['id']??0).'">';
        $fields=['profile_name'=>'Nome profilo','language_code'=>'Lingua','tone_of_voice'=>'Tono di voce','target_audience'=>'Pubblico target','editorial_style'=>'Stile editoriale','seo_rules'=>'Regole SEO','affiliate_rules'=>'Regole affiliate','image_rules'=>'Regole immagini','source_rules'=>'Regole fonti','anti_duplication_rules'=>'Regole anti-duplicazione','avoid_rules'=>'Cose da evitare','disclosure_policy'=>'Disclosure policy','custom_prompt'=>'Prompt libero personalizzato','internal_notes'=>'Note interne'];
        foreach($fields as $k=>$l){ echo '<p><label><strong>'.esc_html($l).'</strong><br>'; if(in_array($k,['profile_name','language_code'],true)){ echo '<input class="regular-text" name="'.esc_attr($k).'" value="'.esc_attr($p[$k]??'').'"></label></p>'; } else { echo '<textarea class="large-text" rows="3" name="'.esc_attr($k).'">'.esc_textarea($p[$k]??'').'</textarea></label></p>'; }}
        echo '<p><button class="button button-primary">Salva profilo</button></p></form>';
    }
    private static function render_ideas_tab(){ $status = sanitize_key($_GET['idea_status'] ?? ''); echo '<h2>Genera idee</h2>'; self::action_form('generate_ideas','Genera idee','<input type="number" min="1" max="10" name="max_ideas" value="3"/> <input name="theme" placeholder="Tema"> <input name="destination" placeholder="Destinazione"><br><textarea name="temporary_instructions" class="large-text" rows="2" placeholder="Istruzioni aggiuntive per questa generazione (opzionale)"></textarea>');
        echo '<form method="get"><input type="hidden" name="post_type" value="affiliate_link"><input type="hidden" name="page" value="alma-ai-content-agent"><input type="hidden" name="tab" value="idee"><select name="idea_status"><option value="">Tutti gli stati</option>'; foreach(ALMA_AI_Content_Agent_Store::allowed_idea_statuses() as $st){ echo '<option '.selected($status,$st,false).' value="'.esc_attr($st).'">'.esc_html($st).'</option>'; } echo '</select> <button class="button">Filtra</button></form>';
        foreach(ALMA_AI_Content_Agent_Store::get_ideas($status) as $i){ echo '<div><strong>#'.(int)$i['id'].' '.esc_html($i['proposed_title']).'</strong> - '.esc_html($i['status']).' - Profile ID: '.esc_html($i['instruction_profile_id']??'n/a').' - Snapshot: '.esc_html(substr((string)($i['instruction_snapshot_hash']??''),0,12)).'</div>'; }
    }
    private static function action_form($do,$label,$extra=''){ echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('alma_ai_agent_action'); echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="'.esc_attr($do).'">'.$extra.'<p><button class="button">'.esc_html($label).'</button></p></form>'; }
}
ALMA_AI_Content_Agent_Admin::init();
