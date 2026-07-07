<?php
/**
 * Generatore di immagini in evidenza con l'AI (OpenAI gpt-image-1).
 *
 * Molti Link Affiliati (soprattutto GetYourGuide) non hanno un'immagine in
 * evidenza: il runner notturno genera gradualmente una fotografia realistica
 * in stile catalogo di esperienze di viaggio, estremamente coerente con il
 * contenuto del link (titolo, descrizione, località, tipologia).
 *
 * Regole:
 * - le immagini vengono salvate in una cartella dedicata uploads/ai/;
 * - la compressione la fa WordPress: conversione in WebP via WP_Image_Editor
 *   (fallback JPEG se WebP non è supportato dal server);
 * - massimo N immagini al giorno (cap configurabile, default 5) con coda per
 *   click decrescenti, esclusi i link morti (Link Health) e quelli con troppi
 *   tentativi falliti;
 * - ogni chiamata è tracciata in ALMA_AI_Usage_Logger con il costo stimato;
 * - mai sovrascrittura automatica: un link con immagine viene saltato, la
 *   sovrascrittura esiste solo come azione manuale esplicita.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Image_Generator {
    const CRON_HOOK = 'alma_ai_image_generator_run';
    const OPTION_ENABLED = 'alma_ai_images_enabled';
    const OPTION_DAILY = 'alma_ai_images_daily_limit';
    const OPTION_QUALITY = 'alma_ai_images_quality';
    const OPTION_COUNTER = 'alma_ai_images_counter';
    const OPTION_LOG = 'alma_ai_images_log';
    const LOCK_OPTION = 'alma_ai_images_lock';
    const LOCK_TTL = 600;
    const TIME_BUDGET_SECONDS = 150;
    const MAX_LOG_ENTRIES = 60;
    const MAX_FAILS = 3;
    const MODEL = 'gpt-image-1';
    const IMAGE_SIZE = '1536x1024';
    const META_ATTACHMENT = '_alma_ai_image_attachment_id';
    const META_PROMPT = '_alma_ai_image_prompt';
    const META_GENERATED_AT = '_alma_ai_image_generated_at';
    const META_LAST_ERROR = '_alma_ai_image_last_error';
    const META_FAILS = '_alma_ai_image_fails';
    const ATTACHMENT_FLAG = '_alma_ai_generated_image';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_schedule_cron'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
        add_action('admin_post_alma_ai_images_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_alma_ai_images_run_now', array(__CLASS__, 'handle_run_now'));
        add_action('admin_post_alma_ai_images_generate_single', array(__CLASS__, 'handle_generate_single'));
    }

    public static function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $first = strtotime('tomorrow 03:40', current_time('timestamp'));
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
        return max(0, min(20, absint(get_option(self::OPTION_DAILY, 5))));
    }

    public static function get_quality() {
        $quality = sanitize_key(get_option(self::OPTION_QUALITY, 'medium'));
        return in_array($quality, array('low', 'medium', 'high'), true) ? $quality : 'medium';
    }

    public static function generated_today() {
        $counter = get_option(self::OPTION_COUNTER, array());
        return (is_array($counter) && ($counter['date'] ?? '') === current_time('Y-m-d')) ? (int) $counter['count'] : 0;
    }

    private static function bump_counter() {
        $today = current_time('Y-m-d');
        $counter = get_option(self::OPTION_COUNTER, array());
        if (!is_array($counter) || ($counter['date'] ?? '') !== $today) {
            $counter = array('date' => $today, 'count' => 0);
        }
        $counter['count'] = (int) $counter['count'] + 1;
        update_option(self::OPTION_COUNTER, $counter, false);
    }

    /* ---------------------------------------------------------------------
     * Coda di lavoro
     * ------------------------------------------------------------------ */

    /**
     * Link pubblicati senza immagine in evidenza, ordinati per click
     * decrescenti; esclusi i link morti e quelli con troppi fallimenti.
     */
    public static function next_links($limit) {
        $limit = max(1, absint($limit));
        $ids = get_posts(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => 120,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS')),
        ));
        $rows = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if ($id < 1) { continue; }
            if (absint(get_post_meta($id, self::META_FAILS, true)) >= self::MAX_FAILS) { continue; }
            if (class_exists('ALMA_Link_Health_Checker') && ALMA_Link_Health_Checker::is_dead($id)) { continue; }
            $rows[] = array('id' => $id, 'clicks' => (int) get_post_meta($id, '_click_count', true));
        }
        usort($rows, function ($a, $b) { return $b['clicks'] <=> $a['clicks']; });
        return array_slice(wp_list_pluck($rows, 'id'), 0, $limit);
    }

    /**
     * Quanti link pubblicati sono ancora senza immagine in evidenza (per la
     * card di stato: include anche quelli oltre il limite fallimenti).
     */
    public static function pending_count() {
        $query = new WP_Query(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS')),
        ));
        return (int) $query->found_posts;
    }

    /* ---------------------------------------------------------------------
     * Runner notturno (batch interrompibile con catena, mai job invisibili)
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

    public static function run() {
        if (!self::is_enabled() || trim((string) get_option('alma_openai_api_key', '')) === '') { return; }
        if (!self::acquire_lock()) { return; }
        $started = time();
        try {
            $quota = max(0, self::get_daily_limit() - self::generated_today());
            if ($quota < 1) { return; }
            $queue = self::next_links($quota);
            $processed = 0;
            foreach ($queue as $post_id) {
                if ($processed >= $quota) { break; }
                if ((time() - $started) > self::TIME_BUDGET_SECONDS) {
                    // Budget esaurito: si riparte tra poco da dove eravamo.
                    wp_schedule_single_event(time() + 90, self::CRON_HOOK);
                    if (function_exists('spawn_cron')) { spawn_cron(); }
                    break;
                }
                $result = self::generate_for_link($post_id, false);
                if (($result['status'] ?? '') === 'skipped_existing') { continue; }
                $processed++;
                self::bump_counter();
            }
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    /* ---------------------------------------------------------------------
     * Generazione singola
     * ------------------------------------------------------------------ */

    /**
     * Genera l'immagine in evidenza per un link. Ritorna sempre un array
     * con status; success=true solo con immagine creata e associata.
     */
    public static function generate_for_link($post_id, $overwrite = false) {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'affiliate_link') {
            return array('success' => false, 'status' => 'invalid_post', 'error' => __('Link affiliato non valido.', 'affiliate-link-manager-ai'));
        }
        if (has_post_thumbnail($post_id) && !$overwrite) {
            return array('success' => false, 'status' => 'skipped_existing', 'error' => __('Immagine in evidenza già presente: non sovrascritta.', 'affiliate-link-manager-ai'));
        }
        $api_key = trim((string) get_option('alma_openai_api_key', ''));
        if ($api_key === '') {
            return self::record_failure($post_id, 'no_api_key', __('OpenAI non configurato.', 'affiliate-link-manager-ai'), null, 0, '');
        }

        $prompt = self::build_prompt(self::prompt_data($post_id));
        $start = microtime(true);
        $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'model' => self::MODEL,
                'prompt' => $prompt,
                'size' => self::IMAGE_SIZE,
                'quality' => self::get_quality(),
                'n' => 1,
                // PNG dall'API: la compressione WebP la fa WordPress al salvataggio.
                'output_format' => 'png',
            )),
            'timeout' => 120,
        ));
        $rt = (int) round((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return self::record_failure($post_id, 'connection_error', $response->get_error_message(), null, $rt, $prompt);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = sanitize_text_field((string) ($data['error']['message'] ?? sprintf(__('Errore OpenAI (HTTP %d).', 'affiliate-link-manager-ai'), $code)));
            return self::record_failure($post_id, 'api_error', $message, is_array($data['usage'] ?? null) ? $data['usage'] : null, $rt, $prompt);
        }
        $b64 = (string) ($data['data'][0]['b64_json'] ?? '');
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : null;
        if ($b64 === '') {
            return self::record_failure($post_id, 'empty_image', __('Risposta OpenAI senza immagine.', 'affiliate-link-manager-ai'), $usage, $rt, $prompt);
        }
        $binary = base64_decode($b64, true);
        if ($binary === false || strlen($binary) < 1000) {
            return self::record_failure($post_id, 'invalid_image', __('Immagine ricevuta non valida.', 'affiliate-link-manager-ai'), $usage, $rt, $prompt);
        }

        // La chiamata è riuscita (e ha un costo): loggala subito, poi salva.
        $cost = self::estimate_image_cost($usage);
        self::log_usage($post_id, true, '', $usage, $cost, $rt);

        $attachment_id = self::save_image_attachment($post_id, $binary, $prompt);
        if (is_wp_error($attachment_id)) {
            return self::record_failure($post_id, 'save_failed', $attachment_id->get_error_message(), null, $rt, $prompt, false);
        }

        update_post_meta($post_id, self::META_ATTACHMENT, absint($attachment_id));
        update_post_meta($post_id, self::META_PROMPT, sanitize_textarea_field($prompt));
        update_post_meta($post_id, self::META_GENERATED_AT, current_time('mysql'));
        delete_post_meta($post_id, self::META_LAST_ERROR);
        delete_post_meta($post_id, self::META_FAILS);

        $result = array('success' => true, 'status' => 'generated', 'attachment_id' => (int) $attachment_id, 'cost' => $cost, 'response_time' => $rt);
        self::log_entry($post_id, $result);
        do_action('alma_ai_image_generated', $post_id, (int) $attachment_id, $result);
        return $result;
    }

    private static function record_failure($post_id, $status, $message, $usage, $rt, $prompt, $log_usage = true) {
        $message = sanitize_text_field((string) $message);
        update_post_meta($post_id, self::META_LAST_ERROR, $message);
        update_post_meta($post_id, self::META_FAILS, absint(get_post_meta($post_id, self::META_FAILS, true)) + 1);
        if ($log_usage) {
            self::log_usage($post_id, false, $message, $usage, self::estimate_image_cost($usage), $rt);
        }
        $result = array('success' => false, 'status' => sanitize_key($status), 'error' => $message, 'response_time' => (int) $rt);
        self::log_entry($post_id, $result);
        return $result;
    }

    private static function log_usage($post_id, $success, $error, $usage, $cost, $rt) {
        if (!class_exists('ALMA_AI_Usage_Logger')) { return; }
        ALMA_AI_Usage_Logger::log(array(
            'model' => self::MODEL,
            'task' => 'ai_image_generation',
            'input_tokens' => is_array($usage) ? absint($usage['input_tokens'] ?? 0) : null,
            'output_tokens' => is_array($usage) ? absint($usage['output_tokens'] ?? 0) : null,
            'estimated_cost' => $cost,
            'response_time' => (int) $rt,
            'success' => (bool) $success,
            'error' => (string) $error,
            'reference_id' => 'link:' . absint($post_id),
        ));
    }

    /* ---------------------------------------------------------------------
     * Prompt (puro e testabile)
     * ------------------------------------------------------------------ */

    private static function prompt_data($post_id) {
        $description = trim(wp_strip_all_tags((string) get_post($post_id)->post_content));
        if ($description === '') {
            $description = trim(wp_strip_all_tags((string) get_post_meta($post_id, '_alma_ai_context', true)));
        }
        $types = array();
        $terms = get_the_terms($post_id, 'link_type');
        if (is_array($terms)) {
            foreach ($terms as $term) { $types[] = (string) $term->name; }
        }
        return array(
            'title' => (string) get_the_title($post_id),
            'description' => $description,
            'city' => (string) get_post_meta($post_id, '_alma_geo_primary_city', true),
            'region' => (string) get_post_meta($post_id, '_alma_geo_primary_region', true),
            'country' => (string) get_post_meta($post_id, '_alma_geo_primary_country', true),
            'location_name' => (string) get_post_meta($post_id, '_alma_geo_primary_name', true),
            'types' => $types,
        );
    }

    /**
     * Costruisce il prompt fotografico dal contenuto del link. Funzione pura:
     * niente WP, così è verificabile con test standalone.
     */
    public static function build_prompt($data) {
        $data = is_array($data) ? $data : array();
        $title = trim(preg_replace('/\s+/', ' ', (string) ($data['title'] ?? '')));
        $description = trim(preg_replace('/\s+/', ' ', (string) ($data['description'] ?? '')));
        if (function_exists('mb_substr')) {
            if (mb_strlen($description) > 320) { $description = mb_substr($description, 0, 320) . '…'; }
        } elseif (strlen($description) > 320) {
            $description = substr($description, 0, 320) . '…';
        }
        $place = array();
        foreach (array('location_name', 'city', 'region', 'country') as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '') { continue; }
            $exists = false;
            foreach ($place as $seen) {
                if (strcasecmp($seen, $value) === 0) { $exists = true; break; }
            }
            if (!$exists) { $place[] = $value; }
        }
        $types = array();
        foreach ((array) ($data['types'] ?? array()) as $type) {
            $type = trim((string) $type);
            if ($type !== '') { $types[] = $type; }
        }

        $lines = array();
        $lines[] = 'Fotografia di viaggio realistica che rappresenta questa esperienza turistica: "' . $title . '".';
        if ($description !== '') {
            $lines[] = 'Dettagli dell\'esperienza: ' . $description;
        }
        if (!empty($place)) {
            $lines[] = 'Luogo reale: ' . implode(', ', $place) . '. La scena deve essere riconoscibile e coerente con questo luogo, con elementi visivi tipici (architettura, paesaggio, atmosfera).';
        }
        if (!empty($types)) {
            $lines[] = 'Tipo di esperienza: ' . implode(', ', $types) . '.';
        }
        $lines[] = 'Stile: fotografia professionale da catalogo di esperienze di viaggio, come le foto di copertina di GetYourGuide. Scatto reale e credibile, luce naturale (ora dorata o piena luce diurna), colori vividi ma naturali, forte profondità, composizione orizzontale con soggetto chiaro, atmosfera invitante che fa venire voglia di prenotare.';
        $lines[] = 'Vietato assolutamente: testi, scritte, numeri, loghi, watermark, cornici, collage, volti riconoscibili in primo piano, aspetto da illustrazione, rendering 3D o cartone animato.';
        return implode("\n", $lines);
    }

    /**
     * Costo stimato USD di una generazione gpt-image-1 dai token di usage
     * (testo input $5/1M, immagini input $10/1M, immagine output $40/1M).
     * Funzione pura; null se usage assente.
     */
    public static function estimate_image_cost($usage) {
        if (!is_array($usage)) { return null; }
        $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : array();
        $text_in = absint($details['text_tokens'] ?? ($usage['input_tokens'] ?? 0));
        $image_in = absint($details['image_tokens'] ?? 0);
        $out = absint($usage['output_tokens'] ?? 0);
        if ($text_in === 0 && $image_in === 0 && $out === 0) { return null; }
        return round(($text_in * 5.0 + $image_in * 10.0 + $out * 40.0) / 1000000, 6);
    }

    /* ---------------------------------------------------------------------
     * Salvataggio in uploads/ai/ con conversione WebP di WordPress
     * ------------------------------------------------------------------ */

    private static function save_image_attachment($post_id, $binary, $prompt) {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('uploads_error', sanitize_text_field((string) $uploads['error']));
        }
        $dir = trailingslashit($uploads['basedir']) . 'ai';
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('mkdir_failed', __('Impossibile creare la cartella uploads/ai.', 'affiliate-link-manager-ai'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam('alma-ai-image');
        if (!$tmp || file_put_contents($tmp, $binary) === false) {
            return new WP_Error('tmp_failed', __('Impossibile scrivere il file temporaneo.', 'affiliate-link-manager-ai'));
        }
        if (!@getimagesize($tmp)) {
            @unlink($tmp);
            return new WP_Error('invalid_image', __('Il file generato non è un\'immagine valida.', 'affiliate-link-manager-ai'));
        }

        $slug = sanitize_title(get_the_title($post_id));
        $base = 'ai-' . ($slug !== '' ? $slug . '-' : '') . $post_id;
        $mime = 'image/webp';
        $path = '';
        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            // Nessun editor immagini sul server: si salva il PNG originale.
            $filename = wp_unique_filename($dir, $base . '.png');
            $path = $dir . '/' . $filename;
            $mime = 'image/png';
            if (!@copy($tmp, $path)) {
                @unlink($tmp);
                return new WP_Error('copy_failed', __('Impossibile salvare l\'immagine in uploads/ai.', 'affiliate-link-manager-ai'));
            }
        } else {
            $editor->set_quality(82);
            $filename = wp_unique_filename($dir, $base . '.webp');
            $saved = $editor->save($dir . '/' . $filename, 'image/webp');
            if (is_wp_error($saved)) {
                // WebP non supportato dal server: fallback JPEG.
                $filename = wp_unique_filename($dir, $base . '.jpg');
                $saved = $editor->save($dir . '/' . $filename, 'image/jpeg');
                $mime = 'image/jpeg';
            }
            if (is_wp_error($saved)) {
                @unlink($tmp);
                return $saved;
            }
            $path = !empty($saved['path']) ? (string) $saved['path'] : $dir . '/' . $filename;
            if (!empty($saved['mime-type'])) { $mime = (string) $saved['mime-type']; }
        }
        @unlink($tmp);

        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $mime,
            'post_title' => get_the_title($post_id),
            'post_status' => 'inherit',
            'guid' => trailingslashit($uploads['baseurl']) . 'ai/' . wp_basename($path),
        ), $path, $post_id, true);
        if (is_wp_error($attachment_id)) {
            @unlink($path);
            return $attachment_id;
        }
        $attachment_id = absint($attachment_id);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $path));
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field(get_the_title($post_id)));
        update_post_meta($attachment_id, self::ATTACHMENT_FLAG, '1');
        update_post_meta($attachment_id, self::META_PROMPT, sanitize_textarea_field($prompt));
        update_post_meta($attachment_id, '_alma_media_origin', 'ai_generated');
        update_post_meta($attachment_id, '_alma_media_role', 'affiliate_featured_image');
        update_post_meta($attachment_id, '_alma_related_post_id', absint($post_id));
        update_post_meta($attachment_id, '_alma_related_post_type', 'affiliate_link');

        if (!set_post_thumbnail($post_id, $attachment_id)) {
            return new WP_Error('set_thumbnail_failed', __('Immagine creata ma associazione come immagine in evidenza fallita.', 'affiliate-link-manager-ai'));
        }
        return $attachment_id;
    }

    /* ---------------------------------------------------------------------
     * Report attività
     * ------------------------------------------------------------------ */

    private static function log_entry($post_id, $result) {
        $log = (array) get_option(self::OPTION_LOG, array());
        array_unshift($log, array(
            'time' => current_time('mysql'),
            'post_id' => absint($post_id),
            'title' => sanitize_text_field((string) get_the_title($post_id)),
            'status' => sanitize_key((string) ($result['status'] ?? '')),
            'attachment_id' => absint($result['attachment_id'] ?? 0),
            'cost' => isset($result['cost']) && $result['cost'] !== null ? (float) $result['cost'] : null,
            'error' => sanitize_text_field((string) ($result['error'] ?? '')),
        ));
        update_option(self::OPTION_LOG, array_slice($log, 0, self::MAX_LOG_ENTRIES), false);
    }

    /* ---------------------------------------------------------------------
     * Admin: tab "Immagini AI" + azioni
     * ------------------------------------------------------------------ */

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_images_admin');
        update_option(self::OPTION_ENABLED, empty($_POST[self::OPTION_ENABLED]) ? '0' : '1', false);
        update_option(self::OPTION_DAILY, max(0, min(20, absint($_POST[self::OPTION_DAILY] ?? 5))), false);
        $quality = sanitize_key($_POST[self::OPTION_QUALITY] ?? 'medium');
        update_option(self::OPTION_QUALITY, in_array($quality, array('low', 'medium', 'high'), true) ? $quality : 'medium', false);
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Impostazioni Immagini AI salvate.', 'affiliate-link-manager-ai')), 120);
        self::redirect_back();
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_images_admin');
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        if (function_exists('spawn_cron')) { spawn_cron(); }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Generazione immagini avviata in background: il report comparirà qui sotto tra qualche minuto (1-2 minuti per immagine).', 'affiliate-link-manager-ai')), 120);
        self::redirect_back();
    }

    public static function handle_generate_single() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('alma_ai_images_admin');
        $post_id = absint($_POST['link_id'] ?? 0);
        $overwrite = !empty($_POST['overwrite_existing']);
        if ($post_id < 1 || get_post_type($post_id) !== 'affiliate_link') {
            set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), array('type' => 'error', 'message' => __('ID non valido: indica l\'ID di un Link Affiliato.', 'affiliate-link-manager-ai')), 120);
            self::redirect_back();
        }
        // Azione manuale esplicita: azzera i fallimenti e conta nel cap giornaliero.
        delete_post_meta($post_id, self::META_FAILS);
        $result = self::generate_for_link($post_id, $overwrite);
        if (($result['status'] ?? '') !== 'skipped_existing') { self::bump_counter(); }
        if (!empty($result['success'])) {
            $message = sprintf(__('Immagine generata per "%s" (costo stimato %s USD).', 'affiliate-link-manager-ai'), get_the_title($post_id), $result['cost'] !== null ? number_format((float) $result['cost'], 4) : 'n/d');
            $notice = array('type' => 'success', 'message' => $message);
        } else {
            $notice = array('type' => 'error', 'message' => sprintf(__('Generazione non riuscita: %s', 'affiliate-link-manager-ai'), (string) ($result['error'] ?? '')));
        }
        set_transient('alma_ai_agent_admin_notice_' . get_current_user_id(), $notice, 120);
        self::redirect_back();
    }

    private static function redirect_back() {
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=affiliate_link&page=alma-ai-content-agent&tab=immagini-ai'));
        exit;
    }

    public static function render_settings_tab() {
        $enabled = self::is_enabled();
        $pending = self::pending_count();
        $today = self::generated_today();
        $limit = self::get_daily_limit();
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        $openai_ready = trim((string) get_option('alma_openai_api_key', '')) !== '';
        $log = (array) get_option(self::OPTION_LOG, array());
        $totals = self::usage_totals();

        echo '<h2>Immagini in evidenza generate dall\'AI</h2>';
        echo '<p class="description" style="max-width:900px;">Ogni notte l\'AI (OpenAI ' . esc_html(self::MODEL) . ') genera gradualmente le immagini in evidenza mancanti dei Link Affiliati pubblicati: fotografie realistiche in stile catalogo di esperienze (ispirate a GetYourGuide), coerenti con titolo, descrizione, località e tipologia del link. Le immagini vengono salvate in <code>uploads/ai/</code> e convertite in <strong>WebP</strong> da WordPress. Priorità ai link con più click; esclusi i link morti. Nessuna immagine esistente viene mai sovrascritta automaticamente.</p>';
        if (!$openai_ready) {
            echo '<div class="notice notice-warning inline"><p>OpenAI non è configurata: imposta la API key nelle impostazioni AI del plugin.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_ai_images_admin');
        echo '<input type="hidden" name="action" value="alma_ai_images_save_settings">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Attiva generazione</th><td><label><input type="checkbox" name="' . esc_attr(self::OPTION_ENABLED) . '" value="1" ' . checked($enabled, true, false) . '> Genera in background ogni notte le immagini mancanti</label></td></tr>';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_DAILY) . '">Immagini al giorno</label></th><td><input type="number" min="0" max="20" class="small-text" name="' . esc_attr(self::OPTION_DAILY) . '" id="' . esc_attr(self::OPTION_DAILY) . '" value="' . esc_attr((string) $limit) . '"> <span class="description">Oggi: ' . (int) $today . ' / ' . (int) $limit . '. Ogni immagine è una chiamata OpenAI a pagamento.</span></td></tr>';
        echo '<tr><th scope="row"><label for="' . esc_attr(self::OPTION_QUALITY) . '">Qualità immagine</label></th><td><select name="' . esc_attr(self::OPTION_QUALITY) . '" id="' . esc_attr(self::OPTION_QUALITY) . '">';
        foreach (array('low' => 'Bassa (≈ $0.02)', 'medium' => 'Media (≈ $0.07) — consigliata', 'high' => 'Alta (≈ $0.30)') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected(self::get_quality(), $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <span class="description">Costo indicativo per immagine ' . esc_html(self::IMAGE_SIZE) . '; il costo reale è tracciato per ogni chiamata.</span></td></tr>';
        echo '</table><p><button class="button button-primary">Salva impostazioni</button></p></form>';

        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 16px;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('alma_ai_images_admin');
        echo '<input type="hidden" name="action" value="alma_ai_images_run_now"><button class="button" ' . disabled(!$enabled || !$openai_ready, true, false) . '>Esegui ora</button></form>';
        echo '<span class="description">Link senza immagine: <strong>' . (int) $pending . '</strong> · Prossima esecuzione automatica: ' . esc_html($next_run ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i') : '—') . ' · Costo totale immagini AI: <strong>$' . esc_html(number_format((float) $totals['cost'], 2)) . '</strong> (' . (int) $totals['count'] . ' generazioni)</span>';
        echo '</div>';

        echo '<h3>Genera per un link specifico</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;">';
        wp_nonce_field('alma_ai_images_admin');
        echo '<input type="hidden" name="action" value="alma_ai_images_generate_single">';
        echo '<label>ID Link Affiliato<br><input type="number" min="1" class="small-text" name="link_id" required></label>';
        echo '<label style="padding-bottom:6px;"><input type="checkbox" name="overwrite_existing" value="1"> Sovrascrivi immagine esistente</label>';
        echo '<button class="button" ' . disabled(!$openai_ready, true, false) . '>Genera immagine</button>';
        echo '<span class="description" style="padding-bottom:8px;">Esecuzione immediata (attendi 1-2 minuti); azzera i tentativi falliti del link.</span></form>';

        echo '<h3>Report attività (ultime ' . (int) self::MAX_LOG_ENTRIES . ')</h3>';
        if (empty($log)) {
            echo '<p class="description">Nessuna attività registrata.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Anteprima</th><th>Link</th><th>Esito</th><th>Costo (USD)</th><th>Note</th></tr></thead><tbody>';
        foreach ($log as $entry) {
            if (!is_array($entry)) { continue; }
            $post_id = absint($entry['post_id'] ?? 0);
            $attachment_id = absint($entry['attachment_id'] ?? 0);
            $edit_url = $post_id ? get_edit_post_link($post_id, 'raw') : '';
            $thumb = $attachment_id ? wp_get_attachment_image($attachment_id, array(90, 60)) : '—';
            $ok = ($entry['status'] ?? '') === 'generated';
            echo '<tr><td>' . esc_html((string) ($entry['time'] ?? '')) . '</td>';
            echo '<td>' . ($ok ? $thumb : '—') . '</td>';
            echo '<td>' . ($edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html((string) ($entry['title'] ?? '')) . '</a>' : esc_html((string) ($entry['title'] ?? ''))) . ' <small>#' . (int) $post_id . '</small></td>';
            echo '<td>' . ($ok ? '<span class="alma-badge is-success">Generata</span>' : '<span class="alma-badge is-warning">' . esc_html((string) ($entry['status'] ?? 'errore')) . '</span>') . '</td>';
            echo '<td>' . (isset($entry['cost']) && $entry['cost'] !== null ? esc_html(number_format((float) $entry['cost'], 4)) : '—') . '</td>';
            echo '<td>' . esc_html((string) ($entry['error'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function usage_totals() {
        global $wpdb;
        $totals = array('cost' => 0.0, 'count' => 0);
        if (!class_exists('ALMA_AI_Usage_Logger')) { return $totals; }
        $table = ALMA_AI_Usage_Logger::table_name();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { return $totals; }
        $row = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(estimated_cost),0) AS cost, COUNT(*) AS cnt FROM {$table} WHERE task=%s AND success=1", 'ai_image_generation'), ARRAY_A);
        if (is_array($row)) {
            $totals['cost'] = (float) $row['cost'];
            $totals['count'] = (int) $row['cnt'];
        }
        return $totals;
    }
}
