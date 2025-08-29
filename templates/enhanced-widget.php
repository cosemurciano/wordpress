<?php
/**
 * Enhanced Widget AI Content Analyzer - CORREZIONE INSERIMENTO
 * Versione 2.0 - Fix inserimento link affiliati nell'editor
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// INIZIALIZZAZIONE SICURA
// =============================================================================

$post_id = (isset($post) && is_object($post)) ? $post->ID : 0;
$enabled = get_option('alma_analyzer_enabled', true);

$current_content = '';
$content_length = 0;

if ($post_id) {
    $current_content = get_post_field('post_content', $post_id);
    if (is_wp_error($current_content)) {
        $current_content = '';
    }
    $content_length = strlen(strip_tags($current_content));
}

$max_suggestions = (int) get_option('alma_analyzer_max_suggestions', 5);
$min_length = (int) get_option('alma_analyzer_min_content_length', 100);

if ($max_suggestions < 1 || $max_suggestions > 20) $max_suggestions = 5;
if ($min_length < 50 || $min_length > 1000) $min_length = 100;

// =============================================================================
// OTTIENI LINK AFFILIATI REALI CON DATI COMPLETI
// =============================================================================

$available_links = array();
$available_links_count = 0;

try {
    $query = new WP_Query(array(
        'post_type' => 'affiliate_link',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'no_found_rows' => true
    ));
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $link_id = get_the_ID();
            
            // Ottieni metadati
            $affiliate_url = get_post_meta($link_id, '_affiliate_url', true);
            $click_count = get_post_meta($link_id, '_click_count', true) ?: 0;
            $usage_count = 0;
            
            // Conta utilizzi negli articoli
            global $wpdb;
            $shortcode_usage = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                 WHERE post_content LIKE %s 
                 AND post_status = 'publish'
                 AND post_type IN ('post', 'page')",
                '%[affiliate_link id="' . $link_id . '"]%'
            ));
            $usage_count = (int) $shortcode_usage;
            
            // Ottieni tipologie/categorie
            $types = wp_get_post_terms($link_id, 'affiliate_type');
            $type_names = array();
            if (!is_wp_error($types) && !empty($types)) {
                $type_names = wp_list_pluck($types, 'name');
            }
            
            // Dati completi per debug e matching
            $link_title = get_the_title($link_id);
            $link_content = get_the_content();
            $link_excerpt = get_the_excerpt($link_id);
            
            $available_links[] = array(
                'id' => $link_id,
                'title' => $link_title,
                'url' => $affiliate_url,
                'clicks' => $click_count,
                'usage' => $usage_count,
                'types' => $type_names,
                'excerpt' => $link_excerpt,
                'content' => $link_content,
                // AGGIUNTO: Dati per matching ottimizzato
                'title_words' => array_filter(explode(' ', strtolower($link_title))),
                'search_text' => strtolower($link_title . ' ' . implode(' ', $type_names) . ' ' . $link_excerpt)
            );
        }
        $available_links_count = count($available_links);
    }
    wp_reset_postdata();
} catch (Exception $e) {
    $available_links = array();
    $available_links_count = 0;
}

$widget_status = 'ready';
$widget_status_text = '✅ Pronto';
$widget_status_class = 'status-ready';

if (!$enabled) {
    $widget_status = 'disabled';
    $widget_status_text = '❌ Disabilitato';
    $widget_status_class = 'status-disabled';
} elseif ($content_length < $min_length) {
    $widget_status = 'warning';
    $widget_status_text = '⚠️ Contenuto breve';
    $widget_status_class = 'status-warning';
}

?>

<!-- CSS SNELLITO -->
<style>
.alma-analyzer-widget {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    border-left: 4px solid #667eea;
}

.alma-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee;
}

.alma-title {
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.alma-title .alma-icon {
    margin-right: 6px;
}

.status-ready { background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
.status-warning { background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
.status-disabled { background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 11px; }

.alma-config {
    background: #f9fafb;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}

.alma-stats {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
    margin-bottom: 12px;
}

.alma-stat {
    text-align: center;
    padding: 8px;
    background: #667eea;
    color: white;
    border-radius: 6px;
    font-size: 12px;
}

.alma-stat-num {
    display: block;
    font-weight: bold;
    font-size: 16px;
}

.alma-btn-analyze {
    width: 100%;
    padding: 12px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    margin-bottom: 16px;
}

.alma-btn-analyze:disabled {
    background: #9ca3af;
    cursor: not-allowed;
}

.alma-btn-analyze:hover:not(:disabled) {
    background: #5b6bc0;
}

.alma-results {
    border-top: 1px solid #e5e7eb;
    padding-top: 16px;
}

.alma-insights {
    background: #f8fafc;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 12px;
}

.alma-insights h4 {
    margin: 0 0 8px 0;
    font-size: 13px;
    color: #374151;
}

.alma-keyword, .alma-theme {
    display: inline-block;
    background: #e0e7ff;
    color: #3730a3;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 10px;
    margin: 2px;
}

.alma-suggestions {
    margin-bottom: 16px;
}

.alma-suggestion {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 8px;
    background: white;
}

.alma-suggestion:hover {
    border-color: #667eea;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.alma-suggestion.selected {
    border-color: #667eea;
    background: #f0f4ff;
}

.alma-sugg-header {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
}

.alma-sugg-title {
    flex: 1;
    font-weight: 500;
    font-size: 13px;
    margin-left: 8px;
}

.alma-sugg-stats {
    font-size: 11px;
    color: #666;
    margin-left: 8px;
}

.alma-sugg-details {
    margin-top: 8px;
    font-size: 12px;
}

.alma-sugg-types {
    color: #667eea;
    font-weight: 500;
    margin-bottom: 4px;
}

.alma-anchor-input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    margin-top: 4px;
}

.alma-actions {
    background: #f8fafc;
    padding: 12px;
    border-radius: 6px;
    text-align: center;
}

.alma-btn-secondary {
    margin: 0 4px;
    padding: 6px 12px;
    font-size: 12px;
}

.alma-btn-insert {
    width: 100%;
    padding: 10px;
    background: #10b981;
    color: white;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 8px;
}

.alma-btn-insert:hover:not(:disabled) {
    background: #059669;
}

.alma-btn-insert:disabled {
    background: #9ca3af;
}

.alma-spinner {
    width: 12px;
    height: 12px;
    border: 2px solid #ccc;
    border-top: 2px solid #667eea;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    display: inline-block;
    margin-right: 6px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.alma-help {
    font-size: 11px;
    color: #666;
    text-align: center;
    margin-top: 8px;
}

.alma-debug {
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    padding: 10px;
    margin: 10px 0;
    font-family: monospace;
    font-size: 11px;
    color: #92400e;
}

.alma-insertion-mode {
    background: #e0f2fe;
    border: 1px solid #0369a1;
    border-radius: 6px;
    padding: 10px;
    margin: 10px 0;
    text-align: center;
    font-size: 12px;
    color: #0369a1;
}

@media (max-width: 782px) {
    .alma-stats { grid-template-columns: 1fr; gap: 6px; }
    .alma-config { flex-direction: column; gap: 8px; }
}
</style>

<div id="alma-enhanced-analyzer" class="alma-analyzer-widget">
    
    <!-- Header Singolo -->
    <div class="alma-header">
        <div class="alma-title">
            <span class="alma-icon">🤖</span>
            <strong>AI Content Analyzer v2.0</strong>
        </div>
        <div>
            <span class="<?php echo esc_attr($widget_status_class); ?>">
                <?php echo esc_html($widget_status_text); ?>
            </span>
        </div>
    </div>

    <?php if (!$enabled): ?>
        <div style="text-align: center; padding: 20px; color: #666;">
            <p>🔧 Content Analyzer disabilitato</p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=affiliate-ai-settings')); ?>" 
               class="button button-secondary" target="_blank">Abilita</a>
        </div>
        
    <?php elseif (!$post_id): ?>
        <div style="text-align: center; padding: 20px; color: #666;">
            <p>💾 Salva il post per utilizzare l'AI Analyzer</p>
        </div>
        
    <?php else: ?>
        
        <!-- Configurazione -->
        <div class="alma-config">
            <label><strong>🎯 Link da suggerire:</strong></label>
            <select id="alma-max-suggestions" style="padding: 4px;">
                <?php for($i = 1; $i <= 10; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected($max_suggestions, $i); ?>>
                        <?php echo $i; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Statistiche -->
        <div class="alma-stats">
            <div class="alma-stat">
                <span class="alma-stat-num"><?php echo number_format($content_length); ?></span>
                <span>caratteri</span>
            </div>
            <div class="alma-stat">
                <span class="alma-stat-num"><?php echo $available_links_count; ?></span>
                <span>link disponibili</span>
            </div>
            <div class="alma-stat">
                <span class="alma-stat-num" id="alma-suggestions-count">0</span>
                <span>suggerimenti</span>
            </div>
        </div>

        <!-- Modalità Inserimento -->
        <div class="alma-insertion-mode">
            <label style="display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer;">
                <input type="radio" name="alma-insert-mode" value="cursor" checked id="alma-mode-cursor">
                <span>📍 Alla posizione cursore</span>
            </label>
            <label style="display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; margin-top: 4px;">
                <input type="radio" name="alma-insert-mode" value="end" id="alma-mode-end">
                <span>📄 Alla fine del contenuto</span>
            </label>
        </div>

        <!-- Debug Info Links Disponibili -->
        <?php if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('administrator')): ?>
        <div class="alma-debug">
            <strong>🔧 DEBUG - Link Affiliati Trovati (<?php echo $available_links_count; ?>):</strong><br>
            <?php if ($available_links_count > 0): ?>
                <?php foreach (array_slice($available_links, 0, 3) as $link): ?>
                    • ID: <?php echo $link['id']; ?> | Titolo: "<?php echo esc_html($link['title']); ?>" | 
                    Tipologie: [<?php echo implode(', ', $link['types']); ?>] | 
                    Click: <?php echo $link['clicks']; ?> | Utilizzi: <?php echo $link['usage']; ?><br>
                <?php endforeach; ?>
                <?php if ($available_links_count > 3): ?>
                    ... e altri <?php echo $available_links_count - 3; ?> link
                <?php endif; ?>
            <?php else: ?>
                <em>Nessun link affiliato trovato nel database.</em>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($available_links_count === 0): ?>
        <div style="background: #fef3c7; padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 12px;">
            <p style="margin: 0;"><strong>⚠️ Nessun link affiliato disponibile</strong></p>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=affiliate_link')); ?>" 
               class="button button-small" target="_blank">➕ Aggiungi Link</a>
        </div>
        <?php endif; ?>

        <!-- Pulsante Analisi -->
        <button type="button" id="alma-analyze-btn" class="alma-btn-analyze" 
                <?php echo ($content_length < $min_length) ? 'disabled' : ''; ?>>
            <span class="alma-btn-text">🔍 Analizza Contenuto</span>
            <span class="alma-btn-loading" style="display: none;">
                <span class="alma-spinner"></span>Analizzando...
            </span>
        </button>
        
        <?php if ($content_length < $min_length): ?>
            <div class="alma-help">
                ℹ️ Minimo <?php echo $min_length; ?> caratteri (attuali: <?php echo $content_length; ?>)
            </div>
        <?php endif; ?>

        <!-- Risultati Analisi -->
        <div id="alma-results" class="alma-results" style="display: none;">
            
            <!-- Debug Matching -->
            <div id="alma-debug-matching" class="alma-debug" style="display: none;">
                <!-- Debug info popolato via JavaScript -->
            </div>
            
            <!-- Insights -->
            <div class="alma-insights">
                <h4>🧠 Analisi Contenuto</h4>
                <div id="alma-insights-data">
                    <!-- Popolato via JavaScript -->
                </div>
            </div>

            <!-- Suggerimenti -->
            <div class="alma-suggestions">
                <h4 style="font-size: 13px; margin-bottom: 8px;">💡 Suggerimenti Link Affiliati</h4>
                <div id="alma-suggestions-list">
                    <!-- Popolato via JavaScript -->
                </div>
            </div>

            <!-- Azioni -->
            <div id="alma-actions" class="alma-actions" style="display: none;">
                <button type="button" id="alma-select-all" class="button button-secondary alma-btn-secondary">
                    ☑️ Tutti
                </button>
                <button type="button" id="alma-clear-all" class="button button-secondary alma-btn-secondary">
                    ❌ Nessuno
                </button>
                
                <button type="button" id="alma-insert-btn" class="alma-btn-insert">
                    <span class="alma-insert-text">✨ Inserisci Link Selezionati</span>
                    <span class="alma-insert-loading" style="display: none;">
                        <span class="alma-spinner"></span>Inserendo...
                    </span>
                </button>
                
                <div style="margin-top: 6px; font-size: 12px; color: #666;">
                    <span id="alma-selected-count">0</span> link selezionati
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Configurazione per JavaScript -->
<script type="application/json" id="alma-config">
{
    "postId": <?php echo $post_id; ?>,
    "enabled": <?php echo $enabled ? 'true' : 'false'; ?>,
    "minLength": <?php echo $min_length; ?>,
    "ajaxUrl": "<?php echo esc_url(admin_url('admin-ajax.php')); ?>",
    "nonce": "<?php echo wp_create_nonce('alma_content_analyzer_nonce'); ?>",
    "availableLinks": <?php echo json_encode($available_links); ?>,
    "debug": <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'true' : 'false'; ?>
}
</script>

<!-- JavaScript CORRETTO per Inserimento -->
<script>
(function() {
    'use strict';

    let config = {};
    let state = {
        isAnalyzing: false,
        isInserting: false,
        currentSuggestions: [],
        selectedSuggestions: [],
        cursorPosition: null,
        insertMode: 'cursor'
    };

    document.addEventListener('DOMContentLoaded', function() {
        initializeWidget();
    });

    function initializeWidget() {
        loadConfig();
        bindEvents();
        detectEditor();
        console.log('🤖 AI Content Analyzer v2.0 inizializzato');
        
        // Debug info caricamento
        if (config.debug) {
            console.log('📋 Link affiliati caricati:', config.availableLinks?.length || 0);
            console.log('📝 Editor tipo:', state.editorType);
        }
    }

    function detectEditor() {
        // Rileva tipo di editor
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            state.editorType = 'gutenberg';
            console.log('📝 Editor rilevato: Gutenberg');
        } else if (document.getElementById('content')) {
            state.editorType = 'classic';
            console.log('📝 Editor rilevato: Classic Editor');
        } else {
            state.editorType = 'unknown';
            console.log('⚠️ Editor non rilevato');
        }
    }

    function loadConfig() {
        const configEl = document.getElementById('alma-config');
        if (configEl) {
            config = JSON.parse(configEl.textContent);
        }
    }

    function bindEvents() {
        const analyzeBtn = document.getElementById('alma-analyze-btn');
        const selectAllBtn = document.getElementById('alma-select-all');
        const clearAllBtn = document.getElementById('alma-clear-all');
        const insertBtn = document.getElementById('alma-insert-btn');
        
        // Radio buttons modalità inserimento
        const modeInputs = document.querySelectorAll('input[name="alma-insert-mode"]');

        if (analyzeBtn) {
            analyzeBtn.addEventListener('click', handleAnalyze);
        }

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', selectAllSuggestions);
        }

        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', clearAllSelections);
        }

        if (insertBtn) {
            insertBtn.addEventListener('click', insertSelectedLinks);
        }

        // Modalità inserimento
        modeInputs.forEach(input => {
            input.addEventListener('change', function() {
                state.insertMode = this.value;
                console.log('🎯 Modalità inserimento cambiata:', state.insertMode);
            });
        });

        // Eventi delegati per suggerimenti dinamici
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('alma-suggestion-checkbox')) {
                handleSuggestionToggle(e);
            }
        });

        // Traccia posizione cursore più robusta
        trackCursorPosition();
    }

    function trackCursorPosition() {
        console.log('📍 Avvio tracking posizione cursore...');
        
        // Classic Editor
        const contentField = document.getElementById('content');
        if (contentField) {
            console.log('📝 Tracking cursore Classic Editor');
            
            ['keyup', 'click', 'focus', 'mouseup'].forEach(event => {
                contentField.addEventListener(event, function() {
                    state.cursorPosition = this.selectionStart || 0;
                    console.log('📍 Posizione cursore aggiornata:', state.cursorPosition);
                });
            });
        }

        // Gutenberg - tracking più semplice
        if (state.editorType === 'gutenberg') {
            console.log('📝 Tracking cursore Gutenberg (semplificato)');
            
            // Per Gutenberg, useremo inserimento alla fine come fallback sicuro
            document.addEventListener('click', function(e) {
                // Se click in area editor Gutenberg
                if (e.target.closest('.block-editor-writing-flow') || 
                    e.target.closest('.wp-block') ||
                    e.target.closest('.editor-styles-wrapper')) {
                    
                    state.cursorPosition = 'gutenberg-active';
                    console.log('📍 Click in Gutenberg editor area');
                }
            });
        }
    }

    function handleAnalyze() {
        if (state.isAnalyzing || !config.enabled) return;

        const content = getCurrentContent();
        if (!validateContent(content)) {
            showNotification('⚠️ Contenuto troppo breve per l\'analisi', 'warning');
            return;
        }

        startAnalysis(content);
    }

    function startAnalysis(content) {
        state.isAnalyzing = true;
        updateAnalyzeButton(true);

        // ANALISI REALE DEL CONTENUTO
        console.log('🔍 Avvio analisi contenuto...', content.length, 'caratteri');
        const analysis = analyzeContent(content);
        console.log('🧠 Analisi completata:', analysis);
        
        // GENERAZIONE SUGGERIMENTI CON MATCHING MIGLIORATO
        const suggestions = generateSuggestions(analysis);
        console.log('💡 Suggerimenti generati:', suggestions.length);

        // Simula delay per UX
        setTimeout(() => {
            displayResults(analysis, suggestions);
            state.isAnalyzing = false;
            updateAnalyzeButton(false);
        }, 1500);
    }

    function analyzeContent(content) {
        // ANALISI REALE DEL CONTENUTO - MIGLIORATA
        const cleanText = stripHtml(content).toLowerCase();
        
        // Rimuovi punteggiatura e caratteri speciali per le parole
        const words = cleanText
            .replace(/[^\w\s]/g, ' ')
            .split(/\s+/)
            .filter(w => w.length > 2); // Parole minimo 3 caratteri
        
        const wordCount = words.length;

        // Estrai keyword più frequenti (MIGLIORATO)
        const wordFreq = {};
        words.forEach(word => {
            // Stop words italiane estese
            const stopWords = [
                'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'a', 'da', 'in', 'con', 'su', 'per', 
                'tra', 'fra', 'sono', 'è', 'siamo', 'siete', 'sei', 'ho', 'hai', 'ha', 'abbiamo', 'avete', 'hanno',
                'questo', 'quello', 'questa', 'quella', 'questi', 'quelli', 'queste', 'quelle', 'che', 'chi', 'cui',
                'come', 'quando', 'dove', 'perché', 'se', 'ma', 'però', 'quindi', 'così', 'anche', 'ancora', 'più',
                'molto', 'tutto', 'tutti', 'tutte', 'ogni', 'qualche', 'alcuni', 'alcune', 'altri', 'altre',
                'stesso', 'stessa', 'stessi', 'stesse', 'proprio', 'propria', 'propri', 'proprie', 'mio', 'mia',
                'essere', 'avere', 'fare', 'dire', 'andare', 'potere', 'dovere', 'volere', 'vedere', 'sapere',
                'prendere', 'venire', 'stare', 'dare', 'uscire', 'partire', 'portare', 'mettere', 'sentire'
            ];
            
            if (!stopWords.includes(word) && word.length > 2) {
                wordFreq[word] = (wordFreq[word] || 0) + 1;
            }
        });

        // Seleziona keyword più frequenti e significative
        const keywords = Object.keys(wordFreq)
            .filter(word => wordFreq[word] > 1) // Minimo 2 occorrenze
            .sort((a, b) => wordFreq[b] - wordFreq[a])
            .slice(0, 10); // Top 10 keyword

        console.log('🎯 Keywords estratte:', keywords);

        // Identifica temi principali
        const themes = [];
        const themeKeywords = {
            'tecnologia': ['wordpress', 'plugin', 'software', 'web', 'sito', 'digitale', 'online', 'computer', 'internet', 'app', 'tecnologia', 'tech', 'hosting', 'domain', 'server'],
            'business': ['business', 'azienda', 'marketing', 'vendita', 'cliente', 'mercato', 'prodotto', 'servizio', 'commercio', 'vendere', 'comprare', 'prezzo', 'costo'],
            'lifestyle': ['casa', 'cucina', 'viaggi', 'moda', 'salute', 'fitness', 'benessere', 'famiglia', 'hobby', 'tempo', 'libero', 'divertimento', 'relax'],
            'educazione': ['corso', 'imparare', 'studiare', 'formazione', 'scuola', 'università', 'conoscenza', 'tutorial', 'guida', 'lezione', 'insegnare'],
            'finanza': ['denaro', 'investimento', 'risparmio', 'banca', 'prestito', 'economia', 'soldi', 'pagamento', 'carta', 'credito', 'bitcoin', 'crypto']
        };

        for (const [theme, themeWords] of Object.entries(themeKeywords)) {
            let matches = 0;
            themeWords.forEach(tw => {
                if (cleanText.includes(tw)) {
                    matches++;
                }
            });
            if (matches > 0) {
                themes.push({ theme, score: matches });
            }
        }

        // Analisi sentiment semplificata
        const positiveWords = ['ottimo', 'fantastico', 'eccellente', 'perfetto', 'consiglio', 'migliore', 'incredibile', 'magnifico', 'straordinario', 'buono', 'bene', 'felice'];
        const negativeWords = ['pessimo', 'terribile', 'orribile', 'sconsiglio', 'peggiore', 'disastro', 'deludente', 'male', 'cattivo', 'sbagliato'];
        
        let positiveCount = 0;
        let negativeCount = 0;
        
        positiveWords.forEach(word => {
            if (cleanText.includes(word)) positiveCount++;
        });
        negativeWords.forEach(word => {
            if (cleanText.includes(word)) negativeCount++;
        });
        
        let sentiment = 'neutral';
        if (positiveCount > negativeCount) sentiment = 'positive';
        else if (negativeCount > positiveCount) sentiment = 'negative';

        return {
            keywords: keywords,
            themes: themes.sort((a, b) => b.score - a.score).slice(0, 3).map(t => t.theme),
            sentiment: sentiment,
            wordCount: wordCount,
            contentLength: content.length
        };
    }

    function generateSuggestions(analysis) {
        if (!config.availableLinks || config.availableLinks.length === 0) {
            console.log('⚠️ Nessun link affiliato disponibile per suggerimenti');
            return [];
        }

        const maxSuggestions = parseInt(document.getElementById('alma-max-suggestions').value) || 5;
        const suggestions = [];

        console.log('🔍 Controllo matching per', config.availableLinks.length, 'link affiliati');

        config.availableLinks.forEach((link, index) => {
            const relevance = calculateRelevanceImproved(link, analysis);
            
            console.log(`📋 Link ${index + 1}: "${link.title}" → ${Math.round(relevance*100)}%`, 
                       relevance > 0.05 ? '✅ MATCH' : '❌ NO MATCH');
            
            // SOGLIA RIDOTTA per essere meno restrittivi
            if (relevance > 0.05) {
                suggestions.push({
                    id: link.id,
                    title: link.title,
                    types: link.types,
                    clicks: link.clicks,
                    usage: link.usage,
                    relevance: relevance,
                    suggestedAnchor: generateAnchorTextImproved(link, analysis)
                });
            }
        });

        // Ordina per rilevanza e limita
        const finalSuggestions = suggestions
            .sort((a, b) => b.relevance - a.relevance)
            .slice(0, maxSuggestions);

        console.log('💡 Suggerimenti finali:', finalSuggestions.length);
        
        return finalSuggestions;
    }

    function calculateRelevanceImproved(link, analysis) {
        let score = 0;
        
        // FOCUS PRINCIPALE: MATCHING CON TITLE DEL LINK (80% del punteggio)
        const linkTitle = link.title.toLowerCase();
        let titleMatches = 0;
        let exactMatches = 0;
        let partialMatches = 0;

        analysis.keywords.forEach(keyword => {
            // Controllo match esatto nella parola
            const keywordRegex = new RegExp('\\b' + escapeRegExp(keyword) + '\\b', 'i');
            if (keywordRegex.test(linkTitle)) {
                exactMatches++;
                titleMatches++;
            }
            // Controllo match parziale
            else if (linkTitle.includes(keyword)) {
                partialMatches++;
                titleMatches++;
            }
        });

        // Calcolo punteggio title (massimo 0.8)
        const titleScore = titleMatches > 0 ? 
            (exactMatches * 0.6 + partialMatches * 0.2) / analysis.keywords.length : 0;
        score += Math.min(titleScore, 0.8);

        // CONTROLLO TIPOLOGIE (15% del punteggio)
        let typeMatches = 0;
        if (link.types && link.types.length > 0) {
            const typesText = link.types.join(' ').toLowerCase();
            analysis.keywords.forEach(keyword => {
                if (typesText.includes(keyword)) {
                    typeMatches++;
                }
            });
            score += (typeMatches / analysis.keywords.length) * 0.15;
        }

        // PERFORMANCE STORICA (5% del punteggio)
        const performanceScore = Math.min(link.clicks / 20, 1); // Normalizza su 20 click
        score += performanceScore * 0.05;

        return Math.min(score, 1);
    }

    function generateAnchorTextImproved(link, analysis) {
        let anchor = link.title;

        if (analysis.keywords.length > 0) {
            const topKeyword = analysis.keywords[0];
            const linkTitle = link.title.toLowerCase();
            
            if (!linkTitle.includes(topKeyword)) {
                const variations = [
                    topKeyword + ' - ' + anchor,
                    'Migliori ' + topKeyword,
                    topKeyword + ' consigliati',
                    anchor + ' per ' + topKeyword
                ];
                
                anchor = variations.reduce((shortest, current) => 
                    current.length < shortest.length ? current : shortest
                );
            }
        }

        return anchor;
    }

    function displayResults(analysis, suggestions) {
        const resultsContainer = document.getElementById('alma-results');
        if (resultsContainer) {
            resultsContainer.style.display = 'block';
        }

        displayInsights(analysis);
        displaySuggestions(suggestions);
        
        const countEl = document.getElementById('alma-suggestions-count');
        if (countEl) {
            countEl.textContent = suggestions.length;
        }

        const message = suggestions.length > 0 ? 
            `✅ Analisi completata: ${suggestions.length} suggerimenti trovati` :
            `⚠️ Nessun suggerimento trovato`;
        
        showNotification(message, suggestions.length > 0 ? 'success' : 'warning');
    }

    function displayInsights(analysis) {
        const insightsEl = document.getElementById('alma-insights-data');
        if (!insightsEl) return;

        const keywordsHtml = analysis.keywords.map(k => 
            `<span class="alma-keyword">${escapeHtml(k)}</span>`
        ).join('');

        const themesHtml = analysis.themes.map(t => 
            `<span class="alma-theme">${escapeHtml(t)}</span>`
        ).join('');

        const sentimentText = {
            'positive': '😊 Positivo',
            'negative': '😞 Negativo',
            'neutral': '😐 Neutrale'
        }[analysis.sentiment];

        insightsEl.innerHTML = `
            <div style="margin-bottom: 8px;">
                <strong>🎯 Keywords:</strong><br>
                ${keywordsHtml || '<em>Nessuna keyword identificata</em>'}
            </div>
            <div style="margin-bottom: 8px;">
                <strong>🏷️ Temi:</strong><br>
                ${themesHtml || '<em>Nessun tema identificato</em>'}
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 11px;">
                <span><strong>😊 Sentiment:</strong> ${sentimentText}</span>
                <span><strong>📝 Parole:</strong> ${analysis.wordCount}</span>
            </div>
        `;
    }

    function displaySuggestions(suggestions) {
        const listEl = document.getElementById('alma-suggestions-list');
        if (!listEl) return;

        if (suggestions.length === 0) {
            listEl.innerHTML = `
                <div style="text-align: center; padding: 20px; color: #666; background: #f8f9fa; border-radius: 6px;">
                    <div style="font-size: 32px; margin-bottom: 10px;">🤔</div>
                    <h4 style="margin: 0 0 8px 0;">Nessun suggerimento trovato</h4>
                    <p style="margin: 0; font-size: 12px;">
                        L'AI non ha trovato link affiliati corrispondenti alle keyword del contenuto.
                    </p>
                </div>
            `;
            return;
        }

        let html = '';
        suggestions.forEach(suggestion => {
            const relevancePercent = Math.round(suggestion.relevance * 100);
            const typesText = suggestion.types.length > 0 ? suggestion.types.join(', ') : 'Generale';

            html += `
                <div class="alma-suggestion">
                    <div class="alma-sugg-header">
                        <input type="checkbox" class="alma-suggestion-checkbox" value="${suggestion.id}" data-suggestion='${JSON.stringify(suggestion)}'>
                        <span class="alma-sugg-title">${escapeHtml(suggestion.title)}</span>
                        <span class="alma-sugg-stats">${relevancePercent}% | 👆${suggestion.clicks} | ✓${suggestion.usage}</span>
                    </div>
                    <div class="alma-sugg-details">
                        <div class="alma-sugg-types">📂 ${escapeHtml(typesText)}</div>
                        <label style="font-size: 11px; color: #666;">🔗 Testo del link:</label>
                        <input type="text" class="alma-anchor-input" value="${escapeHtml(suggestion.suggestedAnchor)}" placeholder="Personalizza testo link...">
                    </div>
                </div>
            `;
        });

        listEl.innerHTML = html;
        
        const actionsEl = document.getElementById('alma-actions');
        if (actionsEl) {
            actionsEl.style.display = 'block';
        }

        state.currentSuggestions = suggestions;
        state.selectedSuggestions = [];
        updateSelectionCount();
    }

    function handleSuggestionToggle(e) {
        const checkbox = e.target;
        const suggestionData = JSON.parse(checkbox.dataset.suggestion);
        const suggestionEl = checkbox.closest('.alma-suggestion');

        if (checkbox.checked) {
            if (!state.selectedSuggestions.find(s => s.id === suggestionData.id)) {
                const anchorInput = suggestionEl.querySelector('.alma-anchor-input');
                suggestionData.customAnchor = anchorInput ? anchorInput.value : suggestionData.suggestedAnchor;
                
                state.selectedSuggestions.push(suggestionData);
            }
            suggestionEl.classList.add('selected');
        } else {
            const index = state.selectedSuggestions.findIndex(s => s.id === suggestionData.id);
            if (index > -1) {
                state.selectedSuggestions.splice(index, 1);
            }
            suggestionEl.classList.remove('selected');
        }

        updateSelectionCount();
    }

    function selectAllSuggestions() {
        const checkboxes = document.querySelectorAll('.alma-suggestion-checkbox');
        checkboxes.forEach(cb => {
            if (!cb.checked) {
                cb.checked = true;
                cb.dispatchEvent(new Event('change'));
            }
        });
    }

    function clearAllSelections() {
        const checkboxes = document.querySelectorAll('.alma-suggestion-checkbox');
        checkboxes.forEach(cb => {
            if (cb.checked) {
                cb.checked = false;
                cb.dispatchEvent(new Event('change'));
            }
        });
    }

    function insertSelectedLinks() {
        if (state.selectedSuggestions.length === 0) {
            showNotification('⚠️ Seleziona almeno un link', 'warning');
            return;
        }

        if (state.isInserting) return;

        console.log('🚀 Avvio inserimento link:', state.selectedSuggestions.length);
        console.log('📍 Modalità inserimento:', state.insertMode);
        console.log('📝 Editor tipo:', state.editorType);

        // Aggiorna anchor text personalizzati
        state.selectedSuggestions.forEach(suggestion => {
            const checkbox = document.querySelector(`input[value="${suggestion.id}"]`);
            if (checkbox) {
                const anchorInput = checkbox.closest('.alma-suggestion').querySelector('.alma-anchor-input');
                if (anchorInput) {
                    suggestion.customAnchor = anchorInput.value;
                }
            }
        });

        state.isInserting = true;
        updateInsertButton(true);

        // INSERIMENTO MIGLIORATO
        setTimeout(() => {
            const success = performInsertion();
            
            if (success) {
                showNotification(`✅ ${state.selectedSuggestions.length} link inseriti con successo!`, 'success');
                clearAllSelections();
            } else {
                showNotification('❌ Errore durante l\'inserimento', 'error');
            }
            
            state.isInserting = false;
            updateInsertButton(false);
        }, 1000);
    }

    function performInsertion() {
        console.log('📝 Esecuzione inserimento...');
        
        try {
            const currentContent = getCurrentContent();
            console.log('📄 Contenuto attuale lunghezza:', currentContent.length);
            
            if (!currentContent) {
                console.error('❌ Contenuto vuoto');
                return false;
            }

            // Genera shortcode per ogni link selezionato
            let shortcodesToInsert = '';
            state.selectedSuggestions.forEach((suggestion, index) => {
                const anchorText = suggestion.customAnchor || suggestion.suggestedAnchor;
                const shortcode = `[affiliate_link id="${suggestion.id}"]${anchorText}[/affiliate_link]`;
                
                if (index > 0) {
                    shortcodesToInsert += '\n\n';
                }
                shortcodesToInsert += shortcode;
                
                console.log(`📎 Shortcode generato: ${shortcode}`);
            });

            console.log('📝 Shortcode da inserire:', shortcodesToInsert);

            let newContent;

            if (state.insertMode === 'end' || state.editorType === 'unknown') {
                // INSERIMENTO ALLA FINE (SICURO)
                console.log('📄 Inserimento alla fine del contenuto');
                newContent = currentContent + '\n\n' + shortcodesToInsert;
            } else {
                // INSERIMENTO ALLA POSIZIONE CURSORE
                const insertPosition = getInsertPosition(currentContent);
                console.log('📍 Posizione inserimento:', insertPosition);
                
                newContent = currentContent.slice(0, insertPosition) + 
                           '\n\n' + shortcodesToInsert + '\n\n' + 
                           currentContent.slice(insertPosition);
            }

            console.log('📝 Nuovo contenuto lunghezza:', newContent.length);

            // Aggiorna contenuto nell'editor
            const updateSuccess = updateEditorContent(newContent);
            
            if (updateSuccess) {
                console.log('✅ Contenuto aggiornato con successo');
                return true;
            } else {
                console.error('❌ Errore aggiornamento contenuto');
                return false;
            }

        } catch (error) {
            console.error('❌ Errore durante inserimento:', error);
            return false;
        }
    }

    function getInsertPosition(content) {
        // Strategia multipla per trovare posizione di inserimento
        
        // 1. Se abbiamo una posizione cursore valida nel Classic Editor
        if (state.editorType === 'classic' && 
            typeof state.cursorPosition === 'number' && 
            state.cursorPosition >= 0 && 
            state.cursorPosition <= content.length) {
            
            console.log('📍 Usando posizione cursore Classic Editor:', state.cursorPosition);
            return state.cursorPosition;
        }
        
        // 2. Fallback: metà del contenuto
        const midPoint = Math.floor(content.length / 2);
        
        // 3. Cerca un punto di inserimento naturale vicino alla metà
        const searchStart = Math.max(0, midPoint - 200);
        const searchEnd = Math.min(content.length, midPoint + 200);
        const searchArea = content.slice(searchStart, searchEnd);
        
        // Cerca punti naturali per l'inserimento (fine paragrafo)
        const naturalBreaks = ['\n\n', '</p>', '<br>', '\n'];
        
        for (const breakPoint of naturalBreaks) {
            const breakIndex = searchArea.lastIndexOf(breakPoint);
            if (breakIndex > -1) {
                const position = searchStart + breakIndex + breakPoint.length;
                console.log('📍 Trovato punto naturale:', position);
                return position;
            }
        }
        
        // Fallback finale: metà contenuto
        console.log('📍 Usando fallback metà contenuto:', midPoint);
        return midPoint;
    }

    function getCurrentContent() {
        console.log('📖 Recupero contenuto corrente...');
        
        try {
            // Prova Gutenberg
            if (state.editorType === 'gutenberg' && typeof wp !== 'undefined' && wp.data) {
                const content = wp.data.select('core/editor').getEditedPostContent();
                if (content) {
                    console.log('📝 Contenuto da Gutenberg:', content.length, 'caratteri');
                    return content;
                }
            }

            // Classic Editor
            const contentField = document.getElementById('content');
            if (contentField && contentField.value) {
                console.log('📝 Contenuto da Classic Editor:', contentField.value.length, 'caratteri');
                return contentField.value;
            }

            console.log('⚠️ Nessun contenuto trovato');
            return '';
        } catch (error) {
            console.error('❌ Errore recupero contenuto:', error);
            return '';
        }
    }

    function updateEditorContent(newContent) {
        console.log('📝 Aggiornamento contenuto editor...');
        
        try {
            // Gutenberg
            if (state.editorType === 'gutenberg' && typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                console.log('📝 Aggiornamento Gutenberg');
                wp.data.dispatch('core/editor').editPost({ content: newContent });
                
                // Forza refresh editor
                setTimeout(() => {
                    wp.data.dispatch('core/editor').savePost();
                }, 100);
                
                return true;
            }

            // Classic Editor
            const contentField = document.getElementById('content');
            if (contentField) {
                console.log('📝 Aggiornamento Classic Editor');
                contentField.value = newContent;
                
                // Trigger eventi per WordPress
                contentField.dispatchEvent(new Event('input', { bubbles: true }));
                contentField.dispatchEvent(new Event('change', { bubbles: true }));
                
                // Focus per vedere il cambiamento
                contentField.focus();
                
                return true;
            }

            console.error('❌ Nessun editor trovato per aggiornamento');
            return false;

        } catch (error) {
            console.error('❌ Errore aggiornamento editor:', error);
            return false;
        }
    }

    function validateContent(content) {
        return stripHtml(content).trim().length >= config.minLength;
    }

    function updateAnalyzeButton(loading) {
        const btn = document.getElementById('alma-analyze-btn');
        if (!btn) return;

        const textEl = btn.querySelector('.alma-btn-text');
        const loadingEl = btn.querySelector('.alma-btn-loading');

        btn.disabled = loading;
        
        if (loading) {
            textEl.style.display = 'none';
            loadingEl.style.display = 'inline';
        } else {
            textEl.style.display = 'inline';
            loadingEl.style.display = 'none';
        }
    }

    function updateInsertButton(loading) {
        const btn = document.getElementById('alma-insert-btn');
        if (!btn) return;

        const textEl = btn.querySelector('.alma-insert-text');
        const loadingEl = btn.querySelector('.alma-insert-loading');

        btn.disabled = loading;
        
        if (loading) {
            textEl.style.display = 'none';
            loadingEl.style.display = 'inline';
        } else {
            textEl.style.display = 'inline';
            loadingEl.style.display = 'none';
        }
    }

    function updateSelectionCount() {
        const countEl = document.getElementById('alma-selected-count');
        if (countEl) {
            countEl.textContent = state.selectedSuggestions.length;
        }

        const insertBtn = document.getElementById('alma-insert-btn');
        if (insertBtn) {
            insertBtn.disabled = state.selectedSuggestions.length === 0 || state.isInserting;
        }
    }

    function showNotification(message, type) {
        console.log(`${type.toUpperCase()}: ${message}`);
        
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed; top: 32px; right: 20px; z-index: 99999;
            padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500;
            background: ${type === 'success' ? '#d1fae5' : type === 'warning' ? '#fef3c7' : type === 'error' ? '#fee2e2' : '#e0f2fe'};
            color: ${type === 'success' ? '#065f46' : type === 'warning' ? '#92400e' : type === 'error' ? '#991b1b' : '#0369a1'};
            border: 1px solid ${type === 'success' ? '#a7f3d0' : type === 'warning' ? '#fde68a' : type === 'error' ? '#fca5a5' : '#7dd3fc'};
            max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        `;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => notification.remove(), 5000);
        
        notification.addEventListener('click', () => notification.remove());
    }

    function stripHtml(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || div.innerText || '';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

})();
</script>

<?php
// Fine enhanced-widget.php v2.0 - CORREZIONE INSERIMENTO
?>