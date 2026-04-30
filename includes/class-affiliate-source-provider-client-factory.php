<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Factory {
    public static function make($source) {
        $provider = sanitize_key($source['provider'] ?? '');
        if ($provider === 'custom_api') {
            return new ALMA_Affiliate_Source_Provider_Client_Custom_API();
        }
        return new ALMA_Affiliate_Source_Provider_Client_Fallback();
    }
}
