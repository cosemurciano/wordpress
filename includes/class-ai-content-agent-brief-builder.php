<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Brief_Builder {
    public static function generate_for_idea($idea_id) {
        $idea = ALMA_AI_Content_Agent_Store::get_idea($idea_id); if (!$idea) { return array('success'=>false,'error'=>'Idea non trovata'); }
        if (empty(get_option('alma_openai_api_key', ''))) { return array('success'=>false,'error'=>'OpenAI non configurato.'); }
        $profile = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile();
        $instruction_block = ALMA_AI_Content_Agent_Instructions_Manager::build_compact_instruction_block($profile, '');
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Genera brief editoriale JSON rispettando le istruzioni.', 'user_prompt'=>'Crea brief per idea: '.wp_json_encode($idea).' Istruzioni: '.$instruction_block, 'json_output'=>true, 'max_output_tokens'=>1200));
        if (empty($res['success'])) { ALMA_AI_Usage_Logger::log(array('task'=>'content_brief_generation','success'=>false,'error'=>$res['error'] ?? 'errore','model'=>$res['model'] ?? '')); return $res; }
        $brief = json_decode($res['response'], true); if (!is_array($brief)) { $brief = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json($res['response']), true); }
        if (!is_array($brief)) { return array('success'=>false,'error'=>'Brief JSON non valido'); }
        $diag=array('instruction_profile_id'=>$profile['id']??null,'instruction_snapshot_hash'=>ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash($instruction_block),'instruction_snapshot'=>$instruction_block);
        ALMA_AI_Content_Agent_Store::save_brief($idea_id, $brief, $res['model'],$diag); ALMA_AI_Content_Agent_Store::update_idea_status($idea_id, 'brief_ready');
        ALMA_AI_Usage_Logger::log(array('task'=>'content_brief_generation','success'=>true,'model'=>$res['model'],'response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null));
        return array('success'=>true);
    }
}
