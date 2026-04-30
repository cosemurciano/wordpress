<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Provider_Client_Factory {
    const PROVIDER_CUSTOM_API = 'custom_api';
    const PROVIDER_VIATOR = 'viator';

    public static function make($source) {
        $provider = self::resolve_provider_key($source);

        if ($provider === self::PROVIDER_CUSTOM_API) {
            return new ALMA_Affiliate_Source_Provider_Client_Custom_API();
        }

        if ($provider === self::PROVIDER_VIATOR) {
            return new ALMA_Affiliate_Source_Provider_Client_Viator();
        }

        return new ALMA_Affiliate_Source_Provider_Client_Fallback();
    }

    public static function resolve_provider_key($source) {
        $schema = ALMA_Affiliate_Source_Provider_Presets::get_schema();
        $valid_keys = array_keys($schema);

        $preset = sanitize_key($source['provider_preset'] ?? '');
        if ($preset !== '' && in_array($preset, $valid_keys, true)) {
            return $preset;
        }

        $provider = sanitize_key($source['provider'] ?? '');
        if ($provider !== '' && in_array($provider, $valid_keys, true)) {
            return $provider;
        }

        $aliased = self::apply_provider_aliases($provider, $valid_keys);
        if ($aliased !== '') {
            return $aliased;
        }

        return $provider;
    }

    private static function apply_provider_aliases($provider, $valid_keys) {
        if ($provider === '') {
            return '';
        }

        $aliases = array(
            'customapi' => self::PROVIDER_CUSTOM_API,
        );

        if (isset($aliases[$provider]) && in_array($aliases[$provider], $valid_keys, true)) {
            return $aliases[$provider];
        }

        $collapsed = str_replace('_', '', $provider);
        if ($collapsed !== $provider) {
            return '';
        }

        $matches = array();
        foreach ($valid_keys as $key) {
            if (str_replace('_', '', $key) === $provider) {
                $matches[] = $key;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return '';
    }
}
