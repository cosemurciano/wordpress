<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Registry {
    private $providers = array();

    public function register_provider(ALMA_Affiliate_Source_Provider_Interface $provider) {
        $this->providers[$provider->get_provider_id()] = $provider;
    }

    public function get_provider($provider_id) {
        return isset($this->providers[$provider_id]) ? $this->providers[$provider_id] : null;
    }

    public function get_providers() {
        return $this->providers;
    }

    public function bootstrap_native_providers() {
        $this->register_provider(new ALMA_Affiliate_Source_Provider_Manual());
        $this->register_provider(new ALMA_Affiliate_Source_Provider_CSV());
        $this->register_provider(new ALMA_Affiliate_Source_Provider_Custom_API());
        $this->register_provider(new ALMA_Affiliate_Source_Provider_Generic_API());

        $extra_providers = apply_filters('alma_affiliate_source_providers', array());
        if (is_array($extra_providers)) {
            foreach ($extra_providers as $provider) {
                if ($provider instanceof ALMA_Affiliate_Source_Provider_Interface) {
                    $this->register_provider($provider);
                }
            }
        }
    }
}
