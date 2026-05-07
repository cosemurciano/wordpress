<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Internal_Link_Index {
    const OPTION_STATE = 'alma_ai_internal_link_index_state';

    public static function table_name() {
        return ALMA_AI_Content_Agent_Store::table('internal_link_index');
    }

    public static function init() {
        add_action('save_post', array(__CLASS__, 'handle_save_post'), 20, 3);
        add_action('trashed_post', array(__CLASS__, 'handle_remove_post'));
        add_action('deleted_post', array(__CLASS__, 'handle_remove_post'));
    }

    public static function supported_post_types() {
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) apply_filters('alma_ai_internal_link_index_post_types', array('post'))))));
    }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table_name() . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,post_id BIGINT UNSIGNED NOT NULL,post_type VARCHAR(40) NOT NULL DEFAULT 'post',post_status VARCHAR(20) NOT NULL DEFAULT 'publish',post_title TEXT NOT NULL,post_slug VARCHAR(200) NOT NULL DEFAULT '',permalink TEXT NOT NULL,post_excerpt TEXT NULL,categories_json LONGTEXT NULL,tags_json LONGTEXT NULL,search_text LONGTEXT NULL,published_at DATETIME NULL,modified_at DATETIME NULL,indexed_at DATETIME NULL,PRIMARY KEY (id),UNIQUE KEY post_id (post_id),KEY post_type_status (post_type, post_status),KEY post_slug (post_slug),KEY published_at (published_at),KEY modified_at (modified_at),KEY indexed_at (indexed_at)) $c;");
    }

    public static function get_stats() {
        global $wpdb;
        $table = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $state = get_option(self::OPTION_STATE, array());
        if ($exists !== $table) {
            return array('table_exists'=>false,'indexed_count'=>0,'last_rebuild_at'=>sanitize_text_field($state['last_rebuild_at'] ?? ''),'last_indexed_at'=>'','pending'=>self::empty_pending_counts(false, sanitize_text_field($state['last_rebuild_at'] ?? '')));

        }
        return array(
            'table_exists' => true,
            'indexed_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE post_status='publish'"),
            'last_rebuild_at' => sanitize_text_field($state['last_rebuild_at'] ?? ''),
            'last_indexed_at' => (string) $wpdb->get_var("SELECT MAX(indexed_at) FROM $table"),
            'pending' => self::get_pending_counts(),
        );
    }


    private static function empty_pending_counts($table_exists = true, $last_rebuild_at = '') {
        return array(
            'table_exists' => (bool) $table_exists,
            'new' => 0,
            'modified' => 0,
            'stale' => 0,
            'indexed' => 0,
            'last_rebuild_at' => sanitize_text_field($last_rebuild_at),
            'last_indexed_at' => '',
        );
    }

    private static function post_type_sql_placeholders($post_types) {
        $post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types)));
        return !empty($post_types) ? implode(',', array_fill(0, count($post_types), '%s')) : "''";
    }

    public static function get_pending_counts() {
        global $wpdb;
        $table = self::table_name();
        $state = get_option(self::OPTION_STATE, array());
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return self::empty_pending_counts(false, sanitize_text_field($state['last_rebuild_at'] ?? '')); }
        $post_types = self::supported_post_types();
        if (empty($post_types)) { return self::empty_pending_counts(true, sanitize_text_field($state['last_rebuild_at'] ?? '')); }
        $type_placeholders = self::post_type_sql_placeholders($post_types);

        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE post_status='publish'");
        $last_indexed_at = (string) $wpdb->get_var("SELECT MAX(indexed_at) FROM $table");
        $new_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p LEFT JOIN $table i ON i.post_id = p.ID WHERE p.post_type IN ($type_placeholders) AND p.post_status='publish' AND i.post_id IS NULL";
        $modified_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p INNER JOIN $table i ON i.post_id = p.ID WHERE p.post_type IN ($type_placeholders) AND p.post_status='publish' AND (i.indexed_at IS NULL OR p.post_modified_gmt > i.indexed_at)";
        $stale_sql = "SELECT COUNT(1) FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL OR p.post_type NOT IN ($type_placeholders) OR p.post_status <> 'publish'";

        return array(
            'table_exists' => true,
            'new' => (int) $wpdb->get_var($wpdb->prepare($new_sql, $post_types)),
            'modified' => (int) $wpdb->get_var($wpdb->prepare($modified_sql, $post_types)),
            'stale' => (int) $wpdb->get_var($wpdb->prepare($stale_sql, $post_types)),
            'indexed' => $indexed,
            'last_rebuild_at' => sanitize_text_field($state['last_rebuild_at'] ?? ''),
            'last_indexed_at' => $last_indexed_at,
        );
    }

    public static function sync_pending($limit = 300) {
        global $wpdb;
        self::install_table();
        $table = self::table_name();
        $post_types = self::supported_post_types();
        $limit = max(1, min(500, absint($limit)));
        $result = array('new'=>0,'modified'=>0,'stale'=>0,'errors'=>0);
        if (empty($post_types)) { return $result; }
        $type_placeholders = self::post_type_sql_placeholders($post_types);

        $new_sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $table i ON i.post_id = p.ID WHERE p.post_type IN ($type_placeholders) AND p.post_status='publish' AND i.post_id IS NULL ORDER BY p.ID ASC LIMIT %d";
        $new_ids = $wpdb->get_col($wpdb->prepare($new_sql, array_merge($post_types, array($limit))));
        foreach ((array) $new_ids as $post_id) {
            if (self::index_post((int) $post_id)) { $result['new']++; } else { $result['errors']++; }
        }

        $remaining = max(1, $limit - count((array) $new_ids));
        $modified_sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN $table i ON i.post_id = p.ID WHERE p.post_type IN ($type_placeholders) AND p.post_status='publish' AND (i.indexed_at IS NULL OR p.post_modified_gmt > i.indexed_at) ORDER BY p.ID ASC LIMIT %d";
        $modified_ids = $wpdb->get_col($wpdb->prepare($modified_sql, array_merge($post_types, array($remaining))));
        foreach ((array) $modified_ids as $post_id) {
            if (self::index_post((int) $post_id)) { $result['modified']++; } else { $result['errors']++; }
        }

        $stale_sql = "DELETE i FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL OR p.post_type NOT IN ($type_placeholders) OR p.post_status <> 'publish'";
        $deleted = $wpdb->query($wpdb->prepare($stale_sql, $post_types));
        if ($deleted === false) { $result['errors']++; } else { $result['stale'] = (int) $deleted; }
        return $result;
    }

    public static function rebuild_index($per_page = 200) {
        global $wpdb;
        self::install_table();
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE $table");
        $post_types = self::supported_post_types();
        $indexed = 0;
        $processed = 0;
        if (empty($post_types)) { return array('processed'=>0,'indexed'=>0); }
        $paged = 1;
        $per_page = max(50, min(500, absint($per_page)));
        do {
            $ids = get_posts(array(
                'post_type' => $post_types,
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $paged,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
            ));
            if (!is_array($ids) || empty($ids)) { break; }
            foreach ($ids as $post_id) {
                $processed++;
                if (self::index_post($post_id)) { $indexed++; }
            }
            $paged++;
        } while (count($ids) === $per_page);

        update_option(self::OPTION_STATE, array('last_rebuild_at'=>current_time('mysql'),'processed'=>$processed,'indexed'=>$indexed), false);
        return array('processed'=>$processed,'indexed'=>$indexed);
    }

    public static function handle_save_post($post_id, $post, $update) {
        $post_id = absint($post_id);
        if ($post_id < 1 || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) { return; }
        if (!$post instanceof WP_Post) { $post = get_post($post_id); }
        if (!$post || !in_array($post->post_type, self::supported_post_types(), true)) { return; }
        if ($post->post_status === 'publish') { self::index_post($post_id); return; }
        self::remove_post($post_id);
    }

    public static function handle_remove_post($post_id) {
        self::remove_post(absint($post_id));
    }

    public static function remove_post($post_id) {
        global $wpdb;
        $post_id = absint($post_id);
        if ($post_id < 1) { return false; }
        return false !== $wpdb->delete(self::table_name(), array('post_id'=>$post_id), array('%d'));
    }

    public static function index_post($post_id) {
        global $wpdb;
        $post_id = absint($post_id);
        if ($post_id < 1 || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) { return false; }
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::supported_post_types(), true) || $post->post_status !== 'publish') {
            self::remove_post($post_id);
            return false;
        }
        $permalink = esc_url_raw(get_permalink($post_id));
        if ($permalink === '') { return false; }
        $categories = self::term_names($post_id, 'category');
        $tags = self::term_names($post_id, 'post_tag');
        $excerpt = sanitize_text_field((string)$post->post_excerpt);
        $search_text = self::build_search_text(array($post->post_title, $post->post_name, $excerpt, implode(' ', $categories), implode(' ', $tags)));
        $data = array(
            'post_id' => $post_id,
            'post_type' => sanitize_key($post->post_type),
            'post_status' => sanitize_key($post->post_status),
            'post_title' => sanitize_text_field($post->post_title),
            'post_slug' => sanitize_title($post->post_name),
            'permalink' => $permalink,
            'post_excerpt' => $excerpt,
            'categories_json' => wp_json_encode($categories),
            'tags_json' => wp_json_encode($tags),
            'search_text' => $search_text,
            'published_at' => sanitize_text_field($post->post_date),
            'modified_at' => sanitize_text_field($post->post_modified),
            'indexed_at' => current_time('mysql'),
        );
        $existing = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table_name() . ' WHERE post_id=%d', $post_id));
        if ($existing > 0) { return false !== $wpdb->update(self::table_name(), $data, array('post_id'=>$post_id)); }
        return false !== $wpdb->insert(self::table_name(), $data);
    }

    private static function term_names($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (empty($terms) || is_wp_error($terms)) { return array(); }
        $names = array();
        foreach ($terms as $term) {
            $name = sanitize_text_field((string)($term->name ?? ''));
            if ($name !== '') { $names[$name] = $name; }
        }
        return array_values($names);
    }

    private static function build_search_text($parts) {
        $text = implode(' ', array_map('wp_strip_all_tags', (array)$parts));
        $text = remove_accents(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string)$text));
    }
}
