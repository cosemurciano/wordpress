<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Document_Manager {
    const MAX_UPLOAD_BYTES = 2097152;

    public static function max_upload_bytes() { return (int) apply_filters('alma_ai_agent_txt_max_upload_bytes', self::MAX_UPLOAD_BYTES); }

    public static function handle_upload($name, $file) {
        if (empty($file['name']) || empty($file['tmp_name'])) { return array('success'=>false,'message'=>'File non valido.'); }
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'txt') { return array('success'=>false,'message'=>'File non valido: sono consentiti solo .txt'); }
        if (!empty($file['size']) && (int)$file['size'] > self::max_upload_bytes()) { return array('success'=>false,'message'=>'File troppo grande.'); }
        $mime = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (($mime['ext'] ?? '') !== 'txt') { return array('success'=>false,'message'=>'MIME non valido per file TXT.'); }
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) { return array('success'=>false,'message'=>'Errore lettura file.'); }
        return self::upsert_document($name, $file['name'], (int)$file['size'], $content, 'active', 0);
    }

    public static function upsert_document($name, $file_name, $file_size, $content, $status='active', $existing_id = 0) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('knowledge_items');
        $chunk_table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $norm = ALMA_AI_Content_Agent_Text_Utils::normalize_text((string)$content);
        $hash = hash('sha256', $norm);
        $existing_by_hash = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_type='document_txt' AND content_hash=%s", $hash));
        if ($existing_by_hash && $existing_id === 0) { $existing_id = $existing_by_hash; }
        $data = array(
            'source_type' => 'document_txt', 'source_id' => 0, 'title' => sanitize_text_field($name), 'normalized_excerpt' => mb_substr($norm, 0, 1200),
            'content_hash' => $hash, 'status' => in_array($status, array('active','inactive'), true) ? $status : 'active', 'indexed_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
            'language_code' => ALMA_AI_Content_Agent_Text_Utils::detect_language($norm),
            'keywords' => wp_json_encode(array('file_name'=>sanitize_file_name($file_name), 'file_size'=>absint($file_size))),
        );
        if ($existing_id > 0) { $wpdb->update($table, $data, array('id'=>$existing_id)); $item_id = $existing_id; $wpdb->delete($chunk_table, array('knowledge_item_id'=>$item_id)); }
        else { $wpdb->insert($table, array_merge($data, array('usage_mode'=>'knowledge'))); $item_id = (int) $wpdb->insert_id; }
        foreach (str_split($norm, 900) as $idx => $chunk) {
            $wpdb->insert($chunk_table, array('knowledge_item_id'=>$item_id,'chunk_index'=>$idx+1,'normalized_text'=>$chunk,'content_hash'=>hash('sha256',$chunk),'keywords'=>'[]','est_length'=>mb_strlen($chunk)));
        }
        return array('success'=>true,'message'=>'Documento TXT indicizzato.','id'=>$item_id);
    }
}
