<?php
/**
 * Plugin Name: Affiliate Link Manager AI
 * Plugin URI: https://your-website.com
 * Description: Gestisce link affiliati con intelligenza artificiale per ottimizzazione e tracking automatico.
 * Version: 2.5
 * Author: Cosè Murciano
 * License: GPL v2 or later
 * Text Domain: affiliate-link-manager-ai
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisci costanti del plugin
define('ALMA_VERSION', '2.5');
define('ALMA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALMA_PLUGIN_FILE', __FILE__);

// Utilità comuni per le interazioni con l'AI
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-utils.php';

/**
 * Classe principale del plugin
 */
class AffiliateManagerAI {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Hook per gestire eliminazione link
        add_action('before_delete_post', array($this, 'before_delete_link'));
        add_action('wp_trash_post', array($this, 'before_trash_link'));
    }
    
    public function init() {
        // Carica traduzioni
        load_plugin_textdomain('affiliate-link-manager-ai', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Registra Custom Post Type
        $this->create_post_type();

        // Abilita featured image per i link affiliati
        add_theme_support('post-thumbnails', array('affiliate_link'));
        
        // Inizializza hooks
        $this->init_hooks();
        
        // Admin hooks
        if (is_admin()) {
            $this->init_admin_hooks();
        }
        
        // Frontend hooks per tracking
        if (!is_admin()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        }
    }
    
    /**
     * Enqueue scripts frontend per tracking
     */
    public function enqueue_frontend_scripts() {
        // Verifica se il file esiste prima di caricare script e stile
        $tracking_file = ALMA_PLUGIN_DIR . 'assets/tracking.js';
        if (file_exists($tracking_file)) {
            wp_enqueue_script(
                'alma-tracking',
                ALMA_PLUGIN_URL . 'assets/tracking.js',
                array('jquery'),
                ALMA_VERSION,
                true
            );

            // Passa dati al JavaScript
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
    
    /**
     * Inizializza hooks
     */
    private function init_hooks() {
        // Carica e registra widget
        require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-links-widget.php';

        // Shortcode per mostrare link singolo
        add_shortcode('affiliate_link', array($this, 'display_affiliate_link'));

        // Shortcode per mostrare elenco di link tramite widget
        add_shortcode('affiliate_links_widget', array('ALMA_Affiliate_Links_Widget', 'shortcode'));

        // Shortcode e AJAX per Affiliate Chat AI
        add_shortcode('affiliate_chat_ai', array($this, 'render_affiliate_chat_shortcode'));
        add_action('wp_ajax_alma_affiliate_chat', array($this, 'ajax_affiliate_chat'));
        add_action('wp_ajax_nopriv_alma_affiliate_chat', array($this, 'ajax_affiliate_chat'));

        // Registra il widget
        add_action('widgets_init', array($this, 'register_widget'));
        
        // Hook AJAX per tracking click (modificato per tracking asincrono)
        add_action('wp_ajax_alma_track_click', array($this, 'ajax_track_click'));
        add_action('wp_ajax_nopriv_alma_track_click', array($this, 'ajax_track_click'));
        
        // Hook per ricerca link nell'editor
        add_action('wp_ajax_alma_search_links', array($this, 'ajax_search_links'));
        add_action('wp_ajax_alma_ai_suggest_links', array($this, 'ajax_ai_suggest_links'));

        // Hook per dashboard data
        add_action('wp_ajax_alma_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_alma_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_alma_get_link_stats', array($this, 'ajax_get_link_stats'));
        
        // Cron job per ottimizzazioni automatiche
        if (!wp_next_scheduled('alma_daily_optimization')) {
            wp_schedule_event(time(), 'daily', 'alma_daily_optimization');
        }
        
        // Editor integration
        add_action('admin_footer-post.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-post-new.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-page.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-page-new.php', array($this, 'add_editor_integration'));
    }

    public function register_widget() {
        register_widget('ALMA_Affiliate_Links_Widget');
    }

    /**
     * Display affiliate link - MODIFICATO per link diretti
     */
    public function display_affiliate_link($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'text' => '',
            'class' => 'affiliate-link-btn',
            'img' => 'no',
            'img_size' => 'full',
            'fields' => '',
            'button' => 'no',
            'button_text' => '',
            'button_size' => 'medium',
            'button_align' => 'left'
        ), $atts);
        
        if (!$atts['id']) {
            return '<span style="color:red;">[Affiliate Link: ID mancante]</span>';
        }
        
        $post = get_post($atts['id']);
        if (!$post || $post->post_type !== 'affiliate_link') {
            return '<span style="color:red;">[Affiliate Link: Link non trovato]</span>';
        }
        
        $affiliate_url = get_post_meta($atts['id'], '_affiliate_url', true);
        if (!$affiliate_url) {
            return '<span style="color:red;">[Affiliate Link: URL non configurato]</span>';
        }
        
        $link_rel = get_post_meta($atts['id'], '_link_rel', true);

        if ($link_rel === '') {
            // Link interno: nessun attributo rel
        } elseif (!$link_rel) {
            $link_rel = 'sponsored noopener';
        }

        $link_target = get_post_meta($atts['id'], '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($atts['id'], '_link_title', true);

        if (empty($link_title)) {
            $link_title = get_the_title($atts['id']);
        }

        // Campi richiesti
        $fields = array_filter(array_map('trim', explode(',', $atts['fields'])));

        // Parti del contenuto
        $image_html = '';
        $title_html = '';
        $content_html = '';

        if ($atts['img'] === 'yes') {
            $size = in_array($atts['img_size'], array('thumbnail','medium','large','full')) ? $atts['img_size'] : 'full';
            $image_html = get_the_post_thumbnail($atts['id'], $size, array('class' => 'alma-affiliate-img'));
            if (!$image_html) {
                $atts['img'] = 'no';
            }
        }

        if (in_array('title', $fields)) {
            $title_text = esc_html(get_the_title($atts['id']));
            if (in_array('content', $fields)) {
                $title_html = '<h4 class="alma-link-title">' . $title_text . '</h4>';
            } else {
                $title_html = '<span class="alma-link-title">' . $title_text . '</span>';
            }
        }

        if (in_array('content', $fields)) {
            $post_content = apply_filters('the_content', get_post_field('post_content', $atts['id']));
            $content_html = '<div class="alma-link-content">' . $post_content . '</div>';
        }

        if ($title_html === '') {
            if (!empty($atts['text'])) {
                $title_html = '<span class="alma-link-title">' . esc_html($atts['text']) . '</span>';
            } elseif ($atts['img'] !== 'yes') {
                $title_html = esc_html(get_the_title($atts['id']));
            }

        }

        $link_inner = $image_html . $title_html;

        // NUOVO: Usa link diretto invece di redirect
        $link_html = '<a href="' . esc_url($affiliate_url) . '"';
        $link_html .= ' class="' . esc_attr($atts['class']) . ' alma-affiliate-link"';
        $link_html .= ' data-link-id="' . esc_attr($atts['id']) . '"';
        $link_html .= ' data-track="1"'; // Flag per tracking JavaScript
        if ($link_rel !== '') {
            $link_html .= ' rel="' . esc_attr($link_rel) . '"';
        }
        $link_html .= ' target="' . esc_attr($link_target) . '"';
        $link_html .= ' title="' . esc_attr($link_title) . '"';
        $link_html .= '>' . $link_inner . '</a>';

        if ($content_html) {
            $link_html .= $content_html;
        }

        // Pulsante call to action opzionale
        if ($atts['button'] === 'yes') {
            $size = in_array($atts['button_size'], array('small','medium','large')) ? $atts['button_size'] : 'medium';
            $alignment = in_array($atts['button_align'], array('left','center','right')) ? $atts['button_align'] : 'left';
            $btn_classes = 'alma-affiliate-button alma-btn-' . esc_attr($size) . ' alma-affiliate-link';
            $btn_text = !empty($atts['button_text']) ? esc_html($atts['button_text']) : __('Scopri di più', 'affiliate-link-manager-ai');
            $button_html = '<div class="alma-button-wrapper" style="text-align:' . esc_attr($alignment) . ';">';
            $button_html .= '<a href="' . esc_url($affiliate_url) . '"';
            $button_html .= ' class="' . $btn_classes . '"';
            $button_html .= ' data-link-id="' . esc_attr($atts['id']) . '"';
            $button_html .= ' data-track="1"';
            if ($link_rel !== '') {
                $button_html .= ' rel="' . esc_attr($link_rel) . '"';
            }
            $button_html .= ' target="' . esc_attr($link_target) . '"';
            $button_html .= ' title="' . esc_attr($link_title) . '"';
            $button_html .= '>' . $btn_text . '</a></div>';
            $link_html .= $button_html;
        }

        return $link_html;
    }

    public function render_affiliate_chat_shortcode() {
        wp_enqueue_script(
            'alma-chat-ai',
            ALMA_PLUGIN_URL . 'assets/chat-ai.js',
            array('jquery'),
            ALMA_VERSION,
            true
        );
        wp_localize_script('alma-chat-ai', 'alma_chat_ai', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('alma_affiliate_chat'),
        ));

        ob_start();
        ?>
        <form id="alma-chat-form">
            <input type="text" id="alma-chat-query" placeholder="<?php esc_attr_e('Cerca link affiliati...', 'affiliate-link-manager-ai'); ?>" required />
            <button type="submit"><?php esc_html_e('Cerca', 'affiliate-link-manager-ai'); ?></button>
        </form>
        <div id="alma-chat-response"></div>
        <?php
        return ob_get_clean();
    }

    public function ajax_affiliate_chat() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_affiliate_chat')) {
            wp_send_json_error(__('Nonce non valida', 'affiliate-link-manager-ai'));
        }

        $query = sanitize_text_field($_POST['query'] ?? '');
        if (empty($query)) {
            wp_send_json_error(__('Richiesta mancante', 'affiliate-link-manager-ai'));
        }

        $posts = get_posts(array(
            'post_type'      => 'affiliate_link',
            'numberposts'    => -1,
            'post_status'    => 'publish',
        ));

        $links = array();
        foreach ($posts as $p) {
            $types = wp_get_post_terms($p->ID, 'link_type', array('fields' => 'names'));
            if (empty($types)) {
                $types = array(__('Generale', 'affiliate-link-manager-ai'));
            }
            $url = get_post_meta($p->ID, '_affiliate_url', true);
            foreach ($types as $type) {
                $links[$type][] = array(
                    'title' => get_the_title($p->ID),
                    'url'   => esc_url_raw($url),
                );
            }
        }

        $links_text = '';
        foreach ($links as $type => $items) {
            $links_text .= "$type:\n";
            foreach ($items as $item) {
                $links_text .= '- ' . $item['title'] . ': ' . $item['url'] . "\n";
            }
        }

        $settings      = get_option('alma_prompt_ai_settings', array());
        $system_prompt  = $settings['base_prompt'] ?? '';
        if (!empty($settings['personality'])) {
            $system_prompt .= '\nTono: ' . $settings['personality'];
            if ($settings['personality'] === 'personalizzato' && !empty($settings['personality_custom'])) {
                $system_prompt .= ' (' . $settings['personality_custom'] . ')';
            }
        }

        $user_prompt = "Richiesta utente: $query\nLink disponibili:\n$links_text\n" .
            'Suggerisci i link più pertinenti organizzati per tipologia e spiega brevemente le tue scelte prima della lista.';

        $result = ALMA_AI_Utils::call_claude_api($user_prompt, $system_prompt);

        if (!$result['success']) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success(array('reply' => $result['response']));
    }

    /**
     * AJAX handler per tracking click - NUOVO
     */
    public function ajax_track_click() {
        // Verifica nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_track_click')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $link_id = isset($_POST['link_id']) ? intval($_POST['link_id']) : 0;
        
        if (!$link_id) {
            wp_send_json_error('Invalid link ID');
            return;
        }
        
        // Verifica che il link esista
        $post = get_post($link_id);
        if (!$post || $post->post_type !== 'affiliate_link') {
            wp_send_json_error('Link not found');
            return;
        }
        
        // Registra il click
        $current_count = get_post_meta($link_id, '_click_count', true) ?: 0;
        update_post_meta($link_id, '_click_count', $current_count + 1);
        update_post_meta($link_id, '_last_click', current_time('mysql'));
        
        // 🤖 Aggiorna dati per training AI
        $this->update_ai_training_data($link_id);
        
        // Registra dati analytics dettagliati
        global $wpdb;
        $table_name = $wpdb->prefix . 'alma_analytics';
        
        // Verifica se la tabella esiste
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $wpdb->insert($table_name, array(
                'link_id' => $link_id,
                'click_time' => current_time('mysql'),
                'user_ip' => $this->get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'referrer' => isset($_POST['referrer']) ? esc_url_raw($_POST['referrer']) : ''
            ));
        }
        
        wp_send_json_success(array(
            'link_id' => $link_id,
            'new_count' => $current_count + 1,
            'message' => 'Click tracked successfully'
        ));
    }
    
    /**
     * Helper per ottenere IP utente in modo sicuro
     */
    private function get_user_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = filter_var($_SERVER[$key], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
    
    /**
     * 🤖 Aggiorna dati training AI
     */
    private function update_ai_training_data($link_id) {
        $historical_data = get_post_meta($link_id, '_ai_historical_data', true) ?: array();
        $usage_data = $this->get_shortcode_usage_stats($link_id);
        $clicks = get_post_meta($link_id, '_click_count', true) ?: 0;
        $ctr = $usage_data['total_occurrences'] > 0 ? ($clicks / $usage_data['total_occurrences']) * 100 : 0;
        
        // Aggiungi punto dati storico
        $historical_data[] = array(
            'date' => current_time('Y-m-d'),
            'clicks' => $clicks,
            'impressions' => $usage_data['total_occurrences'],
            'ctr' => $ctr,
            'timestamp' => time()
        );
        
        // Mantieni solo ultimi 30 giorni
        $historical_data = array_slice($historical_data, -30);
        
        update_post_meta($link_id, '_ai_historical_data', $historical_data);
        update_post_meta($link_id, '_ai_recent_performance', time());
    }
    
    /**
     * Crea Custom Post Type per i link affiliati
     */
    public function create_post_type() {
        // Prima registra la taxonomy per le tipologie
        register_taxonomy('link_type', 'affiliate_link', array(
            'labels' => array(
                'name' => __('Tipologie Link', 'affiliate-link-manager-ai'),
                'singular_name' => __('Tipologia', 'affiliate-link-manager-ai'),
                'search_items' => __('Cerca Tipologie', 'affiliate-link-manager-ai'),
                'all_items' => __('Tutte le Tipologie', 'affiliate-link-manager-ai'),
                'edit_item' => __('Modifica Tipologia', 'affiliate-link-manager-ai'),
                'update_item' => __('Aggiorna Tipologia', 'affiliate-link-manager-ai'),
                'add_new_item' => __('Aggiungi Nuova Tipologia', 'affiliate-link-manager-ai'),
                'new_item_name' => __('Nome Nuova Tipologia', 'affiliate-link-manager-ai'),
                'menu_name' => __('Tipologie', 'affiliate-link-manager-ai'),
            ),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'link-type'),
        ));
        
        // Registra il Custom Post Type
        register_post_type('affiliate_link', array(
            'labels' => array(
                'name' => __('Link Affiliati', 'affiliate-link-manager-ai'),
                'singular_name' => __('Link Affiliato', 'affiliate-link-manager-ai'),
                'add_new' => __('Aggiungi Nuovo', 'affiliate-link-manager-ai'),
                'add_new_item' => __('Aggiungi Nuovo Link', 'affiliate-link-manager-ai'),
                'edit_item' => __('Modifica Link', 'affiliate-link-manager-ai'),
                'new_item' => __('Nuovo Link', 'affiliate-link-manager-ai'),
                'view_item' => __('Visualizza Link', 'affiliate-link-manager-ai'),
                'search_items' => __('Cerca Link', 'affiliate-link-manager-ai'),
                'not_found' => __('Nessun link trovato', 'affiliate-link-manager-ai'),
                'not_found_in_trash' => __('Nessun link nel cestino', 'affiliate-link-manager-ai'),
                'menu_name' => __('Affiliate AI', 'affiliate-link-manager-ai'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-admin-links',
            'supports' => array('title', 'editor', 'thumbnail'),
            'taxonomies' => array('link_type'),
            'has_archive' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'capabilities' => array(
                'create_posts' => 'edit_posts',
            ),
            'map_meta_cap' => true,
        ));
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_admin_hooks() {
        // Metabox per dettagli link
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_affiliate_link', array($this, 'save_link_meta'));
        
        // Colonne personalizzate nella lista
        add_filter('manage_affiliate_link_posts_columns', array($this, 'set_custom_columns'));
        add_action('manage_affiliate_link_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        add_filter('manage_edit-affiliate_link_sortable_columns', array($this, 'sortable_columns'));
        
        // Menu pages
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('admin_menu', array($this, 'reorder_dashboard_menu'), 100);

        // Cleanup shortcodes when widget instances change
        add_action('update_option_widget_affiliate_links_widget', array($this, 'on_widget_option_update'), 10, 3);
        
        // Stili e script admin
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_alma_get_ai_suggestions', array($this, 'ajax_get_ai_suggestions'));
        add_action('wp_ajax_alma_ai_suggest_text', array($this, 'ajax_ai_suggest_text'));
        add_action('wp_ajax_alma_test_claude_api', array($this, 'ajax_test_claude_api'));
        add_action('wp_ajax_alma_get_performance_predictions', array($this, 'ajax_get_performance_predictions'));
        add_action('wp_ajax_alma_get_link_types', array($this, 'ajax_get_link_types'));
        add_action('wp_ajax_alma_import_affiliate_link', array($this, 'ajax_import_affiliate_link'));

        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('pre_get_posts', array($this, 'filter_posts_without_affiliates'));
    }
    
    /**
     * Enqueue admin scripts e stili
     */
    public function admin_enqueue_scripts($hook) {
        // Solo nelle pagine del plugin
        $screen = get_current_screen();
        $allowed_types = get_option('alma_link_post_types', array('post', 'page'));
        
        if ($screen && ($screen->post_type === 'affiliate_link' || 
            strpos($hook, 'affiliate-link-manager') !== false ||
            $hook === 'index.php')) {
            
            // Stili - verifica che il file esista
            if (file_exists(ALMA_PLUGIN_DIR . 'assets/admin.css')) {
                wp_enqueue_style(
                    'alma-admin-style',
                    ALMA_PLUGIN_URL . 'assets/admin.css',
                    array(),
                    ALMA_VERSION
                );
            }
            
            // Script AI - verifica che il file esista
            if (file_exists(ALMA_PLUGIN_DIR . 'assets/ai.js')) {
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

            if ($hook === 'affiliate_link_page_affiliate-link-import') {
                if (file_exists(ALMA_PLUGIN_DIR . 'assets/import-wizard.css')) {
                    wp_enqueue_style(
                        'alma-import-wizard',
                        ALMA_PLUGIN_URL . 'assets/import-wizard.css',
                        array(),
                        ALMA_VERSION
                    );
                }
                if (file_exists(ALMA_PLUGIN_DIR . 'assets/import-wizard.js')) {
                    wp_enqueue_script(
                        'alma-import-wizard',
                        ALMA_PLUGIN_URL . 'assets/import-wizard.js',
                        array('jquery'),
                        ALMA_VERSION,
                        true
                    );
                    wp_localize_script('alma-import-wizard', 'almaImport', array(
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'nonce'    => wp_create_nonce('alma_import_links'),
                        'msg_success' => __('Importato: %s', 'affiliate-link-manager-ai'),
                        'msg_error' => __('Errore: %s', 'affiliate-link-manager-ai'),
                        'msg_duplicate' => __('Duplicato: %s', 'affiliate-link-manager-ai'),
                        'msg_no_title' => __('Titolo mancante', 'affiliate-link-manager-ai'),
                        'msg_no_url' => __('URL mancante', 'affiliate-link-manager-ai'),
                        'msg_bad_url' => __('URL non valido', 'affiliate-link-manager-ai'),
                        'msg_ajax' => __('Errore di comunicazione', 'affiliate-link-manager-ai'),
                        'msg_summary' => __('Totali: %total% | Importati: %success% | Duplicati: %dup% | Errori: %err%', 'affiliate-link-manager-ai'),
                    ));
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
        
        // Script editor solo per i tipi di contenuto selezionati
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
    }
    
    /**
     * Aggiungi metabox
     */
    public function add_meta_boxes() {
        remove_meta_box('link_typediv', 'affiliate_link', 'side');
        // Box principale configurazione
        add_meta_box(
            'affiliate_link_details',
            __('⚙️ Configurazione Link Affiliato', 'affiliate-link-manager-ai'),
            array($this, 'render_link_details_metabox'),
            'affiliate_link',
            'normal',
            'high'
        );
        
        // Box statistiche e AI
        add_meta_box(
            'affiliate_link_stats',
            __('📊 Statistiche & AI Insights', 'affiliate-link-manager-ai'),
            array($this, 'render_stats_metabox'),
            'affiliate_link',
            'side',
            'default'
        );
        
        // Box suggerimenti AI
        add_meta_box(
            'affiliate_ai_suggestions',
            __('🤖 Suggerimenti AI', 'affiliate-link-manager-ai'),
            array($this, 'render_ai_suggestions_metabox'),
            'affiliate_link',
            'normal',
            'default'
        );
    }
    
    /**
     * Render metabox dettagli link
     */
    public function render_link_details_metabox($post) {
        wp_nonce_field('save_affiliate_link', 'affiliate_link_nonce');
        
        $affiliate_url = get_post_meta($post->ID, '_affiliate_url', true);
        $link_rel = get_post_meta($post->ID, '_link_rel', true);
        if ($link_rel === '') {
            // Link interno: nessun attributo rel
        } elseif (!$link_rel) {
            $link_rel = 'sponsored noopener';
        }
        $link_target = get_post_meta($post->ID, '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($post->ID, '_link_title', true);
        $click_count = get_post_meta($post->ID, '_click_count', true) ?: 0;
        $last_click = get_post_meta($post->ID, '_last_click', true);
        $ai_score = get_post_meta($post->ID, '_ai_performance_score', true) ?: 0;
        
        echo '<table class="form-table">';
        
        // URL Affiliato
        echo '<tr>';
        echo '<th><label for="affiliate_url">' . __('URL Affiliato', 'affiliate-link-manager-ai') . ' <span style="color:red;">*</span></label></th>';
        echo '<td><input type="url" id="affiliate_url" name="affiliate_url" value="' . esc_attr($affiliate_url) . '" class="large-text" required />';
        echo '<p class="description">' . __('L\'URL completo del link affiliato (es: https://www.amazon.it/dp/...?tag=...)', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';
        
        // Relazione Link
        echo '<tr>';
        echo '<th><label for="link_rel">' . __('Tipo Relazione', 'affiliate-link-manager-ai') . '</label></th>';
        echo '<td>';
        echo '<select id="link_rel" name="link_rel" style="min-width:200px;">';
        echo '<option value=""' . selected($link_rel, '', false) . '>' . __('Link interno (Follow)', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="sponsored noopener"' . selected($link_rel, 'sponsored noopener', false) . '>' . __('Sponsored + NoOpener (Raccomandato)', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="sponsored"' . selected($link_rel, 'sponsored', false) . '>' . __('Solo Sponsored', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="nofollow"' . selected($link_rel, 'nofollow', false) . '>' . __('Nofollow (Legacy)', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="sponsored nofollow"' . selected($link_rel, 'sponsored nofollow', false) . '>' . __('Sponsored + Nofollow', 'affiliate-link-manager-ai') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Sponsored è raccomandato per link affiliati secondo le linee guida Google', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';
        
        // Target Link
        echo '<tr>';
        echo '<th><label for="link_target">' . __('Target Link', 'affiliate-link-manager-ai') . '</label></th>';
        echo '<td>';
        echo '<select id="link_target" name="link_target" style="min-width:200px;">';
        echo '<option value="_blank"' . selected($link_target, '_blank', false) . '>' . __('Nuova finestra (_blank)', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="_self"' . selected($link_target, '_self', false) . '>' . __('Stessa finestra (_self)', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="_parent"' . selected($link_target, '_parent', false) . '>' . __('Finestra padre (_parent)', 'affiliate-link-manager-ai') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Dove aprire il link quando cliccato', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';
        
        // Title Link
        echo '<tr>';
        echo '<th><label for="link_title">' . __('Title del Link', 'affiliate-link-manager-ai') . '</label></th>';
        echo '<td><input type="text" id="link_title" name="link_title" value="' . esc_attr($link_title) . '" class="regular-text" placeholder="' . esc_attr(get_the_title($post->ID)) . '" />';
        echo '<p class="description">' . __('Testo che appare al passaggio del mouse. Se vuoto, userà il titolo del link.', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';

        // Tipologie
        $selected_types = wp_get_object_terms($post->ID, 'link_type', array('fields' => 'ids'));
        $all_terms = get_terms(array('taxonomy' => 'link_type', 'hide_empty' => false));
        echo '<tr>';
        echo '<th><label for="alma_link_types">' . __('Tipologie', 'affiliate-link-manager-ai') . '</label></th>';
        echo '<td><select id="alma_link_types" name="alma_link_types[]" multiple style="min-width:200px;">';
        foreach ($all_terms as $term) {
            $sel = in_array($term->term_id, $selected_types) ? ' selected' : '';
            echo '<option value="' . intval($term->term_id) . '"' . $sel . '>' . esc_html($term->name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Seleziona una o più tipologie per il link', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';

        // Statistiche
        echo '<tr>';
        echo '<th>' . __('Statistiche Performance', 'affiliate-link-manager-ai') . '</th>';
        echo '<td>';
        echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:15px;">';
        
        echo '<div style="text-align:center;background:#f0f6fc;padding:15px;border-radius:6px;">';
        echo '<div style="font-size:24px;font-weight:bold;color:#2271b1;">' . $click_count . '</div>';
        echo '<div style="color:#666;">Click Totali</div>';
        echo '</div>';
        
        echo '<div style="text-align:center;background:#f0f9ff;padding:15px;border-radius:6px;">';
        echo '<div style="font-size:24px;font-weight:bold;color:#0891b2;">' . ($last_click ? human_time_diff(strtotime($last_click), current_time('timestamp')) . ' fa' : 'Mai') . '</div>';
        echo '<div style="color:#666;">Ultimo Click</div>';
        echo '</div>';
        
        echo '<div style="text-align:center;background:#f0fdf4;padding:15px;border-radius:6px;">';
        echo '<div style="font-size:24px;font-weight:bold;color:#16a34a;">' . $ai_score . '%</div>';
        echo '<div style="color:#666;">AI Score</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        
        // Shortcode
        echo '<tr>';
        echo '<th>' . __('Shortcode', 'affiliate-link-manager-ai') . '</th>';
        echo '<td>';
        echo '<div style="display:flex;gap:10px;align-items:center;">';
        echo '<code id="alma-shortcode-display" data-id="' . $post->ID . '" style="padding:8px 12px;background:#f0f0f0;border-radius:4px;">[affiliate_link id="' . $post->ID . '"]</code>';
        echo '<button type="button" id="alma-shortcode-copy" class="button button-small alma-copy-btn" data-copy="[affiliate_link id=&quot;' . $post->ID . '&quot;]">📋 Copia</button>';
        echo '</div>';
        echo '<div class="alma-shortcode-config" style="margin-top:8px;">';
        echo '<label><input type="checkbox" id="alma-sc-img"> ' . __('Immagine', 'affiliate-link-manager-ai') . '</label> ';
        echo '<label><input type="checkbox" id="alma-sc-title"> ' . __('Titolo', 'affiliate-link-manager-ai') . '</label> ';
        echo '<label><input type="checkbox" id="alma-sc-content"> ' . __('Contenuto', 'affiliate-link-manager-ai') . '</label>';
        echo '</div>';
        echo '<div class="alma-shortcode-config" style="margin-top:8px;">';
        echo '<label><input type="checkbox" id="alma-sc-button"> ' . __('Pulsante', 'affiliate-link-manager-ai') . '</label> ';
        echo '<select id="alma-sc-button-size" style="margin-left:5px;" disabled>';
        echo '<option value="small">' . __('Piccolo', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="medium" selected>' . __('Medio', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="large">' . __('Grande', 'affiliate-link-manager-ai') . '</option>';
        echo '</select>';
        echo '<select id="alma-sc-button-align" style="margin-left:5px;" disabled>';
        echo '<option value="left">' . __('Sinistra', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="center">' . __('Centro', 'affiliate-link-manager-ai') . '</option>';
        echo '<option value="right">' . __('Destra', 'affiliate-link-manager-ai') . '</option>';
        echo '</select>';
        echo '<input type="text" id="alma-sc-button-text" placeholder="' . esc_attr__('Testo pulsante', 'affiliate-link-manager-ai') . '" style="margin-left:5px;" disabled />';
        echo '</div>';
        echo '<p class="description">' . __('Usa questo shortcode per inserire il link nei tuoi contenuti', 'affiliate-link-manager-ai') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
    }
    
    /**
     * Render metabox statistiche
     */
    public function render_stats_metabox($post) {
        $click_count = get_post_meta($post->ID, '_click_count', true) ?: 0;
        $usage_data = $this->get_shortcode_usage_stats($post->ID);
        $ai_score = get_post_meta($post->ID, '_ai_performance_score', true) ?: 0;
        $last_optimization = get_post_meta($post->ID, '_last_ai_optimization', true);
        
        echo '<div class="alma-stats-box">';
        
        // Performance Score con colore dinamico
        $score_color = $ai_score > 70 ? '#16a34a' : ($ai_score > 40 ? '#eab308' : '#dc2626');
        echo '<div style="text-align:center;padding:20px;background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:white;border-radius:8px;margin-bottom:15px;">';
        echo '<div style="font-size:48px;font-weight:bold;">' . $ai_score . '%</div>';
        echo '<div style="font-size:14px;opacity:0.9;">AI Performance Score</div>';
        echo '</div>';
        
        // Statistiche dettagliate
        echo '<div style="space-y:10px;">';
        
        echo '<div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e5e7eb;">';
        echo '<span>📊 Click Totali:</span>';
        echo '<strong>' . number_format($click_count) . '</strong>';
        echo '</div>';
        
        echo '<div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e5e7eb;">';
        echo '<span>📝 Utilizzi:</span>';
        echo '<strong>' . $usage_data['total_occurrences'] . ' in ' . $usage_data['post_count'] . ' post</strong>';
        echo '</div>';
        
        if ($usage_data['total_occurrences'] > 0) {
            $ctr = round(($click_count / $usage_data['total_occurrences']) * 100, 2);
            echo '<div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e5e7eb;">';
            echo '<span>🎯 CTR:</span>';
            echo '<strong>' . $ctr . '%</strong>';
            echo '</div>';
        }
        
        if ($last_optimization) {
            echo '<div style="display:flex;justify-content:space-between;padding:10px 0;">';
            echo '<span>🤖 Ultima Ottimizzazione:</span>';
            echo '<strong>' . human_time_diff(strtotime($last_optimization), current_time('timestamp')) . ' fa</strong>';
            echo '</div>';
        }
        
        echo '</div>';

        echo '</div>';
    }
    
    /**
     * Render metabox suggerimenti AI
     */
    public function render_ai_suggestions_metabox($post) {
        echo '<div style="text-align:center;padding:20px;">';
        echo '<button type="button" id="alma-ai-suggest-btn" class="button button-primary" data-link-id="' . $post->ID . '">';
        echo '🤖 ' . __('Genera Suggerimenti AI', 'affiliate-link-manager-ai');
        echo '</button>';
        echo '</div>';
        echo '<div id="alma-ai-suggestions-container"></div>';
    }
    
    /**
     * Salva meta del link
     */
    public function save_link_meta($post_id) {
        if (!isset($_POST['affiliate_link_nonce']) || 
            !wp_verify_nonce($_POST['affiliate_link_nonce'], 'save_affiliate_link')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Salva URL affiliato
        if (isset($_POST['affiliate_url'])) {
            update_post_meta($post_id, '_affiliate_url', esc_url_raw($_POST['affiliate_url']));
        }
        
        // Salva relazione link
        if (isset($_POST['link_rel'])) {
            update_post_meta($post_id, '_link_rel', sanitize_text_field($_POST['link_rel']));
        }
        
        // Salva target
        if (isset($_POST['link_target'])) {
            update_post_meta($post_id, '_link_target', sanitize_text_field($_POST['link_target']));
        }
        
        // Salva title
        if (isset($_POST['link_title'])) {
            update_post_meta($post_id, '_link_title', sanitize_text_field($_POST['link_title']));
        }

        // Salva tipologie
        if (isset($_POST['alma_link_types'])) {
            $types = array_map('intval', (array)$_POST['alma_link_types']);
            wp_set_object_terms($post_id, $types, 'link_type');
        }

        // Calcola AI Performance Score iniziale
        $this->calculate_ai_performance_score($post_id);
    }
    
    /**
     * Colonne personalizzate
     */
    public function set_custom_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            if ($key === 'cb') {
                $new_columns[$key] = $value;
                $new_columns['id'] = __('ID', 'affiliate-link-manager-ai');
            } elseif ($key === 'title') {
                $new_columns[$key] = $value;
                $new_columns['shortcode'] = __('Shortcode', 'affiliate-link-manager-ai');
                $new_columns['link_type'] = __('Tipologia', 'affiliate-link-manager-ai');
                $new_columns['clicks'] = __('Click', 'affiliate-link-manager-ai');
                $new_columns['ai_score'] = __('AI Score', 'affiliate-link-manager-ai');
                $new_columns['usage'] = __('Utilizzi', 'affiliate-link-manager-ai');
            } elseif ($key !== 'date') {
                $new_columns[$key] = $value;
            }
        }
        
        $new_columns['date'] = __('Data', 'affiliate-link-manager-ai');
        
        return $new_columns;
    }
    
    /**
     * Contenuto colonne personalizzate
     */
    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'id':
                echo intval($post_id);
                break;
            case 'shortcode':
                echo '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">[affiliate_link id="' . $post_id . '"]</code>';
                echo ' <button class="button button-small alma-copy-btn" data-copy="[affiliate_link id=&quot;' . $post_id . '&quot;]" style="margin-left:5px;">📋</button>';
                break;
                
            case 'link_type':
                $terms = get_the_terms($post_id, 'link_type');
                if ($terms && !is_wp_error($terms)) {
                    $term_names = wp_list_pluck($terms, 'name');
                    echo implode(', ', $term_names);
                } else {
                    echo '—';
                }
                break;
                
            case 'clicks':
                $clicks = get_post_meta($post_id, '_click_count', true) ?: 0;
                echo '<strong>' . number_format($clicks) . '</strong>';
                break;
                
            case 'ai_score':
                $score = get_post_meta($post_id, '_ai_performance_score', true) ?: 0;
                $color = $score > 70 ? '#16a34a' : ($score > 40 ? '#eab308' : '#dc2626');
                echo '<span style="color:' . $color . ';font-weight:bold;">' . $score . '%</span>';
                break;
                
            case 'usage':
                $usage_data = $this->get_shortcode_usage_stats($post_id);
                if ($usage_data['post_count'] > 0) {
                    echo '<a href="' . admin_url('admin.php?page=alma-usage-details&link_id=' . $post_id) . '">';
                    echo $usage_data['post_count'] . ' post';
                    echo '</a>';
                } else {
                    echo '<span style="color:#999;">Non utilizzato</span>';
                }
                break;
        }
    }
    
    /**
     * Colonne ordinabili
     */
    public function sortable_columns($columns) {
        $columns['clicks'] = 'clicks';
        $columns['ai_score'] = 'ai_score';
        return $columns;
    }
    
    /**
     * Aggiungi pagine admin
     */
    public function add_admin_pages() {
        // Dashboard principale
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Dashboard', 'affiliate-link-manager-ai'),
            __('Dashboard', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-link-manager-dashboard',
            array($this, 'render_dashboard_page')
        );
        
        // Impostazioni
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Impostazioni', 'affiliate-link-manager-ai'),
            __('Impostazioni', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-link-manager-settings',
            array($this, 'render_settings_page')
        );

        // Importazione massiva
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Importa Link', 'affiliate-link-manager-ai'),
            __('Importa Link', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-link-import',
            array($this, 'render_import_page')
        );

        // Creazione widget
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Crea Widget Link AI', 'affiliate-link-manager-ai'),
            __('Crea Widget Link AI', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-create-widget',
            array($this, 'render_create_widget_page')
        );

        // Shortcode widget
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Shortcode Widget', 'affiliate-link-manager-ai'),
            __('Shortcode Widget', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-link-widgets',
            array($this, 'render_widget_shortcode_page')
        );

        // Affiliate Chat AI
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Affiliate Chat AI', 'affiliate-link-manager-ai'),
            __('Affiliate Chat AI', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-chat-ai',
            array($this, 'render_affiliate_chat_ai_page')
        );

        // Pagina nascosta per modifica widget
        add_submenu_page(
            null,
            __('Modifica Widget', 'affiliate-link-manager-ai'),
            __('Modifica Widget', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-edit-widget',
            array($this, 'render_edit_widget_page')
        );


        // Pagina nascosta per dettagli utilizzo
        add_submenu_page(
            null,
            __('Dettagli Utilizzo Link', 'affiliate-link-manager-ai'),
            __('Dettagli Utilizzo', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-usage-details',
            array($this, 'usage_details_page')
        );
    }

    /**
     * Reorder submenu: dashboard first and widget pages after taxonomy.
     */
    public function reorder_dashboard_menu() {
        global $submenu;
        $parent = 'edit.php?post_type=affiliate_link';
        if (!isset($submenu[$parent])) {
            return;
        }

        $items = $submenu[$parent];
        $order = array(
            'affiliate-link-manager-dashboard',
            'edit.php?post_type=affiliate_link',
            'post-new.php?post_type=affiliate_link',
            'edit-tags.php?taxonomy=link_type&post_type=affiliate_link',
            'alma-create-widget',
            'affiliate-link-widgets',
            'affiliate-chat-ai',
            'affiliate-link-import',
            'alma-prompt-ai-settings',
            'affiliate-link-manager-settings',
        );

        $new = array();
        foreach ($order as $slug) {
            foreach ($items as $item) {
                if ($item[2] === $slug) {
                    switch ($slug) {
                        case 'affiliate-link-manager-dashboard':
                            $item[0] = __('Dashboard', 'affiliate-link-manager-ai');
                            break;
                        case 'edit.php?post_type=affiliate_link':
                            $item[0] = __('Affiliate Link AI', 'affiliate-link-manager-ai');
                            break;
                        case 'post-new.php?post_type=affiliate_link':
                            $item[0] = __('Aggiungi Link', 'affiliate-link-manager-ai');
                            break;
                        case 'edit-tags.php?taxonomy=link_type&post_type=affiliate_link':
                            $item[0] = __('Tipologie Link', 'affiliate-link-manager-ai');
                            break;
                        case 'alma-create-widget':
                            $item[0] = __('Crea Widget Link AI', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-link-widgets':
                            $item[0] = __('Shortcode Widget', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-chat-ai':
                            $item[0] = __('Affiliate Chat AI', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-link-import':
                            $item[0] = __('Importa Link', 'affiliate-link-manager-ai');
                            break;
                        case 'alma-prompt-ai-settings':
                            $item[0] = __('Prompt AI', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-link-manager-settings':
                            $item[0] = __('Impostazioni', 'affiliate-link-manager-ai');
                            break;
                    }
                    $new[] = $item;
                    break;
                }
            }
        }

        $submenu[$parent] = $new;
    }
    
    /**
     * Render Dashboard Page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }
        
        $total_clicks = $this->get_total_clicks();
        $total_links = wp_count_posts('affiliate_link')->publish;
        $unused_links = $this->get_unused_links_count();
        $top_links = $this->get_top_performing_links(10);

        global $wpdb;
        $total_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");
        $like = $wpdb->esc_like('[affiliate_link');
        $posts_with_links = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content LIKE %s", '%' . $like . '%'));
        $posts_without_links = $total_posts - $posts_with_links;
        $no_affiliate_url = admin_url('edit.php?post_type=post&alma_no_affiliates=1');
        
        ?>
        <div class="wrap">
            <h1><?php _e('Dashboard Link - Affiliate Link Manager', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:14px;color:#666;margin:5px 0 10px;">
                <?php _e('Gestione e monitoraggio dei link affiliati direttamente dal tuo sito.', 'affiliate-link-manager-ai'); ?>
            </p>
            <p style="font-size:14px;color:#666;">Versione <?php echo ALMA_VERSION; ?></p>

            <!-- Statistiche Principali -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);grid-template-rows:auto auto;gap:20px;margin:30px 0;">

                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;grid-row:span 2;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">📝 Stato Articoli</h3>
                    <p style="margin:0 0 5px 0;color:#23282d;">
                        <strong><?php echo number_format($posts_with_links); ?></strong> articoli con link affiliati
                    </p>
                    <p style="margin:0;color:#23282d;">
                        <a href="<?php echo esc_url($no_affiliate_url); ?>">
                            <strong><?php echo number_format($posts_without_links); ?></strong> articoli senza link affiliati
                        </a>
                    </p>
                    <canvas id="alma-posts-pie" style="max-width:200px;margin-top:15px;"></canvas>
                </div>

                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🔗 Link Attivi</h3>
                    <div style="font-size:36px;font-weight:bold;color:#00a32a;"><?php echo number_format($total_links); ?></div>
                    <p style="color:#666;margin:5px 0 0 0;">Link pubblicati</p>
                </div>

                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">📊 Click Totali</h3>
                    <div style="font-size:36px;font-weight:bold;color:#2271b1;"><?php echo number_format($total_clicks); ?></div>
                    <p style="color:#666;margin:5px 0 0 0;">Su tutti i link affiliati</p>
                </div>

                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🚫 Link Non Utilizzati</h3>
                    <div style="font-size:36px;font-weight:bold;color:#8e44ad;">
                        <?php echo number_format($unused_links); ?>
                    </div>
                    <p style="color:#666;margin:5px 0 0 0;">Link senza utilizzo</p>
                </div>

                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🎯 CTR Medio</h3>
                    <div style="font-size:36px;font-weight:bold;color:#d63638;">
                        <?php
                        $avg_ctr = $this->get_average_ctr();
                        echo $avg_ctr . '%';
                        ?>
                    </div>
                    <p style="color:#666;margin:5px 0 0 0;">Click-through rate</p>
                </div>

            </div>

            <!-- Andamento Click Totali -->
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;margin-bottom:30px;">
                <h2>📈 Andamento Click Totali</h2>
                <p style="margin-top:0;color:#666;">Grafico dell'evoluzione reale dei click sui link affiliati.</p>
                <div style="display:flex;flex-wrap:wrap;gap:20px;">
                    <div style="flex:1 1 300px;">
                        <h3>Mensile</h3>
                        <canvas id="alma-clicks-monthly"></canvas>
                    </div>
                    <div style="flex:1 1 300px;">
                        <h3>Settimanale</h3>
                        <canvas id="alma-clicks-weekly"></canvas>
                    </div>
                </div>
            </div>

            <!-- Altre Statistiche -->
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;margin-bottom:30px;">
                <h2>📊 Altre Statistiche</h2>
                <p style="margin-top:0;color:#666;">Andamento dei link pubblicati e del CTR medio nel tempo.</p>
                <div style="display:flex;flex-wrap:wrap;gap:20px;">
                    <div style="flex:1 1 300px;">
                        <h3>Link Attivi</h3>
                        <canvas id="alma-links-trend"></canvas>
                    </div>
                    <div style="flex:1 1 300px;">
                        <h3>CTR Medio</h3>
                        <canvas id="alma-ctr-trend"></canvas>
                    </div>
                </div>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var ctx = document.getElementById('alma-posts-pie');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: ['Con link affiliati', 'Senza link affiliati'],
                            datasets: [{
                                data: [<?php echo $posts_with_links; ?>, <?php echo $posts_without_links; ?>],
                                backgroundColor: ['#16a34a', '#dc2626']
                            }]
                        },
                        options: {
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }

                function fetchChart(metric, range, canvasId, label, color) {
                    var canvas = document.getElementById(canvasId);
                    if (!canvas) return;
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'alma_get_chart_data',
                            nonce: '<?php echo wp_create_nonce('alma_admin_nonce'); ?>',
                            metric: metric,
                            range: range
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) return;
                        new Chart(canvas, {
                            type: 'line',
                            data: {
                                labels: data.data.labels,
                                datasets: [{
                                    label: label,
                                    data: data.data.data,
                                    borderColor: color,
                                    backgroundColor: color,
                                    fill: false
                                }]
                            },
                            options: {
                                scales: {
                                    y: { beginAtZero: true }
                                }
                            }
                        });
                    });
                }

                fetchChart('clicks', 'monthly', 'alma-clicks-monthly', 'Click Mensili', '#2271b1');
                fetchChart('clicks', 'weekly', 'alma-clicks-weekly', 'Click Settimanali', '#00a32a');
                fetchChart('links', 'monthly', 'alma-links-trend', 'Link Attivi', '#00a32a');
                fetchChart('ctr', 'monthly', 'alma-ctr-trend', 'CTR Medio', '#d63638');
            });
            </script>

            <!-- Top Performing Links -->
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;margin-bottom:30px;">
                <h2>🏆 Top 10 Link Performanti</h2>
                <?php if (!empty($top_links)) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Link</th>
                                <th style="width:100px;">Click</th>
                                <th style="width:100px;">AI Score</th>
                                <th style="width:150px;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_links as $link) : 
                                $ai_score = get_post_meta($link->ID, '_ai_performance_score', true) ?: 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <a href="<?php echo get_edit_post_link($link->ID); ?>">
                                                <?php echo esc_html($link->post_title); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td><strong><?php echo number_format($link->click_count); ?></strong></td>
                                    <td>
                                        <?php
                                        $color = $ai_score > 70 ? '#16a34a' : ($ai_score > 40 ? '#eab308' : '#dc2626');
                                        echo '<span style="color:' . $color . ';font-weight:bold;">' . $ai_score . '%</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($link->ID); ?>" class="button button-small">Modifica</a>
                                        <a href="<?php echo admin_url('admin.php?page=alma-usage-details&link_id=' . $link->ID); ?>" class="button button-small">Dettagli</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>Nessun dato disponibile.</p>
                <?php endif; ?>
            </div>
            
            <!-- AI Insights -->
            <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                <h2>🤖 AI Insights & Suggerimenti</h2>
                <div style="padding:15px;background:#f0f6fc;border-left:4px solid #2271b1;margin:15px 0;">
                    <strong>💡 Suggerimento del giorno:</strong><br>
                    I link con CTR inferiore al 2% potrebbero beneficiare di un testo più accattivante. 
                    Prova a usare parole d'azione come "Scopri", "Ottieni" o "Risparmia".
                </div>
                <div style="padding:15px;background:#f0fdf4;border-left:4px solid #16a34a;margin:15px 0;">
                    <strong>📈 Trend positivo:</strong><br>
                    I tuoi link hanno registrato un aumento del 15% nei click nell'ultima settimana.
                </div>
            </div>
        </div>
        <?php
    }    
    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        // Elimina link per ID
        if (isset($_POST['alma_delete_by_id']) &&
            isset($_POST['alma_delete_links_nonce']) &&
            wp_verify_nonce($_POST['alma_delete_links_nonce'], 'alma_delete_links')) {
            $delete_id = intval($_POST['delete_link_id']);
            if ($delete_id) {
                wp_delete_post($delete_id, true);
                echo '<div class="notice notice-success"><p>' . __('Link eliminato.', 'affiliate-link-manager-ai') . '</p></div>';
            }
        }

        // Elimina link per tipologia
        if (isset($_POST['alma_delete_by_type']) &&
            isset($_POST['alma_delete_links_nonce']) &&
            wp_verify_nonce($_POST['alma_delete_links_nonce'], 'alma_delete_links')) {
            $type_id = intval($_POST['delete_link_type']);
            if ($type_id) {
                $posts = get_posts(array(
                    'post_type' => 'affiliate_link',
                    'numberposts' => -1,
                    'fields' => 'ids',
                    'tax_query' => array(array(
                        'taxonomy' => 'link_type',
                        'field'    => 'term_id',
                        'terms'    => $type_id,
                    )),
                ));
                foreach ($posts as $pid) {
                    wp_delete_post($pid, true);
                }
                echo '<div class="notice notice-success"><p>' . sprintf(__('Eliminati %d link.', 'affiliate-link-manager-ai'), count($posts)) . '</p></div>';
            }
        }

        // Salva impostazioni se form inviato
        if (isset($_POST['alma_save_settings']) &&
            isset($_POST['alma_settings_nonce']) &&
            wp_verify_nonce($_POST['alma_settings_nonce'], 'alma_save_settings')) {

            // Impostazioni generali
            update_option('alma_track_logged_out', sanitize_text_field($_POST['track_logged_out'] ?? 'yes'));
            update_option('alma_enable_ai', sanitize_text_field($_POST['enable_ai'] ?? 'yes'));

            // Editor integration
            $selected_types = array_map('sanitize_text_field', $_POST['alma_link_post_types'] ?? array());
            update_option('alma_link_post_types', $selected_types);

            // Claude API settings
            update_option('alma_claude_api_key', sanitize_text_field($_POST['claude_api_key'] ?? ''));
            update_option('alma_claude_model', sanitize_text_field($_POST['claude_model'] ?? 'claude-3-haiku-20240307'));
            update_option('alma_claude_temperature', floatval($_POST['claude_temperature'] ?? 0.7));
            
            echo '<div class="notice notice-success"><p>' . __('Impostazioni salvate!', 'affiliate-link-manager-ai') . '</p></div>';
        }
        
        // Recupera impostazioni attuali
        $track_logged_out = get_option('alma_track_logged_out', 'yes');
        $enable_ai = get_option('alma_enable_ai', 'yes');
        $claude_api_key = get_option('alma_claude_api_key', '');
        $claude_model = get_option('alma_claude_model', 'claude-3-haiku-20240307');
        $claude_temperature = get_option('alma_claude_temperature', 0.7);
        $allowed_post_types = get_option('alma_link_post_types', array('post', 'page'));

        ?>
        <div class="wrap">
            <h1><?php _e('Impostazioni - Affiliate Link Manager AI', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:14px;color:#666;">Versione <?php echo ALMA_VERSION; ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('alma_save_settings', 'alma_settings_nonce'); ?>
                <?php wp_nonce_field('alma_delete_links', 'alma_delete_links_nonce'); ?>
                
                <!-- Tabs Navigation -->
                <h2 class="nav-tab-wrapper alma-settings-tabs">
                    <a href="#general" class="nav-tab nav-tab-active">Generale</a>
                    <a href="#tracking" class="nav-tab">Tracking</a>
                    <a href="#ai" class="nav-tab">AI Settings</a>
                    <a href="#claude" class="nav-tab">Claude API</a>
                    <a href="#editor" class="nav-tab">Editor</a>
                    <a href="#cleanup" class="nav-tab">Pulizia</a>
                </h2>
                
                <!-- General Settings -->
                <div id="general" class="alma-settings-section">
                    <h2>Impostazioni Generali</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="enable_ai"><?php _e('Abilita funzionalità AI', 'affiliate-link-manager-ai'); ?></label>
                            </th>
                            <td>
                                <select name="enable_ai" id="enable_ai">
                                    <option value="yes" <?php selected($enable_ai, 'yes'); ?>>Sì</option>
                                    <option value="no" <?php selected($enable_ai, 'no'); ?>>No</option>
                                </select>
                                <p class="description">Abilita suggerimenti AI e ottimizzazioni automatiche</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Tracking Settings -->
                <div id="tracking" class="alma-settings-section" style="display:none;">
                    <h2>Impostazioni Tracking</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="track_logged_out"><?php _e('Traccia utenti non loggati', 'affiliate-link-manager-ai'); ?></label>
                            </th>
                            <td>
                                <select name="track_logged_out" id="track_logged_out">
                                    <option value="yes" <?php selected($track_logged_out, 'yes'); ?>>Sì</option>
                                    <option value="no" <?php selected($track_logged_out, 'no'); ?>>No</option>
                                </select>
                                <p class="description">Traccia click anche per visitatori non registrati</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- AI Settings -->
                <div id="ai" class="alma-settings-section" style="display:none;">
                    <h2>🤖 Impostazioni Intelligenza Artificiale</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">AI Performance Scoring</th>
                            <td>
                                <label>
                                    <input type="checkbox" checked disabled /> 
                                    Calcolo automatico performance score
                                </label>
                                <p class="description">Analizza CTR, utilizzo e engagement per ogni link</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Suggerimenti Automatici</th>
                            <td>
                                <label>
                                    <input type="checkbox" checked disabled /> 
                                    Genera suggerimenti per migliorare performance
                                </label>
                                <p class="description">Suggerimenti basati su pattern di successo</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Claude API Settings -->
                <div id="claude" class="alma-settings-section" style="display:none;">
                    <h2>🧠 Claude API Configuration</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="claude_api_key">API Key</label>
                            </th>
                            <td>
                                <input type="password" 
                                       name="claude_api_key" 
                                       id="claude_api_key" 
                                       value="<?php echo esc_attr($claude_api_key); ?>" 
                                       class="regular-text" />
                                <button type="button" class="button alma-toggle-api-key">👁 Mostra</button>
                                <p class="description">
                                    Ottieni la tua API key da 
                                    <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="claude_model">Modello</label>
                            </th>
                            <td>
                                <select name="claude_model" id="claude_model">
                                    <option value="claude-3-haiku-20240307" <?php selected($claude_model, 'claude-3-haiku-20240307'); ?>>
                                        Claude 3 Haiku (Veloce ed economico)
                                    </option>
                                    <option value="claude-3-sonnet-20240229" <?php selected($claude_model, 'claude-3-sonnet-20240229'); ?>>
                                        Claude 3 Sonnet (Bilanciato)
                                    </option>
                                    <option value="claude-3-opus-20240229" <?php selected($claude_model, 'claude-3-opus-20240229'); ?>>
                                        Claude 3 Opus (Più potente)
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="claude_temperature">Temperature</label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="claude_temperature" 
                                       id="claude_temperature" 
                                       value="<?php echo esc_attr($claude_temperature); ?>" 
                                       min="0" 
                                       max="1" 
                                       step="0.1" 
                                       style="width:80px;" />
                                <p class="description">0 = Deterministico, 1 = Creativo (default: 0.7)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Test Connessione</th>
                            <td>
                                <button type="button" id="test-claude-connection" class="button">
                                    🧪 Testa Connessione
                                </button>
                                <div id="claude-test-result" style="margin-top:10px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Editor Settings -->
                <div id="editor" class="alma-settings-section" style="display:none;">
                    <h2><?php _e('Tipi di contenuto abilitati', 'affiliate-link-manager-ai'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label><?php _e('Mostra pulsante "Aggiungi Nuovo Link" in:', 'affiliate-link-manager-ai'); ?></label>
                            </th>
                            <td>
                                <?php
                                $post_types = get_post_types(array('show_ui' => true), 'objects');
                                unset($post_types['affiliate_link']);
                                foreach ($post_types as $pt) {
                                    $checked = in_array($pt->name, $allowed_post_types, true) ? 'checked' : '';
                                    echo '<label><input type="checkbox" name="alma_link_post_types[]" value="' . esc_attr($pt->name) . '" ' . $checked . '> ' . esc_html($pt->labels->singular_name) . '</label><br />';
                                }
                                ?>
                                <p class="description"><?php _e('Seleziona i contenuti in cui visualizzare il pulsante per inserire link affiliati.', 'affiliate-link-manager-ai'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Cleanup Settings -->
                <div id="cleanup" class="alma-settings-section" style="display:none;">
                    <h2>Impostazioni Pulizia</h2>
                    <p><?php _e('Quando un link viene eliminato, è rimosso definitivamente dal database e tutti gli shortcode associati vengono eliminati dai contenuti.', 'affiliate-link-manager-ai'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="delete_link_id"><?php _e('Elimina per ID', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <input type="number" name="delete_link_id" id="delete_link_id" />
                                <?php submit_button(__('Elimina', 'affiliate-link-manager-ai'), 'delete', 'alma_delete_by_id', false); ?>
                                <p class="description">ID del link affiliato da eliminare</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="delete_link_type"><?php _e('Elimina per Tipologia', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <?php wp_dropdown_categories(array(
                                    'taxonomy' => 'link_type',
                                    'name' => 'delete_link_type',
                                    'hide_empty' => false,
                                    'show_option_none' => __('Seleziona tipologia', 'affiliate-link-manager-ai'),
                                )); ?>
                                <?php submit_button(__('Elimina', 'affiliate-link-manager-ai'), 'delete', 'alma_delete_by_type', false); ?>
                                <p class="description">Rimuove tutti i link della tipologia selezionata</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="alma_save_settings" class="button-primary" value="<?php _e('Salva Impostazioni', 'affiliate-link-manager-ai'); ?>" />
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.alma-settings-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');

                $('.alma-settings-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.alma-settings-section').hide();
                $(target).show();
            });

            // Toggle API key visibility
            $('.alma-toggle-api-key').on('click', function() {
                var $input = $('#claude_api_key');
                var type = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
                $(this).text(type === 'password' ? '👁 Mostra' : '🙈 Nascondi');
            });

            // Test Claude API connection
            $('#test-claude-connection').on('click', function(e) {
                e.preventDefault();
                var $result = $('#claude-test-result');
                $result.html('<span class="spinner is-active" style="float:none; margin-top:0;"></span> Test in corso...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'alma_test_claude_api',
                        nonce: '<?php echo wp_create_nonce("alma_admin_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color:green;">✅ Connessione OK (' +
                                response.data.model + ' - ' + response.data.response_time + 'ms)</span>');
                        } else {
                            $result.html('<span style="color:#dc3232;">❌ ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color:#dc3232;">❌ Errore di connessione</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Widget Dashboard
     */
    public function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'alma_dashboard_widget',
            '🔗 Affiliate Link Manager AI',
            array($this, 'render_dashboard_widget')
        );
    }
    
    public function render_dashboard_widget() {
        $total_clicks = $this->get_total_clicks();
        $total_links = wp_count_posts('affiliate_link')->publish;
        $recent_clicks = $this->get_recent_clicks(7);
        
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">';
        echo '<div style="text-align:center;padding:10px;background:#f0f6fc;border-radius:6px;">';
        echo '<div style="font-size:24px;font-weight:bold;color:#2271b1;">' . number_format($total_clicks) . '</div>';
        echo '<div style="color:#666;font-size:12px;">Click Totali</div>';
        echo '</div>';
        echo '<div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:6px;">';
        echo '<div style="font-size:24px;font-weight:bold;color:#16a34a;">' . $total_links . '</div>';
        echo '<div style="color:#666;font-size:12px;">Link Attivi</div>';
        echo '</div>';
        echo '</div>';
        
        echo '<p style="text-align:center;margin:15px 0;">';
        echo '<a href="' . admin_url('edit.php?post_type=affiliate_link') . '" class="button button-primary">Gestisci Link</a> ';
        echo '<a href="' . admin_url('admin.php?page=affiliate-link-manager-dashboard') . '" class="button">Dashboard Completa</a>';
        echo '</p>';
    }

    /**
     * Pagina importazione massiva
     */
    public function render_import_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $types = get_terms(array(
            'taxonomy' => 'link_type',
            'hide_empty' => false,
        ));

        ?>
        <div class="wrap">
            <h1><?php _e('Importa Link Affiliati', 'affiliate-link-manager-ai'); ?></h1>

            <ol class="alma-import-steps">
                <li class="active"><?php _e('Inserimento', 'affiliate-link-manager-ai'); ?></li>
                <li><?php _e('Anteprima', 'affiliate-link-manager-ai'); ?></li>
                <li><?php _e('Importazione', 'affiliate-link-manager-ai'); ?></li>
            </ol>

            <div id="alma-step1" class="alma-step">
                <h2><?php _e('1. Inserisci i link', 'affiliate-link-manager-ai'); ?></h2>
                <p><?php _e('Inserisci un link per riga nel formato <strong>Titolo|URL</strong>. Ogni riga valida creerà un nuovo Link Affiliato. Esempio:<br><code>Nome prodotto|https://esempio.com</code>', 'affiliate-link-manager-ai'); ?></p>
                <textarea id="alma-import-input" rows="10" style="width:100%;"></textarea>
                <p>
                    <?php _e('Totale righe', 'affiliate-link-manager-ai'); ?>: <span id="alma-line-count">0</span> ·
                    <?php _e('Valide', 'affiliate-link-manager-ai'); ?>: <span id="alma-valid-count">0</span> ·
                    <?php _e('Errori', 'affiliate-link-manager-ai'); ?>: <span id="alma-error-count">0</span>
                </p>
                <p class="alma-step-actions">
                    <button id="alma-to-step2" class="button button-primary"><?php _e('Avanti', 'affiliate-link-manager-ai'); ?></button>
                </p>
            </div>

            <div id="alma-step2" class="alma-step" style="display:none;">
                <h2><?php _e('2. Anteprima e validazione', 'affiliate-link-manager-ai'); ?></h2>
                <table id="alma-preview" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Titolo', 'affiliate-link-manager-ai'); ?></th>
                            <th>URL</th>
                            <th><?php _e('Errore', 'affiliate-link-manager-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <p>
                    <?php _e('Totali', 'affiliate-link-manager-ai'); ?>: <span id="alma-total-preview">0</span> ·
                    <?php _e('Validi', 'affiliate-link-manager-ai'); ?>: <span id="alma-valid-preview">0</span> ·
                    <?php _e('Errori', 'affiliate-link-manager-ai'); ?>: <span id="alma-error-preview">0</span>
                </p>
                <h3><?php _e('Impostazioni importazione', 'affiliate-link-manager-ai'); ?></h3>
                <p>
                    <label for="alma-import-status"><?php _e('Stato dei nuovi link', 'affiliate-link-manager-ai'); ?></label>
                    <select id="alma-import-status">
                        <option value="draft"><?php _e('Bozza', 'affiliate-link-manager-ai'); ?></option>
                        <option value="publish"><?php _e('Pubblicato', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                </p>
                <p>
                    <label for="alma-import-rel"><?php _e('Tipo Relazione', 'affiliate-link-manager-ai'); ?></label>
                    <select id="alma-import-rel" style="min-width:200px;">
                        <option value=""><?php _e('Link interno (Follow)', 'affiliate-link-manager-ai'); ?></option>
                        <option value="sponsored noopener" selected><?php _e('Sponsored + NoOpener (Raccomandato)', 'affiliate-link-manager-ai'); ?></option>
                        <option value="sponsored"><?php _e('Solo Sponsored', 'affiliate-link-manager-ai'); ?></option>
                        <option value="nofollow"><?php _e('Nofollow (Legacy)', 'affiliate-link-manager-ai'); ?></option>
                        <option value="sponsored nofollow"><?php _e('Sponsored + Nofollow', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                </p>
                <p>
                    <label for="alma-import-target"><?php _e('Target Link', 'affiliate-link-manager-ai'); ?></label>
                    <select id="alma-import-target" style="min-width:200px;">
                        <option value="_blank" selected><?php _e('Nuova finestra (_blank)', 'affiliate-link-manager-ai'); ?></option>
                        <option value="_self"><?php _e('Stessa finestra (_self)', 'affiliate-link-manager-ai'); ?></option>
                        <option value="_parent"><?php _e('Finestra padre (_parent)', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                </p>
                <div id="alma-import-types">
                    <p><?php _e('Tipologie da assegnare', 'affiliate-link-manager-ai'); ?>:</p>
                    <?php if (!is_wp_error($types) && !empty($types)) : ?>
                        <?php foreach ($types as $type) : ?>
                            <label style="margin-right:15px;">
                                <input type="checkbox" value="<?php echo esc_attr($type->term_id); ?>"> <?php echo esc_html($type->name); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p><?php _e('Nessuna tipologia disponibile.', 'affiliate-link-manager-ai'); ?></p>
                    <?php endif; ?>
                </div>
                <p class="alma-step-actions">
                    <button id="alma-back-step1" class="button"><?php _e('Indietro', 'affiliate-link-manager-ai'); ?></button>
                    <button id="alma-to-step3" class="button button-primary"><?php _e('Importa', 'affiliate-link-manager-ai'); ?></button>
                </p>
            </div>

            <div id="alma-step3" class="alma-step" style="display:none;">
                <h2><?php _e('3. Importazione', 'affiliate-link-manager-ai'); ?></h2>
                <div class="alma-progress">
                    <div id="alma-progress-bar"></div>
                </div>
                <div id="alma-log" style="max-height:200px;overflow:auto;margin-top:15px;"></div>
                <div id="alma-final-stats" style="margin-top:15px;"></div>
                <p class="alma-step-actions">
                    <button id="alma-restart" class="button" style="display:none;">
                        <?php _e('Importa altri link', 'affiliate-link-manager-ai'); ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Pagina creazione widget
     */
    public function render_create_widget_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $created     = false;
        $shortcode   = '';
        $php_code    = '';
        $suggestions = array();
        $instance    = array(
            'title'        => '',
            'custom_content' => '',
            'show_image'   => 0,
            'show_title'   => 0,
            'show_content' => 0,
            'show_button'  => 0,
            'button_text'  => '',
            'format'       => 'large',
            'orientation'  => 'vertical',
            'links'        => array(),
            'manual_ids'   => array(),
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alma_create_widget'])) {
            check_admin_referer('alma_create_widget');

            $instance['title']        = sanitize_text_field($_POST['title'] ?? '');
            $instance['custom_content'] = wp_kses_post($_POST['custom_content'] ?? '');
            $instance['show_image']   = !empty($_POST['show_image']) ? 1 : 0;
            $instance['show_title']   = !empty($_POST['show_title']) ? 1 : 0;
            $instance['show_content'] = !empty($_POST['show_content']) ? 1 : 0;
            $instance['show_button']  = !empty($_POST['show_button']) ? 1 : 0;
            $instance['button_text']  = sanitize_text_field($_POST['button_text'] ?? '');
            $instance['format']       = ($_POST['format'] ?? 'large') === 'small' ? 'small' : 'large';
            $instance['orientation']  = ($_POST['orientation'] ?? 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';

            $suggested_links = array_map('intval', $_POST['links'] ?? array());
            $manual_ids = array_filter(array_map('intval', explode(',', $_POST['manual_ids'] ?? '')));
            $manual_ids = array_slice(array_unique($manual_ids), 0, 20);

                $instance['manual_ids'] = $manual_ids;
                $instance['links']      = array_unique(array_merge($suggested_links, $manual_ids));

                if (empty($instance['links'])) {
                    $suggestions = $this->generate_widget_ai_suggestions($instance['title']);
                } else {
                    $instances = get_option('widget_affiliate_links_widget', array());
                    $id        = (int) get_option('alma_widget_next_id', 1);

                    while (isset($instances[$id])) {
                        $id++;
                    }
                    update_option('alma_widget_next_id', $id + 1);

                    // Salva data di creazione
                    $instance['created_at'] = current_time('mysql');

                    $instances[$id] = $instance;
                    update_option('widget_affiliate_links_widget', $instances);

                    $created   = true;
                    $shortcode = '[affiliate_links_widget id="' . $id . '"]';
                    $php_code  = "<?php echo do_shortcode('" . $shortcode . "'); ?>";
                }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Crea Widget AI', 'affiliate-link-manager-ai'); ?></h1>

            <?php if ($created) : ?>
                <div class="notice notice-success"><p><?php _e('Widget creato con successo!', 'affiliate-link-manager-ai'); ?></p></div>
                <p><?php _e('Shortcode:', 'affiliate-link-manager-ai'); ?> <code><?php echo esc_html($shortcode); ?></code></p>
                <p><?php _e('PHP:', 'affiliate-link-manager-ai'); ?> <code><?php echo esc_html($php_code); ?></code></p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('alma_create_widget'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="alma_widget_title"><?php _e('Titolo', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input name="title" type="text" id="alma_widget_title" value="<?php echo esc_attr($instance['title']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_content"><?php _e('Contenuto', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><textarea name="custom_content" id="alma_widget_content" rows="4" class="large-text code"><?php echo esc_textarea($instance['custom_content']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Opzioni', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <label><input type="checkbox" name="show_image" value="1" <?php checked($instance['show_image']); ?>> <?php _e('Mostra immagine', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_title" value="1" <?php checked($instance['show_title']); ?>> <?php _e('Mostra titolo', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_content" value="1" <?php checked($instance['show_content']); ?>> <?php _e('Mostra contenuto', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_button" value="1" <?php checked($instance['show_button']); ?>> <?php _e('Mostra pulsante', 'affiliate-link-manager-ai'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_button_text"><?php _e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input name="button_text" type="text" id="alma_widget_button_text" value="<?php echo esc_attr($instance['button_text']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_format"><?php _e('Formato', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <select name="format" id="alma_widget_format">
                                    <option value="large" <?php selected($instance['format'], 'large'); ?>><?php _e('Immagine grande, titolo e contenuto', 'affiliate-link-manager-ai'); ?></option>
                                    <option value="small" <?php selected($instance['format'], 'small'); ?>><?php _e('Immagine piccola e titolo', 'affiliate-link-manager-ai'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_orientation"><?php _e('Orientamento', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <select name="orientation" id="alma_widget_orientation">
                                    <option value="vertical" <?php selected($instance['orientation'], 'vertical'); ?>><?php _e('Verticale', 'affiliate-link-manager-ai'); ?></option>
                                    <option value="horizontal" <?php selected($instance['orientation'], 'horizontal'); ?>><?php _e('Orizzontale', 'affiliate-link-manager-ai'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php if (!empty($suggestions)) : ?>
                        <tr>
                            <th scope="row"><?php _e('Link suggeriti', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php foreach ($suggestions as $s) : ?>
                                    <label class="alma-suggested-link">
                                        <input type="checkbox" name="links[]" value="<?php echo esc_attr($s['id']); ?>">
                                        <?php echo esc_html($s['title']); ?><br>
                                        <small>
                                            <span class="dashicons dashicons-admin-links"></span>
                                            <?php _e('Coerenza', 'affiliate-link-manager-ai'); ?>: <?php echo esc_html(number_format_i18n($s['score'], 1)); ?>% -
                                            <?php _e('Tipologia', 'affiliate-link-manager-ai'); ?>: <?php echo !empty($s['types']) ? esc_html(implode(', ', $s['types'])) : '-'; ?> -
                                            <?php printf(__('In %d Articoli, Click: %d', 'affiliate-link-manager-ai'), $s['usage']['post_count'], $s['clicks']); ?>
                                        </small>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><label for="alma_widget_manual_ids"><?php _e('ID Link affiliati', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <input name="manual_ids" type="text" id="alma_widget_manual_ids" value="<?php echo esc_attr(implode(',', $instance['manual_ids'])); ?>" class="regular-text">
                                <p class="description"><?php _e('Inserisci fino a 20 ID separati da virgola', 'affiliate-link-manager-ai'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php if (!$created) : ?>
                <p><input type="submit" name="alma_create_widget" class="button-primary" value="<?php echo empty($suggestions) ? esc_attr__('Genera suggerimenti', 'affiliate-link-manager-ai') : esc_attr__('Crea Widget AI', 'affiliate-link-manager-ai'); ?>"></p>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    public function render_edit_widget_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $widget_id = isset($_GET['widget_id']) ? intval($_GET['widget_id']) : 0;
        $instances = get_option('widget_affiliate_links_widget', array());
        if (!$widget_id || !isset($instances[$widget_id])) {
            echo '<div class="wrap"><h1>' . esc_html__('Widget non trovato', 'affiliate-link-manager-ai') . '</h1></div>';
            return;
        }

        $instance    = $instances[$widget_id];
        $instance   += array(
            'custom_content' => '',
            'manual_ids'     => array(),
            'show_button'    => 0,
            'button_text'    => '',
        );
        $saved       = false;
        $suggestions = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alma_save_widget'])) {
            check_admin_referer('alma_edit_widget');

            $instance['title']        = sanitize_text_field($_POST['title'] ?? '');
            $instance['custom_content'] = wp_kses_post($_POST['custom_content'] ?? '');
            $instance['show_image']   = !empty($_POST['show_image']) ? 1 : 0;
            $instance['show_title']   = !empty($_POST['show_title']) ? 1 : 0;
            $instance['show_content'] = !empty($_POST['show_content']) ? 1 : 0;
            $instance['show_button']  = !empty($_POST['show_button']) ? 1 : 0;
            $instance['button_text']  = sanitize_text_field($_POST['button_text'] ?? '');
            $instance['format']       = ($_POST['format'] ?? 'large') === 'small' ? 'small' : 'large';
            $instance['orientation']  = ($_POST['orientation'] ?? 'vertical') === 'horizontal' ? 'horizontal' : 'vertical';

            $suggested_links = array_map('intval', $_POST['links'] ?? array());
            $manual_ids = array_filter(array_map('intval', explode(',', $_POST['manual_ids'] ?? '')));
            $manual_ids = array_slice(array_unique($manual_ids), 0, 20);

            $instance['manual_ids'] = $manual_ids;
            $instance['links']      = array_unique(array_merge($suggested_links, $manual_ids));

            if (empty($instance['created_at'])) {
                $instance['created_at'] = current_time('mysql');
            }

            if (empty($instance['links'])) {
                $suggestions = $this->generate_widget_ai_suggestions($instance['title']);
            } else {
                $instances[$widget_id] = $instance;
                update_option('widget_affiliate_links_widget', $instances);
                $saved = true;
            }
        } elseif (empty($instance['links'])) {
            $suggestions = $this->generate_widget_ai_suggestions($instance['title']);
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Modifica Widget', 'affiliate-link-manager-ai'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success"><p><?php _e('Widget aggiornato.', 'affiliate-link-manager-ai'); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('alma_edit_widget'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="alma_widget_title"><?php _e('Titolo', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input name="title" type="text" id="alma_widget_title" value="<?php echo esc_attr($instance['title']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_content"><?php _e('Contenuto', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><textarea name="custom_content" id="alma_widget_content" rows="4" class="large-text code"><?php echo esc_textarea($instance['custom_content']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Opzioni', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <label><input type="checkbox" name="show_image" value="1" <?php checked($instance['show_image']); ?>> <?php _e('Mostra immagine', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_title" value="1" <?php checked($instance['show_title']); ?>> <?php _e('Mostra titolo', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_content" value="1" <?php checked($instance['show_content']); ?>> <?php _e('Mostra contenuto', 'affiliate-link-manager-ai'); ?></label><br>
                                <label><input type="checkbox" name="show_button" value="1" <?php checked($instance['show_button']); ?>> <?php _e('Mostra pulsante', 'affiliate-link-manager-ai'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_button_text"><?php _e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input name="button_text" type="text" id="alma_widget_button_text" value="<?php echo esc_attr($instance['button_text']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_format"><?php _e('Formato', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <select name="format" id="alma_widget_format">
                                    <option value="large" <?php selected($instance['format'], 'large'); ?>><?php _e('Immagine grande, titolo e contenuto', 'affiliate-link-manager-ai'); ?></option>
                                    <option value="small" <?php selected($instance['format'], 'small'); ?>><?php _e('Immagine piccola e titolo', 'affiliate-link-manager-ai'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_orientation"><?php _e('Orientamento', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <select name="orientation" id="alma_widget_orientation">
                                    <option value="vertical" <?php selected($instance['orientation'], 'vertical'); ?>><?php _e('Verticale', 'affiliate-link-manager-ai'); ?></option>
                                    <option value="horizontal" <?php selected($instance['orientation'], 'horizontal'); ?>><?php _e('Orizzontale', 'affiliate-link-manager-ai'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php if (!empty($suggestions)) : ?>
                        <tr>
                            <th scope="row"><?php _e('Link suggeriti', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php foreach ($suggestions as $s) : ?>
                                    <label class="alma-suggested-link">
                                        <input type="checkbox" name="links[]" value="<?php echo esc_attr($s['id']); ?>" <?php checked(in_array($s['id'], (array) ($instance['links'] ?? array()))); ?>>
                                        <?php echo esc_html($s['id']) . ' - ' . esc_html($s['title']); ?><br>
                                        <small>
                                            <span class="dashicons dashicons-admin-links"></span>
                                            <?php _e('Coerenza', 'affiliate-link-manager-ai'); ?>: <?php echo esc_html(number_format_i18n($s['score'], 1)); ?>% -
                                            <?php _e('Tipologia', 'affiliate-link-manager-ai'); ?>: <?php echo !empty($s['types']) ? esc_html(implode(', ', $s['types'])) : '-'; ?> -
                                            <?php printf(__('In %d Articoli, Click: %d', 'affiliate-link-manager-ai'), $s['usage']['post_count'], $s['clicks']); ?>
                                        </small>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php else : ?>
                        <tr>
                            <th scope="row"><?php _e('Link selezionati', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php foreach ((array) ($instance['links'] ?? array()) as $lid) : ?>
                                    <label class="alma-suggested-link">
                                        <input type="checkbox" name="links[]" value="<?php echo esc_attr($lid); ?>" checked>
                                        <?php echo esc_html($lid) . ' - ' . esc_html(get_the_title($lid)); ?><br>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><label for="alma_widget_manual_ids"><?php _e('ID Link affiliati', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <input name="manual_ids" type="text" id="alma_widget_manual_ids" value="<?php echo esc_attr(implode(',', $instance['manual_ids'])); ?>" class="regular-text">
                                <p class="description"><?php _e('Inserisci fino a 20 ID separati da virgola', 'affiliate-link-manager-ai'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p><input type="submit" name="alma_save_widget" class="button-primary" value="<?php echo empty($instance['links']) ? esc_attr__('Salva Widget', 'affiliate-link-manager-ai') : esc_attr__('Aggiorna Widget', 'affiliate-link-manager-ai'); ?>"></p>
            </form>
        </div>
        <?php
    }

    public function render_widget_shortcode_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $instances = get_option('widget_affiliate_links_widget', array());

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['widget_id']) && check_admin_referer('alma_delete_widget_' . $_GET['widget_id'])) {
            $del_id = intval($_GET['widget_id']);
            if (isset($instances[$del_id])) {
                unset($instances[$del_id]);
                update_option('widget_affiliate_links_widget', $instances);
                echo '<div class="notice notice-success"><p>' . esc_html__('Widget eliminato.', 'affiliate-link-manager-ai') . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Shortcode Widget AI', 'affiliate-link-manager-ai'); ?></h1>
            <?php
            $has_items = false;
            if (is_array($instances)) {
                foreach ($instances as $id => $instance) {
                    if (is_numeric($id)) {
                        $has_items = true;
                        break;
                    }
                }
            }
            if (!$has_items) : ?>
                <p><?php _e('Nessun widget configurato.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('ID Widget', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Titolo', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Creato il', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Shortcode', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Azioni', 'affiliate-link-manager-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instances as $id => $instance) :
                            if (!is_numeric($id)) {
                                continue;
                            }
                            $title        = $instance['title'] ?? '';
                            $shortcode    = '[affiliate_links_widget id="' . $id . '"]';
                            $created_at   = $instance['created_at'] ?? '';
                            $created_disp = $created_at ? mysql2date(get_option('date_format'), $created_at) : '-';
                        ?>
                        <tr>
                            <td><?php echo esc_html($id); ?></td>
                            <td><?php echo esc_html($title); ?></td>
                            <td><?php echo esc_html($created_disp); ?></td>
                            <td><code><?php echo esc_html($shortcode); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=alma-edit-widget&widget_id=' . $id)); ?>"><?php _e('Modifica', 'affiliate-link-manager-ai'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=affiliate-link-widgets&action=delete&widget_id=' . $id), 'alma_delete_widget_' . $id)); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminando lo shortcode verrà rimosso da tutti i contenuti in cui è stato inserito. Continuare?', 'affiliate-link-manager-ai')); ?>');"><?php _e('Elimina', 'affiliate-link-manager-ai'); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_affiliate_chat_ai_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Affiliate Chat AI', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php _e('Utilizza lo shortcode <code>[affiliate_chat_ai]</code> per mostrare il modulo di ricerca AI nelle pagine o nei post.', 'affiliate-link-manager-ai'); ?></p>
        </div>
        <?php
    }

    /**
     * Pagina dettagli utilizzo
     */
    public function usage_details_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }
        
        $link_id = isset($_GET['link_id']) ? intval($_GET['link_id']) : 0;
        
        if (!$link_id) {
            echo '<div class="wrap"><h1>Errore</h1><p>Link non specificato.</p></div>';
            return;
        }
        
        $post = get_post($link_id);
        if (!$post || $post->post_type !== 'affiliate_link') {
            echo '<div class="wrap"><h1>Errore</h1><p>Link non trovato.</p></div>';
            return;
        }
        
        $usage_details = $this->get_detailed_shortcode_usage($link_id);
        
        ?>
        <div class="wrap">
            <h1>
                <?php _e('Dettagli Utilizzo:', 'affiliate-link-manager-ai'); ?> 
                <?php echo esc_html($post->post_title); ?>
            </h1>
            
            <?php if (empty($usage_details)) : ?>
                <p><?php _e('Questo link non è ancora utilizzato in nessun contenuto.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Post/Pagina</th>
                            <th>Tipo</th>
                            <th>Stato</th>
                            <th>Occorrenze</th>
                            <th>Ultima Modifica</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usage_details as $usage) : ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo get_edit_post_link($usage->ID); ?>">
                                            <?php echo esc_html($usage->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo $usage->post_type === 'page' ? 'Pagina' : 'Post'; ?></td>
                                <td><?php echo $usage->post_status === 'publish' ? 'Pubblicato' : ucfirst($usage->post_status); ?></td>
                                <td><?php echo $usage->occurrences; ?></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($usage->post_modified)); ?></td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($usage->ID); ?>" class="button button-small">Modifica</a>
                                    <a href="<?php echo get_permalink($usage->ID); ?>" class="button button-small" target="_blank">Visualizza</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <p style="margin-top:20px;">
                <a href="<?php echo get_edit_post_link($link_id); ?>" class="button button-primary">Modifica Link</a>
                <a href="<?php echo admin_url('edit.php?post_type=affiliate_link'); ?>" class="button">Torna alla Lista</a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Editor Integration - Non inserisce più HTML qui
     */
    public function add_editor_integration() {
        // Il modal viene inserito tramite JavaScript nel file editor.js
        // Questa funzione ora serve solo come placeholder per compatibilità
        return;
    }
    
    /**
     * AJAX Handlers
     */
    
    public function ajax_search_links() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_editor_search')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        $type_filter = isset($_POST['type_filter']) ? intval($_POST['type_filter']) : 0;
        
        $args = array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        // Aggiungi ricerca se presente
        if (!empty($search)) {
            $args['s'] = $search;
        }
        
        // Aggiungi filtro tipologia se presente
        if ($type_filter > 0) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'link_type',
                    'field' => 'term_id',
                    'terms' => $type_filter
                )
            );
        }
        
        $query = new WP_Query($args);
        $results = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // Ottieni URL affiliato
                $affiliate_url = get_post_meta($post_id, '_affiliate_url', true);
                
                // Ottieni statistiche
                $click_count = get_post_meta($post_id, '_click_count', true) ?: 0;
                $usage_data = $this->get_shortcode_usage_stats($post_id);
                
                // Ottieni tipologie
                $terms = get_the_terms($post_id, 'link_type');
                $types = array();
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $types[] = $term->name;
                    }
                }
                
                $results[] = array(
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'url' => $affiliate_url,
                    'types' => $types,
                    'clicks' => $click_count,
                    'usage' => $usage_data,
                    'shortcode' => '[affiliate_link id="' . $post_id . '"]'
                );
            }
            wp_reset_postdata();
        }
        
        wp_send_json_success($results);
    }

    public function ajax_ai_suggest_links() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_editor_search')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $title   = isset($_POST['title']) ? wp_strip_all_tags(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? wp_strip_all_tags(wp_unslash($_POST['content'])) : '';

        // Limita la lunghezza per evitare richieste eccessive
        $title   = mb_substr($title, 0, 500);
        $content = mb_substr($content, 0, 2000);

        // Recupera fino a 50 link affiliati
        $links = get_posts(array(
            'post_type'      => 'affiliate_link',
            'post_status'    => 'publish',
            'numberposts'    => 50,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        if (empty($links)) {
            wp_send_json_success(array());
        }

        $prompt = "Articolo: {$title}\n\n{$content}\n\nLinks disponibili:\n";
        foreach ($links as $link) {
            $prompt .= 'ID ' . $link->ID . ': ' . $link->post_title . "\n";
        }
        $prompt .= "\nRestituisci un array JSON con massimo 3 oggetti {\"id\": ID, \"score\": COERENZA}, dove COERENZA è un numero da 0 a 100 che indica quanto il link è coerente con l'articolo. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.\n";

        $response = ALMA_AI_Utils::call_claude_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');
        if (empty($response['success'])) {
            $msg = $response['error'] ?? __('Impossibile generare suggerimenti con Claude.', 'affiliate-link-manager-ai');
            error_log('Claude API error: ' . $msg);
            wp_send_json_error($msg);
        }

        $clean = ALMA_AI_Utils::extract_first_json($response['response']);
        $items = json_decode($clean, true);
        if (!is_array($items)) {
            error_log('JSON decode failed: ' . json_last_error_msg() . ' | Raw: ' . $response['response']);
            wp_send_json_error(__('Risposta AI non valida.', 'affiliate-link-manager-ai'));
        }

        $results = array();
        foreach (array_slice($items, 0, 3) as $item) {
            $id    = isset($item['id']) ? intval($item['id']) : 0;
            $score = isset($item['score']) ? floatval($item['score']) : 0;

            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link') {
                continue;
            }

            $affiliate_url = get_post_meta($id, '_affiliate_url', true);
            $click_count   = get_post_meta($id, '_click_count', true) ?: 0;
            $usage_data    = $this->get_shortcode_usage_stats($id);
            $terms         = get_the_terms($id, 'link_type');
            $types         = array();
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $types[] = $term->name;
                }
            }

            $results[] = array(
                'id'       => $id,
                'title'    => get_the_title($id),
                'url'      => $affiliate_url,
                'types'    => $types,
                'clicks'   => $click_count,
                'usage'    => $usage_data,
                'shortcode'=> '[affiliate_link id="' . $id . '"]',
                'score'    => max(0, min(100, round($score))),
            );
        }

        wp_send_json_success($results);
    }

    public function ajax_get_ai_suggestions() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $link_id = intval($_POST['link_id']);

        // Genera suggerimenti AI basati su Claude
        $suggestions = $this->generate_ai_suggestions($link_id);

        if (is_wp_error($suggestions) || empty($suggestions)) {
            $msg = is_wp_error($suggestions)
                ? $suggestions->get_error_message()
                : __('Impossibile generare suggerimenti con Claude.', 'affiliate-link-manager-ai');
            wp_send_json_error($msg);
        }

        wp_send_json_success($suggestions);
    }

    public function ajax_ai_suggest_text() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_ai_suggest_text')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $title       = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

        // Se non vengono passati titolo/descrizione, prova a recuperarli dal link_id
        if (!$title && !$description && isset($_POST['link_id'])) {
            $link_id = intval($_POST['link_id']);
            $post    = get_post($link_id);

            if ($post && $post->post_type === 'affiliate_link') {
                $title       = $post->post_title;
                $description = $post->post_content;
            } else {
                wp_send_json_error('Invalid link');
                return;
            }
        }

        if (!$title && !$description) {
            wp_send_json_error('Missing data');
            return;
        }

        $suggestions = $this->generate_title_suggestions($title, $description);

        if (is_wp_error($suggestions) || empty($suggestions)) {
            $msg = is_wp_error($suggestions)
                ? $suggestions->get_error_message()
                : __('Impossibile generare suggerimenti con Claude.', 'affiliate-link-manager-ai');
            wp_send_json_error($msg);
        }

        wp_send_json_success(array('suggestions' => $suggestions));
    }
    
    public function ajax_test_claude_api() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $api_key = get_option('alma_claude_api_key');
        if (empty($api_key)) {
            wp_send_json_error('API Key non configurata');
            return;
        }
        
        // Test semplice con Claude
        $response = ALMA_AI_Utils::call_claude_api('Rispondi solo con: "Connessione OK"');
        
        if ($response['success']) {
            wp_send_json_success(array(
                'model' => $response['model'],
                'response_time' => $response['response_time']
            ));
        } else {
            wp_send_json_error($response['error']);
        }
    }
    
    public function ajax_get_performance_predictions() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $link_id = intval($_POST['link_id']);
        $predictions = $this->get_ai_performance_predictions($link_id);
        
        wp_send_json_success($predictions);
    }
    
    public function ajax_get_dashboard_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $data = array(
            'total_clicks' => $this->get_total_clicks(),
            'total_links' => wp_count_posts('affiliate_link')->publish,
            'avg_ctr' => $this->get_average_ctr(),
            'top_links' => $this->get_top_performing_links(5)
        );
        
        wp_send_json_success($data);
    }
    
    public function ajax_get_chart_data() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        global $wpdb;

        $metric = sanitize_text_field($_POST['metric'] ?? 'clicks');
        $range  = sanitize_text_field($_POST['range'] ?? 'monthly');

        $labels = array();
        $data   = array();

        switch ($metric) {
            case 'clicks':
                $table = $wpdb->prefix . 'alma_analytics';
                if ($range === 'weekly') {
                    for ($i = 11; $i >= 0; $i--) {
                        $start = date('Y-m-d', strtotime("monday -$i week"));
                        $end   = date('Y-m-d', strtotime("sunday -$i week"));
                        $count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table WHERE click_time BETWEEN %s AND %s",
                            $start . ' 00:00:00',
                            $end . ' 23:59:59'
                        ));
                        $labels[] = 'W' . date('W', strtotime($start));
                        $data[]   = intval($count);
                    }
                } else {
                    for ($i = 11; $i >= 0; $i--) {
                        $month = date('n', strtotime("-$i months"));
                        $year  = date('Y', strtotime("-$i months"));
                        $count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table WHERE YEAR(click_time)=%d AND MONTH(click_time)=%d",
                            $year,
                            $month
                        ));
                        $labels[] = date_i18n('M', strtotime("$year-$month-01"));
                        $data[]   = intval($count);
                    }
                }
                break;
            case 'links':
                for ($i = 11; $i >= 0; $i--) {
                    $month = date('n', strtotime("-$i months"));
                    $year  = date('Y', strtotime("-$i months"));
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='affiliate_link' AND post_status='publish' AND YEAR(post_date)=%d AND MONTH(post_date)=%d",
                        $year,
                        $month
                    ));
                    $labels[] = date_i18n('M', strtotime("$year-$month-01"));
                    $data[]   = intval($count);
                }
                break;
            case 'ctr':
                $total_occurrences = $this->get_total_link_occurrences();
                for ($i = 11; $i >= 0; $i--) {
                    $month = date('n', strtotime("-$i months"));
                    $year  = date('Y', strtotime("-$i months"));
                    $clicks = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}alma_analytics WHERE YEAR(click_time)=%d AND MONTH(click_time)=%d",
                        $year,
                        $month
                    ));
                    $ctr = $total_occurrences > 0 ? ($clicks / $total_occurrences) * 100 : 0;
                    $labels[] = date_i18n('M', strtotime("$year-$month-01"));
                    $data[]   = round($ctr, 2);
                }
                break;
        }

        wp_send_json_success(array(
            'labels' => $labels,
            'data'   => $data
        ));
    }
    
    public function ajax_get_link_types() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_editor_search')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $terms = get_terms(array(
            'taxonomy' => 'link_type',
            'hide_empty' => false,
        ));
        
        $types = array();
        
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $types[] = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'count' => $term->count
                );
            }
        }
        
        wp_send_json_success($types);
    }
    
    public function ajax_get_link_stats() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $link_id = intval($_POST['link_id']);
        
        $clicks = get_post_meta($link_id, '_click_count', true) ?: 0;
        $usage_data = $this->get_shortcode_usage_stats($link_id);
        $ai_score = get_post_meta($link_id, '_ai_performance_score', true) ?: 0;
        
        $ctr = 0;
        if ($usage_data['total_occurrences'] > 0) {
            $ctr = round(($clicks / $usage_data['total_occurrences']) * 100, 2);
        }
        
        wp_send_json_success(array(
            'clicks' => $clicks,
            'ctr' => $ctr,
            'ai_score' => $ai_score
        ));
    }

    public function ajax_import_affiliate_link() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_import_links')) {
            wp_send_json_error(array('code' => 'invalid_nonce'));
        }

        $title = sanitize_text_field($_POST['title'] ?? '');
        $url   = esc_url_raw($_POST['url'] ?? '');
        $status = ($_POST['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';
        $types = isset($_POST['types']) ? array_map('intval', (array) $_POST['types']) : array();
        $link_rel = sanitize_text_field($_POST['rel'] ?? 'sponsored noopener');
        $link_target = sanitize_text_field($_POST['target'] ?? '_blank');

        if (empty($title) || empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('code' => 'invalid_data'));
        }

        // Controllo duplicati
        $existing = get_posts(array(
            'post_type'  => 'affiliate_link',
            'post_status'=> 'any',
            'meta_key'   => '_affiliate_url',
            'meta_value' => $url,
            'fields'     => 'ids',
            'numberposts'=> 1,
        ));
        if ($existing) {
            wp_send_json_error(array('code' => 'duplicate'));
        }

        $post_id = wp_insert_post(array(
            'post_title'  => $title,
            'post_type'   => 'affiliate_link',
            'post_status' => $status,
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('code' => 'wp_error', 'message' => $post_id->get_error_message()));
        }

        update_post_meta($post_id, '_affiliate_url', $url);
        update_post_meta($post_id, '_link_rel', $link_rel);
        update_post_meta($post_id, '_link_target', $link_target);
        if (!empty($types)) {
            wp_set_object_terms($post_id, $types, 'link_type');
        }

        wp_send_json_success(array(
            'id' => $post_id,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
        ));
    }

    /**
     * Helper Functions
     */
    private function get_total_clicks() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT SUM(meta_value) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_click_count'
        ");
        return intval($result ?: 0);
    }
    
    private function get_recent_clicks($days = 7) {
        global $wpdb;
        $table = $wpdb->prefix . 'alma_analytics';
        $start = date('Y-m-d', strtotime("-$days days"));
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE click_time >= %s",
            $start . ' 00:00:00'
        ));
        return intval($count);
    }

    private function get_average_ai_score() {
        global $wpdb;
        $result = $wpdb->get_var("
            SELECT AVG(meta_value)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_ai_performance_score'
        ");
        return round($result ?: 50);
    }

    private function get_average_ctr() {
        $links = get_posts(array(
            'post_type'   => 'affiliate_link',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ));

        $total = 0;
        $count = 0;
        foreach ($links as $id) {
            $clicks = get_post_meta($id, '_click_count', true) ?: 0;
            $usage  = $this->get_shortcode_usage_stats($id);
            if ($usage['total_occurrences'] > 0) {
                $total += ($clicks / $usage['total_occurrences']) * 100;
                $count++;
            }
        }

        return $count > 0 ? round($total / $count, 2) : 0;
    }

    private function get_top_performing_links($limit = 5) {
        global $wpdb;
        
        $query = "
            SELECT p.ID, p.post_title, pm.meta_value as click_count
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'affiliate_link'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_click_count'
            AND pm.meta_value > 0
            ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit));
    }

    private function get_unused_links_count() {
        $links = get_posts(array(
            'post_type'   => 'affiliate_link',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ));

        $unused = 0;
        foreach ($links as $id) {
            $usage = $this->get_shortcode_usage_stats($id);
            if ($usage['total_occurrences'] == 0) {
                $unused++;
            }
        }
        return $unused;
    }

    private function get_total_link_occurrences() {
        global $wpdb;
        $pattern = '[affiliate_link';
        $query = "
            SELECT SUM((LENGTH(post_content) - LENGTH(REPLACE(post_content, %s, ''))) / LENGTH(%s)) as total_occurrences
            FROM {$wpdb->posts}
            WHERE post_status='publish'
            AND post_type IN ('post','page')
            AND post_content LIKE %s
        ";
        $result = $wpdb->get_var($wpdb->prepare(
            $query,
            $pattern,
            $pattern,
            '%' . $wpdb->esc_like($pattern) . '%'
        ));
        return $result ? intval($result) : 0;
    }
    
    private function get_shortcode_usage_stats($link_id) {
        global $wpdb;
        
        $shortcode_pattern = '[affiliate_link id="' . $link_id . '"';
        
        $query = "
            SELECT COUNT(*) as post_count, 
                   SUM((LENGTH(post_content) - LENGTH(REPLACE(post_content, %s, ''))) / LENGTH(%s)) as total_occurrences
            FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_status = 'publish'
            AND post_type IN ('post', 'page')
        ";
        
        $results = $wpdb->get_row($wpdb->prepare(
            $query,
            $shortcode_pattern,
            $shortcode_pattern,
            '%' . $wpdb->esc_like($shortcode_pattern) . '%'
        ));
        
        return array(
            'post_count' => $results ? intval($results->post_count) : 0,
            'total_occurrences' => $results ? intval($results->total_occurrences) : 0
        );
    }
    
    private function get_detailed_shortcode_usage($link_id) {
        global $wpdb;
        
        $shortcode_pattern = '[affiliate_link id="' . $link_id . '"';
        
        $query = "
            SELECT ID, post_title, post_content, post_status, post_type, post_modified,
                   (LENGTH(post_content) - LENGTH(REPLACE(post_content, %s, ''))) / LENGTH(%s) as occurrences
            FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_status IN ('publish', 'draft', 'private')
            AND post_type IN ('post', 'page')
            ORDER BY post_modified DESC
        ";
        
        return $wpdb->get_results($wpdb->prepare(
            $query,
            $shortcode_pattern,
            $shortcode_pattern,
            '%' . $wpdb->esc_like($shortcode_pattern) . '%'
        ));
    }
    
    private function find_posts_with_shortcode($link_id) {
        global $wpdb;
        
        $shortcode_pattern = '[affiliate_link id="' . $link_id . '"';
        
        $query = "
            SELECT ID 
            FROM {$wpdb->posts}
            WHERE post_content LIKE %s
            AND post_status IN ('publish', 'draft', 'private')
            AND post_type IN ('post', 'page')
        ";
        
        return $wpdb->get_col($wpdb->prepare($query, '%' . $wpdb->esc_like($shortcode_pattern) . '%'));
    }
    
    private function calculate_ai_performance_score($link_id) {
        $clicks = get_post_meta($link_id, '_click_count', true) ?: 0;
        $usage_data = $this->get_shortcode_usage_stats($link_id);
        
        $score = 50; // Base score
        
        // CTR impact (max 30 points)
        if ($usage_data['total_occurrences'] > 0) {
            $ctr = ($clicks / $usage_data['total_occurrences']) * 100;
            $score += min(30, $ctr * 3);
        }
        
        // Usage impact (max 20 points)
        $score += min(20, $usage_data['post_count'] * 2);
        
        // Update score
        update_post_meta($link_id, '_ai_performance_score', round($score));

        return $score;
    }

    private function generate_widget_ai_suggestions($title) {
        $title = mb_substr(wp_strip_all_tags($title), 0, 500);
        if (empty($title)) {
            return array();
        }

        $links = get_posts(array(
            'post_type'   => 'affiliate_link',
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ));

        if (empty($links)) {
            return array();
        }

        $prompt = "Titolo widget: {$title}\n\nLinks disponibili:\n";
        foreach ($links as $link) {
            $prompt .= $link->ID . ': ' . $link->post_title . "\n";
        }
        $prompt .= "\nRestituisci un array JSON con massimo 10 oggetti {\"id\": ID, \"score\": PERTINENZA}, dove PERTINENZA è un numero da 0 a 100 che indica quanto il link è coerente con il titolo. Ordina dal più pertinente al meno pertinente. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.";

        $response = ALMA_AI_Utils::call_claude_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');
        if (empty($response['success'])) {
            return array();
        }

        $clean = ALMA_AI_Utils::extract_first_json($response['response']);
        $items = json_decode($clean, true);
        if (!is_array($items)) {
            error_log('JSON decode failed: ' . json_last_error_msg() . ' | Raw: ' . $response['response']);
            return array();
        }

        usort($items, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $suggestions = array();
        foreach (array_slice($items, 0, 10) as $item) {
            $id    = intval($item['id'] ?? 0);
            $score = floatval($item['score'] ?? 0);

            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link') {
                continue;
            }

            $click_count = get_post_meta($id, '_click_count', true) ?: 0;
            $usage_data  = $this->get_shortcode_usage_stats($id);
            $terms       = get_the_terms($id, 'link_type');
            $types       = array();
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $types[] = $term->name;
                }
            }

            $suggestions[] = array(
                'id'     => $id,
                'title'  => get_the_title($id),
                'score'  => $score,
                'types'  => $types,
                'clicks' => $click_count,
                'usage'  => $usage_data,
            );
        }

        return $suggestions;
    }

    private function generate_ai_suggestions($link_id) {
        $post = get_post($link_id);

        if (!$post || $post->post_type !== 'affiliate_link') {
            return new \WP_Error('invalid_link', 'Invalid link');
        }

        $prompt = sprintf(
            'In base al titolo "%s" e al contenuto "%s", genera 5 suggerimenti brevi in italiano per ottimizzare un link affiliato. Restituisci un JSON array di oggetti con le chiavi "title" e "description". Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.',
            wp_strip_all_tags($post->post_title),
            wp_strip_all_tags($post->post_content)
        );

        $response = ALMA_AI_Utils::call_claude_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');

        if (empty($response['success'])) {
            return new \WP_Error('claude_error', $response['error'] ?? __('Errore sconosciuto', 'affiliate-link-manager-ai'));
        }

        $clean   = ALMA_AI_Utils::extract_first_json($response['response']);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            error_log('JSON decode failed: ' . json_last_error_msg() . ' | Raw: ' . $response['response']);
            return new \WP_Error('claude_parse_error', __('Risposta non valida da Claude', 'affiliate-link-manager-ai'));
        }

        $suggestions = array();

        foreach ($decoded as $item) {
            if (isset($item['title']) && isset($item['description'])) {
                $suggestions[] = array(
                    'title'       => sanitize_text_field($item['title']),
                    'description' => sanitize_text_field($item['description'])
                );
            }
        }

        if (empty($suggestions)) {
            return new \WP_Error('empty_suggestions', __('Risposta non valida da Claude', 'affiliate-link-manager-ai'));
        }

        return $suggestions;
    }

    private function generate_title_suggestions($title, $description) {
        $title       = wp_strip_all_tags($title);
        $description = wp_strip_all_tags($description);
        $content_part = $description ? sprintf(' e il contenuto "%s"', $description) : '';

        $prompt = sprintf(
            'Sei un copywriter SEO. Analizza il titolo "%s"%s e proponi 3 alternative in italiano, ottimizzate per i motori di ricerca e con alto potenziale di conversione per un link affiliato. Rispondi con un array JSON contenente esclusivamente i tre titoli suggeriti. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.',
            $title,
            $content_part
        );

        $response = ALMA_AI_Utils::call_claude_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');

        if (empty($response['success'])) {
            return new \WP_Error('claude_error', $response['error'] ?? __('Errore sconosciuto', 'affiliate-link-manager-ai'));
        }

        $clean   = ALMA_AI_Utils::extract_first_json($response['response']);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            error_log('JSON decode failed: ' . json_last_error_msg() . ' | Raw: ' . $response['response']);
            return new \WP_Error('claude_parse_error', __('Risposta non valida da Claude', 'affiliate-link-manager-ai'));
        }

        $suggestions = array();
        foreach (array_slice($decoded, 0, 3) as $text) {
            $suggestions[] = array(
                'text'       => sanitize_text_field($text),
                'confidence' => 90,
                'pattern'    => 'ai'
            );
        }

        if (empty($suggestions)) {
            return new \WP_Error('empty_suggestions', __('Risposta non valida da Claude', 'affiliate-link-manager-ai'));
        }

        return $suggestions;
    }

    private function get_ai_performance_predictions($link_id) {
        $historical_data = get_post_meta($link_id, '_ai_historical_data', true) ?: array();
        
        if (count($historical_data) < 7) {
            return array(
                'confidence' => 'low',
                'predicted_ctr' => 0,
                'predicted_clicks' => 0,
                'trend' => 'insufficient_data',
                'recommendation' => 'Necessari più dati per predizioni accurate'
            );
        }
        
        // Calcolo semplificato delle predizioni
        $recent_data = array_slice($historical_data, -7);
        $avg_ctr = array_sum(array_column($recent_data, 'ctr')) / count($recent_data);
        $avg_clicks = array_sum(array_column($recent_data, 'clicks')) / count($recent_data);
        
        return array(
            'confidence' => 'medium',
            'predicted_ctr' => round($avg_ctr, 2),
            'predicted_clicks' => round($avg_clicks),
            'trend' => $avg_ctr > 2 ? 'up' : 'stable',
            'recommendation' => 'Performance stabile. Considera A/B testing per migliorare.'
        );
    }
    
    /**
     * Gestione eliminazione e cleanup
     */
    public function before_delete_link($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'affiliate_link') {
            $this->handle_shortcode_cleanup($post_id, 'delete');
        }
    }
    
    public function before_trash_link($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'affiliate_link') {
            $this->handle_shortcode_cleanup($post_id, 'trash');
        }
    }
    
    private function handle_shortcode_cleanup($link_id, $action = 'delete') {
        $affected_posts = $this->find_posts_with_shortcode($link_id);

        if (empty($affected_posts)) {
            return;
        }

        foreach ($affected_posts as $post_id) {
            $post    = get_post($post_id);
            $content = $this->remove_shortcode_from_content($post->post_content, $link_id);

            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $content
            ));
        }
    }
    
    private function remove_shortcode_from_content($content, $link_id) {
        $pattern = '/\[affiliate_link[^\]]*id=["\']?' . $link_id . '["\']?[^\]]*\]/';
        return preg_replace($pattern, '', $content);
    }

    // Le funzioni per sostituzione o commento shortcode sono state rimosse in favore di una cancellazione diretta.

    /**
     * Gestisce l'aggiornamento dell'opzione dei widget per rimuovere gli shortcode orfani.
     */
    public function on_widget_option_update($old_value, $value, $option) {
        $old_ids = array_filter(array_keys((array) $old_value), 'is_numeric');
        $new_ids = array_filter(array_keys((array) $value), 'is_numeric');
        $deleted = array_diff($old_ids, $new_ids);
        if (empty($deleted)) {
            return;
        }
        foreach ($deleted as $widget_id) {
            $this->cleanup_widget_shortcodes($widget_id);
        }
    }

    private function cleanup_widget_shortcodes($widget_id) {
        global $wpdb;
        $like  = '%' . $wpdb->esc_like("[affiliate_links_widget id=\"$widget_id\"") . '%';
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type IN ('post','page') AND post_status IN ('publish','draft','private')",
            $like
        ));

        if (!$posts) {
            return;
        }

        foreach ($posts as $post) {
            $content = $this->remove_widget_shortcode_from_content($post->post_content, $widget_id);
            if ($content !== $post->post_content) {
                wp_update_post(array(
                    'ID'           => $post->ID,
                    'post_content' => $content
                ));
            }
        }
    }

    private function remove_widget_shortcode_from_content($content, $widget_id) {
        $pattern = '/\[affiliate_links_widget[^\]]*id=["\']?' . $widget_id . '["\']?[^\]]*\]/';
        return preg_replace($pattern, '', $content);
    }

    public function filter_posts_without_affiliates($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== 'post' || !isset($_GET['alma_no_affiliates'])) {
            return;
        }
        global $wpdb;
        $like = $wpdb->esc_like('[affiliate_link');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content LIKE %s", '%' . $like . '%'));
        if (!empty($ids)) {
            $query->set('post__not_in', $ids);
        }
    }

    /**
     * Activation/Deactivation
     */
    public function activate() {
        $this->create_analytics_table();
        $this->create_default_categories();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Rimuovi cron jobs
        wp_clear_scheduled_hook('alma_daily_optimization');
        flush_rewrite_rules();
    }
    
    private function create_analytics_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'alma_analytics';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            link_id mediumint(9) NOT NULL,
            click_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            user_ip varchar(45) DEFAULT '' NOT NULL,
            user_agent text DEFAULT '' NOT NULL,
            referrer varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY (id),
            KEY link_id (link_id),
            KEY click_time (click_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function create_default_categories() {
        $default_categories = array(
            'Amazon' => 'Link affiliati Amazon',
            'Software' => 'Software e servizi online',
            'Corsi' => 'Corsi online e formazione',
            'E-commerce' => 'Negozi online generici',
        );
        
        foreach ($default_categories as $name => $description) {
            if (!term_exists($name, 'link_type')) {
                wp_insert_term($name, 'link_type', array('description' => $description));
            }
        }
    }
}

// Inizializza il plugin
new AffiliateManagerAI();
if (is_admin()) {
    require_once ALMA_PLUGIN_DIR . 'includes/class-prompt-ai-admin.php';
    new ALMA_Prompt_AI_Admin();
}

?>
