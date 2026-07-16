<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Export filtrato + Import di AGGIORNAMENTO dei Link Affiliati (v2.107.0).
 *
 * - L'export CSV (handler storico nel file principale) accetta filtri:
 *   source, tipologie link, provider, stato, ricerca testo, modalità testo.
 *   Gli argomenti della query vengono costruiti qui (build_export_query_args).
 * - L'import legge un CSV con la colonna chiave affiliate_link_id e aggiorna
 *   SOLO i campi presenti nel file e non vuoti (cella vuota = mantieni il
 *   valore attuale; sentinella [VUOTO] = svuota il campo). Anteprima con
 *   diff per riga, poi applicazione in batch AJAX interrompibili con lock
 *   atomico e cursore (pattern geocoding/gyg/travelpayouts).
 * - Campi in sola lettura mai toccati: click_count, provider, source_*,
 *   external_id di sistema? no: external_id è aggiornabile; geo_* esclusi
 *   (gestiti dal geocoding/geo index), created/updated, featured_image_url.
 */
class ALMA_Affiliate_Link_Update_Importer {
    const SETTINGS_PAGE = 'affiliate-link-manager-settings';
    const PREVIEW_TRANSIENT = 'alma_link_update_preview_';
    const PREVIEW_TTL = 1800; // 30 minuti
    const MAX_ROWS = 2000;
    const BATCH_SIZE = 100;
    const LOCK_OPTION = 'alma_link_update_lock';
    const LOCK_TTL = 300;
    const OPTION_LAST_REPORT = 'alma_link_update_last_report';
    const CLEAR_SENTINEL = '[VUOTO]';

    public static function init() {
        add_action('admin_post_alma_link_update_upload', array(__CLASS__, 'handle_upload'));
        add_action('wp_ajax_alma_link_update_apply', array(__CLASS__, 'ajax_apply_batch'));
        add_action('admin_footer', array(__CLASS__, 'render_footer_form'));
    }

    /* ------------------------------------------------------------------
     * Campi aggiornabili (colonna CSV → destinazione)
     * ---------------------------------------------------------------- */

    public static function updatable_fields() {
        return array(
            'post_title'        => array('type' => 'post', 'key' => 'post_title'),
            'post_slug'         => array('type' => 'post', 'key' => 'post_name'),
            'post_status'       => array('type' => 'post', 'key' => 'post_status'),
            'post_content'      => array('type' => 'post', 'key' => 'post_content'),
            'post_excerpt'      => array('type' => 'post', 'key' => 'post_excerpt'),
            'affiliate_url'     => array('type' => 'meta', 'key' => '_affiliate_url'),
            'link_title'        => array('type' => 'meta', 'key' => '_link_title'),
            'link_target'       => array('type' => 'meta', 'key' => '_link_target'),
            'link_rel'          => array('type' => 'meta', 'key' => '_link_rel'),
            'ai_context'        => array('type' => 'meta', 'key' => '_alma_ai_context'),
            'external_id'       => array('type' => 'meta', 'key' => '_alma_external_id'),
            'link_types'        => array('type' => 'terms', 'key' => 'link_type'),
            'featured_image_id' => array('type' => 'thumbnail', 'key' => '_thumbnail_id'),
        );
    }

