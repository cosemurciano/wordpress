<?php
/**
 * Fase 7.2 — Connettore Google Trends (endpoint interni, NON ufficiali).
 *
 * Google Trends non espone un'API pubblica: si usano gli endpoint interni
 * del sito (gli stessi di pytrends): explore → token dei widget, poi
 * widgetdata/multiline (serie storica) e widgetdata/relatedsearches
 * (query correlate). Le risposte hanno un prefisso anti-JSON-hijacking
 * ")]}'" da rimuovere prima del parse.
 *
 * Dati distillati per l'agente (ricerche dall'ITALIA, ultimi 5 anni):
 * - stagionalità della DOMANDA di ricerca: in quali mesi gli italiani
 *   cercano la destinazione (spesso anticipa i mesi di viaggio: è il
 *   momento giusto per pubblicare);
 * - trend ultimo anno vs precedente (interesse in crescita o calo);
 * - query correlate TOP e IN CRESCITA (gli angoli emergenti).
 *
 * Difese (endpoint non ufficiale, va trattato con rispetto):
 * - circuit breaker: al primo HTTP 429 la fonte si sospende per 6 ore;
 * - cookie NID recuperato una volta e riusato (transient 1 giorno);
 * - cache per termine (transient 7 giorni) per il tool dell'agente;
 * - le schede località hanno già TTL 30 giorni in alma_geo_facts.
 * Se Google cambia gli endpoint la fonte degrada con un errore chiaro,
 * senza impattare il resto del plugin.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Google_Trends {
    const COOLDOWN_TRANSIENT = 'alma_gtrends_cooldown';
    const COOKIE_TRANSIENT = 'alma_gtrends_cookie';
    const TERM_CACHE_PREFIX = 'alma_gtrends_term_';
    const COOLDOWN_SECONDS = 6 * HOUR_IN_SECONDS;
    const TERM_CACHE_SECONDS = 7 * DAY_IN_SECONDS;
    const GEO = 'IT';
    const TIMEFRAME = 'today 5-y';

    /* ---------------------------------------------------------------------
     * Fetch principale
     * ------------------------------------------------------------------ */

    /**
     * Scheda tendenze per un termine (destinazione o tema).
     */
    public static function fetch_for_keyword($keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return new WP_Error('alma_gtrends', __('Termine mancante.', 'affiliate-link-manager-ai'));
        }
        if (get_transient(self::COOLDOWN_TRANSIENT)) {
            return new WP_Error('alma_gtrends_cooldown', __('Google Trends temporaneamente sospeso (limite richieste raggiunto): la fonte riprova da sola tra qualche ora.', 'affiliate-link-manager-ai'));
        }

        // 1. explore: restituisce i token dei widget.
        $req = wp_json_encode(array(
            'comparisonItem' => array(array('keyword' => $keyword, 'geo' => self::GEO, 'time' => self::TIMEFRAME)),
            'category' => 0,
            'property' => '',
        ));
        $explore = self::request('https://trends.google.com/trends/api/explore?hl=it&tz=-60&req=' . rawurlencode($req));
        if (is_wp_error($explore)) { return $explore; }
        $widgets = isset($explore['widgets']) ? (array) $explore['widgets'] : array();
        $timeseries_widget = null;
        $related_widget = null;
        foreach ($widgets as $widget) {
            if (($widget['id'] ?? '') === 'TIMESERIES') { $timeseries_widget = $widget; }
            if (($widget['id'] ?? '') === 'RELATED_QUERIES') { $related_widget = $widget; }
        }
        if (!$timeseries_widget || empty($timeseries_widget['token'])) {
            return new WP_Error('alma_gtrends', __('Google Trends: widget serie storica non trovato (endpoint cambiato o termine senza dati).', 'affiliate-link-manager-ai'));
        }

        // 2. Serie storica settimanale (5 anni).
        $timeline = self::request('https://trends.google.com/trends/api/widgetdata/multiline?hl=it&tz=-60&req=' . rawurlencode(wp_json_encode($timeseries_widget['request'])) . '&token=' . rawurlencode((string) $timeseries_widget['token']));
        if (is_wp_error($timeline)) { return $timeline; }
        $points = (array) ($timeline['default']['timelineData'] ?? array());
        $season = self::aggregate_timeline($points);
        if (!$season) {
            return new WP_Error('alma_gtrends', sprintf(__('Google Trends: dati insufficienti per "%s" (volume di ricerca troppo basso).', 'affiliate-link-manager-ai'), $keyword));
        }

        // 3. Query correlate (facoltative: se falliscono la scheda esce senza).
        $top = array();
        $rising = array();
        if ($related_widget && !empty($related_widget['token'])) {
            $related = self::request('https://trends.google.com/trends/api/widgetdata/relatedsearches?hl=it&tz=-60&req=' . rawurlencode(wp_json_encode($related_widget['request'])) . '&token=' . rawurlencode((string) $related_widget['token']));
            if (!is_wp_error($related)) {
                $parsed = self::parse_related($related);
                $top = $parsed['top'];
                $rising = $parsed['rising'];
            }
        }

        return self::build_payload($keyword, $season, $top, $rising);
    }

    /**
     * Payload per il tool dell'agente, con cache per termine (7 giorni):
     * l'agente può sondare più temi senza ripetere le chiamate.
     */
    public static function agent_payload($term) {
        $term = trim((string) $term);
        if ($term === '') { return array('error' => 'Termine mancante.'); }
        $cache_key = self::TERM_CACHE_PREFIX . md5(mb_strtolower($term));
        $cached = get_transient($cache_key);
        if (is_array($cached)) { return $cached; }
        $payload = self::fetch_for_keyword($term);
        if (is_wp_error($payload)) {
            return array('error' => sanitize_text_field($payload->get_error_message()));
        }
        set_transient($cache_key, $payload, self::TERM_CACHE_SECONDS);
        return $payload;
    }

    /* ---------------------------------------------------------------------
     * HTTP con cookie e circuit breaker
     * ------------------------------------------------------------------ */

    private static function request($url) {
        $headers = array('Accept-Language' => 'it-IT,it;q=0.9');
        $cookie = get_transient(self::COOKIE_TRANSIENT);
        if (is_string($cookie) && $cookie !== '') { $headers['Cookie'] = $cookie; }
        $response = wp_remote_get($url, array('timeout' => 20, 'user-agent' => self::user_agent(), 'headers' => $headers));
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 429 && !$cookie) {
            // Primo tentativo senza cookie: Google spesso chiede il cookie
            // NID — lo si recupera dalla home e si riprova una volta.
            $cookie = self::fetch_cookie();
            if ($cookie !== '') {
                $headers['Cookie'] = $cookie;
                $response = wp_remote_get($url, array('timeout' => 20, 'user-agent' => self::user_agent(), 'headers' => $headers));
                if (is_wp_error($response)) { return $response; }
                $code = wp_remote_retrieve_response_code($response);
            }
        }
        if ($code === 429) {
            set_transient(self::COOLDOWN_TRANSIENT, 1, self::COOLDOWN_SECONDS);
            return new WP_Error('alma_gtrends_429', __('Google Trends: limite richieste raggiunto (HTTP 429), fonte sospesa per 6 ore.', 'affiliate-link-manager-ai'));
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('alma_gtrends', sprintf(__('Google Trends: risposta HTTP %d (endpoint non ufficiale, potrebbe essere cambiato).', 'affiliate-link-manager-ai'), $code));
        }
        $data = self::decode_body(wp_remote_retrieve_body($response));
        if ($data === null) {
            return new WP_Error('alma_gtrends', __('Google Trends: corpo della risposta non interpretabile.', 'affiliate-link-manager-ai'));
        }
        return $data;
    }

    private static function fetch_cookie() {
        $response = wp_remote_get('https://trends.google.com/trends/explore?geo=' . self::GEO, array('timeout' => 15, 'user-agent' => self::user_agent()));
        if (is_wp_error($response)) { return ''; }
        $cookies = wp_remote_retrieve_cookies($response);
        foreach ((array) $cookies as $wp_cookie) {
            if ($wp_cookie->name === 'NID') {
                $value = 'NID=' . $wp_cookie->value;
                set_transient(self::COOKIE_TRANSIENT, $value, DAY_IN_SECONDS);
                return $value;
            }
        }
        return '';
    }

    private static function user_agent() {
        return 'Mozilla/5.0 (compatible; AffiliateLinkManagerAI/' . ALMA_VERSION . '; +' . home_url('/') . ')';
    }

    /**
     * Rimuove il prefisso anti-hijacking ")]}'" e decodifica. Pura, testabile.
     */
    public static function decode_body($body) {
        $start = strpos((string) $body, '{');
        if ($start === false) { return null; }
        $data = json_decode(substr((string) $body, $start), true);
        return is_array($data) ? $data : null;
    }

    /* ---------------------------------------------------------------------
     * Parser e composizione (funzioni pure, testabili)
     * ------------------------------------------------------------------ */

    /**
     * Serie settimanale → stagionalità: media per mese (indice 0-100),
     * mesi di picco della domanda e trend ultimi 12 mesi vs precedenti.
     */
    public static function aggregate_timeline($points) {
        $by_month = array_fill(1, 12, array('sum' => 0, 'n' => 0));
        $series = array();
        foreach ((array) $points as $point) {
            $ts = isset($point['time']) ? (int) $point['time'] : 0;
            $value = isset($point['value'][0]) ? (int) $point['value'][0] : null;
            if ($ts <= 0 || $value === null) { continue; }
            $month = (int) gmdate('n', $ts);
            $by_month[$month]['sum'] += $value;
            $by_month[$month]['n']++;
            $series[] = array('ts' => $ts, 'value' => $value);
        }
        if (count($series) < 26) { return null; } // meno di ~6 mesi di dati
        $monthly = array();
        foreach ($by_month as $month => $acc) {
            $monthly[$month] = $acc['n'] > 0 ? (int) round($acc['sum'] / $acc['n']) : 0;
        }
        // Picchi: mesi con media >= 80% del mese massimo.
        $max = max($monthly);
        $peaks = array();
        if ($max > 0) {
            foreach ($monthly as $month => $avg) {
                if ($avg >= $max * 0.8) { $peaks[] = $month; }
            }
        }
        // Trend: media ultimi 12 mesi vs 12 precedenti (dalla fine serie).
        $last_ts = $series[count($series) - 1]['ts'];
        $one_year = 365 * DAY_IN_SECONDS;
        $recent = array(); $previous = array();
        foreach ($series as $point) {
            $age = $last_ts - $point['ts'];
            if ($age < $one_year) { $recent[] = $point['value']; }
            elseif ($age < 2 * $one_year) { $previous[] = $point['value']; }
        }
        $trend_pct = null;
        if (count($recent) >= 10 && count($previous) >= 10) {
            $avg_recent = array_sum($recent) / count($recent);
            $avg_previous = array_sum($previous) / count($previous);
            if ($avg_previous > 0) { $trend_pct = (int) round(($avg_recent - $avg_previous) / $avg_previous * 100); }
        }
        return array('monthly' => $monthly, 'peaks' => $peaks, 'trend_pct' => $trend_pct);
    }

    /**
     * Widget relatedsearches → query top e in crescita.
     */
    public static function parse_related($data) {
        $lists = (array) ($data['default']['rankedList'] ?? array());
        $top = array();
        $rising = array();
        foreach ($lists as $index => $list) {
            foreach ((array) ($list['rankedKeyword'] ?? array()) as $item) {
                $query = trim((string) ($item['query'] ?? ''));
                if ($query === '') { continue; }
                $formatted = (string) ($item['formattedValue'] ?? '');
                // Le liste "rising" hanno valori tipo "+250%" o "Impennata".
                $is_rising = $index > 0 || strpos($formatted, '+') === 0 || stripos($formatted, 'breakout') !== false || stripos($formatted, 'impennata') !== false;
                if ($is_rising) {
                    if (count($rising) < 8) { $rising[] = array('query' => $query, 'crescita' => $formatted); }
                } elseif (count($top) < 8) {
                    $top[] = $query;
                }
            }
        }
        return array('top' => $top, 'rising' => $rising);
    }

    /**
     * Scheda compatta in italiano. Pura, testabile.
     */
    public static function build_payload($keyword, $season, $top, $rising) {
        $names = array(1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre');
        $peak_labels = array_map(function ($m) use ($names) { return $names[$m]; }, (array) $season['peaks']);
        $monthly_named = array();
        foreach ((array) $season['monthly'] as $month => $avg) {
            $monthly_named[$names[$month]] = $avg;
        }
        $trend_pct = $season['trend_pct'];
        $trend_label = 'stabile';
        if ($trend_pct !== null && $trend_pct >= 15) { $trend_label = 'in crescita (+' . $trend_pct . '%)'; }
        elseif ($trend_pct !== null && $trend_pct <= -15) { $trend_label = 'in calo (' . $trend_pct . '%)'; }
        elseif ($trend_pct !== null) { $trend_label = 'stabile (' . ($trend_pct >= 0 ? '+' : '') . $trend_pct . '%)'; }
        $parts = array();
        if ($peak_labels) { $parts[] = 'gli italiani lo cercano soprattutto a ' . implode(', ', $peak_labels) . ' (pubblicare PRIMA del picco)'; }
        $parts[] = 'interesse ultimo anno vs precedente: ' . $trend_label;
        if ($rising) {
            $labels = array_map(function ($r) { return $r['query'] . ' (' . $r['crescita'] . ')'; }, array_slice($rising, 0, 5));
            $parts[] = 'ricerche correlate in crescita: ' . implode(', ', $labels);
        }
        return array(
            'termine' => (string) $keyword,
            'area_ricerche' => 'Italia, ultimi 5 anni',
            'mesi_picco_ricerche' => implode(', ', $peak_labels),
            'trend_interesse' => $trend_label,
            'interesse_medio_per_mese' => $monthly_named,
            'query_correlate_top' => array_slice((array) $top, 0, 8),
            'query_correlate_in_crescita' => array_slice((array) $rising, 0, 8),
            'sintesi' => ucfirst(implode('; ', $parts)) . '.',
            'fonte' => 'trends.google.com (endpoint non ufficiale)',
        );
    }
}
