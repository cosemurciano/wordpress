<?php
/**
 * Editor modal and AJAX link lookup handlers.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Editor_Ajax {
    private $dashboard_stats;

    public function __construct($dashboard_stats) {
        $this->dashboard_stats = $dashboard_stats;
    }

    public function init() {
        add_action('wp_ajax_alma_search_links', array($this, 'ajax_search_links'));
        add_action('wp_ajax_alma_ai_suggest_links', array($this, 'ajax_ai_suggest_links'));
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

        wp_send_json_success($results);
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
