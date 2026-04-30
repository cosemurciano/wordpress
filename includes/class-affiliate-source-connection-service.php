<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Connection_Service {
    public function test($source) {
        $client = ALMA_Affiliate_Source_Provider_Client_Factory::make($source);
        $result = $client->test_connection($source);
        update_option('alma_last_connection_test_' . (int)$source['id'], array('at' => current_time('mysql'), 'ok' => !is_wp_error($result)));
        return $result;
    }
}
