<?php
if (!defined('ABSPATH')) { exit; }

interface ALMA_Affiliate_Source_Provider_Interface {
    public function get_provider_id();
    public function get_label();
    public function get_settings_schema();
    public function validate_settings($settings);
    public function test_connection($settings);
    public function fetch_items($source, $args = array());
    public function normalize_item($item, $source);
    public function import_item_to_affiliate_link($normalized_item, $source, $rules = array());
}
