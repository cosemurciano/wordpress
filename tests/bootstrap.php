<?php
/**
 * Gli shim di WordPress per i test standalone.
 *
 * Il CHANGELOG di questo plugin cita conteggi di test da quarantanove
 * versioni — «Test standalone 51/51», «22/22», «28/28 ancora verdi» — e nel
 * repository non c'è mai stato un file di test: venivano scritti, eseguiti e
 * persi. Questa cartella è il posto dove smettere di perderli.
 *
 * I test girano senza WordPress: qui dentro c'è il minimo indispensabile
 * perché le classi a logica pura si possano caricare ed esercitare. Ogni
 * shim è dichiarato con `function_exists` così un test può anticiparne uno
 * proprio prima di includere questo file — **il primo dichiarato vince**.
 *
 * @package AffiliateLinkManagerAI
 */

if ('cli' !== PHP_SAPI) {
    exit;
}

// Le classi del plugin escono subito se non la trovano definita.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('ALMA_PLUGIN_DIR')) {
    define('ALMA_PLUGIN_DIR', dirname(__DIR__) . '/');
}

/** Archivio dei meta, pilotabile dai test. */
$GLOBALS['alma_test_meta']    = array();
$GLOBALS['alma_test_options'] = array();
$GLOBALS['alma_test_filters'] = array();

if (!function_exists('get_post_meta')) {
    /**
     * @param int    $post_id ID del post.
     * @param string $key     Chiave.
     * @param bool   $single  Valore singolo.
     * @return mixed
     */
    function get_post_meta($post_id, $key = '', $single = false) {
        $valori = isset($GLOBALS['alma_test_meta'][(int) $post_id][$key])
            ? $GLOBALS['alma_test_meta'][(int) $post_id][$key]
            : null;

        if (null === $valori) {
            return $single ? '' : array();
        }

        return $single ? $valori : array($valori);
    }
}

if (!function_exists('metadata_exists')) {
    /**
     * La funzione che distingue «campo scelto vuoto» da «campo mai scritto»,
     * cioè l'intero punto della correzione che questi test sorvegliano.
     *
     * @param string $type    Tipo di oggetto.
     * @param int    $id      ID.
     * @param string $key     Chiave.
     * @return bool
     */
    function metadata_exists($type, $id, $key) {
        return array_key_exists($key, (array) ($GLOBALS['alma_test_meta'][(int) $id] ?? array()));
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['alma_test_options'])
            ? $GLOBALS['alma_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['alma_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        if (isset($GLOBALS['alma_test_filters'][$hook]) && is_callable($GLOBALS['alma_test_filters'][$hook])) {
            $argomenti = func_get_args();
            array_shift($argomenti);

            return call_user_func_array($GLOBALS['alma_test_filters'][$hook], $argomenti);
        }

        return $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim(strip_tags((string) $text));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);

        return $remove_breaks ? trim(preg_replace('/[\r\n\t ]+/', ' ', $text)) : $text;
    }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return $text;
    }
}

/* -------------------------------------------------------------------------
 * Le asserzioni
 * ---------------------------------------------------------------------- */

$GLOBALS['alma_test_passed'] = 0;
$GLOBALS['alma_test_failed'] = 0;

/**
 * Due valori identici, `===`.
 *
 * @param mixed  $atteso   Valore atteso.
 * @param mixed  $ottenuto Valore ottenuto.
 * @param string $nome     Nome della prova.
 * @return void
 */
function alma_assert_same($atteso, $ottenuto, $nome) {
    if ($atteso === $ottenuto) {
        $GLOBALS['alma_test_passed']++;
        echo "  ok   $nome\n";

        return;
    }

    $GLOBALS['alma_test_failed']++;
    echo "  FAIL $nome\n";
    echo '       atteso:   ' . str_replace("\n", '', var_export($atteso, true)) . "\n";
    echo '       ottenuto: ' . str_replace("\n", '', var_export($ottenuto, true)) . "\n";
}

/**
 * Chiude il runner con il codice d'uscita giusto.
 *
 * @return void
 */
function alma_test_summary() {
    echo "\n" . str_repeat('-', 52) . "\n";
    printf("%d superati, %d falliti\n\n", $GLOBALS['alma_test_passed'], $GLOBALS['alma_test_failed']);

    exit($GLOBALS['alma_test_failed'] > 0 ? 1 : 0);
}

/**
 * Svuota l'archivio dei meta fra una prova e l'altra.
 *
 * @return void
 */
function alma_test_reset_meta() {
    $GLOBALS['alma_test_meta'] = array();
}
