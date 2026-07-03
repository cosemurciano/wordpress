<?php
/**
 * Editor modal and AJAX link lookup handlers.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Editor_Ajax {
    private $dashboard_stats;
    private $geo_store_instance = null;

    public function __construct($dashboard_stats) {
        $this->dashboard_stats = $dashboard_stats;
    }

    public function init() {
        add_action('wp_ajax_alma_search_links', array($this, 'ajax_search_links'));
        add_action('wp_ajax_alma_ai_suggest_links', array($this, 'ajax_ai_suggest_links'));
        add_action('wp_ajax_alma_editor_geo_locations', array($this, 'ajax_editor_geo_locations'));
        add_action('admin_footer-post.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-post-new.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-page.php', array($this, 'add_editor_integration'));
        add_action('admin_footer-page-new.php', array($this, 'add_editor_integration'));
    }

    public function add_editor_integration() {
        // Placeholder: the modal is injected by assets/editor.js.
        return;
    }

    private function ajax_require_nonce($action, $field = 'nonce') {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(array('message' => __('Verifica di sicurezza non riuscita.', 'affiliate-link-manager-ai')), 403);
        }
    }

    private function ajax_require_capability($capability, $args = array()) {
        $allowed = empty($args) ? current_user_can($capability) : current_user_can($capability, $args[0]);
        if (!$allowed) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
    }

    private function get_shortcode_usage_stats($link_id) {
        return $this->dashboard_stats->get_shortcode_usage_stats($link_id);
    }

    private function truncate($value, $length) {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    public function ajax_search_links() {
        $this->ajax_require_nonce('alma_editor_search');
        $this->ajax_require_capability('edit_posts');

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $type_filter = isset($_POST['type_filter']) ? absint($_POST['type_filter']) : 0;
        $geo_filter = isset($_POST['geo_filter']) ? absint($_POST['geo_filter']) : 0;

        $args = array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        if ($type_filter > 0) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'link_type',
                    'field' => 'term_id',
                    'terms' => $type_filter
                )
            );
        }

        if ($geo_filter > 0) {
            $geo_link_ids = $this->get_link_ids_for_location_area($geo_filter);
            if (empty($geo_link_ids)) {
                wp_send_json_success(array());
            }
            $args['post__in'] = $geo_link_ids;
        }

        $query = new WP_Query($args);
        $results = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $affiliate_url = get_post_meta($post_id, '_affiliate_url', true);
                $click_count = get_post_meta($post_id, '_click_count', true) ?: 0;
                $usage_data = $this->get_shortcode_usage_stats($post_id);

                $terms = get_the_terms($post_id, 'link_type');
                $types = array();
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $types[] = $term->name;
                    }
                }

                $results[] = array(
                    'id' => (int) $post_id,
                    'title' => get_the_title(),
                    'url' => esc_url_raw($affiliate_url),
                    'types' => array_map('sanitize_text_field', $types),
                    'clicks' => (int) $click_count,
                    'usage' => $usage_data,
                    'shortcode' => '[affiliate_link id="' . $post_id . '"]'
                );
            }
            wp_reset_postdata();
        }

        $results = $this->attach_location_labels($results);

        wp_send_json_success($results);
    }

    /**
     * Località disponibili per il filtro geografico del modale (con conteggio
     * link collegati) e località primaria del post corrente per la preselezione.
     */
    public function ajax_editor_geo_locations() {
        $this->ajax_require_nonce('alma_editor_search');
        $this->ajax_require_capability('edit_posts');

        $store = $this->geo_store();
        if (!$store || !$store->tables_exist()) {
            wp_send_json_success(array('locations' => array(), 'current_location_id' => 0));
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.canonical_name, l.type, l.city, l.country, l.country_code, COUNT(DISTINCT ci.object_id) AS link_count
             FROM {$store->table_locations()} l
             INNER JOIN {$store->table_content_index()} ci ON ci.location_id = l.id AND ci.object_type = %s
             GROUP BY l.id
             ORDER BY link_count DESC, l.canonical_name ASC
             LIMIT 150",
            ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK
        ), ARRAY_A);

        $locations = array();
        foreach ((array) $rows as $row) {
            $label = sanitize_text_field($row['canonical_name']);
            $country = sanitize_text_field($row['country'] ?: $row['country_code']);
            if ($country !== '' && strcasecmp($country, $label) !== 0) {
                $label .= ' (' . $country . ')';
            }
            $locations[] = array(
                'id' => (int) $row['id'],
                'label' => $label,
                'count' => (int) $row['link_count'],
            );
        }

        // Località primaria dell'articolo in modifica: preselezionata nel filtro.
        $current_location_id = 0;
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id > 0 && current_user_can('edit_post', $post_id)) {
            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                $object_type = $post->post_type === 'page' ? ALMA_Geo_Index_Store::OBJECT_TYPE_PAGE : ALMA_Geo_Index_Store::OBJECT_TYPE_POST;
                $primary = $store->get_primary_location_for_object($post_id, $object_type);
                $current_location_id = (int) ($primary['id'] ?? 0);
                if ($current_location_id > 0 && !in_array($current_location_id, wp_list_pluck($locations, 'id'), true)) {
                    // La località dell'articolo non ha link diretti: resta utile
                    // come filtro perché l'area viene espansa (stessa città/paese).
                    $location = $store->get_location($current_location_id);
                    if ($location) {
                        $label = sanitize_text_field($location['canonical_name']);
                        $country = sanitize_text_field($location['country'] ?: $location['country_code']);
                        if ($country !== '' && strcasecmp($country, $label) !== 0) {
                            $label .= ' (' . $country . ')';
                        }
                        array_unshift($locations, array('id' => $current_location_id, 'label' => $label, 'count' => 0));
                    }
                }
            }
        }

        wp_send_json_success(array('locations' => $locations, 'current_location_id' => $current_location_id));
    }

    /**
     * Espande la località scelta alla sua "area" e ritorna gli ID dei link
     * affiliati collegati: stessa riga, righe omonime (stessa città/canonical,
     * import diversi) e — per località di tipo paese — tutte le località di
     * quel paese.
     */
    private function get_link_ids_for_location_area($location_id) {
        $store = $this->geo_store();
        if (!$store || !$store->tables_exist()) {
            return array();
        }
        $location = $store->get_location($location_id);
        if (!$location) {
            return array();
        }

        global $wpdb;
        $conditions = array('l.id = %d');
        $params = array(absint($location_id));

        $city = trim((string) ($location['city'] ?: $location['canonical_name']));
        if ($city !== '') {
            $conditions[] = 'l.city = %s';
            $conditions[] = 'l.canonical_name = %s';
            array_push($params, $city, $city);
        }
        if (sanitize_key($location['type'] ?? '') === 'country') {
            if ((string) $location['country_code'] !== '') {
                $conditions[] = 'l.country_code = %s';
                $params[] = (string) $location['country_code'];
            }
            $country_name = trim((string) ($location['country'] ?: $location['canonical_name']));
            if ($country_name !== '') {
                $conditions[] = 'l.country = %s';
                $params[] = $country_name;
            }
        }

        $params_final = array_merge(array(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK), $params);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ci.object_id
             FROM {$store->table_content_index()} ci
             INNER JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_type = %s AND (" . implode(' OR ', $conditions) . ') LIMIT 500',
            $params_final
        ));
        return array_values(array_filter(array_map('absint', (array) $ids)));
    }

    /**
     * Etichetta della località primaria per ciascun link nei risultati (una query).
     */
    private function attach_location_labels($results) {
        if (empty($results)) {
            return $results;
        }
        $store = $this->geo_store();
        if (!$store || !$store->tables_exist()) {
            return $results;
        }
        global $wpdb;
        $ids = array_map('absint', wp_list_pluck($results, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ci.object_id, l.canonical_name, l.country, l.country_code
             FROM {$store->table_content_index()} ci
             INNER JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_type = %s AND ci.is_primary = 1 AND ci.object_id IN ($placeholders)",
            array_merge(array(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK), $ids)
        ), ARRAY_A);
        $labels = array();
        foreach ((array) $rows as $row) {
            $label = sanitize_text_field($row['canonical_name']);
            $country = sanitize_text_field($row['country'] ?: $row['country_code']);
            if ($country !== '' && strcasecmp($country, $label) !== 0) {
                $label .= ', ' . $country;
            }
            $labels[absint($row['object_id'])] = $label;
        }
        foreach ($results as &$result) {
            $result['location'] = $labels[$result['id']] ?? '';
        }
        unset($result);
        return $results;
    }

    private function geo_store() {
        if (!class_exists('ALMA_Geo_Index_Store')) {
            return null;
        }
        if ($this->geo_store_instance === null) {
            $this->geo_store_instance = new ALMA_Geo_Index_Store();
        }
        return $this->geo_store_instance;
    }

    public function ajax_ai_suggest_links() {
        $this->ajax_require_nonce('alma_editor_search');
        $this->ajax_require_capability('edit_posts');

        $title   = isset($_POST['title']) ? wp_strip_all_tags(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? wp_strip_all_tags(wp_unslash($_POST['content'])) : '';

        $title   = $this->truncate($title, 500);
        $content = $this->truncate($content, 2000);

        $links = get_posts(array(
            'post_type'      => 'affiliate_link',
            'post_status'    => 'publish',
            'numberposts'    => 50,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        if (empty($links)) {
            wp_send_json_success(array());
        }

        $prompt = "Articolo: {$title}\n\n{$content}\n\nLinks disponibili:\n";
        foreach ($links as $link) {
            $prompt .= 'ID ' . $link->ID . ': ' . $link->post_title . "\n";
        }
        $prompt .= "\nRestituisci un array JSON con massimo 10 oggetti {\"id\": ID, \"score\": COERENZA}, dove COERENZA è un numero da 0 a 100 che indica quanto il link è coerente con l'articolo. Rispondi esclusivamente con JSON valido, senza testo aggiuntivo.\n";

        $response = ALMA_AI_Utils::call_openai_api($prompt, 'Rispondi esclusivamente con JSON valido, senza testo aggiuntivo');
        if (empty($response['success'])) {
            $msg = $response['error'] ?? __('Impossibile generare suggerimenti con OpenAI.', 'affiliate-link-manager-ai');
            ALMA_Logger::error('OpenAI API error', array('error' => $msg));
            wp_send_json_error($msg);
        }

        $clean = ALMA_AI_Utils::extract_first_json($response['response']);
        $items = json_decode($clean, true);
        if (!is_array($items)) {
            ALMA_Logger::warning('JSON decode failed', array('json_error' => json_last_error_msg(), 'raw_ai_response' => $response['response']));
            wp_send_json_error(__('Risposta AI non valida.', 'affiliate-link-manager-ai'));
        }

        $results = array();
        foreach (array_slice($items, 0, 10) as $item) {
            $id    = isset($item['id']) ? intval($item['id']) : 0;
            $score = isset($item['score']) ? floatval($item['score']) : 0;

            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link') {
                continue;
            }

            $affiliate_url = get_post_meta($id, '_affiliate_url', true);
            $click_count   = get_post_meta($id, '_click_count', true) ?: 0;
            $usage_data    = $this->get_shortcode_usage_stats($id);
            $terms         = get_the_terms($id, 'link_type');
            $types         = array();
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $types[] = $term->name;
                }
            }

            $results[] = array(
                'id'       => (int) $id,
                'title'    => get_the_title($id),
                'url'      => esc_url_raw($affiliate_url),
                'types'    => array_map('sanitize_text_field', $types),
                'clicks'   => (int) $click_count,
                'usage'    => $usage_data,
                'shortcode'=> '[affiliate_link id="' . $id . '"]',
                'score'    => max(0, min(100, round($score))),
            );
        }

        wp_send_json_success($results);
    }
}
