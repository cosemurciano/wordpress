<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Builder {
    private static function fail($message, $model = '', $reference_id = '', $extra = array()) {
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>false,'error'=>sanitize_text_field($message),'model'=>sanitize_text_field($model),'reference_id'=>sanitize_text_field($reference_id)));
        return array_merge(array('success'=>false,'error'=>sanitize_text_field($message),'warnings'=>array()), $extra);
    }
    public static function generate_for_idea($idea_id) {
        $idea_id = absint($idea_id); $idea = ALMA_AI_Content_Agent_Store::get_idea($idea_id);
        if (!$idea) return self::fail('Idea non trovata.', '', 'idea:'.$idea_id);
        if (in_array($idea['status'], array('rejected','archived'), true)) return self::fail('Idea non generabile.', '', 'idea:'.$idea_id);
        if (empty(get_option('alma_openai_api_key', ''))) return self::fail('OpenAI non configurato.', '', 'idea:'.$idea_id);
        $existing = ALMA_AI_Content_Agent_Store::get_draft_post_by_idea($idea_id); if ($existing) return self::fail('Bozza già esistente.', '', 'idea:'.$idea_id, array('post_id'=>(int)$existing->ID,'edit_url'=>get_edit_post_link((int)$existing->ID, 'raw')));
        $brief = ALMA_AI_Content_Agent_Store::get_brief_by_idea($idea_id); if (!$brief) return self::fail('Brief non trovato.', '', 'idea:'.$idea_id);
        $context = array('idea'=>$idea,'brief'=>$brief,'instruction_snapshot'=>$brief['instruction_snapshot'] ?? ($idea['instruction_snapshot'] ?? ''),'candidate_affiliate_links'=>json_decode((string)($brief['candidate_affiliate_links'] ?? '[]'), true),'candidate_images'=>json_decode((string)($brief['candidate_images'] ?? '[]'), true),'knowledge_suggestions'=>json_decode((string)($brief['suggested_knowledge_sources'] ?? '[]'), true),'warnings'=>json_decode((string)($brief['warnings'] ?? '[]'), true));
        $prompt = 'Genera JSON con: title,slug,excerpt,content_html,seo_title,meta_description,focus_keyword,suggested_tags,affiliate_links_used,featured_image_id,inline_image_ids,qa_notes,warnings. Usa solo shortcode [affiliate_link id="ID" text="anchor"]. Non inventare ID.';
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress. Output solo JSON valido.', 'user_prompt'=>$prompt.' CONTEXT: '.wp_json_encode($context), 'json_output'=>true, 'max_output_tokens'=>1800));
        if (empty($res['success'])) { return self::fail($res['error'] ?? 'Risposta OpenAI fallita.', $res['model'] ?? '', 'idea:'.$idea_id); }
        $parsed = json_decode($res['response'], true); if (!is_array($parsed)) $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json($res['response']), true);
        if (!is_array($parsed)) { return self::fail('Draft JSON non valido', $res['model'] ?? '', 'idea:'.$idea_id); }
        $candidate_affiliate_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_affiliate_links'], 'link_id'))));
        $candidate_image_ids = array_values(array_filter(array_map('absint', array_column((array)$context['candidate_images'], 'attachment_id'))));
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids);
        if (!is_array($clean) || !array_key_exists('title', $clean) || !array_key_exists('content', $clean)) return self::fail('QA output non valido.', $res['model'] ?? '', 'idea:'.$idea_id);
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') return self::fail('Output draft non valido dopo QA.', $res['model'] ?? '', 'idea:'.$idea_id);
        $post_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_author'=>get_current_user_id(),'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content']), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'idea:'.$idea_id); }
        if (!empty($clean['featured_image_id'])) set_post_thumbnail($post_id, $clean['featured_image_id']);
        update_post_meta($post_id, '_alma_ai_agent_generated', 1); update_post_meta($post_id, '_alma_ai_agent_idea_id', $idea_id); update_post_meta($post_id, '_alma_ai_agent_brief_id', absint($brief['id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_task', 'content_draft_generation'); update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($brief['instruction_profile_id'] ?? $idea['instruction_profile_id'] ?? 0)); update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($brief['instruction_snapshot_hash'] ?? $idea['instruction_snapshot_hash'] ?? '')); update_post_meta($post_id, '_alma_ai_agent_affiliate_links_used', wp_json_encode($clean['affiliate_links_used'])); update_post_meta($post_id, '_alma_ai_agent_image_ids_used', wp_json_encode($clean['inline_image_ids'])); update_post_meta($post_id, '_alma_ai_agent_featured_image_id', absint($clean['featured_image_id'])); update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_merge((array)$clean['warnings'], (array)($parsed['warnings'] ?? array())))); update_post_meta($post_id, '_alma_ai_seo_title', sanitize_text_field($parsed['seo_title'] ?? '')); update_post_meta($post_id, '_alma_ai_meta_description', sanitize_text_field($parsed['meta_description'] ?? '')); update_post_meta($post_id, '_alma_ai_focus_keyword', sanitize_text_field($parsed['focus_keyword'] ?? '')); update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql')); update_post_meta($post_id, '_alma_ai_suggested_tags', wp_json_encode((array)($parsed['suggested_tags'] ?? array())));
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'estimated_cost'=>$res['usage']['total_tokens'] ?? null,'reference_id'=>'post:'.$post_id));
        return array('success'=>true,'post_id'=>$post_id,'edit_url'=>get_edit_post_link($post_id, 'raw'),'warnings'=>array_values(array_merge((array)$clean['warnings'], (array)($parsed['warnings'] ?? array()))));
    }
}
