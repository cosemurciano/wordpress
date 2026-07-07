<?php
/**
 * Shortcode registration and rendering.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Shortcodes {
    public function init() {
        $this->load_widget_dependencies();

        add_shortcode('affiliate_link', array($this, 'display_affiliate_link'));
        add_shortcode('affiliate_links_widget', array('ALMA_Affiliate_Links_Widget', 'shortcode'));
        // init() viene eseguito su `init` (priorità 10), quando `widgets_init` (priorità 1)
        // è già scattato: l'aggancio qui non verrebbe mai eseguito. La registrazione del
        // widget è agganciata nel bootstrap del plugin; questo ramo resta solo per
        // retrocompatibilità con chiamate a init() precedenti a `widgets_init`.
        if (!did_action('widgets_init')) {
            add_action('widgets_init', array($this, 'register_widget'));
        }
    }

    public function register_widget() {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $this->load_widget_dependencies();
        register_widget('ALMA_Affiliate_Links_Widget');
    }

    private function load_widget_dependencies() {
        if (file_exists(ALMA_PLUGIN_DIR . 'includes/class-affiliate-widget-layout-registry.php')) {
            require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-widget-layout-registry.php';
        }
        require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-links-widget.php';
    }

    public function display_affiliate_link($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'text' => '',
            'class' => 'affiliate-link-btn',
            'img' => 'no',
            'img_size' => 'full',
            'fields' => '',
            'button' => 'no',
            'button_text' => '',
            'button_size' => 'medium',
            'button_align' => 'left',
            'source' => 'shortcode'
        ), $atts);

        if (!$atts['id']) {
            return '<span style="color:red;">[Affiliate Link: ID mancante]</span>';
        }

        $post = get_post($atts['id']);
        if (!$post || $post->post_type !== 'affiliate_link') {
            return '<span style="color:red;">[Affiliate Link: Link non trovato]</span>';
        }

        $affiliate_url = get_post_meta($atts['id'], '_affiliate_url', true);
        if (!$affiliate_url) {
            return '<span style="color:red;">[Affiliate Link: URL non configurato]</span>';
        }

        // Link in quarantena (prodotto rimosso/404 confermato dal Link
        // Health Checker): degrada ad ancora di testo semplice — il
        // visitatore non atterra mai su una pagina inesistente.
        if (class_exists('ALMA_Link_Health_Checker') && ALMA_Link_Health_Checker::is_dead($atts['id'])) {
            $fallback_text = trim((string) $atts['text']) !== '' ? (string) $atts['text'] : get_the_title($atts['id']);
            return esc_html($fallback_text);
        }

        $link_rel = get_post_meta($atts['id'], '_link_rel', true);

        if ($link_rel === '') {
            // Link interno: nessun attributo rel
        } elseif (!$link_rel) {
            $link_rel = 'sponsored noopener';
        }

        $link_target = get_post_meta($atts['id'], '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($atts['id'], '_link_title', true);
        $source = sanitize_key($atts['source']);
        if ($source === '') {
            $source = 'shortcode';
        }

        if (empty($link_title)) {
            $link_title = get_the_title($atts['id']);
        }

        $fields = array_filter(array_map('trim', explode(',', $atts['fields'])));

        $image_html = '';
        $title_html = '';
        $content_html = '';

        if ($atts['img'] === 'yes') {
            $size = in_array($atts['img_size'], array('thumbnail','medium','large','full')) ? $atts['img_size'] : 'full';
            $image_html = get_the_post_thumbnail($atts['id'], $size, array('class' => 'alma-affiliate-img'));
            if (!$image_html) {
                $atts['img'] = 'no';
            }
        }

        if (in_array('title', $fields)) {
            $title_text = esc_html(get_the_title($atts['id']));
            if (in_array('content', $fields)) {
                $title_html = '<h4 class="alma-link-title">' . $title_text . '</h4>';
            } else {
                $title_html = '<span class="alma-link-title">' . $title_text . '</span>';
            }
        }

        if (in_array('content', $fields)) {
            $post_content = apply_filters('the_content', get_post_field('post_content', $atts['id']));
            $content_html = '<div class="alma-link-content">' . $post_content . '</div>';
        }

        if ($title_html === '') {
            if (!empty($atts['text'])) {
                $title_html = '<span class="alma-link-title">' . esc_html($atts['text']) . '</span>';
            } elseif ($atts['img'] !== 'yes') {
                $title_html = esc_html(get_the_title($atts['id']));
            }

        }

        $link_inner = $image_html . $title_html;

        $link_html = '<a href="' . esc_url($affiliate_url) . '"';
        $link_html .= ' class="' . esc_attr($atts['class']) . ' alma-affiliate-link"';
        $link_html .= ' data-link-id="' . esc_attr($atts['id']) . '"';
        $link_html .= ' data-track="1"';
        $link_html .= ' data-source="' . esc_attr($source) . '"';
        if ($link_rel !== '') {
            $link_html .= ' rel="' . esc_attr($link_rel) . '"';
        }
        $link_html .= ' target="' . esc_attr($link_target) . '"';
        $link_html .= ' title="' . esc_attr($link_title) . '"';
        $link_html .= '>' . $link_inner . '</a>';

        if ($content_html) {
            $link_html .= $content_html;
        }

        if ($atts['button'] === 'yes') {
            $size = in_array($atts['button_size'], array('small','medium','large')) ? $atts['button_size'] : 'medium';
            $alignment = in_array($atts['button_align'], array('left','center','right')) ? $atts['button_align'] : 'left';
            $btn_classes = 'alma-affiliate-button alma-btn-' . esc_attr($size) . ' alma-affiliate-link';
            $btn_text = !empty($atts['button_text']) ? esc_html($atts['button_text']) : __('Scopri di più', 'affiliate-link-manager-ai');
            $button_html = '<div class="alma-button-wrapper" style="text-align:' . esc_attr($alignment) . ';">';
            $button_html .= '<a href="' . esc_url($affiliate_url) . '"';
            $button_html .= ' class="' . $btn_classes . '"';
            $button_html .= ' data-link-id="' . esc_attr($atts['id']) . '"';
            $button_html .= ' data-track="1"';
            $button_html .= ' data-source="' . esc_attr($source) . '"';
            if ($link_rel !== '') {
                $button_html .= ' rel="' . esc_attr($link_rel) . '"';
            }
            $button_html .= ' target="' . esc_attr($link_target) . '"';
            $button_html .= ' title="' . esc_attr($link_title) . '"';
            $button_html .= '>' . $btn_text . '</a></div>';
            $link_html .= $button_html;
        }

        return $link_html;
    }


}
