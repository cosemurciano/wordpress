<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_Affiliate_Source_Provider_Generic_API extends ALMA_Affiliate_Source_Provider_Manual {
public function get_provider_id(){return 'generic_api';}
public function get_label(){return __('Generic API','affiliate-link-manager-ai');}
public function test_connection($settings){$endpoint=esc_url_raw($settings['endpoint']??''); if(!$endpoint){return new WP_Error('missing_endpoint',__('Endpoint mancante','affiliate-link-manager-ai'));} $res=wp_remote_request($endpoint,array('method'=>sanitize_text_field($settings['method']??'GET'),'timeout'=>15)); if(is_wp_error($res)){return $res;} return array('success'=>true,'message'=>__('Connessione riuscita','affiliate-link-manager-ai'));}
public function fetch_items($source,$args=array()){return array();}
}
