<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Knowledge_Indexer {
    const BATCH_SIZE = 20;

    public static function reindex_batch() {
        global $wpdb;
        $items = array();
        $items = array_merge($items, self::collect_posts('post'));
        $items = array_merge($items, self::collect_posts('page'));
        $items = array_merge($items, self::collect_posts('affiliate_link'));
        foreach ($items as $item) {
            self::upsert_knowledge($item['source_type'], $item['source_id'], $item['title'], $item['content'], 'knowledge');
        }
        return count($items);
    }

    private static function collect_posts($type) {
        $q = new WP_Query(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>self::BATCH_SIZE,'paged'=>1,'fields'=>'ids'));
        $out = array();
        foreach ($q->posts as $id) {
            $c = get_post_field('post_content', $id);
            if ($type === 'affiliate_link') { $c .= ' ' . get_post_meta($id, '_alma_ai_context', true); }
            $out[] = array('source_type'=>$type,'source_id'=>$id,'title'=>get_the_title($id),'content'=>$c);
        }
        return $out;
    }

    public static function upsert_knowledge($source_type, $source_id, $title, $content, $usage_mode='knowledge') {
        global $wpdb;
        $table = ALMA_AI_Content_Agent_Store::table('knowledge_items');
        $chunk_table = ALMA_AI_Content_Agent_Store::table('content_chunks');
        $norm = ALMA_AI_Content_Agent_Text_Utils::normalize_text($content);
        $hash = hash('sha256', $norm);
        $data = array(
            'source_type'=>sanitize_key($source_type),'source_id'=>(int)$source_id,'title'=>sanitize_text_field($title),
            'normalized_excerpt'=>mb_substr($norm,0,1200),'content_hash'=>$hash,'language_code'=>ALMA_AI_Content_Agent_Text_Utils::detect_language($norm),
            'keywords'=>wp_json_encode(ALMA_AI_Content_Agent_Text_Utils::extract_keywords($norm)),'usage_mode'=>sanitize_key($usage_mode),'status'=>'active','indexed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        );
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE source_type=%s AND source_id=%d", $data['source_type'],$data['source_id']));
        if ($id) { $wpdb->update($table,$data,array('id'=>(int)$id)); $item_id=(int)$id; $wpdb->delete($chunk_table,array('knowledge_item_id'=>$item_id)); }
        else { $wpdb->insert($table,$data); $item_id=(int)$wpdb->insert_id; }
        $chunks = str_split($norm, 900);
        foreach ($chunks as $idx=>$chunk) {
            $wpdb->insert($chunk_table,array('knowledge_item_id'=>$item_id,'chunk_index'=>$idx+1,'normalized_text'=>$chunk,'content_hash'=>hash('sha256',$chunk),'keywords'=>wp_json_encode(ALMA_AI_Content_Agent_Text_Utils::extract_keywords($chunk,8)),'est_length'=>mb_strlen($chunk)));
        }
    }
}
