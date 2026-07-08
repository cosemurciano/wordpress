/**
 * Mappa delle località di fine articolo.
 * Inizializzazione pigra: la mappa Leaflet viene costruita solo quando il
 * contenitore entra (quasi) nel viewport, così il caricamento della pagina
 * non paga nulla. Marker a cerchio (nessuna immagine da caricare), popup con
 * pulsante "Apri in Google Maps" in nuova scheda.
 */
(function () {
    'use strict';

    function initMap(el) {
        if (el.dataset.almaMapReady === '1' || typeof window.L === 'undefined') {
            return;
        }
        el.dataset.almaMapReady = '1';

        var locations;
        try {
            locations = JSON.parse(el.getAttribute('data-locations') || '[]');
        } catch (e) {
            locations = [];
        }
        if (!locations.length) {
            return;
        }

        var accent = el.getAttribute('data-accent') || '#1a6ee0';
        var openLabel = el.getAttribute('data-open-label') || 'Apri in Google Maps';
        var linksLabel = el.getAttribute('data-links-label') || 'Tour e attività';
        var map = window.L.map(el, { scrollWheelZoom: false });

        // Tracking dei link affiliati nei popup: i popup nascono dopo il
        // binding di tracking.js, quindi il click viene registrato qui via
        // window.ALMA.trackClick (source: article_map).
        map.on('popupopen', function (e) {
            var root = e.popup.getElement();
            if (!root) { return; }
            root.querySelectorAll('a[data-link-id]').forEach(function (a) {
                if (a.dataset.almaBound === '1') { return; }
                a.dataset.almaBound = '1';
                a.addEventListener('click', function () {
                    if (window.ALMA && typeof window.ALMA.trackClick === 'function') {
                        window.ALMA.trackClick(a.getAttribute('data-link-id'), 'article_map');
                    }
                });
            });
        });
        window.L.tileLayer(el.getAttribute('data-tile-url'), {
            attribution: el.getAttribute('data-tile-attribution'),
            maxZoom: 18
        }).addTo(map);

        var bounds = [];
        locations.forEach(function (loc) {
            if (typeof loc.lat !== 'number' || typeof loc.lng !== 'number') {
                return;
            }
            var isPrimary = !!loc.primary;
            var marker = window.L.circleMarker([loc.lat, loc.lng], {
                radius: isPrimary ? 11 : 8,
                color: '#ffffff',
                weight: 2,
                fillColor: isPrimary ? accent : '#5f6b7a',
                fillOpacity: 0.95
            }).addTo(map);

            var html = '<div style="min-width:190px;max-width:250px;text-align:center;">'
                + '<div style="font-size:15px;font-weight:700;margin-bottom:2px;">' + escapeHtml(loc.name || '') + '</div>'
                + (loc.country ? '<div style="color:#5f6b7a;font-size:12px;margin-bottom:8px;">' + escapeHtml(loc.country) + '</div>' : '')
                + '<a href="' + escapeAttr(loc.gmaps || '#') + '" target="_blank" rel="noopener nofollow" '
                + 'style="display:inline-block;background:' + escapeAttr(accent) + ';color:#fff;padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;">'
                + escapeHtml(openLabel) + ' ↗</a>';
            if (Array.isArray(loc.links) && loc.links.length) {
                html += '<div style="border-top:1px solid #e6e8ef;margin:10px -4px 0;padding:8px 4px 0;text-align:left;">'
                    + '<div style="font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#5f6b7a;margin-bottom:5px;">' + escapeHtml(linksLabel) + '</div>';
                loc.links.forEach(function (link) {
                    if (!link || !link.url) { return; }
                    html += '<a href="' + escapeAttr(link.url) + '" data-link-id="' + escapeAttr(String(link.id || '')) + '" '
                        + 'target="_blank" rel="sponsored noopener" '
                        + 'style="display:block;color:' + escapeAttr(accent) + ';font-size:13px;font-weight:600;line-height:1.35;text-decoration:none;margin:0 0 6px;">'
                        + '🎟 ' + escapeHtml(link.title || '') + '</a>';
                });
                html += '</div>';
            }
            html += '</div>';
            marker.bindPopup(html);
            bounds.push([loc.lat, loc.lng]);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], 10);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [36, 36], maxZoom: 11 });
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    /**
     * Con i temi a builder (BeTheme) the_content viene applicato più volte e
     * il blocco mappa può finire in un frammento sbagliato (posizione e
     * larghezza errate). Prima dell'inizializzazione il wrapper viene
     * SPOSTATO in coda al contenitore principale del contenuto del tema.
     */
    function relocate() {
        var wrap = document.querySelector('.alma-article-map-wrap');
        if (!wrap) {
            return;
        }
        var selectors = ['.the_content_wrapper', '.entry-content', '.post-content', 'article .content', 'article'];
        for (var i = 0; i < selectors.length; i++) {
            var target = document.querySelector(selectors[i]);
            if (target && !target.contains(wrap)) {
                target.appendChild(wrap);
                return;
            }
            if (target && target.lastElementChild !== wrap) {
                target.appendChild(wrap); // già dentro ma non in coda
                return;
            }
            if (target) {
                return; // già dentro e in coda: posizione corretta
            }
        }
    }

    function setup() {
        relocate();
        var maps = document.querySelectorAll('.alma-article-map');
        if (!maps.length) {
            return;
        }
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        observer.unobserve(entry.target);
                        initMap(entry.target);
                    }
                });
            }, { rootMargin: '400px 0px' });
            maps.forEach(function (el) { observer.observe(el); });
        } else {
            maps.forEach(initMap);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
