<?php
/**
 * Enhanced AI Content Analyzer
 * Versione 2.0.0 - Analisi intelligente e suggerimenti link affiliati automatici
 * 
 * Features v2.0.0:
 * - Analisi AI del contenuto dell'articolo
 * - Suggerimenti link affiliati pertinenti
 * - Inserimento automatico con un click
 * - Controllo numero link da inserire
 * - Machine learning per migliorare suggerimenti
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe principale Enhanced Content Analyzer
 */
class ALMA_Enhanced_ContentAnalyzer {
    
    private $version = '2.0.0';
    private $plugin_dir;
    private $plugin_url;
    
    public function __construct() {
        $this->plugin_dir = ALMA_PLUGIN_DIR . 'content-analyzer/';
        $this->plugin_url = ALMA_PLUGIN_URL . 'content-analyzer/';
        
        // Hook inizializzazione
        add_action('admin_init', array($this, 'init'));
        add_action('add_meta_boxes', array($this, 'add_content_analyzer_metabox'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX hooks per nuove funzionalità v2.0.0
        add_action('wp_ajax_alma_analyze_content_ai', array($this, 'ajax_analyze_content'));
        add_action('wp_ajax_alma_insert_suggested_links', array($this, 'ajax_insert_links'));
        add_action('wp_ajax_alma_get_affiliate_links', array($this, 'ajax_get_available_links'));
        
        // Hook per salvare impostazioni
        add_action('wp_ajax_alma_save_analyzer_settings', array($this, 'save_analyzer_settings'));
    }
    
    /**
     * Inizializzazione plugin
     */
    public function init() {
        // Registra impostazioni
        register_setting('alma_content_analyzer', 'alma_analyzer_enabled', array(
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        
        register_setting('alma_content_analyzer', 'alma_analyzer_max_suggestions', array(
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => array($this, 'sanitize_max_suggestions')
        ));
        
        register_setting('alma_content_analyzer', 'alma_analyzer_auto_insert', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        
        register_setting('alma_content_analyzer', 'alma_analyzer_min_content_length', array(
            'type' => 'integer',
            'default' => 100,
            'sanitize_callback' => array($this, 'sanitize_min_length')
        ));
        
        // Crea tabella per analytics se non esistente
        $this->create_analytics_table();
    }
    
    /**
     * Aggiungi metabox nell'editor
     */
    public function add_content_analyzer_metabox() {
        $post_types = array('post', 'page');
        $post_types = apply_filters('alma_content_analyzer_post_types', $post_types);
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'alma-content-analyzer-enhanced',
                '🤖 AI Content Analyzer v2.0',
                array($this, 'render_metabox'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    /**
     * Carica script e stili
     */
    public function enqueue_scripts($hook) {
        // Solo nelle pagine di editing
        if (!in_array($hook, array('post.php', 'post-new.php', 'page.php', 'page-new.php'))) {
            return;
        }
        
        // CSS Enhanced
        wp_enqueue_style(
            'alma-enhanced-analyzer-css',
            $this->plugin_url . 'enhanced-analyzer.css',
            array(),
            $this->version
        );
        
        // JavaScript Enhanced
        wp_enqueue_script(
            'alma-enhanced-analyzer-js',
            $this->plugin_url . 'enhanced-analyzer.js',
            array('jquery', 'wp-util'),
            $this->version,
            true
        );
        
        // Localizzazione script
        wp_localize_script('alma-enhanced-analyzer-js', 'almaAnalyzer', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alma_content_analyzer_nonce'),
            'postId' => get_the_ID(),
            'messages' => array(
                'analyzing' => __('🔍 Analizzando contenuto...', 'affiliate-link-manager-ai'),
                'generating' => __('🤖 Generando suggerimenti AI...', 'affiliate-link-manager-ai'),
                'inserting' => __('✨ Inserimento link automatico...', 'affiliate-link-manager-ai'),
                'success' => __('✅ Operazione completata!', 'affiliate-link-manager-ai'),
                'error' => __('❌ Errore durante l\'operazione', 'affiliate-link-manager-ai'),
                'noContent' => __('⚠️ Contenuto troppo breve per l\'analisi', 'affiliate-link-manager-ai'),
                'noSuggestions' => __('ℹ️ Nessun suggerimento pertinente trovato', 'affiliate-link-manager-ai'),
                'confirmInsert' => __('Confermi l\'inserimento di {count} link affiliati?', 'affiliate-link-manager-ai')
            ),
            'settings' => array(
                'maxSuggestions' => get_option('alma_analyzer_max_suggestions', 5),
                'minContentLength' => get_option('alma_analyzer_min_content_length', 100),
                'autoInsert' => get_option('alma_analyzer_auto_insert', false)
            )
        ));
    }
    
/**
     * Render metabox - VERSIONE ULTRA SICURA
     */
    public function render_metabox($post) {
        // Verifica critica che $post esista
        if (!$post || !is_object($post) || !isset($post->ID)) {
            echo '<div style="padding: 20px; background: #fee; border: 1px solid #fcc;">';
            echo '<h4>❌ Errore Widget</h4>';
            echo '<p>Oggetto post non valido. Prova a ricaricare la pagina.</p>';
            echo '</div>';
            return;
        }

        // Path template con controlli multipli
        $template_path = $this->plugin_dir . 'templates/enhanced-widget.php';
        
        // Verifica file template esista
        if (!file_exists($template_path)) {
            echo '<div style="padding: 20px; background: #fee; border: 1px solid #fcc;">';
            echo '<h4>❌ Template Non Trovato</h4>';
            echo '<p>File mancante: <code>' . esc_html($template_path) . '</code></p>';
            echo '<p>Verifica che il file esista nella cartella corretta.</p>';
            echo '</div>';
            return;
        }

        // Verifica file leggibile
        if (!is_readable($template_path)) {
            echo '<div style="padding: 20px; background: #fee; border: 1px solid #fcc;">';
            echo '<h4>❌ Permessi File</h4>';
            echo '<p>Impossibile leggere: <code>' . esc_html($template_path) . '</code></p>';
            echo '<p>Controlla i permessi del file (dovrebbero essere 644).</p>';
            echo '</div>';
            return;
        }

        // Caricamento template con massima sicurezza
        try {
            // Output buffering per catturare errori
            ob_start();
            
            // Include con controllo errori
            $include_result = include $template_path;
            
            // Se include fallisce
            if ($include_result === false) {
                ob_end_clean();
                throw new Exception('Include fallito per template');
            }
            
            // Ottieni output
            $template_output = ob_get_clean();
            
            // Verifica che ci sia output
            if (empty(trim($template_output))) {
                throw new Exception('Template non ha prodotto output');
            }
            
            // Stampa output finale
            echo $template_output;
            
        } catch (Exception $e) {
            // Pulizia buffer se necessario
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Errore con dettagli per debug
            echo '<div style="padding: 20px; background: #fee; border: 1px solid #fcc;">';
            echo '<h4>❌ Errore Caricamento Template</h4>';
            echo '<p><strong>Errore:</strong> ' . esc_html($e->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> <code>' . esc_html($template_path) . '</code></p>';
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                echo '<div style="background: #f0f0f0; padding: 10px; margin: 10px 0; font-family: monospace; font-size: 11px;">';
                echo '<strong>Stack Trace:</strong><br>';
                echo nl2br(esc_html($e->getTraceAsString()));
                echo '</div>';
            }
            
            echo '<p><em>Controlla i log di errore per maggiori dettagli.</em></p>';
            echo '</div>';
            
            // Log errore
            if (function_exists('error_log')) {
                error_log('ALMA Widget Error: ' . $e->getMessage() . ' in ' . $template_path);
            }
        }
    }
    
    /**
     * AJAX: Analizza contenuto con AI
     */
    public function ajax_analyze_content() {
        // Verifica sicurezza
        if (!check_ajax_referer('alma_content_analyzer_nonce', 'nonce', false)) {
            wp_send_json_error('Richiesta non autorizzata');
            return;
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permessi insufficienti');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $content = sanitize_textarea_field($_POST['content']);
        $max_suggestions = intval($_POST['max_suggestions']);
        
        // Validazione contenuto
        if (strlen(trim($content)) < get_option('alma_analyzer_min_content_length', 100)) {
            wp_send_json_error('Contenuto troppo breve per l\'analisi AI');
            return;
        }
        
        try {
            // Analisi AI del contenuto
            $analysis_result = $this->analyze_content_with_ai($content);
            
            // Genera suggerimenti pertinenti
            $suggestions = $this->generate_link_suggestions($analysis_result, $max_suggestions);
            
            // Salva analytics
            $this->save_analysis_analytics($post_id, $analysis_result, count($suggestions));
            
            wp_send_json_success(array(
                'analysis' => $analysis_result,
                'suggestions' => $suggestions,
                'total_suggestions' => count($suggestions),
                'timestamp' => current_time('mysql')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Errore durante l\'analisi: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Inserisci link suggeriti automaticamente
     */
    public function ajax_insert_links() {
        // Verifica sicurezza
        if (!check_ajax_referer('alma_content_analyzer_nonce', 'nonce', false)) {
            wp_send_json_error('Richiesta non autorizzata');
            return;
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permessi insufficienti');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $content = stripslashes($_POST['content']);
        $selected_suggestions = json_decode(stripslashes($_POST['suggestions']), true);
        
        if (!is_array($selected_suggestions) || empty($selected_suggestions)) {
            wp_send_json_error('Nessun suggerimento selezionato');
            return;
        }
        
        try {
            // Inserisci link nel contenuto
            $modified_content = $this->insert_affiliate_links($content, $selected_suggestions);
            
            // Salva statistiche inserimento
            $this->save_insertion_analytics($post_id, $selected_suggestions);
            
            wp_send_json_success(array(
                'modified_content' => $modified_content,
                'inserted_count' => count($selected_suggestions),
                'timestamp' => current_time('mysql')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Errore durante l\'inserimento: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Ottieni link affiliati disponibili
     */
    public function ajax_get_available_links() {
        if (!check_ajax_referer('alma_content_analyzer_nonce', 'nonce', false)) {
            wp_send_json_error('Richiesta non autorizzata');
            return;
        }
        
        $available_links = $this->get_available_affiliate_links();
        
        wp_send_json_success(array(
            'links' => $available_links,
            'total' => count($available_links)
        ));
    }
    
    /**
     * Analisi AI del contenuto
     */
    private function analyze_content_with_ai($content) {
        // Estrazione keyword e temi principali
        $keywords = $this->extract_keywords($content);
        $themes = $this->identify_content_themes($content);
        $sentiment = $this->analyze_sentiment($content);
        
        // Analisi struttura contenuto
        $word_count = str_word_count(strip_tags($content));
        $readability = $this->calculate_readability_score($content);
        
        return array(
            'keywords' => $keywords,
            'themes' => $themes,
            'sentiment' => $sentiment,
            'word_count' => $word_count,
            'readability_score' => $readability,
            'analysis_timestamp' => current_time('mysql'),
            'content_hash' => md5($content)
        );
    }
    
    /**
     * Genera suggerimenti link affiliati pertinenti
     */
    private function generate_link_suggestions($analysis, $max_suggestions = 5) {
        $available_links = $this->get_available_affiliate_links();
        $suggestions = array();
        
        if (empty($available_links)) {
            return $suggestions;
        }
        
        foreach ($available_links as $link) {
            // Calcola pertinenza del link con il contenuto
            $relevance_score = $this->calculate_link_relevance($link, $analysis);
            
            if ($relevance_score > 0.3) { // Soglia minima pertinenza
                $suggestions[] = array(
                    'link_id' => $link['id'],
                    'title' => $link['title'],
                    'url' => $link['url'],
                    'shortcode' => $link['shortcode'],
                    'relevance_score' => $relevance_score,
                    'suggested_anchor' => $this->suggest_anchor_text($link, $analysis),
                    'reason' => $this->explain_suggestion_reason($link, $analysis),
                    'ai_score' => $link['ai_score']
                );
            }
        }
        
        // Ordina per pertinenza decrescente
        usort($suggestions, function($a, $b) {
            return $b['relevance_score'] <=> $a['relevance_score'];
        });
        
        // Limita al numero massimo richiesto
        return array_slice($suggestions, 0, $max_suggestions);
    }
    
    /**
     * Inserisci link affiliati nel contenuto
     */
    private function insert_affiliate_links($content, $suggestions) {
        $modified_content = $content;
        
        foreach ($suggestions as $suggestion) {
            $link_id = $suggestion['link_id'];
            $anchor_text = $suggestion['anchor_text'];
            $position = $suggestion['position']; // Posizione nel contenuto
            
            // Crea shortcode con anchor text personalizzato
            $shortcode = '[affiliate_link id="' . $link_id . '" anchor="' . esc_attr($anchor_text) . '"]';
            
            // Inserisci nel contenuto nella posizione ottimale
            $modified_content = $this->smart_insert_at_position($modified_content, $shortcode, $position);
        }
        
        return $modified_content;
    }
    
    /**
     * Ottieni link affiliati disponibili con dati completi
     */
    private function get_available_affiliate_links() {
        $query = new WP_Query(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        $links = array();
        
        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $affiliate_url = get_post_meta($post_id, '_affiliate_url', true);
                $click_count = get_post_meta($post_id, '_click_count', true) ?: 0;
                
                // Ottieni categorie/tipologie
                $types = wp_get_post_terms($post_id, 'affiliate_type');
                $type_names = !is_wp_error($types) ? wp_list_pluck($types, 'name') : array();
                
                // Calcola AI score (da plugin principale)
                $ai_score = method_exists('AffiliateManagerAI', 'calculate_ai_performance_score') ? 
                           AffiliateManagerAI::calculate_ai_performance_score($post_id) : 0;
                
                $links[] = array(
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'url' => $affiliate_url,
                    'shortcode' => '[affiliate_link id="' . $post_id . '"]',
                    'clicks' => $click_count,
                    'types' => $type_names,
                    'ai_score' => $ai_score,
                    'content' => get_post_field('post_content', $post_id),
                    'excerpt' => get_post_field('post_excerpt', $post_id)
                );
            }
        }
        
        return $links;
    }
    
    /**
     * Estrai keyword dal contenuto
     */
    private function extract_keywords($content, $max_keywords = 10) {
        // Rimuovi HTML e normalizza
        $text = strip_tags($content);
        $text = strtolower($text);
        
        // Rimuovi stop words italiane comuni
        $stop_words = array('il', 'la', 'di', 'che', 'e', 'a', 'un', 'per', 'in', 'con', 'su', 'da', 'del', 'della', 'dei', 'delle', 'questo', 'questa', 'come', 'ma', 'se', 'non', 'o', 'anche', 'più', 'molto', 'tutto', 'tutti', 'quando', 'dove', 'mentre', 'però');
        
        // Estrai parole
        $words = str_word_count($text, 1);
        $word_freq = array_count_values($words);
        
        // Filtra stop words e parole troppo corte
        $filtered_words = array();
        foreach ($word_freq as $word => $freq) {
            if (strlen($word) > 3 && !in_array($word, $stop_words) && $freq > 1) {
                $filtered_words[$word] = $freq;
            }
        }
        
        // Ordina per frequenza
        arsort($filtered_words);
        
        return array_slice(array_keys($filtered_words), 0, $max_keywords);
    }
    
    /**
     * Identifica temi principali del contenuto
     */
    private function identify_content_themes($content) {
        $themes = array();
        $text = strtolower(strip_tags($content));
        
        // Categorie tematiche predefinite con parole chiave
        $theme_categories = array(
            'tecnologia' => array('computer', 'software', 'app', 'smartphone', 'digitale', 'online', 'internet', 'tech', 'innovazione', 'ai', 'intelligenza', 'artificiale'),
            'salute' => array('salute', 'benessere', 'fitness', 'sport', 'medicina', 'dottore', 'cura', 'rimedio', 'integratore', 'vitamina'),
            'cucina' => array('ricetta', 'cucina', 'cucinare', 'cibo', 'ingrediente', 'chef', 'ristorante', 'mangiare', 'piatto', 'sapore'),
            'viaggi' => array('viaggio', 'vacanza', 'hotel', 'aereo', 'turismo', 'destinazione', 'città', 'paese', 'cultura', 'esperienza'),
            'casa' => array('casa', 'arredamento', 'mobile', 'decorazione', 'giardino', 'pulizia', 'ristrutturazione', 'design', 'interno'),
            'moda' => array('moda', 'vestito', 'abbigliamento', 'scarpe', 'accessorio', 'stile', 'tendenza', 'marca', 'fashion', 'look'),
            'finance' => array('soldi', 'denaro', 'investimento', 'risparmio', 'banca', 'prestito', 'economia', 'budget', 'finanza', 'costo')
        );
        
        // Conta occorrenze per tema
        foreach ($theme_categories as $theme => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($text, $keyword);
            }
            
            if ($score > 0) {
                $themes[$theme] = $score;
            }
        }
        
        // Ordina per rilevanza
        arsort($themes);
        
        return array_slice(array_keys($themes), 0, 3); // Top 3 temi
    }
    
    /**
     * Calcola pertinenza link con contenuto
     */
    private function calculate_link_relevance($link, $analysis) {
        $relevance_score = 0.0;
        
        // Match keywords (30% del punteggio)
        $link_text = strtolower($link['title'] . ' ' . $link['content'] . ' ' . implode(' ', $link['types']));
        foreach ($analysis['keywords'] as $keyword) {
            if (strpos($link_text, $keyword) !== false) {
                $relevance_score += 0.3 / count($analysis['keywords']);
            }
        }
        
        // Match temi (40% del punteggio)
        foreach ($analysis['themes'] as $theme) {
            if (strpos($link_text, $theme) !== false) {
                $relevance_score += 0.4 / count($analysis['themes']);
            }
        }
        
        // AI score del link (20% del punteggio)
        $relevance_score += ($link['ai_score'] / 100) * 0.2;
        
        // Performance storica (10% del punteggio)
        $click_performance = min(1.0, $link['clicks'] / 100); // Normalizza su 100 click
        $relevance_score += $click_performance * 0.1;
        
        return min(1.0, $relevance_score);
    }
    
    /**
     * Suggerisci anchor text per il link
     */
    private function suggest_anchor_text($link, $analysis) {
        // Estrai potenziali anchor text dalle keyword più rilevanti
        $potential_anchors = array();
        
        // Usa titolo del link come base
        $potential_anchors[] = $link['title'];
        
        // Usa keyword più pertinenti
        foreach (array_slice($analysis['keywords'], 0, 3) as $keyword) {
            $potential_anchors[] = ucfirst($keyword);
            $potential_anchors[] = "migliori " . $keyword;
            $potential_anchors[] = $keyword . " consigliati";
        }
        
        // Seleziona il migliore (per ora il primo)
        return !empty($potential_anchors) ? $potential_anchors[0] : $link['title'];
    }
    
    /**
     * Spiega perché il link è stato suggerito
     */
    private function explain_suggestion_reason($link, $analysis) {
        $reasons = array();
        
        // Verifica match keyword
        $link_text = strtolower($link['title'] . ' ' . implode(' ', $link['types']));
        $matched_keywords = array();
        foreach ($analysis['keywords'] as $keyword) {
            if (strpos($link_text, $keyword) !== false) {
                $matched_keywords[] = $keyword;
            }
        }
        
        if (!empty($matched_keywords)) {
            $reasons[] = "Contiene keyword: " . implode(', ', array_slice($matched_keywords, 0, 3));
        }
        
        // Verifica performance
        if ($link['ai_score'] > 70) {
            $reasons[] = "Alta performance AI (" . $link['ai_score'] . "%)";
        }
        
        if ($link['clicks'] > 50) {
            $reasons[] = "Popolare (" . $link['clicks'] . " click)";
        }
        
        return !empty($reasons) ? implode(" • ", $reasons) : "Pertinenza generale con il contenuto";
    }
    
    /**
     * Analizza sentiment (semplificato)
     */
    private function analyze_sentiment($content) {
        $positive_words = array('ottimo', 'fantastico', 'eccellente', 'perfetto', 'consiglio', 'migliore', 'fantastico', 'incredibile', 'amore', 'felice');
        $negative_words = array('pessimo', 'terribile', 'orribile', 'sconsiglio', 'peggiore', 'odio', 'triste', 'male', 'problema', 'difetto');
        
        $text = strtolower(strip_tags($content));
        $positive_count = 0;
        $negative_count = 0;
        
        foreach ($positive_words as $word) {
            $positive_count += substr_count($text, $word);
        }
        
        foreach ($negative_words as $word) {
            $negative_count += substr_count($text, $word);
        }
        
        if ($positive_count > $negative_count) {
            return 'positive';
        } elseif ($negative_count > $positive_count) {
            return 'negative';
        }
        
        return 'neutral';
    }
    
    /**
     * Calcola readability score (semplificato)
     */
    private function calculate_readability_score($content) {
        $text = strip_tags($content);
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = str_word_count($text);
        $syllables = $this->count_syllables($text);
        
        if (count($sentences) == 0 || $words == 0) {
            return 0;
        }
        
        // Formula Flesch semplificata
        $avg_sentence_length = $words / count($sentences);
        $avg_syllables = $syllables / $words;
        
        $score = 206.835 - (1.015 * $avg_sentence_length) - (84.6 * $avg_syllables);
        
        return max(0, min(100, round($score, 1)));
    }
    
    /**
     * Conta sillabe approssimative
     */
    private function count_syllables($text) {
        $text = strtolower(strip_tags($text));
        $words = str_word_count($text, 1);
        $syllables = 0;
        
        foreach ($words as $word) {
            // Conta vocali come approssimazione sillabe
            $syllables += preg_match_all('/[aeiou]/i', $word);
        }
        
        return max(1, $syllables); // Minimo 1 sillaba per parola
    }
    
    /**
     * Inserimento intelligente nel contenuto
     */
    private function smart_insert_at_position($content, $shortcode, $position_hint = 'middle') {
        // Per ora inserimento semplice alla fine del paragrafo
        $paragraphs = explode('</p>', $content);
        
        if (count($paragraphs) < 2) {
            return $content . "\n\n" . $shortcode;
        }
        
        $insert_position = intval(count($paragraphs) / 2); // Metà del contenuto
        
        $paragraphs[$insert_position] .= "\n\n" . $shortcode . "\n";
        
        return implode('</p>', $paragraphs);
    }
    
    /**
     * Salva analytics analisi
     */
    private function save_analysis_analytics($post_id, $analysis, $suggestions_count) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        
        $wpdb->insert($table_name, array(
            'post_id' => $post_id,
            'analysis_data' => json_encode($analysis),
            'suggestions_generated' => $suggestions_count,
            'analysis_timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ));
    }
    
    /**
     * Salva analytics inserimento
     */
    private function save_insertion_analytics($post_id, $inserted_suggestions) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        
        // Aggiorna record esistente o crea nuovo
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE post_id = %d ORDER BY analysis_timestamp DESC LIMIT 1",
            $post_id
        ));
        
        if ($existing) {
            $wpdb->update(
                $table_name,
                array(
                    'suggestions_inserted' => count($inserted_suggestions),
                    'inserted_data' => json_encode($inserted_suggestions),
                    'insertion_timestamp' => current_time('mysql')
                ),
                array('id' => $existing)
            );
        }
    }
    
    /**
     * Crea tabella analytics se non esiste
     */
    private function create_analytics_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'alma_content_analytics';
        
        $charset_collate = $wpdb->get_charset_collate();
        
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
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY analysis_timestamp (analysis_timestamp)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Sanitize max suggestions
     */
    public function sanitize_max_suggestions($input) {
        $value = intval($input);
        return ($value >= 1 && $value <= 20) ? $value : 5;
    }
    
    /**
     * Sanitize min length
     */
    public function sanitize_min_length($input) {
        $value = intval($input);
        return ($value >= 50 && $value <= 1000) ? $value : 100;
    }
    
    /**
     * Salva impostazioni
     */
    public function save_analyzer_settings() {
        if (!check_ajax_referer('alma_content_analyzer_nonce', 'nonce', false)) {
            wp_send_json_error('Richiesta non autorizzata');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
            return;
        }
        
        $settings = array(
            'alma_analyzer_enabled' => isset($_POST['enabled']) ? rest_sanitize_boolean($_POST['enabled']) : false,
            'alma_analyzer_max_suggestions' => $this->sanitize_max_suggestions($_POST['max_suggestions']),
            'alma_analyzer_auto_insert' => isset($_POST['auto_insert']) ? rest_sanitize_boolean($_POST['auto_insert']) : false,
            'alma_analyzer_min_content_length' => $this->sanitize_min_length($_POST['min_length'])
        );
        
        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }
        
        wp_send_json_success(array(
            'message' => 'Impostazioni salvate con successo',
            'settings' => $settings
        ));
    }
}

// Inizializza Content Analyzer se plugin principale è attivo
if (class_exists('AffiliateManagerAI')) {
    new ALMA_Enhanced_ContentAnalyzer();
}
?>