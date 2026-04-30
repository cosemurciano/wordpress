<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Connection_Service {
    private $storage;

    public function __construct() {
        $this->storage = new ALMA_Affiliate_Source_Connection_Test_Storage();
    }

    public function test($source) {
        $source_id = (int) ($source['id'] ?? 0);
        $this->storage->maybe_migrate_legacy($source_id);

        $provider = ALMA_Affiliate_Source_Provider_Client_Factory::resolve_provider_key($source);
        $client = ALMA_Affiliate_Source_Provider_Client_Factory::make($source);

        $started = microtime(true);
        $result = $client->test_connection($source);
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        $entry = array(
            'provider' => $provider,
            'status' => is_wp_error($result) ? 'error' : 'success',
            'error_code' => is_wp_error($result) ? $result->get_error_code() : '',
            'message' => is_wp_error($result) ? $result->get_error_message() : 'Connessione riuscita',
            'tested_at' => current_time('mysql'),
            'duration_ms' => $duration_ms,
            'http_status' => is_array($result) ? (int) ($result['http_status'] ?? 0) : 0,
        );

        $this->storage->set($source_id, $entry);

        return $result;
    }
}
