<?php
/**
 * WordPress Dashboard shortcut for AI Content Agent.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Content_Agent_Dashboard_Widget {
    const MENU_SLUG = 'alma-ai-content-agent';
    const CAPABILITY = 'manage_options';

    public function init() {
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    public function add_dashboard_widget() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        wp_add_dashboard_widget(
            'alma_ai_content_agent_dashboard_widget',
            __('AI Content Agent', 'affiliate-link-manager-ai'),
            array($this, 'render_dashboard_widget'),
            null,
            null,
            'normal',
            'high'
        );
    }

    public function render_dashboard_widget() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $agent_url = $this->get_admin_url('dashboard');

        echo '<div class="alma-ai-content-agent-widget">';
        echo '<div class="alma-ai-content-agent-widget__icon" aria-hidden="true"><span class="dashicons dashicons-edit-page"></span></div>';
        echo '<div class="alma-ai-content-agent-widget__content">';
        echo '<p class="alma-ai-content-agent-widget__description">' . esc_html__('Crea idee, genera brief e prepara bozze articolo con supporto AI.', 'affiliate-link-manager-ai') . '</p>';
        echo '<p class="alma-ai-content-agent-widget__actions"><a class="button button-primary" href="' . esc_url($agent_url) . '">' . esc_html__('Apri AI Content Agent', 'affiliate-link-manager-ai') . '</a></p>';
        echo '<p class="alma-ai-content-agent-widget__note">' . esc_html__('Scorciatoia rapida alla sezione admin: nessun dato AI o payload OpenAI viene mostrato nella Bacheca.', 'affiliate-link-manager-ai') . '</p>';
        echo '</div>';
        echo '</div>';
    }

    private function get_admin_url($tab = 'dashboard') {
        $args = array(
            'post_type' => 'affiliate_link',
            'page'      => self::MENU_SLUG,
        );

        $tab = sanitize_key($tab);
        if ($tab !== '') {
            $args['tab'] = $tab;
        }

        return add_query_arg($args, admin_url('edit.php'));
    }
}
