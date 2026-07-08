<?php
/**
 * Plugin Name: Affiliate Link Manager AI
 * Plugin URI: https://your-website.com
 * Description: Gestisce link affiliati con intelligenza artificiale per ottimizzazione e tracking automatico.
 * Version: 2.90.2
 * Author: Cosè Murciano
 * License: GPL v2 or later
 * Text Domain: affiliate-link-manager-ai
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisci costanti del plugin
define('ALMA_VERSION', '2.90.2');
define('ALMA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ALMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ALMA_PLUGIN_FILE', __FILE__);

// Utilità comuni per le interazioni con l'AI
require_once ALMA_PLUGIN_DIR . 'includes/class-logger.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-utils.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-content-analysis-ai.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-dashboard-stats.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-dashboard-insights.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-openai-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-usage-logger.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-widget-ai-rewriter.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-admin.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-store.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-media-index.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-text-utils.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-knowledge-indexer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-knowledge-search.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-affiliate-index.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-internal-link-index.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-selection-session.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-ideas.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-media-indexer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-source-tech-registry.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-source-manager.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-document-manager.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-context-builder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-affiliate-selector.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-internal-link-selector.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-media-selector.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-opportunity-scorer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-planner.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-brief-builder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-draft-quality-checker.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-draft-builder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-result-usage.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-instructions-manager.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-idea-importer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-idea-agent.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-agent-control-room.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-insertion-rules.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-post-optimizer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-seo-bridge.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-telegram-bot.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-post-enricher.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-gsc-connector.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-link-auditor.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-link-health-checker.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-universal-link-types.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-image-generator.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-article-locations-map.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-google-trends.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-facts.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-url-validator.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-interface.php';
require_once ALMA_PLUGIN_DIR . 'includes/providers/class-affiliate-source-provider-manual.php';
require_once ALMA_PLUGIN_DIR . 'includes/providers/class-affiliate-source-provider-csv.php';
require_once ALMA_PLUGIN_DIR . 'includes/providers/class-affiliate-source-provider-generic-api.php';
require_once ALMA_PLUGIN_DIR . 'includes/providers/class-affiliate-source-provider-custom-api.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-registry.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-presets.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-client-fallback.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-client-custom-api.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-client-viator.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-client-getyourguide.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-viator-field-catalog.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-viator-destination-resolver.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-getyourguide-field-catalog.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-viator-media-resolver.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-getyourguide-media-resolver.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-provider-client-factory.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-connection-test-storage.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-connection-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-field-discovery-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-archive-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-normalizer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-import-dedupe-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-media-sideload-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-importer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-import-record-filter.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-gyg-csv-importer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-gyg-csv-import-job-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-import-preview-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-manual-import-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-import-criteria-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-link-ai-context-builder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-source-manager.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-links-source-filter.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-widget-layout-registry.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-contextual-affiliate-matcher.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-contextual-affiliate-widget.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-assets.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-editor-ajax.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-content-agent-dashboard-widget.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-store.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-job-store.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-google-geocoder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-geocoder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-metabox.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-importer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-affiliate-link-importer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-auto-indexer.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-ai-location-extractor.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-geocoding-queue.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-map.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-trip-finder.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-geo-index-admin.php';

/**
 * Classe principale del plugin
 */
class AffiliateManagerAI {
    const AI_CONTENT_AGENT_MENU_SLUG = 'alma-ai-content-agent';
    const AI_CONTENT_AGENT_CAPABILITY = 'manage_options';
    const AFFILIATE_LINK_PARENT_MENU = 'edit.php?post_type=affiliate_link';
    const AFFILIATE_LINK_SAVE_TRANSIENT_PREFIX = 'alma_affiliate_link_editor_save_';
    const AFFILIATE_LINK_SAVE_TRANSIENT_TTL = 30;
    const AFFILIATE_LINK_SAVE_FALLBACK_MAX_AGE = 10;

    private $dashboard_stats;
    private $source_manager;
    private $affiliate_links_source_filter;
    private $assets;
    private $shortcodes;
    private $editor_ajax;
    private $ai_content_agent_dashboard_widget;
    private $geo_index_store;
    private $geo_index_metabox;
    private $geo_index_admin;
    private $geo_auto_indexer;
    private $geo_map;
    private $trip_finder;

    public function __construct() {
        global $wpdb;
        $this->dashboard_stats = new ALMA_Dashboard_Stats($wpdb);
        $this->source_manager = new ALMA_Affiliate_Source_Manager();
        $this->affiliate_links_source_filter = new ALMA_Affiliate_Links_Source_Filter();
        $this->assets = new ALMA_Assets($this->source_manager);
        $this->shortcodes = new ALMA_Shortcodes();
        $this->editor_ajax = new ALMA_Editor_Ajax($this->dashboard_stats);
        $this->ai_content_agent_dashboard_widget = new ALMA_AI_Content_Agent_Dashboard_Widget();
        $this->geo_index_store = new ALMA_Geo_Index_Store();
        $this->geo_index_metabox = new ALMA_Geo_Index_Metabox($this->geo_index_store);
        $this->geo_index_admin = new ALMA_Geo_Index_Admin($this->geo_index_store);
        $this->geo_auto_indexer = new ALMA_Geo_Auto_Indexer($this->geo_index_store);
        $this->geo_auto_indexer->init_hooks();
        ALMA_Geo_Geocoding_Queue::init();
        $this->geo_map = new ALMA_Geo_Map($this->geo_index_store);
        $this->geo_map->init();
        $this->trip_finder = new ALMA_Trip_Finder();
        $this->trip_finder->init();
        ALMA_Dashboard_Insights::init();
        ALMA_AI_Content_Agent_Idea_Importer::init();
        ALMA_AI_Idea_Agent::init();
        ALMA_AI_Agent_Control_Room::init();
        ALMA_AI_Post_Optimizer::init();
        ALMA_Telegram_Bot::init();
        ALMA_AI_Post_Enricher::init();
        ALMA_GSC_Connector::init();
        ALMA_Affiliate_Link_Auditor::init();
        ALMA_Link_Health_Checker::init();
        ALMA_Universal_Link_Types::init();
        ALMA_AI_Image_Generator::init();
        ALMA_Article_Locations_Map::init();
        ALMA_Geo_Facts::init();
        add_action('init', array($this, 'init'));
        add_action('widgets_init', array('ALMA_Contextual_Affiliate_Widget', 'register_widget'));
        // Registrato qui (prima che `widgets_init` scatti) perché ALMA_Shortcodes::init()
        // gira su `init` priorità 10, quando l'hook è già passato.
        add_action('widgets_init', array($this->shortcodes, 'register_widget'));
        // Consente di definire le chiavi API in wp-config.php senza salvarle nel database:
        // define('ALMA_OPENAI_API_KEY', '...'); define('ALMA_GEO_GOOGLE_MAPS_API_KEY', '...');
        add_filter('pre_option_alma_openai_api_key', array(__CLASS__, 'filter_openai_api_key_constant'));
        add_filter('pre_option_alma_geo_google_maps_api_key', array(__CLASS__, 'filter_google_maps_api_key_constant'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'maybe_run_update_tasks'));
        
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
        add_theme_support('post-thumbnails', array('affiliate_link', 'post'));
        
        // Inizializza hooks
        $this->init_hooks();
        
        // Admin hooks
        if (is_admin()) {
            $this->init_admin_hooks();
            $this->affiliate_links_source_filter->init();
        }
        
        // Asset loading is routed through the dedicated bootstrap class.
        $this->assets->init();
    }
    
    /**
     * Inizializza hooks
     */
    private function init_hooks() {
        ALMA_AI_Content_Agent_Internal_Link_Index::init();

        // Shortcodes and editor AJAX are now routed through dedicated classes.
        $this->shortcodes->init();
        $this->editor_ajax->init();
        add_action('save_post', array('ALMA_Contextual_Affiliate_Widget', 'maybe_invalidate_on_save'), 20, 3);
        
        // Hook AJAX per tracking click (modificato per tracking asincrono)
        add_action('wp_ajax_alma_track_click', array($this, 'ajax_track_click'));
        add_action('wp_ajax_nopriv_alma_track_click', array($this, 'ajax_track_click'));

        // Hook per dashboard data
        add_action('wp_ajax_alma_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_alma_get_chart_data', array($this, 'ajax_get_chart_data'));
        add_action('wp_ajax_alma_get_link_stats', array($this, 'ajax_get_link_stats'));
        
        // L'evento giornaliero alma_daily_optimization non aveva alcun handler registrato
        // e girava a vuoto: non viene più schedulato e le occorrenze residue vengono
        // rimosse una sola volta (vedi maybe_run_update_tasks / deactivate).

        add_action('save_post', array($this, 'invalidate_dashboard_cache'));
        add_action('deleted_post', array($this, 'invalidate_dashboard_cache'));
        add_action('trashed_post', array($this, 'invalidate_dashboard_cache'));
        add_action('untrashed_post', array($this, 'invalidate_dashboard_cache'));
    }

    /**
     * Se definita in wp-config.php, la costante ha priorità sull'option nel DB.
     * Ritornare $pre (false) lascia la normale lettura dal database.
     */
    public static function filter_openai_api_key_constant($pre) {
        if (defined('ALMA_OPENAI_API_KEY') && ALMA_OPENAI_API_KEY !== '') {
            return ALMA_OPENAI_API_KEY;
        }
        return $pre;
    }

    public static function filter_google_maps_api_key_constant($pre) {
        if (defined('ALMA_GEO_GOOGLE_MAPS_API_KEY') && ALMA_GEO_GOOGLE_MAPS_API_KEY !== '') {
            return ALMA_GEO_GOOGLE_MAPS_API_KEY;
        }
        return $pre;
    }

