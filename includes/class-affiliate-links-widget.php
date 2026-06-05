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
        $has_preset = !empty($instance['layout_preset']) && class_exists('ALMA_Affiliate_Widget_Layout_Registry') && ALMA_Affiliate_Widget_Layout_Registry::is_valid_preset($instance['layout_preset']);

        if ($has_preset) {
            $preset = ALMA_Affiliate_Widget_Layout_Registry::get_preset($instance['layout_preset']);
            $show_image = true;
            $show_title = true;
            $show_content = true;
            $show_button = true;
            $desktop_columns = absint($preset['desktop']);
            $mobile_columns = absint($preset['mobile']);
            $layout_preset = ALMA_Affiliate_Widget_Layout_Registry::sanitize_preset($instance['layout_preset']);
        } else {
            $show_image = !empty($instance['show_image']);
            $show_title = !empty($instance['show_title']);
            $show_content = !empty($instance['show_content']);
            $show_button = !empty($instance['show_button']);
            $desktop_columns = isset($instance['template_desktop_columns']) ? intval($instance['template_desktop_columns']) : 0;
            $mobile_columns = isset($instance['template_mobile_columns']) ? intval($instance['template_mobile_columns']) : 0;
            $layout_preset = class_exists('ALMA_Affiliate_Widget_Layout_Registry') ? ALMA_Affiliate_Widget_Layout_Registry::infer_preset($instance) : '';
        }

        $button_text = isset($instance['button_text']) ? sanitize_text_field($instance['button_text']) : '';
        if ($button_text === '') {
            $button_text = __('Scopri di più', 'affiliate-link-manager-ai');
        }
        $rewritten_links = self::normalize_rewritten_links($instance['rewritten_links'] ?? array());

        if ($desktop_columns < 1) {
            if (!empty($instance['format']) && $instance['format'] === 'small') {
                $desktop_columns = 2;
            } elseif (!empty($instance['orientation']) && $instance['orientation'] === 'horizontal') {
                $desktop_columns = 2;
            } else {
                $desktop_columns = 1;
            }
        }
        $desktop_columns = max(1, min(6, $desktop_columns));

        if ($mobile_columns < 1) {
            $mobile_columns = $desktop_columns >= 4 ? 2 : 1;
        }
        $mobile_columns = $has_preset ? max(1, min(2, $mobile_columns)) : max(1, min(4, $mobile_columns));

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
        // I widget usano sempre l'immagine originale/full del singolo Link Affiliato.
        $img_size = 'full';

        $container_classes = 'alma-affiliate-widget alma-affiliate-widget--black-links template-desktop-' . $desktop_columns . ' template-mobile-' . $mobile_columns;
        if ($layout_preset) {
            $container_classes .= ' layout-preset-' . sanitize_html_class($layout_preset);
        }
        $container_style = '--alma-desktop-columns:' . $desktop_columns . ';--alma-mobile-columns:' . $mobile_columns . ';display:grid;gap:20px;';

        static $styles_printed = false;
        $output = '';
        if (!$styles_printed) {
            $styles_printed = true;
            $inline_css = '.alma-affiliate-widget{display:grid;gap:20px;grid-template-columns:repeat(var(--alma-desktop-columns,1),minmax(0,1fr));}'
                . '.alma-affiliate-item{min-width:0;}'
                . '.alma-affiliate-widget--black-links a,.alma-affiliate-widget--black-links a:visited,.alma-affiliate-widget--black-links .affiliate-link-title a{color:#000!important;}'
                . '.alma-affiliate-widget--black-links .affiliate-link-button,.alma-affiliate-widget--black-links a.button{color:#000!important;}'
                . '@media (max-width:782px){.alma-affiliate-widget{grid-template-columns:repeat(var(--alma-mobile-columns,1),minmax(0,1fr));}}'
                . '@media (max-width:480px){.alma-affiliate-widget{grid-template-columns:1fr!important;}}';
            $output .= '<style id="alma-affiliate-widget-template-styles">' . esc_html($inline_css) . '</style>';
        }

        $output .= '<div class="' . esc_attr($container_classes) . '" style="' . esc_attr($container_style) . '">';

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();

            if (isset($rewritten_links[(string) $id])) {
                $link_html = self::render_rewritten_link($id, $rewritten_links[(string) $id], array(
                    'show_image' => $show_image,
                    'show_title' => $show_title,
                    'show_content' => $show_content,
                    'show_button' => $show_button,
                    'button_text' => $button_text,
                    'img_size' => $img_size,
                ));
            } else {
                $fields_attr = !empty($fields) ? ' fields="' . implode(',', $fields) . '"' : '';
                $button_attr = $show_button ? ' button="yes"' : ' button="no"';
                $text_attr = ($show_button && $button_text !== '') ? ' button_text="' . esc_attr($button_text) . '"' : '';
                $shortcode = '[affiliate_link id="' . $id . '" img="' . $img . '" img_size="' . $img_size . '"' . $fields_attr . $button_attr . $text_attr . ' source="widget"]';
                $link_html = do_shortcode($shortcode);
            }

            $output .= '<div class="alma-affiliate-item">' . $link_html . '</div>';
        }
        wp_reset_postdata();

        $output .= '</div>';

        return $output;
    }

    private static function normalize_rewritten_links($rewritten_links) {
        $items = array();
        foreach ((array) $rewritten_links as $link_id => $item) {
            $key = (string) absint($link_id);
            if ($key === '0' || !is_array($item)) {
                continue;
            }
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            $description = sanitize_textarea_field((string) ($item['description'] ?? ''));
            if ($title === '' || $description === '') {
                continue;
            }
            $items[$key] = array('title' => $title, 'description' => $description);
        }
        return $items;
    }

    private static function render_rewritten_link($id, $rewrite, $args) {
        $affiliate_url = get_post_meta($id, '_affiliate_url', true);
        if (!$affiliate_url) {
            return '<span style="color:red;">' . esc_html__('[Affiliate Link: URL non configurato]', 'affiliate-link-manager-ai') . '</span>';
        }

        $link_rel = get_post_meta($id, '_link_rel', true);
        if ($link_rel === '') {
            // Link interno: nessun attributo rel.
        } elseif (!$link_rel) {
            $link_rel = 'sponsored noopener';
        }
        $link_target = get_post_meta($id, '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($id, '_link_title', true);
        if (empty($link_title)) {
            $link_title = $rewrite['title'];
        }

        $image_html = '';
        if (!empty($args['show_image'])) {
            $image_html = get_the_post_thumbnail($id, $args['img_size'] ?? 'full', array('class' => 'alma-affiliate-img', 'alt' => esc_attr($rewrite['title'])));
        }

        $title_html = '';
        if (!empty($args['show_title'])) {
            $title_html = '<h4 class="alma-link-title">' . esc_html($rewrite['title']) . '</h4>';
        }

        $base_attrs = ' href="' . esc_url($affiliate_url) . '" data-link-id="' . esc_attr($id) . '" data-track="1" data-source="widget"';
        if ($link_rel !== '') {
            $base_attrs .= ' rel="' . esc_attr($link_rel) . '"';
        }
        $base_attrs .= ' target="' . esc_attr($link_target) . '" title="' . esc_attr($link_title) . '"';

        $html = '<a' . $base_attrs . ' class="affiliate-link-btn alma-affiliate-link">' . $image_html . $title_html . '</a>';
        if (!empty($args['show_content'])) {
            $html .= '<div class="alma-link-content">' . wpautop(esc_html($rewrite['description'])) . '</div>';
        }
        if (!empty($args['show_button'])) {
            $button_text = sanitize_text_field($args['button_text'] ?? '');
            if ($button_text === '') {
                $button_text = __('Scopri di più', 'affiliate-link-manager-ai');
            }
            $html .= '<div class="alma-button-wrapper" style="text-align:left;"><a' . $base_attrs . ' class="alma-affiliate-button alma-btn-medium alma-affiliate-link">' . esc_html($button_text) . '</a></div>';
        }

        return $html;
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo '<h3 class="alma-widget-title">' . esc_html(apply_filters('widget_title', $instance['title'])) . '</h3>';
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
        $desktop_columns = isset($instance['template_desktop_columns']) ? intval($instance['template_desktop_columns']) : 0;
        $mobile_columns = isset($instance['template_mobile_columns']) ? intval($instance['template_mobile_columns']) : 0;
        if ($desktop_columns < 1) {
            if (!empty($instance['orientation']) && $instance['orientation'] === 'horizontal') {
                $desktop_columns = 2;
            } else {
                $desktop_columns = 1;
            }
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
        if (isset($old_instance['orientation'])) {
            $instance['orientation'] = $old_instance['orientation'] === 'horizontal' ? 'horizontal' : 'vertical';
        }
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
            $output .= '<h3 class="alma-widget-title">' . esc_html($title) . '</h3>';
        }
        if (!empty($instance['custom_content'])) {
            $output .= '<div class="alma-widget-content">' . wp_kses_post($instance['custom_content']) . '</div>';
        }
        $output .= self::render_links($instance);
        return $output;
    }
}

