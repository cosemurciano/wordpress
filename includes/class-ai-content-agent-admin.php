<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Admin {
    public static function render_page(){ if(!current_user_can('manage_options')) return; $configured = get_option('alma_openai_api_key','') !== ''; $model = get_option('alma_openai_model','gpt-5.4-mini'); $last = get_option('alma_openai_last_test', array()); $logs = class_exists('ALMA_AI_Usage_Logger') ? ALMA_AI_Usage_Logger::get_recent_logs(10):array();
        $tabs=array('overview'=>'Overview','documenti'=>'Documenti','fonti'=>'Fonti','media'=>'Media Library','idee'=>'Idee contenuto','bozze'=>'Bozze','programmazione'=>'Programmazione','log'=>'Log'); $tab=sanitize_key($_GET['tab']??'overview'); if(!isset($tabs[$tab])) $tab='overview';
        echo '<div class="wrap"><h1>AI Content Agent</h1><h2 class="nav-tab-wrapper">'; foreach($tabs as $k=>$l){$u=admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab='.$k); echo '<a class="nav-tab '.($k===$tab?'nav-tab-active':'').'" href="'.esc_url($u).'">'.esc_html($l).'</a>'; } echo '</h2>';
        if($tab!=='overview'){ echo '<p>Funzionalità prevista nello step successivo.</p></div>'; return; }
        echo '<p><strong>Stato OpenAI:</strong> '.($configured?'Configurato':'Non configurato').'</p><p><strong>Modello default:</strong> '.esc_html($model).'</p>';
        if(!empty($last)){ echo '<p><strong>Ultimo test connessione:</strong> '.esc_html($last['date']??'').' - '.esc_html($last['status']??'').'</p>'; }
        echo '<h3>Ultimi log AI</h3><ul>'; foreach($logs as $log){ echo '<li>'.esc_html($log['created_at'].' | '.$log['task'].' | '.$log['model'].' | '.($log['success']?'OK':'KO')).'</li>'; } echo '</ul>';
        echo '<h3>Step successivi</h3><p>Knowledge Base, trend collector, document indexing, media intelligence e scheduler saranno implementati in PR successive.</p></div>'; }
}
