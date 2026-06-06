(function ($) {
    'use strict';

    var locations = [];
    var isInitialRender = true;
    var primaryChanged = false;
    var roles = ['main_destination', 'major_destination', 'mentioned_destination', 'excursion', 'nearby_place', 'route_stop', 'context_only'];

    function strings(key) {
        return (window.almaGeoIndexAdmin && window.almaGeoIndexAdmin.strings && window.almaGeoIndexAdmin.strings[key]) || key;
    }

    function roleLabel(role) {
        return (window.almaGeoIndexAdmin && window.almaGeoIndexAdmin.roleLabels && window.almaGeoIndexAdmin.roleLabels[role]) || role;
    }

    function maxLocations() {
        return parseInt((window.almaGeoIndexAdmin && window.almaGeoIndexAdmin.maxLocations) || 10, 10);
    }

    function setFeedback(message, type) {
        var $feedback = $('#alma_geo_location_feedback');
        $feedback.removeClass('notice-success notice-error notice-warning').text(message || '');
        if (message) {
            $feedback.addClass(type === 'success' ? 'notice-success' : (type === 'warning' ? 'notice-warning' : 'notice-error'));
        }
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function field(key) {
        return $('#' + key);
    }

    function setField(key, value) {
        field(key).val(value == null ? '' : value).trigger('change');
    }

    function truthy(value) {
        return value === true || value === 1 || value === '1' || value === 'yes' || value === 'true';
    }

    function derivedForType(type) {
        var map = {
            city: ['city', 'city_guide'],
            country: ['country', 'country_guide'],
            region: ['region', 'region_guide'],
            area: ['area', 'area_guide'],
            poi: ['poi', 'poi_guide'],
            route: ['itinerary_multi_location', 'itinerary']
        };
        return map[type] || ['uncertain', 'destination_guide'];
    }

    function isVerifiedLocation(item) {
        return item.geocoding_status === 'verified' || (item.lat !== '' && item.lng !== '' && item.geo_provider_place_id !== '');
    }

    function normalizeLocation(data, isPrimary) {
        data = data || {};
        var primary = truthy(isPrimary) || truthy(data.is_primary);
        var status = data.geocoding_status || (data.place_id || data.geo_provider_place_id ? 'verified' : 'pending');
        var item = {
            local_id: data.local_id || data.geo_provider_place_id || data.place_id || data.location_id || data.id || ('alma-local-' + Date.now() + '-' + Math.floor(Math.random() * 100000)),
            location_id: data.location_id || data.id || 0,
            name: data.name || data.canonical_name || '',
            canonical_name: data.canonical_name || data.name || '',
            type: data.type || 'unknown',
            country: data.country || '',
            country_code: data.country_code || '',
            region: data.region || '',
            city: data.city || '',
            area: data.area || '',
            poi: data.poi || '',
            lat: data.lat == null ? '' : data.lat,
            lng: data.lng == null ? '' : data.lng,
            geo_provider: data.geo_provider || data.provider || (data.place_id ? 'google_maps' : ''),
            geo_provider_place_id: data.geo_provider_place_id || data.place_id || '',
            formatted_address: data.formatted_address || '',
            geocoding_status: status,
            role: primary ? 'main_destination' : (data.role && data.role !== 'main_destination' ? data.role : 'major_destination'),
            is_primary: primary,
            source: data.source || 'manual_google_search',
            confidence: data.confidence == null || data.confidence === '' ? 1 : data.confidence,
            match_weight: primary ? 100 : (data.match_weight || 70)
        };
        if (isVerifiedLocation(item)) {
            item.geocoding_status = 'verified';
        }
        return item;
    }

    function signature(item) {
        item = item || {};
        if (item.geo_provider_place_id) {
            return 'place:' + item.geo_provider_place_id;
        }
        return 'text:' + [item.canonical_name || item.name, item.type, item.country_code, item.region, item.city, item.area, item.poi, item.formatted_address].join('|').toLowerCase();
    }

    function markPrimaryChanged() {
        primaryChanged = true;
        $('#alma_geo_primary_changed').val('1');
    }

    function statusLabel(status) {
        switch (status) {
            case 'verified': return 'Geocodificato';
            case 'pending': return 'In attesa di geocoding';
            case 'manual_required': return 'Richiede verifica manuale';
            case 'failed': return 'Geocoding fallito';
            case 'ambiguous': return 'Ambiguo';
            case 'not_required': return 'Non richiesto';
            default: return status || '';
        }
    }

    function setBadge(label, value, cls) {
        var classes = 'alma-geo-badge-active alma-geo-badge-inactive alma-geo-badge-pending alma-geo-badge-manual alma-geo-badge-failed alma-geo-badge-ambiguous alma-geo-badge-verified';
        $('.alma-geo-summary > div').filter(function () {
            return $(this).text().indexOf(label) !== -1;
        }).find('.alma-geo-badge').removeClass(classes).addClass(cls).text(value);
    }

    function primaryLocation() {
        return locations.filter(function (item) { return truthy(item.is_primary); })[0] || null;
    }

    function updateBadges() {
        var primary = primaryLocation();
        if (!primary) {
            setBadge('Indice geografico:', 'Non attivo', 'alma-geo-badge-inactive');
            setBadge('Stato import:', 'Richiede revisione', 'alma-geo-badge-inactive');
            setBadge('Stato geocoding:', 'In attesa di geocoding', 'alma-geo-badge-pending');
            return;
        }
        setBadge('Indice geografico:', 'Attivo', 'alma-geo-badge-active');
        setBadge('Stato import:', 'Importato', 'alma-geo-badge-active');
        if (primary.geocoding_status === 'verified') {
            setBadge('Stato geocoding:', 'Geocodificato', 'alma-geo-badge-verified');
        } else if (primary.geocoding_status === 'failed') {
            setBadge('Stato geocoding:', 'Geocoding fallito', 'alma-geo-badge-failed');
        } else if (primary.geocoding_status === 'manual_required') {
            setBadge('Stato geocoding:', 'Richiede verifica manuale', 'alma-geo-badge-manual');
        } else {
            setBadge('Stato geocoding:', 'In attesa di geocoding', 'alma-geo-badge-pending');
        }
    }

    function applyDerivedGeoFieldsFromPrimary(primary) {
        if (!primary) {
            return;
        }
        var derived = derivedForType(primary.type || 'unknown');
        setField('_alma_geo_scope', derived[0]);
        setField('_alma_geo_content_type', derived[1]);
        setField('_alma_geo_commercial_intent', primary.commercial_intent || 'high');
        setField('_alma_geo_match_weight', primary.match_weight || 100);
        setField('_alma_geo_confidence', primary.confidence == null || primary.confidence === '' ? 1 : primary.confidence);
    }

    function updatePrimaryFields() {
        var primary = primaryLocation();
        if (!primary) {
            ['_alma_geo_primary_name', '_alma_geo_primary_canonical_name', '_alma_geo_primary_type', '_alma_geo_primary_country', '_alma_geo_primary_country_code', '_alma_geo_primary_region', '_alma_geo_primary_city', '_alma_geo_primary_area', '_alma_geo_primary_poi', '_alma_geo_primary_lat', '_alma_geo_primary_lng', '_alma_geo_primary_place_id', '_alma_geo_provider', '_alma_geo_primary_provider', '_alma_geo_primary_formatted_address'].forEach(function (key) { setField(key, ''); });
            if (!isInitialRender) {
                $('#_alma_geo_enabled').prop('checked', false);
            }
            if (!isInitialRender) {
                setField('_alma_geo_geocoding_status', 'pending');
                setField('_alma_geo_import_status', 'review');
            }
            $('.alma-geo-metabox').attr('data-has-primary-location', '0');
            return;
        }
        setField('_alma_geo_primary_name', primary.name);
        setField('_alma_geo_primary_canonical_name', primary.canonical_name || primary.name);
        setField('_alma_geo_primary_type', primary.type || 'unknown');
        setField('_alma_geo_primary_country', primary.country);
        setField('_alma_geo_primary_country_code', primary.country_code);
        setField('_alma_geo_primary_region', primary.region);
        setField('_alma_geo_primary_city', primary.city);
        setField('_alma_geo_primary_area', primary.area);
        setField('_alma_geo_primary_poi', primary.poi);
        setField('_alma_geo_primary_lat', primary.lat);
        setField('_alma_geo_primary_lng', primary.lng);
        setField('_alma_geo_primary_place_id', primary.geo_provider_place_id);
        setField('_alma_geo_provider', primary.geo_provider);
        setField('_alma_geo_primary_provider', primary.geo_provider);
        setField('_alma_geo_primary_formatted_address', primary.formatted_address);
        if (!isInitialRender) {
            setField('_alma_geo_geocoding_status', primary.geocoding_status || 'pending');
            setField('_alma_geo_source', primary.source || 'manual_google_search');
            setField('_alma_geo_import_status', 'imported');
        }
        if (!isInitialRender) {
            $('#_alma_geo_enabled').prop('checked', true);
        }
        if (primaryChanged || !field('_alma_geo_scope').val() || !field('_alma_geo_content_type').val() || !field('_alma_geo_commercial_intent').val()) {
            applyDerivedGeoFieldsFromPrimary(primary);
            primaryChanged = false;
        }
        $('.alma-geo-metabox').attr('data-has-primary-location', '1');
    }

    function ensureSinglePrimary() {
        var hasPrimary = false;
        locations = locations.slice(0, maxLocations()).map(function (item) {
            item = normalizeLocation(item, item.is_primary);
            if (truthy(item.is_primary) && !hasPrimary) {
                hasPrimary = true;
                item.is_primary = true;
                item.role = 'main_destination';
                item.match_weight = 100;
            } else {
                item.is_primary = false;
                item.role = item.role === 'main_destination' ? 'major_destination' : item.role;
            }
            return item;
        });
        if (!hasPrimary && locations.length) {
            locations[0].is_primary = true;
            locations[0].role = 'main_destination';
            locations[0].match_weight = 100;
        }
    }

    function syncAssociatedLocationsToHiddenField() {
        ensureSinglePrimary();
        $('#alma_geo_associated_locations_json').val(JSON.stringify(locations));
        updatePrimaryFields();
        if (!isInitialRender) {
            updateBadges();
        }
    }

    function renderLocations() {
        var $tbody = $('#alma_geo_associated_locations_table tbody').empty();
        ensureSinglePrimary();
        $('#alma_geo_empty_locations').toggle(!locations.length);
        locations.forEach(function (item, index) {
            var $row = $('<tr></tr>');
            $('<td></td>').append($('<label></label>').append(
                $('<input type="radio" name="alma_geo_primary_location_choice">').prop('checked', truthy(item.is_primary)).on('change', function () {
                    markPrimaryChanged();
                    locations.forEach(function (location, idx) {
                        location.is_primary = idx === index;
                        location.role = idx === index ? 'main_destination' : (location.role === 'main_destination' ? 'major_destination' : location.role);
                        location.match_weight = idx === index ? 100 : (location.match_weight || 70);
                    });
                    renderLocations();
                    setFeedback(strings('associated'), 'success');
                }),
                ' ', strings('main')
            )).appendTo($row);
            $('<td></td>').html('<strong>' + esc(item.name || item.canonical_name) + '</strong><br><span class="description">' + esc(item.formatted_address) + '</span>').appendTo($row);
            $('<td></td>').text(item.type || '').appendTo($row);
            $('<td></td>').text(item.country || '').appendTo($row);
            $('<td></td>').text(item.region || '').appendTo($row);
            $('<td></td>').text(item.city || '').appendTo($row);
            $('<td></td>').text(statusLabel(item.geocoding_status)).appendTo($row);
            var $select = $('<select class="alma-geo-location-role"></select>');
            roles.forEach(function (role) {
                $('<option></option>').val(role).text(roleLabel(role)).prop('selected', item.role === role).appendTo($select);
            });
            $select.prop('disabled', truthy(item.is_primary)).on('change', function () {
                locations[index].role = $(this).val();
                locations[index].match_weight = '';
                syncAssociatedLocationsToHiddenField();
            });
            $('<td></td>').append($select).appendTo($row);
            $('<td></td>').append($('<button type="button" class="button button-small"></button>').text(strings('remove')).on('click', function () {
                var wasPrimary = truthy(locations[index].is_primary);
                locations.splice(index, 1);
                if (wasPrimary && locations.length) {
                    locations[0].is_primary = true;
                    locations[0].role = 'main_destination';
                    locations[0].match_weight = 100;
                    markPrimaryChanged();
                    setFeedback(strings('promoted'), 'warning');
                } else {
                    setFeedback(strings('removed'), 'success');
                }
                renderLocations();
            })).appendTo($row);
            $tbody.append($row);
        });
        syncAssociatedLocationsToHiddenField();
    }

    function addLocation(data) {
        if (locations.length >= maxLocations()) {
            setFeedback(strings('limitReached'), 'warning');
            return;
        }
        var willBePrimary = locations.length === 0;
        var item = normalizeLocation(data, willBePrimary);
        var itemSig = signature(item);
        if (locations.some(function (location) { return signature(location) === itemSig; })) {
            setFeedback(strings('duplicate'), 'warning');
            return;
        }
        if (!item.is_primary) {
            item.role = 'major_destination';
            item.match_weight = 70;
        }
        locations.push(item);
        if (willBePrimary) {
            markPrimaryChanged();
        }
        $('#_alma_geo_enabled').prop('checked', true);
        renderLocations();
        setFeedback(strings('associated'), 'success');
    }

    function renderResults(results) {
        var $wrap = $('#alma_geo_location_results').empty();
        if (!results || !results.length) {
            setFeedback(strings('noResults'), 'warning');
            return;
        }
        results.forEach(function (item) {
            var $card = $('<div class="alma-geo-result-card"></div>');
            var $actions = $('<div class="alma-geo-result-actions"></div>');
            $card.append('<strong class="alma-geo-result-title">' + esc(item.name) + '</strong>');
            $card.append('<div class="alma-geo-result-address">' + esc(item.formatted_address) + '</div>');
            $card.append('<div class="alma-geo-result-meta">' + esc(strings('type')) + ' ' + esc(item.type || 'unknown') + (item.country ? ' · ' + esc(strings('country')) + ' ' + esc(item.country) : '') + '</div>');
            $('<button type="button" class="button button-primary"></button>').text(strings('associate')).on('click', function () {
                addLocation(item);
            }).appendTo($actions);
            $('<button type="button" class="button-link alma-geo-hide-result" aria-label="' + esc(strings('hideSearchResult')) + '" title="' + esc(strings('hideSearchResult')) + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>').on('click', function () {
                $card.remove();
                if (!$wrap.children().length) {
                    setFeedback(strings('noResults'), 'warning');
                }
            }).appendTo($actions);
            $card.append($actions);
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
                if (response.data && response.data.message && !(response.data.results || []).length) {
                    setFeedback(response.data.message, 'warning');
                }
                return;
            }
            setFeedback((response.data && response.data.message) || strings('searchError'), 'error');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            setFeedback((response.data && response.data.message) || strings('searchError'), 'error');
        });
    }

    function loadInitialLocations() {
        var raw = $('#alma_geo_associated_locations_json').val() || '[]';
        try {
            locations = JSON.parse(raw) || [];
        } catch (error) {
            locations = [];
        }
        locations = locations.map(function (item) { return normalizeLocation(item, item.is_primary); });
        renderLocations();
        isInitialRender = false;
    }

    $(function () {
        loadInitialLocations();
        $('#alma_geo_location_search_button').on('click', searchLocation);
        $('#alma_geo_location_query').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchLocation();
            }
        });
        $('#post').on('submit', function () {
            syncAssociatedLocationsToHiddenField();
        });
    });
}(jQuery));
