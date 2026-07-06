<?php
/**
 * Link Health Checker — verifica che i link affiliati esistano ancora.
 *
 * Il problema reale: un'attività GetYourGuide/Viator ritirata raramente
 * risponde 404 — di solito il sito REINDIRIZZA con 200 alla pagina della
 * città ("soft-404"). Il controllo giusto è seguire i redirect e
 * verificare che l'URL finale contenga ancora il CODICE PRODOTTO
 * (-t123456 per GYG, 8647P347 per Viator). Per gli altri domini vale il
 * classico 404/410.
 *
 * Anti falsi positivi: un link diventa "morto" solo dopo DUE verifiche
 * fallite in giorni diversi; gli errori di rete/5xx/429 non contano mai
 * come fallimento (il merchant potrebbe limitare il nostro server).
 *
 * Quarantena (mai eliminazione automatica): un link morto viene escluso
 * da widget contestuale e selezione candidati delle nuove bozze, e negli
 * articoli lo shortcode degrada ad ancora di testo semplice — nessun 404
 * esposto ai visitatori, storico click preservato, cestino solo manuale.
 *
 * Job notturno interrompibile con run in catena (pattern warmer) + una
 * verifica LIVE dei candidati al momento della creazione dell'articolo.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Link_Health_Checker {
    const CRON_HOOK = 'alma_link_health_check';
    const LOCK_OPTION = 'alma_link_health_lock';
    const LOCK_TTL = 120;
    const OPTION_LAST_REPORT = 'alma_link_health_last_report';
    const OPTION_CHAIN_COUNTER = 'alma_link_health_chain_counter';
    const META_STATUS = '_alma_link_health';          // ok | suspect | dead
    const META_CHECKED_AT = '_alma_link_health_checked_at';
    const META_REASON = '_alma_link_health_reason';
    const META_FAILS = '_alma_link_health_fails';
    const BATCH_SIZE = 20;
    const TIME_BUDGET = 40;
    const RECHECK_DAYS = 30;   // ricontrollo periodico dei link "ok"
    const MAX_REDIRECTS = 6;
    const MAX_CHAINS_PER_DAY = 30;
    const CHAIN_DELAY = 90;

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_batch'));
        add_action('admin_post_alma_link_health_run', array(__CLASS__, 'handle_run_now'));
        add_action('admin_post_alma_link_health_recheck', array(__CLASS__, 'handle_recheck'));
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // 02:30 locali: prima degli altri job notturni del plugin.
            wp_schedule_event(strtotime('tomorrow 02:30') ?: time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ---------------------------------------------------------------------
     * Valutazione (funzioni pure, testabili)
     * ------------------------------------------------------------------ */

    /**
     * Codice prodotto dall'URL: GYG "t123456", Viator "8647P347".
     * Vuoto per gli altri domini (per loro basta lo status HTTP).
     */
    public static function extract_product_code($url) {
        $parts = wp_parse_url((string) $url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (strpos($host, 'getyourguide.') !== false && preg_match('/-t(\d+)\/?$/', $path, $m)) {
            return 't' . $m[1];
        }
        if (strpos($host, 'viator.com') !== false && preg_match('#/d\d+-(\d+P\d+)/?$#', $path, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Esito di una verifica: 'ok', 'fail:<motivo>' (conta verso "morto")
     * o 'soft:<motivo>' (errore temporaneo, NON conta). Pura, testabile.
     */
    public static function evaluate_response($original_url, $final_url, $status) {
        $status = (int) $status;
        if ($status === 404 || $status === 410) {
            return 'fail:HTTP ' . $status;
        }
        if ($status >= 500 || $status === 429 || $status === 403 || $status === 0) {
            // Errori temporanei o difese anti-bot del merchant: mai un
            // verdetto di morte su questa base.
            return 'soft:HTTP ' . $status;
        }
        if ($status >= 400) {
            return 'fail:HTTP ' . $status;
        }
        // 2xx: per GYG/Viator il codice prodotto deve sopravvivere ai
        // redirect, altrimenti è la pagina-città di ripiego (soft-404).
        $code = self::extract_product_code($original_url);
        if ($code !== '' && stripos((string) $final_url, $code) === false) {
            return 'fail:prodotto rimosso (redirect a pagina generica senza codice ' . $code . ')';
        }
        return 'ok';
    }

    /**
     * Transizione di stato: "morto" solo al secondo fallimento in un
     * GIORNO DIVERSO dal primo. Pura, testabile.
     * Ritorna array(status, fails).
     */
    public static function next_status($is_fail, $previous_fails, $last_check_date, $today) {
        if (!$is_fail) {
            return array('ok', 0);
        }
        $previous_fails = (int) $previous_fails;
        if ($previous_fails >= 1 && (string) $last_check_date !== '' && (string) $last_check_date !== (string) $today) {
            return array('dead', $previous_fails + 1);
        }
        return array('suspect', max(1, $previous_fails));
    }

    /* ---------------------------------------------------------------------
     * Verifica HTTP
     * ------------------------------------------------------------------ */

    /**
     * Segue i redirect e ritorna array(final_url, status) o WP_Error di rete.
     */
    public static function resolve_final($url) {
        $current = (string) $url;
        $status = 0;
        for ($hop = 0; $hop < self::MAX_REDIRECTS; $hop++) {
            $response = wp_remote_get($current, array(
                'timeout' => 15,
                'redirection' => 0,
                'user-agent' => 'Mozilla/5.0 (compatible; AffiliateLinkManagerAI/' . ALMA_VERSION . '; +' . home_url('/') . ')',
                'headers' => array('Accept-Language' => 'it-IT,it;q=0.9'),
            ));
            if (is_wp_error($response)) {
                return $response;
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 300 || $status >= 400) {
                return array('final_url' => $current, 'status' => $status);
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) { $location = end($location); }
            if ((string) $location === '') {
                return array('final_url' => $current, 'status' => $status);
            }
            $next = ALMA_Affiliate_Link_Auditor::resolve_location_url($current, (string) $location);
            if ($next === '') {
                return array('final_url' => $current, 'status' => $status);
            }
            $current = $next;
        }
        return array('final_url' => $current, 'status' => $status);
    }

    /**
     * Verifica un singolo link e ne aggiorna i meta. Ritorna lo stato.
     */
    public static function check_link($link_id) {
        $link_id = absint($link_id);
        $url = trim((string) get_post_meta($link_id, '_affiliate_url', true));
        $today = current_time('Y-m-d');
        if ($url === '') {
            update_post_meta($link_id, self::META_STATUS, 'suspect');
            update_post_meta($link_id, self::META_REASON, 'URL affiliato vuoto');
            update_post_meta($link_id, self::META_CHECKED_AT, $today);
            return 'suspect';
        }
        $resolved = self::resolve_final($url);
        if (is_wp_error($resolved)) {
            $verdict = 'soft:' . $resolved->get_error_message();
        } else {
            $verdict = self::evaluate_response($url, $resolved['final_url'], $resolved['status']);
        }
        if (strpos($verdict, 'soft:') === 0) {
            // Errore temporaneo: si registra il passaggio senza cambiare
            // lo stato; il ciclo periodico riproverà.
            update_post_meta($link_id, self::META_CHECKED_AT, $today);
            update_post_meta($link_id, self::META_REASON, sanitize_text_field(substr($verdict, 5) . ' (temporaneo, stato invariato)'));
            return (string) (get_post_meta($link_id, self::META_STATUS, true) ?: 'ok');
        }
        $is_fail = strpos($verdict, 'fail:') === 0;
        $previous_fails = (int) get_post_meta($link_id, self::META_FAILS, true);
        $last_check = (string) get_post_meta($link_id, self::META_CHECKED_AT, true);
        list($status, $fails) = self::next_status($is_fail, $previous_fails, $last_check, $today);
        update_post_meta($link_id, self::META_STATUS, $status);
        update_post_meta($link_id, self::META_FAILS, $fails);
        update_post_meta($link_id, self::META_CHECKED_AT, $today);
        update_post_meta($link_id, self::META_REASON, $is_fail ? sanitize_text_field(substr($verdict, 5)) : '');
        return $status;
    }

    /**
     * Link morto? Helper centrale usato da shortcode, widget e Draft Builder.
     */
    public static function is_dead($link_id) {
        return get_post_meta(absint($link_id), self::META_STATUS, true) === 'dead';
    }

    /* ---------------------------------------------------------------------
     * Coda e batch notturno
     * ------------------------------------------------------------------ */

    /**
     * Coda auto-avanzante: prima i sospetti di giorni precedenti (serve la
     * seconda opinione), poi i mai verificati, poi i più vecchi oltre il
     * ricontrollo periodico.
     */
    public static function pending_links($limit) {
        global $wpdb;
        $today = current_time('Y-m-d');
        $recheck_cutoff = gmdate('Y-m-d', current_time('timestamp') - self::RECHECK_DAYS * DAY_IN_SECONDS);
        return array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_affiliate_url' AND u.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
             WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish'
               AND (
                    (s.meta_value = 'suspect' AND c.meta_value < %s)
                 OR c.meta_id IS NULL
                 OR (COALESCE(s.meta_value, 'ok') <> 'dead' AND c.meta_value < %s)
               )
             ORDER BY (s.meta_value = 'suspect' AND c.meta_value < %s) DESC, c.meta_value IS NULL DESC, c.meta_value ASC
             LIMIT %d",
            self::META_STATUS, self::META_CHECKED_AT, $today, $recheck_cutoff, $today, max(1, absint($limit))
        )));
    }

    public static function pending_count() {
        global $wpdb;
        $today = current_time('Y-m-d');
        $recheck_cutoff = gmdate('Y-m-d', current_time('timestamp') - self::RECHECK_DAYS * DAY_IN_SECONDS);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_affiliate_url' AND u.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
             WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish'
               AND (
                    (s.meta_value = 'suspect' AND c.meta_value < %s)
                 OR c.meta_id IS NULL
                 OR (COALESCE(s.meta_value, 'ok') <> 'dead' AND c.meta_value < %s)
               )",
            self::META_STATUS, self::META_CHECKED_AT, $today, $recheck_cutoff
        ));
    }

    public static function status_counts() {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(s.meta_value, 'mai-verificato') AS stato, COUNT(*) AS n
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = '_affiliate_url' AND u.meta_value <> ''
             LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             GROUP BY stato",
            self::META_STATUS
        ), ARRAY_A);
        $out = array('ok' => 0, 'suspect' => 0, 'dead' => 0, 'mai-verificato' => 0);
        foreach ($rows as $row) {
            $out[(string) $row['stato']] = (int) $row['n'];
        }
        return $out;
    }

    public static function dead_links($limit = 50) {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, r.meta_value AS motivo, c.meta_value AS verificato
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = 'dead'
             LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
             WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             ORDER BY c.meta_value DESC LIMIT %d",
            self::META_STATUS, self::META_REASON, self::META_CHECKED_AT, max(1, absint($limit))
        ), ARRAY_A);
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

    public static function run_batch() {
        if (!self::acquire_lock()) { return; }
        $started_at = time();
        $checked = 0;
        $found_dead = 0;
        $found_suspect = 0;
        try {
            $ids = self::pending_links(self::BATCH_SIZE);
            foreach ($ids as $link_id) {
                if ((time() - $started_at) > self::TIME_BUDGET) { break; }
                $status = self::check_link($link_id);
                $checked++;
                if ($status === 'dead') { $found_dead++; }
                if ($status === 'suspect') { $found_suspect++; }
                usleep(300000); // cortesia verso i merchant
            }
            update_option(self::OPTION_LAST_REPORT, array(
                'time' => current_time('mysql'),
                'checked' => $checked,
                'dead' => $found_dead,
                'suspect' => $found_suspect,
                'remaining' => self::pending_count(),
            ), false);
            // I morti nuovi non devono più uscire dal widget contestuale.
            if ($found_dead > 0 && class_exists('ALMA_Contextual_Affiliate_Widget') && method_exists('ALMA_Contextual_Affiliate_Widget', 'bump_cache_version')) {
                ALMA_Contextual_Affiliate_Widget::bump_cache_version();
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        if ($checked > 0 && self::pending_count() > 0 && self::chain_allowed()) {
            wp_schedule_single_event(time() + self::CHAIN_DELAY, self::CRON_HOOK);
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
    }

    /**
     * Filtro LIVE dei candidati alla creazione dell'articolo: verifica gli
     * URL sul momento e scarta i morti (marcandoli per il ciclo notturno).
     * Budget totale ridotto: la creazione bozza non deve rallentare troppo.
     */
    public static function filter_live_candidates($candidates) {
        $started = time();
        $out = array();
        foreach ((array) $candidates as $candidate) {
            $link_id = absint($candidate['source_id'] ?? ($candidate['id'] ?? 0));
            if ($link_id > 0 && self::is_dead($link_id)) {
                continue; // già in quarantena
            }
            if ($link_id > 0 && (time() - $started) < 15) {
                $status = self::check_link($link_id);
                if ($status === 'dead') { continue; }
            }
            $out[] = $candidate;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Azioni admin + card per la pagina Verifica link
     * ------------------------------------------------------------------ */

    private static function redirect_back($type, $message) {
        set_transient('alma_link_audit_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=' . ALMA_Affiliate_Link_Auditor::MENU_SLUG));
        exit;
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_health');
        if (get_option(self::LOCK_OPTION)) {
            self::redirect_back('error', 'Una verifica è già in corso.');
        }
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        if (function_exists('spawn_cron')) { spawn_cron(); }
        self::redirect_back('success', 'Verifica link avviata in background: ricarica tra qualche istante per vedere il report.');
    }

    public static function handle_recheck() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_health');
        global $wpdb;
        // Rimette in coda morti e sospetti: azzera stato e contatori, i
        // link verranno riverificati da zero (2 conferme per ridiventare morti).
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ('dead','suspect')",
            self::META_STATUS
        ));
        foreach ((array) $ids as $link_id) {
            delete_post_meta((int) $link_id, self::META_STATUS);
            delete_post_meta((int) $link_id, self::META_FAILS);
            delete_post_meta((int) $link_id, self::META_CHECKED_AT);
            delete_post_meta((int) $link_id, self::META_REASON);
        }
        if (class_exists('ALMA_Contextual_Affiliate_Widget') && method_exists('ALMA_Contextual_Affiliate_Widget', 'bump_cache_version')) {
            ALMA_Contextual_Affiliate_Widget::bump_cache_version();
        }
        self::redirect_back('success', sprintf('%d link rimessi in coda di verifica (torneranno "morti" solo dopo 2 nuove conferme).', count((array) $ids)));
    }

    public static function render_card() {
        $counts = self::status_counts();
        $report = get_option(self::OPTION_LAST_REPORT, null);
        $running = (bool) get_option(self::LOCK_OPTION);
        $card = 'border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:14px 16px;margin:0 0 14px;';
        echo '<div style="' . esc_attr($card) . '"><h2 style="margin-top:0;">🩺 Salute dei link (verifica 404 e prodotti rimossi)</h2>';
        echo '<p class="description">Ogni notte il plugin verifica che i link esistano ancora: segue i redirect e, per GetYourGuide/Viator, controlla che l\'URL finale contenga ancora il codice prodotto (le attività ritirate reindirizzano con 200 alla pagina città). Un link diventa <strong>morto</strong> solo dopo 2 verifiche fallite in giorni diversi; gli errori temporanei (5xx/429) non contano mai. I link morti vengono <strong>messi in quarantena</strong>: esclusi dal widget contestuale e dai nuovi articoli, e negli articoli esistenti lo shortcode degrada a testo semplice. Nessuna eliminazione automatica: lo storico click resta.</p>';
        echo '<p><span class="alma-badge is-success">' . esc_html((string) $counts['ok']) . ' ok</span> ';
        echo '<span class="alma-badge is-warning">' . esc_html((string) $counts['suspect']) . ' sospetti</span> ';
        echo '<span class="alma-badge" style="background:#fcf0f1;color:#d63638;">' . esc_html((string) $counts['dead']) . ' morti</span> ';
        echo esc_html((string) $counts['mai-verificato']) . ' mai verificati · ' . esc_html((string) self::pending_count()) . ' in coda' . ($running ? ' — <strong>verifica in corso…</strong>' : '') . '</p>';
        if (is_array($report)) {
            echo '<p class="description">Ultimo giro: ' . esc_html((string) $report['time']) . ' — verificati ' . esc_html((string) $report['checked']) . ', nuovi morti ' . esc_html((string) $report['dead']) . ', sospetti ' . esc_html((string) $report['suspect']) . ', in coda ' . esc_html((string) $report['remaining']) . '.</p>';
        }
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_link_health');
        echo '<input type="hidden" name="action" value="alma_link_health_run"><button class="button button-primary"' . disabled($running, true, false) . '>Esegui ora un giro di verifica</button></form>';
        if ($counts['dead'] > 0 || $counts['suspect'] > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('alma_link_health');
            echo '<input type="hidden" name="action" value="alma_link_health_recheck"><button class="button">Rimetti in coda morti e sospetti</button></form>';
        }
        echo '</div>';
        $dead = self::dead_links(50);
        if (!empty($dead)) {
            echo '<h3 style="margin-bottom:6px;">Link morti (in quarantena)</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Link</th><th>Motivo</th><th>Verificato il</th></tr></thead><tbody>';
            foreach ($dead as $row) {
                echo '<tr><td><a href="' . esc_url(get_edit_post_link((int) $row['ID'], 'raw')) . '">' . esc_html((string) $row['post_title']) . '</a></td>';
                echo '<td>' . esc_html((string) ($row['motivo'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['verificato'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
