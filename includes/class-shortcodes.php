<?php
/**
 * Shortcode registration and rendering.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Shortcodes {
    public function init() {
        require_once ALMA_PLUGIN_DIR . 'includes/class-affiliate-links-widget.php';

        add_shortcode('affiliate_link', array($this, 'display_affiliate_link'));
        add_shortcode('affiliate_links_widget', array('ALMA_Affiliate_Links_Widget', 'shortcode'));
        add_shortcode('affiliate_chat_ai', array($this, 'render_affiliate_chat_shortcode'));

        add_action('wp_ajax_alma_affiliate_chat', array($this, 'ajax_affiliate_chat'));
        add_action('wp_ajax_nopriv_alma_affiliate_chat', array($this, 'ajax_affiliate_chat'));
        add_action('widgets_init', array($this, 'register_widget'));
    }

    public function register_widget() {
        register_widget('ALMA_Affiliate_Links_Widget');
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

    public function render_affiliate_chat_shortcode() {
        wp_enqueue_script(
            'alma-chat-ai',
            ALMA_PLUGIN_URL . 'assets/chat-ai.js',
            array('jquery'),
            ALMA_VERSION,
            true
        );
        wp_localize_script('alma-chat-ai', 'alma_chat_ai', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('alma_affiliate_chat'),
        ));

        ob_start();
        ?>
        <div id="alma-chat-container">
            <iframe id="alma-chat-frame"></iframe>
            <form id="alma-chat-form">
                <input type="text" id="alma-chat-query" placeholder="<?php esc_attr_e('Cerca link affiliati...', 'affiliate-link-manager-ai'); ?>" required />
                <button type="submit" id="alma-chat-submit"><?php esc_html_e('Chiedi', 'affiliate-link-manager-ai'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function ajax_require_nonce($action, $field = 'nonce') {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, $action)) {
            wp_send_json_error(array('message' => __('Verifica di sicurezza non riuscita.', 'affiliate-link-manager-ai')), 403);
        }
    }

    private function get_user_ip() {
        $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    private function get_affiliate_chat_rate_limit_key() {
        $ip = $this->get_user_ip();
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = 'unknown';
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ua = function_exists('mb_substr') ? mb_substr($ua, 0, 120) : substr($ua, 0, 120);
        return 'alma_chat_rl_' . hash('sha256', $ip . '|' . $ua);
    }

    private function check_affiliate_chat_rate_limit() {
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return true;
        }

        $defaults = array('limit' => 10, 'window' => 10 * MINUTE_IN_SECONDS);
        $config = apply_filters('alma_affiliate_chat_rate_limit', $defaults);
        $limit = max(1, absint($config['limit'] ?? $defaults['limit']));
        $window = max(MINUTE_IN_SECONDS, absint($config['window'] ?? $defaults['window']));
        $key = $this->get_affiliate_chat_rate_limit_key();
        $bucket = get_transient($key);
        if (!is_array($bucket)) {
            $bucket = array('count' => 0, 'reset' => time() + $window);
        }

        if ((int) ($bucket['count'] ?? 0) >= $limit) {
            ALMA_AI_Usage_Logger::log(array(
                'task' => 'affiliate_chat_rate_limited',
                'success' => false,
                'error' => 'rate_limit_exceeded',
                'reference_id' => 'public_chat:' . substr($key, -12),
            ));
            wp_send_json_error(array(
                'code' => 'rate_limit_exceeded',
                'message' => __('Hai raggiunto il limite temporaneo di richieste. Attendi qualche minuto e riprova.', 'affiliate-link-manager-ai'),
                'retry_after' => max(1, (int) ($bucket['reset'] ?? (time() + $window)) - time()),
            ), 429);
        }

        $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;
        $bucket['reset'] = (int) ($bucket['reset'] ?? (time() + $window));
        set_transient($key, $bucket, max(1, $bucket['reset'] - time()));
        return true;
    }

    public function ajax_affiliate_chat() {
        $this->ajax_require_nonce('alma_affiliate_chat');
        $this->check_affiliate_chat_rate_limit();

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $query = function_exists('mb_substr') ? mb_substr($query, 0, 500) : substr($query, 0, 500);
        if (empty($query)) {
            wp_send_json_error(array('message' => __('Richiesta mancante', 'affiliate-link-manager-ai')), 400);
        }

        $cached       = ALMA_Content_Analysis_AI::search_cache($query);
        $content_text = '';
        foreach ($cached as $item) {
            $snippet = function_exists('mb_substr') ? mb_substr(wp_strip_all_tags((string) ($item['content'] ?? '')), 0, 200) : substr(wp_strip_all_tags((string) ($item['content'] ?? '')), 0, 200);
            $content_text .= '- ' . sanitize_text_field($item['title'] ?? '') . ': ' . sanitize_text_field($snippet) . "\n";
        }

        $conversation = array();
        if (!empty($_POST['conversation'])) {
            $decoded = json_decode(wp_unslash($_POST['conversation']), true);
            if (is_array($decoded)) {
                foreach (array_slice($decoded, -10) as $msg) {
                    if (empty($msg['content'])) {
                        continue;
                    }
                    $conversation[] = array(
                        'role'    => isset($msg['role']) && $msg['role'] === 'assistant' ? 'assistant' : 'user',
                        'content' => function_exists('mb_substr') ? mb_substr(sanitize_textarea_field($msg['content']), 0, 1000) : substr(sanitize_textarea_field($msg['content']), 0, 1000)
                    );
                }
            }
        }

        $posts = get_posts(array(
            'post_type'      => 'affiliate_link',
            'numberposts'    => -1,
            'post_status'    => 'publish',
        ));

        $links = array();
        foreach ($posts as $p) {
            $types = wp_get_post_terms($p->ID, 'link_type', array('fields' => 'names'));
            if (empty($types)) {
                $types = array(__('Generale', 'affiliate-link-manager-ai'));
            }
            $url = get_post_meta($p->ID, '_affiliate_url', true);
            foreach ($types as $type) {
                $links[$type][] = array(
                    'title' => get_the_title($p->ID),
                    'url'   => esc_url_raw($url),
                );
            }
        }

        $links_text = '';
        foreach ($links as $type => $items) {
            $links_text .= sanitize_text_field($type) . ":\n";
            foreach ($items as $item) {
                $links_text .= '- ' . sanitize_text_field($item['title']) . ': ' . esc_url_raw($item['url']) . "\n";
            }
        }

        $settings      = get_option('alma_prompt_ai_settings', array());
        $system_prompt  = $settings['base_prompt'] ?? '';
        if (!empty($settings['personality'])) {
            $system_prompt .= '\nTono: ' . $settings['personality'];
            if ($settings['personality'] === 'personalizzato' && !empty($settings['personality_custom'])) {
                $system_prompt .= ' (' . $settings['personality_custom'] . ')';
            }
        }

        $user_prompt = "Richiesta utente: $query\n";
        if ($content_text !== '') {
            $user_prompt .= "Contenuti disponibili:\n$content_text\n";
        }
        $user_prompt .= "Link disponibili:\n$links_text\n" .
            "Sulla base esclusiva dei link forniti, suggerisci quelli più pertinenti organizzati per tipologia. " .
            "Non menzionare o generare link esterni alla lista. Se nessun link è adatto, segnala che non sono disponibili suggerimenti. " .
            "Spiega brevemente le tue scelte prima della lista.";

        $result = ALMA_AI_Utils::call_openai_api($user_prompt, $system_prompt, $conversation);

        if (!$result['success']) {
            wp_send_json_error(array('message' => __('Non riesco a generare una risposta in questo momento. Riprova più tardi.', 'affiliate-link-manager-ai')), 502);
        }

        wp_send_json_success(array('reply' => wp_kses_post($result['response'])));
    }
}
