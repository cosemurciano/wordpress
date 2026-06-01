<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_GYG_CSV_Import_Job_Service {
    const CRON_HOOK = 'alma_gyg_csv_run_import_job_batch';
    const BATCH_SIZE = 50;

    private function jobs_table() { global $wpdb; return $wpdb->prefix . 'alma_gyg_csv_import_jobs'; }
    private function sessions_table() { global $wpdb; return $wpdb->prefix . 'alma_gyg_csv_import_sessions'; }
    private function progress_table() { global $wpdb; return $wpdb->prefix . 'alma_gyg_csv_import_progress'; }
    private function table_exists($table) { global $wpdb; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table; }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}alma_gyg_csv_import_jobs (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, session_id bigint(20) unsigned NOT NULL, source_id bigint(20) unsigned NOT NULL, activity_type varchar(255) NOT NULL DEFAULT '', activity_type_hash char(64) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'queued', criteria_json longtext NULL, record_mapping_json longtext NULL, selected_external_ids_json longtext NULL, total_records int(10) unsigned NOT NULL DEFAULT 0, processed_records int(10) unsigned NOT NULL DEFAULT 0, imported_count int(10) unsigned NOT NULL DEFAULT 0, updated_count int(10) unsigned NOT NULL DEFAULT 0, existing_count int(10) unsigned NOT NULL DEFAULT 0, skipped_count int(10) unsigned NOT NULL DEFAULT 0, error_count int(10) unsigned NOT NULL DEFAULT 0, last_cursor int(10) unsigned NOT NULL DEFAULT 0, last_message text NULL, created_by bigint(20) unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, started_at datetime NULL, finished_at datetime NULL, last_error text NULL, PRIMARY KEY  (id), KEY session_id (session_id), KEY source_id (source_id), KEY status (status), KEY activity_type_hash (activity_type_hash), KEY updated_at (updated_at)) $c;");
    }

    public function get_job($job_id) {
        global $wpdb;
        $table = $this->jobs_table();
        if (!$this->table_exists($table)) return array();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", absint($job_id)), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public function get_latest_jobs_for_session($session_id) {
        global $wpdb;
        $table = $this->jobs_table();
        if (!$this->table_exists($table)) return array();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE session_id=%d ORDER BY updated_at DESC, id DESC", absint($session_id)), ARRAY_A);
        $out = array();
        foreach ((array)$rows as $row) {
            $hash = (string)($row['activity_type_hash'] ?? '');
            if ($hash !== '' && !isset($out[$hash])) $out[$hash] = $row;
        }
        return $out;
    }

    public function get_session_job_totals($session_id) {
        global $wpdb;
        $table = $this->jobs_table();
        if (!$this->table_exists($table)) return array('processed'=>0,'imported'=>0,'updated'=>0,'existing'=>0,'skipped'=>0,'errors'=>0,'status'=>'ready','updated_at'=>'');
        $row = $wpdb->get_row($wpdb->prepare("SELECT SUM(processed_records) processed, SUM(imported_count) imported, SUM(updated_count) updated, SUM(existing_count) existing, SUM(skipped_count) skipped, SUM(error_count) errors, MAX(updated_at) updated_at FROM {$table} WHERE session_id=%d", absint($session_id)), ARRAY_A);
        $running = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id=%d AND status IN ('queued','running','paused')", absint($session_id)));
        $failed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id=%d AND status='failed'", absint($session_id)));
        $status = $running > 0 ? 'running' : ($failed > 0 ? 'failed' : 'ready');
        return array('processed'=>absint($row['processed'] ?? 0),'imported'=>absint($row['imported'] ?? 0),'updated'=>absint($row['updated'] ?? 0),'existing'=>absint($row['existing'] ?? 0),'skipped'=>absint($row['skipped'] ?? 0),'errors'=>absint($row['errors'] ?? 0),'status'=>$status,'updated_at'=>(string)($row['updated_at'] ?? ''));
    }

    public function create_job($session, $source, $activity_type, $criteria, $record_mapping, $selected_external_ids, $fallback_term_ids, $update_existing) {
        global $wpdb;
        self::create_table();
        $selected = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$selected_external_ids))));
        $mapping = array();
        foreach ((array)$record_mapping as $external_id => $term_ids) {
            $external_id = sanitize_text_field((string)$external_id);
            $ids = ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids($term_ids);
            if ($external_id !== '' && !empty($ids) && (empty($selected) || in_array($external_id, $selected, true))) $mapping[$external_id] = $ids;
        }
        $criteria = is_array($criteria) ? $criteria : array();
        $criteria['fallback_term_ids'] = ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids($fallback_term_ids);
        $criteria['update_existing'] = !empty($update_existing) ? 1 : 0;
        $now = current_time('mysql');
        $data = array(
            'session_id' => absint($session['id'] ?? 0),
            'source_id' => absint($source['id'] ?? 0),
            'activity_type' => sanitize_text_field((string)$activity_type),
            'activity_type_hash' => ALMA_Affiliate_Source_GYG_CSV_Importer::activity_type_hash($activity_type),
            'status' => 'queued',
            'criteria_json' => wp_json_encode($criteria),
            'record_mapping_json' => wp_json_encode($mapping),
            'selected_external_ids_json' => wp_json_encode($selected),
            'total_records' => count($selected),
            'processed_records' => 0,
            'last_cursor' => 0,
            'last_message' => __('Job creato. In attesa del primo batch.', 'affiliate-link-manager-ai'),
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        );
        $ok = $wpdb->insert($this->jobs_table(), $data);
        if (!$ok) return new WP_Error('job_insert_failed', __('Impossibile creare il job di importazione.', 'affiliate-link-manager-ai'));
        $job_id = (int)$wpdb->insert_id;
        $this->schedule_job($job_id);
        return $job_id;
    }

    public function schedule_job($job_id) {
        if (!wp_next_scheduled(self::CRON_HOOK, array(absint($job_id)))) {
            wp_schedule_single_event(time() + 20, self::CRON_HOOK, array(absint($job_id)));
        }
    }

    private function decode_json($raw) { $d = json_decode((string)$raw, true); return is_array($d) ? $d : array(); }

    private function get_session_by_id($session_id) {
        global $wpdb;
        $table = $this->sessions_table();
        if (!$this->table_exists($table)) return array();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", absint($session_id)), ARRAY_A);
        if (!is_array($row) || empty($row['file_path']) || !file_exists($row['file_path'])) return array();
        return array('id'=>absint($row['id']),'path'=>(string)$row['file_path'],'name'=>(string)$row['original_filename'],'token'=>(string)$row['token'],'columns'=>$this->decode_json($row['columns_json'] ?? ''),'summary'=>$this->decode_json($row['summary_json'] ?? ''),'total_rows'=>absint($row['total_rows']));
    }

    private function get_source($source_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id=%d", absint($source_id)), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public function run_batch($job_id) {
        global $wpdb;
        $job = $this->get_job($job_id);
        if (empty($job) || in_array((string)$job['status'], array('completed','failed','cancelled'), true)) return $this->format_status($job);
        $lock_key = 'alma_gyg_csv_job_lock_' . absint($job_id);
        if (get_transient($lock_key)) return $this->format_status($job, __('Un batch è già in esecuzione.', 'affiliate-link-manager-ai'));
        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);
        try {
            $session = $this->get_session_by_id($job['session_id']);
            $source = $this->get_source($job['source_id']);
            if (empty($session) || empty($source)) throw new Exception(__('Sessione o source non disponibile.', 'affiliate-link-manager-ai'));
            $criteria = $this->decode_json($job['criteria_json'] ?? '');
            $selected = $this->decode_json($job['selected_external_ids_json'] ?? '');
            $mapping = $this->decode_json($job['record_mapping_json'] ?? '');
            $fallback = ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_mapping_term_ids($criteria['fallback_term_ids'] ?? array());
            $update_existing = !empty($criteria['update_existing']);
            $svc = new ALMA_Affiliate_Source_GYG_CSV_Importer();
            $result = $svc->import_selected_batch($session['path'], $session['columns']['columns'] ?? $session['columns'], (string)$job['activity_type'], $source, $selected, $fallback, $mapping, absint($job['last_cursor']), self::BATCH_SIZE, $update_existing);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            $processed = absint($job['processed_records']) + absint($result['processed']);
            $done = !empty($result['done']) || $processed >= absint($job['total_records']);
            $status = $done ? 'completed' : 'running';
            $now = current_time('mysql');
            $wpdb->update($this->jobs_table(), array(
                'status'=>$status,
                'processed_records'=>$processed,
                'imported_count'=>absint($job['imported_count']) + absint($result['imported']),
                'updated_count'=>absint($job['updated_count']) + absint($result['updated']),
                'existing_count'=>absint($job['existing_count']) + absint($result['existing']),
                'skipped_count'=>absint($job['skipped_count']) + absint($result['skipped']),
                'error_count'=>absint($job['error_count']) + absint($result['errors']),
                'last_cursor'=>absint($result['next_cursor']),
                'last_message'=>$done ? __('Importazione completata.', 'affiliate-link-manager-ai') : __('Batch completato, prossimo batch programmato.', 'affiliate-link-manager-ai'),
                'updated_at'=>$now,
                'started_at'=>empty($job['started_at']) ? $now : $job['started_at'],
                'finished_at'=>$done ? $now : null,
                'last_error'=>'',
            ), array('id'=>absint($job_id)));
            $svc->upsert_progress(absint($session['id']), absint($source['id']), (string)$job['activity_type'], $fallback, array_merge($result, array('done'=>$done)));
            if (!$done) $this->schedule_job($job_id);
        } catch (Exception $e) {
            $wpdb->update($this->jobs_table(), array('status'=>'failed','last_error'=>$e->getMessage(),'last_message'=>__('Importazione in errore.', 'affiliate-link-manager-ai'),'updated_at'=>current_time('mysql')), array('id'=>absint($job_id)));
        }
        delete_transient($lock_key);
        return $this->format_status($this->get_job($job_id));
    }

    public function cancel_job($job_id) {
        global $wpdb;
        $job = $this->get_job($job_id);
        if (empty($job)) return false;
        if (!in_array((string)$job['status'], array('completed','failed','cancelled'), true)) {
            $wpdb->update($this->jobs_table(), array('status'=>'cancelled','last_message'=>__('Job annullato.', 'affiliate-link-manager-ai'),'updated_at'=>current_time('mysql'),'finished_at'=>current_time('mysql')), array('id'=>absint($job_id)));
        }
        return true;
    }

    public function format_status($job, $message = '') {
        if (empty($job)) return array();
        $total = absint($job['total_records'] ?? 0);
        $processed = absint($job['processed_records'] ?? 0);
        $remaining = max(0, $total - $processed);
        $percent = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 0;
        return array(
            'job_id'=>absint($job['id'] ?? 0),
            'status'=>(string)($job['status'] ?? ''),
            'total_records'=>$total,
            'processed_records'=>$processed,
            'remaining_records'=>$remaining,
            'imported_count'=>absint($job['imported_count'] ?? 0),
            'updated_count'=>absint($job['updated_count'] ?? 0),
            'existing_count'=>absint($job['existing_count'] ?? 0),
            'skipped_count'=>absint($job['skipped_count'] ?? 0),
            'error_count'=>absint($job['error_count'] ?? 0),
            'percent'=>$percent,
            'message'=>$message !== '' ? $message : (string)($job['last_message'] ?: $job['last_error']),
            'last_error'=>(string)($job['last_error'] ?? ''),
            'updated_at'=>(string)($job['updated_at'] ?? ''),
        );
    }
}
