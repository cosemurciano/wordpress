<?php
/**
 * Plugin Name: Affiliate Link Manager AI
 * Plugin URI: https://your-website.com
 * Description: Gestisce link affiliati con intelligenza artificiale per ottimizzazione e tracking automatico.
 * Version: 1.6
 * Author: Cosè Murciano
 * License: GPL v2 or later
 * Text Domain: affiliate-link-manager-ai
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisci costanti del plugin
define('ALMA_VERSION', '1.6');
define('ALMA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALMA_PLUGIN_FILE', __FILE__);

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
        // Verifica se il file esiste prima di caricarlo
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
    }
    
    /**
     * Inizializza hooks
     */
    private function init_hooks() {
        // Shortcode per mostrare link
        add_shortcode('affiliate_link', array($this, 'display_affiliate_link'));
        
        // Hook AJAX per tracking click (modificato per tracking asincrono)
        add_action('wp_ajax_alma_track_click', array($this, 'ajax_track_click'));
        add_action('wp_ajax_nopriv_alma_track_click', array($this, 'ajax_track_click'));
        
        // Hook per controllo utilizzo link
        add_action('wp_ajax_alma_check_usage', array($this, 'check_link_usage'));
        
        // Hook per ricerca link nell'editor
        add_action('wp_ajax_alma_search_links', array($this, 'ajax_search_links'));
        
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
    
    /**
     * Display affiliate link - MODIFICATO per link diretti
     */
    public function display_affiliate_link($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'text' => '',
            'class' => 'affiliate-link-btn',
            'img' => 'no',
            'fields' => ''
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
            $image_html = get_the_post_thumbnail($atts['id'], 'full', array('class' => 'alma-affiliate-img'));
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

        return $link_html;
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
                'menu_name' => __('🔗 Affiliate AI', 'affiliate-link-manager-ai'),
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
        
        // Stili e script admin
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_alma_get_ai_suggestions', array($this, 'ajax_get_ai_suggestions'));
        add_action('wp_ajax_alma_ai_suggest_text', array($this, 'ajax_ai_suggest_text'));
        add_action('wp_ajax_alma_test_claude_api', array($this, 'ajax_test_claude_api'));
        add_action('wp_ajax_alma_get_performance_predictions', array($this, 'ajax_get_performance_predictions'));
        add_action('wp_ajax_alma_get_link_types', array($this, 'ajax_get_link_types'));
        
        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }
    
    /**
     * Enqueue admin scripts e stili
     */
    public function admin_enqueue_scripts($hook) {
        // Solo nelle pagine del plugin
        $screen = get_current_screen();
        
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
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('alma_ai_suggest_text'),
                    'messages' => array(
                        'generating' => __('Generazione suggerimenti...', 'affiliate-link-manager-ai'),
                        'generated'  => __('Suggerimenti generati!', 'affiliate-link-manager-ai'),
                        'error'      => __('Errore durante la generazione', 'affiliate-link-manager-ai'),
                    ),
                ));
            }
        }
        
        // Script editor (per tutte le pagine di editing)
        if (in_array($hook, array('post.php', 'post-new.php', 'page.php', 'page-new.php'))) {
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
        echo '<label><input type="checkbox" id="alma-sc-title" disabled> ' . __('Titolo', 'affiliate-link-manager-ai') . '</label> ';
        echo '<label><input type="checkbox" id="alma-sc-content" disabled> ' . __('Contenuto', 'affiliate-link-manager-ai') . '</label>';
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
        
        // Azioni rapide
        echo '<div style="margin-top:20px;">';
        echo '<button type="button" class="button button-primary button-small" style="width:100%;" onclick="almaGetAIPredictions(' . $post->ID . ')">🔮 Ottieni Predizioni AI</button>';
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
        
        // Calcola AI Performance Score iniziale
        $this->calculate_ai_performance_score($post_id);
    }
    
    /**
     * Colonne personalizzate
     */
    public function set_custom_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            if ($key === 'title') {
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
            __('Dashboard AI', 'affiliate-link-manager-ai'),
            __('Dashboard AI', 'affiliate-link-manager-ai'),
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

        // Importa link
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Importa link', 'affiliate-link-manager-ai'),
            __('Importa link', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-import',
            array($this, 'render_import_page')
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
     * Render Dashboard Page
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }
        
        $total_clicks = $this->get_total_clicks();
        $total_links = wp_count_posts('affiliate_link')->publish;
        $top_links = $this->get_top_performing_links(10);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Dashboard AI - Affiliate Link Manager', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:14px;color:#666;">Versione <?php echo ALMA_VERSION; ?></p>
            <p><a href="<?php echo admin_url('edit.php?post_type=affiliate_link&page=alma-import'); ?>" class="button button-primary">Importa link</a></p>
            
            <!-- Statistiche Principali -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin:30px 0;">
                
                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">📊 Click Totali</h3>
                    <div style="font-size:36px;font-weight:bold;color:#2271b1;"><?php echo number_format($total_clicks); ?></div>
                    <p style="color:#666;margin:5px 0 0 0;">Su tutti i link affiliati</p>
                </div>
                
                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🔗 Link Attivi</h3>
                    <div style="font-size:36px;font-weight:bold;color:#00a32a;"><?php echo number_format($total_links); ?></div>
                    <p style="color:#666;margin:5px 0 0 0;">Link pubblicati</p>
                </div>
                
                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🎯 CTR Medio</h3>
                    <div style="font-size:36px;font-weight:bold;color:#d63638;">
                        <?php 
                        $avg_ctr = $total_links > 0 ? round($total_clicks / $total_links, 2) : 0;
                        echo $avg_ctr . '%';
                        ?>
                    </div>
                    <p style="color:#666;margin:5px 0 0 0;">Click-through rate</p>
                </div>
                
                <div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:8px;">
                    <h3 style="margin:0 0 10px 0;color:#23282d;">🤖 AI Score Medio</h3>
                    <div style="font-size:36px;font-weight:bold;color:#8e44ad;">
                        <?php 
                        $avg_score = $this->get_average_ai_score();
                        echo $avg_score . '%';
                        ?>
                    </div>
                    <p style="color:#666;margin:5px 0 0 0;">Performance score</p>
                </div>
                
            </div>
            
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
     * Render Import Wizard Page
     */
    public function render_import_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        switch ($step) {
            case 2:
                $this->render_import_step2();
                break;
            case 3:
                $this->render_import_step3();
                break;
            default:
                $this->render_import_step1();
                break;
        }
    }

    private function render_import_step1() {
        if (isset($_POST['alma_import_nonce']) && wp_verify_nonce($_POST['alma_import_nonce'], 'alma_import_step1')) {
            if (!empty($_FILES['import_file']['name'])) {
                $file = $_FILES['import_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = array('csv', 'tsv', 'xlsx');
                if (!in_array($ext, $allowed)) {
                    echo '<div class="notice notice-error"><p>Formato file non supportato.</p></div>';
                } else {
                    $upload = wp_handle_upload($file, array('test_form' => false));
                    if (!isset($upload['error'])) {
                        set_transient($this->get_import_transient_name(), $upload['file'], HOUR_IN_SECONDS);
                        wp_redirect(add_query_arg('step', 2, admin_url('edit.php?post_type=affiliate_link&page=alma-import')));
                        exit;
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html($upload['error']) . '</p></div>';
                    }
                }
            }
        }

        if (isset($_POST['alma_delete_nonce']) && wp_verify_nonce($_POST['alma_delete_nonce'], 'alma_import_delete')) {
            $import_id = sanitize_text_field($_POST['delete_import_id']);
            if ($import_id !== '') {
                $deleted = $this->delete_imported_links($import_id);
                echo '<div class="notice notice-success"><p>Eliminati ' . intval($deleted) . ' link importati.</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Importa Link - Step 1', 'affiliate-link-manager-ai'); ?></h1>
            <p>Carica un file <strong>CSV</strong> o <strong>TSV</strong> con intestazione nella prima riga. Campi obbligatori: <code>post_title</code> e <code>_affiliate_url</code>. Campi opzionali: <code>_link_rel</code>, <code>_link_target</code>, <code>_link_title</code> e <code>link_type</code> (separa termini multipli con virgole).</p>
            <form method="post" enctype="multipart/form-data" style="margin-bottom:30px;">
                <?php wp_nonce_field('alma_import_step1', 'alma_import_nonce'); ?>
                <input type="file" name="import_file" accept=".csv,.tsv,.xlsx" required />
                <?php submit_button(__('Carica e continua', 'affiliate-link-manager-ai')); ?>
            </form>

            <h2><?php _e('Elimina link importati', 'affiliate-link-manager-ai'); ?></h2>
            <p>Hai un ID importazione precedente? Inseriscilo per cancellare tutti i link creati in quel batch.</p>
            <form method="post">
                <?php wp_nonce_field('alma_import_delete', 'alma_delete_nonce'); ?>
                <input type="text" name="delete_import_id" placeholder="ID importazione" />
                <?php submit_button(__('Elimina link', 'affiliate-link-manager-ai'), 'delete'); ?>
            </form>
        </div>
        <?php
    }

    private function render_import_step2() {
        $file = get_transient($this->get_import_transient_name());
        if (!$file || !file_exists($file)) {
            echo '<div class="wrap"><h1>Errore</h1><p>File non trovato.</p></div>';
            return;
        }

        list($header) = $this->get_file_data($file);

        if (isset($_POST['alma_map_nonce']) && wp_verify_nonce($_POST['alma_map_nonce'], 'alma_import_step2')) {
            $mapping = array(
                'post_title' => sanitize_text_field($_POST['map_post_title']),
                '_affiliate_url' => sanitize_text_field($_POST['map_affiliate_url']),
                '_link_rel' => sanitize_text_field($_POST['map_link_rel']),
                '_link_target' => sanitize_text_field($_POST['map_link_target']),
                '_link_title' => sanitize_text_field($_POST['map_link_title']),
                'link_type' => sanitize_text_field($_POST['map_link_type']),
            );

            if (!$mapping['post_title'] || !$mapping['_affiliate_url']) {
                echo '<div class="notice notice-error"><p>Campi obbligatori mancanti.</p></div>';
            } else {
                set_transient($this->get_import_transient_name() . '_map', $mapping, HOUR_IN_SECONDS);
                wp_redirect(add_query_arg('step', 3, admin_url('edit.php?post_type=affiliate_link&page=alma-import')));
                exit;
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Importa Link - Step 2', 'affiliate-link-manager-ai'); ?></h1>
            <p>Abbina le colonne del tuo file ai campi del plugin. I campi contrassegnati con * sono obbligatori.</p>
            <form method="post">
                <?php wp_nonce_field('alma_import_step2', 'alma_map_nonce'); ?>
                <table class="form-table">
                    <?php
                    $fields = array(
                        'map_post_title' => array('label' => 'Titolo (post_title)', 'required' => true),
                        'map_affiliate_url' => array('label' => 'URL Affiliato (_affiliate_url)', 'required' => true),
                        'map_link_rel' => array('label' => 'Rel (_link_rel)', 'required' => false),
                        'map_link_target' => array('label' => 'Target (_link_target)', 'required' => false),
                        'map_link_title' => array('label' => 'Title (_link_title)', 'required' => false),
                        'map_link_type' => array('label' => 'Tipologia (link_type)', 'required' => false),
                    );
                    foreach ($fields as $name => $info) {
                        echo '<tr><th><label for="' . $name . '">' . $info['label'];
                        if ($info['required']) {
                            echo ' *';
                        }
                        echo '</label></th><td><select name="' . $name . '" id="' . $name . '"><option value="">--</option>';
                        foreach ($header as $col) {
                            echo '<option value="' . esc_attr($col) . '">' . esc_html($col) . '</option>';
                        }
                        echo '</select></td></tr>';
                    }
                    ?>
                </table>
                <?php submit_button(__('Conferma mappatura', 'affiliate-link-manager-ai')); ?>
            </form>
        </div>
        <?php
    }

    private function render_import_step3() {
        $file = get_transient($this->get_import_transient_name());
        $mapping = get_transient($this->get_import_transient_name() . '_map');

        if (!$file || !$mapping || !file_exists($file)) {
            echo '<div class="wrap"><h1>Errore</h1><p>Dati importazione mancanti.</p></div>';
            return;
        }

        list($header, $rows) = $this->get_file_data($file, 5);

        if (isset($_POST['alma_import_confirm']) && wp_verify_nonce($_POST['alma_import_confirm'], 'alma_import_step3')) {
            $import_id = uniqid('alma_', false);
            $result = $this->process_import($file, $mapping, $import_id);
            delete_transient($this->get_import_transient_name());
            delete_transient($this->get_import_transient_name() . '_map');
            @unlink($file);

            echo '<div class="wrap"><h1>Report Importazione</h1>';
            echo '<p>Successi: ' . $result['success'] . ' | Fallimenti: ' . $result['failed'] . '</p>';
            if (!empty($result['errors'])) {
                echo '<ul>';
                foreach ($result['errors'] as $err) {
                    echo '<li>' . esc_html($err) . '</li>';
                }
                echo '</ul>';
            }
            echo '<p>ID importazione: <code>' . esc_html($import_id) . '</code></p>';
            echo '<form method="post" style="margin-top:20px;">';
            wp_nonce_field('alma_import_delete', 'alma_delete_nonce');
            echo '<input type="hidden" name="delete_import_id" value="' . esc_attr($import_id) . '" />';
            submit_button(__('Elimina questi link', 'affiliate-link-manager-ai'), 'delete');
            echo '</form>';
            echo '<p><a class="button" href="' . admin_url('edit.php?post_type=affiliate_link&page=alma-import') . '">Nuova Importazione</a></p>';
            echo '</div>';
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Importa Link - Step 3', 'affiliate-link-manager-ai'); ?></h1>
            <h2><?php _e('Anteprima', 'affiliate-link-manager-ai'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <?php foreach ($mapping as $field => $col) { if ($col) { echo '<th>' . esc_html($field) . '</th>'; } } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) {
                        $assoc = array_combine($header, $row);
                        echo '<tr>';
                        foreach ($mapping as $field => $col) {
                            if ($col) {
                                $val = $assoc[$col] ?? '';
                                echo '<td>' . esc_html($val) . '</td>';
                            }
                        }
                        echo '</tr>';
                    } ?>
                </tbody>
            </table>
            <form method="post">
                <?php wp_nonce_field('alma_import_step3', 'alma_import_confirm'); ?>
                <?php submit_button(__('Avvia importazione', 'affiliate-link-manager-ai')); ?>
            </form>
        </div>
        <?php
    }

    private function process_import($file, $mapping, $import_id) {
        list($header, $rows) = $this->get_file_data($file);
        $success = 0;
        $failed = 0;
        $errors = array();

        foreach ($rows as $index => $row) {
            $assoc = array_combine($header, $row);
            $title = trim($assoc[$mapping['post_title']] ?? '');
            $url = trim($assoc[$mapping['_affiliate_url']] ?? '');

            if ($title === '' || $url === '') {
                $failed++;
                $errors[] = sprintf('Riga %d: campi obbligatori mancanti', $index + 2);
                continue;
            }

            $post_id = wp_insert_post(
                array(
                    'post_title' => $title,
                    'post_type' => 'affiliate_link',
                    'post_status' => 'publish',
                ),
                true
            );

            if (is_wp_error($post_id)) {
                $failed++;
                $errors[] = sprintf('Riga %d: %s', $index + 2, $post_id->get_error_message());
                continue;
            }

            update_post_meta($post_id, '_affiliate_url', $url);
            update_post_meta($post_id, '_alma_import_id', $import_id);

            foreach (array('_link_rel', '_link_target', '_link_title') as $meta_key) {
                if (!empty($mapping[$meta_key])) {
                    $val = $assoc[$mapping[$meta_key]] ?? '';
                    if ($val !== '') {
                        update_post_meta($post_id, $meta_key, $val);
                    }
                }
            }

            if (!empty($mapping['link_type'])) {
                $terms_raw = $assoc[$mapping['link_type']] ?? '';
                if ($terms_raw !== '') {
                    $terms = array_map('trim', explode(',', $terms_raw));
                    wp_set_object_terms($post_id, $terms, 'link_type');
                }
            }

            $success++;
        }

        return array(
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        );
    }

    private function delete_imported_links($import_id) {
        $posts = get_posts(array(
            'post_type' => 'affiliate_link',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => '_alma_import_id',
            'meta_value' => $import_id,
        ));

        foreach ($posts as $post_id) {
            wp_delete_post($post_id, true);
        }

        return count($posts);
    }

    private function get_file_data($file, $limit = null) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === 'xlsx' && class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($file);
            $rows = $xlsx ? $xlsx->rows() : array();
            $header = array_shift($rows);
            if ($limit !== null) {
                $rows = array_slice($rows, 0, $limit);
            }
            return array($header, $rows);
        }

        $delimiter = $ext === 'tsv' ? "\t" : ',';
        $header = array();
        $rows = array();
        if (($handle = fopen($file, 'r')) !== false) {
            $header = fgetcsv($handle, 0, $delimiter);
            $count = 0;
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rows[] = $data;
                if ($limit !== null && ++$count >= $limit) {
                    break;
                }
            }
            fclose($handle);
        }
        return array($header, $rows);
    }

    private function get_import_transient_name() {
        return 'alma_import_' . get_current_user_id();
    }
    
    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }
        
        // Salva impostazioni se form inviato
        if (isset($_POST['alma_save_settings']) && 
            isset($_POST['alma_settings_nonce']) && 
            wp_verify_nonce($_POST['alma_settings_nonce'], 'alma_save_settings')) {
            
            // Impostazioni generali
            update_option('alma_track_logged_out', sanitize_text_field($_POST['track_logged_out'] ?? 'yes'));
            update_option('alma_shortcode_cleanup', sanitize_text_field($_POST['shortcode_cleanup'] ?? 'replace'));
            update_option('alma_enable_ai', sanitize_text_field($_POST['enable_ai'] ?? 'yes'));
            
            // Claude API settings
            update_option('alma_claude_api_key', sanitize_text_field($_POST['claude_api_key'] ?? ''));
            update_option('alma_claude_model', sanitize_text_field($_POST['claude_model'] ?? 'claude-3-haiku-20240307'));
            update_option('alma_claude_temperature', floatval($_POST['claude_temperature'] ?? 0.7));
            
            echo '<div class="notice notice-success"><p>' . __('Impostazioni salvate!', 'affiliate-link-manager-ai') . '</p></div>';
        }
        
        // Recupera impostazioni attuali
        $track_logged_out = get_option('alma_track_logged_out', 'yes');
        $shortcode_cleanup = get_option('alma_shortcode_cleanup', 'replace');
        $enable_ai = get_option('alma_enable_ai', 'yes');
        $claude_api_key = get_option('alma_claude_api_key', '');
        $claude_model = get_option('alma_claude_model', 'claude-3-haiku-20240307');
        $claude_temperature = get_option('alma_claude_temperature', 0.7);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Impostazioni - Affiliate Link Manager AI', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:14px;color:#666;">Versione <?php echo ALMA_VERSION; ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('alma_save_settings', 'alma_settings_nonce'); ?>
                
                <!-- Tabs Navigation -->
                <h2 class="nav-tab-wrapper alma-settings-tabs">
                    <a href="#general" class="nav-tab nav-tab-active">Generale</a>
                    <a href="#tracking" class="nav-tab">Tracking</a>
                    <a href="#ai" class="nav-tab">AI Settings</a>
                    <a href="#claude" class="nav-tab">Claude API</a>
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
                
                <!-- Cleanup Settings -->
                <div id="cleanup" class="alma-settings-section" style="display:none;">
                    <h2>Impostazioni Pulizia</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="shortcode_cleanup"><?php _e('Quando un link viene eliminato', 'affiliate-link-manager-ai'); ?></label>
                            </th>
                            <td>
                                <select name="shortcode_cleanup" id="shortcode_cleanup">
                                    <option value="replace" <?php selected($shortcode_cleanup, 'replace'); ?>>
                                        Sostituisci con testo barrato
                                    </option>
                                    <option value="remove" <?php selected($shortcode_cleanup, 'remove'); ?>>
                                        Rimuovi completamente
                                    </option>
                                    <option value="comment" <?php selected($shortcode_cleanup, 'comment'); ?>>
                                        Converti in commento HTML
                                    </option>
                                    <option value="none" <?php selected($shortcode_cleanup, 'none'); ?>>
                                        Non modificare (sconsigliato)
                                    </option>
                                </select>
                                <p class="description">Cosa fare con gli shortcode quando elimini un link</p>
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
    
    public function check_link_usage() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_check_usage')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        $link_id = intval($_POST['link_id']);
        $posts = $this->find_posts_with_shortcode($link_id);
        
        wp_send_json_success(array(
            'usage' => !empty($posts),
            'count' => count($posts),
            'posts' => $posts
        ));
    }
    
    public function ajax_get_ai_suggestions() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alma_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        $link_id = intval($_POST['link_id']);

        // Genera suggerimenti AI basati su Claude
        $suggestions = $this->generate_ai_suggestions($link_id);

        if (empty($suggestions)) {
            wp_send_json_error(__('Impossibile generare suggerimenti con Claude.', 'affiliate-link-manager-ai'));
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

        if (empty($suggestions)) {
            wp_send_json_error(__('Impossibile generare suggerimenti con Claude.', 'affiliate-link-manager-ai'));
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
        $response = $this->call_claude_api('Rispondi solo con: "Connessione OK"');
        
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
        
        $days = intval($_POST['days'] ?? 30);
        
        // Genera dati fittizi per il grafico (da implementare con dati reali)
        $labels = array();
        $clicks = array();
        $ctr = array();
        
        for ($i = $days; $i > 0; $i--) {
            $labels[] = date('d/m', strtotime("-$i days"));
            $clicks[] = rand(10, 100);
            $ctr[] = rand(1, 10);
        }
        
        wp_send_json_success(array(
            'labels' => $labels,
            'clicks' => $clicks,
            'ctr' => $ctr
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
        // Implementazione semplificata
        return rand(100, 500);
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
        // Implementazione semplificata
        return rand(2, 8);
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
    
    private function generate_ai_suggestions($link_id) {
        $post = get_post($link_id);

        if (!$post || $post->post_type !== 'affiliate_link') {
            return array();
        }

        $prompt = sprintf(
            'In base al titolo "%s" e al contenuto "%s", genera 5 suggerimenti brevi in italiano per ottimizzare un link affiliato. Restituisci un JSON array di oggetti con le chiavi "title" e "description".',
            wp_strip_all_tags($post->post_title),
            wp_strip_all_tags($post->post_content)
        );

        $response = $this->call_claude_api($prompt);

        if (!empty($response['success']) && !empty($response['response'])) {
            $decoded = json_decode($response['response'], true);

            if (is_array($decoded)) {
                $suggestions = array();

                foreach ($decoded as $item) {
                    if (isset($item['title']) && isset($item['description'])) {
                        $suggestions[] = array(
                            'title'       => sanitize_text_field($item['title']),
                            'description' => sanitize_text_field($item['description'])
                        );
                    }
                }

                return $suggestions;
            }
        }

        return array();
    }

    private function generate_title_suggestions($title, $description) {
        $prompt = sprintf(
            'In base al titolo "%s" e al contenuto "%s", genera 5 varianti di testo in italiano per promuovere un link affiliato. Restituisci un JSON array con solo i testi delle varianti.',
            wp_strip_all_tags($title),
            wp_strip_all_tags($description)
        );

        $response = $this->call_claude_api($prompt);

        if (!empty($response['success']) && !empty($response['response'])) {
            $decoded = json_decode($response['response'], true);

            if (is_array($decoded)) {
                $suggestions = array();
                foreach ($decoded as $text) {
                    $suggestions[] = array(
                        'text'       => sanitize_text_field($text),
                        'confidence' => 90,
                        'pattern'    => 'ai'
                    );
                }

                return $suggestions;
            }
        }

        return array();
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
    
    private function call_claude_api($prompt) {
        $api_key = get_option('alma_claude_api_key');
        
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'API Key non configurata');
        }
        
        $model       = get_option('alma_claude_model', 'claude-3-haiku-20240307');
        $temperature = (float) get_option('alma_claude_temperature', 0.7);

        $body = array(
            'model'       => $model,
            'max_tokens'  => 300,
            'temperature' => $temperature,
            'messages'    => array(
                array('role' => 'user', 'content' => $prompt)
            )
        );

        $start    = microtime(true);
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'       => 'application/json',
                'x-api-key'          => $api_key,
                'anthropic-version'  => '2023-06-01',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ));

        $time = round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code || empty($data['content'][0]['text'])) {
            return array('success' => false, 'error' => 'Risposta non valida da Claude');
        }

        return array(
            'success'       => true,
            'model'         => $data['model'] ?? $model,
            'response_time' => $time,
            'response'      => $data['content'][0]['text'],
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
        $cleanup_option = get_option('alma_shortcode_cleanup', 'replace');
        
        if ($cleanup_option === 'none') {
            return;
        }
        
        $affected_posts = $this->find_posts_with_shortcode($link_id);
        
        if (empty($affected_posts)) {
            return;
        }
        
        $link_title = get_the_title($link_id);
        
        foreach ($affected_posts as $post_id) {
            $post = get_post($post_id);
            $content = $post->post_content;
            
            switch ($cleanup_option) {
                case 'remove':
                    $content = $this->remove_shortcode_from_content($content, $link_id);
                    break;
                case 'replace':
                    $content = $this->replace_shortcode_in_content($content, $link_id, $link_title);
                    break;
                case 'comment':
                    $content = $this->comment_shortcode_in_content($content, $link_id);
                    break;
            }
            
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
        }
    }
    
    private function remove_shortcode_from_content($content, $link_id) {
        $pattern = '/\[affiliate_link[^\]]*id=["\']?' . $link_id . '["\']?[^\]]*\]/';
        return preg_replace($pattern, '', $content);
    }
    
    private function replace_shortcode_in_content($content, $link_id, $link_title) {
        $pattern = '/\[affiliate_link[^\]]*id=["\']?' . $link_id . '["\']?[^\]]*\]/';
        $replacement = '<span style="text-decoration:line-through;color:#999;">' . esc_html($link_title) . '</span>';
        return preg_replace($pattern, $replacement, $content);
    }
    
    private function comment_shortcode_in_content($content, $link_id) {
        $pattern = '/\[affiliate_link[^\]]*id=["\']?' . $link_id . '["\']?[^\]]*\]/';
        return preg_replace_callback($pattern, function($matches) {
            return '<!-- Affiliate link removed: ' . $matches[0] . ' -->';
        }, $content);
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

?>