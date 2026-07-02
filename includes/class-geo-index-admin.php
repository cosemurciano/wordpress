<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin page for the Geo Index foundation module.
 */
class ALMA_Geo_Index_Admin {
    const MENU_SLUG = 'alma-geo-index';
    const PREVIEW_TRANSIENT_PREFIX = 'alma_geo_index_preview_';

    private $store;
    private $importer;
    private $geocoder;
    private $affiliate_importer;
    private $job_store;
    private $auto_indexer;
    private $notice = '';

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
        $this->importer = new ALMA_Geo_Index_Importer($this->store);
        $this->geocoder = new ALMA_Geo_Index_Geocoder($this->store);
        $this->affiliate_importer = new ALMA_Geo_Index_Affiliate_Link_Importer($this->store);
        $this->job_store = new ALMA_Geo_Index_Job_Store();
        $this->auto_indexer = new ALMA_Geo_Auto_Indexer($this->store);
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_alma_geo_affiliate_import_status', array($this, 'ajax_affiliate_import_status'));
        add_action('wp_ajax_alma_geo_affiliate_import_batch', array($this, 'ajax_affiliate_import_batch'));
        add_action('wp_ajax_alma_geo_get_affiliate_link_import_job_status', array($this, 'ajax_affiliate_import_status'));
        add_action('wp_ajax_alma_geo_process_affiliate_link_import_job', array($this, 'ajax_affiliate_import_batch'));
        add_action('wp_ajax_alma_geo_cancel_affiliate_link_import_job', array($this, 'ajax_cancel_affiliate_import_job'));
        add_action('wp_ajax_alma_geo_pause_affiliate_link_import_job', array($this, 'ajax_pause_affiliate_import_job'));
        add_action('wp_ajax_alma_geo_resume_affiliate_link_import_job', array($this, 'ajax_resume_affiliate_import_job'));
        add_action('wp_ajax_alma_geo_geocode_affiliate_locations_batch', array($this, 'ajax_geocode_affiliate_locations_batch'));
        add_action('wp_ajax_alma_geo_auto_index_batch', array($this, 'ajax_auto_index_batch'));
        add_action('wp_ajax_alma_geo_auto_suggestions', array($this, 'ajax_auto_suggestions'));
        add_action('wp_ajax_alma_geo_auto_suggestion_action', array($this, 'ajax_auto_suggestion_action'));
        add_action('admin_post_alma_geo_download_geocoding_csv', array($this, 'download_geocoding_csv'));
        add_action('admin_post_alma_geo_download_geocoding_json', array($this, 'download_geocoding_json'));
    }

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Import GEO Link Affiliati', 'affiliate-link-manager-ai'),
            __('Import GEO Link Affiliati', 'affiliate-link-manager-ai'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_page')
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        // Alias dei vecchi slug: i link esistenti (bottoni, bookmark, form)
        // continuano a funzionare dopo il raggruppamento delle tab.
        $aliases = array(
            'dashboard' => 'overview',
            'coverage' => 'overview',
            'affiliate_import' => 'import',
            'geocoding' => 'settings',
            'log' => 'settings',
        );
        if (isset($aliases[$tab])) {
            $tab = $aliases[$tab];
        }
        if (!in_array($tab, array('overview', 'import', 'locations', 'settings'), true)) {
            $tab = 'overview';
        }
        $this->handle_actions($tab);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Indice Geografico', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php esc_html_e('Collega articoli, pagine e Link Affiliati a località geografiche. L\'associazione e il geocoding Google avvengono automaticamente all\'import e alla creazione dei contenuti; qui trovi copertura, revisione, località e configurazione.', 'affiliate-link-manager-ai'); ?></p>
            <?php echo $this->notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <nav class="nav-tab-wrapper">
                <?php $this->tab_link('overview', __('Panoramica', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('import', __('Import', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('locations', __('Località', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('settings', __('Impostazioni & Log', 'affiliate-link-manager-ai'), $tab); ?>
            </nav>
            <?php
            if ($tab === 'import') {
                $this->render_import_group_tab();
            } elseif ($tab === 'locations') {
                $this->render_locations_tab();
            } elseif ($tab === 'settings') {
                $this->render_settings_group_tab();
            } else {
                $this->render_overview_tab();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Panoramica: stato geocoding automatico + dashboard + copertura/revisione.
     * È il centro operativo del workflow quotidiano.
     */
    private function render_overview_tab() {
        $this->render_auto_geocoding_status();
        $this->render_coverage_tab();
        $this->render_dashboard_tab();
    }

    /**
     * Import: i due flussi (Link Affiliati e articoli) raggruppati in un'unica
     * tab con sotto-navigazione.
     */
    private function render_import_group_tab() {
        $flow = isset($_GET['flow']) ? sanitize_key($_GET['flow']) : 'links';
        if (!in_array($flow, array('links', 'articles'), true)) {
            $flow = 'links';
        }
        $base = admin_url('edit.php?post_type=affiliate_link&page=' . self::MENU_SLUG . '&tab=import');
        ?>
        <ul class="subsubsub" style="margin-bottom:12px;">
            <li><a href="<?php echo esc_url($base . '&flow=links'); ?>" <?php echo $flow === 'links' ? 'class="current"' : ''; ?>><?php esc_html_e('Import GEO Link Affiliati', 'affiliate-link-manager-ai'); ?></a> |</li>
            <li><a href="<?php echo esc_url($base . '&flow=articles'); ?>" <?php echo $flow === 'articles' ? 'class="current"' : ''; ?>><?php esc_html_e('Import record articoli', 'affiliate-link-manager-ai'); ?></a></li>
        </ul>
        <div style="clear:both;"></div>
        <?php
        if ($flow === 'articles') {
            $this->render_import_tab();
        } else {
            $this->render_affiliate_import_tab();
        }
    }

    /**
     * Impostazioni & Log: configurazione geocoding (con automatismo), strumenti
     * manuali di fallback e log/ultimi report raggruppati.
     */
    private function render_settings_group_tab() {
        $this->render_geocoding_tab();
        echo '<hr style="margin:24px 0;">';
        echo '<h2>' . esc_html__('Log / ultimi import', 'affiliate-link-manager-ai') . '</h2>';
        $this->render_log_tab();
    }

    /**
     * Pannello stato del geocoding automatico in Panoramica.
     */
    private function render_auto_geocoding_status() {
        $enabled = ALMA_Geo_Geocoding_Queue::is_enabled();
        $pending = ALMA_Geo_Geocoding_Queue::count_pending();
        $next = ALMA_Geo_Geocoding_Queue::next_run_timestamp();
        $last = ALMA_Geo_Geocoding_Queue::get_last_run_report();
        $api_key_missing = trim((string) get_option('alma_geo_google_maps_api_key', '')) === '';
        $settings_url = admin_url('edit.php?post_type=affiliate_link&page=' . self::MENU_SLUG . '&tab=settings');
        ?>
        <div class="card" style="max-width:960px;">
            <h2><?php esc_html_e('Geocoding automatico', 'affiliate-link-manager-ai'); ?></h2>
            <?php if ($api_key_missing) : ?>
                <p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> <?php esc_html_e('API key Google Maps non configurata: le località restano in attesa finché non la salvi nelle impostazioni.', 'affiliate-link-manager-ai'); ?> <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Vai alle impostazioni', 'affiliate-link-manager-ai'); ?></a></p>
            <?php elseif (!$enabled) : ?>
                <p><span class="dashicons dashicons-controls-pause" style="color:#996800;"></span> <?php esc_html_e('Geocoding automatico disattivato: le località nuove restano pending finché non lanci un batch manuale o riattivi l\'automatismo.', 'affiliate-link-manager-ai'); ?> <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Vai alle impostazioni', 'affiliate-link-manager-ai'); ?></a></p>
            <?php else : ?>
                <p><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> <?php esc_html_e('Attivo: le località associate da import, API, metabox e auto-indicizzazione vengono geocodificate automaticamente in background.', 'affiliate-link-manager-ai'); ?></p>
            <?php endif; ?>
            <ul style="margin-left:1.4em;list-style:disc;">
                <li><?php printf(esc_html__('Località in attesa di geocoding: %s', 'affiliate-link-manager-ai'), '<strong>' . esc_html(number_format_i18n($pending)) . '</strong>'); ?></li>
                <?php if ($next) : ?>
                    <li><?php printf(esc_html__('Prossima esecuzione automatica: %s', 'affiliate-link-manager-ai'), esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'd/m/Y H:i:s'))); ?></li>
                <?php elseif ($enabled && $pending > 0) : ?>
                    <li><?php esc_html_e('Prossima esecuzione: alla prossima associazione di località (o avvia un batch manuale dalle impostazioni).', 'affiliate-link-manager-ai'); ?></li>
                <?php endif; ?>
                <?php if (!empty($last['ran_at'])) : ?>
                    <li><?php printf(esc_html__('Ultima esecuzione automatica: %1$s — %2$d processate, %3$d verificate, %4$d ambigue, %5$d fallite.', 'affiliate-link-manager-ai'), esc_html($last['ran_at']), (int) ($last['processed'] ?? 0), (int) ($last['verified'] ?? 0), (int) ($last['ambiguous'] ?? 0), (int) ($last['failed'] ?? 0)); ?></li>
                <?php endif; ?>
                <?php if (!empty($last['configuration_error'])) : ?>
                    <li style="color:#d63638;"><?php esc_html_e('Ultimo run interrotto: Google ha rifiutato la richiesta (REQUEST_DENIED). Verifica API key e abilitazione della Geocoding API nelle impostazioni.', 'affiliate-link-manager-ai'); ?></li>
                <?php endif; ?>
                <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && $pending > 0) : ?>
                    <li style="color:#996800;"><?php esc_html_e('WP-Cron è disabilitato su questo sito (DISABLE_WP_CRON): assicurati che il cron di sistema chiami wp-cron.php, oppure usa il pulsante qui sotto.', 'affiliate-link-manager-ai'); ?></li>
                <?php endif; ?>
            </ul>
            <?php if (!$api_key_missing && $pending > 0) : ?>
                <?php $this->render_geocoding_button('drain_geocoding_now', __('Geocodifica ora le località in attesa', 'affiliate-link-manager-ai')); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_actions($tab) {
        if (empty($_POST['alma_geo_index_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['alma_geo_index_action']));
        if ($tab === 'import') {
            // I due flussi convivono nella stessa tab: le action articoli
            // (preview/import) e quelle Link Affiliati hanno nomi disgiunti.
            if (in_array($action, array('preview', 'import'), true)) {
                check_admin_referer('alma_geo_index_import');
                if ($action === 'preview') {
                    $this->handle_preview_upload();
                } else {
                    $this->handle_import_submit();
                }
                return;
            }
            check_admin_referer('alma_geo_affiliate_import');
            $this->handle_affiliate_import_action($action);
            return;
        }
        if (in_array($tab, array('settings', 'locations', 'overview'), true)) {
            check_admin_referer('alma_geo_index_geocoding');
            $this->handle_geocoding_action($action);
        }
    }

    private function handle_geocoding_action($action) {
        if (!current_user_can('manage_options')) {
            $this->notice_error(__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
            return;
        }
        if ($action === 'save_geocoding_settings') {
            $api_key = isset($_POST['alma_geo_google_maps_api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['alma_geo_google_maps_api_key']))) : '';
            update_option('alma_geo_geocoding_provider', 'google', false);
            if ($api_key !== '') {
                update_option('alma_geo_google_maps_api_key', $api_key, false);
            }
            update_option('alma_geo_geocoding_batch_size', max(1, min(50, absint($_POST['alma_geo_geocoding_batch_size'] ?? 20))), false);
            update_option('alma_geo_geocoding_timeout', max(1, min(30, absint($_POST['alma_geo_geocoding_timeout'] ?? 15))), false);
            update_option('alma_geo_geocoding_delay_ms', max(0, min(5000, absint($_POST['alma_geo_geocoding_delay_ms'] ?? 200))), false);
            update_option('alma_geo_geocoding_overwrite_verified', !empty($_POST['alma_geo_geocoding_overwrite_verified']) ? 'yes' : 'no', false);
            update_option('alma_geo_geocoding_country_bias', sanitize_text_field(wp_unslash($_POST['alma_geo_geocoding_country_bias'] ?? '')), false);
            update_option(ALMA_Geo_Geocoding_Queue::ENABLED_OPTION, !empty($_POST['alma_geo_auto_geocoding']) ? 'yes' : 'no', false);
            // Se l'automatismo è attivo e ci sono località in attesa, riparte subito.
            if (ALMA_Geo_Geocoding_Queue::is_enabled() && ALMA_Geo_Geocoding_Queue::count_pending() > 0) {
                ALMA_Geo_Geocoding_Queue::maybe_schedule();
            }
            $this->notice_success($api_key === '' && get_option('alma_geo_google_maps_api_key', '') === '' ? __('Impostazioni salvate. API key non configurata.', 'affiliate-link-manager-ai') : __('Impostazioni geocoding salvate.', 'affiliate-link-manager-ai'));
            return;
        }
        if ($action === 'test_api_key') {
            if (!$this->geocoder->is_enabled()) {
                $this->notice_error(__('API key non configurata.', 'affiliate-link-manager-ai'));
                return;
            }
            $provider = new ALMA_Geo_Index_Google_Geocoder(get_option('alma_geo_google_maps_api_key', ''), (int) get_option('alma_geo_geocoding_timeout', 15));
            $test = $provider->geocode('Palermo, Sicilia, Italy', array('region' => get_option('alma_geo_geocoding_country_bias', '')));
            if (!empty($test['success'])) {
                $this->notice_success(__('Test API key completato: Google Geocoding ha risposto correttamente.', 'affiliate-link-manager-ai'));
            } else {
                $this->notice_error(sprintf(__('Test API key fallito: %s', 'affiliate-link-manager-ai'), sanitize_text_field($test['message'] ?? $test['status'] ?? 'errore')));
            }
            return;
        }
        if ($action === 'batch_pending' || $action === 'retry_failed') {
            if (!$this->geocoder->is_enabled()) {
                $this->notice_error(__('API key non configurata. Salva una chiave Google Maps prima di avviare il batch.', 'affiliate-link-manager-ai'));
                return;
            }
            $settings = $this->geocoder->get_settings();
            $report = $this->geocoder->geocode_batch($settings['batch_size'], $action === 'retry_failed' ? 'failed' : 'pending');
            if (!empty($report['locked'])) {
                $this->notice_error(__('Un\'altra elaborazione di geocoding è già in corso: il batch non è stato avviato. Riprova tra qualche minuto.', 'affiliate-link-manager-ai'));
                return;
            }
            if (!empty($report['configuration_error'])) {
                $this->notice_error(__('Google ha rifiutato la richiesta (REQUEST_DENIED): verifica API key e abilitazione della Geocoding API. Il batch è stato interrotto.', 'affiliate-link-manager-ai'));
            }
            $this->notice_success(sprintf(__('Batch geocoding completato: %1$d processate, %2$d verified, %3$d ambiguous, %4$d manual_required, %5$d failed. Oggetti collegati sincronizzati: %6$d, errori sync: %7$d.', 'affiliate-link-manager-ai'), $report['processed'], $report['verified'], $report['ambiguous'], $report['manual_required'], $report['failed'], $report['linked_objects_synced'], $report['linked_objects_sync_errors']));
            return;
        }
        if ($action === 'drain_geocoding_now') {
            if (!$this->geocoder->is_enabled()) {
                $this->notice_error(__('API key non configurata: impossibile geocodificare.', 'affiliate-link-manager-ai'));
                return;
            }
            ALMA_Geo_Geocoding_Queue::drain(true);
            $last = ALMA_Geo_Geocoding_Queue::get_last_run_report();
            $remaining = ALMA_Geo_Geocoding_Queue::count_pending();
            if (!empty($last['locked'])) {
                $this->notice_error(__('Un\'altra elaborazione di geocoding è in corso: riprova tra qualche minuto.', 'affiliate-link-manager-ai'));
                return;
            }
            $this->notice_success(sprintf(__('Geocoding eseguito ora: %1$d processate, %2$d verificate, %3$d ambigue, %4$d fallite. Località ancora in attesa: %5$d%6$s.', 'affiliate-link-manager-ai'), (int) ($last['processed'] ?? 0), (int) ($last['verified'] ?? 0), (int) ($last['ambiguous'] ?? 0), (int) ($last['failed'] ?? 0), $remaining, $remaining > 0 ? __(' (il resto prosegue in automatico)', 'affiliate-link-manager-ai') : ''));
            return;
        }
        if ($action === 'sync_verified_locations') {
            $report = $this->store->sync_all_verified_locations_to_objects();
            update_option('alma_geo_geocoding_last_sync_report', $report, false);
            $this->notice_success(sprintf(__('Risincronizzazione completata: %1$d località verified, %2$d oggetti trovati, %3$d aggiornati, %4$d saltati, %5$d errori.', 'affiliate-link-manager-ai'), $report['locations_found'], $report['objects_found'], $report['objects_updated'], $report['objects_skipped'], count($report['errors'])));
            return;
        }
        if ($action === 'geocode_location' || $action === 'retry_location') {
            if (!$this->geocoder->is_enabled()) {
                $this->notice_error(__('API key non configurata.', 'affiliate-link-manager-ai'));
                return;
            }
            $location_id = absint($_POST['location_id'] ?? 0);
            $location = $this->store->get_location($location_id);
            $expected_status = $action === 'retry_location' ? 'failed' : 'pending';
            if (!$location || ($location['geocoding_status'] ?? '') !== $expected_status) {
                $this->notice_error(__('Azione non eseguita: la località non è nello stato previsto per questa operazione.', 'affiliate-link-manager-ai'));
                return;
            }
            $result = $this->geocoder->geocode_location($location_id);
            $this->notice_success(sprintf(__('Località #%1$d aggiornata con stato %2$s. Oggetti collegati sincronizzati: %3$d, errori sync: %4$d.', 'affiliate-link-manager-ai'), $location_id, ALMA_Geo_Index_Metabox::geocoding_status_label($result['status'] ?? 'failed'), (int) ($result['linked_objects_synced'] ?? 0), (int) ($result['linked_objects_sync_errors'] ?? 0)));
            return;
        }
        if ($action === 'mark_manual_required') {
            $location_id = absint($_POST['location_id'] ?? 0);
            $this->store->update_location_geocoding($location_id, array('status' => 'manual_required', 'message' => __('Marcata manual_required da admin.', 'affiliate-link-manager-ai')));
            $this->notice_success(__('Località marcata come richiede verifica manuale.', 'affiliate-link-manager-ai'));
        }
    }

    private function handle_preview_upload() {
        if (!current_user_can('manage_options')) {
            $this->notice_error(__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
            return;
        }

        $validation = $this->importer->validate_uploaded_csv($_FILES['alma_geo_csv'] ?? array());
        if (is_wp_error($validation)) {
            $this->notice_error($this->format_upload_error_message($validation));
            return;
        }

        $stored_file = $this->store_preview_upload($validation);
        if (is_wp_error($stored_file)) {
            $this->notice_error($stored_file->get_error_message());
            return;
        }

        $parsed = $this->importer->parse_csv_file($stored_file, 10, $validation['delimiter']);
        $preview = array(
            'file' => $stored_file,
            'file_name' => $validation['file_name'],
            'total' => $parsed['total'],
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'valid' => true,
            'missing' => array(),
            'delimiter' => $validation['delimiter'],
            'mime' => $validation['mime'],
        );

        $old_preview = get_transient($this->preview_key());
        if (!empty($old_preview['file']) && file_exists($old_preview['file'])) {
            wp_delete_file($old_preview['file']);
        }
        set_transient($this->preview_key(), $preview, HOUR_IN_SECONDS);
        $this->notice_success(__('CSV caricato e validato. Controlla la preview e conferma l’importazione.', 'affiliate-link-manager-ai'));
    }

    private function handle_import_submit() {
        $preview = get_transient($this->preview_key());
        if (empty($preview['file']) || !file_exists($preview['file'])) {
            $this->notice_error(__('Preview non trovata o file temporaneo non disponibile. Ricarica il CSV.', 'affiliate-link-manager-ai'));
            return;
        }
        if (empty($preview['valid'])) {
            $this->notice_error(__('Il CSV non ha header validi e non può essere importato.', 'affiliate-link-manager-ai'));
            return;
        }
        $overwrite = !empty($_POST['alma_geo_overwrite']);
        $report = $this->importer->import_csv($preview['file'], array(
            'overwrite' => $overwrite,
            'file_name' => $preview['file_name'],
            'allowed_post_types' => array('post', 'page'),
            'delimiter' => $preview['delimiter'] ?? null,
        ));
        if (file_exists($preview['file'])) {
            wp_delete_file($preview['file']);
        }
        delete_transient($this->preview_key());
        $this->notice_success(sprintf(__('Import completato: %1$d importati, %2$d aggiornati, %3$d saltati, %4$d errori.', 'affiliate-link-manager-ai'), $report['imported'], $report['updated'], $report['skipped'] + $report['existing_skipped'], $report['errors'] + $report['post_not_found']));
    }

    private function render_dashboard_tab() {
        $counts = $this->store->get_dashboard_counts();
        if (empty($counts['tables_exist'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Le tabelle dell’Indice Geografico non risultano ancora disponibili. Riattiva il plugin o esegui l’upgrade per crearle.', 'affiliate-link-manager-ai') . '</p></div>';
            return;
        }
        $cards = array(
            __('Contenuti geolocalizzati attivi', 'affiliate-link-manager-ai') => $counts['active_geo_content'],
            __('Contenuti con località verified', 'affiliate-link-manager-ai') => $counts['content_with_verified_locations'],
            __('Contenuti ancora pending', 'affiliate-link-manager-ai') => $counts['content_pending_geocoding'],
            __('Località verified', 'affiliate-link-manager-ai') => $counts['locations_verified'],
            __('Località pending', 'affiliate-link-manager-ai') => $counts['locations_pending'],
            __('Località failed', 'affiliate-link-manager-ai') => $counts['locations_failed'],
            __('Da revisione', 'affiliate-link-manager-ai') => $counts['manual_review'],
            __('Non attivi/scartati', 'affiliate-link-manager-ai') => $counts['inactive_or_discarded'],
            __('Compatibilità widget', 'affiliate-link-manager-ai') => $counts['widget_eligible'],
            __('Link Affiliati con geo meta', 'affiliate-link-manager-ai') => $counts['affiliate_links_with_geo_meta'],
            __('Località salvate', 'affiliate-link-manager-ai') => $counts['locations'],
            __('Relazioni contenuto/località', 'affiliate-link-manager-ai') => $counts['content_relations'],
        );
        echo '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px;">';
        foreach ($cards as $label => $value) {
            echo '<div class="postbox"><div class="inside"><h2>' . esc_html($label) . '</h2><p style="font-size:28px;margin:0;"><strong>' . esc_html((string) $value) . '</strong></p></div></div>';
        }
        echo '</div>';
    }

    private function render_import_tab() {
        $preview = get_transient($this->preview_key());
        ?>
        <h2><?php esc_html_e('Importa record articoli', 'affiliate-link-manager-ai'); ?></h2>
        <p><?php esc_html_e('File atteso: sothra_geo_article_index_safe_import.csv. Verranno importati solo record safe per articoli e pagine, senza geocoding automatico.', 'affiliate-link-manager-ai'); ?></p>
        <p><?php esc_html_e('Sono accettati file CSV con intestazioni generate dal flusso Geo Index.', 'affiliate-link-manager-ai'); ?></p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('alma_geo_index_import'); ?>
            <input type="hidden" name="alma_geo_index_action" value="preview">
            <input type="file" name="alma_geo_csv" accept=".csv,text/csv,text/plain,application/csv,application/vnd.ms-excel" required>
            <?php submit_button(__('Carica e mostra preview', 'affiliate-link-manager-ai'), 'secondary', 'submit', false); ?>
        </form>
        <?php
        if (!empty($preview)) {
            $this->render_preview($preview);
        }
    }

    private function render_preview($preview) {
        echo '<h3>' . esc_html__('Preview primi 10 record', 'affiliate-link-manager-ai') . '</h3>';
        echo '<p><strong>' . esc_html__('File:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($preview['file_name']) . ' — <strong>' . esc_html__('Record totali:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html((string) $preview['total']) . '</p>';
        if (empty($preview['valid'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Header mancanti:', 'affiliate-link-manager-ai') . ' ' . esc_html(implode(', ', $preview['missing'])) . '</p></div>';
            return;
        }
        echo '<div style="max-width:100%;overflow:auto;"><table class="widefat striped"><thead><tr>';
        foreach ($preview['headers'] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($preview['rows'] as $row) {
            echo '<tr>';
            foreach ($preview['headers'] as $header) {
                echo '<td>' . esc_html(wp_trim_words((string) ($row[$header] ?? ''), 12, '…')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        ?>
        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('alma_geo_index_import'); ?>
            <input type="hidden" name="alma_geo_index_action" value="import">
            <label><input type="checkbox" name="alma_geo_overwrite" value="1"> <?php esc_html_e('Sovrascrivi dati geografici esistenti', 'affiliate-link-manager-ai'); ?></label>
            <?php submit_button(__('Importa record validi', 'affiliate-link-manager-ai'), 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    private function render_locations_tab() {
        if (!$this->store->tables_exist()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Le tabelle dell’Indice Geografico non sono disponibili.', 'affiliate-link-manager-ai') . '</p></div>';
            return;
        }
        $locations = $this->store->get_locations(100);
        echo '<h2>' . esc_html__('Località', 'affiliate-link-manager-ai') . '</h2>';
        $this->render_locations_table($locations, true);

    }


    private function render_geocoding_tab() {
        if (!$this->store->tables_exist()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Le tabelle dell’Indice Geografico non sono disponibili.', 'affiliate-link-manager-ai') . '</p></div>';
            return;
        }
        $settings = $this->geocoder->get_settings();
        $counts = $this->store->get_geocoding_status_counts();
        $article_counts = $this->store->get_geocoding_status_counts_by_object_type('post');
        $page_counts = $this->store->get_geocoding_status_counts_by_object_type('page');
        $affiliate_counts = $this->store->get_geocoding_status_counts_by_object_type(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
        $api_key = (string) get_option('alma_geo_google_maps_api_key', '');
        $masked_key = $api_key === '' ? __('Non configurata', 'affiliate-link-manager-ai') : sprintf(__('Configurata (termina con %s)', 'affiliate-link-manager-ai'), substr($api_key, -4));
        echo '<h2>' . esc_html__('Configurazione geocoding', 'affiliate-link-manager-ai') . '</h2>';
        echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('Stato configurazione', 'affiliate-link-manager-ai') . '</h3><table class="widefat striped"><tbody>';
        $rows = array(
            __('Provider attivo', 'affiliate-link-manager-ai') => 'Google Maps',
            __('Google Maps API key', 'affiliate-link-manager-ai') => $masked_key,
            __('Località pending totali', 'affiliate-link-manager-ai') => $counts['pending'],
            __('Località pending da articoli/pagine', 'affiliate-link-manager-ai') => (int) $article_counts['pending'] + (int) $page_counts['pending'],
            __('Località pending da Link Affiliati', 'affiliate-link-manager-ai') => $affiliate_counts['pending'],
            __('Località verified', 'affiliate-link-manager-ai') => $counts['verified'],
            __('Località ambiguous', 'affiliate-link-manager-ai') => $counts['ambiguous'],
            __('Località failed', 'affiliate-link-manager-ai') => $counts['failed'],
            __('Batch size', 'affiliate-link-manager-ai') => $settings['batch_size'],
            __('Timeout', 'affiliate-link-manager-ai') => $settings['timeout'],
        );
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        $last_report = get_option(ALMA_Geo_Index_Geocoder::LAST_REPORT_OPTION, array());
        if (!empty($last_report)) {
            echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('Ultimo batch geocoding', 'affiliate-link-manager-ai') . '</h3><p>' . esc_html(sprintf(__('Processate: %1$d, verified: %2$d, ambiguous: %3$d, failed: %4$d, retry later: %5$d, pending rimanenti: %6$d.', 'affiliate-link-manager-ai'), (int) ($last_report['processed'] ?? 0), (int) ($last_report['verified'] ?? 0), (int) ($last_report['ambiguous'] ?? 0), (int) ($last_report['failed'] ?? 0), (int) ($last_report['retry_later'] ?? 0), (int) ($last_report['remaining_pending'] ?? 0))) . '</p></div></div>';
        }
        $last_sync_report = get_option('alma_geo_geocoding_last_sync_report', array());
        if (!empty($last_sync_report)) {
            echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('Ultimo report risincronizzazione', 'affiliate-link-manager-ai') . '</h3><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($last_sync_report, JSON_PRETTY_PRINT)) . '</pre></div></div>';
        }
        ?>
        <form method="post" class="postbox" style="padding:12px;">
            <?php wp_nonce_field('alma_geo_index_geocoding'); ?>
            <input type="hidden" name="alma_geo_index_action" value="save_geocoding_settings">
            <h3><?php esc_html_e('Impostazioni geocoding', 'affiliate-link-manager-ai'); ?></h3>
            <table class="form-table"><tbody>
                <tr><th><label><?php esc_html_e('Provider geocoding', 'affiliate-link-manager-ai'); ?></label></th><td><select name="alma_geo_geocoding_provider"><option value="google">Google Maps</option></select></td></tr>
                <tr><th><label for="alma_geo_google_maps_api_key"><?php esc_html_e('API key Google Maps', 'affiliate-link-manager-ai'); ?></label></th><td><input type="password" id="alma_geo_google_maps_api_key" name="alma_geo_google_maps_api_key" value="" class="regular-text" autocomplete="off"><p class="description"><?php echo esc_html($masked_key); ?>. <?php esc_html_e('Lascia vuoto per mantenere la chiave esistente.', 'affiliate-link-manager-ai'); ?></p></td></tr>
                <tr><th><label for="alma_geo_geocoding_batch_size"><?php esc_html_e('Batch size', 'affiliate-link-manager-ai'); ?></label></th><td><input type="number" min="1" max="50" id="alma_geo_geocoding_batch_size" name="alma_geo_geocoding_batch_size" value="<?php echo esc_attr((string) $settings['batch_size']); ?>"></td></tr>
                <tr><th><label for="alma_geo_geocoding_timeout"><?php esc_html_e('Timeout richiesta', 'affiliate-link-manager-ai'); ?></label></th><td><input type="number" min="1" max="30" id="alma_geo_geocoding_timeout" name="alma_geo_geocoding_timeout" value="<?php echo esc_attr((string) $settings['timeout']); ?>"></td></tr>
                <tr><th><label for="alma_geo_geocoding_delay_ms"><?php esc_html_e('Pausa tra richieste (ms)', 'affiliate-link-manager-ai'); ?></label></th><td><input type="number" min="0" max="5000" id="alma_geo_geocoding_delay_ms" name="alma_geo_geocoding_delay_ms" value="<?php echo esc_attr((string) $settings['delay_ms']); ?>"></td></tr>
                <tr><th><label for="alma_geo_geocoding_country_bias"><?php esc_html_e('Country bias opzionale', 'affiliate-link-manager-ai'); ?></label></th><td><input type="text" id="alma_geo_geocoding_country_bias" name="alma_geo_geocoding_country_bias" value="<?php echo esc_attr($settings['country_bias']); ?>" class="small-text" maxlength="10"></td></tr>
                <tr><th><?php esc_html_e('Sovrascrittura', 'affiliate-link-manager-ai'); ?></th><td><label><input type="checkbox" name="alma_geo_geocoding_overwrite_verified" value="1" <?php checked($settings['overwrite_verified'], 'yes'); ?>> <?php esc_html_e('Permetti sovrascrittura località già verified', 'affiliate-link-manager-ai'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Geocoding automatico', 'affiliate-link-manager-ai'); ?></th><td><label><input type="checkbox" name="alma_geo_auto_geocoding" value="1" <?php checked(get_option(ALMA_Geo_Geocoding_Queue::ENABLED_OPTION, 'yes'), 'yes'); ?>> <?php esc_html_e('Geocodifica automaticamente in background le località associate da import, API, metabox e auto-indicizzazione (nessun secondo passaggio manuale).', 'affiliate-link-manager-ai'); ?></label><p class="description"><?php esc_html_e('Richiede la API key. Lo stato è visibile nella tab Panoramica; i batch manuali qui sotto restano disponibili come fallback.', 'affiliate-link-manager-ai'); ?></p></td></tr>
            </tbody></table>
            <?php submit_button(__('Salva impostazioni geocoding', 'affiliate-link-manager-ai'), 'primary', 'submit', false); ?>
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
            <?php $this->render_geocoding_button('test_api_key', __('Test API key', 'affiliate-link-manager-ai')); ?>
            <?php $this->render_geocoding_button('batch_pending', __('Geocodifica prossime località pending', 'affiliate-link-manager-ai')); ?>
            <?php $this->render_geocoding_button('retry_failed', __('Riprova failed', 'affiliate-link-manager-ai')); ?>
            <?php $this->render_geocoding_button('sync_verified_locations', __('Risincronizza geocoding nei contenuti', 'affiliate-link-manager-ai')); ?>
        </div>
        <?php
        $this->render_affiliate_geocoding_bulk_section($affiliate_counts, $settings);
        echo '<h3 id="alma-geo-pending-locations">' . esc_html__('Località pending/recenti', 'affiliate-link-manager-ai') . '</h3>';
        $this->render_locations_table($this->store->get_locations(50), false);
    }


    private function render_affiliate_geocoding_bulk_section($affiliate_counts, $settings) {
        $nonce = wp_create_nonce('alma_geo_affiliate_geocoding');
        $csv_url = wp_nonce_url(admin_url('admin-post.php?action=alma_geo_download_geocoding_csv'), 'alma_geo_download_geocoding_report');
        $json_url = wp_nonce_url(admin_url('admin-post.php?action=alma_geo_download_geocoding_json'), 'alma_geo_download_geocoding_report');
        ?>
        <div class="postbox" id="alma-geo-affiliate-geocoding"><div class="inside">
            <h3><?php esc_html_e('Geocoding Link Affiliati', 'affiliate-link-manager-ai'); ?></h3>
            <p><?php esc_html_e('Processa le località primarie dei Link Affiliati in batch AJAX visibili e interrompibili. Le località già verified non vengono riprocessate.', 'affiliate-link-manager-ai'); ?></p>
            <table class="widefat striped"><tbody>
                <tr><th><?php esc_html_e('Pending da Link Affiliati', 'affiliate-link-manager-ai'); ?></th><td data-alma-affiliate-pending><?php echo esc_html((string) ($affiliate_counts['pending'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Verified', 'affiliate-link-manager-ai'); ?></th><td><?php echo esc_html((string) ($affiliate_counts['verified'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Ambiguous', 'affiliate-link-manager-ai'); ?></th><td><?php echo esc_html((string) ($affiliate_counts['ambiguous'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Failed', 'affiliate-link-manager-ai'); ?></th><td><?php echo esc_html((string) ($affiliate_counts['failed'] ?? 0)); ?></td></tr>
                <tr><th><?php esc_html_e('Batch size', 'affiliate-link-manager-ai'); ?></th><td><select id="alma-geo-affiliate-batch-size"><option value="5">5</option><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select></td></tr>
                <tr><th><?php esc_html_e('Timeout', 'affiliate-link-manager-ai'); ?></th><td><input type="number" min="1" max="30" id="alma-geo-affiliate-timeout" value="<?php echo esc_attr((string) ($settings['timeout'] ?? 15)); ?>"> <?php esc_html_e('secondi', 'affiliate-link-manager-ai'); ?></td></tr>
                <tr><th><?php esc_html_e('Opzioni', 'affiliate-link-manager-ai'); ?></th><td><label><input type="checkbox" id="alma-geo-include-ambiguous" value="1"> <?php esc_html_e('Includi ambiguous', 'affiliate-link-manager-ai'); ?></label><br><label><input type="checkbox" id="alma-geo-retry-failed" value="1"> <?php esc_html_e('Riprova failed', 'affiliate-link-manager-ai'); ?></label></td></tr>
            </tbody></table>
            <p>
                <button type="button" class="button" id="alma-geo-next-affiliate-batch"><?php esc_html_e('Geocodifica prossimo batch', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button button-primary" id="alma-geo-all-affiliate-batches"><?php esc_html_e('Geocodifica tutte le pending da Link Affiliati', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button" id="alma-geo-stop-affiliate-batches" disabled><?php esc_html_e('Interrompi elaborazione', 'affiliate-link-manager-ai'); ?></button>
                <a class="button" href="<?php echo esc_url($csv_url); ?>"><?php esc_html_e('Scarica report geocoding CSV', 'affiliate-link-manager-ai'); ?></a>
                <a class="button" href="<?php echo esc_url($json_url); ?>"><?php esc_html_e('Scarica log geocoding JSON', 'affiliate-link-manager-ai'); ?></a>
            </p>
            <div style="height:18px;background:#f0f0f1;border:1px solid #c3c4c7;max-width:520px;"><div id="alma-geo-affiliate-progress" style="height:18px;background:#2271b1;width:0%;"></div></div>
            <p id="alma-geo-affiliate-status"><?php esc_html_e('Pronto.', 'affiliate-link-manager-ai'); ?></p>
            <div id="alma-geo-affiliate-errors" class="notice notice-error inline" style="display:none;"><p></p></div>
            <h4><?php esc_html_e('Report cumulativo sessione corrente', 'affiliate-link-manager-ai'); ?></h4>
            <ul id="alma-geo-affiliate-session-report"><li><?php esc_html_e('Nessun batch eseguito in questa sessione.', 'affiliate-link-manager-ai'); ?></li></ul>
            <h4><?php esc_html_e('Esempi ultimi record processati', 'affiliate-link-manager-ai'); ?></h4>
            <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Località', 'affiliate-link-manager-ai'); ?></th><th><?php esc_html_e('Query', 'affiliate-link-manager-ai'); ?></th><th><?php esc_html_e('Stato', 'affiliate-link-manager-ai'); ?></th><th>Lat/Lng</th><th>Place ID</th><th><?php esc_html_e('Messaggio', 'affiliate-link-manager-ai'); ?></th></tr></thead><tbody id="alma-geo-affiliate-examples"><tr><td colspan="7"><?php esc_html_e('Nessun dato.', 'affiliate-link-manager-ai'); ?></td></tr></tbody></table>
        </div></div>
        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var runningAll = false;
            var stopRequested = false;
            var sessionId = String(Date.now()) + '-' + String(Math.random()).slice(2);
            var totals = {processed:0, verified:0, ambiguous:0, failed:0, skipped:0, retry_later:0, api_errors:0};
            var initialPending = parseInt(document.querySelector('[data-alma-affiliate-pending]').textContent, 10) || 0;
            function el(id){ return document.getElementById(id); }
            function setStatus(text){ el('alma-geo-affiliate-status').textContent = text; }
            function setButtons(running){ el('alma-geo-next-affiliate-batch').disabled = running; el('alma-geo-all-affiliate-batches').disabled = running; el('alma-geo-stop-affiliate-batches').disabled = !running; }
            function updateReport(data){
                totals.processed += data.processed || 0; totals.verified += data.verified || 0; totals.ambiguous += data.ambiguous || 0; totals.failed += data.failed || 0; totals.skipped += data.skipped || 0; totals.retry_later += data.retry_later || 0; totals.api_errors += (data.api_errors || []).length;
                el('alma-geo-affiliate-session-report').innerHTML = '<li>Processate: '+totals.processed+'</li><li>Verified: '+totals.verified+'</li><li>Ambiguous: '+totals.ambiguous+'</li><li>Failed: '+totals.failed+'</li><li>Skipped: '+totals.skipped+'</li><li>Retry later: '+totals.retry_later+'</li><li>Errori API: '+totals.api_errors+'</li><li>Pending rimanenti: '+(data.remaining_pending || 0)+'</li>';
                var pendingCell = document.querySelector('[data-alma-affiliate-pending]'); if (pendingCell) { pendingCell.textContent = data.remaining_pending || 0; }
                var done = initialPending > 0 ? Math.max(0, initialPending - (data.remaining_pending || 0)) : totals.processed;
                var pct = initialPending > 0 ? Math.min(100, Math.round(done / initialPending * 100)) : 0; el('alma-geo-affiliate-progress').style.width = pct + '%';
                if ((data.api_errors || []).length) { el('alma-geo-affiliate-errors').style.display='block'; el('alma-geo-affiliate-errors').querySelector('p').textContent = (data.api_errors || []).join(' | '); }
                var rows = data.examples || []; var html = '';
                rows.forEach(function(row){ html += '<tr><td>'+esc(row.location_id)+'</td><td>'+esc(row.name || row.canonical_name || '')+'</td><td>'+esc(row.query || '')+'</td><td>'+esc(row.new_status || '')+'</td><td>'+esc(((row.lat || '') && (row.lng || '')) ? (row.lat+', '+row.lng) : '')+'</td><td>'+esc(row.place_id || '')+'</td><td>'+esc(row.message || '')+'</td></tr>'; });
                el('alma-geo-affiliate-examples').innerHTML = html || '<tr><td colspan="7">Nessun dato.</td></tr>';
            }
            function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
            function requestBatch(){
                var body = new URLSearchParams(); body.set('action','alma_geo_geocode_affiliate_locations_batch'); body.set('nonce', nonce); body.set('source_filter','affiliate_links'); body.set('batch_size', el('alma-geo-affiliate-batch-size').value); body.set('timeout', el('alma-geo-affiliate-timeout').value); body.set('include_ambiguous', el('alma-geo-include-ambiguous').checked ? '1' : '0'); body.set('retry_failed', el('alma-geo-retry-failed').checked ? '1' : '0'); body.set('session_id', sessionId);
                return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){ return r.json(); }).then(function(resp){ if(!resp || !resp.success){ throw new Error((resp && resp.data && resp.data.message) ? resp.data.message : 'Errore AJAX geocoding.'); } return resp.data; });
            }
            function runOne(continueAll){
                setButtons(true); setStatus('Batch in corso...');
                requestBatch().then(function(data){ updateReport(data); if (data.rate_limit_detected) { runningAll=false; stopRequested=true; setStatus('Elaborazione fermata: quota/rate limit Google rilevato.'); setButtons(false); return; } if (continueAll && !stopRequested && (data.remaining_pending || 0) > 0 && (data.processed || 0) > 0) { setStatus('Batch completato, avvio il successivo...'); window.setTimeout(function(){ runOne(true); }, 500); } else { runningAll=false; setButtons(false); setStatus(stopRequested ? 'Elaborazione interrotta dopo il batch corrente.' : 'Elaborazione completata o nessuna pending rimasta.'); } }).catch(function(err){ runningAll=false; setButtons(false); el('alma-geo-affiliate-errors').style.display='block'; el('alma-geo-affiliate-errors').querySelector('p').textContent=err.message; setStatus('Errore. Elaborazione fermata.'); });
            }
            el('alma-geo-next-affiliate-batch').addEventListener('click', function(){ stopRequested=false; runOne(false); });
            el('alma-geo-all-affiliate-batches').addEventListener('click', function(){ stopRequested=false; runningAll=true; runOne(true); });
            el('alma-geo-stop-affiliate-batches').addEventListener('click', function(){ stopRequested=true; runningAll=false; setStatus('Interruzione richiesta: il batch corrente terminerà, poi il processo si fermerà.'); });
        }());
        </script>
        <?php
    }

    private function render_geocoding_button($action, $label, $location_id = 0) {
        echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0;">';
        wp_nonce_field('alma_geo_index_geocoding');
        echo '<input type="hidden" name="alma_geo_index_action" value="' . esc_attr($action) . '">';
        if ($location_id) {
            echo '<input type="hidden" name="location_id" value="' . esc_attr((string) absint($location_id)) . '">';
        }
        $attrs = $action === 'reset_affiliate_import' ? array(
            'onclick' => "return confirm('" . esc_js(__('Reset import cancellerà solo lo stato/sessione GEO e non eliminerà i Link Affiliati già importati. Continuare?', 'affiliate-link-manager-ai')) . "');",
        ) : array();
        submit_button($label, 'secondary small', 'submit', false, $attrs);
        echo '</form>';
    }

    private function render_locations_table($locations, $include_content_count) {
        echo '<div style="max-width:100%;overflow:auto;"><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Nome canonico', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Tipo', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Paese', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Regione', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Città', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Query', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Stato', 'affiliate-link-manager-ai') . '</th><th>Lat/Lng</th><th>Provider</th><th>Place ID</th><th>' . esc_html__('Formatted address', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Errore', 'affiliate-link-manager-ai') . '</th><th>Geocoded at</th>' . ($include_content_count ? '<th>' . esc_html__('Contenuti', 'affiliate-link-manager-ai') . '</th>' : '') . '<th>' . esc_html__('Azioni', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
        if (empty($locations)) {
            $colspan = $include_content_count ? 16 : 15;
            echo '<tr><td colspan="' . esc_attr((string) $colspan) . '">' . esc_html__('Nessuna località salvata.', 'affiliate-link-manager-ai') . '</td></tr>';
        }
        foreach ($locations as $location) {
            $lat_lng = ($location['lat'] !== null && $location['lng'] !== null) ? $location['lat'] . ', ' . $location['lng'] : '';
            echo '<tr><td>' . esc_html((string) $location['id']) . '</td><td>' . esc_html($location['canonical_name']) . '</td><td>' . esc_html($location['type']) . '</td><td>' . esc_html($location['country']) . '</td><td>' . esc_html($location['region']) . '</td><td>' . esc_html($location['city']) . '</td><td>' . esc_html($location['suggested_geocoding_query']) . '</td><td>' . esc_html(ALMA_Geo_Index_Metabox::geocoding_status_label($location['geocoding_status'])) . '</td><td>' . esc_html($lat_lng) . '</td><td>' . esc_html($location['geo_provider']) . '</td><td>' . esc_html($location['geo_provider_place_id']) . '</td><td>' . esc_html(wp_trim_words((string) ($location['formatted_address'] ?? ''), 12, '…')) . '</td><td>' . esc_html(wp_trim_words((string) ($location['geocoding_error'] ?? ''), 12, '…')) . '</td><td>' . esc_html((string) ($location['geocoded_at'] ?? '')) . '</td>';
            if ($include_content_count) {
                echo '<td>' . esc_html((string) $location['content_count']) . '</td>';
            }
            echo '<td>';
            $this->render_geocoding_button('geocode_location', __('Geocodifica', 'affiliate-link-manager-ai'), (int) $location['id']);
            $this->render_geocoding_button('retry_location', __('Riprova', 'affiliate-link-manager-ai'), (int) $location['id']);
            $this->render_geocoding_button('mark_manual_required', __('Segna manual_required', 'affiliate-link-manager-ai'), (int) $location['id']);
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }


    private function handle_affiliate_import_action($action) {
        if (!current_user_can('manage_options')) {
            $this->notice_error(__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
            return;
        }
        if ($action === 'preview_affiliate_csv') {
            $validation = $this->affiliate_importer->validate_uploaded_csv($_FILES['alma_geo_affiliate_csv'] ?? array());
            if (is_wp_error($validation)) {
                $this->notice_error($this->format_upload_error_message($validation));
                return;
            }
            $stored_file = $this->store_preview_upload($validation);
            if (is_wp_error($stored_file)) {
                $this->notice_error($stored_file->get_error_message());
                return;
            }
            $parsed = $this->affiliate_importer->parse_csv_file($stored_file, 10, $validation['delimiter']);
            $headers_validation = $this->affiliate_importer->validate_headers($parsed['headers']);
            $preview = array(
                'file' => $stored_file,
                'file_name' => $validation['file_name'],
                'total' => $parsed['total'],
                'headers' => $parsed['headers'],
                'rows' => $parsed['rows'],
                'valid' => $headers_validation['valid'],
                'missing' => $headers_validation['missing'],
                'recommended_missing' => $headers_validation['recommended_missing'],
                'delimiter' => $validation['delimiter'],
                'mime' => $validation['mime'],
            );
            $old_preview = get_transient($this->affiliate_preview_key());
            if (!empty($old_preview['file']) && file_exists($old_preview['file'])) {
                wp_delete_file($old_preview['file']);
            }
            set_transient($this->affiliate_preview_key(), $preview, HOUR_IN_SECONDS);
            $this->notice_success(__('CSV GEO Link Affiliati caricato e validato. Controlla la preview e crea una sessione di import manuale.', 'affiliate-link-manager-ai'));
            return;
        }
        if ($action === 'repair_geo_import_tables') {
            $status = $this->job_store->repair_tables();
            set_transient($this->geo_schema_repair_key(), $status, HOUR_IN_SECONDS);
            if (!empty($status['success'])) {
                $this->notice_success(__('Schema riparato correttamente. Nessun dato esistente è stato eliminato.', 'affiliate-link-manager-ai'));
            } else {
                $message = !empty($status['message']) ? $status['message'] : __('Riparazione schema GEO non completata: verifica i dettagli tecnici negli strumenti avanzati.', 'affiliate-link-manager-ai');
                $this->notice_error($message . ' ' . __('Apri Strumenti avanzati per la diagnostica dbDelta.', 'affiliate-link-manager-ai'));
            }
            return;
        }
        if ($action === 'start_affiliate_import') {
            $schema_ready = $this->job_store->ensure_tables(true);
            if (is_wp_error($schema_ready)) {
                $data = $schema_ready->get_error_data();
                if (!empty($data['repair_status']) && is_array($data['repair_status'])) {
                    set_transient($this->geo_schema_repair_key(), $data['repair_status'], HOUR_IN_SECONDS);
                }
                $this->notice_error($schema_ready->get_error_message());
                return;
            }
            $preview = get_transient($this->affiliate_preview_key());
            if (empty($preview['file']) || !file_exists($preview['file']) || empty($preview['valid'])) {
                $this->notice_error(__('Preview non valida o scaduta. Ricarica il CSV Link Affiliati.', 'affiliate-link-manager-ai'));
                return;
            }
            $job_id = $this->affiliate_importer->create_job_from_csv($preview['file'], array(
                'file_name' => $preview['file_name'],
                'delimiter' => $preview['delimiter'] ?? null,
                'overwrite' => !empty($_POST['alma_geo_affiliate_overwrite']),
                'safe_only' => !empty($_POST['alma_geo_affiliate_safe_only']),
                'batch_size' => $this->sanitize_geo_affiliate_batch_size($_POST['alma_geo_affiliate_batch_size'] ?? 50),
            ));
            if (is_wp_error($job_id)) {
                $this->notice_error($job_id->get_error_message());
                return;
            }
            if (file_exists($preview['file'])) {
                wp_delete_file($preview['file']);
            }
            delete_transient($this->affiliate_preview_key());
            $created_job = $this->job_store->get_job($job_id);
            $created_report = $this->job_store->get_report($job_id);
            if (!empty($created_job['status']) && $created_job['status'] === 'needs_review') {
                $this->notice_error($this->affiliate_job_status_message($created_job));
            } else {
                $this->notice_success(sprintf(__('Sessione GEO preparata con %1$d item staging processabili su %2$d righe lette. Usa Importa prossimo batch: non partirà nessun job automatico in background.', 'affiliate-link-manager-ai'), (int) ($created_report['items_created'] ?? 0), (int) ($created_report['records_read'] ?? 0)));
            }
            return;
        }
        $job_id = absint($_POST['job_id'] ?? 0);
        if (!$job_id) {
            $latest = $this->job_store->get_latest_job();
            $job_id = absint($latest['id'] ?? 0);
        }
        if (!$job_id) {
            $this->notice_error(__('Nessun job disponibile.', 'affiliate-link-manager-ai'));
            return;
        }
        if ($action === 'download_affiliate_log') {
            $this->download_affiliate_job_log($job_id);
        } elseif ($action === 'download_affiliate_json_log') {
            $this->download_affiliate_job_json_log($job_id);
        } elseif ($action === 'reset_affiliate_import') {
            $this->job_store->delete_job($job_id);
            $this->notice_success(__('Reset import completato: stato e sessione eliminati. I Link Affiliati già creati o aggiornati non sono stati eliminati.', 'affiliate-link-manager-ai'));
        }
    }


    /**
     * Neutralizza l'esecuzione di formule (Excel/Sheets) nei CSV esportati:
     * i valori che iniziano con = + - @ o tab/CR vengono prefissati con apostrofo.
     */
    private function csv_safe_cell($value) {
        $value = (string) $value;
        // ltrim come l'escaping dell'export link del core: " =SUM(...)" con spazi
        // iniziali viene comunque interpretato come formula dai fogli di calcolo.
        $check = ltrim($value);
        if ($check !== '' && in_array($check[0], array('=', '+', '-', '@', "\t", "\r"), true)) {
            $value = "'" . $value;
        }
        return $value;
    }

    private function download_affiliate_job_log($job_id) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }
        $job = $this->job_store->get_job($job_id);
        if (!$job) {
            wp_die(esc_html__('Sessione non trovata.', 'affiliate-link-manager-ai'));
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alma-geo-affiliate-import-job-' . absint($job_id) . '-log.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('session_id','row_number','affiliate_link_id','post_title','affiliate_url','primary_name','city','region','final_bucket','safe_for_auto_import','status','action','motivo','suggerimento','processed_at'));
        foreach ($this->job_store->get_all_items_with_payload($job_id, 1000, 0) as $item) {
            $payload = is_array($item['raw_payload'] ?? null) ? $item['raw_payload'] : array();
            fputcsv($out, array(
                (int) $item['job_id'],
                (int) $item['row_number'],
                (int) $item['object_id'],
                $this->csv_safe_cell(sanitize_text_field($payload['post_title'] ?? ($payload['title'] ?? ($payload['link_title'] ?? '')))),
                $this->csv_safe_cell(esc_url_raw($payload['affiliate_url'] ?? ($payload['url'] ?? ''))),
                $this->csv_safe_cell(sanitize_text_field($payload['primary_name'] ?? '')),
                $this->csv_safe_cell(sanitize_text_field($payload['primary_city'] ?? ($payload['city'] ?? ''))),
                $this->csv_safe_cell(sanitize_text_field($payload['primary_region'] ?? ($payload['region'] ?? ''))),
                sanitize_key($payload['final_bucket'] ?? ''),
                $this->csv_safe_cell(sanitize_text_field($payload['safe_for_auto_import'] ?? '')),
                sanitize_key($item['status']),
                sanitize_key($item['action']),
                $this->csv_safe_cell(sanitize_textarea_field($item['message'])),
                $this->csv_safe_cell($this->affiliate_import_suggestion($item['message'] ?? '')),
                sanitize_text_field($item['processed_at']),
            ));
        }
        fclose($out);
        exit;
    }


    private function download_affiliate_job_json_log($job_id) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }
        $job = $this->job_store->get_job($job_id);
        if (!$job) {
            wp_die(esc_html__('Sessione non trovata.', 'affiliate-link-manager-ai'));
        }
        $items = array();
        foreach ($this->job_store->get_all_items_with_payload($job_id, 1000, 0) as $item) {
            $payload = is_array($item['raw_payload'] ?? null) ? $item['raw_payload'] : array();
            $items[] = array(
                'row_number' => (int) $item['row_number'],
                'affiliate_link_id' => (int) $item['object_id'],
                'post_title' => sanitize_text_field($payload['post_title'] ?? ($payload['title'] ?? ($payload['link_title'] ?? ''))),
                'affiliate_url' => esc_url_raw($payload['affiliate_url'] ?? ($payload['url'] ?? '')),
                'primary_name' => sanitize_text_field($payload['primary_name'] ?? ''),
                'city' => sanitize_text_field($payload['primary_city'] ?? ($payload['city'] ?? '')),
                'region' => sanitize_text_field($payload['primary_region'] ?? ($payload['region'] ?? '')),
                'final_bucket' => sanitize_key($payload['final_bucket'] ?? ''),
                'safe_for_auto_import' => sanitize_text_field($payload['safe_for_auto_import'] ?? ''),
                'status' => sanitize_key($item['status']),
                'action' => sanitize_key($item['action']),
                'message' => sanitize_textarea_field($item['message']),
                'suggestion' => $this->affiliate_import_suggestion($item['message'] ?? ''),
                'processed_at' => sanitize_text_field($item['processed_at']),
                'payload' => $payload,
            );
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="alma-geo-affiliate-import-session-' . absint($job_id) . '-log.json"');
        echo wp_json_encode(array(
            'session' => $this->format_affiliate_job_response($job),
            'cumulative_report' => $this->job_store->get_report($job_id),
            'items' => $items,
        ), JSON_PRETTY_PRINT);
        exit;
    }

    private function sanitize_geo_affiliate_batch_size($value) {
        $size = absint($value);
        $allowed = array(25, 50, 100, 250);
        if (!in_array($size, $allowed, true)) {
            $size = 50;
        }
        return max(25, min(250, $size));
    }

    private function affiliate_import_suggestion($message) {
        $message = (string) $message;
        if (strpos($message, 'affiliate_link_not_found') !== false) {
            return __('Verifica che affiliate_link_id esista e non sia stato eliminato.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'object_not_affiliate_link') !== false) {
            return __('Correggi l’ID: il record deve puntare a un CPT affiliate_link.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'missing_primary_location') !== false || strpos($message, 'missing_primary_name') !== false || strpos($message, 'unknown_location') !== false) {
            return __('Completa località/città nel CSV prima di riprovare.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'missing_region') !== false) {
            return __('Aggiungi o normalizza la regione nel CSV.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'invalid_affiliate_url') !== false || strpos($message, 'invalid_url') !== false) {
            return __('Correggi l’URL affiliato e riprova il record.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'existing_geo_skipped') !== false) {
            return __('Record già geolocalizzato: abilita sovrascrittura solo se necessario.', 'affiliate-link-manager-ai');
        }
        if (strpos($message, 'safe_import_false') !== false || strpos($message, 'safe_import_skipped') !== false) {
            return __('Rivedi final_bucket/safe_for_auto_import o disattiva safe_only con cautela.', 'affiliate-link-manager-ai');
        }
        return __('Rivedi il record CSV e riprova se necessario.', 'affiliate-link-manager-ai');
    }


    public function ajax_geocode_affiliate_locations_batch() {
        if (check_ajax_referer('alma_geo_affiliate_geocoding', 'nonce', false) === false) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        $source_filter = sanitize_key(wp_unslash($_POST['source_filter'] ?? 'affiliate_links'));
        if ($source_filter !== 'affiliate_links') {
            wp_send_json_error(array('message' => __('Filtro sorgente non supportato.', 'affiliate-link-manager-ai')), 400);
        }
        $session_id = sanitize_key(wp_unslash($_POST['session_id'] ?? ''));
        $report = $this->geocoder->geocode_affiliate_locations_batch(array(
            'batch_size' => max(1, min(50, absint(wp_unslash($_POST['batch_size'] ?? 20)))),
            'timeout' => max(1, min(30, absint(wp_unslash($_POST['timeout'] ?? 15)))),
            'include_ambiguous' => !empty($_POST['include_ambiguous']) && sanitize_key(wp_unslash($_POST['include_ambiguous'])) === '1',
            'retry_failed' => !empty($_POST['retry_failed']) && sanitize_key(wp_unslash($_POST['retry_failed'])) === '1',
        ));
        $this->store_cumulative_geocoding_report($report, $session_id);
        wp_send_json_success($report);
    }


    public function ajax_auto_index_batch() {
        if (check_ajax_referer('alma_geo_auto_index', 'nonce', false) === false) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        $report = $this->auto_indexer->process_batch(array(
            'target' => sanitize_key(wp_unslash($_POST['target'] ?? 'affiliate_links')),
            'batch_size' => max(1, min(100, absint(wp_unslash($_POST['batch_size'] ?? 50)))),
            'use_ai' => !empty($_POST['use_ai']) && sanitize_key(wp_unslash($_POST['use_ai'])) === '1',
            'retry_unresolved' => !empty($_POST['retry_unresolved']) && sanitize_key(wp_unslash($_POST['retry_unresolved'])) === '1',
            'retry_rejected' => !empty($_POST['retry_rejected']) && sanitize_key(wp_unslash($_POST['retry_rejected'])) === '1',
        ));
        if (empty($report['success'])) {
            wp_send_json_error($report, !empty($report['locked']) ? 409 : 400);
        }
        wp_send_json_success($report);
    }

    public function ajax_auto_suggestions() {
        if (check_ajax_referer('alma_geo_auto_index', 'nonce', false) === false) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        $offset = max(0, absint(wp_unslash($_POST['offset'] ?? 0)));
        wp_send_json_success($this->auto_indexer->get_pending_suggestions(50, $offset));
    }

    public function ajax_auto_suggestion_action() {
        if (check_ajax_referer('alma_geo_auto_index', 'nonce', false) === false) {
            wp_send_json_error(array('message' => __('Nonce non valido.', 'affiliate-link-manager-ai')), 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        $action = sanitize_key(wp_unslash($_POST['suggestion_action'] ?? ''));
        if (!in_array($action, array('approve', 'reject'), true)) {
            wp_send_json_error(array('message' => __('Azione non valida.', 'affiliate-link-manager-ai')), 400);
        }
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? array()))));
        if (empty($ids) || count($ids) > 100) {
            wp_send_json_error(array('message' => __('Seleziona da 1 a 100 elementi.', 'affiliate-link-manager-ai')), 400);
        }
        $ok = 0;
        $failed = 0;
        foreach ($ids as $post_id) {
            $result = $action === 'approve' ? $this->auto_indexer->approve_suggestion($post_id) : $this->auto_indexer->reject_suggestion($post_id);
            if ($result) {
                $ok++;
            } else {
                $failed++;
            }
        }
        wp_send_json_success(array('action' => $action, 'ok' => $ok, 'failed' => $failed));
    }

    private function render_coverage_tab() {
        $coverage = $this->auto_indexer->get_coverage();
        if (empty($coverage)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Tabelle GEO non disponibili: esegui la riparazione dagli Strumenti avanzati.', 'affiliate-link-manager-ai') . '</p></div>';
            return;
        }
        $nonce = wp_create_nonce('alma_geo_auto_index');
        $labels = array(
            'posts' => __('Articoli pubblicati', 'affiliate-link-manager-ai'),
            'affiliate_links' => __('Link Affiliati pubblicati', 'affiliate-link-manager-ai'),
        );
        $openai_ready = trim((string) get_option('alma_openai_api_key', '')) !== '';
        ?>
        <div class="card" style="max-width:960px;">
            <h2><?php esc_html_e('Copertura geografica', 'affiliate-link-manager-ai'); ?></h2>
            <p><?php esc_html_e('Stato dell\'indicizzazione geografica dei contenuti pubblicati. Gli oggetti "da rivedere" hanno proposte automatiche in attesa di conferma.', 'affiliate-link-manager-ai'); ?></p>
            <table class="widefat striped" style="max-width:760px;">
                <thead><tr>
                    <th><?php esc_html_e('Contenuto', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Totale', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Indicizzati', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Non indicizzati', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Da rivedere', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Copertura', 'affiliate-link-manager-ai'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($coverage as $key => $row) :
                    $pct = $row['total'] > 0 ? round($row['indexed'] / $row['total'] * 100) : 0; ?>
                    <tr>
                        <td><strong><?php echo esc_html($labels[$key] ?? $key); ?></strong></td>
                        <td><?php echo esc_html(number_format_i18n($row['total'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($row['indexed'])); ?></td>
                        <td data-alma-unindexed="<?php echo esc_attr($key); ?>"><?php echo esc_html(number_format_i18n($row['unindexed'])); ?></td>
                        <td data-alma-suggested="<?php echo esc_attr($key); ?>"><?php echo esc_html(number_format_i18n($row['suggested'])); ?></td>
                        <td><?php echo esc_html($pct); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="max-width:960px;">
            <h2><?php esc_html_e('Indicizzazione automatica', 'affiliate-link-manager-ai'); ?></h2>
            <p><?php esc_html_e('Associa automaticamente le località: 1) destinazione dichiarata dal provider (link importati, applicata subito); 2) località conosciute trovate nel titolo (applicata subito) o in slug/heading/contenuto (in revisione); 3) opzionale, estrazione AI per i contenuti irrisolti (sempre in revisione, mai applicata da sola). Le associazioni manuali non vengono mai modificate.', 'affiliate-link-manager-ai'); ?></p>
            <p>
                <label><?php esc_html_e('Contenuto:', 'affiliate-link-manager-ai'); ?>
                    <select id="alma-geo-auto-target">
                        <option value="affiliate_links"><?php esc_html_e('Link Affiliati', 'affiliate-link-manager-ai'); ?></option>
                        <option value="posts"><?php esc_html_e('Articoli', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                </label>
                <label style="margin-left:12px;"><?php esc_html_e('Batch:', 'affiliate-link-manager-ai'); ?>
                    <select id="alma-geo-auto-batch-size">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                </label>
                <label style="margin-left:12px;"><input type="checkbox" id="alma-geo-auto-use-ai" <?php disabled(!$openai_ready); ?> /> <?php esc_html_e('Usa AI per i contenuti irrisolti', 'affiliate-link-manager-ai'); ?><?php if (!$openai_ready) { echo ' <em>(' . esc_html__('OpenAI non configurata', 'affiliate-link-manager-ai') . ')</em>'; } ?></label>
                <label style="margin-left:12px;"><input type="checkbox" id="alma-geo-auto-retry-unresolved" /> <?php esc_html_e('Riprova irrisolti', 'affiliate-link-manager-ai'); ?></label>
            </p>
            <p>
                <button type="button" class="button button-primary" id="alma-geo-auto-run-one"><?php esc_html_e('Elabora prossimo batch', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button" id="alma-geo-auto-run-all"><?php esc_html_e('Elabora tutto', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button" id="alma-geo-auto-stop" disabled><?php esc_html_e('Interrompi', 'affiliate-link-manager-ai'); ?></button>
            </p>
            <p id="alma-geo-auto-status" style="font-weight:600;"></p>
            <ul id="alma-geo-auto-report"></ul>
            <table class="widefat striped" style="display:none;" id="alma-geo-auto-examples-wrap">
                <thead><tr><th>ID</th><th><?php esc_html_e('Titolo', 'affiliate-link-manager-ai'); ?></th><th><?php esc_html_e('Esito', 'affiliate-link-manager-ai'); ?></th><th><?php esc_html_e('Metodo', 'affiliate-link-manager-ai'); ?></th><th><?php esc_html_e('Località', 'affiliate-link-manager-ai'); ?></th></tr></thead>
                <tbody id="alma-geo-auto-examples"></tbody>
            </table>
        </div>

        <div class="card" style="max-width:960px;">
            <h2><?php esc_html_e('Coda di revisione', 'affiliate-link-manager-ai'); ?></h2>
            <p><?php esc_html_e('Proposte a confidenza media/bassa (gazetteer su slug/heading/contenuto, ambiguità, AI). Conferma o scarta in blocco; la prima località elencata diventa la primaria.', 'affiliate-link-manager-ai'); ?></p>
            <p>
                <button type="button" class="button" id="alma-geo-suggestions-load"><?php esc_html_e('Carica proposte', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button button-primary" id="alma-geo-suggestions-approve" disabled><?php esc_html_e('Conferma selezionate', 'affiliate-link-manager-ai'); ?></button>
                <button type="button" class="button" id="alma-geo-suggestions-reject" disabled><?php esc_html_e('Scarta selezionate', 'affiliate-link-manager-ai'); ?></button>
                <span id="alma-geo-suggestions-count"></span>
            </p>
            <table class="widefat striped">
                <thead><tr>
                    <th style="width:28px;"><input type="checkbox" id="alma-geo-suggestions-all" /></th>
                    <th><?php esc_html_e('Contenuto', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Tipo', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Località proposte', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Metodo', 'affiliate-link-manager-ai'); ?></th>
                    <th><?php esc_html_e('Confidenza', 'affiliate-link-manager-ai'); ?></th>
                </tr></thead>
                <tbody id="alma-geo-suggestions-body"><tr><td colspan="6"><?php esc_html_e('Premi "Carica proposte" per vedere la coda.', 'affiliate-link-manager-ai'); ?></td></tr></tbody>
            </table>
        </div>

        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var runningAll = false, stopRequested = false;
            var totals = {processed:0, auto_applied:0, suggested:0, unresolved:0, errors:0};
            function el(id){ return document.getElementById(id); }
            function esc(v){ return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
            function post(data){
                var body = new URLSearchParams(); Object.keys(data).forEach(function(k){
                    if (Array.isArray(data[k])) { data[k].forEach(function(v){ body.append(k + '[]', v); }); } else { body.set(k, data[k]); }
                });
                body.set('nonce', nonce);
                return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()})
                    .then(function(r){ return r.json(); })
                    .then(function(resp){ if (!resp || !resp.success) { throw new Error((resp && resp.data && resp.data.message) ? resp.data.message : 'Errore AJAX.'); } return resp.data; });
            }
            function setButtons(running){ el('alma-geo-auto-run-one').disabled = running; el('alma-geo-auto-run-all').disabled = running; el('alma-geo-auto-stop').disabled = !running; }
            function updateReport(data){
                ['processed','auto_applied','suggested','unresolved','errors'].forEach(function(k){ totals[k] += data[k] || 0; });
                el('alma-geo-auto-report').innerHTML =
                    '<li>Processati: ' + totals.processed + '</li>' +
                    '<li>Associati automaticamente: ' + totals.auto_applied + '</li>' +
                    '<li>In revisione: ' + totals.suggested + '</li>' +
                    '<li>Irrisolti: ' + totals.unresolved + '</li>' +
                    '<li>Errori: ' + totals.errors + '</li>' +
                    '<li>Non indicizzati rimanenti: ' + (data.remaining != null ? data.remaining : '–') + '</li>';
                var target = el('alma-geo-auto-target').value;
                var unindexedCell = document.querySelector('[data-alma-unindexed="' + target + '"]');
                if (unindexedCell && data.remaining != null) { unindexedCell.textContent = data.remaining; }
                var suggestedCell = document.querySelector('[data-alma-suggested="' + target + '"]');
                if (suggestedCell && data.suggested_total != null) { suggestedCell.textContent = data.suggested_total; }
                var rows = data.examples || [];
                if (rows.length) {
                    el('alma-geo-auto-examples-wrap').style.display = '';
                    el('alma-geo-auto-examples').innerHTML = rows.map(function(r){
                        return '<tr><td>' + esc(r.id) + '</td><td>' + esc(r.title) + '</td><td>' + esc(r.outcome) + '</td><td>' + esc(r.method) + '</td><td>' + esc(r.location) + '</td></tr>';
                    }).join('');
                }
            }
            function runOne(continueAll){
                setButtons(true);
                el('alma-geo-auto-status').textContent = 'Batch in corso...';
                post({
                    action: 'alma_geo_auto_index_batch',
                    target: el('alma-geo-auto-target').value,
                    batch_size: el('alma-geo-auto-batch-size').value,
                    use_ai: el('alma-geo-auto-use-ai').checked ? '1' : '0',
                    retry_unresolved: el('alma-geo-auto-retry-unresolved').checked ? '1' : '0'
                }).then(function(data){
                    updateReport(data);
                    if (continueAll && !stopRequested && !data.done && (data.processed || 0) > 0) {
                        el('alma-geo-auto-status').textContent = 'Batch completato, avvio il successivo...';
                        window.setTimeout(function(){ runOne(true); }, 400);
                        return;
                    }
                    runningAll = false; setButtons(false);
                    el('alma-geo-auto-status').textContent = stopRequested ? 'Elaborazione interrotta.' : (data.done ? 'Coda completata.' : 'Batch completato.');
                }).catch(function(err){
                    runningAll = false; setButtons(false);
                    el('alma-geo-auto-status').textContent = 'Errore: ' + err.message;
                });
            }
            el('alma-geo-auto-run-one').addEventListener('click', function(){ stopRequested = false; totals = {processed:0, auto_applied:0, suggested:0, unresolved:0, errors:0}; runOne(false); });
            el('alma-geo-auto-run-all').addEventListener('click', function(){ stopRequested = false; runningAll = true; totals = {processed:0, auto_applied:0, suggested:0, unresolved:0, errors:0}; runOne(true); });
            el('alma-geo-auto-stop').addEventListener('click', function(){ stopRequested = true; runningAll = false; el('alma-geo-auto-status').textContent = 'Interruzione richiesta: il batch corrente terminerà, poi il processo si fermerà.'; });

            function selectedIds(){
                return Array.prototype.slice.call(document.querySelectorAll('.alma-geo-suggestion-check:checked')).map(function(c){ return c.value; });
            }
            function refreshBulkButtons(){
                var any = selectedIds().length > 0;
                el('alma-geo-suggestions-approve').disabled = !any;
                el('alma-geo-suggestions-reject').disabled = !any;
            }
            function locationLabel(loc){
                var parts = [loc.name || loc.canonical_name || ''];
                if (loc.region) { parts.push(loc.region); }
                if (loc.country || loc.country_code) { parts.push(loc.country || loc.country_code); }
                return parts.filter(Boolean).join(', ');
            }
            function loadSuggestions(){
                el('alma-geo-suggestions-body').innerHTML = '<tr><td colspan="6">Caricamento…</td></tr>';
                post({action: 'alma_geo_auto_suggestions', offset: 0}).then(function(data){
                    var items = data.items || [];
                    el('alma-geo-suggestions-count').textContent = ' Totale in coda: ' + (data.total || 0);
                    if (!items.length) {
                        el('alma-geo-suggestions-body').innerHTML = '<tr><td colspan="6">Nessuna proposta in attesa.</td></tr>';
                        refreshBulkButtons();
                        return;
                    }
                    el('alma-geo-suggestions-body').innerHTML = items.map(function(item){
                        var locs = (item.locations || []).map(locationLabel).map(esc).join('<br>');
                        var title = '<a href="' + esc(item.edit_url) + '">' + esc(item.title || ('#' + item.id)) + '</a>';
                        return '<tr><td><input type="checkbox" class="alma-geo-suggestion-check" value="' + esc(item.id) + '"></td><td>' + title + '</td><td>' + esc(item.post_type) + '</td><td>' + locs + '</td><td>' + esc(item.method) + '</td><td>' + esc(item.confidence) + '</td></tr>';
                    }).join('');
                    Array.prototype.slice.call(document.querySelectorAll('.alma-geo-suggestion-check')).forEach(function(c){ c.addEventListener('change', refreshBulkButtons); });
                    refreshBulkButtons();
                }).catch(function(err){
                    el('alma-geo-suggestions-body').innerHTML = '<tr><td colspan="6">' + esc(err.message) + '</td></tr>';
                });
            }
            function suggestionAction(action){
                var ids = selectedIds();
                if (!ids.length) { return; }
                post({action: 'alma_geo_auto_suggestion_action', suggestion_action: action, ids: ids}).then(function(){
                    loadSuggestions();
                }).catch(function(err){ window.alert(err.message); });
            }
            el('alma-geo-suggestions-load').addEventListener('click', loadSuggestions);
            el('alma-geo-suggestions-approve').addEventListener('click', function(){ suggestionAction('approve'); });
            el('alma-geo-suggestions-reject').addEventListener('click', function(){ suggestionAction('reject'); });
            el('alma-geo-suggestions-all').addEventListener('change', function(){
                var checked = this.checked;
                Array.prototype.slice.call(document.querySelectorAll('.alma-geo-suggestion-check')).forEach(function(c){ c.checked = checked; });
                refreshBulkButtons();
            });
        }());
        </script>
        <?php
    }

    private function store_cumulative_geocoding_report($report, $session_id) {
        $report = is_array($report) ? $report : array();
        $session_id = sanitize_key($session_id ?: 'default');
        $current = get_user_meta(get_current_user_id(), 'alma_geo_affiliate_geocoding_last_report', true);
        if (empty($current) || !is_array($current) || sanitize_key($current['session_id'] ?? '') !== $session_id) {
            $current = array_merge($report, array(
                'session_id' => $session_id,
                'processed' => 0,
                'verified' => 0,
                'ambiguous' => 0,
                'failed' => 0,
                'skipped' => 0,
                'retry_later' => 0,
                'api_errors' => array(),
                'rows' => array(),
                'examples' => array(),
            ));
        }
        foreach (array('processed','verified','ambiguous','failed','skipped','retry_later') as $metric) {
            $current[$metric] = (int) ($current[$metric] ?? 0) + (int) ($report[$metric] ?? 0);
        }
        $current['date'] = current_time('mysql');
        $current['remaining_pending'] = (int) ($report['remaining_pending'] ?? ($current['remaining_pending'] ?? 0));
        $current['rate_limit_detected'] = !empty($current['rate_limit_detected']) || !empty($report['rate_limit_detected']);
        $current['api_errors'] = array_values(array_unique(array_merge((array) ($current['api_errors'] ?? array()), (array) ($report['api_errors'] ?? array()))));
        // Cap alle ultime 500 righe: senza limite il report cumulativo in user_meta
        // cresceva a ogni batch fino a righe usermeta enormi.
        $current['rows'] = array_slice(array_merge((array) ($current['rows'] ?? array()), (array) ($report['rows'] ?? array())), -500);
        $current['examples'] = array_slice(array_merge((array) ($current['examples'] ?? array()), (array) ($report['examples'] ?? array())), -5);
        $current['session_id'] = $session_id;
        update_user_meta(get_current_user_id(), 'alma_geo_affiliate_geocoding_last_report', $current);
    }

    public function download_geocoding_csv() {
        $this->verify_geocoding_download_request();
        $report = $this->current_geocoding_download_report();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alma-affiliate-geocoding-report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('ID località','nome località','canonical name','query usata','status precedente','status nuovo','lat','lng','place_id','formatted address','confidence','errore/messaggio','numero Link Affiliati collegati'));
        foreach ((array) ($report['rows'] ?? array()) as $row) {
            fputcsv($out, array((int) ($row['location_id'] ?? 0), $this->csv_safe_cell($row['name'] ?? ''), $this->csv_safe_cell($row['canonical_name'] ?? ''), $this->csv_safe_cell($row['query'] ?? ''), (string) ($row['previous_status'] ?? ''), (string) ($row['new_status'] ?? ''), (string) ($row['lat'] ?? ''), (string) ($row['lng'] ?? ''), $this->csv_safe_cell($row['place_id'] ?? ''), $this->csv_safe_cell($row['formatted_address'] ?? ''), (string) ($row['confidence'] ?? ''), $this->csv_safe_cell($row['message'] ?? ''), (int) ($row['affiliate_link_count'] ?? 0)));
        }
        fclose($out);
        exit;
    }

    public function download_geocoding_json() {
        $this->verify_geocoding_download_request();
        $report = $this->current_geocoding_download_report();
        unset($report['api_key'], $report['google_maps_api_key']);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="alma-affiliate-geocoding-log.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function verify_geocoding_download_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }
        check_admin_referer('alma_geo_download_geocoding_report');
    }

    private function current_geocoding_download_report() {
        $report = get_user_meta(get_current_user_id(), 'alma_geo_affiliate_geocoding_last_report', true);
        if (empty($report) || !is_array($report)) {
            $report = get_option(ALMA_Geo_Index_Geocoder::LAST_REPORT_OPTION, array());
        }
        return is_array($report) ? $report : array();
    }

    public function ajax_affiliate_import_status() {
        $this->verify_affiliate_import_ajax_request();
        $job_id = absint($_POST['job_id'] ?? 0);
        $job = $job_id ? $this->job_store->get_job($job_id) : $this->job_store->get_latest_job();
        if ($job && !$this->is_affiliate_import_job($job)) {
            wp_send_json_error(array('message' => __('Tipo job non valido.', 'affiliate-link-manager-ai')), 400);
        }
        wp_send_json_success($this->format_affiliate_job_response($job));
    }

    public function ajax_affiliate_import_batch() {
        $this->verify_affiliate_import_ajax_request();
        $job_id = absint($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(array('message' => __('job_id mancante.', 'affiliate-link-manager-ai')), 400);
        }
        $job = $this->job_store->get_job($job_id);
        if (!$job) {
            wp_send_json_error(array('message' => __('Sessione non trovata.', 'affiliate-link-manager-ai')), 404);
        }
        if (!$this->is_affiliate_import_job($job)) {
            wp_send_json_error(array('message' => __('Tipo job non valido.', 'affiliate-link-manager-ai')), 400);
        }
        if (in_array($job['status'], array('cancelled','completed','failed','needs_review'), true)) {
            wp_send_json_success($this->format_affiliate_job_response($job, 0, $this->affiliate_job_status_message($job)));
        }
        $batch_size = $this->sanitize_geo_affiliate_batch_size($_POST['batch_size'] ?? ($job['options']['batch_size'] ?? 50));
        $result = $this->affiliate_importer->process_job_batch($job_id, $batch_size, !empty($_POST['retry_errors']));
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'job' => $this->format_affiliate_job_response($job)['job'] ?? null,
                'counts' => $this->job_store->get_item_status_counts($job_id),
                'debug' => is_array($result->get_error_data()) ? $result->get_error_data() : array(),
            ), 400);
        }
        $job = $result['job'] ?? $this->job_store->get_job($job_id);
        $counts = $result['counts'] ?? $this->job_store->get_item_status_counts($job_id);
        $claimed = (int) ($result['claimed'] ?? 0);
        $processed = (int) ($result['processed'] ?? 0);
        $message = !empty($result['message']) ? sanitize_text_field($result['message']) : __('Batch processato.', 'affiliate-link-manager-ai');
        $terminal = !empty($job['status']) && in_array($job['status'], array('completed','cancelled','failed','needs_review'), true);
        if ($claimed === 0 && !empty($counts['queued'])) {
            $message = __('Nessun item claimato nonostante esistano item in coda.', 'affiliate-link-manager-ai');
        } elseif ($processed === 0 && !$terminal) {
            $message = !empty($result['message']) ? sanitize_text_field($result['message']) : __('Nessun record processabile trovato: controlla strumenti avanzati.', 'affiliate-link-manager-ai');
        }
        wp_send_json_success($this->format_affiliate_job_response($job, $processed, $message, $result));
    }

    public function ajax_cancel_affiliate_import_job() {
        $this->ajax_update_affiliate_import_job_status('cancelled');
    }

    public function ajax_pause_affiliate_import_job() {
        $this->verify_affiliate_import_ajax_request();
        wp_send_json_error(array('message' => __('La pausa non è supportata nel nuovo import manuale GEO. Usa Importa prossimo batch quando vuoi avanzare; nessun job automatico resta in esecuzione.', 'affiliate-link-manager-ai')), 400);
    }

    public function ajax_resume_affiliate_import_job() {
        $this->ajax_update_affiliate_import_job_status('queued');
    }

    private function ajax_update_affiliate_import_job_status($status) {
        $this->verify_affiliate_import_ajax_request();
        $job_id = absint($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(array('message' => __('job_id mancante.', 'affiliate-link-manager-ai')), 400);
        }
        $job = $this->job_store->get_job($job_id);
        if (!$job) {
            wp_send_json_error(array('message' => __('Sessione non trovata.', 'affiliate-link-manager-ai')), 404);
        }
        if (!$this->is_affiliate_import_job($job)) {
            wp_send_json_error(array('message' => __('Tipo job non valido.', 'affiliate-link-manager-ai')), 400);
        }
        $this->job_store->recount_job($job_id);
        $updated = $this->job_store->update_job_status($job_id, sanitize_key($status));
        if ($updated === false) {
            wp_send_json_error(array('message' => __('Stato job non supportato nel workflow manuale GEO.', 'affiliate-link-manager-ai')), 400);
        }
        $job = $this->job_store->get_job($job_id);
        wp_send_json_success($this->format_affiliate_job_response($job, 0, $this->affiliate_job_status_message($job)));
    }

    private function verify_affiliate_import_ajax_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        if (check_ajax_referer('alma_geo_affiliate_import_ajax', 'nonce', false) === false) {
            wp_send_json_error(array('message' => __('Nonce non valido. Ricarica la pagina e riprova.', 'affiliate-link-manager-ai')), 403);
        }
    }

    private function is_affiliate_import_job($job) {
        return !empty($job) && sanitize_key($job['job_type'] ?? '') === ALMA_Geo_Index_Job_Store::JOB_TYPE_AFFILIATE_LINKS_GEO_IMPORT;
    }

    private function render_affiliate_import_tab() {
        $preview = get_transient($this->affiliate_preview_key());
        $schema_status = $this->job_store->schema_status();
        $job = (!empty($schema_status['jobs_exists']) && !empty($schema_status['items_exists'])) ? $this->job_store->get_latest_job() : array();
        ?>
        <h2><?php esc_html_e('Import GEO Link Affiliati', 'affiliate-link-manager-ai'); ?></h2>
        <p><?php esc_html_e('Importa manualmente a batch le località geografiche da CSV AI, ad esempio sothra_geo_affiliate_links_index.csv, e associa ogni riga al CPT affiliate_link tramite affiliate_link_id. Nessuna chiamata Google Maps viene eseguita durante l’import.', 'affiliate-link-manager-ai'); ?></p>
        <?php if (empty($schema_status['items_exists'])) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html(sprintf(__('La tabella staging GEO non esiste: %s. Clicca Ripara tabelle GEO in Strumenti avanzati o disattiva/riattiva il plugin. Nessun Link Affiliato è stato modificato.', 'affiliate-link-manager-ai'), $schema_status['items_table'])); ?></p></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="postbox" style="padding:12px;">
            <?php wp_nonce_field('alma_geo_affiliate_import'); ?>
            <input type="hidden" name="alma_geo_index_action" value="preview_affiliate_csv">
            <h3><?php esc_html_e('A. Carica CSV', 'affiliate-link-manager-ai'); ?></h3>
            <input type="file" name="alma_geo_affiliate_csv" accept=".csv,text/csv,text/plain,application/csv,application/vnd.ms-excel" required>
            <?php submit_button(__('Valida e mostra preview', 'affiliate-link-manager-ai'), 'secondary', 'submit', false); ?>
        </form>
        <?php
        if (!empty($preview)) {
            $this->render_affiliate_preview($preview);
        }
        $this->render_affiliate_job_panel($job);
        $this->render_affiliate_job_script($job);
    }

    private function render_affiliate_preview($preview) {
        echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('B. Preview', 'affiliate-link-manager-ai') . '</h3>';
        echo '<p><strong>' . esc_html__('File:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($preview['file_name']) . ' — <strong>' . esc_html__('Record totali:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html((string) $preview['total']) . ' — <strong>' . esc_html__('Delimiter:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html($preview['delimiter']) . '</p>';
        if (empty($preview['valid'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Header mancanti:', 'affiliate-link-manager-ai') . ' ' . esc_html(implode(', ', $preview['missing'])) . '</p></div></div></div>';
            return;
        }
        if (!empty($preview['recommended_missing'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Header consigliati mancanti:', 'affiliate-link-manager-ai') . ' ' . esc_html(implode(', ', $preview['recommended_missing'])) . '</p></div>';
        }
        $display_headers = array_values(array_intersect(array('affiliate_link_id','post_title','affiliate_url','provider','source_name','widget_eligible','commercial_intent','activity_type','geo_scope','primary_name','primary_canonical_name'), (array) $preview['headers']));
        if (empty($display_headers)) {
            $display_headers = array_slice((array) $preview['headers'], 0, 10);
        }
        echo '<p><strong>' . esc_html__('Colonne rilevate:', 'affiliate-link-manager-ai') . '</strong> ' . esc_html(implode(', ', (array) $preview['headers'])) . '</p>';
        echo '<div style="max-width:100%;overflow-x:auto;"><table class="widefat striped"><thead><tr>';
        foreach ($display_headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_slice((array) $preview['rows'], 0, 10) as $row) {
            echo '<tr>';
            foreach ($display_headers as $header) {
                echo '<td>' . esc_html(wp_trim_words((string) ($row[$header] ?? ''), 12, '…')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        ?>
        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('alma_geo_affiliate_import'); ?>
            <input type="hidden" name="alma_geo_index_action" value="start_affiliate_import">
            <h3><?php esc_html_e('C. Prepara import', 'affiliate-link-manager-ai'); ?></h3><details><summary><?php esc_html_e('Opzioni avanzate', 'affiliate-link-manager-ai'); ?></summary><p><label><input type="checkbox" name="alma_geo_affiliate_overwrite" value="1"> <?php esc_html_e('Sovrascrivi dati Geo esistenti', 'affiliate-link-manager-ai'); ?></label></p>
            <p><label><input type="checkbox" name="alma_geo_affiliate_safe_only" value="1" checked> <?php esc_html_e('Importa solo safe_import', 'affiliate-link-manager-ai'); ?></label></p>
            <p><label><?php esc_html_e('Batch size iniziale', 'affiliate-link-manager-ai'); ?> <select name="alma_geo_affiliate_batch_size"><option value="25">25</option><option value="50" selected>50</option><option value="100">100</option><option value="250">250</option></select></label></p></details>
            <?php submit_button(__('Prepara import GEO', 'affiliate-link-manager-ai'), 'primary', 'submit', false); ?>
        </form>
        <?php
        echo '</div></div>';
    }

    private function render_affiliate_job_panel($job) {
        echo '<div class="postbox"><div class="inside" id="alma-geo-affiliate-job" data-job-id="' . esc_attr((string) absint($job['id'] ?? 0)) . '">';
        echo '<h3>' . esc_html__('A. Carica CSV', 'affiliate-link-manager-ai') . '</h3>';
        if (empty($job)) {
            echo '<p>' . esc_html__('Nessuna sessione Import GEO Link Affiliati ancora creata.', 'affiliate-link-manager-ai') . '</p>';
            echo '<hr><details style="margin-top:12px;" open><summary><strong>' . esc_html__('G. Strumenti avanzati', 'affiliate-link-manager-ai') . '</strong></summary>';
            echo '<div style="margin-top:12px;">';
            $this->render_geo_import_schema_tools();
            echo '</div></details></div></div>';
            return;
        }
        $total = (int) ($job['total_records'] ?? 0);
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><th>' . esc_html__('Nome file corrente', 'affiliate-link-manager-ai') . '</th><td>' . esc_html(sanitize_file_name($job['file_name'] ?? '')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Totale record rilevati', 'affiliate-link-manager-ai') . '</th><td>' . esc_html((string) $total) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Stato sessione', 'affiliate-link-manager-ai') . '</th><td><span data-alma-job-status>' . esc_html($this->job_store->get_public_session_status($job)) . '</span></td></tr>';
        echo '</tbody></table>';

        echo '<hr><h3>' . esc_html__('D. Importazione', 'affiliate-link-manager-ai') . '</h3>';
        $this->render_affiliate_job_summary($job);
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0;">';
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><span>' . esc_html__('Batch size', 'affiliate-link-manager-ai') . '</span>' . $this->render_affiliate_batch_size_select($job, 'alma-geo-affiliate-batch-size') . '</label>';
        echo '<button type="button" class="button button-primary" id="alma-geo-affiliate-process-next">' . esc_html__('Importa prossimo batch', 'affiliate-link-manager-ai') . '</button>';
        echo '</div>';

        echo '<hr><h3>' . esc_html__('E. Report', 'affiliate-link-manager-ai') . '</h3>';
        echo '<div id="alma-geo-affiliate-job-live"><p>' . esc_html__('Report ultimo batch non ancora disponibile.', 'affiliate-link-manager-ai') . '</p></div>';
        $this->render_affiliate_public_report((int) $job['id']);
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;">';
        $this->render_affiliate_job_button('download_affiliate_log', __('Scarica report CSV', 'affiliate-link-manager-ai'), $job);
        $this->render_affiliate_job_button('download_affiliate_json_log', __('Scarica log JSON', 'affiliate-link-manager-ai'), $job);
        echo '</div>';

        echo '<hr>';
        $this->render_affiliate_geocoding_google_box();

        echo '<hr><details style="margin-top:12px;"><summary><strong>' . esc_html__('G. Strumenti avanzati', 'affiliate-link-manager-ai') . '</strong></summary>';
        echo '<div style="margin-top:12px;">';
        $this->render_geo_import_schema_tools();
        $this->render_affiliate_job_button('reset_affiliate_import', __('Reset import', 'affiliate-link-manager-ai'), $job);
        $this->render_affiliate_job_diagnostic((int) $job['id']);
        $this->render_affiliate_job_logs((int) $job['id']);
        echo '</div></details>';
        echo '</div></div>';
    }

    private function render_affiliate_job_summary($job) {
        $total = (int) ($job['total_records'] ?? 0);
        $processed = (int) ($job['processed_records'] ?? 0);
        $remaining = max(0, $total - $processed);
        $percent = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 0;
        echo '<div id="alma-geo-affiliate-summary" data-job-id="' . esc_attr((string) absint($job['id'] ?? 0)) . '">';
        echo '<div class="alma-geo-progress" style="position:relative;background:#f0f0f1;border:1px solid #c3c4c7;height:24px;max-width:720px;overflow:hidden;">';
        echo '<div data-alma-progress-bar style="background:#2271b1;height:24px;width:' . esc_attr((string) $percent) . '%;transition:width .2s ease;"></div>';
        echo '<span data-alma-progress-label style="position:absolute;left:8px;top:3px;font-weight:600;color:#1d2327;">' . esc_html(sprintf(__('%1$s%% — %2$d/%3$d record', 'affiliate-link-manager-ai'), (string) $percent, $processed, $total)) . '</span></div>';
        echo '<p data-alma-progress-text>' . esc_html(sprintf(__('Processati: %1$d. Rimanenti: %2$d. Importati: %3$d. Aggiornati: %4$d. Saltati: %5$d. Errori: %6$d.', 'affiliate-link-manager-ai'), $processed, $remaining, (int) $job['imported_records'], (int) $job['updated_records'], (int) $job['skipped_records'], (int) $job['error_records'])) . '</p>';
        echo '<p data-alma-job-message>' . esc_html($this->affiliate_job_status_message($job)) . '</p>';
        echo '<div data-alma-last-batch-error style="display:none;border-left:4px solid #b32d2e;background:#fcf0f1;padding:10px 12px;margin:12px 0;max-width:720px;"><strong>' . esc_html__('Errore ultimo batch', 'affiliate-link-manager-ai') . '</strong><div data-alma-last-batch-error-body></div></div>';
        echo '</div>';
    }

    private function render_affiliate_batch_size_select($job, $id = '') {
        $current = $this->sanitize_geo_affiliate_batch_size($job['options']['batch_size'] ?? 50);
        $html = '<select' . ($id ? ' id="' . esc_attr($id) . '"' : '') . '>';
        foreach (array(25, 50, 100, 250) as $size) {
            $html .= '<option value="' . esc_attr((string) $size) . '"' . selected($current, $size, false) . '>' . esc_html((string) $size) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function render_affiliate_public_report($job_id) {
        $report = $this->job_store->get_report($job_id);
        $keys = array(
            'records_read' => __('righe lette', 'affiliate-link-manager-ai'),
            'items_created' => __('item staging creati', 'affiliate-link-manager-ai'),
            'rows_discarded' => __('righe scartate', 'affiliate-link-manager-ai'),
            'records_processed' => __('processati', 'affiliate-link-manager-ai'),
            'records_remaining' => __('rimanenti', 'affiliate-link-manager-ai'),
            'imported' => __('importati', 'affiliate-link-manager-ai'),
            'updated' => __('aggiornati', 'affiliate-link-manager-ai'),
            'skipped' => __('saltati', 'affiliate-link-manager-ai'),
            'errors' => __('errori', 'affiliate-link-manager-ai'),
            'needs_review' => __('da verificare', 'affiliate-link-manager-ai'),
            'geo_assigned' => __('geografia assegnata', 'affiliate-link-manager-ai'),
        );
        echo '<h4>' . esc_html__('Report cumulativo', 'affiliate-link-manager-ai') . '</h4>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody data-alma-public-report>';
        foreach ($keys as $key => $label) {
            $value = $report[$key] ?? 0;
            if (is_array($value)) {
                $value = wp_json_encode($value);
            }
            echo '<tr><th>' . esc_html($label) . '</th><td data-alma-report="' . esc_attr($key) . '">' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($report['discard_reasons'])) {
            echo '<h4>' . esc_html__('Motivi righe scartate', 'affiliate-link-manager-ai') . '</h4><table class="widefat striped" style="max-width:720px;"><tbody data-alma-discard-reasons>';
            foreach ((array) $report['discard_reasons'] as $reason => $count) {
                echo '<tr><th>' . esc_html($reason) . '</th><td>' . esc_html((string) absint($count)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!empty($report['discard_examples'])) {
            echo '<h4>' . esc_html__('Ultimi 10 esempi scartati', 'affiliate-link-manager-ai') . '</h4><div style="max-width:100%;overflow-x:auto;"><table class="widefat striped"><thead><tr><th>Riga CSV</th><th>affiliate_link_id</th><th>post_title</th><th>affiliate_url</th><th>primary_name</th><th>final_bucket</th><th>safe_for_auto_import</th><th>Motivo</th></tr></thead><tbody data-alma-discard-examples>';
            foreach ((array) $report['discard_examples'] as $example) {
                $title = $example['post_title'] ?? ($example['title'] ?? '');
                echo '<tr><td>' . esc_html((string) ($example['row_number'] ?? '')) . '</td><td>' . esc_html((string) ($example['affiliate_link_id'] ?? '')) . '</td><td>' . esc_html($title) . '</td><td>' . esc_html($example['affiliate_url'] ?? '') . '</td><td>' . esc_html($example['primary_name'] ?? '') . '</td><td>' . esc_html($example['final_bucket'] ?? '') . '</td><td>' . esc_html($example['safe_for_auto_import'] ?? '') . '</td><td>' . esc_html($example['reason'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    private function render_affiliate_geocoding_google_box() {
        $geocoding_url = add_query_arg(array('post_type' => 'affiliate_link', 'page' => self::MENU_SLUG, 'tab' => 'geocoding'), admin_url('edit.php'));
        echo '<div class="postbox" style="margin-top:12px;"><div class="inside"><h3>' . esc_html__('F. Geocoding Google', 'affiliate-link-manager-ai') . '</h3>';
        echo '<p>' . esc_html__('Il geocoding non viene eseguito durante l’import batch. I dati importati possono alimentare la tab Geocoding/località pending in una fase separata e controllata.', 'affiliate-link-manager-ai') . '</p>';
        echo '<p><a href="' . esc_url($geocoding_url) . '#alma-geo-pending-locations">' . esc_html__('Apri Geocoding / località pending', 'affiliate-link-manager-ai') . '</a></p>';
        $fields = array(
            '_alma_geo_source' => __('luogo sorgente / source', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_canonical_name' => __('località normalizzata', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_region' => __('regione', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_country' => __('paese', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_lat' => __('latitudine', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_lng' => __('longitudine', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_place_id' => __('Google Place ID', 'affiliate-link-manager-ai'),
            '_alma_geo_primary_formatted_address' => __('formatted address', 'affiliate-link-manager-ai'),
            '_alma_geo_geocoding_status' => __('geocoding status', 'affiliate-link-manager-ai'),
            '_alma_geo_confidence' => __('geocoding confidence', 'affiliate-link-manager-ai'),
            '_alma_geo_updated_at' => __('data ultimo geocoding/import', 'affiliate-link-manager-ai'),
            'alma_geo_locations.geocoding_error' => __('messaggio errore geocoding', 'affiliate-link-manager-ai'),
        );
        echo '<ul style="list-style:disc;margin-left:20px;">';
        foreach ($fields as $key => $label) {
            echo '<li><code>' . esc_html($key) . '</code> — ' . esc_html($label) . '</li>';
        }
        echo '</ul></div></div>';
    }


    private function render_geo_import_schema_tools() {
        $status = $this->job_store->schema_status();
        $jobs_state = !empty($status['jobs_exists']) ? __('presente', 'affiliate-link-manager-ai') : __('mancante', 'affiliate-link-manager-ai');
        $items_state = !empty($status['items_exists']) ? __('presente', 'affiliate-link-manager-ai') : __('mancante', 'affiliate-link-manager-ai');
        echo '<h4>' . esc_html__('Stato tabelle GEO', 'affiliate-link-manager-ai') . '</h4>';
        echo '<table class="widefat striped" style="max-width:720px;margin-bottom:10px;"><tbody>';
        echo '<tr><th>' . esc_html__('Tabella jobs', 'affiliate-link-manager-ai') . '</th><td><code>' . esc_html($status['jobs_table']) . '</code> — ' . esc_html($jobs_state) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Tabella job items', 'affiliate-link-manager-ai') . '</th><td><code>' . esc_html($status['items_table']) . '</code> — ' . esc_html($items_state) . '</td></tr>';
        echo '</tbody></table>';
        if (empty($status['items_exists'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('La tabella staging GEO non esiste: %s. Clicca Ripara tabelle GEO o disattiva/riattiva il plugin. Nessun Link Affiliato è stato modificato.', 'affiliate-link-manager-ai'), $status['items_table'])) . '</p></div>';
        }
        $repair = get_transient($this->geo_schema_repair_key());
        if (is_array($repair)) {
            $this->render_geo_schema_repair_diagnostic($repair);
        }
        echo '<form method="post" style="display:inline-block;margin:0 8px 12px 0;">';
        wp_nonce_field('alma_geo_affiliate_import');
        echo '<input type="hidden" name="alma_geo_index_action" value="repair_geo_import_tables">';
        submit_button(__('Ripara tabelle GEO', 'affiliate-link-manager-ai'), 'secondary small', 'submit', false);
        echo '</form>';
    }


    private function render_geo_schema_repair_diagnostic($repair) {
        $before = is_array($repair['before'] ?? null) ? $repair['before'] : array();
        $after = is_array($repair['after'] ?? null) ? $repair['after'] : array();
        $dbdelta = is_array($repair['dbdelta'] ?? null) ? $repair['dbdelta'] : array();
        $rows = array(
            __('Tabella jobs richiesta', 'affiliate-link-manager-ai') => $repair['jobs_table'] ?? ($repair['requested_tables']['jobs'] ?? ''),
            __('Tabella job items richiesta', 'affiliate-link-manager-ai') => $repair['items_table'] ?? ($repair['requested_tables']['items'] ?? ''),
            __('Jobs esisteva prima del repair', 'affiliate-link-manager-ai') => !empty($before['jobs_exists']) ? 'yes' : 'no',
            __('Job items esisteva prima del repair', 'affiliate-link-manager-ai') => !empty($before['items_exists']) ? 'yes' : 'no',
            __('Jobs esiste dopo il repair', 'affiliate-link-manager-ai') => !empty($after['jobs_exists']) ? 'yes' : 'no',
            __('Job items esiste dopo il repair', 'affiliate-link-manager-ai') => !empty($after['items_exists']) ? 'yes' : 'no',
            __('Risultato dbDelta jobs', 'affiliate-link-manager-ai') => implode(' | ', (array) ($dbdelta['jobs'] ?? array())),
            __('Risultato dbDelta job items', 'affiliate-link-manager-ai') => implode(' | ', (array) ($dbdelta['items'] ?? array())),
            __('wpdb last_error', 'affiliate-link-manager-ai') => sanitize_textarea_field($repair['last_error'] ?? ''),
            __('MySQL error', 'affiliate-link-manager-ai') => sanitize_textarea_field($repair['mysql_error'] ?? ''),
            __('SQLSTATE', 'affiliate-link-manager-ai') => sanitize_text_field($repair['sqlstate'] ?? ''),
            __('Tabelle item con nome diverso', 'affiliate-link-manager-ai') => implode(', ', (array) ($repair['variant_item_tables'] ?? array())),
            __('Messaggio operativo', 'affiliate-link-manager-ai') => sanitize_textarea_field($repair['message'] ?? ''),
        );
        echo '<details style="max-width:920px;margin:10px 0 14px;"><summary><strong>' . esc_html__('Diagnostica avanzata repair GEO', 'affiliate-link-manager-ai') . '</strong></summary>';
        echo '<p>' . esc_html__('Dettagli tecnici sicuri per capire perché dbDelta ha creato o non ha creato la tabella staging. Non contiene SQL completo né path server.', 'affiliate-link-manager-ai') . '</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    private function render_affiliate_job_diagnostic($job_id) {
        $diagnostic = $this->job_store->get_job_diagnostic($job_id);
        $job = $this->job_store->get_job($job_id);
        $options = is_array($job['options'] ?? null) ? $job['options'] : array();
        $rows = array(
            'colonne CSV rilevate' => implode(', ', (array) ($options['csv_headers'] ?? array())),
            'colonne obbligatorie mancanti' => implode(', ', (array) ($options['required_missing'] ?? array())),
            'numero righe lette' => (int) ($job['total_records'] ?? 0),
            'item staging creati' => (int) ($options['items_created'] ?? 0),
            'righe scartate' => (int) ($options['rows_discarded'] ?? 0),
            'motivi di scarto' => wp_json_encode((array) ($options['discard_reasons'] ?? array())),
            'ultimi errori SQL' => implode(' | ', (array) ($options['sql_errors'] ?? array())),
            'stato interno' => sanitize_key($job['status'] ?? ''),
            'total_records' => (int) ($job['total_records'] ?? 0),
            'total items in DB' => (int) ($diagnostic['total_item_rows'] ?? 0),
            'queued' => (int) ($diagnostic['queued'] ?? 0),
            'processing' => (int) ($diagnostic['processing'] ?? 0),
            'imported' => (int) ($diagnostic['imported'] ?? 0),
            'updated' => (int) ($diagnostic['updated'] ?? 0),
            'skipped' => (int) ($diagnostic['skipped'] ?? 0),
            'error' => (int) ($diagnostic['error'] ?? 0),
            'last_error' => sanitize_textarea_field($diagnostic['last_error'] ?? ''),
            'batch_size' => (int) ($diagnostic['batch_size'] ?? 0),
            'safe_only' => !empty($diagnostic['safe_only']) ? 'true' : 'false',
            'overwrite' => !empty($diagnostic['overwrite']) ? 'true' : 'false',
            'ultimo batch claimed' => '',
            'ultimo batch processed' => '',
            'ultimo errore AJAX' => '',
        );
        echo '<details data-alma-diagnostic-details style="margin:12px 0;max-width:920px;"><summary><strong>' . esc_html__('Diagnostica tecnica', 'affiliate-link-manager-ai') . '</strong></summary>';
        echo '<table class="widefat striped" style="margin-top:8px;"><tbody data-alma-job-diagnostic>';
        foreach ($rows as $key => $value) {
            echo '<tr><th>' . esc_html($key) . '</th><td data-alma-diagnostic="' . esc_attr($key) . '">' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    private function render_affiliate_job_button($action, $label, $job) {
        echo '<form method="post" style="display:inline-block;margin:0;">';
        wp_nonce_field('alma_geo_affiliate_import');
        echo '<input type="hidden" name="alma_geo_index_action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="job_id" value="' . esc_attr((string) absint($job['id'] ?? 0)) . '">';
        $attrs = $action === 'reset_affiliate_import' ? array(
            'onclick' => "return confirm('" . esc_js(__('Reset import cancellerà solo lo stato/sessione GEO e non eliminerà i Link Affiliati già importati. Continuare?', 'affiliate-link-manager-ai')) . "');",
        ) : array();
        submit_button($label, 'secondary small', 'submit', false, $attrs);
        echo '</form>';
    }

    private function render_affiliate_job_logs($job_id) {
        $items = $this->job_store->get_items($job_id, 50);
        echo '<h4>' . esc_html__('Ultimi 50 log item', 'affiliate-link-manager-ai') . '</h4><div style="max-width:100%;overflow:auto;"><table class="widefat striped"><thead><tr><th>Riga</th><th>affiliate_link_id</th><th>Status</th><th>Azione</th><th>Messaggio</th><th>Processato</th></tr></thead><tbody data-alma-job-logs>';
        if (empty($items)) {
            $counts = $this->job_store->get_item_status_counts($job_id);
            if (!empty($counts['queued'])) {
                echo '<tr><td colspan="6">' . esc_html(sprintf(__('Item in coda: %d', 'affiliate-link-manager-ai'), (int) $counts['queued'])) . '</td></tr>';
            } else {
                echo '<tr><td colspan="6">' . esc_html__('Nessun log.', 'affiliate-link-manager-ai') . '</td></tr>';
            }
        }
        foreach ($items as $item) {
            echo '<tr><td>' . esc_html((string) $item['row_number']) . '</td><td>' . esc_html((string) $item['object_id']) . '</td><td>' . esc_html($item['status']) . '</td><td>' . esc_html($item['action']) . '</td><td>' . esc_html($item['message']) . '</td><td>' . esc_html((string) $item['processed_at']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_affiliate_job_script($job) {
        if (empty($job)) {
            return;
        }
        $nonce = wp_create_nonce('alma_geo_affiliate_import_ajax');
        ?>
        <script>
        (function(){
            var jobId = <?php echo (int) $job['id']; ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var running = false;
            var processButton = document.getElementById('alma-geo-affiliate-process-next');
            var lastBatchFailed = false;
            var lastJob = <?php echo wp_json_encode(array('status' => sanitize_key($job['status'] ?? ''), 'total_records' => (int) ($job['total_records'] ?? 0), 'processed_records' => (int) ($job['processed_records'] ?? 0))); ?>;

            function text(value) {
                return String(value == null ? '' : value).replace(/[&<>'"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]; });
            }
            function percent(job) {
                return job && job.total_records > 0 ? Math.min(100, Math.round((job.processed_records / job.total_records) * 1000) / 10) : 0;
            }
            function getBatchSize() {
                var field = document.getElementById('alma-geo-affiliate-batch-size');
                return field ? field.value : 50;
            }
            function post(action, data) {
                var body = new URLSearchParams(Object.assign({action: action, nonce: nonce, job_id: jobId, batch_size: getBatchSize()}, data || {}));
                return fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()}).then(function(r){
                    return r.text().then(function(raw){
                        var parsed;
                        try { parsed = JSON.parse(raw); } catch (e) {
                            var invalid = new Error('<?php echo esc_js(__('Risposta AJAX non valida.', 'affiliate-link-manager-ai')); ?>');
                            invalid.httpStatus = r.status;
                            invalid.raw = raw;
                            throw invalid;
                        }
                        if (!r.ok || !parsed.success) {
                            var ajaxError = new Error((parsed.data && parsed.data.message) ? parsed.data.message : ('HTTP ' + r.status));
                            ajaxError.httpStatus = r.status;
                            ajaxError.raw = raw;
                            ajaxError.data = parsed.data || null;
                            throw ajaxError;
                        }
                        return parsed.data;
                    });
                });
            }
            function render(data) {
                if (!data || !data.job) { return; }
                var j = data.job;
                var pct = percent(j);
                var status = document.querySelector('[data-alma-job-status]');
                var bar = document.querySelector('[data-alma-progress-bar]');
                var label = document.querySelector('[data-alma-progress-label]');
                var progress = document.querySelector('[data-alma-progress-text]');
                var message = document.querySelector('[data-alma-job-message]');
                var logs = document.querySelector('[data-alma-job-logs]');
                lastJob = j;
                if (status) { status.textContent = j.session_status || j.status; }
                if (bar) { bar.style.width = pct + '%'; }
                if (label) { label.textContent = pct + '% — ' + j.processed_records + '/' + j.total_records + ' record'; }
                if (progress) { progress.textContent = 'Processati: ' + j.processed_records + '. Rimanenti: ' + Math.max(0, j.total_records - j.processed_records) + '. Importati: ' + j.imported_records + '. Aggiornati: ' + j.updated_records + '. Saltati: ' + j.skipped_records + '. Errori: ' + j.error_records + '.'; }
                clearError();
                if (message) { message.textContent = data.message || ''; message.style.color = ''; }
                updateButtonState(j, false);
                if (data.counts) {
                    Object.keys(data.counts).forEach(function(key){
                        var cell = document.querySelector('[data-alma-count="' + key + '"]');
                        if (cell) { cell.textContent = data.counts[key]; }
                    });
                }
                if (logs && data.items) {
                    logs.innerHTML = data.items.length ? data.items.map(function(item){
                        return '<tr><td>' + text(item.row_number) + '</td><td>' + text(item.affiliate_link_id) + '</td><td>' + text(item.status) + '</td><td>' + text(item.action) + '</td><td>' + text(item.message) + '</td><td>' + text(item.processed_at) + '</td></tr>';
                    }).join('') : '<tr><td colspan="6"><?php echo esc_js(__('Nessun log.', 'affiliate-link-manager-ai')); ?></td></tr>';
                }
                updateDiagnostic(data);
                renderPublicReport(data.report || {});
                renderBatchReport(data);
            }
            function renderPublicReport(report) {
                Object.keys(report || {}).forEach(function(key){
                    var cell = document.querySelector('[data-alma-report="' + key + '"]');
                    if (cell) { cell.textContent = report[key] == null ? '0' : report[key]; }
                });
            }
            function renderBatchReport(data) {
                var target = document.getElementById('alma-geo-affiliate-job-live');
                if (!target || !data) { return; }
                var report = data.batch_report || {};
                var rows = ['processed','claimed','imported','updated','skipped','geo_assigned','needs_review','errors'].map(function(key){
                    return '<tr><th>' + text(key) + '</th><td>' + text(report[key] || 0) + '</td></tr>';
                }).join('');
                target.innerHTML = '<h4><?php echo esc_js(__('Report ultimo batch', 'affiliate-link-manager-ai')); ?></h4><table class="widefat striped" style="max-width:720px;"><tbody>' + rows + '</tbody></table>';
            }
            function updateDiagnostic(data) {
                var diagnostic = data && data.diagnostic ? data.diagnostic : {};
                var counts = data && data.counts ? data.counts : {};
                var values = {
                    'total_records': data && data.job ? data.job.total_records : '',
                    'total items in DB': diagnostic.total_item_rows || 0,
                    'queued': counts.queued != null ? counts.queued : diagnostic.queued,
                    'processing': counts.processing != null ? counts.processing : diagnostic.processing,
                    'imported': counts.imported != null ? counts.imported : diagnostic.imported,
                    'updated': counts.updated != null ? counts.updated : diagnostic.updated,
                    'skipped': counts.skipped != null ? counts.skipped : diagnostic.skipped,
                    'error': counts.error != null ? counts.error : diagnostic.error,
                    'last_error': diagnostic.last_error || '',
                    'batch_size': diagnostic.batch_size || '',
                    'safe_only': diagnostic.safe_only ? 'true' : 'false',
                    'overwrite': diagnostic.overwrite ? 'true' : 'false',
                    'ultimo batch claimed': data && data.claimed != null ? data.claimed : '',
                    'ultimo batch processed': data && data.processed != null ? data.processed : ''
                };
                Object.keys(values).forEach(function(key){
                    var cell = document.querySelector('[data-alma-diagnostic="' + key + '"]');
                    if (cell) { cell.textContent = values[key] == null ? '' : values[key]; }
                });
            }
            function setAjaxErrorDiagnostic(value) {
                var cell = document.querySelector('[data-alma-diagnostic="ultimo errore AJAX"]');
                if (cell) { cell.textContent = value || ''; }
            }
            function clearError() {
                var box = document.querySelector('[data-alma-last-batch-error]');
                var body = document.querySelector('[data-alma-last-batch-error-body]');
                lastBatchFailed = false;
                if (box) { box.style.display = 'none'; }
                if (body) { body.innerHTML = ''; }
                setAjaxErrorDiagnostic('');
            }
            function isTerminal(job) {
                return job && ['completed','cancelled','failed','needs_review'].indexOf(job.status) !== -1;
            }
            function updateButtonState(job, inFlight) {
                if (!processButton) { return; }
                if (inFlight) {
                    processButton.disabled = true;
                    processButton.textContent = '<?php echo esc_js(__('Processamento…', 'affiliate-link-manager-ai')); ?>';
                    return;
                }
                processButton.textContent = isTerminal(job) ? '<?php echo esc_js(__('Batch non disponibile', 'affiliate-link-manager-ai')); ?>' : '<?php echo esc_js(__('Importa prossimo batch', 'affiliate-link-manager-ai')); ?>';
                processButton.disabled = isTerminal(job);
                if (isTerminal(job)) {
                    processButton.title = '<?php echo esc_js(__('Sessione completata, annullata, fallita o da verificare.', 'affiliate-link-manager-ai')); ?>';
                } else {
                    processButton.title = '';
                }
            }
            function showError(error) {
                lastBatchFailed = true;
                var msg = error && error.message ? error.message : '<?php echo esc_js(__('Errore sconosciuto.', 'affiliate-link-manager-ai')); ?>';
                var status = error && error.httpStatus ? error.httpStatus : '';
                                var now = new Date().toLocaleString();
                var message = document.querySelector('[data-alma-job-message]');
                var box = document.querySelector('[data-alma-last-batch-error]');
                var body = document.querySelector('[data-alma-last-batch-error-body]');
                if (message) {
                    message.textContent = '<?php echo esc_js(__('Il batch non è stato processato.', 'affiliate-link-manager-ai')); ?> ' + msg;
                    message.style.color = '#b32d2e';
                }
                setAjaxErrorDiagnostic(now + ' — HTTP ' + (status || 'n/d') + ' — ' + msg);
                if (box && body) {
                    box.style.display = 'block';
                    body.innerHTML = '<p><strong><?php echo esc_js(__('Messaggio:', 'affiliate-link-manager-ai')); ?></strong> ' + text(msg) + '</p>' +
                        '<p><strong>HTTP status:</strong> ' + text(status || '<?php echo esc_js(__('n/d', 'affiliate-link-manager-ai')); ?>') + '</p>' +
                        '<p><strong>Timestamp:</strong> ' + text(now) + '</p>' +
                        '<p><?php echo esc_js(__('Suggerimento: consulta Strumenti avanzati o scarica log.', 'affiliate-link-manager-ai')); ?></p>';
                }
            }
            function processOnce(manual) {
                if (running) { return Promise.resolve(); }
                running = true;
                updateButtonState(lastJob, true);
                return post('alma_geo_process_affiliate_link_import_job').then(function(data){
                    render(data);
                    var j = data.job;
                    running = false;
                    updateButtonState(j, false);

                }).catch(function(error){
                    running = false;
                    updateButtonState(lastJob, false);
                    showError(error);
                });
            }
            updateButtonState(lastJob, false);
            if (processButton) {
                processButton.addEventListener('click', function(){ processOnce(true); });
            }

        })();
        </script>
        <?php
    }

    private function format_affiliate_job_response($job, $batch_processed = 0, $message = '', $batch_result = array()) {
        if (empty($job)) {
            return array('processed' => 0, 'claimed' => 0, 'job' => null, 'counts' => array(), 'items' => array(), 'report' => array(), 'debug' => array(), 'diagnostic' => array(), 'message' => __('Sessione non trovata.', 'affiliate-link-manager-ai'));
        }
        $total = (int) $job['total_records'];
        $processed = (int) $job['processed_records'];
        $percent = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 0;
        $items = array();
        foreach ($this->job_store->get_items((int) $job['id'], 50) as $item) {
            $items[] = array(
                'row_number' => (int) $item['row_number'],
                'affiliate_link_id' => (int) $item['object_id'],
                'status' => sanitize_key($item['status']),
                'action' => sanitize_key($item['action']),
                'message' => sanitize_textarea_field($item['message']),
                'processed_at' => sanitize_text_field($item['processed_at']),
            );
        }
        return array(
            'processed' => (int) $batch_processed,
            'claimed' => (int) ($batch_result['claimed'] ?? 0),
            'total_records' => $total,
            'processed_records' => $processed,
            'imported_records' => (int) $job['imported_records'],
            'updated_records' => (int) $job['updated_records'],
            'skipped_records' => (int) $job['skipped_records'],
            'error_records' => (int) $job['error_records'],
            'percent' => $percent,
            'status' => sanitize_key($job['status']),
            'session_status' => $this->job_store->get_public_session_status($job),
            'message' => $message ?: $this->affiliate_job_status_message($job),
            'job' => array(
                'id' => (int) $job['id'],
                'status' => sanitize_key($job['status']),
                'session_status' => $this->job_store->get_public_session_status($job),
                'file_name' => sanitize_file_name($job['file_name']),
                'total_records' => $total,
                'processed_records' => $processed,
                'imported_records' => (int) $job['imported_records'],
                'updated_records' => (int) $job['updated_records'],
                'skipped_records' => (int) $job['skipped_records'],
                'error_records' => (int) $job['error_records'],
                'percent' => $percent,
            ),
            'counts' => !empty($batch_result['counts']) && is_array($batch_result['counts']) ? $batch_result['counts'] : $this->job_store->get_item_status_counts((int) $job['id']),
            'items' => $items,
            'report' => $this->job_store->get_report((int) $job['id']),
            'batch_report' => $this->format_affiliate_batch_report($batch_result),
            'debug' => !empty($batch_result['debug']) && is_array($batch_result['debug']) ? $batch_result['debug'] : array(),
            'diagnostic' => !empty($batch_result['diagnostic']) && is_array($batch_result['diagnostic']) ? $batch_result['diagnostic'] : $this->job_store->get_job_diagnostic((int) $job['id'], array('last_ajax_message' => $message)),
        );
    }


    private function format_affiliate_batch_report($batch_result) {
        $report = array(
            'processed' => (int) ($batch_result['processed'] ?? 0),
            'claimed' => (int) ($batch_result['claimed'] ?? 0),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'already_present' => 0,
            'duplicates' => 0,
            'invalid_urls' => 0,
            'incomplete_records' => 0,
            'unknown_locations' => 0,
            'missing_region' => 0,
            'geo_assigned' => 0,
            'needs_review' => 0,
            'errors' => 0,
        );
        foreach ((array) ($batch_result['item_results'] ?? ($batch_result['debug']['item_results'] ?? array())) as $item) {
            $status = sanitize_key($item['status'] ?? '');
            $message = (string) ($item['message'] ?? '');
            if (isset($report[$status])) {
                $report[$status]++;
            }
            if ($status === 'error') {
                $report['errors']++;
            }
            if (strpos($message, 'existing_geo_skipped') !== false) { $report['already_present']++; }
            if (strpos($message, 'duplicate') !== false) { $report['duplicates']++; }
            if (strpos($message, 'invalid_affiliate_url') !== false || strpos($message, 'invalid_url') !== false) { $report['invalid_urls']++; }
            if (strpos($message, 'incomplete_record') !== false) { $report['incomplete_records']++; }
            if (strpos($message, 'unknown_location') !== false || strpos($message, 'missing_primary_name') !== false) { $report['unknown_locations']++; }
            if (strpos($message, 'missing_region') !== false) { $report['missing_region']++; }
            if (strpos($message, 'needs_review') !== false) { $report['needs_review']++; }
        }
        $report['geo_assigned'] = $report['imported'] + $report['updated'];
        return $report;
    }

    private function affiliate_job_status_message($job) {
        if (empty($job)) {
            return __('Sessione non trovata.', 'affiliate-link-manager-ai');
        }
        $processed = (int) ($job['processed_records'] ?? 0);
        $total = (int) ($job['total_records'] ?? 0);
        $status = sanitize_key($job['status'] ?? '');
        if ($status === 'cancelled') {
            return $processed > 0 ? sprintf(__('Sessione annullata. Record processati: %1$d/%2$d.', 'affiliate-link-manager-ai'), $processed, $total) : __('Sessione annullata prima dell’elaborazione.', 'affiliate-link-manager-ai');
        }
        if ($status === 'completed') {
            return sprintf(__('Sessione completata. Record processati: %1$d/%2$d.', 'affiliate-link-manager-ai'), $processed, $total);
        }
        if ($status === 'failed') {
            return __('Sessione fallita. Controlla gli errori e il log item.', 'affiliate-link-manager-ai');
        }
        if ($status === 'needs_review') {
            $last_error = trim(sanitize_textarea_field($job['last_error'] ?? ''));
            return $last_error !== '' ? $last_error : __('Il CSV è stato letto, ma nessuna riga è stata accettata nella staging. Il report mostra il motivo prevalente.', 'affiliate-link-manager-ai');
        }
        if ($processed === 0) {
            return __('Sessione pronta: clicca Importa prossimo batch.', 'affiliate-link-manager-ai');
        }
        return sprintf(__('Sessione parziale. Record processati: %1$d/%2$d.', 'affiliate-link-manager-ai'), $processed, $total);
    }

    private function render_log_tab() {
        $report = get_option(ALMA_Geo_Index_Importer::REPORT_OPTION, array());
        echo '<h2>' . esc_html__('Log / ultimi import', 'affiliate-link-manager-ai') . '</h2>';
        if (empty($report)) {
            echo '<p>' . esc_html__('Nessun import registrato.', 'affiliate-link-manager-ai') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><tbody>';
        foreach (array('date','file_name','records_read','imported','updated','skipped','existing_skipped','post_not_found','errors') as $key) {
            echo '<tr><th>' . esc_html($key) . '</th><td>' . esc_html((string) ($report[$key] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($report['messages'])) {
            echo '<h3>' . esc_html__('Dettaglio esiti', 'affiliate-link-manager-ai') . '</h3><pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:320px;overflow:auto;">' . esc_html(wp_json_encode($report['messages'], JSON_PRETTY_PRINT)) . '</pre>';
        }
    }

    private function store_preview_upload($validation) {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('geo_csv_upload_dir_error', $upload_dir['error']);
        }

        $dir = trailingslashit($upload_dir['basedir']) . 'alma-geo-index-imports';
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('geo_csv_temp_dir_error', __('Impossibile creare la cartella temporanea per la preview CSV.', 'affiliate-link-manager-ai'));
        }

        if (!file_exists($dir . '/index.html')) {
            file_put_contents($dir . '/index.html', ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \".*\">\nRequire all denied\n</FilesMatch>\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }

        $file_name = wp_unique_filename($dir, wp_generate_uuid4() . '-' . $validation['file_name']);
        $target = trailingslashit($dir) . $file_name;
        if (!move_uploaded_file($validation['tmp_name'], $target)) {
            return new WP_Error('geo_csv_move_failed', __('Impossibile salvare temporaneamente il CSV per la preview.', 'affiliate-link-manager-ai'));
        }

        return $target;
    }

    private function format_upload_error_message($error) {
        if (in_array($error->get_error_code(), array('geo_csv_invalid_mime', 'geo_csv_invalid_extension'), true)) {
            return __('Il file caricato non sembra un CSV valido. Carica un file .csv esportato dal processo Geo Index.', 'affiliate-link-manager-ai');
        }

        return $error->get_error_message();
    }

    private function tab_link($tab, $label, $current) {
        $url = add_query_arg(array('post_type' => 'affiliate_link', 'page' => self::MENU_SLUG, 'tab' => $tab), admin_url('edit.php'));
        echo '<a class="nav-tab ' . ($current === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function preview_key() {
        return self::PREVIEW_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function affiliate_preview_key() {
        return self::PREVIEW_TRANSIENT_PREFIX . 'affiliate_' . get_current_user_id();
    }

    private function geo_schema_repair_key() {
        return self::PREVIEW_TRANSIENT_PREFIX . 'geo_schema_repair_' . get_current_user_id();
    }

    private function notice_success($message) {
        $this->notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function notice_error($message) {
        $this->notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
