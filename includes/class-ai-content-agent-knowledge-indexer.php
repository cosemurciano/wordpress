<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Knowledge_Indexer {
    const BATCH_SIZE = 20;
    const REINDEX_STATE_OPTION = 'alma_ai_knowledge_reindex_state';

    public static function reindex_batch() {
        global $wpdb;
        // Stato di avanzamento persistente: prima ogni esecuzione ripartiva sempre
        // dalla pagina 1 e nella knowledge base entravano solo i 20 post più recenti
        // per tipo; ora i batch successivi coprono progressivamente tutto il sito.
        $state = get_option(self::REINDEX_STATE_OPTION, array());
        if (!is_array($state)) { $state = array(); }
        $items = array();
        foreach (array('post', 'page', 'affiliate_link') as $type) {
            // Come nel comportamento storico, ogni run rinfresca sempre i contenuti
            // modificati più di recente (l'editor che reindicizza dopo una modifica
            // deve trovarla nella knowledge base)...
            foreach (self::collect_posts($type, 1, 'modified') as $item) {
                $items[$item['source_type'] . ':' . $item['source_id']] = $item;
            }
            // ...e in più avanza il cursore di backfill sul resto del sito.
            $paged = max(1, absint($state[$type] ?? 1));
            $collected = self::collect_posts($type, $paged);
            foreach ($collected as $item) {
                $items[$item['source_type'] . ':' . $item['source_id']] = $item;
            }
            // Pagina piena → probabilmente c'è altro: avanza. Pagina parziale/vuota →
            // fine del tipo: riparti da 1.
            $state[$type] = count($collected) === self::BATCH_SIZE ? $paged + 1 : 1;
        }
        foreach ($items as $item) {
            self::upsert_knowledge($item['source_type'], $item['source_id'], $item['title'], $item['content'], 'knowledge');
        }
        update_option(self::REINDEX_STATE_OPTION, $state, false);
        return count($items);
    }

    private static function collect_posts($type, $paged = 1, $orderby = 'ID') {
        // Backfill ordinato per ID: la paginazione per data slitta quando vengono
        // pubblicati nuovi contenuti. Il refresh dei recenti usa orderby=modified.
        $order = $orderby === 'modified' ? 'DESC' : 'ASC';
        $q = new WP_Query(array('post_type'=>$type,'post_status'=>'publish','posts_per_page'=>self::BATCH_SIZE,'paged'=>max(1, absint($paged)),'orderby'=>$orderby,'order'=>$order,'fields'=>'ids'));
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
