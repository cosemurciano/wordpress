<?php

if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Links_Source_Filter {
    public function init() {
        add_action('restrict_manage_posts', array($this, 'render_source_filter_dropdown'));
        add_action('pre_get_posts', array($this, 'apply_source_filter_to_query'));
    }

    public function render_source_filter_dropdown() {
        global $typenow;

        if ($typenow !== 'affiliate_link') {
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        $selected = isset($_GET['alma_source_filter']) ? sanitize_text_field(wp_unslash($_GET['alma_source_filter'])) : '';
        $sources  = $this->get_sources();

        echo '<label for="alma_source_filter" class="screen-reader-text">' . esc_html__('Filtra per Source', 'affiliate-link-manager-ai') . '</label>';
        echo '<select name="alma_source_filter" id="alma_source_filter">';
        echo '<option value="">' . esc_html__('Tutte le Sources', 'affiliate-link-manager-ai') . '</option>';

        foreach ($sources as $source) {
            $source_id   = (int) ($source['id'] ?? 0);
            $source_name = sanitize_text_field($source['name'] ?? '');

            if ($source_id <= 0 || $source_name === '') {
                continue;
            }

            echo '<option value="' . esc_attr((string) $source_id) . '" ' . selected($selected, (string) $source_id, false) . '>' . esc_html($source_name) . '</option>';
        }

        echo '<option value="0" ' . selected($selected, '0', false) . '>' . esc_html__('Senza Source', 'affiliate-link-manager-ai') . '</option>';
        echo '</select>';
    }

    public function apply_source_filter_to_query($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'affiliate_link') {
            return;
        }

        $raw_filter = isset($_GET['alma_source_filter']) ? wp_unslash($_GET['alma_source_filter']) : '';
        if ($raw_filter === '') {
            return;
        }

        $source_filter = sanitize_text_field($raw_filter);

        if (!preg_match('/^\d+$/', $source_filter)) {
            return;
        }

        $meta_query = (array) $query->get('meta_query');

        if ($source_filter === '0') {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_alma_source_id',
                    'value'   => '0',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_alma_source_id',
                    'compare' => 'NOT EXISTS',
                ),
            );
        } else {
            $meta_query[] = array(
                'key'     => '_alma_source_id',
                'value'   => (string) absint($source_filter),
                'compare' => '=',
            );
        }

        $query->set('meta_query', $meta_query);
    }

    private function get_sources() {
        global $wpdb;

        return (array) $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}alma_affiliate_sources ORDER BY name ASC",
            ARRAY_A
        );
    }
}
