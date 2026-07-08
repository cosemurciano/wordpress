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
        var map = window.L.map(el, { scrollWheelZoom: false });
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

            var html = '<div style="min-width:170px;text-align:center;">'
                + '<div style="font-size:15px;font-weight:700;margin-bottom:2px;">' + escapeHtml(loc.name || '') + '</div>'
                + (loc.country ? '<div style="color:#5f6b7a;font-size:12px;margin-bottom:8px;">' + escapeHtml(loc.country) + '</div>' : '')
                + '<a href="' + escapeAttr(loc.gmaps || '#') + '" target="_blank" rel="noopener nofollow" '
                + 'style="display:inline-block;background:' + escapeAttr(accent) + ';color:#fff;padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;">'
                + escapeHtml(openLabel) + ' ↗</a></div>';
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

    function setup() {
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
