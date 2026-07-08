<?php
/**
 * Mappa interattiva delle località citate nell'articolo, a fine contenuto.
 *
 * Dati: le località associate all'articolo dall'indice geografico
 * (content_index → locations) con coordinate già geocodificate. La località
 * primaria è evidenziata nel colore accento. Ogni marker apre un popup con
 * il pulsante "Apri in Google Maps" (nuova scheda): quando la località ha il
 * place_id di Google salvato si apre la scheda esatta del luogo, altrimenti
 * si usano le coordinate. Nessuna API key necessaria.
 *
 * Rendering: Leaflet incluso nel plugin (stesso stack della mappa Trova
 * Viaggio) + tile OpenStreetMap; la mappa viene inizializzata SOLO quando
 * l'utente ci scrolla vicino (IntersectionObserver): zero impatto sul
 * caricamento della pagina. Se l'articolo non ha località geocodificate non
 * viene mostrato nulla.
 *
 * Controlli: opzione globale (default attiva), shortcode [alma_mappa_articolo]
 * per il posizionamento manuale (disattiva l'append automatico in quel post),
 * metabox per escludere il singolo articolo.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Article_Locations_Map {
    const OPTION_ENABLED = 'alma_article_map_enabled';
    const META_DISABLE = '_alma_article_map_disable';
    const SHORTCODE = 'alma_mappa_articolo';
    const MAX_MARKERS = 10;
    const MAX_LINKS_PER_LOCATION = 2;
    const LINKS_CACHE_TTL = 43200; // 12 ore

    public static function init() {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));
        // Dopo i filtri standard (shortcode degli articoli a 11): priorità 30.
        add_filter('the_content', array(__CLASS__, 'append_to_content'), 30);
        add_action('add_meta_boxes_post', array(__CLASS__, 'register_metabox'));
        add_action('save_post_post', array(__CLASS__, 'save_metabox'));
    }

    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, '1') === '1';
    }

    /* ---------------------------------------------------------------------
     * Dati
     * ------------------------------------------------------------------ */

    /**
     * Località geocodificate dell'articolo: primaria per prima, poi per peso.
     */
    public static function get_locations($post_id) {
        if (!class_exists('ALMA_Geo_Index_Store')) { return array(); }
        $store = new ALMA_Geo_Index_Store();
        if (!$store->tables_exist()) { return array(); }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.canonical_name, l.country, l.type, l.lat, l.lng, l.geo_provider_place_id, ci.is_primary
             FROM {$store->table_content_index()} ci
             INNER JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_id = %d AND ci.object_type = %s
               AND l.lat IS NOT NULL AND l.lng IS NOT NULL
             ORDER BY ci.is_primary DESC, ci.match_weight DESC, l.id ASC
             LIMIT %d",
            absint($post_id), ALMA_Geo_Index_Store::OBJECT_TYPE_POST, self::MAX_MARKERS
        ), ARRAY_A);
        $out = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $lat = (float) $row['lat'];
            $lng = (float) $row['lng'];
            if ($lat === 0.0 && $lng === 0.0) { continue; }
            $name = html_entity_decode(sanitize_text_field((string) $row['canonical_name']), ENT_QUOTES, 'UTF-8');
            $key = strtolower($name) . '|' . round($lat, 4) . '|' . round($lng, 4);
            if ($name === '' || isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = array(
                'name' => $name,
                'country' => html_entity_decode(sanitize_text_field((string) $row['country']), ENT_QUOTES, 'UTF-8'),
                'lat' => $lat,
                'lng' => $lng,
                'primary' => !empty($row['is_primary']),
                'gmaps' => self::build_gmaps_url($name, $lat, $lng, (string) $row['geo_provider_place_id']),
                // Fase 2: i migliori tour/attività della zona nel popup.
                'links' => self::top_links_for_location((int) $row['id'], $store),
            );
        }
        return $out;
    }

    /**
     * I migliori link affiliati dell'AREA della località (vivi, pubblicati,
     * per click decrescenti, max 2): la conversione direttamente nel popup.
     * Cache 12h per località: la query sull'area non pesa sul rendering.
     */
    private static function top_links_for_location($location_id, $store) {
        $location_id = absint($location_id);
        if ($location_id < 1) { return array(); }
        $cache_key = 'alma_artmap_links_' . $location_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) { return $cached; }

        $rows = array();
        foreach ((array) $store->get_affiliate_link_ids_for_area($location_id) as $link_id) {
            $link_id = absint($link_id);
            if ($link_id < 1 || get_post_status($link_id) !== 'publish') { continue; }
            $url = trim((string) get_post_meta($link_id, '_affiliate_url', true));
            if ($url === '') { continue; }
            if (class_exists('ALMA_Link_Health_Checker') && ALMA_Link_Health_Checker::is_dead($link_id)) { continue; }
            $rows[] = array(
                'id' => $link_id,
                'title' => html_entity_decode(sanitize_text_field((string) get_the_title($link_id)), ENT_QUOTES, 'UTF-8'),
                'url' => $url,
                'clicks' => (int) get_post_meta($link_id, '_click_count', true),
            );
        }
        usort($rows, function ($a, $b) { return $b['clicks'] <=> $a['clicks']; });
        $links = array();
        foreach (array_slice($rows, 0, self::MAX_LINKS_PER_LOCATION) as $row) {
            $links[] = array('id' => $row['id'], 'title' => $row['title'], 'url' => $row['url']);
        }
        set_transient($cache_key, $links, self::LINKS_CACHE_TTL);
        return $links;
    }

    /**
     * URL Google Maps ufficiale (nessuna API key). Con place_id si apre la
     * scheda esatta del luogo; altrimenti le coordinate. Funzione pura.
     */
    public static function build_gmaps_url($name, $lat, $lng, $place_id = '') {
        $place_id = trim((string) $place_id);
        $name = trim((string) $name);
        if ($place_id !== '' && $name !== '') {
            return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($name) . '&query_place_id=' . rawurlencode($place_id);
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(round((float) $lat, 7) . ',' . round((float) $lng, 7));
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    public static function render_shortcode($atts = array()) {
        $post_id = get_the_ID();
        if (!$post_id) { return ''; }
        return self::render_map($post_id);
    }

    public static function append_to_content($content) {
        if (!self::is_enabled() || is_admin() || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        // Temi e builder (BeTheme/WPBakery) applicano the_content più volte
        // sulla stessa pagina: la mappa va aggiunta UNA sola volta.
        if (!did_action('wp_head')) { return $content; } // passaggi in <head> (SEO/schema)
        if (strpos((string) $content, 'alma-article-map') !== false) { return $content; }
        $post_id = get_the_ID();
        if (!$post_id) { return $content; }
        static $rendered = array();
        if (isset($rendered[$post_id])) { return $content; }
        if (get_post_meta($post_id, self::META_DISABLE, true) === '1') { return $content; }
        // Posizionamento manuale via shortcode: niente doppioni in coda.
        if (has_shortcode((string) get_post_field('post_content', $post_id), self::SHORTCODE)) { return $content; }
        $map = self::render_map($post_id);
        if ($map === '') { return $content; }
        $rendered[$post_id] = true;
        return $content . "\n" . $map;
    }

    private static function render_map($post_id) {
        $locations = self::get_locations($post_id);
        if (empty($locations)) { return ''; }

        wp_enqueue_style('alma-leaflet', ALMA_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4');
        wp_enqueue_script('alma-leaflet', ALMA_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true);
        wp_enqueue_script('alma-article-map', ALMA_PLUGIN_URL . 'assets/article-map.js', array('alma-leaflet'), ALMA_VERSION, true);

        $accent = class_exists('ALMA_Geo_Map') ? ALMA_Geo_Map::get_accent_color() : '#1a6ee0';
        $tile_url = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
        $tile_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
        $saved_tile = trim((string) get_option('alma_geo_map_tile_url', ''));
        if ($saved_tile !== '' && strpos($saved_tile, '{z}') !== false) { $tile_url = $saved_tile; }
        $saved_attr = trim((string) get_option('alma_geo_map_tile_attribution', ''));
        if ($saved_attr !== '') { $tile_attribution = $saved_attr; }

        static $instance = 0;
        $instance++;
        $map_id = 'alma-article-map-' . $instance;

        $output = '<div class="alma-article-map-wrap" style="margin:28px 0 8px;">';
        $output .= '<h3 class="alma-article-map-title" style="margin:0 0 10px;">📍 ' . esc_html__('I luoghi di questo articolo', 'affiliate-link-manager-ai') . '</h3>';
        $output .= '<div id="' . esc_attr($map_id) . '" class="alma-article-map"'
            . ' data-locations="' . esc_attr(wp_json_encode($locations)) . '"'
            . ' data-accent="' . esc_attr($accent) . '"'
            . ' data-tile-url="' . esc_attr($tile_url) . '"'
            . ' data-tile-attribution="' . esc_attr($tile_attribution) . '"'
            . ' data-open-label="' . esc_attr__('Apri in Google Maps', 'affiliate-link-manager-ai') . '"'
            . ' data-links-label="' . esc_attr__('Tour e attività', 'affiliate-link-manager-ai') . '"'
            . ' style="width:100%;height:400px;border-radius:10px;background:#e8ecf1;"></div>';
        // Accessibilità/SEO: l'elenco testuale esiste anche senza JavaScript.
        $output .= '<noscript><ul>';
        foreach ($locations as $location) {
            $output .= '<li><a href="' . esc_url($location['gmaps']) . '" target="_blank" rel="noopener nofollow">' . esc_html($location['name']) . ($location['country'] !== '' ? ' (' . esc_html($location['country']) . ')' : '') . '</a></li>';
        }
        $output .= '</ul></noscript>';
        $output .= '</div>';
        return $output;
    }

    /* ---------------------------------------------------------------------
     * Metabox di esclusione per singolo articolo
     * ------------------------------------------------------------------ */

    public static function register_metabox() {
        add_meta_box('alma_article_map', __('📍 Mappa località', 'affiliate-link-manager-ai'), array(__CLASS__, 'render_metabox'), 'post', 'side', 'low');
    }

    public static function render_metabox($post) {
        // Nessun <form> qui: la metabox vive dentro il form del post.
        wp_nonce_field('alma_article_map_meta', 'alma_article_map_nonce');
        $disabled = get_post_meta($post->ID, self::META_DISABLE, true) === '1';
        $count = count(self::get_locations($post->ID));
        echo '<label><input type="checkbox" name="' . esc_attr(self::META_DISABLE) . '" value="1" ' . checked($disabled, true, false) . '> ' . esc_html__('Non mostrare la mappa in questo articolo', 'affiliate-link-manager-ai') . '</label>';
        echo '<p class="description">' . esc_html(sprintf(__('Località geocodificate trovate: %d. La mappa compare a fine articolo solo se ce n\'è almeno una. Shortcode per posizionarla a mano: [%s].', 'affiliate-link-manager-ai'), $count, self::SHORTCODE)) . '</p>';
    }

    public static function save_metabox($post_id) {
        if (!isset($_POST['alma_article_map_nonce']) || !wp_verify_nonce($_POST['alma_article_map_nonce'], 'alma_article_map_meta')) { return; }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        if (!empty($_POST[self::META_DISABLE])) {
            update_post_meta($post_id, self::META_DISABLE, '1');
        } else {
            delete_post_meta($post_id, self::META_DISABLE);
        }
    }
}