    private function ajax_require_nonce($action, $field = 'nonce') {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(array('message' => __('Verifica di sicurezza non riuscita.', 'affiliate-link-manager-ai')), 403);
        }
    }

    private function ajax_require_capability($capability, $args = array()) {
        $allowed = empty($args) ? current_user_can($capability) : current_user_can($capability, $args[0]);
        if (!$allowed) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
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
        $source  = sanitize_key($_POST['source'] ?? 'unknown');

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

        // L'opzione "non tracciare utenti anonimi" era applicata solo in JavaScript:
        // va rispettata anche qui, altrimenti l'endpoint accetta click comunque.
        if (!is_user_logged_in() && get_option('alma_track_logged_out', 'yes') !== 'yes') {
            wp_send_json_success(array('link_id' => $link_id, 'tracked' => false, 'message' => 'Tracking disabled for logged out users'));
            return;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if ($this->is_bot_user_agent($user_agent)) {
            wp_send_json_success(array('link_id' => $link_id, 'tracked' => false, 'message' => 'Bot traffic ignored'));
            return;
        }

        // Rate limit breve per IP+UA+link: assorbe doppi eventi (click+middle-click)
        // e replay. Lo user agent nel key riduce le collisioni tra utenti diversi
        // dietro lo stesso IP (CDN, NAT aziendali).
        $user_ip = $this->get_user_ip();
        $rate_key = 'alma_click_rl_' . md5($user_ip . '|' . $user_agent . '|' . $link_id);
        if (get_transient($rate_key)) {
            wp_send_json_success(array('link_id' => $link_id, 'tracked' => false, 'message' => 'Duplicate click ignored'));
            return;
        }
        set_transient($rate_key, 1, 3);

        // Registra il click con incremento atomico: il read-modify-write precedente
        // perdeva click concorrenti.
        $new_count = $this->increment_click_count($link_id);
        update_post_meta($link_id, '_last_click', current_time('mysql'));

        // 🤖 Aggiorna dati per training AI (al massimo una volta l'ora per link:
        // la ricostruzione delle statistiche di utilizzo è costosa e i dati storici
        // sono comunque aggregati per giorno).
        if (!get_transient('alma_ai_training_' . $link_id)) {
            set_transient('alma_ai_training_' . $link_id, 1, HOUR_IN_SECONDS);
            $this->update_ai_training_data($link_id);
        }

        // Registra dati analytics dettagliati
        global $wpdb;
        $table_name = $wpdb->prefix . 'alma_analytics';

        // Verifica la presenza delle tabelle una sola volta per versione,
        // non con una SHOW TABLES a ogni click.
        if (get_option('alma_analytics_tables_verified') !== ALMA_VERSION) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
                $this->create_analytics_table();
                ALMA_AI_Usage_Logger::create_table();
                ALMA_Affiliate_Source_Manager::create_tables();
            }
            // Marca come verificato solo se la tabella esiste davvero, così un
            // fallimento di creazione (es. permessi CREATE mancanti) viene ritentato.
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
                update_option('alma_analytics_tables_verified', ALMA_VERSION, false);
            }
        }

        // Post di provenienza del click: il frontend invia l'URL della pagina
        // corrente (page_url); il referrer da solo indica la pagina PRECEDENTE
        // e non permette di attribuire il click all'articolo giusto.
        $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
        $source_post_id = $page_url !== '' ? absint(url_to_postid($page_url)) : 0;

        $inserted = $wpdb->insert($table_name, array(
            'link_id' => $link_id,
            'post_id' => $source_post_id,
            'click_time' => current_time('mysql'),
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'referrer' => isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '',
            'source' => $source
        ));
        if (false === $inserted) {
            ALMA_Logger::warning('Insert click analytics fallito', array('link_id' => $link_id, 'db_error' => $wpdb->last_error));
        }

        wp_send_json_success(array(
            'link_id' => $link_id,
            'new_count' => $new_count,
            'tracked' => true,
            'message' => 'Click tracked successfully'
        ));
    }

    /**
     * Incrementa _click_count in modo atomico direttamente su postmeta.
     * Ritorna il nuovo valore del contatore.
     */
    private function increment_click_count($link_id) {
        global $wpdb;
        $increment_sql = $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = '_click_count'",
            $link_id
        );
        $updated = $wpdb->query($increment_sql);
        if (!$updated) {
            // Primo click: crea la riga; se una richiesta concorrente l'ha appena
            // creata, add_post_meta(unique) fallisce e si riprova con l'UPDATE.
            if (!add_post_meta($link_id, '_click_count', 1, true)) {
                $wpdb->query($increment_sql);
            } else {
                // add_post_meta(unique) è check-then-insert, non atomico: due primi
                // click simultanei possono creare due righe che poi verrebbero
                // incrementate entrambe. Teniamo solo la riga più vecchia.
                $wpdb->query($wpdb->prepare(
                    "DELETE pm1 FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm1.meta_key = pm2.meta_key AND pm1.meta_id > pm2.meta_id WHERE pm1.post_id = %d AND pm1.meta_key = '_click_count'",
                    $link_id
                ));
            }
        }
        wp_cache_delete($link_id, 'post_meta');
        return (int) get_post_meta($link_id, '_click_count', true);
    }

    /**
     * Riconoscimento euristico dei bot più comuni dallo user agent.
     * Il risultato passa sempre dal filtro, quindi un sito può sia estendere
     * sia annullare la classificazione (es. UA legittimi che contengono "bot").
     */
    private function is_bot_user_agent($user_agent) {
        $ua = strtolower(trim((string) $user_agent));
        $is_bot = ($ua === '');
        if (!$is_bot) {
            // Pattern mirati ai crawler noti: un generico "bot" matcherebbe anche
            // device reali come i telefoni Cubot.
            $patterns = array(
                'googlebot', 'bingbot', 'yandex', 'baiduspider', 'duckduckbot',
                'applebot', 'petalbot', 'ahrefsbot', 'semrushbot', 'mj12bot',
                'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headless',
                'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client',
                'okhttp', 'httpclient', 'java/', 'libwww-perl', 'phantomjs', 'lighthouse',
                'bot/', 'bot;', '+http',
            );
            foreach ($patterns as $pattern) {
                if (strpos($ua, $pattern) !== false) {
                    $is_bot = true;
                    break;
                }
            }
        }
        /**
         * Consente di estendere o annullare il riconoscimento bot.
         *
         * @param bool   $is_bot     Esito dell'euristica interna.
         * @param string $user_agent User agent grezzo.
         */
        return (bool) apply_filters('alma_is_bot_user_agent', $is_bot, $user_agent);
    }
    
    /**
     * Helper per ottenere IP utente in modo sicuro
     */
    private function get_user_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            // X-Forwarded-For può contenere una lista "client, proxy1, proxy2":
            // senza lo split la validazione falliva e si ricadeva sull'IP del proxy.
            foreach (explode(',', (string) $_SERVER[$key]) as $candidate) {
                $ip = filter_var(trim($candidate), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
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
                'menu_name' => __('Tipologie Link', 'affiliate-link-manager-ai'),
            ),
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_menu' => false,
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
        add_action('save_post_affiliate_link', array($this, 'remember_affiliate_link_editor_save'), 1, 3);
        add_action('save_post_affiliate_link', array($this, 'save_link_meta'));
        add_action('admin_init', array($this, 'maybe_recover_affiliate_link_wrong_edit_redirect'), 1);
        // Priorità massima: nessun altro filtro deve poter riportare il
        // salvataggio di un Link Affiliato sull'elenco articoli.
        add_filter('redirect_post_location', array($this, 'normalize_affiliate_link_update_redirect'), PHP_INT_MAX, 2);
        add_filter('wp_redirect', array($this, 'guard_affiliate_link_editpost_redirect'), PHP_INT_MAX, 2);
        add_action('shutdown', array($this, 'record_affiliate_link_editpost_shutdown'), 1);
        add_action('admin_post_alma_export_affiliate_links_csv', array($this, 'export_affiliate_links_csv'));
        
        // Colonne personalizzate nella lista
        add_filter('manage_affiliate_link_posts_columns', array($this, 'set_custom_columns'));
        add_action('manage_affiliate_link_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
        add_filter('manage_edit-affiliate_link_sortable_columns', array($this, 'sortable_columns'));
        // Priorità 20: deve girare dopo ALMA_Affiliate_Links_Source_Filter (priorità 10),
        // che appende la propria clausola alla meta_query esistente; qui la si ingloba
        // in un gruppo AND insieme alla clausola di ordinamento.
        add_action('pre_get_posts', array($this, 'handle_sortable_columns_orderby'), 20);
        
        // Menu pages
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('admin_menu', array($this, 'reorder_dashboard_menu'), 100);

        // Cleanup shortcodes when widget instances change
        add_action('update_option_widget_affiliate_links_widget', array($this, 'on_widget_option_update'), 10, 3);
        
        // AJAX handlers
        add_action('wp_ajax_alma_get_ai_suggestions', array($this, 'ajax_get_ai_suggestions'));
        add_action('wp_ajax_alma_ai_suggest_text', array($this, 'ajax_ai_suggest_text'));
        add_action('wp_ajax_alma_test_openai_connection', array($this, 'ajax_test_openai_connection'));
        add_action('wp_ajax_alma_verify_openai_storage', array($this, 'ajax_verify_openai_storage'));
        add_action('wp_ajax_alma_get_performance_predictions', array($this, 'ajax_get_performance_predictions'));
        add_action('wp_ajax_alma_get_link_types', array($this, 'ajax_get_link_types'));
        add_action('wp_ajax_alma_import_affiliate_link', array($this, 'ajax_import_affiliate_link'));

        // Dashboard widget
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        $this->ai_content_agent_dashboard_widget->init();
        $this->geo_index_metabox->init();
        $this->geo_index_admin->init();
        add_action('pre_get_posts', array($this, 'filter_posts_without_affiliates'));
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
        // Imposta "sponsored noopener" come valore predefinito solo alla creazione
        $link_rel = get_post_meta($post->ID, '_link_rel', true);
        if (!metadata_exists('post', $post->ID, '_link_rel')) {
            $link_rel = 'sponsored noopener';
        }
        $link_target = get_post_meta($post->ID, '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($post->ID, '_link_title', true);
        $click_count = get_post_meta($post->ID, '_click_count', true) ?: 0;
        $last_click = get_post_meta($post->ID, '_last_click', true);
        $ai_score = get_post_meta($post->ID, '_ai_performance_score', true) ?: 0;
        $source_id = (int)get_post_meta($post->ID, '_alma_source_id', true);
        $source_name = 'Manuale';
        if ($source_id > 0) {
            global $wpdb;
            $source = $wpdb->get_row($wpdb->prepare("SELECT name, deleted_at FROM {$wpdb->prefix}alma_affiliate_sources WHERE id = %d", $source_id), ARRAY_A);
            if (is_array($source)) {
                $source_name = (string) ($source['name'] ?? ('Source #' . $source_id));
                if (!empty($source['deleted_at'])) {
                    $source_name .= ' (eliminata)';
                }
            } else {
                $snapshot_name = (string) get_post_meta($post->ID, '_alma_source_name', true);
                $source_name = $snapshot_name !== '' ? ($snapshot_name . ' (source eliminata)') : ('Source non disponibile #' . $source_id);
            }
        }
        
        echo '<table class="form-table">';
        
        // URL Affiliato
        echo '<tr>';
        echo '<th><label for="affiliate_url">' . __('URL Affiliato', 'affiliate-link-manager-ai') . ' <span style="color:red;">*</span></label></th>';
        echo '<td><input type="url" id="affiliate_url" name="affiliate_url" value="' . esc_attr($affiliate_url) . '" class="large-text" required />';
        echo '<p class="description">' . __('L\'URL completo del link affiliato (es: https://www.amazon.it/dp/...?tag=...)', 'affiliate-link-manager-ai') . '</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th><label>' . __('Provenienza', 'affiliate-link-manager-ai') . '</label></th>';
        echo '<td>' . esc_html($source_name ?: 'Manuale') . '</td>';
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
        $nonce_present = isset($_POST['affiliate_link_nonce']);
        $nonce_valid = $nonce_present && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['affiliate_link_nonce'])), 'save_affiliate_link');
        $this->log_affiliate_link_save_diagnostic('Affiliate link meta save received.', array(
            'post_id' => absint($post_id),
            'post_type' => get_post_type($post_id),
            'link_nonce_present' => (bool) $nonce_present,
            'link_nonce_valid' => (bool) $nonce_valid,
            'post_post_type' => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '',
            'post_action' => isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '',
        ));

        if (!$nonce_valid) {
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
        $legacy_url = get_post_meta($post_id, '_alma_affiliate_url', true);
        $canonical_url = get_post_meta($post_id, '_affiliate_url', true);
        if (empty($canonical_url) && !empty($legacy_url)) {
            update_post_meta($post_id, '_affiliate_url', esc_url_raw($legacy_url));
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

        if (!metadata_exists('post', $post_id, '_alma_provider')) { update_post_meta($post_id, '_alma_provider', 'manual'); }
        if (!metadata_exists('post', $post_id, '_alma_source_id')) { update_post_meta($post_id, '_alma_source_id', 0); }
        if (!metadata_exists('post', $post_id, '_alma_import_mode')) { update_post_meta($post_id, '_alma_import_mode', 'manual'); }
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
                $new_columns['affiliate_image'] = __('Immagine', 'affiliate-link-manager-ai');
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
                echo ' <button type="button" class="button button-small alma-copy-btn" data-copy="[affiliate_link id=&quot;' . $post_id . '&quot;]" style="margin-left:5px;">📋</button>';
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

            case 'affiliate_image':
                $thumb_id = (int) get_post_thumbnail_id($post_id);
                $status = sanitize_key(get_post_meta($post_id, '_alma_featured_image_import_status', true));
                $error = sanitize_text_field((string) get_post_meta($post_id, '_alma_featured_image_last_error', true));
                if ($thumb_id > 0) {
                    echo get_the_post_thumbnail($post_id, array(48, 48), array('style' => 'display:block;margin-bottom:4px;'));
                }
                if ($status === 'downloaded') {
                    echo '<span class="alma-image-badge alma-image-badge-ok">' . esc_html__('Importata', 'affiliate-link-manager-ai') . '</span>';
                } elseif ($status === 'reused_existing_attachment') {
                    echo '<span class="alma-image-badge alma-image-badge-ok">' . esc_html__('Riutilizzata', 'affiliate-link-manager-ai') . '</span>';
                } elseif (strpos($status, 'failed_') === 0) {
                    echo '<span class="alma-image-badge alma-image-badge-error" title="' . esc_attr($error) . '">' . esc_html__('Errore', 'affiliate-link-manager-ai') . '</span>';
                } elseif ($thumb_id < 1) {
                    echo '<span class="alma-image-badge alma-image-badge-missing">' . esc_html__('Mancante', 'affiliate-link-manager-ai') . '</span>';
                } else {
                    echo '<span class="alma-image-badge alma-image-badge-ok">' . esc_html__('Presente', 'affiliate-link-manager-ai') . '</span>';
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
     * Applica l'ordinamento reale alle colonne dichiarate ordinabili.
     */
    public function handle_sortable_columns_orderby($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'affiliate_link') {
            return;
        }
        $orderby = $query->get('orderby');
        $meta_map = array(
            'clicks'   => '_click_count',
            'ai_score' => '_ai_performance_score',
        );
        if (isset($meta_map[$orderby])) {
            // Il ramo NOT EXISTS mantiene in elenco anche i link privi del meta
            // (mai cliccati / senza score), che finiscono in coda all'ordinamento.
            $sort_clause = array(
                'relation' => 'OR',
                'alma_sort_value'   => array('key' => $meta_map[$orderby], 'compare' => 'EXISTS', 'type' => 'NUMERIC'),
                'alma_sort_missing' => array('key' => $meta_map[$orderby], 'compare' => 'NOT EXISTS'),
            );
            // La meta_query esistente (es. filtro per Source) va preservata:
            // sostituirla annullerebbe silenziosamente il filtro attivo.
            $existing = $query->get('meta_query');
            if (is_array($existing) && !empty($existing)) {
                $query->set('meta_query', array('relation' => 'AND', $existing, $sort_clause));
            } else {
                $query->set('meta_query', $sort_clause);
            }
            // Ordina esplicitamente sulla clausola nominata, così l'ordinamento non
            // dipende dalla prima clausola della meta_query combinata.
            $query->set('orderby', 'alma_sort_value');
        }
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

        // Editor CSS
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Editor CSS', 'affiliate-link-manager-ai'),
            __('Editor CSS', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-css-editor',
            array($this, 'render_css_editor_page')
        );

        // Tipologie Link
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Tipologie Link', 'affiliate-link-manager-ai'),
            __('Tipologie Link', 'affiliate-link-manager-ai'),
            'manage_categories',
            'edit-tags.php?taxonomy=link_type&post_type=affiliate_link'
        );

        // Creazione widget
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Crea Widget Link', 'affiliate-link-manager-ai'),
            __('Crea Widget Link', 'affiliate-link-manager-ai'),
            'manage_options',
            'alma-create-widget',
            array($this, 'render_create_widget_page')
        );

        // Shortcode widget
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Elenco Widget Link', 'affiliate-link-manager-ai'),
            __('Elenco Widget Link', 'affiliate-link-manager-ai'),
            'manage_options',
            'affiliate-link-widgets',
            array($this, 'render_widget_shortcode_page')
        );

        // Widget contestuale
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Widget Link Contestuale', 'affiliate-link-manager-ai'),
            __('Widget Contestuale', 'affiliate-link-manager-ai'),
            'manage_options',
            ALMA_Contextual_Affiliate_Widget::MENU_SLUG,
            array($this, 'render_contextual_widget_page')
        );

        // AI Content Agent
        add_submenu_page(
            self::AFFILIATE_LINK_PARENT_MENU,
            __('AI Content Agent', 'affiliate-link-manager-ai'),
            __('AI Content Agent', 'affiliate-link-manager-ai'),
            self::AI_CONTENT_AGENT_CAPABILITY,
            self::AI_CONTENT_AGENT_MENU_SLUG,
            array('ALMA_AI_Content_Agent_Admin', 'render_page')
        );

        // Regia AI (camera di regia dell'agente di ideazione)
        add_submenu_page(
            self::AFFILIATE_LINK_PARENT_MENU,
            __('Regia AI', 'affiliate-link-manager-ai'),
            __('Regia AI', 'affiliate-link-manager-ai'),
            self::AI_CONTENT_AGENT_CAPABILITY,
            ALMA_AI_Agent_Control_Room::MENU_SLUG,
            array('ALMA_AI_Agent_Control_Room', 'render_page')
        );

        // Tutte le idee (elenco, modello "Post")
        add_submenu_page(
            self::AFFILIATE_LINK_PARENT_MENU,
            __('Tutte le idee', 'affiliate-link-manager-ai'),
            __('Tutte le idee', 'affiliate-link-manager-ai'),
            self::AI_CONTENT_AGENT_CAPABILITY,
            ALMA_AI_Content_Agent_Admin::IDEAS_LIST_MENU_SLUG,
            array('ALMA_AI_Content_Agent_Admin', 'render_ideas_list_page')
        );

        // Aggiungi idea (workspace)
        add_submenu_page(
            self::AFFILIATE_LINK_PARENT_MENU,
            __('Aggiungi idea', 'affiliate-link-manager-ai'),
            __('Aggiungi idea', 'affiliate-link-manager-ai'),
            self::AI_CONTENT_AGENT_CAPABILITY,
            ALMA_AI_Content_Agent_Admin::ADD_IDEA_MENU_SLUG,
            array('ALMA_AI_Content_Agent_Admin', 'render_add_idea_page')
        );

        // Importazione massiva idee (pagina dedicata, raggiunta da Tutte le idee)
        add_submenu_page(
            null,
            __('Importazione massiva idee', 'affiliate-link-manager-ai'),
            __('Importazione massiva idee', 'affiliate-link-manager-ai'),
            self::AI_CONTENT_AGENT_CAPABILITY,
            ALMA_AI_Content_Agent_Idea_Importer::PAGE_SLUG,
            array('ALMA_AI_Content_Agent_Idea_Importer', 'render_page')
        );

        // Verifica link affiliati (audit URL + bonifica tpx.li)
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Verifica link affiliati', 'affiliate-link-manager-ai'),
            __('Verifica link', 'affiliate-link-manager-ai'),
            'manage_options',
            ALMA_Affiliate_Link_Auditor::MENU_SLUG,
            array('ALMA_Affiliate_Link_Auditor', 'render_page')
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
            // Regia AI subito dopo la Dashboard: la camera di regia dell'agente.
            ALMA_AI_Agent_Control_Room::MENU_SLUG,
            // Idee sul modello "Post": elenco + aggiungi.
            ALMA_AI_Content_Agent_Admin::IDEAS_LIST_MENU_SLUG,
            ALMA_AI_Content_Agent_Admin::ADD_IDEA_MENU_SLUG,
            'edit.php?post_type=affiliate_link',
            'post-new.php?post_type=affiliate_link',
            'edit-tags.php?taxonomy=link_type&post_type=affiliate_link',
            'alma-create-widget',
            'affiliate-link-widgets',
            ALMA_Contextual_Affiliate_Widget::MENU_SLUG,
            ALMA_Geo_Index_Admin::MENU_SLUG,
            ALMA_Geo_Map::MENU_SLUG,
            ALMA_Trip_Finder::MENU_SLUG,
            'alma-affiliate-sources',
            // Impostazioni AI Content prima delle Impostazioni generali.
            self::AI_CONTENT_AGENT_MENU_SLUG,
            'affiliate-link-manager-settings',
            'alma-css-editor',
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
                            $item[0] = __('Crea Widget Link', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-link-widgets':
                            $item[0] = __('Elenco Widget Link', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_Contextual_Affiliate_Widget::MENU_SLUG:
                            $item[0] = __('Widget Contestuale', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_Geo_Index_Admin::MENU_SLUG:
                            $item[0] = __('Indice Geografico', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_Geo_Map::MENU_SLUG:
                            $item[0] = __('Mappa Geografica', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_Trip_Finder::MENU_SLUG:
                            $item[0] = __('Trova Viaggio', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_AI_Content_Agent_Admin::IDEAS_LIST_MENU_SLUG:
                            $item[0] = __('Tutte le idee', 'affiliate-link-manager-ai');
                            break;
                        case ALMA_AI_Content_Agent_Admin::ADD_IDEA_MENU_SLUG:
                            $item[0] = __('Aggiungi idea', 'affiliate-link-manager-ai');
                            break;
                        case self::AI_CONTENT_AGENT_MENU_SLUG:
                            $item[0] = __('Impostazioni AI Content', 'affiliate-link-manager-ai');
                            break;
                        case 'alma-affiliate-sources':
                            $item[0] = __('Affiliate Sources', 'affiliate-link-manager-ai');
                            break;
                        case 'affiliate-link-manager-settings':
                            $item[0] = __('Impostazioni', 'affiliate-link-manager-ai');
                            break;
                        case 'alma-css-editor':
                            $item[0] = __('Editor CSS', 'affiliate-link-manager-ai');
                            break;
                    }
                    $new[] = $item;
                    break;
                }
            }
        }

        $known = array();
        foreach ($new as $item) { $known[] = $item[2]; }
        foreach ($items as $item) {
            if (!in_array($item[2], $known, true)) {
                $new[] = $item;
            }
        }

        $submenu[$parent] = $new;
    }
    

    /**
     * Render Contextual Widget settings page.
     */
    public function render_contextual_widget_page() {
        ALMA_Contextual_Affiliate_Widget::render_settings_page();
    }
    
    /**
     * Render Dashboard Page
     */
    /**
     * Dashboard snapshot-driven: legge SOLO lo snapshot precalcolato dal cron
     * giornaliero (ALMA_Dashboard_Insights) — nessuna query pesante durante
     * il rendering della pagina.
     */
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }
        $snapshot = ALMA_Dashboard_Insights::get_snapshot();
        $advice = ALMA_Dashboard_Insights::get_advice();
        ?>
        <div class="wrap">
            <h1><?php _e('Dashboard Link - Affiliate Link Manager', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:13px;color:#666;margin-top:2px;">
                <?php printf(esc_html__('Versione %s', 'affiliate-link-manager-ai'), esc_html(ALMA_VERSION)); ?>
                <?php if ($snapshot) : ?>
                    · <?php printf(esc_html__('Dati aggiornati al %s (elaborazione %d ms, ricostruiti ogni notte)', 'affiliate-link-manager-ai'), esc_html($snapshot['generated_at']), (int) $snapshot['duration_ms']); ?>
                <?php endif; ?>
                · <button type="button" class="button button-small" id="alma-insights-rebuild"><?php esc_html_e('Aggiorna ora', 'affiliate-link-manager-ai'); ?></button>
                <span id="alma-insights-rebuild-feedback" style="color:#2271b1;"></span>
            </p>

            <?php if (!$snapshot) : ?>
                <div class="notice notice-info"><p><?php esc_html_e('Nessuno snapshot ancora disponibile: premi "Aggiorna ora" per generare i dati (poi verranno ricostruiti automaticamente ogni notte).', 'affiliate-link-manager-ai'); ?></p></div>
            <?php else : ?>

                <?php
                $kpis = array(
                    '7' => __('Click ultimi 7 giorni', 'affiliate-link-manager-ai'),
                    '30' => __('Click ultimi 30 giorni', 'affiliate-link-manager-ai'),
                    '180' => __('Click ultimi 180 giorni', 'affiliate-link-manager-ai'),
                );
                ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin:16px 0;">
                    <?php foreach ($kpis as $key => $label) :
                        $period = $snapshot['periods'][$key] ?? array('clicks' => 0, 'previous' => 0, 'delta_pct' => null);
                        $delta = $period['delta_pct'];
                        $delta_color = $delta === null ? '#666' : ($delta >= 0 ? '#1a7f37' : '#d63638');
                        $delta_text = $delta === null ? __('n/d', 'affiliate-link-manager-ai') : (($delta >= 0 ? '▲ +' : '▼ ') . $delta . '%');
                        ?>
                        <div class="postbox" style="margin:0;"><div class="inside">
                            <h3 style="margin:4px 0 8px;"><?php echo esc_html($label); ?></h3>
                            <div style="font-size:28px;font-weight:700;line-height:1;"><?php echo esc_html(number_format_i18n((int) $period['clicks'])); ?></div>
                            <p style="margin:8px 0 0;color:<?php echo esc_attr($delta_color); ?>;font-weight:600;">
                                <?php echo esc_html($delta_text); ?>
                                <span style="color:#888;font-weight:400;"><?php printf(esc_html__('vs %s precedenti', 'affiliate-link-manager-ai'), esc_html(number_format_i18n((int) $period['previous']))); ?></span>
                            </p>
                        </div></div>
                    <?php endforeach; ?>
                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3 style="margin:4px 0 8px;"><?php esc_html_e('Link senza click (90 giorni)', 'affiliate-link-manager-ai'); ?></h3>
                        <div style="font-size:28px;font-weight:700;line-height:1;"><?php echo esc_html(number_format_i18n((int) ($snapshot['links_no_clicks_90d'] ?? 0))); ?></div>
                        <p style="margin:8px 0 0;color:#888;"><?php printf(esc_html__('su %s link pubblicati', 'affiliate-link-manager-ai'), esc_html(number_format_i18n((int) ($snapshot['links_total'] ?? 0)))); ?></p>
                    </div></div>
                </div>

                <div class="postbox"><div class="inside">
                    <h3 style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">📈 <?php esc_html_e('Andamento click', 'affiliate-link-manager-ai'); ?>
                        <span>
                            <button type="button" class="button button-small alma-insights-range" data-range="daily"><?php esc_html_e('Giorno (30gg)', 'affiliate-link-manager-ai'); ?></button>
                            <button type="button" class="button button-small alma-insights-range" data-range="weekly"><?php esc_html_e('Settimana (26)', 'affiliate-link-manager-ai'); ?></button>
                            <button type="button" class="button button-small alma-insights-range" data-range="monthly"><?php esc_html_e('Mese (12)', 'affiliate-link-manager-ai'); ?></button>
                        </span>
                    </h3>
                    <div style="height:320px;"><canvas id="alma-insights-chart"></canvas></div>
                </div></div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;margin-top:16px;">
                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3>🏆 <?php esc_html_e('Top Link (ultimi 30 giorni)', 'affiliate-link-manager-ai'); ?></h3>
                        <?php if (empty($snapshot['top_links'])) : ?>
                            <p><?php esc_html_e('Nessun click registrato negli ultimi 30 giorni.', 'affiliate-link-manager-ai'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead><tr>
                                    <th><?php esc_html_e('Link', 'affiliate-link-manager-ai'); ?></th>
                                    <th><?php esc_html_e('Tipologia', 'affiliate-link-manager-ai'); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e('Click 30gg', 'affiliate-link-manager-ai'); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e('Storico', 'affiliate-link-manager-ai'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($snapshot['top_links'] as $link) : ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url($link['edit_url']); ?>"><?php echo esc_html($link['title']); ?></a></td>
                                        <td><?php echo esc_html($link['types']); ?></td>
                                        <td style="text-align:right;font-weight:600;"><?php echo esc_html(number_format_i18n($link['clicks_period'])); ?></td>
                                        <td style="text-align:right;color:#666;"><?php echo esc_html(number_format_i18n($link['clicks_total'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div></div>

                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3>📰 <?php esc_html_e('Top Articoli per click affiliati', 'affiliate-link-manager-ai'); ?></h3>
                        <?php
                        $top_articles = !empty($snapshot['top_articles_30d']) ? $snapshot['top_articles_30d'] : ($snapshot['top_articles_all'] ?? array());
                        $articles_label = !empty($snapshot['top_articles_30d']) ? __('ultimi 30 giorni', 'affiliate-link-manager-ai') : __('da inizio tracciamento', 'affiliate-link-manager-ai');
                        ?>
                        <?php if (empty($top_articles)) : ?>
                            <p><?php esc_html_e('Ancora nessun dato: l\'articolo di provenienza viene registrato per i click ricevuti a partire da questa versione. I dati compariranno con i prossimi click.', 'affiliate-link-manager-ai'); ?></p>
                        <?php else : ?>
                            <p class="description" style="margin-top:0;"><?php echo esc_html($articles_label); ?></p>
                            <table class="widefat striped">
                                <thead><tr>
                                    <th><?php esc_html_e('Articolo', 'affiliate-link-manager-ai'); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e('Click affiliati', 'affiliate-link-manager-ai'); ?></th>
                                    <th></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($top_articles as $article) : ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url($article['edit_url']); ?>"><?php echo esc_html($article['title']); ?></a></td>
                                        <td style="text-align:right;font-weight:600;"><?php echo esc_html(number_format_i18n($article['clicks'])); ?></td>
                                        <td><a href="<?php echo esc_url($article['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Vedi', 'affiliate-link-manager-ai'); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div></div>
                </div>

                <h2 style="margin-top:24px;">🌍 <?php esc_html_e('Copertura geografica', 'affiliate-link-manager-ai'); ?></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;">
                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3>🔥 <?php esc_html_e('Località più cliccate (90gg)', 'affiliate-link-manager-ai'); ?></h3>
                        <?php if (empty($snapshot['geo_top'])) : ?>
                            <p><?php esc_html_e('Nessun click su link geolocalizzati nel periodo.', 'affiliate-link-manager-ai'); ?></p>
                        <?php else : ?>
                            <ul style="margin:0;">
                                <?php foreach ($snapshot['geo_top'] as $geo) : ?>
                                    <li>📍 <?php echo esc_html($geo['name']); ?><?php echo $geo['country'] !== '' ? esc_html(' (' . $geo['country'] . ')') : ''; ?> — <strong><?php echo esc_html(number_format_i18n($geo['clicks'])); ?></strong> <?php esc_html_e('click', 'affiliate-link-manager-ai'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div></div>

                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3>🧊 <?php esc_html_e('Con link ma SENZA click (90gg)', 'affiliate-link-manager-ai'); ?></h3>
                        <p class="description" style="margin-top:0;"><?php esc_html_e('L\'offerta esiste ma non produce: rivedi il posizionamento dei link o rafforza gli articoli di queste aree.', 'affiliate-link-manager-ai'); ?></p>
                        <?php if (empty($snapshot['geo_unused'])) : ?>
                            <p><?php esc_html_e('Nessuna: tutte le località con link hanno ricevuto click. 🎉', 'affiliate-link-manager-ai'); ?></p>
                        <?php else : ?>
                            <ul style="margin:0;">
                                <?php foreach ($snapshot['geo_unused'] as $geo) : ?>
                                    <li>📍 <?php echo esc_html($geo['name']); ?><?php echo $geo['country'] !== '' ? esc_html(' (' . $geo['country'] . ')') : ''; ?> — <?php printf(esc_html__('%1$s link, %2$s articoli', 'affiliate-link-manager-ai'), esc_html(number_format_i18n($geo['links'])), esc_html(number_format_i18n((int) ($geo['posts'] ?? 0)))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div></div>

                    <div class="postbox" style="margin:0;"><div class="inside">
                        <h3>💡 <?php esc_html_e('Articoli SENZA link affiliati', 'affiliate-link-manager-ai'); ?></h3>
                        <p class="description" style="margin-top:0;"><?php esc_html_e('Contenuto senza monetizzazione: importa o associa link affiliati per queste località.', 'affiliate-link-manager-ai'); ?></p>
                        <?php if (empty($snapshot['geo_no_links'])) : ?>
                            <p><?php esc_html_e('Nessuna: tutte le località con articoli hanno link affiliati. 🎉', 'affiliate-link-manager-ai'); ?></p>
                        <?php else : ?>
                            <ul style="margin:0;">
                                <?php foreach ($snapshot['geo_no_links'] as $geo) : ?>
                                    <li>📍 <?php echo esc_html($geo['name']); ?><?php echo $geo['country'] !== '' ? esc_html(' (' . $geo['country'] . ')') : ''; ?> — <?php printf(esc_html__('%s articoli', 'affiliate-link-manager-ai'), esc_html(number_format_i18n($geo['posts']))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div></div>
                </div>

                <h2 style="margin-top:24px;">🤖 <?php esc_html_e('Consigli strategici AI', 'affiliate-link-manager-ai'); ?></h2>
                <div class="postbox"><div class="inside">
                    <p><?php esc_html_e('I consigli strategici si sono trasferiti nella Regia AI, accorpati al consiglio del piano editoriale: lì l\'AI usa TUTTE le informazioni disponibili (statistiche complete, opportunità Search Console, ritmo di produzione) e ti propone piano + raccomandazioni applicabili con un click.', 'affiliate-link-manager-ai'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-regia')); ?>">🎬 <?php esc_html_e('Apri la Regia AI', 'affiliate-link-manager-ai'); ?></a></p>
                    <?php if ($advice) : ?>
                        <details><summary><?php esc_html_e('Ultimi consigli generati', 'affiliate-link-manager-ai'); ?> · <?php echo esc_html($advice['generated_at']); ?></summary>
                        <pre style="white-space:pre-wrap;font-family:inherit;font-size:14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:14px;"><?php echo esc_html($advice['text']); ?></pre></details>
                    <?php endif; ?>
                </div></div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Ricorda per pochi secondi qualunque salvataggio editor standard di un Link Affiliato.
     *
     * Questo marker serve solo al recupero del redirect e non dipende dai nonce dei metabox.
     */
    public function remember_affiliate_link_editor_save($post_id, $post = null, $update = false) {
        $post_id = absint($post_id);
        if (!$post instanceof WP_Post) {
            $post = $post_id ? get_post($post_id) : null;
        }
        $post_type = $post instanceof WP_Post ? $post->post_type : ($post_id ? get_post_type($post_id) : '');
        $transient_key = $this->get_affiliate_link_save_transient_key();
        $bypass_reason = $this->get_affiliate_link_redirect_bypass_reason($post_id, $post_type);

        if ($bypass_reason === '' && !current_user_can('edit_post', $post_id)) {
            $bypass_reason = 'capability_failed';
        }

        if ($bypass_reason !== '') {
            $context = $this->get_affiliate_link_save_marker_diagnostic_context($post_id, $post_type, $update, false);
            $context['transient_key'] = $transient_key;
            $context['bypass_reason'] = $bypass_reason;
            $this->log_affiliate_link_save_diagnostic('Affiliate link editor save marker skipped.', $context);
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            $context = $this->get_affiliate_link_save_marker_diagnostic_context($post_id, $post_type, $update, false);
            $context['transient_key'] = $transient_key;
            $context['bypass_reason'] = 'missing_user';
            $this->log_affiliate_link_save_diagnostic('Affiliate link editor save marker skipped.', $context);
            return;
        }

        $message = $update ? 1 : 6;
        $payload = array(
            'post_id' => $post_id,
            'created_at' => time(),
            'update' => $update ? 1 : 0,
            'message' => $message,
            'needs_recovery' => 1,
            'wrong_landing_expected' => 1,
            'handled' => 0,
            'classic_editor' => $this->affiliate_link_update_requested_classic_editor() ? 1 : 0,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'referer' => wp_get_referer(),
            'nonce' => wp_generate_password(12, false),
        );
        set_transient($transient_key, $payload, self::AFFILIATE_LINK_SAVE_TRANSIENT_TTL);

        $context = $this->get_affiliate_link_save_marker_diagnostic_context($post_id, $post_type, $update, true);
        $context['transient_key'] = $transient_key;
        $context['ttl'] = self::AFFILIATE_LINK_SAVE_TRANSIENT_TTL;
        $context['message'] = $message;
        $this->log_affiliate_link_save_diagnostic('Affiliate link editor save marker created.', $context);
    }

    /**
     * Ultima difesa: recupera solo l'atterraggio immediato e anomalo sulla lista articoli standard.
     */
    public function maybe_recover_affiliate_link_wrong_edit_redirect() {
        global $pagenow;

        $user_id = get_current_user_id();
        $transient_key = $user_id ? $this->get_affiliate_link_save_transient_key($user_id) : '';
        $data = $transient_key ? get_transient($transient_key) : false;
        $created_at = is_array($data) && !empty($data['created_at']) ? absint($data['created_at']) : 0;
        $age = $created_at ? max(0, time() - $created_at) : null;
        $referer = wp_get_referer();
        $method = '';
        $post_id = 0;
        $message = 1;
        $classic_editor = false;
        $bypass_reason = $this->get_affiliate_link_admin_edit_base_recovery_bypass_reason();

        $context = array(
            'guard' => 'admin_init',
            'pagenow' => isset($pagenow) ? $pagenow : '',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'current_post_type_get' => isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '',
            'request_action' => isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '',
            'request_action2' => isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '',
            'transient_key' => $transient_key,
            'transient_present' => is_array($data),
            'transient_age' => $age,
            'post_id' => 0,
            'post_type' => '',
            'recovery_method' => '',
            'referer' => $referer,
            'final_redirect' => '',
            'activated' => false,
        );

        if ($bypass_reason === '') {
            $transient_result = $this->resolve_affiliate_link_recovery_from_transient($data, $age);
            if (!empty($transient_result['post_id'])) {
                $method = 'transient_explicit_recovery';
                $post_id = absint($transient_result['post_id']);
                $message = absint($transient_result['message'] ?? 1) ?: 1;
                $classic_editor = !empty($transient_result['classic_editor']);
            } else {
                if ($transient_key && is_array($data) && in_array($transient_result['bypass_reason'] ?? '', array('stale_transient', 'invalid_transient_post', 'handled_transient'), true)) {
                    delete_transient($transient_key);
                    $context['transient_deleted'] = true;
                }
                $bypass_reason = $transient_result['bypass_reason'] ?? 'no_explicit_recovery_signal';
            }
        }

        $post_type = $post_id ? get_post_type($post_id) : '';
        $redirect = ($post_id && $post_type === 'affiliate_link') ? $this->build_affiliate_link_edit_redirect_from_message($post_id, $message, $classic_editor) : '';
        $context['post_id'] = $post_id;
        $context['post_type'] = $post_type;
        $context['recovery_method'] = $method;
        $context['final_redirect'] = $redirect;

        if ($bypass_reason !== '' || !$redirect) {
            if ($bypass_reason === '' && !$redirect) {
                $bypass_reason = 'invalid_recovered_post';
            }
            $context['bypass_reason'] = $bypass_reason;
            $this->log_affiliate_link_save_diagnostic('Affiliate link admin_init fallback not activated.', $context);
            return;
        }

        if ($transient_key) {
            delete_transient($transient_key);
            $context['transient_deleted'] = true;
        }
        $context['activated'] = true;
        $this->log_affiliate_link_save_diagnostic('Affiliate link admin_init fallback activated.', $context);
        wp_safe_redirect($redirect);
        exit;
    }

    private function get_affiliate_link_admin_edit_base_recovery_bypass_reason() {
        if (!is_admin()) {
            return 'not_admin';
        }
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return 'ajax';
        }
        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return 'not_edit_php';
        }
        $current_post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($current_post_type !== '') {
            return $current_post_type === 'affiliate_link' ? 'affiliate_link_list' : 'explicit_post_type_list';
        }
        if (isset($_GET['post_status']) && sanitize_key(wp_unslash($_GET['post_status'])) === 'trash') {
            return 'trash_list';
        }
        $request_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $request_action2 = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        if ($this->is_affiliate_link_blocked_admin_action($request_action) || $this->is_affiliate_link_blocked_admin_action($request_action2)) {
            return 'blocked_action';
        }
        return '';
    }

    private function resolve_affiliate_link_recovery_from_transient($data, $age) {
        if (!is_array($data) || empty($data['post_id'])) {
            return array('bypass_reason' => 'missing_transient');
        }
        if ($age === null || $age >= self::AFFILIATE_LINK_SAVE_FALLBACK_MAX_AGE) {
            return array('bypass_reason' => 'stale_transient');
        }
        if (!empty($data['handled'])) {
            return array('bypass_reason' => 'handled_transient');
        }
        if (empty($data['needs_recovery'])) {
            return array('bypass_reason' => 'missing_explicit_recovery_signal');
        }
        global $pagenow;
        $current_post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($pagenow !== 'edit.php' || $current_post_type !== '') {
            return array('bypass_reason' => 'not_immediate_plain_edit_landing');
        }
        $post_id = absint($data['post_id']);
        if (!$post_id || get_post_type($post_id) !== 'affiliate_link') {
            return array('bypass_reason' => 'invalid_transient_post');
        }
        return array(
            'post_id' => $post_id,
            'message' => !empty($data['message']) ? absint($data['message']) : 1,
            'classic_editor' => !empty($data['classic_editor']),
        );
    }

    private function resolve_affiliate_link_recovery_from_post_php_referer($referer) {
        $args = $this->parse_affiliate_link_referer_query($referer, 'post.php');
        if (empty($args)) {
            return array('bypass_reason' => 'referer_not_post_php');
        }
        $post_id = isset($args['post']) ? absint($args['post']) : 0;
        $action = isset($args['action']) ? sanitize_key($args['action']) : '';
        if (!$post_id || $action !== 'edit') {
            return array('bypass_reason' => 'referer_post_php_not_edit');
        }
        if (get_post_type($post_id) !== 'affiliate_link') {
            return array('bypass_reason' => 'referer_post_php_not_affiliate_link');
        }
        if (!$this->affiliate_link_post_was_modified_recently($post_id)) {
            return array('bypass_reason' => 'referer_post_php_not_recent_save');
        }
        return array(
            'post_id' => $post_id,
            'classic_editor' => array_key_exists('classic-editor', $args),
        );
    }

    private function resolve_affiliate_link_recovery_from_post_new_referer($referer) {
        $args = $this->parse_affiliate_link_referer_query($referer, 'post-new.php');
        if (empty($args)) {
            return array('bypass_reason' => 'referer_not_post_new_php');
        }
        $post_type = isset($args['post_type']) ? sanitize_key($args['post_type']) : '';
        if ($post_type !== 'affiliate_link') {
            return array('bypass_reason' => 'referer_post_new_not_affiliate_link');
        }
        $post_id = $this->get_latest_recent_affiliate_link_for_current_user();
        if (!$post_id) {
            return array('bypass_reason' => 'latest_affiliate_link_not_found');
        }
        return array(
            'post_id' => $post_id,
            'classic_editor' => array_key_exists('classic-editor', $args),
        );
    }

    private function parse_affiliate_link_referer_query($referer, $expected_file) {
        $referer = (string) $referer;
        if ($referer === '') {
            return array();
        }
        $path = wp_parse_url($referer, PHP_URL_PATH);
        if (!$path || basename($path) !== $expected_file) {
            return array();
        }
        $query = wp_parse_url($referer, PHP_URL_QUERY);
        $args = array();
        if ($query) {
            wp_parse_str($query, $args);
        }
        return is_array($args) ? $args : array();
    }

    private function affiliate_link_post_was_modified_recently($post_id) {
        $modified_time = get_post_modified_time('U', false, absint($post_id));
        if (!$modified_time) {
            return false;
        }
        return (current_time('timestamp') - absint($modified_time)) < self::AFFILIATE_LINK_SAVE_FALLBACK_MAX_AGE;
    }

    private function get_latest_recent_affiliate_link_for_current_user() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return 0;
        }
        $posts = get_posts(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'any',
            'author' => $user_id,
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'column' => 'post_date',
                    'after' => date('Y-m-d H:i:s', current_time('timestamp') - 60),
                    'inclusive' => true,
                ),
            ),
            'suppress_filters' => true,
            'no_found_rows' => true,
        ));
        return !empty($posts[0]) ? absint($posts[0]) : 0;
    }

    private function get_affiliate_link_save_marker_diagnostic_context($post_id, $post_type, $update, $transient_created) {
        return array(
            'post_id' => absint($post_id),
            'post_type' => (string) $post_type,
            'update' => (bool) $update,
            'post_action' => isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '',
            'post_post_type' => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '',
            'post_post_id' => isset($_POST['post_ID']) ? absint(wp_unslash($_POST['post_ID'])) : 0,
            'link_nonce_present' => isset($_POST['affiliate_link_nonce']),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'referer' => wp_get_referer(),
            'transient_created' => (bool) $transient_created,
        );
    }


    private function mark_affiliate_link_save_transient_handled($post_id) {
        $key = $this->get_affiliate_link_save_transient_key();
        $data = $key ? get_transient($key) : false;
        if (!is_array($data)) {
            return false;
        }
        if (!empty($post_id) && absint($data['post_id'] ?? 0) !== absint($post_id)) {
            return false;
        }
        $data['handled'] = 1;
        $data['needs_recovery'] = 0;
        return set_transient($key, $data, self::AFFILIATE_LINK_SAVE_TRANSIENT_TTL);
    }

    private function get_affiliate_link_save_transient_key($user_id = 0) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        return self::AFFILIATE_LINK_SAVE_TRANSIENT_PREFIX . $user_id;
    }

    /**
     * Mantiene i salvataggi normali dei Link Affiliati nella schermata di modifica del CPT.
     */
    public function normalize_affiliate_link_update_redirect($location, $post_id) {
        $original_location = $location;
        $received_filter_post_id = absint($post_id);
        $post_id = $this->resolve_affiliate_link_redirect_post_id($post_id);
        $post_type = $this->resolve_affiliate_link_redirect_post_type($post_id);
        $diagnostic_context = $this->get_affiliate_link_redirect_diagnostic_context($original_location, $post_id, $post_type);
        $diagnostic_context['received_filter_post_id'] = $received_filter_post_id;
        $final_location = $location;
        $bypass_reason = $this->get_affiliate_link_redirect_bypass_reason($post_id, $post_type);

        $transient_deleted = false;
        if ($bypass_reason === '') {
            $final_location = $this->build_affiliate_link_edit_redirect($post_id, $location);
            $this->mark_affiliate_link_save_transient_handled($post_id);
            $transient_deleted = delete_transient($this->get_affiliate_link_save_transient_key());
        }

        $diagnostic_context['final_location'] = $final_location;
        $diagnostic_context['forced_redirect'] = ($bypass_reason === '' && $final_location !== $location);
        $diagnostic_context['transient_deleted'] = $transient_deleted;
        $diagnostic_context['bypass_reason'] = $bypass_reason;
        $this->log_affiliate_link_save_diagnostic('Affiliate link redirect_post_location evaluated.', $diagnostic_context);

        return $final_location;
    }

    /**
     * Ultima difesa: corregge solo redirect admin strettamente riconducibili a editpost affiliate_link verso edit.php.
     */
    public function guard_affiliate_link_editpost_redirect($location, $status = 302) {
        $post_id = $this->resolve_affiliate_link_redirect_post_id(0);
        $post_type = $this->resolve_affiliate_link_redirect_post_type($post_id);
        $diagnostic_context = $this->get_affiliate_link_redirect_diagnostic_context($location, $post_id, $post_type);
        $diagnostic_context['http_status'] = absint($status);
        $diagnostic_context['guard'] = 'wp_redirect';
        $final_location = $location;
        $bypass_reason = $this->get_affiliate_link_redirect_bypass_reason($post_id, $post_type);

        if ($bypass_reason === '') {
            $path = wp_parse_url($location, PHP_URL_PATH);
            $query = wp_parse_url($location, PHP_URL_QUERY);
            $query_args = array();
            if ($query) {
                wp_parse_str($query, $query_args);
            }
            $target_post_type = isset($query_args['post_type']) ? sanitize_key($query_args['post_type']) : '';
            $is_wrong_admin_edit = $path && basename($path) === 'edit.php' && $target_post_type === '';
            if ($is_wrong_admin_edit) {
                $final_location = $this->build_affiliate_link_edit_redirect($post_id, $location);
                $this->mark_affiliate_link_save_transient_handled($post_id);
                $diagnostic_context['transient_deleted'] = delete_transient($this->get_affiliate_link_save_transient_key());
            } else {
                $bypass_reason = 'not_plain_edit_php_redirect';
            }
        }

        $diagnostic_context['final_location'] = $final_location;
        $diagnostic_context['forced_redirect'] = ($final_location !== $location);
        $diagnostic_context['bypass_reason'] = $bypass_reason;
        $this->log_affiliate_link_save_diagnostic('Affiliate link wp_redirect guard evaluated.', $diagnostic_context);

        return $final_location;
    }

    private function resolve_affiliate_link_redirect_post_id($post_id) {
        $post_id = absint($post_id);
        if (!$post_id && isset($_POST['post_ID'])) {
            $post_id = absint(wp_unslash($_POST['post_ID']));
        }
        if (!$post_id && isset($_REQUEST['post'])) {
            $post_id = absint(wp_unslash($_REQUEST['post']));
        }
        return $post_id;
    }

    private function resolve_affiliate_link_redirect_post_type($post_id) {
        $post_type = $post_id ? get_post_type($post_id) : '';
        if (!$post_type && isset($_POST['post_type'])) {
            $post_type = sanitize_key(wp_unslash($_POST['post_type']));
        }
        if (!$post_type && isset($_REQUEST['post_type'])) {
            $post_type = sanitize_key(wp_unslash($_REQUEST['post_type']));
        }
        return (string) $post_type;
    }

    private function get_affiliate_link_redirect_bypass_reason($post_id, $post_type) {
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return 'ajax';
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return 'autosave';
        }
        if (!$post_id || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return 'empty_or_autosave_or_revision';
        }
        if ($post_type !== 'affiliate_link') {
            return 'not_affiliate_link';
        }
        $request_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $request_action2 = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        if ($this->is_affiliate_link_blocked_admin_action($request_action) || $this->is_affiliate_link_blocked_admin_action($request_action2)) {
            return 'blocked_action';
        }
        // 'alma_retry_affiliate_image': se markup vecchio (form annidato) dirotta
        // il form del post su questa azione, il redirect di fallback verso il
        // plain edit.php va comunque riscritto verso l'editor del link.
        if ($request_action !== 'editpost' && $request_action !== 'alma_retry_affiliate_image') {
            return 'not_editpost';
        }
        return '';
    }

    private function is_affiliate_link_blocked_admin_action($action) {
        return in_array((string) $action, array('trash', 'delete', 'delete_all', 'untrash', 'bulk_edit', 'inline-save', 'restore'), true);
    }

    private function affiliate_link_referer_points_to_editor($referer) {
        $referer = (string) $referer;
        if ($referer === '') {
            return false;
        }
        $path = wp_parse_url($referer, PHP_URL_PATH);
        if (!$path) {
            return false;
        }
        return in_array(basename($path), array('post.php', 'post-new.php'), true);
    }

    private function build_affiliate_link_edit_redirect_from_message($post_id, $message = 1, $classic_editor = false) {
        $args = array(
            'post' => absint($post_id),
            'action' => 'edit',
            'message' => absint($message) ?: 1,
        );
        if ($classic_editor) {
            $args['classic-editor'] = '';
        }
        $redirect = add_query_arg($args, admin_url('post.php'));
        if ($classic_editor) {
            $redirect = preg_replace('/([?&])classic-editor=(?=(&|$))/', '$1classic-editor', $redirect);
        }
        return $redirect;
    }

    private function build_affiliate_link_edit_redirect($post_id, $location = '') {
        return $this->build_affiliate_link_edit_redirect_from_message(
            $post_id,
            $this->resolve_affiliate_link_redirect_message($location),
            $this->affiliate_link_update_requested_classic_editor()
        );
    }

    private function resolve_affiliate_link_redirect_message($location) {
        $parts = wp_parse_url((string) $location);
        $query_args = array();
        if (!empty($parts['query'])) {
            wp_parse_str($parts['query'], $query_args);
        }
        $message = isset($query_args['message']) ? absint($query_args['message']) : 0;
        if ($message) {
            return $message;
        }
        $original_status = isset($_POST['original_post_status']) ? sanitize_key(wp_unslash($_POST['original_post_status'])) : '';
        $original_post_id = isset($_POST['original_post_ID']) ? absint(wp_unslash($_POST['original_post_ID'])) : 0;
        if ($original_status === 'auto-draft' || !$original_post_id) {
            return 6;
        }
        return 1;
    }

    private function get_affiliate_link_redirect_diagnostic_context($location, $post_id, $post_type) {
        return array(
            'original_location' => $location,
            'filter_post_id' => absint($post_id),
            'resolved_post_type' => $post_type,
            'get_post_type' => $post_id ? get_post_type($post_id) : '',
            'post_post_id' => isset($_POST['post_ID']) ? absint(wp_unslash($_POST['post_ID'])) : 0,
            'post_post_type' => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '',
            'post_action' => isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '',
            'request_post' => isset($_REQUEST['post']) ? absint(wp_unslash($_REQUEST['post'])) : 0,
            'request_post_type' => isset($_REQUEST['post_type']) ? sanitize_key(wp_unslash($_REQUEST['post_type'])) : '',
            'request_action' => isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '',
            'request_classic_editor' => isset($_REQUEST['classic-editor']) ? sanitize_text_field(wp_unslash($_REQUEST['classic-editor'])) : '',
            'referer' => wp_get_referer(),
        );
    }

    private function affiliate_link_update_requested_classic_editor() {
        if (isset($_REQUEST['classic-editor'])) {
            return true;
        }

        foreach (array('_wp_http_referer', 'HTTP_REFERER') as $key) {
            $value = $key === 'HTTP_REFERER' ? ($_SERVER[$key] ?? '') : ($_REQUEST[$key] ?? '');
            if ($value === '') {
                continue;
            }
            $value = wp_unslash($value);
            $query = wp_parse_url($value, PHP_URL_QUERY);
            if ($query === false || $query === null) {
                continue;
            }
            $query_args = array();
            wp_parse_str($query, $query_args);
            if (array_key_exists('classic-editor', $query_args) || strpos($query, 'classic-editor') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Traccia visibile in admin degli eventi di salvataggio dei Link
     * Affiliati (ring buffer in option, sempre attivo): una riproduzione
     * del problema basta per leggere l'intera catena nella card
     * "Diagnostica salvataggio" della pagina Verifica link.
     */
    public static function record_link_save_event($message, $context = array()) {
        $events = get_option('alma_link_save_diag_events', array());
        if (!is_array($events)) { $events = array(); }
        $compact = array();
        foreach ((array) $context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $compact[$key] = is_string($value) ? mb_substr($value, 0, 200) : $value;
            }
        }
        array_unshift($events, array('time' => current_time('mysql'), 'message' => (string) $message, 'context' => $compact));
        update_option('alma_link_save_diag_events', array_slice($events, 0, 40), false);
    }

    /**
     * Fotografia di fine richiesta per gli editpost dei Link Affiliati:
     * registra dove il browser è stato mandato davvero (header Location),
     * lo stato del post e quanti handler di salvataggio sono girati —
     * l'evidenza che serve quando un salvataggio "sparisce".
     */
    public function record_affiliate_link_editpost_shutdown() {
        if (!is_admin() || (isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '') !== 'editpost') {
            return;
        }
        $post_id = isset($_POST['post_ID']) ? absint(wp_unslash($_POST['post_ID'])) : 0;
        if (!$post_id || get_post_type($post_id) !== 'affiliate_link') {
            return;
        }
        $location = '';
        if (function_exists('headers_list')) {
            foreach ((array) headers_list() as $header) {
                if (stripos($header, 'Location:') === 0) { $location = trim(substr($header, 9)); }
            }
        }
        self::record_link_save_event('Fine richiesta editpost (shutdown).', array(
            'post_id' => $post_id,
            'post_status' => (string) get_post_status($post_id),
            'affiliate_url_salvato' => trim((string) get_post_meta($post_id, '_affiliate_url', true)) !== '' ? 'sì' : 'NO',
            'tipologie_salvate' => count((array) wp_get_post_terms($post_id, 'link_type', array('fields' => 'ids'))),
            'save_post_affiliate_link_eseguiti' => (int) did_action('save_post_affiliate_link'),
            'header_location' => $location !== '' ? $location : '(nessuno)',
            'nonce_link_presente' => isset($_POST['affiliate_link_nonce']) ? 'sì' : 'NO',
            'campo_affiliate_url_in_post' => isset($_POST['affiliate_url']) ? 'sì' : 'NO',
        ));
    }

    private function log_affiliate_link_save_diagnostic($message, $context = array()) {
        // Cattura sempre nel buffer visibile in admin (indipendente da WP_DEBUG).
        self::record_link_save_event($message, $context);
        if (!$this->is_affiliate_link_save_diagnostic_enabled()) {
            return;
        }
        if (class_exists('ALMA_Logger')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ALMA_Logger::debug($message, $context);
            } else {
                ALMA_Logger::info($message, $context);
            }
            return;
        }
        error_log('[ALMA] [DEBUG] ' . $message . ' | context=' . wp_json_encode($context));
    }

    private function is_affiliate_link_save_diagnostic_enabled() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        return (bool) get_option('alma_affiliate_link_save_redirect_diagnostics', false);
    }

    private function get_affiliate_link_export_counts() {
        $counts = wp_count_posts('affiliate_link');
        $total = 0;
        if ($counts) {
            foreach ((array) $counts as $count) {
                $total += (int) $count;
            }
        }
        return array(
            'total' => $total,
            'publish' => $counts && isset($counts->publish) ? (int) $counts->publish : 0,
        );
    }

    public function export_affiliate_links_csv() {
        if (!is_admin() || !current_user_can('manage_options')) {
            wp_die(__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }
        check_admin_referer('alma_export_affiliate_links_csv');

        $filename = 'sothra_affiliate_links_export_' . current_time('Y-m-d_H-i-s') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        if (!$output) {
            wp_die(__('Impossibile aprire lo stream CSV.', 'affiliate-link-manager-ai'));
        }

        $headers = array(
            'affiliate_link_id','post_title','post_slug','post_status','affiliate_url','link_title','link_target','link_rel','click_count','link_types','provider','source_name','source_id','external_id','ai_context','post_content','post_excerpt','featured_image_url','featured_image_id','geo_enabled','geo_scope','geo_primary_name','geo_primary_type','geo_primary_country','geo_primary_country_code','geo_primary_region','geo_primary_city','geo_primary_area','geo_primary_poi','geo_geocoding_status','geo_lat','geo_lng','geo_place_id','created_at','updated_at'
        );
        fputcsv($output, $this->escape_csv_row($headers));

        $paged = 1;
        $per_page = 200;
        do {
            $query = new WP_Query(array(
                'post_type' => 'affiliate_link',
                'post_status' => 'any',
                'posts_per_page' => $per_page,
                'paged' => $paged,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
            ));

            foreach ($query->posts as $post) {
                $post_id = (int) $post->ID;
                $featured_image_id = (int) get_post_thumbnail_id($post_id);
                $terms = get_the_terms($post_id, 'link_type');
                $term_names = array();
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $term_names[] = $term->name;
                    }
                }

                fputcsv($output, $this->escape_csv_row(array(
                    $post_id,
                    $post->post_title,
                    $post->post_name,
                    $post->post_status,
                    get_post_meta($post_id, '_affiliate_url', true),
                    get_post_meta($post_id, '_link_title', true),
                    get_post_meta($post_id, '_link_target', true),
                    get_post_meta($post_id, '_link_rel', true),
                    (int) get_post_meta($post_id, '_click_count', true),
                    implode('|', $term_names),
                    $this->get_first_post_meta_value($post_id, array('_alma_provider', '_alma_source_provider', '_alma_provider_preset')),
                    $this->get_first_post_meta_value($post_id, array('_alma_source_name', '_alma_source_provider_label')),
                    get_post_meta($post_id, '_alma_source_id', true),
                    get_post_meta($post_id, '_alma_external_id', true),
                    $this->clean_affiliate_export_text(get_post_meta($post_id, '_alma_ai_context', true)),
                    $this->clean_affiliate_export_text($post->post_content),
                    $this->clean_affiliate_export_text($post->post_excerpt),
                    $featured_image_id ? wp_get_attachment_url($featured_image_id) : '',
                    $featured_image_id,
                    get_post_meta($post_id, '_alma_geo_enabled', true),
                    get_post_meta($post_id, '_alma_geo_scope', true),
                    get_post_meta($post_id, '_alma_geo_primary_name', true),
                    get_post_meta($post_id, '_alma_geo_primary_type', true),
                    get_post_meta($post_id, '_alma_geo_primary_country', true),
                    get_post_meta($post_id, '_alma_geo_primary_country_code', true),
                    get_post_meta($post_id, '_alma_geo_primary_region', true),
                    get_post_meta($post_id, '_alma_geo_primary_city', true),
                    get_post_meta($post_id, '_alma_geo_primary_area', true),
                    get_post_meta($post_id, '_alma_geo_primary_poi', true),
                    get_post_meta($post_id, '_alma_geo_geocoding_status', true),
                    get_post_meta($post_id, '_alma_geo_primary_lat', true),
                    get_post_meta($post_id, '_alma_geo_primary_lng', true),
                    get_post_meta($post_id, '_alma_geo_primary_place_id', true),
                    $post->post_date,
                    $post->post_modified,
                )));
            }

            $count = count($query->posts);
            wp_reset_postdata();
            $paged++;
        } while ($count === $per_page);

        fclose($output);
        exit;
    }

    private function get_first_post_meta_value($post_id, $keys) {
        foreach ($keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function escape_csv_row($row) {
        return array_map(array($this, 'escape_csv_cell'), (array) $row);
    }

    private function escape_csv_cell($value) {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_scalar($value) || $value === null) {
            $value = (string) $value;
        } else {
            $value = wp_json_encode($value);
            $value = is_string($value) ? $value : '';
        }

        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], array('=', '+', '-', '@'), true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function clean_affiliate_export_text($text) {
        $text = (string) $text;
        if (function_exists('strip_shortcodes')) {
            $text = strip_shortcodes($text);
        }
        $text = wp_strip_all_tags($text, true);
        $text = html_entity_decode($text, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);
        return trim(preg_replace('/ {2,}/', ' ', $text));
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
            if ($delete_id && get_post_type($delete_id) === 'affiliate_link') {
                wp_delete_post($delete_id, true);
                echo '<div class="notice notice-success"><p>' . __('Link eliminato.', 'affiliate-link-manager-ai') . '</p></div>';
            } elseif ($delete_id) {
                echo '<div class="notice notice-error"><p>' . esc_html__('L\'ID indicato non corrisponde a un Link Affiliato: nessun contenuto è stato eliminato.', 'affiliate-link-manager-ai') . '</p></div>';
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

        // Aggiorna cache analisi contenuti
        if (isset($_POST['alma_refresh_content_cache']) &&
            isset($_POST['alma_settings_nonce']) &&
            wp_verify_nonce($_POST['alma_settings_nonce'], 'alma_save_settings')) {

            $analysis_types = array_map('sanitize_text_field', $_POST['alma_content_analysis_post_types'] ?? array());
            update_option('alma_content_analysis_post_types', $analysis_types);
            $cache = ALMA_Content_Analysis_AI::build_cache($analysis_types);
            echo "<div class=\"notice notice-success\"><p>" . sprintf(__('Analizzati %d contenuti e cache aggiornata.', 'affiliate-link-manager-ai'), count($cache)) . "</p></div>";
        }

        // Salva impostazioni se form inviato
        if (isset($_POST['alma_save_settings']) &&
            isset($_POST['alma_settings_nonce']) &&
            wp_verify_nonce($_POST['alma_settings_nonce'], 'alma_save_settings')) {

            // Impostazioni generali
            update_option('alma_track_logged_out', sanitize_text_field($_POST['track_logged_out'] ?? 'yes'));
            update_option('alma_enable_ai', sanitize_text_field($_POST['enable_ai'] ?? 'yes'));
            update_option('alma_ai_auto_publish', ($_POST['alma_ai_auto_publish'] ?? 'no') === 'yes' ? 'yes' : 'no');
            update_option('alma_article_map_enabled', empty($_POST['alma_article_map_enabled']) ? '0' : '1', false);

            $selected_types = array_map('sanitize_text_field', $_POST['alma_link_post_types'] ?? array());
            update_option('alma_link_post_types', $selected_types);
            // Content analysis settings
            $analysis_types = array_map('sanitize_text_field', $_POST['alma_content_analysis_post_types'] ?? array());
            update_option('alma_content_analysis_post_types', $analysis_types);

            // OpenAI API key: vive SOLO in wp-config.php (ALMA_OPENAI_API_KEY).
            // Nessun salvataggio dal form: così il salvataggio delle
            // impostazioni non può più toccarla né cancellarla.
            $selected_model = sanitize_text_field($_POST['openai_model'] ?? 'gpt-5.4-mini');
            $custom_model = sanitize_text_field($_POST['openai_model_custom'] ?? '');
            update_option('alma_openai_model', $custom_model !== '' ? $custom_model : $selected_model);
            update_option('alma_openai_max_output_tokens', absint($_POST['openai_max_output_tokens'] ?? 600));
            update_option('alma_openai_timeout', absint($_POST['openai_timeout'] ?? 30));
            update_option('alma_openai_temperature', floatval($_POST['openai_temperature'] ?? 0.7));

            // Storage OpenAI (Vector Store) usato dall'agente via file_search.
            $vector_store_id = sanitize_text_field(wp_unslash($_POST['openai_vector_store_id'] ?? ''));
            update_option('alma_openai_vector_store_id', preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $vector_store_id) ? $vector_store_id : '', false);

            // Prompt Widget AI rewrite settings
            update_option('alma_widget_ai_rewrite_prompt', sanitize_textarea_field(wp_unslash($_POST['alma_widget_ai_rewrite_prompt'] ?? '')));
            $widget_tokens = absint($_POST['alma_widget_ai_rewrite_max_output_tokens'] ?? ALMA_Affiliate_Widget_AI_Rewriter::DEFAULT_MAX_OUTPUT_TOKENS);
            update_option('alma_widget_ai_rewrite_max_output_tokens', $widget_tokens > 0 ? $widget_tokens : ALMA_Affiliate_Widget_AI_Rewriter::DEFAULT_MAX_OUTPUT_TOKENS);
            $widget_timeout = absint($_POST['alma_widget_ai_rewrite_timeout'] ?? ALMA_Affiliate_Widget_AI_Rewriter::DEFAULT_TIMEOUT);
            update_option('alma_widget_ai_rewrite_timeout', $widget_timeout > 0 ? $widget_timeout : ALMA_Affiliate_Widget_AI_Rewriter::DEFAULT_TIMEOUT);
            
            echo '<div class="notice notice-success"><p>' . __('Impostazioni salvate!', 'affiliate-link-manager-ai') . '</p></div>';
        }
        
        // La chiave OpenAI vive in wp-config.php: se la costante è definita,
        // l'eventuale copia storica nel database viene eliminata per sicurezza.
        $openai_constant_defined = defined('ALMA_OPENAI_API_KEY') && ALMA_OPENAI_API_KEY !== '';
        remove_filter('pre_option_alma_openai_api_key', array(__CLASS__, 'filter_openai_api_key_constant'));
        $openai_db_key = trim((string) get_option('alma_openai_api_key', ''));
        add_filter('pre_option_alma_openai_api_key', array(__CLASS__, 'filter_openai_api_key_constant'));
        if ($openai_constant_defined && $openai_db_key !== '') {
            delete_option('alma_openai_api_key');
            $openai_db_key = '';
            echo '<div class="notice notice-success"><p>' . __('Chiave OpenAI rimossa dal database: ora viene usata solo la costante ALMA_OPENAI_API_KEY di wp-config.php.', 'affiliate-link-manager-ai') . '</p></div>';
        }

        // Recupera impostazioni attuali
        $track_logged_out = get_option('alma_track_logged_out', 'yes');
        $enable_ai = get_option('alma_enable_ai', 'yes');
        $openai_api_key = get_option('alma_openai_api_key', '');
        $openai_model = get_option('alma_openai_model', 'gpt-5.4-mini');
        $openai_temperature = get_option('alma_openai_temperature', 0.7);
        $widget_rewrite_prompt = get_option('alma_widget_ai_rewrite_prompt', '');
        $widget_rewrite_max_output_tokens = ALMA_Affiliate_Widget_AI_Rewriter::get_max_output_tokens();
        $widget_rewrite_timeout = ALMA_Affiliate_Widget_AI_Rewriter::get_timeout();
        $widget_rewrite_logs = $this->get_widget_ai_rewrite_logs(10);
        $allowed_post_types = get_option('alma_link_post_types', array('post', 'page'));

        ?>
        <div class="wrap">
            <h1><?php _e('Impostazioni - Affiliate Link Manager AI', 'affiliate-link-manager-ai'); ?></h1>
            <p style="font-size:14px;color:#666;">Versione <?php echo esc_html(ALMA_VERSION); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('alma_save_settings', 'alma_settings_nonce'); ?>
                <?php wp_nonce_field('alma_delete_links', 'alma_delete_links_nonce'); ?>
                
                <!-- Tabs Navigation -->
                <h2 class="nav-tab-wrapper alma-settings-tabs">
                    <a href="#general" class="nav-tab nav-tab-active">Generale</a>
                    <a href="#tracking" class="nav-tab">Tracking</a>
                    <a href="#openai" class="nav-tab">OpenAI API</a>
                    <a href="#content-analysis" class="nav-tab">Content Analysis AI</a>
                    <a href="#prompt-widget" class="nav-tab">Prompt Widget</a>
                    <a href="#export-affiliate-links" class="nav-tab">Export Link Affiliati</a>
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
                        <tr>
                            <th scope="row">
                                <label for="alma_ai_auto_publish"><?php _e('Pubblicazione diretta delle bozze AI', 'affiliate-link-manager-ai'); ?></label>
                            </th>
                            <td>
                                <select name="alma_ai_auto_publish" id="alma_ai_auto_publish">
                                    <option value="no" <?php selected(get_option('alma_ai_auto_publish', 'no'), 'no'); ?>><?php _e('No — crea bozze da revisionare (consigliato)', 'affiliate-link-manager-ai'); ?></option>
                                    <option value="yes" <?php selected(get_option('alma_ai_auto_publish', 'no'), 'yes'); ?>><?php _e('Sì — pubblica subito gli articoli generati', 'affiliate-link-manager-ai'); ?></option>
                                </select>
                                <p class="description"><?php _e('⚠️ Con "Sì" TUTTI gli articoli generati dall\'AI (workspace, runner programmato, agente) vanno online immediatamente senza revisione umana, con immagine in evidenza e meta SEO già applicati. Attivalo solo quando la qualità delle bozze ti soddisfa costantemente.', 'affiliate-link-manager-ai'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Mappa località negli articoli', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <label><input type="checkbox" name="alma_article_map_enabled" value="1" <?php checked(get_option('alma_article_map_enabled', '1'), '1'); ?>> <?php _e('Mostra a fine articolo la mappa interattiva delle località citate (solo articoli con località geocodificate)', 'affiliate-link-manager-ai'); ?></label>
                                <p class="description"><?php _e('I marker aprono la scheda Google Maps del luogo in una nuova scheda. Shortcode per posizionarla a mano: [alma_mappa_articolo]; esclusione per singolo articolo dalla metabox "📍 Mappa località".', 'affiliate-link-manager-ai'); ?></p>
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
                
                <!-- OpenAI API Settings -->
                <div id="openai" class="alma-settings-section" style="display:none;">
                    <h2>🧠 OpenAI API Configuration</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <?php if ($openai_constant_defined) : ?>
                                    <p><span class="alma-badge is-success">✅ Configurata in wp-config.php</span> <code>ALMA_OPENAI_API_KEY</code></p>
                                    <p class="description"><?php _e('La chiave vive solo in wp-config.php, mai nel database: non può essere letta dall\'admin né persa salvando le impostazioni.', 'affiliate-link-manager-ai'); ?></p>
                                <?php else : ?>
                                    <p><span class="alma-badge is-warning">Non configurata in wp-config.php</span></p>
                                    <p><?php _e('Aggiungi a', 'affiliate-link-manager-ai'); ?> <code>wp-config.php</code> (<?php _e('sopra la riga', 'affiliate-link-manager-ai'); ?> <code>/* That's all, stop editing! */</code>):</p>
                                    <p><code>define( 'ALMA_OPENAI_API_KEY', 'sk-...' );</code></p>
                                    <?php if ($openai_db_key !== '') : ?>
                                        <p class="description">⚠️ <?php _e('È presente una chiave salvata nel database (funziona ancora, per retrocompatibilità): appena definisci la costante, la copia nel database verrà eliminata automaticamente.', 'affiliate-link-manager-ai'); ?></p>
                                    <?php else : ?>
                                        <p class="description"><?php printf(__('Ottieni la tua API key da %s.', 'affiliate-link-manager-ai'), '<a href="https://platform.openai.com/" target="_blank" rel="noopener">OpenAI Platform</a>'); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="openai_model">Modello</label>
                            </th>
                            <td>
                                <select name="openai_model" id="openai_model"><option value="gpt-5.4-mini" <?php selected($openai_model, 'gpt-5.4-mini'); ?>>gpt-5.4-mini (consigliato)</option><option value="gpt-5.4" <?php selected($openai_model, 'gpt-5.4'); ?>>gpt-5.4</option><option value="gpt-5.5" <?php selected($openai_model, 'gpt-5.5'); ?>>gpt-5.5</option></select><p><input type="text" name="openai_model_custom" placeholder="Modello custom (opzionale)" /></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="openai_temperature">Temperature</label>
                            </th>
                            <td>
                                <input type="number" 
                                       name="openai_temperature" 
                                       id="openai_temperature" 
                                       value="<?php echo esc_attr($openai_temperature); ?>" 
                                       min="0" 
                                       max="1" 
                                       step="0.1" 
                                       style="width:80px;" />
                                <p class="description">0 = Deterministico, 1 = Creativo (default: 0.7)</p>
                            </td>
                        </tr><tr><th scope="row"><label for="openai_max_output_tokens">Max output tokens</label></th><td><input type="number" name="openai_max_output_tokens" id="openai_max_output_tokens" value="<?php echo esc_attr(get_option('alma_openai_max_output_tokens', 600)); ?>" min="1" /></td></tr><tr><th scope="row"><label for="openai_timeout">Timeout richiesta</label></th><td><input type="number" name="openai_timeout" id="openai_timeout" value="<?php echo esc_attr(get_option('alma_openai_timeout', 30)); ?>" min="5" /></td></tr><tr><th scope="row">Stato configurazione</th><td><?php echo empty($openai_api_key) ? 'Non configurato' : 'Configurato'; ?></td></tr>
                        <tr>
                            <th scope="row">Test Connessione</th>
                            <td>
                                <button type="button" id="test-openai-connection" class="button">
                                    🧪 Testa Connessione
                                </button>
                                <div id="openai-test-result" style="margin-top:10px;"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="openai_vector_store_id">Storage OpenAI (Vector Store ID)</label></th>
                            <td>
                                <input type="text" name="openai_vector_store_id" id="openai_vector_store_id" class="regular-text" placeholder="vs_..." value="<?php echo esc_attr(get_option('alma_openai_vector_store_id', '')); ?>" />
                                <button type="button" id="alma-verify-openai-storage" class="button">🔍 Verifica accesso</button>
                                <div id="alma-openai-storage-result" style="margin-top:10px;"></div>
                                <p class="description"><?php _e('ID del Vector Store su OpenAI Platform (Storage → Vector stores): l\'Agente di ideazione lo consulta con lo strumento file_search per usare anche i documenti caricati lì (guide, brief, materiali dell\'editore). Lascia vuoto per disattivare.', 'affiliate-link-manager-ai'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Content Analysis AI -->
                <div id="content-analysis" class="alma-settings-section" style="display:none;">
                    <h2><?php _e('Content Analysis AI', 'affiliate-link-manager-ai'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Tipi di contenuto da analizzare', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php
                                $post_types = get_post_types(array('show_ui' => true), 'objects');
                                $selected_analysis = get_option('alma_content_analysis_post_types', array());
                                foreach ($post_types as $pt) {
                                    $checked = in_array($pt->name, $selected_analysis, true) ? 'checked' : '';
                                    echo '<label><input type="checkbox" name="alma_content_analysis_post_types[]" value="' . esc_attr($pt->name) . '" ' . $checked . '> ' . esc_html($pt->labels->singular_name) . '</label><br />';
                                }
                                ?>
                                <p class="description"><?php _e('Seleziona i contenuti da analizzare e memorizzare in cache.', 'affiliate-link-manager-ai'); ?></p>
                                <?php submit_button(__('Analizza e aggiorna cache', 'affiliate-link-manager-ai'), 'secondary', 'alma_refresh_content_cache', false); ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Prompt Widget Settings -->
                <div id="prompt-widget" class="alma-settings-section" style="display:none;">
                    <h2><?php _e('Prompt Widget', 'affiliate-link-manager-ai'); ?></h2>
                    <p><?php _e('Configura il prompt usato per riscrivere obbligatoriamente titolo e descrizione dei Link Affiliati selezionati prima della creazione o salvataggio di un Widget Link.', 'affiliate-link-manager-ai'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="alma_widget_ai_rewrite_prompt"><?php _e('Prompt Widget', 'affiliate-link-manager-ai'); ?></label></th>
                            <td>
                                <textarea name="alma_widget_ai_rewrite_prompt" id="alma_widget_ai_rewrite_prompt" rows="8" class="large-text"><?php echo esc_textarea($widget_rewrite_prompt); ?></textarea>
                                <p class="description"><?php _e('Se lasciato vuoto viene usato il prompt default qui sotto. Il prompt viene combinato con Contesto AI del link e istruzioni della Source.', 'affiliate-link-manager-ai'); ?></p>
                                <p><strong><?php _e('Prompt default:', 'affiliate-link-manager-ai'); ?></strong></p>
                                <pre style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:900px;"><?php echo esc_html(ALMA_Affiliate_Widget_AI_Rewriter::default_prompt()); ?></pre>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_ai_rewrite_max_output_tokens"><?php _e('Max output tokens Widget', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input type="number" min="1" name="alma_widget_ai_rewrite_max_output_tokens" id="alma_widget_ai_rewrite_max_output_tokens" value="<?php echo esc_attr($widget_rewrite_max_output_tokens); ?>" class="small-text"><p class="description"><?php _e('Default consigliato: 3000.', 'affiliate-link-manager-ai'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="alma_widget_ai_rewrite_timeout"><?php _e('Timeout richiesta Widget', 'affiliate-link-manager-ai'); ?></label></th>
                            <td><input type="number" min="1" name="alma_widget_ai_rewrite_timeout" id="alma_widget_ai_rewrite_timeout" value="<?php echo esc_attr($widget_rewrite_timeout); ?>" class="small-text"> <?php esc_html_e('secondi', 'affiliate-link-manager-ai'); ?><p class="description"><?php _e('Default consigliato: 60 secondi.', 'affiliate-link-manager-ai'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Stato OpenAI', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php if (ALMA_Affiliate_Widget_AI_Rewriter::is_openai_configured()) : ?>
                                    <span style="color:#008a20;font-weight:600;"><?php _e('Configurato', 'affiliate-link-manager-ai'); ?></span>
                                <?php else : ?>
                                    <span style="color:#b32d2e;font-weight:600;"><?php _e('Non configurato', 'affiliate-link-manager-ai'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Ultimi log riscrittura widget', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php if (empty($widget_rewrite_logs)) : ?>
                                    <p class="description"><?php _e('Nessun log disponibile per widget_link_rewrite.', 'affiliate-link-manager-ai'); ?></p>
                                <?php else : ?>
                                    <table class="widefat striped" style="max-width:1000px;"><thead><tr><th><?php _e('Data', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Esito', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Modello', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Tempo', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Token', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Riferimento', 'affiliate-link-manager-ai'); ?></th><th><?php _e('Errore', 'affiliate-link-manager-ai'); ?></th></tr></thead><tbody>
                                    <?php foreach ($widget_rewrite_logs as $log) : ?>
                                        <tr>
                                            <td><?php echo esc_html($log['created_at'] ?? ''); ?></td>
                                            <td><?php echo !empty($log['success']) ? esc_html__('Successo', 'affiliate-link-manager-ai') : esc_html__('Fallimento', 'affiliate-link-manager-ai'); ?></td>
                                            <td><?php echo esc_html($log['model'] ?? ''); ?></td>
                                            <td><?php echo esc_html(isset($log['response_time']) ? absint($log['response_time']) . ' ms' : ''); ?></td>
                                            <td><?php echo esc_html(absint($log['input_tokens'] ?? 0) . ' / ' . absint($log['output_tokens'] ?? 0)); ?></td>
                                            <td><?php echo esc_html($log['reference_id'] ?? ''); ?></td>
                                            <td><?php echo esc_html($log['error_message'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody></table>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Export Affiliate Links -->
                <div id="export-affiliate-links" class="alma-settings-section" style="display:none;">
                    <h2><?php _e('Export Link Affiliati', 'affiliate-link-manager-ai'); ?></h2>
                    <p><?php _e('Esporta i Link Affiliati con URL, tipologie, contesto AI, provider, immagine e dati geografici già presenti. Il CSV può essere usato per analisi esterne e per preparare un futuro file di geolocalizzazione dei Link Affiliati.', 'affiliate-link-manager-ai'); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Link Affiliati disponibili', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <?php $affiliate_counts = $this->get_affiliate_link_export_counts(); ?>
                                <p>
                                    <strong><?php echo esc_html(number_format_i18n($affiliate_counts['total'])); ?></strong> <?php esc_html_e('totali', 'affiliate-link-manager-ai'); ?> ·
                                    <strong><?php echo esc_html(number_format_i18n($affiliate_counts['publish'])); ?></strong> <?php esc_html_e('pubblicati', 'affiliate-link-manager-ai'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('CSV analisi esterna', 'affiliate-link-manager-ai'); ?></th>
                            <td>
                                <p class="description"><?php _e('Esporta tutti i Link Affiliati in un file CSV utile per analisi esterne, geolocalizzazione e successivo re-import dei dati geografici.', 'affiliate-link-manager-ai'); ?></p>
                                <p>
                                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alma_export_affiliate_links_csv'), 'alma_export_affiliate_links_csv')); ?>">
                                        <?php _e('Esporta Link Affiliati CSV', 'affiliate-link-manager-ai'); ?>
                                    </a>
                                </p>
                                <p class="description"><?php _e('Il file non include API key, log tecnici privati o altri segreti; contiene solo dati del CPT affiliate_link e meta utili alla geolocalizzazione.', 'affiliate-link-manager-ai'); ?></p>
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

            // Verifica accesso allo Storage OpenAI (Vector Store)
            $('#alma-verify-openai-storage').on('click', function(e) {
                e.preventDefault();
                var $result = $('#alma-openai-storage-result');
                $result.html('<span class="spinner is-active" style="float:none;margin-top:0;"></span> Verifica in corso...');
                $.post(ajaxurl, {
                    action: 'alma_verify_openai_storage',
                    nonce: '<?php echo wp_create_nonce("alma_admin_nonce"); ?>',
                    vector_store_id: $('#openai_vector_store_id').val()
                }, function(response) {
                    if (response && response.success) {
                        $result.html('<span style="color:green;">✅ Accesso OK: "' + response.data.name + '" — stato ' + response.data.status + ', file completati ' + response.data.files_completed + '/' + response.data.files_total + ', dimensione ' + response.data.usage + '</span>');
                    } else {
                        $result.html('<span style="color:#dc3232;">❌ ' + ((response && response.data && response.data.message) ? response.data.message : 'Verifica fallita') + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color:#dc3232;">❌ Errore di connessione</span>');
                });
            });

            // Test OpenAI API connection
            $('#test-openai-connection').on('click', function(e) {
                e.preventDefault();
                var $result = $('#openai-test-result');
                $result.html('<span class="spinner is-active" style="float:none; margin-top:0;"></span> Test in corso...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'alma_test_openai_connection',
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
     * Verifica l'accesso al Vector Store OpenAI configurato: conferma che la
     * API key possa leggerlo e mostra nome, stato e numero di file. È lo
     * storage che l'Agente di ideazione consulta via file_search.
     */
    public function ajax_verify_openai_storage() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        if (!check_ajax_referer('alma_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 400);
        }
        $api_key = trim((string) get_option('alma_openai_api_key', ''));
        if ($api_key === '') {
            wp_send_json_error(array('message' => __('OpenAI non è configurata: salva prima la API key.', 'affiliate-link-manager-ai')));
        }
        $vector_store_id = sanitize_text_field(wp_unslash($_POST['vector_store_id'] ?? get_option('alma_openai_vector_store_id', '')));
        if ($vector_store_id === '' || !preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $vector_store_id)) {
            wp_send_json_error(array('message' => __('Inserisci un Vector Store ID valido (es. vs_...).', 'affiliate-link-manager-ai')));
        }
        $response = wp_remote_get('https://api.openai.com/v1/vector_stores/' . rawurlencode($vector_store_id), array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Bearer ' . $api_key),
        ));
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Errore di connessione a OpenAI.', 'affiliate-link-manager-ai')));
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $api_error = sanitize_text_field((string)($data['error']['message'] ?? ''));
            $message = $code === 404 ? __('Vector Store non trovato: controlla l\'ID e che appartenga allo stesso progetto della API key.', 'affiliate-link-manager-ai') : sprintf(__('Errore OpenAI (HTTP %d).', 'affiliate-link-manager-ai'), $code);
            wp_send_json_error(array('message' => $message . ($api_error !== '' ? ' — ' . $api_error : '')));
        }
        $counts = (array)($data['file_counts'] ?? array());
        wp_send_json_success(array(
            'name' => sanitize_text_field((string)($data['name'] ?? $vector_store_id)),
            'status' => sanitize_text_field((string)($data['status'] ?? '')),
            'files_completed' => (int)($counts['completed'] ?? 0),
            'files_total' => (int)($counts['total'] ?? 0),
            'usage' => size_format((int)($data['usage_bytes'] ?? 0)),
        ));
    }

    /**
     * Renderizza la pagina dell'editor CSS
     */
    public function render_css_editor_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $css = get_option('alma_custom_css', '');
        if (isset($_POST['alma_save_css']) && isset($_POST['alma_css_nonce']) && wp_verify_nonce($_POST['alma_css_nonce'], 'alma_save_css')) {
            $css = wp_unslash($_POST['alma_custom_css']);
            update_option('alma_custom_css', wp_strip_all_tags($css));
            echo '<div class="notice notice-success"><p>' . __('CSS salvato.', 'affiliate-link-manager-ai') . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Editor CSS', 'affiliate-link-manager-ai'); ?></h1>
            <form method="post" action="">
                <?php wp_nonce_field('alma_save_css', 'alma_css_nonce'); ?>
                <textarea id="alma-custom-css" name="alma_custom_css" rows="20" class="widefat" style="min-height:400px;">
<?php echo esc_textarea($css); ?></textarea>
                <?php submit_button(__('Salva CSS', 'affiliate-link-manager-ai'), 'primary', 'alma_save_css'); ?>
            </form>
        </div>
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
     * Restituisce i preset visuali disponibili per i widget AI.
     */
    private function get_widget_layout_presets() {
        if (class_exists('ALMA_Affiliate_Widget_Layout_Registry')) {
            return ALMA_Affiliate_Widget_Layout_Registry::get_presets();
        }

        return array();
    }

    private function get_default_widget_layout_preset() {
        return class_exists('ALMA_Affiliate_Widget_Layout_Registry') ? ALMA_Affiliate_Widget_Layout_Registry::get_default_preset() : 'columns_3';
    }

    /**
     * Sanitizza il preset layout salvato nell'istanza widget.
     */
    private function sanitize_widget_layout_preset($layout_preset) {
        $layout_preset = sanitize_key($layout_preset);
        $presets = $this->get_widget_layout_presets();

        return isset($presets[$layout_preset]) ? $layout_preset : '';
    }

    /**
     * Deduce il preset più vicino per widget legacy privi di layout_preset.
     */
    private function infer_widget_layout_preset($instance) {
        if (class_exists('ALMA_Affiliate_Widget_Layout_Registry')) {
            return ALMA_Affiliate_Widget_Layout_Registry::infer_preset($instance);
        }

        $desktop = isset($instance['template_desktop_columns']) ? (int) $instance['template_desktop_columns'] : 3;
        return 'columns_' . max(1, min(6, $desktop));
    }

    /**
     * Restituisce il preset salvato o, per i widget legacy, quello dedotto dai campi esistenti.
     */
    private function get_widget_layout_preset_for_instance($instance) {
        $saved = $this->sanitize_widget_layout_preset($instance['layout_preset'] ?? '');

        return $saved ? $saved : $this->infer_widget_layout_preset($instance);
    }

    /**
     * Nome leggibile del layout widget per le tabelle admin.
     */
    private function get_widget_layout_label($layout_preset) {
        $presets = $this->get_widget_layout_presets();
        $layout_preset = $this->sanitize_widget_layout_preset($layout_preset);

        return $layout_preset && isset($presets[$layout_preset]) ? $presets[$layout_preset]['label'] : __('Layout automatico', 'affiliate-link-manager-ai');
    }

    private function get_widget_source_options() {
        global $wpdb;

        $options = array();
        $table = $wpdb->prefix . 'alma_affiliate_sources';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists === $table) {
            $rows = $wpdb->get_results("SELECT id, name, provider_label, provider, deleted_at FROM {$table} ORDER BY name ASC LIMIT 200", ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $label = (string) ($row['name'] ?? '');
                    if (!empty($row['provider_label'])) {
                        $label .= ' · ' . (string) $row['provider_label'];
                    } elseif (!empty($row['provider'])) {
                        $label .= ' · ' . (string) $row['provider'];
                    }
                    if (!empty($row['deleted_at'])) {
                        $label .= ' (' . __('eliminata', 'affiliate-link-manager-ai') . ')';
                    }
                    if ($label !== '') {
                        $options[(string) absint($row['id'])] = $label;
                    }
                }
            }
        }

        $provider_rows = $wpdb->get_results("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_alma_source_provider_label','_alma_source_provider','_alma_source_name') AND meta_value <> '' ORDER BY meta_value ASC LIMIT 200", ARRAY_A);
        if (is_array($provider_rows)) {
            foreach ($provider_rows as $row) {
                $value = sanitize_text_field($row['meta_value'] ?? '');
                if ($value !== '') {
                    $options['provider:' . $value] = sprintf(__('Provider/meta: %s', 'affiliate-link-manager-ai'), $value);
                }
            }
        }

        return $options;
    }

    private function get_widget_link_source_label($post_id) {
        $source_id = absint(get_post_meta($post_id, '_alma_source_id', true));
        if ($source_id > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'alma_affiliate_sources';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists === $table) {
                $source = $wpdb->get_row($wpdb->prepare("SELECT name, provider_label, provider, deleted_at FROM {$table} WHERE id = %d", $source_id), ARRAY_A);
                if (is_array($source)) {
                    $label = (string) ($source['name'] ?? ('Source #' . $source_id));
                    $provider = (string) (($source['provider_label'] ?? '') ?: ($source['provider'] ?? ''));
                    if ($provider !== '') {
                        $label .= ' · ' . $provider;
                    }
                    if (!empty($source['deleted_at'])) {
                        $label .= ' (' . __('eliminata', 'affiliate-link-manager-ai') . ')';
                    }
                    return $label;
                }
            }
        }

        $snapshot = (string) get_post_meta($post_id, '_alma_source_name', true);
        if ($snapshot !== '') {
            $provider = (string) get_post_meta($post_id, '_alma_source_provider_label', true);
            return $provider !== '' ? $snapshot . ' · ' . $provider : $snapshot;
        }

        $provider = (string) get_post_meta($post_id, '_alma_source_provider_label', true);
        if ($provider === '') {
            $provider = (string) get_post_meta($post_id, '_alma_source_provider', true);
        }

        return $provider !== '' ? $provider : __('Manuale', 'affiliate-link-manager-ai');
    }

    private function get_widget_link_item($post_id) {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'affiliate_link' || $post->post_status !== 'publish') {
            return null;
        }

        $terms = get_the_terms($post_id, 'link_type');
        $types = array();
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $types[] = $term->name;
            }
        }

        $usage = $this->get_shortcode_usage_stats($post_id);
        $thumbnail = get_the_post_thumbnail_url($post_id, 'thumbnail');

        return array(
            'id'        => $post_id,
            'title'     => get_the_title($post_id),
            'thumbnail' => $thumbnail ? $thumbnail : '',
            'types'     => $types,
            'source'    => $this->get_widget_link_source_label($post_id),
            'clicks'    => (int) (get_post_meta($post_id, '_click_count', true) ?: 0),
            'usage'     => is_array($usage) ? $usage : array('post_count' => 0, 'total_occurrences' => 0),
            'excerpt'   => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 22),
        );
    }

    private function validate_widget_manual_ids($manual_ids) {
        $invalid = array();
        $valid = array();
        $pieces = preg_split('/[\s,]+/', (string) $manual_ids);
        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }
            $id = absint($piece);
            if (!$id) {
                continue;
            }
            $post = get_post($id);
            if ($post && $post->post_type === 'affiliate_link' && $post->post_status === 'publish') {
                $valid[] = $id;
            } else {
                $invalid[] = $id;
            }
        }

        return array(array_values(array_unique($valid)), array_values(array_unique($invalid)));
    }

    private function normalize_widget_instance_from_request($base_instance = array()) {
        $layout_preset = $this->sanitize_widget_layout_preset($_POST['layout_preset'] ?? '');
        if (!$layout_preset) {
            $layout_preset = $this->get_default_widget_layout_preset();
        }
        $presets = $this->get_widget_layout_presets();
        $preset = $presets[$layout_preset];

        $button_text = sanitize_text_field(wp_unslash($_POST['button_text'] ?? ''));
        if ($button_text === '') {
            $button_text = __('Scopri di più', 'affiliate-link-manager-ai');
        }

        list($manual_ids, $invalid_ids) = $this->validate_widget_manual_ids(wp_unslash($_POST['manual_ids'] ?? ''));
        $selected = isset($_POST['links']) ? array_map('absint', (array) wp_unslash($_POST['links'])) : array();
        $result_selected = isset($_POST['result_links']) ? array_map('absint', (array) wp_unslash($_POST['result_links'])) : array();
        $selected = array_filter(array_unique(array_merge($selected, $result_selected)));
        $valid_selected = array();
        foreach ($selected as $link_id) {
            $item = $this->get_widget_link_item($link_id);
            if ($item) {
                $valid_selected[] = $link_id;
            }
        }

        $merged = array_values(array_unique(array_merge($valid_selected, $manual_ids)));
        $limit_exceeded = count($merged) > 20;
        $merged = array_slice($merged, 0, 20);

        $instance = array_merge((array) $base_instance, array(
            'title'                    => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            // Campo "Contenuto introduttivo" rimosso dai form (2.82.0): il
            // valore già salvato nei widget esistenti viene preservato.
            'custom_content'           => (string) ($base_instance['custom_content'] ?? ''),
            'show_image'               => 1,
            'show_title'               => 1,
            'show_content'             => 1,
            'show_button'              => 1,
            'button_text'              => $button_text,
            'layout_preset'            => $layout_preset,
            'template_desktop_columns' => absint($preset['desktop']),
            'template_mobile_columns'  => absint($preset['mobile']),
            'manual_ids'               => $manual_ids,
            'links'                    => $merged,
        ));

        return array($instance, $invalid_ids, $limit_exceeded);
    }


    private function remove_widget_link_from_instance($instance, $remove_id) {
        $remove_id = absint($remove_id);
        if (!$remove_id) {
            return $instance;
        }

        $instance['links'] = array_values(array_diff(array_map('absint', (array) ($instance['links'] ?? array())), array($remove_id)));
        $instance['manual_ids'] = array_values(array_diff(array_map('absint', (array) ($instance['manual_ids'] ?? array())), array($remove_id)));
        if (isset($instance['rewritten_links'][(string) $remove_id])) {
            unset($instance['rewritten_links'][(string) $remove_id]);
        }
        if (isset($instance['rewritten_links'][$remove_id])) {
            unset($instance['rewritten_links'][$remove_id]);
        }

        return $instance;
    }

    private function search_widget_affiliate_links($selected_ids = array()) {
        $keyword = sanitize_text_field(wp_unslash($_POST['affiliate_search_keyword'] ?? $_GET['affiliate_search_keyword'] ?? ''));
        $type = absint($_POST['affiliate_search_type'] ?? $_GET['affiliate_search_type'] ?? 0);
        $source = sanitize_text_field(wp_unslash($_POST['affiliate_search_source'] ?? $_GET['affiliate_search_source'] ?? ''));
        $page = max(1, absint($_POST['search_page'] ?? $_GET['search_page'] ?? 1));
        $has_explicit_search = isset($_POST['alma_search_links']) || isset($_POST['search_page']) || isset($_GET['search_page']) || $keyword !== '' || $type > 0 || $source !== '';

        if (!$has_explicit_search) {
            return array(
                'keyword' => $keyword,
                'type'    => $type,
                'source'  => $source,
                'page'    => $page,
                'pages'   => 0,
                'results' => array(),
                'searched' => false,
            );
        }

        $args = array(
            'post_type'      => 'affiliate_link',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        if ($keyword !== '') {
            $args['s'] = $keyword;
        }
        if ($type > 0) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'link_type',
                    'field'    => 'term_id',
                    'terms'    => $type,
                ),
            );
        }
        if ($source !== '') {
            if (ctype_digit($source)) {
                $args['meta_query'] = array(
                    array(
                        'key'   => '_alma_source_id',
                        'value' => (string) absint($source),
                    ),
                );
            } else {
                $source_value = strpos($source, 'provider:') === 0 ? substr($source, 9) : $source;
                $args['meta_query'] = array(
                    'relation' => 'OR',
                    array('key' => '_alma_source_provider', 'value' => $source_value, 'compare' => 'LIKE'),
                    array('key' => '_alma_source_provider_label', 'value' => $source_value, 'compare' => 'LIKE'),
                    array('key' => '_alma_source_name', 'value' => $source_value, 'compare' => 'LIKE'),
                );
            }
        }

        $query = new WP_Query($args);
        $results = array();
        foreach ($query->posts as $post) {
            $item = $this->get_widget_link_item($post->ID);
            if ($item) {
                $item['selected'] = in_array((int) $post->ID, array_map('intval', (array) $selected_ids), true);
                $results[] = $item;
            }
        }
        wp_reset_postdata();

        return array(
            'keyword' => $keyword,
            'type'    => $type,
            'source'  => $source,
            'page'    => $page,
            'pages'   => max(1, (int) $query->max_num_pages),
            'results' => $results,
            'searched' => true,
        );
    }

    /**
     * Renderizza la selezione visuale dei layout widget.
     */
    private function render_widget_layout_preset_field($selected_layout) {
        $presets = class_exists('ALMA_Affiliate_Widget_Layout_Registry') ? ALMA_Affiliate_Widget_Layout_Registry::get_selectable_presets() : $this->get_widget_layout_presets();
        $selected_layout = $this->sanitize_widget_layout_preset($selected_layout);
        if (!$selected_layout) {
            $selected_layout = $this->get_default_widget_layout_preset();
        }
        // Widget salvato con un layout legacy a colonne: lo si mostra nel
        // picker (con la sua etichetta) così la selezione attuale resta visibile.
        if (!isset($presets[$selected_layout])) {
            $all_presets = $this->get_widget_layout_presets();
            if (isset($all_presets[$selected_layout])) {
                $presets[$selected_layout] = $all_presets[$selected_layout];
            }
        }
        ?>
        <tr>
            <th scope="row"><?php _e('Scegli layout widget', 'affiliate-link-manager-ai'); ?></th>
            <td>
                <fieldset class="alma-layout-picker" aria-describedby="alma-layout-picker-description">
                    <legend class="screen-reader-text"><span><?php _e('Scegli layout widget', 'affiliate-link-manager-ai'); ?></span></legend>
                    <p id="alma-layout-picker-description" class="description alma-layout-picker__intro">
                        <?php _e('Layout in stile catalogo di esperienze: Card destinazione per le mete, Card esperienza per tour e attività (carosello su mobile), Vetrina per un singolo link di punta.', 'affiliate-link-manager-ai'); ?>
                    </p>
                    <div class="alma-layout-grid">
                        <?php foreach ($presets as $slug => $preset) :
                            $input_id = 'alma_layout_preset_' . $slug;
                            ?>
                            <label class="alma-layout-card" for="<?php echo esc_attr($input_id); ?>">
                                <input
                                    type="radio"
                                    id="<?php echo esc_attr($input_id); ?>"
                                    name="layout_preset"
                                    value="<?php echo esc_attr($slug); ?>"
                                    <?php checked($selected_layout, $slug); ?>
                                >
                                <span class="alma-layout-card__visual">
                                    <img src="<?php echo esc_url(ALMA_PLUGIN_URL . 'assets/' . $preset['image']); ?>" alt="<?php echo esc_attr(sprintf(__('Anteprima layout: %s', 'affiliate-link-manager-ai'), $preset['label'])); ?>" loading="lazy">
                                </span>
                                <span class="alma-layout-card__name"><?php echo esc_html($preset['label']); ?></span>
                                <span class="alma-layout-card__description"><?php echo esc_html($preset['description']); ?></span>
                                <span class="alma-layout-card__meta">
                                    <span class="alma-layout-badge"><?php echo esc_html(!empty($preset['badge']) ? $preset['badge'] : sprintf(__('%d colonne', 'affiliate-link-manager-ai'), absint($preset['desktop']))); ?></span>
                                    <span class="alma-layout-badge alma-layout-badge--responsive"><?php esc_html_e('Responsive', 'affiliate-link-manager-ai'); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </td>
        </tr>
        <?php
    }

    private function render_widget_affiliate_search($search, $selected_ids) {
        $terms = get_terms(array('taxonomy' => 'link_type', 'hide_empty' => false));
        $sources = $this->get_widget_source_options();
        ?>
        <div class="alma-widget-builder-section alma-affiliate-search">
            <h2><?php _e('Ricerca Link Affiliati', 'affiliate-link-manager-ai'); ?></h2>
            <div class="alma-affiliate-search__controls">
                <label>
                    <span><?php _e('Parola chiave', 'affiliate-link-manager-ai'); ?></span>
                    <input type="search" name="affiliate_search_keyword" value="<?php echo esc_attr($search['keyword']); ?>" class="regular-text">
                </label>
                <label>
                    <span><?php _e('Tipologie Link', 'affiliate-link-manager-ai'); ?></span>
                    <select name="affiliate_search_type">
                        <option value="0"><?php _e('Tutte le tipologie', 'affiliate-link-manager-ai'); ?></option>
                        <?php if (!is_wp_error($terms)) : foreach ($terms as $term) : ?>
                            <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected($search['type'], $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </label>
                <label>
                    <span><?php _e('Fonte/Provider', 'affiliate-link-manager-ai'); ?></span>
                    <select name="affiliate_search_source">
                        <option value=""><?php _e('Tutte le fonti', 'affiliate-link-manager-ai'); ?></option>
                        <?php foreach ($sources as $source_id => $source_label) : ?>
                            <option value="<?php echo esc_attr($source_id); ?>" <?php selected($search['source'], (string) $source_id); ?>><?php echo esc_html($source_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" name="alma_search_links" class="button"><?php esc_html_e('Cerca link', 'affiliate-link-manager-ai'); ?></button>
            </div>
            <?php if (!empty($search['results'])) : ?>
                <div class="alma-affiliate-search-results">
                    <?php foreach ($search['results'] as $result) : ?>
                        <label class="alma-affiliate-search-result<?php echo $result['selected'] ? ' is-selected' : ''; ?>">
                            <span class="alma-affiliate-search-result__check">
                                <input type="checkbox" name="result_links[]" value="<?php echo esc_attr($result['id']); ?>" <?php checked($result['selected']); ?>>
                            </span>
                            <span class="alma-affiliate-search-result__thumb">
                                <?php if ($result['thumbnail']) : ?>
                                    <img src="<?php echo esc_url($result['thumbnail']); ?>" alt="">
                                <?php else : ?>
                                    <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                                <?php endif; ?>
                            </span>
                            <span class="alma-affiliate-search-result__body">
                                <strong><?php echo esc_html($result['id']); ?> · <?php echo esc_html($result['title']); ?></strong>
                                <span><?php printf(esc_html__('Tipologie: %s', 'affiliate-link-manager-ai'), esc_html(!empty($result['types']) ? implode(', ', $result['types']) : '-')); ?></span>
                                <span><?php printf(esc_html__('Fonte: %s', 'affiliate-link-manager-ai'), esc_html($result['source'])); ?></span>
                                <span><?php printf(esc_html__('Click: %d · Utilizzo: %d post / %d shortcode', 'affiliate-link-manager-ai'), absint($result['clicks']), absint($result['usage']['post_count'] ?? 0), absint($result['usage']['total_occurrences'] ?? 0)); ?></span>
                                <?php if ($result['excerpt']) : ?><em><?php echo esc_html($result['excerpt']); ?></em><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="alma-affiliate-search__add-selected">
                    <button type="submit" name="alma_add_selected_links" class="button button-primary">
                        <?php esc_html_e('Aggiungi selezionati al widget', 'affiliate-link-manager-ai'); ?>
                    </button>
                </p>
                <?php if ($search['pages'] > 1) : ?>
                    <p class="alma-pagination">
                        <?php for ($p = 1; $p <= $search['pages']; $p++) : ?>
                            <button type="submit" name="search_page" value="<?php echo esc_attr($p); ?>" class="button <?php echo (int) $p === (int) $search['page'] ? 'button-primary' : ''; ?>"><?php echo esc_html($p); ?></button>
                        <?php endfor; ?>
                    </p>
                <?php endif; ?>
            <?php elseif (!empty($search['searched'])) : ?>
                <p class="description"><?php _e('Nessun Link Affiliato trovato per i criteri selezionati.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <p class="description"><?php _e('Cerca per titolo, destinazione, attività o parola chiave per selezionare i Link Affiliati da inserire nel widget.', 'affiliate-link-manager-ai'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_widget_selected_links($selected_ids) {
        $selected_ids = array_slice(array_values(array_unique(array_map('absint', (array) $selected_ids))), 0, 20);
        ?>
        <div class="alma-widget-builder-section alma-selected-links">
            <h2><?php _e('Link selezionati nel widget', 'affiliate-link-manager-ai'); ?> <span class="alma-selected-links-count"><?php echo esc_html(count($selected_ids)); ?>/20</span></h2>
            <?php if (count($selected_ids) >= 20) : ?>
                <div class="notice notice-warning inline"><p><?php _e('Limite massimo di 20 link raggiunto: rimuovi un link per aggiungerne altri.', 'affiliate-link-manager-ai'); ?></p></div>
            <?php endif; ?>
            <?php if (empty($selected_ids)) : ?>
                <p class="description"><?php _e('Nessun link selezionato. Cerca link affiliati o inserisci ID manuali.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <div class="alma-selected-links-list">
                    <?php foreach ($selected_ids as $link_id) : $item = $this->get_widget_link_item($link_id); if (!$item) { continue; } ?>
                        <div class="alma-selected-link">
                            <input type="hidden" name="links[]" value="<?php echo esc_attr($link_id); ?>">
                            <span class="alma-selected-link__thumb">
                                <?php if ($item['thumbnail']) : ?><img src="<?php echo esc_url($item['thumbnail']); ?>" alt=""><?php else : ?><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php endif; ?>
                            </span>
                            <span class="alma-selected-link__body">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php printf(esc_html__('ID: %d', 'affiliate-link-manager-ai'), absint($link_id)); ?></span>
                                <?php if (!empty($item['types'])) : ?><span><?php printf(esc_html__('Tipologia: %s', 'affiliate-link-manager-ai'), esc_html(implode(', ', $item['types']))); ?></span><?php endif; ?>
                            </span>
                            <button type="submit" name="remove_link" value="<?php echo esc_attr($link_id); ?>" class="button-link-delete alma-remove-selected-link" aria-label="<?php esc_attr_e('Rimuovi questo link dal widget', 'affiliate-link-manager-ai'); ?>" title="<?php esc_attr_e('Rimuovi questo link dal widget', 'affiliate-link-manager-ai'); ?>">
                                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('Rimuovi questo link dal widget', 'affiliate-link-manager-ai'); ?></span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Anteprima live del widget nel builder: rendering reale (stessi markup e
     * CSS del frontend) con i click disabilitati per evitare tracking/navigazioni.
     */
    private function render_widget_preview($instance) {
        $links = array_filter(array_map('absint', (array) ($instance['links'] ?? array())));
        ?>
        <div class="alma-widget-builder-section alma-widget-preview">
            <h2><?php _e('Anteprima', 'affiliate-link-manager-ai'); ?></h2>
            <?php if (empty($links)) : ?>
                <p class="description"><?php _e('Aggiungi almeno un link per vedere l\'anteprima del widget.', 'affiliate-link-manager-ai'); ?></p>
            <?php else :
                $preview = class_exists('ALMA_Affiliate_Links_Widget') ? ALMA_Affiliate_Links_Widget::render_links($instance) : '';
                if ($preview === '') : ?>
                    <p class="description"><?php _e('Nessun link renderizzabile (link non pubblicati, senza URL o in quarantena).', 'affiliate-link-manager-ai'); ?></p>
                <?php else : ?>
                    <div style="pointer-events:none;background:#fff;border:1px dashed #c3c4c7;border-radius:8px;padding:16px;max-width:1100px;">
                        <?php echo $preview; // Markup generato ed escapato dal renderer del widget. ?>
                    </div>
                    <p class="description"><?php _e('Anteprima indicativa (i font finali dipendono dal tema); i click qui sono disabilitati. Ricarica la pagina dopo aver aggiunto o rimosso link per aggiornarla.', 'affiliate-link-manager-ai'); ?></p>
                <?php endif;
            endif; ?>
        </div>
        <?php
    }

    private function get_widget_ai_rewrite_logs($limit = 10) {
        global $wpdb;
        $table = ALMA_AI_Usage_Logger::table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE task = %s ORDER BY created_at DESC LIMIT %d", ALMA_Affiliate_Widget_AI_Rewriter::TASK, absint($limit)), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Pagina creazione widget
     */
    public function render_create_widget_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $created = false;
        $shortcode = '';
        $php_code = '';
        $invalid_manual_ids = array();
        $link_limit_exceeded = false;
        $no_links_selected = false;
        $no_result_links_selected = false;
        $title_required = false;
        $ai_rewrite_error = '';
        $instance = array(
            'title' => '',
            'custom_content' => '',
            'show_image' => 1,
            'show_title' => 1,
            'show_content' => 1,
            'show_button' => 1,
            'button_text' => __('Scopri di più', 'affiliate-link-manager-ai'),
            'layout_preset' => $this->get_default_widget_layout_preset(),
            'template_desktop_columns' => 3,
            'template_mobile_columns' => 1,
            'links' => array(),
            'manual_ids' => array(),
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('alma_create_widget');
            list($instance, $invalid_manual_ids, $link_limit_exceeded) = $this->normalize_widget_instance_from_request($instance);
            if (isset($_POST['alma_add_selected_links']) && empty($_POST['result_links'])) {
                $no_result_links_selected = true;
            }

            if (isset($_POST['remove_link'])) {
                $instance = $this->remove_widget_link_from_instance($instance, $_POST['remove_link']);
            }

            if (isset($_POST['alma_create_widget'])) {
                if (trim((string) ($instance['title'] ?? '')) === '') {
                    $title_required = true;
                } elseif (empty($instance['links'])) {
                    $no_links_selected = true;
                } else {
                    $instances = get_option('widget_affiliate_links_widget', array());
                    $id = (int) get_option('alma_widget_next_id', 1);
                    while (isset($instances[$id])) {
                        $id++;
                    }
                    $rewrite = ALMA_Affiliate_Widget_AI_Rewriter::rewrite_links($instance['links'], array('widget_id' => $id));
                    if (empty($rewrite['success'])) {
                        $ai_rewrite_error = sanitize_text_field($rewrite['error'] ?? __('Riscrittura AI non riuscita: il widget non è stato creato.', 'affiliate-link-manager-ai'));
                    } else {
                        update_option('alma_widget_next_id', $id + 1);
                        $instance['created_at'] = current_time('mysql');
                        $instance['rewritten_links'] = ALMA_Affiliate_Widget_AI_Rewriter::sanitize_rewritten_links($rewrite['items'] ?? array(), $instance['links']);
                        $instances[$id] = $instance;
                        update_option('widget_affiliate_links_widget', $instances);
                        $created = true;
                        $shortcode = '[affiliate_links_widget id="' . $id . '"]';
                        $php_code = "<?php echo do_shortcode('" . $shortcode . "'); ?>";
                    }
                }
            }
        }

        $search = $this->search_widget_affiliate_links($instance['links']);
        ?>
        <div class="wrap alma-widget-builder">
            <h1><?php _e('Crea Widget Link', 'affiliate-link-manager-ai'); ?></h1>
            <p class="description alma-widget-builder-description"><?php esc_html_e('Crea un widget responsive di Link Affiliati scegliendo un layout preimpostato, cercando i link da inserire e copiando lo shortcode finale nei tuoi contenuti.', 'affiliate-link-manager-ai'); ?></p>
            <?php if ($link_limit_exceeded) : ?><div class="notice notice-warning"><p><?php _e('Hai selezionato più di 20 link: verranno utilizzati solo i primi 20.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if (!empty($invalid_manual_ids)) : ?><div class="notice notice-warning"><p><?php printf(esc_html__('Gli ID %s non sono validi e sono stati ignorati.', 'affiliate-link-manager-ai'), esc_html(implode(', ', $invalid_manual_ids))); ?></p></div><?php endif; ?>
            <?php if ($title_required) : ?><div class="notice notice-error"><p><?php _e('Il titolo widget è obbligatorio per creare il widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($no_links_selected) : ?><div class="notice notice-error"><p><?php _e('Seleziona almeno un link prima di creare il widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($no_result_links_selected) : ?><div class="notice notice-warning"><p><?php _e('Seleziona almeno un risultato di ricerca prima di aggiungerlo al widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($ai_rewrite_error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($ai_rewrite_error); ?></p></div><?php endif; ?>
            <?php if ($created) : ?>
                <div class="notice notice-success"><p><?php _e('Widget Link creato. I testi dei link selezionati sono stati riscritti dall’AI.', 'affiliate-link-manager-ai'); ?></p></div>
                <div class="alma-widget-created-box">
                    <p><strong><?php _e('Shortcode:', 'affiliate-link-manager-ai'); ?></strong> <code id="alma-created-shortcode"><?php echo esc_html($shortcode); ?></code> <button type="button" class="button alma-copy-button" data-copy-target="#alma-created-shortcode"><?php esc_html_e('Copia shortcode', 'affiliate-link-manager-ai'); ?></button></p>
                    <p><strong><?php _e('Codice PHP:', 'affiliate-link-manager-ai'); ?></strong> <code id="alma-created-php"><?php echo esc_html($php_code); ?></code> <button type="button" class="button alma-copy-button" data-copy-target="#alma-created-php"><?php esc_html_e('Copia codice PHP', 'affiliate-link-manager-ai'); ?></button></p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=affiliate-link-widgets')); ?>"><?php esc_html_e('Vai a Elenco Widget Link', 'affiliate-link-manager-ai'); ?></a></p>
                </div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('alma_create_widget'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><label for="alma_widget_title"><?php _e('Titolo widget', 'affiliate-link-manager-ai'); ?></label></th><td><input name="title" id="alma_widget_title" type="text" value="<?php echo esc_attr($instance['title']); ?>" class="regular-text"></td></tr>
                    <?php $this->render_widget_layout_preset_field($this->get_widget_layout_preset_for_instance($instance)); ?>
                    <tr><th scope="row"><label for="alma_widget_button_text"><?php _e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label></th><td><input name="button_text" type="text" id="alma_widget_button_text" value="<?php echo esc_attr($instance['button_text']); ?>" class="regular-text"><p class="description"><?php _e('Default: Scopri di più. Se vuoto, verrà salvato il default.', 'affiliate-link-manager-ai'); ?></p></td></tr>
                    <tr><th scope="row"><label for="alma_widget_manual_ids"><?php _e('ID Link affiliati', 'affiliate-link-manager-ai'); ?></label></th><td><input name="manual_ids" type="text" id="alma_widget_manual_ids" value="<?php echo esc_attr(implode(',', (array) $instance['manual_ids'])); ?>" class="regular-text"><p class="description"><?php _e('ID separati da virgola. Verranno validati come affiliate_link pubblicati, senza duplicati e nel limite di 20 link totali.', 'affiliate-link-manager-ai'); ?></p></td></tr>
                </tbody></table>
                <?php $this->render_widget_affiliate_search($search, $instance['links']); ?>
                <?php $this->render_widget_selected_links($instance['links']); ?>
                <?php $this->render_widget_preview($instance); ?>
                <?php if (!$created) : ?><p><input type="submit" name="alma_create_widget" class="button-primary" value="<?php esc_attr_e('Crea widget', 'affiliate-link-manager-ai'); ?>"></p><?php endif; ?>
            </form>
        </div>
        <?php
    }

    public function render_edit_widget_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Non hai i permessi per accedere a questa pagina.'));
        }

        $widget_id = isset($_GET['widget_id']) ? absint($_GET['widget_id']) : 0;
        $instances = get_option('widget_affiliate_links_widget', array());
        if (!$widget_id || !isset($instances[$widget_id])) {
            echo '<div class="wrap"><h1>' . esc_html__('Widget non trovato', 'affiliate-link-manager-ai') . '</h1></div>';
            return;
        }

        $instance = $this->normalize_widget_instance_for_admin($instances[$widget_id]);
        $saved = false;
        $invalid_manual_ids = array();
        $link_limit_exceeded = false;
        $no_links_selected = false;
        $no_result_links_selected = false;
        $title_required = false;
        $ai_rewrite_error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('alma_edit_widget');
            list($instance, $invalid_manual_ids, $link_limit_exceeded) = $this->normalize_widget_instance_from_request($instance);
            if (isset($_POST['alma_add_selected_links']) && empty($_POST['result_links'])) {
                $no_result_links_selected = true;
            }
            if (isset($_POST['remove_link'])) {
                $instance = $this->remove_widget_link_from_instance($instance, $_POST['remove_link']);
            }
            if (isset($_POST['alma_save_widget'])) {
                if (trim((string) ($instance['title'] ?? '')) === '') {
                    $title_required = true;
                } elseif (empty($instance['links'])) {
                    $no_links_selected = true;
                } else {
                    $existing_rewrites = ALMA_Affiliate_Widget_AI_Rewriter::sanitize_rewritten_links($instances[$widget_id]['rewritten_links'] ?? array(), $instance['links']);
                    $new_link_ids = array_values(array_diff(array_map('absint', (array) $instance['links']), array_map('absint', array_keys($existing_rewrites))));
                    $new_rewrites = array();
                    if (!empty($new_link_ids)) {
                        $rewrite = ALMA_Affiliate_Widget_AI_Rewriter::rewrite_links($new_link_ids, array('widget_id' => $widget_id));
                        if (empty($rewrite['success'])) {
                            $ai_rewrite_error = sanitize_text_field($rewrite['error'] ?? __('Riscrittura AI non riuscita: il widget non è stato salvato.', 'affiliate-link-manager-ai'));
                        } else {
                            $new_rewrites = ALMA_Affiliate_Widget_AI_Rewriter::sanitize_rewritten_links($rewrite['items'] ?? array(), $new_link_ids);
                        }
                    }
                    if ($ai_rewrite_error === '') {
                        if (empty($instance['created_at'])) {
                            $instance['created_at'] = current_time('mysql');
                        }
                        $instance['rewritten_links'] = ALMA_Affiliate_Widget_AI_Rewriter::sanitize_rewritten_links(array_replace($existing_rewrites, $new_rewrites), $instance['links']);
                        $instances[$widget_id] = $instance;
                        update_option('widget_affiliate_links_widget', $instances);
                        $saved = true;
                    }
                }
            }
        }

        $search = $this->search_widget_affiliate_links($instance['links']);
        ?>
        <div class="wrap alma-widget-builder">
            <h1><?php printf(esc_html__('Modifica Widget #%d', 'affiliate-link-manager-ai'), absint($widget_id)); ?></h1>
            <?php if ($saved) : ?><div class="notice notice-success"><p><?php _e('Widget Link aggiornato. I nuovi link aggiunti sono stati riscritti dall’AI.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($link_limit_exceeded) : ?><div class="notice notice-warning"><p><?php _e('Hai selezionato più di 20 link: verranno utilizzati solo i primi 20.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if (!empty($invalid_manual_ids)) : ?><div class="notice notice-warning"><p><?php printf(esc_html__('Gli ID %s non sono validi e sono stati ignorati.', 'affiliate-link-manager-ai'), esc_html(implode(', ', $invalid_manual_ids))); ?></p></div><?php endif; ?>
            <?php if ($title_required) : ?><div class="notice notice-error"><p><?php _e('Il titolo widget è obbligatorio per salvare il widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($no_links_selected) : ?><div class="notice notice-error"><p><?php _e('Seleziona almeno un link prima di salvare il widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($no_result_links_selected) : ?><div class="notice notice-warning"><p><?php _e('Seleziona almeno un risultato di ricerca prima di aggiungerlo al widget.', 'affiliate-link-manager-ai'); ?></p></div><?php endif; ?>
            <?php if ($ai_rewrite_error !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($ai_rewrite_error); ?></p></div><?php endif; ?>
            <form method="post">
                <?php wp_nonce_field('alma_edit_widget'); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row"><label for="alma_widget_title"><?php _e('Titolo widget', 'affiliate-link-manager-ai'); ?></label></th><td><input name="title" id="alma_widget_title" type="text" value="<?php echo esc_attr($instance['title'] ?? ''); ?>" class="regular-text"></td></tr>
                    <?php $this->render_widget_layout_preset_field($this->get_widget_layout_preset_for_instance($instance)); ?>
                    <tr><th scope="row"><label for="alma_widget_button_text"><?php _e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label></th><td><input name="button_text" type="text" id="alma_widget_button_text" value="<?php echo esc_attr($instance['button_text'] ?? __('Scopri di più', 'affiliate-link-manager-ai')); ?>" class="regular-text"><p class="description"><?php _e('Default: Scopri di più. Se vuoto, verrà salvato il default.', 'affiliate-link-manager-ai'); ?></p></td></tr>
                    <tr><th scope="row"><label for="alma_widget_manual_ids"><?php _e('ID Link affiliati', 'affiliate-link-manager-ai'); ?></label></th><td><input name="manual_ids" type="text" id="alma_widget_manual_ids" value="<?php echo esc_attr(implode(',', (array) ($instance['manual_ids'] ?? array()))); ?>" class="regular-text"><p class="description"><?php _e('ID separati da virgola. Verranno validati come affiliate_link pubblicati, senza duplicati e nel limite di 20 link totali.', 'affiliate-link-manager-ai'); ?></p></td></tr>
                </tbody></table>
                <?php $this->render_widget_affiliate_search($search, $instance['links'] ?? array()); ?>
                <?php $this->render_widget_selected_links($instance['links'] ?? array()); ?>
                <?php $this->render_widget_preview($instance); ?>
                <p><input type="submit" name="alma_save_widget" class="button-primary" value="<?php esc_attr_e('Salva widget', 'affiliate-link-manager-ai'); ?>"></p>
            </form>
        </div>
        <?php
    }

    private function normalize_widget_instance_for_admin($instance) {
        $layout_preset = $this->get_widget_layout_preset_for_instance($instance);
        $presets = $this->get_widget_layout_presets();
        $preset = $presets[$layout_preset] ?? $presets[$this->get_default_widget_layout_preset()];
        $links = isset($instance['links']) ? array_slice(array_values(array_unique(array_map('absint', (array) $instance['links']))), 0, 20) : array();
        $manual_ids = isset($instance['manual_ids']) ? array_values(array_unique(array_map('absint', (array) $instance['manual_ids']))) : array();

        return array_merge((array) $instance, array(
            'title'                    => sanitize_text_field($instance['title'] ?? ''),
            'custom_content'           => $instance['custom_content'] ?? '',
            'button_text'              => sanitize_text_field(($instance['button_text'] ?? '') ?: __('Scopri di più', 'affiliate-link-manager-ai')),
            'show_image'               => 1,
            'show_title'               => 1,
            'show_content'             => 1,
            'show_button'              => 1,
            'layout_preset'            => $layout_preset,
            'template_desktop_columns' => absint($preset['desktop']),
            'template_mobile_columns'  => absint($preset['mobile']),
            'links'                    => $links,
            'manual_ids'               => $manual_ids,
            'rewritten_links'          => class_exists('ALMA_Affiliate_Widget_AI_Rewriter') ? ALMA_Affiliate_Widget_AI_Rewriter::sanitize_rewritten_links($instance['rewritten_links'] ?? array(), $links) : (array) ($instance['rewritten_links'] ?? array()),
        ));
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

        if (isset($_POST['alma_save_widget_accent']) && check_admin_referer('alma_widget_accent')) {
            $accent = sanitize_text_field(wp_unslash($_POST['alma_widget_accent_color'] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
                update_option('alma_widget_accent_color', $accent, false);
                echo '<div class="notice notice-success"><p>' . esc_html__('Colore accento salvato: verrà usato da pulsanti e CTA di tutti i widget a card.', 'affiliate-link-manager-ai') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Colore non valido: usa il formato esadecimale (#RRGGBB).', 'affiliate-link-manager-ai') . '</p></div>';
            }
        }

        // Solo le istanze numeriche, dal più recente al più vecchio (gli ID
        // sono progressivi; created_at può mancare nei widget storici).
        $rows = array();
        foreach ((array) $instances as $id => $instance) {
            if (is_numeric($id)) {
                $rows[(int) $id] = $instance;
            }
        }
        krsort($rows, SORT_NUMERIC);

        // Click degli ultimi 30 giorni provenienti dai widget, per link.
        $widget_clicks_by_link = array();
        $all_link_ids = array();
        foreach ($rows as $instance) {
            foreach ((array) ($instance['links'] ?? array()) as $lid) {
                $all_link_ids[] = absint($lid);
            }
        }
        $all_link_ids = array_values(array_unique(array_filter($all_link_ids)));
        if (!empty($all_link_ids)) {
            global $wpdb;
            $table = $wpdb->prefix . 'alma_analytics';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $placeholders = implode(',', array_fill(0, count($all_link_ids), '%d'));
                $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);
                $click_rows = $wpdb->get_results($wpdb->prepare("SELECT link_id, COUNT(*) AS clicks FROM {$table} WHERE source = 'widget' AND click_time >= %s AND link_id IN ({$placeholders}) GROUP BY link_id", array_merge(array($cutoff), $all_link_ids)), ARRAY_A);
                foreach ((array) $click_rows as $click_row) {
                    $widget_clicks_by_link[(int) $click_row['link_id']] = (int) $click_row['clicks'];
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Elenco Widget Link', 'affiliate-link-manager-ai'); ?></h1>
            <?php if (empty($rows)) : ?>
                <p><?php _e('Nessun widget configurato.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('ID Widget', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Titolo', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Creato il', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Layout', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Link', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Click 30gg', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Shortcode', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php _e('Azioni', 'affiliate-link-manager-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $id => $instance) :
                            $title        = $instance['title'] ?? '';
                            $shortcode    = '[affiliate_links_widget id="' . $id . '"]';
                            $created_at   = $instance['created_at'] ?? '';
                            $created_disp = $created_at ? mysql2date(get_option('date_format'), $created_at) : '-';
                            $layout_label = $this->get_widget_layout_label($this->get_widget_layout_preset_for_instance($instance));
                            $link_ids     = array_values(array_unique(array_filter(array_map('absint', (array) ($instance['links'] ?? array())))));
                            $clicks       = 0;
                            foreach ($link_ids as $lid) { $clicks += $widget_clicks_by_link[$lid] ?? 0; }
                            $is_ai        = !empty($instance['alma_created_by']) && $instance['alma_created_by'] === 'ai_agent';
                        ?>
                        <tr>
                            <td><?php echo esc_html($id); ?></td>
                            <td><?php echo esc_html($title); ?><?php if ($is_ai) : ?> <span title="<?php esc_attr_e('Creato dall\'Agente AI', 'affiliate-link-manager-ai'); ?>">🤖</span><?php endif; ?></td>
                            <td><?php echo esc_html($created_disp); ?></td>
                            <td><span class="alma-layout-badge"><?php echo esc_html($layout_label); ?></span></td>
                            <td><?php echo esc_html(count($link_ids)); ?></td>
                            <td><?php echo esc_html($clicks); ?></td>
                            <td><code><?php echo esc_html($shortcode); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=alma-edit-widget&widget_id=' . $id)); ?>"><?php _e('Modifica', 'affiliate-link-manager-ai'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=affiliate-link-widgets&action=delete&widget_id=' . $id), 'alma_delete_widget_' . $id)); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminando lo shortcode verrà rimosso da tutti i contenuti in cui è stato inserito. Continuare?', 'affiliate-link-manager-ai')); ?>');"><?php _e('Elimina', 'affiliate-link-manager-ai'); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php _e('Click 30gg: click con provenienza "widget" sui link contenuti nel widget negli ultimi 30 giorni. Se lo stesso link è presente in più widget, i click contano in ciascuno.', 'affiliate-link-manager-ai'); ?></p>
            <?php endif; ?>

            <div class="postbox" style="margin-top:16px;max-width:720px;"><div class="inside">
                <h3 style="margin-top:8px;"><?php _e('Colore accento dei widget a card', 'affiliate-link-manager-ai'); ?></h3>
                <p class="description"><?php _e('Usato da pulsanti e CTA dei layout Card esperienza e Vetrina in evidenza. Sovrascrivibile via CSS con la variabile --alma-wgt-accent.', 'affiliate-link-manager-ai'); ?></p>
                <form method="post" style="display:flex;gap:10px;align-items:center;">
                    <?php wp_nonce_field('alma_widget_accent'); ?>
                    <input type="color" name="alma_widget_accent_color" value="<?php echo esc_attr(class_exists('ALMA_Affiliate_Links_Widget') ? ALMA_Affiliate_Links_Widget::accent_color() : '#1a6ee0'); ?>">
                    <button type="submit" name="alma_save_widget_accent" value="1" class="button button-primary"><?php esc_html_e('Salva colore', 'affiliate-link-manager-ai'); ?></button>
                </form>
            </div></div>
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
    
    public function ajax_get_ai_suggestions() {
        $this->ajax_require_nonce('alma_admin_nonce');

        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        if ($link_id > 0) {
            $this->ajax_require_capability('edit_post', array($link_id));
        } else {
            $this->ajax_require_capability('edit_posts');
        }

        $suggestions = $this->generate_ai_suggestions($link_id);

        if (is_wp_error($suggestions) || empty($suggestions)) {
            $msg = is_wp_error($suggestions)
                ? $suggestions->get_error_message()
                : __('Impossibile generare suggerimenti con OpenAI.', 'affiliate-link-manager-ai');
            wp_send_json_error($msg);
        }

        wp_send_json_success($suggestions);
    }

    public function ajax_ai_suggest_text() {
        $this->ajax_require_nonce('alma_ai_suggest_text');
        $this->ajax_require_capability('edit_posts');

        $title       = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

        // Se non vengono passati titolo/descrizione, prova a recuperarli dal link_id
        if (!$title && !$description && isset($_POST['link_id'])) {
            $link_id = absint($_POST['link_id']);
            $post    = get_post($link_id);

            if ($post && $post->post_type === 'affiliate_link') {
                $this->ajax_require_capability('edit_post', array($link_id));
                $title       = sanitize_text_field($post->post_title);
                $description = sanitize_textarea_field($post->post_content);
            } else {
                wp_send_json_error('Invalid link');
                return;
            }
        }

        if (!$title && !$description) {
            wp_send_json_error('Missing data');
            return;
        }

        $title_suggestions   = $this->generate_title_suggestions($title, $description);
        $content_suggestions = $this->generate_content_suggestions($title);

        if (is_wp_error($title_suggestions) || is_wp_error($content_suggestions) ||
            (empty($title_suggestions) && empty($content_suggestions))) {
            $error = is_wp_error($title_suggestions) ? $title_suggestions : $content_suggestions;
            $msg   = $error ? $error->get_error_message() : __('Impossibile generare suggerimenti con OpenAI.', 'affiliate-link-manager-ai');
            wp_send_json_error($msg);
        }

        wp_send_json_success(array(
            'title_suggestions'   => $title_suggestions,
            'content_suggestions' => $content_suggestions
        ));
    }
    
    public function ajax_test_openai_connection() {
        $this->ajax_require_nonce('alma_admin_nonce');
        $this->ajax_require_capability('manage_options');

        $api_key = get_option('alma_openai_api_key');

        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key non configurata');
            return;
        }
        
        // Test semplice con OpenAI
        $response = ALMA_AI_Utils::call_openai_api('Rispondi solo con: "Connessione OK"');
        
        if ($response['success']) {
            update_option('alma_openai_last_test', array('date' => current_time('mysql'),'status' => 'ok','model' => $response['model']));
            wp_send_json_success(array('esito'=>'ok','model' => $response['model'],'response_time' => $response['response_time'],'usage' => $response['usage'] ?? null));
        } else {
            wp_send_json_error($response['error']);
        }
    }
    
    public function ajax_get_performance_predictions() {
        $this->ajax_require_nonce('alma_admin_nonce');

        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        if ($link_id > 0) {
            $this->ajax_require_capability('edit_post', array($link_id));
        } else {
            $this->ajax_require_capability('edit_posts');
        }
        $predictions = $this->get_ai_performance_predictions($link_id);
        
        wp_send_json_success($predictions);
    }
    
    public function ajax_get_dashboard_data() {
        $this->ajax_require_nonce('alma_admin_nonce');
        $this->ajax_require_capability('manage_options');
        $data = $this->dashboard_stats->get_summary(5);
        $data['top_links'] = array_map(function($item) {
            return array(
                'id' => (int) $item['ID'],
                'title' => get_the_title((int) $item['ID']),
                'click_count' => (int) $item['click_count'],
                'edit_url' => get_edit_post_link((int) $item['ID'])
            );
        }, (array) $data['top_links']);
        wp_send_json_success($data);
    }
    
    public function ajax_get_chart_data() {
        $this->ajax_require_nonce('alma_admin_nonce');
        $this->ajax_require_capability('manage_options');
        $metric = sanitize_key($_POST['metric'] ?? 'clicks');
        $range  = sanitize_key($_POST['range'] ?? 'monthly');
        $chart = $this->dashboard_stats->get_chart_data($metric, $range);
        if (is_wp_error($chart)) {
            wp_send_json_error(array('message' => $chart->get_error_message()), 400);
            return;
        }
        wp_send_json_success($chart);
    }
    
    public function ajax_get_link_types() {
        $this->ajax_require_nonce('alma_editor_search');
        $this->ajax_require_capability('edit_posts');

        $terms = get_terms(array(
            'taxonomy' => 'link_type',
            'hide_empty' => false,
        ));
        
        $types = array();
        
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $types[] = array(
                    'id' => (int) $term->term_id,
                    'name' => sanitize_text_field($term->name),
                    'count' => (int) $term->count
                );
            }
        }
        
        wp_send_json_success($types);
    }
    
    public function ajax_get_link_stats() {
        $this->ajax_require_nonce('alma_admin_nonce');
        $this->ajax_require_capability('manage_options');
        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        if (!$link_id) {
            wp_send_json_error(array('message' => __('ID link non valido.', 'affiliate-link-manager-ai')), 400);
            return;
        }
        $stats = $this->dashboard_stats->get_link_stats($link_id);
        $ai_score = get_post_meta($link_id, '_ai_performance_score', true) ?: 0;
        wp_send_json_success(array('clicks' => $stats['clicks'], 'ctr' => $stats['ctr'], 'ai_score' => $ai_score));
    }

    public function ajax_import_affiliate_link() {
        $this->ajax_require_nonce('alma_import_links');
        $this->ajax_require_capability('manage_options');

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $url   = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $status = isset($_POST['status']) && sanitize_key(wp_unslash($_POST['status'])) === 'publish' ? 'publish' : 'draft';
        $types = isset($_POST['types']) ? array_map('absint', (array) wp_unslash($_POST['types'])) : array();
        $link_rel = isset($_POST['rel']) ? sanitize_text_field(wp_unslash($_POST['rel'])) : 'sponsored noopener';
        $link_target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '_blank';

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
    private function get_total_clicks() { return $this->dashboard_stats->get_total_clicks(); }
    
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

    private function get_average_ctr() { return $this->dashboard_stats->get_average_ctr(); }

    private function get_top_performing_links($limit = 5) { return $this->dashboard_stats->get_top_performing_links($limit); }

    private function get_unused_links_count() { return $this->dashboard_stats->get_unused_links_count(); }

    private function get_total_link_occurrences() { return $this->dashboard_stats->get_total_link_occurrences(); }
    
    private function get_shortcode_usage_stats($link_id) { return $this->dashboard_stats->get_shortcode_usage_stats($link_id); }
    
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
            return new \WP_Error('empty_title', __('Titolo mancante', 'affiliate-link-manager-ai'));
        }

        $links = get_posts(array(
            'post_type'   => 'affiliate_link',
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ));

        if (empty($links)) {
            return new \WP_Error('no_links', __('Nessun link affiliato disponibile', 'affiliate-link-manager-ai'));
        }

        $prompt = "Titolo widget: {$title}\n\nLinks disponibili:\n";
        foreach ($links as $link) {
            $prompt .= $link->ID . ': ' . $link->post_title . "\n";
        }
        $prompt .= "\nRestituisci un array JSON con massimo 500 oggetti {\"id\": ID, \"score\": PERTINENZA}, dove PERTINENZA è un numero da 0 a 100 che indica quanto il link è coerente con il titolo. Ordina dal più pertinente al meno pertinente. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.";

        $response = ALMA_AI_Utils::call_openai_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');
        if (empty($response['success'])) {
            return new \WP_Error('openai_error', $response['error'] ?? __('Errore nella richiesta AI', 'affiliate-link-manager-ai'));
        }

        $clean = ALMA_AI_Utils::extract_first_json($response['response']);
        $items = json_decode($clean, true);
        if (!is_array($items)) {
            ALMA_Logger::warning('JSON decode failed', array('json_error' => json_last_error_msg(), 'raw_ai_response' => $response['response']));
            return new \WP_Error('openai_parse_error', __('Risposta non valida dall\'AI', 'affiliate-link-manager-ai'));
        }

        usort($items, function ($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $suggestions = array();
        foreach (array_slice($items, 0, 500) as $item) {
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

        if (empty($suggestions)) {
            return new \WP_Error('no_suggestions', __('Nessun suggerimento disponibile', 'affiliate-link-manager-ai'));
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

        $response = ALMA_AI_Utils::call_openai_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');

        if (empty($response['success'])) {
            return new \WP_Error('openai_error', $response['error'] ?? __('Errore sconosciuto', 'affiliate-link-manager-ai'));
        }

        $clean   = ALMA_AI_Utils::extract_first_json($response['response']);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            ALMA_Logger::warning('JSON decode failed', array('json_error' => json_last_error_msg(), 'raw_ai_response' => $response['response']));
            return new \WP_Error('openai_parse_error', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
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
            return new \WP_Error('empty_suggestions', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
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

        $response = ALMA_AI_Utils::call_openai_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');

        if (empty($response['success'])) {
            return new \WP_Error('openai_error', $response['error'] ?? __('Errore sconosciuto', 'affiliate-link-manager-ai'));
        }

        $clean   = ALMA_AI_Utils::extract_first_json($response['response']);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            ALMA_Logger::warning('JSON decode failed', array('json_error' => json_last_error_msg(), 'raw_ai_response' => $response['response']));
            return new \WP_Error('openai_parse_error', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
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
            return new \WP_Error('empty_suggestions', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
        }

        return $suggestions;
    }

    private function generate_content_suggestions($title) {
        $title = wp_strip_all_tags($title);

        $prompt = sprintf(
            'Sei un copywriter SEO. Basandoti sul titolo "%s", genera 3 possibili contenuti descrittivi in italiano, massimo 50 parole ciascuno, per accompagnare un link affiliato. Rispondi con un array JSON contenente esclusivamente i tre contenuti. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.',
            $title
        );

        $response = ALMA_AI_Utils::call_openai_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');

        if (empty($response['success'])) {
            return new \WP_Error('openai_error', $response['error'] ?? __('Errore sconosciuto', 'affiliate-link-manager-ai'));
        }

        $clean   = ALMA_AI_Utils::extract_first_json($response['response']);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            ALMA_Logger::warning('JSON decode failed', array('json_error' => json_last_error_msg(), 'raw_ai_response' => $response['response']));
            return new \WP_Error('openai_parse_error', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
        }

        $suggestions = array();
        foreach (array_slice($decoded, 0, 3) as $text) {
            $sanitized = sanitize_text_field($text);
            $sanitized = wp_trim_words($sanitized, 50, '');
            $suggestions[] = array(
                'text'       => $sanitized,
                'confidence' => 90,
                'pattern'    => 'ai'
            );
        }

        if (empty($suggestions)) {
            return new \WP_Error('empty_suggestions', __('Risposta non valida da OpenAI', 'affiliate-link-manager-ai'));
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
        // (?!\d) evita falsi positivi: rimuovendo il link 12 non deve sparire lo shortcode del link 123.
        $pattern = '/\[affiliate_link\b[^\]]*\bid=["\']?' . (int) $link_id . '(?!\d)[^\]]*\]/';
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
        // (?!\d) evita falsi positivi su ID widget con più cifre (es. 12 vs 123).
        $pattern = '/\[affiliate_links_widget\b[^\]]*\bid=["\']?' . (int) $widget_id . '(?!\d)[^\]]*\]/';
        return preg_replace($pattern, '', $content);
    }

    public function filter_posts_without_affiliates($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        global $pagenow;
        if ($pagenow !== 'edit.php' || !isset($_GET['alma_no_affiliates'])) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-post') {
            return;
        }
        $query_post_type = $query->get('post_type');
        if ($query_post_type !== 'post' && $query_post_type !== '') {
            return;
        }
        global $wpdb;
        $like = $wpdb->esc_like('[affiliate_link');
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content LIKE %s", '%' . $like . '%'));
        if (!empty($ids)) {
            $query->set('post__not_in', $ids);
        }
    }

    public function invalidate_dashboard_cache() {
        if ($this->dashboard_stats) {
            $this->dashboard_stats->clear_cache();
        }
    }

    /**
     * Activation/Deactivation
     */
    public function activate() {
        ALMA_AI_Content_Agent_Store::install();
        $this->clear_deprecated_trend_cron_events();
        $this->create_analytics_table();
        ALMA_AI_Usage_Logger::create_table();
        ALMA_Affiliate_Source_Manager::create_tables();
        ALMA_Geo_Index_Store::create_tables();
        ALMA_Geo_Index_Job_Store::create_tables();
        ALMA_Geo_Facts::create_table();
        ALMA_Contextual_Affiliate_Widget::maybe_set_default_options();
        $this->create_default_categories();
        update_option('alma_db_schema_version', '6');
        update_option('alma_plugin_version', ALMA_VERSION);
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Rimuovi cron jobs
        wp_clear_scheduled_hook('alma_daily_optimization');
        ALMA_Geo_Geocoding_Queue::unschedule();
        ALMA_Dashboard_Insights::unschedule();
        ALMA_AI_Content_Agent_Idea_Importer::unschedule();
        ALMA_AI_Idea_Agent::unschedule();
        ALMA_AI_Post_Enricher::unschedule();
        ALMA_GSC_Connector::unschedule();
        ALMA_Geo_Facts::unschedule();
        ALMA_Link_Health_Checker::unschedule();
        ALMA_AI_Image_Generator::unschedule();
        $this->clear_deprecated_trend_cron_events();
        flush_rewrite_rules();
    }

    private function clear_deprecated_trend_cron_events() {
        $deprecated_hooks = array('alma_trend_content_ideas_cron', 'alma_ai_trend_radar_run_profile');
        foreach ($deprecated_hooks as $hook) {
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook($hook);
                continue;
            }

            if (!function_exists('_get_cron_array') || !function_exists('wp_unschedule_event')) {
                wp_clear_scheduled_hook($hook);
                continue;
            }

            foreach (_get_cron_array() as $timestamp => $cron) {
                if (empty($cron[$hook])) {
                    continue;
                }

                foreach ($cron[$hook] as $event) {
                    wp_unschedule_event($timestamp, $hook, isset($event['args']) ? $event['args'] : array());
                }
            }
        }
    }

    public function maybe_run_update_tasks() {
        // Rimuove l'evento legacy senza handler eventualmente ancora schedulato.
        if (wp_next_scheduled('alma_daily_optimization')) {
            wp_clear_scheduled_hook('alma_daily_optimization');
        }

        // Migrazione one-time: i vecchi valori di estimated_cost contenevano il
        // numero totale di token, non un costo. Vengono azzerati per non falsare
        // le somme con i nuovi valori in USD.
        if (!get_option('alma_ai_cost_unit_migrated')) {
            global $wpdb;
            $usage_table = ALMA_AI_Usage_Logger::table_name();
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $usage_table)) == $usage_table) {
                $wpdb->query("UPDATE {$usage_table} SET estimated_cost = NULL WHERE estimated_cost IS NOT NULL");
            }
            update_option('alma_ai_cost_unit_migrated', 1, false);
        }

        $installed_version = get_option('alma_plugin_version', '0.0.0');
        $geo_import_schema_version = get_option('alma_geo_import_schema_version', '0');
        $geo_job_store = new ALMA_Geo_Index_Job_Store();
        if (version_compare($installed_version, ALMA_VERSION, '<') || version_compare((string) $geo_import_schema_version, '4', '<') || !$geo_job_store->tables_exist()) {
            ALMA_AI_Content_Agent_Store::install();
            $this->clear_deprecated_trend_cron_events();
            $this->create_analytics_table();
            ALMA_AI_Usage_Logger::create_table();
            ALMA_Affiliate_Source_Manager::create_tables();
            ALMA_Geo_Index_Store::create_tables();
            ALMA_Geo_Index_Job_Store::create_tables();
            ALMA_Geo_Facts::create_table();
            ALMA_Contextual_Affiliate_Widget::maybe_set_default_options();
            update_option('alma_db_schema_version', '6');
            update_option('alma_plugin_version', ALMA_VERSION);
        }
    }
    
    private function create_analytics_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'alma_analytics';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            link_id mediumint(9) NOT NULL,
            post_id bigint(20) unsigned DEFAULT 0 NOT NULL,
            click_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            user_ip varchar(45) DEFAULT '' NOT NULL,
            user_agent text DEFAULT '' NOT NULL,
            referrer varchar(255) DEFAULT '' NOT NULL,
            source varchar(50) DEFAULT '' NOT NULL,
            PRIMARY KEY (id),
            KEY link_id (link_id),
            KEY click_time (click_time),
            KEY link_time (link_id, click_time),
            KEY source_time (source, click_time),
            KEY post_time (post_id, click_time)
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
