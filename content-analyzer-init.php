<?php
/**
 * Enhanced Content Analyzer Initialization v2.0.0
 * Carica e inizializza il Content Analyzer migliorato
 * 
 * IMPORTANTE: Questo file sostituisce il content-analyzer-init.php esistente
 * 
 * Nuove Features v2.0.0:
 * - Caricamento file enhanced
 * - Verifica dipendenze avanzate
 * - Setup database automatico
 * - Gestione errori migliorata
 * - Compatibilità backward
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Verifica che il plugin principale sia caricato
if (!defined('ALMA_VERSION')) {
    return;
}

/**
 * Classe per inizializzazione Enhanced Content Analyzer
 */
class ALMA_Enhanced_ContentAnalyzer_Init {
    
    private static $instance = null;
    private $version = '2.0.0';
    private $required_files = array();
    private $init_errors = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook inizializzazione con priorità alta
        add_action('plugins_loaded', array($this, 'load_enhanced_analyzer'), 15);
        add_action('admin_init', array($this, 'check_requirements'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
        
        // Hook per aggiungere opzioni di default
        register_activation_hook(ALMA_PLUGIN_FILE, array($this, 'set_default_options'));
        register_activation_hook(ALMA_PLUGIN_FILE, array($this, 'create_database_tables'));
        
        // Hook per aggiornamento versione
        add_action('admin_init', array($this, 'check_version_upgrade'));
        
        // Debug hooks (solo se WP_DEBUG attivo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('wp_footer', array($this, 'debug_info'));
            add_action('admin_footer', array($this, 'debug_info'));
        }
    }
    
    /**
     * Carica Enhanced Content Analyzer se i requisiti sono soddisfatti
     */
    public function load_enhanced_analyzer() {
        // Verifica prerequisiti critici
        if (!$this->check_critical_prerequisites()) {
            $this->init_errors[] = 'Prerequisiti critici non soddisfatti';
            return false;
        }
        
        // Definisci file richiesti per Enhanced Analyzer
        $this->define_required_files();
        
        // Verifica esistenza file
        if (!$this->verify_required_files()) {
            $this->init_errors[] = 'File Enhanced Analyzer mancanti';
            return false;
        }
        
        // Carica file principale Enhanced Content Analyzer
        try {
            require_once ALMA_PLUGIN_DIR . 'content-analyzer/content-analyzer.php';
            
            // Log successo
            $this->log_initialization('success');
            
            // Hook post-inizializzazione
            do_action('alma_enhanced_content_analyzer_loaded');
            
            return true;
            
        } catch (Exception $e) {
            $this->init_errors[] = 'Errore caricamento: ' . $e->getMessage();
            $this->log_initialization('error', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Definisci file richiesti
     */
    private function define_required_files() {
        $base_path = ALMA_PLUGIN_DIR . 'content-analyzer/';
        
        $this->required_files = array(
            'main' => $base_path . 'content-analyzer.php',
            'enhanced_css' => $base_path . 'enhanced-analyzer.css',
            'enhanced_js' => $base_path . 'enhanced-analyzer.js',
            'enhanced_widget_template' => $base_path . 'templates/enhanced-widget.php'
        );
        
        // File opzionali (non bloccanti)
        $this->optional_files = array(
            'settings_template' => $base_path . 'templates/settings-section.php',
            'suggestion_template' => $base_path . 'templates/suggestion-widget.php',
            'legacy_css' => $base_path . 'content-analyzer.css',
            'legacy_js' => $base_path . 'ai-content-analysis.js'
        );
    }
    
    /**
     * Verifica file richiesti
     */
    private function verify_required_files() {
        $missing_files = array();
        
        foreach ($this->required_files as $key => $file_path) {
            if (!file_exists($file_path)) {
                $missing_files[] = $key . ' (' . basename($file_path) . ')';
            }
        }
        
        if (!empty($missing_files)) {
            $this->init_errors[] = 'File mancanti: ' . implode(', ', $missing_files);
            return false;
        }
        
        return true;
    }
    
    /**
     * Verifica prerequisiti critici
     */
    private function check_critical_prerequisites() {
        $checks = array();
        
        // WordPress versione minima
        if (version_compare($GLOBALS['wp_version'], '5.0', '<')) {
            $checks[] = 'WordPress 5.0+ richiesto';
        }
        
        // PHP versione minima  
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $checks[] = 'PHP 7.4+ richiesto';
        }
        
        // Estensioni PHP richieste
        $required_extensions = array('json', 'mbstring', 'curl');
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $checks[] = "Estensione PHP '$ext' mancante";
            }
        }
        
        // Plugin principale attivo
        if (!class_exists('AffiliateManagerAI')) {
            $checks[] = 'Plugin principale Affiliate Manager AI non attivo';
        }
        
        // Database connection
        if (!$this->check_database_connection()) {
            $checks[] = 'Connessione database fallita';
        }
        
        if (!empty($checks)) {
            $this->init_errors = array_merge($this->init_errors, $checks);
            return false;
        }
        
        return true;
    }
    
