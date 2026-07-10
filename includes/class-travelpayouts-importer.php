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

    const PREVIEW_TRANSIENT = 'alma_tp_preview_';   // righe grezze CSV per l'anteprima

    public static function init() {
        add_action('admin_post_alma_tp_convert_now', array(__CLASS__, 'handle_convert_now'));
        add_action('admin_post_alma_tp_download_demo', array(__CLASS__, 'handle_download_demo'));
        // Flusso integrato in Affiliate Sources.
        add_action('admin_post_alma_tp_source_settings', array(__CLASS__, 'handle_source_settings'));
        add_action('admin_post_alma_tp_source_upload', array(__CLASS__, 'handle_source_upload'));
        add_action('admin_post_alma_tp_source_import', array(__CLASS__, 'handle_source_import'));
        add_action('wp_ajax_alma_tp_source_progress', array(__CLASS__, 'ajax_source_progress'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_conversion'));
    }

    /** URL della pagina di import della source travelpayouts_csv. */
    private static function source_import_url($source_id, $args = array()) {
        return add_query_arg(array_merge(array(
            'post_type' => 'affiliate_link',
            'page' => 'alma-affiliate-sources',
            'alma_view' => 'import_contents',
            'source_id' => absint($source_id),
        ), $args), admin_url('edit.php'));
    }

    /* ---------------------------------------------------------------------
     * Flusso integrato in Affiliate Sources: credenziali + anteprima +
     * mappatura colonne + deduplica + import + avanzamento live
     * ------------------------------------------------------------------ */

    private static function source_notice($source_id, $type, $message) {
        set_transient('alma_tp_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
        wp_safe_redirect(self::source_import_url($source_id));
        exit;
    }

    /** Salva le credenziali API (globali: l'account Travelpayouts è unico). */
    public static function handle_source_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_source_settings');
        $source_id = absint($_POST['source_id'] ?? 0);
        update_option(self::OPTION_TOKEN, trim(sanitize_text_field(wp_unslash($_POST['tp_token'] ?? ''))), false);
        update_option(self::OPTION_TRS, absint($_POST['tp_trs'] ?? 0), false);
        update_option(self::OPTION_MARKER, absint($_POST['tp_marker'] ?? 0), false);
        update_option(self::OPTION_SHORTEN, empty($_POST['tp_shorten']) ? '0' : '1', false);
        self::source_notice($source_id, 'success', __('Credenziali Travelpayouts salvate.', 'affiliate-link-manager-ai'));
    }

    /** Legge il CSV grezzo (intestazioni + righe) senza mappatura. */
    public static function read_csv_raw($path) {
        $handle = @fopen($path, 'r');
        if (!$handle) { return array('headers' => array(), 'rows' => array()); }
        $first = fgets($handle);
        if ($first === false) { fclose($handle); return array('headers' => array(), 'rows' => array()); }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
        $headers = array_map(function ($h) { return trim((string) $h); }, str_getcsv($first, $delimiter, '"', '\\'));
        $rows = array();
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (count($rows) >= self::MAX_ROWS) { break; }
            // Salta righe totalmente vuote.
            if (count(array_filter(array_map('trim', (array) $data), function ($v) { return $v !== ''; })) === 0) { continue; }
            $rows[] = array_map(function ($c) { return trim((string) $c); }, (array) $data);
        }
        fclose($handle);
        return array('headers' => $headers, 'rows' => $rows);
    }

    /** Estrae i campi da una riga grezza secondo la mappatura field=>indice. */
    public static function row_to_fields($raw_row, $mapping) {
        $get = function ($field) use ($mapping, $raw_row) {
            $idx = isset($mapping[$field]) ? (int) $mapping[$field] : -1;
            return ($idx >= 0 && isset($raw_row[$idx])) ? trim((string) $raw_row[$idx]) : '';
        };
        return array(
            'title' => $get('title'),
            'description' => $get('description'),
            'city' => $get('city'),
            'url' => $get('url'),
            'link_type' => $get('link_type'),
        );
    }

    /** Link affiliato già esistente con questo URL (originale o attuale)? */
    public static function find_existing_by_url($url) {
        $url = trim((string) $url);
        if ($url === '') { return 0; }
        $q = new WP_Query(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => self::META_ORIGINAL_URL, 'value' => $url),
                array('key' => '_affiliate_url', 'value' => $url),
            ),
        ));
        return !empty($q->posts) ? (int) $q->posts[0] : 0;
    }

    /** Carica il CSV, memorizza le righe grezze in transient e va all'anteprima. */
    public static function handle_source_upload() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_source_upload');
        $source_id = absint($_POST['source_id'] ?? 0);
        if (empty($_FILES['tp_csv']['tmp_name']) || !is_uploaded_file($_FILES['tp_csv']['tmp_name'])) {
            self::source_notice($source_id, 'error', __('Nessun file CSV caricato.', 'affiliate-link-manager-ai'));
        }
        $data = self::read_csv_raw($_FILES['tp_csv']['tmp_name']);
        if (empty($data['rows'])) {
            self::source_notice($source_id, 'error', __('CSV vuoto o illeggibile.', 'affiliate-link-manager-ai'));
        }
        $token = wp_generate_password(12, false);
        set_transient(self::PREVIEW_TRANSIENT . get_current_user_id() . '_' . $token, $data, 30 * MINUTE_IN_SECONDS);
        wp_safe_redirect(self::source_import_url($source_id, array('tp_preview' => $token)));
        exit;
    }

    /** Crea/aggiorna i link dalle righe selezionate e avvia la conversione. */
    public static function handle_source_import() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_tp_source_import');
        $source_id = absint($_POST['source_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['tp_token_preview'] ?? ''));
        $data = get_transient(self::PREVIEW_TRANSIENT . get_current_user_id() . '_' . $token);
        if (!is_array($data) || empty($data['rows'])) {
            self::source_notice($source_id, 'error', __('Anteprima scaduta: ricarica il CSV.', 'affiliate-link-manager-ai'));
        }
        // Mappatura scelta a mano (field => indice colonna).
        $mapping = array();
        foreach (array('title', 'description', 'city', 'url', 'link_type') as $field) {
            $mapping[$field] = isset($_POST['map'][$field]) ? (int) $_POST['map'][$field] : -1;
        }
        if ($mapping['url'] < 0) {
            self::source_notice($source_id, 'error', __('Mappa la colonna URL prima di importare.', 'affiliate-link-manager-ai'));
        }
        $update_existing = !empty($_POST['tp_update_existing']);
        $selected = array_map('absint', (array) ($_POST['rows'] ?? array()));
        $selected = array_values(array_unique($selected));
        $created = 0; $updated = 0; $skipped = 0; $link_ids = array();
        foreach ($selected as $i) {
            if (!isset($data['rows'][$i])) { continue; }
            $fields = self::row_to_fields($data['rows'][$i], $mapping);
            $url = esc_url_raw((string) $fields['url']);
            if ($url === '' || !wp_http_validate_url($url)) { $skipped++; continue; }
            $existing = self::find_existing_by_url($url);
            if ($existing > 0 && !$update_existing) { $skipped++; continue; }
            if ($existing > 0) {
                // Aggiorna: ripristina URL originale + rimette in coda conversione.
                update_post_meta($existing, self::META_ORIGINAL_URL, $url);
                update_post_meta($existing, '_affiliate_url', $url);
                update_post_meta($existing, self::META_PENDING, '1');
                $link_ids[] = $existing; $updated++;
            } else {
                $id = self::create_link_from_row($fields);
                if ($id > 0) { $link_ids[] = $id; $created++; } else { $skipped++; }
            }
        }
        delete_transient(self::PREVIEW_TRANSIENT . get_current_user_id() . '_' . $token);
        update_option(self::OPTION_STATUS, array(
            'imported_at' => current_time('mysql'),
            'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
            'convert_state' => self::is_configured() ? 'in_coda' : 'in_attesa_config',
            'convert_detail' => '', 'converted' => 0, 'convert_errors' => 0, 'convert_finished_at' => '',
        ), false);
        if (!empty($link_ids) && self::is_configured()) { self::schedule_conversion(); }
        $msg = sprintf(__('Import: %1$d creati, %2$d aggiornati, %3$d saltati.', 'affiliate-link-manager-ai'), $created, $updated, $skipped);
        $msg .= self::is_configured() ? ' ' . __('Conversione URL in corso in background.', 'affiliate-link-manager-ai') : ' ' . __('Configura le credenziali per convertire gli URL.', 'affiliate-link-manager-ai');
        self::source_notice($source_id, ($created + $updated) > 0 ? 'success' : 'error', $msg);
    }

    /** Stato conversione in tempo reale per la barra di avanzamento. */
    public static function ajax_source_progress() {
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'forbidden'), 403); }
        check_ajax_referer('alma_tp_progress', 'nonce');
        $status = (array) get_option(self::OPTION_STATUS, array());
        wp_send_json_success(array(
            'state' => (string) ($status['convert_state'] ?? ''),
            'converted' => (int) ($status['converted'] ?? 0),
            'errors' => (int) ($status['convert_errors'] ?? 0),
            'pending' => self::pending_count(),
            'detail' => (string) ($status['convert_detail'] ?? ''),
        ));
    }

    /**
     * Pagina di import Travelpayouts DENTRO Affiliate Sources: credenziali,
     * upload, anteprima con mappatura colonne editabile e deduplica, e la
     * barra di avanzamento della conversione API.
     */
    public static function render_source_import_page($source) {
        $source_id = (int) ($source['id'] ?? 0);
        $notice = get_transient('alma_tp_notice_' . get_current_user_id());
        if ($notice) { delete_transient('alma_tp_notice_' . get_current_user_id()); }
        $token = trim((string) get_option(self::OPTION_TOKEN, ''));
        $trs = absint(get_option(self::OPTION_TRS, 0));
        $marker = absint(get_option(self::OPTION_MARKER, 0));
        $shorten = get_option(self::OPTION_SHORTEN, '1') === '1';
        $status = (array) get_option(self::OPTION_STATUS, array());
        $pending = self::pending_count();
        $action = esc_url(admin_url('admin-post.php'));
        $ajax = esc_url(admin_url('admin-ajax.php'));
        $progress_nonce = wp_create_nonce('alma_tp_progress');

        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Travelpayouts CSV:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html__('carica un CSV, verifica la mappatura delle colonne e l\'anteprima, importa i link e la conversione API aggiorna gli URL in affiliati.', 'affiliate-link-manager-ai') . '</p></div>';
        if (is_array($notice)) {
            echo '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        // 1) Credenziali API (account unico): si gestiscono nella pagina «Configura provider».
        $config_url = add_query_arg(array('post_type' => 'affiliate_link', 'page' => 'alma-affiliate-sources', 'alma_view' => 'provider_config', 'source_id' => $source_id), admin_url('edit.php'));
        echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;"><span>' . esc_html__('1. Credenziali API Travelpayouts', 'affiliate-link-manager-ai') . '</span></h2><div class="inside">';
        echo '<p>' . (self::is_configured() ? '<span style="color:#1a7f37;">✅ ' . esc_html__('configurate', 'affiliate-link-manager-ai') . '</span>' : '<span style="color:#d63638;">⚠️ ' . esc_html__('non configurate', 'affiliate-link-manager-ai') . '</span>');
        echo ' — ' . esc_html__('token', 'affiliate-link-manager-ai') . ': ' . ($token !== '' ? esc_html__('salvato', 'affiliate-link-manager-ai') : '—') . ' · trs: ' . ($trs > 0 ? (int) $trs : '—') . ' · marker: ' . ($marker > 0 ? (int) $marker : '—') . ' · ' . esc_html__('link brevi', 'affiliate-link-manager-ai') . ': ' . ($shorten ? esc_html__('Sì', 'affiliate-link-manager-ai') : 'No') . '</p>';
        echo '<p><a class="button" href="' . esc_url($config_url) . '">⚙️ ' . esc_html__('Configura provider', 'affiliate-link-manager-ai') . '</a> <span class="description">' . esc_html__('Token, trs e marker sono condivisi da tutte le source Travelpayouts (account unico).', 'affiliate-link-manager-ai') . '</span></p>';
        echo '</div></div>';

        // 2) Upload CSV.
        echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;"><span>' . esc_html__('2. Carica il CSV', 'affiliate-link-manager-ai') . '</span></h2><div class="inside">';
        echo '<p class="description">' . esc_html__('Colonne consigliate: Nome, descrizione, città, url, tipologia link.', 'affiliate-link-manager-ai') . ' <a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=alma_tp_download_demo'), 'alma_tp_download_demo')) . '">' . esc_html__('Scarica CSV demo', 'affiliate-link-manager-ai') . '</a></p>';
        echo '<form method="post" action="' . $action . '" enctype="multipart/form-data">';
        wp_nonce_field('alma_tp_source_upload');
        echo '<input type="hidden" name="action" value="alma_tp_source_upload"><input type="hidden" name="source_id" value="' . $source_id . '">';
        echo '<input type="file" name="tp_csv" accept=".csv,text/csv" required> ';
        submit_button(__('Carica e anteprima', 'affiliate-link-manager-ai'), 'primary', 'submit', false);
        echo '</form></div></div>';

        // 3) Anteprima + mappatura + deduplica (se presente token anteprima).
        $preview_token = isset($_GET['tp_preview']) ? sanitize_text_field(wp_unslash($_GET['tp_preview'])) : '';
        if ($preview_token !== '') {
            $data = get_transient(self::PREVIEW_TRANSIENT . get_current_user_id() . '_' . $preview_token);
            if (is_array($data) && !empty($data['rows'])) {
                self::render_preview_table($source_id, $preview_token, $data, $action);
            } else {
                echo '<div class="notice notice-warning"><p>' . esc_html__('Anteprima scaduta: ricarica il CSV.', 'affiliate-link-manager-ai') . '</p></div>';
            }
        }

        // 4) Avanzamento conversione (live).
        echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;"><span>' . esc_html__('4. Conversione URL → affiliati', 'affiliate-link-manager-ai') . '</span></h2><div class="inside">';
        if (!empty($status['imported_at'])) {
            echo '<p>' . esc_html(sprintf(__('Ultimo import: %1$s — %2$d creati, %3$d aggiornati, %4$d saltati.', 'affiliate-link-manager-ai'), (string) $status['imported_at'], (int) ($status['created'] ?? 0), (int) ($status['updated'] ?? 0), (int) ($status['skipped'] ?? 0))) . '</p>';
        }
        echo '<div class="alma-progress" style="background:#e2e4e7;border-radius:4px;height:16px;overflow:hidden;max-width:520px;"><div id="alma-tp-bar" style="background:#2271b1;height:100%;width:0;transition:width .4s;"></div></div>';
        echo '<p id="alma-tp-progress-text" class="description">' . esc_html(sprintf(__('In attesa di conversione: %d', 'affiliate-link-manager-ai'), $pending)) . '</p>';
        if ($pending > 0 && self::is_configured()) {
            echo '<form method="post" action="' . $action . '" style="display:inline;">';
            wp_nonce_field('alma_tp_convert_now');
            echo '<input type="hidden" name="action" value="alma_tp_convert_now">';
            submit_button(__('Converti ora', 'affiliate-link-manager-ai'), 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div></div>';
        ?>
        <script>
        (function(){
            var ajax = <?php echo wp_json_encode($ajax); ?>, nonce = <?php echo wp_json_encode($progress_nonce); ?>;
            var bar = document.getElementById('alma-tp-bar'), txt = document.getElementById('alma-tp-progress-text');
            if (!bar) { return; }
            function poll(){
                var body = new URLSearchParams(); body.set('action','alma_tp_source_progress'); body.set('nonce', nonce);
                fetch(ajax, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()})
                    .then(function(r){ return r.json(); }).then(function(res){
                        if (!res || !res.success) { return; }
                        var d = res.data, done = d.converted + d.errors, total = done + d.pending;
                        var pct = total > 0 ? Math.round(done*100/total) : (d.state==='completata'?100:0);
                        bar.style.width = pct + '%';
                        if (txt) { txt.textContent = 'Convertiti: ' + d.converted + ' · non convertibili: ' + d.errors + ' · in attesa: ' + d.pending + (d.detail ? ' — ' + d.detail : ''); }
                        if (d.pending > 0 || d.state === 'in_corso' || d.state === 'in_coda') { setTimeout(poll, 4000); }
                    }).catch(function(){});
            }
            poll();
        })();
        </script>
        <?php
    }

    /** Tabella anteprima con selettori di mappatura colonne e flag deduplica. */
    private static function render_preview_table($source_id, $preview_token, $data, $action) {
        $headers = (array) $data['headers'];
        $rows = (array) $data['rows'];
        $auto = self::map_headers($headers); // field => indice suggerito
        $fields = array(
            'title' => __('Nome / Titolo', 'affiliate-link-manager-ai'),
            'description' => __('Descrizione', 'affiliate-link-manager-ai'),
            'city' => __('Città', 'affiliate-link-manager-ai'),
            'url' => __('URL', 'affiliate-link-manager-ai'),
            'link_type' => __('Tipologia link', 'affiliate-link-manager-ai'),
        );
        echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px;"><span>' . esc_html__('3. Anteprima e mappatura colonne', 'affiliate-link-manager-ai') . '</span></h2><div class="inside">';
        echo '<form method="post" action="' . $action . '">';
        wp_nonce_field('alma_tp_source_import');
        echo '<input type="hidden" name="action" value="alma_tp_source_import"><input type="hidden" name="source_id" value="' . (int) $source_id . '"><input type="hidden" name="tp_token_preview" value="' . esc_attr($preview_token) . '">';

        // Mappatura colonne.
        echo '<table class="form-table"><tbody>';
        foreach ($fields as $field => $label) {
            echo '<tr><th>' . esc_html($label) . ($field === 'url' ? ' <span style="color:#d63638;">*</span>' : '') . '</th><td><select name="map[' . esc_attr($field) . ']">';
            echo '<option value="-1">' . esc_html__('— nessuna —', 'affiliate-link-manager-ai') . '</option>';
            foreach ($headers as $i => $h) {
                $sel = (isset($auto[$field]) && (int) $auto[$field] === (int) $i) ? ' selected' : '';
                echo '<option value="' . (int) $i . '"' . $sel . '>' . esc_html($h !== '' ? $h : ('Col ' . ($i + 1))) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><label><input type="checkbox" name="tp_update_existing" value="1"> ' . esc_html__('Aggiorna anche i link già esistenti (stesso URL)', 'affiliate-link-manager-ai') . '</label></p>';

        // Anteprima righe con flag deduplica.
        $url_idx = isset($auto['url']) ? (int) $auto['url'] : -1;
        $shown = array_slice($rows, 0, 100, true);
        echo '<p><button type="button" class="button alma-tp-all">' . esc_html__('Seleziona tutti', 'affiliate-link-manager-ai') . '</button> <button type="button" class="button alma-tp-none">' . esc_html__('Deseleziona tutti', 'affiliate-link-manager-ai') . '</button> <span class="description">' . esc_html(sprintf(__('%d righe (mostrate max 100)', 'affiliate-link-manager-ai'), count($rows))) . '</span></p>';
        echo '<table class="widefat striped"><thead><tr><th></th>';
        foreach ($headers as $h) { echo '<th>' . esc_html($h) . '</th>'; }
        echo '<th>' . esc_html__('Stato', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
        foreach ($shown as $i => $row) {
            $url = ($url_idx >= 0 && isset($row[$url_idx])) ? esc_url_raw((string) $row[$url_idx]) : '';
            $valid = $url !== '' && wp_http_validate_url($url);
            $exists = $valid ? self::find_existing_by_url($url) : 0;
            $state = !$valid ? '<span style="color:#d63638;">' . esc_html__('URL mancante/non valido', 'affiliate-link-manager-ai') . '</span>' : ($exists ? '<span style="color:#996800;">' . esc_html__('già presente', 'affiliate-link-manager-ai') . '</span>' : '<span style="color:#1a7f37;">' . esc_html__('nuovo', 'affiliate-link-manager-ai') . '</span>');
            $checked = ($valid && !$exists) ? ' checked' : '';
            echo '<tr><td><input type="checkbox" class="alma-tp-row" name="rows[]" value="' . (int) $i . '"' . ($valid ? '' : ' disabled') . $checked . '></td>';
            foreach ($headers as $ci => $h) { echo '<td>' . esc_html(mb_substr((string) ($row[$ci] ?? ''), 0, 80)) . '</td>'; }
            echo '<td>' . $state . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . get_submit_button(__('Importa i selezionati', 'affiliate-link-manager-ai'), 'primary', 'submit', false) . '</p>';
        echo '</form>';
        ?>
        <script>
        (function(){
            var wrap = document.currentScript.closest('.inside');
            if (!wrap) { return; }
            wrap.querySelector('.alma-tp-all').addEventListener('click', function(){ wrap.querySelectorAll('.alma-tp-row:not([disabled])').forEach(function(c){ c.checked = true; }); });
            wrap.querySelector('.alma-tp-none').addEventListener('click', function(){ wrap.querySelectorAll('.alma-tp-row').forEach(function(c){ c.checked = false; }); });
        })();
        </script>
        <?php
        echo '</div></div>';
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

    // NOTA: i metodi standalone seguenti (handle_settings/handle_upload/
    // render_page/redirect_back) NON sono più agganciati: la configurazione
    // e l'import vivono ora nella pagina di import della Source
    // travelpayouts_csv. Restano solo come riferimento e verranno rimossi.
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
        if (self::is_configured()) {
            // Evento con argomento unico: WP-Cron non lo deduplica come identico.
            wp_schedule_single_event(time() + 2, self::CRON_HOOK, array('manual-' . time()));
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
        // Ritorna alla pagina di provenienza (import della source).
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-affiliate-sources'));
        exit;
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
