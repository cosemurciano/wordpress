<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Result_Usage {
    public static function table_name() { return ALMA_AI_Content_Agent_Store::table('content_agent_result_usage'); }
    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table_name() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            result_key VARCHAR(191) NOT NULL,
            source_group VARCHAR(40) NOT NULL DEFAULT '',
            source_type VARCHAR(40) NOT NULL DEFAULT '',
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            knowledge_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            wp_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            usage_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            last_draft_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY result_key (result_key),
            KEY source_group (source_group),
            KEY source_id (source_id)
        ) $c;");
    }
    public static function get_counts($result_keys) {
        global $wpdb;
        $keys = array_values(array_filter(array_map('sanitize_text_field', (array)$result_keys)));
        if (empty($keys)) { return array(); }
        $table = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return array(); }
        $ph = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT result_key, usage_count FROM $table WHERE result_key IN ($ph)", $keys), ARRAY_A);
        $counts = array_fill_keys($keys, 0);
        foreach ((array)$rows as $row) { $counts[sanitize_text_field($row['result_key'])] = max(0, (int)$row['usage_count']); }
        return $counts;
    }
    /**
     * Conteggio d'uso per source_id (aggregato su tutte le result_key dello
     * stesso oggetto): usato per variare l'offerta affiliata preferendo i
     * link meno utilizzati a parità di pertinenza.
     *
     * @return array<int,int> source_id => quante volte usato in bozze
     */
    public static function get_counts_by_source($source_ids, $source_group = 'affiliate_link') {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $source_ids))));
        if (empty($ids)) { return array(); }
        $table = self::table_name();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return array_fill_keys($ids, 0); }
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        $params = $ids;
        $params[] = sanitize_key($source_group);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT source_id, SUM(usage_count) AS c FROM $table WHERE source_id IN ($ph) AND source_group = %s GROUP BY source_id",
            $params
        ), ARRAY_A);
        $counts = array_fill_keys($ids, 0);
        foreach ((array) $rows as $row) { $counts[(int) $row['source_id']] = max(0, (int) $row['c']); }
        return $counts;
    }

    public static function increment_for_results($results, $draft_post_id) {
        global $wpdb;
        $table = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return false; }
        $draft_post_id = absint($draft_post_id);
        foreach ((array)$results as $row) {
            $result_key = sanitize_text_field($row['result_key'] ?? '');
            if ($result_key === '') { continue; }
            $data = array(
                'result_key' => $result_key,
                'source_group' => sanitize_key($row['source_group'] ?? ''),
                'source_type' => sanitize_text_field($row['source_type'] ?? ''),
                'source_id' => absint($row['source_id'] ?? 0),
                'knowledge_item_id' => absint($row['knowledge_item_id'] ?? 0),
                'wp_id' => absint($row['wp_id'] ?? 0),
                'usage_count' => 1,
                'last_used_at' => current_time('mysql'),
                'last_draft_post_id' => $draft_post_id,
            );
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (result_key,source_group,source_type,source_id,knowledge_item_id,wp_id,usage_count,last_used_at,last_draft_post_id)
                VALUES (%s,%s,%s,%d,%d,%d,%d,%s,%d)
                ON DUPLICATE KEY UPDATE usage_count = usage_count + 1,last_used_at = VALUES(last_used_at),last_draft_post_id = VALUES(last_draft_post_id),source_group = VALUES(source_group),source_type = VALUES(source_type),source_id = VALUES(source_id),knowledge_item_id = VALUES(knowledge_item_id),wp_id = VALUES(wp_id)",
                $data['result_key'],$data['source_group'],$data['source_type'],$data['source_id'],$data['knowledge_item_id'],$data['wp_id'],$data['usage_count'],$data['last_used_at'],$data['last_draft_post_id']
            ));
        }
        return true;
    }
}
