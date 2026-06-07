<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistent background job store for Geo Index imports.
 */
class ALMA_Geo_Index_Job_Store {
    const JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT = 'affiliate_links_geo_import';

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
        $store->install_tables();
    }

    public function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $jobs = $this->table_jobs();
        $items = $this->table_items();

        $sql_jobs = "CREATE TABLE $jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(80) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'queued',
            file_name VARCHAR(255) DEFAULT '',
            total_records INT NOT NULL DEFAULT 0,
            processed_records INT NOT NULL DEFAULT 0,
            imported_records INT NOT NULL DEFAULT 0,
            updated_records INT NOT NULL DEFAULT 0,
            skipped_records INT NOT NULL DEFAULT 0,
            error_records INT NOT NULL DEFAULT 0,
            options LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            last_error TEXT NULL,
            PRIMARY KEY  (id),
            KEY job_type (job_type),
            KEY status (status)
        ) $charset_collate;";

        $sql_items = "CREATE TABLE $items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NULL,
            object_type VARCHAR(50) DEFAULT '',
            row_number INT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'queued',
            action VARCHAR(40) DEFAULT '',
            message TEXT NULL,
            raw_payload LONGTEXT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY status (status),
            KEY object_lookup (object_id, object_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_jobs);
        dbDelta($sql_items);
    }

    public function tables_exist() {
        global $wpdb;
        $jobs = $this->table_jobs();
        $items = $this->table_items();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jobs)) === $jobs
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items)) === $items;
    }

    public function create_job($job_type, $file_name, $options = array(), $created_by = 0) {
        global $wpdb;
        if (!$this->tables_exist()) {
            $this->install_tables();
        }
        $wpdb->insert($this->table_jobs(), array(
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
        return (int) $wpdb->insert_id;
    }

    public function add_job_item($job_id, $row_number, $payload, $object_id = 0, $object_type = '') {
        global $wpdb;
        $wpdb->insert($this->table_items(), array(
            'job_id' => absint($job_id),
            'object_id' => $object_id ? absint($object_id) : null,
            'object_type' => sanitize_key($object_type),
            'row_number' => absint($row_number),
            'status' => 'queued',
            'action' => '',
            'message' => '',
            'raw_payload' => wp_json_encode(is_array($payload) ? $payload : array()),
            'processed_at' => null,
        ), array('%d','%d','%s','%d','%s','%s','%s','%s','%s'));
        return (int) $wpdb->insert_id;
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

    public function update_job_status($job_id, $status, $last_error = '') {
        global $wpdb;
        $status = sanitize_key($status);
        $allowed = array('queued','running','paused','completed','failed','cancelled');
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $row = array('status' => $status, 'last_error' => sanitize_textarea_field($last_error));
        $formats = array('%s','%s');
        if ($status === 'running') {
            $row['started_at'] = current_time('mysql');
            $formats[] = '%s';
        }
        if (in_array($status, array('completed','failed','cancelled'), true)) {
            $row['finished_at'] = current_time('mysql');
            $formats[] = '%s';
        }
        return $wpdb->update($this->table_jobs(), $row, array('id' => absint($job_id)), $formats, array('%d'));
    }

    public function claim_items($job_id, $limit = 50, $statuses = array('queued')) {
        global $wpdb;
        $limit = max(1, min(100, absint($limit)));
        $statuses = array_map('sanitize_key', (array) $statuses);
        $statuses = array_values(array_intersect($statuses, array('queued','error')));
        if (empty($statuses)) {
            $statuses = array('queued');
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge(array(absint($job_id)), $statuses, array($limit));
        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_items()} WHERE job_id = %d AND status IN ($placeholders) ORDER BY id ASC LIMIT %d", $params), ARRAY_A);
        foreach ($items as &$item) {
            $wpdb->update($this->table_items(), array('status' => 'processing'), array('id' => (int) $item['id']), array('%s'), array('%d'));
            $item['raw_payload'] = json_decode((string) ($item['raw_payload'] ?? ''), true) ?: array();
        }
        return $items;
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
        if (!$job || in_array($job['status'], array('paused','cancelled','failed','completed'), true)) {
            return $job;
        }
        $counts = $this->recount_job($job_id);
        if ($counts['queued'] === 0 && $counts['processing'] === 0) {
            $this->update_job_status($job_id, 'completed');
        }
        return $this->get_job($job_id);
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

    public function reset_error_items($job_id) {
        global $wpdb;
        return $wpdb->update($this->table_items(), array('status' => 'queued', 'action' => 'retry', 'processed_at' => null), array('job_id' => absint($job_id), 'status' => 'error'), array('%s','%s','%s'), array('%d','%s'));
    }

    public function get_report($job_id) {
        $job = $this->get_job($job_id);
        if (!$job) {
            return array();
        }
        $items = $this->get_items($job_id, 5000);
        $report = array(
            'date' => $job['finished_at'] ?: $job['created_at'],
            'file' => $job['file_name'],
            'records_read' => (int) $job['total_records'],
            'records_processed' => (int) $job['processed_records'],
            'imported' => (int) $job['imported_records'],
            'updated' => (int) $job['updated_records'],
            'skipped' => (int) $job['skipped_records'],
            'errors' => (int) $job['error_records'],
            'affiliate_link_not_found' => 0,
            'object_not_affiliate_link' => 0,
            'safe_import_skipped' => 0,
            'existing_geo_skipped' => 0,
            'secondaries_imported' => 0,
            'secondary_locations_json_invalid' => 0,
            'locations_created' => 0,
            'locations_reused' => 0,
            'relations_created' => 0,
            'relations_updated' => 0,
        );
        foreach ($items as $item) {
            $message = (string) ($item['message'] ?? '');
            foreach (array('affiliate_link_not_found','object_not_affiliate_link','safe_import_skipped','existing_geo_skipped','secondary_locations_json_invalid') as $needle) {
                if (strpos($message, $needle) !== false) {
                    $report[$needle]++;
                }
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
        return $report;
    }
}
