/**
 * Affiliate Link Manager AI - Mappa Geografica frontend
 *
 * Rendering con Leaflet + OpenStreetMap (nessuna API key).
 * Marker sulle località con articoli geolocalizzati; ricerca sui nomi
 * delle località; click → popup con articoli e link alla pagina elenco.
 */
(function () {
    'use strict';

    var WORLD_CENTER = [22, 8];

    function cfg() {
        return window.almaGeoMapCfg || {};
    }

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .trim();
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = String(text == null ? '' : text);
        return div.innerHTML;
    }

    function fetchJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function listPageUrl(container, ids) {
        var base = container.getAttribute('data-list-url');
        if (!base) { return ''; }
        return base + (base.indexOf('?') === -1 ? '?' : '&') + 'alma_location=' + encodeURIComponent(ids);
    }

    function popupContent(markerData, container, articles, recommendedLine) {
        var html = '<div class="alma-geo-map-info" style="max-width:300px;font-size:13px;line-height:1.45;">';
        html += '<strong style="font-size:16px;display:block;margin-bottom:1px;">📍 ' + escapeHtml(markerData.name) + '</strong>';
        html += '<span style="color:#666;">' + escapeHtml(String(markerData.count)) + (markerData.count === 1 ? ' articolo' : ' articoli') + '</span>';
        if (recommendedLine) {
            html += '<div style="margin-top:4px;font-weight:600;color:#2271b1;">🎯 ' + escapeHtml(recommendedLine) + '</div>';
        }
        if (articles === null) {
            html += '<p style="margin:10px 0 0;color:#666;">Caricamento articoli…</p>';
        } else if (articles.length) {
            html += '<div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">';
            articles.slice(0, 5).forEach(function (article) {
                var thumb = article.thumbnail
                    ? '<img src="' + escapeHtml(article.thumbnail) + '" alt="" loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;" />'
                    : '<span style="width:48px;height:48px;border-radius:6px;background:#eef1f5;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px;">📄</span>';
                html += '<a href="' + escapeHtml(article.url) + '" style="display:flex;align-items:center;gap:10px;text-decoration:none;padding:4px;border-radius:6px;">' +
                    thumb +
                    '<span style="font-weight:600;line-height:1.3;color:#1d2327;">' + escapeHtml(article.title) + '</span>' +
                    '</a>';
            });
            html += '</div>';
        }
        var pageUrl = listPageUrl(container, markerData.ids);
        if (pageUrl) {
            html += '<p style="margin:12px 0 0;text-align:center;"><a href="' + escapeHtml(pageUrl) + '" style="display:inline-block;padding:7px 14px;background:#2271b1;color:#fff;border-radius:6px;font-weight:600;text-decoration:none;">Vedi tutti gli articoli →</a></p>';
        }
        html += '</div>';
        return html;
    }

    function initMapContainer(container) {
        var zoom = parseInt(container.getAttribute('data-zoom'), 10) || 2;
        var ajaxUrl = container.getAttribute('data-ajax-url');

        var map = L.map(container, {
            center: WORLD_CENTER, // vista mondo, continenti visibili
            zoom: zoom,
            minZoom: 2,
            // Il mondo non si ripete: confini rigidi sull'intero planisfero.
            maxBounds: [[-85, -180], [85, 180]],
            maxBoundsViscosity: 1.0,
            // Lo scroll della pagina non deve zoomare per errore: zoom con
            // ctrl+rotella, doppio click o controlli.
            scrollWheelZoom: false
        });
        map.on('focus click', function () { map.scrollWheelZoom.enable(); });
        map.on('blur', function () { map.scrollWheelZoom.disable(); });

        L.tileLayer(cfg().tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            noWrap: true, // niente copie ripetute del planisfero ai bordi
            bounds: [[-85, -180], [85, 180]],
            attribution: cfg().tileAttribution || '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var entry = { container: container, map: map, markers: [] };

        fetchJson(ajaxUrl + '?action=alma_geo_map_markers').then(function (response) {
            if (!response || !response.success || !Array.isArray(response.data)) { return; }
            response.data.forEach(function (markerData) {
                var marker = L.marker([markerData.lat, markerData.lng], {
                    title: markerData.name + ' (' + markerData.count + ')'
                }).addTo(map);
                marker.almaData = markerData;
                marker.bindPopup(popupContent(markerData, container, null, ''), { maxWidth: 340 });
                marker.on('click', function () {
                    openMarker(entry, marker);
                });
                entry.markers.push(marker);
            });
            populateSearch(entry);
        }).catch(function () { /* endpoint non raggiungibile: mappa senza marker */ });

        return entry;
    }

    function openMarker(entry, marker) {
        var data = marker.almaData;
        var container = entry.container;
        var pageUrl = listPageUrl(container, data.ids);

        // Secondo click sul marker già aperto: vai direttamente alla pagina elenco.
        if (pageUrl && entry.lastOpened === marker && marker.isPopupOpen()) {
            window.location.href = pageUrl;
            return;
        }
        entry.lastOpened = marker;

        marker.setPopupContent(popupContent(data, container, null, ''));
        marker.openPopup();

        var ajaxUrl = container.getAttribute('data-ajax-url');
        fetchJson(ajaxUrl + '?action=alma_geo_map_articles&location_ids=' + encodeURIComponent(data.ids)).then(function (response) {
            var payload = response && response.success && response.data ? response.data : {};
            var articles = Array.isArray(payload.items) ? payload.items : [];
            marker.setPopupContent(popupContent(data, container, articles, payload.recommended_line || ''));
        }).catch(function () {
            marker.setPopupContent(popupContent(data, container, [], ''));
        });
    }

    function populateSearch(entry) {
        var input = document.querySelector('.alma-geo-map-search-input[data-map="' + entry.container.id + '"]');
        if (!input) { return; }
        var datalist = document.getElementById(entry.container.id + '-locations');
        if (datalist) {
            datalist.innerHTML = entry.markers.map(function (marker) {
                return '<option value="' + escapeHtml(marker.almaData.name) + '"></option>';
            }).join('');
        }
        var feedback = input.parentNode.querySelector('.alma-geo-map-search-feedback');

        function findAndFocus() {
            var term = normalize(input.value);
            if (term.length < 2) { return; }
            var exact = null;
            var partial = null;
            entry.markers.forEach(function (marker) {
                var name = normalize(marker.almaData.name);
                if (name === term && !exact) { exact = marker; }
                if (name.indexOf(term) !== -1 && !partial) { partial = marker; }
            });
            var target = exact || partial;
            if (target) {
                if (feedback) { feedback.style.display = 'none'; }
                entry.map.setView(target.getLatLng(), Math.max(entry.map.getZoom(), 8));
                openMarker(entry, target);
            } else if (feedback) {
                feedback.textContent = 'Nessuna località trovata per "' + input.value + '". Prova con un altro nome.';
                feedback.style.display = 'block';
            }
        }

        input.addEventListener('change', findAndFocus);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                findAndFocus();
            }
        });
        input.addEventListener('input', function () {
            if (feedback) { feedback.style.display = 'none'; }
            if (input.value === '') {
                entry.map.setView(WORLD_CENTER, parseInt(entry.container.getAttribute('data-zoom'), 10) || 2);
                entry.map.closePopup();
                entry.lastOpened = null;
            }
        });
    }

    function boot() {
        if (typeof L === 'undefined') { return; }
        // Le icone di default di Leaflet vengono risolte dagli asset del plugin.
        if (cfg().leafletImages) {
            L.Icon.Default.imagePath = cfg().leafletImages;
        }
        var containers = document.querySelectorAll('.alma-geo-map');
        Array.prototype.forEach.call(containers, function (container) {
            if (!container.getAttribute('data-alma-initialized')) {
                container.setAttribute('data-alma-initialized', '1');
                initMapContainer(container);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