    /**
     * Verifica connessione database
     */
    private function check_database_connection() {
        global $wpdb;
        
        try {
            $result = $wpdb->get_var("SELECT 1");
            return $result == 1;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Verifica requisiti aggiuntivi
     */
    public function check_requirements() {
        // Solo per admin
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Controlla permessi cartella
        $content_analyzer_dir = ALMA_PLUGIN_DIR . 'content-analyzer/';
        if (!is_writable(dirname($content_analyzer_dir))) {
            $this->init_errors[] = 'Cartella plugin non scrivibile';
        }
        
        // Controlla AJAX endpoint
        if (!$this->test_ajax_endpoint()) {
            $this->init_errors[] = 'Endpoint AJAX non raggiungibile';
        }
        
        // Controlla nonce system
        if (!wp_verify_nonce(wp_create_nonce('test'), 'test')) {
            $this->init_errors[] = 'Sistema nonce malfunzionante';
        }
    }
    
    /**
     * Test AJAX endpoint
     */
    private function test_ajax_endpoint() {
        $ajax_url = admin_url('admin-ajax.php');
        
        // Test semplice con wp_remote_get
        $response = wp_remote_get($ajax_url . '?action=heartbeat', array(
            'timeout' => 5,
            'sslverify' => false
        ));
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Crea tabelle database necessarie
     */
    public function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabella analytics Content Analyzer
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            analysis_data longtext,
            suggestions_generated int(11) DEFAULT 0,
            suggestions_inserted int(11) DEFAULT 0,
            inserted_data longtext,
            analysis_timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            insertion_timestamp datetime NULL,
            user_id bigint(20) DEFAULT 0,
            content_hash varchar(32) DEFAULT '',
            analyzer_version varchar(10) DEFAULT '2.0.0',
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY analysis_timestamp (analysis_timestamp),
            KEY content_hash (content_hash),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verifica creazione tabella
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_exists) {
            $this->log_database_action('Tabella analytics creata con successo');
        } else {
            $this->init_errors[] = 'Errore creazione tabella analytics';
        }
        
        // Aggiorna versione database
        update_option('alma_content_analyzer_db_version', '2.0.0');
    }
    
    /**
     * Imposta opzioni di default Enhanced Analyzer
     */
    public function set_default_options() {
        $default_options = array(
            'alma_analyzer_enabled' => true,
            'alma_analyzer_max_suggestions' => 5,
            'alma_analyzer_auto_insert' => false,
            'alma_analyzer_min_content_length' => 100,
            'alma_analyzer_cache_enabled' => true,
            'alma_analyzer_cache_duration' => 3600, // 1 ora
            'alma_analyzer_log_enabled' => true,
            'alma_analyzer_debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'alma_analyzer_api_timeout' => 30,
            'alma_analyzer_version' => $this->version
        );
        
        foreach ($default_options as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
        
        $this->log_initialization('defaults_set');
    }
    
    /**
     * Controlla upgrade versione
     */
    public function check_version_upgrade() {
        $current_version = get_option('alma_analyzer_version', '1.0.0');
        
        if (version_compare($current_version, $this->version, '<')) {
            $this->perform_upgrade($current_version, $this->version);
        }
    }
    
    /**
     * Esegui upgrade
     */
    private function perform_upgrade($from_version, $to_version) {
        // Backup opzioni esistenti
        $this->backup_options();
        
        // Aggiornamenti specifici per versione
        if (version_compare($from_version, '2.0.0', '<')) {
            $this->upgrade_to_2_0_0();
        }
        
        // Aggiorna versione
        update_option('alma_analyzer_version', $to_version);
        
        $this->log_initialization('upgrade', "Aggiornato da $from_version a $to_version");
    }
    
    /**
     * Upgrade specifico per v2.0.0
     */
    private function upgrade_to_2_0_0() {
        // Crea nuove tabelle
        $this->create_database_tables();
        
        // Migra impostazioni legacy se esistenti
        $legacy_options = array(
            'alma_content_analyzer_enabled' => 'alma_analyzer_enabled',
            'alma_max_suggestions' => 'alma_analyzer_max_suggestions'
        );
        
        foreach ($legacy_options as $old_key => $new_key) {
            $old_value = get_option($old_key);
            if ($old_value !== false && get_option($new_key) === false) {
                update_option($new_key, $old_value);
            }
        }
        
        // Pulisci cache legacy
        $this->clear_legacy_cache();
    }
    
    /**
     * Backup opzioni
     */
    private function backup_options() {
        $backup_data = array();
        $analyzer_options = $this->get_analyzer_options();
        
        foreach ($analyzer_options as $option) {
            $backup_data[$option] = get_option($option);
        }
        
        update_option('alma_analyzer_options_backup', $backup_data);
        update_option('alma_analyzer_backup_timestamp', current_time('mysql'));
    }
    
    /**
     * Ottieni lista opzioni analyzer
     */
    private function get_analyzer_options() {
        global $wpdb;
        
        $options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE 'alma_analyzer_%' 
             OR option_name LIKE 'alma_content_analyzer_%'"
        );
        
        return $options ?: array();
    }
    
    /**
     * Pulisci cache legacy
     */
    private function clear_legacy_cache() {
        // Transients legacy
        $legacy_transients = array(
            'alma_analyzer_cache_',
            'alma_content_analysis_',
            'alma_suggestions_'
        );
        
        global $wpdb;
        foreach ($legacy_transients as $prefix) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                '_transient_' . $prefix . '%'
            ));
        }
    }
    
    /**
     * Display avvisi amministratore
     */
    public function display_admin_notices() {
        if (empty($this->init_errors) || !current_user_can('manage_options')) {
            return;
        }
        
        $class = 'notice notice-error';
        $title = 'Affiliate Link Manager AI - Enhanced Content Analyzer';
        
        echo '<div class="' . esc_attr($class) . '">';
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<p><strong>Errori di inizializzazione:</strong></p>';
        echo '<ul>';
        foreach ($this->init_errors as $error) {
            echo '<li>• ' . esc_html($error) . '</li>';
        }
        echo '</ul>';
        echo '<p><em>Il Content Analyzer Enhanced potrebbe non funzionare correttamente.</em></p>';
        echo '</div>';
    }
    
    /**
     * Log azioni inizializzazione
     */
    private function log_initialization($action, $details = '') {
        if (!get_option('alma_analyzer_log_enabled', true)) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'action' => $action,
            'details' => $details,
            'version' => $this->version,
            'user_id' => get_current_user_id(),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'
        );
        
        // Log su file se WP_DEBUG attivo
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('ALMA Enhanced Content Analyzer [' . $action . ']: ' . $details);
        }
        
        // Salva in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        
        // Controlla se tabella esiste prima di loggare
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $wpdb->insert($table_name, array(
                'post_id' => 0, // Log di sistema
                'analysis_data' => json_encode($log_entry),
                'user_id' => get_current_user_id(),
                'analyzer_version' => $this->version
            ));
        }
    }
    
    /**
     * Log azioni database
     */
    private function log_database_action($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('ALMA Enhanced Content Analyzer DB: ' . $message);
        }
    }
    
    /**
     * Debug info (solo se WP_DEBUG attivo)
     */
    public function debug_info() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $debug_data = array(
            'version' => $this->version,
            'loaded' => class_exists('ALMA_Enhanced_ContentAnalyzer'),
            'errors' => $this->init_errors,
            'required_files_exist' => $this->verify_required_files(),
            'database_version' => get_option('alma_content_analyzer_db_version', 'unknown'),
            'options_set' => !empty($this->get_analyzer_options())
        );
        
        echo '<!-- ALMA Enhanced Content Analyzer Debug -->';
        echo '<script type="application/json" id="alma-debug-data">';
        echo json_encode($debug_data, JSON_PRETTY_PRINT);
        echo '</script>';
        echo '<!-- /ALMA Enhanced Content Analyzer Debug -->';
    }
    
    /**
     * Funzioni di utilità per ispezione stato
     */
    public function get_initialization_status() {
        return array(
            'version' => $this->version,
            'loaded' => class_exists('ALMA_Enhanced_ContentAnalyzer'),
            'errors' => $this->init_errors,
            'database_ready' => $this->is_database_ready(),
            'files_verified' => empty($this->init_errors)
        );
    }
    
    /**
     * Controlla se database è pronto
     */
    private function is_database_ready() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Metodo per reset completo (solo per sviluppo)
     */
    public function reset_analyzer($confirm = false) {
        if (!$confirm || !current_user_can('manage_options')) {
            return false;
        }
        
        // Rimuovi opzioni
        $options = $this->get_analyzer_options();
        foreach ($options as $option) {
            delete_option($option);
        }
        
        // Rimuovi tabelle
        global $wpdb;
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        // Re-inizializza
        $this->set_default_options();
        $this->create_database_tables();
        
        return true;
    }
}

// Inizializza Enhanced Content Analyzer
ALMA_Enhanced_ContentAnalyzer_Init::get_instance();

// Hook per compatibilità legacy
do_action('alma_content_analyzer_init_loaded');

// Funzioni helper globali
if (!function_exists('alma_get_analyzer_status')) {
    function alma_get_analyzer_status() {
        $init = ALMA_Enhanced_ContentAnalyzer_Init::get_instance();
        return $init->get_initialization_status();
    }
}

if (!function_exists('alma_is_analyzer_ready')) {
    function alma_is_analyzer_ready() {
        $status = alma_get_analyzer_status();
        return $status['loaded'] && empty($status['errors']) && $status['database_ready'];
    }
}
?>