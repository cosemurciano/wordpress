/**
 * Affiliate Link Manager AI - Content Analyzer JavaScript
 * Version: 1.3.0
 * Author: Cosè Murciano
 */

jQuery(document).ready(function($) {
    
    let contentAnalysisData = null;
    let isAnalyzing = false;
    let lastContentHash = '';
    let autoAnalyzeTimer = null;
    
    // Inizializzazione
    init();
    
    function init() {
        console.log('🤖 ALMA Content Analyzer v1.3.0 inizializzato');
        
        // Bind eventi
        bindEvents();
        
        // Auto-analisi se abilitata
        if (alma_analyzer.settings.auto_analyze) {
            scheduleAutoAnalysis();
        }
        
        // Inizializza stato UI
        updateUIState('ready');
    }
    
    /**
     * Bind eventi UI
     */
    function bindEvents() {
        // Pulsante analizza manuale
        $(document).on('click', '#alma-analyze-content-btn', function(e) {
            e.preventDefault();
            analyzeCurrentContent();
        });
        
        // Pulsante refresh suggerimenti
        $(document).on('click', '#alma-refresh-suggestions', function(e) {
            e.preventDefault();
            if (contentAnalysisData) {
                getSuggestions(contentAnalysisData);
            }
        });
        
        // Inserimento link suggerito
        $(document).on('click', '.alma-suggestion-insert', function(e) {
            e.preventDefault();
            const suggestionData = $(this).data();
            insertSuggestedLink(suggestionData);
        });
        
        // Preview link suggerito
        $(document).on('click', '.alma-suggestion-preview', function(e) {
            e.preventDefault();
            const suggestionData = $(this).data();
            previewSuggestion(suggestionData);
        });
        
        // Configurazione veloce
        $(document).on('change', '#alma-max-suggestions', function() {
            const maxSuggestions = $(this).val();
            if (contentAnalysisData) {
                getSuggestions(contentAnalysisData, maxSuggestions);
            }
        });
        
        // Auto-analisi su cambiamento contenuto
        if (alma_analyzer.settings.auto_analyze) {
            // Monitor cambiamenti editor
            setupContentMonitoring();
        }
        
        // Gestione toggle sezioni
        $(document).on('click', '.alma-section-toggle', function(e) {
            e.preventDefault();
            const section = $(this).data('section');
            toggleSection(section);
        });
        
        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            // Ctrl+Shift+A per analisi rapida
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.keyCode === 65) {
                e.preventDefault();
                analyzeCurrentContent();
            }
        });
    }
    
    /**
     * Setup monitoraggio contenuto per auto-analisi
     */
    function setupContentMonitoring() {
        // Gutenberg editor
        if (typeof wp !== 'undefined' && wp.data) {
            let lastContent = '';
            setInterval(function() {
                try {
                    const editor = wp.data.select('core/editor');
                    if (editor) {
                        const content = editor.getEditedPostContent();
                        if (content !== lastContent && content.length > alma_analyzer.settings.min_content_length) {
                            lastContent = content;
                            scheduleAutoAnalysis();
                        }
                    }
                } catch (error) {
                    // Continua silenziosamente se Gutenberg non è disponibile
                }
            }, 2000);
        }
        
        // Classic Editor
        if (typeof tinyMCE !== 'undefined') {
            $(document).on('input keyup', '#content', function() {
                scheduleAutoAnalysis();
            });
            
            // TinyMCE events
            $(document).on('tinymce-editor-init', function(event, editor) {
                editor.on('input keyup', function() {
                    scheduleAutoAnalysis();
                });
            });
        }
    }
    
    /**
     * Programma auto-analisi con debounce
     */
    function scheduleAutoAnalysis() {
        if (autoAnalyzeTimer) {
            clearTimeout(autoAnalyzeTimer);
        }
        
        autoAnalyzeTimer = setTimeout(function() {
            const currentContent = getCurrentContent();
            const currentHash = hashCode(currentContent);
            
            if (currentHash !== lastContentHash && currentContent.length >= alma_analyzer.settings.min_content_length) {
                lastContentHash = currentHash;
                analyzeCurrentContent(true); // true = auto analysis
            }
        }, 3000); // Attesa 3 secondi dopo ultima modifica
    }
    
    /**
     * Analizza contenuto corrente
     */
    function analyzeCurrentContent(isAutoAnalysis = false) {
        if (isAnalyzing) {
            if (!isAutoAnalysis) {
                showNotification(alma_analyzer.messages.analyzing, 'info');
            }
            return;
        }
        
        const content = getCurrentContent();
        const title = getCurrentTitle();
        const postId = getCurrentPostId();
        
        // Validazioni
        if (!content || content.length < alma_analyzer.settings.min_content_length) {
            if (!isAutoAnalysis) {
                showNotification(alma_analyzer.messages.min_content_length, 'warning');
            }
            return;
        }
        
        isAnalyzing = true;
        updateUIState('analyzing');
        
        if (!isAutoAnalysis) {
            showNotification(alma_analyzer.messages.analyzing, 'info');
        }
        
        $.ajax({
            url: alma_analyzer.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_analyze_content',
                content: content,
                post_title: title,
                post_id: postId,
                nonce: alma_analyzer.nonce
            },
            success: function(response) {
                if (response.success) {
                    contentAnalysisData = response.data;
                    displayAnalysisResults(contentAnalysisData);
                    
                    // Ottieni suggerimenti automaticamente
                    getSuggestions(contentAnalysisData);
                    
                    if (!isAutoAnalysis) {
                        showNotification(alma_analyzer.messages.analyzed, 'success');
                    }
                } else {
                    showNotification(response.data || alma_analyzer.messages.error, 'error');
                    updateUIState('error');
                }
            },
            error: function() {
                showNotification(alma_analyzer.messages.error, 'error');
                updateUIState('error');
            },
            complete: function() {
                isAnalyzing = false;
            }
        });
    }
    
    /**
     * Ottieni suggerimenti basati sull'analisi
     */
    function getSuggestions(analysisData, maxSuggestions = null) {
        updateUIState('getting_suggestions');
        showNotification(alma_analyzer.messages.getting_suggestions, 'info');
        
        maxSuggestions = maxSuggestions || $('#alma-max-suggestions').val() || alma_analyzer.settings.max_suggestions;
        
        $.ajax({
            url: alma_analyzer.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_get_suggestions',
                analysis_data: JSON.stringify(analysisData),
                post_id: getCurrentPostId(),
                max_suggestions: maxSuggestions,
                nonce: alma_analyzer.nonce
            },
            success: function(response) {
                if (response.success) {
                    displaySuggestions(response.data.suggestions);
                    updateAnalysisSummary(response.data.analysis_summary);
                    updateAvailableLinksCount(response.data.total_available_links);
                    
                    showNotification(alma_analyzer.messages.suggestions_ready, 'success');
                    updateUIState('suggestions_ready');
                } else {
                    showNotification(response.data || alma_analyzer.messages.error, 'error');
                    updateUIState('error');
                }
            },
            error: function() {
                showNotification(alma_analyzer.messages.error, 'error');
                updateUIState('error');
            }
        });
    }
    
    /**
     * Inserisci link suggerito nell'editor
     */
    function insertSuggestedLink(suggestionData) {
        const linkId = suggestionData.linkId;
        const suggestedText = suggestionData.suggestedText || '';
        const insertPosition = suggestionData.insertPosition || 'cursor';
        
        updateUIState('inserting');
        showNotification(alma_analyzer.messages.inserting, 'info');
        
        $.ajax({
            url: alma_analyzer.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_insert_suggested_link',
                link_id: linkId,
                insert_position: insertPosition,
                custom_text: suggestedText,
                post_id: getCurrentPostId(),
                nonce: alma_analyzer.nonce
            },
            success: function(response) {
                if (response.success) {
                    const shortcode = response.data.shortcode;
                    
                    // Inserisci nell'editor
                    if (insertShortcodeInEditor(shortcode, insertPosition)) {
                        showNotification(alma_analyzer.messages.inserted, 'success');
                        
                        // Aggiorna UI
                        markSuggestionAsUsed(linkId);
                        updateUIState('suggestions_ready');
                        
                        // Log per analytics
                        logSuggestionUsage(linkId, 'inserted');
                    } else {
                        showNotification('Impossibile inserire nell\'editor', 'error');
                    }
                } else {
                    showNotification(response.data || alma_analyzer.messages.error, 'error');
                }
            },
            error: function() {
                showNotification(alma_analyzer.messages.error, 'error');
            },
            complete: function() {
                updateUIState('suggestions_ready');
            }
        });
    }
    
    /**
     * Inserisci shortcode nell'editor appropriato
     */
    function insertShortcodeInEditor(shortcode, position = 'cursor') {
        try {
            // Gutenberg Block Editor
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
                return insertInGutenberg(shortcode, position);
            }
            
            // Classic Editor TinyMCE
            if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
                return insertInTinyMCE(shortcode, position);
            }
            
            // Fallback textarea
            return insertInTextarea(shortcode, position);
            
        } catch (error) {
            console.error('Errore inserimento shortcode:', error);
            return false;
        }
    }
    
    /**
     * Inserimento in Gutenberg
     */
    function insertInGutenberg(shortcode, position) {
        try {
            const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
            
            if (selectedBlock && selectedBlock.name === 'core/paragraph') {
                // Aggiungi al blocco paragrafo esistente
                const currentContent = selectedBlock.attributes.content || '';
                const newContent = insertAtPosition(currentContent, shortcode, position);
                
                wp.data.dispatch('core/block-editor').updateBlockAttributes(
                    selectedBlock.clientId,
                    { content: newContent }
                );
            } else {
                // Crea nuovo blocco paragrafo
                const newBlock = wp.blocks.createBlock('core/paragraph', { 
                    content: shortcode 
                });
                wp.data.dispatch('core/block-editor').insertBlocks(newBlock);
            }
            
            return true;
        } catch (error) {
            console.error('Errore Gutenberg:', error);
            return false;
        }
    }
    
    /**
     * Inserimento in TinyMCE
     */
    function insertInTinyMCE(shortcode, position) {
        try {
            const editor = tinyMCE.activeEditor;
            
            if (position === 'end') {
                editor.setContent(editor.getContent() + '<p>' + shortcode + '</p>');
            } else if (position === 'beginning') {
                editor.setContent('<p>' + shortcode + '</p>' + editor.getContent());
            } else {
                // Inserisci alla posizione cursore
                editor.execCommand('mceInsertContent', false, shortcode);
            }
            
            return true;
        } catch (error) {
            console.error('Errore TinyMCE:', error);
            return false;
        }
    }
    
    /**
     * Inserimento in textarea
     */
    function insertInTextarea(shortcode, position) {
        try {
            const textarea = document.getElementById('content');
            if (!textarea) return false;
            
            const content = textarea.value;
            const newContent = insertAtPosition(content, shortcode, position);
            
            textarea.value = newContent;
            
            // Trigger change event
            const event = new Event('change', { bubbles: true });
            textarea.dispatchEvent(event);
            
            return true;
        } catch (error) {
            console.error('Errore textarea:', error);
            return false;
        }
    }
    
    /**
     * Helper per inserimento a posizione specifica
     */
    function insertAtPosition(content, shortcode, position) {
        switch (position) {
            case 'beginning':
                return shortcode + '\n\n' + content;
            case 'end':
                return content + '\n\n' + shortcode;
            case 'middle':
                const middle = Math.floor(content.length / 2);
                const nextParagraph = content.indexOf('\n\n', middle);
                const insertPoint = nextParagraph !== -1 ? nextParagraph + 2 : middle;
                return content.slice(0, insertPoint) + '\n\n' + shortcode + '\n\n' + content.slice(insertPoint);
            default: // cursor
                return content + '\n\n' + shortcode;
        }
    }
    
    /**
     * Display risultati analisi
     */
    function displayAnalysisResults(analysisData) {
        const container = $('#alma-analysis-results');
        
        let html = `
            <div class="alma-analysis-summary">
                <div class="alma-analysis-stats">
                    <div class="alma-stat">
                        <span class="alma-stat-value">${analysisData.word_count}</span>
                        <span class="alma-stat-label">Parole</span>
                    </div>
                    <div class="alma-stat">
                        <span class="alma-stat-value">${analysisData.content_type}</span>
                        <span class="alma-stat-label">Tipo</span>
                    </div>
                    <div class="alma-stat">
                        <span class="alma-stat-value">${analysisData.sentiment}</span>
                        <span class="alma-stat-label">Sentiment</span>
                    </div>
                    <div class="alma-stat">
                        <span class="alma-stat-value">${analysisData.analysis_source === 'claude_ai' ? '🧠 Claude' : '🤖 Interno'}</span>
                        <span class="alma-stat-label">AI Engine</span>
                    </div>
                </div>
            </div>
        `;
        
        // Sezione keyword espandibile
        if (analysisData.keywords && analysisData.keywords.length > 0) {
            html += `
                <div class="alma-analysis-section">
                    <h4 class="alma-section-toggle" data-section="keywords">
                        🔍 Keywords Identificate (${analysisData.keywords.length})
                        <span class="alma-toggle-icon">▼</span>
                    </h4>
                    <div class="alma-section-content" id="alma-keywords-section">
                        <div class="alma-keywords-cloud">
                            ${analysisData.keywords.map(keyword => 
                                `<span class="alma-keyword-tag">${keyword}</span>`
                            ).join('')}
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Sezione topic
        if (analysisData.topics && analysisData.topics.length > 0) {
            html += `
                <div class="alma-analysis-section">
                    <h4 class="alma-section-toggle" data-section="topics">
                        🎯 Topic Principali (${analysisData.topics.length})
                        <span class="alma-toggle-icon">▼</span>
                    </h4>
                    <div class="alma-section-content alma-collapsed" id="alma-topics-section">
                        <div class="alma-topics-list">
                            ${analysisData.topics.map(topic => 
                                `<div class="alma-topic-item">${topic}</div>`
                            ).join('')}
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Analisi Claude se disponibile
        if (analysisData.claude_analysis) {
            const claude = analysisData.claude_analysis;
            html += `
                <div class="alma-analysis-section">
                    <h4 class="alma-section-toggle" data-section="claude">
                        🧠 Insights Claude AI
                        <span class="alma-toggle-icon">▼</span>
                    </h4>
                    <div class="alma-section-content alma-collapsed" id="alma-claude-section">
                        <div class="alma-claude-insights">
                            ${claude.monetization_potential ? `
                                <div class="alma-insight-item">
                                    <strong>💰 Potenziale Monetizzazione:</strong> ${claude.monetization_potential}%
                                </div>
                            ` : ''}
                            ${claude.audience ? `
                                <div class="alma-insight-item">
                                    <strong>👥 Target Audience:</strong> ${claude.audience}
                                </div>
                            ` : ''}
                            ${claude.intent ? `
                                <div class="alma-insight-item">
                                    <strong>🎯 Intent:</strong> ${claude.intent}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Timestamp
        html += `
            <div class="alma-analysis-meta">
                <small>⏱️ Analizzato: ${new Date(analysisData.processed_at).toLocaleString()}</small>
                <small>🚀 Tempo: ${analysisData.processing_time}ms</small>
            </div>
        `;
        
        container.html(html);
        container.show();
    }
    
    /**
     * Display suggerimenti
     */
    function displaySuggestions(suggestions) {
        const container = $('#alma-suggestions-list');
        
        if (!suggestions || suggestions.length === 0) {
            container.html(`
                <div class="alma-no-suggestions">
                    <div class="alma-no-suggestions-icon">💭</div>
                    <p><strong>${alma_analyzer.messages.no_suggestions}</strong></p>
                    <p>Prova ad aggiungere più contenuto o cambiare argomento.</p>
                    <button class="button button-small" onclick="location.href='post-new.php?post_type=affiliate_link'">
                        + Crea nuovo link affiliato
                    </button>
                </div>
            `);
            return;
        }
        
        let html = '';
        
        suggestions.forEach(function(suggestion, index) {
            const relevanceColor = getRelevanceColor(suggestion.relevance_score);
            const aiScoreColor = getAIScoreColor(suggestion.ai_score);
            
            html += `
                <div class="alma-suggestion-item" data-link-id="${suggestion.link_id}">
                    <div class="alma-suggestion-header">
                        <div class="alma-suggestion-title">
                            <strong>${escapeHtml(suggestion.title)}</strong>
                            <div class="alma-suggestion-badges">
                                <span class="alma-badge alma-relevance-badge" style="background-color: ${relevanceColor}">
                                    ${suggestion.relevance_score}% rilevante
                                </span>
                                <span class="alma-badge alma-ai-score-badge" style="background-color: ${aiScoreColor}">
                                    🤖 ${suggestion.ai_score}%
                                </span>
                            </div>
                        </div>
                        <div class="alma-suggestion-stats">
                            <small>📊 ${suggestion.clicks} click</small>
                        </div>
                    </div>
                    
                    <div class="alma-suggestion-body">
                        <div class="alma-suggested-text">
                            <strong>💡 Testo suggerito:</strong>
                            <span class="alma-text-preview">"${escapeHtml(suggestion.suggested_text)}"</span>
                        </div>
                        
                        <div class="alma-suggestion-reason">
                            <small>🔍 <em>${escapeHtml(suggestion.reason)}</em></small>
                        </div>
                        
                        ${suggestion.types && suggestion.types.length > 0 ? `
                            <div class="alma-suggestion-types">
                                ${suggestion.types.map(type => 
                                    `<span class="alma-type-badge">${escapeHtml(type)}</span>`
                                ).join('')}
                            </div>
                        ` : ''}
                    </div>
                    
                    <div class="alma-suggestion-actions">
                        <button type="button" class="button button-primary alma-suggestion-insert"
                                data-link-id="${suggestion.link_id}"
                                data-suggested-text="${escapeHtml(suggestion.suggested_text)}"
                                data-insert-position="${suggestion.suggested_position}">
                            📝 Inserisci
                        </button>
                        <button type="button" class="button alma-suggestion-preview"
                                data-link-id="${suggestion.link_id}"
                                data-suggested-text="${escapeHtml(suggestion.suggested_text)}">
                            👁️ Preview
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.html(html);
        container.show();
        
        // Aggiorna contatore
        updateSuggestionsCount(suggestions.length);
    }
    
    /**
     * Aggiorna stato UI
     */
    function updateUIState(state) {
        const widget = $('#alma-content-analyzer');
        const analyzeBtn = $('#alma-analyze-content-btn');
        const refreshBtn = $('#alma-refresh-suggestions');
        
        // Reset classi stato
        widget.removeClass('alma-state-ready alma-state-analyzing alma-state-getting-suggestions alma-state-suggestions-ready alma-state-inserting alma-state-error');
        widget.addClass(`alma-state-${state}`);
        
        switch (state) {
            case 'ready':
                analyzeBtn.prop('disabled', false).html('🤖 Analizza Contenuto');
                refreshBtn.prop('disabled', true);
                break;
                
            case 'analyzing':
                analyzeBtn.prop('disabled', true).html('🔄 Analizzando...');
                refreshBtn.prop('disabled', true);
                break;
                
            case 'getting_suggestions':
                analyzeBtn.prop('disabled', false).html('🤖 Analizza Contenuto');
                refreshBtn.prop('disabled', true).html('🔄 Ottenendo...');
                break;
                
            case 'suggestions_ready':
                analyzeBtn.prop('disabled', false).html('🤖 Analizza Contenuto');
                refreshBtn.prop('disabled', false).html('🔄 Refresh');
                break;
                
            case 'inserting':
                $('.alma-suggestion-insert').prop('disabled', true);
                break;
                
            case 'error':
                analyzeBtn.prop('disabled', false).html('🤖 Riprova Analisi');
                refreshBtn.prop('disabled', false).html('🔄 Refresh');
                break;
        }
    }
    
    /**
     * Helper functions
     */
    function getCurrentContent() {
        // Prova Gutenberg prima
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
        
        // Textarea fallback
        const textarea = document.getElementById('content');
        return textarea ? textarea.value : '';
    }
    
    function getCurrentTitle() {
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data) {
            try {
                const editor = wp.data.select('core/editor');
                if (editor) {
                    return editor.getEditedPostAttribute('title');
                }
            } catch (error) {
                // Fallback
            }
        }
        
        // Classic editor
        const titleField = document.getElementById('title');
        return titleField ? titleField.value : '';
    }
    
    function getCurrentPostId() {
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data) {
            try {
                const editor = wp.data.select('core/editor');
                if (editor) {
                    return editor.getCurrentPostId();
                }
            } catch (error) {
                // Fallback
            }
        }
        
        // Classic editor
        const postIdField = document.getElementById('post_ID');
        return postIdField ? postIdField.value : 0;
    }
    
    function hashCode(str) {
        let hash = 0;
        if (str.length === 0) return hash;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return hash;
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function getRelevanceColor(score) {
        if (score >= 90) return '#00a32a';
        if (score >= 80) return '#46b450';
        if (score >= 70) return '#dba617';
        if (score >= 60) return '#ffb900';
        return '#d63638';
    }
    
    function getAIScoreColor(score) {
        if (score >= 80) return '#00a32a';
        if (score >= 60) return '#dba617';
        return '#d63638';
    }
    
    function toggleSection(sectionName) {
        const section = $(`#alma-${sectionName}-section`);
        const toggle = $(`.alma-section-toggle[data-section="${sectionName}"] .alma-toggle-icon`);
        
        if (section.hasClass('alma-collapsed')) {
            section.removeClass('alma-collapsed').slideDown(300);
            toggle.text('▲');
        } else {
            section.addClass('alma-collapsed').slideUp(300);
            toggle.text('▼');
        }
    }
    
    function markSuggestionAsUsed(linkId) {
        $(`.alma-suggestion-item[data-link-id="${linkId}"]`)
            .addClass('alma-suggestion-used')
            .find('.alma-suggestion-insert')
            .prop('disabled', true)
            .html('✅ Inserito');
    }
    
    function updateSuggestionsCount(count) {
        $('#alma-suggestions-count').text(count);
    }
    
    function updateAnalysisSummary(summary) {
        const container = $('#alma-summary-info');
        if (container.length && summary) {
            container.html(`
                <div><strong>Tipo:</strong> ${summary.content_type}</div>
                <div><strong>Topic:</strong> ${summary.main_topics}</div>
                <div><strong>Engine:</strong> ${summary.analysis_source}</div>
            `);
        }
    }
    
    function updateAvailableLinksCount(count) {
        $('#alma-available-links-count').text(count);
    }
    
    function logSuggestionUsage(linkId, action) {
        // Log locale per future analytics
        if (!window.alma_suggestion_log) {
            window.alma_suggestion_log = [];
        }
        
        window.alma_suggestion_log.push({
            timestamp: new Date().toISOString(),
            link_id: linkId,
            action: action,
            post_id: getCurrentPostId()
        });
    }
    
    function previewSuggestion(suggestionData) {
        const modal = $(`
            <div class="alma-preview-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.8);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div class="alma-preview-content" style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 500px;
                    width: 90%;
                    position: relative;
                ">
                    <button class="alma-preview-close" style="
                        position: absolute;
                        top: 15px;
                        right: 15px;
                        background: none;
                        border: none;
                        font-size: 24px;
                        cursor: pointer;
                    ">&times;</button>
                    
                    <h3>👁️ Preview Link Affiliato</h3>
                    <div class="alma-preview-link" style="
                        background: #f0f6fc;
                        padding: 15px;
                        border-radius: 6px;
                        border: 1px solid #2271b1;
                        text-align: center;
                        margin: 20px 0;
                    ">
                        <a href="#" class="affiliate-link-btn" style="
                            background: #2271b1;
                            color: white;
                            padding: 10px 20px;
                            border-radius: 4px;
                            text-decoration: none;
                            display: inline-block;
                        ">${escapeHtml(suggestionData.suggestedText)}</a>
                    </div>
                    
                    <p><strong>Shortcode generato:</strong></p>
                    <code style="background: #f6f7f7; padding: 10px; display: block; border-radius: 4px;">
                        [affiliate_link id="${suggestionData.linkId}" text="${escapeHtml(suggestionData.suggestedText)}"]
                    </code>
                    
                    <div style="text-align: right; margin-top: 20px;">
                        <button class="button alma-preview-close" style="margin-right: 10px;">Chiudi</button>
                        <button class="button button-primary alma-preview-insert"
                                data-link-id="${suggestionData.linkId}"
                                data-suggested-text="${escapeHtml(suggestionData.suggestedText)}"
                                data-insert-position="${suggestionData.insertPosition}">
                            📝 Inserisci
                        </button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        
        // Eventi modal
        modal.on('click', '.alma-preview-close', function() {
            modal.remove();
        });
        
        modal.on('click', '.alma-preview-insert', function() {
            const data = $(this).data();
            modal.remove();
            insertSuggestedLink(data);
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    }
    
    function showNotification(message, type = 'info') {
        const colors = {
            success: '#00a32a',
            error: '#d63638',
            warning: '#dba617',
            info: '#2271b1'
        };
        
        const notification = $(`
            <div class="alma-analyzer-notification" style="
                position: fixed;
                top: 32px;
                right: 20px;
                background: ${colors[type]};
                color: white;
                padding: 12px 20px;
                border-radius: 6px;
                font-weight: 600;
                z-index: 100001;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                opacity: 0;
                transform: translateY(-20px);
                transition: all 0.3s ease;
            ">
                ${message}
            </div>
        `);
        
        $('body').append(notification);
        
        setTimeout(function() {
            notification.css({
                'opacity': 1,
                'transform': 'translateY(0)'
            });
        }, 100);
        
        setTimeout(function() {
            notification.css({
                'opacity': 0,
                'transform': 'translateY(-20px)'
            });
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 3000);
    }
    
    // Esponi funzioni globali per debug
    window.almaAnalyzer = {
        analyzeContent: analyzeCurrentContent,
        getCurrentContent: getCurrentContent,
        contentAnalysisData: function() { return contentAnalysisData; }
    };
    
    console.log('🤖 ALMA Content Analyzer JavaScript caricato!');
});