    /**
     * Rimuove BOM e l'apice iniziale aggiunto dall'export per neutralizzare
     * le formule (=, +, -, @): senza questo strip l'import re-importerebbe
     * l'apice dentro il valore.
     */
    public static function normalize_cell($value) {
        $value = (string) $value;
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) { $value = substr($value, 3); }
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], array('=', '+', '-', '@'), true)) {
            $value = substr($value, 1);
        }
        return $value;
    }

    /** Valore "svuotato" coerente col tipo di campo. */
    public static function clear_value($column) {
        if ($column === 'link_types') { return array(); }
        if ($column === 'featured_image_id') { return 0; }
        return '';
    }

    /**
     * Sanifica il valore di una colonna aggiornabile.
     * Ritorna array('ok'=>bool, 'value'=>mixed, 'error'=>string).
     */
    public static function sanitize_field_value($column, $raw) {
        $raw = (string) $raw;
        switch ($column) {
            case 'post_title':
            case 'link_title':
            case 'link_rel':
            case 'external_id':
                return array('ok' => true, 'value' => sanitize_text_field($raw), 'error' => '');
            case 'post_slug':
                $slug = sanitize_title($raw);
                if ($slug === '') { return array('ok' => false, 'value' => '', 'error' => 'slug non valido'); }
                return array('ok' => true, 'value' => $slug, 'error' => '');
            case 'post_status':
                $status = sanitize_key($raw);
                if (!in_array($status, array('publish', 'draft', 'pending', 'private'), true)) {
                    return array('ok' => false, 'value' => '', 'error' => 'post_status non valido (ammessi: publish, draft, pending, private)');
                }
                return array('ok' => true, 'value' => $status, 'error' => '');
            case 'post_content':
            case 'post_excerpt':
                return array('ok' => true, 'value' => wp_kses_post($raw), 'error' => '');
            case 'ai_context':
                return array('ok' => true, 'value' => sanitize_textarea_field($raw), 'error' => '');
            case 'affiliate_url':
                $url = esc_url_raw(trim($raw));
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    return array('ok' => false, 'value' => '', 'error' => 'affiliate_url non valido (richiesto http/https)');
                }
                return array('ok' => true, 'value' => $url, 'error' => '');
            case 'link_target':
                $target = trim($raw);
                if (!in_array($target, array('_blank', '_self'), true)) {
                    return array('ok' => false, 'value' => '', 'error' => 'link_target non valido (ammessi: _blank, _self)');
                }
                return array('ok' => true, 'value' => $target, 'error' => '');
            case 'link_types':
                $names = array_values(array_filter(array_map(function ($name) { return sanitize_text_field(trim((string) $name)); }, explode('|', $raw)), function ($name) { return $name !== ''; }));
                if (empty($names)) { return array('ok' => false, 'value' => array(), 'error' => 'link_types vuoto (usa Nome|Nome oppure ' . self::CLEAR_SENTINEL . ')'); }
                return array('ok' => true, 'value' => $names, 'error' => '');
            case 'featured_image_id':
                if (!preg_match('/^\d+$/', trim($raw))) {
                    return array('ok' => false, 'value' => 0, 'error' => 'featured_image_id non numerico');
                }
                return array('ok' => true, 'value' => absint($raw), 'error' => '');
        }
        return array('ok' => false, 'value' => '', 'error' => 'colonna non aggiornabile');
    }

    /** Confronto nuovo/attuale coerente col tipo. */
    public static function values_equal($column, $new_value, $current_value) {
        if ($column === 'link_types') {
            $a = array_map(function ($v) { return function_exists('mb_strtolower') ? mb_strtolower(trim((string) $v)) : strtolower(trim((string) $v)); }, (array) $new_value);
            $b = array_map(function ($v) { return function_exists('mb_strtolower') ? mb_strtolower(trim((string) $v)) : strtolower(trim((string) $v)); }, (array) $current_value);
            sort($a); sort($b);
            return $a === $b;
        }
        if ($column === 'featured_image_id') { return absint($new_value) === absint($current_value); }
        return (string) $new_value === (string) $current_value;
    }

    private static function current_value($post, $column, $spec) {
        if ($spec['type'] === 'post') { return (string) $post->{$spec['key']}; }
        if ($spec['type'] === 'meta') { return (string) get_post_meta($post->ID, $spec['key'], true); }
        if ($spec['type'] === 'thumbnail') { return (int) get_post_thumbnail_id($post->ID); }
        if ($spec['type'] === 'terms') {
            $terms = get_the_terms($post->ID, $spec['key']);
            $names = array();
            if (!is_wp_error($terms) && !empty($terms)) { foreach ($terms as $term) { $names[] = $term->name; } }
            return $names;
        }
        return '';
    }

    /**
     * Diff di una riga CSV rispetto al Link attuale.
     * Regole: colonna assente = non toccare; cella vuota = mantieni;
     * [VUOTO] = svuota. Ritorna post_id, title, changes, errors, status
     * (update|unchanged|not_found|invalid|error).
     */
    public static function build_row_changes($row) {
        $row = is_array($row) ? $row : array();
        $post_id = absint(self::normalize_cell($row['affiliate_link_id'] ?? ''));
        if ($post_id < 1) {
            return array('post_id' => 0, 'title' => '', 'changes' => array(), 'errors' => array('affiliate_link_id mancante o non numerico'), 'status' => 'invalid');
        }
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'affiliate_link') {
            return array('post_id' => $post_id, 'title' => '', 'changes' => array(), 'errors' => array('Link affiliato non trovato'), 'status' => 'not_found');
        }
        $changes = array();
        $errors = array();
        foreach (self::updatable_fields() as $column => $spec) {
            if (!array_key_exists($column, $row)) { continue; }
            $raw = self::normalize_cell($row[$column]);
            if (trim($raw) === '') { continue; }
            if (trim($raw) === self::CLEAR_SENTINEL) {
                $new_value = self::clear_value($column);
            } else {
                $sanitized = self::sanitize_field_value($column, $raw);
                if (empty($sanitized['ok'])) { $errors[] = $sanitized['error']; continue; }
                $new_value = $sanitized['value'];
            }
            $current = self::current_value($post, $column, $spec);
            if (self::values_equal($column, $new_value, $current)) { continue; }
            $changes[$column] = $new_value;
        }
        $status = !empty($changes) ? 'update' : (!empty($errors) ? 'error' : 'unchanged');
        return array('post_id' => $post_id, 'title' => (string) $post->post_title, 'changes' => $changes, 'errors' => $errors, 'status' => $status);
    }

    /**
     * Applica le modifiche a un Link. Ritorna array('updated_fields'=>[], 'warnings'=>[]).
     */
    public static function apply_changes($post_id, $changes) {
        $post_id = absint($post_id);
        $changes = is_array($changes) ? $changes : array();
        $fields = self::updatable_fields();
        $updated = array();
        $warnings = array();
        $post_data = array();
        foreach ($changes as $column => $value) {
            if (!isset($fields[$column])) { continue; }
            $spec = $fields[$column];
            if ($spec['type'] === 'post') {
                $post_data[$spec['key']] = $value;
                $updated[] = $column;
            } elseif ($spec['type'] === 'meta') {
                update_post_meta($post_id, $spec['key'], wp_slash((string) $value));
                $updated[] = $column;
            } elseif ($spec['type'] === 'terms') {
                $term_ids = array();
                foreach ((array) $value as $name) {
                    $existing = term_exists($name, $spec['key']);
                    if (!$existing) {
                        $created = wp_insert_term($name, $spec['key']);
                        if (is_wp_error($created)) { $warnings[] = 'Tipologia non creabile: ' . $name; continue; }
                        $term_ids[] = (int) $created['term_id'];
                        $warnings[] = 'Nuova tipologia creata: ' . $name;
                    } else {
                        $term_ids[] = (int) (is_array($existing) ? $existing['term_id'] : $existing);
                    }
                }
                wp_set_object_terms($post_id, $term_ids, $spec['key'], false);
                $updated[] = $column;
            } elseif ($spec['type'] === 'thumbnail') {
                $attachment_id = absint($value);
                if ($attachment_id < 1) {
                    delete_post_thumbnail($post_id);
                    $updated[] = $column;
                } elseif (get_post_type($attachment_id) === 'attachment') {
                    set_post_thumbnail($post_id, $attachment_id);
                    $updated[] = $column;
                } else {
                    $warnings[] = 'featured_image_id ' . $attachment_id . ' non è un media esistente: ignorato';
                }
            }
        }
        if (!empty($post_data)) {
            $post_data['ID'] = $post_id;
            $result = wp_update_post(wp_slash($post_data), true);
            if (is_wp_error($result)) { $warnings[] = 'Errore aggiornamento post: ' . $result->get_error_message(); }
        }
        return array('updated_fields' => $updated, 'warnings' => $warnings);
    }

    /* ------------------------------------------------------------------
     * Filtri export (usati dall'handler storico nel file principale)
     * ---------------------------------------------------------------- */

    /**
     * Costruisce gli argomenti WP_Query dell'export a partire dai filtri.
     * $filters: status, source_id (0 tutte, -1 senza source), provider,
     * link_type_ids (array), search, has_image (''|yes|no).
     */
    public static function build_export_query_args($filters) {
        $filters = is_array($filters) ? $filters : array();
        $status = sanitize_key((string) ($filters['status'] ?? 'any'));
        if (!in_array($status, array('any', 'publish', 'draft', 'pending', 'private'), true)) { $status = 'any'; }
        $args = array(
            'post_type' => 'affiliate_link',
            'post_status' => $status === 'any' ? 'any' : $status,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        );
        $search = sanitize_text_field((string) ($filters['search'] ?? ''));
        if ($search !== '') { $args['s'] = $search; }
        $type_ids = array_values(array_unique(array_filter(array_map('absint', (array) ($filters['link_type_ids'] ?? array())))));
        if (!empty($type_ids)) {
            $args['tax_query'] = array(array('taxonomy' => 'link_type', 'field' => 'term_id', 'terms' => $type_ids));
        }
        $meta_query = array('relation' => 'AND');
        $source_id = (int) ($filters['source_id'] ?? 0);
        if ($source_id > 0) {
            $meta_query[] = array('key' => '_alma_source_id', 'value' => (string) $source_id, 'compare' => '=');
        } elseif ($source_id === -1) {
            $meta_query[] = array('relation' => 'OR',
                array('key' => '_alma_source_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_alma_source_id', 'value' => '', 'compare' => '='),
                array('key' => '_alma_source_id', 'value' => '0', 'compare' => '='),
            );
        }
        $provider = sanitize_key((string) ($filters['provider'] ?? ''));
        if ($provider !== '') {
            $meta_query[] = array('key' => '_alma_provider', 'value' => $provider, 'compare' => '=');
        }
        $has_image = sanitize_key((string) ($filters['has_image'] ?? ''));
        if ($has_image === 'yes') {
            $meta_query[] = array('key' => '_thumbnail_id', 'compare' => 'EXISTS');
        } elseif ($has_image === 'no') {
            $meta_query[] = array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS');
        }
        if (count($meta_query) > 1) { $args['meta_query'] = $meta_query; }
        return $args;
    }

    /** Filtri export letti dalla request GET dell'handler di export. */
    public static function export_filters_from_request($request) {
        $request = is_array($request) ? $request : array();
        return array(
            'status' => sanitize_key((string) ($request['alma_status'] ?? 'any')),
            'source_id' => isset($request['alma_source_id']) ? (int) $request['alma_source_id'] : 0,
            'provider' => sanitize_key((string) ($request['alma_provider'] ?? '')),
            'link_type_ids' => array_filter(array_map('absint', explode(',', sanitize_text_field((string) ($request['alma_link_types'] ?? ''))))),
            'search' => sanitize_text_field(wp_unslash((string) ($request['alma_search'] ?? ''))),
            'has_image' => sanitize_key((string) ($request['alma_has_image'] ?? '')),
            'text_mode' => (sanitize_key((string) ($request['alma_text_mode'] ?? 'raw')) === 'clean') ? 'clean' : 'raw',
        );
    }

    /* ------------------------------------------------------------------
     * Upload CSV → anteprima
     * ---------------------------------------------------------------- */

    private static function settings_url($extra = array()) {
        $url = add_query_arg(array_merge(array('post_type' => 'affiliate_link', 'page' => self::SETTINGS_PAGE), $extra), admin_url('edit.php'));
        return $url . '#export-affiliate-links';
    }

    private static function preview_key($token) {
        return self::PREVIEW_TRANSIENT . get_current_user_id() . '_' . sanitize_key($token);
    }

    private static function notice($type, $message) {
        set_transient('alma_link_update_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
    }

    public static function handle_upload() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_link_update_upload');
        $file = $_FILES['link_update_csv'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            self::notice('error', __('Caricamento CSV non riuscito: seleziona un file .csv valido.', 'affiliate-link-manager-ai'));
            wp_safe_redirect(self::settings_url()); exit;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'csv' && $ext !== 'txt') {
            self::notice('error', __('Formato non supportato: carica un file .csv.', 'affiliate-link-manager-ai'));
            wp_safe_redirect(self::settings_url()); exit;
        }
        $parsed = self::parse_csv_file($file['tmp_name']);
        if (is_wp_error($parsed)) {
            self::notice('error', $parsed->get_error_message());
            wp_safe_redirect(self::settings_url()); exit;
        }
        $counts = array('total' => count($parsed['rows']), 'update' => 0, 'unchanged' => 0, 'not_found' => 0, 'invalid' => 0, 'error' => 0, 'truncated' => (int) $parsed['truncated']);
        $preview_rows = array();
        foreach ($parsed['rows'] as $row) {
            $diff = self::build_row_changes($row);
            $counts[$diff['status']] = ($counts[$diff['status']] ?? 0) + 1;
            // In transient restano solo le righe che servono all'applicazione
            // o alla diagnosi (update/errori): le "nessuna modifica" si contano.
            if ($diff['status'] === 'update' || !empty($diff['errors'])) {
                $preview_rows[] = $diff;
            }
        }
        $token = strtolower(wp_generate_password(20, false, false));
        set_transient(self::preview_key($token), array(
            'filename' => sanitize_file_name((string) ($file['name'] ?? 'import.csv')),
            'created_at' => current_time('mysql'),
            'counts' => $counts,
            'rows' => $preview_rows,
        ), self::PREVIEW_TTL);
        wp_safe_redirect(self::settings_url(array('alma_link_update_token' => $token))); exit;
    }

    /**
     * Parse CSV con fgetcsv (gestisce celle multilinea quotate), BOM strip,
     * autodetect delimitatore , o ; sulla riga di intestazione.
     */
    private static function parse_csv_file($path) {
        $handle = fopen($path, 'r');
        if (!$handle) { return new WP_Error('alma_link_update_open', __('Impossibile leggere il file caricato.', 'affiliate-link-manager-ai')); }
        $first_line = (string) fgets($handle);
        if (strncmp($first_line, "\xEF\xBB\xBF", 3) === 0) { $first_line = substr($first_line, 3); }
        $delimiter = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';
        rewind($handle);
        // Salta il BOM anche per fgetcsv.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            return new WP_Error('alma_link_update_headers', __('Intestazioni CSV non leggibili.', 'affiliate-link-manager-ai'));
        }
        $headers = array_map(function ($h) { return strtolower(trim(self::normalize_cell((string) $h))); }, $headers);
        if (!in_array('affiliate_link_id', $headers, true)) {
            fclose($handle);
            return new WP_Error('alma_link_update_key', __('Colonna obbligatoria mancante: affiliate_link_id. Usa il CSV generato dall\'export.', 'affiliate-link-manager-ai'));
        }
        $rows = array();
        $truncated = 0;
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (!is_array($cells) || (count($cells) === 1 && trim((string) $cells[0]) === '')) { continue; }
            if (count($rows) >= self::MAX_ROWS) { $truncated++; continue; }
            $row = array();
            foreach ($headers as $i => $header) {
                if ($header === '') { continue; }
                $row[$header] = (string) ($cells[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);
        if (empty($rows)) { return new WP_Error('alma_link_update_empty', __('Il CSV non contiene righe dati.', 'affiliate-link-manager-ai')); }
        return array('headers' => $headers, 'rows' => $rows, 'truncated' => $truncated);
    }

    /* ------------------------------------------------------------------
     * Applicazione in batch (AJAX iterativo, lock atomico, cursore)
     * ---------------------------------------------------------------- */

    private static function acquire_lock() {
        if (add_option(self::LOCK_OPTION, (string) time(), '', 'no')) { return true; }
        $started = absint(get_option(self::LOCK_OPTION, 0));
        if ($started > 0 && (time() - $started) > self::LOCK_TTL) {
            update_option(self::LOCK_OPTION, (string) time(), false);
            return true;
        }
        return false;
    }

    public static function ajax_apply_batch() {
        check_ajax_referer('alma_link_update_apply', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Permessi insufficienti.'), 403); }
        $token = sanitize_key((string) ($_POST['token'] ?? ''));
        $cursor = absint($_POST['cursor'] ?? 0);
        $data = get_transient(self::preview_key($token));
        if (!is_array($data) || empty($data['rows'])) {
            wp_send_json_error(array('message' => __('Anteprima scaduta o vuota: ricarica il CSV.', 'affiliate-link-manager-ai')));
        }
        if ($cursor === 0) {
            if (!self::acquire_lock()) {
                wp_send_json_error(array('message' => __('Un\'altra applicazione è già in corso: attendi qualche istante.', 'affiliate-link-manager-ai')));
            }
        } else {
            update_option(self::LOCK_OPTION, (string) time(), false); // heartbeat del lock
        }
        $rows = array_values((array) $data['rows']);
        $slice = array_slice($rows, $cursor, self::BATCH_SIZE);
        $updated = 0; $skipped = 0; $warnings = array();
        foreach ($slice as $row) {
            if (($row['status'] ?? '') !== 'update' || empty($row['changes'])) { $skipped++; continue; }
            $applied = self::apply_changes(absint($row['post_id']), (array) $row['changes']);
            if (!empty($applied['updated_fields'])) { $updated++; } else { $skipped++; }
            foreach ((array) $applied['warnings'] as $warning) {
                if (count($warnings) < 20) { $warnings[] = '#' . absint($row['post_id']) . ': ' . $warning; }
            }
        }
        $next_cursor = $cursor + count($slice);
        $done = $next_cursor >= count($rows);
        if ($done) {
            delete_option(self::LOCK_OPTION);
            delete_transient(self::preview_key($token));
            $report = array(
                'time' => current_time('mysql'),
                'filename' => sanitize_file_name((string) ($data['filename'] ?? '')),
                'counts' => (array) ($data['counts'] ?? array()),
            );
            update_option(self::OPTION_LAST_REPORT, $report, false);
        }
        wp_send_json_success(array(
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'next_cursor' => $next_cursor,
            'total' => count($rows),
            'done' => $done,
        ));
    }

    /* ------------------------------------------------------------------
     * UI (sezione nel tab Export Link Affiliati delle Impostazioni)
     * ---------------------------------------------------------------- */

    /**
     * Il form di upload non può stare dentro il form delle Impostazioni
     * (form annidati vengono fusi dal browser): vive nel footer e i campi
     * nel tab lo referenziano con l'attributo HTML5 form="...".
     */
    public static function render_footer_form() {
        if (!is_admin() || sanitize_key($_GET['page'] ?? '') !== self::SETTINGS_PAGE) { return; }
        echo '<form id="alma-link-update-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_link_update_upload');
        echo '<input type="hidden" name="action" value="alma_link_update_upload"/></form>';
    }

    public static function render_settings_section() {
        global $wpdb;
        $notice = get_transient('alma_link_update_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('alma_link_update_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' inline"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        $sources = $wpdb->get_results("SELECT id, name, provider_label, provider FROM {$wpdb->prefix}alma_affiliate_sources WHERE deleted_at IS NULL ORDER BY name ASC", ARRAY_A);
        if (!is_array($sources)) { $sources = array(); }
        $providers = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_alma_provider' AND meta_value <> '' ORDER BY meta_value ASC");
        if (!is_array($providers)) { $providers = array(); }
        $terms = get_terms(array('taxonomy' => 'link_type', 'hide_empty' => false));
        if (is_wp_error($terms) || !is_array($terms)) { $terms = array(); }
        $export_base = wp_nonce_url(admin_url('admin-post.php?action=alma_export_affiliate_links_csv'), 'alma_export_affiliate_links_csv');

        echo '<h3>' . esc_html__('Filtri export', 'affiliate-link-manager-ai') . '</h3>';
        echo '<p class="description">' . esc_html__('Esporta tutti i Link Affiliati o solo un sottoinsieme. Il CSV contiene ID e tutti i campi utili: puoi modificarlo e ricaricarlo qui sotto per aggiornare i Link in blocco.', 'affiliate-link-manager-ai') . '</p>';
        echo '<div class="alma-export-filters" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:10px 0;">';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Source', 'affiliate-link-manager-ai') . '</strong><br/><select id="alma-exp-source"><option value="0">' . esc_html__('Tutte', 'affiliate-link-manager-ai') . '</option><option value="-1">' . esc_html__('Senza source (manuali)', 'affiliate-link-manager-ai') . '</option>';
        foreach ($sources as $source_row) {
            echo '<option value="' . (int) $source_row['id'] . '">' . esc_html($source_row['name'] . ' (' . (($source_row['provider_label'] !== '' ? $source_row['provider_label'] : $source_row['provider'])) . ')') . '</option>';
        }
        echo '</select></label></p>';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Tipologie Link', 'affiliate-link-manager-ai') . '</strong><br/><select id="alma-exp-types" multiple size="4" style="min-width:180px;">';
        foreach ($terms as $term) { echo '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>'; }
        echo '</select></label><br/><span class="description">' . esc_html__('Nessuna selezione = tutte', 'affiliate-link-manager-ai') . '</span></p>';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Provider', 'affiliate-link-manager-ai') . '</strong><br/><select id="alma-exp-provider"><option value="">' . esc_html__('Tutti', 'affiliate-link-manager-ai') . '</option>';
        foreach ($providers as $provider_key) { echo '<option value="' . esc_attr($provider_key) . '">' . esc_html($provider_key) . '</option>'; }
        echo '</select></label></p>';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Stato', 'affiliate-link-manager-ai') . '</strong><br/><select id="alma-exp-status"><option value="any">' . esc_html__('Tutti', 'affiliate-link-manager-ai') . '</option><option value="publish">publish</option><option value="draft">draft</option><option value="pending">pending</option><option value="private">private</option></select></label></p>';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Immagine', 'affiliate-link-manager-ai') . '</strong><br/><select id="alma-exp-image"><option value="">' . esc_html__('Indifferente', 'affiliate-link-manager-ai') . '</option><option value="yes">' . esc_html__('Con immagine', 'affiliate-link-manager-ai') . '</option><option value="no">' . esc_html__('Senza immagine', 'affiliate-link-manager-ai') . '</option></select></label></p>';
        echo '<p style="margin:0;flex:1 1 200px;"><label><strong>' . esc_html__('Ricerca testo', 'affiliate-link-manager-ai') . '</strong><br/><input type="text" id="alma-exp-search" class="regular-text" placeholder="' . esc_attr__('es. Parigi, hotel…', 'affiliate-link-manager-ai') . '"/></label></p>';
        echo '</div>';
        echo '<p><label><input type="checkbox" id="alma-exp-clean"/> ' . esc_html__('Testo "pulito" (senza HTML/shortcode) per analisi esterne — NON adatto al re-import di contenuto e descrizione', 'affiliate-link-manager-ai') . '</label></p>';
        echo '<p><a class="button button-primary" id="alma-export-filtered" data-base="' . esc_attr($export_base) . '" href="' . esc_url($export_base) . '">' . esc_html__('Esporta CSV con i filtri scelti', 'affiliate-link-manager-ai') . '</a></p>';

        echo '<hr/><h3>' . esc_html__('Import aggiornamento Link Affiliati', 'affiliate-link-manager-ai') . '</h3>';
        echo '<p class="description">' . esc_html__('Carica un CSV con la colonna affiliate_link_id (il file dell\'export va benissimo, anche parziale): vengono aggiornati SOLO i campi presenti nel file e non vuoti. Cella vuota = valore attuale mantenuto; scrivi [VUOTO] per svuotare un campo. Campi aggiornabili: titolo, slug, stato, contenuto, riassunto, URL affiliato, titolo/target/rel del link, contesto AI, external_id, tipologie (Nome|Nome), immagine in evidenza (ID media). Click, provider, source e dati geografici non vengono mai toccati.', 'affiliate-link-manager-ai') . '</p>';
        echo '<p><input type="file" name="link_update_csv" form="alma-link-update-form" accept=".csv,text/csv" required/> <button type="submit" class="button button-primary" form="alma-link-update-form">' . esc_html__('Carica e anteprima', 'affiliate-link-manager-ai') . '</button></p>';

        $last_report = get_option(self::OPTION_LAST_REPORT, null);
        if (is_array($last_report) && !empty($last_report['time'])) {
            $report_counts = (array) ($last_report['counts'] ?? array());
            echo '<p class="description">' . esc_html(sprintf(__('Ultimo import aggiornamento: %1$s (%2$s) — %3$d righe, %4$d da aggiornare, %5$d senza modifiche, %6$d non trovati.', 'affiliate-link-manager-ai'), (string) $last_report['time'], (string) ($last_report['filename'] ?? ''), (int) ($report_counts['total'] ?? 0), (int) ($report_counts['update'] ?? 0), (int) ($report_counts['unchanged'] ?? 0), (int) ($report_counts['not_found'] ?? 0))) . '</p>';
        }

        self::render_preview_section();
        self::render_inline_js();
    }

    private static function render_preview_section() {
        $token = sanitize_key($_GET['alma_link_update_token'] ?? '');
        if ($token === '') { return; }
        $data = get_transient(self::preview_key($token));
        if (!is_array($data)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Anteprima scaduta: ricarica il CSV.', 'affiliate-link-manager-ai') . '</p></div>';
            return;
        }
        $counts = (array) ($data['counts'] ?? array());
        $rows = array_values((array) ($data['rows'] ?? array()));
        echo '<div id="alma-link-update-preview" data-token="' . esc_attr($token) . '" data-nonce="' . esc_attr(wp_create_nonce('alma_link_update_apply')) . '" style="margin-top:14px;padding:12px 14px;border:1px solid #c5d9ed;background:#f0f6fc;border-radius:6px;">';
        echo '<h4 style="margin-top:0;">' . esc_html(sprintf(__('Anteprima «%s»', 'affiliate-link-manager-ai'), (string) ($data['filename'] ?? 'CSV'))) . '</h4>';
        echo '<p>' . esc_html(sprintf(__('Righe: %1$d · Da aggiornare: %2$d · Senza modifiche: %3$d · ID non trovati: %4$d · Righe con errori: %5$d · Non valide: %6$d', 'affiliate-link-manager-ai'), (int) ($counts['total'] ?? 0), (int) ($counts['update'] ?? 0), (int) ($counts['unchanged'] ?? 0), (int) ($counts['not_found'] ?? 0), (int) ($counts['error'] ?? 0), (int) ($counts['invalid'] ?? 0)));
        if (!empty($counts['truncated'])) { echo ' · ' . esc_html(sprintf(__('ATTENZIONE: %d righe oltre il limite di %d sono state ignorate.', 'affiliate-link-manager-ai'), (int) $counts['truncated'], self::MAX_ROWS)); }
        echo '</p>';
        $shown = 0;
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Titolo', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Campi che cambiano', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Note', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if ($shown >= 50) { break; }
            $shown++;
            $change_labels = array();
            foreach ((array) ($row['changes'] ?? array()) as $column => $value) {
                $preview_value = is_array($value) ? implode('|', $value) : (string) $value;
                $change_labels[] = $column . ' → ' . wp_trim_words($preview_value, 8, '…');
            }
            echo '<tr><td>' . (int) ($row['post_id'] ?? 0) . '</td><td>' . esc_html((string) ($row['title'] ?? '')) . '</td><td>' . esc_html($change_labels ? implode(' · ', $change_labels) : '—') . '</td><td>' . esc_html(implode(' · ', (array) ($row['errors'] ?? array()))) . '</td></tr>';
        }
        if (empty($rows)) { echo '<tr><td colspan="4">' . esc_html__('Nessuna riga da aggiornare: i valori del CSV coincidono con quelli attuali.', 'affiliate-link-manager-ai') . '</td></tr>'; }
        echo '</tbody></table>';
        if (count($rows) > $shown) { echo '<p class="description">' . esc_html(sprintf(__('Mostrate le prime %1$d righe di %2$d.', 'affiliate-link-manager-ai'), $shown, count($rows))) . '</p>'; }
        if ((int) ($counts['update'] ?? 0) > 0) {
            echo '<p><button type="button" class="button button-primary" id="alma-link-update-apply">' . esc_html(sprintf(__('Applica %d aggiornamenti', 'affiliate-link-manager-ai'), (int) $counts['update'])) . '</button></p>';
            echo '<div id="alma-link-update-progress" style="display:none;"><div class="alma-progress" style="height:18px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:10px;overflow:hidden;max-width:520px;"><div id="alma-link-update-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s ease;"></div></div><p id="alma-link-update-status" style="font-weight:600;"></p><ul id="alma-link-update-warnings" style="margin:6px 0 0 18px;"></ul></div>';
        }
        echo '</div>';
    }

    private static function render_inline_js() {
        ?>
        <script>
        jQuery(function($){
            // Attiva il tab dall'hash (l'upload/anteprima reindirizza qui).
            if (window.location.hash === '#export-affiliate-links') {
                $('.alma-settings-tabs .nav-tab[href="#export-affiliate-links"]').trigger('click');
            }
            // Export: costruisce l'URL con i filtri scelti.
            $('#alma-export-filtered').on('click', function(){
                var base = $(this).data('base');
                var params = {
                    alma_source_id: $('#alma-exp-source').val() || '0',
                    alma_provider: $('#alma-exp-provider').val() || '',
                    alma_status: $('#alma-exp-status').val() || 'any',
                    alma_has_image: $('#alma-exp-image').val() || '',
                    alma_search: $('#alma-exp-search').val() || '',
                    alma_link_types: ($('#alma-exp-types').val() || []).join(','),
                    alma_text_mode: $('#alma-exp-clean').is(':checked') ? 'clean' : 'raw'
                };
                var query = [];
                $.each(params, function(k, v){ if (v !== '' && v !== '0' && !(k === 'alma_status' && v === 'any') && !(k === 'alma_text_mode' && v === 'raw')) { query.push(k + '=' + encodeURIComponent(v)); } });
                $(this).attr('href', base + (query.length ? '&' + query.join('&') : ''));
            });
            // Import: applica gli aggiornamenti in batch interrompibili.
            $('#alma-link-update-apply').on('click', function(){
                var $btn = $(this).prop('disabled', true).text('Applicazione in corso…');
                var $wrap = $('#alma-link-update-preview');
                var token = $wrap.data('token'), nonce = $wrap.data('nonce');
                var totals = { updated: 0, skipped: 0 };
                $('#alma-link-update-progress').show();
                function step(cursor){
                    $.post(ajaxurl, { action: 'alma_link_update_apply', nonce: nonce, token: token, cursor: cursor }).done(function(res){
                        if (!res || !res.success) {
                            $('#alma-link-update-status').text((res && res.data && res.data.message) || 'Errore applicazione.');
                            $btn.prop('disabled', false).text('Riprova');
                            return;
                        }
                        var d = res.data;
                        totals.updated += parseInt(d.updated || 0, 10);
                        totals.skipped += parseInt(d.skipped || 0, 10);
                        var pct = d.total > 0 ? Math.min(100, Math.round(d.next_cursor / d.total * 100)) : 100;
                        $('#alma-link-update-bar').css('width', pct + '%');
                        $('#alma-link-update-status').text('Processate ' + d.next_cursor + ' / ' + d.total + ' righe — aggiornati ' + totals.updated);
                        $.each(d.warnings || [], function(_, w){ $('#alma-link-update-warnings').append($('<li/>').text(w)); });
                        if (d.done) {
                            $('#alma-link-update-status').text('Completato: ' + totals.updated + ' Link aggiornati.');
                            $btn.text('Completato ✓');
                        } else {
                            step(d.next_cursor);
                        }
                    }).fail(function(){
                        $('#alma-link-update-status').text('Errore di rete: riprova (l\'applicazione riparte dal punto raggiunto).');
                        $btn.prop('disabled', false).text('Riprendi');
                        $btn.data('resume-cursor', cursor);
                    });
                }
                var resume = parseInt($btn.data('resume-cursor') || 0, 10);
                step(resume);
            });
        });
        </script>
        <?php
    }
}
