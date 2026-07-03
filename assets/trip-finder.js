/**
 * Affiliate Link Manager AI - Trova il tuo viaggio (ricerca a faccette)
 *
 * Progressive enhancement sul form GET renderizzato dal PHP: senza JS il
 * form si invia normalmente; con JS le select aggiornano risultati e
 * contatori via AJAX, la paginazione non ricarica la pagina e l'URL resta
 * condivisibile (history.replaceState).
 */
(function () {
    'use strict';

    function initFinder(container) {
        var ajaxUrl = container.getAttribute('data-ajax-url');
        var baseUrl = container.getAttribute('data-base-url') || window.location.href.split('?')[0];
        var perPage = container.getAttribute('data-per-page') || '';
        var taxonomies = container.getAttribute('data-taxonomies') || '';
        var facets = container.querySelector('.alma-trip-finder__facets');
        var results = container.querySelector('.alma-trip-finder__results');
        if (!ajaxUrl || !facets || !results) { return; }

        container.classList.add('alma-trip-finder--js');

        function currentSelection() {
            var selection = {};
            Array.prototype.forEach.call(container.querySelectorAll('.alma-trip-finder__select'), function (select) {
                var match = /alma_f\[([^\]]+)\]/.exec(select.getAttribute('name') || '');
                if (match && select.value !== '') {
                    selection[match[1]] = select.value;
                }
            });
            return selection;
        }

        function buildParams(selection, page) {
            var params = new URLSearchParams();
            Object.keys(selection).forEach(function (tax) {
                params.set('alma_f[' + tax + ']', selection[tax]);
            });
            if (page > 1) { params.set('alma_tf_page', String(page)); }
            return params;
        }

        var requestId = 0;
        function update(page, selectionOverride) {
            var selection = selectionOverride || currentSelection();
            var params = buildParams(selection, page);

            // URL condivisibile senza ricaricare la pagina.
            var query = params.toString();
            try {
                window.history.replaceState(null, '', query ? baseUrl + '?' + query : baseUrl);
            } catch (e) { /* browser restrittivi: l'URL resta quello corrente */ }

            params.set('action', 'alma_trip_finder_render');
            if (perPage) { params.set('per_page', perPage); }
            if (taxonomies) { params.set('taxonomies', taxonomies); }

            container.classList.add('is-loading');
            var thisRequest = ++requestId;
            fetch(ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (response) {
                    if (thisRequest !== requestId) { return; } // risposta superata da una selezione più recente
                    if (response && response.success && response.data) {
                        facets.innerHTML = response.data.facets_html || '';
                        results.innerHTML = response.data.results_html || '';
                    }
                })
                .catch(function () { /* endpoint non raggiungibile: il form resta utilizzabile via submit */ })
                .then(function () {
                    if (thisRequest === requestId) { container.classList.remove('is-loading'); }
                });
        }

        // Delegation: i nodi vengono sostituiti a ogni refresh AJAX.
        container.addEventListener('change', function (event) {
            if (event.target && event.target.classList.contains('alma-trip-finder__select')) {
                update(1);
            }
        });

        container.addEventListener('click', function (event) {
            var pageLink = event.target.closest ? event.target.closest('a[data-tf-page]') : null;
            if (pageLink && container.contains(pageLink)) {
                event.preventDefault();
                update(parseInt(pageLink.getAttribute('data-tf-page'), 10) || 1);
                var top = container.getBoundingClientRect().top + window.pageYOffset - 80;
                window.scrollTo({ top: top > 0 ? top : 0, behavior: 'smooth' });
                return;
            }
            var chip = event.target.closest ? event.target.closest('a[data-tf-remove]') : null;
            if (chip && container.contains(chip)) {
                event.preventDefault();
                var selection = currentSelection();
                delete selection[chip.getAttribute('data-tf-remove')];
                // Riallinea anche la select corrispondente prima del refresh.
                var select = container.querySelector('select[name="alma_f[' + chip.getAttribute('data-tf-remove') + ']"]');
                if (select) { select.value = ''; }
                update(1, selection);
            }
        });
    }

    function boot() {
        var containers = document.querySelectorAll('.alma-trip-finder');
        Array.prototype.forEach.call(containers, function (container) {
            if (!container.getAttribute('data-alma-initialized')) {
                container.setAttribute('data-alma-initialized', '1');
                initFinder(container);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
