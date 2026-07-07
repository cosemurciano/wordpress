<?php
/**
 * Importazione massiva delle Idee contenuto da CSV con programmazione.
 *
 * - Pagina dedicata (raggiunta dal pulsante in "Tutte le idee"): upload CSV,
 *   download del CSV di esempio, limite bozze automatiche/giorno, report
 *   dell'ultimo import.
 * - Colonne CSV: Titolo (obbligatoria), Localita, Tema, Keyword principale,
 *   Keyword secondarie, Profilo istruzioni (nome o ID), Data programmata
 *   (YYYY-MM-DD o GG/MM/AAAA), Note AI. Separatore , o ; (autorilevato).
 * - Ogni riga crea un'idea (CPT alma_content_idea) con località risolta
 *   sull'indice geografico e data programmata.
 * - Runner in background (WP-Cron giornaliero + kick immediato post-import):
 *   per le idee programmate in scadenza seleziona automaticamente i link
 *   affiliati candidati (geo-first + keyword) e genera la bozza via OpenAI,
 *   rispettando un tetto di bozze/giorno per controllare i costi. Lock via
 *   add_option atomica, esiti nella tabella jobs (mai job invisibili).
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Content_Agent_Idea_Importer {
    const PAGE_SLUG = 'alma-ai-import-ideas';
    const CRON_HOOK = 'alma_ai_ideas_scheduled_runner';
    const OPTION_DAILY_LIMIT = 'alma_ai_ideas_daily_draft_limit';
    const OPTION_COUNTER = 'alma_ai_ideas_draft_counter';
    const LOCK_OPTION = 'alma_ai_ideas_runner_lock';
    const LOCK_TTL = 600;
    const REPORT_TRANSIENT = 'alma_ai_ideas_import_report_';
    const MAX_ROWS = 500;
    const MAX_AUTO_CANDIDATES = 8;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_scheduled'));
        add_action('admin_post_alma_ai_ideas_import', array(__CLASS__, 'handle_import'));
        add_action('admin_post_alma_ai_ideas_csv_template', array(__CLASS__, 'handle_template_download'));
        add_action('admin_post_alma_ai_ideas_import_settings', array(__CLASS__, 'handle_settings'));
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $first = strtotime('tomorrow 04:30', current_time('timestamp'));
            $first = $first - (current_time('timestamp') - time());
            wp_schedule_event($first, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_daily_limit() {
        return max(0, min(50, absint(get_option(self::OPTION_DAILY_LIMIT, 3))));
    }

    /* ---------------------------------------------------------------------
     * Pagina admin
     * ------------------------------------------------------------------ */

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }
        $list_url = admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-ideas');
        $report = get_transient(self::REPORT_TRANSIENT . get_current_user_id());
        $template_url = wp_nonce_url(admin_url('admin-post.php?action=alma_ai_ideas_csv_template'), 'alma_ai_ideas_csv_template');
        ?>
        <div class="wrap alma-ai-agent-admin">
            <h1><?php esc_html_e('Importazione massiva idee (CSV)', 'affiliate-link-manager-ai'); ?></h1>
            <p><a href="<?php echo esc_url($list_url); ?>">← <?php esc_html_e('Torna a Tutte le idee', 'affiliate-link-manager-ai'); ?></a></p>

            <?php if (is_array($report)) : delete_transient(self::REPORT_TRANSIENT . get_current_user_id()); ?>
                <div class="notice notice-<?php echo esc_attr(empty($report['errors']) ? 'success' : 'warning'); ?>">
                    <p><strong><?php printf(esc_html__('Import completato: %1$d idee create, %2$d righe scartate.', 'affiliate-link-manager-ai'), (int)$report['created'], count((array)$report['errors'])); ?></strong></p>
                    <?php if (!empty($report['errors'])) : ?>
                        <ul><?php foreach ((array)$report['errors'] as $error) : ?><li><?php echo esc_html($error); ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if (!empty($report['unresolved_locations'])) : ?>
                        <p><?php esc_html_e('Località non trovate nell\'indice geografico (idea creata senza località):', 'affiliate-link-manager-ai'); ?> <?php echo esc_html(implode(', ', (array)$report['unresolved_locations'])); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Struttura del file', 'affiliate-link-manager-ai'); ?></h2>
                <p><?php esc_html_e('Colonne (intestazione obbligatoria in prima riga; separatore virgola o punto e virgola):', 'affiliate-link-manager-ai'); ?></p>
                <p><code>Titolo</code> (obbligatoria) · <code>Localita</code> · <code>Tema</code> · <code>Keyword principale</code> · <code>Keyword secondarie</code> (separate da |) · <code>Profilo istruzioni</code> (nome o ID) · <code>Data programmata</code> (AAAA-MM-GG o GG/MM/AAAA) · <code>Note AI</code></p>
                <p><a class="button" href="<?php echo esc_url($template_url); ?>">⬇️ <?php esc_html_e('Scarica CSV di esempio', 'affiliate-link-manager-ai'); ?></a></p>
                <p class="description"><?php printf(esc_html__('Massimo %d righe per file. Le idee con Data programmata vengono lavorate automaticamente in background: alla data indicata l\'AI seleziona i link affiliati della località (o coerenti con le keyword) e genera la bozza, entro il limite giornaliero configurato qui sotto.', 'affiliate-link-manager-ai'), (int)self::MAX_ROWS); ?></p>
            </div>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Carica CSV', 'affiliate-link-manager-ai'); ?></h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('alma_ai_ideas_import'); ?>
                    <input type="hidden" name="action" value="alma_ai_ideas_import">
                    <p><input type="file" name="ideas_csv" accept=".csv,text/csv" required></p>
                    <p><button class="button button-primary"><?php esc_html_e('Importa idee', 'affiliate-link-manager-ai'); ?></button></p>
                </form>
                <p class="description"><?php esc_html_e('L\'import crea subito le idee (visibili in Tutte le idee) e avvia automaticamente il runner in background per quelle già in scadenza.', 'affiliate-link-manager-ai'); ?></p>
            </div>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Generazione automatica in background', 'affiliate-link-manager-ai'); ?></h2>
                <p class="description"><?php esc_html_e('Ogni idea programmata genera la sua bozza nel giorno previsto, senza tetto giornaliero: il ritmo lo decide la programmazione dei piani (quanti articoli, in quanti giorni, da quando) nella Regia AI. Il runner lavora a run brevi e si auto-programma finché ci sono idee in scadenza.', 'affiliate-link-manager-ai'); ?></p>
                <?php
                $counter = get_option(self::OPTION_COUNTER, array());
                $today = current_time('Y-m-d');
                $today_count = (is_array($counter) && ($counter['date'] ?? '') === $today) ? (int)$counter['count'] : 0;
                $next_run = wp_next_scheduled(self::CRON_HOOK);
                ?>
                <p class="description"><?php printf(esc_html__('Bozze generate oggi: %1$d · Prossima esecuzione automatica: %2$s', 'affiliate-link-manager-ai'), $today_count, $next_run ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i')) : '—'); ?></p>
            </div>
        </div>
        <?php
    }

    public static function handle_template_download() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_ideas_csv_template');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="idee-contenuto-esempio.csv"');
        $out = fopen('php://output', 'w');
        // BOM UTF-8 per Excel.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('Titolo', 'Localita', 'Tema', 'Keyword principale', 'Keyword secondarie', 'Profilo istruzioni', 'Data programmata', 'Note AI'));
        fputcsv($out, array('Cosa vedere ad Algeri in 3 giorni', 'Algeri', 'Città', 'algeri cosa vedere', 'algeria viaggio|casbah algeri', 'Default', current_time('Y-m-d'), 'Taglio pratico, itinerario giorno per giorno, budget indicativo.'));
        fputcsv($out, array('Weekend enogastronomico in Salento', 'Salento', 'Enogastronomia', 'salento weekend enogastronomia', 'cantine salento|cucina salentina', '', gmdate('Y-m-d', current_time('timestamp') + 7 * DAY_IN_SECONDS), 'Tono caldo, consigli locali, esperienze prenotabili.'));
        fclose($out);
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_ideas_import_settings');
        update_option(self::OPTION_DAILY_LIMIT, max(0, min(50, absint($_POST[self::OPTION_DAILY_LIMIT] ?? 3))), false);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Import CSV
     * ------------------------------------------------------------------ */

    public static function handle_import() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_ideas_import');

        $report = array('created' => 0, 'errors' => array(), 'unresolved_locations' => array());
        $file = $_FILES['ideas_csv'] ?? array();
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $report['errors'][] = __('Nessun file ricevuto.', 'affiliate-link-manager-ai');
        } elseif ((int)$file['size'] > 2 * 1024 * 1024) {
            $report['errors'][] = __('File troppo grande (max 2 MB).', 'affiliate-link-manager-ai');
        } else {
            $report = self::import_file($file['tmp_name']);
        }

        set_transient(self::REPORT_TRANSIENT . get_current_user_id(), $report, 300);
        // Avvio automatico: le idee già in scadenza vengono lavorate subito
        // in background, senza aspettare il giro notturno.
        if ($report['created'] > 0) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private static function import_file($path) {
        $report = array('created' => 0, 'errors' => array(), 'unresolved_locations' => array());
        $handle = fopen($path, 'r');
        if (!$handle) {
            $report['errors'][] = __('Impossibile leggere il file.', 'affiliate-link-manager-ai');
            return $report;
        }
        $first_line = (string) fgets($handle);
        // Rimuove il BOM e autorileva il separatore.
        $first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line);
        $delimiter = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';
        $headers = array_map(array(__CLASS__, 'normalize_header'), (array) str_getcsv($first_line, $delimiter));

        $column_map = array();
        $aliases = array(
            'titolo' => 'title', 'title' => 'title',
            'localita' => 'location', 'località' => 'location', 'location' => 'location',
            'tema' => 'theme', 'theme' => 'theme',
            'keyword principale' => 'primary_keyword', 'keyword' => 'primary_keyword',
            'keyword secondarie' => 'secondary_keywords',
            'profilo istruzioni' => 'profile', 'profilo' => 'profile',
            'data programmata' => 'scheduled', 'data' => 'scheduled',
            'note ai' => 'notes', 'note' => 'notes', 'prompt' => 'notes',
        );
        foreach ($headers as $index => $header) {
            if (isset($aliases[$header])) { $column_map[$aliases[$header]] = $index; }
        }
        if (!isset($column_map['title'])) {
            fclose($handle);
            $report['errors'][] = __('Colonna "Titolo" mancante nell\'intestazione.', 'affiliate-link-manager-ai');
            return $report;
        }

        $profiles_by_name = array();
        foreach ((array) ALMA_AI_Content_Agent_Instructions_Manager::get_profiles(100, 0) as $profile_row) {
            $profiles_by_name[mb_strtolower(trim((string)($profile_row['profile_name'] ?? '')))] = (int)($profile_row['id'] ?? 0);
        }

        $row_number = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row_number++;
            if ($report['created'] >= self::MAX_ROWS) {
                $report['errors'][] = sprintf(__('Limite di %d righe raggiunto: le righe successive sono state ignorate.', 'affiliate-link-manager-ai'), self::MAX_ROWS);
                break;
            }
            $get = function ($key) use ($row, $column_map) {
                return isset($column_map[$key], $row[$column_map[$key]]) ? trim((string) $row[$column_map[$key]]) : '';
            };
            $title = sanitize_text_field($get('title'));
            if ($title === '') {
                if (implode('', array_map('trim', (array)$row)) !== '') {
                    $report['errors'][] = sprintf(__('Riga %d: titolo mancante.', 'affiliate-link-manager-ai'), $row_number);
                }
                continue;
            }

            $profile_id = 0;
            $profile_raw = $get('profile');
            if ($profile_raw !== '') {
                $profile_id = ctype_digit($profile_raw) ? absint($profile_raw) : ($profiles_by_name[mb_strtolower($profile_raw)] ?? 0);
                if ($profile_id === 0) {
                    $report['errors'][] = sprintf(__('Riga %1$d: profilo istruzioni "%2$s" non trovato (idea creata senza profilo).', 'affiliate-link-manager-ai'), $row_number, $profile_raw);
                }
            }

            $idea_id = ALMA_AI_Content_Agent_Ideas::create($title, $profile_id);
            if ($idea_id < 1) {
                $report['errors'][] = sprintf(__('Riga %d: errore creazione idea.', 'affiliate-link-manager-ai'), $row_number);
                continue;
            }

            $location_raw = sanitize_text_field($get('location'));
            if ($location_raw !== '') {
                $location = self::resolve_location($location_raw);
                if ($location) {
                    update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_ID, (int)$location['id']);
                    update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_LABEL, sanitize_text_field($location['label']));
                } else {
                    $report['unresolved_locations'][] = $location_raw;
                }
            }

            $keywords = array_values(array_filter(array_map('sanitize_text_field', array_merge(
                array($get('primary_keyword')),
                array_map('trim', explode('|', $get('secondary_keywords')))
            ))));
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_KEYWORDS, $keywords);

            $theme = sanitize_text_field($get('theme'));
            $notes = sanitize_textarea_field($get('notes'));
            $prompt_parts = array_filter(array($notes, $theme !== '' ? 'Tema: ' . $theme : ''));
            if (!empty($prompt_parts)) {
                update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_PROMPT, implode("\n", $prompt_parts));
            }

            $scheduled = self::normalize_date($get('scheduled'));
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, $scheduled);
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SOURCE, 'csv');

            $report['created']++;
        }
        fclose($handle);
        $report['unresolved_locations'] = array_values(array_unique($report['unresolved_locations']));
        return $report;
    }

    private static function normalize_header($header) {
        return mb_strtolower(trim((string) $header));
    }

    private static function normalize_date($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') { return ''; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) { return $raw; }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return '';
    }

    private static function resolve_location($name) {
        global $wpdb;
        if (!class_exists('ALMA_Geo_Index_Store')) { return null; }
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_name, country FROM {$store->table_locations()} WHERE LOWER(canonical_name) = LOWER(%s) ORDER BY id ASC LIMIT 1",
            $name
        ), ARRAY_A);
        if (!$row) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_name, country FROM {$store->table_locations()} WHERE canonical_name LIKE %s ORDER BY CHAR_LENGTH(canonical_name) ASC LIMIT 1",
                $wpdb->esc_like($name) . '%'
            ), ARRAY_A);
        }
        if (!$row) { return null; }
        $label = html_entity_decode(sanitize_text_field($row['canonical_name']), ENT_QUOTES, 'UTF-8');
        $country = sanitize_text_field((string)$row['country']);
        if ($country !== '' && strcasecmp($country, $label) !== 0) { $label .= ' (' . $country . ')'; }
        return array('id' => (int)$row['id'], 'label' => $label);
    }

    /* ---------------------------------------------------------------------
     * Runner in background
     * ------------------------------------------------------------------ */

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
     * Genera le bozze di TUTTE le idee programmate in scadenza: il ritmo lo
     * decide la programmazione dei piani (quanti articoli, in quanti giorni,
     * da quando), non un tetto giornaliero. Il run lavora entro un budget di
     * tempo e, se restano idee in scadenza, si auto-programma un run di
     * recupero (pattern del warmer). Riusa l'intera pipeline esistente:
     * selezione candidati via Knowledge Search (geo-first), sessione
     * contenuto, Draft Builder.
     */
    public static function run_scheduled() {
        if (!self::acquire_lock()) { return; }
        global $wpdb;
        $job_id = 0;
        $processed = 0;
        try {
            $today = current_time('Y-m-d');
            $counter = get_option(self::OPTION_COUNTER, array());
            $done_today = (is_array($counter) && ($counter['date'] ?? '') === $today) ? (int)$counter['count'] : 0;
            if (empty(get_option('alma_openai_api_key', ''))) { return; }

            $idea_ids = get_posts(array(
                'post_type' => ALMA_AI_Content_Agent_Ideas::CPT,
                'post_status' => 'publish',
                'posts_per_page' => 10,
                'fields' => 'ids',
                'orderby' => 'meta_value',
                'meta_key' => ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT,
                'order' => 'ASC',
                'no_found_rows' => true,
                'meta_query' => array(
                    array('key' => ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, 'value' => '', 'compare' => '!='),
                    array('key' => ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, 'value' => $today, 'compare' => '<='),
                    array('key' => ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, 'value' => '', 'compare' => '='),
                    array('key' => ALMA_AI_Content_Agent_Ideas::META_DRAFT_POST_ID, 'value' => 0, 'compare' => '<=', 'type' => 'NUMERIC'),
                ),
            ));
            if (empty($idea_ids)) { return; }

            $jobs_table = ALMA_AI_Content_Agent_Store::table('jobs');
            $wpdb->insert($jobs_table, array('job_type' => 'scheduled_ideas', 'status' => 'running', 'total_items' => count($idea_ids), 'started_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
            $job_id = (int) $wpdb->insert_id;

            $errors = 0;
            $last_error = '';
            $started_ts = time();
            $original_user = get_current_user_id();
            foreach ($idea_ids as $idea_id) {
                // Budget di tempo per run (hosting condiviso): le idee non
                // elaborate restano in scadenza e riprendono col run di
                // recupero auto-programmato qui sotto.
                if ((time() - $started_ts) > 180) { break; }
                $result = self::generate_draft_for_scheduled_idea((int) $idea_id);
                $processed++;
                if (empty($result['success'])) {
                    $errors++;
                    $last_error = sanitize_text_field((string)($result['error'] ?? 'errore generazione'));
                } else {
                    $done_today++;
                }
                update_option(self::OPTION_COUNTER, array('date' => $today, 'count' => $done_today), false);
                if ($job_id > 0) {
                    $wpdb->update($jobs_table, array('processed_items' => $processed, 'errors_count' => $errors, 'last_error' => $last_error, 'updated_at' => current_time('mysql')), array('id' => $job_id));
                }
            }
            wp_set_current_user($original_user);
            if ($job_id > 0) {
                $wpdb->update($jobs_table, array('status' => $errors > 0 ? 'completed_with_errors' : 'completed', 'finished_at' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => $job_id));
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
        // Run di recupero: se questo giro ha prodotto qualcosa e restano
        // idee in scadenza, si riparte tra 2 minuti (mai loop a vuoto).
        if ($processed > 0 && self::due_ideas_count() > 0) {
            wp_schedule_single_event(time() + 120, self::CRON_HOOK);
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
    }

    /**
     * Idee programmate in scadenza (oggi o prima) senza bozza.
     */
    public static function due_ideas_count() {
        $ids = get_posts(array(
            'post_type' => ALMA_AI_Content_Agent_Ideas::CPT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, 'value' => '', 'compare' => '!='),
                array('key' => ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, 'value' => current_time('Y-m-d'), 'compare' => '<='),
                array('key' => ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, 'value' => '', 'compare' => '='),
                array('key' => ALMA_AI_Content_Agent_Ideas::META_DRAFT_POST_ID, 'value' => 0, 'compare' => '<=', 'type' => 'NUMERIC'),
            ),
        ));
        return count($ids);
    }

    /**
     * Genera SUBITO la bozza di un'idea: usato dall'agente di ideazione per
     * le idee programmate a oggi. Nessun tetto giornaliero: il ritmo lo
     * decide la programmazione del piano (il contatore resta solo come
     * statistica visibile in Regia).
     */
    public static function generate_draft_now($idea_id) {
        $today = current_time('Y-m-d');
        $counter = get_option(self::OPTION_COUNTER, array());
        $done = (is_array($counter) && ($counter['date'] ?? '') === $today) ? (int)$counter['count'] : 0;
        $result = self::generate_draft_for_scheduled_idea(absint($idea_id));
        if (!empty($result['success'])) {
            update_option(self::OPTION_COUNTER, array('date' => $today, 'count' => $done + 1), false);
        }
        return is_array($result) ? $result : array('success' => false, 'error' => 'Risposta generazione non valida');
    }

    /**
     * Pipeline per una singola idea programmata:
     * 1. candidati affiliate via Knowledge Search (geo-first sulla località
     *    dell'idea + keyword del CSV), top N selezionati;
     * 2. selezione persistita sull'idea e caricata in sessione (nel contesto
     *    dell'autore dell'idea, come farebbe dall'admin);
     * 3. bozza generata dal Draft Builder esistente; meta idea aggiornate.
     */
    private static function generate_draft_for_scheduled_idea($idea_id) {
        $idea = ALMA_AI_Content_Agent_Ideas::get($idea_id);
        if (empty($idea)) { return array('success' => false, 'error' => 'Idea non trovata'); }
        $author_id = absint(get_post_field('post_author', $idea_id)) ?: 1;
        wp_set_current_user($author_id);

        $geo_link_ids = array();
        if (!empty($idea['location_id']) && class_exists('ALMA_Geo_Index_Store')) {
            $store = new ALMA_Geo_Index_Store();
            $geo_link_ids = $store->get_affiliate_link_ids_for_area((int)$idea['location_id']);
        }
        $keywords = (array)($idea['keywords'] ?? array());
        $query_text = trim(implode(' ', array_filter(array($idea['title'], implode(' ', array_slice($keywords, 0, 3))))));

        $search = ALMA_AI_Content_Agent_Knowledge_Search::search(array(
            'content_search_query' => $query_text,
            'search_scope' => 'affiliate_links_only',
            'geo_location_id' => (int)($idea['location_id'] ?? 0),
            'geo_location_label' => (string)($idea['location_label'] ?? ''),
            'geo_link_ids' => $geo_link_ids,
        ));
        $candidates = array_slice((array)($search['groups']['affiliate_link'] ?? array()), 0, self::MAX_AUTO_CANDIDATES);
        // Verifica LIVE al momento della creazione dell'articolo: i link
        // morti vengono scartati (e marcati) prima di entrare nella bozza.
        if (class_exists('ALMA_Link_Health_Checker')) {
            $candidates = ALMA_Link_Health_Checker::filter_live_candidates($candidates);
        }
        // Candidato universale: il miglior link di tipologia universale
        // (assicurazioni, eSIM…) è sempre disponibile all'AI, per qualsiasi
        // destinazione — deciderà lei se e dove inserirlo (max 1 per
        // articolo, come da regole di inserimento).
        if (class_exists('ALMA_Universal_Link_Types')) {
            $existing_ids = array();
            foreach ($candidates as $candidate) { $existing_ids[] = absint($candidate['source_id'] ?? 0); }
            foreach (ALMA_Universal_Link_Types::top_universal_links(1, $existing_ids) as $universal_id) {
                $term_labels = array();
                $terms = get_the_terms($universal_id, 'link_type');
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) { $term_labels[] = $term->name; }
                }
                $candidates[] = array(
                    'source_id' => (int) $universal_id,
                    'title' => html_entity_decode(get_the_title($universal_id), ENT_QUOTES, 'UTF-8'),
                    'link_types' => implode(', ', $term_labels),
                    'score' => 1,
                    'reason' => __('Tipologia universale: valido per qualsiasi articolo (inserire solo se naturale, max 1).', 'affiliate-link-manager-ai'),
                    'selected' => true,
                    'universal' => true,
                );
            }
        }
        if (empty($candidates)) {
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, current_time('mysql'));
            return array('success' => false, 'error' => 'Nessun link affiliato candidato per l\'idea #' . $idea_id);
        }
        foreach ($candidates as &$candidate) { $candidate['selected'] = true; }
        unset($candidate);

        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_RESULTS, $candidates);
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SELECTION, $candidates);
        update_user_meta($author_id, '_alma_active_idea_id', $idea_id);
        ALMA_AI_Content_Agent_Selection_Session::load_from_idea(ALMA_AI_Content_Agent_Ideas::get($idea_id));

        $result = ALMA_AI_Content_Agent_Draft_Builder::generate_from_selection_session($author_id);
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_EXECUTED_AT, current_time('mysql'));
        if (!empty($result['success']) && !empty($result['post_id'])) {
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_DRAFT_POST_ID, absint($result['post_id']));
        }
        return is_array($result) ? $result : array('success' => false, 'error' => 'Risposta generazione non valida');
    }
}
