<?php
/**
 * Verifica link affiliati — audit degli URL salvati + bonifica tpx.li.
 *
 * Caso reale che ha originato lo strumento: alcuni link GetYourGuide erano
 * salvati nel database come short link Travelpayouts (getyourguide.tpx.li/…)
 * invece del deep link ufficiale con partner_id → il programma partner
 * GetYourGuide mostrava 0 visite. Il plugin NON altera mai gli URL in
 * uscita: emette esattamente ciò che è salvato in _affiliate_url; questa
 * pagina serve a vedere COSA è salvato e a correggerlo.
 *
 * Bonifica: per ogni link tpx.li il server segue i redirect fino al
 * prodotto getyourguide.*, elimina i parametri di tracciamento
 * Travelpayouts e riapplica partner_id/utm_medium ufficiali (riusando il
 * builder dell'import CSV). L'URL originale viene salvato in un meta di
 * backup prima di ogni modifica: nulla va perso. Batch da 15 link per
 * click, interrompibile, con lock atomico e marcatura dei falliti (per
 * non rielaborarli all'infinito).
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Link_Auditor {
    const MENU_SLUG = 'alma-link-audit';
    const LOCK_OPTION = 'alma_link_audit_lock';
    const LOCK_TTL = 120;
    const OPTION_LAST_REPORT = 'alma_link_audit_last_report';
    const META_BACKUP = '_alma_url_pre_bonifica';
    const META_FAILED = '_alma_url_bonifica_failed';
    const BATCH_SIZE = 50;
    const TIME_BUDGET = 40;
    const MAX_REDIRECTS = 6;
    // SOLO i link GetYourGuide: gli short link Travelpayouts di altri
    // programmi (altri sottodomini *.tpx.li) non vengono toccati.
    const TPX_GYG_MARKER = 'getyourguide.tpx.li/';

    public static function init() {
        add_action('admin_post_alma_link_audit_fix', array(__CLASS__, 'handle_fix'));
        add_action('admin_post_alma_link_audit_retry', array(__CLASS__, 'handle_retry'));
        add_action('admin_post_alma_link_audit_domain', array(__CLASS__, 'handle_domain'));
    }

    /* ---------------------------------------------------------------------
     * Audit (sola lettura)
     * ------------------------------------------------------------------ */

    /**
     * Riepilogo dei domini presenti in _affiliate_url.
     */
    public static function domain_summary() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, '://', -1), '/', 1), '?', 1)) AS dominio, COUNT(*) AS n
             FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value <> '' AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             GROUP BY dominio ORDER BY n DESC LIMIT 30",
            ARRAY_A
        );
        return (array) $rows;
    }

    /**
     * Link con URL tpx.li (Travelpayouts) ancora da bonificare.
     */
    public static function tpx_links($limit = 50, $exclude_failed = true) {
        global $wpdb;
        $failed_join = $exclude_failed ? "LEFT JOIN {$wpdb->postmeta} f ON f.post_id = pm.post_id AND f.meta_key = '" . self::META_FAILED . "'" : '';
        $failed_where = $exclude_failed ? 'AND f.meta_id IS NULL' : '';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS url, p.post_title
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             {$failed_join}
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s
               AND p.post_type = 'affiliate_link' AND p.post_status = 'publish' {$failed_where}
             ORDER BY pm.post_id ASC LIMIT %d",
            '%' . $wpdb->esc_like(self::TPX_GYG_MARKER) . '%', max(1, absint($limit))
        ), ARRAY_A);
    }

    public static function tpx_count($exclude_failed = true) {
        global $wpdb;
        $failed_join = $exclude_failed ? "LEFT JOIN {$wpdb->postmeta} f ON f.post_id = pm.post_id AND f.meta_key = '" . self::META_FAILED . "'" : '';
        $failed_where = $exclude_failed ? 'AND f.meta_id IS NULL' : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             {$failed_join}
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s
               AND p.post_type = 'affiliate_link' AND p.post_status = 'publish' {$failed_where}",
            '%' . $wpdb->esc_like(self::TPX_GYG_MARKER) . '%'
        ));
    }

    public static function failed_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META_FAILED
        ));
    }

    /**
     * Link getyourguide.* SENZA partner_id: si monetizzano ma non tracciano.
     */
    public static function gyg_missing_partner_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s AND pm.meta_value NOT LIKE %s
               AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'",
            '%' . $wpdb->esc_like('getyourguide.') . '%', '%' . $wpdb->esc_like('partner_id=') . '%'
        ));
    }

    /**
     * Partner ID già in uso nei link GYG corretti: precompila la bonifica.
     */
    public static function detect_partner_id() {
        global $wpdb;
        $url = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s AND pm.meta_value LIKE %s
               AND p.post_type = 'affiliate_link' AND p.post_status = 'publish' LIMIT 1",
            '%' . $wpdb->esc_like('getyourguide.') . '%', '%' . $wpdb->esc_like('partner_id=') . '%'
        ));
        return self::extract_partner_id($url);
    }

    /**
     * partner_id da un URL. Pura, testabile.
     */
    public static function extract_partner_id($url) {
        $query = (string) (wp_parse_url((string) $url, PHP_URL_QUERY) ?: '');
        if ($query === '') { return ''; }
        wp_parse_str($query, $params);
        return sanitize_text_field((string) ($params['partner_id'] ?? ''));
    }

    /* ---------------------------------------------------------------------
     * Bonifica
     * ------------------------------------------------------------------ */

    /**
     * URL GetYourGuide "pulito": host getyourguide.*, niente query/fragment
     * (via i parametri di tracciamento Travelpayouts), path di un'attività
     * (…-t123456/). Ritorna '' se l'URL non è un prodotto GYG. Pura, testabile.
     */
    public static function clean_gyg_url($url) {
        $parts = wp_parse_url(trim((string) $url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || strpos($host, 'getyourguide.') === false || strpos($host, 'tpx.li') !== false) {
            return '';
        }
        if (!preg_match('#-t\d+/?$#', $path)) {
            return '';
        }
        return 'https://' . $host . rtrim($path, '/') . '/';
    }

    /**
     * Risolve un header Location eventualmente relativo. Pura, testabile.
     */
    public static function resolve_location_url($current_url, $location) {
        $location = trim((string) $location);
        if ($location === '') { return ''; }
        if (preg_match('#^https?://#i', $location)) { return $location; }
        $parts = wp_parse_url((string) $current_url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') { return ''; }
        if (strpos($location, '//') === 0) { return $scheme . ':' . $location; }
        if (strpos($location, '/') === 0) { return $scheme . '://' . $host . $location; }
        $base_path = (string) ($parts['path'] ?? '/');
        $base_dir = substr($base_path, 0, (int) strrpos($base_path, '/') + 1);
        return $scheme . '://' . $host . $base_dir . $location;
    }

    /**
     * Segue i redirect (max MAX_REDIRECTS) fino a un URL getyourguide.*.
     */
    public static function resolve_redirect_chain($url) {
        $current = (string) $url;
        for ($hop = 0; $hop < self::MAX_REDIRECTS; $hop++) {
            $host = strtolower((string) (wp_parse_url($current, PHP_URL_HOST) ?: ''));
            if ($host !== '' && strpos($host, 'getyourguide.') !== false && strpos($host, 'tpx.li') === false) {
                return $current;
            }
            $response = wp_remote_get($current, array(
                'timeout' => 15,
                'redirection' => 0,
                'user-agent' => 'Mozilla/5.0 (compatible; AffiliateLinkManagerAI/' . ALMA_VERSION . '; +' . home_url('/') . ')',
            ));
            if (is_wp_error($response)) {
                return new WP_Error('alma_audit_http', $response->get_error_message());
            }
            $code = wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) { $location = end($location); }
            if ($code < 300 || $code >= 400 || (string) $location === '') {
                return new WP_Error('alma_audit_noredirect', sprintf(__('La catena di redirect si è fermata su %1$s (HTTP %2$d) senza raggiungere getyourguide.', 'affiliate-link-manager-ai'), $current, $code));
            }
            $next = self::resolve_location_url($current, (string) $location);
            if ($next === '') {
                return new WP_Error('alma_audit_location', __('Header Location non interpretabile.', 'affiliate-link-manager-ai'));
            }
            $current = $next;
        }
        return new WP_Error('alma_audit_loop', __('Troppi redirect senza raggiungere getyourguide.', 'affiliate-link-manager-ai'));
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

    private static function redirect_back($type, $message) {
        set_transient('alma_link_audit_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=' . self::MENU_SLUG));
        exit;
    }

    public static function handle_fix() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_audit');
        $partner_id = sanitize_text_field(wp_unslash($_POST['partner_id'] ?? ''));
        if ($partner_id === '') {
            self::redirect_back('error', 'Inserisci il Partner ID GetYourGuide prima di avviare la bonifica.');
        }
        if (!self::acquire_lock()) {
            self::redirect_back('error', 'Una bonifica è già in corso: riprova tra qualche istante.');
        }
        $started_at = time();
        $details = array();
        $fixed = 0;
        $errors = 0;
        try {
            $links = self::tpx_links(self::BATCH_SIZE);
            foreach ($links as $link) {
                if ((time() - $started_at) > self::TIME_BUDGET) { break; }
                $post_id = (int) $link['post_id'];
                $old_url = (string) $link['url'];
                $resolved = self::resolve_redirect_chain($old_url);
                $clean = is_wp_error($resolved) ? '' : self::clean_gyg_url($resolved);
                if (is_wp_error($resolved) || $clean === '') {
                    $reason = is_wp_error($resolved) ? $resolved->get_error_message() : __('Destinazione non riconosciuta come attività GetYourGuide.', 'affiliate-link-manager-ai');
                    update_post_meta($post_id, self::META_FAILED, sanitize_text_field($reason));
                    $details[] = array('post_id' => $post_id, 'titolo' => (string) $link['post_title'], 'esito' => 'errore', 'nota' => sanitize_text_field($reason));
                    $errors++;
                } else {
                    $new_url = ALMA_Affiliate_Source_GYG_CSV_Importer::build_affiliate_url($clean, $partner_id);
                    // Backup una-tantum: se esiste già non viene sovrascritto,
                    // così l'URL originario pre-bonifica resta sempre recuperabile.
                    add_post_meta($post_id, self::META_BACKUP, $old_url, true);
                    update_post_meta($post_id, '_affiliate_url', esc_url_raw($new_url));
                    $details[] = array('post_id' => $post_id, 'titolo' => (string) $link['post_title'], 'esito' => 'corretto', 'nota' => $new_url);
                    $fixed++;
                }
                usleep(150000); // cortesia verso il servizio di redirect
            }
            update_option(self::OPTION_LAST_REPORT, array(
                'time' => current_time('mysql'),
                'fixed' => $fixed,
                'errors' => $errors,
                'remaining' => self::tpx_count(),
                'details' => $details,
            ), false);
            // Gli URL sono cambiati: il widget contestuale deve rigenerare la cache.
            if (class_exists('ALMA_Contextual_Affiliate_Widget') && method_exists('ALMA_Contextual_Affiliate_Widget', 'bump_cache_version')) {
                ALMA_Contextual_Affiliate_Widget::bump_cache_version();
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        $remaining = self::tpx_count();
        self::redirect_back($errors > 0 && $fixed === 0 ? 'error' : 'success', sprintf('Bonifica: %1$d corretti, %2$d falliti in questo giro; %3$d ancora da bonificare.', $fixed, $errors, $remaining) . ($remaining > 0 ? ' Premi di nuovo il pulsante per continuare.' : ' Completata!'));
    }

    public static function handle_retry() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_audit');
        delete_post_meta_by_key(self::META_FAILED);
        self::redirect_back('success', 'Marcature di errore azzerate: i link falliti verranno ritentati alla prossima bonifica.');
    }

    /* ---------------------------------------------------------------------
     * Dominio ufficiale GetYourGuide
     * ------------------------------------------------------------------ */

    /**
     * Link GYG pubblicati su un dominio diverso da quello preferito
     * (es. .com quando il programma partner è italiano → .it).
     */
    public static function offdomain_count() {
        global $wpdb;
        $preferred = ALMA_Affiliate_Source_GYG_CSV_Importer::preferred_domain();
        if ($preferred === '') { return 0; }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s
               AND pm.meta_value NOT LIKE %s AND pm.meta_value NOT LIKE %s
               AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'",
            '%' . $wpdb->esc_like('getyourguide.') . '%',
            '%' . $wpdb->esc_like('://' . $preferred . '/') . '%',
            '%' . $wpdb->esc_like('.tpx.li/') . '%'
        ));
    }

    /**
     * Salva la preferenza dominio e/o converte un batch di link esistenti:
     * pura sostituzione dell'host (percorso, query e partner_id restano),
     * con backup una-tantum dell'URL precedente. Nessuna chiamata HTTP.
     */
    public static function handle_domain() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_audit');
        global $wpdb;
        $domain = sanitize_text_field(wp_unslash($_POST['alma_gyg_preferred_domain'] ?? 'www.getyourguide.it'));
        if (!in_array($domain, array('www.getyourguide.it', 'www.getyourguide.com', 'keep'), true)) {
            $domain = 'www.getyourguide.it';
        }
        update_option('alma_gyg_preferred_domain', $domain, false);
        if (empty($_POST['convert_now']) || $domain === 'keep') {
            self::redirect_back('success', 'Preferenza dominio salvata.');
        }
        if (!self::acquire_lock()) {
            self::redirect_back('error', 'Un\'operazione è già in corso: riprova tra qualche istante.');
        }
        $converted = 0;
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value AS url FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_affiliate_url' AND pm.meta_value LIKE %s
                   AND pm.meta_value NOT LIKE %s AND pm.meta_value NOT LIKE %s
                   AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'
                 ORDER BY pm.post_id ASC LIMIT 50",
                '%' . $wpdb->esc_like('getyourguide.') . '%',
                '%' . $wpdb->esc_like('://' . $domain . '/') . '%',
                '%' . $wpdb->esc_like('.tpx.li/') . '%'
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $new_url = ALMA_Affiliate_Source_GYG_CSV_Importer::normalize_gyg_domain((string) $row['url'], $domain);
                if ($new_url === (string) $row['url']) { continue; }
                add_post_meta((int) $row['post_id'], self::META_BACKUP, (string) $row['url'], true);
                update_post_meta((int) $row['post_id'], '_affiliate_url', esc_url_raw($new_url));
                $converted++;
            }
            if ($converted > 0 && class_exists('ALMA_Contextual_Affiliate_Widget') && method_exists('ALMA_Contextual_Affiliate_Widget', 'bump_cache_version')) {
                ALMA_Contextual_Affiliate_Widget::bump_cache_version();
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        $remaining = self::offdomain_count();
        self::redirect_back('success', sprintf('Convertiti %1$d link a %2$s; %3$d ancora da convertire.', $converted, $domain, $remaining) . ($remaining > 0 ? ' Premi di nuovo per continuare.' : ' Conversione completata!'));
    }

    /* ---------------------------------------------------------------------
     * Pagina
     * ------------------------------------------------------------------ */

    public static function render_page() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $notice = get_transient('alma_link_audit_notice_' . get_current_user_id());
        $card = 'border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:14px 16px;margin:0 0 14px;';
        echo '<div class="wrap"><h1>🔍 Verifica link affiliati</h1>';
        if (is_array($notice) && !empty($notice['message'])) {
            delete_transient('alma_link_audit_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        echo '<p class="description" style="max-width:900px;">Il plugin pubblica esattamente l\'URL salvato in ogni Link Affiliato, senza alterarlo. Questa pagina mostra <strong>cosa è salvato davvero</strong> e corregge <strong>solo i link GetYourGuide</strong> salvati come short link Travelpayouts (<code>getyourguide.tpx.li</code>) — che non vengono tracciati dal programma partner ufficiale — trasformandoli nel deep link ufficiale con il tuo <code>partner_id</code>. I link di altri programmi/domini non vengono toccati.</p>';

        // ---- Riepilogo domini ----
        echo '<div style="' . esc_attr($card) . '"><h2 style="margin-top:0;">Domini in uso nei link pubblicati</h2>';
        $domains = self::domain_summary();
        if (empty($domains)) {
            echo '<p class="description">Nessun link affiliato pubblicato.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:560px;"><thead><tr><th>Dominio</th><th>Link</th><th></th></tr></thead><tbody>';
            foreach ($domains as $row) {
                $dominio = (string) $row['dominio'];
                $flag = '';
                if ($dominio === 'getyourguide.tpx.li') {
                    $flag = '<span style="color:#d63638;">⚠️ da bonificare</span>';
                } elseif (strpos($dominio, 'tpx.li') !== false) {
                    $flag = '<span class="description">altro programma — per ora non toccato</span>';
                }
                echo '<tr><td><code>' . esc_html($dominio) . '</code></td><td>' . esc_html((string) $row['n']) . '</td><td>' . $flag . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $missing_partner = self::gyg_missing_partner_count();
        if ($missing_partner > 0) {
            echo '<p style="color:#996800;">⚠️ ' . esc_html(sprintf('%d link getyourguide.* SENZA partner_id: portano traffico ma non commissioni.', $missing_partner)) . '</p>';
        }
        echo '</div>';

        // ---- Dominio ufficiale ----
        $preferred = ALMA_Affiliate_Source_GYG_CSV_Importer::preferred_domain();
        $offdomain = self::offdomain_count();
        echo '<div style="' . esc_attr($card) . '"><h2 style="margin-top:0;">Dominio ufficiale GetYourGuide</h2>';
        echo '<p class="description">Il programma partner dell\'editore è italiano: il riferimento ufficiale è <code>www.getyourguide.it</code>. Gli ID delle attività (<code>t…</code>) sono indipendenti dal dominio e GYG reindirizza allo slug italiano mantenendo il partner_id, quindi la conversione è una semplice sostituzione dell\'host — nessuna chiamata esterna. Il dominio scelto viene applicato anche a tutti gli import CSV futuri e alla bonifica tpx.li.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
        wp_nonce_field('alma_link_audit');
        echo '<input type="hidden" name="action" value="alma_link_audit_domain">';
        $current_setting = (string) get_option('alma_gyg_preferred_domain', 'www.getyourguide.it');
        echo '<p style="margin:0;"><label><strong>Dominio preferito</strong><br><select name="alma_gyg_preferred_domain">';
        foreach (array('www.getyourguide.it' => 'www.getyourguide.it (consigliato)', 'www.getyourguide.com' => 'www.getyourguide.com', 'keep' => 'Mantieni il dominio originale') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current_setting, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p style="margin:0;"><button class="button">Salva preferenza</button></p>';
        if ($preferred !== '' && $offdomain > 0) {
            echo '<p style="margin:0;"><button class="button button-primary" name="convert_now" value="1">Converti i prossimi ' . esc_html((string) min(50, $offdomain)) . ' link (' . esc_html((string) $offdomain) . ' su altri domini)</button></p>';
        } elseif ($preferred !== '') {
            echo '<p style="margin:0;">✅ Tutti i link GYG usano già ' . esc_html($preferred) . '.</p>';
        }
        echo '</form>';
        echo '<p class="description" style="margin-top:8px;">Anche qui l\'URL precedente viene salvato nel meta di backup <code>' . esc_html(self::META_BACKUP) . '</code> prima della modifica.</p>';
        echo '</div>';

        // ---- Bonifica tpx.li ----
        $tpx_pending = self::tpx_count();
        $failed = self::failed_count();
        echo '<div style="' . esc_attr($card) . '"><h2 style="margin-top:0;">Bonifica link getyourguide.tpx.li (Travelpayouts → GetYourGuide ufficiale)</h2>';
        if ($tpx_pending === 0 && $failed === 0) {
            echo '<p>✅ Nessun link tpx.li da bonificare.</p>';
        } else {
            echo '<p>' . esc_html(sprintf('%d link da bonificare.', $tpx_pending)) . ($failed > 0 ? ' <span style="color:#d63638;">' . esc_html(sprintf('%d falliti in run precedenti (elencati sotto, esclusi dai prossimi giri).', $failed)) . '</span>' : '') . '</p>';
            echo '<p class="description">Per ogni link il server segue i redirect fino al prodotto getyourguide.*, elimina i parametri Travelpayouts e applica il tuo partner_id. L\'URL originale viene salvato in un meta di backup (<code>' . esc_html(self::META_BACKUP) . '</code>) prima di ogni modifica. Batch fino a ' . esc_html((string) self::BATCH_SIZE) . ' link per click, entro un budget di ' . esc_html((string) self::TIME_BUDGET) . ' secondi a giro (i non elaborati restano in coda per il click successivo).</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
            wp_nonce_field('alma_link_audit');
            echo '<input type="hidden" name="action" value="alma_link_audit_fix">';
            echo '<p style="margin:0;"><label><strong>Partner ID GetYourGuide</strong><br><input type="text" name="partner_id" class="regular-text" value="' . esc_attr(self::detect_partner_id()) . '" placeholder="es. 88HSYUH"></label></p>';
            echo '<p style="margin:0;"><button class="button button-primary">Bonifica i prossimi ' . esc_html((string) min(self::BATCH_SIZE, max(1, $tpx_pending))) . ' link</button></p>';
            echo '</form>';
            if ($failed > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
                wp_nonce_field('alma_link_audit');
                echo '<input type="hidden" name="action" value="alma_link_audit_retry"><button class="button">Ritenta i falliti</button></form>';
            }
        }
        $report = get_option(self::OPTION_LAST_REPORT, null);
        if (is_array($report) && !empty($report['details'])) {
            echo '<h3>Ultimo giro — ' . esc_html((string) $report['time']) . ' (corretti ' . esc_html((string) $report['fixed']) . ', falliti ' . esc_html((string) $report['errors']) . ', rimanenti ' . esc_html((string) $report['remaining']) . ')</h3>';
            echo '<table class="widefat striped"><thead><tr><th>Link</th><th>Esito</th><th>Dettaglio</th></tr></thead><tbody>';
            foreach ((array) $report['details'] as $detail) {
                $edit = get_edit_post_link((int) $detail['post_id'], 'raw');
                echo '<tr><td><a href="' . esc_url($edit) . '">' . esc_html((string) $detail['titolo']) . '</a></td>';
                echo '<td>' . ($detail['esito'] === 'corretto' ? '✅' : '<span style="color:#d63638;">⚠️</span>') . '</td>';
                echo '<td style="word-break:break-all;">' . esc_html((string) $detail['nota']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // ---- Elenco tpx ancora presenti ----
        $tpx_list = self::tpx_links(30, false);
        if (!empty($tpx_list)) {
            echo '<div style="' . esc_attr($card) . '"><h2 style="margin-top:0;">Link tpx.li presenti (primi 30)</h2>';
            echo '<table class="widefat striped"><thead><tr><th>Link</th><th>URL salvato</th></tr></thead><tbody>';
            foreach ($tpx_list as $row) {
                echo '<tr><td><a href="' . esc_url(get_edit_post_link((int) $row['post_id'], 'raw')) . '">' . esc_html((string) $row['post_title']) . '</a></td><td style="word-break:break-all;"><code>' . esc_html((string) $row['url']) . '</code></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }
}
