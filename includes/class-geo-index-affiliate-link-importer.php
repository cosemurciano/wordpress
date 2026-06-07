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
            'primary_name',
            'primary_type',
            'primary_country',
            'primary_country_code',
            'primary_suggested_geocoding_query',
            'safe_for_auto_import',
            'safe_for_auto_geocoding',
            'final_bucket',
        );
    }

    public static function recommended_headers() {
        return array('activity_type','geo_scope','commercial_intent','widget_eligible','secondary_locations_json','confidence','match_weight','review_reason_code','geo_quality_flags');
    }

    public static function allowed_csv_mimes() {
        return ALMA_Geo_Index_Importer::allowed_csv_mimes();
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
        $headers = array_map('sanitize_key', (array) $headers);
        $headers = array_values(array_filter($headers));
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
                $rows[] = $this->normalize_row(array_combine($headers, array_slice(array_pad($data, count($headers), ''), 0, count($headers))));
            }
        }
        fclose($handle);
        return array('headers' => $headers, 'rows' => $rows, 'total' => $total, 'error' => '', 'delimiter' => $delimiter);
    }

    public function normalize_row($row) {
        $normalized = array();
        foreach ((array) $row as $key => $value) {
            $key = sanitize_key($key);
            if (is_string($value)) {
                $normalized[$key] = trim($value);
            } else {
                $normalized[$key] = $value;
            }
        }
        $normalized['affiliate_link_id'] = absint($normalized['affiliate_link_id'] ?? 0);
        $normalized['primary_type'] = sanitize_key($normalized['primary_type'] ?? 'unknown');
        $normalized['primary_country_code'] = strtoupper(sanitize_text_field($normalized['primary_country_code'] ?? ''));
        $normalized['final_bucket'] = sanitize_key($normalized['final_bucket'] ?? '');
        return $normalized;
    }

    public function is_safe_row($row) {
        return $this->to_bool($row['safe_for_auto_import'] ?? false) && sanitize_key($row['final_bucket'] ?? '') === 'safe_import';
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
        $job_id = $job_store->create_job(ALMA_Geo_Index_Job_Store::JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT, $args['file_name'], array(
            'overwrite' => !empty($args['overwrite']),
            'safe_only' => !empty($args['safe_only']),
            'batch_size' => max(1, min(100, absint($args['batch_size']))),
        ), get_current_user_id());
        $parsed = $this->parse_csv_file($file_path, 0, $args['delimiter']);
        $row_number = 1;
        foreach ($parsed['rows'] as $row) {
            $row_number++;
            $job_store->add_job_item($job_id, $row_number, $row, absint($row['affiliate_link_id'] ?? 0), ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
        }
        $job_store->set_total_records($job_id, (int) $parsed['total']);
        return $job_id;
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
        if (in_array($job['status'], array('paused','cancelled','completed','failed'), true)) {
            $counts = $job_store->get_item_status_counts($job_id);
            return array(
                'processed' => 0,
                'claimed' => 0,
                'job' => $job_store->get_job($job_id),
                'items' => $job_store->get_items($job_id, 50),
                'counts' => $counts,
                'debug' => array('status_guard' => sanitize_key($job['status'])),
                'diagnostic' => $job_store->get_job_diagnostic($job_id, array('last_ajax_message' => 'status_guard')),
                'message' => __('Job in stato terminale: nessun batch processato.', 'affiliate-link-manager-ai'),
            );
        }
        $recovered = $job_store->recover_stale_processing_items($job_id, 10);
        if ($job['status'] === 'queued') {
            $job_store->update_job_status($job_id, 'running');
        }
        $options = is_array($job['options']) ? $job['options'] : array();
        $batch_size = max(1, min(100, absint($batch_size ?: ($options['batch_size'] ?? 50))));
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
        $message = $claimed === 0 && !empty($counts['queued'])
            ? __('Nessun item claimato nonostante esistano item in coda: controlla diagnostica claim.', 'affiliate-link-manager-ai')
            : sprintf(__('Batch processato: %1$d item claimati, %2$d processati.', 'affiliate-link-manager-ai'), $claimed, $processed);
        return array(
            'processed' => $processed,
            'claimed' => $claimed,
            'job' => $job,
            'counts' => $counts,
            'items' => $job_store->get_items($job_id, 50),
            'debug' => $debug,
            'diagnostic' => $job_store->get_job_diagnostic($job_id, array('last_ajax_message' => 'batch_processed')),
            'message' => $message,
        );
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
            return $this->row_result('skipped', 'safe_import_skipped', $affiliate_link_id);
        }
        if ($this->has_existing_geo($affiliate_link_id) && empty($args['overwrite'])) {
            return $this->row_result('skipped', 'skipped_existing_geo existing_geo_skipped', $affiliate_link_id);
        }

        $before_locations = $this->count_locations();
        $before_relations = $this->count_relations($affiliate_link_id);
        $geo_data = $this->row_to_geo_data($row);
        if (empty($geo_data['primary_location']['canonical_name']) && empty($geo_data['primary_location']['name'])) {
            return $this->row_result('error', 'missing_primary_location', $affiliate_link_id);
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
        return in_array($value, array('1','true','yes','y','si','sì','on'), true);
    }

    private function float_or_empty($value) {
        return $value === '' || $value === null ? '' : (string) (float) $value;
    }
}
