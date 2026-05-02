<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Context_Builder {
    public static function build($args = array()) {
        global $wpdb; $usage_mode=sanitize_key($args['usage_mode'] ?? 'knowledge');
        $limit_items=max(1,min(20,absint($args['limit_items']??8))); $limit_chunks=max(1,min(30,absint($args['limit_chunks']??12)));
        $items=$wpdb->get_results($wpdb->prepare("SELECT id,title,normalized_excerpt FROM ".ALMA_AI_Content_Agent_Store::table('knowledge_items')." WHERE status='active' AND usage_mode <> 'exclude_from_generation' AND usage_mode IN (%s,'knowledge') ORDER BY indexed_at DESC LIMIT %d",$usage_mode,$limit_items),ARRAY_A);
        $chunks=array();
        if (!empty($items)) {
            $ids = array_map('absint', wp_list_pluck($items, 'id'));
            $in = implode(',', $ids);
            $chunks=$wpdb->get_results("SELECT knowledge_item_id,normalized_text FROM ".ALMA_AI_Content_Agent_Store::table('content_chunks')." WHERE knowledge_item_id IN ($in) ORDER BY id DESC LIMIT ".(int)$limit_chunks,ARRAY_A);
        }
        $links=ALMA_AI_Content_Agent_Affiliate_Selector::select_candidates($args); $media=ALMA_AI_Content_Agent_Media_Selector::select_candidates($args);
        $profile = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profile(); $temporary = sanitize_textarea_field($args['temporary_instructions'] ?? '');
        $instruction_block = ALMA_AI_Content_Agent_Instructions_Manager::build_compact_instruction_block($profile, $temporary);
        return array('context'=>array('knowledge_items'=>$items,'chunks'=>$chunks,'affiliate_links'=>$links['items'],'media'=>$media['items'],'instruction_block'=>$instruction_block),'diagnostics'=>array('knowledge_items'=>count($items),'chunks'=>count($chunks),'affiliate_links'=>count($links['items']),'images'=>count($media['items']),'instruction_profile_id'=>$profile['id']??null,'has_active_instruction_profile'=>!empty($profile),'instruction_snapshot_hash'=>ALMA_AI_Content_Agent_Instructions_Manager::snapshot_hash($instruction_block),'instruction_snapshot'=>$instruction_block,'warnings'=>array_merge($links['warnings'],$media['warnings'])));
    }
}
