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
        $links = isset($instance['links']) ? array_map('intval', (array) $instance['links']) : array();
        $show_image = !empty($instance['show_image']);
        $show_title = !empty($instance['show_title']);
        $show_content = !empty($instance['show_content']);
        $show_button = !empty($instance['show_button']);
        $button_text = isset($instance['button_text']) ? sanitize_text_field($instance['button_text']) : '';
        $orientation = isset($instance['orientation']) && $instance['orientation'] === 'horizontal' ? 'horizontal' : 'vertical';
        $desktop_columns = isset($instance['template_desktop_columns']) ? intval($instance['template_desktop_columns']) : 0;
        $mobile_columns = isset($instance['template_mobile_columns']) ? intval($instance['template_mobile_columns']) : 0;

        if ($desktop_columns < 1) {
            if (!empty($instance['format']) && $instance['format'] === 'small') {
                $desktop_columns = 2;
            } else {
                $desktop_columns = $orientation === 'horizontal' ? 2 : 1;
            }
        }
        $desktop_columns = max(1, min(6, $desktop_columns));

        if ($mobile_columns < 1) {
            $mobile_columns = 1;
        }
        $mobile_columns = max(1, min(4, $mobile_columns));

        if (empty($links)) {
            return '';
        }

        $query_args = array(
            'post_type'      => 'affiliate_link',
            'post__in'       => $links,
            'orderby'        => 'post__in',
            'posts_per_page' => count($links),
            'post_status'    => 'publish',
        );

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
        $img_size = $desktop_columns > 2 ? 'thumbnail' : 'full';

        $container_classes = 'alma-affiliate-widget orientation-' . $orientation . ' template-desktop-' . $desktop_columns . ' template-mobile-' . $mobile_columns;
        $container_style = '--alma-desktop-columns:' . $desktop_columns . ';--alma-mobile-columns:' . $mobile_columns . ';display:grid;gap:20px;';

        static $styles_printed = false;
        $output = '';
        if (!$styles_printed) {
            $styles_printed = true;
            $inline_css = '.alma-affiliate-widget{display:grid;gap:20px;grid-template-columns:repeat(var(--alma-desktop-columns,1),minmax(0,1fr));}'
                . '.alma-affiliate-item{min-width:0;}'
                . '@media (max-width:782px){.alma-affiliate-widget{grid-template-columns:repeat(var(--alma-mobile-columns,1),minmax(0,1fr));}}';
            $output .= '<style id="alma-affiliate-widget-template-styles">' . esc_html($inline_css) . '</style>';
        }

        $output .= '<div class="' . esc_attr($container_classes) . '" style="' . esc_attr($container_style) . '">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();

            $fields_attr = !empty($fields) ? ' fields="' . implode(',', $fields) . '"' : '';
            $button_attr = $show_button ? ' button="yes"' : ' button="no"';
            $text_attr = ($show_button && $button_text !== '') ? ' button_text="' . esc_attr($button_text) . '"' : '';
            $shortcode = '[affiliate_link id="' . $id . '" img="' . $img . '" img_size="' . $img_size . '"' . $fields_attr . $button_attr . $text_attr . ' source="widget"]';
            $link_html = do_shortcode($shortcode);

            $output .= '<div class="alma-affiliate-item">' . $link_html . '</div>';
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
        if (!empty($instance['custom_content'])) {
            echo '<div class="alma-widget-content">' . wp_kses_post($instance['custom_content']) . '</div>';
        }
        echo self::render_links($instance);
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? '';
        $custom_content = $instance['custom_content'] ?? '';
        $show_image = !empty($instance['show_image']);
        $show_title = !empty($instance['show_title']);
        $show_content = !empty($instance['show_content']);
        $show_button = !empty($instance['show_button']);
        $button_text = $instance['button_text'] ?? '';
        $orientation = $instance['orientation'] ?? 'vertical';
        $desktop_columns = isset($instance['template_desktop_columns']) ? intval($instance['template_desktop_columns']) : 0;
        $mobile_columns = isset($instance['template_mobile_columns']) ? intval($instance['template_mobile_columns']) : 0;
        if ($desktop_columns < 1) {
            $desktop_columns = $orientation === 'horizontal' ? 2 : 1;
        }
        $desktop_columns = max(1, min(6, $desktop_columns));
        if ($mobile_columns < 1) {
            $mobile_columns = 1;
        }
        $mobile_columns = max(1, min(4, $mobile_columns));
        $links = isset($instance['links']) ? implode(',', array_map('intval', (array) $instance['links'])) : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Titolo:', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('custom_content')); ?>"><?php _e('Contenuto HTML:', 'affiliate-link-manager-ai'); ?></label>
            <textarea class="widefat" rows="4" id="<?php echo esc_attr($this->get_field_id('custom_content')); ?>" name="<?php echo esc_attr($this->get_field_name('custom_content')); ?>"><?php echo esc_textarea($custom_content); ?></textarea>
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
            <input class="checkbox" type="checkbox" <?php checked($show_button); ?> id="<?php echo esc_attr($this->get_field_id('show_button')); ?>" name="<?php echo esc_attr($this->get_field_name('show_button')); ?>" />
            <label for="<?php echo esc_attr($this->get_field_id('show_button')); ?>"><?php _e('Pulsante', 'affiliate-link-manager-ai'); ?></label>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('button_text')); ?>"><?php _e('Testo pulsante:', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('button_text')); ?>" name="<?php echo esc_attr($this->get_field_name('button_text')); ?>" type="text" value="<?php echo esc_attr($button_text); ?>">
        </p>
        <p>
            <strong><?php _e('Template Widget', 'affiliate-link-manager-ai'); ?></strong>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('template_desktop_columns')); ?>"><?php _e('Link per riga (Desktop)', 'affiliate-link-manager-ai'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('template_desktop_columns')); ?>" name="<?php echo esc_attr($this->get_field_name('template_desktop_columns')); ?>">
                <?php for ($i = 1; $i <= 6; $i++) : ?>
                    <option value="<?php echo esc_attr($i); ?>" <?php selected($desktop_columns, $i); ?>><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('template_mobile_columns')); ?>"><?php _e('Link per riga (Mobile)', 'affiliate-link-manager-ai'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('template_mobile_columns')); ?>" name="<?php echo esc_attr($this->get_field_name('template_mobile_columns')); ?>">
                <?php for ($i = 1; $i <= 4; $i++) : ?>
                    <option value="<?php echo esc_attr($i); ?>" <?php selected($mobile_columns, $i); ?>><?php echo esc_html($i); ?></option>
                <?php endfor; ?>
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
            <label for="<?php echo esc_attr($this->get_field_id('links')); ?>"><?php _e('ID Link (separati da virgola, max 20):', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('links')); ?>" name="<?php echo esc_attr($this->get_field_name('links')); ?>" type="text" value="<?php echo esc_attr($links); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['custom_content'] = wp_kses_post($new_instance['custom_content'] ?? '');
        $instance['show_image'] = !empty($new_instance['show_image']) ? 1 : 0;
        $instance['show_title'] = !empty($new_instance['show_title']) ? 1 : 0;
        $instance['show_content'] = !empty($new_instance['show_content']) ? 1 : 0;
        $instance['show_button'] = !empty($new_instance['show_button']) ? 1 : 0;
        $instance['button_text'] = sanitize_text_field($new_instance['button_text'] ?? '');
        $instance['orientation'] = $new_instance['orientation'] === 'horizontal' ? 'horizontal' : 'vertical';
        $desktop_columns = isset($new_instance['template_desktop_columns']) ? intval($new_instance['template_desktop_columns']) : 1;
        $desktop_columns = max(1, min(6, $desktop_columns));
        $mobile_columns = isset($new_instance['template_mobile_columns']) ? intval($new_instance['template_mobile_columns']) : 1;
        $mobile_columns = max(1, min(4, $mobile_columns));
        $instance['template_desktop_columns'] = $desktop_columns;
        $instance['template_mobile_columns'] = $mobile_columns;
        $links = array_filter(array_map('intval', explode(',', $new_instance['links'] ?? '')));
        $instance['links'] = array_slice(array_unique($links), 0, 20);
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
        $instance = $instances[$id];
        $title = $instance['title'] ?? '';
        $output = '';
        if ($title) {
            $output .= '<h2 class="alma-widget-title">' . esc_html($title) . '</h2>';
        }
        if (!empty($instance['custom_content'])) {
            $output .= '<div class="alma-widget-content">' . wp_kses_post($instance['custom_content']) . '</div>';
        }
        $output .= self::render_links($instance);
        return $output;
    }
}

