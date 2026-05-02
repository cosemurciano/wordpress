<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Document_Manager {
    public static function index_attachment($attachment_id, $manual_text = '', $usage_mode = 'knowledge', $language = '') {
        $att = get_post((int)$attachment_id);
        if (!$att || $att->post_type !== 'attachment') { return; }
        $filename = basename((string)get_attached_file($attachment_id));
        $mime = (string) get_post_mime_type($attachment_id);
        $content = $att->post_title . ' ' . $att->post_excerpt . ' ' . $manual_text . ' ' . $filename;
        ALMA_AI_Content_Agent_Knowledge_Indexer::upsert_knowledge('document_attachment', $attachment_id, $att->post_title, $content, $usage_mode);
    }

    public static function save_manual_note($data) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('knowledge_items');
        $wpdb->insert($table, array(
            'source_type'=>'manual_note','source_id'=>0,'title'=>sanitize_text_field($data['title'] ?? ''),
            'normalized_excerpt'=>ALMA_AI_Content_Agent_Text_Utils::normalize_text($data['content'] ?? ''),
            'content_hash'=>hash('sha256', (string)($data['content'] ?? '')),'language_code'=>sanitize_text_field($data['language_code'] ?? ''),
            'keywords'=>wp_json_encode(ALMA_AI_Content_Agent_Text_Utils::extract_keywords($data['content'] ?? '')),
            'usage_mode'=>sanitize_key($data['usage_mode'] ?? 'knowledge'),'status'=>sanitize_key($data['status'] ?? 'active'),'indexed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ));
    }
}
