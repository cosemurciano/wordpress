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
            'post_id','source_url','post_title','geo_scope','primary_name','safe_for_auto_import','safe_for_auto_geocoding'
        );
    }

    public static function allowed_csv_mimes() {
        return array(
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/octet-stream',
        );
    }

    public function validate_headers($headers) {
        $headers = array_map('sanitize_key', (array) $headers);
        $headers = array_values(array_filter($headers));
        $missing = array();
        foreach (self::required_headers() as $required) {
            if (!in_array($required, $headers, true)) {
                $missing[] = $required;
            }
        }
        return array('valid' => empty($missing), 'missing' => $missing, 'headers' => $headers);
    }

    public function validate_uploaded_csv($file) {
        if (empty($file) || !is_array($file) || empty($file['name'])) {
            return new WP_Error('geo_csv_missing_file', __('File mancante. Seleziona un file CSV da caricare.', 'affiliate-link-manager-ai'));
        }

        $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error !== UPLOAD_ERR_OK) {
            return new WP_Error('geo_csv_upload_error', $this->upload_error_message($upload_error));
        }

        $file_name = sanitize_file_name($file['name']);
        if (strtolower(pathinfo($file_name, PATHINFO_EXTENSION)) !== 'csv') {
            return new WP_Error('geo_csv_invalid_extension', __('Estensione non valida. Carica solo file con estensione .csv generati dal flusso Geo Index.', 'affiliate-link-manager-ai'));
        }

        $tmp_name = $file['tmp_name'] ?? '';
        if (!$tmp_name || !is_readable($tmp_name)) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile. Riprova il caricamento del CSV.', 'affiliate-link-manager-ai'));
        }

        $content = $this->validate_csv_content($tmp_name);
        if (is_wp_error($content)) {
            return $content;
        }

        $detected_mime = $this->detect_uploaded_mime($tmp_name, $file_name, $file['type'] ?? '');
        if ($detected_mime && !in_array($detected_mime, self::allowed_csv_mimes(), true)) {
            return new WP_Error('geo_csv_invalid_mime', __('MIME non consentito. Il file caricato non sembra un CSV valido. Carica un file .csv esportato dal processo Geo Index.', 'affiliate-link-manager-ai'), array('mime' => $detected_mime));
        }

        return array(
            'file_name' => $file_name,
            'tmp_name' => $tmp_name,
            'mime' => $detected_mime,
            'headers' => $content['headers'],
            'delimiter' => $content['delimiter'],
        );
    }

    public function validate_csv_content($file_path) {
        if (!$file_path || !is_readable($file_path)) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile. Riprova il caricamento del CSV.', 'affiliate-link-manager-ai'));
        }

        $delimiter = $this->detect_csv_delimiter($file_path);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile. Riprova il caricamento del CSV.', 'affiliate-link-manager-ai'));
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        fclose($handle);

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $headers = array_map('sanitize_key', (array) $headers);
        $headers = array_values(array_filter($headers));
        if (empty($headers) || count($headers) < 2) {
            return new WP_Error('geo_csv_missing_headers', __('CSV senza intestazioni. Verifica che la prima riga contenga le intestazioni generate dal flusso Geo Index.', 'affiliate-link-manager-ai'));
        }

        $validation = $this->validate_headers($headers);
        if (!$validation['valid']) {
            return new WP_Error('geo_csv_required_headers_missing', sprintf(__('Intestazioni obbligatorie mancanti: %s', 'affiliate-link-manager-ai'), implode(', ', $validation['missing'])), array('missing' => $validation['missing']));
        }

        return array('headers' => $headers, 'delimiter' => $delimiter);
    }

    public function parse_csv_file($file_path, $limit = 0, $delimiter = null) {
        $rows = array();
        $headers = array();
        $total = 0;
        $delimiter = $delimiter ?: $this->detect_csv_delimiter($file_path);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return array('headers' => array(), 'rows' => array(), 'total' => 0, 'error' => 'file_open_failed', 'delimiter' => $delimiter);
        }

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
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

        return array('headers' => $headers, 'rows' => $rows, 'total' => $total, 'error' => '', 'delimiter' => $delimiter);
    }

    public function import_csv($file_path, $args = array()) {
        $args = wp_parse_args($args, array(
            'overwrite' => false,
            'file_name' => basename($file_path),
            'allowed_post_types' => array('post', 'page'),
        ));
        $parsed = $this->parse_csv_file($file_path, 0, $args['delimiter'] ?? null);
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
            && in_array($import_status, array('', 'active', 'needs_geocoding', 'ready'), true);
    }

    public function row_to_meta($row) {
        $content_type = $this->allowed_or_default($row['content_type'] ?? '', ALMA_Geo_Index_Metabox::content_types(), 'uncertain');
        $commercial_intent = $this->allowed_or_default($row['commercial_intent'] ?? '', ALMA_Geo_Index_Metabox::commercial_intents(), 'none');
        $geo_scope = $this->allowed_or_default($row['geo_scope'] ?? '', ALMA_Geo_Index_Metabox::geo_scopes(), 'uncertain');
        $primary_type = $this->allowed_or_default($row['primary_type'] ?? '', ALMA_Geo_Index_Metabox::primary_types(), 'unknown');
        $widget_eligible = $this->to_bool($row['widget_eligible'] ?? false) || $this->to_bool($row['safe_for_auto_import'] ?? false);

        return array(
            '_alma_geo_enabled' => 'yes',
            '_alma_geo_scope' => $geo_scope,
            '_alma_geo_content_type' => $content_type,
            '_alma_geo_commercial_intent' => $commercial_intent,
            '_alma_geo_widget_eligible' => $widget_eligible ? 'yes' : 'no',
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
            '_alma_geo_geocoding_status' => 'pending',
            '_alma_geo_import_status' => 'active',
            '_alma_geo_locations_json' => '',
            '_alma_geo_quality_flags' => sanitize_textarea_field($row['geo_quality_flags'] ?? ''),
            '_alma_geo_notes' => '',
            '_alma_geo_source' => sanitize_text_field(!empty($row['primary_source']) ? $row['primary_source'] : 'safe_import_csv'),
            '_alma_geo_updated_at' => current_time('mysql'),
        );
    }

    private function detect_csv_delimiter($file_path) {
        $line = '';
        $handle = fopen($file_path, 'r');
        if ($handle) {
            $line = (string) fgets($handle);
            fclose($handle);
        }

        $comma_count = substr_count($line, ',');
        $semicolon_count = substr_count($line, ';');
        return $semicolon_count > $comma_count ? ';' : ',';
    }

    private function detect_uploaded_mime($tmp_name, $file_name, $browser_mime) {
        $browser_mime = sanitize_mime_type($browser_mime);
        if ($browser_mime && in_array($browser_mime, self::allowed_csv_mimes(), true)) {
            return $browser_mime;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $finfo_mime = sanitize_mime_type((string) finfo_file($finfo, $tmp_name));
                finfo_close($finfo);
                if ($finfo_mime) {
                    return $finfo_mime;
                }
            }
        }

        $allowed = array('csv' => implode('|', self::allowed_csv_mimes()));
        if (function_exists('wp_check_filetype_and_ext')) {
            $checked = wp_check_filetype_and_ext($tmp_name, $file_name, $allowed);
            if (!empty($checked['type'])) {
                return $checked['type'];
            }
        }

        $filetype = wp_check_filetype($file_name, $allowed);
        return !empty($filetype['type']) ? $filetype['type'] : $browser_mime;
    }

    private function upload_error_message($error_code) {
        switch ((int) $error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('Errore upload: il file CSV supera la dimensione massima consentita dal server.', 'affiliate-link-manager-ai');
            case UPLOAD_ERR_PARTIAL:
                return __('Errore upload: il file CSV è stato caricato solo parzialmente.', 'affiliate-link-manager-ai');
            case UPLOAD_ERR_NO_FILE:
                return __('File mancante. Seleziona un file CSV da caricare.', 'affiliate-link-manager-ai');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Errore upload: cartella temporanea del server non disponibile.', 'affiliate-link-manager-ai');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Errore upload: impossibile scrivere il file temporaneo sul server.', 'affiliate-link-manager-ai');
            case UPLOAD_ERR_EXTENSION:
                return __('Errore upload: il server ha bloccato il caricamento del file.', 'affiliate-link-manager-ai');
            default:
                return __('Errore upload sconosciuto. Riprova con un file CSV valido.', 'affiliate-link-manager-ai');
        }
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
