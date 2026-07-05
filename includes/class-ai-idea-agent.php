<?php
/**
 * Agente AI di ideazione contenuti (OpenAI Responses API + tool calling).
 *
 * Missione: leggere i dati reali del sito tramite strumenti tipizzati
 * (performance click, gap geografici, link affiliati, articoli esistenti)
 * e CREARE nuove idee contenuto motivate dai dati, visibili in "Tutte le
 * idee" — mai bozze o pubblicazioni dirette.
 *
 * Guard-rail:
 * - strumenti in sola lettura + un'unica azione: crea_idea (CPT idea);
 * - massimo idee per esecuzione e massimo esecuzioni al giorno configurabili;
 * - loop limitato (round e durata), lock atomico anti-concorrenza;
 * - ogni chiamata OpenAI registrata nell'usage logger con costo stimato;
 * - esecuzione in background (evento cron immediato), report persistente.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Idea_Agent {
    const CRON_HOOK = 'alma_ai_idea_agent_run';
    const OPTION_MAX_IDEAS = 'alma_ai_idea_agent_max_ideas';
    const OPTION_DAILY_RUNS = 'alma_ai_idea_agent_daily_runs';
    const OPTION_RUN_COUNTER = 'alma_ai_idea_agent_run_counter';
    const OPTION_LAST_RUN = 'alma_ai_idea_agent_last_run';
    const LOCK_OPTION = 'alma_ai_idea_agent_lock';
    const LOCK_TTL = 600;
    const MAX_ROUNDS = 12;

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'), 10, 3);
        add_action('admin_post_alma_ai_idea_agent_start', array(__CLASS__, 'handle_start'));
        add_action('admin_post_alma_ai_idea_agent_settings', array(__CLASS__, 'handle_settings'));
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_max_ideas() {
        return max(1, min(10, absint(get_option(self::OPTION_MAX_IDEAS, 5))));
    }

    public static function get_daily_runs_limit() {
        return max(1, min(20, absint(get_option(self::OPTION_DAILY_RUNS, 2))));
    }

    public static function get_last_run() {
        $report = get_option(self::OPTION_LAST_RUN, null);
        return is_array($report) ? $report : null;
    }

    public static function runs_today() {
        $counter = get_option(self::OPTION_RUN_COUNTER, array());
        return (is_array($counter) && ($counter['date'] ?? '') === current_time('Y-m-d')) ? (int)$counter['count'] : 0;
    }

    /* ---------------------------------------------------------------------
     * Avvio (admin) → esecuzione in background
     * ------------------------------------------------------------------ */

    public static function handle_start() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_idea_agent_start');
        $notice = array('type' => 'success', 'message' => __('Agente ideazione avviato in background: i risultati compariranno qui e in Tutte le idee entro qualche minuto.', 'affiliate-link-manager-ai'));

        if (empty(get_option('alma_openai_api_key', ''))) {
            $notice = array('type' => 'error', 'message' => __('OpenAI non è configurata.', 'affiliate-link-manager-ai'));
        } elseif (self::runs_today() >= self::get_daily_runs_limit()) {
            $notice = array('type' => 'error', 'message' => __('Limite di esecuzioni giornaliere dell\'agente raggiunto.', 'affiliate-link-manager-ai'));
        } elseif (get_option(self::LOCK_OPTION)) {
            $notice = array('type' => 'error', 'message' => __('Un\'esecuzione dell\'agente è già in corso.', 'affiliate-link-manager-ai'));
        } else {
            $objective = sanitize_textarea_field(wp_unslash($_POST['agent_objective'] ?? ''));
            $create_drafts = empty($_POST['agent_create_drafts']) ? 0 : 1;
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, array(get_current_user_id(), $objective, $create_drafts));
            if (function_exists('spawn_cron')) { spawn_cron(); }
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-ideas'));
        exit;
    }

    public static function handle_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_idea_agent_settings');
        update_option(self::OPTION_MAX_IDEAS, max(1, min(10, absint($_POST[self::OPTION_MAX_IDEAS] ?? 5))), false);
        update_option(self::OPTION_DAILY_RUNS, max(1, min(20, absint($_POST[self::OPTION_DAILY_RUNS] ?? 2))), false);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-ideas'));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Loop agente
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

    public static function run($user_id = 0, $objective = '', $create_drafts = 0) {
        if (!self::acquire_lock()) { return; }
        $started_at = current_time('mysql');
        $report = array(
            'started_at' => $started_at, 'finished_at' => '', 'rounds' => 0,
            'tool_calls' => array(), 'ideas_created' => array(), 'drafts_created' => array(),
            'cost_total' => 0.0, 'model' => '', 'summary' => '', 'error' => '',
        );
        try {
            if (self::runs_today() >= self::get_daily_runs_limit()) {
                $report['error'] = 'Limite esecuzioni giornaliere raggiunto.';
                return;
            }
            update_option(self::OPTION_RUN_COUNTER, array('date' => current_time('Y-m-d'), 'count' => self::runs_today() + 1), false);

            $user_id = absint($user_id) ?: 1;
            wp_set_current_user($user_id);

            $max_ideas = self::get_max_ideas();
            $ideas_created = array();

            $input_items = array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => self::system_prompt($max_ideas)))),
                array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => self::user_prompt($objective)))),
            );

            for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
                $report['rounds'] = $round;
                $res = ALMA_OpenAI_Service::request(array(
                    'input_items' => $input_items,
                    'tools' => self::tool_definitions(),
                    'tool_choice' => 'auto',
                    'max_output_tokens' => 1600,
                    'temperature' => 0.4,
                    'timeout' => 90,
                ));
                if (!empty($res['estimated_cost'])) { $report['cost_total'] += (float)$res['estimated_cost']; }
                $report['model'] = sanitize_text_field((string)($res['model'] ?? $report['model']));
                ALMA_AI_Usage_Logger::log(array('task' => 'idea_agent', 'success' => !empty($res['success']), 'model' => $res['model'] ?? '', 'response_time' => $res['response_time'] ?? null, 'input_tokens' => $res['usage']['input_tokens'] ?? null, 'output_tokens' => $res['usage']['output_tokens'] ?? null, 'estimated_cost' => $res['estimated_cost'] ?? null, 'error' => $res['error'] ?? '', 'reference_id' => 'idea_agent_run'));

                if (empty($res['success'])) {
                    $report['error'] = sanitize_text_field((string)($res['error'] ?? 'Errore OpenAI'));
                    break;
                }
                $function_calls = (array)($res['function_calls'] ?? array());
                if (empty($function_calls)) {
                    // Risposta finale dell'agente.
                    $report['summary'] = sanitize_textarea_field((string)$res['response']);
                    break;
                }
                foreach ($function_calls as $call) {
                    $name = sanitize_key($call['name']);
                    $arguments = json_decode((string)$call['arguments'], true);
                    if (!is_array($arguments)) { $arguments = array(); }
                    $output = self::execute_tool($name, $arguments, $ideas_created, $max_ideas);
                    $report['tool_calls'][] = array('name' => $name, 'arguments' => wp_json_encode($arguments));
                    $input_items[] = array('type' => 'function_call', 'call_id' => $call['call_id'], 'name' => $call['name'], 'arguments' => $call['arguments']);
                    $input_items[] = array('type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => wp_json_encode($output));
                }
                if ($round === self::MAX_ROUNDS) {
                    $report['error'] = 'Limite di round del loop agente raggiunto senza risposta finale.';
                }
            }
            $report['ideas_created'] = $ideas_created;

            // Su richiesta: genera SUBITO le bozze delle idee create, nel
            // rispetto del limite giornaliero di bozze automatiche (Fase 3).
            if (!empty($create_drafts) && !empty($ideas_created) && class_exists('ALMA_AI_Content_Agent_Idea_Importer')) {
                foreach ($ideas_created as $idea) {
                    $draft = ALMA_AI_Content_Agent_Idea_Importer::generate_draft_now((int)$idea['id']);
                    $report['drafts_created'][] = array(
                        'idea_id' => (int)$idea['id'],
                        'titolo' => $idea['titolo'],
                        'post_id' => (int)($draft['post_id'] ?? 0),
                        'error' => empty($draft['success']) ? sanitize_text_field((string)($draft['error'] ?? '')) : '',
                    );
                    if (!empty($draft['quota_exhausted'])) {
                        $report['drafts_created'][] = array('idea_id' => 0, 'titolo' => '', 'post_id' => 0, 'error' => 'Limite giornaliero raggiunto: le idee rimanenti restano in coda (generale automaticamente domani o dal workspace).');
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $report['error'] = sanitize_text_field($e->getMessage());
            ALMA_Logger::error('Idea agent run error', array('error' => $e->getMessage()));
        } finally {
            $report['finished_at'] = current_time('mysql');
            update_option(self::OPTION_LAST_RUN, $report, false);
            delete_option(self::LOCK_OPTION);
            // Regia Telegram: report di fine esecuzione alle chat autorizzate.
            if (class_exists('ALMA_Telegram_Bot')) {
                ALMA_Telegram_Bot::notify_agent_report($report);
            }
        }
    }

    private static function system_prompt($max_ideas) {
        $prompt = 'Sei l\'agente strategico di ideazione contenuti di un blog di viaggi italiano monetizzato con link affiliati. '
            . 'Il tuo compito: analizzare i DATI REALI del sito tramite gli strumenti disponibili e creare fino a ' . (int)$max_ideas . ' nuove idee di articolo ad alto potenziale. '
            . 'Metodo obbligatorio: 1) analizza le performance, i gap geografici e — se disponibile — le ricerche reali con analizza_ricerche_google (le "opportunità" con impression alte e posizione debole sono il segnale più prezioso: domanda dimostrata senza contenuto adeguato); 2) per ogni opportunità verifica con cerca_link_affiliati che esistano link da monetizzare, con elenca_articoli_esistenti che il tema non sia già coperto (evita duplicati) e con cerca_media se la Media Library ha già immagini utilizzabili sul tema (se sì, segnalalo nel prompt dell\'idea); 3) crea le idee con crea_idea, includendo località (se pertinente), un prompt editoriale ricco che citi le query target, e una data programmata distribuita nei prossimi 14 giorni. '
            . 'Privilegia: località con link affiliati ma senza click (offerta inutilizzata), località con molti articoli ma senza copertura pratica/commerciale, trend di click in crescita. '
            . 'Non inventare dati: basa ogni decisione sugli output degli strumenti. Non superare il numero massimo di idee. '
            . 'Alla fine rispondi in italiano con un riepilogo: per ogni idea creata, titolo e motivazione basata sui numeri.';
        if (self::get_vector_store_id() !== '') {
            $prompt .= ' Hai inoltre accesso allo storage documenti dell\'editore su OpenAI tramite file_search: consultalo per linee guida editoriali, brief e materiali di contesto prima di decidere le idee.';
        }
        return $prompt;
    }

    private static function get_vector_store_id() {
        $id = trim((string) get_option('alma_openai_vector_store_id', ''));
        return preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $id) ? $id : '';
    }

    private static function user_prompt($objective) {
        $objective = trim((string)$objective);
        $base = 'Analizza i dati del sito e crea le idee di contenuto più promettenti.';
        return $objective !== '' ? $base . ' Obiettivo specifico indicato dall\'editore: ' . $objective : $base;
    }

    /* ---------------------------------------------------------------------
     * Strumenti
     * ------------------------------------------------------------------ */

    private static function tool_definitions() {
        $tools = array();
        // Storage OpenAI: strumento ospitato file_search sul Vector Store
        // configurato — la ricerca avviene lato OpenAI, nessun round locale.
        $vector_store_id = self::get_vector_store_id();
        if ($vector_store_id !== '') {
            $tools[] = array('type' => 'file_search', 'vector_store_ids' => array($vector_store_id));
        }
        return array_merge($tools, array(
            array('type' => 'function', 'name' => 'analizza_performance', 'description' => 'Statistiche reali dei click affiliati: trend 7/30/180 giorni, top link, top articoli, località più cliccate, link senza click.', 'parameters' => array('type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'trova_gap_geografici', 'description' => 'Gap di monetizzazione: località con link affiliati ma senza click negli ultimi 90 giorni, e località con articoli pubblicati ma senza link affiliati.', 'parameters' => array('type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'analizza_ricerche_google', 'description' => 'Dati reali da Google Search Console: query top per click, query in crescita, OPPORTUNITÀ (query con molte impression ma posizione debole 8-30: i contenuti da creare/rafforzare) e pagine top. Usalo per proporre idee con domanda di ricerca già dimostrata.', 'parameters' => array('type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'cerca_link_affiliati', 'description' => 'Cerca i link affiliati disponibili per una query e (opzionale) una località: restituisce titolo, tipologie e punteggio di pertinenza.', 'parameters' => array('type' => 'object', 'properties' => array('query' => array('type' => 'string', 'description' => 'Cosa cercare (es. "tour deserto")'), 'localita' => array('type' => 'string', 'description' => 'Nome località opzionale')), 'required' => array('query'), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'elenca_articoli_esistenti', 'description' => 'Articoli già pubblicati che corrispondono a una ricerca: usalo per evitare idee duplicate.', 'parameters' => array('type' => 'object', 'properties' => array('query' => array('type' => 'string', 'description' => 'Tema o località da verificare')), 'required' => array('query'), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'cerca_media', 'description' => 'Immagini editoriali già presenti nella Media Library di WordPress che corrispondono a una ricerca: usale per capire se un\'idea ha già immagini utilizzabili.', 'parameters' => array('type' => 'object', 'properties' => array('query' => array('type' => 'string', 'description' => 'Tema o località delle immagini da cercare')), 'required' => array('query'), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'crea_idea', 'description' => 'Crea una nuova idea contenuto (visibile in Tutte le idee). Non pubblica nulla: la bozza verrà generata in seguito nel rispetto dei limiti giornalieri.', 'parameters' => array('type' => 'object', 'properties' => array(
                'titolo' => array('type' => 'string', 'description' => 'Titolo di lavoro dell\'articolo'),
                'localita' => array('type' => 'string', 'description' => 'Nome della località (opzionale, verrà risolto sull\'indice geografico)'),
                'prompt' => array('type' => 'string', 'description' => 'Istruzioni editoriali per la bozza: taglio, cosa includere, perché l\'idea è promettente'),
                'keywords' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Keyword SEO (max 5)'),
                'data_programmata' => array('type' => 'string', 'description' => 'Data generazione bozza YYYY-MM-DD (opzionale)'),
            ), 'required' => array('titolo', 'prompt'), 'additionalProperties' => false)),
        ));
    }

    private static function execute_tool($name, $arguments, &$ideas_created, $max_ideas) {
        switch ($name) {
            case 'analizza_performance':
                return self::tool_performance();
            case 'trova_gap_geografici':
                return self::tool_geo_gaps();
            case 'analizza_ricerche_google':
                return class_exists('ALMA_GSC_Connector') ? ALMA_GSC_Connector::agent_payload() : array('error' => 'Connettore Search Console non disponibile.');
            case 'cerca_link_affiliati':
                return self::tool_search_links(sanitize_text_field((string)($arguments['query'] ?? '')), sanitize_text_field((string)($arguments['localita'] ?? '')));
            case 'elenca_articoli_esistenti':
                return self::tool_existing_articles(sanitize_text_field((string)($arguments['query'] ?? '')));
            case 'cerca_media':
                return self::tool_search_media(sanitize_text_field((string)($arguments['query'] ?? '')));
            case 'crea_idea':
                return self::tool_create_idea($arguments, $ideas_created, $max_ideas);
        }
        return array('error' => 'Strumento sconosciuto: ' . $name);
    }

    private static function tool_performance() {
        $snapshot = ALMA_Dashboard_Insights::get_snapshot();
        if (!$snapshot) {
            ALMA_Dashboard_Insights::rebuild();
            $snapshot = ALMA_Dashboard_Insights::get_snapshot();
        }
        if (!$snapshot) { return array('error' => 'Snapshot statistiche non disponibile.'); }
        return array(
            'periodi' => $snapshot['periods'] ?? array(),
            'top_link_30gg' => array_slice((array)($snapshot['top_links'] ?? array()), 0, 5),
            'top_articoli_30gg' => array_slice((array)($snapshot['top_articles_30d'] ?? array()), 0, 5),
            'localita_piu_cliccate_90gg' => array_slice((array)($snapshot['geo_top'] ?? array()), 0, 8),
            'link_totali' => $snapshot['links_total'] ?? 0,
            'link_senza_click_90gg' => $snapshot['links_no_clicks_90d'] ?? 0,
            'dati_aggiornati_al' => $snapshot['generated_at'] ?? '',
        );
    }

    private static function tool_geo_gaps() {
        $snapshot = ALMA_Dashboard_Insights::get_snapshot();
        if (!$snapshot) { return array('error' => 'Snapshot statistiche non disponibile: chiama prima analizza_performance.'); }
        return array(
            'localita_con_link_senza_click_90gg' => array_slice((array)($snapshot['geo_unused'] ?? array()), 0, 15),
            'localita_con_articoli_senza_link' => array_slice((array)($snapshot['geo_no_links'] ?? array()), 0, 15),
        );
    }

    private static function tool_search_links($query, $location_name) {
        if ($query === '') { return array('error' => 'Query mancante.'); }
        $geo_link_ids = array();
        $location_label = '';
        if ($location_name !== '' && class_exists('ALMA_Geo_Index_Store')) {
            $location = self::resolve_location($location_name);
            if ($location) {
                $location_label = $location['label'];
                $store = new ALMA_Geo_Index_Store();
                $geo_link_ids = $store->get_affiliate_link_ids_for_area($location['id']);
            }
        }
        $search = ALMA_AI_Content_Agent_Knowledge_Search::search(array(
            'content_search_query' => $query,
            'search_scope' => 'affiliate_links_only',
            'geo_location_label' => $location_label,
            'geo_link_ids' => $geo_link_ids,
        ));
        $rows = array_slice((array)($search['groups']['affiliate_link'] ?? array()), 0, 10);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array('id' => (int)$row['source_id'], 'titolo' => $row['title'], 'tipologie' => $row['link_types'], 'score' => (int)$row['score'], 'motivo' => $row['reason']);
        }
        return array('localita_risolta' => $location_label, 'link_trovati' => count($out), 'link' => $out);
    }

    private static function tool_existing_articles($query) {
        if ($query === '') { return array('error' => 'Query mancante.'); }
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 's' => $query, 'numberposts' => 10, 'orderby' => 'relevance', 'suppress_filters' => false));
        $out = array();
        foreach ((array)$posts as $p) {
            $out[] = array('id' => (int)$p->ID, 'titolo' => html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'), 'data' => get_the_date('Y-m-d', $p));
        }
        return array('articoli_trovati' => count($out), 'articoli' => $out);
    }

    /**
     * Immagini editoriali della Media Library (dall'indice media dell'AI
     * Content Agent): solo metadati, nessun file binario.
     */
    private static function tool_search_media($query) {
        global $wpdb;
        if ($query === '') { return array('error' => 'Query mancante.'); }
        $table = ALMA_AI_Content_Agent_Store::table('media_index');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array('error' => 'Indice media non disponibile: ricostruiscilo da Impostazioni AI Content.');
        }
        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, file_name, title, alt_text FROM {$table}
             WHERE is_editorial_candidate = 1 AND (file_name LIKE %s OR title LIKE %s OR alt_text LIKE %s OR caption LIKE %s OR search_text LIKE %s)
             ORDER BY indexed_at DESC LIMIT 10",
            $like, $like, $like, $like, $like
        ), ARRAY_A);
        $out = array();
        foreach ((array)$rows as $row) {
            $out[] = array(
                'attachment_id' => (int)$row['attachment_id'],
                'titolo' => sanitize_text_field((string)($row['title'] ?: $row['file_name'])),
                'alt' => sanitize_text_field((string)$row['alt_text']),
            );
        }
        return array('immagini_trovate' => count($out), 'immagini' => $out);
    }

    private static function tool_create_idea($arguments, &$ideas_created, $max_ideas) {
        if (count($ideas_created) >= $max_ideas) {
            return array('error' => 'Limite massimo di idee per esecuzione raggiunto: non crearne altre, produci il riepilogo finale.');
        }
        $title = sanitize_text_field((string)($arguments['titolo'] ?? ''));
        $prompt = sanitize_textarea_field((string)($arguments['prompt'] ?? ''));
        if ($title === '' || $prompt === '') { return array('error' => 'titolo e prompt sono obbligatori.'); }

        $idea_id = ALMA_AI_Content_Agent_Ideas::create($title);
        if ($idea_id < 1) { return array('error' => 'Errore creazione idea.'); }
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_PROMPT, $prompt);
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SOURCE, 'agent');

        $location_name = sanitize_text_field((string)($arguments['localita'] ?? ''));
        $location_label = '';
        if ($location_name !== '') {
            $location = self::resolve_location($location_name);
            if ($location) {
                update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_ID, (int)$location['id']);
                update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LOCATION_LABEL, $location['label']);
                $location_label = $location['label'];
            }
        }
        $keywords = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array)($arguments['keywords'] ?? array())))), 0, 5);
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_KEYWORDS, $keywords);
        $scheduled = sanitize_text_field((string)($arguments['data_programmata'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled)) {
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, $scheduled);
        }

        $ideas_created[] = array('id' => $idea_id, 'titolo' => $title, 'localita' => $location_label);
        return array('creata' => true, 'idea_id' => $idea_id, 'localita_risolta' => $location_label, 'idee_rimanenti' => max(0, $max_ideas - count($ideas_created)));
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
     * UI (card nella pagina Tutte le idee)
     * ------------------------------------------------------------------ */

    public static function render_panel() {
        if (!current_user_can('manage_options')) { return; }
        $last = self::get_last_run();
        $running = (bool) get_option(self::LOCK_OPTION);
        echo '<div class="alma-ideas-card" style="border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:14px 16px;margin:12px 0;">';
        echo '<h2 style="margin:0 0 6px;">🤖 Agente ideazione AI</h2>';
        echo '<p class="description" style="margin-top:0;">Analizza i dati reali (click, gap geografici, link disponibili, articoli esistenti) e crea nuove idee motivate dai numeri. Non genera bozze e non pubblica nulla.</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
        wp_nonce_field('alma_ai_idea_agent_start');
        echo '<input type="hidden" name="action" value="alma_ai_idea_agent_start">';
        echo '<p style="margin:0;flex:1 1 320px;"><label><strong>'.esc_html__('Obiettivo (opzionale)', 'affiliate-link-manager-ai').'</strong><br><input type="text" name="agent_objective" class="widefat" placeholder="'.esc_attr__('Es. concentrati sull\'Italia, oppure su idee per l\'estate…', 'affiliate-link-manager-ai').'"></label></p>';
        echo '<p style="margin:0;"><label title="'.esc_attr__('Le bozze contano nel limite giornaliero di bozze automatiche (impostazioni Importazione massiva).', 'affiliate-link-manager-ai').'"><input type="checkbox" name="agent_create_drafts" value="1"> '.esc_html__('Crea subito anche le bozze', 'affiliate-link-manager-ai').'</label></p>';
        echo '<p style="margin:0;"><button class="button button-primary" '.disabled($running, true, false).'>'.esc_html($running ? __('Esecuzione in corso…', 'affiliate-link-manager-ai') : __('Esegui agente', 'affiliate-link-manager-ai')).'</button></p>';
        echo '</form>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:8px;">';
        wp_nonce_field('alma_ai_idea_agent_settings');
        echo '<input type="hidden" name="action" value="alma_ai_idea_agent_settings">';
        echo '<label>'.esc_html__('Max idee per esecuzione', 'affiliate-link-manager-ai').' <input type="number" min="1" max="10" class="small-text" name="'.esc_attr(self::OPTION_MAX_IDEAS).'" value="'.esc_attr((string)self::get_max_ideas()).'"></label>';
        echo '<label>'.esc_html__('Max esecuzioni al giorno', 'affiliate-link-manager-ai').' <input type="number" min="1" max="20" class="small-text" name="'.esc_attr(self::OPTION_DAILY_RUNS).'" value="'.esc_attr((string)self::get_daily_runs_limit()).'"></label>';
        echo '<span class="description">'.esc_html(sprintf(__('Esecuzioni oggi: %1$d / %2$d', 'affiliate-link-manager-ai'), self::runs_today(), self::get_daily_runs_limit())).'</span>';
        echo '<button class="button">'.esc_html__('Salva limiti', 'affiliate-link-manager-ai').'</button>';
        echo '</form>';

        if ($last) {
            $ideas = (array)($last['ideas_created'] ?? array());
            echo '<details style="margin-top:10px;"'.(empty($last['error']) ? '' : ' open').'><summary><strong>'.esc_html__('Ultima esecuzione', 'affiliate-link-manager-ai').'</strong> · '.esc_html($last['started_at'] ?? '').' · '.esc_html(sprintf(__('%1$d idee create, %2$d chiamate strumento, costo stimato ~$%3$s', 'affiliate-link-manager-ai'), count($ideas), count((array)($last['tool_calls'] ?? array())), number_format((float)($last['cost_total'] ?? 0), 4))).($last['model'] !== '' ? ' · '.esc_html($last['model']) : '').'</summary>';
            if (!empty($last['error'])) { echo '<p style="color:#d63638;"><strong>'.esc_html__('Errore:', 'affiliate-link-manager-ai').'</strong> '.esc_html($last['error']).'</p>'; }
            if (!empty($ideas)) {
                echo '<ul style="margin:8px 0 0 18px;list-style:disc;">';
                foreach ($ideas as $idea) {
                    $edit = admin_url('edit.php?post_type=affiliate_link&page=alma-ai-add-idea&idea_id=' . (int)$idea['id']);
                    echo '<li><a href="'.esc_url($edit).'">'.esc_html($idea['titolo']).'</a>'.(!empty($idea['localita']) ? ' — 📍 '.esc_html($idea['localita']) : '').'</li>';
                }
                echo '</ul>';
            }
            $drafts = (array)($last['drafts_created'] ?? array());
            if (!empty($drafts)) {
                echo '<p style="margin:10px 0 4px;"><strong>'.esc_html__('Bozze generate:', 'affiliate-link-manager-ai').'</strong></p><ul style="margin:0 0 0 18px;list-style:disc;">';
                foreach ($drafts as $draft) {
                    if (!empty($draft['post_id'])) {
                        echo '<li><a href="'.esc_url(get_edit_post_link((int)$draft['post_id'], 'raw')).'">'.esc_html($draft['titolo']).'</a></li>';
                    } elseif (!empty($draft['error'])) {
                        echo '<li style="color:#996800;">'.esc_html(($draft['titolo'] !== '' ? $draft['titolo'].' — ' : '').$draft['error']).'</li>';
                    }
                }
                echo '</ul>';
            }
            if (!empty($last['summary'])) { echo '<pre style="white-space:pre-wrap;font-family:inherit;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;margin-top:8px;">'.esc_html($last['summary']).'</pre>'; }
            echo '</details>';
        }
        echo '</div>';
    }
}
