<?php
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Affiliate_Links_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'affiliate_links_widget',
            __('Link Affiliati', 'affiliate-link-manager-ai'),
            array('description' => __('Mostra un elenco di link affiliati', 'affiliate-link-manager-ai'))
        );
    }

    public static function render_links($instance) {
        $number = isset($instance['number']) ? intval($instance['number']) : 5;
        $types = isset($instance['types']) ? (array) $instance['types'] : array();
        $show_image = !empty($instance['show_image']);
        $show_title = !empty($instance['show_title']);
        $show_content = !empty($instance['show_content']);
        $format = isset($instance['format']) && $instance['format'] === 'small' ? 'small' : 'large';
        $orientation = isset($instance['orientation']) && $instance['orientation'] === 'horizontal' ? 'horizontal' : 'vertical';

        $query_args = array(
            'post_type' => 'affiliate_link',
            'posts_per_page' => $number,
            'post_status' => 'publish',
        );

        if (!empty($types)) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'link_type',
                    'field' => 'term_id',
                    'terms' => array_map('intval', $types),
                ),
            );
        }

        $q = new WP_Query($query_args);
        if (!$q->have_posts()) {
            return '';
        }

        $fields = array();
        if ($show_title) {
            $fields[] = 'title';
        }
        if ($show_content) {
            $fields[] = 'content';
        }

        $img = $show_image ? 'yes' : 'no';
        $img_size = $format === 'small' ? 'thumbnail' : 'full';

        $container_classes = 'alma-affiliate-widget format-' . $format . ' orientation-' . $orientation;
        $container_style = $orientation === 'horizontal' ? 'display:flex;flex-wrap:wrap;' : '';
        $item_style = $orientation === 'horizontal' ? 'width:50%;padding:10px;box-sizing:border-box;' : 'margin-bottom:10px;';

        $output = '<div class="' . esc_attr($container_classes) . '" style="' . esc_attr($container_style) . '">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();
            $link_html = do_shortcode('[affiliate_link id="' . $id . '" img="' . $img . '" img_size="' . $img_size . '" fields="' . implode(',', $fields) . '" button="no"]');
            $output .= '<div class="alma-affiliate-item" style="' . esc_attr($item_style) . '">' . $link_html . '</div>';
        }
        wp_reset_postdata();

        $output .= '</div>';

        return $output;
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        echo self::render_links($instance);
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? '';
        $show_image = !empty($instance['show_image']);
        $show_title = !empty($instance['show_title']);
        $show_content = !empty($instance['show_content']);
        $types = $instance['types'] ?? array();
        $format = $instance['format'] ?? 'large';
        $orientation = $instance['orientation'] ?? 'vertical';
        $number = $instance['number'] ?? 5;

        $all_types = get_terms(array('taxonomy' => 'link_type', 'hide_empty' => false));
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Titolo:', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_image); ?> id="<?php echo esc_attr($this->get_field_id('show_image')); ?>" name="<?php echo esc_attr($this->get_field_name('show_image')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('show_image')); ?>"><?php _e('Mostra immagine', 'affiliate-link-manager-ai'); ?></label>
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_title); ?> id="<?php echo esc_attr($this->get_field_id('show_title')); ?>" name="<?php echo esc_attr($this->get_field_name('show_title')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('show_title')); ?>"><?php _e('Mostra titolo', 'affiliate-link-manager-ai'); ?></label>
        </p>
        <p>
            <input class="checkbox" type="checkbox" <?php checked($show_content); ?> id="<?php echo esc_attr($this->get_field_id('show_content')); ?>" name="<?php echo esc_attr($this->get_field_name('show_content')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('show_content')); ?>"><?php _e('Mostra contenuto', 'affiliate-link-manager-ai'); ?></label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('types')); ?>"><?php _e('Tipologie:', 'affiliate-link-manager-ai'); ?></label>
            <select multiple class="widefat" id="<?php echo esc_attr($this->get_field_id('types')); ?>" name="<?php echo esc_attr($this->get_field_name('types')); ?>[]">
                <?php foreach ($all_types as $t) : ?>
                    <option value="<?php echo esc_attr($t->term_id); ?>" <?php echo in_array($t->term_id, (array) $types) ? 'selected' : ''; ?>><?php echo esc_html($t->name); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('format')); ?>"><?php _e('Formato:', 'affiliate-link-manager-ai'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('format')); ?>" name="<?php echo esc_attr($this->get_field_name('format')); ?>">
                <option value="large" <?php selected($format, 'large'); ?>><?php _e('Immagine grande, titolo e contenuto', 'affiliate-link-manager-ai'); ?></option>
                <option value="small" <?php selected($format, 'small'); ?>><?php _e('Immagine piccola e titolo', 'affiliate-link-manager-ai'); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('orientation')); ?>"><?php _e('Orientamento:', 'affiliate-link-manager-ai'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('orientation')); ?>" name="<?php echo esc_attr($this->get_field_name('orientation')); ?>">
                <option value="vertical" <?php selected($orientation, 'vertical'); ?>><?php _e('Verticale', 'affiliate-link-manager-ai'); ?></option>
                <option value="horizontal" <?php selected($orientation, 'horizontal'); ?>><?php _e('Orizzontale', 'affiliate-link-manager-ai'); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('number')); ?>"><?php _e('Numero di link:', 'affiliate-link-manager-ai'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('number')); ?>" name="<?php echo esc_attr($this->get_field_name('number')); ?>" type="number" step="1" min="1" value="<?php echo esc_attr($number); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['show_image'] = !empty($new_instance['show_image']) ? 1 : 0;
        $instance['show_title'] = !empty($new_instance['show_title']) ? 1 : 0;
        $instance['show_content'] = !empty($new_instance['show_content']) ? 1 : 0;
        $instance['types'] = array_map('intval', $new_instance['types'] ?? array());
        $instance['format'] = $new_instance['format'] === 'small' ? 'small' : 'large';
        $instance['orientation'] = $new_instance['orientation'] === 'horizontal' ? 'horizontal' : 'vertical';
        $instance['number'] = intval($new_instance['number'] ?? 5);
        return $instance;
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts);
        $id = intval($atts['id']);
        if (!$id) {
            return '';
        }
        $instances = get_option('widget_affiliate_links_widget', array());
        if (!isset($instances[$id])) {
            return '';
        }
        return self::render_links($instances[$id]);
    }
}

