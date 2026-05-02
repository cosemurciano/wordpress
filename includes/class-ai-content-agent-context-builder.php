<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Context_Builder {
    public static function build($args = array()) {
        global $wpdb;
        $usage_mode = sanitize_key($args['usage_mode'] ?? 'knowledge');
        $limit_items = max(1, min(20, absint($args['limit_items'] ?? 8)));
        $limit_chunks = max(1, min(30, absint($args['limit_chunks'] ?? 12)));
        $table_k = ALMA_AI_Content_Agent_Store::table('knowledge_items');
        $table_c = ALMA_AI_Content_Agent_Store::table('content_chunks');

        $items = $wpdb->get_results($wpdb->prepare("SELECT id,title,normalized_excerpt FROM $table_k WHERE status='active' AND usage_mode IN (%s,'knowledge') AND usage_mode <> 'exclude_from_generation' ORDER BY indexed_at DESC LIMIT %d", $usage_mode, $limit_items), ARRAY_A);
        $chunks = $wpdb->get_results($wpdb->prepare("SELECT knowledge_item_id,normalized_text FROM $table_c ORDER BY id DESC LIMIT %d", $limit_chunks), ARRAY_A);
        $links = ALMA_AI_Content_Agent_Affiliate_Selector::select_candidates($args);
        $media = ALMA_AI_Content_Agent_Media_Selector::select_candidates($args);

        $compact = array('knowledge_items'=>$items,'chunks'=>$chunks,'affiliate_links'=>$links['items'],'media'=>$media['items']);
        return array('context'=>$compact,'diagnostics'=>array('knowledge_items'=>count($items),'chunks'=>count($chunks),'affiliate_links'=>count($links['items']),'images'=>count($media['items']),'warnings'=>array_merge($links['warnings'],$media['warnings'])));
    }
}
