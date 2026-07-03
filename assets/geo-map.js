/**
 * Affiliate Link Manager AI - Mappa Geografica frontend
 *
 * Inizializzata dal callback del loader Google Maps (almaGeoMapInit).
 * Marker sulle località con articoli geolocalizzati; ricerca sui nomi
 * delle località (nessuna API aggiuntiva); click → popup con articoli
 * e link alla pagina elenco.
 */
(function () {
    'use strict';

    var maps = [];

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

    function infoWindowContent(marker, container, articles) {
        var html = '<div class="alma-geo-map-info" style="max-width:300px;font-size:13px;line-height:1.45;">';
        html += '<strong style="font-size:15px;display:block;margin-bottom:2px;">📍 ' + escapeHtml(marker.name) + '</strong>';
        html += '<span style="color:#666;">' + escapeHtml(String(marker.count)) + (marker.count === 1 ? ' articolo' : ' articoli') + '</span>';
        if (articles === null) {
            html += '<p style="margin:8px 0 0;color:#666;">Caricamento articoli…</p>';
        } else if (articles.length) {
            html += '<ul style="margin:8px 0 0;padding-left:18px;">';
            articles.slice(0, 5).forEach(function (article) {
                html += '<li style="margin-bottom:4px;"><a href="' + escapeHtml(article.url) + '">' + escapeHtml(article.title) + '</a></li>';
            });
            html += '</ul>';
        }
        var pageUrl = listPageUrl(container, marker.ids);
        if (pageUrl) {
            html += '<p style="margin:10px 0 0;"><a href="' + escapeHtml(pageUrl) + '" style="font-weight:600;">Vedi tutti gli articoli →</a></p>';
        }
        html += '</div>';
        return html;
    }

    function initMapContainer(container) {
        var zoom = parseInt(container.getAttribute('data-zoom'), 10) || 2;
        var ajaxUrl = container.getAttribute('data-ajax-url');
        var map = new google.maps.Map(container, {
            zoom: zoom,
            center: { lat: 22, lng: 8 }, // vista mondo, continenti visibili
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            gestureHandling: 'cooperative'
        });
        var infoWindow = new google.maps.InfoWindow();
        var entry = { container: container, map: map, markers: [], infoWindow: infoWindow };
        maps.push(entry);

        fetchJson(ajaxUrl + '?action=alma_geo_map_markers').then(function (response) {
            if (!response || !response.success || !Array.isArray(response.data)) { return; }
            response.data.forEach(function (markerData) {
                var marker = new google.maps.Marker({
                    map: map,
                    position: { lat: markerData.lat, lng: markerData.lng },
                    title: markerData.name + ' (' + markerData.count + ')'
                });
                marker.almaData = markerData;
                marker.addListener('click', function () {
                    openMarker(entry, marker);
                });
                entry.markers.push(marker);
            });
            populateSearch(entry);
        }).catch(function () { /* endpoint non raggiungibile: mappa vuota */ });

        return entry;
    }

    function openMarker(entry, marker) {
        var data = marker.almaData;
        var container = entry.container;
        var pageUrl = listPageUrl(container, data.ids);

        entry.infoWindow.setContent(infoWindowContent(data, container, null));
        entry.infoWindow.open({ map: entry.map, anchor: marker });

        var ajaxUrl = container.getAttribute('data-ajax-url');
        fetchJson(ajaxUrl + '?action=alma_geo_map_articles&location_ids=' + encodeURIComponent(data.ids)).then(function (response) {
            var articles = response && response.success && response.data && Array.isArray(response.data.items) ? response.data.items : [];
            entry.infoWindow.setContent(infoWindowContent(data, container, articles));
        }).catch(function () {
            entry.infoWindow.setContent(infoWindowContent(data, container, []));
        });

        // Se è configurata la pagina elenco e l'utente clicca di nuovo il marker
        // già aperto, si naviga direttamente alla pagina.
        if (pageUrl && entry.lastOpened === marker) {
            window.location.href = pageUrl;
            return;
        }
        entry.lastOpened = marker;
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
                entry.map.panTo(target.getPosition());
                entry.map.setZoom(Math.max(entry.map.getZoom(), 8));
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
                entry.map.setZoom(parseInt(entry.container.getAttribute('data-zoom'), 10) || 2);
                entry.map.panTo({ lat: 22, lng: 8 });
                entry.infoWindow.close();
                entry.lastOpened = null;
            }
        });
    }

    // Callback globale richiamato dal loader Google Maps.
    window.almaGeoMapInit = function () {
        var containers = document.querySelectorAll('.alma-geo-map');
        Array.prototype.forEach.call(containers, function (container) {
            if (!container.getAttribute('data-alma-initialized')) {
                container.setAttribute('data-alma-initialized', '1');
                initMapContainer(container);
            }
        });
    };
})();
