<?php
// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

require_once ALMA_PLUGIN_DIR . 'includes/class-ai-utils.php';

/**
 * Suggerisce link affiliati con l'AI e li mostra in un popup.
 */
class ALMA_Bot_Affiliate {
    const META_ENABLED   = '_alma_bot_affiliate_enabled';
    const META_LINKS     = '_alma_bot_affiliate_links';
    const META_DELAY     = '_alma_bot_affiliate_delay';
    const META_INTRO     = '_alma_bot_affiliate_intro';
    const META_ANIMATION = '_alma_bot_affiliate_animation';
    const META_NUM_LINKS = '_alma_bot_affiliate_num_links';

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_popup'));
    }

    /**
     * Aggiunge la metabox per abilitare il Bot Affiliate.
     */
    public function add_meta_box() {
        add_meta_box(
            'alma_bot_affiliate',
            __('Bot Affiliate', 'affiliate-link-manager-ai'),
            array($this, 'render_meta_box'),
            'post',
            'side'
        );
    }

    /**
     * Render della metabox.
     */
    public function render_meta_box($post) {
        $enabled   = get_post_meta($post->ID, self::META_ENABLED, true);
        $delay     = get_post_meta($post->ID, self::META_DELAY, true);
        $intro     = get_post_meta($post->ID, self::META_INTRO, true);
        $animation = get_post_meta($post->ID, self::META_ANIMATION, true);
        $num_links = get_post_meta($post->ID, self::META_NUM_LINKS, true);
        wp_nonce_field('alma_bot_affiliate_nonce', 'alma_bot_affiliate_nonce_field');
        ?>
        <label>
            <input type="checkbox" name="alma_bot_affiliate_enabled" value="1" <?php checked($enabled, '1'); ?> />
            <?php esc_html_e('Abilita suggerimenti affiliati automatici', 'affiliate-link-manager-ai'); ?>
        </label>
        <p>
            <label for="alma_bot_affiliate_delay">
                <?php esc_html_e('Ritardo popup (secondi)', 'affiliate-link-manager-ai'); ?>
            </label>
            <select name="alma_bot_affiliate_delay" id="alma_bot_affiliate_delay">
                <?php for ($i = 0; $i <= 5; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected((string) $delay, (string) $i); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </p>
        <p>
            <label for="alma_bot_affiliate_intro">
                <?php esc_html_e('Testo personalizzato', 'affiliate-link-manager-ai'); ?>
            </label>
            <textarea name="alma_bot_affiliate_intro" id="alma_bot_affiliate_intro" rows="3" class="widefat"><?php echo esc_textarea($intro); ?></textarea>
        </p>
        <p>
            <label for="alma_bot_affiliate_num_links">
                <?php esc_html_e('Numero di link da mostrare', 'affiliate-link-manager-ai'); ?>
            </label>
            <select name="alma_bot_affiliate_num_links" id="alma_bot_affiliate_num_links">
                <option value="0" <?php selected((string) $num_links, '0'); ?>><?php esc_html_e('Usa impostazioni generali', 'affiliate-link-manager-ai'); ?></option>
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected((string) $num_links, (string) $i); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </p>
        <p>
            <label for="alma_bot_affiliate_animation">
                <?php esc_html_e('Animazione popup', 'affiliate-link-manager-ai'); ?>
            </label>
            <select name="alma_bot_affiliate_animation" id="alma_bot_affiliate_animation" class="widefat">
                <option value="fade" <?php selected($animation, 'fade'); ?>><?php esc_html_e('Dissolvenza', 'affiliate-link-manager-ai'); ?></option>
                <option value="slide" <?php selected($animation, 'slide'); ?>><?php esc_html_e('Scorrimento', 'affiliate-link-manager-ai'); ?></option>
                <option value="zoom" <?php selected($animation, 'zoom'); ?>><?php esc_html_e('Zoom', 'affiliate-link-manager-ai'); ?></option>
                <option value="left" <?php selected($animation, 'left'); ?>><?php esc_html_e('Entrata da sinistra', 'affiliate-link-manager-ai'); ?></option>
            </select>
        </p>
        <?php
    }

    /**
     * Salva il valore della metabox.
     */
    public function save_meta_box($post_id) {
        if (!isset($_POST['alma_bot_affiliate_nonce_field']) ||
            !wp_verify_nonce($_POST['alma_bot_affiliate_nonce_field'], 'alma_bot_affiliate_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $enabled = isset($_POST['alma_bot_affiliate_enabled']) ? '1' : '';
        update_post_meta($post_id, self::META_ENABLED, $enabled);

        $delay = isset($_POST['alma_bot_affiliate_delay']) ? (int) $_POST['alma_bot_affiliate_delay'] : 0;
        if ($delay < 0 || $delay > 5) {
            $delay = 0;
        }
        update_post_meta($post_id, self::META_DELAY, $delay);

        $intro = isset($_POST['alma_bot_affiliate_intro']) ? sanitize_textarea_field($_POST['alma_bot_affiliate_intro']) : '';
        update_post_meta($post_id, self::META_INTRO, $intro);

        $animation = sanitize_text_field($_POST['alma_bot_affiliate_animation'] ?? '');
        $allowed_animations = array('fade', 'slide', 'zoom', 'left');
        if (!in_array($animation, $allowed_animations, true)) {
            $animation = '';
        }
        update_post_meta($post_id, self::META_ANIMATION, $animation);

        $num_links = isset($_POST['alma_bot_affiliate_num_links']) ? (int) $_POST['alma_bot_affiliate_num_links'] : 0;
        if ($num_links < 0 || $num_links > 10) {
            $num_links = 0;
        }
        if ($num_links === 0) {
            delete_post_meta($post_id, self::META_NUM_LINKS);
        } else {
            update_post_meta($post_id, self::META_NUM_LINKS, $num_links);
        }

        if (!$enabled) {
            delete_post_meta($post_id, self::META_LINKS);
        }
    }

    /**
     * Carica CSS e JS solo se necessario.
     */
    public function enqueue_assets() {
        if (!is_singular('post')) {
            return;
        }
        global $post;
        if (!$post || get_post_meta($post->ID, self::META_ENABLED, true) !== '1') {
            return;
        }
        $delay     = (int) get_post_meta($post->ID, self::META_DELAY, true);
        $animation = get_post_meta($post->ID, self::META_ANIMATION, true);
        if ($animation === '') {
            $animation = get_option('alma_bot_affiliate_animation', 'fade');
        }
        $css = ALMA_PLUGIN_DIR . 'assets/bot-affiliate.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'alma-bot-affiliate',
                ALMA_PLUGIN_URL . 'assets/bot-affiliate.css',
                array(),
                ALMA_VERSION
            );
        }
        $js = ALMA_PLUGIN_DIR . 'assets/bot-affiliate.js';
        if (file_exists($js)) {
            wp_enqueue_script(
                'alma-bot-affiliate',
                ALMA_PLUGIN_URL . 'assets/bot-affiliate.js',
                array('jquery'),
                ALMA_VERSION,
                true
            );
            wp_localize_script(
                'alma-bot-affiliate',
                'alma_bot_affiliate',
                array(
                    'animation' => $animation,
                    'delay'     => $delay
                )
            );
        }
    }

    /**
     * Render del popup frontend.
     */
    public function render_popup() {
        if (!is_singular('post')) {
            return;
        }
        global $post;
        if (!$post || get_post_meta($post->ID, self::META_ENABLED, true) !== '1') {
            return;
        }
        $num_links = (int) get_post_meta($post->ID, self::META_NUM_LINKS, true);
        if ($num_links <= 0) {
            $num_links = (int) get_option('alma_bot_affiliate_num_links', 3);
        }
        $links = $this->get_links($post->ID, $num_links);
        if (empty($links) || !is_array($links)) {
            return;
        }
        $intro = get_post_meta($post->ID, self::META_INTRO, true);
        if (trim($intro) === '') {
            $intro = get_option('alma_bot_affiliate_intro', '');
        }
        echo '<div id="alma-bot-affiliate" class="alma-bot-affiliate">';
        echo '<button type="button" class="alma-bot-affiliate-close" aria-label="' . esc_attr__('Chiudi', 'affiliate-link-manager-ai') . '">&times;</button>';
        if (!empty($intro)) {
            echo '<p class="alma-bot-intro">' . wp_kses_post($intro) . '</p>';
        }
        echo '<ul>';
        foreach (array_slice($links, 0, $num_links) as $link) {
            $url   = esc_url($link['url'] ?? '#');
            $title = esc_html($link['title'] ?? $link['url'] ?? '');
            echo "<li><a href='{$url}' target='_blank' rel='sponsored noopener'>{$title}</a></li>";
        }
        echo '</ul></div>';
    }

    /**
     * Recupera o genera i link affiliati.
     */
    private function get_links($post_id, $num_links) {
        $links = get_post_meta($post_id, self::META_LINKS, true);
        if (is_array($links) && count($links) >= $num_links) {
            return array_slice($links, 0, $num_links);
        }
        $content = get_post_field('post_content', $post_id);
        $prompt = "Analizza il seguente contenuto e restituisci {$num_links} link affiliati pertinenti in formato JSON: [{\\\"title\\\":\\\"Titolo\\\",\\\"url\\\":\\\"https://esempio.com\\\"}]\\nContenuto:\\n" . wp_strip_all_tags($content);
        $result = ALMA_AI_Utils::call_claude_api($prompt);
        if (!$result['success']) {
            return array();
        }
        $json  = ALMA_AI_Utils::extract_first_json($result['response']);
        $links = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($links)) {
            return array();
        }
        update_post_meta($post_id, self::META_LINKS, $links);
        return array_slice($links, 0, $num_links);
    }
}
