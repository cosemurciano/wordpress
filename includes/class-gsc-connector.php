<?php
/**
 * Fase 7.1 — Connettore Google Search Console per l'AI Content Agent.
 *
 * Autenticazione via SERVICE ACCOUNT (nessun flusso OAuth interattivo):
 * 1. su Google Cloud crea un service account e una chiave JSON;
 * 2. abilita la "Search Console API" nel progetto;
 * 3. in Search Console aggiungi l'email del service account come utente
 *    (accesso completo o limitato) della proprietà;
 * 4. in wp-config.php definisci UNA delle due costanti:
 *    define('ALMA_GSC_SERVICE_ACCOUNT_FILE', '/percorso/fuori-webroot/sa.json');
 *    define('ALMA_GSC_SERVICE_ACCOUNT_JSON', '{"type":"service_account",...}');
 * Le credenziali non toccano mai il database.
 *
 * Dati estratti (quelli realmente utili all'agente di ideazione):
 * - top_queries: con quali ricerche gli utenti trovano il sito (90gg);
 * - rising_queries: query in crescita (28gg vs 28gg precedenti) → trend;
 * - opportunities: query con molte impression ma posizione debole (8-30)
 *   → i contenuti da creare o rafforzare, il segnale più prezioso;
 * - top_pages: le pagine che portano più click (contesto/link interni).
 * Lo snapshot è salvato in option e rigenerato al massimo una volta al
 * giorno (o su richiesta): l'agente lo legge con il tool
 * analizza_ricerche_google.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_GSC_Connector {
    const OPTION_PROPERTY = 'alma_gsc_property';
    const OPTION_SNAPSHOT = 'alma_gsc_snapshot';
    const TOKEN_TRANSIENT = 'alma_gsc_access_token';
    const SNAPSHOT_TTL = DAY_IN_SECONDS;
    const MIN_IMPRESSIONS_OPPORTUNITY = 50;

    public static function init() {
        add_action('admin_post_alma_gsc_verify', array(__CLASS__, 'handle_verify'));
        add_action('admin_post_alma_gsc_refresh', array(__CLASS__, 'handle_refresh'));
    }

    /* ---------------------------------------------------------------------
     * Credenziali e configurazione
     * ------------------------------------------------------------------ */

    public static function credentials_json() {
        if (defined('ALMA_GSC_SERVICE_ACCOUNT_FILE') && is_readable(ALMA_GSC_SERVICE_ACCOUNT_FILE)) {
            return (string) file_get_contents(ALMA_GSC_SERVICE_ACCOUNT_FILE);
        }
        if (defined('ALMA_GSC_SERVICE_ACCOUNT_JSON')) {
            return (string) ALMA_GSC_SERVICE_ACCOUNT_JSON;
        }
        return '';
    }

    /**
     * Diagnostica granulare delle credenziali: distingue costante mancante,
     * file inesistente, file non leggibile (permessi/open_basedir) e JSON
     * non valido — "costante non definita" da sola non basta a capire dove
     * intervenire.
     */
    public static function credentials_status() {
        if (defined('ALMA_GSC_SERVICE_ACCOUNT_FILE')) {
            $path = (string) ALMA_GSC_SERVICE_ACCOUNT_FILE;
            if (!file_exists($path)) {
                return array('state' => 'error', 'message' => sprintf(__('Costante definita ma il file NON esiste per PHP: %s — controlla il percorso assoluto (e eventuali restrizioni open_basedir).', 'affiliate-link-manager-ai'), $path));
            }
            if (!is_readable($path)) {
                return array('state' => 'error', 'message' => sprintf(__('File presente ma NON leggibile dall\'utente del web server: %s — sistem i permessi (es. chmod 640 con owner corretto).', 'affiliate-link-manager-ai'), $path));
            }
            return self::validate_credentials_json((string) file_get_contents($path), sprintf(__('file %s', 'affiliate-link-manager-ai'), $path));
        }
        if (defined('ALMA_GSC_SERVICE_ACCOUNT_JSON')) {
            return self::validate_credentials_json((string) ALMA_GSC_SERVICE_ACCOUNT_JSON, __('costante JSON', 'affiliate-link-manager-ai'));
        }
        return array('state' => 'warning', 'message' => __('Nessuna costante definita. Verifica che il define() sia nel wp-config.php del sito, PRIMA della riga "/* That\'s all, stop editing! */" (dopo quella riga le costanti non vengono caricate).', 'affiliate-link-manager-ai'));
    }

    private static function validate_credentials_json($json, $source_label) {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return array('state' => 'error', 'message' => sprintf(__('%s: contenuto non è JSON valido.', 'affiliate-link-manager-ai'), $source_label));
        }
        if (($data['type'] ?? '') !== 'service_account') {
            return array('state' => 'error', 'message' => sprintf(__('%s: non è una chiave service account (type="%s") — serve il JSON con "type":"service_account".', 'affiliate-link-manager-ai'), $source_label, (string)($data['type'] ?? '')));
        }
        if (empty($data['client_email']) || empty($data['private_key'])) {
            return array('state' => 'error', 'message' => sprintf(__('%s: mancano client_email o private_key.', 'affiliate-link-manager-ai'), $source_label));
        }
        return array('state' => 'success', 'message' => sprintf(__('OK (%1$s) — service account: %2$s. Ricorda di aggiungere questa email come utente della proprietà in Search Console.', 'affiliate-link-manager-ai'), $source_label, sanitize_text_field((string) $data['client_email'])));
    }

    public static function is_configured() {
        return self::credentials_json() !== '' && self::get_property() !== '';
    }

    public static function get_property() {
        $property = trim((string) get_option(self::OPTION_PROPERTY, ''));
        if ($property === '') { return ''; }
        if (strpos($property, 'sc-domain:') === 0) { return $property; }
        $url = esc_url_raw($property);
        return $url !== '' ? trailingslashit($url) : '';
    }

    public static function save_settings($post) {
        $property = sanitize_text_field(wp_unslash($post[self::OPTION_PROPERTY] ?? ''));
        update_option(self::OPTION_PROPERTY, $property, false);
    }

    /* ---------------------------------------------------------------------
     * OAuth service account (JWT RS256 → access token)
     * ------------------------------------------------------------------ */

    private static function base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Ripara i danni tipici del copia-incolla del PEM: "\n" letterali
     * (backslash+n) al posto degli a-capo, \r residui, spazi attorno ai
     * marker BEGIN/END. Una chiave già corretta resta invariata.
     */
    public static function normalize_private_key($key) {
        $key = str_replace(array('\\n', "\r"), array("\n", ''), $key);
        $key = trim($key);
        if (strpos($key, "\n") === false && preg_match('/^(-----BEGIN [A-Z ]+-----)(.+)(-----END [A-Z ]+-----)$/s', $key, $m)) {
            // PEM finito su una riga sola: ricostruisce le righe da 64 caratteri.
            $key = $m[1] . "\n" . chunk_split(preg_replace('/\s+/', '', $m[2]), 64, "\n") . $m[3] . "\n";
        } elseif (strpos($key, '-----BEGIN') === false && preg_match('/^[A-Za-z0-9+\/\s=]+$/', $key)) {
            // Marker BEGIN/END rimossi per errore: la chiave Google è PKCS#8,
            // ricostruisce il PEM attorno al corpo base64.
            $key = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END PRIVATE KEY-----\n";
        }
        return $key;
    }

    private static function get_access_token() {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && $cached !== '') { return $cached; }

        $credentials = json_decode(self::credentials_json(), true);
        if (!is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            return new WP_Error('alma_gsc_credentials', __('Credenziali service account mancanti o non valide (client_email/private_key).', 'affiliate-link-manager-ai'));
        }
        if (!function_exists('openssl_sign')) {
            return new WP_Error('alma_gsc_openssl', __('Estensione OpenSSL non disponibile sul server.', 'affiliate-link-manager-ai'));
        }
        $now = time();
        $header = self::base64url(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claims = self::base64url(wp_json_encode(array(
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        )));
        // Normalizza la chiave: incollando il JSON in wp-config gli "\n"
        // possono arrivare come backslash letterali (o con \r) e il PEM
        // risulta su una riga sola — OpenSSL lo rifiuta.
        $private_key = self::normalize_private_key((string) $credentials['private_key']);
        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature, $private_key, 'sha256WithRSAEncryption')) {
            $openssl_detail = '';
            while (($openssl_error = openssl_error_string()) !== false) { $openssl_detail = $openssl_error; }
            return new WP_Error('alma_gsc_sign', __('Firma JWT fallita: chiave privata non valida.', 'affiliate-link-manager-ai') . ($openssl_detail !== '' ? ' [' . sanitize_text_field($openssl_detail) . ']' : '') . ' ' . __('Suggerimento: usa la costante ALMA_GSC_SERVICE_ACCOUNT_FILE con il file JSON originale non modificato.', 'affiliate-link-manager-ai'));
        }
        $jwt = $header . '.' . $claims . '.' . self::base64url($signature);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('alma_gsc_token', $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            $detail = is_array($data) ? sanitize_text_field((string)($data['error_description'] ?? ($data['error'] ?? ''))) : '';
            return new WP_Error('alma_gsc_token', __('Token non ottenuto da Google.', 'affiliate-link-manager-ai') . ($detail !== '' ? ' — ' . $detail : ''));
        }
        set_transient(self::TOKEN_TRANSIENT, $token, 50 * MINUTE_IN_SECONDS);
        return $token;
    }

    /* ---------------------------------------------------------------------
     * Search Analytics API
     * ------------------------------------------------------------------ */

    public static function search_analytics($dimensions, $start_date, $end_date, $row_limit = 25) {
        $property = self::get_property();
        if ($property === '') {
            return new WP_Error('alma_gsc_property', __('Proprietà Search Console non configurata.', 'affiliate-link-manager-ai'));
        }
        $token = self::get_access_token();
        if (is_wp_error($token)) { return $token; }

        $response = wp_remote_post('https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query', array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'startDate' => $start_date,
                'endDate' => $end_date,
                'dimensions' => array_values((array) $dimensions),
                'rowLimit' => max(1, min(1000, absint($row_limit))),
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('alma_gsc_api', $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $detail = is_array($data) ? sanitize_text_field((string)($data['error']['message'] ?? '')) : '';
            if ($code === 403) {
                $detail .= ' ' . __('Verifica che l\'email del service account sia stata aggiunta come utente della proprietà in Search Console.', 'affiliate-link-manager-ai');
            }
            return new WP_Error('alma_gsc_api', sprintf(__('Errore Search Console (HTTP %d).', 'affiliate-link-manager-ai'), $code) . ' ' . $detail);
        }
        $rows = array();
        foreach ((array) ($data['rows'] ?? array()) as $row) {
            $rows[] = array(
                'key' => sanitize_text_field((string) ($row['keys'][0] ?? '')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2),
                'position' => round((float) ($row['position'] ?? 0), 1),
            );
        }
        return $rows;
    }

    /* ---------------------------------------------------------------------
     * Snapshot per l'agente
     * ------------------------------------------------------------------ */

    public static function get_snapshot($force_refresh = false) {
        $snapshot = get_option(self::OPTION_SNAPSHOT, null);
        $fresh = is_array($snapshot) && !empty($snapshot['generated_at'])
            && (current_time('timestamp') - strtotime($snapshot['generated_at'])) < self::SNAPSHOT_TTL;
        if ($fresh && !$force_refresh) { return $snapshot; }
        $built = self::build_snapshot();
        if (is_wp_error($built)) {
            // Meglio uno snapshot vecchio di un errore, se esiste.
            return is_array($snapshot) ? $snapshot : $built;
        }
        return $built;
    }

    public static function build_snapshot() {
        if (!self::is_configured()) {
            return new WP_Error('alma_gsc_config', __('Search Console non configurata (costanti wp-config + proprietà).', 'affiliate-link-manager-ai'));
        }
        $now = current_time('timestamp');
        // GSC ha ~2 giorni di ritardo sui dati.
        $end = gmdate('Y-m-d', $now - 2 * DAY_IN_SECONDS);
        $start_90 = gmdate('Y-m-d', $now - 92 * DAY_IN_SECONDS);
        $start_28 = gmdate('Y-m-d', $now - 30 * DAY_IN_SECONDS);
        $prev_start = gmdate('Y-m-d', $now - 58 * DAY_IN_SECONDS);
        $prev_end = gmdate('Y-m-d', $now - 31 * DAY_IN_SECONDS);

        $queries_90 = self::search_analytics(array('query'), $start_90, $end, 250);
        if (is_wp_error($queries_90)) { return $queries_90; }
        $queries_recent = self::search_analytics(array('query'), $start_28, $end, 250);
        if (is_wp_error($queries_recent)) { return $queries_recent; }
        $queries_previous = self::search_analytics(array('query'), $prev_start, $prev_end, 250);
        if (is_wp_error($queries_previous)) { return $queries_previous; }
        $pages = self::search_analytics(array('page'), $start_28, $end, 15);
        if (is_wp_error($pages)) { $pages = array(); }

        // Query in crescita: confronto 28gg vs 28gg precedenti.
        $previous_map = array();
        foreach ($queries_previous as $row) { $previous_map[$row['key']] = $row; }
        $rising = array();
        foreach ($queries_recent as $row) {
            if ($row['impressions'] < 20) { continue; }
            $before = $previous_map[$row['key']] ?? array('clicks' => 0, 'impressions' => 0);
            $growth = $row['impressions'] - (int) $before['impressions'];
            if ($growth > 0 && ($before['impressions'] === 0 || $growth >= max(10, (int) $before['impressions'] * 0.5))) {
                $rising[] = array_merge($row, array('impressions_prima' => (int) $before['impressions'], 'crescita_impressions' => $growth));
            }
        }
        usort($rising, function ($a, $b) { return $b['crescita_impressions'] <=> $a['crescita_impressions']; });

        // Opportunità: tante impression, posizione debole (pagina 1 bassa / pagina 2-3).
        $opportunities = array_values(array_filter($queries_90, function ($row) {
            return $row['impressions'] >= self::MIN_IMPRESSIONS_OPPORTUNITY && $row['position'] >= 8 && $row['position'] <= 30;
        }));
        usort($opportunities, function ($a, $b) { return $b['impressions'] <=> $a['impressions']; });

        usort($queries_90, function ($a, $b) { return $b['clicks'] <=> $a['clicks']; });

        $snapshot = array(
            'property' => self::get_property(),
            'generated_at' => current_time('mysql'),
            'period' => array('start' => $start_90, 'end' => $end),
            'top_queries' => array_slice($queries_90, 0, 20),
            'rising_queries' => array_slice($rising, 0, 15),
            'opportunities' => array_slice($opportunities, 0, 20),
            'top_pages' => array_slice($pages, 0, 10),
        );
        update_option(self::OPTION_SNAPSHOT, $snapshot, false);
        return $snapshot;
    }

    /**
     * Payload compatto per il tool dell'agente.
     */
    public static function agent_payload() {
        if (!self::is_configured()) {
            return array('error' => 'Search Console non configurata: impostala in Impostazioni AI Content → Search Console.');
        }
        $snapshot = self::get_snapshot();
        if (is_wp_error($snapshot)) {
            return array('error' => sanitize_text_field($snapshot->get_error_message()));
        }
        return array(
            'dati_aggiornati_al' => $snapshot['generated_at'],
            'query_top_per_click_90gg' => $snapshot['top_queries'],
            'query_in_crescita_28gg' => $snapshot['rising_queries'],
            'opportunita_impression_alte_posizione_debole' => $snapshot['opportunities'],
            'pagine_top_28gg' => $snapshot['top_pages'],
            'nota' => 'Le "opportunita" sono query dove il sito appare (posizione 8-30) ma non converte in click: creare o rafforzare contenuti mirati su queste query ha il ritorno più alto.',
        );
    }

    /* ---------------------------------------------------------------------
     * Azioni admin
     * ------------------------------------------------------------------ */

    private static function redirect_back($type, $message) {
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=search-console'));
        exit;
    }

    public static function handle_verify() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_gsc_admin');
        if (self::credentials_json() === '') {
            self::redirect_back('error', 'Costanti ALMA_GSC_SERVICE_ACCOUNT_FILE/JSON non definite in wp-config.php.');
        }
        $rows = self::search_analytics(array('query'), gmdate('Y-m-d', current_time('timestamp') - 9 * DAY_IN_SECONDS), gmdate('Y-m-d', current_time('timestamp') - 2 * DAY_IN_SECONDS), 1);
        if (is_wp_error($rows)) {
            self::redirect_back('error', 'Verifica fallita: ' . $rows->get_error_message());
        }
        self::redirect_back('success', 'Connessione OK: la proprietà risponde (' . count($rows) . ' riga di esempio ricevuta).');
    }

    public static function handle_refresh() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_gsc_admin');
        $snapshot = self::build_snapshot();
        if (is_wp_error($snapshot)) {
            self::redirect_back('error', 'Aggiornamento fallito: ' . $snapshot->get_error_message());
        }
        self::redirect_back('success', sprintf('Dati Search Console aggiornati: %d query top, %d in crescita, %d opportunità.', count($snapshot['top_queries']), count($snapshot['rising_queries']), count($snapshot['opportunities'])));
    }

    /* ---------------------------------------------------------------------
     * Tab impostazioni
     * ------------------------------------------------------------------ */

    public static function render_settings_tab() {
        $credentials_status = self::credentials_status();
        $snapshot = get_option(self::OPTION_SNAPSHOT, null);

        echo '<h2>Google Search Console</h2>';
        echo '<div class="alma-agent-card" style="max-width:900px;"><h3>Come configurare (una sola volta)</h3><ol>';
        echo '<li>Su <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> crea (o usa) un progetto, abilita la <strong>Search Console API</strong> e crea un <strong>service account</strong> con una chiave JSON.</li>';
        echo '<li>In <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a> → Impostazioni → Utenti e autorizzazioni, aggiungi l\'email del service account (es. <code>nome@progetto.iam.gserviceaccount.com</code>) come utente della proprietà.</li>';
        echo '<li>In <code>wp-config.php</code> definisci UNA delle due costanti:<br><code>define( \'ALMA_GSC_SERVICE_ACCOUNT_FILE\', \'/percorso/fuori-webroot/service-account.json\' );</code> (consigliata)<br>oppure <code>define( \'ALMA_GSC_SERVICE_ACCOUNT_JSON\', \'{"type":"service_account",...}\' );</code></li>';
        echo '<li>Indica la proprietà qui sotto, salva, poi <strong>Verifica connessione</strong> e <strong>Aggiorna dati ora</strong>.</li></ol>';
        echo '<p><strong>Cosa ci fa l\'agente:</strong> legge le query reali con cui gli utenti trovano il sito (top, in crescita, e soprattutto le <em>opportunità</em>: query con molte impression ma posizione debole) e le usa per proporre idee di contenuto con domanda già dimostrata.</p></div>';

        $badge_class = $credentials_status['state'] === 'success' ? 'is-success' : 'is-warning';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Stato credenziali</th><td><span class="alma-badge '.esc_attr($badge_class).'">'.esc_html($credentials_status['state'] === 'success' ? 'OK' : ($credentials_status['state'] === 'error' ? 'errore' : 'non configurate')).'</span> '.esc_html($credentials_status['message']).'<p class="description">Le credenziali vivono solo in wp-config.php / file, mai nel database. La costante va inserita PRIMA della riga <code>/* That\'s all, stop editing! */</code>.</p></td></tr>';
        echo '</table>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_gsc_settings">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="'.esc_attr(self::OPTION_PROPERTY).'">Proprietà</label></th><td>';
        echo '<input type="text" class="regular-text" name="'.esc_attr(self::OPTION_PROPERTY).'" id="'.esc_attr(self::OPTION_PROPERTY).'" value="'.esc_attr((string) get_option(self::OPTION_PROPERTY, '')).'" placeholder="https://www.sothra.it/ oppure sc-domain:sothra.it">';
        echo '<p class="description">Esattamente come appare in Search Console (URL con slash finale, o proprietà dominio con prefisso sc-domain:).</p></td></tr>';
        echo '</table><p><button class="button button-primary">Salva</button></p></form>';

        echo '<div class="alma-actions-inline" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px;">';
        foreach (array('alma_gsc_verify' => 'Verifica connessione', 'alma_gsc_refresh' => 'Aggiorna dati ora') as $action => $label) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('alma_gsc_admin');
            echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><button class="button">'.esc_html($label).'</button></form>';
        }
        echo '</div>';

        if (is_array($snapshot)) {
            echo '<h3>Ultimo snapshot — '.esc_html((string)($snapshot['generated_at'] ?? '')).'</h3>';
            $sections = array(
                'opportunities' => 'Opportunità (impression alte, posizione 8-30) — il segnale più utile',
                'rising_queries' => 'Query in crescita (28gg vs precedenti)',
                'top_queries' => 'Query top per click (90gg)',
            );
            foreach ($sections as $key => $title) {
                $rows = array_slice((array)($snapshot[$key] ?? array()), 0, 10);
                echo '<h4>'.esc_html($title).'</h4>';
                if (empty($rows)) { echo '<p class="description">Nessun dato.</p>'; continue; }
                echo '<table class="widefat striped" style="max-width:860px;"><thead><tr><th>Query</th><th>Click</th><th>Impression</th><th>CTR</th><th>Posizione</th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    echo '<tr><td>'.esc_html($row['key']).'</td><td>'.(int)$row['clicks'].'</td><td>'.(int)$row['impressions'].'</td><td>'.esc_html($row['ctr']).'%</td><td>'.esc_html($row['position']).'</td></tr>';
                }
                echo '</tbody></table>';
            }
        } else {
            echo '<p class="description">Nessuno snapshot ancora generato.</p>';
        }
    }
}
