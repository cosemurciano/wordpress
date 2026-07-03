<?php
/**
 * Mappa frontend delle località con articoli geolocalizzati.
 *
 * Rendering con Leaflet + tile OpenStreetMap (libreria BSD inclusa nel plugin,
 * nessuna API key e nessun costo — Maps JavaScript API non è utilizzabile).
 *
 * - Shortcode [alma_geo_map width="100%" height="600px" zoom="2"]: mappa mondo
 *   con marker sulle località (lat/lng presenti) collegate ad articoli
 *   pubblicati; ricerca ampia sopra la mappa sui nomi delle località.
 * - Click sul marker: popup con gli articoli e link alla pagina elenco
 *   configurata (shortcode [alma_geo_location_articles]).
 * - Pagina impostazioni dedicata: categorie da escludere, pagina elenco,
 *   dimensioni di default. Tile server personalizzabile con i filtri
 *   `alma_geo_map_tile_url` e `alma_geo_map_tile_attribution`.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Geo_Map {
    const MENU_SLUG = 'alma-geo-map';
    const OPTION_EXCLUDED_CATS = 'alma_geo_map_excluded_categories';
    const OPTION_LIST_PAGE = 'alma_geo_map_list_page_id';
    const OPTION_DEFAULT_WIDTH = 'alma_geo_map_default_width';
    const OPTION_DEFAULT_HEIGHT = 'alma_geo_map_default_height';
    const OPTION_ACCENT = 'alma_geo_map_accent_color';
    const DEFAULT_ACCENT = '#2271b1';
    const MARKERS_CACHE_KEY = 'alma_geo_map_markers_v1';
    const MARKERS_CACHE_TTL = 900; // 15 minuti
    const MAX_MARKERS = 2000;

    private $store;

    public function __construct($store = null) {
        $this->store = $store instanceof ALMA_Geo_Index_Store ? $store : new ALMA_Geo_Index_Store();
    }

    public function init() {
        add_shortcode('alma_geo_map', array($this, 'render_map_shortcode'));
        add_shortcode('alma_geo_location_articles', array($this, 'render_location_articles_shortcode'));
        add_action('admin_menu', array($this, 'add_menu'), 11);
        add_action('wp_ajax_alma_geo_map_markers', array($this, 'ajax_markers'));
        add_action('wp_ajax_nopriv_alma_geo_map_markers', array($this, 'ajax_markers'));
        add_action('wp_ajax_alma_geo_map_articles', array($this, 'ajax_articles'));
        add_action('wp_ajax_nopriv_alma_geo_map_articles', array($this, 'ajax_articles'));
        add_action('save_post_post', array($this, 'invalidate_markers_cache'));
    }

    public function invalidate_markers_cache() {
        delete_transient(self::MARKERS_CACHE_KEY);
    }

    /**
     * Colore accento dei componenti frontend (popup mappa, pagina elenco,
     * Trova il tuo viaggio): configurabile dalle impostazioni per integrarsi
     * con la palette del tema attivo (es. BeTheme). Default retrocompatibile.
     */
    public static function get_accent_color() {
        $color = sanitize_hex_color((string) get_option(self::OPTION_ACCENT, self::DEFAULT_ACCENT));
        return $color ? $color : self::DEFAULT_ACCENT;
    }

    /* ---------------------------------------------------------------------
     * Shortcode mappa
     * ------------------------------------------------------------------ */

    public function render_map_shortcode($atts) {
        $atts = shortcode_atts(array(
            'width' => get_option(self::OPTION_DEFAULT_WIDTH, '100%'),
            'height' => get_option(self::OPTION_DEFAULT_HEIGHT, '600px'),
            'zoom' => 2,
            'search' => 'yes',
        ), $atts, 'alma_geo_map');

        $width = $this->sanitize_css_dimension($atts['width'], '100%');
        $height = $this->sanitize_css_dimension($atts['height'], '600px');
        $zoom = max(1, min(12, absint($atts['zoom'])));
        $show_search = sanitize_key($atts['search']) !== 'no';

        $this->enqueue_map_assets();

        static $instance = 0;
        $instance++;
        $map_id = 'alma-geo-map-' . $instance;

        $list_page_id = absint(get_option(self::OPTION_LIST_PAGE, 0));
        $list_url = $list_page_id > 0 ? get_permalink($list_page_id) : '';

        ob_start();
        ?>
        <div class="alma-geo-map-wrap" style="width:<?php echo esc_attr($width); ?>;max-width:100%;">
            <?php if ($show_search) : ?>
                <div class="alma-geo-map-search" style="margin:0 0 10px;position:relative;">
                    <input type="search"
                           class="alma-geo-map-search-input"
                           data-map="<?php echo esc_attr($map_id); ?>"
                           list="<?php echo esc_attr($map_id); ?>-locations"
                           placeholder="<?php esc_attr_e('🔍 Cerca una località… (es. Copenaghen, Maldive, Parigi)', 'affiliate-link-manager-ai'); ?>"
                           style="width:100%;padding:14px 18px;font-size:16px;border:2px solid #d0d5dd;border-radius:8px;box-sizing:border-box;" />
                    <datalist id="<?php echo esc_attr($map_id); ?>-locations"></datalist>
                    <p class="alma-geo-map-search-feedback" style="display:none;margin:6px 2px 0;font-size:13px;color:#d63638;"></p>
                </div>
            <?php endif; ?>
            <div id="<?php echo esc_attr($map_id); ?>"
                 class="alma-geo-map"
                 data-zoom="<?php echo esc_attr((string) $zoom); ?>"
                 data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                 data-list-url="<?php echo esc_url($list_url); ?>"
                 style="width:100%;height:<?php echo esc_attr($height); ?>;min-height:280px;border-radius:8px;background:#e8ecf1;"></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Valida il template URL delle tile PRESERVANDO i placeholder {z}/{x}/{y}:
     * esc_url_raw rimuove le parentesi graffe e trasformava l'URL in
     * ".../z/x/y.png" — tutte le tile andavano in 404 e la mappa restava grigia
     * con i soli marker visibili. Il valore viene emesso via wp_localize_script
     * (JSON-encoded), quindi qui basta validare schema e caratteri.
     */
    private function sanitize_tile_url($url) {
        $default = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
        $url = trim((string) $url);
        if (preg_match('#^https://[a-z0-9.\-]+/[a-z0-9._\-/{}?&=%]*\{z\}[a-z0-9._\-/{}?&=%]*\{x\}[a-z0-9._\-/{}?&=%]*\{y\}[a-z0-9._\-/{}?&=%]*$#i', $url)) {
            return $url;
        }
        return $default;
    }

    /**
     * Dimensioni CSS sicure per lo shortcode: numeri con unità px/%/vh/vw/em/rem
     * (default px se l'unità manca).
     */
    private function sanitize_css_dimension($value, $fallback) {
        $value = trim((string) $value);
        if (preg_match('/^(\d+(?:\.\d+)?)(px|%|vh|vw|em|rem)?$/i', $value, $m)) {
            $unit = isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : 'px';
            return $m[1] . $unit;
        }
        return $fallback;
    }

    private function enqueue_map_assets() {
        // Leaflet è incluso nel plugin: nessun CDN, nessuna chiave, nessun costo.
        wp_enqueue_style('alma-leaflet', ALMA_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4');
        // Icona di chiusura del popup più evidente: cerchio bianco con X grande.
        wp_add_inline_style('alma-leaflet', '
            .alma-geo-map .leaflet-popup-close-button {
                width: 28px !important;
                height: 28px !important;
                top: 8px !important;
                right: 8px !important;
                font-size: 20px !important;
                font-weight: 700;
                line-height: 26px !important;
                color: #1d2327 !important;
                background: #f0f0f1 !important;
                border-radius: 50%;
                box-shadow: 0 1px 3px rgba(0,0,0,.25);
                text-align: center;
            }
            .alma-geo-map .leaflet-popup-close-button:hover {
                background: #d63638 !important;
                color: #fff !important;
            }
            .alma-geo-map .leaflet-popup-content { margin: 14px 18px; }
        ');
        wp_enqueue_script('alma-leaflet', ALMA_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true);
        if (file_exists(ALMA_PLUGIN_DIR . 'assets/geo-map.js')) {
            wp_enqueue_script('alma-geo-map', ALMA_PLUGIN_URL . 'assets/geo-map.js', array('alma-leaflet'), ALMA_VERSION, true);
            wp_localize_script('alma-geo-map', 'almaGeoMapCfg', array(
                'leafletImages' => ALMA_PLUGIN_URL . 'assets/vendor/leaflet/images/',
                /**
                 * Tile server personalizzabile: il default OpenStreetMap è adatto a
                 * traffico moderato (tile usage policy OSMF); per siti ad alto
                 * traffico impostare un provider dedicato con questi filtri.
                 */
                'tileUrl' => $this->sanitize_tile_url(apply_filters('alma_geo_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')),
                'tileAttribution' => wp_kses_post(apply_filters('alma_geo_map_tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors')),
                'accentColor' => self::get_accent_color(),
            ));
        }
    }

    /* ---------------------------------------------------------------------
     * Endpoint dati (pubblici, sola lettura su contenuti pubblicati)
     * ------------------------------------------------------------------ */

    public function ajax_markers() {
        $markers = get_transient(self::MARKERS_CACHE_KEY);
        if (!is_array($markers)) {
            $markers = $this->build_markers();
            set_transient(self::MARKERS_CACHE_KEY, $markers, self::MARKERS_CACHE_TTL);
        }
        wp_send_json_success($markers);
    }

    /**
     * Località con coordinate e almeno un articolo pubblicato (escluse le
     * categorie configurate), raggruppate per coordinate arrotondate così le
     * righe-località duplicate (import diversi) diventano un solo marker.
     */
    private function build_markers() {
        global $wpdb;
        if (!$this->store->tables_exist()) {
            return array();
        }

        $excluded = $this->get_excluded_category_ids();
        $exclude_sql = '';
        $params = array(ALMA_Geo_Index_Store::OBJECT_TYPE_POST);
        if (!empty($excluded)) {
            $placeholders = implode(',', array_fill(0, count($excluded), '%d'));
            $exclude_sql = " AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = 'category' AND tt.term_id IN ($placeholders)
            )";
            $params = array_merge($params, $excluded);
        }
        $params[] = self::MAX_MARKERS;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ROUND(l.lat, 4) AS rlat, ROUND(l.lng, 4) AS rlng,
                    MIN(l.canonical_name) AS name,
                    GROUP_CONCAT(DISTINCT l.id) AS location_ids,
                    COUNT(DISTINCT p.ID) AS article_count
             FROM {$this->store->table_locations()} l
             INNER JOIN {$this->store->table_content_index()} ci ON ci.location_id = l.id AND ci.object_type = %s
             INNER JOIN {$wpdb->posts} p ON p.ID = ci.object_id AND p.post_type = 'post' AND p.post_status = 'publish'
             WHERE l.lat IS NOT NULL AND l.lng IS NOT NULL
             {$exclude_sql}
             GROUP BY rlat, rlng
             ORDER BY article_count DESC
             LIMIT %d",
            $params
        ), ARRAY_A);

        $markers = array();
        foreach ((array) $rows as $row) {
            $ids = implode(',', array_map('absint', array_filter(explode(',', (string) $row['location_ids']))));
            $markers[] = array(
                'name' => html_entity_decode(sanitize_text_field($row['name']), ENT_QUOTES, 'UTF-8'),
                'lat' => (float) $row['rlat'],
                'lng' => (float) $row['rlng'],
                'count' => (int) $row['article_count'],
                'ids' => $ids,
            );
        }
        return $markers;
    }

    public function ajax_articles() {
        $ids = $this->sanitize_location_ids($_GET['location_ids'] ?? ($_POST['location_ids'] ?? ''));
        if (empty($ids)) {
            wp_send_json_error(array('message' => __('Località non valida.', 'affiliate-link-manager-ai')), 400);
        }
        $articles = $this->get_articles_for_locations($ids, 10);
        wp_send_json_success($articles);
    }

    /**
     * Link affiliati associati alle località, raggruppati per tipologia:
     * alimenta la riga "Consigliati: 3 tour, 2 avventure".
     */
    public function get_recommended_link_counts($location_ids) {
        global $wpdb;
        $location_ids = array_values(array_filter(array_map('absint', (array) $location_ids)));
        if (empty($location_ids) || !$this->store->tables_exist()) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($location_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.name AS label, COUNT(DISTINCT p.ID) AS total
             FROM {$this->store->table_content_index()} ci
             INNER JOIN {$wpdb->posts} p ON p.ID = ci.object_id AND p.post_type = 'affiliate_link' AND p.post_status = 'publish'
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'link_type'
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE ci.object_type = %s AND ci.location_id IN ($placeholders)
             GROUP BY t.term_id
             ORDER BY total DESC, t.name ASC
             LIMIT 6",
            array_merge(array(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK), $location_ids)
        ), ARRAY_A);

        $recommended = array();
        foreach ((array) $rows as $row) {
            $recommended[] = array(
                'label' => sanitize_text_field($row['label']),
                'count' => (int) $row['total'],
            );
        }
        return $recommended;
    }

    /**
     * Riga "Consigliati: 3 tour, 2 avventure" pronta per la stampa.
     */
    public function format_recommended_line($recommended) {
        if (empty($recommended)) {
            return '';
        }
        $parts = array();
        foreach ($recommended as $entry) {
            $parts[] = (int) $entry['count'] . ' ' . mb_strtolower($entry['label']);
        }
        return __('Consigliati:', 'affiliate-link-manager-ai') . ' ' . implode(', ', $parts);
    }

    private function sanitize_location_ids($raw) {
        $ids = array_values(array_filter(array_map('absint', explode(',', (string) $raw))));
        return array_slice($ids, 0, 20);
    }

    /**
     * Articoli pubblicati collegati alle località indicate (categorie escluse
     * rispettate), ordinati per data.
     */
    public function get_articles_for_locations($location_ids, $limit = 20, $paged = 1) {
        global $wpdb;
        if (empty($location_ids) || !$this->store->tables_exist()) {
            return array('items' => array(), 'total' => 0, 'location_name' => '');
        }
        $placeholders = implode(',', array_fill(0, count($location_ids), '%d'));
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ci.object_id FROM {$this->store->table_content_index()} ci
             WHERE ci.object_type = %s AND ci.location_id IN ($placeholders)",
            array_merge(array(ALMA_Geo_Index_Store::OBJECT_TYPE_POST), array_map('absint', $location_ids))
        ));
        $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));
        if (empty($post_ids)) {
            return array('items' => array(), 'total' => 0, 'location_name' => $this->location_label($location_ids));
        }

        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post__in' => $post_ids,
            'posts_per_page' => max(1, min(50, absint($limit))),
            'paged' => max(1, absint($paged)),
            'orderby' => 'date',
            'order' => 'DESC',
        );
        $excluded = $this->get_excluded_category_ids();
        if (!empty($excluded)) {
            $args['category__not_in'] = $excluded;
        }
        $query = new WP_Query($args);
        $items = array();
        foreach ($query->posts as $post) {
            $items[] = array(
                'id' => (int) $post->ID,
                // Decodifica le entità HTML (&#8217; ecc.): il JS inserisce i titoli
                // come testo, quindi le entità arriverebbero letterali a schermo.
                'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'url' => get_permalink($post),
                'date' => get_the_date('', $post),
                'excerpt' => html_entity_decode(wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 24, '…'), ENT_QUOTES, 'UTF-8'),
                'thumbnail' => get_the_post_thumbnail_url($post, 'medium') ?: '',
            );
        }
        $recommended = $this->get_recommended_link_counts($location_ids);
        return array(
            'items' => $items,
            'total' => (int) $query->found_posts,
            'location_name' => $this->location_label($location_ids),
            'recommended' => $recommended,
            'recommended_line' => $this->format_recommended_line($recommended),
        );
    }

    private function location_label($location_ids) {
        $location = $this->store->get_location(absint($location_ids[0] ?? 0));
        if (!$location) {
            return '';
        }
        $label = sanitize_text_field($location['canonical_name']);
        $country = sanitize_text_field($location['country'] ?: $location['country_code']);
        if ($country !== '' && strcasecmp($country, $label) !== 0) {
            $label .= ', ' . $country;
        }
        return $label;
    }

    private function get_excluded_category_ids() {
        $excluded = get_option(self::OPTION_EXCLUDED_CATS, array());
        return array_values(array_filter(array_map('absint', (array) $excluded)));
    }

    /* ---------------------------------------------------------------------
     * Shortcode pagina elenco articoli
     * ------------------------------------------------------------------ */

    public function render_location_articles_shortcode($atts) {
        $atts = shortcode_atts(array('per_page' => 20), $atts, 'alma_geo_location_articles');
        $ids = $this->sanitize_location_ids($_GET['alma_location'] ?? '');
        if (empty($ids)) {
            return '<p>' . esc_html__('Seleziona una località dalla mappa per vedere gli articoli collegati.', 'affiliate-link-manager-ai') . '</p>';
        }
        $paged = max(1, absint($_GET['alma_page'] ?? 1));
        $per_page = max(1, min(50, absint($atts['per_page'])));
        $data = $this->get_articles_for_locations($ids, $per_page, $paged);
        $accent = self::get_accent_color();

        ob_start();
        ?>
        <div class="alma-geo-location-articles">
            <?php if ($data['location_name'] !== '') : ?>
                <h2 class="alma-geo-location-articles__title">📍 <?php echo esc_html($data['location_name']); ?></h2>
                <p class="alma-geo-location-articles__count" style="margin:0 0 4px;color:#555;"><?php echo esc_html(sprintf(_n('%d articolo', '%d articoli', $data['total'], 'affiliate-link-manager-ai'), $data['total'])); ?></p>
                <?php if ($data['recommended_line'] !== '') : ?>
                    <p class="alma-geo-location-articles__recommended" style="margin:0 0 18px;font-weight:600;color:<?php echo esc_attr($accent); ?>;">🎯 <?php echo esc_html($data['recommended_line']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (empty($data['items'])) : ?>
                <p><?php esc_html_e('Nessun articolo trovato per questa località.', 'affiliate-link-manager-ai'); ?></p>
            <?php else : ?>
                <div class="alma-geo-location-articles__grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;">
                    <?php foreach ($data['items'] as $item) : ?>
                        <article class="alma-geo-location-articles__item" style="border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;background:#fff;">
                            <?php if ($item['thumbnail'] !== '') : ?>
                                <a href="<?php echo esc_url($item['url']); ?>"><img src="<?php echo esc_url($item['thumbnail']); ?>" alt="<?php echo esc_attr($item['title']); ?>" style="width:100%;height:160px;object-fit:cover;display:block;" loading="lazy" /></a>
                            <?php endif; ?>
                            <div style="padding:14px 16px;">
                                <h3 style="margin:0 0 6px;font-size:16px;"><a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['title']); ?></a></h3>
                                <p style="margin:0 0 6px;font-size:13px;color:#666;"><?php echo esc_html($item['date']); ?></p>
                                <p style="margin:0;font-size:14px;color:#444;"><?php echo esc_html($item['excerpt']); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php
                $total_pages = (int) ceil($data['total'] / $per_page);
                if ($total_pages > 1) {
                    echo '<nav class="alma-geo-location-articles__pagination" style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;">';
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $url = add_query_arg(array('alma_location' => implode(',', $ids), 'alma_page' => $i));
                        if ($i === $paged) {
                            echo '<span style="padding:6px 12px;background:' . esc_attr($accent) . ';color:#fff;border-radius:4px;">' . esc_html((string) $i) . '</span>';
                        } else {
                            echo '<a href="' . esc_url($url) . '" style="padding:6px 12px;border:1px solid #d0d5dd;border-radius:4px;">' . esc_html((string) $i) . '</a>';
                        }
                    }
                    echo '</nav>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /* ---------------------------------------------------------------------
     * Pagina impostazioni dedicata
     * ------------------------------------------------------------------ */

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Mappa Geografica', 'affiliate-link-manager-ai'),
            __('Mappa Geografica', 'affiliate-link-manager-ai'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }

        if (!empty($_POST['alma_geo_map_save']) && check_admin_referer('alma_geo_map_settings')) {
            update_option(self::OPTION_EXCLUDED_CATS, array_values(array_filter(array_map('absint', (array) ($_POST['alma_geo_map_excluded'] ?? array())))), false);
            update_option(self::OPTION_LIST_PAGE, absint($_POST[self::OPTION_LIST_PAGE] ?? 0), false);
            update_option(self::OPTION_DEFAULT_WIDTH, $this->sanitize_css_dimension($_POST[self::OPTION_DEFAULT_WIDTH] ?? '100%', '100%'), false);
            update_option(self::OPTION_DEFAULT_HEIGHT, $this->sanitize_css_dimension($_POST[self::OPTION_DEFAULT_HEIGHT] ?? '600px', '600px'), false);
            $accent = sanitize_hex_color((string) ($_POST[self::OPTION_ACCENT] ?? ''));
            update_option(self::OPTION_ACCENT, $accent ? $accent : self::DEFAULT_ACCENT, false);
            $this->invalidate_markers_cache();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni Mappa Geografica salvate.', 'affiliate-link-manager-ai') . '</p></div>';
        }

        $excluded = $this->get_excluded_category_ids();
        $categories = get_categories(array('hide_empty' => false));
        $list_page_id = absint(get_option(self::OPTION_LIST_PAGE, 0));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Mappa Geografica', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php esc_html_e('Mappa Google frontend con le località che contengono articoli geolocalizzati. Inseriscila con lo shortcode qui sotto; il click su una località apre la pagina elenco articoli.', 'affiliate-link-manager-ai'); ?></p>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Shortcode', 'affiliate-link-manager-ai'); ?></h2>
                <p><code>[alma_geo_map width="100%" height="600px"]</code></p>
                <p class="description"><?php esc_html_e('Attributi: width e height accettano px, %, vh, vw, em, rem (es. width="80%" height="70vh"); zoom="2" (1-12) per lo zoom iniziale; search="no" per nascondere la ricerca. Per la pagina elenco usa lo shortcode:', 'affiliate-link-manager-ai'); ?> <code>[alma_geo_location_articles per_page="20"]</code></p>
            </div>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Integrazione con il tema (BeTheme / Muffin Builder)', 'affiliate-link-manager-ai'); ?></h2>
                <p><?php esc_html_e('Per una pagina "Esplora la mappa": crea una pagina con il Muffin Builder, aggiungi una sezione full width con padding 0 e inserisci lo shortcode in un elemento Column/Shortcode. La mappa si adatta alla larghezza della sezione.', 'affiliate-link-manager-ai'); ?></p>
                <p><?php esc_html_e('Tutti gli elementi frontend usano classi CSS stabili (alma-geo-map, alma-geo-popup-*, alma-geo-location-articles__*, alma-trip-finder__*) personalizzabili dal Custom CSS del tema. Il colore accento qui sotto permette di allineare popup e pulsanti alla palette del tema senza CSS.', 'affiliate-link-manager-ai'); ?></p>
            </div>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Motore mappa: Leaflet + OpenStreetMap', 'affiliate-link-manager-ai'); ?></h2>
                <p><?php esc_html_e('La mappa usa Leaflet (libreria open source inclusa nel plugin) con le mappe di OpenStreetMap: nessuna API key, nessun costo e nessun servizio Google. Per siti ad alto traffico è possibile impostare un tile server dedicato con i filtri alma_geo_map_tile_url e alma_geo_map_tile_attribution.', 'affiliate-link-manager-ai'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('alma_geo_map_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Categorie da escludere', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <?php if (empty($categories)) : ?>
                                <p class="description"><?php esc_html_e('Nessuna categoria presente.', 'affiliate-link-manager-ai'); ?></p>
                            <?php else : ?>
                                <div style="max-height:220px;overflow:auto;border:1px solid #d0d5dd;border-radius:4px;padding:10px 12px;max-width:420px;">
                                    <?php foreach ($categories as $category) : ?>
                                        <label style="display:block;margin-bottom:4px;">
                                            <input type="checkbox" name="alma_geo_map_excluded[]" value="<?php echo esc_attr((string) $category->term_id); ?>" <?php checked(in_array((int) $category->term_id, $excluded, true)); ?> />
                                            <?php echo esc_html($category->name); ?> <span style="color:#888;">(<?php echo esc_html((string) $category->count); ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="description"><?php esc_html_e('Gli articoli nelle categorie selezionate non vengono considerati: né nei marker né negli elenchi.', 'affiliate-link-manager-ai'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_LIST_PAGE); ?>"><?php esc_html_e('Pagina elenco articoli', 'affiliate-link-manager-ai'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(array(
                                'name' => self::OPTION_LIST_PAGE,
                                'id' => self::OPTION_LIST_PAGE,
                                'selected' => $list_page_id,
                                'show_option_none' => __('— Nessuna (elenco solo nel popup della mappa) —', 'affiliate-link-manager-ai'),
                                'option_none_value' => '0',
                            )); ?>
                            <p class="description"><?php esc_html_e('La pagina che si apre al click su una località: deve contenere lo shortcode [alma_geo_location_articles]. Senza pagina, il popup sulla mappa mostra comunque gli articoli.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ACCENT); ?>"><?php esc_html_e('Colore accento', 'affiliate-link-manager-ai'); ?></label></th>
                        <td>
                            <input type="color" name="<?php echo esc_attr(self::OPTION_ACCENT); ?>" id="<?php echo esc_attr(self::OPTION_ACCENT); ?>" value="<?php echo esc_attr(self::get_accent_color()); ?>" />
                            <p class="description"><?php esc_html_e('Colore di pulsanti ed evidenziazioni nei componenti frontend (popup mappa, pagina elenco, Trova il tuo viaggio). Impostalo sul colore del tema, es. #2E8CCB per BeTheme di sothra.it. Default: #2271b1.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dimensioni di default', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <label><?php esc_html_e('Larghezza', 'affiliate-link-manager-ai'); ?> <input type="text" name="<?php echo esc_attr(self::OPTION_DEFAULT_WIDTH); ?>" value="<?php echo esc_attr((string) get_option(self::OPTION_DEFAULT_WIDTH, '100%')); ?>" class="small-text" /></label>
                            <label style="margin-left:16px;"><?php esc_html_e('Altezza', 'affiliate-link-manager-ai'); ?> <input type="text" name="<?php echo esc_attr(self::OPTION_DEFAULT_HEIGHT); ?>" value="<?php echo esc_attr((string) get_option(self::OPTION_DEFAULT_HEIGHT, '600px')); ?>" class="small-text" /></label>
                            <p class="description"><?php esc_html_e('Usate quando lo shortcode non specifica width/height. Unità: px, %, vh, vw, em, rem.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salva impostazioni', 'affiliate-link-manager-ai'), 'primary', 'alma_geo_map_save'); ?>
            </form>
        </div>
        <?php
    }
}
