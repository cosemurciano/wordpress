<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Media_Indexer {
    public static function reindex_batch($limit = 30) {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('media_index');
        $q = new WP_Query(array('post_type'=>'attachment','post_mime_type'=>'image','post_status'=>'inherit','posts_per_page'=>(int)$limit,'paged'=>1));
        foreach ($q->posts as $att) {
            $meta = wp_get_attachment_metadata($att->ID);
            $file = get_attached_file($att->ID);
            $alt = get_post_meta($att->ID, '_wp_attachment_image_alt', true);
            $text = $att->post_title.' '.$alt.' '.$att->post_excerpt.' '.$att->post_content.' '.basename((string)$file);
            $row = array('attachment_id'=>$att->ID,'filename'=>basename((string)$file),'title'=>$att->post_title,'alt_text'=>$alt,'caption'=>$att->post_excerpt,'description'=>$att->post_content,'mime_type'=>$att->post_mime_type,'width'=>(int)($meta['width']??0),'height'=>(int)($meta['height']??0),'upload_date'=>$att->post_date,'parent_post_id'=>(int)$att->post_parent,'keywords'=>wp_json_encode(ALMA_AI_Content_Agent_Text_Utils::extract_keywords($text)),'destinations'=>wp_json_encode(ALMA_AI_Content_Agent_Text_Utils::extract_keywords($text,6)),'indexed_at'=>current_time('mysql'));
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE attachment_id=%d", $att->ID));
            $exists ? $wpdb->update($table,$row,array('attachment_id'=>$att->ID)) : $wpdb->insert($table,$row);
        }
        return count($q->posts);
    }
}
