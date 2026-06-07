<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistent manual import session store for Geo Index imports.
 */
class ALMA_Geo_Index_Job_Store {
    const JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT = 'affiliate_links_geo_import';

    private $last_claim_debug = array();

    public function table_jobs() {
        global $wpdb;
        return $wpdb->prefix . 'alma_geo_import_jobs';
    }

    public function table_items() {
        global $wpdb;
        return $wpdb->prefix . 'alma_geo_import_job_items';
    }

    public static function create_tables() {
        $store = new self();
        return $store->install_tables();
    }

    public function install_tables() {
        return $this->repair_tables();
    }

    public function repair_tables() {
        global $wpdb;
        $before = $this->schema_status();
        $charset_collate = $wpdb->get_charset_collate();
        $jobs = $this->table_jobs();
        $items = $this->table_items();

        $sql_jobs = "CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(80) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'queued',
            file_name varchar(255) DEFAULT '',
            total_records int(11) NOT NULL DEFAULT 0,
            processed_records int(11) NOT NULL DEFAULT 0,
            imported_records int(11) NOT NULL DEFAULT 0,
            updated_records int(11) NOT NULL DEFAULT 0,
            skipped_records int(11) NOT NULL DEFAULT 0,
            error_records int(11) NOT NULL DEFAULT 0,
            options longtext NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            last_error text NULL,
            PRIMARY KEY  (id),
            KEY job_type (job_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_items = "CREATE TABLE $items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            object_id bigint(20) unsigned NULL,
            object_type varchar(50) DEFAULT '',
            row_number int(11) NOT NULL,
            status varchar(40) NOT NULL DEFAULT 'queued',
            action varchar(40) DEFAULT '',
            message text NULL,
            raw_payload longtext NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            processed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY status (status),
            KEY job_status (job_id, status),
            KEY object_lookup (object_id, object_type),
            KEY row_lookup (job_id, row_number),
            KEY created_at (created_at)
        ) $charset_collate;";

        $wpdb->last_error = '';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $dbdelta_jobs = dbDelta($sql_jobs);
        $dbdelta_items = dbDelta($sql_items);
        $last_error = (string) $wpdb->last_error;
        $after = $this->schema_status();
        $variant_tables = $this->find_variant_item_tables();
        $success = !empty($after['jobs_exists']) && !empty($after['items_exists']);
        $message = $success
            ? __('Schema riparato correttamente.', 'affiliate-link-manager-ai')
            : $this->repair_failure_message($before, $after, $dbdelta_items, $last_error, $variant_tables);

        if ($success) {
            update_option('alma_geo_import_schema_version', '3', false);
        }

        return array_merge($after, array(
            'success' => $success,
            'message' => $message,
            'requested_tables' => array('jobs' => $jobs, 'items' => $items),
            'before' => $before,
            'after' => $after,
            'dbdelta' => array(
                'jobs' => is_array($dbdelta_jobs) ? array_map('sanitize_text_field', $dbdelta_jobs) : array(),
                'items' => is_array($dbdelta_items) ? array_map('sanitize_text_field', $dbdelta_items) : array(),
            ),
            'last_error' => sanitize_textarea_field($last_error),
            'mysql_error' => sanitize_textarea_field($this->mysql_error_message()),
            'sqlstate' => sanitize_text_field($this->mysql_sqlstate()),
            'variant_item_tables' => $variant_tables,
        ));
    }

    public function schema_status() {
        global $wpdb;
        $jobs = $this->table_jobs();
        $items = $this->table_items();
        return array(
            'jobs_table' => $jobs,
            'items_table' => $items,
            'jobs_exists' => $this->table_exists($jobs),
            'items_exists' => $this->table_exists($items),
            'schema_version' => (string) get_option('alma_geo_import_schema_version', ''),
            'last_error' => sanitize_textarea_field($wpdb->last_error),
        );
    }

    private function table_exists($table) {
        global $wpdb;
        $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : addcslashes($table, '_%\\');
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
    }

