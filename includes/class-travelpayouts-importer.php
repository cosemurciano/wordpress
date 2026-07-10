<?php
/**
 * Importazione massiva di link affiliati da CSV con conversione Travelpayouts.
 *
 * Flusso:
 *  1) l'admin carica un CSV con colonne: Nome, descrizione, città, url,
 *     tipologia link (intestazioni riconosciute in modo flessibile);
 *  2) ogni riga crea un Link Affiliato (titolo, descrizione, tipologia,
 *     geolocalizzazione dalla città) con l'URL ORIGINALE e un flag di
 *     conversione pendente;
 *  3) un job in background chiama l'API Travelpayouts
 *     (POST https://api.travelpayouts.com/links/v1/create, max 10 link per
 *     richiesta, 100 richieste/minuto) che converte l'URL diretto in URL
 *     affiliato (partner_url) e AGGIORNA il link affiliato.
 *
 * Il job è interrompibile, con cursore e lock atomico a TTL (mai job
 * invisibili): stato e ultimo esito sono mostrati nella pagina.
 */
if (!defined('ABSPATH')) { exit; }

class ALMA_Travelpayouts_Importer {
    const MENU_SLUG = 'alma-travelpayouts-import';
    const OPTION_TOKEN = 'alma_tp_api_token';
    const OPTION_TRS = 'alma_tp_trs';
    const OPTION_MARKER = 'alma_tp_marker';
    const OPTION_SHORTEN = 'alma_tp_shorten';
    const OPTION_STATUS = 'alma_tp_import_status';   // ultimo esito import + conversione
    const CRON_HOOK = 'alma_tp_convert_links';
    const LOCK_OPTION = 'alma_tp_convert_lock';
    const LOCK_TTL = 300;
    const META_PENDING = '_alma_tp_convert_pending';   // 1 = da convertire
    const META_ORIGINAL_URL = '_alma_tp_original_url';  // URL diretto pre-conversione
    const META_CONVERT_ERROR = '_alma_tp_convert_error';
    const SOURCE = 'travelpayouts_csv';
    const API_ENDPOINT = 'https://api.travelpayouts.com/links/v1/create';
    const API_CHUNK = 10;         // max link per richiesta (limite API)
    const CONVERT_BATCH = 40;     // link per esecuzione del job (4 richieste API)
    const MAX_ROWS = 1000;        // tetto per upload

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 60);
        add_action('admin_post_alma_tp_save_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_alma_tp_upload', array(__CLASS__, 'handle_upload'));
        add_action('admin_post_alma_tp_convert_now', array(__CLASS__, 'handle_convert_now'));
        add_action('admin_post_alma_tp_download_demo', array(__CLASS__, 'handle_download_demo'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_conversion'));
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Import Travelpayouts', 'affiliate-link-manager-ai'),
            __('Import Travelpayouts', 'affiliate-link-manager-ai'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    /* ---------------------------------------------------------------------
     * Configurazione
     * ------------------------------------------------------------------ */

    public static function is_configured() {
        return trim((string) get_option(self::OPTION_TOKEN, '')) !== ''
            && absint(get_option(self::OPTION_TRS, 0)) > 0
            && absint(get_option(self::OPTION_MARKER, 0)) > 0;
    }

    public static function pending_count() {
        $q = new WP_Query(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'meta_query' => array(array('key' => self::META_PENDING, 'value' => '1')),
        ));
        return (int) $q->found_posts;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_settings');
        update_option(self::OPTION_TOKEN, trim(sanitize_text_field(wp_unslash($_POST['tp_token'] ?? ''))), false);
        update_option(self::OPTION_TRS, absint($_POST['tp_trs'] ?? 0), false);
        update_option(self::OPTION_MARKER, absint($_POST['tp_marker'] ?? 0), false);
        update_option(self::OPTION_SHORTEN, empty($_POST['tp_shorten']) ? '0' : '1', false);
        self::redirect_back(array('type' => 'success', 'message' => __('Impostazioni Travelpayouts salvate.', 'affiliate-link-manager-ai')));
    }

    /* ---------------------------------------------------------------------
     * Import CSV
     * ------------------------------------------------------------------ */

    public static function handle_upload() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_upload');
        if (empty($_FILES['tp_csv']['tmp_name']) || !is_uploaded_file($_FILES['tp_csv']['tmp_name'])) {
            self::redirect_back(array('type' => 'error', 'message' => __('Nessun file CSV caricato.', 'affiliate-link-manager-ai')));
        }
        $rows = self::parse_csv($_FILES['tp_csv']['tmp_name']);
        if (empty($rows)) {
            self::redirect_back(array('type' => 'error', 'message' => __('CSV vuoto o intestazioni non riconosciute (attese: Nome, descrizione, città, url, tipologia link).', 'affiliate-link-manager-ai')));
        }
        $created = 0; $skipped = 0; $link_ids = array();
        foreach ($rows as $row) {
            $id = self::create_link_from_row($row);
            if ($id > 0) { $created++; $link_ids[] = $id; } else { $skipped++; }
        }
        $status = array(
            'imported_at' => current_time('mysql'),
            'created' => $created,
            'skipped' => $skipped,
            'convert_state' => self::is_configured() ? 'in_coda' : 'in_attesa_config',
            'convert_detail' => '',
            'converted' => 0,
            'convert_errors' => 0,
            'convert_finished_at' => '',
        );
        update_option(self::OPTION_STATUS, $status, false);
        // Avvia subito la conversione se le API sono configurate.
        if ($created > 0 && self::is_configured()) {
            self::schedule_conversion();
        }
        $msg = sprintf(__('Import completato: %1$d link creati, %2$d righe saltate.', 'affiliate-link-manager-ai'), $created, $skipped);
        if ($created > 0 && !self::is_configured()) {
            $msg .= ' ' . __('Configura le API Travelpayouts per convertire gli URL in link affiliati.', 'affiliate-link-manager-ai');
        } elseif ($created > 0) {
            $msg .= ' ' . __('Conversione degli URL in corso in background.', 'affiliate-link-manager-ai');
        }
        self::redirect_back(array('type' => $created > 0 ? 'success' : 'error', 'message' => $msg));
    }

    /**
     * Legge il CSV e mappa le intestazioni (italiane/flessibili) sui campi
     * interni. Rileva il delimitatore tra , e ; .
     *
     * @return array<int,array{title,description,city,url,link_type}>
     */
    public static function parse_csv($path) {
        $handle = @fopen($path, 'r');
        if (!$handle) { return array(); }
        $first = fgets($handle);
        if ($first === false) { fclose($handle); return array(); }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // via BOM
        $delimiter = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
        $header = str_getcsv($first, $delimiter, '"', '\\');
        $map = self::map_headers($header);
        if (!isset($map['url']) || (!isset($map['title']) && !isset($map['url']))) { fclose($handle); return array(); }
        $rows = array();
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($rows) >= self::MAX_ROWS) { break; }
            $get = function ($key) use ($map, $data) {
                return isset($map[$key], $data[$map[$key]]) ? trim((string) $data[$map[$key]]) : '';
            };
            $url = $get('url');
            if ($url === '') { continue; }
            $rows[] = array(
                'title' => $get('title'),
                'description' => $get('description'),
                'city' => $get('city'),
                'url' => $url,
                'link_type' => $get('link_type'),
            );
        }
        fclose($handle);
        return $rows;
    }

    /** Mappa nome-colonna → indice, tollerante a varianti/accenti/maiuscole. */
    private static function map_headers($header) {
        $aliases = array(
            'title' => array('nome', 'titolo', 'title', 'name'),
            'description' => array('descrizione', 'description', 'desc'),
            'city' => array('citta', 'città', 'city', 'localita', 'località'),
            'url' => array('url', 'link', 'indirizzo'),
            'link_type' => array('tipologia link', 'tipologia', 'tipo', 'link type', 'link_type', 'type'),
        );
        $map = array();
        foreach ((array) $header as $i => $col) {
            $norm = self::normalize_header((string) $col);
            foreach ($aliases as $field => $names) {
                if (isset($map[$field])) { continue; }
                foreach ($names as $n) {
                    if ($norm === self::normalize_header($n)) { $map[$field] = $i; break; }
                }
            }
        }
        return $map;
    }

    private static function normalize_header($s) {
        $s = strtolower(trim((string) $s));
        $s = strtr($s, array('à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u'));
        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Crea un Link Affiliato da una riga: titolo, descrizione, URL originale
     * (in attesa di conversione), tipologia e geolocalizzazione dalla città.
     *
     * @return int ID del post creato, 0 se scartata.
     */
    public static function create_link_from_row($row) {
        $url = esc_url_raw((string) ($row['url'] ?? ''));
        if ($url === '' || !wp_http_validate_url($url)) { return 0; }
        $title = sanitize_text_field((string) ($row['title'] ?? '')) ?: wp_parse_url($url, PHP_URL_HOST);
        $post_id = wp_insert_post(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => wp_kses_post((string) ($row['description'] ?? '')),
        ), true);
        if (is_wp_error($post_id) || !$post_id) { return 0; }

        update_post_meta($post_id, '_affiliate_url', $url);
        update_post_meta($post_id, self::META_ORIGINAL_URL, $url);
        update_post_meta($post_id, self::META_PENDING, '1');
        update_post_meta($post_id, '_alma_source_provenance', self::SOURCE);
        // Contesto AI per bozze/arricchimento (descrizione).
        $desc = trim(wp_strip_all_tags((string) ($row['description'] ?? '')));
        if ($desc !== '') { update_post_meta($post_id, '_alma_ai_context', $desc); }

        // Tipologia: crea/associa il termine link_type.
        $type_name = sanitize_text_field((string) ($row['link_type'] ?? ''));
        if ($type_name !== '') {
            $term = self::resolve_link_type_term($type_name);
            if ($term > 0) { wp_set_object_terms($post_id, array($term), 'link_type', false); }
        }
        // Geolocalizzazione dalla città (in coda al geocoding automatico).
        $city = sanitize_text_field((string) ($row['city'] ?? ''));
        if ($city !== '') { self::assign_city_geo($post_id, $city); }

        return (int) $post_id;
    }

    private static function resolve_link_type_term($name) {
        $existing = get_term_by('name', $name, 'link_type');
        if ($existing && !is_wp_error($existing)) { return (int) $existing->term_id; }
        $created = wp_insert_term($name, 'link_type');
        return (is_array($created) && !empty($created['term_id'])) ? (int) $created['term_id'] : 0;
    }

    private static function assign_city_geo($post_id, $city) {
        if (!class_exists('ALMA_Geo_Index_Store')) { return; }
        $store = new ALMA_Geo_Index_Store();
        $store->save_geo_meta_for_object((int) $post_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK, array(
            'primary_location' => array(
                'name' => $city,
                'canonical_name' => $city,
                'type' => 'city',
                'geocoding_status' => 'pending',
                'suggested_geocoding_query' => $city,
            ),
        ), self::SOURCE);
    }

    /* ---------------------------------------------------------------------
     * Conversione via API (job in background)
     * ------------------------------------------------------------------ */

    public static function schedule_conversion() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, array('run-' . time()));
        }
        if (function_exists('spawn_cron')) { spawn_cron(); }
    }

    public static function handle_convert_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_convert_now');
        if (!self::is_configured()) {
            self::redirect_back(array('type' => 'error', 'message' => __('Configura prima token, trs e marker Travelpayouts.', 'affiliate-link-manager-ai')));
        }
        // Evento con argomento unico: WP-Cron non lo deduplica come identico.
        wp_schedule_single_event(time() + 2, self::CRON_HOOK, array('manual-' . time()));
        if (function_exists('spawn_cron')) { spawn_cron(); }
        self::redirect_back(array('type' => 'success', 'message' => __('Conversione avviata: gli URL verranno convertiti in background.', 'affiliate-link-manager-ai')));
    }

    /** ID dei link ancora da convertire (limite $limit). */
    private static function pending_ids($limit) {
        $q = new WP_Query(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => max(1, (int) $limit),
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(array('key' => self::META_PENDING, 'value' => '1')),
        ));
        return array_map('intval', (array) $q->posts);
    }

    /**
     * Runner del job: converte fino a CONVERT_BATCH link a chiamata, in
     * blocchi di API_CHUNK (10). Lock atomico con TTL; se restano link
     * pendenti richiama sé stesso.
     */
    public static function run_conversion() {
        if (!self::is_configured()) { return; }
        if (!add_option(self::LOCK_OPTION, (string) time(), '', 'no')) {
            $started = absint(get_option(self::LOCK_OPTION, 0));
            if ($started > 0 && (time() - $started) <= self::LOCK_TTL) { return; }
            update_option(self::LOCK_OPTION, (string) time(), false);
        }
        $status = (array) get_option(self::OPTION_STATUS, array());
        $converted = (int) ($status['converted'] ?? 0);
        $errors = (int) ($status['convert_errors'] ?? 0);
        try {
            $ids = self::pending_ids(self::CONVERT_BATCH);
            foreach (array_chunk($ids, self::API_CHUNK) as $chunk) {
                $url_by_id = array();
                foreach ($chunk as $id) {
                    $u = (string) get_post_meta($id, self::META_ORIGINAL_URL, true);
                    if ($u === '') { $u = (string) get_post_meta($id, '_affiliate_url', true); }
                    if ($u !== '') { $url_by_id[$id] = $u; }
                }
                if (empty($url_by_id)) { continue; }
                $result = self::api_convert(array_values($url_by_id));
                if (!empty($result['fatal'])) {
                    // Errore globale (token/trs/marker): ferma e riporta.
                    $status['convert_state'] = 'errore';
                    $status['convert_detail'] = sanitize_text_field((string) $result['fatal']);
                    $status['converted'] = $converted;
                    $status['convert_errors'] = $errors;
                    update_option(self::OPTION_STATUS, $status, false);
                    return;
                }
                $map = (array) ($result['map'] ?? array());
                foreach ($url_by_id as $id => $orig) {
                    $partner = isset($map[$orig]) ? (string) $map[$orig] : '';
                    if ($partner !== '') {
                        update_post_meta($id, '_affiliate_url', esc_url_raw($partner));
                        delete_post_meta($id, self::META_PENDING);
                        delete_post_meta($id, self::META_CONVERT_ERROR);
                        $converted++;
                    } else {
                        // Non convertibile (brand non supportato / non iscritto):
                        // esce dalla coda per non bloccarla, con motivo salvato.
                        delete_post_meta($id, self::META_PENDING);
                        update_post_meta($id, self::META_CONVERT_ERROR, isset($result['messages'][$orig]) ? sanitize_text_field((string) $result['messages'][$orig]) : 'conversione non riuscita');
                        $errors++;
                    }
                }
            }
        } catch (Throwable $e) {
            $status['convert_state'] = 'errore';
            $status['convert_detail'] = sanitize_text_field($e->getMessage());
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        $remaining = self::pending_count();
        $status['converted'] = $converted;
        $status['convert_errors'] = $errors;
        if ($remaining > 0) {
            $status['convert_state'] = 'in_corso';
            $status['convert_detail'] = sprintf(__('%d link ancora da convertire.', 'affiliate-link-manager-ai'), $remaining);
            update_option(self::OPTION_STATUS, $status, false);
            // Catena: rispetta il rate limit dell'API (100 req/min).
            wp_schedule_single_event(time() + 20, self::CRON_HOOK, array('chain-' . time()));
            if (function_exists('spawn_cron')) { spawn_cron(); }
        } else {
            $status['convert_state'] = 'completata';
            $status['convert_detail'] = '';
            $status['convert_finished_at'] = current_time('mysql');
            update_option(self::OPTION_STATUS, $status, false);
        }
    }

    /**
     * Chiama l'API Travelpayouts per convertire un lotto di URL (max 10).
     *
     * @return array{map:array<string,string>,messages:array<string,string>,fatal?:string}
     */
    public static function api_convert($urls) {
        $token = trim((string) get_option(self::OPTION_TOKEN, ''));
        $trs = absint(get_option(self::OPTION_TRS, 0));
        $marker = absint(get_option(self::OPTION_MARKER, 0));
        $shorten = get_option(self::OPTION_SHORTEN, '1') === '1';
        $links = array();
        foreach (array_slice((array) $urls, 0, self::API_CHUNK) as $u) {
            $links[] = array('url' => (string) $u, 'sub_id' => 'alma_import');
        }
        $body = wp_json_encode(array('trs' => $trs, 'marker' => $marker, 'shorten' => $shorten, 'links' => $links));
        $res = wp_remote_post(self::API_ENDPOINT, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Access-Token' => $token,
            ),
            'body' => $body,
        ));
        if (is_wp_error($res)) {
            return array('map' => array(), 'messages' => array(), 'fatal' => $res->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code === 401) { return array('map' => array(), 'messages' => array(), 'fatal' => __('Token API non valido (401).', 'affiliate-link-manager-ai')); }
        if ($code === 400) {
            $err = is_array($json) ? (string) ($json['error'] ?? 'richiesta non valida') : 'richiesta non valida';
            return array('map' => array(), 'messages' => array(), 'fatal' => sprintf(__('Richiesta non valida (400): %s. Verifica trs e marker.', 'affiliate-link-manager-ai'), $err));
        }
        $map = array(); $messages = array();
        $items = (is_array($json) && isset($json['result']['links'])) ? (array) $json['result']['links'] : array();
        foreach ($items as $item) {
            $u = (string) ($item['url'] ?? '');
            if ($u === '') { continue; }
            if (($item['code'] ?? '') === 'success' && !empty($item['partner_url'])) {
                $map[$u] = (string) $item['partner_url'];
            } else {
                $messages[$u] = (string) ($item['message'] ?? 'conversione non riuscita');
            }
        }
        return array('map' => $map, 'messages' => $messages);
    }

    /* ---------------------------------------------------------------------
     * CSV demo
     * ------------------------------------------------------------------ */

    public static function handle_download_demo() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_download_demo');
        $rows = array(
            array('Nome', 'descrizione', 'città', 'url', 'tipologia link'),
            array('Hotel Le Marais', 'Boutique hotel nel cuore del Marais, a due passi da Notre-Dame.', 'Parigi', 'https://www.booking.com/hotel/fr/le-marais.it.html', 'Hotel e Resort'),
            array('Tour guidato del Louvre', 'Visita guidata salta-fila alle opere principali del Louvre.', 'Parigi', 'https://www.getyourguide.it/parigi-l16/louvre-t1234/', 'Tour e attività'),
            array('eSIM Francia 5GB', 'Connessione dati per il tuo viaggio in Francia, attivazione immediata.', '', 'https://yesim.app/country/france/5gb-esim-data-plan/', 'eSIM'),
        );
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="travelpayouts-demo.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM per Excel
        foreach ($rows as $r) { fputcsv($out, $r); }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------------------
     * Pagina admin
     * ------------------------------------------------------------------ */

    private static function redirect_back($notice) {
        set_transient('alma_tp_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('edit.php?post_type=affiliate_link&page=' . self::MENU_SLUG));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { return; }
        $notice = get_transient('alma_tp_notice_' . get_current_user_id());
        if ($notice) { delete_transient('alma_tp_notice_' . get_current_user_id()); }
        $token = (string) get_option(self::OPTION_TOKEN, '');
        $trs = absint(get_option(self::OPTION_TRS, 0));
        $marker = absint(get_option(self::OPTION_MARKER, 0));
        $shorten = get_option(self::OPTION_SHORTEN, '1') === '1';
        $status = (array) get_option(self::OPTION_STATUS, array());
        $pending = self::pending_count();
        $action = esc_url(admin_url('admin-post.php'));

        echo '<div class="wrap"><h1>' . esc_html__('Import Travelpayouts', 'affiliate-link-manager-ai') . '</h1>';
        echo '<p class="description">' . esc_html__('Importa link affiliati in massa da un CSV. Dopo l\'import, le API Travelpayouts convertono ogni URL diretto in URL affiliato e aggiornano il link.', 'affiliate-link-manager-ai') . '</p>';

        if (is_array($notice)) {
            echo '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        // --- Impostazioni API ---
        echo '<h2>' . esc_html__('1. Credenziali API', 'affiliate-link-manager-ai') . '</h2>';
        echo '<form method="post" action="' . $action . '">';
        wp_nonce_field('alma_tp_settings');
        echo '<input type="hidden" name="action" value="alma_tp_save_settings">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="tp_token">' . esc_html__('API token', 'affiliate-link-manager-ai') . '</label></th><td><input type="text" id="tp_token" name="tp_token" class="regular-text" value="' . esc_attr($token) . '" autocomplete="off"><p class="description">' . esc_html__('Profilo Travelpayouts → API token.', 'affiliate-link-manager-ai') . '</p></td></tr>';
        echo '<tr><th><label for="tp_trs">' . esc_html__('trs (Project ID)', 'affiliate-link-manager-ai') . '</label></th><td><input type="number" id="tp_trs" name="tp_trs" value="' . esc_attr((string) $trs) . '"></td></tr>';
        echo '<tr><th><label for="tp_marker">' . esc_html__('marker (Partner ID)', 'affiliate-link-manager-ai') . '</label></th><td><input type="number" id="tp_marker" name="tp_marker" value="' . esc_attr((string) $marker) . '"></td></tr>';
        echo '<tr><th>' . esc_html__('Link brevi', 'affiliate-link-manager-ai') . '</th><td><label><input type="checkbox" name="tp_shorten" value="1" ' . checked($shorten, true, false) . '> ' . esc_html__('Genera link affiliati brevi (shorten)', 'affiliate-link-manager-ai') . '</label></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Salva credenziali', 'affiliate-link-manager-ai'));
        echo '</form>';
        echo '<p>' . (self::is_configured() ? '<span style="color:#1a7f37;">✅ ' . esc_html__('API configurate.', 'affiliate-link-manager-ai') . '</span>' : '<span style="color:#d63638;">⚠️ ' . esc_html__('API non ancora configurate: l\'import creerà i link con l\'URL originale, la conversione partirà dopo la configurazione.', 'affiliate-link-manager-ai') . '</span>') . '</p>';

        // --- Upload CSV ---
        echo '<hr><h2>' . esc_html__('2. Carica il CSV', 'affiliate-link-manager-ai') . '</h2>';
        echo '<p class="description">' . esc_html__('Colonne attese: Nome, descrizione, città, url, tipologia link. La prima riga è l\'intestazione.', 'affiliate-link-manager-ai') . ' ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=alma_tp_download_demo'), 'alma_tp_download_demo')) . '">' . esc_html__('Scarica un CSV demo', 'affiliate-link-manager-ai') . '</a>.</p>';
        echo '<form method="post" action="' . $action . '" enctype="multipart/form-data">';
        wp_nonce_field('alma_tp_upload');
        echo '<input type="hidden" name="action" value="alma_tp_upload">';
        echo '<input type="file" name="tp_csv" accept=".csv,text/csv" required> ';
        submit_button(__('Importa i link', 'affiliate-link-manager-ai'), 'primary', 'submit', false);
        echo '</form>';

        // --- Stato conversione ---
        echo '<hr><h2>' . esc_html__('3. Conversione URL → affiliati', 'affiliate-link-manager-ai') . '</h2>';
        if (!empty($status['imported_at'])) {
            echo '<p>' . esc_html(sprintf(__('Ultimo import: %1$s — %2$d link creati, %3$d saltati.', 'affiliate-link-manager-ai'), (string) $status['imported_at'], (int) ($status['created'] ?? 0), (int) ($status['skipped'] ?? 0))) . '</p>';
        }
        $state = (string) ($status['convert_state'] ?? '');
        $state_labels = array(
            'in_coda' => __('in coda', 'affiliate-link-manager-ai'),
            'in_corso' => __('in corso', 'affiliate-link-manager-ai'),
            'completata' => __('completata', 'affiliate-link-manager-ai'),
            'errore' => __('errore', 'affiliate-link-manager-ai'),
            'in_attesa_config' => __('in attesa di configurazione API', 'affiliate-link-manager-ai'),
        );
        if ($state !== '') {
            $color = $state === 'errore' ? '#d63638' : ($state === 'completata' ? '#1a7f37' : '#2271b1');
            echo '<p>' . esc_html__('Stato conversione:', 'affiliate-link-manager-ai') . ' <strong style="color:' . esc_attr($color) . ';">' . esc_html($state_labels[$state] ?? $state) . '</strong>';
            echo ' · ' . esc_html(sprintf(__('convertiti: %1$d · non convertibili: %2$d · in attesa: %3$d', 'affiliate-link-manager-ai'), (int) ($status['converted'] ?? 0), (int) ($status['convert_errors'] ?? 0), $pending));
            echo '</p>';
            if (!empty($status['convert_detail'])) { echo '<p class="description">' . esc_html((string) $status['convert_detail']) . '</p>'; }
        } else {
            echo '<p>' . esc_html(sprintf(__('Link in attesa di conversione: %d', 'affiliate-link-manager-ai'), $pending)) . '</p>';
        }
        if ($pending > 0 && self::is_configured()) {
            echo '<form method="post" action="' . $action . '">';
            wp_nonce_field('alma_tp_convert_now');
            echo '<input type="hidden" name="action" value="alma_tp_convert_now">';
            submit_button(__('Converti ora', 'affiliate-link-manager-ai'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div>';
    }
}
