<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin metabox for editing Geo Index data on content objects.
 */
class ALMA_Geo_Index_Metabox {
    const NONCE_ACTION = 'alma_geo_index_save_metabox';
    const NONCE_NAME = 'alma_geo_index_nonce';

    private $store;

    public function __construct($store = null) {
        $this->store = $store ?: new ALMA_Geo_Index_Store();
    }

    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save'), 30, 2);
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
        $primary_location = $this->store->get_primary_location_for_object($post->ID, $post->post_type);
        $effective_geocoding_status = $this->effective_geocoding_status($values, $primary_location);
        $meta_synced = $this->is_primary_location_synced($values, $primary_location);
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
        ));
        ?>
        <p><?php esc_html_e('Vista sintetica dei dati geografici del contenuto. I campi manuali restano modificabili, mentre i dettagli tecnici sono raccolti nelle sezioni avanzate.', 'affiliate-link-manager-ai'); ?></p>
        <style>
            .alma-geo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 18px}.alma-geo-field label{display:block;font-weight:600;margin-bottom:4px}.alma-geo-field input,.alma-geo-field select,.alma-geo-field textarea{width:100%;max-width:100%}.alma-geo-section{border-top:1px solid #dcdcde;margin-top:16px;padding-top:12px}.alma-geo-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}.alma-geo-kv{display:grid;grid-template-columns:180px 1fr;gap:6px 12px}.alma-geo-kv dt{font-weight:600}.alma-geo-badge{display:inline-block;border-radius:999px;padding:3px 9px;font-weight:600;background:#f0f0f1;color:#2c3338}.alma-geo-badge-active{background:#d1e7dd;color:#0f5132}.alma-geo-badge-inactive{background:#f8d7da;color:#842029}.alma-geo-badge-pending{background:#fff3cd;color:#664d03}.alma-geo-badge-verified{background:#cfe2ff;color:#084298}.alma-geo-badge-manual{background:#fde2c2;color:#7a3e00}.alma-geo-badge-failed{background:#f8d7da;color:#842029}.alma-geo-badge-ambiguous{background:#e2d9f3;color:#3d0a69}.alma-geo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.alma-geo-actions .button{margin:0}.alma-geo-details{border:1px solid #dcdcde;padding:10px 12px;margin-top:14px;background:#fff}.alma-geo-details summary{font-weight:600;cursor:pointer}
        </style>

        <div class="alma-geo-section">
            <h3><?php esc_html_e('Stato sintetico', 'affiliate-link-manager-ai'); ?></h3>
            <div class="alma-geo-summary">
                <div><?php esc_html_e('Indice geografico:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_record_status_badge($values['_alma_geo_enabled'] ?? 'no'); ?></div>
                <div><?php esc_html_e('Stato import:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_import_status_badge($values['_alma_geo_import_status'] ?? 'review'); ?></div>
                <div><?php esc_html_e('Stato geocoding:', 'affiliate-link-manager-ai'); ?> <?php echo $this->render_geocoding_status_badge($effective_geocoding_status); ?></div>
            </div>
            <p>
                <label><input type="checkbox" name="alma_geo[_alma_geo_enabled]" value="yes" <?php checked($this->is_yes($values['_alma_geo_enabled'] ?? ''), true); ?>> <?php esc_html_e('Abilita geolocalizzazione', 'affiliate-link-manager-ai'); ?></label>
                &nbsp;&nbsp;
                <label><input type="checkbox" name="alma_geo[_alma_geo_widget_eligible]" value="yes" <?php checked($this->is_yes($values['_alma_geo_widget_eligible'] ?? ''), true); ?>> <?php esc_html_e('Widget eligible', 'affiliate-link-manager-ai'); ?></label>
            </p>
        </div>

        <div class="alma-geo-section">
            <h3><?php esc_html_e('Località primaria', 'affiliate-link-manager-ai'); ?></h3>
            <div class="alma-geo-grid">
                <?php $this->render_input('_alma_geo_primary_name', __('Nome località', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_select('_alma_geo_primary_type', __('Tipo', 'affiliate-link-manager-ai'), $display_values, self::primary_types()); ?>
                <?php $this->render_input('_alma_geo_primary_country', __('Paese', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_input('_alma_geo_primary_region', __('Regione', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_input('_alma_geo_primary_city', __('Città', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_input('_alma_geo_primary_area', __('Area', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_input('_alma_geo_primary_poi', __('POI', 'affiliate-link-manager-ai'), $display_values); ?>
            </div>
        </div>

        <div class="alma-geo-section">
            <h3><?php esc_html_e('Dati geocoding', 'affiliate-link-manager-ai'); ?></h3>
            <dl class="alma-geo-kv">
                <dt><?php esc_html_e('Provider', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html($provider); ?></dd>
                <dt><?php esc_html_e('Place ID', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html($place_id); ?></dd>
                <dt><?php esc_html_e('Formatted address', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html($formatted_address); ?></dd>
                <dt><?php esc_html_e('Latitudine', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html((string) $lat); ?></dd>
                <dt><?php esc_html_e('Longitudine', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html((string) $lng); ?></dd>
                <dt><?php esc_html_e('Ultimo geocoding', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html($primary_location['geocoded_at'] ?? ''); ?></dd>
                <dt><?php esc_html_e('Errore', 'affiliate-link-manager-ai'); ?></dt><dd><?php echo esc_html($primary_location['geocoding_error'] ?? ''); ?></dd>
            </dl>
            <div class="alma-geo-grid">
                <?php $this->render_input('_alma_geo_primary_lat', __('Latitudine manuale', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_primary_lat' => $lat)), 'number', 'step="0.0000001"'); ?>
                <?php $this->render_input('_alma_geo_primary_lng', __('Longitudine manuale', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_primary_lng' => $lng)), 'number', 'step="0.0000001"'); ?>
                <?php $this->render_input('_alma_geo_primary_place_id', __('Place ID', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_primary_place_id' => $place_id))); ?>
                <?php $this->render_input('_alma_geo_provider', __('Provider geocoding', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_provider' => $provider))); ?>
                <?php $this->render_input('_alma_geo_primary_formatted_address', __('Formatted address', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_primary_formatted_address' => $formatted_address))); ?>
                <input type="hidden" name="alma_geo[_alma_geo_primary_provider]" value="<?php echo esc_attr($provider); ?>">
            </div>
            <div class="alma-geo-actions">
                <a class="button" href="#alma_geo_suggested_geocoding_query"><?php esc_html_e('Cerca località', 'affiliate-link-manager-ai'); ?></a>
                <?php if ($effective_geocoding_status === 'pending') : ?>
                    <span class="button disabled" aria-disabled="true"><?php esc_html_e('Geocodifica questa località dalla tab Geocoding', 'affiliate-link-manager-ai'); ?></span>
                <?php endif; ?>
                <?php if (!empty($primary_location['id']) && ($primary_location['geocoding_status'] ?? '') === 'verified' && !$meta_synced) : ?>
                    <button type="submit" class="button" name="alma_geo_resync_post" value="1"><?php esc_html_e('Risincronizza dati località', 'affiliate-link-manager-ai'); ?></button>
                <?php endif; ?>
            </div>
        </div>

        <details class="alma-geo-details">
            <summary><?php esc_html_e('Classificazione', 'affiliate-link-manager-ai'); ?></summary>
            <div class="alma-geo-grid">
                <?php $this->render_select('_alma_geo_content_type', __('Content type', 'affiliate-link-manager-ai'), $values, self::content_types()); ?>
                <?php $this->render_select('_alma_geo_commercial_intent', __('Commercial intent', 'affiliate-link-manager-ai'), $values, self::commercial_intents()); ?>
                <?php $this->render_select('_alma_geo_scope', __('Geo scope', 'affiliate-link-manager-ai'), $values, self::geo_scopes()); ?>
                <?php $this->render_input('_alma_geo_confidence', __('Confidence', 'affiliate-link-manager-ai'), $values, 'number', 'step="0.001" min="0" max="1"'); ?>
                <?php $this->render_input('_alma_geo_match_weight', __('Match weight', 'affiliate-link-manager-ai'), $values, 'number'); ?>
            </div>
        </details>

        <details class="alma-geo-details">
            <summary><?php esc_html_e('Campi tecnici', 'affiliate-link-manager-ai'); ?></summary>
            <div class="alma-geo-grid">
                <?php $this->render_input('_alma_geo_primary_canonical_name', __('Nome canonico', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_input('_alma_geo_primary_country_code', __('Codice paese', 'affiliate-link-manager-ai'), $display_values); ?>
                <?php $this->render_select('_alma_geo_import_status', __('Geo import status', 'affiliate-link-manager-ai'), $values, self::geo_import_statuses()); ?>
                <?php $this->render_select('_alma_geo_geocoding_status', __('Stato geocoding meta', 'affiliate-link-manager-ai'), array_merge($values, array('_alma_geo_geocoding_status' => $effective_geocoding_status)), self::geocoding_statuses()); ?>
                <?php $this->render_input('_alma_geo_source', __('Fonte dati', 'affiliate-link-manager-ai'), $values); ?>
                <?php $this->render_input('_alma_geo_updated_at', __('Ultimo aggiornamento', 'affiliate-link-manager-ai'), $values, 'text', 'readonly'); ?>
                <div class="alma-geo-field" style="grid-column:1/-1">
                    <label for="alma_geo_suggested_geocoding_query"><?php esc_html_e('Query geocoding suggerita', 'affiliate-link-manager-ai'); ?></label>
                    <input type="text" id="alma_geo_suggested_geocoding_query" name="alma_geo[suggested_geocoding_query]" value="<?php echo esc_attr($suggested_query); ?>">
                </div>
            </div>
            <?php $this->render_textarea('_alma_geo_quality_flags', __('Quality flags', 'affiliate-link-manager-ai'), $values, 3); ?>
            <?php $this->render_textarea('_alma_geo_notes', __('Note interne', 'affiliate-link-manager-ai'), $values, 4); ?>
        </details>

        <details class="alma-geo-details">
            <summary><?php esc_html_e('Località secondarie', 'affiliate-link-manager-ai'); ?></summary>
            <?php $this->render_textarea('_alma_geo_locations_json', __('Località secondarie JSON', 'affiliate-link-manager-ai'), $values, 5); ?>
        </details>
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
                'geo_provider' => $data['_alma_geo_provider'],
                'geo_provider_place_id' => $data['_alma_geo_primary_place_id'],
                'suggested_geocoding_query' => $data['suggested_geocoding_query'] ?? '',
                'geocoding_status' => $data['_alma_geo_geocoding_status'],
            ));
        }

        $this->store->upsert_content_index($post_id, $post_type, $location_id, array(
            'is_primary' => 1,
            'role' => 'primary',
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
    public static function geo_import_statuses() { return array('active','ready','review','discard','needs_geocoding','geocoding_failed'); }
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


    private function render_record_status_badge($value) {
        $active = $this->is_yes($value);
        $label = $active ? __('Attivo', 'affiliate-link-manager-ai') : __('Non attivo', 'affiliate-link-manager-ai');
        $class = $active ? 'alma-geo-badge-active' : 'alma-geo-badge-inactive';
        return '<span class="alma-geo-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    private function render_import_status_badge($status) {
        $status = sanitize_key($status);
        $class = in_array($status, array('active', 'ready', 'needs_geocoding'), true) ? 'alma-geo-badge-active' : 'alma-geo-badge-inactive';
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