    private function find_variant_item_tables() {
        global $wpdb;
        $variant_suffixes = array(
            'alma_geo_import' . '_items',
            'alma_geo_index' . '_job_items',
            'alma_geo_import' . '_job_item',
        );
        $variants = array('alma_geo_import' . '_job_items');
        foreach ($variant_suffixes as $suffix) {
            $variants[] = $wpdb->prefix . $suffix;
            $variants[] = $suffix;
        }
        $found = array();
        foreach (array_unique($variants) as $table) {
            if ($table !== $this->table_items() && $this->table_exists($table)) {
                $found[] = sanitize_text_field($table);
            }
        }
        return $found;
    }

    private function repair_failure_message($before, $after, $dbdelta_items, $last_error, $variant_tables) {
        if (!empty($variant_tables)) {
            return __('La tabella esiste con nome diverso.', 'affiliate-link-manager-ai');
        }
        if ($last_error !== '') {
            if (stripos($last_error, 'CREATE') !== false && (stripos($last_error, 'denied') !== false || stripos($last_error, 'command denied') !== false)) {
                return __('Permessi database insufficienti per CREATE TABLE.', 'affiliate-link-manager-ai');
            }
            return sprintf(__('Errore SQL: %s', 'affiliate-link-manager-ai'), sanitize_textarea_field($last_error));
        }
        if (empty($after['items_exists']) && empty($dbdelta_items)) {
            return __('La query di creazione non è stata applicata da dbDelta.', 'affiliate-link-manager-ai');
        }
        if (!empty($before['jobs_exists']) && empty($before['items_exists']) && empty($after['items_exists'])) {
            return __('La tabella jobs esiste, ma la tabella staging job items non è stata creata.', 'affiliate-link-manager-ai');
        }
        return __('Riparazione schema GEO non completata: verifica i dettagli tecnici negli strumenti avanzati.', 'affiliate-link-manager-ai');
    }

    private function mysql_error_message() {
        global $wpdb;
        if (is_object($wpdb->dbh) && property_exists($wpdb->dbh, 'error')) {
            return (string) $wpdb->dbh->error;
        }
        return '';
    }

    private function mysql_sqlstate() {
        global $wpdb;
        if (is_object($wpdb->dbh) && property_exists($wpdb->dbh, 'sqlstate')) {
            return (string) $wpdb->dbh->sqlstate;
        }
        return '';
    }


    public function tables_exist() {
        $status = $this->schema_status();
        return !empty($status['jobs_exists']) && !empty($status['items_exists']);
    }

    public function ensure_tables($repair = true) {
        $status = $this->schema_status();
        if (!empty($status['jobs_exists']) && !empty($status['items_exists'])) {
            return true;
        }
        $repair_status = array();
        if ($repair) {
            $repair_status = $this->repair_tables();
            $status = is_array($repair_status['after'] ?? null) ? $repair_status['after'] : $repair_status;
            if (!empty($status['jobs_exists']) && !empty($status['items_exists'])) {
                return true;
            }
        }
        $missing = array();
        if (empty($status['jobs_exists'])) {
            $missing[] = $this->table_jobs();
        }
        if (empty($status['items_exists'])) {
            $missing[] = $this->table_items();
        }
        return new WP_Error(
            'alma_geo_import_schema_missing',
            !empty($repair_status['message'])
                ? sprintf(__('%s Nessun Link Affiliato è stato modificato.', 'affiliate-link-manager-ai'), $repair_status['message'])
                : __('Le tabelle staging GEO non sono disponibili. Clicca Ripara tabelle GEO o disattiva/riattiva il plugin. Nessun Link Affiliato è stato modificato.', 'affiliate-link-manager-ai'),
            array('missing_tables' => $missing, 'schema_status' => $status, 'repair_status' => $repair_status)
        );
    }

    public function create_job($job_type, $file_name, $options = array(), $created_by = 0) {
        global $wpdb;
        $schema_ready = $this->ensure_tables(true);
        if (is_wp_error($schema_ready)) {
            return $schema_ready;
        }
        $inserted = $wpdb->insert($this->table_jobs(), array(
            'job_type' => sanitize_key($job_type),
            'status' => 'queued',
            'file_name' => sanitize_file_name($file_name),
            'total_records' => 0,
            'processed_records' => 0,
            'imported_records' => 0,
            'updated_records' => 0,
            'skipped_records' => 0,
            'error_records' => 0,
            'options' => wp_json_encode(is_array($options) ? $options : array()),
            'created_by' => absint($created_by),
            'created_at' => current_time('mysql'),
        ), array('%s','%s','%s','%d','%d','%d','%d','%d','%d','%s','%d','%s'));
        if (!$inserted) {
            return new WP_Error('alma_geo_import_job_insert_failed', __('Impossibile creare la sessione staging GEO.', 'affiliate-link-manager-ai'), array(
                'table' => $this->table_jobs(),
                'operation' => 'insert_job',
                'sql_error' => sanitize_textarea_field($wpdb->last_error),
            ));
        }
        return (int) $wpdb->insert_id;
    }

