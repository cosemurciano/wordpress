<?php
/**
 * Fase 7.2 — Schede località: fatti esterni agganciati al gazetteer geo.
 *
 * Principio: NON si importano le fonti esterne, si salvano solo le risposte
 * alle domande del sito. Ogni informazione è agganciata a una località già
 * presente nell'indice geografico del plugin (tabella alma_geo_locations):
 * una località che non riguarda il sito non genera mai una chiamata.
 *
 * Fonte attiva in questa versione: Open-Meteo (archivio ERA5, nessuna API
 * key). Non il meteo di domani ma il CLIMA: medie mensili degli ultimi anni
 * da cui derivare i "mesi migliori per visitare" — il dato utile all'agente
 * per stagionalità editoriale e coerenza dei consigli.
 *
 * Le risposte grezze non si salvano mai: al fetch vengono distillate in un
 * payload compatto in italiano (pochi KB) pronto per il prompt dell'agente.
 *
 * Riempimento: warmer giornaliero interrompibile (N località per run, lock
 * atomico via add_option con TTL, la condizione "scheda mancante o scaduta"
 * fa avanzare il lavoro da sola senza cursore) + fetch on-demand quando
 * l'agente chiede una località non ancora in cache.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Geo_Facts {
    const SOURCE_OPEN_METEO = 'open_meteo';
    const CRON_HOOK = 'alma_geo_facts_warm';
    const LOCK_OPTION = 'alma_geo_facts_lock';
    const LOCK_TTL = 300;
    const OPTION_ENABLED = 'alma_geo_facts_enabled';
    const OPTION_BATCH = 'alma_geo_facts_batch_size';
    const OPTION_LAST_REPORT = 'alma_geo_facts_last_report';
    const TTL_DAYS_OK = 270;   // clima ~statico: 9 mesi
    const TTL_DAYS_ERROR = 7;  // errore API: ritenta dopo una settimana
    const TIME_BUDGET = 60;    // secondi per run del warmer

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'warm_batch'));
        add_action('admin_post_alma_geo_facts_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_alma_geo_facts_run_now', array(__CLASS__, 'handle_run_now'));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // 05:00 locali circa: dopo geocoding (04:30) e prima dell'enricher (05:30).
            wp_schedule_event(strtotime('tomorrow 05:00') ?: time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ---------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'alma_geo_facts';
    }

    public static function create_table() {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(32) NOT NULL,
            payload LONGTEXT NULL,
            fetched_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY location_source (location_id, source),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Scheda salvata per località+fonte, null se assente o scaduta.
     */
    public static function get_fact($location_id, $source) {
        global $wpdb;
        if (!self::table_exists()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT payload, fetched_at, expires_at FROM " . self::table_name() . " WHERE location_id = %d AND source = %s AND expires_at > %s LIMIT 1",
            absint($location_id), sanitize_key($source), current_time('mysql')
        ), ARRAY_A);
        if (!$row) { return null; }
        $payload = json_decode((string) $row['payload'], true);
        return is_array($payload) ? $payload : null;
    }

    public static function save_fact($location_id, $source, $payload, $ttl_days) {
        global $wpdb;
        if (!self::table_exists()) { return false; }
        $now = current_time('mysql');
        $expires = gmdate('Y-m-d H:i:s', current_time('timestamp') + absint($ttl_days) * DAY_IN_SECONDS);
        return false !== $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table_name() . " (location_id, source, payload, fetched_at, expires_at)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)",
            absint($location_id), sanitize_key($source), wp_json_encode($payload), $now, $expires
        ));
    }

    /* ---------------------------------------------------------------------
     * Open-Meteo: clima mensile → mesi migliori
     * ------------------------------------------------------------------ */

    /**
     * Scarica dall'archivio Open-Meteo (ERA5) i dati giornalieri degli
     * ultimi 3 anni completi e li aggrega in medie mensili.
     */
    public static function fetch_open_meteo($lat, $lng) {
        $end_year = (int) gmdate('Y') - 1;
        $start_year = $end_year - 2;
        $url = add_query_arg(array(
            'latitude' => round((float) $lat, 4),
            'longitude' => round((float) $lng, 4),
            'start_date' => $start_year . '-01-01',
            'end_date' => $end_year . '-12-31',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => 'auto',
        ), 'https://archive-api.open-meteo.com/v1/archive');
        $response = wp_remote_get($url, array(
            'timeout' => 25,
            'user-agent' => 'AffiliateLinkManagerAI/' . ALMA_VERSION . ' (WordPress; ' . home_url('/') . ')',
        ));
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['daily']['time'])) {
            $detail = is_array($data) ? sanitize_text_field((string)($data['reason'] ?? '')) : '';
            return new WP_Error('alma_open_meteo', sprintf(__('Open-Meteo: risposta non valida (HTTP %d). %s', 'affiliate-link-manager-ai'), $code, $detail));
        }
        $payload = self::aggregate_climate(
            (array) $data['daily']['time'],
            (array) ($data['daily']['temperature_2m_max'] ?? array()),
            (array) ($data['daily']['temperature_2m_min'] ?? array()),
            (array) ($data['daily']['precipitation_sum'] ?? array())
        );
        if (!$payload) {
            return new WP_Error('alma_open_meteo', __('Open-Meteo: dati giornalieri insufficienti per l\'aggregazione.', 'affiliate-link-manager-ai'));
        }
        $payload['fonte'] = 'open-meteo.com (archivio ERA5, medie ' . $start_year . '-' . $end_year . ')';
        return $payload;
    }

    /**
     * Aggregazione pura (testabile senza WordPress): serie giornaliere →
     * 12 medie mensili + mesi migliori/da evitare + sintesi in italiano.
     */
    public static function aggregate_climate($dates, $tmax_series, $tmin_series, $precip_series) {
        $months = array_fill(1, 12, array('tmax_sum' => 0.0, 'tmin_sum' => 0.0, 'tmax_n' => 0, 'tmin_n' => 0, 'precip_sum' => 0.0, 'rain_days' => 0, 'days' => 0, 'years' => array()));
        foreach ($dates as $i => $date) {
            $month = (int) substr((string) $date, 5, 2);
            $year = (int) substr((string) $date, 0, 4);
            if ($month < 1 || $month > 12) { continue; }
            $months[$month]['days']++;
            $months[$month]['years'][$year] = true;
            if (isset($tmax_series[$i]) && $tmax_series[$i] !== null) { $months[$month]['tmax_sum'] += (float) $tmax_series[$i]; $months[$month]['tmax_n']++; }
            if (isset($tmin_series[$i]) && $tmin_series[$i] !== null) { $months[$month]['tmin_sum'] += (float) $tmin_series[$i]; $months[$month]['tmin_n']++; }
            if (isset($precip_series[$i]) && $precip_series[$i] !== null) {
                $months[$month]['precip_sum'] += (float) $precip_series[$i];
                if ((float) $precip_series[$i] >= 1.0) { $months[$month]['rain_days']++; }
            }
        }
        $names = array(1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre');
        $out_months = array();
        $scores = array();
        foreach ($months as $m => $acc) {
            if ($acc['tmax_n'] === 0 || $acc['days'] === 0) { return null; }
            $n_years = max(1, count($acc['years']));
            $t_max = round($acc['tmax_sum'] / $acc['tmax_n'], 1);
            $t_min = $acc['tmin_n'] > 0 ? round($acc['tmin_sum'] / $acc['tmin_n'], 1) : null;
            $precip = round($acc['precip_sum'] / $n_years);
            $rain_days = (int) round($acc['rain_days'] / $n_years);
            $out_months[] = array('mese' => $names[$m], 't_min' => $t_min, 't_max' => $t_max, 'pioggia_mm' => $precip, 'giorni_pioggia' => $rain_days);
            // Comfort di visita: massime vicine a 23°C e pochi giorni di pioggia.
            $scores[$m] = -abs($t_max - 23) * 1.5 - $rain_days * 0.8;
        }
        arsort($scores);
        $ranked = array_keys($scores);
        $best = array_slice($ranked, 0, 4);
        sort($best);
        $worst = array();
        foreach ($months as $m => $acc) {
            $t_max = round($acc['tmax_sum'] / max(1, $acc['tmax_n']), 1);
            $rain_days = (int) round($acc['rain_days'] / max(1, count($acc['years'])));
            if ($t_max < 8 || $t_max > 33 || $rain_days > 14) { $worst[] = $m; }
        }
        $best_labels = array_map(function ($m) use ($names) { return $names[$m]; }, $best);
        $worst_labels = array_map(function ($m) use ($names) { return $names[$m]; }, $worst);
        return array(
            'mesi' => $out_months,
            'mesi_migliori' => implode(', ', $best_labels),
            'mesi_da_evitare' => implode(', ', $worst_labels),
            'sintesi' => 'Mesi migliori per visitare: ' . implode(', ', $best_labels) . '.'
                . ($worst_labels ? ' Mesi sconsigliati (troppo freddi, torridi o piovosi): ' . implode(', ', $worst_labels) . '.' : ''),
        );
    }

    /* ---------------------------------------------------------------------
     * Warmer in background
     * ------------------------------------------------------------------ */

    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, 'yes') === 'yes';
    }

    public static function get_batch_size() {
        return max(1, min(50, absint(get_option(self::OPTION_BATCH, 10))));
    }

    private static function acquire_lock() {
        if (add_option(self::LOCK_OPTION, (string) time(), '', 'no')) { return true; }
        $started = absint(get_option(self::LOCK_OPTION, 0));
        if ($started > 0 && (time() - $started) > self::LOCK_TTL) {
            update_option(self::LOCK_OPTION, (string) time(), false);
            return true;
        }
        return false;
    }

    /**
     * Località con coordinate ma senza scheda clima valida, le più usate
     * nei contenuti per prime. La condizione "senza scheda valida" fa
     * avanzare il lavoro da sola: ogni run riparte da dove si era fermato.
     */
    public static function pending_locations($limit) {
        global $wpdb;
        if (!class_exists('ALMA_Geo_Index_Store') || !self::table_exists()) { return array(); }
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) { return array(); }
        $locations = $store->table_locations();
        $content_index = $store->table_content_index();
        $facts = self::table_name();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.canonical_name, l.lat, l.lng,
                    (SELECT COUNT(*) FROM {$content_index} ci WHERE ci.location_id = l.id) AS usi
             FROM {$locations} l
             WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$facts} f
                   WHERE f.location_id = l.id AND f.source = %s AND f.expires_at > %s
               )
             ORDER BY usi DESC, l.id ASC
             LIMIT %d",
            self::SOURCE_OPEN_METEO, current_time('mysql'), max(1, absint($limit))
        ), ARRAY_A);
    }

    public static function pending_count() {
        global $wpdb;
        if (!class_exists('ALMA_Geo_Index_Store') || !self::table_exists()) { return 0; }
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) { return 0; }
        $locations = $store->table_locations();
        $facts = self::table_name();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$locations} l
             WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$facts} f
                   WHERE f.location_id = l.id AND f.source = %s AND f.expires_at > %s
               )",
            self::SOURCE_OPEN_METEO, current_time('mysql')
        ));
    }

    public static function ready_count() {
        global $wpdb;
        if (!self::table_exists()) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE source = %s AND expires_at > %s AND payload NOT LIKE %s",
            self::SOURCE_OPEN_METEO, current_time('mysql'), '%"errore"%'
        ));
    }

    public static function warm_batch() {
        if (!self::is_enabled() || !self::acquire_lock()) { return; }
        $started_at = time();
        $ok = 0;
        $errors = 0;
        $processed = 0;
        try {
            $pending = self::pending_locations(self::get_batch_size());
            foreach ($pending as $location) {
                if ((time() - $started_at) > self::TIME_BUDGET) { break; }
                $processed++;
                $result = self::fetch_open_meteo($location['lat'], $location['lng']);
                if (is_wp_error($result)) {
                    // Marcatore d'errore con TTL breve: niente martellamenti,
                    // si ritenta dopo una settimana.
                    self::save_fact((int) $location['id'], self::SOURCE_OPEN_METEO, array('errore' => sanitize_text_field($result->get_error_message())), self::TTL_DAYS_ERROR);
                    $errors++;
                } else {
                    self::save_fact((int) $location['id'], self::SOURCE_OPEN_METEO, $result, self::TTL_DAYS_OK);
                    $ok++;
                }
                usleep(500000); // mezzo secondo tra le chiamate: cortesia verso l'API gratuita
            }
            update_option(self::OPTION_LAST_REPORT, array(
                'time' => current_time('mysql'),
                'processed' => $processed,
                'ok' => $ok,
                'errors' => $errors,
                'remaining' => self::pending_count(),
            ), false);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    /* ---------------------------------------------------------------------
     * Scheda per l'agente
     * ------------------------------------------------------------------ */

    /**
     * Payload del tool scheda_localita: risolve il nome sul gazetteer,
     * unisce clima (con fetch on-demand se manca) e dati interni del sito.
     */
    public static function agent_payload($name) {
        global $wpdb;
        $name = trim((string) $name);
        if ($name === '') { return array('error' => 'Nome località mancante.'); }
        if (!class_exists('ALMA_Geo_Index_Store')) { return array('error' => 'Indice geografico non disponibile.'); }
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) { return array('error' => 'Indice geografico non disponibile.'); }
        $locations = $store->table_locations();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_name, type, country, region, lat, lng FROM {$locations} WHERE LOWER(canonical_name) = LOWER(%s) ORDER BY id ASC LIMIT 1",
            $name
        ), ARRAY_A);
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_name, type, country, region, lat, lng FROM {$locations} WHERE canonical_name LIKE %s ORDER BY CHAR_LENGTH(canonical_name) ASC LIMIT 1",
                $wpdb->esc_like($name) . '%'
            ), ARRAY_A);
        }
        if (!$row) {
            return array('error' => 'Località "' . $name . '" non presente nell\'indice geografico del sito.');
        }
        $location_id = (int) $row['id'];

        $out = array(
            'localita' => html_entity_decode(sanitize_text_field($row['canonical_name']), ENT_QUOTES, 'UTF-8'),
            'tipo' => sanitize_text_field((string) $row['type']),
            'paese' => sanitize_text_field((string) $row['country']),
            'regione' => sanitize_text_field((string) $row['region']),
        );

        // Clima: cache prima, altrimenti fetch on-demand (mai bloccante).
        $clima = self::get_fact($location_id, self::SOURCE_OPEN_METEO);
        if (!$clima && $row['lat'] !== null && $row['lng'] !== null) {
            $fetched = self::fetch_open_meteo($row['lat'], $row['lng']);
            if (!is_wp_error($fetched)) {
                self::save_fact($location_id, self::SOURCE_OPEN_METEO, $fetched, self::TTL_DAYS_OK);
                $clima = $fetched;
            }
        }
        if (is_array($clima) && empty($clima['errore'])) {
            $out['clima'] = array(
                'mesi_migliori' => $clima['mesi_migliori'] ?? '',
                'mesi_da_evitare' => $clima['mesi_da_evitare'] ?? '',
                'sintesi' => $clima['sintesi'] ?? '',
                'mesi' => $clima['mesi'] ?? array(),
                'fonte' => $clima['fonte'] ?? '',
            );
        } else {
            $out['clima'] = 'non disponibile';
        }

        // Dati interni: quanti asset del sito riguardano già la zona.
        $link_ids = method_exists($store, 'get_affiliate_link_ids_for_area') ? (array) $store->get_affiliate_link_ids_for_area($location_id) : array();
        $link_esempi = array();
        foreach (array_slice($link_ids, 0, 5) as $link_id) {
            $title = get_the_title($link_id);
            if ($title !== '') { $link_esempi[] = array('id' => (int) $link_id, 'titolo' => html_entity_decode($title, ENT_QUOTES, 'UTF-8')); }
        }
        $content_index = $store->table_content_index();
        $articoli = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT object_id) FROM {$content_index} WHERE location_id = %d AND object_type = 'post'",
            $location_id
        ));
        $out['dati_interni'] = array(
            'link_affiliati_zona' => count($link_ids),
            'esempi_link' => $link_esempi,
            'articoli_pubblicati_zona' => $articoli,
        );
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Azioni admin + tab impostazioni
     * ------------------------------------------------------------------ */

    private static function redirect_back($type, $message) {
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=schede-localita'));
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_geo_facts_admin');
        update_option(self::OPTION_ENABLED, empty($_POST[self::OPTION_ENABLED]) ? 'no' : 'yes', false);
        update_option(self::OPTION_BATCH, max(1, min(50, absint($_POST[self::OPTION_BATCH] ?? 10))), false);
        self::redirect_back('success', 'Impostazioni schede località salvate.');
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_geo_facts_admin');
        if (get_option(self::LOCK_OPTION)) {
            self::redirect_back('error', 'Un aggiornamento delle schede è già in corso.');
        }
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        if (function_exists('spawn_cron')) { spawn_cron(); }
        self::redirect_back('success', 'Aggiornamento schede avviato in background: ricarica la pagina tra qualche istante per vedere il report.');
    }

    public static function render_settings_tab() {
        $ready = self::ready_count();
        $pending = self::pending_count();
        $report = get_option(self::OPTION_LAST_REPORT, null);
        $running = (bool) get_option(self::LOCK_OPTION);

        echo '<h2>Schede località (fonti esterne)</h2>';
        echo '<div class="alma-agent-card" style="max-width:900px;"><h3>Come funziona</h3>';
        echo '<p>Per ogni località dell\'indice geografico con coordinate, il plugin costruisce una <strong>scheda</strong> con dati da fonti esterne — oggi il <strong>clima</strong> da Open-Meteo (medie mensili storiche, gratuito, senza API key): mesi migliori per visitare, mesi da evitare, temperature e piogge mese per mese. Non si importano interi dataset: si salva solo la scheda compatta, già in italiano, riusata dall\'agente AI con lo strumento <code>scheda_localita</code> per proporre idee con la stagionalità giusta.</p>';
        echo '<p>Le schede si riempiono da sole con un job notturno (poche località per volta, interrompibile) e restano valide 9 mesi; se l\'agente chiede una località non ancora pronta, la scheda viene creata al volo.</p></div>';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Stato</th><td>';
        echo '<span class="alma-badge ' . ($pending === 0 ? 'is-success' : 'is-warning') . '">' . esc_html($ready) . ' schede pronte</span> ';
        echo esc_html($pending) . ' località in attesa' . ($running ? ' — <strong>aggiornamento in corso…</strong>' : '');
        if (is_array($report)) {
            echo '<p class="description">Ultimo run: ' . esc_html((string) $report['time']) . ' — elaborate ' . esc_html((string) $report['processed']) . ', ok ' . esc_html((string) $report['ok']) . ', errori ' . esc_html((string) $report['errors']) . ', rimanenti ' . esc_html((string) $report['remaining']) . '.</p>';
        }
        echo '</td></tr></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_geo_facts_admin');
        echo '<input type="hidden" name="action" value="alma_geo_facts_settings">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Riempimento automatico</th><td><label><input type="checkbox" name="' . esc_attr(self::OPTION_ENABLED) . '" value="yes"' . checked(self::is_enabled(), true, false) . '> Attivo (job notturno giornaliero)</label></td></tr>';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_BATCH) . '">Località per run</label></th><td><input type="number" min="1" max="50" name="' . esc_attr(self::OPTION_BATCH) . '" id="' . esc_attr(self::OPTION_BATCH) . '" value="' . esc_attr((string) self::get_batch_size()) . '" class="small-text"><p class="description">Quante località elaborare a ogni esecuzione (default 10). Le località più usate nei contenuti hanno la priorità.</p></td></tr>';
        echo '</table><p><button class="button button-primary">Salva</button></p></form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:4px;">';
        wp_nonce_field('alma_geo_facts_admin');
        echo '<input type="hidden" name="action" value="alma_geo_facts_run_now"><button class="button"' . disabled($running, true, false) . '>Esegui ora un run</button></form>';
    }
}
