<?php
/**
 * Insight strategici della Dashboard: snapshot precalcolato in background.
 *
 * Le query pesanti (trend click, top link/articoli, gap geografici) girano
 * UNA volta al giorno via WP-Cron (o su richiesta con "Aggiorna ora") e il
 * risultato viene salvato in un'option: il rendering della Dashboard legge
 * solo lo snapshot, zero query pesanti a ogni apertura della pagina.
 *
 * Contenuto dello snapshot:
 * - Trend click: per giorno (30gg), per settimana (26), per mese (12).
 * - Periodi 7/30/180 giorni con confronto sul periodo precedente (delta %).
 * - Top link per click negli ultimi 30 giorni (+ click totali storici).
 * - Top articoli per click sui link affiliati che contengono (post_id
 *   registrato dal tracking a partire dalla v2.54.0).
 * - Gap geografici: località con link affiliati MA senza click (90gg),
 *   località con articoli MA senza link affiliati, top località per click.
 *
 * "Consigli AI": su richiesta lo snapshot viene riassunto e inviato al
 * servizio OpenAI già configurato per ottenere raccomandazioni strategiche;
 * il risultato è salvato con data e costo stimato (mai chiamate automatiche).
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Dashboard_Insights {
    const CRON_HOOK = 'alma_dashboard_insights_rebuild';
    const OPTION_SNAPSHOT = 'alma_dashboard_insights_snapshot';
    const OPTION_ADVICE = 'alma_dashboard_insights_ai_advice';
    const LOCK_OPTION = 'alma_dashboard_insights_lock';
    const LOCK_TTL = 300; // 5 minuti: oltre, il lock è considerato orfano
    const GEO_CLICK_WINDOW_DAYS = 90;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'rebuild'));
        add_action('wp_ajax_alma_insights_rebuild', array(__CLASS__, 'ajax_rebuild'));
        add_action('wp_ajax_alma_insights_ai_advice', array(__CLASS__, 'ajax_ai_advice'));
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Prima esecuzione nella notte successiva (~03:30 ora del sito),
            // poi ogni giorno: i dati sono pronti al mattino.
            $first = strtotime('tomorrow 03:30', current_time('timestamp'));
            $first = $first - (current_time('timestamp') - time()); // riporta a UTC
            wp_schedule_event($first, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_snapshot() {
        $snapshot = get_option(self::OPTION_SNAPSHOT, null);
        return is_array($snapshot) ? $snapshot : null;
    }

    public static function get_advice() {
        $advice = get_option(self::OPTION_ADVICE, null);
        return is_array($advice) ? $advice : null;
    }

    /* ---------------------------------------------------------------------
     * Ricostruzione snapshot (cron o manuale)
     * ------------------------------------------------------------------ */

    /**
     * Lock via add_option atomica con TTL (pattern del geocoding/auto-indexer):
     * evita ricostruzioni concorrenti cron + "Aggiorna ora".
     */
    private static function acquire_lock() {
        if (add_option(self::LOCK_OPTION, (string) time(), '', 'no')) {
            return true;
        }
        $started = absint(get_option(self::LOCK_OPTION, 0));
        if ($started > 0 && (time() - $started) > self::LOCK_TTL) {
            update_option(self::LOCK_OPTION, (string) time(), false);
            return true;
        }
        return false;
    }

    private static function release_lock() {
        delete_option(self::LOCK_OPTION);
    }

    public static function rebuild() {
        if (!self::acquire_lock()) {
            return false;
        }
        $start = microtime(true);
        try {
            $snapshot = array(
                'clicks_daily' => self::clicks_series_daily(30),
                'clicks_weekly' => self::clicks_series_weekly(26),
                'clicks_monthly' => self::clicks_series_monthly(12),
                'periods' => array(
                    '7' => self::period_comparison(7),
                    '30' => self::period_comparison(30),
                    '180' => self::period_comparison(180),
                ),
                'top_links' => self::top_links(10, 30),
                'top_articles_30d' => self::top_articles(10, 30),
                'top_articles_all' => self::top_articles(10, 0),
                'geo_top' => self::geo_top_locations(10, self::GEO_CLICK_WINDOW_DAYS),
                'geo_unused' => self::geo_unused_locations(15, self::GEO_CLICK_WINDOW_DAYS),
                'geo_no_links' => self::geo_locations_without_links(15),
                'links_total' => (int) wp_count_posts('affiliate_link')->publish,
                'links_no_clicks_90d' => self::links_without_clicks_count(self::GEO_CLICK_WINDOW_DAYS),
                'generated_at' => current_time('mysql'),
                'duration_ms' => 0,
            );
            $snapshot['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            update_option(self::OPTION_SNAPSHOT, $snapshot, false);
            return true;
        } finally {
            self::release_lock();
        }
    }

    private static function analytics_table() {
        global $wpdb;
        return $wpdb->prefix . 'alma_analytics';
    }

    /**
     * Confine temporale in ora del sito (click_time è salvato con
     * current_time('mysql')).
     */
    private static function since($days) {
        return gmdate('Y-m-d 00:00:00', current_time('timestamp') - $days * DAY_IN_SECONDS);
    }

    private static function clicks_series_daily($days) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(click_time) AS bucket, COUNT(*) AS c FROM " . self::analytics_table() . " WHERE click_time >= %s GROUP BY bucket",
            self::since($days - 1)
        ), ARRAY_A);
        $map = array();
        foreach ((array) $rows as $row) {
            $map[$row['bucket']] = (int) $row['c'];
        }
        $labels = array();
        $data = array();
        $now = current_time('timestamp');
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', $now - $i * DAY_IN_SECONDS);
            $labels[] = wp_date('d/m', strtotime($day . ' 12:00:00'));
            $data[] = isset($map[$day]) ? $map[$day] : 0;
        }
        return array('labels' => $labels, 'data' => $data);
    }

    private static function clicks_series_weekly($weeks) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT YEARWEEK(click_time, 1) AS bucket, COUNT(*) AS c FROM " . self::analytics_table() . " WHERE click_time >= %s GROUP BY bucket",
            self::since($weeks * 7)
        ), ARRAY_A);
        $map = array();
        foreach ((array) $rows as $row) {
            $map[(string) $row['bucket']] = (int) $row['c'];
        }
        $labels = array();
        $data = array();
        $now = current_time('timestamp');
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $ts = $now - $i * WEEK_IN_SECONDS;
            $key = gmdate('oW', $ts); // formato YEARWEEK mode 1 (ISO)
            $labels[] = wp_date('d/m', strtotime(gmdate('Y-m-d', $ts) . ' 12:00:00'));
            $data[] = isset($map[$key]) ? $map[$key] : 0;
        }
        return array('labels' => $labels, 'data' => $data);
    }

    private static function clicks_series_monthly($months) {
        global $wpdb;
        $now = current_time('timestamp');
        $start = gmdate('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months', $now));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(click_time, '%%Y-%%m') AS bucket, COUNT(*) AS c FROM " . self::analytics_table() . " WHERE click_time >= %s GROUP BY bucket",
            $start
        ), ARRAY_A);
        $map = array();
        foreach ((array) $rows as $row) {
            $map[$row['bucket']] = (int) $row['c'];
        }
        $labels = array();
        $data = array();
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = gmdate('Y-m', strtotime("-$i months", $now));
            $labels[] = wp_date('M y', strtotime($ym . '-01 12:00:00'));
            $data[] = isset($map[$ym]) ? $map[$ym] : 0;
        }
        return array('labels' => $labels, 'data' => $data);
    }

    /**
     * Click del periodo vs periodo precedente di pari durata, con delta %.
     */
    private static function period_comparison($days) {
        global $wpdb;
        $table = self::analytics_table();
        $current_start = self::since($days);
        $previous_start = self::since($days * 2);
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE click_time >= %s", $current_start
        ));
        $previous = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE click_time >= %s AND click_time < %s", $previous_start, $current_start
        ));
        $delta = null;
        if ($previous > 0) {
            $delta = round((($current - $previous) / $previous) * 100, 1);
        } elseif ($current > 0) {
            $delta = 100.0;
        }
        return array('clicks' => $current, 'previous' => $previous, 'delta_pct' => $delta);
    }

    /**
     * Top link per click nel periodo, con tipologia e click totali storici.
     */
    private static function top_links($limit, $days) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.link_id, COUNT(*) AS clicks
             FROM " . self::analytics_table() . " a
             INNER JOIN {$wpdb->posts} p ON p.ID = a.link_id AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             WHERE a.click_time >= %s
             GROUP BY a.link_id ORDER BY clicks DESC LIMIT %d",
            self::since($days), absint($limit)
        ), ARRAY_A);
        $links = array();
        foreach ((array) $rows as $row) {
            $link_id = (int) $row['link_id'];
            $types = get_the_terms($link_id, 'link_type');
            $type_names = array();
            if (is_array($types)) {
                foreach ($types as $type) {
                    $type_names[] = html_entity_decode($type->name, ENT_QUOTES, 'UTF-8');
                }
            }
            $links[] = array(
                'id' => $link_id,
                'title' => html_entity_decode(get_the_title($link_id), ENT_QUOTES, 'UTF-8'),
                'clicks_period' => (int) $row['clicks'],
                'clicks_total' => (int) (get_post_meta($link_id, '_click_count', true) ?: 0),
                'types' => implode(', ', $type_names),
                'edit_url' => get_edit_post_link($link_id, 'raw'),
            );
        }
        return $links;
    }

    /**
     * Top articoli per click sui link affiliati che contengono. Usa la colonna
     * post_id valorizzata dal tracking dalla v2.54.0: i click precedenti non
     * hanno il dato (post_id = 0) e vengono esclusi.
     */
    private static function top_articles($limit, $days) {
        global $wpdb;
        $where_time = $days > 0 ? $wpdb->prepare(' AND a.click_time >= %s', self::since($days)) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.post_id, COUNT(*) AS clicks
             FROM " . self::analytics_table() . " a
             INNER JOIN {$wpdb->posts} p ON p.ID = a.post_id AND p.post_type IN ('post', 'page') AND p.post_status = 'publish'
             WHERE a.post_id > 0{$where_time}
             GROUP BY a.post_id ORDER BY clicks DESC LIMIT %d",
            absint($limit)
        ), ARRAY_A);
        $articles = array();
        foreach ((array) $rows as $row) {
            $post_id = (int) $row['post_id'];
            $articles[] = array(
                'id' => $post_id,
                'title' => html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8'),
                'clicks' => (int) $row['clicks'],
                'url' => get_permalink($post_id),
                'edit_url' => get_edit_post_link($post_id, 'raw'),
            );
        }
        return $articles;
    }

    private static function geo_store() {
        return class_exists('ALMA_Geo_Index_Store') ? new ALMA_Geo_Index_Store() : null;
    }

    /**
     * Località più cliccate: click sui link affiliati associati a ciascuna
     * località nel periodo (un click conta per ogni località del link).
     */
    private static function geo_top_locations($limit, $days) {
        global $wpdb;
        $store = self::geo_store();
        if (!$store || !$store->tables_exist()) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.canonical_name AS name, l.country, COUNT(*) AS clicks
             FROM " . self::analytics_table() . " a
             INNER JOIN {$store->table_content_index()} ci ON ci.object_type = %s AND ci.object_id = a.link_id
             INNER JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE a.click_time >= %s
             GROUP BY l.id ORDER BY clicks DESC LIMIT %d",
            ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, self::since($days), absint($limit)
        ), ARRAY_A);
        return self::format_geo_rows($rows, 'clicks');
    }

    /**
     * Aree geografiche NON utilizzate: località che HANNO link affiliati ma
     * nessun click nel periodo — dove l'offerta esiste ma non produce.
     */
    private static function geo_unused_locations($limit, $days) {
        global $wpdb;
        $store = self::geo_store();
        if (!$store || !$store->tables_exist()) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.canonical_name AS name, l.country,
                    COUNT(DISTINCT ci.object_id) AS links,
                    (SELECT COUNT(DISTINCT ci3.object_id) FROM {$store->table_content_index()} ci3
                     INNER JOIN {$wpdb->posts} p3 ON p3.ID = ci3.object_id AND p3.post_type = 'post' AND p3.post_status = 'publish'
                     WHERE ci3.location_id = l.id AND ci3.object_type = %s) AS posts
             FROM {$store->table_locations()} l
             INNER JOIN {$store->table_content_index()} ci ON ci.location_id = l.id AND ci.object_type = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = ci.object_id AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             WHERE NOT EXISTS (
                 SELECT 1 FROM " . self::analytics_table() . " a
                 INNER JOIN {$store->table_content_index()} ci2 ON ci2.object_type = %s AND ci2.object_id = a.link_id
                 WHERE ci2.location_id = l.id AND a.click_time >= %s
             )
             GROUP BY l.id ORDER BY links DESC, posts DESC LIMIT %d",
            ALMA_Geo_Index_Store::OBJECT_TYPE_POST,
            ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK,
            ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK,
            self::since($days), absint($limit)
        ), ARRAY_A);
        return self::format_geo_rows($rows, 'links', 'posts');
    }

    /**
     * Opportunità: località con articoli pubblicati ma NESSUN link affiliato
     * associato — contenuto senza monetizzazione.
     */
    private static function geo_locations_without_links($limit) {
        global $wpdb;
        $store = self::geo_store();
        if (!$store || !$store->tables_exist()) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.canonical_name AS name, l.country, COUNT(DISTINCT ci.object_id) AS posts
             FROM {$store->table_locations()} l
             INNER JOIN {$store->table_content_index()} ci ON ci.location_id = l.id AND ci.object_type = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = ci.object_id AND p.post_type = 'post' AND p.post_status = 'publish'
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$store->table_content_index()} ci2
                 WHERE ci2.location_id = l.id AND ci2.object_type = %s
             )
             GROUP BY l.id ORDER BY posts DESC LIMIT %d",
            ALMA_Geo_Index_Store::OBJECT_TYPE_POST,
            ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK,
            absint($limit)
        ), ARRAY_A);
        return self::format_geo_rows($rows, 'posts');
    }

    private static function format_geo_rows($rows, $metric_key, $secondary_key = null) {
        $out = array();
        foreach ((array) $rows as $row) {
            $entry = array(
                'name' => html_entity_decode(sanitize_text_field($row['name']), ENT_QUOTES, 'UTF-8'),
                'country' => sanitize_text_field((string) ($row['country'] ?? '')),
                $metric_key => (int) $row[$metric_key],
            );
            if ($secondary_key !== null && isset($row[$secondary_key])) {
                $entry[$secondary_key] = (int) $row[$secondary_key];
            }
            $out[] = $entry;
        }
        return $out;
    }

    private static function links_without_clicks_count($days) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             AND NOT EXISTS (SELECT 1 FROM " . self::analytics_table() . " a WHERE a.link_id = p.ID AND a.click_time >= %s)",
            self::since($days)
        ));
    }

    /* ---------------------------------------------------------------------
     * AJAX admin
     * ------------------------------------------------------------------ */

    private static function verify_ajax_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        if (!check_ajax_referer('alma_insights', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 400);
        }
    }

    public static function ajax_rebuild() {
        self::verify_ajax_request();
        if (!self::rebuild()) {
            wp_send_json_error(array('message' => __('Ricostruzione già in corso, riprova tra qualche minuto.', 'affiliate-link-manager-ai')), 409);
        }
        $snapshot = self::get_snapshot();
        wp_send_json_success(array(
            'generated_at' => $snapshot['generated_at'] ?? '',
            'duration_ms' => $snapshot['duration_ms'] ?? 0,
        ));
    }

    public static function ajax_ai_advice() {
        self::verify_ajax_request();
        $snapshot = self::get_snapshot();
        if (!$snapshot) {
            wp_send_json_error(array('message' => __('Genera prima lo snapshot dei dati (Aggiorna ora).', 'affiliate-link-manager-ai')), 400);
        }

        $result = ALMA_OpenAI_Service::request(array(
            'system_prompt' => 'Sei un consulente strategico di affiliate marketing per un blog di viaggi italiano. '
                . 'Ricevi le statistiche aggregate dei click sui link affiliati e i gap geografici. '
                . 'Rispondi in italiano, conciso e operativo.',
            'user_prompt' => self::build_advice_prompt($snapshot),
            'max_output_tokens' => 900,
            'temperature' => 0.4,
            'timeout' => 60,
        ));

        if (empty($result['success'])) {
            wp_send_json_error(array('message' => sanitize_text_field($result['error'] ?? __('Errore AI', 'affiliate-link-manager-ai'))), 500);
        }

        $advice = array(
            'text' => sanitize_textarea_field((string) $result['response']),
            'generated_at' => current_time('mysql'),
            'model' => sanitize_text_field((string) ($result['model'] ?? '')),
            'estimated_cost' => isset($result['estimated_cost']) && $result['estimated_cost'] !== null ? (float) $result['estimated_cost'] : null,
        );
        update_option(self::OPTION_ADVICE, $advice, false);
        wp_send_json_success($advice);
    }

    /**
     * Riassunto compatto dello snapshot per il prompt AI (solo aggregati,
     * nessun dato personale: IP e user agent non lasciano mai il sito).
     */
    public static function build_advice_prompt($snapshot) {
        $lines = array();
        $lines[] = 'DATI AGGREGATI (generati il ' . ($snapshot['generated_at'] ?? '?') . '):';
        foreach (array('7' => '7 giorni', '30' => '30 giorni', '180' => '180 giorni') as $key => $label) {
            $period = $snapshot['periods'][$key] ?? null;
            if ($period) {
                $delta = $period['delta_pct'] === null ? 'n/d' : ($period['delta_pct'] >= 0 ? '+' : '') . $period['delta_pct'] . '%';
                $lines[] = "- Click ultimi {$label}: {$period['clicks']} (periodo precedente: {$period['previous']}, variazione: {$delta})";
            }
        }
        $lines[] = '- Link affiliati pubblicati: ' . ($snapshot['links_total'] ?? 0) . '; senza click negli ultimi 90 giorni: ' . ($snapshot['links_no_clicks_90d'] ?? 0);

        $lines[] = 'TOP LINK (30 giorni):';
        foreach (array_slice((array) ($snapshot['top_links'] ?? array()), 0, 5) as $link) {
            $lines[] = "- {$link['title']} [{$link['types']}]: {$link['clicks_period']} click (storico {$link['clicks_total']})";
        }
        $lines[] = 'TOP ARTICOLI per click affiliati (30 giorni):';
        foreach (array_slice((array) ($snapshot['top_articles_30d'] ?? array()), 0, 5) as $article) {
            $lines[] = "- {$article['title']}: {$article['clicks']} click";
        }
        $lines[] = 'LOCALITÀ PIÙ CLICCATE (90 giorni):';
        foreach (array_slice((array) ($snapshot['geo_top'] ?? array()), 0, 5) as $geo) {
            $lines[] = "- {$geo['name']} ({$geo['country']}): {$geo['clicks']} click";
        }
        $lines[] = 'LOCALITÀ CON LINK AFFILIATI MA SENZA CLICK (90 giorni):';
        foreach (array_slice((array) ($snapshot['geo_unused'] ?? array()), 0, 10) as $geo) {
            $lines[] = "- {$geo['name']} ({$geo['country']}): {$geo['links']} link, " . ($geo['posts'] ?? 0) . ' articoli';
        }
        $lines[] = 'LOCALITÀ CON ARTICOLI MA SENZA LINK AFFILIATI:';
        foreach (array_slice((array) ($snapshot['geo_no_links'] ?? array()), 0, 10) as $geo) {
            $lines[] = "- {$geo['name']} ({$geo['country']}): {$geo['posts']} articoli";
        }
        $lines[] = '';
        $lines[] = 'Sulla base di questi dati fornisci 5 consigli strategici concreti e prioritizzati '
            . '(1 = più importante) per aumentare i click affiliati e coprire i gap geografici. '
            . 'Per ogni consiglio: azione specifica, motivazione basata sui numeri, risultato atteso.';
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     * Payload per il JS della dashboard (grafici)
     * ------------------------------------------------------------------ */

    public static function get_chart_payload() {
        $snapshot = self::get_snapshot();
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alma_insights'),
            'series' => array(
                'daily' => $snapshot['clicks_daily'] ?? array('labels' => array(), 'data' => array()),
                'weekly' => $snapshot['clicks_weekly'] ?? array('labels' => array(), 'data' => array()),
                'monthly' => $snapshot['clicks_monthly'] ?? array('labels' => array(), 'data' => array()),
            ),
            'strings' => array(
                'clicks' => __('Click', 'affiliate-link-manager-ai'),
                'rebuilding' => __('Ricostruzione in corso…', 'affiliate-link-manager-ai'),
                'rebuilt' => __('Dati aggiornati, ricarico la pagina…', 'affiliate-link-manager-ai'),
                'aiWorking' => __('L\'AI sta analizzando i dati…', 'affiliate-link-manager-ai'),
                'error' => __('Errore, riprova.', 'affiliate-link-manager-ai'),
            ),
        );
    }
}
