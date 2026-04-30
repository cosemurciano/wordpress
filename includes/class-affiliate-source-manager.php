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
        $this->maybe_handle_source_form();
        global $wpdb;
        $providers = $this->registry->get_registered_providers();
        $terms = get_terms(array('taxonomy' => 'link_type', 'hide_empty' => false));
        $editing_id = isset($_GET['edit_source']) ? absint($_GET['edit_source']) : 0;
        $editing = array();
        if ($editing_id > 0) {
            $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id = %d", $editing_id), ARRAY_A);
        }
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources ORDER BY id DESC LIMIT 100", ARRAY_A);
        echo '<div class="wrap"><h1>Affiliate Sources</h1>';
        echo '<button type="button" class="button button-primary alma-toggle-source-form">Aggiungi nuova source</button>';
        echo '<div id="alma-source-form-wrap" class="alma-source-form-wrap" style="' . ($editing ? '' : 'display:none;') . '">';
        echo '<h2>' . ($editing ? 'Modifica source' : 'Nuova source') . '</h2>';
        echo '<form method="post" id="alma-source-form">';
        wp_nonce_field('alma_save_source', 'alma_source_nonce');
        echo '<input type="hidden" name="action_type" value="save_source" />';
        echo '<input type="hidden" name="source_id" value="' . esc_attr($editing['id'] ?? 0) . '" />';
        echo '<table class="form-table"><tbody>';
        $this->render_input_row('name', 'Name', $editing['name'] ?? '');
        echo '<tr><th><label for="provider">Provider</label></th><td><select name="provider" id="provider" required>';
        foreach ($providers as $key => $provider) { echo '<option value="' . esc_attr($key) . '"' . selected($editing['provider'] ?? '', $key, false) . '>' . esc_html($provider->get_name()) . '</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th><label for="is_active">is_active</label></th><td><label><input type="checkbox" name="is_active" id="is_active" value="1" ' . checked((int)($editing['is_active'] ?? 1), 1, false) . '> Active</label></td></tr>';
        $this->render_input_row('language', 'Language', $editing['language'] ?? '');
        $this->render_input_row('market', 'Market', $editing['market'] ?? '');
        echo '<tr><th><label for="import_mode">Import mode</label></th><td><select name="import_mode" id="import_mode">';
        foreach (array('create_only','update_existing','create_update') as $mode) { echo '<option value="' . esc_attr($mode) . '"' . selected($editing['import_mode'] ?? 'create_update', $mode, false) . '>' . esc_html($mode) . '</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th><label for="destination_term_id">Destination term</label></th><td><select name="destination_term_id" id="destination_term_id"><option value="0">—</option>';
        foreach ($terms as $term) { echo '<option value="' . intval($term->term_id) . '"' . selected((int)($editing['destination_term_id'] ?? 0), (int)$term->term_id, false) . '>' . esc_html($term->name) . '</option>'; }
        echo '</select></td></tr>';
        echo '<tr><th><label for="settings">Settings JSON</label></th><td><textarea name="settings" id="settings" rows="5" class="large-text code">' . esc_textarea($editing['settings'] ?? '') . '</textarea></td></tr>';
        echo '<tr><th><label for="credentials">Credentials JSON</label></th><td><textarea name="credentials" id="credentials" rows="5" class="large-text code">' . esc_textarea($editing['credentials'] ?? '') . '</textarea></td></tr>';
        echo '</tbody></table><p><button type="submit" class="button button-primary">Salva source</button></p></form></div>';
        echo '<table class="widefat striped"><thead><tr><th>Nome</th><th>Provider</th><th>Stato</th><th>Lingua</th><th>Mercato</th><th>Ultimo Sync</th><th>Stato Sync</th><th>Azioni</th></tr></thead><tbody>';
        if (empty($rows)) { echo '<tr><td colspan="7">Nessuna fonte configurata.</td></tr>'; }
        foreach ($rows as $r) {
            $edit_link = add_query_arg(array('post_type' => 'affiliate_link', 'page' => 'alma-affiliate-sources', 'edit_source' => (int)$r['id']), admin_url('edit.php'));
            echo '<tr><td>' . esc_html($r['name']) . '</td><td>' . esc_html($r['provider']) . '</td><td>' . ($r['is_active'] ? 'Attivo' : 'Disattivo') . '</td><td>' . esc_html($r['language']) . '</td><td>' . esc_html($r['market']) . '</td><td>' . esc_html($r['last_sync_at']) . '</td><td>' . esc_html($r['last_sync_status']) . '</td><td><a class="button button-small" href="' . esc_url($edit_link) . '">Modifica</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private function render_input_row($name, $label, $value) { echo '<tr><th><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input type="text" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" /></td></tr>'; }
    private function decode_json_input($raw) { $raw = trim((string)$raw); if ($raw === '') { return ''; } $decoded = json_decode(wp_unslash($raw), true); if (!is_array($decoded)) { return ''; } return wp_json_encode($decoded); }
    private function maybe_handle_source_form() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action_type']) || $_POST['action_type'] !== 'save_source') { return; }
        if (!isset($_POST['alma_source_nonce']) || !wp_verify_nonce($_POST['alma_source_nonce'], 'alma_save_source')) { wp_die('Nonce non valido'); }
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        global $wpdb;
        $data = array(
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'provider' => sanitize_text_field(wp_unslash($_POST['provider'] ?? 'manual')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'language' => sanitize_text_field(wp_unslash($_POST['language'] ?? '')),
            'market' => sanitize_text_field(wp_unslash($_POST['market'] ?? '')),
            'import_mode' => sanitize_text_field(wp_unslash($_POST['import_mode'] ?? 'create_update')),
            'destination_term_id' => absint($_POST['destination_term_id'] ?? 0),
            'settings' => $this->decode_json_input($_POST['settings'] ?? ''),
            'credentials' => $this->decode_json_input($_POST['credentials'] ?? ''),
            'updated_at' => current_time('mysql'),
        );
        $source_id = absint($_POST['source_id'] ?? 0);
        if ($source_id > 0) { $wpdb->update("{$wpdb->prefix}alma_affiliate_sources", $data, array('id' => $source_id)); }
        else { $data['created_at'] = current_time('mysql'); $wpdb->insert("{$wpdb->prefix}alma_affiliate_sources", $data); }
        wp_safe_redirect(add_query_arg(array('post_type' => 'affiliate_link', 'page' => 'alma-affiliate-sources'), admin_url('edit.php')));
        exit;
    }

    public function register_technical_metabox() {
        add_meta_box('alma_affiliate_source_tech', __('Affiliate Source (tecnico)', 'affiliate-link-manager-ai'), array($this, 'render_technical_metabox'), 'affiliate_link', 'side', 'default');
    }

    public function render_technical_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) { return; }
        wp_nonce_field('alma_source_meta', 'alma_source_meta_nonce');
        global $wpdb;
        $provider = get_post_meta($post->ID, '_alma_provider', true) ?: 'manual';
        $source_id = (int)get_post_meta($post->ID, '_alma_source_id', true);
        $source_name = 'Manuale';
        if ($source_id > 0) { $source_name = (string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}alma_affiliate_sources WHERE id = %d", $source_id)); }
        echo '<p><strong>Provider:</strong> ' . esc_html($provider) . '</p>';
        echo '<p><strong>Source name:</strong> ' . esc_html($source_name ?: 'Manuale') . '</p>';
        echo '<p><strong>Import status:</strong> ' . esc_html(get_post_meta($post->ID, '_alma_import_status', true) ?: 'manual') . '</p>';
        echo '<p><strong>AI visibility:</strong> ' . esc_html(get_post_meta($post->ID, '_alma_ai_visibility', true) ?: 'available') . '</p>';
    }

    public function save_technical_meta($post_id) {
        if (!isset($_POST['alma_source_meta_nonce']) || !wp_verify_nonce($_POST['alma_source_meta_nonce'], 'alma_source_meta')) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        if (get_post_type($post_id) !== 'affiliate_link') { return; }
        if (!metadata_exists('post', $post_id, '_alma_ai_visibility')) { update_post_meta($post_id, '_alma_ai_visibility', 'available'); }
        if (!metadata_exists('post', $post_id, '_alma_import_status')) { update_post_meta($post_id, '_alma_import_status', 'manual'); }
    }
}
