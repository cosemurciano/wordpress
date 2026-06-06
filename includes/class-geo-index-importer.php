<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Safe CSV importer for article Geo Index records.
 */
class ALMA_Geo_Index_Importer {
    const REPORT_OPTION = 'alma_geo_index_last_import_report';

    private $store;
    private $metabox;

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
        $this->metabox = new ALMA_Geo_Index_Metabox($this->store);
    }

    public static function required_headers() {
        return array(
            'post_id','source_url','post_title','post_slug','content_type','commercial_intent','geo_scope','primary_name','primary_canonical_name','primary_type','primary_country','primary_country_code','primary_region','primary_city','primary_area','primary_poi','primary_source','primary_strength','primary_role','confidence','match_weight','suggested_primary_geocoding_query','safe_for_auto_import','safe_for_auto_geocoding','geo_import_status','geocoding_status','geo_quality_flags'
        );
    }

    public function validate_headers($headers) {
        $headers = array_map('sanitize_key', (array) $headers);
        $missing = array();
        foreach (self::required_headers() as $required) {
            if (!in_array($required, $headers, true)) {
                $missing[] = $required;
            }
        }
        return array('valid' => empty($missing), 'missing' => $missing, 'headers' => $headers);
    }

    public function parse_csv_file($file_path, $limit = 0) {
        $rows = array();
        $headers = array();
        $total = 0;
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return array('headers' => array(), 'rows' => array(), 'total' => 0, 'error' => 'file_open_failed');
        }

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if (empty($headers)) {
                if (isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                }
                $headers = array_map('sanitize_key', $data);
                continue;
            }
            if ($this->is_empty_csv_row($data)) {
                continue;
            }
            $total++;
            if (!$limit || count($rows) < $limit) {
                $rows[] = array_combine($headers, array_slice(array_pad($data, count($headers), ''), 0, count($headers)));
            }
        }
        fclose($handle);

        return array('headers' => $headers, 'rows' => $rows, 'total' => $total, 'error' => '');
    }

    public function import_csv($file_path, $args = array()) {
        $args = wp_parse_args($args, array(
            'overwrite' => false,
            'file_name' => basename($file_path),
            'allowed_post_types' => array('post', 'page'),
        ));
        $parsed = $this->parse_csv_file($file_path);
        $validation = $this->validate_headers($parsed['headers']);
        $report = $this->empty_report($args['file_name']);
        $report['records_read'] = (int) $parsed['total'];

        if (!$validation['valid']) {
            $report['errors']++;
            $report['messages'][] = array('code' => 'missing_headers', 'headers' => $validation['missing']);
            $this->save_report($report);
            return $report;
        }

        if (!$this->store->tables_exist()) {
            $this->store->install_tables();
        }

        foreach ($parsed['rows'] as $row) {
            $result = $this->import_row($row, $args);
            $report[$result['bucket']]++;
            if (!empty($result['message'])) {
                $report['messages'][] = $result['message'];
            }
        }

        $this->save_report($report);
        return $report;
    }

    public function import_row($row, $args = array()) {
        $args = wp_parse_args($args, array('overwrite' => false, 'allowed_post_types' => array('post', 'page')));
        $row = $this->normalize_row($row);
        $post_id = absint($row['post_id'] ?? 0);

        if (!$this->is_safe_row($row)) {
            return $this->result('skipped', 'unsafe_or_status_not_allowed', $post_id);
        }
        if (!$post_id) {
            return $this->result('errors', 'invalid_post_id', 0);
        }
        $post = get_post($post_id);
        if (!$post) {
            return $this->result('post_not_found', 'post_not_found', $post_id);
        }
        if (!in_array($post->post_type, (array) $args['allowed_post_types'], true)) {
            return $this->result('errors', 'post_type_not_allowed', $post_id, array('post_type' => $post->post_type));
        }
        $has_existing_geo = get_post_meta($post_id, '_alma_geo_enabled', true) !== '' || get_post_meta($post_id, '_alma_geo_primary_canonical_name', true) !== '';
        if ($has_existing_geo && empty($args['overwrite'])) {
            return $this->result('existing_skipped', 'skipped_existing_geo', $post_id);
        }

        $meta = $this->row_to_meta($row);
        foreach (ALMA_Geo_Index_Metabox::meta_keys() as $key) {
            update_post_meta($post_id, $key, $meta[$key] ?? '');
        }
        update_post_meta($post_id, '_alma_geo_updated_at', current_time('mysql'));
        $this->metabox->sync_tables($post_id, $post->post_type, array_merge($meta, array(
            'suggested_geocoding_query' => $row['suggested_primary_geocoding_query'] ?? '',
            'raw_payload_source' => 'safe_csv',
            'raw_payload' => array(
                'post_id' => $post_id,
                'source_url' => esc_url_raw($row['source_url'] ?? ''),
                'post_slug' => sanitize_title($row['post_slug'] ?? ''),
                'primary_strength' => sanitize_text_field($row['primary_strength'] ?? ''),
                'primary_role' => sanitize_key($row['primary_role'] ?? ''),
            ),
        )));

        if ($has_existing_geo) {
            ALMA_Logger::info('Geo Index import updated existing geo record.', array('post_id' => $post_id));
            return $this->result('updated', 'updated_existing_geo', $post_id);
        }

        ALMA_Logger::info('Geo Index import created geo record.', array('post_id' => $post_id));
        return $this->result('imported', 'imported', $post_id);
    }

    public function normalize_row($row) {
        $normalized = array();
        foreach ((array) $row as $key => $value) {
            $key = sanitize_key($key);
            $normalized[$key] = is_string($value) ? trim($value) : $value;
        }
        return $normalized;
    }

    public function is_safe_row($row) {
        $import_status = sanitize_key($row['geo_import_status'] ?? '');
        return $this->to_bool($row['safe_for_auto_import'] ?? false)
            && $this->to_bool($row['safe_for_auto_geocoding'] ?? false)
            && in_array($import_status, array('needs_geocoding', 'ready'), true);
    }

    public function row_to_meta($row) {
        $content_type = $this->allowed_or_default($row['content_type'] ?? '', ALMA_Geo_Index_Metabox::content_types(), 'uncertain');
        $commercial_intent = $this->allowed_or_default($row['commercial_intent'] ?? '', ALMA_Geo_Index_Metabox::commercial_intents(), 'none');
        $geo_scope = $this->allowed_or_default($row['geo_scope'] ?? '', ALMA_Geo_Index_Metabox::geo_scopes(), 'uncertain');
        $primary_type = $this->allowed_or_default($row['primary_type'] ?? '', ALMA_Geo_Index_Metabox::primary_types(), 'unknown');
        $import_status = $this->allowed_or_default($row['geo_import_status'] ?? '', ALMA_Geo_Index_Metabox::geo_import_statuses(), 'review');
        $geocoding_status = $this->allowed_or_default($row['geocoding_status'] ?? '', ALMA_Geo_Index_Metabox::geocoding_statuses(), 'pending');

        return array(
            '_alma_geo_enabled' => '1',
            '_alma_geo_scope' => $geo_scope,
            '_alma_geo_content_type' => $content_type,
            '_alma_geo_commercial_intent' => $commercial_intent,
            '_alma_geo_widget_eligible' => $commercial_intent === 'high' || $commercial_intent === 'medium' ? '1' : '0',
            '_alma_geo_primary_name' => sanitize_text_field($row['primary_name'] ?? ''),
            '_alma_geo_primary_canonical_name' => sanitize_text_field($row['primary_canonical_name'] ?? ($row['primary_name'] ?? '')),
            '_alma_geo_primary_type' => $primary_type,
            '_alma_geo_primary_country' => sanitize_text_field($row['primary_country'] ?? ''),
            '_alma_geo_primary_country_code' => strtoupper(sanitize_text_field($row['primary_country_code'] ?? '')),
            '_alma_geo_primary_region' => sanitize_text_field($row['primary_region'] ?? ''),
            '_alma_geo_primary_city' => sanitize_text_field($row['primary_city'] ?? ''),
            '_alma_geo_primary_area' => sanitize_text_field($row['primary_area'] ?? ''),
            '_alma_geo_primary_poi' => sanitize_text_field($row['primary_poi'] ?? ''),
            '_alma_geo_primary_lat' => '',
            '_alma_geo_primary_lng' => '',
            '_alma_geo_primary_place_id' => '',
            '_alma_geo_confidence' => isset($row['confidence']) && $row['confidence'] !== '' ? (string) min(1, max(0, (float) $row['confidence'])) : '',
            '_alma_geo_match_weight' => isset($row['match_weight']) && $row['match_weight'] !== '' ? (string) (int) $row['match_weight'] : '',
            '_alma_geo_geocoding_status' => $geocoding_status,
            '_alma_geo_import_status' => $import_status,
            '_alma_geo_locations_json' => '',
            '_alma_geo_quality_flags' => sanitize_textarea_field($row['geo_quality_flags'] ?? ''),
            '_alma_geo_notes' => '',
            '_alma_geo_source' => sanitize_text_field($row['primary_source'] ?? 'safe_csv'),
            '_alma_geo_updated_at' => current_time('mysql'),
        );
    }

    private function empty_report($file_name) {
        return array(
            'date' => current_time('mysql'),
            'file_name' => sanitize_file_name($file_name),
            'records_read' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'updated' => 0,
            'post_not_found' => 0,
            'existing_skipped' => 0,
            'messages' => array(),
        );
    }

    private function result($bucket, $code, $post_id, $extra = array()) {
        return array(
            'bucket' => $bucket,
            'message' => array_merge(array('code' => $code, 'post_id' => absint($post_id)), $extra),
        );
    }

    private function save_report($report) {
        update_option(self::REPORT_OPTION, $report, false);
    }

    private function allowed_or_default($value, $allowed, $default) {
        $value = sanitize_key($value);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function to_bool($value) {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'yes', 'y', 'si', 'sì'), true);
    }

    private function is_empty_csv_row($row) {
        foreach ((array) $row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }
}
