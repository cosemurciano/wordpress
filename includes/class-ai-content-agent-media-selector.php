<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Media_Selector {
    public static function select_candidates($args = array()) {
        global $wpdb;
        $limit = max(1, min(10, absint($args['limit_media'] ?? 5)));
        $table = ALMA_AI_Content_Agent_Store::table('media_index');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT attachment_id,file_name,title,alt_text,caption,search_text FROM $table WHERE is_editorial_candidate=1 ORDER BY indexed_at DESC LIMIT %d", $limit), ARRAY_A);
        $items = array();
        foreach ($rows as $r) {
            $items[] = array('attachment_id'=>(int)$r['attachment_id'],'preview'=>wp_get_attachment_image((int)$r['attachment_id'], array(80,80)),'title'=>$r['title'],'filename'=>$r['file_name'],'reason'=>'Media editoriale indicizzato coerente con knowledge','score_match'=>65);
        }
        $warnings = empty($items) ? array('Media index vuoto o privo di candidate editoriali: idee generate senza immagini candidate.') : array();
        return array('items'=>$items,'warnings'=>$warnings);
    }
}
