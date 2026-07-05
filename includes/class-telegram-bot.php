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
    const REST_NAMESPACE = 'alma/v1';
    const REST_ROUTE = '/telegram';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_rest_route'));
        add_action('admin_post_alma_telegram_register_webhook', array(__CLASS__, 'handle_register_webhook'));
        add_action('admin_post_alma_telegram_check_webhook', array(__CLASS__, 'handle_check_webhook'));
        add_action('admin_post_alma_telegram_send_instructions', array(__CLASS__, 'handle_send_instructions'));
        add_action('admin_post_alma_telegram_add_chat', array(__CLASS__, 'handle_add_chat'));
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
            default:
                self::send_message($chat_id, 'Comando non riconosciuto. ' . "\n" . self::instructions_text());
        }
    }

    public static function instructions_text() {
        return "<b>Comandi disponibili</b>\n"
            . "/agente &lt;argomento&gt; — avvia l'agente: crea idee E bozze sul tema indicato (opzionale); parte anche oltre il limite giornaliero\n"
            . "/report — esito dell'ultima esecuzione dell'agente\n"
            . "/bozze — ultime bozze AI con pulsanti Pubblica/Cestina\n"
            . "/top — report sintetico: click e gap geografici\n"
            . "/consigli — ultimi consigli strategici AI\n"
            . "/id — mostra la tua Chat ID\n"
            . "/help — questo elenco";
    }

    /* ---------------------------------------------------------------------
     * Comandi
     * ------------------------------------------------------------------ */

    private static function command_agent($chat_id, $objective) {
        if (!class_exists('ALMA_AI_Idea_Agent')) {
            self::send_message($chat_id, 'Agente non disponibile.');
            return;
        }
        if (empty(get_option('alma_openai_api_key', ''))) {
            self::send_message($chat_id, '❌ OpenAI non è configurata nel plugin.');
            return;
        }
        if (get_option(ALMA_AI_Idea_Agent::LOCK_OPTION)) {
            self::send_message($chat_id, '⏳ Un\'esecuzione dell\'agente è già in corso: riceverai il report al termine.');
            return;
        }
        // Lancio dalla regia Telegram: force=1 (parte anche oltre il limite
        // giornaliero) e bozze immediate attive, come dalla pagina Regia AI.
        $over_limit = ALMA_AI_Idea_Agent::runs_today() >= ALMA_AI_Idea_Agent::get_daily_runs_limit();
        // Autore delle idee: il primo amministratore.
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        $user_id = !empty($admins) ? (int) $admins[0] : 1;
        wp_schedule_single_event(time() + 5, ALMA_AI_Idea_Agent::CRON_HOOK, array($user_id, sanitize_textarea_field($objective), 1, 1, 0, 0));
        if (function_exists('spawn_cron')) { spawn_cron(); }
        self::send_message($chat_id, '🤖 Agente di ideazione avviato' . ($objective !== '' ? ' sul tema: <i>' . self::esc($objective) . '</i>' : '')
            . "\nCreerà idee e le relative bozze (nel rispetto del limite giornaliero bozze)."
            . ($over_limit ? "\n✋ Limite esecuzioni giornaliere già raggiunto: il lancio manuale viene eseguito comunque." : '')
            . "\nRiceverai qui il report al termine (2-5 minuti).");
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
        $text = "<b>🤖 Report agente ideazione</b>\n";
        $text .= 'Avviato: ' . self::esc($report['started_at'] ?? '') . "\n";
        $text .= 'Idee create: <b>' . count($ideas) . '</b> · Bozze: <b>' . count($drafts) . '</b> · Costo stimato: ~$' . number_format((float) ($report['cost_total'] ?? 0), 4) . "\n";
        foreach (array_slice($ideas, 0, 8) as $idea) {
            $text .= '• ' . self::esc($idea['titolo']) . (!empty($idea['localita']) ? ' — 📍 ' . self::esc($idea['localita']) : '') . "\n";
        }
        foreach (array_slice($drafts, 0, 5) as $draft) {
            $preview = get_preview_post_link((int) $draft['post_id']);
            $text .= '📝 <a href="' . esc_url($preview) . '">' . self::esc($draft['titolo']) . "</a>\n";
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
            self::redirect_back('success', 'Webhook registrato: ' . self::webhook_url());
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
        echo '<p><strong>Cosa puoi fare dal bot:</strong> avviare l\'agente di ideazione con un obiettivo (<code>/agente idee per l\'estate in Puglia</code>), ricevere il report di idee e bozze create, ottenere i link alle bozze con pulsanti <em>Pubblica/Cestina</em> (<code>/bozze</code>), un report sintetico su click e gap (<code>/top</code>) e i consigli strategici AI (<code>/consigli</code>). Le nuove bozze AI arrivano in chat automaticamente con i pulsanti di revisione.</p>';
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
