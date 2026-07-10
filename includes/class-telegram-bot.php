<?php
/**
 * Fase 5 — Bot Telegram: regia, monitoraggio e strategia.
 *
 * - Webhook REST (alma/v1/telegram) protetto dal secret di Telegram
 *   (header X-Telegram-Bot-Api-Secret-Token) e da whitelist di chat.
 * - Comandi: /id (scopri la chat ID), /help, /agente <obiettivo> (avvia
 *   l'agente di ideazione), /report (ultima esecuzione agente), /bozze
 *   (ultime bozze AI con pulsanti Pubblica/Cestina), /top (click e gap
 *   dallo snapshot Dashboard), /consigli (ultimi consigli strategici AI).
 * - Notifiche push: bozza creata (con pulsanti di revisione) e report di
 *   fine esecuzione dell'agente.
 * - Configurazione via costanti in wp-config.php (mai nel database):
 *   define('ALMA_TELEGRAM_BOT_TOKEN', '123456:ABC...');
 *   define('ALMA_TELEGRAM_SECRET', 'una-stringa-casuale-lunga');
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Telegram_Bot {
    const OPTION_ENABLED = 'alma_telegram_enabled';
    const OPTION_CHAT_IDS = 'alma_telegram_chat_ids';
    const OPTION_RECENT_CHATS = 'alma_telegram_recent_chats';
    const OPTION_NOTIFY_DRAFTS = 'alma_telegram_notify_drafts';
    const OPTION_NOTIFY_PUBLISH = 'alma_telegram_notify_publish';
    const META_PUBLISH_NOTIFIED = '_alma_telegram_publish_notified';
    const REST_NAMESPACE = 'alma/v1';
    const REST_ROUTE = '/telegram';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));
        add_action('admin_post_alma_telegram_register_webhook', array(__CLASS__, 'handle_register_webhook'));
        add_action('admin_post_alma_telegram_check_webhook', array(__CLASS__, 'handle_check_webhook'));
        add_action('admin_post_alma_telegram_send_instructions', array(__CLASS__, 'handle_send_instructions'));
        add_action('admin_post_alma_telegram_add_chat', array(__CLASS__, 'handle_add_chat'));
        // Notifica di OGNI articolo pubblicato (manuale o AI) con link.
        add_action('transition_post_status', array(__CLASS__, 'notify_post_published'), 10, 3);
    }

    public static function get_token() {
        return defined('ALMA_TELEGRAM_BOT_TOKEN') ? trim((string) ALMA_TELEGRAM_BOT_TOKEN) : '';
    }

    public static function get_secret() {
        return defined('ALMA_TELEGRAM_SECRET') ? trim((string) ALMA_TELEGRAM_SECRET) : '';
    }

    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, '0') === '1' && self::get_token() !== '' && self::get_secret() !== '';
    }

    public static function webhook_url() {
        return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
    }

    public static function get_chat_ids() {
        return array_values(array_filter(array_map(function ($id) {
            return preg_match('/^-?\d{1,20}$/', (string) $id) ? (string) $id : '';
        }, (array) get_option(self::OPTION_CHAT_IDS, array()))));
    }

    private static function is_authorized_chat($chat_id) {
        return in_array((string) $chat_id, self::get_chat_ids(), true);
    }

    /* ---------------------------------------------------------------------
     * API Telegram
     * ------------------------------------------------------------------ */

    private static function api_request($method, $params = array()) {
        $token = self::get_token();
        if ($token === '') { return array('ok' => false, 'description' => 'Token non definito'); }
        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/' . $method, array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($params),
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'description' => $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : array('ok' => false, 'description' => 'Risposta non valida');
    }

    public static function send_message($chat_id, $text, $keyboard = null) {
        $params = array('chat_id' => (string) $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true);
        if (is_array($keyboard)) { $params['reply_markup'] = array('inline_keyboard' => $keyboard); }
        return self::api_request('sendMessage', $params);
    }

    private static function broadcast($text, $keyboard = null) {
        foreach (self::get_chat_ids() as $chat_id) {
            self::send_message($chat_id, $text, $keyboard);
        }
    }

    /** Digest e messaggi di servizio verso tutte le chat autorizzate. */
    public static function send_message_to_all($text, $keyboard = null) {
        if (!self::is_enabled()) { return; }
        self::broadcast($text, $keyboard);
    }

    private static function esc($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /* ---------------------------------------------------------------------
     * Webhook
     * ------------------------------------------------------------------ */

    public static function register_rest_route() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            // L'autorizzazione è il secret di Telegram verificato nel callback:
            // il webhook deve restare raggiungibile senza cookie WordPress.
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_webhook($request) {
        if (!self::is_enabled()) {
            return new WP_REST_Response(array('ok' => false), 403);
        }
        $secret_header = (string) $request->get_header('x_telegram_bot_api_secret_token');
        if ($secret_header === '' || !hash_equals(self::get_secret(), $secret_header)) {
            return new WP_REST_Response(array('ok' => false), 403);
        }
        $update = $request->get_json_params();
        if (!is_array($update)) {
            return new WP_REST_Response(array('ok' => true), 200);
        }
        try {
            if (!empty($update['callback_query'])) {
                self::handle_callback_query((array) $update['callback_query']);
            } elseif (!empty($update['message'])) {
                self::handle_message((array) $update['message']);
            }
        } catch (Throwable $e) {
            ALMA_Logger::warning('Telegram webhook error', array('error' => $e->getMessage()));
        }
        return new WP_REST_Response(array('ok' => true), 200);
    }

    private static function remember_chat($chat) {
        $chat_id = (string) ($chat['id'] ?? '');
        if ($chat_id === '') { return; }
        $recent = (array) get_option(self::OPTION_RECENT_CHATS, array());
        $name = trim(sanitize_text_field((string)($chat['title'] ?? '')) ?: trim(sanitize_text_field((string)($chat['first_name'] ?? '')) . ' ' . sanitize_text_field((string)($chat['last_name'] ?? ''))));
        $recent[$chat_id] = array('name' => $name !== '' ? $name : ($chat['username'] ?? ''), 'last_seen' => current_time('mysql'));
        // Solo le ultime 10 chat viste.
        if (count($recent) > 10) {
            uasort($recent, function ($a, $b) { return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? '')); });
            $recent = array_slice($recent, 0, 10, true);
        }
        update_option(self::OPTION_RECENT_CHATS, $recent, false);
    }

    private static function handle_message($message) {
        $chat = (array) ($message['chat'] ?? array());
        $chat_id = (string) ($chat['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($chat_id === '' || $text === '') { return; }
        self::remember_chat($chat);

        $command = strtolower(strtok($text, ' @'));
        $argument = trim((string) substr($text, strlen((string) strtok($text, ' '))));

        // /id e /start sono aperti a tutti: servono a scoprire la chat ID.
        if ($command === '/id' || $command === '/start') {
            self::send_message($chat_id, 'La tua Chat ID è: <code>' . self::esc($chat_id) . '</code>' . "\n" . 'Aggiungila alle chat autorizzate in Impostazioni AI Content → Telegram.');
            return;
        }
        if (!self::is_authorized_chat($chat_id)) {
            self::send_message($chat_id, 'Chat non autorizzata. Usa /id e aggiungi questa Chat ID nelle impostazioni del plugin.');
            return;
        }

        switch ($command) {
            case '/help':
                self::send_message($chat_id, self::instructions_text());
                break;
            case '/agente':
            case '/idea':
                self::command_agent($chat_id, $argument);
                break;
            case '/piano':
                self::command_plan($chat_id, $argument);
                break;
            case '/stato':
                self::command_status($chat_id);
                break;
            case '/stop':
                self::command_stop($chat_id);
                break;
            case '/report':
                self::command_report($chat_id);
                break;
            case '/bozze':
                self::command_drafts($chat_id);
                break;
            case '/top':
                self::command_top($chat_id);
                break;
            case '/consigli':
                self::command_advice($chat_id);
                break;
            case '/salute':
                self::command_health($chat_id);
                break;
            case '/immagini':
                self::command_images($chat_id);
                break;
            default:
                self::send_message($chat_id, 'Comando non riconosciuto. ' . "\n" . self::instructions_text());
        }
    }

    public static function instructions_text() {
        return "<b>🎬 Regia AI</b>\n"
            . "/agente &lt;tema&gt; — avvia subito l'agente: crea idee E bozze sul tema (opzionale); parte anche oltre il limite giornaliero\n"
            . "/piano &lt;articoli&gt; &lt;giorni&gt; &lt;tema&gt; — programma un piano editoriale, es. <code>/piano 5 10 borghi siciliani</code> (come «Applica il piano» in Regia AI)\n"
            . "/stato — l'agente è in esecuzione? bozze programmate, immagini AI, salute link\n"
            . "/stop — ferma l'esecuzione dell'agente in corso\n"
            . "/report — esito dell'ultima esecuzione\n"
            . "\n<b>📝 Contenuti</b>\n"
            . "/bozze — ultime bozze AI con pulsanti Pubblica/Cestina\n"
            . "<i>In automatico ricevi: nuove bozze AI (con pulsanti) e ogni articolo pubblicato (con link).</i>\n"
            . "\n<b>📊 Strategia</b>\n"
            . "/top — click e gap geografici\n"
            . "/consigli — ultimi consigli strategici AI\n"
            . "\n<b>🛠 Manutenzione</b>\n"
            . "/salute — stato dei link affiliati (morti/sospetti)\n"
            . "/immagini — immagini AI: generate oggi, coda, ultime create\n"
            . "/id — mostra la tua Chat ID\n"
            . "/help — questo elenco";
    }

    /**
     * Elenco comandi per il menu nativo di Telegram (setMyCommands).
     */
    public static function bot_commands() {
        return array(
            array('command' => 'agente', 'description' => 'Avvia l\'agente AI (tema opzionale)'),
            array('command' => 'piano', 'description' => 'Piano editoriale: articoli giorni tema'),
            array('command' => 'stato', 'description' => 'Stato agente, bozze, immagini, link'),
            array('command' => 'stop', 'description' => 'Ferma l\'agente in esecuzione'),
            array('command' => 'report', 'description' => 'Report ultima esecuzione'),
            array('command' => 'bozze', 'description' => 'Bozze AI con Pubblica/Cestina'),
            array('command' => 'top', 'description' => 'Click e gap geografici'),
            array('command' => 'consigli', 'description' => 'Consigli strategici AI'),
            array('command' => 'salute', 'description' => 'Salute dei link affiliati'),
            array('command' => 'immagini', 'description' => 'Stato immagini AI'),
            array('command' => 'id', 'description' => 'Mostra la tua Chat ID'),
            array('command' => 'help', 'description' => 'Elenco comandi'),
        );
    }

    /* ---------------------------------------------------------------------
     * Comandi
     * ------------------------------------------------------------------ */

    private static function command_agent($chat_id, $objective, $num_ideas = 0, $days_span = 0) {
        if (!class_exists('ALMA_AI_Idea_Agent')) {
            self::send_message($chat_id, 'Agente non disponibile.');
            return;
        }
        if (empty(get_option('alma_openai_api_key', ''))) {
            self::send_message($chat_id, '❌ OpenAI non è configurata nel plugin.');
            return;
        }
        // I piani si sommano: se uno è in corso, questo viene accodato
        // (nessun rifiuto), coerente con la pagina Regia AI.
        $num_ideas = max(0, min(10, absint($num_ideas)));
        $days_span = max(0, min(60, absint($days_span)));
        // Autore delle idee: il primo amministratore.
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        $user_id = !empty($admins) ? (int) $admins[0] : 1;
        // force=1 (oltre il limite giornaliero); immediate=0 (bozze secondo la
        // programmazione, come default della Regia).
        $result = ALMA_AI_Idea_Agent::enqueue_or_start_plan(array($user_id, sanitize_textarea_field($objective), 1, 1, $num_ideas, $days_span, '', 0));
        if (($result['type'] ?? '') === 'error') {
            self::send_message($chat_id, '⚠️ ' . self::esc((string) ($result['message'] ?? 'Impossibile avviare il piano.')));
            return;
        }
        $plan_note = $num_ideas > 0 ? sprintf("\n📋 Piano: %d articoli in %d giorni a partire da oggi.", $num_ideas, max(1, $days_span)) : '';
        if (!empty($result['queued'])) {
            self::send_message($chat_id, '🕓 Un piano è già in corso: questo è stato <b>accodato</b> (posizione ' . (int) ($result['position'] ?? 1) . ').'
                . $plan_note
                . "\nPartirà automaticamente al termine di quello attuale. Riceverai il report al termine.");
            return;
        }
        self::send_message($chat_id, '🤖 Agente di ideazione avviato' . ($objective !== '' ? ' sul tema: <i>' . self::esc($objective) . '</i>' : '')
            . $plan_note
            . "\nOgni idea creerà la sua bozza nel giorno programmato."
            . "\nRiceverai qui il report al termine (2-5 minuti). Usa /stato per seguirlo o /stop per fermarlo.");
    }

    /**
     * /piano <articoli> <giorni> <tema…> — come «Applica il piano» in Regia AI.
     */
    private static function command_plan($chat_id, $argument) {
        if (!preg_match('/^(\d{1,2})\s+(\d{1,2})\s*(.*)$/s', trim($argument), $m)) {
            self::send_message($chat_id, "Formato: <code>/piano articoli giorni tema</code>\nEs. <code>/piano 5 10 borghi siciliani</code> = 5 articoli in 10 giorni sul tema indicato (tema opzionale).");
            return;
        }
        self::command_agent($chat_id, trim((string) $m[3]), absint($m[1]), absint($m[2]));
    }

    /**
     * /stato — fotografia operativa: agente, bozze programmate, immagini, link.
     */
    private static function command_status($chat_id) {
        $running = class_exists('ALMA_AI_Idea_Agent') && get_option(ALMA_AI_Idea_Agent::LOCK_OPTION);
        $stopping = class_exists('ALMA_AI_Idea_Agent') && get_option(ALMA_AI_Idea_Agent::OPTION_CANCEL);
        $text = "<b>📡 Stato operativo</b>\n";
        $text .= '🤖 Agente: ' . ($running ? ($stopping ? '<b>in arresto…</b>' : '<b>in esecuzione</b> (usa /stop per fermarlo)') : 'fermo') . "\n";
        if (class_exists('ALMA_AI_Content_Agent_Idea_Importer') && method_exists('ALMA_AI_Content_Agent_Idea_Importer', 'due_ideas_count')) {
            $text .= '📋 Bozze programmate in attesa: <b>' . (int) ALMA_AI_Content_Agent_Idea_Importer::due_ideas_count() . "</b>\n";
        }
        if (class_exists('ALMA_AI_Image_Generator')) {
            $queue = count((array) get_option(ALMA_AI_Image_Generator::OPTION_PRIORITY_QUEUE, array()));
            $text .= '🖼 Immagini AI: oggi ' . (int) ALMA_AI_Image_Generator::generated_today() . '/' . (int) ALMA_AI_Image_Generator::get_daily_limit()
                . ' · link senza immagine: ' . (int) ALMA_AI_Image_Generator::pending_count()
                . ($queue > 0 ? ' · in coda prioritaria: ' . $queue : '') . "\n";
        }
        if (class_exists('ALMA_Link_Health_Checker')) {
            $counts = ALMA_Link_Health_Checker::status_counts();
            $text .= '🩺 Link: ✅ ' . (int) ($counts['ok'] ?? 0) . ' · ⚠️ sospetti ' . (int) ($counts['suspect'] ?? 0) . ' · ❌ morti ' . (int) ($counts['dead'] ?? 0) . "\n";
        }
        if (class_exists('ALMA_AI_Post_Enricher')) {
            $text .= '🔗 Arricchimento articoli: oggi ' . (int) ALMA_AI_Post_Enricher::processed_today() . '/' . (int) ALMA_AI_Post_Enricher::get_daily_limit() . ' (' . (ALMA_AI_Post_Enricher::is_enabled() ? 'attivo' : 'spento') . ")\n";
        }
        $text .= "\n/report per l'ultima esecuzione · /bozze per revisionare";
        self::send_message($chat_id, $text);
    }

    /**
     * /stop — richiede l'arresto dell'agente (come il pulsante in Regia AI).
     */
    private static function command_stop($chat_id) {
        if (!class_exists('ALMA_AI_Idea_Agent')) {
            self::send_message($chat_id, 'Agente non disponibile.');
            return;
        }
        if (!get_option(ALMA_AI_Idea_Agent::LOCK_OPTION)) {
            self::send_message($chat_id, 'ℹ️ Nessuna esecuzione dell\'agente in corso.');
            return;
        }
        update_option(ALMA_AI_Idea_Agent::OPTION_CANCEL, (string) time(), false);
        self::send_message($chat_id, '🛑 Arresto richiesto: l\'agente si fermerà al prossimo punto sicuro (le idee già create restano).');
    }

    /**
     * /salute — sintesi Link Health.
     */
    private static function command_health($chat_id) {
        if (!class_exists('ALMA_Link_Health_Checker')) {
            self::send_message($chat_id, 'Link Health non disponibile.');
            return;
        }
        $counts = ALMA_Link_Health_Checker::status_counts();
        $report = (array) get_option(ALMA_Link_Health_Checker::OPTION_LAST_REPORT, array());
        $text = "<b>🩺 Salute dei link affiliati</b>\n";
        $text .= '✅ OK: <b>' . (int) ($counts['ok'] ?? 0) . '</b> · ⚠️ Sospetti: <b>' . (int) ($counts['suspect'] ?? 0) . '</b> · ❌ Morti: <b>' . (int) ($counts['dead'] ?? 0) . '</b> · ⏳ Mai verificati: <b>' . (int) ($counts['mai-verificato'] ?? 0) . "</b>\n";
        if (!empty($report['finished_at'])) {
            $text .= 'Ultima verifica: ' . self::esc((string) $report['finished_at']) . "\n";
        }
        $text .= "\nI link morti sono esclusi da widget e nuove bozze. Dettagli e bonifica: Link Affiliati → Verifica link.";
        self::send_message($chat_id, $text);
    }

    /**
     * /immagini — stato del generatore di immagini AI.
     */
    private static function command_images($chat_id) {
        if (!class_exists('ALMA_AI_Image_Generator')) {
            self::send_message($chat_id, 'Generatore immagini non disponibile.');
            return;
        }
        $queue = count((array) get_option(ALMA_AI_Image_Generator::OPTION_PRIORITY_QUEUE, array()));
        $log = (array) get_option(ALMA_AI_Image_Generator::OPTION_LOG, array());
        $text = "<b>🖼 Immagini AI</b>\n";
        $text .= 'Notturno: ' . (ALMA_AI_Image_Generator::is_enabled() ? 'attivo' : 'spento') . ' · oggi ' . (int) ALMA_AI_Image_Generator::generated_today() . '/' . (int) ALMA_AI_Image_Generator::get_daily_limit() . "\n";
        $text .= 'Link pubblicati senza immagine: <b>' . (int) ALMA_AI_Image_Generator::pending_count() . '</b>' . ($queue > 0 ? ' · coda prioritaria: <b>' . $queue . '</b>' : '') . "\n";
        $recent = array_slice(array_filter($log, function ($e) { return is_array($e) && ($e['status'] ?? '') === 'generated'; }), 0, 3);
        if (!empty($recent)) {
            $text .= "\n<b>Ultime generate</b>\n";
            foreach ($recent as $entry) {
                $text .= '• ' . self::esc((string) ($entry['title'] ?? '')) . (isset($entry['cost']) && $entry['cost'] !== null ? ' — $' . number_format((float) $entry['cost'], 4) : '') . "\n";
            }
        }
        self::send_message($chat_id, $text);
    }

    private static function command_report($chat_id) {
        $report = class_exists('ALMA_AI_Idea_Agent') ? ALMA_AI_Idea_Agent::get_last_run() : null;
        if (!$report) {
            self::send_message($chat_id, 'Nessuna esecuzione dell\'agente registrata.');
            return;
        }
        self::send_message($chat_id, self::format_agent_report($report));
    }

    public static function format_agent_report($report) {
        $ideas = (array) ($report['ideas_created'] ?? array());
        $drafts = array_filter((array) ($report['drafts_created'] ?? array()), function ($d) { return !empty($d['post_id']); });
        $auto_publish = get_option('alma_ai_auto_publish', 'no') === 'yes';
        $text = "<b>🤖 Report agente ideazione</b>\n";
        $text .= 'Avviato: ' . self::esc($report['started_at'] ?? '') . "\n";
        $text .= 'Idee create: <b>' . count($ideas) . '</b> · Bozze: <b>' . count($drafts) . '</b> · Costo stimato: ~$' . number_format((float) ($report['cost_total'] ?? 0), 4) . "\n";
        if ($auto_publish) {
            // Pubblicazione automatica attiva: niente elenco idee (ridondante),
            // per ogni articolo pubblicato titolo + link + modifica.
            foreach (array_slice($drafts, 0, 10) as $draft) {
                $post_id = (int) $draft['post_id'];
                $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
                if (get_post_status($post_id) === 'publish') {
                    $text .= '📣 <a href="' . esc_url(get_permalink($post_id)) . '">' . self::esc($draft['titolo']) . '</a> · <a href="' . esc_url($edit_url) . '">✏️ Modifica</a>' . "\n";
                } else {
                    $preview = get_preview_post_link($post_id);
                    $text .= '📝 <a href="' . esc_url($preview ?: $edit_url) . '">' . self::esc($draft['titolo']) . '</a> · <a href="' . esc_url($edit_url) . '">✏️ Modifica</a>' . "\n";
                }
            }
        } else {
            foreach (array_slice($ideas, 0, 8) as $idea) {
                $text .= '• ' . self::esc($idea['titolo']) . (!empty($idea['localita']) ? ' — 📍 ' . self::esc($idea['localita']) : '') . "\n";
            }
            foreach (array_slice($drafts, 0, 5) as $draft) {
                $preview = get_preview_post_link((int) $draft['post_id']);
                $text .= '📝 <a href="' . esc_url($preview) . '">' . self::esc($draft['titolo']) . "</a>\n";
            }
        }
        // Il PERCHÉ delle bozze mancate, sempre visibile: programmate per un
        // altro giorno o fallite (queste ultime vengono ritentate in automatico).
        foreach ((array) ($report['drafts_created'] ?? array()) as $draft_row) {
            if (!empty($draft_row['post_id'])) { continue; }
            if (!empty($draft_row['programmata'])) {
                $text .= '📅 ' . self::esc((string) $draft_row['titolo']) . ' — bozza in programma il ' . self::esc((string) $draft_row['programmata']) . "\n";
            } elseif (!empty($draft_row['error'])) {
                $text .= '⚠️ Bozza NON creata per "' . self::esc((string) $draft_row['titolo']) . '": ' . self::esc((string) $draft_row['error']) . ' — verrà ritentata automaticamente entro pochi minuti (max 3 tentativi).' . "\n";
            }
        }
        if (!empty($report['error'])) { $text .= '⚠️ ' . self::esc($report['error']) . "\n"; }
        if (!empty($report['summary'])) { $text .= "\n" . self::esc(wp_trim_words($report['summary'], 80, '…')); }
        return $text;
    }

    private static function command_drafts($chat_id) {
        $drafts = ALMA_AI_Content_Agent_Store::get_agent_drafts(5);
        if (empty($drafts)) {
            self::send_message($chat_id, 'Nessuna bozza AI trovata.');
            return;
        }
        foreach ($drafts as $draft) {
            self::send_draft_card($chat_id, $draft->ID);
        }
    }

    private static function send_draft_card($chat_id, $post_id) {
        $post = get_post($post_id);
        if (!$post) { return; }
        $status = get_post_status_object($post->post_status);
        $text = '📝 <b>' . self::esc(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8')) . "</b>\n"
            . 'Stato: ' . self::esc($status ? $status->label : $post->post_status) . ' · ' . self::esc(get_the_date('Y-m-d H:i', $post)) . "\n"
            . self::esc(wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 30, '…'));
        $keyboard = array();
        $row = array();
        if ($post->post_status !== 'publish') {
            $row[] = array('text' => '✅ Pubblica', 'callback_data' => 'pub:' . (int) $post->ID);
        } else {
            $keyboard[] = array(array('text' => '🔗 Apri articolo', 'url' => get_permalink($post)));
        }
        $row[] = array('text' => '🗑 Cestina', 'callback_data' => 'del:' . (int) $post->ID);
        $keyboard[] = $row;
        $preview = get_preview_post_link($post);
        if ($preview && $post->post_status !== 'publish') {
            $keyboard[] = array(array('text' => '👁 Anteprima', 'url' => $preview));
        }
        self::send_message($chat_id, $text, $keyboard);
    }

    private static function command_top($chat_id) {
        $snapshot = class_exists('ALMA_Dashboard_Insights') ? ALMA_Dashboard_Insights::get_snapshot() : null;
        if (!$snapshot) {
            self::send_message($chat_id, 'Snapshot statistiche non disponibile: apri la Dashboard del plugin e premi "Aggiorna ora".');
            return;
        }
        $periods = (array) ($snapshot['periods'] ?? array());
        $p7 = (array) ($periods['7'] ?? array());
        $p30 = (array) ($periods['30'] ?? array());
        $text = "<b>📊 Report click affiliati</b>\n";
        $text .= '7 giorni: <b>' . (int) ($p7['clicks'] ?? 0) . '</b>' . (isset($p7['delta_pct']) && $p7['delta_pct'] !== null ? ' (' . ($p7['delta_pct'] >= 0 ? '+' : '') . $p7['delta_pct'] . '%)' : '') . "\n";
        $text .= '30 giorni: <b>' . (int) ($p30['clicks'] ?? 0) . '</b>' . (isset($p30['delta_pct']) && $p30['delta_pct'] !== null ? ' (' . ($p30['delta_pct'] >= 0 ? '+' : '') . $p30['delta_pct'] . '%)' : '') . "\n";
        $text .= "\n<b>Top link (30gg)</b>\n";
        foreach (array_slice((array) ($snapshot['top_links'] ?? array()), 0, 5) as $link) {
            $text .= '• ' . self::esc($link['title']) . ' — ' . (int) $link['clicks_period'] . " click\n";
        }
        $geo_unused = array_slice((array) ($snapshot['geo_unused'] ?? array()), 0, 3);
        if (!empty($geo_unused)) {
            $text .= "\n<b>Gap: link senza click (90gg)</b>\n";
            foreach ($geo_unused as $geo) {
                $text .= '• 📍 ' . self::esc($geo['name']) . ' — ' . (int) $geo['links'] . " link fermi\n";
            }
        }
        $text .= "\nUsa /consigli per i suggerimenti AI o /agente per trasformare i gap in idee.";
        self::send_message($chat_id, $text);
    }

    private static function command_advice($chat_id) {
        $advice = class_exists('ALMA_Dashboard_Insights') ? ALMA_Dashboard_Insights::get_advice() : null;
        if (!$advice || empty($advice['text'])) {
            self::send_message($chat_id, 'Nessun consiglio AI salvato: generane uno dalla Dashboard del plugin ("Genera consigli AI").');
            return;
        }
        $text = "<b>💡 Consigli strategici AI</b> (" . self::esc($advice['generated_at'] ?? '') . ")\n" . self::esc(wp_trim_words($advice['text'], 300, '…'));
        self::send_message($chat_id, $text);
    }

    /* ---------------------------------------------------------------------
     * Callback (pulsanti Pubblica / Cestina)
     * ------------------------------------------------------------------ */

    private static function handle_callback_query($callback) {
        $callback_id = (string) ($callback['id'] ?? '');
        $chat = (array) ($callback['message']['chat'] ?? array());
        $chat_id = (string) ($chat['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        if (!self::is_authorized_chat($chat_id)) {
            self::api_request('answerCallbackQuery', array('callback_query_id' => $callback_id, 'text' => 'Chat non autorizzata'));
            return;
        }
        if (!preg_match('/^(pub|del):(\d+)$/', $data, $m)) {
            self::api_request('answerCallbackQuery', array('callback_query_id' => $callback_id));
            return;
        }
        $action = $m[1];
        $post_id = (int) $m[2];
        $post = get_post($post_id);
        // Solo contenuti generati dall'agente: mai altri post del sito.
        if (!$post || $post->post_type !== 'post' || get_post_meta($post_id, '_alma_ai_agent_generated', true) !== '1') {
            self::api_request('answerCallbackQuery', array('callback_query_id' => $callback_id, 'text' => 'Post non gestibile da Telegram'));
            return;
        }
        if ($action === 'pub') {
            wp_publish_post($post_id);
            self::api_request('answerCallbackQuery', array('callback_query_id' => $callback_id, 'text' => 'Pubblicato ✅'));
            self::send_message($chat_id, '✅ Pubblicato: <a href="' . esc_url(get_permalink($post_id)) . '">' . self::esc(get_the_title($post_id)) . '</a>');
        } else {
            wp_trash_post($post_id);
            self::api_request('answerCallbackQuery', array('callback_query_id' => $callback_id, 'text' => 'Cestinato 🗑'));
            self::send_message($chat_id, '🗑 Cestinato: ' . self::esc(get_the_title($post_id)));
        }
    }

    /* ---------------------------------------------------------------------
     * Notifiche push
     * ------------------------------------------------------------------ */

    public static function notify_draft_created($post_id, $post_status = 'draft') {
        if (!self::is_enabled() || get_option(self::OPTION_NOTIFY_DRAFTS, '1') !== '1') { return; }
        foreach (self::get_chat_ids() as $chat_id) {
            self::send_draft_card($chat_id, $post_id);
        }
    }

    public static function notify_agent_report($report) {
        if (!self::is_enabled()) { return; }
        self::broadcast(self::format_agent_report((array) $report));
    }

    /**
     * Ogni articolo pubblicato (manuale o AI) arriva in chat con il link
     * alla visualizzazione. Una sola notifica per articolo.
     */
    public static function notify_post_published($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') { return; }
        if (!($post instanceof WP_Post) || $post->post_type !== 'post') { return; }
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) { return; }
        if (!self::is_enabled() || get_option(self::OPTION_NOTIFY_PUBLISH, '1') !== '1') { return; }
        if (get_post_meta($post->ID, self::META_PUBLISH_NOTIFIED, true) !== '') { return; }
        update_post_meta($post->ID, self::META_PUBLISH_NOTIFIED, current_time('mysql'));

        $is_ai = get_post_meta($post->ID, '_alma_ai_agent_generated', true) === '1';
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 30, '…');
        $text = '📣 <b>Articolo pubblicato</b>' . ($is_ai ? ' · 🤖 scritto dall\'Agente AI' : '') . "\n"
            . '<b>' . self::esc(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8')) . "</b>\n"
            . self::esc($excerpt);
        $keyboard = array(array(
            array('text' => '🔗 Apri articolo', 'url' => get_permalink($post)),
            array('text' => '✏️ Modifica', 'url' => admin_url('post.php?post=' . (int) $post->ID . '&action=edit')),
        ));
        self::broadcast($text, $keyboard);
    }

    /* ---------------------------------------------------------------------
     * Azioni admin (webhook, istruzioni, chat)
     * ------------------------------------------------------------------ */

    private static function verify_admin_action($nonce_action) {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer($nonce_action);
    }

    private static function redirect_back($type, $message) {
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => $type, 'message' => $message), 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=telegram'));
        exit;
    }

    public static function handle_register_webhook() {
        self::verify_admin_action('alma_telegram_admin');
        if (self::get_token() === '' || self::get_secret() === '') {
            self::redirect_back('error', 'Definisci prima ALMA_TELEGRAM_BOT_TOKEN e ALMA_TELEGRAM_SECRET in wp-config.php.');
        }
        $result = self::api_request('setWebhook', array(
            'url' => self::webhook_url(),
            'secret_token' => self::get_secret(),
            'allowed_updates' => array('message', 'callback_query'),
        ));
        if (!empty($result['ok'])) {
            // Menu comandi nativo di Telegram (pulsante "/" nella chat).
            self::api_request('setMyCommands', array('commands' => self::bot_commands()));
            self::redirect_back('success', 'Webhook registrato e menu comandi aggiornato: ' . self::webhook_url());
        }
        self::redirect_back('error', 'Registrazione webhook fallita: ' . sanitize_text_field((string)($result['description'] ?? 'errore sconosciuto')));
    }

    public static function handle_check_webhook() {
        self::verify_admin_action('alma_telegram_admin');
        $result = self::api_request('getWebhookInfo');
        if (empty($result['ok'])) {
            self::redirect_back('error', 'Verifica fallita: ' . sanitize_text_field((string)($result['description'] ?? 'errore sconosciuto')));
        }
        $info = (array) ($result['result'] ?? array());
        $url = sanitize_text_field((string)($info['url'] ?? ''));
        $pending = (int) ($info['pending_update_count'] ?? 0);
        $last_error = sanitize_text_field((string)($info['last_error_message'] ?? ''));
        $message = $url === '' ? 'Nessun webhook registrato.' : ('Webhook attivo su ' . $url . ' · aggiornamenti in coda: ' . $pending . ($last_error !== '' ? ' · ultimo errore: ' . $last_error : ' · nessun errore recente'));
        self::redirect_back($url === self::webhook_url() ? 'success' : 'error', $message);
    }

    public static function handle_send_instructions() {
        self::verify_admin_action('alma_telegram_admin');
        $chats = self::get_chat_ids();
        if (empty($chats)) {
            self::redirect_back('error', 'Nessuna Chat ID autorizzata: aggiungine una prima.');
        }
        self::broadcast(self::instructions_text());
        self::redirect_back('success', 'Istruzioni inviate a ' . count($chats) . ' chat.');
    }

    public static function handle_add_chat() {
        self::verify_admin_action('alma_telegram_admin');
        $chat_id = sanitize_text_field(wp_unslash($_POST['chat_id'] ?? ''));
        if (!preg_match('/^-?\d{1,20}$/', $chat_id)) {
            self::redirect_back('error', 'Chat ID non valida.');
        }
        $chats = self::get_chat_ids();
        if (!in_array($chat_id, $chats, true)) {
            $chats[] = $chat_id;
            update_option(self::OPTION_CHAT_IDS, $chats, false);
        }
        self::redirect_back('success', 'Chat ID ' . $chat_id . ' autorizzata.');
    }

    public static function save_settings($post) {
        update_option(self::OPTION_ENABLED, empty($post[self::OPTION_ENABLED]) ? '0' : '1', false);
        update_option(self::OPTION_NOTIFY_DRAFTS, empty($post[self::OPTION_NOTIFY_DRAFTS]) ? '0' : '1', false);
        update_option(self::OPTION_NOTIFY_PUBLISH, empty($post[self::OPTION_NOTIFY_PUBLISH]) ? '0' : '1', false);
        $chat_ids = array_values(array_unique(array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($post['alma_telegram_chat_ids_raw'] ?? '')))), function ($id) {
            return preg_match('/^-?\d{1,20}$/', $id);
        })));
        update_option(self::OPTION_CHAT_IDS, $chat_ids, false);
    }

    /* ---------------------------------------------------------------------
     * Tab impostazioni (guida + stato)
     * ------------------------------------------------------------------ */

    public static function render_settings_tab() {
        $token_defined = self::get_token() !== '';
        $secret_defined = self::get_secret() !== '';
        $enabled = get_option(self::OPTION_ENABLED, '0') === '1';
        $chat_ids = self::get_chat_ids();
        $recent = (array) get_option(self::OPTION_RECENT_CHATS, array());

        echo '<h2>Telegram — regia, monitoraggio e strategia</h2>';

        echo '<div class="alma-agent-card" style="max-width:900px;"><h3>Come configurare e usare l\'integrazione Telegram</h3>';
        echo '<p><strong>Configurazione (una sola volta):</strong></p><ol>';
        echo '<li>Crea un bot con <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> su Telegram e copia il token.</li>';
        echo '<li>In <code>wp-config.php</code> aggiungi:<br><code>define( \'ALMA_TELEGRAM_BOT_TOKEN\', \'123456:ABC...\' );</code><br><code>define( \'ALMA_TELEGRAM_SECRET\', \'una-stringa-casuale-lunga\' );</code></li>';
        echo '<li>Spunta "Abilita Telegram", salva, poi clicca <strong>Registra webhook</strong> e <strong>Verifica stato webhook</strong> per conferma.</li>';
        echo '<li>Scopri la tua Chat ID: apri il bot su Telegram e invia <code>/id</code> (il bot risponde con l\'ID). L\'ID comparirà anche qui sotto in "Chat ID viste di recente", con un pulsante <strong>Aggiungi</strong>.</li>';
        echo '<li>Aggiungi le Chat ID autorizzate e salva di nuovo le impostazioni.</li></ol>';
        echo '<p><strong>Cosa puoi fare dal bot (il pulsante <code>/</code> in chat mostra il menu completo):</strong></p>';
        echo '<ul style="list-style:disc;margin-left:20px;">';
        echo '<li><strong>Regia AI</strong>: <code>/agente borghi siciliani</code> avvia subito l\'agente sul tema; <code>/piano 5 10 borghi siciliani</code> programma 5 articoli in 10 giorni (come «Applica il piano» in Regia AI); <code>/stato</code> mostra agente in esecuzione, bozze programmate, immagini AI e salute link; <code>/stop</code> ferma l\'esecuzione; <code>/report</code> l\'esito dell\'ultima.</li>';
        echo '<li><strong>Contenuti</strong>: <code>/bozze</code> con pulsanti <em>Pubblica/Cestina</em>; in automatico ricevi ogni nuova bozza AI e <strong>ogni articolo pubblicato</strong> con il link.</li>';
        echo '<li><strong>Strategia</strong>: <code>/top</code> click e gap geografici; <code>/consigli</code> i consigli strategici AI.</li>';
        echo '<li><strong>Manutenzione</strong>: <code>/salute</code> link morti/sospetti; <code>/immagini</code> stato del generatore di immagini AI.</li>';
        echo '</ul>';
        echo '<p class="description">«Registra webhook» aggiorna anche il menu comandi nativo del bot su Telegram.</p>';
        echo '</div>';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Stato costanti</th><td>';
        echo '<p>ALMA_TELEGRAM_BOT_TOKEN: <span class="alma-badge '.($token_defined ? 'is-success' : 'is-warning').'">'.($token_defined ? 'definita' : 'non definita').'</span></p>';
        echo '<p>ALMA_TELEGRAM_SECRET: <span class="alma-badge '.($secret_defined ? 'is-success' : 'is-warning').'">'.($secret_defined ? 'definita' : 'non definita').'</span></p>';
        echo '<p class="description">Le credenziali vivono solo in wp-config.php, mai nel database.</p></td></tr>';
        echo '<tr><th scope="row">URL webhook</th><td><code>'.esc_html(self::webhook_url()).'</code></td></tr>';
        echo '</table>';

        // Form impostazioni (salvate tramite l'handler comune della pagina).
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_telegram_settings">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Abilita Telegram</th><td><label><input type="checkbox" name="'.esc_attr(self::OPTION_ENABLED).'" value="1" '.checked($enabled, true, false).'> Attiva webhook, comandi e notifiche</label></td></tr>';
        echo '<tr><th scope="row">Notifica nuove bozze</th><td><label><input type="checkbox" name="'.esc_attr(self::OPTION_NOTIFY_DRAFTS).'" value="1" '.checked(get_option(self::OPTION_NOTIFY_DRAFTS, '1'), '1', false).'> Invia in chat ogni nuova bozza AI con i pulsanti Pubblica/Cestina</label></td></tr>';
        echo '<tr><th scope="row">Notifica pubblicazioni</th><td><label><input type="checkbox" name="'.esc_attr(self::OPTION_NOTIFY_PUBLISH).'" value="1" '.checked(get_option(self::OPTION_NOTIFY_PUBLISH, '1'), '1', false).'> Invia in chat ogni articolo pubblicato (manuale o AI) con il link alla visualizzazione</label></td></tr>';
        echo '<tr><th scope="row"><label for="alma_telegram_chat_ids_raw">Chat ID autorizzate</label></th><td>';
        echo '<input type="text" class="regular-text" id="alma_telegram_chat_ids_raw" name="alma_telegram_chat_ids_raw" value="'.esc_attr(implode(', ', $chat_ids)).'" placeholder="Es. 123456789, -100987654321">';
        echo '<p class="description">Separate da virgola. Solo queste chat possono usare i comandi e ricevere le notifiche.</p></td></tr>';
        echo '</table><p><button class="button button-primary">Salva impostazioni Telegram</button></p></form>';

        // Pulsanti webhook / istruzioni.
        echo '<div class="alma-actions-inline" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;">';
        foreach (array(
            'alma_telegram_register_webhook' => 'Registra webhook',
            'alma_telegram_check_webhook' => 'Verifica stato webhook',
            'alma_telegram_send_instructions' => 'Invia istruzioni su Telegram',
        ) as $action => $label) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('alma_telegram_admin');
            echo '<input type="hidden" name="action" value="'.esc_attr($action).'"><button class="button">'.esc_html($label).'</button></form>';
        }
        echo '</div>';
        echo '<p class="description">I pulsanti usano il token e il secret configurati nelle costanti, senza terminale. Registra il webhook dopo aver salvato le impostazioni e definito le costanti.</p>';

        // Chat viste di recente.
        echo '<h3>Chat ID viste di recente</h3>';
        if (empty($recent)) {
            echo '<p class="description">Nessuna chat ha ancora scritto al bot. Invia /id al bot per comparire qui.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:640px;"><thead><tr><th>Chat ID</th><th>Nome</th><th>Ultimo contatto</th><th></th></tr></thead><tbody>';
            foreach ($recent as $chat_id => $meta) {
                $is_authorized = in_array((string) $chat_id, $chat_ids, true);
                echo '<tr><td><code>'.esc_html((string) $chat_id).'</code></td><td>'.esc_html((string)($meta['name'] ?? '')).'</td><td>'.esc_html((string)($meta['last_seen'] ?? '')).'</td><td>';
                if ($is_authorized) {
                    echo '<span class="alma-badge is-success">autorizzata</span>';
                } else {
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                    wp_nonce_field('alma_telegram_admin');
                    echo '<input type="hidden" name="action" value="alma_telegram_add_chat"><input type="hidden" name="chat_id" value="'.esc_attr((string) $chat_id).'"><button class="button button-small">Aggiungi</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }
}
