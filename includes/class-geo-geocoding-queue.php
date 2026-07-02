<?php
/**
 * Coda di geocoding automatico.
 *
 * Elimina il doppio passaggio "associa località → poi lancia il geocoding":
 * ogni volta che una località viene associata (metabox, import CSV/API,
 * auto-indexer, creazione post/link) e resta in stato `pending`, viene
 * schedulato un drain asincrono via WP-Cron che geocodifica le località
 * pending a piccoli lotti finché la coda non è vuota.
 *
 * Non è un job invisibile: stato, ultimo report e prossima esecuzione sono
 * mostrati nella tab Panoramica dell'Indice Geografico, e l'automatismo è
 * disattivabile dalle impostazioni (option `alma_geo_auto_geocoding`).
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Geo_Geocoding_Queue {
    const CRON_HOOK = 'alma_geo_drain_geocoding_queue';
    const ENABLED_OPTION = 'alma_geo_auto_geocoding';
    const LAST_RUN_OPTION = 'alma_geo_auto_geocoding_last_run';
    const BATCH_SIZE = 20;
    const RESCHEDULE_DELAY = 60;        // coda ancora piena → riparti presto
    const RATE_LIMIT_DELAY = 300;       // quota/rate limit → attendi 5 minuti

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'drain'));
        // Watchdog: se restano località pending senza un drain in programma
        // (evento cron perso, sito a basso traffico), lo riarma a ogni visita
        // admin, con throttle per non pesare sulle pageview.
        add_action('admin_init', array(__CLASS__, 'watchdog'));
    }

    public static function watchdog() {
        if (get_transient('alma_geo_queue_watchdog')) {
            return;
        }
        set_transient('alma_geo_queue_watchdog', 1, 2 * MINUTE_IN_SECONDS);
        if (!self::is_enabled()) {
            return;
        }
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            // Evento in ritardo (cron non partito): forza lo spawn.
            if ($next <= time()) {
                self::spawn_now_on_shutdown();
            }
            return;
        }
        if (self::count_pending() > 0) {
            self::maybe_schedule();
        }
    }

    public static function is_enabled() {
        if (get_option(self::ENABLED_OPTION, 'yes') !== 'yes') {
            return false;
        }
        return trim((string) get_option('alma_geo_google_maps_api_key', '')) !== '';
    }

    /**
     * Schedula un drain a breve se non già in programma. Chiamata dal punto
     * unico di associazione località (store) e quindi coprente metabox,
     * import massivi CSV, import API, auto-indexer e creazione contenuti.
     */
    public static function maybe_schedule($delay = 0) {
        if (!self::is_enabled()) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // L'evento nasce già scaduto: così lo spawn a fine richiesta lo
            // esegue subito. Senza spawn immediato partirebbe solo alla
            // prossima pageview e su siti a basso traffico le località
            // restavano "In attesa di geocoding" a tempo indefinito.
            $delay = absint($delay);
            wp_schedule_single_event($delay > 0 ? time() + $delay : time() - 1, self::CRON_HOOK);
            if ($delay === 0) {
                self::spawn_now_on_shutdown();
            }
        }
    }

    /**
     * Esegue il cron a fine richiesta senza bloccarla (loopback non bloccante
     * di WordPress). spawn_cron processa solo eventi già scaduti.
     */
    private static function spawn_now_on_shutdown() {
        static $registered = false;
        if ($registered) {
            return;
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return; // Il sito usa un cron di sistema: ci pensa lui.
        }
        if (!function_exists('spawn_cron')) {
            return;
        }
        $registered = true;
        // Su shutdown: lo spawn avviene dopo che l'evento è stato scritto e
        // non allunga la logica della richiesta corrente.
        add_action('shutdown', function () {
            spawn_cron();
        }, 999);
    }

    public static function next_run_timestamp() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        return $timestamp ? (int) $timestamp : 0;
    }

    public static function get_last_run_report() {
        $report = get_option(self::LAST_RUN_OPTION, array());
        return is_array($report) ? $report : array();
    }

    /**
     * Handler cron: geocodifica un lotto di località pending e si ri-schedula
     * finché la coda non è vuota. Riusa il lock del geocoder, quindi non può
     * sovrapporsi ai batch manuali lanciati dall'admin.
     */
    public static function drain($force = false) {
        // $force: azione manuale "Geocodifica ora" — richiede solo la API key,
        // funziona anche con l'automatismo disattivato.
        if (!$force && !self::is_enabled()) {
            return;
        }
        if ($force && trim((string) get_option('alma_geo_google_maps_api_key', '')) === '') {
            return;
        }
        $geocoder = new ALMA_Geo_Index_Geocoder();
        $report = $geocoder->geocode_batch(self::BATCH_SIZE, 'pending');
        $report['ran_at'] = current_time('mysql');
        $report['trigger'] = 'auto';
        update_option(self::LAST_RUN_OPTION, $report, false);

        if (!empty($report['locked'])) {
            // Un batch manuale è in corso: riprova più tardi senza rumore.
            self::reschedule(self::RATE_LIMIT_DELAY);
            return;
        }
        if (!empty($report['configuration_error'])) {
            // API key non valida / API non abilitata: fermarsi, l'errore è
            // permanente e visibile in Panoramica. Ripartirà al prossimo
            // salvataggio di impostazioni o associazione.
            if (class_exists('ALMA_Logger')) {
                ALMA_Logger::warning('Auto geocoding interrotto: REQUEST_DENIED (configurazione).', array('report' => array('processed' => (int) ($report['processed'] ?? 0))));
            }
            return;
        }

        $pending = self::count_pending();
        if ($pending > 0) {
            $rate_limited = !empty($report['errors']) && self::report_mentions_rate_limit($report);
            self::reschedule($rate_limited ? self::RATE_LIMIT_DELAY : self::RESCHEDULE_DELAY);
        }
    }

    public static function count_pending() {
        global $wpdb;
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$store->table_locations()} WHERE geocoding_status = %s",
            'pending'
        ));
    }

    private static function report_mentions_rate_limit($report) {
        foreach ((array) ($report['errors'] ?? array()) as $error) {
            $message = strtolower((string) (is_array($error) ? ($error['message'] ?? '') : $error));
            if (strpos($message, 'quota') !== false || strpos($message, 'rate limit') !== false || strpos($message, 'over_query_limit') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function reschedule($delay) {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(5, absint($delay)), self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
