<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Storage layer for the Geo Index module.
 */
class ALMA_Geo_Index_Store {
    const OBJECT_TYPE_POST = 'post';
    const OBJECT_TYPE_PAGE = 'page';
    const OBJECT_TYPE_AFFILIATE_LINK = 'affiliate_link';

    public function table_locations() {
        global $wpdb;
        return $wpdb->prefix . 'alma_geo_locations';
    }

    public function table_content_index() {
        global $wpdb;
        return $wpdb->prefix . 'alma_geo_content_index';
    }

    public static function create_tables() {
        $store = new self();
        $store->install_tables();
    }

    public function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $locations = $this->table_locations();
        $content_index = $this->table_content_index();

        $sql_locations = "CREATE TABLE $locations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            country VARCHAR(120) DEFAULT '',
            country_code VARCHAR(10) DEFAULT '',
            region VARCHAR(160) DEFAULT '',
            city VARCHAR(160) DEFAULT '',
            area VARCHAR(160) DEFAULT '',
            poi VARCHAR(180) DEFAULT '',
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            geo_provider VARCHAR(50) DEFAULT '',
            geo_provider_place_id VARCHAR(255) DEFAULT '',
            suggested_geocoding_query TEXT NULL,
            geocoding_status VARCHAR(50) DEFAULT 'pending',
            formatted_address TEXT NULL,
            address_components LONGTEXT NULL,
            geocoded_at DATETIME NULL,
            geocoding_error TEXT NULL,
            aliases LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY canonical_name (canonical_name(191)),
            KEY type (type),
            KEY country_code (country_code),
            KEY geocoding_status (geocoding_status)
        ) $charset_collate;";

        $sql_content_index = "CREATE TABLE $content_index (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            location_id BIGINT UNSIGNED NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            role VARCHAR(80) DEFAULT '',
            geo_scope VARCHAR(80) DEFAULT '',
            content_type VARCHAR(100) DEFAULT '',
            commercial_intent VARCHAR(30) DEFAULT '',
            widget_eligible TINYINT(1) NOT NULL DEFAULT 0,
            confidence DECIMAL(4,3) NULL,
            match_weight INT NULL,
            source VARCHAR(100) DEFAULT '',
            raw_payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY object_lookup (object_id, object_type),
            KEY location_id (location_id),
            KEY is_primary (is_primary),
            KEY widget_eligible (widget_eligible),
            KEY geo_scope (geo_scope)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_locations);
        dbDelta($sql_content_index);
    }

    public function tables_exist() {
        global $wpdb;
        $locations = $this->table_locations();
        $content_index = $this->table_content_index();
        $locations_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $locations)) === $locations;
        $content_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $content_index)) === $content_index;
        return $locations_exists && $content_exists;
    }

    public function get_location_by_place_id($place_id, $provider = 'google_maps') {
        global $wpdb;
        $place_id = sanitize_text_field($place_id);
        $provider = sanitize_key($provider);
        if ($place_id === '' || !$this->tables_exist()) {
            return null;
        }
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_locations()} WHERE geo_provider_place_id = %s AND geo_provider = %s LIMIT 1",
                $place_id,
                $provider
            ),
            ARRAY_A
        );
    }

    public function get_location_by_signature($data) {
        global $wpdb;
        $signature = $this->normalize_location_signature($data);
        if ($signature['canonical_name'] === '') {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_locations()}
                 WHERE LOWER(canonical_name) = %s
                   AND type = %s
                   AND country_code = %s
                   AND LOWER(region) = %s
                   AND LOWER(city) = %s
                   AND LOWER(area) = %s
                   AND LOWER(poi) = %s
                   AND LOWER(COALESCE(formatted_address, '')) = %s
                 LIMIT 1",
                $signature['canonical_name'],
                $signature['type'],
                $signature['country_code'],
                $signature['region'],
                $signature['city'],
                $signature['area'],
                $signature['poi'],
                $signature['formatted_address']
            ),
            ARRAY_A
        );
    }

    public function upsert_location($data) {
        global $wpdb;
        $data = $this->sanitize_location_data($data);
        if ($data['canonical_name'] === '') {
            return 0;
        }

        $now = current_time('mysql');
        $existing = null;
        if ($data['geo_provider_place_id'] !== '') {
            $existing = $this->get_location_by_place_id($data['geo_provider_place_id'], $data['geo_provider']);
        }
        if (!$existing) {
            $existing = $this->get_location_by_signature($data);
        }
        $formats = array('%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');
        $row = array(
            'canonical_name' => $data['canonical_name'],
            'type' => $data['type'],
            'country' => $data['country'],
            'country_code' => $data['country_code'],
            'region' => $data['region'],
            'city' => $data['city'],
            'area' => $data['area'],
            'poi' => $data['poi'],
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'geo_provider' => $data['geo_provider'],
            'geo_provider_place_id' => $data['geo_provider_place_id'],
            'suggested_geocoding_query' => $data['suggested_geocoding_query'],
            'geocoding_status' => $data['geocoding_status'],
            'formatted_address' => $data['formatted_address'],
            'address_components' => $data['address_components'],
            'geocoded_at' => $data['geocoded_at'] ?: ($data['geocoding_status'] === 'verified' ? $now : null),
            'geocoding_error' => $data['geocoding_error'],
            'aliases' => $data['aliases'],
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update($this->table_locations(), $row, array('id' => (int) $existing['id']), $formats, array('%d'));
            return (int) $existing['id'];
        }

        $row['created_at'] = $now;
        $wpdb->insert($this->table_locations(), $row, array_merge($formats, array('%s')));
        return (int) $wpdb->insert_id;
    }

    public function upsert_content_index($object_id, $object_type, $location_id, $data) {
        global $wpdb;
        $object_id = absint($object_id);
        $object_type = sanitize_key($object_type);
        if (!$object_id || $object_type === '') {
            return 0;
        }

        $now = current_time('mysql');
        $row = array(
            'object_id' => $object_id,
            'object_type' => $object_type,
            'location_id' => $location_id ? absint($location_id) : null,
            'is_primary' => !empty($data['is_primary']) ? 1 : 0,
            'role' => isset($data['role']) ? sanitize_key($data['role']) : '',
            'geo_scope' => isset($data['geo_scope']) ? sanitize_key($data['geo_scope']) : '',
            'content_type' => isset($data['content_type']) ? sanitize_key($data['content_type']) : '',
            'commercial_intent' => isset($data['commercial_intent']) ? sanitize_key($data['commercial_intent']) : '',
            'widget_eligible' => !empty($data['widget_eligible']) ? 1 : 0,
            'confidence' => isset($data['confidence']) && $data['confidence'] !== '' ? (float) $data['confidence'] : null,
            'match_weight' => isset($data['match_weight']) && $data['match_weight'] !== '' ? (int) $data['match_weight'] : null,
            'source' => isset($data['source']) ? sanitize_text_field($data['source']) : '',
            'raw_payload' => isset($data['raw_payload']) ? wp_json_encode($data['raw_payload']) : null,
            'updated_at' => $now,
        );

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_content_index()} WHERE object_id = %d AND object_type = %s AND is_primary = %d AND (is_primary = 1 OR location_id <=> %d) LIMIT 1",
                $object_id,
                $object_type,
                $row['is_primary'],
                $row['location_id']
            )
        );

        $formats = array('%d','%s','%d','%d','%s','%s','%s','%s','%d','%f','%d','%s','%s','%s');
        if ($existing_id) {
            $wpdb->update($this->table_content_index(), $row, array('id' => (int) $existing_id), $formats, array('%d'));
            return (int) $existing_id;
        }

        $row['created_at'] = $now;
        $wpdb->insert($this->table_content_index(), $row, array_merge($formats, array('%s')));
        return (int) $wpdb->insert_id;
    }

    public function get_associated_locations_for_object($object_id, $object_type) {
        global $wpdb;
        $object_id = absint($object_id);
        $object_type = sanitize_key($object_type);
        if (!$object_id || $object_type === '' || !$this->tables_exist()) {
            return array();
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ci.id AS content_index_id, ci.is_primary, ci.role, ci.geo_scope, ci.content_type, ci.commercial_intent, ci.widget_eligible, ci.confidence, ci.match_weight, ci.source, ci.raw_payload, l.*
                 FROM {$this->table_content_index()} ci
                 LEFT JOIN {$this->table_locations()} l ON l.id = ci.location_id
                 WHERE ci.object_id = %d AND ci.object_type = %s
                 ORDER BY ci.is_primary DESC, ci.id ASC",
                $object_id,
                $object_type
            ),
            ARRAY_A
        );
        $locations = array();
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['raw_payload'] ?? ''), true);
            $payload_location = is_array($payload) && is_array($payload['location'] ?? null) ? $payload['location'] : array();
            $locations[] = $this->format_location_for_json(array_merge($payload_location, array(
                'location_id' => (int) ($row['id'] ?? 0),
                'name' => $payload_location['name'] ?? ($row['canonical_name'] ?? ''),
                'canonical_name' => $row['canonical_name'] ?? ($payload_location['canonical_name'] ?? ''),
                'type' => $row['type'] ?? ($payload_location['type'] ?? 'unknown'),
                'country' => $row['country'] ?? ($payload_location['country'] ?? ''),
                'country_code' => $row['country_code'] ?? ($payload_location['country_code'] ?? ''),
                'region' => $row['region'] ?? ($payload_location['region'] ?? ''),
                'city' => $row['city'] ?? ($payload_location['city'] ?? ''),
                'area' => $row['area'] ?? ($payload_location['area'] ?? ''),
                'poi' => $row['poi'] ?? ($payload_location['poi'] ?? ''),
                'lat' => $row['lat'] ?? ($payload_location['lat'] ?? ''),
                'lng' => $row['lng'] ?? ($payload_location['lng'] ?? ''),
                'geo_provider' => $row['geo_provider'] ?? ($payload_location['geo_provider'] ?? ''),
                'geo_provider_place_id' => $row['geo_provider_place_id'] ?? ($payload_location['geo_provider_place_id'] ?? ''),
                'formatted_address' => $row['formatted_address'] ?? ($payload_location['formatted_address'] ?? ''),
                'geocoding_status' => $row['geocoding_status'] ?? ($payload_location['geocoding_status'] ?? 'pending'),
                'role' => $row['is_primary'] ? 'main_destination' : sanitize_key($row['role'] ?? 'major_destination'),
                'is_primary' => !empty($row['is_primary']),
                'source' => $row['source'] ?? ($payload_location['source'] ?? ''),
                'confidence' => $row['confidence'] ?? ($payload_location['confidence'] ?? ''),
                'match_weight' => $row['match_weight'] ?? ($payload_location['match_weight'] ?? ''),
            )));
        }
        return $locations;
    }

    public function save_geo_meta_for_object($object_id, $object_type, $geo_data, $source = 'manual') {
        global $wpdb;
        $object_id = absint($object_id);
        $object_type = sanitize_key($object_type);
        $geo_data = is_array($geo_data) ? $geo_data : array();
        if (!$object_id || $object_type === '') {
            return array('location_id' => 0, 'content_index_id' => 0, 'locations' => array(), 'meta' => array());
        }
        if (!$this->tables_exist()) {
            $this->install_tables();
        }

        $locations = array();
        if (!empty($geo_data['locations']) && is_array($geo_data['locations'])) {
            $locations = $geo_data['locations'];
        } elseif (!empty($geo_data['primary_location']) && is_array($geo_data['primary_location'])) {
            $locations[] = array_merge($geo_data['primary_location'], array('is_primary' => true));
            if (!empty($geo_data['secondary_locations']) && is_array($geo_data['secondary_locations'])) {
                foreach ($geo_data['secondary_locations'] as $secondary) {
                    if (is_array($secondary)) {
                        $locations[] = array_merge($secondary, array('is_primary' => false));
                    }
                }
            }
        }

        $source = sanitize_text_field($source);
        $locations = $this->normalize_associated_locations($locations, $source);
        if (empty($locations)) {
            $this->log_geo_store_event('info', 'Geo Index Store save received no associated locations.', $object_id, $object_type);
        }
        $primary = null;
        foreach ($locations as $location) {
            if (!empty($location['is_primary'])) {
                $primary = $location;
                break;
            }
        }

        $derive_from_primary = !empty($geo_data['derive_from_primary']);
        $submitted_geo_scope = sanitize_key($geo_data['geo_scope'] ?? '');
        $submitted_content_type = sanitize_key($geo_data['content_type'] ?? '');
        $submitted_commercial_intent = sanitize_key($geo_data['commercial_intent'] ?? '');
        $geo_scope = $submitted_geo_scope !== '' ? $submitted_geo_scope : ($primary ? $this->default_geo_scope_for_type($primary['type']) : 'uncertain');
        $content_type = $submitted_content_type !== '' ? $submitted_content_type : ($primary ? $this->default_content_type_for_type($primary['type']) : 'uncertain');
        $commercial_intent = $submitted_commercial_intent !== '' ? $submitted_commercial_intent : ($derive_from_primary && $primary ? 'high' : 'none');
        $widget_eligible = !empty($geo_data['widget_eligible']) && !in_array((string) $geo_data['widget_eligible'], array('no', '0', 'false', 'off'), true);
        $now = current_time('mysql');
        $updated_locations = array();
        $primary_location_id = 0;
        $primary_content_index_id = 0;

        $this->delete_content_index_for_object($object_id, $object_type);
        foreach ($locations as $location) {
            $location_id = $this->upsert_location(array(
                'canonical_name' => $location['canonical_name'] ?: $location['name'],
                'type' => $location['type'],
                'country' => $location['country'],
                'country_code' => $location['country_code'],
                'region' => $location['region'],
                'city' => $location['city'],
                'area' => $location['area'],
                'poi' => $location['poi'],
                'lat' => $location['lat'],
                'lng' => $location['lng'],
                'geo_provider' => $location['geo_provider'],
                'geo_provider_place_id' => $location['geo_provider_place_id'],
                'suggested_geocoding_query' => $location['suggested_geocoding_query'] ?? '',
                'geocoding_status' => $location['geocoding_status'],
                'formatted_address' => $location['formatted_address'],
                'address_components' => $location['address_components'] ?? null,
                'geocoded_at' => $location['geocoding_status'] === 'verified' ? $now : '',
            ));
            if (!$location_id) {
                $this->log_geo_store_event('warning', 'Geo Index Store could not upsert location.', $object_id, $object_type);
            }
            $location['location_id'] = $location_id;
            $location['match_weight'] = !empty($location['is_primary']) ? 100 : $this->default_match_weight_for_role($location['role']);
            $content_index_id = $this->upsert_content_index($object_id, $object_type, $location_id, array(
                'is_primary' => !empty($location['is_primary']) ? 1 : 0,
                'role' => $location['role'],
                'geo_scope' => $geo_scope,
                'content_type' => $content_type,
                'commercial_intent' => $commercial_intent,
                'widget_eligible' => $widget_eligible,
                'confidence' => $location['confidence'],
                'match_weight' => $location['match_weight'],
                'source' => $location['source'] ?: $source,
                'raw_payload' => array('location' => $location, 'activity_type' => sanitize_key($geo_data['activity_type'] ?? ''), 'import_payload' => isset($geo_data['raw_payload']) ? $geo_data['raw_payload'] : array()),
            ));
            if (!$content_index_id) {
                $this->log_geo_store_event('warning', 'Geo Index Store could not upsert content index relation.', $object_id, $object_type);
            }
            if (!empty($location['is_primary'])) {
                $primary_location_id = $location_id;
                $primary_content_index_id = $content_index_id;
                $primary = $location;
            }
            $updated_locations[] = $this->format_location_for_json($location);
        }

        $meta = array(
            '_alma_geo_enabled' => $primary ? 'yes' : 'no',
            '_alma_geo_widget_eligible' => $widget_eligible ? 'yes' : 'no',
            '_alma_geo_import_status' => $primary ? 'imported' : 'review',
            '_alma_geo_geocoding_status' => $primary ? $primary['geocoding_status'] : 'pending',
            '_alma_geo_scope' => $geo_scope,
            '_alma_geo_content_type' => $content_type,
            '_alma_geo_commercial_intent' => $commercial_intent,
            '_alma_geo_primary_name' => $primary['name'] ?? '',
            '_alma_geo_primary_canonical_name' => $primary['canonical_name'] ?? ($primary['name'] ?? ''),
            '_alma_geo_primary_type' => $primary['type'] ?? 'unknown',
            '_alma_geo_primary_country' => $primary['country'] ?? '',
            '_alma_geo_primary_country_code' => $primary['country_code'] ?? '',
            '_alma_geo_primary_region' => $primary['region'] ?? '',
            '_alma_geo_primary_city' => $primary['city'] ?? '',
            '_alma_geo_primary_area' => $primary['area'] ?? '',
            '_alma_geo_primary_poi' => $primary['poi'] ?? '',
            '_alma_geo_primary_lat' => $primary['lat'] ?? '',
            '_alma_geo_primary_lng' => $primary['lng'] ?? '',
            '_alma_geo_primary_place_id' => $primary['geo_provider_place_id'] ?? '',
            '_alma_geo_provider' => $primary['geo_provider'] ?? '',
            '_alma_geo_primary_provider' => $primary['geo_provider'] ?? '',
            '_alma_geo_primary_formatted_address' => $primary['formatted_address'] ?? '',
            '_alma_geo_confidence' => $primary['confidence'] ?? '',
            '_alma_geo_match_weight' => $primary['match_weight'] ?? '',
            '_alma_geo_quality_flags' => sanitize_textarea_field($geo_data['quality_flags'] ?? ''),
            '_alma_geo_notes' => sanitize_textarea_field($geo_data['notes'] ?? ''),
            '_alma_geo_activity_type' => sanitize_key($geo_data['activity_type'] ?? ''),
            '_alma_geo_locations_json' => wp_json_encode($updated_locations),
            '_alma_geo_source' => $primary['source'] ?? $source,
            '_alma_geo_updated_at' => $now,
        );
        foreach ($meta as $key => $value) {
            update_post_meta($object_id, $key, $value);
        }
        return array('location_id' => $primary_location_id, 'content_index_id' => $primary_content_index_id, 'locations' => $updated_locations, 'meta' => $meta);
    }

    private function log_geo_store_event($level, $message, $object_id, $object_type) {
        if (!class_exists('ALMA_Logger')) {
            return;
        }
        $context = array(
            'object_id' => absint($object_id),
            'object_type' => sanitize_key($object_type),
        );
        if ($level === 'error') {
            ALMA_Logger::error($message, $context);
        } elseif ($level === 'warning') {
            ALMA_Logger::warning($message, $context);
        } elseif ($level === 'info') {
            ALMA_Logger::info($message, $context);
        } else {
            ALMA_Logger::debug($message, $context);
        }
    }

    public function normalize_associated_locations($locations, $source = 'manual') {
        $normalized = array();
        $seen = array();
        $has_primary = false;
        foreach (is_array($locations) ? $locations : array() as $location) {
            if (!is_array($location)) {
                continue;
            }
            $item = $this->format_location_for_json($location);
            if ($item['name'] === '' && $item['canonical_name'] === '') {
                continue;
            }
            $dedupe_key = $this->location_dedupe_key($item);
            if ($dedupe_key !== '' && isset($seen[$dedupe_key])) {
                continue;
            }
            if ($dedupe_key !== '') {
                $seen[$dedupe_key] = true;
            }
            $item['source'] = $item['source'] ?: sanitize_text_field($source);
            if (!empty($item['is_primary']) && !$has_primary) {
                $item['is_primary'] = true;
                $item['role'] = 'main_destination';
                $item['match_weight'] = 100;
                $has_primary = true;
            } else {
                $item['is_primary'] = false;
                if ($item['role'] === '' || $item['role'] === 'main_destination') {
                    $item['role'] = 'major_destination';
                }
                $item['match_weight'] = $item['match_weight'] !== '' ? (int) $item['match_weight'] : $this->default_match_weight_for_role($item['role']);
            }
            $normalized[] = $item;
        }
        if (!$has_primary && !empty($normalized)) {
            $normalized[0]['is_primary'] = true;
            $normalized[0]['role'] = 'main_destination';
            $normalized[0]['match_weight'] = 100;
        }
        return $normalized;
    }

    public function format_location_for_json($location) {
        $location = is_array($location) ? $location : array();
        $provider = sanitize_key($location['geo_provider'] ?? ($location['provider'] ?? ''));
        $place_id = sanitize_text_field($location['geo_provider_place_id'] ?? ($location['place_id'] ?? ''));
        $is_primary = !empty($location['is_primary']) && !in_array((string) $location['is_primary'], array('0', 'no', 'false', 'off'), true);
        return array(
            'local_id' => sanitize_text_field($location['local_id'] ?? ''),
            'location_id' => absint($location['location_id'] ?? ($location['id'] ?? 0)),
            'name' => sanitize_text_field($location['name'] ?? ($location['canonical_name'] ?? '')),
            'canonical_name' => sanitize_text_field($location['canonical_name'] ?? ($location['name'] ?? '')),
            'type' => sanitize_key($location['type'] ?? 'unknown'),
            'country' => sanitize_text_field($location['country'] ?? ''),
            'country_code' => strtoupper(sanitize_text_field($location['country_code'] ?? '')),
            'region' => sanitize_text_field($location['region'] ?? ''),
            'city' => sanitize_text_field($location['city'] ?? ''),
            'area' => sanitize_text_field($location['area'] ?? ''),
            'poi' => sanitize_text_field($location['poi'] ?? ''),
            'lat' => isset($location['lat']) && $location['lat'] !== '' && $location['lat'] !== null ? (string) (float) $location['lat'] : '',
            'lng' => isset($location['lng']) && $location['lng'] !== '' && $location['lng'] !== null ? (string) (float) $location['lng'] : '',
            'geo_provider' => $provider,
            'geo_provider_place_id' => $place_id,
            'formatted_address' => sanitize_text_field($location['formatted_address'] ?? ''),
            'geocoding_status' => sanitize_key($location['geocoding_status'] ?? 'pending'),
            'role' => sanitize_key($location['role'] ?? ($is_primary ? 'main_destination' : 'major_destination')),
            'is_primary' => $is_primary,
            'source' => sanitize_text_field($location['source'] ?? ''),
            'confidence' => isset($location['confidence']) && $location['confidence'] !== '' ? (float) $location['confidence'] : 1,
            'match_weight' => isset($location['match_weight']) && $location['match_weight'] !== '' ? (int) $location['match_weight'] : ($is_primary ? 100 : 70),
        );
    }


    private function location_dedupe_key($location) {
        $location = $this->format_location_for_json($location);
        if ($location['geo_provider_place_id'] !== '') {
            return 'place:' . strtolower($location['geo_provider_place_id']);
        }
        $parts = array(
            $location['canonical_name'] ?: $location['name'],
            $location['type'],
            $location['country_code'],
            $location['region'],
            $location['city'],
            $location['area'],
            $location['poi'],
            $location['formatted_address'],
        );
        return 'text:' . strtolower(implode('|', array_map('trim', $parts)));
    }

    public static function get_location_role_labels() {
        return array(
            'main_destination' => __('Località principale', 'affiliate-link-manager-ai'),
            'major_destination' => __('Località importante', 'affiliate-link-manager-ai'),
            'mentioned_destination' => __('Località citata', 'affiliate-link-manager-ai'),
            'excursion' => __('Escursione', 'affiliate-link-manager-ai'),
            'nearby_place' => __('Luogo vicino', 'affiliate-link-manager-ai'),
            'route_stop' => __('Tappa itinerario', 'affiliate-link-manager-ai'),
            'context_only' => __('Solo contesto', 'affiliate-link-manager-ai'),
        );
    }

    private function default_match_weight_for_role($role) {
        $weights = array(
            'main_destination' => 100,
            'major_destination' => 70,
            'excursion' => 60,
            'nearby_place' => 60,
            'mentioned_destination' => 40,
            'route_stop' => 60,
            'context_only' => 20,
        );
        $role = sanitize_key($role);
        return $weights[$role] ?? 70;
    }

    private function default_geo_scope_for_type($type) {
        if ($type === 'route') {
            return 'itinerary_multi_location';
        }
        return in_array($type, array('country', 'region', 'city', 'area', 'poi'), true) ? $type : 'uncertain';
    }

    private function default_content_type_for_type($type) {
        $map = array('country' => 'country_guide', 'region' => 'region_guide', 'city' => 'city_guide', 'area' => 'area_guide', 'poi' => 'poi_guide', 'route' => 'itinerary');
        return $map[$type] ?? 'destination_guide';
    }

    public function delete_content_index_for_object($object_id, $object_type) {
        global $wpdb;
        return $wpdb->delete(
            $this->table_content_index(),
            array('object_id' => absint($object_id), 'object_type' => sanitize_key($object_type)),
            array('%d', '%s')
        );
    }

    public function get_primary_location_for_object($object_id, $object_type) {
        global $wpdb;
        if (!$this->tables_exist()) {
            return null;
        }
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT l.*, ci.raw_payload
                 FROM {$this->table_content_index()} ci
                 LEFT JOIN {$this->table_locations()} l ON l.id = ci.location_id
                 WHERE ci.object_id = %d AND ci.object_type = %s AND ci.is_primary = 1
                 LIMIT 1",
                absint($object_id),
                sanitize_key($object_type)
            ),
            ARRAY_A
        );
    }

    public function get_dashboard_counts() {
        global $wpdb;
        if (!$this->tables_exist()) {
            return array('tables_exist' => false);
        }

        $enabled_values = "('1','yes','true','on')";
        $inactive_values = "('0','no','false','off')";

        return array(
            'tables_exist' => true,
            'active_geo_content' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_alma_geo_enabled' AND pm.meta_value IN $enabled_values AND p.post_type IN ('post','page')"),
            'posts_with_geo_meta' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_alma_geo_enabled' AND pm.meta_value IN $enabled_values AND p.post_type IN ('post','page')"),
            'affiliate_links_with_geo_meta' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_alma_geo_enabled' AND pm.meta_value IN $enabled_values AND p.post_type = %s", self::OBJECT_TYPE_AFFILIATE_LINK)),
            'locations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()}"),
            'content_relations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_content_index()}"),
            'pending_geocoding' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status = 'pending'"),
            'verified_geocoding' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status = 'verified'"),
            'content_with_verified_locations' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT ci.object_id) FROM {$this->table_content_index()} ci INNER JOIN {$this->table_locations()} l ON l.id = ci.location_id WHERE ci.is_primary = 1 AND l.geocoding_status = 'verified'"),
            'content_pending_geocoding' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT ci.object_id) FROM {$this->table_content_index()} ci INNER JOIN {$this->table_locations()} l ON l.id = ci.location_id WHERE ci.is_primary = 1 AND l.geocoding_status = 'pending'"),
            'locations_verified' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status = 'verified'"),
            'locations_pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status = 'pending'"),
            'locations_failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status = 'failed'"),
            'manual_review' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_locations()} WHERE geocoding_status IN ('manual_required','ambiguous')"),
            'inactive_or_discarded' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE (meta_key = '_alma_geo_enabled' AND meta_value IN $inactive_values) OR (meta_key = '_alma_geo_import_status' AND meta_value IN ('discard','geocoding_failed'))"),
            'widget_eligible' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_content_index()} WHERE widget_eligible = 1"),
        );
    }

    public function get_locations($limit = 100) {
        global $wpdb;
        if (!$this->tables_exist()) {
            return array();
        }
        $limit = max(1, min(500, absint($limit)));
        return $wpdb->get_results(
            "SELECT l.*, COUNT(ci.id) AS content_count
             FROM {$this->table_locations()} l
             LEFT JOIN {$this->table_content_index()} ci ON ci.location_id = l.id
             GROUP BY l.id
             ORDER BY l.updated_at DESC
             LIMIT $limit",
            ARRAY_A
        );
    }

    public function get_location($location_id) {
        global $wpdb;
        if (!$this->tables_exist()) {
            return null;
        }
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_locations()} WHERE id = %d LIMIT 1", absint($location_id)),
            ARRAY_A
        );
    }

    public function get_locations_by_geocoding_status($status = 'pending', $limit = 20) {
        global $wpdb;
        if (!$this->tables_exist()) {
            return array();
        }
        $status = sanitize_key($status);
        $limit = max(1, min(5000, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_locations()} WHERE geocoding_status = %s ORDER BY updated_at ASC, id ASC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );
    }

    public function get_locations_by_geocoding_statuses_for_object_type($statuses, $object_type, $limit = 20) {
        global $wpdb;
        $statuses = array_values(array_filter(array_map('sanitize_key', is_array($statuses) ? $statuses : array($statuses))));
        $object_type = sanitize_key($object_type);
        if (!$this->tables_exist() || empty($statuses) || $object_type === '') {
            return array();
        }
        $limit = max(1, min(50, absint($limit)));
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge($statuses, array($object_type, $limit));
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, COUNT(DISTINCT ci.object_id) AS affiliate_link_count
                 FROM {$this->table_locations()} l
                 INNER JOIN {$this->table_content_index()} ci ON ci.location_id = l.id
                 WHERE l.geocoding_status IN ($placeholders)
                   AND ci.object_type = %s
                   AND ci.is_primary = 1
                 GROUP BY l.id
                 ORDER BY l.updated_at ASC, l.id ASC
                 LIMIT %d",
                $params
            ),
            ARRAY_A
        );
    }

    public function count_linked_objects_for_location($location_id, $object_type = '') {
        global $wpdb;
        if (!$this->tables_exist()) {
            return 0;
        }
        $location_id = absint($location_id);
        $object_type = sanitize_key($object_type);
        if (!$location_id) {
            return 0;
        }
        if ($object_type !== '') {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT object_id) FROM {$this->table_content_index()} WHERE location_id = %d AND object_type = %s", $location_id, $object_type));
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT object_id) FROM {$this->table_content_index()} WHERE location_id = %d", $location_id));
    }

    public function sync_location_to_linked_objects($location_id) {
        global $wpdb;
        $report = array(
            'location_id' => absint($location_id),
            'objects_found' => 0,
            'objects_updated' => 0,
            'objects_skipped' => 0,
            'errors' => array(),
        );

        $location = $this->get_location($location_id);
        if (!$location) {
            $report['errors'][] = __('Località non trovata.', 'affiliate-link-manager-ai');
            return $report;
        }
        if (($location['geocoding_status'] ?? '') !== 'verified') {
            $report['errors'][] = __('Località non verified: sincronizzazione saltata.', 'affiliate-link-manager-ai');
            return $report;
        }

        $allowed_types = array(self::OBJECT_TYPE_POST, self::OBJECT_TYPE_PAGE, self::OBJECT_TYPE_AFFILIATE_LINK);
        $relations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_content_index()} WHERE location_id = %d AND is_primary = 1",
                absint($location_id)
            ),
            ARRAY_A
        );
        $report['objects_found'] = count($relations);

        foreach ($relations as $relation) {
            $object_id = absint($relation['object_id'] ?? 0);
            $object_type = sanitize_key($relation['object_type'] ?? '');
            if (!$object_id || !in_array($object_type, $allowed_types, true)) {
                $report['objects_skipped']++;
                continue;
            }

            $post = get_post($object_id);
            if (!$post || $post->post_type !== $object_type) {
                $report['objects_skipped']++;
                $report['errors'][] = sprintf(__('Oggetto #%1$d non trovato o tipo non coerente.', 'affiliate-link-manager-ai'), $object_id);
                continue;
            }

            $current_primary = $this->get_primary_location_for_object($object_id, $object_type);
            if (!empty($current_primary['id']) && (int) $current_primary['id'] !== (int) $location['id']) {
                $report['objects_skipped']++;
                continue;
            }

            update_post_meta($object_id, '_alma_geo_geocoding_status', 'verified');
            update_post_meta($object_id, '_alma_geo_primary_lat', $location['lat'] !== null ? (string) $location['lat'] : '');
            update_post_meta($object_id, '_alma_geo_primary_lng', $location['lng'] !== null ? (string) $location['lng'] : '');
            update_post_meta($object_id, '_alma_geo_primary_place_id', (string) ($location['geo_provider_place_id'] ?? ''));
            update_post_meta($object_id, '_alma_geo_provider', (string) ($location['geo_provider'] ?? ''));
            update_post_meta($object_id, '_alma_geo_primary_provider', (string) ($location['geo_provider'] ?? ''));
            update_post_meta($object_id, '_alma_geo_primary_formatted_address', (string) ($location['formatted_address'] ?? ''));
            update_post_meta($object_id, '_alma_geo_updated_at', current_time('mysql'));
            $this->sync_locations_json_for_object($object_id, $object_type);
            $report['objects_updated']++;
        }

        return $report;
    }

    public function sync_all_verified_locations_to_objects() {
        $locations = $this->get_locations_by_geocoding_status('verified', 5000);
        $report = array(
            'locations_found' => count($locations),
            'locations_processed' => 0,
            'objects_found' => 0,
            'objects_updated' => 0,
            'objects_skipped' => 0,
            'errors' => array(),
        );
        foreach ($locations as $location) {
            $sync = $this->sync_location_to_linked_objects((int) $location['id']);
            $report['locations_processed']++;
            $report['objects_found'] += (int) ($sync['objects_found'] ?? 0);
            $report['objects_updated'] += (int) ($sync['objects_updated'] ?? 0);
            $report['objects_skipped'] += (int) ($sync['objects_skipped'] ?? 0);
            foreach (($sync['errors'] ?? array()) as $error) {
                $report['errors'][] = array('location_id' => (int) $location['id'], 'message' => $error);
            }
        }
        return $report;
    }

    public function get_geocoding_status_counts() {
        global $wpdb;
        $counts = array('pending' => 0, 'verified' => 0, 'ambiguous' => 0, 'manual_required' => 0, 'failed' => 0, 'retry_later' => 0, 'not_required' => 0);
        if (!$this->tables_exist()) {
            return $counts;
        }
        $rows = $wpdb->get_results("SELECT geocoding_status, COUNT(*) AS total FROM {$this->table_locations()} GROUP BY geocoding_status", ARRAY_A);
        foreach ($rows as $row) {
            $status = sanitize_key($row['geocoding_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }
        return $counts;
    }

    public function get_geocoding_status_counts_by_object_type($object_type) {
        global $wpdb;
        $object_type = sanitize_key($object_type);
        $counts = array('pending' => 0, 'verified' => 0, 'ambiguous' => 0, 'manual_required' => 0, 'failed' => 0, 'retry_later' => 0, 'not_required' => 0);
        if (!$this->tables_exist() || $object_type === '') {
            return $counts;
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.geocoding_status, COUNT(DISTINCT l.id) AS total
                 FROM {$this->table_locations()} l
                 INNER JOIN {$this->table_content_index()} ci ON ci.location_id = l.id
                 WHERE ci.object_type = %s
                 GROUP BY l.geocoding_status",
                $object_type
            ),
            ARRAY_A
        );
        foreach ($rows as $row) {
            $status = sanitize_key($row['geocoding_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }
        return $counts;
    }

    public function sync_locations_json_for_object($object_id, $object_type) {
        $locations = $this->get_associated_locations_for_object($object_id, $object_type);
        if (empty($locations)) {
            return false;
        }
        update_post_meta(absint($object_id), '_alma_geo_locations_json', wp_json_encode($locations));
        return true;
    }

    public function update_location_geocoding($location_id, $result) {
        global $wpdb;
        $location_id = absint($location_id);
        if (!$location_id || !$this->tables_exist()) {
            return false;
        }
        $status = sanitize_key($result['status'] ?? 'failed');
        $allowed_statuses = array('pending', 'verified', 'ambiguous', 'manual_required', 'failed', 'retry_later', 'not_required');
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'failed';
        }

        $row = array(
            'geocoding_status' => $status,
            'geocoding_error' => sanitize_textarea_field($result['message'] ?? ''),
            'updated_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%s');

        if (in_array($status, array('verified', 'ambiguous', 'manual_required'), true)) {
            foreach (array('lat' => '%f', 'lng' => '%f') as $key => $format) {
                if (isset($result[$key]) && $result[$key] !== null && $result[$key] !== '') {
                    $row[$key] = (float) $result[$key];
                    $formats[] = $format;
                }
            }
            if (!empty($result['geo_provider'])) {
                $row['geo_provider'] = sanitize_key($result['geo_provider']);
                $formats[] = '%s';
            }
            if (!empty($result['place_id'])) {
                $row['geo_provider_place_id'] = sanitize_text_field($result['place_id']);
                $formats[] = '%s';
            }
            if (!empty($result['formatted_address'])) {
                $row['formatted_address'] = sanitize_text_field($result['formatted_address']);
                $formats[] = '%s';
            }
            if (!empty($result['address_components'])) {
                $row['address_components'] = wp_json_encode($result['address_components']);
                $formats[] = '%s';
            }
            $row['geocoded_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        if ($status === 'verified') {
            $row['geocoding_error'] = '';
        }

        $updated = false !== $wpdb->update($this->table_locations(), $row, array('id' => $location_id), $formats, array('%d'));
        if ($updated && isset($result['confidence']) && $result['confidence'] !== '') {
            $wpdb->update(
                $this->table_content_index(),
                array('confidence' => (float) $result['confidence'], 'updated_at' => current_time('mysql')),
                array('location_id' => $location_id),
                array('%f', '%s'),
                array('%d')
            );
        }
        return $updated;
    }

    public function sanitize_location_data($data) {
        $data = is_array($data) ? $data : array();
        return array(
            'canonical_name' => sanitize_text_field($data['canonical_name'] ?? ''),
            'type' => sanitize_key($data['type'] ?? 'unknown'),
            'country' => sanitize_text_field($data['country'] ?? ''),
            'country_code' => strtoupper(sanitize_text_field($data['country_code'] ?? '')),
            'region' => sanitize_text_field($data['region'] ?? ''),
            'city' => sanitize_text_field($data['city'] ?? ''),
            'area' => sanitize_text_field($data['area'] ?? ''),
            'poi' => sanitize_text_field($data['poi'] ?? ''),
            'lat' => isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null,
            'geo_provider' => sanitize_key($data['geo_provider'] ?? ($data['provider'] ?? '')),
            'geo_provider_place_id' => sanitize_text_field($data['geo_provider_place_id'] ?? ($data['place_id'] ?? '')),
            'suggested_geocoding_query' => sanitize_text_field($data['suggested_geocoding_query'] ?? ''),
            'geocoding_status' => sanitize_key($data['geocoding_status'] ?? 'pending'),
            'formatted_address' => sanitize_text_field($data['formatted_address'] ?? ''),
            'address_components' => isset($data['address_components']) ? wp_json_encode($data['address_components']) : null,
            'geocoded_at' => sanitize_text_field($data['geocoded_at'] ?? ''),
            'geocoding_error' => sanitize_textarea_field($data['geocoding_error'] ?? ''),
            'aliases' => isset($data['aliases']) ? wp_json_encode($data['aliases']) : null,
        );
    }

    private function normalize_location_signature($data) {
        $data = $this->sanitize_location_data($data);
        foreach (array('canonical_name', 'region', 'city', 'area', 'poi', 'formatted_address') as $key) {
            $data[$key] = strtolower(trim($data[$key]));
        }
        return $data;
    }
}
