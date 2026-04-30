<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Fallback {
    public function test_connection($source) {
        return new WP_Error('provider_unsupported', __('Test connessione non ancora supportato per questo provider.', 'affiliate-link-manager-ai'));
    }

    public function discover_fields($source, $force_refresh = false) {
        return new WP_Error('provider_unsupported', __('Discovery campi non ancora supportata per questo provider.', 'affiliate-link-manager-ai'));
    }
}
