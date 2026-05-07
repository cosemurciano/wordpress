<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Media_Index {
    const OPTION_LAST_REBUILD_AT = 'alma_ai_media_index_last_rebuild_at';
    const META_ORIGIN = '_alma_media_origin';
    const META_ROLE = '_alma_media_role';
    const META_RELATED_POST_ID = '_alma_related_post_id';
    const META_RELATED_POST_TYPE = '_alma_related_post_type';

    public static function init() {
        add_action('add_attachment', array(__CLASS__, 'handle_attachment_change'));
        add_action('edit_attachment', array(__CLASS__, 'handle_attachment_change'));
        add_action('save_post_attachment', array(__CLASS__, 'handle_save_post_attachment'), 10, 3);
        add_action('delete_attachment', array(__CLASS__, 'delete_attachment'));
        add_action('wp_trash_post', array(__CLASS__, 'handle_trash_post'));
        add_action('updated_post_meta', array(__CLASS__, 'handle_attachment_meta_change'), 10, 4);
        add_action('added_post_meta', array(__CLASS__, 'handle_attachment_meta_change'), 10, 4);
        add_action('deleted_post_meta', array(__CLASS__, 'handle_attachment_meta_change'), 10, 4);
    }

    public static function table_name() { return ALMA_AI_Content_Agent_Store::table('media_index'); }

    public static function install_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table_name() . " (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,attachment_id BIGINT UNSIGNED NOT NULL,post_status VARCHAR(20) NOT NULL DEFAULT '',mime_type VARCHAR(120) NOT NULL DEFAULT '',title TEXT NULL,alt_text TEXT NULL,caption TEXT NULL,description LONGTEXT NULL,file_name VARCHAR(255) NOT NULL DEFAULT '',url_full TEXT NULL,url_large TEXT NULL,url_medium TEXT NULL,width INT UNSIGNED NOT NULL DEFAULT 0,height INT UNSIGNED NOT NULL DEFAULT 0,post_parent BIGINT UNSIGNED NOT NULL DEFAULT 0,attached_post_title TEXT NULL,media_origin VARCHAR(60) NOT NULL DEFAULT 'editorial',media_role VARCHAR(80) NOT NULL DEFAULT 'editorial_image',provider VARCHAR(80) NOT NULL DEFAULT '',source_id VARCHAR(190) NOT NULL DEFAULT '',external_id VARCHAR(190) NOT NULL DEFAULT '',related_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,related_post_type VARCHAR(60) NOT NULL DEFAULT '',is_affiliate_media TINYINT(1) NOT NULL DEFAULT 0,is_editorial_candidate TINYINT(1) NOT NULL DEFAULT 1,search_text LONGTEXT NULL,created_at DATETIME NULL,modified_at DATETIME NULL,indexed_at DATETIME NULL,indexed_at_gmt DATETIME NULL,PRIMARY KEY (id),UNIQUE KEY attachment_id (attachment_id),KEY post_status (post_status),KEY mime_type (mime_type),KEY post_parent (post_parent),KEY media_origin (media_origin),KEY media_role (media_role),KEY is_affiliate_media (is_affiliate_media),KEY is_editorial_candidate (is_editorial_candidate),KEY related_post (related_post_id, related_post_type),KEY indexed_at (indexed_at),KEY indexed_at_gmt (indexed_at_gmt)) $c;");
        return self::ensure_schema();
    }

    public static function maybe_upgrade_schema() {
        return self::ensure_schema();
    }

    public static function expected_columns() {
        return array(
            'attachment_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_status' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'mime_type' => "VARCHAR(120) NOT NULL DEFAULT ''",
            'title' => 'TEXT NULL',
            'alt_text' => 'TEXT NULL',
            'caption' => 'TEXT NULL',
            'description' => 'LONGTEXT NULL',
            'file_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'url_full' => 'TEXT NULL',
            'url_large' => 'TEXT NULL',
            'url_medium' => 'TEXT NULL',
            'width' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'height' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'post_parent' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'attached_post_title' => 'TEXT NULL',
            'media_origin' => "VARCHAR(60) NOT NULL DEFAULT 'editorial'",
            'media_role' => "VARCHAR(80) NOT NULL DEFAULT 'editorial_image'",
            'provider' => "VARCHAR(80) NOT NULL DEFAULT ''",
            'source_id' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'external_id' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'related_post_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'related_post_type' => "VARCHAR(60) NOT NULL DEFAULT ''",
            'is_affiliate_media' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_editorial_candidate' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'search_text' => 'LONGTEXT NULL',
            'created_at' => 'DATETIME NULL',
            'modified_at' => 'DATETIME NULL',
            'indexed_at' => 'DATETIME NULL',
            'indexed_at_gmt' => 'DATETIME NULL',
        );
    }

    public static function ensure_schema() {
        global $wpdb;
        $table = self::table_name();
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return array('success'=>false,'added_columns'=>array(),'missing_columns'=>array_keys(self::expected_columns()),'checked_columns'=>array_keys(self::expected_columns()),'error'=>'Tabella media_index non disponibile.');
        }

        $wpdb->last_error = '';
        $existing = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);
        if (!is_array($existing)) {
            return array('success'=>false,'added_columns'=>array(),'missing_columns'=>array_keys(self::expected_columns()),'checked_columns'=>array_keys(self::expected_columns()),'error'=>sanitize_text_field($wpdb->last_error ?: 'SHOW COLUMNS non riuscito.'));
        }

        $existing_columns = array();
        foreach ($existing as $column) {
            if (!empty($column['Field'])) { $existing_columns[(string)$column['Field']] = true; }
        }

        $added = array();
        $errors = array();
        foreach (self::expected_columns() as $column => $definition) {
            if (isset($existing_columns[$column])) { continue; }
            $wpdb->last_error = '';
            $ok = $wpdb->query('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
            if ($ok === false) {
                $errors[] = $column . ': ' . sanitize_text_field($wpdb->last_error ?: 'ALTER TABLE non riuscito.');
                continue;
            }
            $added[] = $column;
            $existing_columns[$column] = true;
        }

        $missing = array();
        foreach (array_keys(self::expected_columns()) as $column) {
            if (!isset($existing_columns[$column])) { $missing[] = $column; }
        }

        $success = empty($missing) && empty($errors);
        $error = '';
        if (!$success) { $error = implode(' | ', array_merge($errors, array_map('sanitize_text_field', $missing))); }
        if (!empty($added)) { error_log('ALMA media index schema updated: added missing columns ' . implode(', ', array_map('sanitize_key', $added))); }
        return array('success'=>$success,'added_columns'=>$added,'missing_columns'=>$missing,'checked_columns'=>array_keys(self::expected_columns()),'error'=>$error);
    }

    public static function handle_attachment_change($attachment_id) { self::index_attachment(absint($attachment_id)); }

    public static function handle_save_post_attachment($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
        self::index_attachment(absint($post_id));
    }

    public static function handle_trash_post($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'attachment') { self::delete_attachment($post_id); }
    }

    public static function handle_attachment_meta_change($meta_id, $object_id, $meta_key, $meta_value) {
        $watched = array('_wp_attachment_image_alt','_wp_attachment_metadata','_alma_media_origin','_alma_media_role','_alma_media_provider','_alma_source_id','_alma_external_id','_alma_imported_for_post_id','_alma_related_post_id','_alma_related_post_type','_alma_remote_image_hash');
        if (!in_array((string)$meta_key, $watched, true)) { return; }
        $post = get_post(absint($object_id));
        if ($post && $post->post_type === 'attachment') { self::index_attachment((int)$object_id); }
    }

    public static function rebuild_index($batch_size = 100) {
        global $wpdb;
        $schema = self::install_table();
        $table = self::table_name();
        if (empty($schema['success'])) {
            return array('processed'=>0,'detected_images'=>0,'indexed'=>0,'non_images_skipped'=>0,'missing_url'=>0,'errors'=>1,'error_messages'=>array($schema['error'] ?: 'Schema tabella media_index incompleto.'),'deleted'=>0,'batch_size'=>max(10, min(250, absint($batch_size))),'schema_error'=>true,'schema'=>$schema);
        }

        $batch_size = max(10, min(250, absint($batch_size)));
        $processed = 0; $detected_images = 0; $indexed = 0; $non_images_skipped = 0; $missing_url = 0; $errors = 0; $deleted = 0; $last_id = 0; $seen = array(); $error_messages = array();
        $valid_statuses = array('inherit','private','publish','draft','pending','future');
        $status_placeholders = implode(',', array_fill(0, count($valid_statuses), '%s'));
        do {
            $params = array_merge($valid_statuses, array($last_id, $batch_size));
            $posts = $wpdb->get_results($wpdb->prepare("SELECT ID, post_mime_type, post_status, (post_mime_type LIKE 'image/%') AS is_image_mime FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ($status_placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d", $params));
            $posts = is_array($posts) ? $posts : array();
            foreach ($posts as $attachment_stub) {
                $attachment_id = absint($attachment_stub->ID ?? 0);
                if ($attachment_id < 1) { continue; }
                $last_id = $attachment_id;
                $processed++;
                $post = get_post($attachment_id);
                if (!self::is_valid_image_attachment($post)) {
                    $non_images_skipped++;
                    self::delete_attachment($attachment_id);
                    continue;
                }

                $detected_images++;
                $seen[] = $attachment_id;
                $url_full = wp_get_attachment_url($attachment_id);
                if (!$url_full) {
                    $missing_url++;
                    self::delete_attachment($attachment_id);
                    continue;
                }

                $index_result = self::index_attachment($attachment_id, $post);
                if ($index_result) {
                    $indexed++;
                } else {
                    $errors++;
                    if (!empty($wpdb->last_error)) {
                        $error_messages[] = sanitize_text_field($wpdb->last_error);
                    }
                }
            }
        } while (count($posts) === $batch_size);

        $rows = $wpdb->get_col("SELECT attachment_id FROM $table");
        foreach ((array)$rows as $attachment_id) {
            if (!in_array((int)$attachment_id, $seen, true) && !self::is_valid_image_attachment((int)$attachment_id)) {
                self::delete_attachment((int)$attachment_id); $deleted++;
            }
        }
        update_option(self::OPTION_LAST_REBUILD_AT, current_time('mysql'), false);
        return array('processed'=>$processed,'detected_images'=>$detected_images,'indexed'=>$indexed,'non_images_skipped'=>$non_images_skipped,'missing_url'=>$missing_url,'errors'=>$errors,'error_messages'=>array_values(array_unique(array_slice($error_messages, 0, 3))),'deleted'=>$deleted,'batch_size'=>$batch_size,'schema'=>$schema);
    }

    public static function index_attachment($attachment_id, $post = null) {
        global $wpdb;
        $attachment_id = absint($attachment_id);
        $post = $post instanceof WP_Post ? $post : get_post($attachment_id);
        if (!self::is_valid_image_attachment($post)) { self::delete_attachment($attachment_id); return false; }
        $url_full = wp_get_attachment_url($attachment_id);
        if (!$url_full) { self::delete_attachment($attachment_id); return false; }
        $meta = wp_get_attachment_metadata($attachment_id);
        $meta = is_array($meta) ? $meta : array();
        $file = get_attached_file($attachment_id);
        $file_name = sanitize_file_name(wp_basename((string)$file));
        if ($file_name === '') { $file_name = sanitize_file_name(wp_basename((string)get_post_meta($attachment_id, '_wp_attached_file', true))); }
        $parent = $post->post_parent ? get_post((int)$post->post_parent) : null;
        $classification = self::classify($attachment_id);
        $search_text = self::build_search_text(array($post->post_title, get_post_meta($attachment_id, '_wp_attachment_image_alt', true), $post->post_excerpt, $post->post_content, $file_name, $parent ? $parent->post_title : ''));
        $row = array(
            'attachment_id'=>$attachment_id,
            'post_status'=>sanitize_key($post->post_status),
            'mime_type'=>sanitize_mime_type($post->post_mime_type),
            'title'=>sanitize_text_field($post->post_title),
            'alt_text'=>sanitize_text_field((string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true)),
            'caption'=>sanitize_text_field($post->post_excerpt),
            'description'=>wp_kses_post($post->post_content),
            'file_name'=>$file_name,
            'url_full'=>esc_url_raw((string)$url_full),
            'url_large'=>esc_url_raw((string)wp_get_attachment_image_url($attachment_id, 'large')),
            'url_medium'=>esc_url_raw((string)wp_get_attachment_image_url($attachment_id, 'medium')),
            'width'=>absint($meta['width'] ?? 0),
            'height'=>absint($meta['height'] ?? 0),
            'post_parent'=>absint($post->post_parent),
            'attached_post_title'=>$parent ? sanitize_text_field($parent->post_title) : '',
            'media_origin'=>$classification['media_origin'],
            'media_role'=>$classification['media_role'],
            'provider'=>sanitize_key((string)get_post_meta($attachment_id, '_alma_media_provider', true)),
            'source_id'=>sanitize_text_field((string)get_post_meta($attachment_id, '_alma_source_id', true)),
            'external_id'=>sanitize_text_field((string)get_post_meta($attachment_id, '_alma_external_id', true)),
            'related_post_id'=>absint($classification['related_post_id']),
            'related_post_type'=>sanitize_key($classification['related_post_type']),
            'is_affiliate_media'=>(int)$classification['is_affiliate_media'],
            'is_editorial_candidate'=>(int)$classification['is_editorial_candidate'],
            'search_text'=>$search_text,
            'created_at'=>$post->post_date,
            'modified_at'=>$post->post_modified,
            'indexed_at'=>current_time('mysql'),
            'indexed_at_gmt'=>current_time('mysql', true),
        );
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table_name() . " WHERE attachment_id=%d", $attachment_id));
        $ok = $exists ? (false !== $wpdb->update(self::table_name(), $row, array('attachment_id'=>$attachment_id))) : (false !== $wpdb->insert(self::table_name(), $row));
        if (!$ok && !empty($wpdb->last_error)) {
            error_log('ALMA media index insert/update failed: ' . sanitize_text_field($wpdb->last_error));
        }
        return $ok;
    }

    public static function delete_attachment($attachment_id) {
        global $wpdb;
        $attachment_id = absint($attachment_id);
        if ($attachment_id < 1) { return false; }
        return false !== $wpdb->delete(self::table_name(), array('attachment_id'=>$attachment_id));
    }

    public static function count_records($where = '') {
        global $wpdb; $table = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return 0; }
        if ($where === 'affiliate') { return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_affiliate_media=1"); }
        if ($where === 'editorial') { return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_editorial_candidate=1"); }
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    public static function get_status() {
        global $wpdb; $table = self::table_name(); $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return array('table_exists'=>$exists === $table,'total'=>self::count_records(),'editorial'=>self::count_records('editorial'),'affiliate'=>self::count_records('affiliate'),'last_rebuild_at'=>get_option(self::OPTION_LAST_REBUILD_AT, ''),'pending'=>self::get_pending_counts());
    }


    private static function valid_attachment_statuses() {
        return array('inherit','private','publish','draft','pending','future');
    }

    private static function status_placeholders($statuses) {
        $statuses = array_values(array_filter(array_map('sanitize_key', (array) $statuses)));
        return !empty($statuses) ? implode(',', array_fill(0, count($statuses), '%s')) : "''";
    }

    public static function get_pending_counts() {
        global $wpdb;
        $table = self::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $last_rebuild = get_option(self::OPTION_LAST_REBUILD_AT, '');
        $empty = array('table_exists'=>false,'new'=>0,'modified'=>0,'stale'=>0,'indexed_total'=>0,'indexed_editorial'=>0,'indexed_affiliate'=>0,'last_rebuild'=>$last_rebuild,'last_rebuild_at'=>$last_rebuild,'last_indexed_at'=>'');
        if ($exists !== $table) { return $empty; }
        $schema = self::ensure_schema();
        if (empty($schema['success'])) { return $empty; }
        $statuses = self::valid_attachment_statuses();
        $status_placeholders = self::status_placeholders($statuses);
        $new_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p LEFT JOIN $table i ON i.attachment_id = p.ID WHERE p.post_type='attachment' AND p.post_status IN ($status_placeholders) AND p.post_mime_type LIKE 'image/%' AND i.attachment_id IS NULL";
        $modified_sql = "SELECT COUNT(1) FROM {$wpdb->posts} p INNER JOIN $table i ON i.attachment_id = p.ID WHERE p.post_type='attachment' AND p.post_status IN ($status_placeholders) AND p.post_mime_type LIKE 'image/%' AND (i.indexed_at_gmt IS NULL OR i.indexed_at_gmt = '0000-00-00 00:00:00' OR p.post_modified_gmt > i.indexed_at_gmt)";
        $stale_sql = "SELECT COUNT(1) FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.attachment_id WHERE p.ID IS NULL OR p.post_type <> 'attachment' OR p.post_status NOT IN ($status_placeholders) OR p.post_mime_type NOT LIKE 'image/%'";
        return array(
            'table_exists'=>true,
            'new'=>(int) $wpdb->get_var($wpdb->prepare($new_sql, $statuses)),
            'modified'=>(int) $wpdb->get_var($wpdb->prepare($modified_sql, $statuses)),
            'stale'=>(int) $wpdb->get_var($wpdb->prepare($stale_sql, $statuses)),
            'indexed_total'=>self::count_records(),
            'indexed_editorial'=>self::count_records('editorial'),
            'indexed_affiliate'=>self::count_records('affiliate'),
            'last_rebuild'=>$last_rebuild,
            'last_rebuild_at'=>$last_rebuild,
            'last_indexed_at'=>(string) $wpdb->get_var("SELECT MAX(indexed_at) FROM $table"),
        );
    }

    public static function sync_pending($limit = 300) {
        global $wpdb;
        $schema = self::install_table();
        $result = array('new'=>0,'modified'=>0,'stale'=>0,'errors'=>0,'schema'=>$schema);
        if (empty($schema['success'])) { $result['errors'] = 1; return $result; }
        $table = self::table_name();
        $limit = max(1, min(500, absint($limit)));
        $statuses = self::valid_attachment_statuses();
        $status_placeholders = self::status_placeholders($statuses);

        $new_sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $table i ON i.attachment_id = p.ID WHERE p.post_type='attachment' AND p.post_status IN ($status_placeholders) AND p.post_mime_type LIKE 'image/%' AND i.attachment_id IS NULL ORDER BY p.ID ASC LIMIT %d";
        $new_ids = $wpdb->get_col($wpdb->prepare($new_sql, array_merge($statuses, array($limit))));
        foreach ((array) $new_ids as $attachment_id) {
            if (self::index_attachment((int) $attachment_id)) { $result['new']++; } else { $result['errors']++; }
        }

        $remaining = max(1, $limit - count((array) $new_ids));
        $modified_sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN $table i ON i.attachment_id = p.ID WHERE p.post_type='attachment' AND p.post_status IN ($status_placeholders) AND p.post_mime_type LIKE 'image/%' AND (i.indexed_at_gmt IS NULL OR i.indexed_at_gmt = '0000-00-00 00:00:00' OR p.post_modified_gmt > i.indexed_at_gmt) ORDER BY p.ID ASC LIMIT %d";
        $modified_ids = $wpdb->get_col($wpdb->prepare($modified_sql, array_merge($statuses, array($remaining))));
        foreach ((array) $modified_ids as $attachment_id) {
            if (self::index_attachment((int) $attachment_id)) { $result['modified']++; } else { $result['errors']++; }
        }

        $stale_sql = "DELETE i FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID = i.attachment_id WHERE p.ID IS NULL OR p.post_type <> 'attachment' OR p.post_status NOT IN ($status_placeholders) OR p.post_mime_type NOT LIKE 'image/%'";
        $deleted = $wpdb->query($wpdb->prepare($stale_sql, $statuses));
        if ($deleted === false) { $result['errors']++; } else { $result['stale'] = (int) $deleted; }
        return $result;
    }

    private static function is_valid_image_attachment($attachment) {
        $post = $attachment instanceof WP_Post ? $attachment : get_post(absint($attachment));
        if (!$post || $post->post_type !== 'attachment' || $post->post_status === 'trash') { return false; }
        return strpos(strtolower((string)$post->post_mime_type), 'image/') === 0;
    }

    private static function classify($attachment_id) {
        $origin = sanitize_key((string)get_post_meta($attachment_id, self::META_ORIGIN, true));
        $role = sanitize_key((string)get_post_meta($attachment_id, self::META_ROLE, true));
        $related_post_id = absint(get_post_meta($attachment_id, self::META_RELATED_POST_ID, true));
        $related_post_type = sanitize_key((string)get_post_meta($attachment_id, self::META_RELATED_POST_TYPE, true));
        $imported_for = absint(get_post_meta($attachment_id, '_alma_imported_for_post_id', true));
        if ($related_post_id < 1 && $imported_for > 0) { $related_post_id = $imported_for; }
        if ($related_post_type === '' && $related_post_id > 0) { $related_post_type = sanitize_key((string)get_post_type($related_post_id)); }
        $strong = $origin === 'affiliate_source' || (string)get_post_meta($attachment_id, '_alma_media_provider', true) !== '' || (string)get_post_meta($attachment_id, '_alma_remote_image_hash', true) !== '' || (string)get_post_meta($attachment_id, '_alma_external_id', true) !== '' || ($imported_for > 0 && get_post_type($imported_for) === 'affiliate_link');
        $is_affiliate = (bool)apply_filters('alma_ai_media_index_is_affiliate_media', $strong, $attachment_id);
        if ($is_affiliate) {
            $origin = 'affiliate_source'; $role = 'affiliate_featured_image';
            if ($related_post_type === '') { $related_post_type = 'affiliate_link'; }
            if (get_post_meta($attachment_id, self::META_ORIGIN, true) === '') { update_post_meta($attachment_id, self::META_ORIGIN, $origin); }
            if (get_post_meta($attachment_id, self::META_ROLE, true) === '') { update_post_meta($attachment_id, self::META_ROLE, $role); }
            if ($related_post_id > 0 && get_post_meta($attachment_id, self::META_RELATED_POST_ID, true) === '') { update_post_meta($attachment_id, self::META_RELATED_POST_ID, $related_post_id); }
            if ($related_post_type !== '' && get_post_meta($attachment_id, self::META_RELATED_POST_TYPE, true) === '') { update_post_meta($attachment_id, self::META_RELATED_POST_TYPE, $related_post_type); }
        } else {
            $origin = $origin !== '' ? $origin : 'editorial'; $role = $role !== '' ? $role : 'editorial_image';
        }
        $is_editorial = !$is_affiliate;
        $is_editorial = (bool)apply_filters('alma_ai_media_index_is_editorial_candidate', $is_editorial, $attachment_id, $is_affiliate);
        return array('is_affiliate_media'=>$is_affiliate ? 1 : 0,'is_editorial_candidate'=>$is_editorial ? 1 : 0,'media_origin'=>$origin,'media_role'=>$role,'related_post_id'=>$related_post_id,'related_post_type'=>$related_post_type);
    }

    private static function build_search_text($parts) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(implode(' ', array_map('strval', (array)$parts)))));
        return apply_filters('alma_ai_media_index_search_text', sanitize_textarea_field($text), $parts);
    }
}

ALMA_AI_Content_Agent_Media_Index::init();
