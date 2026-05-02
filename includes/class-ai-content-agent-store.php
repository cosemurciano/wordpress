<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Store {
    public static function table($name) { global $wpdb; return $wpdb->prefix . 'alma_ai_' . $name; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::table('knowledge_items') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(40) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title TEXT NOT NULL,
            normalized_excerpt LONGTEXT NULL,
            content_hash CHAR(64) NOT NULL,
            language_code VARCHAR(10) NOT NULL DEFAULT '',
            keywords TEXT NULL,
            destination VARCHAR(120) NOT NULL DEFAULT '',
            travel_theme VARCHAR(120) NOT NULL DEFAULT '',
            usage_mode VARCHAR(40) NOT NULL DEFAULT 'knowledge',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            indexed_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY source (source_type, source_id),
            KEY usage_mode (usage_mode),
            KEY status (status),
            KEY indexed_at (indexed_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('content_chunks') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            knowledge_item_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            normalized_text LONGTEXT NOT NULL,
            content_hash CHAR(64) NOT NULL,
            keywords TEXT NULL,
            est_length INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY item_chunk (knowledge_item_id, chunk_index),
            KEY content_hash (content_hash)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('media_index') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            title TEXT NULL,
            alt_text TEXT NULL,
            caption TEXT NULL,
            description LONGTEXT NULL,
            mime_type VARCHAR(120) NOT NULL,
            width INT UNSIGNED NOT NULL DEFAULT 0,
            height INT UNSIGNED NOT NULL DEFAULT 0,
            upload_date DATETIME NULL,
            parent_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            keywords TEXT NULL,
            destinations TEXT NULL,
            manual_notes TEXT NULL,
            quality_score DECIMAL(5,2) NULL,
            indexed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_id (attachment_id),
            KEY mime_type (mime_type),
            KEY parent_post_id (parent_post_id)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('sources') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_url TEXT NOT NULL,
            language_code VARCHAR(10) NOT NULL DEFAULT '',
            market VARCHAR(60) NOT NULL DEFAULT '',
            usage_mode VARCHAR(40) NOT NULL DEFAULT 'knowledge',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            last_test_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY source_type (source_type),
            KEY is_active (is_active)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::table('jobs') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            total_items INT UNSIGNED NOT NULL DEFAULT 0,
            processed_items INT UNSIGNED NOT NULL DEFAULT 0,
            errors_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY job_type (job_type),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset;");
    }
}
