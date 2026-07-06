<?php
/**
 * Fase 7.2 — Schede località: fatti esterni agganciati al gazetteer geo.
 *
 * Principio: NON si importano le fonti esterne, si salvano solo le risposte
 * alle domande del sito. Ogni informazione è agganciata a una località già
 * presente nell'indice geografico del plugin (tabella alma_geo_locations):
 * una località che non riguarda il sito non genera mai una chiamata.
 *
 * Fonti attive:
 * - Open-Meteo (archivio ERA5, nessuna API key): non il meteo di domani ma
 *   il CLIMA — medie mensili degli ultimi anni da cui derivare i "mesi
 *   migliori per visitare", per stagionalità editoriale e coerenza consigli;
 * - Wikidata (wbsearchentities + SPARQL, nessuna API key): la "carta
 *   d'identità" della località — descrizione, popolazione, paese, UNESCO,
 *   Wikipedia italiana e attrazioni notevoli nel raggio di 10 km. La
 *   disambiguazione tra omonimi avviene per prossimità alle coordinate
 *   già geocodificate del gazetteer.
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
    const SOURCE_WIKIDATA = 'wikidata';
    const SOURCE_GOOGLE_TRENDS = 'google_trends';
    const CRON_HOOK = 'alma_geo_facts_warm';
    const LOCK_OPTION = 'alma_geo_facts_lock';
    const LOCK_TTL = 300;
    const OPTION_ENABLED = 'alma_geo_facts_enabled';
    const OPTION_BATCH = 'alma_geo_facts_batch_size';
    const OPTION_LAST_REPORT = 'alma_geo_facts_last_report';
    const TTL_DAYS_OK = 270;        // clima ~statico: 9 mesi
    const TTL_DAYS_WIKIDATA = 180;  // fatti anagrafici: 6 mesi
    const TTL_DAYS_TRENDS = 30;     // tendenze di ricerca: 1 mese
    const TTL_DAYS_ERROR = 7;       // errore API: ritenta dopo una settimana
    const TIME_BUDGET = 60;         // guardia totale per invocazione (hosting condiviso)
    const SOURCE_TIME_BUDGET = 20;  // budget PER FONTE: ogni fonte ha il suo turno garantito
    const CHAIN_DELAY = 90;         // secondi tra un run in catena e il successivo
    const MAX_CHAINS_PER_DAY = 30;  // run di recupero auto-programmati al giorno
    const OPTION_CHAIN_COUNTER = 'alma_geo_facts_chain_counter';
    const WIKIDATA_MATCH_KM = 100;  // distanza massima nome↔coordinate per accettare l'entità

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
     * Wikidata: carta d'identità della località + attrazioni vicine
     * ------------------------------------------------------------------ */

    /**
     * Costruisce la scheda Wikidata di una località: cerca l'entità per
     * nome, la disambigua per prossimità alle coordinate del gazetteer,
     * legge i fatti via SPARQL e le attrazioni notevoli entro 10 km.
     */
    public static function fetch_wikidata($name, $lat, $lng) {
        $candidates = self::wikidata_search($name);
        if (is_wp_error($candidates)) { return $candidates; }
        if (empty($candidates)) {
            return new WP_Error('alma_wikidata', sprintf(__('Wikidata: nessuna entità trovata per "%s".', 'affiliate-link-manager-ai'), $name));
        }
        $details = self::wikidata_entity_details(array_slice(wp_list_pluck($candidates, 'id'), 0, 5));
        if (is_wp_error($details)) { return $details; }
        $entity = self::pick_wikidata_candidate($details, $lat, $lng);
        if (!$entity) {
            return new WP_Error('alma_wikidata', sprintf(__('Wikidata: nessun candidato per "%s" entro %d km dalle coordinate note.', 'affiliate-link-manager-ai'), $name, self::WIKIDATA_MATCH_KM));
        }
        $attractions = array();
        if ($lat !== null && $lng !== null) {
            $found = self::wikidata_attractions($lat, $lng);
            if (!is_wp_error($found)) { $attractions = $found; }
        }
        return self::build_wikidata_payload($entity, $attractions);
    }

    /**
     * Ricerca entità per nome (API wbsearchentities, lingua italiana).
     */
    public static function wikidata_search($name) {
        $url = add_query_arg(array(
            'action' => 'wbsearchentities',
            'search' => rawurlencode($name), // add_query_arg non codifica i valori
            'language' => 'it',
            'uselang' => 'it',
            'type' => 'item',
            'limit' => 5,
            'format' => 'json',
        ), 'https://www.wikidata.org/w/api.php');
        $response = wp_remote_get($url, array('timeout' => 15, 'user-agent' => self::wikidata_user_agent()));
        if (is_wp_error($response)) { return $response; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['search'])) {
            return new WP_Error('alma_wikidata', __('Wikidata: risposta di ricerca non valida.', 'affiliate-link-manager-ai'));
        }
        $out = array();
        foreach ((array) $data['search'] as $hit) {
            $id = sanitize_text_field((string) ($hit['id'] ?? ''));
            if (preg_match('/^Q\d+$/', $id)) {
                $out[] = array('id' => $id, 'label' => sanitize_text_field((string) ($hit['label'] ?? '')));
            }
        }
        return $out;
    }

    /**
     * Dettagli dei candidati via SPARQL: coordinate, popolazione, paese,
     * designazione patrimonio, altitudine, descrizione, Wikipedia it.
     */
    public static function wikidata_entity_details($qids) {
        $values = implode(' ', array_map(function ($q) { return 'wd:' . $q; }, $qids));
        $query = 'SELECT ?item ?itemDescription ?coord ?population ?elevation ?countryLabel ?heritageLabel ?article WHERE {'
            . ' VALUES ?item { ' . $values . ' }'
            . ' OPTIONAL { ?item wdt:P625 ?coord . }'
            . ' OPTIONAL { ?item wdt:P1082 ?population . }'
            . ' OPTIONAL { ?item wdt:P2044 ?elevation . }'
            . ' OPTIONAL { ?item wdt:P17 ?country . }'
            . ' OPTIONAL { ?item wdt:P1435 ?heritage . }'
            . ' OPTIONAL { ?article schema:about ?item ; schema:isPartOf <https://it.wikipedia.org/> . }'
            . ' SERVICE wikibase:label { bd:serviceParam wikibase:language "it,en". } }';
        $rows = self::wikidata_sparql($query);
        if (is_wp_error($rows)) { return $rows; }
        // Più righe per entità (es. più designazioni patrimonio): si aggregano.
        $entities = array();
        foreach ($rows as $row) {
            $qid = preg_replace('#^.*/#', '', (string) self::sparql_value($row, 'item'));
            if (!preg_match('/^Q\d+$/', $qid)) { continue; }
            if (!isset($entities[$qid])) {
                $coord = self::parse_wkt_point((string) self::sparql_value($row, 'coord'));
                $entities[$qid] = array(
                    'id' => $qid,
                    'descrizione' => sanitize_text_field((string) self::sparql_value($row, 'itemDescription')),
                    'lat' => $coord ? $coord['lat'] : null,
                    'lng' => $coord ? $coord['lng'] : null,
                    'popolazione' => absint(self::sparql_value($row, 'population')),
                    'altitudine_m' => self::sparql_value($row, 'elevation') !== '' ? (int) round((float) self::sparql_value($row, 'elevation')) : null,
                    'paese' => sanitize_text_field((string) self::sparql_value($row, 'countryLabel')),
                    'patrimonio' => array(),
                    'wikipedia_it' => esc_url_raw((string) self::sparql_value($row, 'article')),
                );
            }
            $heritage = sanitize_text_field((string) self::sparql_value($row, 'heritageLabel'));
            if ($heritage !== '' && !in_array($heritage, $entities[$qid]['patrimonio'], true)) {
                $entities[$qid]['patrimonio'][] = $heritage;
            }
        }
        return array_values($entities);
    }

    /**
     * Attrazioni notevoli entro 10 km, ordinate per notorietà (numero di
     * sitelink): tipologie fisse senza ricorsione P279* per restare rapidi.
     */
    public static function wikidata_attractions($lat, $lng) {
        // museo, chiesa, castello, sito archeologico, palazzo, cattedrale,
        // attrazione turistica, parco nazionale, parco urbano, basilica.
        $query = 'SELECT DISTINCT ?poi ?poiLabel ?links WHERE {'
            . ' SERVICE wikibase:around { ?poi wdt:P625 ?loc . bd:serviceParam wikibase:center "Point(' . round((float) $lng, 4) . ' ' . round((float) $lat, 4) . ')"^^geo:wktLiteral . bd:serviceParam wikibase:radius "10" . }'
            . ' VALUES ?class { wd:Q33506 wd:Q16970 wd:Q23413 wd:Q839954 wd:Q16560 wd:Q2977 wd:Q570116 wd:Q46169 wd:Q22698 wd:Q163687 }'
            . ' ?poi wdt:P31 ?class . ?poi wikibase:sitelinks ?links . FILTER(?links >= 5)'
            . ' SERVICE wikibase:label { bd:serviceParam wikibase:language "it,en". } }'
            . ' ORDER BY DESC(?links) LIMIT 8';
        $rows = self::wikidata_sparql($query);
        if (is_wp_error($rows)) { return $rows; }
        $out = array();
        foreach ($rows as $row) {
            $label = sanitize_text_field((string) self::sparql_value($row, 'poiLabel'));
            // Scarta le entità senza etichetta leggibile (label = QID).
            if ($label !== '' && !preg_match('/^Q\d+$/', $label) && !in_array($label, $out, true)) {
                $out[] = $label;
            }
        }
        return $out;
    }

    public static function wikidata_sparql($query) {
        $response = wp_remote_get(add_query_arg(array('query' => rawurlencode($query), 'format' => 'json'), 'https://query.wikidata.org/sparql'), array(
            'timeout' => 25,
            'user-agent' => self::wikidata_user_agent(),
            'headers' => array('Accept' => 'application/sparql-results+json'),
        ));
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data) || !isset($data['results']['bindings'])) {
            return new WP_Error('alma_wikidata', sprintf(__('Wikidata SPARQL: risposta non valida (HTTP %d).', 'affiliate-link-manager-ai'), $code));
        }
        return (array) $data['results']['bindings'];
    }

    private static function wikidata_user_agent() {
        // La policy WDQS richiede uno User-Agent identificabile con contatto.
        return 'AffiliateLinkManagerAI/' . ALMA_VERSION . ' (WordPress; ' . home_url('/') . ')';
    }

    private static function sparql_value($row, $key) {
        return isset($row[$key]['value']) ? (string) $row[$key]['value'] : '';
    }

    /**
     * "Point(lng lat)" WKT → coordinate. Pura, testabile.
     */
    public static function parse_wkt_point($wkt) {
        if (!preg_match('/Point\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', (string) $wkt, $m)) { return null; }
        return array('lng' => (float) $m[1], 'lat' => (float) $m[2]);
    }

    /**
     * Distanza haversine in km. Pura, testabile.
     */
    public static function haversine_km($lat1, $lng1, $lat2, $lng2) {
        $rad = M_PI / 180;
        $dlat = ((float) $lat2 - (float) $lat1) * $rad;
        $dlng = ((float) $lng2 - (float) $lng1) * $rad;
        $a = sin($dlat / 2) ** 2 + cos((float) $lat1 * $rad) * cos((float) $lat2 * $rad) * sin($dlng / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Sceglie tra i candidati omonimi quello più vicino alle coordinate
     * note del gazetteer (entro WIKIDATA_MATCH_KM). Senza coordinate di
     * riferimento vale il primo candidato (miglior match testuale).
     * Pura, testabile.
     */
    public static function pick_wikidata_candidate($entities, $lat, $lng) {
        if (empty($entities)) { return null; }
        if ($lat === null || $lng === null) { return $entities[0]; }
        $best = null;
        $best_distance = null;
        foreach ($entities as $entity) {
            if (!isset($entity['lat'], $entity['lng']) || $entity['lat'] === null || $entity['lng'] === null) { continue; }
            $distance = self::haversine_km($lat, $lng, $entity['lat'], $entity['lng']);
            if ($distance <= self::WIKIDATA_MATCH_KM && ($best_distance === null || $distance < $best_distance)) {
                $best = $entity;
                $best_distance = $distance;
            }
        }
        return $best;
    }

    /**
     * Scheda compatta in italiano dai dati grezzi. Pura, testabile.
     */
    public static function build_wikidata_payload($entity, $attractions) {
        $unesco = '';
        foreach ((array) $entity['patrimonio'] as $heritage) {
            if (stripos($heritage, 'unesco') !== false || stripos($heritage, 'patrimonio mondiale') !== false || stripos($heritage, 'world heritage') !== false) {
                $unesco = $heritage;
                break;
            }
        }
        $parts = array();
        if (!empty($entity['descrizione'])) { $parts[] = $entity['descrizione']; }
        if (!empty($entity['popolazione'])) { $parts[] = number_format((int) $entity['popolazione'], 0, ',', '.') . ' abitanti'; }
        if ($unesco !== '') { $parts[] = 'patrimonio UNESCO (' . $unesco . ')'; }
        if (!empty($attractions)) { $parts[] = 'attrazioni notevoli: ' . implode(', ', array_slice((array) $attractions, 0, 8)); }
        return array(
            'wikidata_id' => (string) $entity['id'],
            'descrizione' => (string) ($entity['descrizione'] ?? ''),
            'popolazione' => (int) ($entity['popolazione'] ?? 0),
            'paese' => (string) ($entity['paese'] ?? ''),
            'altitudine_m' => $entity['altitudine_m'] ?? null,
            'patrimonio_unesco' => $unesco,
            'attrazioni' => array_slice((array) $attractions, 0, 8),
            'wikipedia_it' => (string) ($entity['wikipedia_it'] ?? ''),
            'sintesi' => ucfirst(implode('; ', $parts)) . ($parts ? '.' : ''),
            'fonte' => 'wikidata.org',
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
    /**
     * Fonti gestite dal warmer, con TTL della scheda valida.
     */
    public static function sources() {
        return array(
            self::SOURCE_OPEN_METEO => self::TTL_DAYS_OK,
            self::SOURCE_WIKIDATA => self::TTL_DAYS_WIKIDATA,
            self::SOURCE_GOOGLE_TRENDS => self::TTL_DAYS_TRENDS,
        );
    }

    public static function pending_locations($limit, $source = self::SOURCE_OPEN_METEO) {
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
            sanitize_key($source), current_time('mysql'), max(1, absint($limit))
        ), ARRAY_A);
    }

    public static function pending_count($source = self::SOURCE_OPEN_METEO) {
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
            sanitize_key($source), current_time('mysql')
        ));
    }

    public static function ready_count($source = self::SOURCE_OPEN_METEO) {
        global $wpdb;
        if (!self::table_exists()) { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . " WHERE source = %s AND expires_at > %s AND payload NOT LIKE %s",
            sanitize_key($source), current_time('mysql'), '%"errore"%'
        ));
    }

    /**
     * Recupera e salva la scheda di una fonte per una località. Ritorna
     * true su successo, false se è stato salvato un marcatore d'errore
     * (TTL breve: niente martellamenti, si ritenta dopo una settimana).
     */
    private static function warm_one($location, $source, $ttl_days) {
        if ($source === self::SOURCE_WIKIDATA) {
            $result = self::fetch_wikidata((string) $location['canonical_name'], $location['lat'], $location['lng']);
        } elseif ($source === self::SOURCE_GOOGLE_TRENDS) {
            $result = ALMA_Google_Trends::fetch_for_keyword((string) $location['canonical_name']);
        } else {
            $result = self::fetch_open_meteo($location['lat'], $location['lng']);
        }
        if (is_wp_error($result)) {
            // I limiti di frequenza (429/cooldown) non sono un problema della
            // località: nessun marcatore, si ritenterà al run successivo.
            if (!in_array($result->get_error_code(), array('alma_gtrends_429', 'alma_gtrends_cooldown'), true)) {
                self::save_fact((int) $location['id'], $source, array('errore' => sanitize_text_field($result->get_error_message())), self::TTL_DAYS_ERROR);
            }
            return false;
        }
        self::save_fact((int) $location['id'], $source, $result, $ttl_days);
        return true;
    }

    /**
     * Un'invocazione del warmer: ogni fonte ha il SUO budget di tempo (niente
     * starvation: prima Open-Meteo esauriva il budget totale e Wikidata/Trends
     * non arrivavano mai al turno), e i contatori vengono salvati anche se
     * il run si interrompe a metà (prima un "break" sul budget azzerava il
     * report dell'intero run, mostrando "elaborate 0" nonostante il lavoro).
     * Se resta lavoro, il run successivo si auto-programma tra CHAIN_DELAY
     * secondi (max MAX_CHAINS_PER_DAY al giorno): tanti run brevi, il pattern
     * giusto per gli hosting condivisi.
     */
    public static function warm_batch() {
        if (!self::is_enabled() || !self::acquire_lock()) { return; }
        $started_at = time();
        $report_sources = array();
        $total_processed = 0;
        try {
            foreach (self::sources() as $source => $ttl_days) {
                $entry = array('processed' => 0, 'ok' => 0, 'errors' => 0, 'remaining' => 0);
                $skip = ($source === self::SOURCE_GOOGLE_TRENDS && get_transient(ALMA_Google_Trends::COOLDOWN_TRANSIENT))
                    || ((time() - $started_at) > self::TIME_BUDGET);
                if (!$skip) {
                    $source_started = time();
                    $pending = self::pending_locations(self::get_batch_size(), $source);
                    foreach ($pending as $location) {
                        if ((time() - $source_started) > self::SOURCE_TIME_BUDGET) { break; }
                        if ($source === self::SOURCE_GOOGLE_TRENDS && get_transient(ALMA_Google_Trends::COOLDOWN_TRANSIENT)) { break; }
                        $entry['processed']++;
                        if (self::warm_one($location, $source, $ttl_days)) { $entry['ok']++; } else { $entry['errors']++; }
                        // Cortesia verso le API gratuite: WDQS chiede ritmi moderati,
                        // gli endpoint non ufficiali di Trends ancora di più.
                        $pauses = array(self::SOURCE_WIKIDATA => 1000000, self::SOURCE_GOOGLE_TRENDS => 2000000);
                        usleep($pauses[$source] ?? 500000);
                    }
                }
                $entry['remaining'] = self::pending_count($source);
                $report_sources[$source] = $entry;
                $total_processed += $entry['processed'];
            }
        } finally {
            $totals = array('processed' => 0, 'ok' => 0, 'errors' => 0, 'remaining' => 0);
            foreach (self::sources() as $source => $ttl_days) {
                if (!isset($report_sources[$source])) {
                    $report_sources[$source] = array('processed' => 0, 'ok' => 0, 'errors' => 0, 'remaining' => self::pending_count($source));
                }
                foreach (array('processed', 'ok', 'errors', 'remaining') as $key) { $totals[$key] += $report_sources[$source][$key]; }
            }
            update_option(self::OPTION_LAST_REPORT, array_merge(array('time' => current_time('mysql'), 'sources' => $report_sources), $totals), false);
            delete_option(self::LOCK_OPTION);
        }
        // Catena di recupero: solo se questo run ha prodotto qualcosa (evita
        // giri a vuoto, es. Trends in cooldown come unica fonte con lavoro).
        if ($total_processed > 0 && $totals['remaining'] > 0 && self::chain_allowed()) {
            wp_schedule_single_event(time() + self::CHAIN_DELAY, self::CRON_HOOK);
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
    }

    /**
     * Contatore giornaliero dei run in catena: consenso e incremento atomici
     * rispetto alla giornata (si azzera al cambio data). Il tetto evita loop
     * infiniti anche in caso di anomalie.
     */
    public static function chain_allowed() {
        $counter = get_option(self::OPTION_CHAIN_COUNTER, array());
        $today = current_time('Y-m-d');
        if (!is_array($counter) || ($counter['date'] ?? '') !== $today) {
            $counter = array('date' => $today, 'count' => 0);
        }
        if ((int) $counter['count'] >= self::MAX_CHAINS_PER_DAY) {
            return false;
        }
        $counter['count'] = (int) $counter['count'] + 1;
        update_option(self::OPTION_CHAIN_COUNTER, $counter, false);
        return true;
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

        // Fatti Wikidata: cache prima, altrimenti fetch on-demand.
        $fatti = self::get_fact($location_id, self::SOURCE_WIKIDATA);
        if (!$fatti) {
            $fetched = self::fetch_wikidata((string) $row['canonical_name'], $row['lat'], $row['lng']);
            if (!is_wp_error($fetched)) {
                self::save_fact($location_id, self::SOURCE_WIKIDATA, $fetched, self::TTL_DAYS_WIKIDATA);
                $fatti = $fetched;
            }
        }
        if (is_array($fatti) && empty($fatti['errore'])) {
            $out['fatti'] = array(
                'sintesi' => $fatti['sintesi'] ?? '',
                'popolazione' => $fatti['popolazione'] ?? 0,
                'patrimonio_unesco' => $fatti['patrimonio_unesco'] ?? '',
                'attrazioni' => $fatti['attrazioni'] ?? array(),
                'wikipedia_it' => $fatti['wikipedia_it'] ?? '',
                'fonte' => $fatti['fonte'] ?? '',
            );
        } else {
            $out['fatti'] = 'non disponibili';
        }

        // Tendenze di ricerca: solo cache/fetch se la fonte non è in cooldown.
        $tendenze = self::get_fact($location_id, self::SOURCE_GOOGLE_TRENDS);
        if (!$tendenze && class_exists('ALMA_Google_Trends')) {
            $fetched = ALMA_Google_Trends::fetch_for_keyword((string) $row['canonical_name']);
            if (!is_wp_error($fetched)) {
                self::save_fact($location_id, self::SOURCE_GOOGLE_TRENDS, $fetched, self::TTL_DAYS_TRENDS);
                $tendenze = $fetched;
            }
        }
        if (is_array($tendenze) && empty($tendenze['errore'])) {
            $out['tendenze_ricerca'] = array(
                'sintesi' => $tendenze['sintesi'] ?? '',
                'mesi_picco_ricerche' => $tendenze['mesi_picco_ricerche'] ?? '',
                'trend_interesse' => $tendenze['trend_interesse'] ?? '',
                'query_correlate_in_crescita' => $tendenze['query_correlate_in_crescita'] ?? array(),
                'query_correlate_top' => $tendenze['query_correlate_top'] ?? array(),
                'fonte' => $tendenze['fonte'] ?? '',
            );
        } else {
            $out['tendenze_ricerca'] = 'non disponibili';
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
        $report = get_option(self::OPTION_LAST_REPORT, null);
        $running = (bool) get_option(self::LOCK_OPTION);
        $labels = array(self::SOURCE_OPEN_METEO => 'Clima (Open-Meteo)', self::SOURCE_WIKIDATA => 'Fatti (Wikidata)', self::SOURCE_GOOGLE_TRENDS => 'Tendenze (Google Trends)');

        echo '<h2>Schede località (fonti esterne)</h2>';
        echo '<div class="alma-agent-card" style="max-width:900px;"><h3>Come funziona</h3>';
        echo '<p>Per ogni località dell\'indice geografico con coordinate, il plugin costruisce una <strong>scheda</strong> con dati da fonti esterne: il <strong>clima</strong> da Open-Meteo (mesi migliori per visitare, mesi da evitare, temperature e piogge mensili), la <strong>carta d\'identità</strong> da Wikidata (descrizione, popolazione, patrimonio UNESCO, Wikipedia italiana e attrazioni notevoli entro 10 km, disambiguate per vicinanza alle coordinate) e le <strong>tendenze di ricerca</strong> da Google Trends (in quali mesi gli italiani cercano la destinazione, trend dell\'interesse, query correlate in crescita). Non si importano interi dataset: si salva solo la scheda compatta, già in italiano, riusata dall\'agente AI con lo strumento <code>scheda_localita</code>.</p>';
        echo '<p>Le schede si riempiono da sole: il job notturno lavora a run brevi (ogni fonte ha il suo turno garantito) e, finché c\'è lavoro, si <strong>auto-programma</strong> ogni ' . esc_html((string) self::CHAIN_DELAY) . ' secondi fino a ' . esc_html((string) self::MAX_CHAINS_PER_DAY) . ' run al giorno. Le schede restano valide 9 mesi (clima) / 6 mesi (fatti) / 1 mese (tendenze); se l\'agente chiede una località non ancora pronta, la scheda viene creata al volo.</p>';
        echo '<p class="description">⚠️ Google Trends non ha un\'API ufficiale: si usano gli endpoint interni del sito. Se Google limita le richieste (HTTP 429) la fonte si sospende da sola per 6 ore e riprende al run successivo; se l\'endpoint cambiasse, la fonte segnala l\'errore senza impattare il resto del plugin.</p></div>';

        echo '<table class="form-table" role="presentation">';
        foreach ($labels as $source => $label) {
            $ready = self::ready_count($source);
            $pending = self::pending_count($source);
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            echo '<span class="alma-badge ' . ($pending === 0 ? 'is-success' : 'is-warning') . '">' . esc_html($ready) . ' schede pronte</span> ';
            echo esc_html($pending) . ' località in attesa';
            if (is_array($report) && isset($report['sources'][$source])) {
                $src = $report['sources'][$source];
                echo '<p class="description">Ultimo run: elaborate ' . esc_html((string) $src['processed']) . ', ok ' . esc_html((string) $src['ok']) . ', errori ' . esc_html((string) $src['errors']) . '.</p>';
            }
            echo '</td></tr>';
        }
        $chain_counter = get_option(self::OPTION_CHAIN_COUNTER, array());
        $chains_today = (is_array($chain_counter) && ($chain_counter['date'] ?? '') === current_time('Y-m-d')) ? (int) $chain_counter['count'] : 0;
        echo '<tr><th scope="row">Ultimo run</th><td>' . (is_array($report) ? esc_html((string) $report['time']) : '—') . ($running ? ' — <strong>aggiornamento in corso…</strong>' : '') . '<p class="description">Run di recupero auto-programmati oggi: ' . esc_html((string) $chains_today) . ' / ' . esc_html((string) self::MAX_CHAINS_PER_DAY) . '.</p></td></tr>';
        echo '</table>';

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
