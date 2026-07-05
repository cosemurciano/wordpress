<?php
/**
 * Regia AI — camera di regia dell'agente di ideazione contenuti.
 *
 * Pagina dedicata (subito dopo la Dashboard) da cui:
 * - lanciare un PIANO EDITORIALE: quanti articoli, in quanti giorni,
 *   obiettivo opzionale, con creazione immediata delle bozze (default sì);
 *   i lanci manuali partono anche oltre il limite giornaliero (force);
 * - farsi CONSIGLIARE il piano dall'AI sulla base dei dati reali
 *   (click, gap geografici, opportunità Search Console, ritmo attuale);
 * - vedere COSA HA FATTO l'agente: grafico idee/bozze per giorno
 *   (Chart.js), storico esecuzioni, report dettagliato dell'ultima;
 * - regolare i limiti di guardia (idee per esecuzione, esecuzioni/giorno).
 *
 * La stessa regia risponde su Telegram: /agente <argomento> lancia un
 * piano sul tema indicato, con bozze immediate, anche oltre i limiti.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Agent_Control_Room {
    const MENU_SLUG = 'alma-ai-regia';
    const OPTION_ADVICE = 'alma_ai_regia_advice';

    public static function init() {
        add_action('admin_post_alma_ai_regia_advice', array(__CLASS__, 'handle_advice'));
    }

    /* ---------------------------------------------------------------------
     * Consiglio AI del piano editoriale
     * ------------------------------------------------------------------ */

    public static function handle_advice() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_regia_advice');
        $notice = array('type' => 'success', 'message' => __('Consiglio del piano generato.', 'affiliate-link-manager-ai'));
        $advice = self::generate_advice();
        if (is_wp_error($advice)) {
            $notice = array('type' => 'error', 'message' => sprintf(__('Consiglio non generato: %s', 'affiliate-link-manager-ai'), $advice->get_error_message()));
        } else {
            update_option(self::OPTION_ADVICE, array('time' => current_time('mysql'), 'data' => $advice), false);
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=' . self::MENU_SLUG));
        exit;
    }

    private static function generate_advice() {
        if (empty(get_option('alma_openai_api_key', ''))) {
            return new WP_Error('alma_regia', __('OpenAI non è configurata.', 'affiliate-link-manager-ai'));
        }
        $context = self::advice_context();
        $result = ALMA_OpenAI_Service::request(array(
            'system_prompt' => 'Sei il direttore editoriale di un blog di viaggi italiano monetizzato con link affiliati. '
                . 'Sulla base dei dati ricevi devi consigliare un piano editoriale sostenibile per l\'agente AI di ideazione. '
                . 'Rispondi SOLO con un oggetto JSON: {"articoli": <1-10>, "giorni": <3-30>, "obiettivo": "<obiettivo editoriale suggerito, 1 frase>", "motivazione": "<perché questo ritmo e questo focus, 2-3 frasi in italiano>"}.',
            'user_prompt' => 'Dati del sito: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE),
            'max_output_tokens' => 400,
            'temperature' => 0.3,
            'timeout' => 60,
        ));
        if (empty($result['success'])) {
            return new WP_Error('alma_regia', sanitize_text_field((string)($result['error'] ?? 'Errore AI')));
        }
        $raw = (string) $result['response'];
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        $data = ($start !== false && $end !== false) ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
        if (!is_array($data) || empty($data['articoli'])) {
            return new WP_Error('alma_regia', __('Risposta AI non interpretabile.', 'affiliate-link-manager-ai'));
        }
        return array(
            'articoli' => max(1, min(10, absint($data['articoli']))),
            'giorni' => max(3, min(30, absint($data['giorni'] ?? 14))),
            'obiettivo' => sanitize_text_field((string)($data['obiettivo'] ?? '')),
            'motivazione' => sanitize_textarea_field((string)($data['motivazione'] ?? '')),
        );
    }

    /**
     * Contesto compatto per il consiglio: numeri, non prose.
     */
    private static function advice_context() {
        global $wpdb;
        $context = array();
        if (class_exists('ALMA_Dashboard_Insights')) {
            $snapshot = ALMA_Dashboard_Insights::get_snapshot();
            if (is_array($snapshot)) {
                $context['click_periodi'] = $snapshot['periods'] ?? array();
                $context['localita_con_link_senza_click'] = count((array)($snapshot['geo_unused'] ?? array()));
                $context['localita_con_articoli_senza_link'] = count((array)($snapshot['geo_no_links'] ?? array()));
            }
        }
        if (class_exists('ALMA_GSC_Connector') && ALMA_GSC_Connector::is_configured()) {
            $gsc = get_option('alma_gsc_snapshot', null);
            if (is_array($gsc)) {
                $context['opportunita_search_console'] = array_slice((array)($gsc['opportunities'] ?? array()), 0, 5);
            }
        }
        $context['idee_in_archivio'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
            'alma_content_idea'
        ));
        $series = self::activity_series(30);
        $context['idee_create_ultimi_30gg'] = array_sum($series['ideas']);
        $context['bozze_ai_ultimi_30gg'] = array_sum($series['drafts']);
        if (class_exists('ALMA_AI_Content_Agent_Idea_Importer')) {
            $context['limite_bozze_automatiche_al_giorno'] = (int) get_option('alma_ai_ideas_daily_draft_limit', 3);
        }
        return $context;
    }

    /* ---------------------------------------------------------------------
     * Dati attività per il grafico
     * ------------------------------------------------------------------ */

    /**
     * Idee create e bozze AI generate per giorno (ultimi N giorni).
     */
    public static function activity_series($days = 30) {
        global $wpdb;
        $days = max(7, min(90, absint($days)));
        $from = gmdate('Y-m-d 00:00:00', current_time('timestamp') - ($days - 1) * DAY_IN_SECONDS);
        $labels = array();
        $ideas = array();
        $drafts = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = gmdate('Y-m-d', current_time('timestamp') - $i * DAY_IN_SECONDS);
            $labels[] = gmdate('d/m', strtotime($key));
            $ideas[$key] = 0;
            $drafts[$key] = 0;
        }
        $idea_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) AS giorno, COUNT(*) AS n FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_date >= %s
             GROUP BY DATE(post_date)",
            'alma_content_idea', $from
        ), ARRAY_A);
        foreach ((array) $idea_rows as $row) {
            if (isset($ideas[$row['giorno']])) { $ideas[$row['giorno']] = (int) $row['n']; }
        }
        $draft_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(p.post_date) AS giorno, COUNT(*) AS n FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_alma_ai_agent_generated' AND m.meta_value = '1'
             WHERE p.post_type = 'post' AND p.post_status NOT IN ('trash','auto-draft') AND p.post_date >= %s
             GROUP BY DATE(p.post_date)",
            $from
        ), ARRAY_A);
        foreach ((array) $draft_rows as $row) {
            if (isset($drafts[$row['giorno']])) { $drafts[$row['giorno']] = (int) $row['n']; }
        }
        return array('labels' => $labels, 'ideas' => array_values($ideas), 'drafts' => array_values($drafts));
    }

    /* ---------------------------------------------------------------------
     * Pagina
     * ------------------------------------------------------------------ */

    private static function render_notice() {
        $notice = get_transient('alma_ai_agent_admin_notice_' . get_current_user_id());
        if (is_array($notice) && !empty($notice['message'])) {
            delete_transient('alma_ai_agent_admin_notice_' . get_current_user_id());
            echo '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        $running = (bool) get_option(ALMA_AI_Idea_Agent::LOCK_OPTION);
        $runs_today = ALMA_AI_Idea_Agent::runs_today();
        $runs_limit = ALMA_AI_Idea_Agent::get_daily_runs_limit();
        $draft_limit = (int) get_option('alma_ai_ideas_daily_draft_limit', 3);
        $draft_counter = get_option('alma_ai_ideas_draft_counter', array());
        $drafts_today = (is_array($draft_counter) && ($draft_counter['date'] ?? '') === current_time('Y-m-d')) ? (int) $draft_counter['count'] : 0;
        $advice = get_option(self::OPTION_ADVICE, null);
        $history = ALMA_AI_Idea_Agent::get_run_history();
        $series = self::activity_series(30);
        $card = 'border:1px solid #c3c4c7;border-radius:6px;background:#fff;padding:14px 16px;margin:0 0 14px;';

        echo '<div class="wrap">';
        echo '<h1>🎬 Regia AI</h1>';
        self::render_notice();
        echo '<p class="description" style="max-width:900px;">La camera di regia dell\'agente di ideazione: da qui gli comunichi il piano editoriale (quanti articoli, in quanti giorni, con quale obiettivo), ti fai consigliare il piano dai dati reali, e vedi cosa ha fatto. La stessa regia risponde su Telegram con <code>/agente &lt;argomento&gt;</code>.</p>';

        // ---- Stato ----
        echo '<div style="' . esc_attr($card) . 'display:flex;gap:22px;flex-wrap:wrap;align-items:center;">';
        echo '<span><strong>' . esc_html__('Stato', 'affiliate-link-manager-ai') . ':</strong> ' . ($running ? '<span style="color:#996800;">⏳ ' . esc_html__('esecuzione in corso…', 'affiliate-link-manager-ai') . '</span>' : '<span style="color:#00753d;">✅ ' . esc_html__('pronto', 'affiliate-link-manager-ai') . '</span>') . '</span>';
        echo '<span><strong>' . esc_html__('Esecuzioni oggi', 'affiliate-link-manager-ai') . ':</strong> ' . esc_html($runs_today . ' / ' . $runs_limit) . ' <span class="description">(' . esc_html__('i lanci manuali partono comunque', 'affiliate-link-manager-ai') . ')</span></span>';
        echo '<span><strong>' . esc_html__('Bozze automatiche oggi', 'affiliate-link-manager-ai') . ':</strong> ' . esc_html($drafts_today . ' / ' . $draft_limit) . '</span>';
        echo '</div>';

        // ---- Piano editoriale ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">📋 ' . esc_html__('Piano editoriale', 'affiliate-link-manager-ai') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_ai_idea_agent_start');
        echo '<input type="hidden" name="action" value="alma_ai_idea_agent_start">';
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('Quanti articoli', 'affiliate-link-manager-ai') . '</strong><br><input type="number" id="alma-regia-num" name="agent_num_ideas" min="1" max="10" value="' . esc_attr((string) ALMA_AI_Idea_Agent::get_max_ideas()) . '" class="small-text"></label></p>';
        echo '<p style="margin:0;"><label><strong>' . esc_html__('In quanti giorni', 'affiliate-link-manager-ai') . '</strong><br><input type="number" id="alma-regia-giorni" name="agent_days" min="1" max="60" value="14" class="small-text"></label></p>';
        echo '<p style="margin:0;flex:1 1 340px;"><label><strong>' . esc_html__('Obiettivo (opzionale)', 'affiliate-link-manager-ai') . '</strong><br><input type="text" id="alma-regia-obiettivo" name="agent_objective" class="widefat" placeholder="' . esc_attr__('Es. destinazioni per l\'autunno, focus Sicilia, cammini…', 'affiliate-link-manager-ai') . '"></label></p>';
        echo '<p style="margin:0;"><label title="' . esc_attr__('Le bozze immediate rispettano il limite giornaliero: le idee oltre quota vengono generate automaticamente nei giorni programmati.', 'affiliate-link-manager-ai') . '"><input type="checkbox" name="agent_create_drafts" value="1" checked> ' . esc_html__('Crea subito anche le bozze', 'affiliate-link-manager-ai') . '</label></p>';
        echo '<p style="margin:0;"><button class="button button-primary button-hero" ' . disabled($running, true, false) . '>' . esc_html($running ? __('Esecuzione in corso…', 'affiliate-link-manager-ai') : __('Avvia l\'agente', 'affiliate-link-manager-ai')) . '</button></p>';
        echo '</div>';
        echo '<p class="description" style="margin:8px 0 0;">' . esc_html__('L\'agente crea le idee con date di pubblicazione distribuite nel periodo indicato; le bozze non immediate vengono generate automaticamente nei giorni programmati (job notturno), nel rispetto del limite giornaliero.', 'affiliate-link-manager-ai') . '</p>';
        echo '</form>';
        echo '</div>';

        // ---- Consiglio AI ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">💡 ' . esc_html__('Fatti consigliare il piano', 'affiliate-link-manager-ai') . '</h2>';
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
        wp_nonce_field('alma_ai_regia_advice');
        echo '<input type="hidden" name="action" value="alma_ai_regia_advice"><button class="button">' . esc_html__('Chiedi un consiglio ai dati', 'affiliate-link-manager-ai') . '</button></form>';
        echo '<span class="description">' . esc_html__('L\'AI analizza click, gap geografici, opportunità Search Console e il tuo ritmo attuale, e propone quanti articoli creare, in quanti giorni e con quale focus.', 'affiliate-link-manager-ai') . '</span>';
        echo '</div>';
        if (is_array($advice) && !empty($advice['data'])) {
            $d = $advice['data'];
            echo '<div style="margin-top:10px;padding:10px 12px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;">';
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html(sprintf(__('Consiglio del %s', 'affiliate-link-manager-ai'), (string) $advice['time'])) . ':</strong> ';
            echo esc_html(sprintf(__('%1$d articoli in %2$d giorni', 'affiliate-link-manager-ai'), (int) $d['articoli'], (int) $d['giorni']));
            if (!empty($d['obiettivo'])) { echo ' — <em>' . esc_html($d['obiettivo']) . '</em>'; }
            echo '</p>';
            if (!empty($d['motivazione'])) { echo '<p style="margin:0 0 8px;" class="description">' . esc_html($d['motivazione']) . '</p>'; }
            echo '<button type="button" class="button button-secondary" id="alma-regia-applica" data-articoli="' . esc_attr((string)(int) $d['articoli']) . '" data-giorni="' . esc_attr((string)(int) $d['giorni']) . '" data-obiettivo="' . esc_attr((string) $d['obiettivo']) . '">' . esc_html__('Applica al piano', 'affiliate-link-manager-ai') . '</button>';
            echo '</div>';
        }
        echo '</div>';

        // ---- Grafico attività ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">📈 ' . esc_html__('Attività ultimi 30 giorni', 'affiliate-link-manager-ai') . '</h2>';
        echo '<div style="height:280px;"><canvas id="alma-regia-chart"></canvas></div>';
        echo '<p class="description">' . esc_html(sprintf(__('Totali: %1$d idee create, %2$d bozze AI generate.', 'affiliate-link-manager-ai'), array_sum($series['ideas']), array_sum($series['drafts']))) . '</p>';
        echo '</div>';

        // ---- Ultima esecuzione ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">📄 ' . esc_html__('Ultima esecuzione', 'affiliate-link-manager-ai') . '</h2>';
        ALMA_AI_Idea_Agent::render_last_run_details();
        echo '</div>';

        // ---- Storico ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">🗂️ ' . esc_html__('Storico esecuzioni', 'affiliate-link-manager-ai') . '</h2>';
        if (empty($history)) {
            echo '<p class="description">' . esc_html__('Ancora nessuna esecuzione in archivio.', 'affiliate-link-manager-ai') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr><th>' . esc_html__('Avvio', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Idee', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Bozze', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Obiettivo', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Costo ~$', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Esito', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
            foreach ($history as $run) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($run['started_at'] ?? '')) . ($run['forced'] ? ' <span class="description" title="' . esc_attr__('Lancio manuale (Regia o Telegram)', 'affiliate-link-manager-ai') . '">✋</span>' : '') . '</td>';
                echo '<td>' . esc_html((string)(int)($run['ideas'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string)(int)($run['drafts'] ?? 0)) . '</td>';
                $objective_label = (string)($run['objective'] ?? '');
                echo '<td>' . esc_html($objective_label !== '' ? $objective_label : '—') . '</td>';
                echo '<td>' . esc_html(number_format((float)($run['cost'] ?? 0), 4)) . '</td>';
                echo '<td>' . (empty($run['error']) ? '✅' : '<span style="color:#d63638;" title="' . esc_attr((string)$run['error']) . '">⚠️ ' . esc_html((string)$run['error']) . '</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // ---- Limiti di guardia ----
        echo '<div style="' . esc_attr($card) . '">';
        echo '<h2 style="margin-top:0;">🛡️ ' . esc_html__('Limiti di guardia', 'affiliate-link-manager-ai') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">';
        wp_nonce_field('alma_ai_idea_agent_settings');
        echo '<input type="hidden" name="action" value="alma_ai_idea_agent_settings">';
        echo '<label>' . esc_html__('Idee per esecuzione (default)', 'affiliate-link-manager-ai') . ' <input type="number" min="1" max="10" class="small-text" name="' . esc_attr(ALMA_AI_Idea_Agent::OPTION_MAX_IDEAS) . '" value="' . esc_attr((string) ALMA_AI_Idea_Agent::get_max_ideas()) . '"></label>';
        echo '<label>' . esc_html__('Esecuzioni automatiche al giorno', 'affiliate-link-manager-ai') . ' <input type="number" min="1" max="20" class="small-text" name="' . esc_attr(ALMA_AI_Idea_Agent::OPTION_DAILY_RUNS) . '" value="' . esc_attr((string) $runs_limit) . '"></label>';
        echo '<button class="button">' . esc_html__('Salva limiti', 'affiliate-link-manager-ai') . '</button>';
        echo '<span class="description">' . esc_html(sprintf(__('Il limite bozze automatiche (%d/giorno) si regola in Importazione massiva.', 'affiliate-link-manager-ai'), $draft_limit)) . '</span>';
        echo '</form>';
        echo '</div>';

        // ---- JS: grafico + applica consiglio ----
        $chart_payload = wp_json_encode(array('labels' => $series['labels'], 'ideas' => $series['ideas'], 'drafts' => $series['drafts']));
        echo '<script>
        (function(){
            var data = ' . $chart_payload . ';
            function initChart(){
                var canvas = document.getElementById("alma-regia-chart");
                if (!canvas || typeof window.Chart === "undefined") { return; }
                new window.Chart(canvas, {
                    type: "bar",
                    data: { labels: data.labels, datasets: [
                        { label: "' . esc_js(__('Idee create', 'affiliate-link-manager-ai')) . '", data: data.ideas, backgroundColor: "rgba(34,113,177,0.6)" },
                        { label: "' . esc_js(__('Bozze AI', 'affiliate-link-manager-ai')) . '", data: data.drafts, backgroundColor: "rgba(0,163,42,0.6)" }
                    ]},
                    options: { responsive: true, maintainAspectRatio: false,
                        scales: { x: { stacked: false }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
                });
            }
            var applyBtn = document.getElementById("alma-regia-applica");
            if (applyBtn) {
                applyBtn.addEventListener("click", function(){
                    var num = document.getElementById("alma-regia-num");
                    var giorni = document.getElementById("alma-regia-giorni");
                    var obiettivo = document.getElementById("alma-regia-obiettivo");
                    if (num) { num.value = this.getAttribute("data-articoli"); }
                    if (giorni) { giorni.value = this.getAttribute("data-giorni"); }
                    if (obiettivo) { obiettivo.value = this.getAttribute("data-obiettivo"); }
                    window.scrollTo({ top: 0, behavior: "smooth" });
                });
            }
            if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", initChart); } else { initChart(); }
        })();
        </script>';
        echo '</div>';
    }
}
