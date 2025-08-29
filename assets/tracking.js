/**
 * Affiliate Link Manager AI - Frontend Tracking
 * Version: 1.3.1
 * Tracking asincrono per link affiliati senza redirect
 */

(function($) {
    'use strict';
    
    // Controlla se jQuery è disponibile
    if (typeof $ === 'undefined') {
        console.warn('ALMA: jQuery non disponibile, tracking disabilitato');
        return;
    }
    
    // Variabili globali per tracking
    let isTracking = false;
    let trackedLinks = new Set();
    
    /**
     * Inizializza il tracking quando il DOM è pronto
     */
    $(document).ready(function() {
        initAffiliateTracking();
        
        // Re-inizializza per contenuti caricati dinamicamente
        $(document).on('DOMNodeInserted', function(e) {
            if ($(e.target).find('.alma-affiliate-link').length > 0) {
                initAffiliateTracking();
            }
        });
    });
    
    /**
     * Inizializza tracking per tutti i link affiliati
     */
    function initAffiliateTracking() {
        // Trova tutti i link affiliati con data-track="1"
        $('.alma-affiliate-link[data-track="1"]').each(function() {
            const $link = $(this);
            const linkId = $link.data('link-id');
            
            // Evita doppio binding
            if (trackedLinks.has(linkId + '_' + $link.get(0))) {
                return;
            }
            
            trackedLinks.add(linkId + '_' + $link.get(0));
            
            // Bind eventi click
            $link.on('click', function(e) {
                // Non bloccare il click, traccia in background
                trackAffiliateClick(linkId, $link.attr('href'));
            });
            
            // Traccia anche right-click (apri in nuova scheda)
            $link.on('contextmenu', function(e) {
                trackAffiliateClick(linkId, $link.attr('href'), 'contextmenu');
            });
            
            // Traccia middle-click (apri in nuova scheda)
            $link.on('mousedown', function(e) {
                if (e.which === 2) {
                    trackAffiliateClick(linkId, $link.attr('href'), 'middleclick');
                }
            });
        });
        
        // Log per debug (rimuovere in produzione)
        if (window.alma_debug) {
            console.log('ALMA: Inizializzati ' + $('.alma-affiliate-link[data-track="1"]').length + ' link affiliati');
        }
    }
    
    /**
     * Traccia click su link affiliato
     */
    function trackAffiliateClick(linkId, url, clickType = 'click') {
        // Evita tracking multipli simultanei
        if (isTracking) {
            return;
        }
        
        // Controlla se tracking è abilitato per utenti non loggati
        if (!alma_tracking.track_logged_out && !isUserLoggedIn()) {
            return;
        }
        
        isTracking = true;
        
        // Prepara dati da inviare
        const trackingData = {
            action: 'alma_track_click',
            nonce: alma_tracking.nonce,
            link_id: linkId,
            referrer: document.referrer || window.location.href,
            click_type: clickType,
            timestamp: Date.now()
        };
        
        // Invia richiesta AJAX asincrona
        $.ajax({
            url: alma_tracking.ajax_url,
            type: 'POST',
            data: trackingData,
            timeout: 2000, // Timeout breve per non rallentare navigazione
            success: function(response) {
                if (response.success) {
                    // Tracking completato con successo
                    if (window.alma_debug) {
                        console.log('ALMA: Click tracciato per link #' + linkId);
                    }
                    
                    // Trigger evento personalizzato per integrazioni
                    $(document).trigger('alma:click_tracked', {
                        link_id: linkId,
                        url: url,
                        response: response.data
                    });
                    
                    // Se c'è Google Analytics, invia evento
                    if (typeof gtag !== 'undefined') {
                        gtag('event', 'affiliate_click', {
                            'event_category': 'Affiliate',
                            'event_label': 'Link #' + linkId,
                            'value': 1
                        });
                    }
                    
                    // Se c'è Google Analytics Universal
                    if (typeof ga !== 'undefined') {
                        ga('send', 'event', 'Affiliate', 'Click', 'Link #' + linkId, 1);
                    }
                }
            },
            error: function(xhr, status, error) {
                // Non bloccare navigazione per errori di tracking
                if (window.alma_debug) {
                    console.warn('ALMA: Errore tracking click', error);
                }
            },
            complete: function() {
                // Reset flag tracking
                setTimeout(function() {
                    isTracking = false;
                }, 100);
            }
        });
        
        // Tracking locale per statistiche immediate (localStorage)
        try {
            const localStats = JSON.parse(localStorage.getItem('alma_local_stats') || '{}');
            const today = new Date().toISOString().split('T')[0];
            
            if (!localStats[today]) {
                localStats[today] = {};
            }
            
            if (!localStats[today][linkId]) {
                localStats[today][linkId] = 0;
            }
            
            localStats[today][linkId]++;
            
            localStorage.setItem('alma_local_stats', JSON.stringify(localStats));
        } catch (e) {
            // Ignora errori localStorage (privacy mode, etc)
        }
    }
    
    /**
     * Verifica se utente è loggato (basato su cookie WordPress)
     */
    function isUserLoggedIn() {
        // Cerca cookie WordPress di login
        return document.cookie.indexOf('wordpress_logged_in_') !== -1;
    }
    
    /**
     * API Pubblica per tracking manuale
     */
    window.ALMA = window.ALMA || {};
    
    window.ALMA.trackClick = function(linkId) {
        if (!linkId) {
            console.error('ALMA: ID link richiesto per tracking manuale');
            return;
        }
        
        trackAffiliateClick(linkId, '', 'manual');
    };
    
    window.ALMA.getLocalStats = function() {
        try {
            return JSON.parse(localStorage.getItem('alma_local_stats') || '{}');
        } catch (e) {
            return {};
        }
    };
    
    window.ALMA.clearLocalStats = function() {
        try {
            localStorage.removeItem('alma_local_stats');
            console.log('ALMA: Statistiche locali cancellate');
        } catch (e) {
            console.error('ALMA: Errore cancellazione statistiche', e);
        }
    };
    
    /**
     * Supporto per Lazy Loading
     */
    if ('IntersectionObserver' in window) {
        const linkObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    const $link = $(entry.target);
                    if ($link.hasClass('alma-affiliate-link') && !$link.data('alma-observed')) {
                        $link.data('alma-observed', true);
                        
                        // Log impression per future analytics
                        if (window.alma_debug) {
                            console.log('ALMA: Link in viewport #' + $link.data('link-id'));
                        }
                    }
                }
            });
        }, {
            rootMargin: '50px'
        });
        
        // Osserva tutti i link affiliati
        $(document).ready(function() {
            $('.alma-affiliate-link').each(function() {
                linkObserver.observe(this);
            });
        });
    }
    
    /**
     * Gestione errori globale per debug
     */
    if (window.alma_debug) {
        window.addEventListener('error', function(e) {
            if (e.filename && e.filename.indexOf('tracking.js') !== -1) {
                console.error('ALMA Tracking Error:', e.message, 'at line', e.lineno);
            }
        });
    }
    
})(jQuery);