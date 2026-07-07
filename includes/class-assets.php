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
        add_action('admin_head-edit.php', array($this, 'print_posts_list_css'));
        add_filter('manage_edit-post_columns', array($this, 'hide_theme_posts_columns'), PHP_INT_MAX);
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

    /**
     * Negli elenchi Articoli/Link Affiliati le molte colonne aggiunte dai
     * plugin (SEO, campi tema, Geo…) schiacciano il Titolo fino a una parola
     * per riga: gli si garantisce una larghezza minima leggibile. La tabella
     * di WordPress usa table-layout fixed, quindi la larghezza è rispettata.
     */
    public function print_posts_list_css() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, array('post', 'affiliate_link'), true)) {
            return;
        }
        echo '<style id="alma-posts-list-title-width">.wp-list-table .column-title{width:28%;min-width:280px;}.wp-list-table .column-categories{width:14%;min-width:140px;}.wp-list-table .column-alma_geo{width:120px;min-width:110px;}@media screen and (max-width:1400px){.wp-list-table .column-title{width:34%;}}</style>';
    }

    /**
     * Nasconde nell'elenco Articoli le colonne dei campi tema (Come, Cosa,
     * Perché, People, Quando, Durata): con tutte le colonne di SEO e plugin
     * il contenuto diventava illeggibile (una lettera per riga). Il match è
     * sull'ETICHETTA, così funziona qualunque sia la chiave usata dal tema.
     */
    public function hide_theme_posts_columns($columns) {
        $hidden_labels = array('come', 'cosa', 'perché', 'perche', 'people', 'quando', 'durata');
        foreach ((array) $columns as $key => $label) {
            $plain = trim(function_exists('mb_strtolower') ? mb_strtolower(wp_strip_all_tags((string) $label)) : strtolower(wp_strip_all_tags((string) $label)));
            if (in_array($plain, $hidden_labels, true)) {
                unset($columns[$key]);
            }
        }
        return $columns;
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
            $hook === 'affiliate_link_page_alma-contextual-widget' ||
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

            if (in_array($hook, array('affiliate_link_page_affiliate-link-manager-dashboard', 'affiliate_link_page_alma-ai-regia'), true)) {
                wp_enqueue_script(
                    'chart.js',
                    'https://cdn.jsdelivr.net/npm/chart.js',
                    array(),
                    '4.4.0',
                    true
                );
                if (file_exists(ALMA_PLUGIN_DIR . 'assets/dashboard-insights.js') && class_exists('ALMA_Dashboard_Insights')) {
                    wp_enqueue_script(
                        'alma-dashboard-insights',
                        ALMA_PLUGIN_URL . 'assets/dashboard-insights.js',
                        array('chart.js'),
                        ALMA_VERSION,
                        true
                    );
                    wp_localize_script('alma-dashboard-insights', 'almaInsights', ALMA_Dashboard_Insights::get_chart_payload());
                }
            }
        }

        if ($screen && in_array($hook, array('post.php', 'post-new.php')) && in_array($screen->post_type, $allowed_types, true)) {
            if (file_exists(ALMA_PLUGIN_DIR . 'assets/editor.js')) {
                // wp-data e wp-blocks garantiscono che l'inserimento del blocco
                // shortcode in Gutenberg trovi sempre le API disponibili.
                $editor_deps = array('jquery');
                if (function_exists('get_current_screen') && $screen && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
                    $editor_deps[] = 'wp-data';
                    $editor_deps[] = 'wp-blocks';
                }
                wp_enqueue_script(
                    'alma-editor-script',
                    ALMA_PLUGIN_URL . 'assets/editor.js',
                    $editor_deps,
                    ALMA_VERSION,
                    true
                );

                wp_localize_script('alma-editor-script', 'alma_editor', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('alma_editor_search'),
                    'plugin_url' => ALMA_PLUGIN_URL,
                    'post_id' => absint(get_the_ID() ?: ($_GET['post'] ?? 0)),
                    'strings' => array(
                        'button_text' => __('🔗 Link Affiliati', 'affiliate-link-manager-ai'),
                        'search_placeholder' => __('Cerca link affiliato...', 'affiliate-link-manager-ai'),
                        'no_results' => __('Nessun link trovato', 'affiliate-link-manager-ai'),
                        'insert' => __('Inserisci', 'affiliate-link-manager-ai'),
                        'insert_error' => __('Impossibile inserire automaticamente lo shortcode nell\'editor.', 'affiliate-link-manager-ai'),
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
