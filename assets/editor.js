/**
 * Affiliate Link Manager AI - Editor Integration
 * Version: 1.5
 * Author: Cosè Murciano
 */

jQuery(document).ready(function($) {
    'use strict';
    
    let selectedLinkId = null;
    let selectedLinkTitle = '';
    let searchTimer = null;
    let aiSuggestionsCache = null;

    /**
     * Recupera titolo e contenuto del post corrente
     */
    function getCurrentPostData() {
        let title = '';
        let content = '';

        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
            try {
                const editor = wp.data.select('core/editor');
                if (editor) {
                    title = editor.getEditedPostAttribute('title') || '';
                    content = editor.getEditedPostContent() || '';
                }
            } catch (e) {
                // fall back
            }
        }

        // Classic editor fallback
        if (!title) {
            title = $('#title').val() || '';
        }
        if (!content) {
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                content = tinyMCE.get('content').getContent({ format: 'text' }) || '';
            } else {
                content = $('#content').val() || '';
            }
        }

        return { title, content };
    }
    
    /**
     * Inizializza integrazione editor
     */
    function initEditorIntegration() {
        // Aggiungi pulsante all'editor
        addEditorButton();
        
        // Setup event handlers
        setupEventHandlers();
        
        // Keyboard shortcuts
        setupKeyboardShortcuts();
        
        console.log('🔗 Affiliate Link Manager AI v1.5 - Editor Integration Loaded');
    }
    
    /**
     * Aggiungi pulsante all'editor
     */
    function addEditorButton() {
        // Funzione per aggiungere il pulsante
        function insertButton() {
            // Rimuovi eventuali pulsanti esistenti per evitare duplicati
            $('#alma-insert-link-btn').remove();
            
            // Cerca container per media buttons
            let mediaButtons = $('.wp-media-buttons').first();
            if (!mediaButtons.length) {
                mediaButtons = $('#wp-content-media-buttons').first();
            }
            if (!mediaButtons.length) {
                mediaButtons = $('.media-buttons').first();
            }
            
            if (mediaButtons.length > 0) {
                const button = $('<button>', {
                    type: 'button',
                    id: 'alma-insert-link-btn',
                    class: 'button',
                    title: alma_editor.strings.button_title || 'Inserisci link affiliato (Ctrl+Shift+L)',
                    html: '<span class="dashicons dashicons-admin-links" style="margin-right:4px;"></span>' + 
                          (alma_editor.strings.button_text || '🔗 Link Affiliati')
                });
                
                mediaButtons.append(button);
                
                // Event handler per il pulsante
                button.on('click', function(e) {
                    e.preventDefault();
                    console.log('🔗 Pulsante cliccato - Apertura modal');
                    openLinkModal();
                });
                
                return true;
            }
            return false;
        }
        
        // Prova ad aggiungere il pulsante immediatamente
        if (!insertButton()) {
            // Se fallisce, riprova dopo un breve delay
            setTimeout(function() {
                if (!insertButton()) {
                    // Ultima risorsa: aggiungi dopo il caricamento completo
                    $(window).on('load', function() {
                        setTimeout(insertButton, 500);
                    });
                }
            }, 100);
        }
        
        // Per compatibilità con Gutenberg
        if (typeof wp !== 'undefined' && wp.domReady) {
            wp.domReady(function() {
                setTimeout(insertButton, 200);
            });
        }
        
        // Observer per cambiamenti DOM (per editor dinamici)
        if (window.MutationObserver) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        const mediaButtons = $('.wp-media-buttons, #wp-content-media-buttons, .media-buttons').first();
                        if (mediaButtons.length && !$('#alma-insert-link-btn').length) {
                            setTimeout(insertButton, 50);
                        }
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }
    
    /**
     * Setup event handlers
     */
    function setupEventHandlers() {
        // Unbind precedenti per evitare duplicati
        $(document).off('.alma_editor');
        
        // Ricerca link
        $(document).on('input.alma_editor', '#alma-link-search', function() {
            const searchTerm = $(this).val();
            
            // Clear previous timer
            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            
            // Debounce search
            searchTimer = setTimeout(function() {
                searchLinks(searchTerm);
            }, 300);
        });
        
        // Filtro tipologia
        $(document).on('change.alma_editor', '#alma-type-filter', function() {
            const searchTerm = $('#alma-link-search').val();
            searchLinks(searchTerm);
        });
        
        // Pulsante Cerca
        $(document).on('click.alma_editor', '#alma-search-btn', function() {
            const searchTerm = $('#alma-link-search').val();
            searchLinks(searchTerm);
        });

        // Suggerimenti AI
        $(document).on('click.alma_editor', '#alma-ai-suggest', function() {
            const data = getCurrentPostData();
            aiSuggestLinks(data.title, data.content);
        });
        
        // Selezione link
        $(document).on('click.alma_editor', '.alma-link-item', function() {
            $('.alma-link-item').removeClass('selected');
            $(this).addClass('selected');
            
            selectedLinkId = $(this).data('link-id');
            selectedLinkTitle = $(this).data('link-title');
            
            // Abilita pulsante inserimento
            $('#alma-insert-shortcode').prop('disabled', false);
            
            // Mostra opzioni shortcode
            $('.alma-shortcode-options').slideDown();
            
            // Aggiorna placeholder testo personalizzato
            $('#alma-custom-text').attr('placeholder', selectedLinkTitle);
        });
        
        // Opzioni testo
        $(document).on('change.alma_editor', 'input[name="alma_text_option"]', function() {
            const isCustom = $(this).val() === 'custom';
            $('#alma-custom-text').prop('disabled', !isCustom);

            if (isCustom) {
                $('#alma-custom-text').focus();
            }
        });

        // Abilita/disabilita opzioni pulsante
        $(document).on('change.alma_editor', '#alma-add-button', function() {
            const enabled = $(this).is(':checked');
            $('#alma-button-text, #alma-button-size, #alma-button-align').prop('disabled', !enabled);
        });

        
        // Inserisci shortcode
        $(document).on('click.alma_editor', '#alma-insert-shortcode', function() {
            if (!selectedLinkId) {
                alert(alma_editor.strings.no_selection || 'Seleziona prima un link');
                return;
            }
            
            insertShortcode();
        });
        
        // Chiudi modal
        $(document).on('click.alma_editor', '.alma-modal-close, .alma-btn-cancel', function() {
            closeModal();
        });
        
        // Chiudi cliccando fuori
        $(document).on('click.alma_editor', '#alma-link-modal', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }
    
    /**
     * Setup keyboard shortcuts
     */
    function setupKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + Shift + L per aprire modal
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.keyCode === 76) {
                e.preventDefault();
                openLinkModal();
            }
            
            // ESC per chiudere modal
            if (e.keyCode === 27 && $('#alma-link-modal').is(':visible')) {
                closeModal();
            }
        });
    }
    
    /**
     * Apri modal selezione link
     */
    function openLinkModal() {
        console.log('🔗 openLinkModal chiamata');
        
        // Crea modal se non esiste
        if (!$('#alma-link-modal').length) {
            console.log('🔗 Creazione modal');
            createModal();
        }
        
        // Reset stato
        resetModalState();
        
        // Assicurati che il modal sia nel body e non in altri container
        if (!$('body > #alma-link-modal').length) {
            console.log('🔗 Spostamento modal nel body');
            $('#alma-link-modal').appendTo('body');
        }
        
        // Forza visibilità con stili inline
        const $modal = $('#alma-link-modal');
        $modal.css({
            'display': 'block',
            'position': 'fixed',
            'top': '0',
            'left': '0',
            'right': '0',
            'bottom': '0',
            'z-index': '999999',
            'background': 'rgba(0, 0, 0, 0.7)'
        });
        
        console.log('🔗 Modal visibile:', $modal.is(':visible'));
        console.log('🔗 Modal z-index:', $modal.css('z-index'));
        
        // Focus su ricerca dopo che il modal è visibile
        setTimeout(function() {
            $('#alma-link-search').focus();
        }, 100);
        
        // Carica link iniziali o mostra suggerimenti AI memorizzati
        if (aiSuggestionsCache && aiSuggestionsCache.length > 0) {
            displaySearchResults(aiSuggestionsCache);
        } else {
            searchLinks('');
        }
    }
    
    /**
     * Crea HTML modal
     */
    function createModal() {
        // Se il modal esiste già, non ricrearlo
        if ($('#alma-link-modal').length) {
            return;
        }
        
        const modalHtml = `
        <div id="alma-link-modal" class="alma-modal-overlay" style="display:none;position:fixed !important;top:0;left:0;right:0;bottom:0;z-index:999999 !important;background:rgba(0,0,0,0.7);">
            <div class="alma-modal-content alma-link-selector" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-width:800px;width:90%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;z-index:999999;">
                <div class="alma-modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:20px 25px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;">
                    <h2 style="margin:0;color:white;font-size:20px;">🔗 Inserisci Link Affiliato</h2>
                    <button class="alma-modal-close" type="button" style="background:none;border:none;color:white;font-size:28px;cursor:pointer;padding:0;width:30px;height:30px;line-height:1;">&times;</button>
                </div>
                
                <div class="alma-search-section" style="padding:20px 25px;background:#f9f9f9;border-bottom:1px solid #e0e0e0;">
                    <div class="alma-search-box" style="position:relative;margin-bottom:15px;">
                        <input type="text" 
                               id="alma-link-search" 
                               placeholder="${alma_editor.strings.search_placeholder || 'Cerca link affiliato...'}"
                               autocomplete="off"
                               style="width:100%;padding:12px 45px 12px 15px;font-size:14px;border:2px solid #ddd;border-radius:6px;">
                        <span class="dashicons dashicons-search" style="position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#999;"></span>
                    </div>
                    
                    <div class="alma-search-filters" style="display:flex;gap:10px;align-items:center;">
                        <select id="alma-type-filter" style="flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                            <option value="">Tutte le tipologie</option>
                        </select>
                        <button type="button" id="alma-search-btn" class="button">
                            Cerca
                        </button>
                        <button type="button" id="alma-ai-suggest" class="button">
                            Suggerisci AI
                        </button>
                    </div>
                </div>
                
                <div class="alma-results-section" style="flex:1;overflow-y:auto;padding:20px 25px;background:white;">
                    <div id="alma-search-results">
                        <div class="alma-loading" style="text-align:center;padding:40px;color:#666;">
                            <span class="spinner is-active" style="float:none;margin:0 auto 20px;"></span>
                            <p>${alma_editor.strings.loading || 'Caricamento...'}</p>
                        </div>
                    </div>
                </div>
                
                <div class="alma-shortcode-options" style="display:none;padding:20px 25px;background:#f9f9f9;border-top:1px solid #e0e0e0;">
                    <h3 style="margin:0 0 15px 0;font-size:14px;color:#23282d;">⚙️ Opzioni Shortcode</h3>

                    <div class="alma-option-row" style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                        <label style="min-width:150px;font-weight:600;color:#23282d;">Usa immagine in primo piano:</label>
                        <input type="checkbox" id="alma-use-img">
                    </div>

                    <div class="alma-option-row alma-fields-row" style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                        <label style="min-width:150px;font-weight:600;color:#23282d;">Elementi da includere:</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:5px;">
                            <input type="checkbox" class="alma-field-option" value="title">
                            Titolo
                        </label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:5px;">
                            <input type="checkbox" class="alma-field-option" value="content">
                            Contenuto
                        </label>
                    </div>

                    <div class="alma-option-row" style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                        <label style="min-width:150px;font-weight:600;color:#23282d;">Testo del link:</label>
                        <div class="alma-radio-group" style="display:flex;gap:20px;">
                            <label style="font-weight:normal;display:flex;align-items:center;gap:5px;">
                                <input type="radio" name="alma_text_option" value="auto" checked>
                                Usa titolo link
                            </label>
                            <label style="font-weight:normal;display:flex;align-items:center;gap:5px;">
                                <input type="radio" name="alma_text_option" value="custom">
                                Testo personalizzato
                            </label>
                        </div>
                    </div>

                    <div class="alma-option-row" style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                        <label for="alma-custom-text" style="min-width:150px;font-weight:600;color:#23282d;">Testo personalizzato:</label>
                        <input type="text"
                               id="alma-custom-text"
                               placeholder="Es: Clicca qui per l'offerta"
                               disabled
                               style="flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                    </div>

                    <div class="alma-option-row" style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">
                        <label style="min-width:150px;font-weight:600;color:#23282d;">Pulsante CTA:</label>
                        <input type="checkbox" id="alma-add-button">
                        <select id="alma-button-size" style="margin-left:10px;">
                            <option value="small">Piccolo</option>
                            <option value="medium" selected>Medio</option>
                            <option value="large">Grande</option>
                        </select>
                        <select id="alma-button-align" style="margin-left:10px;">
                            <option value="left">Sinistra</option>
                            <option value="center">Centro</option>
                            <option value="right">Destra</option>
                        </select>
                        <input type="text" id="alma-button-text" placeholder="Testo pulsante" style="flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                    </div>

                    <div class="alma-option-row" style="display:flex;align-items:center;gap:15px;">
                        <label for="alma-custom-class" style="min-width:150px;font-weight:600;color:#23282d;">Classe CSS:</label>
                        <input type="text"
                               id="alma-custom-class"
                               value="affiliate-link-btn"
                               placeholder="affiliate-link-btn"
                               style="flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;">
                    </div>
                </div>
                
                <div class="alma-modal-footer" style="display:flex;justify-content:flex-end;gap:10px;padding:20px 25px;background:white;border-top:1px solid #e0e0e0;">
                    <button type="button" id="alma-insert-shortcode" class="button button-primary" disabled style="min-width:100px;">
                        ${alma_editor.strings.insert || 'Inserisci'}
                    </button>
                    <button type="button" class="button alma-btn-cancel" style="min-width:100px;">
                        Annulla
                    </button>
                </div>
            </div>
        </div>`;
        
        $('body').append(modalHtml);

        // Disabilita campi pulsante inizialmente
        $('#alma-button-text, #alma-button-size, #alma-button-align').prop('disabled', true);

        // Carica le tipologie dopo aver creato il modal
        loadLinkTypes();
    }
    
    /**
     * Carica tipologie link tramite AJAX
     */
    function loadLinkTypes() {
        $.ajax({
            url: alma_editor.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_get_link_types',
                nonce: alma_editor.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    let optionsHtml = '<option value="">Tutte le tipologie</option>';
                    response.data.forEach(function(type) {
                        optionsHtml += `<option value="${type.id}">${escapeHtml(type.name)}</option>`;
                    });
                    $('#alma-type-filter').html(optionsHtml);
                }
            }
        });
    }
    
    /**
     * Reset stato modal
     */
    function resetModalState() {
        selectedLinkId = null;
        selectedLinkTitle = '';
        $('#alma-link-search').val('');
        $('#alma-type-filter').val('');
        $('#alma-custom-text').val('').prop('disabled', true);
        $('#alma-custom-class').val('affiliate-link-btn');
        $('input[name="alma_text_option"][value="auto"]').prop('checked', true).prop('disabled', false);
        $('#alma-use-img').prop('checked', false);
        $('.alma-field-option').prop('checked', false);
        $('#alma-add-button').prop('checked', false);
        $('#alma-button-text').val('').prop('disabled', true);
        $('#alma-button-size').val('medium').prop('disabled', true);
        $('#alma-button-align').val('left').prop('disabled', true);
        $('#alma-insert-shortcode').prop('disabled', true);
        $('.alma-shortcode-options').hide();
        $('.alma-link-item').removeClass('selected');
    }
    
    /**
     * Cerca link affiliati
     */
    function searchLinks(searchTerm) {
        const typeFilter = $('#alma-type-filter').val();
        
        // Mostra loading
        $('#alma-search-results').html(`
            <div class="alma-loading" style="text-align:center;padding:40px;color:#666;">
                <span class="spinner is-active" style="float:none;margin:0 auto 20px;"></span>
                <p>${alma_editor.strings.loading || 'Caricamento...'}</p>
            </div>
        `);
        
        // AJAX request
        $.ajax({
            url: alma_editor.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_search_links',
                nonce: alma_editor.nonce,
                search: searchTerm,
                type_filter: typeFilter
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    aiSuggestionsCache = response.data;
                    displaySearchResults(response.data);
                } else if (response.success) {
                    displayNoResults();
                } else {
                    displayError(response.data || 'Errore sconosciuto');
                }
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                displayError(msg || 'Errore durante la richiesta');
            }
        });
    }

    /**
     * Suggerisci link tramite AI
     */
    function aiSuggestLinks(title, content) {
        // Mostra loading
        $('#alma-search-results').html(`
            <div class="alma-loading" style="text-align:center;padding:40px;color:#666;">
                <span class="spinner is-active" style="float:none;margin:0 auto 20px;"></span>
                <p>${alma_editor.strings.loading || 'Caricamento...'}</p>
            </div>
        `);

        $.ajax({
            url: alma_editor.ajax_url,
            type: 'POST',
            data: {
                action: 'alma_ai_suggest_links',
                nonce: alma_editor.nonce,
                title: title,
                content: content
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    displaySearchResults(response.data);
                } else {
                    displayNoResults();
                }
            },
            error: function(xhr) {
                const msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
                displayError(msg);
            }
        });
    }
    
    /**
     * Mostra risultati ricerca
     */
    function displaySearchResults(links) {
        let html = '<div class="alma-links-list" style="display:flex;flex-direction:column;gap:10px;">';
        
        links.forEach(function(link) {
            const types = link.types ? link.types.join(', ') : '';
            const clicks = link.clicks || 0;
            const usage = link.usage ? link.usage.post_count : 0;
            const score = typeof link.score !== 'undefined' ? Math.round(link.score) : null;

            html += `
            <div class="alma-link-item"
                 data-link-id="${link.id}"
                 data-link-title="${escapeHtml(link.title)}"
                 style="display:flex;justify-content:space-between;align-items:center;padding:15px 20px;background:white;border:2px solid #e0e0e0;border-radius:6px;cursor:pointer;transition:all 0.2s;">

                <div class="alma-link-main" style="flex:1;min-width:0;">
                    <strong class="alma-link-title" style="display:block;font-size:14px;font-weight:600;color:#23282d;margin-bottom:5px;">${escapeHtml(link.title)}</strong>
                    ${types ? `<span class="alma-link-type" style="display:inline-block;padding:2px 8px;background:#e0e0e0;color:#666;font-size:11px;border-radius:3px;">${escapeHtml(types)}</span>` : ''}
                    <div class="alma-link-meta" style="margin-top:5px;">
                        <span class="alma-link-url" title="${escapeHtml(link.url || '')}" style="color:#666;font-size:12px;">
                            ${link.url ? escapeHtml(truncateUrl(link.url)) : 'URL non configurato'}
                        </span>
                    </div>
                </div>

                <div class="alma-link-stats" style="display:flex;gap:15px;align-items:center;color:#666;font-size:12px;">
                    ${score !== null ? `<span title="Coerenza con il contenuto" style="display:flex;align-items:center;gap:4px;"><span class="dashicons dashicons-thumbs-up"></span> ${score}%</span>` : ''}
                    <span title="Click totali" style="display:flex;align-items:center;gap:4px;">
                        <span class="dashicons dashicons-chart-bar"></span> ${clicks}
                    </span>
                    <span title="Utilizzi" style="display:flex;align-items:center;gap:4px;">
                        <span class="dashicons dashicons-admin-page"></span> ${usage}
                    </span>
                </div>
            </div>`;
        });
        
        html += '</div>';
        
        $('#alma-search-results').html(html);
    }
    
    /**
     * Mostra messaggio nessun risultato
     */
    function displayNoResults() {
        $('#alma-search-results').html(`
            <div class="alma-no-results" style="text-align:center;padding:40px;color:#666;">
                <span class="dashicons dashicons-search" style="display:block;margin:0 auto 15px;font-size:48px;opacity:0.3;width:48px;height:48px;"></span>
                <p style="margin:0 0 10px 0;font-size:16px;">${alma_editor.strings.no_results || 'Nessun link trovato'}</p>
                <p style="margin:0;font-size:14px;opacity:0.8;"><small>Prova con altri termini di ricerca</small></p>
            </div>
        `);
    }
    
    /**
     * Mostra errore
     */
    function displayError(message) {
        const msg = message || alma_editor.strings.error || 'Si è verificato un errore';
        $('#alma-search-results').html(`
            <div class="alma-error" style="text-align:center;padding:40px;color:#666;">
                <span class="dashicons dashicons-warning" style="display:block;margin:0 auto 15px;font-size:48px;color:#d63638;width:48px;height:48px;"></span>
                <p style="margin:0 0 10px 0;font-size:16px;">${escapeHtml(msg)}</p>
                <p style="margin:0;font-size:14px;opacity:0.8;"><small>Riprova tra qualche secondo</small></p>
            </div>
        `);
    }
    
    /**
     * Inserisci shortcode nell'editor
     */
    function insertShortcode() {
        let shortcode = `[affiliate_link id="${selectedLinkId}"`;

        // Opzione immagine
        const useImg = $('#alma-use-img').is(':checked');
        if (useImg) {
            shortcode += ' img="yes"';
        }
        const fields = $('.alma-field-option:checked').map(function() { return $(this).val(); }).get();
        if (fields.length) {
            shortcode += ` fields="${fields.join(',')}"`;
        }

        // Aggiungi testo personalizzato se selezionato
        const textOption = $('input[name="alma_text_option"]:checked').val();
        if (textOption === 'custom') {
            const customText = $('#alma-custom-text').val().trim();
            if (customText) {
                shortcode += ` text="${escapeShortcodeAttr(customText)}"`;
            }
        }

        // Aggiungi pulsante CTA se selezionato
        const addButton = $('#alma-add-button').is(':checked');
        if (addButton) {
            shortcode += ' button="yes"';
            const btnSize = $('#alma-button-size').val();
            if (btnSize) {
                shortcode += ` button_size="${btnSize}"`;
            }
            const btnText = $('#alma-button-text').val().trim();
            if (btnText) {
                shortcode += ` button_text="${escapeShortcodeAttr(btnText)}"`;
            }
            const btnAlign = $('#alma-button-align').val();
            if (btnAlign && btnAlign !== 'left') {
                shortcode += ` button_align="${btnAlign}"`;
            }
        }

        // Aggiungi classe personalizzata se diversa dal default
        const customClass = $('#alma-custom-class').val().trim();
        if (customClass && customClass !== 'affiliate-link-btn') {
            shortcode += ` class="${escapeShortcodeAttr(customClass)}"`;
        }
        
        shortcode += ']';
        
        // Inserisci nell'editor appropriato
        if (insertIntoEditor(shortcode)) {
            // Mostra successo e chiudi modal
            showSuccessMessage();
            setTimeout(function() {
                closeModal();
            }, 1500);
        } else {
            alert(alma_editor.strings.insert_error || 'Errore durante l\'inserimento');
        }
    }
    
    /**
     * Inserisci nell'editor (Gutenberg o Classic)
     */
    function insertIntoEditor(shortcode) {
        try {
            // Prova con Gutenberg Block Editor
            if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
                const selectedBlock = wp.data.select('core/block-editor').getSelectedBlock();
                
                if (selectedBlock && selectedBlock.name === 'core/paragraph') {
                    // Aggiungi al blocco paragrafo esistente
                    const currentContent = selectedBlock.attributes.content || '';
                    const newContent = currentContent + ' ' + shortcode;
                    
                    wp.data.dispatch('core/block-editor').updateBlockAttributes(
                        selectedBlock.clientId,
                        { content: newContent }
                    );
                } else {
                    // Crea nuovo blocco shortcode
                    const block = wp.blocks.createBlock('core/shortcode', {
                        text: shortcode
                    });
                    wp.data.dispatch('core/block-editor').insertBlocks(block);
                }
                return true;
            }
            
            // Prova con Classic Editor (TinyMCE)
            if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
                tinyMCE.activeEditor.execCommand('mceInsertContent', false, shortcode);
                return true;
            }
            
            // Fallback: inserisci in textarea
            const textarea = document.getElementById('content');
            if (textarea) {
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const text = textarea.value;
                
                textarea.value = text.substring(0, start) + shortcode + text.substring(end);
                textarea.selectionStart = textarea.selectionEnd = start + shortcode.length;
                
                // Trigger change event
                const event = new Event('input', { bubbles: true });
                textarea.dispatchEvent(event);
                
                return true;
            }
            
            return false;
            
        } catch (error) {
            console.error('Errore inserimento shortcode:', error);
            return false;
        }
    }
    
    /**
     * Mostra messaggio di successo
     */
    function showSuccessMessage() {
        const message = $(`
            <div class="alma-success-toast" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#00a32a;color:white;padding:15px 25px;border-radius:6px;font-weight:600;z-index:1000000;box-shadow:0 4px 12px rgba(0,0,0,0.3);display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-yes"></span>
                Link inserito con successo!
            </div>
        `);
        
        $('body').append(message);
        
        setTimeout(function() {
            message.fadeOut(function() {
                message.remove();
            });
        }, 2000);
    }
    
    /**
     * Chiudi modal
     */
    function closeModal() {
        $('#alma-link-modal').fadeOut(200, function() {
            $(this).hide();
        });
        resetModalState();
    }
    
    /**
     * Helper functions
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
    
    function escapeShortcodeAttr(text) {
        return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/\[/g, '&#91;').replace(/\]/g, '&#93;');
    }
    
    function truncateUrl(url, maxLength = 40) {
        if (!url || url.length <= maxLength) return url;
        return url.substring(0, maxLength) + '...';
    }
    
    // Inizializza quando DOM è pronto
    initEditorIntegration();
});