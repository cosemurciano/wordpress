<?php
/**
 * Tipologie di link "universali" — valide per qualsiasi articolo.
 *
 * Alcune tipologie (Assicurazioni viaggio, eSIM, VPN, bagagli…) non hanno
 * una geolocalizzazione precisa e il sistema geo-centrico le penalizzava:
 * la dominanza geografica del widget le scartava, la selezione geo-first
 * delle bozze non le sceglieva mai, e l'auto-indexer le contava tra i
 * "senza località" come fossero errori.
 *
 * Soluzione: un flag sul TERMINE della tassonomia link_type (una spunta,
 * tutti i link della tipologia la ereditano). Effetti:
 * - widget contestuale: esenzione dalla dominanza geografica + bonus di
 *   compatibilità neutro, con SLOT massimo di 1 link universale nei
 *   risultati (le esperienze locali restano protagoniste) e rotazione
 *   giornaliera tra gli universali quasi a pari punteggio;
 * - nuove bozze AI: il miglior link universale viene sempre aggiunto ai
 *   candidati (l'AI può inserire l'assicurazione/eSIM dove naturale);
 * - auto-indexer geo: questi link vengono saltati (stato "universal"),
 *   niente geocoding a vuoto né falsi "senza località".
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Universal_Link_Types {
    const TERM_META = 'alma_universal';

    public static function init() {
        add_action('link_type_add_form_fields', array(__CLASS__, 'render_add_field'));
        add_action('link_type_edit_form_fields', array(__CLASS__, 'render_edit_field'), 10, 1);
        add_action('created_link_type', array(__CLASS__, 'save_field'));
        add_action('edited_link_type', array(__CLASS__, 'save_field'));
        add_filter('manage_edit-link_type_columns', array(__CLASS__, 'add_column'));
        add_filter('manage_link_type_custom_column', array(__CLASS__, 'render_column'), 10, 3);
    }

    /* ---------------------------------------------------------------------
     * Interrogazioni
     * ------------------------------------------------------------------ */

    /**
     * ID dei termini link_type marcati come universali (cache per richiesta).
     */
    public static function universal_term_ids() {
        static $ids = null;
        if ($ids !== null) { return $ids; }
        $terms = get_terms(array(
            'taxonomy' => 'link_type',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => array(array('key' => self::TERM_META, 'value' => '1')),
        ));
        $ids = is_wp_error($terms) ? array() : array_map('absint', (array) $terms);
        return $ids;
    }

    /**
     * Il link appartiene a una tipologia universale?
     */
    public static function is_universal_link($link_id) {
        $universal = self::universal_term_ids();
        if (empty($universal)) { return false; }
        $terms = get_the_terms(absint($link_id), 'link_type');
        if (is_wp_error($terms) || empty($terms)) { return false; }
        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $universal, true)) { return true; }
        }
        return false;
    }

    /**
     * Migliori link universali pubblicati (per click), esclusi i morti e
     * gli ID indicati: usati come candidato extra per le nuove bozze.
     */
    public static function top_universal_links($limit = 3, $exclude_ids = array()) {
        $universal = self::universal_term_ids();
        if (empty($universal)) { return array(); }
        $ids = get_posts(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => max(1, absint($limit)) + count((array) $exclude_ids) + 5,
            'fields' => 'ids',
            'orderby' => 'meta_value_num',
            'meta_key' => '_click_count',
            'order' => 'DESC',
            'no_found_rows' => true,
            'tax_query' => array(array('taxonomy' => 'link_type', 'field' => 'term_id', 'terms' => $universal)),
        ));
        $out = array();
        $exclude_ids = array_map('absint', (array) $exclude_ids);
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if (in_array($id, $exclude_ids, true)) { continue; }
            if (trim((string) get_post_meta($id, '_affiliate_url', true)) === '') { continue; }
            if (class_exists('ALMA_Link_Health_Checker') && ALMA_Link_Health_Checker::is_dead($id)) { continue; }
            $out[] = $id;
            if (count($out) >= max(1, absint($limit))) { break; }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * UI tassonomia
     * ------------------------------------------------------------------ */

    public static function render_add_field() {
        echo '<div class="form-field">';
        echo '<label><input type="checkbox" name="' . esc_attr(self::TERM_META) . '" value="1"> ' . esc_html__('Tipologia universale', 'affiliate-link-manager-ai') . '</label>';
        echo '<p class="description">' . esc_html__('Link validi per qualsiasi articolo, senza geolocalizzazione (es. Assicurazioni, eSIM): esenti dai filtri geografici, con slot dedicato nel widget e candidati nelle nuove bozze AI.', 'affiliate-link-manager-ai') . '</p>';
        echo '</div>';
    }

    public static function render_edit_field($term) {
        $checked = get_term_meta($term->term_id, self::TERM_META, true) === '1';
        echo '<tr class="form-field"><th scope="row">' . esc_html__('Tipologia universale', 'affiliate-link-manager-ai') . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(self::TERM_META) . '" value="1"' . checked($checked, true, false) . '> ' . esc_html__('Link validi per qualsiasi articolo, senza geolocalizzazione', 'affiliate-link-manager-ai') . '</label>';
        echo '<p class="description">' . esc_html__('Es. Assicurazioni viaggio, eSIM, VPN: esenti dai filtri geografici, slot dedicato nel widget contestuale (max 1), sempre candidati nelle nuove bozze AI, esclusi dall\'indicizzazione geografica.', 'affiliate-link-manager-ai') . '</p>';
        echo '</td></tr>';
    }

    public static function save_field($term_id) {
        if (!current_user_can('manage_categories')) { return; }
        if (!empty($_POST[self::TERM_META])) {
            update_term_meta($term_id, self::TERM_META, '1');
        } else {
            delete_term_meta($term_id, self::TERM_META);
        }
        // Il widget contestuale deve rigenerare i risultati.
        if (class_exists('ALMA_Contextual_Affiliate_Widget') && method_exists('ALMA_Contextual_Affiliate_Widget', 'bump_cache_version')) {
            ALMA_Contextual_Affiliate_Widget::bump_cache_version();
        }
    }

    public static function add_column($columns) {
        $columns['alma_universal'] = __('Universale', 'affiliate-link-manager-ai');
        return $columns;
    }

    public static function render_column($content, $column, $term_id) {
        if ($column !== 'alma_universal') { return $content; }
        return get_term_meta($term_id, self::TERM_META, true) === '1' ? '🌍 ' . esc_html__('Sì', 'affiliate-link-manager-ai') : '—';
    }
}
