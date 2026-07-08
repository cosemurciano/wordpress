<?php
/**
 * Fase 6 — Arricchimento automatico in background dei post pubblicati.
 *
 * Ogni giorno il runner analizza N articoli pubblicati e APPLICA direttamente
 * gli inserimenti di link affiliati proposti dall'AI (motore condiviso con il
 * metabox "AI Affiliati"): il contenuto esistente NON viene mai riscritto —
 * vengono solo aggiunti shortcode nei punti proposti (o sostituiti link
 * incoerenti nelle ri-analisi). Ogni aggiornamento passa da wp_update_post →
 * revisione WordPress (rollback nativo).
 *
 * Ciclo di copertura:
 * 1. prima gli articoli MAI analizzati, dai PIÙ VECCHI per data di
 *    pubblicazione (i primi articoli del blog sono i meno ottimizzati);
 * 2. finiti tutti, si riparte dai più "vecchi" di analisi — ma un articolo
 *    non viene mai ri-analizzato prima del cooldown configurato (default 60
 *    giorni), per controllare i costi ed evitare churn. Nelle ri-analisi
 *    l'AI può anche sostituire link esistenti se incoerenti.
 *
 * Niente notifiche per singolo articolo: a fine esecuzione un solo digest
 * Telegram (se abilitato) con il riepilogo, e il report completo nella tab
 * "Arricchimento" di Impostazioni AI Content.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Post_Enricher {
    const CRON_HOOK = 'alma_ai_post_enricher_run';
    const OPTION_ENABLED = 'alma_ai_enrich_enabled';
    const OPTION_DAILY = 'alma_ai_enrich_daily_limit';
    const OPTION_COOLDOWN = 'alma_ai_enrich_cooldown_days';
    const OPTION_COUNTER = 'alma_ai_enrich_counter';
    const OPTION_LOG = 'alma_ai_enrich_log';
    const OPTION_RUN_STATUS = 'alma_ai_enrich_run_status';
    const META_LAST_RUN = '_alma_ai_enrich_last_run';
    const LOCK_OPTION = 'alma_ai_enrich_lock';
    const LOCK_TTL = 900;
    const MAX_LOG_ENTRIES = 100;
    const TIME_BUDGET_SECONDS = 150;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'), 10, 1);
        add_action('admin_post_alma_ai_enrich_run_now', array(__CLASS__, 'handle_run_now'));
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $first = strtotime('tomorrow 05:30', current_time('timestamp'));
            $first = $first - (current_time('timestamp') - time());
            wp_schedule_event($first, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, '0') === '1';
    }

    public static function get_daily_limit() {
        return max(0, min(50, absint(get_option(self::OPTION_DAILY, 5))));
    }

    public static function get_cooldown_days() {
        return max(7, min(365, absint(get_option(self::OPTION_COOLDOWN, 60))));
    }

    public static function processed_today() {
        $counter = get_option(self::OPTION_COUNTER, array());
        return (is_array($counter) && ($counter['date'] ?? '') === current_time('Y-m-d')) ? (int)$counter['count'] : 0;
    }

    public static function save_settings($post) {
        update_option(self::OPTION_ENABLED, empty($post[self::OPTION_ENABLED]) ? '0' : '1', false);
        update_option(self::OPTION_DAILY, max(0, min(50, absint($post[self::OPTION_DAILY] ?? 5))), false);
        update_option(self::OPTION_COOLDOWN, max(7, min(365, absint($post[self::OPTION_COOLDOWN] ?? 60))), false);
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_enrich_admin');
        $notice = array('type' => 'success', 'message' => __('Arricchimento avviato in background: lo stato comparirà qui sotto (ricarica tra 1-2 minuti).', 'affiliate-link-manager-ai'));
        if (!self::is_enabled()) {
            $notice = array('type' => 'error', 'message' => __('Arricchimento disattivato: spunta "Attiva arricchimento" e salva prima di eseguire.', 'affiliate-link-manager-ai'));
        } else {
            // Argomento unico: senza, WordPress scarta in silenzio un secondo
            // evento identico entro 10 minuti e il click non fa nulla.
            $scheduled = wp_schedule_single_event(time() + 5, self::CRON_HOOK, array('manual-' . time()));
            if (false === $scheduled) {
                $notice = array('type' => 'error', 'message' => __('Programmazione non riuscita: un\'esecuzione è già in coda. Attendi 1-2 minuti e ricarica.', 'affiliate-link-manager-ai'));
            } else {
                update_option(self::OPTION_RUN_STATUS, array(
                    'trigger' => 'manuale',
                    'requested_at' => current_time('mysql'),
                    'started_at' => '',
                    'finished_at' => '',
                    'state' => 'in attesa di WP-Cron',
                    'detail' => '',
                ), false);
                if (function_exists('spawn_cron')) { spawn_cron(); }
            }
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=arricchimento'));
        exit;
    }

    /**
     * Aggiorna lo stato visibile dell'ultima esecuzione (mai più run muti).
     */
    private static function set_run_status($fields) {
        $status = (array) get_option(self::OPTION_RUN_STATUS, array());
        update_option(self::OPTION_RUN_STATUS, array_merge($status, (array) $fields), false);
    }

    /* ---------------------------------------------------------------------
     * Runner
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
     * Coda di lavoro: prima i post mai analizzati partendo DAI PIÙ VECCHI
     * (le prime pubblicazioni — 2020 — sono quelle meno ottimizzate: i
     * recenti nascono già con link e widget), poi i più vecchi di analisi
     * oltre il cooldown — il ciclo riparte da solo dopo aver coperto tutto.
     * Metodo deterministico (data di pubblicazione crescente): prevedibile,
     * copre tutto l'archivio senza buchi e senza rilavorare due volte.
     */
    public static function next_posts($limit) {
        $limit = max(1, absint($limit));
        $ids = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => array(array('key' => self::META_LAST_RUN, 'compare' => 'NOT EXISTS')),
        ));
        $ids = array_map('absint', (array) $ids);
        if (count($ids) < $limit) {
            $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::get_cooldown_days() * DAY_IN_SECONDS);
            $more = get_posts(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $limit - count($ids),
                'fields' => 'ids',
                'orderby' => 'meta_value',
                'meta_key' => self::META_LAST_RUN,
                'order' => 'ASC',
                'no_found_rows' => true,
                'post__not_in' => $ids ?: array(0),
                'meta_query' => array(array('key' => self::META_LAST_RUN, 'value' => $cutoff, 'compare' => '<')),
            ));
            $ids = array_merge($ids, array_map('absint', (array) $more));
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function run($trigger = '') {
        $manual = is_string($trigger) && strpos($trigger, 'manual') === 0;
        if ($manual || (array) get_option(self::OPTION_RUN_STATUS, array())) {
            self::set_run_status(array('trigger' => $manual ? 'manuale' : 'cron giornaliero', 'started_at' => current_time('mysql'), 'state' => 'in esecuzione', 'detail' => ''));
        }
        if (!self::is_enabled()) {
            self::set_run_status(array('state' => 'saltata', 'detail' => 'Arricchimento disattivato nelle impostazioni.', 'finished_at' => current_time('mysql')));
            return;
        }
        if (!self::acquire_lock()) {
            self::set_run_status(array('state' => 'saltata', 'detail' => 'Un\'altra esecuzione è già in corso (lock attivo, scade in max 15 minuti).', 'finished_at' => current_time('mysql')));
            return;
        }
        $started = time();
        $summary = array('processed' => 0, 'updated' => 0, 'links_added' => 0, 'links_replaced' => 0, 'skipped' => 0, 'errors' => 0);
        $loop_reached = false;
        try {
            $limit = self::get_daily_limit();
            $today = current_time('Y-m-d');
            $done_today = self::processed_today();
            // Esecuzione manuale: l'admin ha chiesto ORA, il contatore
            // giornaliero non la blocca (elabora fino a "Articoli al giorno").
            $quota = $manual ? max(1, $limit) : max(0, $limit - $done_today);
            if ($quota < 1) {
                self::set_run_status(array('state' => 'saltata', 'detail' => sprintf('Limite giornaliero già raggiunto (%d/%d): riparte domani, oppure usa Esegui ora che ignora il limite.', $done_today, $limit), 'finished_at' => current_time('mysql')));
                return;
            }
            if (empty(get_option('alma_openai_api_key', ''))) {
                self::set_run_status(array('state' => 'saltata', 'detail' => 'OpenAI non configurata (ALMA_OPENAI_API_KEY).', 'finished_at' => current_time('mysql')));
                return;
            }

            $post_ids = self::next_posts($quota);
            if (empty($post_ids)) {
                self::set_run_status(array('state' => 'completata', 'detail' => 'Nessun articolo in coda (tutti analizzati e nessuno oltre il cooldown).', 'finished_at' => current_time('mysql')));
                return;
            }
            $loop_reached = true;
            foreach ($post_ids as $post_id) {
                if ((time() - $started) > self::TIME_BUDGET_SECONDS) { break; }
                $entry = self::enrich_post($post_id);
                $summary['processed']++;
                $done_today++;
                update_option(self::OPTION_COUNTER, array('date' => $today, 'count' => $done_today), false);
                if (!empty($entry['updated'])) {
                    $summary['updated']++;
                    $summary['links_added'] += (int) $entry['added'];
                    $summary['links_replaced'] += (int) $entry['replaced'];
                } elseif (!empty($entry['error'])) {
                    $summary['errors']++;
                } else {
                    $summary['skipped']++;
                }
            }
        } catch (Throwable $e) {
            self::set_run_status(array('state' => 'errore', 'detail' => sanitize_text_field($e->getMessage()), 'finished_at' => current_time('mysql')));
            $loop_reached = false; // il finally non deve sovrascrivere l'errore
        } finally {
            delete_option(self::LOCK_OPTION);
            // Solo se il loop è partito: le uscite anticipate hanno già
            // scritto il loro motivo e non vanno sovrascritte.
            if ($loop_reached) {
                self::set_run_status(array(
                    'state' => 'completata',
                    'detail' => sprintf('Analizzati %d · aggiornati %d · link aggiunti %d · sostituiti %d · saltati %d · errori %d. Dettagli per articolo nel report qui sotto.', $summary['processed'], $summary['updated'], $summary['links_added'], $summary['links_replaced'], $summary['skipped'], $summary['errors']),
                    'finished_at' => current_time('mysql'),
                ));
            }
            // Un solo digest Telegram per esecuzione (mai per singolo articolo).
            if ($summary['processed'] > 0 && class_exists('ALMA_Telegram_Bot') && ALMA_Telegram_Bot::is_enabled()) {
                ALMA_Telegram_Bot::send_message_to_all(sprintf(
                    "🔗 <b>Arricchimento affiliati</b>\nArticoli analizzati: %d · aggiornati: %d\nLink aggiunti: %d · sostituiti: %d · saltati: %d · errori: %d",
                    $summary['processed'], $summary['updated'], $summary['links_added'], $summary['links_replaced'], $summary['skipped'], $summary['errors']
                ));
            }
        }
    }

    /**
     * Analizza e aggiorna UN post: proposte dal motore condiviso, applicate
     * tutte automaticamente (aggiunte in ordine di paragrafo decrescente per
     * non spostare gli indici; sostituzioni sul contenuto risultante).
     */
    public static function enrich_post($post_id) {
        $post = get_post($post_id);
        $entry = array('post_id' => (int) $post_id, 'title' => $post ? html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8') : ('#' . $post_id), 'time' => current_time('mysql'), 'added' => 0, 'replaced' => 0, 'updated' => false, 'note' => '', 'error' => '');
        if (!$post || $post->post_status !== 'publish') {
            $entry['error'] = 'Post non disponibile';
            self::log_entry($entry);
            return $entry;
        }
        update_post_meta($post_id, self::META_LAST_RUN, current_time('mysql'));

        // Integrazione geo dal contenuto (mappa articolo): l'arricchimento
        // scorre l'archivio dai post più vecchi, quindi copre progressivamente
        // anche gli articoli pubblicati prima dell'integrazione automatica.
        if (class_exists('ALMA_Geo_Auto_Indexer')) {
            ALMA_Geo_Auto_Indexer::schedule_integration($post_id);
        }

        $has_links = (bool) preg_match('/\[affiliate_link(?:s_widget)?[\s\]]/', $post->post_content);
        $result = ALMA_AI_Post_Optimizer::generate_proposals($post, array(
            'allow_replacements' => $has_links,
            'task' => 'post_enricher',
        ));
        if (!empty($result['error'])) {
            $entry['note'] = $result['error'];
            self::log_entry($entry);
            return $entry;
        }

        $content = $post->post_content;
        $additions = array();
        foreach ((array) $result['proposals'] as $proposal) {
            if (($proposal['pattern'] ?? '') === 'replace') {
                $replaced = ALMA_AI_Post_Optimizer::apply_replacement($content, (int)$proposal['old_link_id'], (int)$proposal['link_id'], (string)($proposal['anchor_text'] ?? ''));
                if (!empty($replaced['replaced'])) {
                    $content = $replaced['content'];
                    $entry['replaced']++;
                }
            } else {
                $additions[] = $proposal;
            }
        }
        // Paragrafi decrescenti: gli inserimenti non spostano gli indici successivi.
        usort($additions, function ($a, $b) { return (int)$b['paragraph'] <=> (int)$a['paragraph']; });
        $inserted_link_ids = array();
        foreach ($additions as $proposal) {
            $insertion = (string)$proposal['insertion'];
            if (($proposal['pattern'] ?? '') === 'widget') {
                // L'istanza widget reale viene creata solo ora che la proposta
                // viene applicata (visibile in Elenco Widget Link).
                $created = ALMA_AI_Post_Optimizer::materialize_widget_proposal($proposal);
                if (!empty($created['error'])) { continue; }
                $insertion = $created['shortcode'];
                $inserted_link_ids = array_merge($inserted_link_ids, array_map('absint', (array)($proposal['widget_request']['link_ids'] ?? array())));
            } else {
                $inserted_link_ids[] = absint($proposal['link_id'] ?? 0);
            }
            $content = ALMA_AI_Post_Optimizer::insert_after_paragraph($content, (int)$proposal['paragraph'], $insertion, !empty($proposal['inline']));
            $entry['added']++;
        }

        if ($content === $post->post_content) {
            $entry['note'] = 'Nessuna modifica applicabile';
            self::log_entry($entry);
            return $entry;
        }
        // Solo aggiunte/sostituzioni di shortcode: il testo esistente resta
        // intatto; wp_update_post crea la revisione per il rollback.
        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => $content), true);
        if (is_wp_error($updated)) {
            $entry['error'] = sanitize_text_field($updated->get_error_message());
        } else {
            $entry['updated'] = true;
            // Immagini AI on-demand per i link appena inseriti senza immagine.
            if (class_exists('ALMA_AI_Image_Generator') && !empty($inserted_link_ids)) {
                ALMA_AI_Image_Generator::queue_links($inserted_link_ids);
            }
        }
        self::log_entry($entry);
        return $entry;
    }

    private static function log_entry($entry) {
        $log = (array) get_option(self::OPTION_LOG, array());
        array_unshift($log, $entry);
        update_option(self::OPTION_LOG, array_slice($log, 0, self::MAX_LOG_ENTRIES), false);
    }

    /* ---------------------------------------------------------------------
     * Tab amministrazione
     * ------------------------------------------------------------------ */

    public static function render_settings_tab() {
        $enabled = self::is_enabled();
        $pending_new = count(self::next_posts(200));
        $log = (array) get_option(self::OPTION_LOG, array());
        $next_run = wp_next_scheduled(self::CRON_HOOK);

        echo '<h2>Arricchimento automatico dei post pubblicati</h2>';
        echo '<p class="description" style="max-width:900px;">Ogni giorno l\'AI analizza gli articoli pubblicati e <strong>aggiunge</strong> (mai riscrive) link affiliati coerenti con contenuto e località, secondo le Regole inserimento, aggiornando il post direttamente. Ciclo: prima tutti gli articoli mai analizzati <strong>partendo dai più vecchi</strong> (le prime pubblicazioni sono le meno ottimizzate; i recenti nascono già con link e widget), poi ri-analisi dei più vecchi di analisi oltre il cooldown — nelle ri-analisi l\'AI può anche sostituire link incoerenti. Dove coerente propone anche <strong>un link di tipologia universale</strong> (Assicurazioni, eSIM) e <strong>un widget a metà articolo</strong> per spezzare il testo. Ogni modifica crea una revisione (rollback nativo). Nessuna notifica per articolo: un solo digest Telegram per esecuzione.</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('alma_ai_agent_action');
        echo '<input type="hidden" name="action" value="alma_ai_agent_action"><input type="hidden" name="do" value="save_enricher_settings">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Attiva arricchimento</th><td><label><input type="checkbox" name="'.esc_attr(self::OPTION_ENABLED).'" value="1" '.checked($enabled, true, false).'> Analizza e aggiorna gli articoli in background ogni giorno</label></td></tr>';
        echo '<tr><th scope="row"><label for="'.esc_attr(self::OPTION_DAILY).'">Articoli al giorno</label></th><td><input type="number" min="0" max="50" class="small-text" name="'.esc_attr(self::OPTION_DAILY).'" id="'.esc_attr(self::OPTION_DAILY).'" value="'.esc_attr((string)self::get_daily_limit()).'"> <span class="description">Ogni articolo = 1 chiamata OpenAI. Oggi: '.(int)self::processed_today().' / '.(int)self::get_daily_limit().'</span></td></tr>';
        echo '<tr><th scope="row"><label for="'.esc_attr(self::OPTION_COOLDOWN).'">Cooldown ri-analisi (giorni)</label></th><td><input type="number" min="7" max="365" class="small-text" name="'.esc_attr(self::OPTION_COOLDOWN).'" id="'.esc_attr(self::OPTION_COOLDOWN).'" value="'.esc_attr((string)self::get_cooldown_days()).'"> <span class="description">Un articolo già analizzato non viene rivisto prima di questo intervallo.</span></td></tr>';
        echo '</table><p><button class="button button-primary">Salva impostazioni</button></p></form>';

        echo '<div class="alma-actions-inline" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 16px;">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('alma_ai_enrich_admin');
        echo '<input type="hidden" name="action" value="alma_ai_enrich_run_now"><button class="button" '.disabled(!$enabled, true, false).'>Esegui ora</button></form>';
        echo '<span class="description">In coda: ~'.(int)$pending_new.' articoli · Prossima esecuzione automatica: '.esc_html($next_run ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i') : '—').'</span>';
        echo '</div>';

        // Stato dell'ultima esecuzione: mai più run muti.
        $run_status = (array) get_option(self::OPTION_RUN_STATUS, array());
        if (!empty($run_status['requested_at']) || !empty($run_status['started_at'])) {
            $state = (string) ($run_status['state'] ?? '');
            $color = $state === 'completata' ? '#00a32a' : ($state === 'errore' || $state === 'saltata' ? '#d63638' : '#996800');
            echo '<div style="border:1px solid #dcdcde;border-radius:6px;background:#fff;padding:10px 14px;margin:0 0 16px;max-width:900px;">';
            echo '<p style="margin:0;"><strong>Ultima esecuzione</strong> (' . esc_html((string) ($run_status['trigger'] ?? '')) . '): <span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($state ?: '—') . '</span>';
            if (!empty($run_status['requested_at'])) { echo ' · richiesta ' . esc_html((string) $run_status['requested_at']); }
            if (!empty($run_status['started_at'])) { echo ' · avviata ' . esc_html((string) $run_status['started_at']); }
            if (!empty($run_status['finished_at'])) { echo ' · terminata ' . esc_html((string) $run_status['finished_at']); }
            echo '</p>';
            if (!empty($run_status['detail'])) { echo '<p style="margin:6px 0 0;" class="description">' . esc_html((string) $run_status['detail']) . '</p>'; }
            if ($state === 'in attesa di WP-Cron' && !empty($run_status['requested_at']) && (current_time('timestamp') - strtotime((string) $run_status['requested_at'])) > 180) {
                echo '<p style="margin:6px 0 0;color:#d63638;">⚠️ Richiesta di oltre 3 minuti fa e mai partita: WP-Cron non sta girando (su alcuni hosting il loopback è bloccato). Visita una pagina del sito per innescarlo, oppure configura un cron reale che chiami <code>wp-cron.php</code>.</p>';
            }
            echo '</div>';
        }

        echo '<h3>Report attività (ultimi '.(int)self::MAX_LOG_ENTRIES.')</h3>';
        if (empty($log)) {
            echo '<p class="description">Nessuna attività registrata.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Articolo</th><th>Esito</th><th>Link aggiunti</th><th>Sostituiti</th><th>Note</th></tr></thead><tbody>';
        foreach ($log as $entry) {
            if (!is_array($entry)) { continue; }
            $edit_url = get_edit_post_link((int)($entry['post_id'] ?? 0), 'raw');
            $status = !empty($entry['updated']) ? '<span class="alma-badge is-success">Aggiornato</span>' : (!empty($entry['error']) ? '<span class="alma-badge is-warning">Errore</span>' : '<span class="alma-badge">Nessuna modifica</span>');
            echo '<tr><td>'.esc_html((string)($entry['time'] ?? '')).'</td>';
            echo '<td>'.($edit_url ? '<a href="'.esc_url($edit_url).'">'.esc_html((string)($entry['title'] ?? '')).'</a>' : esc_html((string)($entry['title'] ?? ''))).'</td>';
            echo '<td>'.$status.'</td>';
            echo '<td>'.(int)($entry['added'] ?? 0).'</td><td>'.(int)($entry['replaced'] ?? 0).'</td>';
            echo '<td>'.esc_html((string)($entry['error'] ?: ($entry['note'] ?? ''))).'</td></tr>';
        }
        echo '</tbody></table>';
    }
}
