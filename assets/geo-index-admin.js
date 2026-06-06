(function ($) {
    'use strict';

    function strings(key) {
        return (window.almaGeoIndexAdmin && window.almaGeoIndexAdmin.strings && window.almaGeoIndexAdmin.strings[key]) || key;
    }

    function setFeedback(message, type) {
        var $feedback = $('#alma_geo_location_feedback');
        $feedback.removeClass('notice-success notice-error notice-warning').text(message || '');
        if (message) {
            $feedback.addClass(type === 'success' ? 'notice-success' : (type === 'warning' ? 'notice-warning' : 'notice-error'));
        }
    }

    function esc(value) {
        return $('<div>').text(value || '').html();
    }

    function field(key) {
        return $('#' + key);
    }

    function setField(key, value) {
        field(key).val(value == null ? '' : value).trigger('change');
    }

    function currentFieldValue(key) {
        return field(key).val() || '';
    }

    function setIfEmpty(key, value) {
        if (!currentFieldValue(key)) {
            setField(key, value);
        }
    }

    function setBadgeText(label, value) {
        $('.alma-geo-summary > div').filter(function () {
            return $(this).text().indexOf(label) !== -1;
        }).find('.alma-geo-badge').removeClass('alma-geo-badge-inactive alma-geo-badge-pending alma-geo-badge-manual alma-geo-badge-failed alma-geo-badge-ambiguous').addClass('alma-geo-badge-active alma-geo-badge-verified').text(value);
    }

    function updateDisplay(data) {
        $('[data-alma-geo-display="_alma_geo_primary_name"]').text(data.name || '');
        $('[data-alma-geo-display="_alma_geo_primary_type"]').text(data.type || '');
        $('[data-alma-geo-display="_alma_geo_primary_country"]').text(data.country || '');
        $('[data-alma-geo-display="_alma_geo_primary_country_code"]').text(data.country_code || '');
        $('[data-alma-geo-display="_alma_geo_primary_region"]').text(data.region || '');
        $('[data-alma-geo-display="_alma_geo_primary_city"]').text(data.city || '');
        $('[data-alma-geo-display="_alma_geo_primary_formatted_address"]').text(data.formatted_address || '');
        $('[data-alma-geo-display="coordinates"]').text((data.lat || '') + (data.lng ? ', ' + data.lng : ''));
        $('[data-alma-geo-display="_alma_geo_primary_place_id"]').text(data.place_id || '');
        $('[data-alma-geo-display="_alma_geo_provider"]').text(data.provider === 'google_maps' ? 'Google Maps' : (data.provider || ''));
        setBadgeText('Indice geografico:', 'Attivo');
        setBadgeText('Stato import:', 'Importato');
        setBadgeText('Stato geocoding:', 'Geocodificato');
    }

    function associate(data) {
        var hasPrimary = $('.alma-geo-metabox').attr('data-has-primary-location') === '1';
        if (hasPrimary && !window.confirm(strings('replaceConfirm'))) {
            return;
        }
        $('#_alma_geo_enabled, #_alma_geo_widget_eligible').prop('checked', true);
        setField('_alma_geo_import_status', data.import_status || 'imported');
        setField('_alma_geo_geocoding_status', data.geocoding_status || 'verified');
        setField('_alma_geo_primary_name', data.name || '');
        setField('_alma_geo_primary_canonical_name', data.canonical_name || data.name || '');
        setField('_alma_geo_primary_type', data.type || 'unknown');
        setField('_alma_geo_primary_country', data.country || '');
        setField('_alma_geo_primary_country_code', data.country_code || '');
        setField('_alma_geo_primary_region', data.region || '');
        setField('_alma_geo_primary_city', data.city || '');
        setField('_alma_geo_primary_area', data.area || '');
        setField('_alma_geo_primary_poi', data.poi || '');
        setField('_alma_geo_primary_lat', data.lat || '');
        setField('_alma_geo_primary_lng', data.lng || '');
        setField('_alma_geo_primary_place_id', data.place_id || '');
        setField('_alma_geo_provider', data.provider || 'google_maps');
        setField('_alma_geo_primary_provider', data.provider || 'google_maps');
        setField('_alma_geo_primary_formatted_address', data.formatted_address || '');
        setField('_alma_geo_confidence', data.confidence || '1');
        setField('_alma_geo_match_weight', data.match_weight || '100');
        setField('_alma_geo_source', data.source || 'manual_google_search');
        setIfEmpty('_alma_geo_commercial_intent', data.commercial_intent || 'high');
        setIfEmpty('_alma_geo_scope', data.geo_scope || 'uncertain');
        setIfEmpty('_alma_geo_content_type', data.content_type || 'destination_guide');
        updateDisplay(data);
        $('.alma-geo-metabox').attr('data-has-primary-location', '1');
        setFeedback(hasPrimary ? strings('replaced') : strings('associated'), 'success');
    }

    function renderResults(results) {
        var $wrap = $('#alma_geo_location_results').empty();
        if (!results || !results.length) {
            setFeedback(strings('noResults'), 'warning');
            return;
        }
        results.forEach(function (item) {
            var $card = $('<div class="alma-geo-result-card"></div>');
            $card.append('<strong class="alma-geo-result-title">' + esc(item.name) + '</strong>');
            $card.append('<div class="alma-geo-result-address">' + esc(item.formatted_address) + '</div>');
            $card.append('<div class="alma-geo-result-meta">' + esc(strings('type')) + ' ' + esc(item.type || 'unknown') + (item.country ? ' · ' + esc(strings('country')) + ' ' + esc(item.country) : '') + '</div>');
            $('<button type="button" class="button button-primary"></button>').text(strings('associate')).on('click', function () {
                associate(item);
            }).appendTo($card);
            $wrap.append($card);
        });
        setFeedback('', 'success');
    }

    function searchLocation() {
        var query = $('#alma_geo_location_query').val() || '';
        if (query.trim().length < 3) {
            setFeedback(strings('minChars'), 'warning');
            return;
        }
        setFeedback(strings('searching'), 'warning');
        $('#alma_geo_location_results').empty();
        $.ajax({
            url: window.almaGeoIndexAdmin.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'alma_geo_search_location',
                nonce: window.almaGeoIndexAdmin.nonce,
                post_id: window.almaGeoIndexAdmin.postId || 0,
                query: query
            }
        }).done(function (response) {
            if (response && response.success) {
                renderResults((response.data && response.data.results) || []);
                return;
            }
            setFeedback((response.data && response.data.message) || strings('searchError'), 'error');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            setFeedback((response.data && response.data.message) || strings('searchError'), 'error');
        });
    }

    $(function () {
        $('#alma_geo_location_search_button').on('click', searchLocation);
        $('#alma_geo_location_query').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchLocation();
            }
        });
    });
}(jQuery));
