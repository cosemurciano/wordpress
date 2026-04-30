<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Field_Discovery_Service {
    public function discover($source, $force_refresh = false) {
        $client = ALMA_Affiliate_Source_Provider_Client_Factory::make($source);
        return $client->discover_fields($source, $force_refresh);
    }
}
