<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Manager {
    private $registry;

    public function __construct() {
        $this->registry = new ALMA_Affiliate_Source_Provider_Registry();
        $this->registry->bootstrap_native_providers();
        add_action('admin_menu', array($this, 'register_submenu'), 11);
        add_action('add_meta_boxes', array($this, 'register_technical_metabox'));
        add_action('save_post_affiliate_link', array($this, 'save_technical_meta'));
    }

    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_affiliate_sources (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL, provider varchar(50) NOT NULL, is_active tinyint(1) NOT NULL DEFAULT 1, language varchar(20) DEFAULT '', market varchar(20) DEFAULT '', destination_term_id bigint(20) unsigned DEFAULT 0, import_mode varchar(30) DEFAULT 'create_update', settings longtext NULL, credentials longtext NULL, last_sync_at datetime NULL, last_sync_status varchar(20) DEFAULT 'manual', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_affiliate_source_logs (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, source_id bigint(20) unsigned NOT NULL, sync_started_at datetime NOT NULL, sync_ended_at datetime NULL, status varchar(20) NOT NULL, created_count int(11) DEFAULT 0, updated_count int(11) DEFAULT 0, skipped_count int(11) DEFAULT 0, error_count int(11) DEFAULT 0, message text NULL, PRIMARY KEY  (id), KEY source_id (source_id)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_affiliate_category_map (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, source_id bigint(20) unsigned NOT NULL, provider_category varchar(191) NOT NULL, link_type_term_id bigint(20) unsigned NOT NULL, is_fallback tinyint(1) NOT NULL DEFAULT 0, auto_create_terms tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY source_id (source_id)) $charset;");
    }

    public function register_submenu() {
        add_submenu_page('edit.php?post_type=affiliate_link', __('Affiliate Sources', 'affiliate-link-manager-ai'), __('Affiliate Sources', 'affiliate-link-manager-ai'), 'manage_options', 'alma-affiliate-sources', array($this, 'render_sources_page'));
    }

    public function render_sources_page() {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources ORDER BY id DESC LIMIT 100", ARRAY_A);
        echo '<div class="wrap"><h1>Affiliate Sources</h1><table class="widefat striped"><thead><tr><th>Nome</th><th>Provider</th><th>Stato</th><th>Lingua</th><th>Mercato</th><th>Ultimo Sync</th><th>Stato Sync</th></tr></thead><tbody>';
        if (empty($rows)) { echo '<tr><td colspan="7">Nessuna fonte configurata.</td></tr>'; }
        foreach ($rows as $r) {
            echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['provider']) . '</td><td>' . ($r['is_active'] ? 'Attivo' : 'Disattivo') . '</td><td>' . esc_html($r['language']) . '</td><td>' . esc_html($r['market']) . '</td><td>' . esc_html($r['last_sync_at']) . '</td><td>' . esc_html($r['last_sync_status']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function register_technical_metabox() {
        add_meta_box('alma_affiliate_source_tech', __('Affiliate Source (tecnico)', 'affiliate-link-manager-ai'), array($this, 'render_technical_metabox'), 'affiliate_link', 'side', 'default');
    }

    public function render_technical_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) { return; }
        wp_nonce_field('alma_source_meta', 'alma_source_meta_nonce');
        $keys = array('_alma_provider','_alma_source_id','_alma_external_id','_alma_original_url','_alma_last_sync_at','_alma_import_status','_alma_ai_visibility','_alma_ai_priority');
        echo '<p><strong>Tipo origine:</strong> ' . esc_html(get_post_meta($post->ID, '_alma_import_status', true) ?: 'manual') . '</p>';
        foreach($keys as $k){ echo '<p><strong>'.esc_html($k).':</strong> '.esc_html((string)get_post_meta($post->ID,$k,true)).'</p>'; }
    }

    public function save_technical_meta($post_id) {
        if (!isset($_POST['alma_source_meta_nonce']) || !wp_verify_nonce($_POST['alma_source_meta_nonce'], 'alma_source_meta')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        if (get_post_type($post_id) !== 'affiliate_link') { return; }
        if (!metadata_exists('post', $post_id, '_alma_ai_visibility')) { update_post_meta($post_id, '_alma_ai_visibility', 'available'); }
        if (!metadata_exists('post', $post_id, '_alma_import_status')) { update_post_meta($post_id, '_alma_import_status', 'manual'); }
    }
}
