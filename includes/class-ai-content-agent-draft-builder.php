<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Draft_Builder {
    const TASK_SELECTION = 'content_agent_draft_from_selection';
    private static function fail($message, $model = '', $reference_id = '', $extra = array()) {
        ALMA_AI_Usage_Logger::log(array('task'=>'content_draft_generation','success'=>false,'error'=>sanitize_text_field($message),'model'=>sanitize_text_field($model),'reference_id'=>sanitize_text_field($reference_id)));
        return array_merge(array('success'=>false,'error'=>sanitize_text_field($message),'warnings'=>array()), $extra);
    }

    private static function resolve_document_knowledge_item_id($row) {
        $candidates = array(
            $row['knowledge_item_id'] ?? '',
            $row['result_key'] ?? '',
            $row['result_id'] ?? '',
            $row['key'] ?? '',
        );
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $kid = absint($candidate);
                if ($kid > 0) { return $kid; }
            }
            $value = sanitize_text_field((string)$candidate);
            if ($value === '') { continue; }
            if (preg_match('/^(?:kb:)?document_txt:(\d+)$/', $value, $m)) { return absint($m[1]); }
            if (preg_match('/^(?:document_txt:)?kb_document_txt_(\d+)$/', $value, $m)) { return absint($m[1]); }
            if (preg_match('/^kb:document_txt:kb_document_txt_(\d+)$/', $value, $m)) { return absint($m[1]); }
        }
        return 0;
    }
    private static function fetch_document_chunks($knowledge_item_id, $limit = 3) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $limit = max(1, absint($limit));
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'normalized_text'));
        if (!$col) { return array(); }
        return (array)$wpdb->get_col($wpdb->prepare("SELECT normalized_text FROM $table WHERE knowledge_item_id=%d ORDER BY id ASC LIMIT %d", absint($knowledge_item_id), $limit));
    }
    private static function build_payload_from_selection_session($user_id = 0) {
        $user_id = absint($user_id ?: get_current_user_id());
        $session = ALMA_AI_Content_Agent_Selection_Session::build_context_package();
        return array('task'=>'create_article_draft_from_selected_sources','site_context'=>array('site_name'=>get_bloginfo('name'),'language'=>get_bloginfo('language'),'generated_at'=>current_time('mysql')),'user_inputs'=>array('theme'=>sanitize_text_field($session['last_query']['theme'] ?? ''),'destination'=>sanitize_text_field($session['last_query']['destination'] ?? ''),'search_terms'=>sanitize_text_field($session['last_query']['search_terms'] ?? '')),'temporary_instructions'=>sanitize_textarea_field($session['last_query']['temporary_instructions'] ?? ''),'instruction_profile'=>$session['instruction_profile_name'] ?? '','selection_context'=>$session['selected_results'] ?? array(),'output_contract'=>array('title','slug','excerpt','content','seo_title','seo_description','affiliate_shortcodes_used','media_used','warnings'));
    }

    public static function download_payload_json_from_selection_session($user_id = 0) {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $payload = self::build_payload_from_selection_session($user_id);
        $filename = 'alma-ai-payload-' . gmdate('Y-m-d-Hi') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
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

    public static function generate_from_selection_session($user_id = 0) {
        global $wpdb;
        $user_id = absint($user_id ?: get_current_user_id());
        $session = ALMA_AI_Content_Agent_Selection_Session::build_context_package();
        $selected = array_values(array_filter((array)($session['selected_results'] ?? array()), function($r){ return !empty($r['selected']); }));
        if (empty($selected)) { return self::fail('Seleziona almeno una fonte prima di creare la bozza.'); }
        $selected_posts = array_values(array_filter($selected, function($r){ return ($r['source_group'] ?? '') === 'post'; }));
        if (count($selected_posts) > ALMA_AI_Content_Agent_Selection_Session::MAX_SELECTED_POSTS) { return self::fail('Puoi selezionare massimo 3 Post.'); }
        if (empty(get_option('alma_openai_api_key', ''))) { return self::fail('OpenAI non è configurata.'); }

        $ctx = array('posts'=>array(),'pages'=>array(),'affiliate_links'=>array(),'documents'=>array(),'sources_online'=>array(),'media'=>array());
        $warnings = array();
        foreach ($selected as $row) {
            $group = sanitize_key($row['source_group'] ?? '');
            $sid = absint($row['source_id'] ?? 0);
            if ($group === 'post' || $group === 'page') {
                $p = get_post($sid);
                if (!$p || $p->post_type !== $group) { $warnings[] = 'Elemento selezionato non più disponibile: '.$group.'#'.$sid; continue; }
                $ctx[$group === 'post' ? 'posts' : 'pages'][] = array('id'=>$p->ID,'title'=>sanitize_text_field($p->post_title),'excerpt'=>wp_trim_words(wp_strip_all_tags($p->post_excerpt ?: $p->post_content), 40),'content'=>mb_substr(wp_strip_all_tags($p->post_content),0,1200),'permalink'=>get_permalink($p->ID));
            } elseif ($group === 'affiliate_link') {
                $p = get_post($sid);
                if (!$p || $p->post_type !== 'affiliate_link') { $warnings[] = 'Affiliate link non disponibile: #'.$sid; continue; }
                $source_id = absint(get_post_meta($p->ID, '_alma_source_id', true));
                $source = $source_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT id,name,provider,settings FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d", $source_id), ARRAY_A) : array();
                $source_settings = is_array($source) ? json_decode((string)($source['settings'] ?? '{}'), true) : array();
                $source_prompt = sanitize_textarea_field((string)($source_settings['ai_source_instructions'] ?? ''));
                if ($source_id > 0 && $source_prompt === '') { $warnings[] = 'Comportamento AI source non trovato per affiliate link #'.$p->ID; }
                $ctx['affiliate_links'][] = array('id'=>$p->ID,'title'=>sanitize_text_field($p->post_title),'description'=>sanitize_text_field($p->post_excerpt),'affiliate_url'=>esc_url_raw((string)get_post_meta($p->ID,'_affiliate_url',true)),'shortcode'=>'[affiliate_link id="'.$p->ID.'"]','ai_context'=>sanitize_textarea_field((string)get_post_meta($p->ID,'_alma_ai_context',true)),'source_id'=>$source_id,'source_name'=>sanitize_text_field($source['name'] ?? ''),'provider'=>sanitize_key($source['provider'] ?? ''),'source_ai_behavior_prompt'=>$source_prompt,'usage_rules'=>'Usa solo shortcode autorizzato; non inventare link affiliati.');
            } elseif ($group === 'document_txt') {
                $kid = self::resolve_document_knowledge_item_id($row);
                if ($kid <= 0) { $warnings[] = 'Documento TXT senza knowledge item id stabile.'; continue; }
                $item = $wpdb->get_row($wpdb->prepare("SELECT id,title,status FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE id=%d AND source_type='document_txt'", $kid), ARRAY_A);
                if (!$item || ($item['status'] ?? '') !== 'active') { $warnings[] = 'Documento TXT non disponibile: #'.$kid; continue; }
                $chunks = self::fetch_document_chunks($kid, 3);
                if (empty($chunks)) { $warnings[] = 'Documento TXT senza chunk validi: #'.$kid; }
                $ctx['documents'][] = array('id'=>(int)$item['id'],'title'=>sanitize_text_field($item['title']),'status'=>sanitize_text_field($item['status']),'chunks'=>array_map(function($c){ return mb_substr(wp_strip_all_tags((string)$c),0,500); }, (array)$chunks));
            } elseif ($group === 'source_online') {
                $src = $wpdb->get_row($wpdb->prepare("SELECT id,name,source_url,source_type,is_active FROM ".ALMA_AI_Content_Agent_Store::table('sources')." WHERE id=%d", $sid), ARRAY_A);
                if (!$src || (int)$src['is_active'] !== 1) { $warnings[] = 'Fonte online non disponibile: #'.$sid; continue; }
                $ctx['sources_online'][] = array('id'=>(int)$src['id'],'name'=>sanitize_text_field($src['name']),'url'=>esc_url_raw($src['source_url']),'technology'=>sanitize_text_field($src['source_type']),'status'=>'active');
            } elseif ($group === 'media') {
                $att = get_post($sid);
                if (!$att || $att->post_type !== 'attachment') { $warnings[] = 'Media non disponibile: #'.$sid; continue; }
                $ctx['media'][] = array('attachment_id'=>$att->ID,'title'=>sanitize_text_field($att->post_title),'alt_text'=>sanitize_text_field(get_post_meta($att->ID,'_wp_attachment_image_alt',true)),'caption'=>sanitize_text_field($att->post_excerpt),'description'=>sanitize_textarea_field($att->post_content),'url'=>wp_get_attachment_url($att->ID));
            }
        }
        if (empty($ctx['posts']) && empty($ctx['pages']) && empty($ctx['documents']) && empty($ctx['affiliate_links']) && empty($ctx['sources_online']) && empty($ctx['media'])) {
            return self::fail('Nessuna fonte valida disponibile nella sessione selezionata.');
        }
        $profile_id = absint($session['instruction_profile_id'] ?? 0);
        $profile = $profile_id ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile();
        $payload = self::build_payload_from_selection_session($user_id);
        $payload['instruction_profile'] = $profile;
        $payload['selection_context'] = $ctx;
        $payload['rules'] = array('output_json'=>true,'title_required'=>true,'content_required'=>true,'slug_optional'=>true,'no_raw_affiliate_urls'=>true);
        $prompt = 'Genera solo JSON con chiavi: title,content,slug,warnings. Usa solo shortcode affiliati autorizzati.';
        $res = ALMA_OpenAI_Service::request(array('system_prompt'=>'Sei un content editor WordPress. Output solo JSON valido.', 'user_prompt'=>$prompt.' CONTEXT: '.wp_json_encode($payload), 'json_output'=>true, 'max_output_tokens'=>1800));
        if (empty($res['success'])) { return self::fail($res['error'] ?? 'Risposta OpenAI fallita.', $res['model'] ?? '', 'session:user:'.$user_id); }
        $parsed = json_decode((string)$res['response'], true); if (!is_array($parsed)) { $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json((string)$res['response']), true); }
        if (!is_array($parsed)) { return self::fail('Draft JSON non valido', $res['model'] ?? '', 'session:user:'.$user_id); }
        $parsed['content_html'] = (string)($parsed['content'] ?? '');
        $candidate_affiliate_ids = array_values(array_map('absint', wp_list_pluck((array)$ctx['affiliate_links'], 'id')));
        $candidate_image_ids = array_values(array_map('absint', wp_list_pluck((array)$ctx['media'], 'attachment_id')));
        $clean = ALMA_AI_Content_Agent_Draft_Quality_Checker::validate_payload($parsed, $candidate_affiliate_ids, $candidate_image_ids);
        if ($clean['title'] === '' || trim(wp_strip_all_tags($clean['content'])) === '') { return self::fail('Titolo o contenuto non validi dopo QA.', $res['model'] ?? '', 'session:user:'.$user_id); }
        $post_id = wp_insert_post(array('post_type'=>'post','post_status'=>'draft','post_author'=>$user_id,'post_title'=>$clean['title'],'post_name'=>$clean['slug'],'post_excerpt'=>$clean['excerpt'],'post_content'=>$clean['content']), true);
        if (is_wp_error($post_id) || !$post_id) { return self::fail('Errore creazione bozza.', $res['model'] ?? '', 'session:user:'.$user_id); }
        update_post_meta($post_id, '_alma_ai_agent_generated', 1);
        update_post_meta($post_id, '_alma_ai_agent_task', self::TASK_SELECTION);
        update_post_meta($post_id, '_alma_ai_agent_model', sanitize_text_field($res['model'] ?? ''));
        update_post_meta($post_id, '_alma_ai_agent_selected_post_ids', wp_json_encode(wp_list_pluck($ctx['posts'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_link_ids', wp_json_encode(wp_list_pluck($ctx['affiliate_links'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_affiliate_source_ids', wp_json_encode(array_values(array_unique(array_filter(array_map('absint', wp_list_pluck($ctx['affiliate_links'], 'source_id')))))));
        update_post_meta($post_id, '_alma_ai_agent_selected_document_txt_ids', wp_json_encode(wp_list_pluck($ctx['documents'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_source_online_ids', wp_json_encode(wp_list_pluck($ctx['sources_online'], 'id')));
        update_post_meta($post_id, '_alma_ai_agent_selected_media_ids', wp_json_encode(wp_list_pluck($ctx['media'], 'attachment_id')));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_id', absint($session['instruction_profile_id'] ?? ($profile['id'] ?? 0)));
        update_post_meta($post_id, '_alma_ai_agent_instruction_profile_name', sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')));
        update_post_meta($post_id, '_alma_ai_agent_instruction_snapshot_hash', sanitize_text_field($session['instruction_snapshot_hash'] ?? ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash(wp_json_encode($profile))));
        update_post_meta($post_id, '_alma_ai_agent_qa_warnings', wp_json_encode(array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], (array)($parsed['warnings'] ?? array()))))));
        update_post_meta($post_id, '_alma_ai_generated_at', current_time('mysql'));
        ALMA_AI_Usage_Logger::log(array('task'=>self::TASK_SELECTION,'success'=>true,'model'=>$res['model'] ?? '','response_time'=>$res['response_time'] ?? null,'input_tokens'=>$res['usage']['input_tokens'] ?? null,'output_tokens'=>$res['usage']['output_tokens'] ?? null,'reference_id'=>'post:'.$post_id));
        return array('success'=>true,'post_id'=>$post_id,'title'=>$clean['title'],'edit_url'=>get_edit_post_link($post_id, 'raw'),'preview_url'=>get_preview_post_link($post_id),'warnings'=>array_values(array_unique(array_merge($warnings, (array)$clean['warnings'], (array)($parsed['warnings'] ?? array())))),'model'=>$res['model'] ?? '','usage'=>$res['usage'] ?? array(),'summary'=>array('status'=>'draft','instruction_profile_name'=>sanitize_text_field($profile['profile_name'] ?? ($session['instruction_profile_name'] ?? '')),'source_counts'=>array('post'=>count((array)$ctx['posts']),'page'=>count((array)$ctx['pages']),'affiliate_link'=>count((array)$ctx['affiliate_links']),'document_txt'=>count((array)$ctx['documents']),'source_online'=>count((array)$ctx['sources_online']),'media'=>count((array)$ctx['media']))));
    }
}
