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
            return array('table_exists'=>false,'indexed_count'=>0,'last_rebuild_at'=>sanitize_text_field($state['last_rebuild_at'] ?? ''),'last_indexed_at'=>'');
        }
        return array(
            'table_exists' => true,
            'indexed_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE post_status='publish'"),
            'last_rebuild_at' => sanitize_text_field($state['last_rebuild_at'] ?? ''),
            'last_indexed_at' => (string) $wpdb->get_var("SELECT MAX(indexed_at) FROM $table"),
        );
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
