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
                 LIMIT 1",
                $signature['canonical_name'],
                $signature['type'],
                $signature['country_code'],
                $signature['region'],
                $signature['city'],
                $signature['area'],
                $signature['poi']
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
        $existing = $this->get_location_by_signature($data);
        $formats = array('%s','%s','%s','%s','%s','%s','%s','%s','%f','%f','%s','%s','%s','%s','%s','%s');
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
                "SELECT id FROM {$this->table_content_index()} WHERE object_id = %d AND object_type = %s AND is_primary = %d LIMIT 1",
                $object_id,
                $object_type,
                $row['is_primary']
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
        $limit = max(1, min(50, absint($limit)));
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_locations()} WHERE geocoding_status = %s ORDER BY updated_at ASC, id ASC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );
    }

    public function get_geocoding_status_counts() {
        global $wpdb;
        $counts = array('pending' => 0, 'verified' => 0, 'ambiguous' => 0, 'manual_required' => 0, 'failed' => 0, 'not_required' => 0);
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

    public function update_location_geocoding($location_id, $result) {
        global $wpdb;
        $location_id = absint($location_id);
        if (!$location_id || !$this->tables_exist()) {
            return false;
        }
        $status = sanitize_key($result['status'] ?? 'failed');
        $allowed_statuses = array('pending', 'verified', 'ambiguous', 'manual_required', 'failed', 'not_required');
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

        return false !== $wpdb->update($this->table_locations(), $row, array('id' => $location_id), $formats, array('%d'));
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
            'geo_provider' => sanitize_key($data['geo_provider'] ?? ''),
            'geo_provider_place_id' => sanitize_text_field($data['geo_provider_place_id'] ?? ''),
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
        foreach (array('canonical_name', 'region', 'city', 'area', 'poi') as $key) {
            $data[$key] = strtolower(trim($data[$key]));
        }
        return $data;
    }
}
