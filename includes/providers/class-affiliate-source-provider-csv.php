<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_Affiliate_Source_Provider_CSV extends ALMA_Affiliate_Source_Provider_Manual {
public function get_provider_id(){return 'csv';}
public function get_label(){return __('CSV','affiliate-link-manager-ai');}
}
