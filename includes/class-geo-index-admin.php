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
    private $notice = '';

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
        $this->importer = new ALMA_Geo_Index_Importer($this->store);
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
        if (!in_array($tab, array('dashboard', 'import', 'locations', 'log'), true)) {
            $tab = 'dashboard';
        }
        $this->handle_actions($tab);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Indice Geografico', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php esc_html_e('Modulo di fondazione per collegare articoli, pagine e Link Affiliati a località geografiche. In questa fase non usa AI e non chiama Google Maps API.', 'affiliate-link-manager-ai'); ?></p>
            <?php echo $this->notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <nav class="nav-tab-wrapper">
                <?php $this->tab_link('dashboard', __('Dashboard', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('import', __('Importa record articoli', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('locations', __('Località', 'affiliate-link-manager-ai'), $tab); ?>
                <?php $this->tab_link('log', __('Log / ultimi import', 'affiliate-link-manager-ai'), $tab); ?>
            </nav>
            <?php
            if ($tab === 'import') {
                $this->render_import_tab();
            } elseif ($tab === 'locations') {
                $this->render_locations_tab();
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
        if ($tab !== 'import' || empty($_POST['alma_geo_index_action'])) {
            return;
        }
        check_admin_referer('alma_geo_index_import');
        $action = sanitize_key(wp_unslash($_POST['alma_geo_index_action']));
        if ($action === 'preview') {
            $this->handle_preview_upload();
        } elseif ($action === 'import') {
            $this->handle_import_submit();
        }
    }

    private function handle_preview_upload() {
        if (empty($_FILES['alma_geo_csv']['name'])) {
            $this->notice_error(__('Seleziona un file CSV da caricare.', 'affiliate-link-manager-ai'));
            return;
        }
        $file_name = sanitize_file_name($_FILES['alma_geo_csv']['name']);
        if (strtolower(pathinfo($file_name, PATHINFO_EXTENSION)) !== 'csv') {
            $this->notice_error(__('Sono accettati solo file .csv.', 'affiliate-link-manager-ai'));
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploaded = wp_handle_upload($_FILES['alma_geo_csv'], array(
            'test_form' => false,
            'mimes' => array('csv' => 'text/csv|text/plain|application/csv|application/vnd.ms-excel'),
        ));
        if (!empty($uploaded['error'])) {
            $this->notice_error($uploaded['error']);
            return;
        }
        $parsed = $this->importer->parse_csv_file($uploaded['file'], 10);
        $validation = $this->importer->validate_headers($parsed['headers']);
        $preview = array(
            'file' => $uploaded['file'],
            'file_name' => $file_name,
            'total' => $parsed['total'],
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'valid' => $validation['valid'],
            'missing' => $validation['missing'],
        );
        set_transient($this->preview_key(), $preview, HOUR_IN_SECONDS);
        if ($validation['valid']) {
            $this->notice_success(__('CSV caricato e validato. Controlla la preview e conferma l’importazione.', 'affiliate-link-manager-ai'));
        } else {
            $this->notice_error(sprintf(__('CSV non valido. Header mancanti: %s', 'affiliate-link-manager-ai'), esc_html(implode(', ', $validation['missing']))));
        }
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
            __('Articoli/pagine con geo meta', 'affiliate-link-manager-ai') => $counts['posts_with_geo_meta'],
            __('Link Affiliati con geo meta', 'affiliate-link-manager-ai') => $counts['affiliate_links_with_geo_meta'],
            __('Località salvate', 'affiliate-link-manager-ai') => $counts['locations'],
            __('Relazioni contenuto/località', 'affiliate-link-manager-ai') => $counts['content_relations'],
            __('Record pending geocoding', 'affiliate-link-manager-ai') => $counts['pending_geocoding'],
            __('Record widget eligible', 'affiliate-link-manager-ai') => $counts['widget_eligible'],
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
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('alma_geo_index_import'); ?>
            <input type="hidden" name="alma_geo_index_action" value="preview">
            <input type="file" name="alma_geo_csv" accept=".csv,text/csv" required>
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
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Nome canonico', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Tipo', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Paese', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Regione', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Città', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Area', 'affiliate-link-manager-ai') . '</th><th>POI</th><th>' . esc_html__('Stato geocoding', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Query suggerita', 'affiliate-link-manager-ai') . '</th><th>' . esc_html__('Contenuti', 'affiliate-link-manager-ai') . '</th></tr></thead><tbody>';
        if (empty($locations)) {
            echo '<tr><td colspan="11">' . esc_html__('Nessuna località salvata.', 'affiliate-link-manager-ai') . '</td></tr>';
        }
        foreach ($locations as $location) {
            echo '<tr><td>' . esc_html((string) $location['id']) . '</td><td>' . esc_html($location['canonical_name']) . '</td><td>' . esc_html($location['type']) . '</td><td>' . esc_html($location['country']) . '</td><td>' . esc_html($location['region']) . '</td><td>' . esc_html($location['city']) . '</td><td>' . esc_html($location['area']) . '</td><td>' . esc_html($location['poi']) . '</td><td>' . esc_html($location['geocoding_status']) . '</td><td>' . esc_html($location['suggested_geocoding_query']) . '</td><td>' . esc_html((string) $location['content_count']) . '</td></tr>';
        }
        echo '</tbody></table>';
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
