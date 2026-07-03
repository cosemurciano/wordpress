<?php
/**
 * "Trova il tuo viaggio": ricerca a faccette combinate sulle tassonomie
 * degli articoli (Dove, Quando, Durata, Perché… su sothra.it).
 *
 * - Shortcode [alma_trip_finder]: una select per ogni tassonomia configurata,
 *   con contatori aggiornati alla selezione corrente e opzioni impossibili
 *   disabilitate; risultati aggiornati via AJAX con fallback GET senza JS
 *   (il form si invia normalmente e la pagina si ricarica filtrata).
 * - Chip faccette negli articoli (opzionale, default disattivo): le tassonomie
 *   dell'articolo come link cliccabili verso la pagina Trova Viaggio
 *   pre-filtrata (fallback: archivio del termine).
 * - Pensato per il Muffin Builder di BeTheme: shortcode inseribile in una
 *   sezione, classi CSS stabili (alma-trip-finder__*) personalizzabili dal
 *   Custom CSS del tema, colore accento condiviso con la Mappa Geografica.
 *
 * Le tassonomie esistenti restano intatte (gli archivi continuano a lavorare
 * per la SEO): questo è solo un layer UX sopra i dati già presenti.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Trip_Finder {
    const MENU_SLUG = 'alma-trip-finder';
    const OPTION_TAXONOMIES = 'alma_trip_finder_taxonomies';
    const OPTION_PAGE = 'alma_trip_finder_page_id';
    const OPTION_EXCLUDED_CATS = 'alma_trip_finder_excluded_categories';
    const OPTION_PER_PAGE = 'alma_trip_finder_per_page';
    const OPTION_CHIPS = 'alma_trip_finder_chips';
    const OPTION_CACHE_VER = 'alma_trip_finder_cache_ver';
    const CACHE_TTL = 600; // 10 minuti
    const MAX_PER_PAGE = 48;
    const MAX_CHIPS_PER_TAXONOMY = 3;

    public function init() {
        add_shortcode('alma_trip_finder', array($this, 'render_shortcode'));
        add_action('admin_menu', array($this, 'add_menu'), 12);
        add_action('wp_ajax_alma_trip_finder_render', array($this, 'ajax_render'));
        add_action('wp_ajax_nopriv_alma_trip_finder_render', array($this, 'ajax_render'));
        // Le chip sono opt-in: comportamento invariato finché non vengono
        // attivate dalle impostazioni (retrocompatibilità).
        add_filter('the_content', array($this, 'append_facet_chips'), 12);
        // I contatori in cache dipendono dai termini assegnati agli articoli.
        add_action('save_post_post', array($this, 'bump_cache_version'));
        add_action('trashed_post', array($this, 'bump_cache_version'));
        add_action('deleted_post', array($this, 'bump_cache_version'));
    }

    public function bump_cache_version() {
        update_option(self::OPTION_CACHE_VER, absint(get_option(self::OPTION_CACHE_VER, 1)) + 1, false);
    }

    /* ---------------------------------------------------------------------
     * Configurazione
     * ------------------------------------------------------------------ */

    /**
     * Tassonomie faccetta valide: pubbliche e collegate ai post. L'override
     * (attributo shortcode o parametro AJAX) è comunque validato contro le
     * tassonomie reali dei post, mai usato così com'è.
     */
    public function get_facet_taxonomies($override = '') {
        $available = get_object_taxonomies('post', 'objects');
        $slugs = array();
        if ($override !== '') {
            $slugs = array_filter(array_map('sanitize_key', explode(',', (string) $override)));
        }
        if (empty($slugs)) {
            $slugs = array_filter(array_map('sanitize_key', (array) get_option(self::OPTION_TAXONOMIES, array('category'))));
        }
        $taxonomies = array();
        foreach ($slugs as $slug) {
            if (isset($available[$slug]) && $available[$slug]->public && $slug !== 'post_format') {
                $taxonomies[$slug] = $available[$slug];
            }
        }
        return $taxonomies;
    }

    /**
     * Selezione corrente dal parametro alma_f[tassonomia] = term_id,
     * mantenendo solo termini esistenti nelle tassonomie configurate.
     */
    public function read_selection($raw, $taxonomies) {
        $selection = array();
        foreach ((array) $raw as $tax => $term_id) {
            $tax = sanitize_key($tax);
            $term_id = absint($term_id);
            if ($term_id > 0 && isset($taxonomies[$tax])) {
                $term = get_term($term_id, $tax);
                if ($term instanceof WP_Term) {
                    $selection[$tax] = $term_id;
                }
            }
        }
        return $selection;
    }

    private function get_excluded_category_ids() {
        return array_values(array_filter(array_map('absint', (array) get_option(self::OPTION_EXCLUDED_CATS, array()))));
    }

    private function get_finder_page_url() {
        $page_id = absint(get_option(self::OPTION_PAGE, 0));
        return $page_id > 0 ? get_permalink($page_id) : '';
    }

    /* ---------------------------------------------------------------------
     * Query e contatori faccette
     * ------------------------------------------------------------------ */

    private function build_query_args($selection, $extra = array()) {
        $args = array_merge(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ), $extra);
        $tax_query = array();
        foreach ($selection as $tax => $term_id) {
            $tax_query[] = array(
                'taxonomy' => $tax,
                'field' => 'term_id',
                'terms' => array($term_id),
                // I termini gerarchici (es. Dove: Italia → Puglia) includono i figli.
                'include_children' => true,
            );
        }
        if (!empty($tax_query)) {
            $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }
        $excluded = $this->get_excluded_category_ids();
        if (!empty($excluded)) {
            $args['category__not_in'] = $excluded;
        }
        return $args;
    }

    /**
     * ID dei post che soddisfano la selezione (tutte le faccette in AND),
     * in transient: alimenta i contatori, non i risultati paginati.
     */
    private function get_matching_ids($selection) {
        $key = 'alma_tf_ids_' . md5(wp_json_encode(array($selection, $this->get_excluded_category_ids(), get_option(self::OPTION_CACHE_VER, 1))));
        $ids = get_transient($key);
        if (is_array($ids)) {
            return $ids;
        }
        $query = new WP_Query($this->build_query_args($selection, array(
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )));
        $ids = array_map('absint', (array) $query->posts);
        set_transient($key, $ids, self::CACHE_TTL);
        return $ids;
    }

    /**
     * Contatori per ogni tassonomia: quanti articoli restano scegliendo quel
     * termine, data la selezione corrente sulle ALTRE faccette (conteggio a
     * faccette standard: la propria selezione non limita le proprie opzioni).
     */
    public function get_facet_counts($taxonomies, $selection) {
        $cache_key = 'alma_tf_counts_' . md5(wp_json_encode(array(array_keys($taxonomies), $selection, $this->get_excluded_category_ids(), get_option(self::OPTION_CACHE_VER, 1))));
        $counts = get_transient($cache_key);
        if (is_array($counts)) {
            return $counts;
        }
        $counts = array();
        foreach (array_keys($taxonomies) as $tax) {
            $others = $selection;
            unset($others[$tax]);
            $post_ids = empty($others) && empty($this->get_excluded_category_ids())
                ? null // nessun filtro: il SQL lavora direttamente su tutti i post pubblicati
                : $this->get_matching_ids($others);
            $pairs = $this->get_term_post_pairs($tax, $post_ids);
            $parents = $this->get_term_parents($tax);
            $counts[$tax] = self::aggregate_hierarchical_counts($pairs, $parents);
        }
        set_transient($cache_key, $counts, self::CACHE_TTL);
        return $counts;
    }

    /**
     * Coppie (term_id, post_id) della tassonomia sui post pubblicati,
     * eventualmente ristrette a un elenco di ID (a blocchi, per non superare
     * i limiti delle clausole IN su dataset di migliaia di articoli).
     */
    private function get_term_post_pairs($taxonomy, $post_ids = null) {
        global $wpdb;
        if (is_array($post_ids) && empty($post_ids)) {
            return array();
        }
        $pairs = array();
        $chunks = is_array($post_ids) ? array_chunk(array_map('absint', $post_ids), 2000) : array(null);
        foreach ($chunks as $chunk) {
            $in_sql = '';
            $params = array($taxonomy);
            if (is_array($chunk)) {
                $in_sql = ' AND tr.object_id IN (' . implode(',', array_fill(0, count($chunk), '%d')) . ')';
                $params = array_merge($params, $chunk);
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT tt.term_id, tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'post' AND p.post_status = 'publish'
                 WHERE 1=1{$in_sql}",
                $params
            ), ARRAY_N);
            foreach ((array) $rows as $row) {
                $pairs[] = array((int) $row[0], (int) $row[1]);
            }
        }
        return $pairs;
    }

    private function get_term_parents($taxonomy) {
        $parents = array();
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'id=>parent'));
        if (is_array($terms)) {
            foreach ($terms as $term_id => $parent) {
                $parents[(int) $term_id] = (int) $parent;
            }
        }
        return $parents;
    }

    /**
     * Conteggio DISTINTO dei post per termine includendo i discendenti: un
     * articolo taggato "Puglia" conta anche per "Italia". Logica pura (senza
     * WordPress) per essere testabile standalone; protetta dai cicli.
     */
    public static function aggregate_hierarchical_counts(array $pairs, array $parents) {
        $sets = array();
        foreach ($pairs as $pair) {
            $term_id = (int) $pair[0];
            $object_id = (int) $pair[1];
            $visited = array();
            while ($term_id > 0 && !isset($visited[$term_id])) {
                $visited[$term_id] = true;
                if (!isset($sets[$term_id])) {
                    $sets[$term_id] = array();
                }
                $sets[$term_id][$object_id] = true;
                $term_id = isset($parents[$term_id]) ? (int) $parents[$term_id] : 0;
            }
        }
        $counts = array();
        foreach ($sets as $term_id => $objects) {
            $counts[$term_id] = count($objects);
        }
        return $counts;
    }

    /* ---------------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------------ */

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'per_page' => get_option(self::OPTION_PER_PAGE, 12),
            'taxonomies' => '',
        ), $atts, 'alma_trip_finder');

        $taxonomies = $this->get_facet_taxonomies($atts['taxonomies']);
        if (empty($taxonomies)) {
            return current_user_can('manage_options')
                ? '<p>' . esc_html__('Trova il tuo viaggio: nessuna tassonomia configurata. Selezionale in Affiliate Link AI → Trova Viaggio.', 'affiliate-link-manager-ai') . '</p>'
                : '';
        }

        $per_page = max(1, min(self::MAX_PER_PAGE, absint($atts['per_page'])));
        $selection = $this->read_selection($_GET['alma_f'] ?? array(), $taxonomies);
        $paged = max(1, absint($_GET['alma_tf_page'] ?? 1));
        $base_url = get_permalink() ?: '';

        $this->enqueue_assets();

        static $instance = 0;
        $instance++;
        $finder_id = 'alma-trip-finder-' . $instance;

        ob_start();
        ?>
        <div id="<?php echo esc_attr($finder_id); ?>"
             class="alma-trip-finder"
             data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
             data-base-url="<?php echo esc_url($base_url); ?>"
             data-per-page="<?php echo esc_attr((string) $per_page); ?>"
             data-taxonomies="<?php echo esc_attr(implode(',', array_keys($taxonomies))); ?>">
            <form class="alma-trip-finder__form" method="get" action="<?php echo esc_url($base_url); ?>">
                <div class="alma-trip-finder__facets">
                    <?php echo $this->render_facets_html($taxonomies, $selection, $base_url); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <button type="submit" class="alma-trip-finder__submit"><?php esc_html_e('Filtra', 'affiliate-link-manager-ai'); ?></button>
            </form>
            <div class="alma-trip-finder__results">
                <?php echo $this->render_results_html($selection, $paged, $per_page, $base_url); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Select delle faccette + chip dei filtri attivi. Con $base_url vuoto
     * (rendering AJAX) i link di rimozione usano solo i data-attribute: il JS
     * è comunque attivo, il fallback senza JS serve solo al primo render.
     */
    public function render_facets_html($taxonomies, $selection, $base_url = '') {
        $counts = $this->get_facet_counts($taxonomies, $selection);
        $accent = class_exists('ALMA_Geo_Map') ? ALMA_Geo_Map::get_accent_color() : '#2271b1';

        ob_start();
        echo '<div class="alma-trip-finder__selects" style="display:flex;flex-wrap:wrap;gap:10px;">';
        foreach ($taxonomies as $slug => $taxonomy) {
            $tax_counts = isset($counts[$slug]) ? $counts[$slug] : array();
            $selected = isset($selection[$slug]) ? $selection[$slug] : 0;
            echo '<label class="alma-trip-finder__facet" style="flex:1 1 180px;min-width:150px;">';
            echo '<span class="alma-trip-finder__facet-label" style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;">' . esc_html($taxonomy->labels->singular_name ?: $taxonomy->label) . '</span>';
            echo '<select name="alma_f[' . esc_attr($slug) . ']" class="alma-trip-finder__select" style="width:100%;padding:9px 10px;border:2px solid #d0d5dd;border-radius:6px;font-size:14px;background:#fff;">';
            echo '<option value="">' . esc_html__('Tutti', 'affiliate-link-manager-ai') . '</option>';
            echo $this->render_term_options($slug, $tax_counts, $selected); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</select>';
            echo '</label>';
        }
        echo '</div>';

        if (!empty($selection)) {
            echo '<div class="alma-trip-finder__active" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
            foreach ($selection as $slug => $term_id) {
                $term = get_term($term_id, $slug);
                if (!$term instanceof WP_Term) {
                    continue;
                }
                $remove_url = $base_url !== '' ? remove_query_arg('alma_tf_page', add_query_arg($this->selection_query_args(array_diff_key($selection, array($slug => 0))), $base_url)) : '#';
                echo '<a class="alma-trip-finder__chip" data-tf-remove="' . esc_attr($slug) . '" href="' . esc_url($remove_url) . '" style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid ' . esc_attr($accent) . ';color:' . esc_attr($accent) . ';border-radius:16px;font-size:13px;font-weight:600;text-decoration:none;">'
                    . esc_html(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')) . ' <span aria-hidden="true">✕</span></a>';
            }
            echo '</div>';
        }
        return (string) ob_get_clean();
    }

    /**
     * Opzioni della select in ordine gerarchico (indentate con "—"); le
     * combinazioni impossibili (0 risultati) restano visibili ma disabilitate.
     */
    private function render_term_options($taxonomy, $tax_counts, $selected) {
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true));
        if (!is_array($terms) || empty($terms)) {
            return '';
        }
        $children = array();
        foreach ($terms as $term) {
            $children[(int) $term->parent][] = $term;
        }
        return $this->walk_term_options($children, 0, 0, $tax_counts, $selected);
    }

    private function walk_term_options($children, $parent, $depth, $tax_counts, $selected) {
        if (!isset($children[$parent]) || $depth > 10) {
            return '';
        }
        $html = '';
        foreach ($children[$parent] as $term) {
            $count = isset($tax_counts[$term->term_id]) ? (int) $tax_counts[$term->term_id] : 0;
            $is_selected = (int) $term->term_id === (int) $selected;
            $label = str_repeat('— ', $depth) . html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') . ' (' . $count . ')';
            $html .= '<option value="' . esc_attr((string) $term->term_id) . '"'
                . selected($is_selected, true, false)
                . disabled($count === 0 && !$is_selected, true, false)
                . '>' . esc_html($label) . '</option>';
            $html .= $this->walk_term_options($children, (int) $term->term_id, $depth + 1, $tax_counts, $selected);
        }
        return $html;
    }

    private function selection_query_args($selection) {
        $args = array();
        foreach ($selection as $tax => $term_id) {
            $args['alma_f[' . $tax . ']'] = absint($term_id);
        }
        return $args;
    }

    /**
     * Griglia risultati + conteggio + paginazione (stesse card della pagina
     * elenco della Mappa Geografica: stile coerente, personalizzabile dal tema).
     */
    public function render_results_html($selection, $paged, $per_page, $base_url = '') {
        $accent = class_exists('ALMA_Geo_Map') ? ALMA_Geo_Map::get_accent_color() : '#2271b1';
        $query = new WP_Query($this->build_query_args($selection, array(
            'posts_per_page' => $per_page,
            'paged' => $paged,
        )));

        ob_start();
        echo '<p class="alma-trip-finder__count" style="margin:16px 0 12px;font-weight:600;color:#555;">'
            . esc_html(sprintf(_n('%d articolo trovato', '%d articoli trovati', (int) $query->found_posts, 'affiliate-link-manager-ai'), (int) $query->found_posts))
            . '</p>';

        if (empty($query->posts)) {
            echo '<p class="alma-trip-finder__empty">' . esc_html__('Nessun articolo corrisponde ai filtri scelti: prova a rimuoverne uno.', 'affiliate-link-manager-ai') . '</p>';
        } else {
            echo '<div class="alma-trip-finder__grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;">';
            foreach ($query->posts as $post) {
                $title = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
                $url = get_permalink($post);
                $thumbnail = get_the_post_thumbnail_url($post, 'medium') ?: '';
                $excerpt = html_entity_decode(wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 22, '…'), ENT_QUOTES, 'UTF-8');
                echo '<article class="alma-trip-finder__item" style="border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;background:#fff;">';
                if ($thumbnail !== '') {
                    echo '<a href="' . esc_url($url) . '"><img src="' . esc_url($thumbnail) . '" alt="' . esc_attr($title) . '" style="width:100%;height:160px;object-fit:cover;display:block;" loading="lazy" /></a>';
                }
                echo '<div style="padding:14px 16px;">';
                echo '<h3 style="margin:0 0 6px;font-size:16px;"><a href="' . esc_url($url) . '">' . esc_html($title) . '</a></h3>';
                echo '<p style="margin:0 0 6px;font-size:13px;color:#666;">' . esc_html(get_the_date('', $post)) . '</p>';
                echo '<p style="margin:0;font-size:14px;color:#444;">' . esc_html($excerpt) . '</p>';
                echo '</div></article>';
            }
            echo '</div>';

            $total_pages = (int) ceil($query->found_posts / $per_page);
            if ($total_pages > 1) {
                echo '<nav class="alma-trip-finder__pagination" style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;">';
                $window_start = max(1, $paged - 4);
                $window_end = min($total_pages, $paged + 4);
                for ($i = $window_start; $i <= $window_end; $i++) {
                    if ($i === $paged) {
                        echo '<span style="padding:6px 12px;background:' . esc_attr($accent) . ';color:#fff;border-radius:4px;">' . esc_html((string) $i) . '</span>';
                    } else {
                        $url = $base_url !== '' ? add_query_arg(array_merge($this->selection_query_args($selection), array('alma_tf_page' => $i)), $base_url) : '#';
                        echo '<a data-tf-page="' . esc_attr((string) $i) . '" href="' . esc_url($url) . '" style="padding:6px 12px;border:1px solid #d0d5dd;border-radius:4px;text-decoration:none;">' . esc_html((string) $i) . '</a>';
                    }
                }
                echo '</nav>';
            }
        }
        return (string) ob_get_clean();
    }

    private function enqueue_assets() {
        if (file_exists(ALMA_PLUGIN_DIR . 'assets/trip-finder.js')) {
            wp_enqueue_script('alma-trip-finder', ALMA_PLUGIN_URL . 'assets/trip-finder.js', array(), ALMA_VERSION, true);
        }
        // Micro-CSS di comportamento (il resto è inline con classi stabili):
        // quando il JS è attivo il pulsante "Filtra" sparisce e i refresh AJAX
        // attenuano i risultati durante il caricamento.
        wp_register_style('alma-trip-finder', false, array(), ALMA_VERSION);
        wp_enqueue_style('alma-trip-finder');
        wp_add_inline_style('alma-trip-finder', '
            .alma-trip-finder--js .alma-trip-finder__submit { display: none; }
            .alma-trip-finder.is-loading .alma-trip-finder__results { opacity: .5; pointer-events: none; }
            .alma-trip-finder__submit { margin-top: 10px; padding: 9px 22px; border-radius: 6px; border: 1px solid #d0d5dd; cursor: pointer; }
            .alma-trip-finder__chip:hover span { color: #d63638; }
        ');
    }

    /* ---------------------------------------------------------------------
     * Endpoint AJAX (pubblico, sola lettura su contenuti pubblicati)
     * ------------------------------------------------------------------ */

    public function ajax_render() {
        $taxonomies = $this->get_facet_taxonomies(sanitize_text_field(wp_unslash($_GET['taxonomies'] ?? '')));
        if (empty($taxonomies)) {
            wp_send_json_error(array('message' => __('Nessuna tassonomia configurata.', 'affiliate-link-manager-ai')), 400);
        }
        $selection = $this->read_selection($_GET['alma_f'] ?? array(), $taxonomies);
        $paged = max(1, absint($_GET['alma_tf_page'] ?? 1));
        $per_page = max(1, min(self::MAX_PER_PAGE, absint($_GET['per_page'] ?? get_option(self::OPTION_PER_PAGE, 12))));

        wp_send_json_success(array(
            'facets_html' => $this->render_facets_html($taxonomies, $selection),
            'results_html' => $this->render_results_html($selection, $paged, $per_page),
        ));
    }

    /* ---------------------------------------------------------------------
     * Chip faccette negli articoli (opt-in)
     * ------------------------------------------------------------------ */

    public function append_facet_chips($content) {
        if (get_option(self::OPTION_CHIPS, '0') !== '1') {
            return $content;
        }
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $taxonomies = $this->get_facet_taxonomies();
        if (empty($taxonomies)) {
            return $content;
        }
        $finder_url = $this->get_finder_page_url();
        $accent = class_exists('ALMA_Geo_Map') ? ALMA_Geo_Map::get_accent_color() : '#2271b1';
        $post_id = get_the_ID();

        $chips = array();
        foreach ($taxonomies as $slug => $taxonomy) {
            $terms = get_the_terms($post_id, $slug);
            if (!is_array($terms)) {
                continue;
            }
            foreach (array_slice($terms, 0, self::MAX_CHIPS_PER_TAXONOMY) as $term) {
                // La chip porta alla pagina Trova Viaggio pre-filtrata; senza
                // pagina configurata, fallback all'archivio del termine (SEO).
                $url = $finder_url !== ''
                    ? add_query_arg(array('alma_f[' . $slug . ']' => (int) $term->term_id), $finder_url)
                    : get_term_link($term);
                if (is_wp_error($url)) {
                    continue;
                }
                $chips[] = '<a class="alma-facet-chip" href="' . esc_url($url) . '" style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border:1px solid ' . esc_attr($accent) . ';color:' . esc_attr($accent) . ';border-radius:16px;font-size:13px;font-weight:600;text-decoration:none;">'
                    . esc_html($taxonomy->labels->singular_name ?: $taxonomy->label) . ': '
                    . esc_html(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8')) . '</a>';
            }
        }
        if (empty($chips)) {
            return $content;
        }
        $bar = '<div class="alma-facet-chips" style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px;">' . implode('', $chips) . '</div>';
        return $bar . $content;
    }

    /* ---------------------------------------------------------------------
     * Pagina impostazioni
     * ------------------------------------------------------------------ */

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('Trova Viaggio', 'affiliate-link-manager-ai'),
            __('Trova Viaggio', 'affiliate-link-manager-ai'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'affiliate-link-manager-ai'));
        }

        if (!empty($_POST['alma_trip_finder_save']) && check_admin_referer('alma_trip_finder_settings')) {
            $available = $this->get_all_post_taxonomies();
            $chosen = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['alma_trip_finder_taxonomies'] ?? array())), array_keys($available)));
            update_option(self::OPTION_TAXONOMIES, $chosen, false);
            update_option(self::OPTION_PAGE, absint($_POST[self::OPTION_PAGE] ?? 0), false);
            update_option(self::OPTION_EXCLUDED_CATS, array_values(array_filter(array_map('absint', (array) ($_POST['alma_trip_finder_excluded'] ?? array())))), false);
            update_option(self::OPTION_PER_PAGE, max(1, min(self::MAX_PER_PAGE, absint($_POST[self::OPTION_PER_PAGE] ?? 12))), false);
            update_option(self::OPTION_CHIPS, empty($_POST[self::OPTION_CHIPS]) ? '0' : '1', false);
            $this->bump_cache_version();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni Trova Viaggio salvate.', 'affiliate-link-manager-ai') . '</p></div>';
        }

        $available = $this->get_all_post_taxonomies();
        $chosen = array_filter(array_map('sanitize_key', (array) get_option(self::OPTION_TAXONOMIES, array('category'))));
        $excluded = $this->get_excluded_category_ids();
        $categories = get_categories(array('hide_empty' => false));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Trova il tuo viaggio', 'affiliate-link-manager-ai'); ?></h1>
            <p><?php esc_html_e('Ricerca a faccette combinate: il visitatore incrocia le tassonomie degli articoli (es. Dove + Quando + Durata + Perché) e vede solo gli articoli che le soddisfano tutte, con contatori aggiornati e combinazioni impossibili disabilitate.', 'affiliate-link-manager-ai'); ?></p>

            <div class="card" style="max-width:860px;">
                <h2><?php esc_html_e('Shortcode', 'affiliate-link-manager-ai'); ?></h2>
                <p><code>[alma_trip_finder]</code></p>
                <p class="description"><?php esc_html_e('Attributi opzionali: per_page="12" (max 48); taxonomies="slug1,slug2" per usare tassonomie diverse da quelle configurate qui. Con BeTheme: inserisci lo shortcode in un elemento del Muffin Builder; funziona anche senza JavaScript (il form si invia normalmente).', 'affiliate-link-manager-ai'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('alma_trip_finder_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Tassonomie faccetta', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <div style="max-height:220px;overflow:auto;border:1px solid #d0d5dd;border-radius:4px;padding:10px 12px;max-width:420px;">
                                <?php foreach ($available as $slug => $taxonomy) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="alma_trip_finder_taxonomies[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $chosen, true)); ?> />
                                        <?php echo esc_html($taxonomy->label); ?> <code><?php echo esc_html($slug); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php esc_html_e('Le dimensioni di ricerca mostrate come filtri combinabili (su sothra.it: Dove, Come, Cosa, Perché, Quando, Durata). Gli archivi delle tassonomie restano invariati: questo è solo un layer di navigazione in più.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_PAGE); ?>"><?php esc_html_e('Pagina Trova Viaggio', 'affiliate-link-manager-ai'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(array(
                                'name' => self::OPTION_PAGE,
                                'id' => self::OPTION_PAGE,
                                'selected' => absint(get_option(self::OPTION_PAGE, 0)),
                                'show_option_none' => __('— Nessuna —', 'affiliate-link-manager-ai'),
                                'option_none_value' => '0',
                            )); ?>
                            <p class="description"><?php esc_html_e('La pagina che contiene lo shortcode [alma_trip_finder]: è la destinazione delle chip faccetta negli articoli. Senza pagina, le chip puntano agli archivi delle tassonomie.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chip faccette negli articoli', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_CHIPS); ?>" value="1" <?php checked(get_option(self::OPTION_CHIPS, '0'), '1'); ?> />
                                <?php esc_html_e('Mostra in cima a ogni articolo le sue faccette come chip cliccabili (es. Dove: Algeria · Quando: Primavera)', 'affiliate-link-manager-ai'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Disattivo di default: nessun cambiamento agli articoli finché non lo abiliti.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Categorie da escludere', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <div style="max-height:220px;overflow:auto;border:1px solid #d0d5dd;border-radius:4px;padding:10px 12px;max-width:420px;">
                                <?php foreach ($categories as $category) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="alma_trip_finder_excluded[]" value="<?php echo esc_attr((string) $category->term_id); ?>" <?php checked(in_array((int) $category->term_id, $excluded, true)); ?> />
                                        <?php echo esc_html($category->name); ?> <span style="color:#888;">(<?php echo esc_html((string) $category->count); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description"><?php esc_html_e('Gli articoli in queste categorie non compaiono nei risultati né nei contatori.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_PER_PAGE); ?>"><?php esc_html_e('Articoli per pagina', 'affiliate-link-manager-ai'); ?></label></th>
                        <td>
                            <input type="number" min="1" max="<?php echo esc_attr((string) self::MAX_PER_PAGE); ?>" name="<?php echo esc_attr(self::OPTION_PER_PAGE); ?>" id="<?php echo esc_attr(self::OPTION_PER_PAGE); ?>" value="<?php echo esc_attr((string) absint(get_option(self::OPTION_PER_PAGE, 12))); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Default dello shortcode (sovrascrivibile con per_page). Il colore accento dei componenti si imposta in Mappa Geografica → Colore accento.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Salva impostazioni', 'affiliate-link-manager-ai'), 'primary', 'alma_trip_finder_save'); ?>
            </form>
        </div>
        <?php
    }

    private function get_all_post_taxonomies() {
        $taxonomies = array();
        foreach (get_object_taxonomies('post', 'objects') as $slug => $taxonomy) {
            if ($taxonomy->public && $slug !== 'post_format') {
                $taxonomies[$slug] = $taxonomy;
            }
        }
        return $taxonomies;
    }
}
