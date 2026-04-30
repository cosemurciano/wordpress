<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_Affiliate_Source_Provider_Manual implements ALMA_Affiliate_Source_Provider_Interface {
public function get_provider_id(){return 'manual';}
public function get_label(){return __('Manuale','affiliate-link-manager-ai');}
public function get_settings_schema(){return array();}
public function validate_settings($settings){return true;}
public function test_connection($settings){return array('success'=>true,'message'=>__('Provider manuale: nessuna connessione richiesta.','affiliate-link-manager-ai'));}
public function fetch_items($source,$args=array()){return array();}
public function normalize_item($item,$source){return ALMA_Affiliate_Source_Normalizer::normalize($item,$source);} 
public function import_item_to_affiliate_link($normalized_item,$source,$rules=array()){return (new ALMA_Affiliate_Source_Importer())->import_item($normalized_item,$source);} }
