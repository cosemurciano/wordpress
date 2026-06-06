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
    private $notice = '';

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
        $this->importer = new ALMA_Geo_Index_Importer($this->store);
        $this->geocoder = new ALMA_Geo_Index_Geocoder($this->store);
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
    }

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Indice Geografico', 'affiliate-link-manager-ai'),
            __('Indice Geografico', 'affiliate-link-manager-ai'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_page')
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        if (!in_array($tab, array('dashboard', 'import', 'locations', 'geocoding', 'log'), true)) {
            $tab = 'dashboard';
        }
        $this->handle_actions($tab);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Indice Geografico', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php esc_html_e('Modulo di fondazione per collegare articoli, pagine e Link Affiliati a località geografiche. Il geocoding usa Google Maps API solo da admin e solo su azione esplicita.', 'affiliate-link-manager-ai'); ?></p>
            <?php echo $this->notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <nav class="nav-tab-wrapper">
                <?php $this->tab_link('dashboard', __('Dashboard', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('import', __('Importa record articoli', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('locations', __('Località', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('geocoding', __('Geocoding', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('log', __('Log / ultimi import', 'affiliate-link-manager-ai'), $tab); ?>
            </nav>
            <?php
            if ($tab === 'import') {
                $this->render_import_tab();
            } elseif ($tab === 'locations') {
                $this->render_locations_tab();
            } elseif ($tab === 'geocoding') {
                $this->render_geocoding_tab();
            } elseif ($tab === 'log') {
                $this->render_log_tab();
            } else {
                $this->render_dashboard_tab();
            }
            ?>
        </div>
        <?php
    }

    private function handle_actions($tab) {
        if (empty($_POST['alma_geo_index_action'])) {
            return;
        }
        $action = sanitize_key(wp_unslash($_POST['alma_geo_index_action']));
        if ($tab === 'import') {
            check_admin_referer('alma_geo_index_import');
            if ($action === 'preview') {
                $this->handle_preview_upload();
            } elseif ($action === 'import') {
                $this->handle_import_submit();
            }
            return;
        }
        if (in_array($tab, array('geocoding', 'locations'), true)) {
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
            update_option('alma_geo_geocoding_timeout', max(1, min(60, absint($_POST['alma_geo_geocoding_timeout'] ?? 15))), false);
            update_option('alma_geo_geocoding_delay_ms', max(0, min(5000, absint($_POST['alma_geo_geocoding_delay_ms'] ?? 200))), false);
            update_option('alma_geo_geocoding_overwrite_verified', !empty($_POST['alma_geo_geocoding_overwrite_verified']) ? 'yes' : 'no', false);
            update_option('alma_geo_geocoding_country_bias', sanitize_text_field(wp_unslash($_POST['alma_geo_geocoding_country_bias'] ?? '')), false);
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
            $this->notice_success(sprintf(__('Batch geocoding completato: %1$d processate, %2$d verified, %3$d ambiguous, %4$d manual_required, %5$d failed. Oggetti collegati sincronizzati: %6$d, errori sync: %7$d.', 'affiliate-link-manager-ai'), $report['processed'], $report['verified'], $report['ambiguous'], $report['manual_required'], $report['failed'], $report['linked_objects_synced'], $report['linked_objects_sync_errors']));
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
        $api_key = (string) get_option('alma_geo_google_maps_api_key', '');
        $masked_key = $api_key === '' ? __('Non configurata', 'affiliate-link-manager-ai') : sprintf(__('Configurata (termina con %s)', 'affiliate-link-manager-ai'), substr($api_key, -4));
        echo '<h2>' . esc_html__('Geocoding', 'affiliate-link-manager-ai') . '</h2>';
        echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('Stato configurazione', 'affiliate-link-manager-ai') . '</h3><table class="widefat striped"><tbody>';
        $rows = array(
            __('Provider attivo', 'affiliate-link-manager-ai') => 'Google Maps',
            __('Google Maps API key', 'affiliate-link-manager-ai') => $masked_key,
            __('Località pending', 'affiliate-link-manager-ai') => $counts['pending'],
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
            echo '<div class="postbox"><div class="inside"><h3>' . esc_html__('Ultimo report geocoding', 'affiliate-link-manager-ai') . '</h3><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($last_report, JSON_PRETTY_PRINT)) . '</pre></div></div>';
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
                <tr><th><label for="alma_geo_geocoding_timeout"><?php esc_html_e('Timeout richiesta', 'affiliate-link-manager-ai'); ?></label></th><td><input type="number" min="1" max="60" id="alma_geo_geocoding_timeout" name="alma_geo_geocoding_timeout" value="<?php echo esc_attr((string) $settings['timeout']); ?>"></td></tr>
                <tr><th><label for="alma_geo_geocoding_delay_ms"><?php esc_html_e('Pausa tra richieste (ms)', 'affiliate-link-manager-ai'); ?></label></th><td><input type="number" min="0" max="5000" id="alma_geo_geocoding_delay_ms" name="alma_geo_geocoding_delay_ms" value="<?php echo esc_attr((string) $settings['delay_ms']); ?>"></td></tr>
                <tr><th><label for="alma_geo_geocoding_country_bias"><?php esc_html_e('Country bias opzionale', 'affiliate-link-manager-ai'); ?></label></th><td><input type="text" id="alma_geo_geocoding_country_bias" name="alma_geo_geocoding_country_bias" value="<?php echo esc_attr($settings['country_bias']); ?>" class="small-text" maxlength="10"></td></tr>
                <tr><th><?php esc_html_e('Sovrascrittura', 'affiliate-link-manager-ai'); ?></th><td><label><input type="checkbox" name="alma_geo_geocoding_overwrite_verified" value="1" <?php checked($settings['overwrite_verified'], 'yes'); ?>> <?php esc_html_e('Permetti sovrascrittura località già verified', 'affiliate-link-manager-ai'); ?></label></td></tr>
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
        echo '<h3>' . esc_html__('Località pending/recenti', 'affiliate-link-manager-ai') . '</h3>';
        $this->render_locations_table($this->store->get_locations(50), false);
    }

    private function render_geocoding_button($action, $label, $location_id = 0) {
        echo '<form method="post" style="display:inline-block;margin:0 4px 4px 0;">';
        wp_nonce_field('alma_geo_index_geocoding');
        echo '<input type="hidden" name="alma_geo_index_action" value="' . esc_attr($action) . '">';
        if ($location_id) {
            echo '<input type="hidden" name="location_id" value="' . esc_attr((string) absint($location_id)) . '">';
        }
        submit_button($label, 'secondary small', 'submit', false);
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

    private function notice_success($message) {
        $this->notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function notice_error($message) {
        $this->notice = '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
