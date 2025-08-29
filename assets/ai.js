/**
 * Affiliate Link Manager AI - AI Features JavaScript
 * Version: 1.3.0
 * Author: Cosè Murciano
 */

jQuery(document).ready(function($) {
    
    // 🤖 Gestisci click su "Genera Suggerimenti AI"
    $(document).on('click', '#alma-ai-suggest-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const linkId = button.data('link-id');
        const container = $('#alma-ai-suggestions-container');
        
        // Disabilita pulsante e mostra loading
        button.prop('disabled', true);
        button.html('🤖 ' + alma_ai.messages.generating);
        
        // Mostra loading nel container
        container.html(`
            <div class="alma-ai-loading" style="text-align: center; padding: 20px;">
                <div class="alma-spinner"></div>
                <p style="margin-top: 10px; color: #646970;">${alma_ai.messages.generating}</p>
            </div>
        `);
        
        // AJAX per generare suggerimenti
        $.ajax({
            url: alma_ai.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_ai_suggest_text',
                link_id: linkId,
                nonce: alma_ai.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayAISuggestions(response.data.suggestions, container);
                    showSuccessMessage(alma_ai.messages.generated);
                } else {
                    showErrorMessage(response.data || alma_ai.messages.error);
                    container.html('<p style="color:#d63638;">Errore durante la generazione. Riprova.</p>');
                }
            },
            error: function() {
                showErrorMessage(alma_ai.messages.error);
                container.html('<p style="color:#d63638;">Errore di connessione. Riprova.</p>');
            },
            complete: function() {
                // Riabilita pulsante
                button.prop('disabled', false);
                button.html('🤖 Genera Suggerimenti AI');
            }
        });
    });
    
    /**
     * Mostra suggerimenti AI generati
     */
    function displayAISuggestions(suggestions, container) {
        if (!suggestions || suggestions.length === 0) {
            container.html('<p style="color:#646970;">Nessun suggerimento generato.</p>');
            return;
        }
        
        let html = '<div class="alma-ai-suggestions">';
        
        suggestions.forEach(function(suggestion, index) {
            const confidenceColor = suggestion.confidence >= 80 ? '#00a32a' : 
                                   suggestion.confidence >= 60 ? '#dba617' : '#d63638';
            
            html += `
                <div class="alma-suggestion-item" style="
                    background: #f0f6fc;
                    padding: 15px;
                    margin: 10px 0;
                    border-radius: 6px;
                    position: relative;
                    border-left: 4px solid ${confidenceColor};
                    transition: transform 0.2s ease;
                " data-suggestion-index="${index}">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                        <strong style="color: #1d2327;">Variante ${index + 1}:</strong>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <span style="
                                background: ${confidenceColor};
                                color: white;
                                padding: 3px 8px;
                                border-radius: 12px;
                                font-size: 11px;
                                font-weight: 600;
                            ">${Math.round(suggestion.confidence)}% AI</span>
                            <button class="alma-copy-suggestion" data-text="${escapeHtml(suggestion.text)}" 
                                    style="background: #2271b1; color: white; border: none; padding: 4px 8px; 
                                           border-radius: 3px; font-size: 11px; cursor: pointer;">
                                📋 Copia
                            </button>
                        </div>
                    </div>
                    <div style="
                        background: white;
                        padding: 12px;
                        border-radius: 4px;
                        border: 1px solid #e0e0e0;
                        font-style: italic;
                        color: #1d2327;
                        font-size: 14px;
                    ">
                        "${escapeHtml(suggestion.text)}"
                    </div>
                    ${suggestion.pattern ? `<small style="color: #646970; margin-top: 8px; display: block;">Pattern: ${suggestion.pattern}</small>` : ''}
                </div>
            `;
        });
        
        html += '</div>';
        
        // Aggiungi sezione azioni
        html += `
            <div class="alma-suggestions-actions" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                <button type="button" class="button alma-start-ab-test-from-suggestions" 
                        style="margin-right: 10px;">
                    🧪 Avvia A/B Test con queste varianti
                </button>
                <button type="button" class="button alma-regenerate-suggestions">
                    🔄 Rigenera Suggerimenti
                </button>
            </div>
        `;
        
        container.html(html);
        
        // Aggiungi hover effects
        $('.alma-suggestion-item').hover(
            function() { $(this).css('transform', 'translateY(-2px)'); },
            function() { $(this).css('transform', 'translateY(0)'); }
        );
    }
    
    // 🤖 Gestisci copia suggerimento
    $(document).on('click', '.alma-copy-suggestion', function(e) {
        e.preventDefault();
        const text = $(this).data('text');
        const button = $(this);
        
        // Copia negli appunti
        if (copyToClipboard(text)) {
            const originalText = button.html();
            button.html('✅ Copiato!').css('background', '#00a32a');
            
            setTimeout(function() {
                button.html(originalText).css('background', '#2271b1');
            }, 2000);
        }
    });
    
    // 🤖 Gestisci copia shortcode generale
    $(document).on('click', '.alma-copy-btn', function(e) {
        e.preventDefault();
        const text = $(this).data('copy');
        const button = $(this);
        
        if (copyToClipboard(text)) {
            const originalText = button.html();
            button.html('✅ Copiato!').css('background', '#00a32a');
            
            setTimeout(function() {
                button.html(originalText).css('background', '#2271b1');
            }, 2000);
        }
    });
    
    // 🤖 Gestisci rigenera suggerimenti
    $(document).on('click', '.alma-regenerate-suggestions', function(e) {
        e.preventDefault();
        $('#alma-ai-suggest-btn').click();
    });
    
    // 🤖 Gestisci avvio A/B test da suggerimenti
    $(document).on('click', '.alma-start-ab-test-from-suggestions', function(e) {
        e.preventDefault();
        
        // Raccogli tutte le varianti suggerite
        const variants = [];
        $('.alma-suggestion-item').each(function() {
            const index = $(this).data('suggestion-index');
            const text = $(this).find('.alma-copy-suggestion').data('text');
            if (text) {
                variants.push({
                    index: index,
                    text: text,
                    source: 'ai_suggestion'
                });
            }
        });
        
        if (variants.length < 2) {
            alert('Servono almeno 2 varianti per avviare un A/B test.');
            return;
        }
        
        // Mostra modal per configurazione A/B test
        showABTestModal(variants);
    });
    
    // 🤖 Gestisci analisi AI completa
    $(document).on('click', '.alma-ai-analyze', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const linkId = button.data('link-id');
        
        button.prop('disabled', true).html('🤖 Analizzando...');
        
        $.ajax({
            url: alma_ai.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_ai_analyze_performance',
                link_id: linkId,
                nonce: alma_ai.nonce
            },
            success: function(response) {
                if (response.success) {
                    showAIAnalysisModal(response.data);
                } else {
                    showErrorMessage(response.data || 'Errore durante l\'analisi AI.');
                }
            },
            error: function() {
                showErrorMessage('Errore di connessione durante l\'analisi.');
            },
            complete: function() {
                button.prop('disabled', false).html('🤖 Analisi AI Completa');
            }
        });
    });
    
    // 🤖 Gestisci suggerimenti AI da pagina dettagli
    $(document).on('click', '.alma-ai-suggestions', function(e) {
        e.preventDefault();
        
        const linkId = $(this).data('link-id');
        const button = $(this);
        
        button.prop('disabled', true).html('🤖 Generando...');
        
        $.ajax({
            url: alma_ai.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_ai_suggest_text',
                link_id: linkId,
                nonce: alma_ai.nonce
            },
            success: function(response) {
                if (response.success) {
                    showAISuggestionsModal(response.data.suggestions);
                } else {
                    showErrorMessage(response.data || 'Errore durante la generazione suggerimenti.');
                }
            },
            error: function() {
                showErrorMessage('Errore di connessione.');
            },
            complete: function() {
                button.prop('disabled', false).html('🤖 Suggerimenti AI');
            }
        });
    });
    
    /**
     * Mostra modal con suggerimenti AI
     */
    function showAISuggestionsModal(suggestions) {
        const modal = $(`
            <div class="alma-modal-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.8);
                z-index: 100001;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div class="alma-modal-content" style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    position: relative;
                ">
                    <button class="alma-modal-close" style="
                        position: absolute;
                        top: 15px;
                        right: 15px;
                        background: none;
                        border: none;
                        font-size: 24px;
                        cursor: pointer;
                        color: #666;
                    ">&times;</button>
                    
                    <h2 style="margin: 0 0 20px 0;">🤖 Suggerimenti AI per il tuo Link</h2>
                    
                    <div class="suggestions-container"></div>
                    
                    <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <button class="button alma-modal-close">Chiudi</button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        
        // Popola i suggerimenti
        displayAISuggestions(suggestions, modal.find('.suggestions-container'));
        
        // Gestisci chiusura
        modal.on('click', '.alma-modal-close', function() {
            modal.fadeOut(function() { modal.remove(); });
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.fadeOut(function() { modal.remove(); });
            }
        });
    }
    
    /**
     * Mostra modal analisi AI
     */
    function showAIAnalysisModal(analysisData) {
        const modal = $(`
            <div class="alma-modal-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.8);
                z-index: 100001;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div class="alma-modal-content" style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 700px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    position: relative;
                ">
                    <button class="alma-modal-close" style="
                        position: absolute;
                        top: 15px;
                        right: 15px;
                        background: none;
                        border: none;
                        font-size: 24px;
                        cursor: pointer;
                        color: #666;
                    ">&times;</button>
                    
                    <h2 style="margin: 0 0 20px 0;">🤖 Analisi AI Completa</h2>
                    
                    <div class="analysis-content">
                        <p style="text-align: center; color: #646970; padding: 40px;">
                            Analisi AI in sviluppo... <br>
                            <small>Funzionalità disponibile nella prossima versione</small>
                        </p>
                    </div>
                    
                    <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <button class="button alma-modal-close">Chiudi</button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        
        // Gestisci chiusura
        modal.on('click', '.alma-modal-close', function() {
            modal.fadeOut(function() { modal.remove(); });
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.fadeOut(function() { modal.remove(); });
            }
        });
    }
    
    /**
     * Mostra modal configurazione A/B test
     */
    function showABTestModal(variants) {
        const modal = $(`
            <div class="alma-modal-overlay" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.8);
                z-index: 100001;
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <div class="alma-modal-content" style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    position: relative;
                ">
                    <button class="alma-modal-close" style="
                        position: absolute;
                        top: 15px;
                        right: 15px;
                        background: none;
                        border: none;
                        font-size: 24px;
                        cursor: pointer;
                        color: #666;
                    ">&times;</button>
                    
                    <h2 style="margin: 0 0 20px 0;">🧪 Configura A/B Test AI</h2>
                    
                    <div class="ab-test-config">
                        <p style="background: #f0f6fc; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                            <strong>🎯 A/B Test Automatico</strong><br>
                            L'AI testerà automaticamente le ${variants.length} varianti suggerite e determinerà la migliore basandosi sulle performance reali.
                        </p>
                        
                        <div class="variants-preview" style="margin-bottom: 20px;">
                            <h4>Varianti da testare:</h4>
                        </div>
                        
                        <div class="test-settings" style="margin-bottom: 20px;">
                            <h4>Impostazioni Test:</h4>
                            <label style="display: block; margin: 10px 0;">
                                <strong>Durata test:</strong>
                                <select id="test-duration" style="margin-left: 10px; padding: 5px;">
                                    <option value="7">7 giorni</option>
                                    <option value="14" selected>14 giorni</option>
                                    <option value="30">30 giorni</option>
                                </select>
                            </label>
                            <label style="display: block; margin: 10px 0;">
                                <strong>Traffico per variante:</strong>
                                <select id="traffic-split" style="margin-left: 10px; padding: 5px;">
                                    <option value="equal" selected>Equamente diviso</option>
                                    <option value="weighted">Peso basato su confidence AI</option>
                                </select>
                            </label>
                        </div>
                        
                        <div style="background: #fff3cd; padding: 15px; border-radius: 6px; border: 1px solid #ffeaa7; margin-bottom: 20px;">
                            <strong>⚠️ Nota:</strong> L'A/B testing automatico è una funzionalità avanzata in fase di sviluppo. 
                            Al momento puoi utilizzare manualmente i testi suggeriti dall'AI.
                        </div>
                    </div>
                    
                    <div style="text-align: right; margin-top: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <button class="button alma-modal-close" style="margin-right: 10px;">Annulla</button>
                        <button class="button button-primary" disabled>🧪 Avvia Test (Prossimamente)</button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        
        // Popola anteprima varianti
        let variantsHtml = '';
        variants.forEach(function(variant, index) {
            variantsHtml += `
                <div style="background: #f9f9f9; padding: 10px; margin: 5px 0; border-radius: 4px;">
                    <strong>Variante ${index + 1}:</strong> "${variant.text}"
                </div>
            `;
        });
        modal.find('.variants-preview').append(variantsHtml);
        
        // Gestisci chiusura
        modal.on('click', '.alma-modal-close', function() {
            modal.fadeOut(function() { modal.remove(); });
        });
        
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.fadeOut(function() { modal.remove(); });
            }
        });
    }
    
    /**
     * Copia testo negli appunti
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            // Metodo moderno
            navigator.clipboard.writeText(text);
            return true;
        } else {
            // Fallback per browser più vecchi
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                textArea.remove();
                return true;
            } catch (error) {
                textArea.remove();
                return false;
            }
        }
    }
    
    /**
     * Escape HTML per sicurezza
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Mostra messaggio di successo
     */
    function showSuccessMessage(message) {
        showNotification(message, 'success');
    }
    
    /**
     * Mostra messaggio di errore
     */
    function showErrorMessage(message) {
        showNotification(message, 'error');
    }
    
    /**
     * Mostra notifica temporanea
     */
    function showNotification(message, type = 'info') {
        const colors = {
            success: { bg: '#00a32a', text: 'white' },
            error: { bg: '#d63638', text: 'white' },
            info: { bg: '#2271b1', text: 'white' }
        };
        
        const notification = $(`
            <div class="alma-notification" style="
                position: fixed;
                top: 32px;
                right: 20px;
                background: ${colors[type].bg};
                color: ${colors[type].text};
                padding: 15px 20px;
                border-radius: 6px;
                font-weight: 600;
                z-index: 100002;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                opacity: 0;
                transform: translateY(-20px);
                transition: all 0.3s ease;
            ">
                ${message}
            </div>
        `);
        
        $('body').append(notification);
        
        // Anima l'ingresso
        setTimeout(function() {
            notification.css({
                'opacity': 1,
                'transform': 'translateY(0)'
            });
        }, 100);
        
        // Rimuovi dopo 4 secondi
        setTimeout(function() {
            notification.css({
                'opacity': 0,
                'transform': 'translateY(-20px)'
            });
            setTimeout(function() {
                notification.remove();
            }, 300);
        }, 4000);
    }
    
    // 🤖 AI Performance Score Animations
    $('.alma-ai-score').each(function() {
        const $this = $(this);
        const score = parseInt($this.find('span').text());
        
        // Aggiungi animazione pulsante basata su score
        if (score >= 80) {
            $this.css('animation', 'alma-pulse-success 2s infinite');
        } else if (score < 50) {
            $this.css('animation', 'alma-pulse-warning 3s infinite');
        }
    });
    
    // CSS Animations dinamiche
    if (!document.getElementById('alma-ai-styles')) {
        const styles = `
            <style id="alma-ai-styles">
                @keyframes alma-pulse-success {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.05); }
                }
                
                @keyframes alma-pulse-warning {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.7; }
                }
                
                .alma-spinner {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    border: 2px solid #f3f3f3;
                    border-top: 2px solid #2271b1;
                    border-radius: 50%;
                    animation: alma-spin 1s linear infinite;
                }
                
                @keyframes alma-spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .alma-suggestion-item:hover {
                    box-shadow: 0 4px 12px rgba(34, 113, 177, 0.15);
                }
                
                .alma-copy-suggestion:hover {
                    background: #135e96 !important;
                    transform: scale(1.05);
                }
                
                .alma-ai-loading {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                }
            </style>
        `;
        $('head').append(styles);
    }
    
    console.log('🤖 Affiliate AI v1.3.0: Funzionalità AI caricate!');
});