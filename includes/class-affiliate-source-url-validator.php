<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_URL_Validator {
    public static function validate($url) {
        $url = is_scalar($url) ? trim((string) $url) : '';
        if ($url === '') {
            return new WP_Error('missing_url', __('URL mancante.', 'affiliate-link-manager-ai'));
        }

        $url = esc_url_raw($url, array('http', 'https'));
        if ($url === '') {
            return new WP_Error('invalid_url', __('URL non valido.', 'affiliate-link-manager-ai'));
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return new WP_Error('invalid_scheme', __('Schema URL non valido.', 'affiliate-link-manager-ai'));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) {
            return new WP_Error('invalid_scheme', __('Sono consentiti solo URL http e https.', 'affiliate-link-manager-ai'));
        }

        if (empty($parts['host'])) {
            return new WP_Error('missing_host', __('Host URL mancante.', 'affiliate-link-manager-ai'));
        }

        $host = strtolower(trim((string) $parts['host'], "[] \t\n\r\0\x0B."));
        if ($host === '') {
            return new WP_Error('missing_host', __('Host URL mancante.', 'affiliate-link-manager-ai'));
        }

        if ($host === 'localhost' || substr($host, -10) === '.localhost') {
            return new WP_Error('blocked_localhost', __('Host localhost non consentito.', 'affiliate-link-manager-ai'));
        }

        $ip_error = self::validate_ip($host);
        if (is_wp_error($ip_error)) {
            return $ip_error;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $resolved = self::resolve_host_ips($host);
            foreach ($resolved as $ip) {
                $ip_error = self::validate_ip($ip);
                if (is_wp_error($ip_error)) {
                    return $ip_error;
                }
            }
        }

        return $url;
    }

    public static function request_args($args = array()) {
        $args = is_array($args) ? $args : array();
        $args['redirection'] = isset($args['redirection']) ? min(1, absint($args['redirection'])) : 1;
        $args['reject_unsafe_urls'] = true;
        return $args;
    }

    private static function validate_ip($host) {
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return new WP_Error('blocked_private_ip', __('IP privati, loopback e link-local non consentiti.', 'affiliate-link-manager-ai'));
        }

        return true;
    }

    private static function resolve_host_ips($host) {
        $ips = array();
        if (function_exists('gethostbynamel')) {
            $records = gethostbynamel($host);
            if (is_array($records)) {
                $ips = array_merge($ips, $records);
            }
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($ips)));
    }
}
