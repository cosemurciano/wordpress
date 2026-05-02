<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Planner {
    public static function generate_ideas($args = array()) {
        $ctx = ALMA_AI_Content_Agent_Context_Builder::build($args);
        if (empty(get_option('alma_openai_api_key', ''))) { return array('success'=>false,'error'=>'OpenAI non configurato.'); }
        if ((int)$ctx['diagnostics']['knowledge_items'] === 0) { return array('success'=>false,'error'=>'Knowledge Base vuota.'); }
        $max = max(1, min(10, absint($args['max_ideas'] ?? 3)));
        $prompt = 'Genera max '.$max.' idee editoriali in JSON con campo ideas rispettando instruction_block, tono, target, SEO, affiliate, anti-duplicazione e istruzioni temporanee se presenti.';
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un planner editoriale.', 'user_prompt'=>$prompt . ' Contesto: ' . wp_json_encode($ctx['context']), 'json_output'=>true, 'max_output_tokens'=>1200));
        if (empty($res['success'])) { ALMA_AI_Usage_Logger::log(array('task'=>'content_idea_generation','success'=>false,'error'=>$res['error'] ?? 'errore','model'=>$res['model'] ?? '')); return $res; }
        $parsed = json_decode($res['response'], true);
        if (!is_array($parsed)) { $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json($res['response']), true); }
        if (!is_array($parsed) || empty($parsed['ideas']) || !is_array($parsed['ideas'])) { return array('success'=>false,'error'=>'JSON idee non valido'); }
        $save_result = ALMA_AI_Content_Agent_Store::save_ideas($parsed['ideas'], $res['model'], $ctx['diagnostics']);
        $saved = (int)($save_result['saved'] ?? 0);
        ALMA_AI_Usage_Logger::log(array('task'=>'content_idea_generation','success'=>true,'model'=>$res['model'],'response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'estimated_cost'=>$res['usage']['total_tokens'] ?? null));
        if ($saved < 1) { return array('success'=>false,'error'=>'Nessuna idea salvata (verificare database).','saved'=>0,'diagnostics'=>$ctx['diagnostics'],'db_errors'=>$save_result['errors'] ?? array()); }
        return array('success'=>true,'saved'=>$saved,'diagnostics'=>$ctx['diagnostics'],'warnings'=>$save_result['errors'] ?? array());
    }
}
