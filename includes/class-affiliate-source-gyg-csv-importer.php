<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_GYG_CSV_Importer {
    const MAX_IMPORT_QUANTITY = 1000;
    const AJAX_BATCH_SIZE = 100;
    const PREVIEW_LIMIT = 10;
    const TRANSIENT_PREFIX = 'alma_gyg_csv_';


    public static function activity_type_hash($activity_type) {
        return hash('sha256', (string)$activity_type);
    }

    private function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'alma_gyg_csv_import_sessions';
    }

    private function progress_table() {
        global $wpdb;
        return $wpdb->prefix . 'alma_gyg_csv_import_progress';
    }

    private function persistent_upload_dir() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('upload_dir', $uploads['error']);
        }
        $dir = trailingslashit($uploads['basedir']) . 'alma-imports/gyg-csv';
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('upload_dir_create', __('Impossibile creare la cartella persistente CSV.', 'affiliate-link-manager-ai'));
        }
        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n");
        }
        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
        return $dir;
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function get_recent_sessions($source_id, $limit = 10) {
        global $wpdb;
        $table = $this->sessions_table();
        if (!$this->table_exists($table)) return array();
        $limit = max(1, min(50, absint($limit)));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE source_id=%d ORDER BY updated_at DESC LIMIT %d", absint($source_id), $limit), ARRAY_A);
    }

    public function get_progress_for_session($session_id) {
        global $wpdb;
        $table = $this->progress_table();
        if (!$this->table_exists($table)) return array();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE session_id=%d", absint($session_id)), ARRAY_A);
        $out = array();
        foreach ((array)$rows as $row) {
            $out[(string)$row['activity_type_hash']] = $row;
        }
        return $out;
    }

    public function get_progress($session_id, $source_id, $activity_type) {
        global $wpdb;
        $table = $this->progress_table();
        if (!$this->table_exists($table)) return array();
        $hash = self::activity_type_hash($activity_type);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_id=%d AND source_id=%d AND activity_type_hash=%s", absint($session_id), absint($source_id), $hash), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public function upsert_progress($session_id, $source_id, $activity_type, $term_ids, $result) {
        global $wpdb;
        $table = $this->progress_table();
        if (!$this->table_exists($table)) return false;
        $session_id = absint($session_id);
        $source_id = absint($source_id);
        $activity_type = sanitize_text_field((string)$activity_type);
        $hash = self::activity_type_hash($activity_type);
        $existing = $this->get_progress($session_id, $source_id, $activity_type);
        $now = current_time('mysql');
        $data = array(
            'session_id' => $session_id,
            'source_id' => $source_id,
            'activity_type' => $activity_type,
            'activity_type_hash' => $hash,
            'mapped_term_ids_json' => wp_json_encode(self::normalize_mapping_term_ids($term_ids)),
            'imported_count' => absint($existing['imported_count'] ?? 0) + absint($result['imported'] ?? 0),
            'updated_count' => absint($existing['updated_count'] ?? 0) + absint($result['updated'] ?? 0),
            'existing_count' => absint($existing['existing_count'] ?? 0) + absint($result['existing'] ?? 0),
            'skipped_count' => absint($existing['skipped_count'] ?? 0) + absint($result['skipped'] ?? 0),
            'error_count' => absint($existing['error_count'] ?? 0) + absint($result['errors'] ?? 0),
            'last_cursor' => absint($result['next_cursor'] ?? ($existing['last_cursor'] ?? 0)),
            'last_report_json' => wp_json_encode(is_array($result) ? $result : array()),
            'updated_at' => $now,
        );
        $session_table = $this->sessions_table();
        if ($this->table_exists($session_table) && $session_id > 0) {
            $wpdb->update($session_table, array('status'=>!empty($result['done'])?'ready':'importing','updated_at'=>$now,'last_error'=>''), array('id'=>$session_id));
        }
        if (!empty($existing['id'])) {
            return $wpdb->update($table, $data, array('id'=>absint($existing['id'])));
        }
        return $wpdb->insert($table, $data);
    }

    public function delete_session($token, $source_id) {
        global $wpdb;
        $session = $this->get_session($token, $source_id);
        if (is_wp_error($session)) return $session;
        $session_table = $this->sessions_table();
        $progress_table = $this->progress_table();
        if ($this->table_exists($progress_table)) {
            $wpdb->delete($progress_table, array('session_id'=>absint($session['id']), 'source_id'=>absint($source_id)));
        }
        if (!empty($session['path']) && file_exists($session['path'])) {
            @unlink($session['path']);
        }
        if ($this->table_exists($session_table)) {
            $wpdb->delete($session_table, array('id'=>absint($session['id']), 'source_id'=>absint($source_id)));
        }
        delete_transient($this->transient_key($token));
        return true;
    }

    public static function build_affiliate_url($original_url, $partner_id, $utm_medium = 'online_publisher') {
        $url = esc_url_raw(trim((string)$original_url));
        if ($url === '' || !wp_http_validate_url($url)) {
            return '';
        }
        $partner_id = sanitize_text_field((string)$partner_id);
        $utm_medium = sanitize_text_field((string)($utm_medium !== '' ? $utm_medium : 'online_publisher'));
        if ($partner_id === '') {
            return $url;
        }

        $fragment = '';
        $hash_pos = strpos($url, '#');
        if ($hash_pos !== false) {
            $fragment = substr($url, $hash_pos);
            $url = substr($url, 0, $hash_pos);
        }

        $query = (string)(wp_parse_url($url, PHP_URL_QUERY) ?: '');
        $params = array();
        if ($query !== '') {
            wp_parse_str($query, $params);
        }
        $append = array();
        if (!array_key_exists('partner_id', $params)) {
            $append[] = 'partner_id=' . rawurlencode($partner_id);
        }
        if (!array_key_exists('utm_medium', $params)) {
            $append[] = 'utm_medium=' . rawurlencode($utm_medium);
        }
        if (!empty($append)) {
            $sep = (strpos($url, '?') === false) ? '?' : ((substr($url, -1) === '?' || substr($url, -1) === '&') ? '' : '&');
            $url .= $sep . implode('&', $append);
        }
        return esc_url_raw($url . $fragment);
    }

    public static function external_id_from_url($url) {
        $url = trim((string)$url);
        $url = preg_replace('/\s+/', '', $url);
        return hash('sha256', strtolower($url));
    }

    public static function default_settings($settings) {
        $settings = is_array($settings) ? $settings : array();
        if (empty($settings['utm_medium'])) $settings['utm_medium'] = 'online_publisher';
        // Backward compatibility: keep any saved batch_size in storage, but the gyg_csv UI and importer ignore it.
        if (empty($settings['type_mappings']) || !is_array($settings['type_mappings'])) {
            $settings['type_mappings'] = array();
        }
        foreach ($settings['type_mappings'] as $type => $term_ids) {
            $settings['type_mappings'][$type] = self::normalize_mapping_term_ids($term_ids);
        }
        return $settings;
    }


    public static function normalize_mapping_term_ids($value) {
        $ids = array();
        $collect = function($item) use (&$collect, &$ids) {
            if ($item === null || $item === false || $item === '') {
                return;
            }
            if (is_array($item)) {
                foreach ($item as $child) {
                    $collect($child);
                }
                return;
            }
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $collect($decoded);
                        return;
                    }
                }
                if (strpos($item, ',') !== false) {
                    foreach (explode(',', $item) as $part) {
                        $collect($part);
                    }
                    return;
                }
            }
            if (is_numeric($item)) {
                $id = (int)$item;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        };
        $collect($value);
        return array_values(array_unique($ids));
    }

    public static function normalize_header($header) {
        $header = strtolower(trim((string)$header));
        $header = strtr($header, array('à'=>'a','á'=>'a','è'=>'e','é'=>'e','ì'=>'i','í'=>'i','ò'=>'o','ó'=>'o','ù'=>'u','ú'=>'u'));
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header);
        return trim((string)$header);
    }

    public function detect_columns($headers) {
        $aliases = array(
            'url' => array('url'),
            'title' => array('titolo attivita', 'titolo attività', 'titolo_attivita', 'titolo', 'title', 'activity_title', 'activity title'),
            'city' => array('citta', 'city'),
            'region' => array('regione di appartenenza', 'regione', 'region'),
            'activity_type' => array('tipologia attivita', 'tipologia attività', 'activity type'),
            'description' => array('descrizione attivita', 'descrizione attività', 'descrizione', 'description'),
        );
        $detected = array();
        foreach ((array)$headers as $i => $header) {
            $normalized = self::normalize_header($header);
            foreach ($aliases as $key => $names) {
                if (!isset($detected[$key]) && in_array($normalized, array_map(array(__CLASS__, 'normalize_header'), $names), true)) {
                    $detected[$key] = (int)$i;
                }
            }
        }
        $missing = array();
        foreach (array('url'=>'URL', 'activity_type'=>'Tipologia attività', 'description'=>'Descrizione attività') as $key => $label) {
            if (!isset($detected[$key])) $missing[] = $label;
        }
        return array('headers'=>array_values((array)$headers), 'columns'=>$detected, 'missing'=>$missing, 'valid'=>empty($missing));
    }

    public function handle_upload($file, $source_id) {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('missing_file', __('Seleziona un file CSV valido.', 'affiliate-link-manager-ai'));
        }
        $name = sanitize_file_name((string)($file['name'] ?? 'getyourguide.csv'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return new WP_Error('invalid_extension', __('Il file deve avere estensione .csv.', 'affiliate-link-manager-ai'));
        }
        $allowed_mimes = array('csv' => 'text/csv');
        $check = wp_check_filetype_and_ext($file['tmp_name'], $name, $allowed_mimes);
        if (empty($check['ext']) && empty($check['type'])) {
            $mime = function_exists('mime_content_type') ? (string)@mime_content_type($file['tmp_name']) : '';
            if ($mime !== '' && !in_array($mime, array('text/plain','text/csv','application/csv','application/vnd.ms-excel'), true)) {
                return new WP_Error('invalid_filetype', __('Tipo file CSV non riconosciuto.', 'affiliate-link-manager-ai'));
            }
        }
        $dir = $this->persistent_upload_dir();
        if (is_wp_error($dir)) return $dir;
        $token = strtolower(wp_generate_password(48, false, false));
        $stored = 'source-' . absint($source_id) . '-' . wp_generate_password(20, false, false) . '.csv';
        $path = trailingslashit($dir) . $stored;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return new WP_Error('upload_move', __('Impossibile salvare il CSV in modo persistente.', 'affiliate-link-manager-ai'));
        }
        @chmod($path, 0640);

        $headers = $this->get_headers($path);
        $det = is_wp_error($headers) ? array('valid'=>false,'columns'=>array(),'headers'=>array(),'missing'=>array()) : $this->detect_columns($headers);
        $summary = !empty($det['valid']) ? $this->summarize($path, $det['columns']) : array('types'=>array(), 'total'=>0, 'invalid_urls'=>0, 'without_city'=>0, 'without_region'=>0);
        $now = current_time('mysql');
        $session = array(
            'source_id' => absint($source_id),
            'token' => $token,
            'original_filename' => $name,
            'stored_filename' => $stored,
            'file_path' => $path,
            'file_hash' => hash_file('sha256', $path),
            'total_rows' => absint($summary['total'] ?? 0),
            'columns_json' => wp_json_encode($det),
            'summary_json' => wp_json_encode($summary),
            'status' => !empty($det['valid']) ? 'ready' : 'invalid',
            'created_at' => $now,
            'updated_at' => $now,
            'last_error' => empty($det['valid']) ? sprintf(__('Colonne obbligatorie mancanti: %s', 'affiliate-link-manager-ai'), implode(', ', (array)($det['missing'] ?? array()))) : '',
        );
        global $wpdb;
        $table = $this->sessions_table();
        if ($this->table_exists($table)) {
            $wpdb->insert($table, $session);
            $session['id'] = (int)$wpdb->insert_id;
        }
        set_transient($this->transient_key($token), array('path'=>$path, 'source_id'=>absint($source_id), 'user_id'=>get_current_user_id(), 'name'=>$name, 'created_at'=>time()), 12 * HOUR_IN_SECONDS);
        return array('token'=>$token, 'path'=>$path, 'name'=>$name, 'session_id'=>absint($session['id'] ?? 0));
    }

    public function transient_key($token) {
        return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . sanitize_key($token);
    }

    public function get_session($token, $source_id) {
        global $wpdb;
        $token = sanitize_key($token);
        $source_id = absint($source_id);
        if ($token === '' || $source_id <= 0) {
            return new WP_Error('csv_session_expired', __('Sessione CSV non valida.', 'affiliate-link-manager-ai'));
        }
        $table = $this->sessions_table();
        if ($this->table_exists($table)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token=%s AND source_id=%d", $token, $source_id), ARRAY_A);
            if (is_array($row) && !empty($row['file_path']) && file_exists($row['file_path'])) {
                return array(
                    'id' => absint($row['id']),
                    'path' => (string)$row['file_path'],
                    'source_id' => absint($row['source_id']),
                    'name' => (string)$row['original_filename'],
                    'token' => (string)$row['token'],
                    'stored_filename' => (string)$row['stored_filename'],
                    'file_hash' => (string)$row['file_hash'],
                    'total_rows' => absint($row['total_rows']),
                    'columns' => json_decode((string)($row['columns_json'] ?? ''), true),
                    'summary' => json_decode((string)($row['summary_json'] ?? ''), true),
                    'status' => (string)$row['status'],
                    'created_at' => (string)$row['created_at'],
                    'updated_at' => (string)$row['updated_at'],
                );
            }
            if (is_array($row)) {
                $wpdb->update($table, array('status'=>'missing_file','last_error'=>__('File CSV persistente non trovato.', 'affiliate-link-manager-ai'),'updated_at'=>current_time('mysql')), array('id'=>absint($row['id'])));
                return new WP_Error('csv_file_missing', __('File CSV persistente non trovato. Elimina la sessione e ricarica il CSV.', 'affiliate-link-manager-ai'));
            }
        }
        $session = get_transient($this->transient_key($token));
        if (!is_array($session) || empty($session['path']) || !file_exists($session['path']) || (int)$session['source_id'] !== $source_id || (int)$session['user_id'] !== get_current_user_id()) {
            return new WP_Error('csv_session_expired', __('Sessione CSV scaduta o non valida. Carica nuovamente il file.', 'affiliate-link-manager-ai'));
        }
        return $session;
    }

    public function get_headers($path) {
        $handle = fopen($path, 'r');
        if (!$handle) return new WP_Error('csv_open', __('Impossibile leggere il CSV.', 'affiliate-link-manager-ai'));
        $headers = fgetcsv($handle, 0, ',');
        if (is_array($headers) && count($headers) === 1 && strpos((string)$headers[0], ';') !== false) {
            rewind($handle);
            $headers = fgetcsv($handle, 0, ';');
        }
        fclose($handle);
        if (!is_array($headers) || empty($headers)) return new WP_Error('csv_empty', __('CSV vuoto o intestazioni mancanti.', 'affiliate-link-manager-ai'));
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
        return $headers;
    }

    private function delimiter($path) {
        $handle = fopen($path, 'r');
        if (!$handle) return ',';
        $line = (string)fgets($handle);
        fclose($handle);
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }


    public function get_column_example($path, $column_index) {
        $column_index = absint($column_index);
        $handle = fopen((string)$path, 'r');
        if (!$handle) return '';
        $delimiter = $this->delimiter($path);
        fgetcsv($handle, 0, $delimiter);
        $example = '';
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $value = trim((string)($row[$column_index] ?? ''));
            if ($value !== '') {
                $example = sanitize_text_field(wp_strip_all_tags($value));
                break;
            }
        }
        fclose($handle);
        return $example;
    }

    public function summarize($path, $columns) {
        $summary = array('types'=>array(), 'total'=>0, 'invalid_urls'=>0, 'without_city'=>0, 'without_region'=>0);
        $handle = fopen($path, 'r'); if (!$handle) return $summary;
        $delimiter = $this->delimiter($path); fgetcsv($handle, 0, $delimiter);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $summary['total']++;
            $type = sanitize_text_field((string)($row[$columns['activity_type']] ?? ''));
            if ($type === '') $type = __('(vuota)', 'affiliate-link-manager-ai');
            if (!isset($summary['types'][$type])) $summary['types'][$type] = 0;
            $summary['types'][$type]++;
            $url = esc_url_raw((string)($row[$columns['url']] ?? ''));
            if ($url === '' || !wp_http_validate_url($url)) $summary['invalid_urls']++;
            if (!isset($columns['city']) || trim((string)($row[$columns['city']] ?? '')) === '') $summary['without_city']++;
            if (!isset($columns['region']) || trim((string)($row[$columns['region']] ?? '')) === '') $summary['without_region']++;
        }
        fclose($handle);
        arsort($summary['types']);
        return $summary;
    }

    public function preview($path, $columns, $activity_type, $source, $filters = array()) {
        $settings = self::default_settings(json_decode((string)($source['settings'] ?? '{}'), true));
        $partner_id = (string)($settings['partner_id'] ?? '');
        $utm = (string)($settings['utm_medium'] ?? 'online_publisher');
        $limit = max(1, min(self::PREVIEW_LIMIT, (int)($filters['limit'] ?? self::PREVIEW_LIMIT)));
        $items = array();
        $handle = fopen($path, 'r'); if (!$handle) return $items;
        $delimiter = $this->delimiter($path); fgetcsv($handle, 0, $delimiter);
        $dedupe = new ALMA_Affiliate_Source_Import_Dedupe_Service();
        $city_filter = strtolower(sanitize_text_field((string)($filters['city'] ?? '')));
        $region_filter = strtolower(sanitize_text_field((string)($filters['region'] ?? '')));
        $search = strtolower(sanitize_text_field((string)($filters['search'] ?? '')));
        $show_existing = !empty($filters['show_existing']);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $item = $this->row_to_item($row, $columns, $source, $partner_id, $utm);
            if ($activity_type !== '' && $item['activity_type'] !== $activity_type) continue;
            if ($city_filter !== '' && strpos(strtolower($item['city']), $city_filter) === false) continue;
            if ($region_filter !== '' && strpos(strtolower($item['region']), $region_filter) === false) continue;
            if ($search !== '' && strpos(strtolower(implode(' ', array($item['original_url'],$item['description'],$item['city'],$item['region']))), $search) === false) continue;
            $normalized = $this->normalize_item($item, $source);
            $match = $dedupe->find_match($normalized, 'create_update');
            $item['post_id'] = (int)($match['post_id'] ?? 0);
            $item['status'] = $item['post_id'] > 0 ? 'aggiornabile' : 'nuovo';
            if (!$show_existing && $item['post_id'] > 0) continue;
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        fclose($handle);
        return $items;
    }

    public function row_to_item($row, $columns, $source, $partner_id, $utm) {
        $url = esc_url_raw((string)($row[$columns['url']] ?? ''));
        return array(
            'external_id' => self::external_id_from_url($url),
            'original_url' => $url,
            'affiliate_url' => self::build_affiliate_url($url, $partner_id, $utm),
            'title' => isset($columns['title']) ? sanitize_text_field((string)($row[$columns['title']] ?? '')) : '',
            'city' => isset($columns['city']) ? sanitize_text_field((string)($row[$columns['city']] ?? '')) : '',
            'region' => isset($columns['region']) ? sanitize_text_field((string)($row[$columns['region']] ?? '')) : '',
            'activity_type' => sanitize_text_field((string)($row[$columns['activity_type']] ?? '')),
            'description' => wp_strip_all_tags((string)($row[$columns['description']] ?? '')),
        );
    }

    private function choose_item_title($item) {
        $title = sanitize_text_field((string)($item['title'] ?? ''));
        if ($title !== '') return $title;
        $provider_title = sanitize_text_field((string)($item['provider_title'] ?? ''));
        if ($provider_title !== '') return $provider_title;
        $description = sanitize_text_field(wp_strip_all_tags((string)($item['description'] ?? '')));
        if ($description !== '') return function_exists('mb_substr') ? mb_substr($description, 0, 120) : substr($description, 0, 120);
        $external_id = sanitize_text_field((string)($item['external_id'] ?? ''));
        if ($external_id !== '') return $external_id;
        $url = esc_url_raw((string)($item['original_url'] ?? ''));
        return $url !== '' ? $url : __('GetYourGuide attività', 'affiliate-link-manager-ai');
    }

    private function build_local_ai_context($item, $term_names = array()) {
        $parts = array();
        $map = array(
            'title' => __('Titolo attività', 'affiliate-link-manager-ai'),
            'description' => __('Descrizione attività', 'affiliate-link-manager-ai'),
            'city' => __('Città', 'affiliate-link-manager-ai'),
            'region' => __('Regione di appartenenza', 'affiliate-link-manager-ai'),
            'activity_type' => __('Tipologia attività', 'affiliate-link-manager-ai'),
        );
        foreach ($map as $key => $label) {
            $value = sanitize_text_field(wp_strip_all_tags((string)($item[$key] ?? '')));
            if ($value !== '') $parts[] = $label . ': ' . $value;
        }
        $term_names = array_values(array_filter(array_map('sanitize_text_field', (array)$term_names)));
        if (!empty($term_names)) $parts[] = __('Tipologie Link associate', 'affiliate-link-manager-ai') . ': ' . implode(', ', array_slice($term_names, 0, 20));
        return implode("\n", $parts);
    }

    private function maybe_store_local_ai_context($post_id, $normalized, $source, $status) {
        $item = is_array($normalized['raw_item'] ?? null) ? $normalized['raw_item'] : array();
        $term_names = is_array($item['link_type_names'] ?? null) ? $item['link_type_names'] : array();
        $context = $this->build_local_ai_context($item, $term_names);
        if ($context === '') return false;
        $settings = self::default_settings(json_decode((string)($source['settings'] ?? '{}'), true));
        $policy = sanitize_key((string)($settings['ai_context_regeneration_policy'] ?? 'if_hash_changed_or_expired'));
        $existing = (string)get_post_meta($post_id, '_alma_ai_context', true);
        if ($status === 'updated' && $policy === 'manual_only' && $existing !== '') return false;
        $hash = hash('sha256', wp_json_encode(array('provider'=>'gyg_csv','external_id'=>$normalized['meta']['_alma_external_id'] ?? '', 'context'=>$context)));
        $old_hash = (string)get_post_meta($post_id, '_alma_ai_context_hash', true);
        if ($status === 'updated' && $policy === 'only_if_hash_changed' && $old_hash === $hash) return false;
        update_post_meta($post_id, '_alma_ai_context', $context);
        update_post_meta($post_id, '_alma_ai_context_updated_at', current_time('mysql'));
        update_post_meta($post_id, '_alma_ai_context_hash', $hash);
        return true;
    }

    public function normalize_item($item, $source) {
        $title = $this->choose_item_title($item);
        $meta = array(
            '_alma_provider' => 'gyg_csv',
            '_alma_provider_preset' => 'gyg_csv',
            '_alma_source_id' => (string)($source['id'] ?? ''),
            '_alma_external_id' => (string)$item['external_id'],
            '_alma_sync_hash' => wp_hash((string)$item['original_url']),
            '_alma_original_url' => (string)$item['original_url'],
            '_alma_gyg_csv_city' => (string)$item['city'],
            '_alma_destination' => (string)$item['city'],
            '_alma_gyg_csv_region' => (string)$item['region'],
            '_alma_region' => (string)$item['region'],
            '_alma_gyg_csv_activity_type' => (string)$item['activity_type'],
            '_alma_gyg_csv_title' => (string)($item['title'] ?? ''),
            '_alma_gyg_csv_description' => (string)$item['description'],
            '_alma_ai_context_seed' => trim((string)($item['title'] ?? '') . ' ' . (string)$item['description']),
            '_alma_ai_visibility' => 'available',
        );
        return array('post_title'=>sanitize_text_field($title !== '' ? $title : __('GetYourGuide attività', 'affiliate-link-manager-ai')), 'post_content'=>wp_kses_post($item['description']), 'featured_image_url'=>'', 'affiliate_url'=>esc_url_raw($item['affiliate_url']), 'original_url'=>esc_url_raw($item['original_url']), 'meta'=>$meta, 'raw_item'=>$item);
    }


    private function is_empty_csv_row($row) {
        if (!is_array($row)) {
            return true;
        }
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    public function count_existing_for_type($path, $columns, $activity_type, $source) {
        $counts = array(
            'total' => 0,
            'existing' => 0,
            'remaining' => 0,
            'new' => 0,
            'invalid' => 0,
            'invalid_urls' => 0,
            'missing_description' => 0,
            'error_code' => '',
            'message' => '',
        );

        $source_id = absint($source['id'] ?? 0);
        if ($source_id <= 0) {
            $counts['error_code'] = 'invalid_source';
            $counts['message'] = __('Source gyg_csv non valida.', 'affiliate-link-manager-ai');
            return $counts;
        }

        $columns = is_array($columns) ? $columns : array();
        foreach (array('url', 'activity_type', 'description') as $required_column) {
            if (!isset($columns[$required_column])) {
                $counts['error_code'] = 'missing_columns';
                $counts['message'] = __('Colonne obbligatorie mancanti nel CSV.', 'affiliate-link-manager-ai');
                return $counts;
            }
        }

        $path = (string)$path;
        if ($path === '' || !is_readable($path)) {
            $counts['error_code'] = 'csv_open';
            $counts['message'] = __('Impossibile leggere il CSV.', 'affiliate-link-manager-ai');
            return $counts;
        }

        $settings = self::default_settings(json_decode((string)($source['settings'] ?? '{}'), true));
        $partner_id = (string)($settings['partner_id'] ?? '');
        $utm = (string)($settings['utm_medium'] ?? 'online_publisher');
        $source_for_count = $source;
        $source_for_count['provider_preset'] = 'gyg_csv';
        $source_for_count['provider'] = 'gyg_csv';
        $dedupe = new ALMA_Affiliate_Source_Import_Dedupe_Service();

        $handle = fopen($path, 'r');
        if (!$handle) {
            $counts['error_code'] = 'csv_open';
            $counts['message'] = __('Impossibile leggere il CSV.', 'affiliate-link-manager-ai');
            return $counts;
        }

        $activity_type = sanitize_text_field((string)$activity_type);
        $delimiter = $this->delimiter($path);
        fgetcsv($handle, 0, $delimiter);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->is_empty_csv_row($row)) {
                continue;
            }
            $item = $this->row_to_item($row, $columns, $source_for_count, $partner_id, $utm);
            if ($activity_type !== '' && $item['activity_type'] !== $activity_type) {
                continue;
            }

            $counts['total']++;
            if ($item['original_url'] === '' || !wp_http_validate_url($item['original_url']) || $item['affiliate_url'] === '') {
                $counts['invalid']++;
                $counts['invalid_urls']++;
                continue;
            }
            if ($item['description'] === '') {
                $counts['invalid']++;
                $counts['missing_description']++;
                continue;
            }

            $normalized = $this->normalize_item($item, $source_for_count);
            $match = $dedupe->find_match($normalized, 'skip_existing');
            if (!empty($match['post_id'])) {
                $counts['existing']++;
            } else {
                $counts['remaining']++;
                $counts['new']++;
            }
        }
        fclose($handle);

        return $counts;
    }

    public function import_batch($path, $columns, $activity_type, $source, $term_ids, $quantity, $cursor = 0, $update_existing = false, $batch_size = self::AJAX_BATCH_SIZE) {
        $start = microtime(true);
        $settings = self::default_settings(json_decode((string)($source['settings'] ?? '{}'), true));
        $partner_id = (string)($settings['partner_id'] ?? '');
        $utm = (string)($settings['utm_medium'] ?? 'online_publisher');
        $cursor = max(0, absint($cursor));
        $quantity = max($cursor + 1, min($cursor + self::MAX_IMPORT_QUANTITY, absint($quantity)));
        $batch_size = max(1, min(self::AJAX_BATCH_SIZE, absint($batch_size)));
        $term_ids = self::normalize_mapping_term_ids($term_ids);
        $result = array('processed'=>0,'imported'=>0,'updated'=>0,'existing'=>0,'skipped'=>0,'errors'=>0,'invalid_urls'=>0,'without_city'=>0,'without_region'=>0,'titles_populated'=>0,'titles_missing'=>0,'ai_contexts_populated'=>0,'ai_contexts_missing'=>0,'link_types_associated'=>0,'duration'=>0,'logs'=>array(),'next_cursor'=>$cursor,'done'=>false);
        if (empty($term_ids)) {
            return new WP_Error('missing_terms', __('Seleziona almeno una Tipologia Link Sothra.', 'affiliate-link-manager-ai'));
        }
        $source_for_import = $source;
        $source_for_import['provider_preset'] = 'gyg_csv';
        $source_for_import['provider'] = 'gyg_csv';
        $source_for_import['settings'] = wp_json_encode(array_merge($settings, array('duplicate_policy'=>$update_existing ? 'create_update' : 'skip_existing')));
        $source_for_import['destination_term_id'] = (int)($term_ids[0] ?? 0);
        $source_for_import['destination_term_ids'] = wp_json_encode($term_ids);
        $source_for_import['import_mode'] = 'create_update';
        $term_names = array();
        foreach ($term_ids as $tid) {
            $term = get_term($tid, 'link_type');
            if ($term && !is_wp_error($term)) $term_names[] = $term->name;
        }
        $importer = new ALMA_Affiliate_Source_Importer();
        $dedupe = new ALMA_Affiliate_Source_Import_Dedupe_Service();
        $handle = fopen($path, 'r');
        if (!$handle) return new WP_Error('csv_open', __('Impossibile leggere il CSV.', 'affiliate-link-manager-ai'));
        $delimiter = $this->delimiter($path);
        fgetcsv($handle, 0, $delimiter);
        $matched_index = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $item = $this->row_to_item($row, $columns, $source, $partner_id, $utm);
            if ($activity_type !== '' && $item['activity_type'] !== $activity_type) continue;
            if ($matched_index++ < $cursor) continue;
            if ($result['processed'] >= $batch_size || ($cursor + $result['processed']) >= $quantity) break;
            $result['processed']++;
            $result['next_cursor'] = $cursor + $result['processed'];
            $label = $item['description'] !== '' ? $item['description'] : $item['original_url'];
            if ($item['original_url'] === '' || !wp_http_validate_url($item['original_url']) || $item['affiliate_url'] === '') {
                $result['invalid_urls']++; $result['errors']++;
                $result['logs'][] = sprintf(__('URL non valido: %s', 'affiliate-link-manager-ai'), $label !== '' ? $label : __('record senza descrizione', 'affiliate-link-manager-ai'));
                continue;
            }
            if ($item['description'] === '') {
                $result['skipped']++; $result['errors']++;
                $result['logs'][] = sprintf(__('Descrizione mancante: %s', 'affiliate-link-manager-ai'), $item['original_url']);
                continue;
            }
            if ($item['city'] === '') $result['without_city']++;
            if ($item['region'] === '') $result['without_region']++;
            if ($item['title'] !== '') $result['titles_populated']++; else $result['titles_missing']++;
            $item['link_type_names'] = $term_names;
            $normalized = $this->normalize_item($item, $source_for_import);
            $match = $dedupe->find_match($normalized, $update_existing ? 'create_update' : 'skip_existing');
            if ($update_existing && !empty($match['post_id']) && $item['title'] === '') {
                $existing_title = get_the_title((int)$match['post_id']);
                if ($existing_title !== '') $normalized['post_title'] = sanitize_text_field($existing_title);
            }
            if (!$update_existing && !empty($match['post_id'])) {
                $result['existing']++;
                continue;
            }
            $res = $importer->import_item($normalized, $source_for_import, array('build_ai_context'=>false, 'dry_run_featured_image'=>true));
            if (is_wp_error($res)) {
                $result['errors']++;
                $result['logs'][] = sprintf(__('Errore creazione/aggiornamento Link affiliato: %s', 'affiliate-link-manager-ai'), $res->get_error_message());
                continue;
            }
            if (($res['status'] ?? '') === 'updated') $result['updated']++;
            elseif (!empty($match['post_id'])) $result['existing']++;
            else $result['imported']++;
            $post_id = (int)($res['post_id'] ?? 0);
            if ($post_id > 0 && $this->maybe_store_local_ai_context($post_id, $normalized, $source_for_import, (string)($res['status'] ?? ''))) $result['ai_contexts_populated']++;
            else $result['ai_contexts_missing']++;
            if (!empty($term_ids)) $result['link_types_associated']++;
        }
        $eof = feof($handle);
        fclose($handle);
        $result['done'] = $eof || $result['next_cursor'] >= $quantity || $result['processed'] < $batch_size;
        $result['duration'] = round(microtime(true) - $start, 2);
        $result['logs'][] = sprintf(__('Diagnostica: Titolo Attività riconosciuto=%s, campo interno=post_title, contesti AI generati=%d, record con titolo vuoto=%d.', 'affiliate-link-manager-ai'), isset($columns['title']) ? __('sì', 'affiliate-link-manager-ai') : __('no', 'affiliate-link-manager-ai'), absint($result['ai_contexts_populated']), absint($result['titles_missing']));
        if (defined('WP_DEBUG') && WP_DEBUG) error_log(sprintf('[ALMA gyg_csv] title_column=%s internal=post_title ai_contexts=%d empty_titles=%d', isset($columns['title']) ? 'yes' : 'no', absint($result['ai_contexts_populated']), absint($result['titles_missing'])));
        return $result;
    }

    public function import_selected($path, $columns, $activity_type, $source, $external_ids, $term_id) {
        return $this->import_batch($path, $columns, $activity_type, $source, array($term_id), count((array)$external_ids), 0, true, self::MAX_IMPORT_QUANTITY);
    }

}
