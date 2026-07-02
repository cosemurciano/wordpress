<?php
/**
 * Contextual affiliate links sidebar widget and admin configuration page.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Contextual_Affiliate_Widget extends WP_Widget {
    const MENU_SLUG = 'alma-contextual-widget';
    const CACHE_VERSION_OPTION = 'alma_contextual_widget_cache_version';

    public function __construct() {
        parent::__construct(
            'alma_contextual_affiliate_widget',
            __('Widget Link Contestuale', 'affiliate-link-manager-ai'),
            array(
                'classname' => 'alma_contextual_affiliate_widget',
                'description' => __('Mostra automaticamente Link Affiliati coerenti con l’articolo visualizzato.', 'affiliate-link-manager-ai'),
            )
        );
    }

    public static function register_widget() {
        register_widget(__CLASS__);
    }

    public static function get_defaults() {
        return array(
            'enabled' => 'yes',
            'post_types' => array('post'),
            'max_links' => 4,
            'min_score' => 40,
            'title' => __('Esperienze consigliate', 'affiliate-link-manager-ai'),
            'button_text' => __('Scopri di più', 'affiliate-link-manager-ai'),
            'show_image' => 'yes',
            'show_description' => 'yes',
            'exclude_existing_links' => 'yes',
            'cache_ttl' => '24h',
            'fallback_mode' => 'hide',
            'candidate_limit' => 200,
        );
    }

    public static function get_global_settings() {
        $defaults = self::get_defaults();
        $post_types = get_option('alma_contextual_widget_post_types', $defaults['post_types']);
        if (!is_array($post_types)) {
            $post_types = array($post_types);
        }

        return array(
            'enabled' => get_option('alma_contextual_widget_enabled', $defaults['enabled']) === 'yes' ? 'yes' : 'no',
            'post_types' => self::sanitize_post_types($post_types),
            'max_links' => max(1, min(20, absint(get_option('alma_contextual_widget_max_links', $defaults['max_links'])))),
            'min_score' => max(0, min(100, absint(get_option('alma_contextual_widget_min_score', $defaults['min_score'])))),
            'title' => sanitize_text_field(get_option('alma_contextual_widget_title', $defaults['title'])),
            'button_text' => sanitize_text_field(get_option('alma_contextual_widget_button_text', $defaults['button_text'])),
            'show_image' => get_option('alma_contextual_widget_show_image', $defaults['show_image']) === 'yes' ? 'yes' : 'no',
            'show_description' => get_option('alma_contextual_widget_show_description', $defaults['show_description']) === 'yes' ? 'yes' : 'no',
            'exclude_existing_links' => get_option('alma_contextual_widget_exclude_existing_links', $defaults['exclude_existing_links']) === 'yes' ? 'yes' : 'no',
            'cache_ttl' => self::sanitize_cache_ttl(get_option('alma_contextual_widget_cache_ttl', $defaults['cache_ttl'])),
            'fallback_mode' => self::sanitize_fallback_mode(get_option('alma_contextual_widget_fallback_mode', $defaults['fallback_mode'])),
            'candidate_limit' => $defaults['candidate_limit'],
        );
    }

    public static function maybe_set_default_options() {
        $defaults = self::get_defaults();
        $map = array(
            'alma_contextual_widget_enabled' => $defaults['enabled'],
            'alma_contextual_widget_post_types' => $defaults['post_types'],
            'alma_contextual_widget_max_links' => $defaults['max_links'],
            'alma_contextual_widget_min_score' => $defaults['min_score'],
            'alma_contextual_widget_title' => $defaults['title'],
            'alma_contextual_widget_button_text' => $defaults['button_text'],
            'alma_contextual_widget_show_image' => $defaults['show_image'],
            'alma_contextual_widget_show_description' => $defaults['show_description'],
            'alma_contextual_widget_exclude_existing_links' => $defaults['exclude_existing_links'],
            'alma_contextual_widget_cache_ttl' => $defaults['cache_ttl'],
            'alma_contextual_widget_fallback_mode' => $defaults['fallback_mode'],
            self::CACHE_VERSION_OPTION => 1,
        );
        foreach ($map as $option => $value) {
            if (get_option($option, null) === null) {
                add_option($option, $value);
            }
        }
    }

    public function widget($args, $instance) {
        if (!is_singular()) {
            return;
        }

        $settings = $this->resolve_settings($instance);
        if ($settings['enabled'] !== 'yes') {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            global $post;
        }
        if (!$post instanceof WP_Post || !in_array($post->post_type, $settings['post_types'], true)) {
            return;
        }

        $links = $this->get_cached_matches($post, $settings);
        if (empty($links) && $settings['fallback_mode'] === 'hide') {
            return;
        }
        if (empty($links)) {
            return;
        }

        echo $args['before_widget'];
        echo '<div class="alma-contextual-widget">';
        if ($settings['title'] !== '') {
            echo $args['before_title'] . '<span class="alma-contextual-widget__title">' . esc_html($settings['title']) . '</span>' . $args['after_title'];
        }
        echo '<div class="alma-contextual-widget__list">';
        foreach ($links as $link) {
            $this->render_link_item($link, $settings);
        }
        echo '</div>';
        echo '</div>';
        echo $args['after_widget'];
    }

    public function form($instance) {
        $instance = wp_parse_args((array) $instance, array(
            'title' => '',
            'max_links' => '',
            'min_score' => '',
            'show_image' => '',
            'show_description' => '',
            'button_text' => '',
        ));
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Titolo box', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($instance['title']); ?>" placeholder="<?php echo esc_attr(self::get_defaults()['title']); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('max_links')); ?>"><?php esc_html_e('Numero massimo link', 'affiliate-link-manager-ai'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('max_links')); ?>" name="<?php echo esc_attr($this->get_field_name('max_links')); ?>" type="number" min="1" max="20" value="<?php echo esc_attr($instance['max_links']); ?>" placeholder="4" />
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('min_score')); ?>"><?php esc_html_e('Soglia minima', 'affiliate-link-manager-ai'); ?></label>
            <input class="tiny-text" id="<?php echo esc_attr($this->get_field_id('min_score')); ?>" name="<?php echo esc_attr($this->get_field_name('min_score')); ?>" type="number" min="0" max="100" value="<?php echo esc_attr($instance['min_score']); ?>" placeholder="40" />
        </p>
        <?php $this->render_widget_select('show_image', __('Mostra immagine', 'affiliate-link-manager-ai'), $instance['show_image']); ?>
        <?php $this->render_widget_select('show_description', __('Mostra descrizione', 'affiliate-link-manager-ai'), $instance['show_description']); ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('button_text')); ?>"><?php esc_html_e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('button_text')); ?>" name="<?php echo esc_attr($this->get_field_name('button_text')); ?>" type="text" value="<?php echo esc_attr($instance['button_text']); ?>" placeholder="<?php echo esc_attr(self::get_defaults()['button_text']); ?>" />
        </p>
        <p class="description"><?php esc_html_e('Lascia vuoto un campo per usare le impostazioni globali del Widget Contestuale.', 'affiliate-link-manager-ai'); ?></p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['max_links'] = isset($new_instance['max_links']) && $new_instance['max_links'] !== '' ? (string) max(1, min(20, absint($new_instance['max_links']))) : '';
        $instance['min_score'] = isset($new_instance['min_score']) && $new_instance['min_score'] !== '' ? (string) max(0, min(100, absint($new_instance['min_score']))) : '';
        $instance['show_image'] = self::sanitize_yes_no_or_empty($new_instance['show_image'] ?? '');
        $instance['show_description'] = self::sanitize_yes_no_or_empty($new_instance['show_description'] ?? '');
        $instance['button_text'] = sanitize_text_field($new_instance['button_text'] ?? '');
        self::bump_cache_version();
        return $instance;
    }

    private function render_widget_select($key, $label, $selected) {
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id($key)); ?>"><?php echo esc_html($label); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id($key)); ?>" name="<?php echo esc_attr($this->get_field_name($key)); ?>">
                <option value="" <?php selected($selected, ''); ?>><?php esc_html_e('Usa impostazione globale', 'affiliate-link-manager-ai'); ?></option>
                <option value="yes" <?php selected($selected, 'yes'); ?>><?php esc_html_e('Sì', 'affiliate-link-manager-ai'); ?></option>
                <option value="no" <?php selected($selected, 'no'); ?>><?php esc_html_e('No', 'affiliate-link-manager-ai'); ?></option>
            </select>
        </p>
        <?php
    }

    private function resolve_settings($instance) {
        $settings = self::get_global_settings();
        $instance = (array) $instance;
        foreach (array('title', 'button_text') as $key) {
            if (isset($instance[$key]) && trim((string) $instance[$key]) !== '') {
                $settings[$key] = sanitize_text_field($instance[$key]);
            }
        }
        foreach (array('max_links', 'min_score') as $key) {
            if (isset($instance[$key]) && $instance[$key] !== '') {
                $settings[$key] = $key === 'max_links' ? max(1, min(20, absint($instance[$key]))) : max(0, min(100, absint($instance[$key])));
            }
        }
        foreach (array('show_image', 'show_description') as $key) {
            $value = self::sanitize_yes_no_or_empty($instance[$key] ?? '');
            if ($value !== '') {
                $settings[$key] = $value;
            }
        }
        return $settings;
    }

    private function get_cached_matches($post, $settings) {
        $hash_settings = $settings;
        unset($hash_settings['title'], $hash_settings['button_text']);
        $hash_settings['cache_version'] = absint(get_option(self::CACHE_VERSION_OPTION, 1));
        // La versione del matcher invalida i risultati calcolati con l'algoritmo
        // precedente; la data di modifica invalida la cache del singolo articolo
        // al suo salvataggio, senza azzerare la cache di tutto il sito.
        $hash_settings['matcher_version'] = ALMA_Contextual_Affiliate_Matcher::MATCHER_VERSION;
        $hash_settings['post_modified'] = (string) $post->post_modified_gmt;
        // Le associazioni geografiche (import GEO, auto-indexer, metabox) scrivono
        // _alma_geo_updated_at senza toccare post_modified: va incluso nell'hash,
        // altrimenti il widget serve risultati calcolati prima dell'associazione.
        $hash_settings['geo_updated'] = (string) get_post_meta($post->ID, '_alma_geo_updated_at', true);
        $hash = md5(wp_json_encode($hash_settings));
        $cache_key = 'alma_contextual_widget_' . absint($post->ID) . '_' . $hash;
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['link_ids'], $cached['settings_hash']) && $cached['settings_hash'] === $hash) {
            return $this->hydrate_cached_links($cached);
        }

        $matcher = new ALMA_Contextual_Affiliate_Matcher($settings);
        $links = $matcher->match($post, $settings);
        set_transient($cache_key, array(
            'link_ids' => wp_list_pluck($links, 'id'),
            'scores' => wp_list_pluck($links, 'score', 'id'),
            'timestamp' => time(),
            'settings_hash' => $hash,
        ), self::get_ttl_seconds($settings['cache_ttl']));

        return $links;
    }

    private function hydrate_cached_links($cached) {
        $links = array();
        $scores = is_array($cached['scores'] ?? null) ? $cached['scores'] : array();
        foreach ((array) $cached['link_ids'] as $id) {
            $id = absint($id);
            $post = get_post($id);
            $affiliate_url = trim((string) get_post_meta($id, '_affiliate_url', true));
            if (!$post || $post->post_type !== 'affiliate_link' || $post->post_status !== 'publish' || $affiliate_url === '') {
                continue;
            }
            $links[] = array(
                'id' => $id,
                'score' => absint($scores[$id] ?? 0),
                'click_count' => absint(get_post_meta($id, '_click_count', true)),
                'title' => get_the_title($id),
                'affiliate_url' => $affiliate_url,
                'has_image' => has_post_thumbnail($id),
            );
        }
        return $links;
    }

    private function render_link_item($link, $settings) {
        $link_id = absint($link['id']);
        $affiliate_url = get_post_meta($link_id, '_affiliate_url', true);
        if ($affiliate_url === '') {
            return;
        }
        $rel = get_post_meta($link_id, '_link_rel', true);
        if ($rel === '' && metadata_exists('post', $link_id, '_link_rel')) {
            $rel_attr = '';
        } elseif (!$rel) {
            $rel_attr = 'sponsored noopener';
        } else {
            $rel_attr = $rel;
        }
        $target = get_post_meta($link_id, '_link_target', true) ?: '_blank';
        $link_title = get_post_meta($link_id, '_link_title', true) ?: get_the_title($link_id);
        $description = wp_trim_words(wp_strip_all_tags(strip_shortcodes(get_post_field('post_excerpt', $link_id) ?: get_post_field('post_content', $link_id))), 18, '…');
        ?>
        <article class="alma-contextual-widget__item">
            <?php if ($settings['show_image'] === 'yes' && has_post_thumbnail($link_id)) : ?>
                <a class="alma-contextual-widget__image alma-affiliate-link" href="<?php echo esc_url($affiliate_url); ?>" data-track="1" data-link-id="<?php echo esc_attr($link_id); ?>" data-source="contextual_widget" <?php echo $rel_attr !== '' ? 'rel="' . esc_attr($rel_attr) . '"' : ''; ?> target="<?php echo esc_attr($target); ?>" title="<?php echo esc_attr($link_title); ?>">
                    <?php echo get_the_post_thumbnail($link_id, 'full'); ?>
                </a>
            <?php endif; ?>
            <div class="alma-contextual-widget__content">
                <a class="alma-contextual-widget__link-title alma-affiliate-link" href="<?php echo esc_url($affiliate_url); ?>" data-track="1" data-link-id="<?php echo esc_attr($link_id); ?>" data-source="contextual_widget" <?php echo $rel_attr !== '' ? 'rel="' . esc_attr($rel_attr) . '"' : ''; ?> target="<?php echo esc_attr($target); ?>" title="<?php echo esc_attr($link_title); ?>"><?php echo esc_html(get_the_title($link_id)); ?></a>
                <?php if ($settings['show_description'] === 'yes' && $description !== '') : ?>
                    <p class="alma-contextual-widget__description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                <a class="alma-contextual-widget__button alma-affiliate-link" href="<?php echo esc_url($affiliate_url); ?>" data-track="1" data-link-id="<?php echo esc_attr($link_id); ?>" data-source="contextual_widget" <?php echo $rel_attr !== '' ? 'rel="' . esc_attr($rel_attr) . '"' : ''; ?> target="<?php echo esc_attr($target); ?>" title="<?php echo esc_attr($link_title); ?>"><?php echo esc_html($settings['button_text']); ?></a>
            </div>
        </article>
        <?php
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'affiliate-link-manager-ai'));
        }

        self::maybe_set_default_options();
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('alma_contextual_widget_settings', 'alma_contextual_widget_nonce');
            $action = sanitize_key($_POST['alma_contextual_widget_action'] ?? 'save');
            if ($action === 'flush') {
                self::bump_cache_version();
                $message = __('Cache Widget Contestuale svuotata.', 'affiliate-link-manager-ai');
            } else {
                self::save_settings_from_post();
                self::bump_cache_version();
                $message = __('Impostazioni Widget Contestuale salvate.', 'affiliate-link-manager-ai');
            }
        }

        $settings = self::get_global_settings();
        $public_post_types = self::get_supported_post_type_choices();
        ?>
        <div class="wrap alma-contextual-widget-admin">
            <h1><?php esc_html_e('Widget Link Contestuale', 'affiliate-link-manager-ai'); ?></h1>
            <?php if ($message !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            <div class="card">
                <h2><?php esc_html_e('Matching locale senza AI', 'affiliate-link-manager-ai'); ?></h2>
                <p><?php esc_html_e('Il widget seleziona i Link Affiliati pertinenti alla pagina corrente in tre passaggi: prima le località condivise tramite l’Indice Geografico (segnale dominante), poi le keyword in comune pesate per specificità (titolo, heading H2/H3, categorie, tag e contenuto), infine tipologie e contesto AI. Mostra solo i link sopra soglia. Non modifica gli articoli e non usa OpenAI.', 'affiliate-link-manager-ai'); ?></p>
                <p><span class="alma-badge"><?php esc_html_e('Cache per post', 'affiliate-link-manager-ai'); ?></span> <?php esc_html_e('La cache del singolo articolo si invalida al suo salvataggio; la cache globale si invalida al salvataggio di Link Affiliati e impostazioni.', 'affiliate-link-manager-ai'); ?></p>
                <p><span class="alma-badge"><?php esc_html_e('Suggerimento', 'affiliate-link-manager-ai'); ?></span> <?php esc_html_e('Per il matching geografico associa le località ad articoli e Link Affiliati dal metabox Geolocalizzazione contenuto o tramite gli import GEO; senza dati geografici il widget usa il solo matching testuale.', 'affiliate-link-manager-ai'); ?></p>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('alma_contextual_widget_settings', 'alma_contextual_widget_nonce'); ?>
                <input type="hidden" name="alma_contextual_widget_action" value="save" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Abilita widget', 'affiliate-link-manager-ai'); ?></th>
                        <td><label><input type="checkbox" name="alma_contextual_widget_enabled" value="yes" <?php checked($settings['enabled'], 'yes'); ?> /> <?php esc_html_e('Abilitato', 'affiliate-link-manager-ai'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Post type supportati', 'affiliate-link-manager-ai'); ?></th>
                        <td>
                            <?php foreach ($public_post_types as $type => $label) : ?>
                                <label><input type="checkbox" name="alma_contextual_widget_post_types[]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $settings['post_types'], true)); ?> /> <?php echo esc_html($label); ?></label><br />
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('MVP: il widget è pensato per pagine singole post e page.', 'affiliate-link-manager-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_max_links"><?php esc_html_e('Numero massimo link', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><input type="number" id="alma_contextual_widget_max_links" name="alma_contextual_widget_max_links" min="1" max="20" value="<?php echo esc_attr($settings['max_links']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_min_score"><?php esc_html_e('Soglia minima score', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><input type="number" id="alma_contextual_widget_min_score" name="alma_contextual_widget_min_score" min="0" max="100" value="<?php echo esc_attr($settings['min_score']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_title"><?php esc_html_e('Titolo box', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><input type="text" class="regular-text" id="alma_contextual_widget_title" name="alma_contextual_widget_title" value="<?php echo esc_attr($settings['title']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_button_text"><?php esc_html_e('Testo pulsante', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><input type="text" class="regular-text" id="alma_contextual_widget_button_text" name="alma_contextual_widget_button_text" value="<?php echo esc_attr($settings['button_text']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Mostra immagine', 'affiliate-link-manager-ai'); ?></th>
                        <td><label><input type="checkbox" name="alma_contextual_widget_show_image" value="yes" <?php checked($settings['show_image'], 'yes'); ?> /> <?php esc_html_e('Usa immagine originale/full del Link Affiliato', 'affiliate-link-manager-ai'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Mostra descrizione', 'affiliate-link-manager-ai'); ?></th>
                        <td><label><input type="checkbox" name="alma_contextual_widget_show_description" value="yes" <?php checked($settings['show_description'], 'yes'); ?> /> <?php esc_html_e('Mostra descrizione breve', 'affiliate-link-manager-ai'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Escludi link già presenti', 'affiliate-link-manager-ai'); ?></th>
                        <td><label><input type="checkbox" name="alma_contextual_widget_exclude_existing_links" value="yes" <?php checked($settings['exclude_existing_links'], 'yes'); ?> /> <?php esc_html_e('Non mostrare link già inseriti nel contenuto articolo', 'affiliate-link-manager-ai'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_cache_ttl"><?php esc_html_e('Durata cache', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><?php self::render_cache_ttl_select($settings['cache_ttl']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="alma_contextual_widget_fallback_mode"><?php esc_html_e('Fallback', 'affiliate-link-manager-ai'); ?></label></th>
                        <td><?php self::render_fallback_select($settings['fallback_mode']); ?><p class="description"><?php esc_html_e('Per questo MVP è attivo hide: se non ci sono link coerenti, il widget non mostra nulla.', 'affiliate-link-manager-ai'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(__('Salva impostazioni', 'affiliate-link-manager-ai')); ?>
            </form>
            <form method="post" action="">
                <?php wp_nonce_field('alma_contextual_widget_settings', 'alma_contextual_widget_nonce'); ?>
                <input type="hidden" name="alma_contextual_widget_action" value="flush" />
                <?php submit_button(__('Svuota cache Widget Contestuale', 'affiliate-link-manager-ai'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    private static function save_settings_from_post() {
        update_option('alma_contextual_widget_enabled', isset($_POST['alma_contextual_widget_enabled']) ? 'yes' : 'no');
        update_option('alma_contextual_widget_post_types', self::sanitize_post_types($_POST['alma_contextual_widget_post_types'] ?? array()));
        update_option('alma_contextual_widget_max_links', max(1, min(20, absint($_POST['alma_contextual_widget_max_links'] ?? 4))));
        update_option('alma_contextual_widget_min_score', max(0, min(100, absint($_POST['alma_contextual_widget_min_score'] ?? 40))));
        update_option('alma_contextual_widget_title', sanitize_text_field($_POST['alma_contextual_widget_title'] ?? self::get_defaults()['title']));
        update_option('alma_contextual_widget_button_text', sanitize_text_field($_POST['alma_contextual_widget_button_text'] ?? self::get_defaults()['button_text']));
        update_option('alma_contextual_widget_show_image', isset($_POST['alma_contextual_widget_show_image']) ? 'yes' : 'no');
        update_option('alma_contextual_widget_show_description', isset($_POST['alma_contextual_widget_show_description']) ? 'yes' : 'no');
        update_option('alma_contextual_widget_exclude_existing_links', isset($_POST['alma_contextual_widget_exclude_existing_links']) ? 'yes' : 'no');
        update_option('alma_contextual_widget_cache_ttl', self::sanitize_cache_ttl($_POST['alma_contextual_widget_cache_ttl'] ?? '24h'));
        update_option('alma_contextual_widget_fallback_mode', self::sanitize_fallback_mode($_POST['alma_contextual_widget_fallback_mode'] ?? 'hide'));
    }

    public static function maybe_invalidate_on_save($post_id, $post, $update) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !$post instanceof WP_Post) {
            return;
        }
        // Bump globale solo quando cambia un Link Affiliato (influisce su tutte le
        // pagine). Il salvataggio di un articolo invalida solo la propria cache,
        // tramite post_modified nell'hash: prima ogni salvataggio azzerava la
        // cache dell'intero sito, rendendola quasi sempre fredda.
        if ($post->post_type === 'affiliate_link') {
            self::bump_cache_version();
        }
    }

    public static function bump_cache_version() {
        $version = absint(get_option(self::CACHE_VERSION_OPTION, 1));
        update_option(self::CACHE_VERSION_OPTION, $version + 1, false);
    }

    private static function sanitize_post_types($post_types) {
        $allowed = array_keys(self::get_supported_post_type_choices());
        $clean = array();
        foreach ((array) $post_types as $post_type) {
            $post_type = sanitize_key($post_type);
            if (in_array($post_type, $allowed, true)) {
                $clean[] = $post_type;
            }
        }
        $clean = array_values(array_unique($clean));
        return empty($clean) ? array('post') : $clean;
    }

    private static function get_supported_post_type_choices() {
        return array(
            'post' => __('Articoli', 'affiliate-link-manager-ai'),
            'page' => __('Pagine', 'affiliate-link-manager-ai'),
        );
    }

    private static function sanitize_yes_no_or_empty($value) {
        $value = sanitize_key($value);
        return in_array($value, array('yes', 'no'), true) ? $value : '';
    }

    private static function sanitize_cache_ttl($value) {
        $value = sanitize_key($value);
        return array_key_exists($value, self::get_cache_ttl_choices()) ? $value : '24h';
    }

    private static function sanitize_fallback_mode($value) {
        $value = sanitize_key($value);
        return in_array($value, array('hide', 'popular', 'manual', 'same_type'), true) ? $value : 'hide';
    }

    private static function get_ttl_seconds($value) {
        $choices = self::get_cache_ttl_choices();
        return $choices[self::sanitize_cache_ttl($value)]['seconds'];
    }

    private static function get_cache_ttl_choices() {
        return array(
            '1h' => array('label' => __('1 ora', 'affiliate-link-manager-ai'), 'seconds' => HOUR_IN_SECONDS),
            '6h' => array('label' => __('6 ore', 'affiliate-link-manager-ai'), 'seconds' => 6 * HOUR_IN_SECONDS),
            '24h' => array('label' => __('24 ore', 'affiliate-link-manager-ai'), 'seconds' => DAY_IN_SECONDS),
            '7d' => array('label' => __('7 giorni', 'affiliate-link-manager-ai'), 'seconds' => WEEK_IN_SECONDS),
        );
    }

    private static function render_cache_ttl_select($selected) {
        echo '<select id="alma_contextual_widget_cache_ttl" name="alma_contextual_widget_cache_ttl">';
        foreach (self::get_cache_ttl_choices() as $value => $data) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($data['label']) . '</option>';
        }
        echo '</select>';
    }

    private static function render_fallback_select($selected) {
        $choices = array(
            'hide' => __('Hide - non mostrare nulla', 'affiliate-link-manager-ai'),
            'popular' => __('Popular - futuro', 'affiliate-link-manager-ai'),
            'manual' => __('Manual - futuro', 'affiliate-link-manager-ai'),
            'same_type' => __('Same type - futuro', 'affiliate-link-manager-ai'),
        );
        echo '<select id="alma_contextual_widget_fallback_mode" name="alma_contextual_widget_fallback_mode">';
        foreach ($choices as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . ($value !== 'hide' ? ' disabled' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
}
