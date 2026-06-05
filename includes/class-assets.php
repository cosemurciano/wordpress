<?php
/**
 * Asset loading for admin, editor and frontend screens.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Assets {
    private $source_manager;

    public function __construct($source_manager = null) {
        $this->source_manager = $source_manager;
    }

    public function init() {
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('wp_head', array($this, 'output_custom_css'), 100);
    }

    public function enqueue_frontend_scripts() {
        $tracking_file = ALMA_PLUGIN_DIR . 'assets/tracking.js';
        if (file_exists($tracking_file)) {
            wp_enqueue_script(
                'alma-tracking',
                ALMA_PLUGIN_URL . 'assets/tracking.js',
                array('jquery'),
                ALMA_VERSION,
                true
            );

            wp_localize_script('alma-tracking', 'alma_tracking', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('alma_track_click'),
                'track_logged_out' => get_option('alma_track_logged_out', 'yes') === 'yes'
            ));
        }

        $style_file = ALMA_PLUGIN_DIR . 'assets/frontend.css';
        if (file_exists($style_file)) {
            wp_enqueue_style(
                'alma-frontend',
                ALMA_PLUGIN_URL . 'assets/frontend.css',
                array(),
                ALMA_VERSION
            );
        }
    }

    public function output_custom_css() {
        $css = get_option('alma_custom_css', '');
        if (!empty($css)) {
            echo "<style id='alma-custom-css'>" . wp_strip_all_tags($css) . '</style>';
        }
    }

    public function admin_enqueue_scripts($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $allowed_types = get_option('alma_link_post_types', array('post', 'page'));

        if ($screen && ($screen->post_type === 'affiliate_link' ||
            strpos($hook, 'affiliate-link-manager') !== false ||
            $hook === 'affiliate_link_page_alma-ai-content-agent' ||
            $hook === 'affiliate_link_page_alma-create-widget' ||
            $hook === 'affiliate_link_page_alma-edit-widget' ||
            $hook === 'admin_page_alma-edit-widget' ||
            $hook === 'affiliate_link_page_affiliate-link-widgets' ||
            $hook === 'index.php')) {

            if (file_exists(ALMA_PLUGIN_DIR . 'assets/admin.css')) {
                wp_enqueue_style(
                    'alma-admin-style',
                    ALMA_PLUGIN_URL . 'assets/admin.css',
                    array(),
                    ALMA_VERSION
                );
            }

            if (!in_array($hook, array('affiliate_link_page_alma-create-widget', 'affiliate_link_page_alma-edit-widget', 'admin_page_alma-edit-widget'), true) && file_exists(ALMA_PLUGIN_DIR . 'assets/ai.js')) {
                wp_enqueue_script(
                    'alma-ai-script',
                    ALMA_PLUGIN_URL . 'assets/ai.js',
                    array('jquery'),
                    ALMA_VERSION,
                    true
                );

                wp_localize_script('alma-ai-script', 'alma_ai', array(
                    'ajax_url'    => admin_url('admin-ajax.php'),
                    'nonce'       => wp_create_nonce('alma_ai_suggest_text'),
                    'messages'    => array(
                        'generating' => __('Generazione suggerimenti...', 'affiliate-link-manager-ai'),
                        'generated'  => __('Suggerimenti generati!', 'affiliate-link-manager-ai'),
                        'error'      => __('Errore durante la generazione', 'affiliate-link-manager-ai'),
                    ),
                ));
            }
            if (in_array($hook, array('affiliate_link_page_alma-ai-content-agent', 'affiliate_link_page_alma-create-widget', 'affiliate_link_page_alma-edit-widget', 'admin_page_alma-edit-widget'), true) && file_exists(ALMA_PLUGIN_DIR . 'assets/admin.js')) {
                wp_enqueue_script(
                    'alma-admin-script',
                    ALMA_PLUGIN_URL . 'assets/admin.js',
                    array('jquery'),
                    ALMA_VERSION,
                    true
                );
                wp_localize_script('alma-admin-script', 'almaAdmin', array(
                    'copiedText' => __('Copiato', 'affiliate-link-manager-ai'),
                ));
            }

            if ($hook === 'affiliate_link_page_alma-affiliate-sources') {
                if (file_exists(ALMA_PLUGIN_DIR . 'assets/affiliate-sources.css')) {
                    wp_enqueue_style('alma-affiliate-sources', ALMA_PLUGIN_URL . 'assets/affiliate-sources.css', array(), ALMA_VERSION);
                }
                if (file_exists(ALMA_PLUGIN_DIR . 'assets/affiliate-sources.js')) {
                    wp_enqueue_script('alma-affiliate-sources', ALMA_PLUGIN_URL . 'assets/affiliate-sources.js', array('jquery'), ALMA_VERSION, true);
                    if ($this->source_manager && method_exists($this->source_manager, 'get_provider_presets')) {
                        wp_localize_script('alma-affiliate-sources', 'almaSourcePresets', array('presets' => $this->source_manager->get_provider_presets(),'ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('alma_test_connection_nonce'),'gygNonce'=>wp_create_nonce('alma_gyg_csv_import_nonce'),'gygJsVersion'=>ALMA_VERSION));
                    }
                }
            }

            if ($hook === 'affiliate_link_page_affiliate-link-manager-dashboard') {
                wp_enqueue_script(
                    'chart.js',
                    'https://cdn.jsdelivr.net/npm/chart.js',
                    array(),
                    '4.4.0',
                    true
                );
            }
        }

        if ($screen && in_array($hook, array('post.php', 'post-new.php')) && in_array($screen->post_type, $allowed_types, true)) {
            if (file_exists(ALMA_PLUGIN_DIR . 'assets/editor.js')) {
                wp_enqueue_script(
                    'alma-editor-script',
                    ALMA_PLUGIN_URL . 'assets/editor.js',
                    array('jquery'),
                    ALMA_VERSION,
                    true
                );

                wp_localize_script('alma-editor-script', 'alma_editor', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('alma_editor_search'),
                    'plugin_url' => ALMA_PLUGIN_URL,
                    'strings' => array(
                        'button_text' => __('🔗 Link Affiliati', 'affiliate-link-manager-ai'),
                        'search_placeholder' => __('Cerca link affiliato...', 'affiliate-link-manager-ai'),
                        'no_results' => __('Nessun link trovato', 'affiliate-link-manager-ai'),
                        'insert' => __('Inserisci', 'affiliate-link-manager-ai'),
                        'loading' => __('Caricamento...', 'affiliate-link-manager-ai')
                    )
                ));
            }
        }

        if ($hook === 'affiliate_link_page_alma-css-editor' && function_exists('wp_enqueue_code_editor')) {
            $editor_settings = wp_enqueue_code_editor(array('type' => 'text/css'));
            wp_enqueue_script('code-editor');
            wp_enqueue_style('wp-codemirror');
            if ($editor_settings) {
                if (isset($editor_settings['codemirror'])) {
                    $editor_settings['codemirror']['lineNumbers'] = true;
                }
                wp_add_inline_script('code-editor', 'jQuery(function($){wp.codeEditor.initialize("alma-custom-css", ' . wp_json_encode($editor_settings) . ');});');
            }
        }
    }
}
