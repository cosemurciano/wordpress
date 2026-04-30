<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_Affiliate_Source_Provider_Custom_API extends ALMA_Affiliate_Source_Provider_Generic_API {
public function get_provider_id(){return 'custom_api';}
public function get_label(){return __('Custom API','affiliate-link-manager-ai');}
}
