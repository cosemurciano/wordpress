<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin metabox for editing Geo Index data on content objects.
 */
class ALMA_Geo_Index_Metabox {
    const MAX_ASSOCIATED_LOCATIONS = 10;
    const NONCE_ACTION = 'alma_geo_index_save_metabox';
    const NONCE_NAME = 'alma_geo_index_nonce';

    private $store;

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
    }

    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save'), 30, 2);
        add_action('wp_ajax_alma_geo_search_location', array($this, 'ajax_search_location'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }


    public function enqueue_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, array('post', 'page', 'affiliate_link'), true)) {
            return;
        }
        if (file_exists(ALMA_PLUGIN_DIR . 'assets/geo-index-admin.js')) {
            wp_enqueue_script('alma-geo-index-admin', ALMA_PLUGIN_URL . 'assets/geo-index-admin.js', array('jquery'), ALMA_VERSION, true);
            wp_localize_script('alma-geo-index-admin', 'almaGeoIndexAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('alma_geo_search_location'),
                'postId' => isset($_GET['post']) ? absint($_GET['post']) : 0,
                'strings' => array(
                    'minChars' => __('Inserisci almeno 3 caratteri.', 'affiliate-link-manager-ai'),
                    'noResults' => __('Nessun luogo trovato.', 'affiliate-link-manager-ai'),
                    'apiKeyMissing' => __('Google Maps API key non configurata.', 'affiliate-link-manager-ai'),
                    'associated' => __('Luogo associato. Salva o aggiorna il post per confermare le modifiche.', 'affiliate-link-manager-ai'),
                    'replaced' => __('Località sostituita. Salva il post per confermare.', 'affiliate-link-manager-ai'),
                    'searchError' => __('Errore durante la ricerca località.', 'affiliate-link-manager-ai'),
                    'replaceConfirm' => __('Questa operazione sostituirà la località primaria attuale.', 'affiliate-link-manager-ai'),
                    'searching' => __('Ricerca in corso...', 'affiliate-link-manager-ai'),
                    'associate' => __('Associa luogo', 'affiliate-link-manager-ai'),
                    'type' => __('Tipo:', 'affiliate-link-manager-ai'),
                    'country' => __('Paese:', 'affiliate-link-manager-ai'),
                    'limitReached' => __('Puoi associare al massimo 10 località a questo contenuto.', 'affiliate-link-manager-ai'),
                    'removed' => __('Località rimossa. Salva il post per confermare.', 'affiliate-link-manager-ai'),
                    'promoted' => __('La località principale è stata rimossa: la prima località rimasta è stata promossa a principale.', 'affiliate-link-manager-ai'),
                    'duplicate' => __('Questa località è già associata al contenuto.', 'affiliate-link-manager-ai'),
                    'emptyLocations' => __('Nessuna località associata.', 'affiliate-link-manager-ai'),
                    'remove' => __('Rimuovi', 'affiliate-link-manager-ai'),
                    'main' => __('Principale', 'affiliate-link-manager-ai'),
                ),
                'maxLocations' => self::MAX_ASSOCIATED_LOCATIONS,
            ));
        }
        if (file_exists(ALMA_PLUGIN_DIR . 'assets/admin.css')) {
            wp_enqueue_style('alma-admin-style', ALMA_PLUGIN_URL . 'assets/admin.css', array(), ALMA_VERSION);
        }
    }

    public function ajax_search_location() {
        check_ajax_referer('alma_geo_search_location', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id > 0) {
            if (!current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
            }
        } elseif (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if (strlen($query) < 3) {
            wp_send_json_error(array('message' => __('Inserisci almeno 3 caratteri.', 'affiliate-link-manager-ai')), 400);
        }
        $api_key = trim((string) get_option('alma_geo_google_maps_api_key', ''));
        if ($api_key === '') {
            wp_send_json_error(array('message' => __('Google Maps API key non configurata.', 'affiliate-link-manager-ai'), 'code' => 'api_key_missing'), 400);
        }

        $provider = new ALMA_Geo_Index_Google_Geocoder($api_key, (int) get_option('alma_geo_geocoding_timeout', 15));
        $result = $provider->search_locations($query, array('region' => get_option('alma_geo_geocoding_country_bias', '')));
        if (empty($result['success'])) {
            wp_send_json_error(array('message' => sanitize_text_field($result['message'] ?? __('Errore durante la ricerca località.', 'affiliate-link-manager-ai'))), 500);
        }
        $results = array_slice(is_array($result['results'] ?? null) ? $result['results'] : array(), 0, 5);
        if (empty($results)) {
            wp_send_json_success(array('results' => array(), 'message' => __('Nessun luogo trovato.', 'affiliate-link-manager-ai')));
        }
        wp_send_json_success(array('results' => $results));
    }

    public function add_meta_boxes() {
        foreach (array('post', 'page', 'affiliate_link') as $screen) {
            add_meta_box(
                'alma_geo_index_metabox',
                __('Geolocalizzazione contenuto', 'affiliate-link-manager-ai'),
                array($this, 'render'),
                $screen,
                'normal',
                'default'
            );
        }
    }

    public function render($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $values = $this->get_values($post->ID);
        $values = $this->apply_new_post_defaults($post->ID, $values);
        $primary_location = $this->store->get_primary_location_for_object($post->ID, $post->post_type);
        $effective_geocoding_status = $this->effective_geocoding_status($values, $primary_location);
        $suggested_query = $primary_location['suggested_geocoding_query'] ?? '';
        $provider = $values['_alma_geo_primary_provider'] ?: ($values['_alma_geo_provider'] ?? '');
        $provider = $provider ?: ($primary_location['geo_provider'] ?? '');
        $place_id = $values['_alma_geo_primary_place_id'] ?: ($primary_location['geo_provider_place_id'] ?? '');
        $formatted_address = $values['_alma_geo_primary_formatted_address'] ?: ($primary_location['formatted_address'] ?? '');
        $lat = $values['_alma_geo_primary_lat'] ?: ($primary_location['lat'] ?? '');
        $lng = $values['_alma_geo_primary_lng'] ?: ($primary_location['lng'] ?? '');
        $display_values = array_merge($values, array(
            '_alma_geo_primary_name' => $values['_alma_geo_primary_name'] ?: ($primary_location['canonical_name'] ?? ''),
            '_alma_geo_primary_canonical_name' => $values['_alma_geo_primary_canonical_name'] ?: ($primary_location['canonical_name'] ?? ''),
            '_alma_geo_primary_type' => $values['_alma_geo_primary_type'] ?: ($primary_location['type'] ?? 'unknown'),
            '_alma_geo_primary_country' => $values['_alma_geo_primary_country'] ?: ($primary_location['country'] ?? ''),
            '_alma_geo_primary_country_code' => $values['_alma_geo_primary_country_code'] ?: ($primary_location['country_code'] ?? ''),
            '_alma_geo_primary_region' => $values['_alma_geo_primary_region'] ?: ($primary_location['region'] ?? ''),
            '_alma_geo_primary_city' => $values['_alma_geo_primary_city'] ?: ($primary_location['city'] ?? ''),
            '_alma_geo_primary_area' => $values['_alma_geo_primary_area'] ?: ($primary_location['area'] ?? ''),
            '_alma_geo_primary_poi' => $values['_alma_geo_primary_poi'] ?: ($primary_location['poi'] ?? ''),
            '_alma_geo_primary_lat' => $lat,
            '_alma_geo_primary_lng' => $lng,
            '_alma_geo_primary_place_id' => $place_id,
            '_alma_geo_provider' => $provider,
            '_alma_geo_primary_provider' => $provider,
            '_alma_geo_primary_formatted_address' => $formatted_address,
            '_alma_geo_geocoding_status' => $effective_geocoding_status,
        ));
        $associated_locations = $this->get_associated_locations($post->ID, $post->post_type, $display_values);
        $has_location = !empty($associated_locations);
        ?>
        <div class="alma-geo-metabox" data-has-primary-location="<?php echo $has_location ? '1' : '0'; ?>">
            <p><?php esc_html_e('Cerca una località con Google Maps, associala al contenuto e lascia che il plugin compili i campi geografici tecnici.', 'affiliate-link-manager-ai'); ?></p>

            <div class="alma-geo-section alma-geo-location-search">
                <h3><?php esc_html_e('Cerca e associa località', 'affiliate-link-manager-ai'); ?></h3>
                <div class="alma-geo-search-row">
                    <input type="text" id="alma_geo_location_query" class="alma-geo-search-input" value="" placeholder="<?php echo esc_attr__('Cerca Londra, Palermo, Kyoto, Etihad Stadium...', 'affiliate-link-manager-ai'); ?>" autocomplete="off">
                    <button type="button" class="button button-secondary" id="alma_geo_location_search_button"><?php esc_html_e('Cerca località', 'affiliate-link-manager-ai'); ?></button>
                </div>
                <div class="alma-geo-feedback" id="alma_geo_location_feedback" role="status" aria-live="polite"></div>
                <div class="alma-geo-search-results" id="alma_geo_location_results"></div>
            </div>

            <div class="alma-geo-section">
                <h3><?php esc_html_e('Stato sintetico', 'affiliate-link-manager-ai'); ?></h3>
                <div class="alma-geo-summary">
                    <div><?php esc_html_e('Indice geografico:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_record_status_badge($values['_alma_geo_enabled'] ?? 'no'); ?></div>
                    <div><?php esc_html_e('Stato import:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_import_status_badge($values['_alma_geo_import_status'] ?? 'review'); ?></div>
                    <div><?php esc_html_e('Stato geocoding:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_geocoding_status_badge($effective_geocoding_status); ?></div>
                </div>
                <p>
                    <label><input type="checkbox" id="_alma_geo_enabled" name="alma_geo[_alma_geo_enabled]" value="yes" <?php checked($this->is_yes($values['_alma_geo_enabled'] ?? ''), true); ?>> <?php esc_html_e('Abilita geolocalizzazione', 'affiliate-link-manager-ai'); ?></label>
                    &nbsp;&nbsp;
                    <label><input type="checkbox" id="_alma_geo_widget_eligible" name="alma_geo[_alma_geo_widget_eligible]" value="yes" <?php checked($this->is_yes($values['_alma_geo_widget_eligible'] ?? ''), true); ?>> <?php esc_html_e('Widget eligible', 'affiliate-link-manager-ai'); ?></label>
                </p>
            </div>

            <div class="alma-geo-section alma-geo-associated-section">
                <h3><?php esc_html_e('Località associate al contenuto', 'affiliate-link-manager-ai'); ?></h3>
                <input type="hidden" id="alma_geo_associated_locations_json" name="alma_geo[associated_locations_json]" value="<?php echo esc_attr(wp_json_encode($associated_locations)); ?>">
                <p class="description"><?php esc_html_e('Associa fino a 10 località tramite Google Maps, scegli una sola principale e assegna un ruolo editoriale alle secondarie.', 'affiliate-link-manager-ai'); ?></p>
                <table class="widefat striped alma-geo-associated-table" id="alma_geo_associated_locations_table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Principale', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Nome località', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Tipo', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Paese', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Regione', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Città', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Stato geocoding', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Ruolo', 'affiliate-link-manager-ai'); ?></th>
                            <th><?php esc_html_e('Azione', 'affiliate-link-manager-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <p class="description alma-geo-empty-locations" id="alma_geo_empty_locations"><?php esc_html_e('Nessuna località associata.', 'affiliate-link-manager-ai'); ?></p>
                <div class="alma-geo-hidden-primary-fields">
                    <?php $this->render_input('_alma_geo_primary_name', __('Nome località', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_canonical_name', __('Nome canonico', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_country', __('Paese', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_country_code', __('Codice paese', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_region', __('Regione', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_city', __('Città', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_area', __('Area', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_poi', __('POI', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_lat', __('Latitudine', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_lng', __('Longitudine', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_place_id', __('Place ID', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_provider', __('Provider geocoding', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_provider', __('Provider primario', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                    <?php $this->render_input('_alma_geo_primary_formatted_address', __('Formatted address', 'affiliate-link-manager-ai'), $display_values, 'hidden'); ?>
                </div>
            </div>

            <details class="alma-geo-details">
                <summary><?php esc_html_e('Classificazione e campi avanzati', 'affiliate-link-manager-ai'); ?></summary>
                <div class="alma-geo-grid">
                    <?php $this->render_select('_alma_geo_content_type', __('Content type', 'affiliate-link-manager-ai'), $values, self::content_types()); ?>
                    <?php $this->render_select('_alma_geo_commercial_intent', __('Commercial intent', 'affiliate-link-manager-ai'), $values, self::commercial_intents()); ?>
                    <?php $this->render_select('_alma_geo_scope', __('Geo scope', 'affiliate-link-manager-ai'), $values, self::geo_scopes()); ?>
                    <?php $this->render_input('_alma_geo_confidence', __('Confidence', 'affiliate-link-manager-ai'), $values, 'number', 'step="0.001" min="0" max="1"'); ?>
                    <?php $this->render_input('_alma_geo_match_weight', __('Match weight', 'affiliate-link-manager-ai'), $values, 'number'); ?>
                    <?php $this->render_input('_alma_geo_source', __('Fonte dati', 'affiliate-link-manager-ai'), $values); ?>
                    <?php $this->render_select('_alma_geo_import_status', __('Stato import tecnico', 'affiliate-link-manager-ai'), $values, self::geo_import_statuses()); ?>
                    <?php $this->render_select('_alma_geo_geocoding_status', __('Stato geocoding tecnico', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_geocoding_status' => $effective_geocoding_status)), self::geocoding_statuses()); ?>
                    <?php $this->render_input('_alma_geo_primary_type', __('Tipo località tecnico', 'affiliate-link-manager-ai'), $display_values); ?>
                    <?php $this->render_input('_alma_geo_updated_at', __('Ultimo aggiornamento', 'affiliate-link-manager-ai'), $values, 'text', 'readonly'); ?>
                    <div class="alma-geo-field" style="grid-column:1/-1">
                        <label for="alma_geo_suggested_geocoding_query"><?php esc_html_e('Query geocoding suggerita', 'affiliate-link-manager-ai'); ?></label>
                        <input type="text" id="alma_geo_suggested_geocoding_query" name="alma_geo[suggested_geocoding_query]" value="<?php echo esc_attr($suggested_query); ?>">
                    </div>
                </div>
                <?php $this->render_textarea('_alma_geo_quality_flags', __('Quality flags', 'affiliate-link-manager-ai'), $values, 3); ?>
                <?php $this->render_textarea('_alma_geo_notes', __('Note interne', 'affiliate-link-manager-ai'), $values, 4); ?>
                <?php $this->render_textarea('_alma_geo_locations_json', __('JSON tecnico località', 'affiliate-link-manager-ai'), $values, 5); ?>
            </details>
        </div>
        <?php
    }

    public function save($post_id, $post) {
        if (!isset($_POST[self::NONCE_NAME])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!in_array($post->post_type, array('post', 'page', 'affiliate_link'), true)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['alma_geo']) || !is_array($_POST['alma_geo'])) {
            return;
        }

        if (!empty($_POST['alma_geo_resync_post'])) {
            $primary_location = $this->store->get_primary_location_for_object($post_id, $post->post_type);
            if (!empty($primary_location['id']) && ($primary_location['geocoding_status'] ?? '') === 'verified') {
                $this->store->sync_location_to_linked_objects((int) $primary_location['id']);
            }
            return;
        }

        $raw = wp_unslash($_POST['alma_geo']);
        $data = $this->sanitize_submitted_data($raw);
        if (!$this->has_geo_payload($data) && !$this->has_existing_geo_meta($post_id)) {
            return;
        }
        $has_associated_payload = array_key_exists('associated_locations_json', $raw);
        $locations = $this->sanitize_associated_locations($raw['associated_locations_json'] ?? '');
        if ($has_associated_payload) {
            $result = $this->store->save_geo_meta_for_object($post_id, $post->post_type, array(
                'locations' => $locations,
                'geo_scope' => $data['_alma_geo_scope'],
                'content_type' => $data['_alma_geo_content_type'],
                'commercial_intent' => $data['_alma_geo_commercial_intent'],
                'widget_eligible' => $data['_alma_geo_widget_eligible'],
            ), 'manual_google_search');
            foreach (self::meta_keys() as $key) {
                if (!array_key_exists($key, $result['meta'] ?? array())) {
                    update_post_meta($post_id, $key, $data[$key] ?? '');
                }
            }
            update_post_meta($post_id, '_alma_geo_locations_json', wp_json_encode($result['locations'] ?? $locations));
            return;
        }

        foreach (self::meta_keys() as $key) {
            update_post_meta($post_id, $key, $data[$key] ?? '');
        }
        update_post_meta($post_id, '_alma_geo_updated_at', current_time('mysql'));

        $this->sync_tables($post_id, $post->post_type, $data);
    }

    public function sync_tables($post_id, $post_type, $data) {
        if (!$this->store->tables_exist()) {
            $this->store->install_tables();
        }
        $canonical = $data['_alma_geo_primary_canonical_name'] ?: $data['_alma_geo_primary_name'];
        $location_id = 0;
        if ($canonical !== '') {
            $location_id = $this->store->upsert_location(array(
                'canonical_name' => $canonical,
                'type' => $data['_alma_geo_primary_type'],
                'country' => $data['_alma_geo_primary_country'],
                'country_code' => $data['_alma_geo_primary_country_code'],
                'region' => $data['_alma_geo_primary_region'],
                'city' => $data['_alma_geo_primary_city'],
                'area' => $data['_alma_geo_primary_area'],
                'poi' => $data['_alma_geo_primary_poi'],
                'lat' => $data['_alma_geo_primary_lat'],
                'lng' => $data['_alma_geo_primary_lng'],
                'geo_provider' => $data['_alma_geo_provider'] ?: $data['_alma_geo_primary_provider'],
                'geo_provider_place_id' => $data['_alma_geo_primary_place_id'],
                'suggested_geocoding_query' => $data['suggested_geocoding_query'] ?? '',
                'geocoding_status' => $data['_alma_geo_geocoding_status'],
                'formatted_address' => $data['_alma_geo_primary_formatted_address'],
            ));
        }

        $this->store->upsert_content_index($post_id, $post_type, $location_id, array(
            'is_primary' => 1,
            'role' => 'main_destination',
            'geo_scope' => $data['_alma_geo_scope'],
            'content_type' => $data['_alma_geo_content_type'],
            'commercial_intent' => $data['_alma_geo_commercial_intent'],
            'widget_eligible' => $this->is_yes($data['_alma_geo_widget_eligible']),
            'confidence' => $data['_alma_geo_confidence'],
            'match_weight' => $data['_alma_geo_match_weight'],
            'source' => $data['_alma_geo_source'],
            'raw_payload' => array(
                'meta' => $data,
                'updated_from' => isset($data['raw_payload_source']) ? sanitize_key($data['raw_payload_source']) : 'metabox',
                'import_payload' => isset($data['raw_payload']) ? $data['raw_payload'] : array(),
            ),
        ));
    }

    public function sanitize_submitted_data($raw) {
        $raw = is_array($raw) ? $raw : array();
        $data = array();
        foreach (self::meta_keys() as $key) {
            $data[$key] = '';
        }
        $data['_alma_geo_enabled'] = !empty($raw['_alma_geo_enabled']) ? 'yes' : 'no';
        $data['_alma_geo_widget_eligible'] = !empty($raw['_alma_geo_widget_eligible']) ? 'yes' : 'no';
        $data['_alma_geo_scope'] = $this->sanitize_allowed($raw['_alma_geo_scope'] ?? '', self::geo_scopes(), 'uncertain');
        $data['_alma_geo_content_type'] = $this->sanitize_allowed($raw['_alma_geo_content_type'] ?? '', self::content_types(), 'uncertain');
        $data['_alma_geo_commercial_intent'] = $this->sanitize_allowed($raw['_alma_geo_commercial_intent'] ?? '', self::commercial_intents(), 'none');
        $data['_alma_geo_primary_type'] = $this->sanitize_allowed($raw['_alma_geo_primary_type'] ?? '', self::primary_types(), 'unknown');
        $data['_alma_geo_import_status'] = $this->sanitize_allowed($raw['_alma_geo_import_status'] ?? '', self::geo_import_statuses(), 'review');
        $data['_alma_geo_geocoding_status'] = $this->sanitize_allowed($raw['_alma_geo_geocoding_status'] ?? '', self::geocoding_statuses(), 'pending');

        foreach (array('_alma_geo_primary_name','_alma_geo_primary_canonical_name','_alma_geo_primary_country','_alma_geo_primary_country_code','_alma_geo_primary_region','_alma_geo_primary_city','_alma_geo_primary_area','_alma_geo_primary_poi','_alma_geo_primary_place_id','_alma_geo_provider','_alma_geo_primary_provider','_alma_geo_primary_formatted_address','_alma_geo_source') as $key) {
            $data[$key] = sanitize_text_field($raw[$key] ?? '');
        }
        $data['_alma_geo_primary_country_code'] = strtoupper($data['_alma_geo_primary_country_code']);
        $data['_alma_geo_primary_lat'] = isset($raw['_alma_geo_primary_lat']) && $raw['_alma_geo_primary_lat'] !== '' ? (string) (float) $raw['_alma_geo_primary_lat'] : '';
        $data['_alma_geo_primary_lng'] = isset($raw['_alma_geo_primary_lng']) && $raw['_alma_geo_primary_lng'] !== '' ? (string) (float) $raw['_alma_geo_primary_lng'] : '';
        if ($data['_alma_geo_primary_lat'] !== '' && $data['_alma_geo_primary_lng'] !== '' && $data['_alma_geo_geocoding_status'] === 'verified' && $data['_alma_geo_provider'] === '') {
            $data['_alma_geo_provider'] = 'manual';
        }
        $data['_alma_geo_confidence'] = isset($raw['_alma_geo_confidence']) && $raw['_alma_geo_confidence'] !== '' ? (string) min(1, max(0, (float) $raw['_alma_geo_confidence'])) : '';
        $data['_alma_geo_match_weight'] = isset($raw['_alma_geo_match_weight']) && $raw['_alma_geo_match_weight'] !== '' ? (string) (int) $raw['_alma_geo_match_weight'] : '';
        $data['_alma_geo_locations_json'] = $this->sanitize_json_textarea($raw['_alma_geo_locations_json'] ?? '');
        $data['_alma_geo_quality_flags'] = sanitize_textarea_field($raw['_alma_geo_quality_flags'] ?? '');
        $data['_alma_geo_notes'] = wp_kses_post($raw['_alma_geo_notes'] ?? '');
        $data['_alma_geo_updated_at'] = sanitize_text_field($raw['_alma_geo_updated_at'] ?? '');
        $data['suggested_geocoding_query'] = sanitize_text_field($raw['suggested_geocoding_query'] ?? '');
        return $data;
    }

    public static function meta_keys() {
        return array(
            '_alma_geo_enabled','_alma_geo_scope','_alma_geo_content_type','_alma_geo_commercial_intent','_alma_geo_widget_eligible',
            '_alma_geo_primary_name','_alma_geo_primary_canonical_name','_alma_geo_primary_type','_alma_geo_primary_country','_alma_geo_primary_country_code','_alma_geo_primary_region','_alma_geo_primary_city','_alma_geo_primary_area','_alma_geo_primary_poi','_alma_geo_primary_lat','_alma_geo_primary_lng','_alma_geo_primary_place_id','_alma_geo_provider','_alma_geo_primary_provider','_alma_geo_primary_formatted_address',
            '_alma_geo_confidence','_alma_geo_match_weight','_alma_geo_geocoding_status','_alma_geo_import_status','_alma_geo_locations_json','_alma_geo_quality_flags','_alma_geo_notes','_alma_geo_source','_alma_geo_updated_at'
        );
    }

    public static function geo_scopes() { return array('world','continent','country','region','city','area','poi','itinerary_multi_location','non_geo','uncertain'); }
    public static function primary_types() { return array('continent','country','region','city','area','island','poi','airport','port','route','unknown'); }
    public static function content_types() { return array('destination_guide','country_guide','region_guide','city_guide','area_guide','poi_guide','itinerary','itinerary_multi_location','cruise_ship','cruise_company','travel_advice','honeymoon','informational','generic_travel','non_travel','uncertain'); }
    public static function commercial_intents() { return array('high','medium','low','none'); }
    public static function geo_import_statuses() { return array('imported','active','ready','review','discard','needs_geocoding','geocoding_failed'); }
    public static function geocoding_statuses() { return array('pending','verified','ambiguous','manual_required','failed','not_required'); }


    private function has_geo_payload($data) {
        if ($this->is_yes($data['_alma_geo_enabled'] ?? 'no') || $this->is_yes($data['_alma_geo_widget_eligible'] ?? 'no')) {
            return true;
        }
        if (($data['_alma_geo_scope'] ?? 'uncertain') !== 'uncertain' || ($data['_alma_geo_content_type'] ?? 'uncertain') !== 'uncertain' || ($data['_alma_geo_commercial_intent'] ?? 'none') !== 'none' || ($data['_alma_geo_import_status'] ?? 'review') !== 'review' || ($data['_alma_geo_geocoding_status'] ?? 'pending') !== 'pending' || ($data['_alma_geo_primary_type'] ?? 'unknown') !== 'unknown') {
            return true;
        }
        foreach (array('_alma_geo_primary_name','_alma_geo_primary_canonical_name','_alma_geo_primary_country','_alma_geo_primary_country_code','_alma_geo_primary_region','_alma_geo_primary_city','_alma_geo_primary_area','_alma_geo_primary_poi','_alma_geo_primary_lat','_alma_geo_primary_lng','_alma_geo_primary_place_id','_alma_geo_confidence','_alma_geo_match_weight','_alma_geo_locations_json','_alma_geo_quality_flags','_alma_geo_notes','_alma_geo_source') as $key) {
            if (!empty($data[$key])) {
                return true;
            }
        }
        return false;
    }

    private function has_existing_geo_meta($post_id) {
        foreach (self::meta_keys() as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                return true;
            }
        }
        return false;
    }

    private function get_values($post_id) {
        $values = array();
        foreach (self::meta_keys() as $key) {
            $values[$key] = get_post_meta($post_id, $key, true);
        }
        return $values;
    }



    private function apply_new_post_defaults($post_id, $values) {
        if (!metadata_exists('post', $post_id, '_alma_geo_enabled')) {
            $values['_alma_geo_enabled'] = 'yes';
        }
        if (!metadata_exists('post', $post_id, '_alma_geo_widget_eligible')) {
            $values['_alma_geo_widget_eligible'] = 'yes';
        }
        return $values;
    }

    private function get_associated_locations($post_id, $post_type, $display_values) {
        $locations = $this->store->get_associated_locations_for_object($post_id, $post_type);
        if (!empty($locations)) {
            return $locations;
        }
        $locations = array();
        if (!empty($display_values['_alma_geo_primary_name']) || !empty($display_values['_alma_geo_primary_place_id'])) {
            $locations[] = array(
                'location_id' => 0,
                'name' => $display_values['_alma_geo_primary_name'],
                'canonical_name' => $display_values['_alma_geo_primary_canonical_name'],
                'type' => $display_values['_alma_geo_primary_type'],
                'country' => $display_values['_alma_geo_primary_country'],
                'country_code' => $display_values['_alma_geo_primary_country_code'],
                'region' => $display_values['_alma_geo_primary_region'],
                'city' => $display_values['_alma_geo_primary_city'],
                'area' => $display_values['_alma_geo_primary_area'],
                'poi' => $display_values['_alma_geo_primary_poi'],
                'lat' => $display_values['_alma_geo_primary_lat'],
                'lng' => $display_values['_alma_geo_primary_lng'],
                'geo_provider' => $display_values['_alma_geo_primary_provider'] ?: $display_values['_alma_geo_provider'],
                'geo_provider_place_id' => $display_values['_alma_geo_primary_place_id'],
                'formatted_address' => $display_values['_alma_geo_primary_formatted_address'],
                'geocoding_status' => $display_values['_alma_geo_geocoding_status'],
                'role' => 'main_destination',
                'is_primary' => true,
                'source' => $display_values['_alma_geo_source'],
                'confidence' => $display_values['_alma_geo_confidence'] !== '' ? $display_values['_alma_geo_confidence'] : 1,
                'match_weight' => 100,
            );
        }
        $decoded = json_decode((string) ($display_values['_alma_geo_locations_json'] ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $secondary) {
                if (is_array($secondary)) {
                    $secondary['is_primary'] = false;
                    $secondary['role'] = sanitize_key($secondary['role'] ?? 'major_destination');
                    $locations[] = $this->store->format_location_for_json($secondary);
                }
            }
        }
        return $this->store->normalize_associated_locations($locations, 'manual');
    }

    private function sanitize_associated_locations($json) {
        $json = trim((string) $json);
        if ($json === '') {
            return array();
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return array();
        }
        $locations = array();
        foreach ($decoded as $location) {
            if (is_array($location)) {
                $locations[] = $this->store->format_location_for_json($location);
            }
        }
        return $this->store->normalize_associated_locations($locations, 'manual_google_search');
    }

    public static function location_roles() {
        return array('main_destination','major_destination','mentioned_destination','excursion','nearby_place','route_stop','context_only');
    }

    private function primary_type_label($type) {
        $labels = array(
            'continent' => __('Continente', 'affiliate-link-manager-ai'),
            'country' => __('Paese', 'affiliate-link-manager-ai'),
            'region' => __('Regione', 'affiliate-link-manager-ai'),
            'city' => __('Città', 'affiliate-link-manager-ai'),
            'area' => __('Area', 'affiliate-link-manager-ai'),
            'island' => __('Isola', 'affiliate-link-manager-ai'),
            'poi' => __('Punto di interesse', 'affiliate-link-manager-ai'),
            'airport' => __('Aeroporto', 'affiliate-link-manager-ai'),
            'port' => __('Porto', 'affiliate-link-manager-ai'),
            'route' => __('Itinerario', 'affiliate-link-manager-ai'),
            'unknown' => __('Sconosciuto', 'affiliate-link-manager-ai'),
        );
        $type = sanitize_key($type);
        return $labels[$type] ?? $type;
    }

    private function render_record_status_badge($value) {
        $active = $this->is_yes($value);
        $label = $active ? __('Attivo', 'affiliate-link-manager-ai') : __('Non attivo', 'affiliate-link-manager-ai');
        $class = $active ? 'alma-geo-badge-active' : 'alma-geo-badge-inactive';
        return '<span class="alma-geo-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    private function render_import_status_badge($status) {
        $status = sanitize_key($status);
        $class = in_array($status, array('imported', 'active', 'ready', 'needs_geocoding'), true) ? 'alma-geo-badge-active' : 'alma-geo-badge-inactive';
        return '<span class="alma-geo-badge ' . esc_attr($class) . '">' . esc_html(self::import_status_label($status)) . '</span>';
    }

    private function render_geocoding_status_badge($status) {
        $status = sanitize_key($status);
        $classes = array(
            'pending' => 'alma-geo-badge-pending',
            'verified' => 'alma-geo-badge-verified',
            'ambiguous' => 'alma-geo-badge-ambiguous',
            'manual_required' => 'alma-geo-badge-manual',
            'failed' => 'alma-geo-badge-failed',
        );
        $class = $classes[$status] ?? 'alma-geo-badge';
        return '<span class="alma-geo-badge ' . esc_attr($class) . '">' . esc_html(self::geocoding_status_label($status)) . '</span>';
    }

    private function status_label($key, $status) {
        if ($key === '_alma_geo_geocoding_status') {
            return self::geocoding_status_label($status);
        }
        if ($key === '_alma_geo_import_status') {
            return self::import_status_label($status);
        }
        return $status;
    }

    public static function geocoding_status_label($status) {
        $labels = array(
            'pending' => __('In attesa di geocoding', 'affiliate-link-manager-ai'),
            'verified' => __('Geocodificato', 'affiliate-link-manager-ai'),
            'ambiguous' => __('Ambiguo', 'affiliate-link-manager-ai'),
            'manual_required' => __('Richiede verifica manuale', 'affiliate-link-manager-ai'),
            'failed' => __('Geocoding fallito', 'affiliate-link-manager-ai'),
            'not_required' => __('Non richiesto', 'affiliate-link-manager-ai'),
        );
        return $labels[sanitize_key($status)] ?? $status;
    }

    public static function import_status_label($status) {
        $labels = array(
            'imported' => __('Importato', 'affiliate-link-manager-ai'),
            'active' => __('Importato', 'affiliate-link-manager-ai'),
            'ready' => __('Importato', 'affiliate-link-manager-ai'),
            'needs_geocoding' => __('Importato', 'affiliate-link-manager-ai'),
            'review' => __('Richiede revisione', 'affiliate-link-manager-ai'),
            'discard' => __('Scartato', 'affiliate-link-manager-ai'),
            'geocoding_failed' => __('Geocoding fallito', 'affiliate-link-manager-ai'),
        );
        return $labels[sanitize_key($status)] ?? $status;
    }

    private function effective_geocoding_status($values, $primary_location) {
        if (!empty($primary_location) && ($primary_location['geocoding_status'] ?? '') === 'verified') {
            return 'verified';
        }
        return sanitize_key($values['_alma_geo_geocoding_status'] ?? 'pending');
    }

    private function is_primary_location_synced($values, $primary_location) {
        if (empty($primary_location) || ($primary_location['geocoding_status'] ?? '') !== 'verified') {
            return true;
        }
        return ($values['_alma_geo_geocoding_status'] ?? '') === 'verified'
            && (string) ($values['_alma_geo_primary_place_id'] ?? '') === (string) ($primary_location['geo_provider_place_id'] ?? '')
            && (string) ($values['_alma_geo_provider'] ?? '') === (string) ($primary_location['geo_provider'] ?? '')
            && (string) ($values['_alma_geo_primary_lat'] ?? '') !== ''
            && (string) ($values['_alma_geo_primary_lng'] ?? '') !== '';
    }

    private function is_yes($value) {
        return in_array((string) $value, array('1', 'yes', 'true', 'on'), true);
    }

    private function render_input($key, $label, $values, $type = 'text', $attrs = '') {
        printf('<div class="alma-geo-field"><label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="alma_geo[%1$s]" value="%4$s" %5$s></div>', esc_attr($key), esc_html($label), esc_attr($type), esc_attr($values[$key] ?? ''), $attrs);
    }

    private function render_textarea($key, $label, $values, $rows = 4) {
        printf('<div class="alma-geo-field"><label for="%1$s">%2$s</label><textarea id="%1$s" name="alma_geo[%1$s]" rows="%3$d">%4$s</textarea></div>', esc_attr($key), esc_html($label), absint($rows), esc_textarea($values[$key] ?? ''));
    }

    private function render_select($key, $label, $values, $options) {
        echo '<div class="alma-geo-field"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label><select id="' . esc_attr($key) . '" name="alma_geo[' . esc_attr($key) . ']">';
        echo '<option value="">' . esc_html__('— Seleziona —', 'affiliate-link-manager-ai') . '</option>';
        foreach ($options as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($values[$key] ?? '', $option, false) . '>' . esc_html($this->status_label($key, $option)) . '</option>';
        }
        echo '</select></div>';
    }

    private function sanitize_allowed($value, $allowed, $default) {
        $value = sanitize_key($value);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function sanitize_json_textarea($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return wp_json_encode($decoded);
        }
        return sanitize_textarea_field($value);
    }
}
