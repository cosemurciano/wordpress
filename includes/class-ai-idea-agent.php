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
    const OPTION_RUN_HISTORY = 'alma_ai_idea_agent_history';
    const HISTORY_MAX = 30;
    const OPTION_CANCEL = 'alma_ai_idea_agent_cancel';
    const OPTION_QUEUE = 'alma_ai_idea_agent_queue';   // piani accodati (si sommano)
    const QUEUE_MAX = 20;

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'), 10, 8);
        add_action('admin_post_alma_ai_idea_agent_start', array(__CLASS__, 'handle_start'));
        add_action('admin_post_alma_ai_idea_agent_stop', array(__CLASS__, 'handle_stop'));
        add_action('admin_post_alma_ai_idea_agent_settings', array(__CLASS__, 'handle_settings'));
        add_action('admin_post_alma_ai_idea_agent_cancel_queued', array(__CLASS__, 'handle_cancel_queued'));
    }

    /**
     * Data di inizio piano valida: oggi se assente, malformata o nel passato.
     */
    public static function sanitize_start_date($date) {
        $date = trim((string) $date);
        $today = current_time('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today) {
            return $today;
        }
        return $date;
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

    public static function get_run_history() {
        $history = get_option(self::OPTION_RUN_HISTORY, array());
        return is_array($history) ? $history : array();
    }

    /* ---------------------------------------------------------------------
     * Avvio (admin) → esecuzione in background
     * ------------------------------------------------------------------ */

    public static function handle_start() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_idea_agent_start');
        $notice = array('type' => 'success', 'message' => __('Agente ideazione avviato in background: il report comparirà nella Regia AI entro qualche minuto.', 'affiliate-link-manager-ai'));

        if (empty(get_option('alma_openai_api_key', ''))) {
            $notice = array('type' => 'error', 'message' => __('OpenAI non è configurata.', 'affiliate-link-manager-ai'));
        } else {
            $objective = sanitize_textarea_field(wp_unslash($_POST['agent_objective'] ?? ''));
            $num_ideas = max(0, min(10, absint($_POST['agent_num_ideas'] ?? 0)));
            $days_span = max(0, min(60, absint($_POST['agent_days'] ?? 0)));
            $start_date = self::sanitize_start_date(wp_unslash($_POST['agent_start_date'] ?? ''));
            // Spunta "Avvia subito la creazione": genera SUBITO le bozze di
            // tutte le idee del piano, ignorando la distribuzione sui giorni.
            $immediate = !empty($_POST['agent_immediate']) ? 1 : 0;
            // I piani si SOMMANO: se un'esecuzione è già in corso il nuovo
            // piano viene accodato e parte al termine di quello attuale
            // (nessun rifiuto). Nessun tetto se non la quantità del piano.
            $args = array(get_current_user_id(), $objective, 1, 1, $num_ideas, $days_span, $start_date, $immediate);
            $notice = self::enqueue_or_start_plan($args);
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-regia'));
        exit;
    }

    /**
     * Lock di esecuzione "vivo": presente E non scaduto (LOCK_TTL). Un run
     * ucciso dall'hosting (timeout/kill: il finally non gira) lasciava il
     * lock per sempre: ogni piano successivo veniva accodato senza che
     * nulla lo facesse mai partire e la regia risultava bloccata. Un lock
     * scaduto viene rimosso e trattato come libero.
     */
    public static function is_running() {
        $started = absint(get_option(self::LOCK_OPTION, 0));
        if ($started < 1) { return false; }
        if ((time() - $started) > self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);
            return false;
        }
        return true;
    }

    /**
     * Programma l'esecuzione del piano come evento cron singolo. Un token
     * univoco viene aggiunto come 9° argomento (il callback ne accetta 8 e
     * lo ignora) perché WP-Cron rifiuta in silenzio un evento identico
     * (stesso hook + stessi argomenti) entro 10 minuti: ripetere lo stesso
     * comando faceva perdere il piano con messaggio di successo.
     */
    private static function schedule_run($args, $delay) {
        $args = array_values((array) $args);
        $args[] = uniqid('plan_', true);
        $scheduled = wp_schedule_single_event(time() + max(1, (int) $delay), self::CRON_HOOK, $args);
        if (false === $scheduled || is_wp_error($scheduled)) { return false; }
        if (function_exists('spawn_cron')) { spawn_cron(); }
        return true;
    }

    /**
     * Avvia un piano subito se libero, altrimenti lo ACCODA (i piani si
     * sommano). Ritorna array('type','message','queued'=>bool,'position'=>int).
     */
    public static function enqueue_or_start_plan($args) {
        if (self::is_running()) {
            $queue = array_values((array) get_option(self::OPTION_QUEUE, array()));
            if (count($queue) >= self::QUEUE_MAX) {
                return array('type' => 'error', 'message' => sprintf(__('Coda piani piena (max %d in attesa): attendi che ne partano alcuni.', 'affiliate-link-manager-ai'), self::QUEUE_MAX), 'queued' => false, 'position' => 0);
            }
            $queue[] = array_values((array) $args);
            update_option(self::OPTION_QUEUE, $queue, false);
            return array('type' => 'success', 'message' => sprintf(__('Un piano è già in corso: questo è stato accodato (posizione %d). Partirà automaticamente al termine di quello attuale.', 'affiliate-link-manager-ai'), count($queue)), 'queued' => true, 'position' => count($queue));
        }
        delete_option(self::OPTION_CANCEL);
        if (!self::schedule_run($args, 5)) {
            return array('type' => 'error', 'message' => __('Impossibile programmare l\'esecuzione (evento cron rifiutato): riprova tra qualche istante.', 'affiliate-link-manager-ai'), 'queued' => false, 'position' => 0);
        }
        return array('type' => 'success', 'message' => __('Agente ideazione avviato in background: il report comparirà nella Regia AI entro qualche minuto.', 'affiliate-link-manager-ai'), 'queued' => false, 'position' => 0);
    }

    /**
     * Estrae e avvia il prossimo piano accodato (chiamato al termine di un
     * run). Nessun rischio di sovrapposizione: parte come nuovo evento cron,
     * il lock è già stato rilasciato.
     */
    /** Numero di piani editoriali attualmente in coda (accodati). */
    public static function queued_count() {
        return count((array) get_option(self::OPTION_QUEUE, array()));
    }

    /**
     * Piani in coda in forma leggibile per la Regia: obiettivo, quantità,
     * giorni, immediato. L'indice è la posizione reale nella coda (per
     * l'annullamento singolo).
     */
    public static function get_queue_summary() {
        $out = array();
        foreach (array_values((array) get_option(self::OPTION_QUEUE, array())) as $index => $args) {
            $args = array_values((array) $args);
            $out[] = array(
                'index' => (int) $index,
                'objective' => sanitize_textarea_field((string) ($args[1] ?? '')),
                'num_ideas' => absint($args[4] ?? 0),
                'days_span' => absint($args[5] ?? 0),
                'start_date' => sanitize_text_field((string) ($args[6] ?? '')),
                'immediate' => !empty($args[7]),
            );
        }
        return $out;
    }

    /**
     * Annulla UN piano in coda (senza toccare l'esecuzione in corso né gli
     * altri accodati) — dalla Regia AI.
     */
    public static function handle_cancel_queued() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_idea_agent_cancel_queued');
        $index = absint($_POST['queue_index'] ?? 0);
        $queue = array_values((array) get_option(self::OPTION_QUEUE, array()));
        if (isset($queue[$index])) {
            array_splice($queue, $index, 1);
            update_option(self::OPTION_QUEUE, $queue, false);
            $notice = array('type' => 'success', 'message' => __('Piano in coda annullato. Gli altri piani restano invariati.', 'affiliate-link-manager-ai'));
        } else {
            $notice = array('type' => 'error', 'message' => __('Piano non trovato in coda (forse è già partito).', 'affiliate-link-manager-ai'));
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-regia'));
        exit;
    }

    private static function start_next_queued_plan() {
        $queue = array_values((array) get_option(self::OPTION_QUEUE, array()));
        if (empty($queue)) { return; }
        $next = array_shift($queue);
        update_option(self::OPTION_QUEUE, $queue, false);
        if (!is_array($next) || empty($next)) { return; }
        delete_option(self::OPTION_CANCEL);
        if (!self::schedule_run($next, 10)) {
            // Evento rifiutato: il piano torna in testa alla coda invece di
            // andare perso (riproverà al termine del prossimo run).
            array_unshift($queue, $next);
            update_option(self::OPTION_QUEUE, $queue, false);
        }
    }

    /**
     * Ferma l'esecuzione in corso: il flag viene letto dal loop dell'agente
     * ai confini di ogni round e prima di ogni bozza (una chiamata OpenAI
     * già partita si conclude, poi il run si interrompe pulitamente).
     */
    public static function handle_stop() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_idea_agent_stop');
        // Lo stop ferma TUTTO: il piano in corso e quelli accodati.
        $queued = self::queued_count();
        delete_option(self::OPTION_QUEUE);
        $queue_note = $queued > 0 ? sprintf(__(' Svuotati anche %d piani in coda.', 'affiliate-link-manager-ai'), $queued) : '';
        if (self::is_running()) {
            update_option(self::OPTION_CANCEL, (string) time(), false);
            $notice = array('type' => 'success', 'message' => __('Richiesta di stop inviata: l\'agente si fermerà entro pochi secondi (al termine del passo in corso).', 'affiliate-link-manager-ai') . $queue_note);
        } else {
            $notice = array('type' => 'success', 'message' => __('Nessuna esecuzione in corso.', 'affiliate-link-manager-ai') . $queue_note);
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-regia'));
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

    public static function run($user_id = 0, $objective = '', $create_drafts = 0, $force = 0, $num_ideas = 0, $days_span = 0, $start_date = '', $immediate = 0) {
        if (!self::acquire_lock()) {
            // Un altro piano è partito nel frattempo (corsa tra eventi cron):
            // questo torna in coda invece di andare perso — verrà avviato
            // dal finally del run in corso.
            $queue = array_values((array) get_option(self::OPTION_QUEUE, array()));
            if (count($queue) < self::QUEUE_MAX) {
                $queue[] = array(absint($user_id), (string) $objective, (int) $create_drafts, (int) $force, (int) $num_ideas, (int) $days_span, (string) $start_date, (int) $immediate);
                update_option(self::OPTION_QUEUE, $queue, false);
            }
            return;
        }
        $started_at = current_time('mysql');
        $report = array(
            'started_at' => $started_at, 'finished_at' => '', 'rounds' => 0,
            'tool_calls' => array(), 'ideas_created' => array(), 'drafts_created' => array(),
            'cost_total' => 0.0, 'model' => '', 'summary' => '', 'error' => '',
            'objective' => sanitize_textarea_field((string) $objective), 'forced' => (int) (bool) $force,
        );
        try {
            update_option(self::OPTION_RUN_COUNTER, array('date' => current_time('Y-m-d'), 'count' => self::runs_today() + 1), false);

            $user_id = absint($user_id) ?: 1;
            wp_set_current_user($user_id);

            $max_ideas = $num_ideas > 0 ? max(1, min(10, absint($num_ideas))) : self::get_max_ideas();
            $days_span = $days_span > 0 ? max(1, min(60, absint($days_span))) : 14;
            $start_date = self::sanitize_start_date($start_date);
            $report['start_date'] = $start_date;
            $ideas_created = array();

            $input_items = array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => self::system_prompt($max_ideas, $days_span, $start_date)))),
                array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => self::user_prompt($objective, $num_ideas, $days_span, $start_date)))),
            );

            for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
                if (get_option(self::OPTION_CANCEL)) {
                    $report['error'] = 'Interrotto dall\'amministratore.';
                    break;
                }
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
                    $input_items[] = array('type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => ALMA_OpenAI_Service::encode_context($output));
                }
                if ($round === self::MAX_ROUNDS) {
                    $report['error'] = 'Limite di round del loop agente raggiunto senza risposta finale.';
                }
            }
            $report['ideas_created'] = $ideas_created;

            // Guardia deterministica sul piano: le date programmate devono
            // cadere nella finestra [inizio, inizio+giorni); le idee senza
            // data o fuori finestra vengono ridistribuite in sequenza.
            self::enforce_schedule($ideas_created, $start_date, $days_span);

            // Ogni idea crea la sua bozza secondo la programmazione: quelle
            // di oggi (o senza data futura) subito, le altre verranno
            // generate dal runner giornaliero nel giorno previsto.
            if (!empty($create_drafts) && !empty($ideas_created) && class_exists('ALMA_AI_Content_Agent_Idea_Importer')) {
                $today = current_time('Y-m-d');
                foreach ($ideas_created as $idea) {
                    if (get_option(self::OPTION_CANCEL)) {
                        $report['error'] = 'Interrotto dall\'amministratore (le bozze rimanenti verranno generate nei giorni programmati).';
                        break;
                    }
                    // Con "Avvia subito" (immediate) si generano TUTTE le bozze
                    // ora, ignorando la distribuzione sui giorni.
                    $scheduled = (string) get_post_meta((int)$idea['id'], ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, true);
                    if (empty($immediate) && $scheduled !== '' && $scheduled > $today) {
                        $report['drafts_created'][] = array('idea_id' => (int)$idea['id'], 'titolo' => $idea['titolo'], 'post_id' => 0, 'error' => '', 'programmata' => $scheduled);
                        continue;
                    }
                    $draft = ALMA_AI_Content_Agent_Idea_Importer::generate_draft_now((int)$idea['id']);
                    $report['drafts_created'][] = array(
                        'idea_id' => (int)$idea['id'],
                        'titolo' => $idea['titolo'],
                        'post_id' => (int)($draft['post_id'] ?? 0),
                        'error' => empty($draft['success']) ? sanitize_text_field((string)($draft['error'] ?? 'Errore generazione bozza')) : '',
                    );
                }
                // Bozze mancate (errori, interruzioni, cavallo di mezzanotte):
                // niente attese silenziose, il runner programmato riparte tra
                // pochi minuti e le ritenta; il motivo resta nel report.
                $has_failures = false;
                foreach ((array) $report['drafts_created'] as $draft_row) {
                    if (empty($draft_row['post_id']) && !empty($draft_row['error'])) { $has_failures = true; break; }
                }
                if ($has_failures) {
                    wp_schedule_single_event(time() + 300, ALMA_AI_Content_Agent_Idea_Importer::CRON_HOOK);
                    if (function_exists('spawn_cron')) { spawn_cron(); }
                }
            }
        } catch (Throwable $e) {
            $report['error'] = sanitize_text_field($e->getMessage());
            ALMA_Logger::error('Idea agent run error', array('error' => $e->getMessage()));
        } finally {
            $report['finished_at'] = current_time('mysql');
            update_option(self::OPTION_LAST_RUN, $report, false);
            // Storico compatto per la Regia AI (ultime 30 esecuzioni).
            $drafts_ok = 0;
            foreach ((array) $report['drafts_created'] as $draft) {
                if (!empty($draft['post_id'])) { $drafts_ok++; }
            }
            $history = self::get_run_history();
            array_unshift($history, array(
                'started_at' => $report['started_at'],
                'ideas' => count((array) $report['ideas_created']),
                'drafts' => $drafts_ok,
                'objective' => mb_substr((string) $report['objective'], 0, 120),
                'cost' => round((float) $report['cost_total'], 4),
                'error' => mb_substr((string) $report['error'], 0, 160),
                'forced' => (int) $report['forced'],
            ));
            update_option(self::OPTION_RUN_HISTORY, array_slice($history, 0, self::HISTORY_MAX), false);
            delete_option(self::OPTION_CANCEL);
            delete_option(self::LOCK_OPTION);
            // Regia Telegram: report di fine esecuzione alle chat autorizzate.
            if (class_exists('ALMA_Telegram_Bot')) {
                ALMA_Telegram_Bot::notify_agent_report($report);
            }
            // Piani accodati (che si sommano): avvia il prossimo, ora che il
            // lock è libero. Ogni piano resta indipendente e completo.
            self::start_next_queued_plan();
        }
    }

    /**
     * Le date programmate delle idee devono cadere nella finestra del piano
     * [inizio, inizio+giorni): l'AI potrebbe ignorare le istruzioni, quindi
     * la finestra viene imposta in modo deterministico dopo il loop. Le idee
     * senza data o fuori finestra ricevono date sequenziali dall'inizio
     * (max una per giorno finché la finestra lo consente).
     */
    private static function enforce_schedule($ideas_created, $start_date, $days_span) {
        $days_span = max(1, (int) $days_span);
        $end_date = gmdate('Y-m-d', strtotime($start_date) + ($days_span - 1) * DAY_IN_SECONDS);
        $fallback_offset = 0;
        foreach ((array) $ideas_created as $idea) {
            $idea_id = (int) ($idea['id'] ?? 0);
            if ($idea_id < 1) { continue; }
            $scheduled = (string) get_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, true);
            if ($scheduled !== '' && $scheduled >= $start_date && $scheduled <= $end_date) { continue; }
            $assigned = gmdate('Y-m-d', strtotime($start_date) + ($fallback_offset % $days_span) * DAY_IN_SECONDS);
            $fallback_offset++;
            update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SCHEDULED_AT, $assigned);
        }
    }

    private static function system_prompt($max_ideas, $days_span = 14, $start_date = '') {
        $prompt = 'Sei l\'agente strategico di ideazione contenuti di un blog di viaggi italiano monetizzato con link affiliati. '
            . 'Il tuo compito: analizzare i DATI REALI del sito tramite gli strumenti disponibili e creare fino a ' . (int)$max_ideas . ' nuove idee di articolo ad alto potenziale. '
            . 'Metodo obbligatorio: 1) analizza le performance, i gap geografici e — se disponibile — le ricerche reali con analizza_ricerche_google (le "opportunità" con impression alte e posizione debole sono il segnale più prezioso: domanda dimostrata senza contenuto adeguato); 2) per ogni opportunità verifica con cerca_link_affiliati che esistano link da monetizzare, con elenca_articoli_esistenti che il tema non sia già coperto (evita duplicati) e con cerca_media se la Media Library ha già immagini utilizzabili sul tema (se sì, segnalalo nel prompt dell\'idea); per le idee legate a una destinazione consulta scheda_localita e usa il clima reale per il taglio stagionale (es. proponi "quando andare" o contenuti per i mesi migliori in arrivo, citando i mesi consigliati nel prompt dell\'idea) e i fatti Wikidata (patrimonio UNESCO, attrazioni notevoli) per angoli accurati e non ancora coperti; per validare un tema usa tendenze_google (Italia, 5 anni): domanda in crescita e mesi di picco delle ricerche indicano COSA proporre e QUANDO pubblicare (prima del picco); 3) crea le idee con crea_idea, includendo località (se pertinente), un prompt editoriale ricco che citi le query target, e una data programmata (data_programmata) distribuita ' . ($start_date !== '' ? 'tra il ' . $start_date . ' e i successivi ' . (int)$days_span . ' giorni' : 'nei prossimi ' . (int)$days_span . ' giorni') . '. '
            . 'Privilegia: località con link affiliati ma senza click (offerta inutilizzata), località con molti articoli ma senza copertura pratica/commerciale, trend di click in crescita. '
            . 'Non inventare dati: basa ogni decisione sugli output degli strumenti. Non superare il numero massimo di idee. '
            . 'Alla fine rispondi in italiano con un riepilogo: per ogni idea creata, titolo e motivazione basata sui numeri.';
        $profiles_hint = self::instruction_profiles_hint();
        if ($profiles_hint !== '') {
            $prompt .= ' ' . $profiles_hint;
        }
        if (self::get_vector_store_id() !== '') {
            $prompt .= ' Hai inoltre accesso allo storage documenti dell\'editore su OpenAI tramite file_search: consultalo per linee guida editoriali, brief e materiali di contesto prima di decidere le idee.';
        }
        return $prompt;
    }

    /**
     * Profili istruzioni attivi da proporre all'agente: per ogni idea deve
     * scegliere STRATEGICAMENTE il profilo editoriale più adatto.
     */
    private static function instruction_profiles_hint() {
        if (!class_exists('ALMA_AI_Content_Agent_Instructions_Manager')) { return ''; }
        $profiles = ALMA_AI_Content_Agent_Instructions_Manager::get_active_profiles(15);
        if (empty($profiles)) { return ''; }
        $rows = array();
        foreach ($profiles as $profile) {
            $summary_parts = array_filter(array(
                trim(wp_strip_all_tags((string) ($profile['tone_of_voice'] ?? ''))),
                trim(wp_strip_all_tags((string) ($profile['target_audience'] ?? ''))),
            ));
            $summary = wp_trim_words(implode(' · ', $summary_parts), 20, '…');
            $rows[] = 'id ' . (int) $profile['id'] . ': "' . sanitize_text_field((string) $profile['profile_name']) . '"'
                . (!empty($profile['is_default']) ? ' (default)' : '')
                . ($summary !== '' ? ' — ' . $summary : '');
        }
        return 'PROFILI ISTRUZIONI DISPONIBILI (Istruzioni AI → Profili): ' . implode('; ', $rows) . '. '
            . 'Per OGNI idea scegli strategicamente il profilo più adatto al taglio dell\'articolo e passane l\'id in profilo_id a crea_idea: la bozza verrà scritta con quel tono, target e regole.';
    }

    private static function get_vector_store_id() {
        $id = trim((string) get_option('alma_openai_vector_store_id', ''));
        return preg_match('/^[A-Za-z0-9_\-]{1,120}$/', $id) ? $id : '';
    }

    private static function user_prompt($objective, $num_ideas = 0, $days_span = 0, $start_date = '') {
        $objective = trim((string)$objective);
        $base = 'Analizza i dati del sito e crea le idee di contenuto più promettenti.';
        if ($num_ideas > 0) {
            $days = max(1, (int)$days_span);
            $window = $start_date !== ''
                ? 'tra il ' . $start_date . ' e il ' . gmdate('Y-m-d', strtotime($start_date) + ($days - 1) * DAY_IN_SECONDS) . ' (incluse)'
                : 'nei prossimi ' . $days . ' giorni';
            // Nessun vincolo "una al giorno": la distribuzione finale viene
            // comunque imposta da enforce_schedule sul piano richiesto.
            $base = 'Piano editoriale richiesto dall\'editore: crea ESATTAMENTE ' . (int)$num_ideas . ' idee di contenuto, con date programmate (data_programmata) distribuite in modo sensato ' . $window . '.';
        }
        return $objective !== '' ? $base . ' Obiettivo e suggerimenti dell\'editore: ' . $objective : $base;
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
            array('type' => 'function', 'name' => 'scheda_localita', 'description' => 'Scheda di una località dell\'indice geografico del sito: CLIMA reale (mesi migliori per visitare, mesi da evitare, temperature e piogge mensili da Open-Meteo), FATTI verificati da Wikidata (descrizione, popolazione, patrimonio UNESCO, attrazioni notevoli entro 10 km, aeroporto più vicino con codice IATA), TENDENZE di ricerca da Google Trends (in quali mesi gli italiani la cercano, trend dell\'interesse, query correlate in crescita), TERRITORIO da OpenStreetMap (spiagge, punti panoramici, porti turistici, campeggi, riserve naturali, terme e sentieri entro 10 km: utile per articoli pratici e per scoprire cosa il sito non copre) più dati interni (link affiliati e articoli già esistenti sulla zona). Usala prima di proporre un\'idea su una destinazione per scegliere il taglio stagionale giusto e citare fatti accurati.', 'parameters' => array('type' => 'object', 'properties' => array('localita' => array('type' => 'string', 'description' => 'Nome della località (es. "Lisbona")')), 'required' => array('localita'), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'tendenze_google', 'description' => 'Tendenze di ricerca Google (Italia, ultimi 5 anni) per QUALSIASI termine o tema, non solo località: mesi di picco della domanda, interesse in crescita o calo, query correlate top e in crescita. Usalo per validare un tema d\'idea (la domanda esiste? sta crescendo? quando pubblicare?) o per scoprire angoli emergenti.', 'parameters' => array('type' => 'object', 'properties' => array('termine' => array('type' => 'string', 'description' => 'Termine o tema da analizzare (es. "cammino di Santiago")')), 'required' => array('termine'), 'additionalProperties' => false)),
            array('type' => 'function', 'name' => 'crea_idea', 'description' => 'Crea una nuova idea contenuto (visibile in Tutte le idee). Non pubblica nulla: la bozza verrà generata in seguito nel rispetto dei limiti giornalieri.', 'parameters' => array('type' => 'object', 'properties' => array(
                'titolo' => array('type' => 'string', 'description' => 'Titolo di lavoro dell\'articolo'),
                'localita' => array('type' => 'string', 'description' => 'Nome della località (opzionale, verrà risolto sull\'indice geografico)'),
                'prompt' => array('type' => 'string', 'description' => 'Istruzioni editoriali per la bozza: taglio, cosa includere, perché l\'idea è promettente'),
                'keywords' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Keyword SEO (max 5)'),
                'data_programmata' => array('type' => 'string', 'description' => 'Data generazione bozza YYYY-MM-DD (opzionale)'),
                'profilo_id' => array('type' => 'integer', 'description' => 'ID del profilo istruzioni più adatto (dall\'elenco PROFILI ISTRUZIONI DISPONIBILI): la bozza verrà scritta con quel tono e quelle regole'),
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
            case 'scheda_localita':
                return class_exists('ALMA_Geo_Facts') ? ALMA_Geo_Facts::agent_payload(sanitize_text_field((string)($arguments['localita'] ?? ''))) : array('error' => 'Schede località non disponibili.');
            case 'tendenze_google':
                return class_exists('ALMA_Google_Trends') ? ALMA_Google_Trends::agent_payload(sanitize_text_field((string)($arguments['termine'] ?? ''))) : array('error' => 'Google Trends non disponibile.');
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
        $usage = class_exists('ALMA_AI_Content_Agent_Result_Usage')
            ? ALMA_AI_Content_Agent_Result_Usage::get_counts_by_source(wp_list_pluck($rows, 'source_id'))
            : array();
        $out = array();
        foreach ($rows as $row) {
            $sid = (int)$row['source_id'];
            $out[] = array('id' => $sid, 'titolo' => $row['title'], 'tipologie' => $row['link_types'], 'score' => (int)$row['score'], 'usato_in_articoli' => (int)($usage[$sid] ?? 0), 'motivo' => $row['reason']);
        }
        return array(
            'localita_risolta' => $location_label,
            'link_trovati' => count($out),
            'suggerimento_varieta' => __('A parità di pertinenza preferisci i link con "usato_in_articoli" più basso: usa progressivamente tutti i link disponibili per variare l\'offerta, non sempre i soliti.', 'affiliate-link-manager-ai'),
            'link' => $out,
        );
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

        // Profilo istruzioni scelto strategicamente dall'agente: validato
        // contro i profili attivi; 0 = istruzioni globali.
        $profile_id = absint($arguments['profilo_id'] ?? 0);
        if ($profile_id > 0) {
            $profile = class_exists('ALMA_AI_Content_Agent_Instructions_Manager') ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id) : array();
            if (empty($profile) || empty($profile['is_active'])) { $profile_id = 0; }
        }

        $idea_id = ALMA_AI_Content_Agent_Ideas::create($title, $profile_id);
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
     * UI
     * ------------------------------------------------------------------ */

    /**
     * Card compatta in Tutte le idee: la gestione completa dell'agente
     * vive nella pagina Regia AI (v2.70.0).
     */
    public static function render_panel() {
        if (!current_user_can('manage_options')) { return; }
        $running = self::is_running();
        $last = self::get_last_run();
        echo '<div class="alma-ideas-card" style="border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:12px 16px;margin:12px 0;display:flex;gap:14px;align-items:center;flex-wrap:wrap;">';
        echo '<span style="font-size:1.05em;"><strong>🤖 Agente ideazione AI</strong></span>';
        if ($running) {
            echo '<span class="description">' . esc_html__('Esecuzione in corso…', 'affiliate-link-manager-ai') . '</span>';
        } elseif ($last) {
            echo '<span class="description">' . esc_html(sprintf(__('Ultima esecuzione: %1$s — %2$d idee create.', 'affiliate-link-manager-ai'), (string)($last['started_at'] ?? ''), count((array)($last['ideas_created'] ?? array())))) . '</span>';
        }
        echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=affiliate_link&page=alma-ai-regia')) . '">' . esc_html__('Apri la Regia AI', 'affiliate-link-manager-ai') . '</a>';
        echo '</div>';
    }

    /**
     * Report dettagliato dell'ultima esecuzione (usato dalla Regia AI).
     */
    public static function render_last_run_details() {
        $last = self::get_last_run();
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
                    } elseif (!empty($draft['programmata'])) {
                        echo '<li>'.esc_html($draft['titolo']).' — 📅 '.esc_html(sprintf(__('bozza in programma il %s', 'affiliate-link-manager-ai'), (string)$draft['programmata'])).'</li>';
                    } elseif (!empty($draft['error'])) {
                        echo '<li style="color:#996800;">'.esc_html(($draft['titolo'] !== '' ? $draft['titolo'].' — ' : '').$draft['error']).'</li>';
                    }
                }
                echo '</ul>';
            }
            if (!empty($last['summary'])) { echo '<pre style="white-space:pre-wrap;font-family:inherit;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;margin-top:8px;">'.esc_html($last['summary']).'</pre>'; }
            echo '</details>';
        } else {
            echo '<p class="description">' . esc_html__('Nessuna esecuzione registrata: avvia il primo piano qui sopra.', 'affiliate-link-manager-ai') . '</p>';
        }
    }
}