    public function add_job_item($job_id, $row_number, $payload, $object_id = 0, $object_type = '', $status = 'queued', $action = '', $message = '') {
        global $wpdb;
        $status = sanitize_key($status ?: 'queued');
        if (!in_array($status, array('queued','processing','imported','updated','skipped','error'), true)) {
            $status = 'error';
        }
        $inserted = $wpdb->insert($this->table_items(), array(
            'job_id' => absint($job_id),
            'object_id' => $object_id ? absint($object_id) : null,
            'object_type' => sanitize_key($object_type),
            'row_number' => absint($row_number),
            'status' => $status,
            'action' => sanitize_key($action),
            'message' => sanitize_textarea_field($message),
            'raw_payload' => wp_json_encode(is_array($payload) ? $payload : array()),
            'created_at' => current_time('mysql'),
            'processed_at' => null,
        ), array('%d','%d','%s','%d','%s','%s','%s','%s','%s','%s'));
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public function set_total_records($job_id, $total) {
        global $wpdb;
        return $wpdb->update($this->table_jobs(), array('total_records' => absint($total)), array('id' => absint($job_id)), array('%d'), array('%d'));
    }

    public function get_job($job_id) {
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_jobs()} WHERE id = %d", absint($job_id)), ARRAY_A);
        if ($job) {
            $job['options'] = json_decode((string) ($job['options'] ?? ''), true) ?: array();
        }
        return $job;
    }

