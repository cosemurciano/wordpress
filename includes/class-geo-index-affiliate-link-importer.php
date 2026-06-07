<?php
if (!defined('ABSPATH')) { exit; }

/**
 * CSV importer for Geo Index affiliate_link associations.
 *
 * This class never calls Google Maps. Imported locations remain pending and are
 * geocoded later by the existing Geo Index geocoding workflow.
 */
class ALMA_Geo_Index_Affiliate_Link_Importer {
    const SOURCE = 'affiliate_geo_csv_import';
    const MAX_ASSOCIATED_LOCATIONS = 10;

    private $store;

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
    }

    public static function required_headers() {
        return array(
            'affiliate_link_id',
            'affiliate_url',
        );
    }

    public static function primary_location_headers() {
        return array('primary_name','primary_canonical_name','primary_city','primary_area','primary_poi','primary_port','primary_airport');
    }

    public static function recommended_headers() {
        return array('post_title','provider','geo_scope','primary_name','primary_canonical_name','primary_country','primary_country_code','primary_region','primary_city','primary_suggested_geocoding_query','safe_for_auto_import','safe_for_auto_geocoding','final_bucket','needs_human_review','secondary_locations_json','source_name','activity_type','commercial_intent','widget_eligible','confidence','match_weight','review_reason_code','geo_quality_flags');
    }

    public static function allowed_csv_mimes() {
        return ALMA_Geo_Index_Importer::allowed_csv_mimes();
    }

    public static function normalize_header($header) {
        $header = (string) $header;
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = preg_replace('/^\x{FEFF}/u', '', $header);
        $header = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $header);
        $header = preg_replace('/[[:cntrl:]]/u', '', $header);
        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[^a-z0-9_]+/', '_', $header);
        $header = preg_replace('/_+/', '_', $header);
        $key = trim($header, '_');
        $aliases = array(
            'id' => 'affiliate_link_id',
            'post_id' => 'affiliate_link_id',
            'link_id' => 'affiliate_link_id',
            'title' => 'post_title',
            'link_title' => 'post_title',
            'url' => 'affiliate_url',
            'link_url' => 'affiliate_url',
            'safe_import' => 'safe_for_auto_import',
            'is_safe_import' => 'safe_for_auto_import',
            'safe_auto_import' => 'safe_for_auto_import',
            'bucket' => 'final_bucket',
            'import_bucket' => 'final_bucket',
            'region' => 'primary_region',
            'country' => 'primary_country',
            'country_code' => 'primary_country_code',
            'city' => 'primary_city',
            'canonical_name' => 'primary_canonical_name',
            'place_id' => 'primary_place_id',
            'formatted_address' => 'primary_formatted_address',
            'area' => 'primary_area',
            'poi' => 'primary_poi',
            'port' => 'primary_port',
            'airport' => 'primary_airport',
            'suggested_geocoding_query' => 'primary_suggested_geocoding_query',
            'geocoding_query' => 'primary_suggested_geocoding_query',
            'safe_geocoding' => 'safe_for_auto_geocoding',
            'human_review' => 'needs_human_review',
        );
        return $aliases[$key] ?? $key;
    }

    public static function normalize_headers($headers) {
        $normalized = array();
        foreach ((array) $headers as $header) {
            $key = self::normalize_header($header);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }
        return $normalized;
    }

    public function validate_headers($headers) {
        $headers = self::normalize_headers($headers);
        $missing = array();
        foreach (self::required_headers() as $required) {
            if (!in_array($required, $headers, true)) {
                $missing[] = $required;
            }
        }
        if (empty(array_intersect(self::primary_location_headers(), $headers))) {
            $missing[] = 'primary_location';
        }
        $recommended_missing = array_values(array_diff(self::recommended_headers(), $headers));
        return array('valid' => empty($missing), 'missing' => $missing, 'recommended_missing' => $recommended_missing, 'headers' => $headers);
    }

    public function validate_uploaded_csv($file) {
        $article_importer = new ALMA_Geo_Index_Importer($this->store);
        if (empty($file) || !is_array($file) || empty($file['name'])) {
            return new WP_Error('geo_csv_missing_file', __('File mancante. Seleziona un file CSV da caricare.', 'affiliate-link-manager-ai'));
        }
        $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error !== UPLOAD_ERR_OK) {
            return new WP_Error('geo_csv_upload_error', __('Errore upload CSV. Riprova con un file valido.', 'affiliate-link-manager-ai'));
        }
        $file_name = sanitize_file_name($file['name']);
        if (strtolower(pathinfo($file_name, PATHINFO_EXTENSION)) !== 'csv') {
            return new WP_Error('geo_csv_invalid_extension', __('Estensione non valida. Carica solo file .csv.', 'affiliate-link-manager-ai'));
        }
        $tmp_name = $file['tmp_name'] ?? '';
        if (!$tmp_name || !is_readable($tmp_name)) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile. Riprova il caricamento del CSV.', 'affiliate-link-manager-ai'));
        }
        $content = $this->validate_csv_content($tmp_name);
        if (is_wp_error($content)) {
            return $content;
        }
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = sanitize_mime_type((string) finfo_file($finfo, $tmp_name));
                finfo_close($finfo);
            }
        }
        $browser_mime = sanitize_mime_type($file['type'] ?? '');
        if ($browser_mime && in_array($browser_mime, self::allowed_csv_mimes(), true)) {
            $mime = $browser_mime;
        }
        if ($mime && !in_array($mime, self::allowed_csv_mimes(), true)) {
            return new WP_Error('geo_csv_invalid_mime', __('MIME non consentito. Il file caricato non sembra un CSV valido.', 'affiliate-link-manager-ai'), array('mime' => $mime));
        }
        unset($article_importer);
        return array('file_name' => $file_name, 'tmp_name' => $tmp_name, 'mime' => $mime, 'headers' => $content['headers'], 'delimiter' => $content['delimiter']);
    }

    public function validate_csv_content($file_path) {
        if (!$file_path || !is_readable($file_path)) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile.', 'affiliate-link-manager-ai'));
        }
        $delimiter = $this->detect_csv_delimiter($file_path);
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('geo_csv_not_readable', __('File non leggibile.', 'affiliate-link-manager-ai'));
        }
        $headers = fgetcsv($handle, 0, $delimiter);
        fclose($handle);
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $headers = self::normalize_headers($headers);
        if (empty($headers) || count($headers) < 2) {
            return new WP_Error('geo_csv_missing_headers', __('CSV senza intestazioni.', 'affiliate-link-manager-ai'));
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
                $headers = self::normalize_headers($data);
                continue;
            }
            if ($this->is_empty_csv_row($data)) {
                continue;
            }
            $total++;
            if (!$limit || count($rows) < $limit) {
                $rows[] = $this->normalize_row(array_combine($headers, array_slice(array_pad($data, count($headers), ''), 0, count($headers))));
            }
        }
        fclose($handle);
        return array('headers' => $headers, 'rows' => $rows, 'total' => $total, 'error' => '', 'delimiter' => $delimiter);
    }

    public function normalize_row($row) {
        $normalized = array();
        foreach ((array) $row as $key => $value) {
            $key = self::normalize_header($key);
            if (is_string($value)) {
                $normalized[$key] = trim($value);
            } else {
                $normalized[$key] = $value;
            }
        }
        $raw_affiliate_link_id = trim((string) ($normalized['affiliate_link_id'] ?? ''));
        $normalized['_raw_affiliate_link_id'] = $raw_affiliate_link_id;
        $normalized['affiliate_link_id'] = absint($raw_affiliate_link_id);
        $normalized['primary_type'] = sanitize_key($normalized['primary_type'] ?? 'unknown');
        $normalized['primary_country_code'] = strtoupper(sanitize_text_field($normalized['primary_country_code'] ?? ''));
        $normalized['final_bucket'] = sanitize_key($normalized['final_bucket'] ?? '');
        if (empty($normalized['final_bucket']) && $this->to_bool($normalized['safe_for_auto_import'] ?? false)) {
            $normalized['final_bucket'] = 'safe_import';
        }
        return $normalized;
    }

    public function is_safe_row($row) {
        $safe_flag = $this->to_bool($row['safe_for_auto_import'] ?? false);
        $bucket = sanitize_key($row['final_bucket'] ?? '');
        return $safe_flag || $bucket === 'safe_import';
    }

    public function create_job_from_csv($file_path, $args = array()) {
        $args = wp_parse_args($args, array(
            'file_name' => basename($file_path),
            'delimiter' => null,
            'overwrite' => false,
            'safe_only' => true,
            'batch_size' => 50,
        ));
        $job_store = new ALMA_Geo_Index_Job_Store();
        $delimiter = $args['delimiter'] ?: $this->detect_csv_delimiter($file_path);
        $options = array(
            'overwrite' => !empty($args['overwrite']),
            'safe_only' => !empty($args['safe_only']),
            'batch_size' => max(25, min(250, absint($args['batch_size']))),
            'delimiter' => $delimiter,
            'csv_headers' => array(),
            'required_missing' => array(),
            'rows_read' => 0,
            'items_created' => 0,
            'rows_discarded' => 0,
            'discard_reasons' => array(),
            'discard_examples' => array(),
            'sql_errors' => array(),
        );
        $job_id = $job_store->create_job(ALMA_Geo_Index_Job_Store::JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT, $args['file_name'], $options, get_current_user_id());

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $job_store->update_job_status($job_id, 'failed', 'csv_open_failed');
            return new WP_Error('alma_geo_csv_open_failed', __('Impossibile riaprire il CSV per creare gli item di import.', 'affiliate-link-manager-ai'), array('job_id' => $job_id));
        }

        $headers = array();
        $total = 0;
        $inserted = 0;
        $discarded = 0;
        $row_number = 0;
        $seen = array();
        global $wpdb;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row_number++;
            if (empty($headers)) {
                $headers = self::normalize_headers($data);
                $options['csv_headers'] = $headers;
                $header_validation = $this->validate_headers($headers);
                $options['required_missing'] = $header_validation['missing'];
                continue;
            }
            if ($this->is_empty_csv_row($data)) {
                continue;
            }
            $total++;
            $row = $this->normalize_row($this->combine_csv_row($headers, $data));
            $reason = $this->staging_reject_reason($row, $args, $seen);
            $status = $reason ? 'skipped' : 'queued';
            $action = $reason ? 'staging_rejected' : '';
            $message = $reason ? 'staging_' . $reason : '';
            $item_id = $job_store->add_job_item($job_id, $row_number, $row, absint($row['affiliate_link_id'] ?? 0), ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, $status, $action, $message);
            if ($item_id && !$reason) {
                $inserted++;
            } elseif ($item_id && $reason) {
                $discarded++;
                $this->record_staging_discard($options, $row_number, $row, $reason);
            } else {
                $discarded++;
                $reason = 'sql_insert_failed';
                $this->record_staging_discard($options, $row_number, $row, $reason);
                if (!empty($wpdb->last_error)) {
                    $options['sql_errors'][] = sanitize_textarea_field($wpdb->last_error);
                    $options['sql_errors'] = array_slice($options['sql_errors'], -10);
                }
            }
        }
        fclose($handle);

        $options['rows_read'] = $total;
        $options['items_created'] = $inserted;
        $options['rows_discarded'] = $discarded;
        $job_store->set_total_records($job_id, $total);
        $job_store->update_job_options($job_id, $options);
        if ($total > 0 && $inserted === 0) {
            $message = $this->zero_staging_message($options);
            $job_store->update_job_status($job_id, 'needs_review', $message);
        }
        return $job_id;
    }

    private function zero_staging_message($options) {
        $reasons = is_array($options['discard_reasons'] ?? null) ? $options['discard_reasons'] : array();
        arsort($reasons);
        $top_reason = key($reasons) ?: 'unknown_error';
        $messages = array(
            'missing_affiliate_link_id' => __('Tutte le righe sono state scartate perché l’header affiliate_link_id non è stato riconosciuto o il valore è vuoto.', 'affiliate-link-manager-ai'),
            'safe_import_false' => __('Tutte le righe sono state scartate perché il filtro safe_import non riconosce righe processabili in safe_for_auto_import/final_bucket.', 'affiliate-link-manager-ai'),
            'affiliate_link_not_found' => __('Tutte le righe sono state scartate perché gli affiliate_link_id non esistono nel database del sito corrente.', 'affiliate-link-manager-ai'),
            'object_not_affiliate_link' => __('Tutte le righe sono state scartate perché gli ID trovati non sono CPT affiliate_link.', 'affiliate-link-manager-ai'),
            'sql_insert_failed' => __('Tutte le righe sono state scartate per errore SQL durante l’inserimento staging.', 'affiliate-link-manager-ai'),
            'missing_affiliate_url' => __('Tutte le righe sono state scartate perché affiliate_url è vuoto.', 'affiliate-link-manager-ai'),
            'invalid_affiliate_url' => __('Tutte le righe sono state scartate perché affiliate_url non è valido.', 'affiliate-link-manager-ai'),
            'missing_primary_location' => __('Tutte le righe sono state scartate perché manca una località primaria riconosciuta.', 'affiliate-link-manager-ai'),
            'final_bucket_discard' => __('Tutte le righe sono state scartate perché final_bucket è discard.', 'affiliate-link-manager-ai'),
        );
        return $messages[$top_reason] ?? sprintf(__('Il CSV è stato letto, ma nessuna riga è stata accettata nella staging. Motivo prevalente: %s.', 'affiliate-link-manager-ai'), sanitize_key($top_reason));
    }

    private function combine_csv_row($headers, $data) {
        $row = array();
        $values = array_slice(array_pad((array) $data, count($headers), ''), 0, count($headers));
        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }
            $value = $values[$index] ?? '';
            if (!isset($row[$header]) || $row[$header] === '') {
                $row[$header] = $value;
            }
        }
        return $row;
    }

    private function staging_reject_reason($row, $args, &$seen) {
        $affiliate_link_id = absint($row['affiliate_link_id'] ?? 0);
        $raw_affiliate_link_id = trim((string) ($row['_raw_affiliate_link_id'] ?? ($row['affiliate_link_id'] ?? '')));
        if ($raw_affiliate_link_id === '') {
            return 'missing_affiliate_link_id';
        }
        if (!$affiliate_link_id) {
            return 'invalid_affiliate_link_id';
        }
        $affiliate_url = trim((string) ($row['affiliate_url'] ?? ''));
        if ($affiliate_url === '') {
            return 'missing_affiliate_url';
        }
        if (!preg_match('#^https?://#i', $affiliate_url) || !filter_var($affiliate_url, FILTER_VALIDATE_URL)) {
            return 'invalid_affiliate_url';
        }
        if (!$this->has_primary_location($row)) {
            return 'missing_primary_location';
        }
        if (sanitize_key($row['final_bucket'] ?? '') === 'discard') {
            return 'final_bucket_discard';
        }
        if (!empty($args['safe_only']) && !$this->is_safe_row($row)) {
            return 'safe_import_false';
        }
        $post = get_post($affiliate_link_id);
        if (!$post) {
            return 'affiliate_link_not_found';
        }
        if ($post->post_type !== ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK) {
            return 'object_not_affiliate_link';
        }
        if ($this->has_existing_geo($affiliate_link_id) && empty($args['overwrite'])) {
            return 'existing_geo_skipped';
        }
        $dedupe_key = $affiliate_link_id . '|' . sanitize_title($this->primary_location_value($row));
        if (isset($seen[$dedupe_key])) {
            return 'duplicate_staging_item';
        }
        $seen[$dedupe_key] = true;
        return '';
    }

    private function record_staging_discard(&$options, $row_number, $row, $reason) {
        $reason = sanitize_key($reason ?: 'unknown_error');
        $options['discard_reasons'][$reason] = (int) ($options['discard_reasons'][$reason] ?? 0) + 1;
        $options['discard_examples'][] = array(
            'row_number' => absint($row_number),
            'affiliate_link_id' => absint($row['affiliate_link_id'] ?? 0),
            'post_title' => sanitize_text_field($row['post_title'] ?? ($row['title'] ?? '')),
            'affiliate_url' => esc_url_raw($row['affiliate_url'] ?? ''),
            'primary_name' => sanitize_text_field($row['primary_name'] ?? ''),
            'final_bucket' => sanitize_key($row['final_bucket'] ?? ''),
            'safe_for_auto_import' => sanitize_text_field($row['safe_for_auto_import'] ?? ''),
            'reason' => $reason,
        );
        $options['discard_examples'] = array_slice($options['discard_examples'], -10);
    }

    public function process_job_batch($job_id, $batch_size = 50, $retry_errors = false) {
        $job_store = new ALMA_Geo_Index_Job_Store();
        $job = $job_store->get_job($job_id);
        if (!$job) {
            return new WP_Error('alma_geo_job_not_found', __('Job non trovato.', 'affiliate-link-manager-ai'));
        }
        if (sanitize_key($job['job_type'] ?? '') !== ALMA_Geo_Index_Job_Store::JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT) {
            return new WP_Error('alma_geo_invalid_job_type', __('Tipo job non valido.', 'affiliate-link-manager-ai'));
        }
        if (in_array($job['status'], array('cancelled','completed','failed','needs_review'), true)) {
            $counts = $job_store->get_item_status_counts($job_id);
            return array(
                'processed' => 0,
                'claimed' => 0,
                'job' => $job_store->get_job($job_id),
                'items' => $job_store->get_items($job_id, 50),
                'counts' => $counts,
                'debug' => array('status_guard' => sanitize_key($job['status'])),
                'diagnostic' => $job_store->get_job_diagnostic($job_id, array('last_ajax_message' => 'status_guard')),
                'message' => __('Sessione in stato terminale: nessun batch processato.', 'affiliate-link-manager-ai'),
            );
        }
        $recovered = $job_store->recover_stale_processing_items($job_id, 10);
        $job_store->update_job_status($job_id, 'processing');
        $options = is_array($job['options']) ? $job['options'] : array();
        $batch_size = max(25, min(250, absint($batch_size ?: ($options['batch_size'] ?? 50))));
        $options['batch_size'] = $batch_size;
        $job_store->update_job_options($job_id, $options);
        $items = $job_store->claim_items($job_id, $batch_size, $retry_errors ? array('queued','error') : array('queued'));
        $claimed = count($items);
        $processed = 0;
        $item_results = array();
        foreach ($items as $item) {
            $item_id = (int) ($item['id'] ?? 0);
            $object_id = absint($item['object_id'] ?? 0);
            try {
                $row = $this->normalize_row($item['raw_payload'] ?? array());
                $result = $this->import_row($row, $options);
                if (is_wp_error($result)) {
                    $result = $this->row_result('error', $result->get_error_message(), absint($row['affiliate_link_id'] ?? $object_id));
                } elseif (!is_array($result) || empty($result['status'])) {
                    $result = $this->row_result('error', 'invalid_import_row_result', absint($row['affiliate_link_id'] ?? $object_id));
                }
            } catch (Throwable $e) {
                $result = $this->row_result('error', 'exception ' . $e->getMessage(), $object_id);
            } catch (Exception $e) {
                $result = $this->row_result('error', 'exception ' . $e->getMessage(), $object_id);
            }

            $status = sanitize_key($result['status'] ?? 'error');
            if (!in_array($status, array('imported','updated','skipped','error'), true)) {
                $status = 'error';
                $result['message'] = trim((string) ($result['message'] ?? '') . ' invalid_final_status');
            }
            $result['status'] = $status;
            $job_store->update_item_result($item_id, $result['status'], $result['action'] ?? $result['status'], $result['message'] ?? '', $result['object_id'] ?? $object_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
            $processed++;
            $item_results[] = array(
                'item_id' => $item_id,
                'row_number' => (int) ($item['row_number'] ?? 0),
                'affiliate_link_id' => absint($result['object_id'] ?? $object_id),
                'status' => $result['status'],
                'action' => sanitize_key($result['action'] ?? $result['status']),
                'message' => sanitize_textarea_field($result['message'] ?? ''),
            );
        }
        $counts = $job_store->recount_job($job_id);
        if ($claimed > 0) {
            $remaining = (int) $counts['queued'] + (int) $counts['processing'];
            $job_store->update_job_status($job_id, $remaining > 0 ? 'queued' : 'completed');
        } else {
            $job_store->update_job_status($job_id, 'queued');
        }
        $job = $job_store->maybe_complete_job($job_id);
        $counts = $job_store->get_item_status_counts($job_id);
        $debug = array(
            'batch_size' => $batch_size,
            'retry_errors' => (bool) $retry_errors,
            'stale_processing_recovered' => (int) $recovered,
            'claim' => $job_store->get_last_claim_debug(),
            'item_results' => $item_results,
        );
        if ($claimed === 0 && !empty($counts['queued'])) {
            $debug['warning'] = 'claim_returned_zero_with_queued_items';
        }
        $message = $this->batch_message($job_id, $counts, $claimed, $processed, $options);
        return array(
            'processed' => $processed,
            'claimed' => $claimed,
            'job' => $job,
            'counts' => $counts,
            'items' => $job_store->get_items($job_id, 50),
            'debug' => $debug,
            'item_results' => $item_results,
            'diagnostic' => $job_store->get_job_diagnostic($job_id, array('last_ajax_message' => 'batch_processed')),
            'message' => $message,
        );
    }


    private function batch_message($job_id, $counts, $claimed, $processed, $options) {
        if ($processed > 0) {
            return sprintf(__('Batch processato: %1$d item claimati, %2$d processati.', 'affiliate-link-manager-ai'), $claimed, $processed);
        }
        $total_items = array_sum(array_map('intval', (array) $counts));
        if ($total_items === 0) {
            return __('Nessun record processabile trovato: gli item non sono stati creati dalla sessione.', 'affiliate-link-manager-ai');
        }
        if (!empty($counts['queued'])) {
            return __('Nessun record processabile trovato: gli item in coda non sono stati claimati dalla tabella staging.', 'affiliate-link-manager-ai');
        }
        if (!empty($counts['error'])) {
            return __('Nessun record processabile trovato: restano solo record in errore nel log.', 'affiliate-link-manager-ai');
        }
        if (!empty($counts['skipped']) && empty($counts['imported']) && empty($counts['updated'])) {
            return !empty($options['safe_only'])
                ? __('Nessun record processabile trovato: il filtro safe-only o dati già importati hanno escluso tutti i record.', 'affiliate-link-manager-ai')
                : __('Nessun record processabile trovato: tutti i record sono già importati o saltati.', 'affiliate-link-manager-ai');
        }
        return __('Nessun record processabile trovato: la sessione non contiene item queued.', 'affiliate-link-manager-ai');
    }

    public function import_row($row, $args = array()) {
        $args = wp_parse_args($args, array('overwrite' => false, 'safe_only' => true));
        $row = $this->normalize_row($row);
        $affiliate_link_id = absint($row['affiliate_link_id'] ?? 0);
        if (!$affiliate_link_id) {
            return $this->row_result('error', 'invalid_affiliate_link_id', 0);
        }
        $post = get_post($affiliate_link_id);
        if (!$post) {
            return $this->row_result('error', 'affiliate_link_not_found', $affiliate_link_id);
        }
        if ($post->post_type !== ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK) {
            return $this->row_result('error', 'object_not_affiliate_link', $affiliate_link_id);
        }
        if (!empty($args['safe_only']) && !$this->is_safe_row($row)) {
            return $this->row_result('skipped', 'safe_import_false', $affiliate_link_id);
        }
        if ($this->has_existing_geo($affiliate_link_id) && empty($args['overwrite'])) {
            return $this->row_result('skipped', 'skipped_existing_geo existing_geo_skipped', $affiliate_link_id);
        }

        $before_locations = $this->count_locations();
        $before_relations = $this->count_relations($affiliate_link_id);
        $geo_data = $this->row_to_geo_data($row);
        if (empty($geo_data['primary_location']['canonical_name']) && empty($geo_data['primary_location']['name'])) {
            return $this->row_result('error', 'missing_primary_name', $affiliate_link_id);
        }
        $result = $this->store->save_geo_meta_for_object($affiliate_link_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, $geo_data, self::SOURCE);
        update_post_meta($affiliate_link_id, '_alma_geo_activity_type', sanitize_key($row['activity_type'] ?? 'unknown'));
        update_post_meta($affiliate_link_id, '_alma_geo_import_status', 'imported');
        update_post_meta($affiliate_link_id, '_alma_geo_source', self::SOURCE);
        $provider = sanitize_text_field($row['primary_provider'] ?? ($row['provider'] ?? ($row['source_name'] ?? '')));
        update_post_meta($affiliate_link_id, '_alma_geo_primary_provider', $provider);
        update_post_meta($affiliate_link_id, '_alma_geo_provider', $provider);

        $after_locations = $this->count_locations();
        $after_relations = $this->count_relations($affiliate_link_id);
        $was_existing = !empty($args['overwrite']) && $before_relations > 0;
        $message = $was_existing ? 'updated_existing_geo' : 'imported';
        $message .= ' secondaries_imported=' . max(0, count($result['locations'] ?? array()) - 1);
        if (!empty($geo_data['secondary_locations_json_invalid'])) {
            $message .= ' secondary_locations_json_invalid';
        }
        $message .= ' locations_created=' . max(0, $after_locations - $before_locations);
        $message .= ' locations_reused=' . max(0, count($result['locations'] ?? array()) - max(0, $after_locations - $before_locations));
        $message .= ' relations_created=' . max(0, $after_relations - $before_relations);
        $message .= ' relations_updated=' . ($was_existing ? max(1, $after_relations) : 0);

        return $this->row_result($was_existing ? 'updated' : 'imported', $message, $affiliate_link_id);
    }

    public function import_primary_location($affiliate_link_id, $row, $args = array()) {
        $geo_data = $this->row_to_geo_data($this->normalize_row($row));
        $location_id = $this->store->upsert_location($this->location_to_store_row($geo_data['primary_location']));
        if ($location_id) {
            $this->store->upsert_content_index($affiliate_link_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, $location_id, array(
                'is_primary' => 1,
                'role' => 'main_destination',
                'geo_scope' => $geo_data['geo_scope'],
                'content_type' => 'affiliate_link',
                'commercial_intent' => $geo_data['commercial_intent'],
                'widget_eligible' => $geo_data['widget_eligible'],
                'confidence' => $geo_data['primary_location']['confidence'],
                'match_weight' => $geo_data['primary_location']['match_weight'],
                'source' => self::SOURCE,
                'raw_payload' => array('location' => $geo_data['primary_location'], 'row' => $row),
            ));
        }
        return $location_id;
    }

    public function import_secondary_locations($affiliate_link_id, $row, $args = array()) {
        $geo_data = $this->row_to_geo_data($this->normalize_row($row));
        $ids = array();
        foreach ($geo_data['secondary_locations'] as $secondary) {
            $location_id = $this->store->upsert_location($this->location_to_store_row($secondary));
            if ($location_id) {
                $ids[] = $location_id;
                $this->store->upsert_content_index($affiliate_link_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, $location_id, array(
                    'is_primary' => 0,
                    'role' => $secondary['role'],
                    'geo_scope' => $geo_data['geo_scope'],
                    'content_type' => 'affiliate_link',
                    'commercial_intent' => $geo_data['commercial_intent'],
                    'widget_eligible' => $geo_data['widget_eligible'],
                    'confidence' => $secondary['confidence'],
                    'match_weight' => $secondary['match_weight'],
                    'source' => self::SOURCE,
                    'raw_payload' => array('location' => $secondary, 'row' => $row),
                ));
            }
        }
        return $ids;
    }

    private function row_to_geo_data($row) {
        $primary = array(
            'name' => sanitize_text_field($row['primary_name'] ?? ''),
            'canonical_name' => sanitize_text_field($row['primary_canonical_name'] ?? ($row['primary_name'] ?? '')),
            'type' => $this->allowed_or_default($row['primary_type'] ?? '', ALMA_Geo_Index_Metabox::primary_types(), 'unknown'),
            'country' => sanitize_text_field($row['primary_country'] ?? ''),
            'country_code' => strtoupper(sanitize_text_field($row['primary_country_code'] ?? '')),
            'region' => sanitize_text_field($row['primary_region'] ?? ''),
            'city' => sanitize_text_field($row['primary_city'] ?? ''),
            'area' => sanitize_text_field($row['primary_area'] ?? ''),
            'poi' => sanitize_text_field($row['primary_poi'] ?? ''),
            'lat' => isset($row['primary_lat']) ? $this->float_or_empty($row['primary_lat']) : '',
            'lng' => isset($row['primary_lng']) ? $this->float_or_empty($row['primary_lng']) : '',
            'geo_provider' => sanitize_key($row['primary_provider'] ?? ($row['provider'] ?? '')),
            'geo_provider_place_id' => sanitize_text_field($row['primary_place_id'] ?? ''),
            'suggested_geocoding_query' => sanitize_text_field($row['primary_suggested_geocoding_query'] ?? ''),
            'formatted_address' => sanitize_text_field($row['primary_formatted_address'] ?? ''),
            'geocoding_status' => 'pending',
            'role' => sanitize_key($row['primary_role'] ?? 'main_destination'),
            'is_primary' => true,
            'source' => self::SOURCE,
            'confidence' => isset($row['confidence']) && $row['confidence'] !== '' ? min(1, max(0, (float) $row['confidence'])) : 1,
            'match_weight' => isset($row['match_weight']) && $row['match_weight'] !== '' ? (int) $row['match_weight'] : 100,
            'activity_type' => sanitize_key($row['activity_type'] ?? 'unknown'),
        );
        $secondary_info = $this->parse_secondary_locations($row);
        $locations = array_merge(array($primary), $secondary_info['locations']);
        $locations = $this->sort_and_limit_locations($locations);
        $primary = array_shift($locations);
        return array(
            'geo_scope' => $this->allowed_or_default($row['geo_scope'] ?? '', ALMA_Geo_Index_Metabox::geo_scopes(), 'uncertain'),
            'content_type' => 'affiliate_link',
            'commercial_intent' => $this->allowed_or_default($row['commercial_intent'] ?? '', ALMA_Geo_Index_Metabox::commercial_intents(), 'none'),
            'widget_eligible' => $this->to_bool($row['widget_eligible'] ?? false),
            'activity_type' => sanitize_key($row['activity_type'] ?? 'unknown'),
            'quality_flags' => sanitize_textarea_field($row['geo_quality_flags'] ?? ''),
            'notes' => sanitize_textarea_field($row['notes'] ?? ''),
            'primary_location' => $primary,
            'secondary_locations' => $locations,
            'secondary_locations_json_invalid' => $secondary_info['invalid'],
            'locations' => array_merge(array($primary), $locations),
            'raw_payload' => $row,
        );
    }

    private function parse_secondary_locations($row) {
        $raw = trim((string) ($row['secondary_locations_json'] ?? ''));
        if ($raw === '') {
            return array('locations' => array(), 'invalid' => false);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array('locations' => array(), 'invalid' => true);
        }
        $locations = array();
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = sanitize_text_field($item['name'] ?? ($item['canonical_name'] ?? ''));
            $canonical = sanitize_text_field($item['canonical_name'] ?? $name);
            if ($canonical === '' && $name === '') {
                continue;
            }
            $locations[] = array(
                'name' => $name,
                'canonical_name' => $canonical,
                'type' => $this->allowed_or_default($item['type'] ?? 'unknown', ALMA_Geo_Index_Metabox::primary_types(), 'unknown'),
                'country' => sanitize_text_field($item['country'] ?? ($row['primary_country'] ?? '')),
                'country_code' => strtoupper(sanitize_text_field($item['country_code'] ?? ($row['primary_country_code'] ?? ''))),
                'region' => sanitize_text_field($item['region'] ?? ''),
                'city' => sanitize_text_field($item['city'] ?? ''),
                'area' => sanitize_text_field($item['area'] ?? ''),
                'poi' => sanitize_text_field($item['poi'] ?? ''),
                'lat' => isset($item['lat']) ? $this->float_or_empty($item['lat']) : '',
                'lng' => isset($item['lng']) ? $this->float_or_empty($item['lng']) : '',
                'geo_provider' => sanitize_key($item['geo_provider'] ?? ''),
                'geo_provider_place_id' => sanitize_text_field($item['geo_provider_place_id'] ?? ($item['place_id'] ?? '')),
                'suggested_geocoding_query' => sanitize_text_field($item['suggested_geocoding_query'] ?? ($item['query'] ?? '')),
                'formatted_address' => sanitize_text_field($item['formatted_address'] ?? ''),
                'geocoding_status' => 'pending',
                'role' => sanitize_key($item['role'] ?? 'major_destination'),
                'is_primary' => false,
                'source' => self::SOURCE,
                'confidence' => isset($item['confidence']) && $item['confidence'] !== '' ? min(1, max(0, (float) $item['confidence'])) : (isset($row['confidence']) ? (float) $row['confidence'] : 0.7),
                'match_weight' => isset($item['match_weight']) && $item['match_weight'] !== '' ? (int) $item['match_weight'] : 70,
            );
        }
        return array('locations' => $locations, 'invalid' => false);
    }

    private function sort_and_limit_locations($locations) {
        $primary = array_shift($locations);
        $weights = array('main_destination' => 1000, 'major_destination' => 800, 'excursion' => 700, 'nearby_place' => 600, 'route_stop' => 500, 'mentioned_destination' => 400, 'context_only' => 100);
        usort($locations, function($a, $b) use ($weights) {
            $aw = $weights[$a['role'] ?? ''] ?? 0;
            $bw = $weights[$b['role'] ?? ''] ?? 0;
            if ($aw === $bw) {
                return (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0);
            }
            return $bw <=> $aw;
        });
        $locations = array_slice($locations, 0, self::MAX_ASSOCIATED_LOCATIONS - 1);
        return array_merge(array($primary), $locations);
    }

    private function location_to_store_row($location) {
        return array(
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
            'suggested_geocoding_query' => $location['suggested_geocoding_query'],
            'geocoding_status' => 'pending',
            'formatted_address' => $location['formatted_address'],
        );
    }

    private function has_primary_location($row) {
        return $this->primary_location_value($row) !== '';
    }

    private function primary_location_value($row) {
        foreach (self::primary_location_headers() as $header) {
            $value = trim((string) ($row[$header] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function has_existing_geo($affiliate_link_id) {
        if (get_post_meta($affiliate_link_id, '_alma_geo_enabled', true) === 'yes') {
            return true;
        }
        return (bool) $this->store->get_primary_location_for_object($affiliate_link_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
    }

    private function row_result($status, $message, $object_id) {
        return array('status' => sanitize_key($status), 'action' => sanitize_key(strtok($message, ' ')), 'message' => sanitize_textarea_field($message), 'object_id' => absint($object_id));
    }

    private function count_locations() {
        global $wpdb;
        return $this->store->tables_exist() ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->store->table_locations()}") : 0;
    }

    private function count_relations($object_id) {
        global $wpdb;
        return $this->store->tables_exist() ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->store->table_content_index()} WHERE object_id = %d AND object_type = %s", absint($object_id), ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK)) : 0;
    }

    private function detect_csv_delimiter($file_path) {
        $line = '';
        $handle = fopen($file_path, 'r');
        if ($handle) {
            $line = (string) fgets($handle);
            fclose($handle);
        }
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function is_empty_csv_row($row) {
        foreach ((array) $row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
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
        return in_array($value, array('1','true','yes','y','si','sì','on','safe','safe_import'), true);
    }

    private function float_or_empty($value) {
        return $value === '' || $value === null ? '' : (string) (float) $value;
    }
}
