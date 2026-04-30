<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Connection_Test_Storage {
    const OPTION_KEY = 'alma_last_connection_tests';
    const MAX_AGE_SECONDS = 30 * DAY_IN_SECONDS;

    public function maybe_migrate_legacy($source_id = 0) {
        $source_id = absint($source_id);
        if ($source_id <= 0) {
            return;
        }

        $legacy_key = 'alma_last_connection_test_' . $source_id;
        $legacy = get_option($legacy_key, null);
        if (!is_array($legacy)) {
            return;
        }

        $entry = array(
            'source_id' => $source_id,
            'provider' => sanitize_key($legacy['provider'] ?? ''),
            'status' => !empty($legacy['ok']) ? 'success' : 'error',
            'error_code' => sanitize_key($legacy['error_code'] ?? ''),
            'message' => sanitize_text_field($legacy['message'] ?? ''),
            'tested_at' => sanitize_text_field($legacy['at'] ?? ''),
            'duration_ms' => max(0, (int) ($legacy['duration_ms'] ?? 0)),
            'http_status' => max(0, (int) ($legacy['http_status'] ?? 0)),
        );

        $this->set($source_id, $entry);
        delete_option($legacy_key);
    }

    public function set($source_id, $entry) {
        $source_id = absint($source_id);
        if ($source_id <= 0) {
            return;
        }

        $all = $this->load_all();
        $all[$source_id] = $this->sanitize_entry($source_id, $entry);
        $all = $this->prune($all);
        $this->save_all($all);
    }

    public function get($source_id) {
        $source_id = absint($source_id);
        if ($source_id <= 0) {
            return null;
        }

        $all = $this->prune($this->load_all());
        if (!isset($all[$source_id]) || !is_array($all[$source_id])) {
            return null;
        }

        return $all[$source_id];
    }

    private function load_all() {
        $all = get_option(self::OPTION_KEY, array());
        return is_array($all) ? $all : array();
    }

    private function save_all($all) {
        $all = is_array($all) ? $all : array();

        if (get_option(self::OPTION_KEY, null) === null) {
            add_option(self::OPTION_KEY, $all, '', false);
            return;
        }

        update_option(self::OPTION_KEY, $all, false);
    }

    private function sanitize_entry($source_id, $entry) {
        return array(
            'source_id' => absint($source_id),
            'provider' => sanitize_key($entry['provider'] ?? ''),
            'status' => ($entry['status'] ?? '') === 'success' ? 'success' : 'error',
            'error_code' => sanitize_key($entry['error_code'] ?? ''),
            'message' => sanitize_text_field($entry['message'] ?? ''),
            'tested_at' => sanitize_text_field($entry['tested_at'] ?? current_time('mysql')),
            'duration_ms' => max(0, (int) ($entry['duration_ms'] ?? 0)),
            'http_status' => max(0, (int) ($entry['http_status'] ?? 0)),
        );
    }

    private function prune($all) {
        if (!is_array($all) || empty($all)) {
            return array();
        }

        $cutoff = time() - self::MAX_AGE_SECONDS;
        foreach ($all as $source_id => $entry) {
            if (!is_array($entry)) {
                unset($all[$source_id]);
                continue;
            }

            $tested = strtotime((string) ($entry['tested_at'] ?? ''));
            if ($tested !== false && $tested < $cutoff) {
                unset($all[$source_id]);
            }
        }

        return $all;
    }
}