    public function get_latest_job($job_type = self::JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT) {
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_jobs()} WHERE job_type = %s ORDER BY id DESC LIMIT 1", sanitize_key($job_type)), ARRAY_A);
        if ($job) {
            $job['options'] = json_decode((string) ($job['options'] ?? ''), true) ?: array();
        }
        return $job;
    }


    public function update_job_options($job_id, $options) {
        global $wpdb;
        return $wpdb->update(
            $this->table_jobs(),
            array('options' => wp_json_encode(is_array($options) ? $options : array())),
            array('id' => absint($job_id)),
            array('%s'),
            array('%d')
        );
    }

    public function update_job_status($job_id, $status, $last_error = '') {
        global $wpdb;
        $status = sanitize_key($status);
        $allowed = array('queued','processing','partial','completed','failed','cancelled','needs_review');
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $row = array('status' => $status, 'last_error' => sanitize_textarea_field($last_error));
        $formats = array('%s','%s');
        if ($status === 'processing') {
            $row['started_at'] = current_time('mysql');
            $formats[] = '%s';
        }
        if (in_array($status, array('completed','failed','cancelled','needs_review'), true)) {
            $row['finished_at'] = current_time('mysql');
            $formats[] = '%s';
        }
        return $wpdb->update($this->table_jobs(), $row, array('id' => absint($job_id)), $formats, array('%d'));
    }

    public function claim_items($job_id, $limit = 50, $statuses = array('queued')) {
        global $wpdb;
        $job_id = absint($job_id);
        $limit = max(1, min(250, absint($limit)));
        $statuses = array_map('sanitize_key', (array) $statuses);
        $statuses = array_values(array_intersect($statuses, array('queued','error')));
        if (empty($statuses)) {
            $statuses = array('queued');
        }
        $this->last_claim_debug = array(
            'job_id' => $job_id,
            'requested_limit' => $limit,
            'claim_requested_statuses' => $statuses,
            'claim_ids_found' => array(),
            'claim_ids_returned' => array(),
            'queued_before' => 0,
            'processing_before' => 0,
            'queued_after' => 0,
            'processing_after' => 0,
            'updated_to_processing' => 0,
            'wpdb_last_error' => '',
        );
        $before_counts = $this->get_item_status_counts($job_id);
        $this->last_claim_debug['queued_before'] = (int) $before_counts['queued'];
        $this->last_claim_debug['processing_before'] = (int) $before_counts['processing'];
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge(array($job_id), $statuses, array($limit));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->table_items()} WHERE job_id = %d AND status IN ($placeholders) ORDER BY id ASC LIMIT %d", $params));
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        $this->last_claim_debug['claim_ids_found'] = $ids;
        if (!empty($ids)) {
            $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $update_params = array_merge(array('processing', 'claimed_for_processing', current_time('mysql'), $job_id), $ids, $statuses);
            $updated = $wpdb->query($wpdb->prepare("UPDATE {$this->table_items()} SET status = %s, action = %s, processed_at = %s WHERE job_id = %d AND id IN ($id_placeholders) AND status IN ($placeholders)", $update_params));
            $this->last_claim_debug['updated_to_processing'] = (int) $updated;
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items()} WHERE job_id = %d AND id IN ($id_placeholders) AND status = %s ORDER BY id ASC", array_merge(array($job_id), $ids, array('processing'))), ARRAY_A);
        } else {
            $items = array();
        }
        foreach ($items as &$item) {
            $decoded_payload = json_decode((string) ($item['raw_payload'] ?? ''), true);
            $item['raw_payload'] = is_array($decoded_payload) ? $decoded_payload : array();
        }
        unset($item);
        $returned_ids = array_values(array_map('absint', wp_list_pluck($items ?: array(), 'id')));
        $after_counts = $this->get_item_status_counts($job_id);
        $this->last_claim_debug['claim_ids_returned'] = $returned_ids;
        $this->last_claim_debug['queued_after'] = (int) $after_counts['queued'];
        $this->last_claim_debug['processing_after'] = (int) $after_counts['processing'];
        $this->last_claim_debug['wpdb_last_error'] = (string) $wpdb->last_error;
        return $items ?: array();
    }

    public function get_last_claim_debug() {
        return $this->last_claim_debug;
    }

    public function update_item_result($item_id, $status, $action, $message, $object_id = 0, $object_type = '') {
        global $wpdb;
        $status = sanitize_key($status);
        $allowed = array('queued','processing','imported','updated','skipped','error');
        if (!in_array($status, $allowed, true)) {
            $status = 'error';
        }
        return $wpdb->update($this->table_items(), array(
            'object_id' => $object_id ? absint($object_id) : null,
            'object_type' => sanitize_key($object_type),
            'status' => $status,
            'action' => sanitize_key($action),
            'message' => sanitize_textarea_field($message),
            'processed_at' => current_time('mysql'),
        ), array('id' => absint($item_id)), array('%d','%s','%s','%s','%s','%s'), array('%d'));
    }

    public function recount_job($job_id) {
        global $wpdb;
        $job_id = absint($job_id);
        $counts = array('processed' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0, 'queued' => 0, 'processing' => 0);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$this->table_items()} WHERE job_id = %d GROUP BY status", $job_id), ARRAY_A);
        foreach ($rows as $row) {
            $status = sanitize_key($row['status'] ?? '');
            $total = (int) $row['total'];
            if (isset($counts[$status])) {
                $counts[$status] = $total;
            }
            if (in_array($status, array('imported','updated','skipped','error'), true)) {
                $counts['processed'] += $total;
            }
        }
        $wpdb->update($this->table_jobs(), array(
            'processed_records' => $counts['processed'],
            'imported_records' => $counts['imported'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'error_records' => $counts['error'],
        ), array('id' => $job_id), array('%d','%d','%d','%d','%d'), array('%d'));
        return $counts;
    }

    public function maybe_complete_job($job_id) {
        $job = $this->get_job($job_id);
        if (!$job || in_array($job['status'], array('cancelled','failed','completed','needs_review'), true)) {
            return $job;
        }
        $this->recover_stale_processing_items($job_id);
        $counts = $this->recount_job($job_id);
        $total = (int) ($job['total_records'] ?? 0);
        if ($counts['queued'] === 0 && $counts['processing'] === 0 && ($total === 0 || $counts['processed'] >= $total)) {
            $this->update_job_status($job_id, 'completed');
        }
        return $this->get_job($job_id);
    }

    public function recover_stale_processing_items($job_id, $minutes = 10) {
        global $wpdb;
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - (max(1, absint($minutes)) * MINUTE_IN_SECONDS));
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_items()} SET status = %s, action = %s, message = %s, processed_at = NULL WHERE job_id = %d AND status = %s AND (processed_at IS NULL OR processed_at < %s)",
            'queued',
            'stale_processing_recovered',
            'stale_processing_recovered',
            absint($job_id),
            'processing',
            $threshold
        ));
    }

    public function get_item_status_counts($job_id) {
        $counts = $this->recount_job($job_id);
        return array(
            'queued' => (int) $counts['queued'],
            'processing' => (int) $counts['processing'],
            'imported' => (int) $counts['imported'],
            'updated' => (int) $counts['updated'],
            'skipped' => (int) $counts['skipped'],
            'error' => (int) $counts['error'],
        );
    }

    public function get_items($job_id, $limit = 50, $statuses = array()) {
        global $wpdb;
        $limit = max(1, min(200, absint($limit)));
        if (!empty($statuses)) {
            $statuses = array_map('sanitize_key', (array) $statuses);
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $params = array_merge(array(absint($job_id)), $statuses, array($limit));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items()} WHERE job_id = %d AND status IN ($placeholders) ORDER BY id DESC LIMIT %d", $params), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items()} WHERE job_id = %d ORDER BY id DESC LIMIT %d", absint($job_id), $limit), ARRAY_A);
        }
        return $rows ?: array();
    }


    public function get_job_diagnostic($job_id, $extra = array()) {
        global $wpdb;
        $job_id = absint($job_id);
        $job = $this->get_job($job_id);
        $counts = $this->get_item_status_counts($job_id);
        $latest_item = $wpdb->get_row($wpdb->prepare("SELECT id, row_number, object_id, object_type, status, action, message, processed_at FROM {$this->table_items()} WHERE job_id = %d ORDER BY id DESC LIMIT 1", $job_id), ARRAY_A);
        return array_merge(array(
            'job_id' => $job_id,
            'total_item_rows' => array_sum(array_map('intval', $counts)),
            'queued' => (int) $counts['queued'],
            'processing' => (int) $counts['processing'],
            'imported' => (int) $counts['imported'],
            'updated' => (int) $counts['updated'],
            'skipped' => (int) $counts['skipped'],
            'error' => (int) $counts['error'],
            'latest_item_status' => !empty($latest_item['status']) ? sanitize_key($latest_item['status']) : '',
            'latest_item' => is_array($latest_item) ? array(
                'id' => (int) $latest_item['id'],
                'row_number' => (int) $latest_item['row_number'],
                'affiliate_link_id' => (int) $latest_item['object_id'],
                'object_type' => sanitize_key($latest_item['object_type']),
                'status' => sanitize_key($latest_item['status']),
                'action' => sanitize_key($latest_item['action']),
                'message' => sanitize_textarea_field($latest_item['message']),
                'processed_at' => sanitize_text_field($latest_item['processed_at']),
            ) : array(),
            'last_error' => sanitize_textarea_field($job['last_error'] ?? ''),
            'batch_size' => isset($job['options']['batch_size']) ? (int) $job['options']['batch_size'] : 0,
            'safe_only' => !empty($job['options']['safe_only']),
            'overwrite' => !empty($job['options']['overwrite']),
            'last_claim' => $this->get_last_claim_debug(),
        ), is_array($extra) ? $extra : array());
    }

    public function reset_error_items($job_id) {
        global $wpdb;
        return $wpdb->update($this->table_items(), array('status' => 'queued', 'action' => 'retry', 'processed_at' => null), array('job_id' => absint($job_id), 'status' => 'error'), array('%s','%s','%s'), array('%d','%s'));
    }


    public function delete_job($job_id) {
        global $wpdb;
        $job_id = absint($job_id);
        if (!$job_id) {
            return false;
        }
        $wpdb->delete($this->table_items(), array('job_id' => $job_id), array('%d'));
        return (bool) $wpdb->delete($this->table_jobs(), array('id' => $job_id), array('%d'));
    }

    public function get_items_with_payload($job_id, $limit = 5000, $offset = 0) {
        global $wpdb;
        $job_id = absint($job_id);
        $limit = max(1, min(20000, absint($limit)));
        $offset = max(0, absint($offset));
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items()} WHERE job_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $job_id, $limit, $offset), ARRAY_A) ?: array();
        foreach ($items as &$item) {
            $payload = json_decode((string) ($item['raw_payload'] ?? ''), true);
            $item['raw_payload'] = is_array($payload) ? $payload : array();
        }
        unset($item);
        return $items ?: array();
    }

    public function get_all_items_with_payload($job_id, $page_size = 1000, $max_items = 0) {
        $items = array();
        $page_size = max(1, min(5000, absint($page_size)));
        $max_items = absint($max_items);
        $unlimited = $max_items === 0;
        if (!$unlimited) {
            $max_items = max($page_size, $max_items);
        }
        for ($offset = 0; $unlimited || $offset < $max_items; $offset += $page_size) {
            $page = $this->get_items_with_payload($job_id, $page_size, $offset);
            if (empty($page)) {
                break;
            }
            if (!$unlimited && count($items) + count($page) > $max_items) {
                $page = array_slice($page, 0, max(0, $max_items - count($items)));
            }
            $items = array_merge($items, $page);
            if (count($page) < $page_size || (!$unlimited && count($items) >= $max_items)) {
                break;
            }
        }
        return $items;
    }

    public function get_public_session_status($job) {
        if (empty($job)) {
            return 'ready';
        }
        $status = sanitize_key($job['status'] ?? 'queued');
        if ($status === 'queued') {
            return ((int) ($job['processed_records'] ?? 0) > 0) ? 'partial' : 'ready';
        }
        if ($status === 'processing') {
            return 'processing';
        }
        if ($status === 'partial') {
            return 'partial';
        }
        if (in_array($status, array('completed','failed','cancelled','needs_review'), true)) {
            return $status;
        }
        return 'ready';
    }


    private function primary_message_reason($message) {
        $message = sanitize_textarea_field((string) $message);
        if ($message === '') {
            return '';
        }
        $reasons = array(
            'duplicate_staging_item',
            'missing_affiliate_link_id',
            'invalid_affiliate_link_id',
            'missing_affiliate_url',
            'affiliate_link_not_found',
            'object_not_affiliate_link',
            'safe_import_false',
            'safe_import_skipped',
            'final_bucket_discard',
            'existing_geo_skipped',
            'secondary_locations_json_invalid',
            'invalid_affiliate_url',
            'invalid_url',
            'incomplete_record',
            'missing_primary_location',
            'missing_primary_name',
            'unknown_location',
            'missing_region',
            'duplicate',
            'sql_insert_failed',
            'unknown_error',
            'needs_review',
        );
        foreach ($reasons as $reason) {
            if (preg_match('/(^|[^a-z0-9_])' . preg_quote($reason, '/') . '($|[^a-z0-9_])/', $message)) {
                return $reason;
            }
        }
        return '';
    }

    public function get_report($job_id) {
        $job = $this->get_job($job_id);
        if (!$job) {
            return array();
        }
        $items = $this->get_all_items_with_payload($job_id, 1000, 0);
        $report = array(
            'session_id' => (int) $job['id'],
            'date' => $job['finished_at'] ?: $job['created_at'],
            'created_at' => $job['created_at'],
            'last_batch_at' => $job['started_at'] ?: $job['finished_at'],
            'file' => $job['file_name'],
            'status' => $this->get_public_session_status($job),
            'records_read' => (int) $job['total_records'],
            'records_processed' => (int) $job['processed_records'],
            'records_remaining' => max(0, (int) $job['total_records'] - (int) $job['processed_records']),
            'items_created' => 0,
            'rows_discarded' => 0,
            'discard_reasons' => array(),
            'discard_examples' => array(),
            'percent' => (int) $job['total_records'] > 0 ? round(((int) $job['processed_records'] / (int) $job['total_records']) * 100, 1) : 0,
            'last_batch_size' => isset($job['options']['batch_size']) ? (int) $job['options']['batch_size'] : 50,
            'imported' => (int) $job['imported_records'],
            'updated' => (int) $job['updated_records'],
            'skipped' => (int) $job['skipped_records'],
            'errors' => (int) $job['error_records'],
            'affiliate_link_not_found' => 0,
            'object_not_affiliate_link' => 0,
            'safe_import_skipped' => 0,
            'existing_geo_skipped' => 0,
            'already_present' => 0,
            'duplicates' => 0,
            'duplicate_staging_item' => 0,
            'duplicate' => 0,
            'invalid_urls' => 0,
            'incomplete_records' => 0,
            'unknown_locations' => 0,
            'missing_region' => 0,
            'geo_assigned' => 0,
            'needs_review' => 0,
            'secondaries_imported' => 0,
            'secondary_locations_json_invalid' => 0,
            'locations_created' => 0,
            'locations_reused' => 0,
            'relations_created' => 0,
            'relations_updated' => 0,
        );
        foreach ($items as $item) {
            $message = (string) ($item['message'] ?? '');
            if (!(in_array(($item['status'] ?? ''), array('skipped','error'), true) && strpos($message, 'staging_') === 0)) {
                $report['items_created']++;
            }
            if (in_array(($item['status'] ?? ''), array('skipped','error'), true) && strpos($message, 'staging_') === 0) {
                $reason = sanitize_key(preg_replace('/^staging_/', '', strtok($message, ' ')));
                $report['rows_discarded']++;
                if ($reason) {
                    $report['discard_reasons'][$reason] = ($report['discard_reasons'][$reason] ?? 0) + 1;
                }
                $payload = is_array($item['raw_payload'] ?? null) ? $item['raw_payload'] : array();
                $report['discard_examples'][] = array(
                    'row_number' => (int) ($item['row_number'] ?? 0),
                    'affiliate_link_id' => absint($payload['affiliate_link_id'] ?? ($item['object_id'] ?? 0)),
                    'post_title' => sanitize_text_field($payload['post_title'] ?? ($payload['title'] ?? '')),
                    'affiliate_url' => esc_url_raw($payload['affiliate_url'] ?? ''),
                    'primary_name' => sanitize_text_field($payload['primary_name'] ?? ''),
                    'final_bucket' => sanitize_key($payload['final_bucket'] ?? ''),
                    'safe_for_auto_import' => sanitize_text_field($payload['safe_for_auto_import'] ?? ''),
                    'reason' => $reason ?: 'unknown_error',
                );
                $report['discard_examples'] = array_slice($report['discard_examples'], -10);
            }
            $primary_reason = $this->primary_message_reason($message);
            if ($primary_reason !== '') {
                if (isset($report[$primary_reason])) {
                    $report[$primary_reason]++;
                }
                if ($primary_reason === 'existing_geo_skipped') { $report['already_present']++; }
                if (in_array($primary_reason, array('invalid_url','invalid_affiliate_url'), true)) { $report['invalid_urls']++; }
                if ($primary_reason === 'incomplete_record') { $report['incomplete_records']++; }
                if (in_array($primary_reason, array('unknown_location','missing_primary_name','missing_primary_location'), true)) { $report['unknown_locations']++; }
                if ($primary_reason === 'missing_region') { $report['missing_region']++; }
                if ($primary_reason === 'duplicate') { $report['duplicates']++; }
                if ($primary_reason === 'needs_review') { $report['needs_review']++; }
            }
            if (preg_match('/secondaries_imported=(\d+)/', $message, $m)) {
                $report['secondaries_imported'] += (int) $m[1];
            }
            foreach (array('locations_created','locations_reused','relations_created','relations_updated') as $metric) {
                if (preg_match('/' . $metric . '=(\d+)/', $message, $m)) {
                    $report[$metric] += (int) $m[1];
                }
            }
        }
        $option_reasons = is_array($job['options']['discard_reasons'] ?? null) ? $job['options']['discard_reasons'] : array();
        foreach ($option_reasons as $reason => $count) {
            $reason = sanitize_key($reason);
            if ($reason && empty($report['discard_reasons'][$reason])) {
                $report['discard_reasons'][$reason] = absint($count);
            }
        }
        if (!empty($job['options']['rows_discarded']) && (int) $job['options']['rows_discarded'] > $report['rows_discarded']) {
            $report['rows_discarded'] = (int) $job['options']['rows_discarded'];
        }
        if (!empty($job['options']['discard_examples']) && count($report['discard_examples']) < 10) {
            $report['discard_examples'] = array_slice(array_merge((array) $job['options']['discard_examples'], $report['discard_examples']), -10);
        }
        if (!empty($job['options']['items_created']) && (int) $job['options']['items_created'] > $report['items_created']) {
            $report['items_created'] = (int) $job['options']['items_created'];
        }
        $report['geo_assigned'] = (int) $report['imported'] + (int) $report['updated'];
        return $report;
    }
}
