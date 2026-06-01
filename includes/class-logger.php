<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Logger {
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    const LONG_TEXT_LIMIT = 2000;
    const LONG_TEXT_KEEP = 500;

    public static function debug($message, $context = array()) { self::log(self::LEVEL_DEBUG, $message, $context); }
    public static function info($message, $context = array()) { self::log(self::LEVEL_INFO, $message, $context); }
    public static function warning($message, $context = array()) { self::log(self::LEVEL_WARNING, $message, $context); }
    public static function error($message, $context = array()) { self::log(self::LEVEL_ERROR, $message, $context); }

    public static function log($level, $message, $context = array()) {
        $level = self::normalize_level($level);
        if ($level === self::LEVEL_DEBUG && !(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        $message = self::redact((string) $message);
        $line = '[ALMA] [' . strtoupper($level) . '] ' . $message;
        if (!empty($context)) {
            $encoded = wp_json_encode(self::redact($context));
            if (is_string($encoded) && $encoded !== '') {
                $line .= ' | context=' . $encoded;
            }
        }
        error_log($line);
    }

    public static function redact($value, $key = '', $inside_long_ai_context = false) {
        $key = is_string($key) ? $key : '';
        $current_long_ai_context = $inside_long_ai_context || self::is_long_ai_value_key($key);

        if (is_array($value)) {
            $redacted = array();
            foreach ($value as $item_key => $item_value) {
                $item_key_string = is_string($item_key) ? $item_key : '';
                if (self::is_secret_key($item_key_string)) {
                    $redacted[$item_key] = '[redacted]';
                    continue;
                }
                $redacted[$item_key] = self::redact($item_value, $item_key_string, $current_long_ai_context);
            }
            return $redacted;
        }

        if (is_object($value)) {
            return self::redact(get_object_vars($value), $key, $current_long_ai_context);
        }

        if (is_string($value)) {
            $value = self::redact_string($value);
            if ($current_long_ai_context && strlen($value) > self::LONG_TEXT_LIMIT) {
                return substr($value, 0, self::LONG_TEXT_KEEP) . '… [truncated ' . strlen($value) . ' chars]';
            }
            return $value;
        }

        return $value;
    }

    private static function normalize_level($level) {
        $level = strtolower((string) $level);
        return in_array($level, array(self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR), true) ? $level : self::LEVEL_INFO;
    }

    private static function is_secret_key($key) {
        $key = strtolower((string) $key);
        if ($key === '') { return false; }
        return (bool) preg_match('/(^|[_\-])(api[_\-]?key|authorization|bearer|bearer[_\-]?token|access[_\-]?token|refresh[_\-]?token|auth[_\-]?token|secret|client[_\-]?secret|password)([_\-]|$)/', $key);
    }

    private static function is_long_ai_value_key($key) {
        $key = strtolower((string) $key);
        if ($key === '') { return false; }
        return (bool) preg_match('/(^|[_\-])(openai|payload|body|prompt|raw[_\-]?response|ai[_\-]?response|openai[_\-]?response|response|output|output[_\-]?text|input|messages|content)([_\-]|$)/', $key);
    }

    private static function redact_string($value) {
        $value = preg_replace('/(Authorization\s*[:=]\s*)Bearer\s+[A-Za-z0-9._\-]+/i', '$1Bearer [redacted]', $value);
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $value);
        $value = preg_replace('/("(?:api[_-]?key|authorization|bearer[_-]?token|access[_-]?token|refresh[_-]?token|auth[_-]?token|secret|client[_-]?secret|password)"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $value);
        $value = preg_replace_callback('/https?:\/\/[^\s"\'<>]+/i', function ($matches) {
            return ALMA_Logger::redact_url($matches[0]);
        }, $value);
        return $value;
    }

    private static function redact_url($url) {
        return preg_replace('/([?&](?:api[_-]?key|key|token|access[_-]?token|refresh[_-]?token|auth[_-]?token|bearer[_-]?token|signature|sig|client[_-]?secret)=)[^&#]*/i', '$1[redacted]', $url);
    }
}
