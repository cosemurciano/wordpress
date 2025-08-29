/**
 * Enhanced Content Analyzer for Affiliate Link Manager AI
 * Version: 2.0.1
 * Fixed: Error handling and element checking
 */

(function($) {
    'use strict';
    
    // Namespace globale
    window.ALMA_Analyzer = window.ALMA_Analyzer || {
        version: '2.0.1',
        initialized: false,
        elements: {},
        state: {
            isAnalyzing: false,
            lastAnalysis: null,
            suggestions: [],
            currentContent: '',
            contentHash: null
        },
        config: {
            minContentLength: 100,
            autoAnalyze: true,
            analyzeDelay: 3000,
            apiEndpoint: null
        }
    };
    
    const Analyzer = window.ALMA_Analyzer;
    
    /**
     * Inizializzazione principale
     */
    $(document).ready(function() {
        // Solo se siamo nell'editor
        if (!$('body').hasClass('post-php') && !$('body').hasClass('post-new-php')) {
            return;
        }
        
        console.log('🤖 ALMA Enhanced Analyzer v2.0.1 - Inizializzazione');
        
        try {
            initializeAnalyzer();
        } catch (error) {
            console.error('ALMA Analyzer: Errore inizializzazione', error);
        }
    });
    
    /**
     * Inizializza l'analyzer
     */
    function initializeAnalyzer() {
        // Verifica che gli elementi necessari esistano
        if (!checkRequiredElements()) {
            console.warn('ALMA Analyzer: Elementi richiesti non trovati, skip inizializzazione');
            return;
        }
        
        // Carica configurazione
        loadConfiguration();
        
        // Cache elementi DOM
        cacheElements();
        
        // Setup event handlers
        setupEventHandlers();
        
        // Log inizializzazione
        if (Analyzer.elements.logContent && Analyzer.elements.logContent.length > 0) {
            logMessage('Sistema inizializzato', 'success');
        }
        
        Analyzer.initialized = true;
        console.log('✅ ALMA Analyzer inizializzato correttamente');
    }
    
    /**
     * Verifica elementi richiesti
     */
    function checkRequiredElements() {
        // Per ora verifichiamo solo che siamo nell'editor
        return $('#post').length > 0 || $('.block-editor').length > 0;
    }
    
    /**
     * Carica configurazione
     */
    function loadConfiguration() {
        // Usa configurazione da PHP se disponibile
        if (typeof alma_analyzer !== 'undefined' && alma_analyzer.config) {
            $.extend(Analyzer.config, alma_analyzer.config);
            console.log('✅ Configurazione caricata da PHP');
        } else {
            // Usa defaults
            console.log('⚠️ Usando configurazione di default');
        }
        
        // Imposta endpoint API
        if (typeof alma_analyzer !== 'undefined' && alma_analyzer.ajax_url) {
            Analyzer.config.apiEndpoint = alma_analyzer.ajax_url;
        }
    }
    
    /**
     * Cache elementi DOM
     */
    function cacheElements() {
        Analyzer.elements = {
            widget: $('#alma-content-analyzer'),
            analyzeBtn: $('#alma-analyze-content-btn'),
            refreshBtn: $('#alma-refresh-suggestions'),
            suggestionsContainer: $('#alma-suggestions-container'),
            analysisResults: $('#alma-analysis-results'),
            insertButtons: $('.alma-suggestion-insert'),
            logContent: $('#alma-analyzer-log'),
            statusIndicator: $('#alma-analyzer-status'),
            progressBar: $('#alma-analysis-progress')
        };
        
        // Verifica elementi critici
        if (!Analyzer.elements.widget || Analyzer.elements.widget.length === 0) {
            console.log('ℹ️ Widget analyzer non presente in questa pagina');
        }
    }
    
    /**
     * Setup event handlers
     */
    function setupEventHandlers() {
        // Solo se il widget esiste
        if (!Analyzer.elements.widget || Analyzer.elements.widget.length === 0) {
            return;
        }
        
        // Analizza contenuto
        if (Analyzer.elements.analyzeBtn && Analyzer.elements.analyzeBtn.length > 0) {
            Analyzer.elements.analyzeBtn.on('click', function(e) {
                e.preventDefault();
                analyzeCurrentContent();
            });
        }
        
        // Refresh suggerimenti
        if (Analyzer.elements.refreshBtn && Analyzer.elements.refreshBtn.length > 0) {
            Analyzer.elements.refreshBtn.on('click', function(e) {
                e.preventDefault();
                refreshSuggestions();
            });
        }
        
        // Inserisci suggerimento
        $(document).on('click', '.alma-suggestion-insert', function(e) {
            e.preventDefault();
            const linkId = $(this).data('link-id');
            insertSuggestion(linkId);
        });
        
        // Auto-analisi su cambio contenuto
        if (Analyzer.config.autoAnalyze) {
            setupContentMonitoring();
        }
    }
    
    /**
     * Setup monitoraggio contenuto
     */
    function setupContentMonitoring() {
        let debounceTimer = null;
        
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data) {
            const { subscribe } = wp.data;
            let lastContent = '';
            
            const unsubscribe = subscribe(() => {
                try {
                    const editor = wp.data.select('core/editor');
                    if (editor) {
                        const content = editor.getEditedPostContent();
                        if (content !== lastContent) {
                            lastContent = content;
                            
                            clearTimeout(debounceTimer);
                            debounceTimer = setTimeout(() => {
                                if (content.length >= Analyzer.config.minContentLength) {
                                    scheduleAutoAnalysis();
                                }
                            }, Analyzer.config.analyzeDelay);
                        }
                    }
                } catch (error) {
                    // Ignora errori silenziosamente
                }
            });
        }
        
        // Classic Editor
        if (typeof tinyMCE !== 'undefined') {
            $(document).on('tinymce-editor-init', function(event, editor) {
                editor.on('change keyup', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const content = editor.getContent();
                        if (content.length >= Analyzer.config.minContentLength) {
                            scheduleAutoAnalysis();
                        }
                    }, Analyzer.config.analyzeDelay);
                });
            });
        }
        
        // Textarea fallback
        $('#content').on('input keyup', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const content = $(this).val();
                if (content.length >= Analyzer.config.minContentLength) {
                    scheduleAutoAnalysis();
                }
            }, Analyzer.config.analyzeDelay);
        });
    }
    
    /**
     * Programma auto-analisi
     */
    function scheduleAutoAnalysis() {
        if (Analyzer.state.isAnalyzing) {
            return;
        }
        
        const currentContent = getCurrentContent();
        const currentHash = hashCode(currentContent);
        
        if (currentHash !== Analyzer.state.contentHash) {
            Analyzer.state.contentHash = currentHash;
            analyzeCurrentContent(true);
        }
    }
    
    /**
     * Analizza contenuto corrente
     */
    function analyzeCurrentContent(isAuto = false) {
        if (Analyzer.state.isAnalyzing) {
            console.log('⏳ Analisi già in corso');
            return;
        }
        
        const content = getCurrentContent();
        
        if (content.length < Analyzer.config.minContentLength) {
            showNotification('Il contenuto è troppo breve per l\'analisi', 'warning');
            return;
        }
        
        Analyzer.state.isAnalyzing = true;
        Analyzer.state.currentContent = content;
        
        updateUIState('analyzing');
        
        // Chiamata AJAX
        $.ajax({
            url: Analyzer.config.apiEndpoint,
            type: 'POST',
            data: {
                action: 'alma_analyze_content',
                nonce: alma_analyzer.nonce,
                content: content,
                post_id: $('#post_ID').val()
            },
            success: function(response) {
                if (response.success) {
                    Analyzer.state.lastAnalysis = response.data;
                    displayAnalysisResults(response.data);
                    
                    if (!isAuto) {
                        showNotification('Analisi completata con successo', 'success');
                    }
                } else {
                    showNotification(response.data || 'Errore durante l\'analisi', 'error');
                }
            },
            error: function() {
                showNotification('Errore di connessione', 'error');
            },
            complete: function() {
                Analyzer.state.isAnalyzing = false;
                updateUIState('ready');
            }
        });
    }
    
    /**
     * Mostra risultati analisi
     */
    function displayAnalysisResults(analysis) {
        if (!Analyzer.elements.analysisResults || Analyzer.elements.analysisResults.length === 0) {
            return;
        }
        
        let html = '<div class="alma-analysis-summary">';
        
        // Metriche principali
        html += '<div class="alma-metrics-grid">';
        html += `<div class="alma-metric">
                    <span class="label">Parole</span>
                    <span class="value">${analysis.word_count || 0}</span>
                 </div>`;
        html += `<div class="alma-metric">
                    <span class="label">Leggibilità</span>
                    <span class="value">${analysis.readability_score || 'N/A'}</span>
                 </div>`;
        html += `<div class="alma-metric">
                    <span class="label">Sentiment</span>
                    <span class="value">${analysis.sentiment || 'Neutro'}</span>
                 </div>`;
        html += '</div>';
        
        // Temi identificati
        if (analysis.themes && analysis.themes.length > 0) {
            html += '<div class="alma-themes">';
            html += '<h4>Temi Identificati:</h4>';
            html += '<div class="alma-tags">';
            analysis.themes.forEach(function(theme) {
                html += `<span class="alma-tag">${theme}</span>`;
            });
            html += '</div>';
            html += '</div>';
        }
        
        // Suggerimenti link
        if (analysis.suggestions && analysis.suggestions.length > 0) {
            html += '<div class="alma-suggestions">';
            html += '<h4>Link Suggeriti:</h4>';
            displaySuggestions(analysis.suggestions);
            html += '</div>';
        }
        
        html += '</div>';
        
        Analyzer.elements.analysisResults.html(html);
    }
    
    /**
     * Mostra suggerimenti
     */
    function displaySuggestions(suggestions) {
        if (!Analyzer.elements.suggestionsContainer || Analyzer.elements.suggestionsContainer.length === 0) {
            return;
        }
        
        let html = '<div class="alma-suggestions-list">';
        
        suggestions.forEach(function(suggestion) {
            html += `
            <div class="alma-suggestion-item" data-link-id="${suggestion.link_id}">
                <div class="alma-suggestion-header">
                    <strong>${suggestion.title}</strong>
                    <span class="alma-relevance-score">${Math.round(suggestion.relevance_score * 100)}%</span>
                </div>
                <div class="alma-suggestion-body">
                    <p>${suggestion.reason}</p>
                    <div class="alma-suggestion-actions">
                        <button class="button alma-suggestion-insert" data-link-id="${suggestion.link_id}">
                            Inserisci
                        </button>
                        <code>[affiliate_link id="${suggestion.link_id}"]</code>
                    </div>
                </div>
            </div>`;
        });
        
        html += '</div>';
        
        Analyzer.elements.suggestionsContainer.html(html);
        Analyzer.state.suggestions = suggestions;
    }
    
    /**
     * Inserisci suggerimento nell'editor
     */
    function insertSuggestion(linkId) {
        const suggestion = Analyzer.state.suggestions.find(s => s.link_id == linkId);
        if (!suggestion) {
            return;
        }
        
        const shortcode = `[affiliate_link id="${linkId}"]`;
        
        if (insertIntoEditor(shortcode)) {
            showNotification('Link inserito con successo', 'success');
            
            // Marca come usato
            $(`.alma-suggestion-item[data-link-id="${linkId}"]`).addClass('used');
        } else {
            showNotification('Errore durante l\'inserimento', 'error');
        }
    }
    
    /**
     * Inserisci nell'editor
     */
    function insertIntoEditor(content) {
        try {
            // Gutenberg
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
                const { createBlock } = wp.blocks;
                const { insertBlocks } = wp.data.dispatch('core/block-editor');
                
                const block = createBlock('core/shortcode', {
                    text: content
                });
                
                insertBlocks(block);
                return true;
            }
            
            // TinyMCE
            if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
                tinyMCE.activeEditor.execCommand('mceInsertContent', false, content);
                return true;
            }
            
            // Textarea
            const textarea = document.getElementById('content');
            if (textarea) {
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const text = textarea.value;
                
                textarea.value = text.substring(0, start) + content + text.substring(end);
                textarea.selectionStart = textarea.selectionEnd = start + content.length;
                
                return true;
            }
            
            return false;
        } catch (error) {
            console.error('Errore inserimento:', error);
            return false;
        }
    }
    
    /**
     * Ottieni contenuto corrente
     */
    function getCurrentContent() {
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data) {
            try {
                const editor = wp.data.select('core/editor');
                if (editor) {
                    return editor.getEditedPostContent();
                }
            } catch (error) {
                // Continua con altri metodi
            }
        }
        
        // TinyMCE
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
            return tinyMCE.activeEditor.getContent();
        }
        
        // Textarea
        const textarea = document.getElementById('content');
        if (textarea) {
            return textarea.value;
        }
        
        return '';
    }
    
    /**
     * Refresh suggerimenti
     */
    function refreshSuggestions() {
        if (Analyzer.state.lastAnalysis) {
            // Richiedi nuovi suggerimenti basati sull'ultima analisi
            $.ajax({
                url: Analyzer.config.apiEndpoint,
                type: 'POST',
                data: {
                    action: 'alma_refresh_suggestions',
                    nonce: alma_analyzer.nonce,
                    analysis: Analyzer.state.lastAnalysis,
                    post_id: $('#post_ID').val()
                },
                success: function(response) {
                    if (response.success) {
                        displaySuggestions(response.data);
                        showNotification('Suggerimenti aggiornati', 'success');
                    }
                },
                error: function() {
                    showNotification('Errore aggiornamento suggerimenti', 'error');
                }
            });
        } else {
            analyzeCurrentContent();
        }
    }
    
    /**
     * Aggiorna stato UI
     */
    function updateUIState(state) {
        if (!Analyzer.elements.widget || Analyzer.elements.widget.length === 0) {
            return;
        }
        
        Analyzer.elements.widget.attr('data-state', state);
        
        if (Analyzer.elements.statusIndicator && Analyzer.elements.statusIndicator.length > 0) {
            let statusText = '';
            let statusClass = '';
            
            switch (state) {
                case 'ready':
                    statusText = 'Pronto';
                    statusClass = 'ready';
                    break;
                case 'analyzing':
                    statusText = 'Analizzando...';
                    statusClass = 'working';
                    break;
                case 'error':
                    statusText = 'Errore';
                    statusClass = 'error';
                    break;
            }
            
            Analyzer.elements.statusIndicator
                .text(statusText)
                .attr('class', 'alma-status ' + statusClass);
        }
    }
    
    /**
     * Mostra notifica
     */
    function showNotification(message, type = 'info') {
        // Se esiste un sistema di notifiche WordPress
        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/notices')) {
            wp.data.dispatch('core/notices').createNotice(
                type === 'error' ? 'error' : type === 'success' ? 'success' : 'info',
                message,
                {
                    isDismissible: true,
                    type: 'snackbar'
                }
            );
        } else {
            // Fallback con console
            console.log(`ALMA Analyzer [${type}]: ${message}`);
        }
    }
    
    /**
     * Log messaggio
     */
    function logMessage(message, type = 'info') {
        if (!Analyzer.elements.logContent || Analyzer.elements.logContent.length === 0) {
            console.log(`ALMA Log [${type}]: ${message}`);
            return;
        }
        
        const timestamp = new Date().toLocaleTimeString();
        const logEntry = `<div class="alma-log-entry ${type}">
                            <span class="time">${timestamp}</span>
                            <span class="message">${message}</span>
                          </div>`;
        
        Analyzer.elements.logContent.append(logEntry);
        
        // Scroll to bottom
        if (Analyzer.elements.logContent[0]) {
            Analyzer.elements.logContent[0].scrollTop = Analyzer.elements.logContent[0].scrollHeight;
        }
    }
    
    /**
     * Hash code per confronto contenuto
     */
    function hashCode(str) {
        let hash = 0;
        if (str.length === 0) return hash;
        
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        
        return hash;
    }
    
})(jQuery);