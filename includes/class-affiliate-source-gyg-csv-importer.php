<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_GYG_CSV_Importer {
    const MAX_IMPORT_QUANTITY = 1000;
    const AJAX_BATCH_SIZE = 100;
    const PREVIEW_LIMIT = 10;
    const TRANSIENT_PREFIX = 'alma_gyg_csv_';

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

    public static function normalize_header($header) {
        $header = strtolower(trim((string)$header));
        $header = strtr($header, array('à'=>'a','á'=>'a','è'=>'e','é'=>'e','ì'=>'i','í'=>'i','ò'=>'o','ó'=>'o','ù'=>'u','ú'=>'u'));
        $header = preg_replace('/[^a-z0-9]+/', ' ', $header);
        return trim((string)$header);
    }

    public function detect_columns($headers) {
        $aliases = array(
            'url' => array('url'),
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
        $check = wp_check_filetype_and_ext($file['tmp_name'], $name, array('csv' => 'text/csv'));
        if (empty($check['ext']) && empty($check['type'])) {
            return new WP_Error('invalid_filetype', __('Tipo file CSV non riconosciuto.', 'affiliate-link-manager-ai'));
        }
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('upload_dir', $uploads['error']);
        }
        $dir = trailingslashit($uploads['basedir']) . 'alma-gyg-csv';
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('upload_dir_create', __('Impossibile creare la cartella temporanea CSV.', 'affiliate-link-manager-ai'));
        }
        $token = wp_generate_password(32, false, false);
        $path = trailingslashit($dir) . 'source-' . absint($source_id) . '-' . get_current_user_id() . '-' . $token . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return new WP_Error('upload_move', __('Impossibile salvare temporaneamente il CSV.', 'affiliate-link-manager-ai'));
        }
        @chmod($path, 0600);
        set_transient($this->transient_key($token), array('path'=>$path, 'source_id'=>absint($source_id), 'user_id'=>get_current_user_id(), 'name'=>$name, 'created_at'=>time()), 12 * HOUR_IN_SECONDS);
        return array('token'=>$token, 'path'=>$path, 'name'=>$name);
    }

    public function transient_key($token) {
        return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . sanitize_key($token);
    }

    public function get_session($token, $source_id) {
        $session = get_transient($this->transient_key($token));
        if (!is_array($session) || empty($session['path']) || !file_exists($session['path']) || (int)$session['source_id'] !== (int)$source_id || (int)$session['user_id'] !== get_current_user_id()) {
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
            'city' => isset($columns['city']) ? sanitize_text_field((string)($row[$columns['city']] ?? '')) : '',
            'region' => isset($columns['region']) ? sanitize_text_field((string)($row[$columns['region']] ?? '')) : '',
            'activity_type' => sanitize_text_field((string)($row[$columns['activity_type']] ?? '')),
            'description' => wp_strip_all_tags((string)($row[$columns['description']] ?? '')),
        );
    }

    public function normalize_item($item, $source) {
        $title = $item['description'];
        if (function_exists('mb_substr')) $title = mb_substr($title, 0, 120);
        else $title = substr($title, 0, 120);
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
            '_alma_gyg_csv_description' => (string)$item['description'],
            '_alma_ai_context_seed' => (string)$item['description'],
            '_alma_ai_visibility' => 'available',
        );
        return array('post_title'=>sanitize_text_field($title !== '' ? $title : __('GetYourGuide attività', 'affiliate-link-manager-ai')), 'post_content'=>wp_kses_post($item['description']), 'featured_image_url'=>'', 'affiliate_url'=>esc_url_raw($item['affiliate_url']), 'original_url'=>esc_url_raw($item['original_url']), 'meta'=>$meta, 'raw_item'=>$item);
    }

    public function import_batch($path, $columns, $activity_type, $source, $term_ids, $quantity, $cursor = 0, $update_existing = false, $batch_size = self::AJAX_BATCH_SIZE) {
        $start = microtime(true);
        $settings = self::default_settings(json_decode((string)($source['settings'] ?? '{}'), true));
        $partner_id = (string)($settings['partner_id'] ?? '');
        $utm = (string)($settings['utm_medium'] ?? 'online_publisher');
        $quantity = max(1, min(self::MAX_IMPORT_QUANTITY, absint($quantity)));
        $cursor = max(0, absint($cursor));
        $batch_size = max(1, min(self::AJAX_BATCH_SIZE, absint($batch_size)));
        $term_ids = self::normalize_mapping_term_ids($term_ids);
        $result = array('processed'=>0,'imported'=>0,'updated'=>0,'existing'=>0,'skipped'=>0,'errors'=>0,'invalid_urls'=>0,'without_city'=>0,'without_region'=>0,'duration'=>0,'logs'=>array(),'next_cursor'=>$cursor,'done'=>false);
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
            $normalized = $this->normalize_item($item, $source_for_import);
            $match = $dedupe->find_match($normalized, $update_existing ? 'create_update' : 'skip_existing');
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
        }
        $eof = feof($handle);
        fclose($handle);
        $result['done'] = $eof || $result['next_cursor'] >= $quantity || $result['processed'] < $batch_size;
        $result['duration'] = round(microtime(true) - $start, 2);
        return $result;
    }

    public function import_selected($path, $columns, $activity_type, $source, $external_ids, $term_id) {
        return $this->import_batch($path, $columns, $activity_type, $source, array($term_id), count((array)$external_ids), 0, true, self::MAX_IMPORT_QUANTITY);
    }

}
