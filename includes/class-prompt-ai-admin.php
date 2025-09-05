<?php
// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestione impostazioni prompt AI per Claude
 */
class ALMA_Prompt_AI_Admin {
    const OPTION_NAME = 'alma_prompt_ai_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_alma_save_prompt_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_alma_test_prompt', array($this, 'ajax_test_prompt'));
    }

    /**
     * Registra la pagina di amministrazione
     */
    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=affiliate_link',
            __('PROMPT AI Settings', 'affiliate-link-manager-ai'),
            __('PROMPT AI Settings', 'affiliate-link-manager-ai'),

            'manage_options',
            'alma-prompt-ai-settings',
            array($this, 'render_page')
        );
    }

    /**
     * Carica script e stili
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'affiliate_link_page_alma-prompt-ai-settings') {
            return;
        }

        if (file_exists(ALMA_PLUGIN_DIR . 'assets/prompt-ai-admin.css')) {
            wp_enqueue_style(
                'alma-prompt-ai-admin',
                ALMA_PLUGIN_URL . 'assets/prompt-ai-admin.css',
                array(),
                ALMA_VERSION
            );
        }

        if (file_exists(ALMA_PLUGIN_DIR . 'assets/prompt-ai-admin.js')) {
            wp_enqueue_script(
                'alma-prompt-ai-admin',
                ALMA_PLUGIN_URL . 'assets/prompt-ai-admin.js',
                array('jquery'),
                ALMA_VERSION,
                true
            );
            wp_localize_script('alma-prompt-ai-admin', 'alma_prompt_ai', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('alma_prompt_ai_nonce'),
            ));
        }
    }

    /**
     * Renderizza la pagina delle impostazioni
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = get_option(self::OPTION_NAME, array());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PROMPT PER AI DEVELOPER', 'affiliate-link-manager-ai'); ?></h1>
            <form id="alma-prompt-settings-form">
                <?php wp_nonce_field('alma_prompt_ai_nonce', 'alma_prompt_ai_nonce_field'); ?>
                <div class="alma-field alma-required">
                    <label for="alma-base-prompt"><strong><?php esc_html_e('Prompt di Sistema Base', 'affiliate-link-manager-ai'); ?></strong><span class="required">*</span></label>
                    <textarea id="alma-base-prompt" name="base_prompt" rows="10" cols="80" required placeholder="<?php esc_attr_e('Es: Sei un assistente customer service professionale per [nome azienda]...', 'affiliate-link-manager-ai'); ?>"><?php echo isset($settings['base_prompt']) ? esc_textarea($settings['base_prompt']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Istruzioni principali che definiscono il ruolo dell\'AI.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field alma-required">
                    <label for="alma-personality"><strong><?php esc_html_e('Personalità', 'affiliate-link-manager-ai'); ?></strong><span class="required">*</span></label>
                    <select name="personality" id="alma-personality" required>
                        <?php $personality = $settings['personality'] ?? ''; ?>
                        <option value="professionale" <?php selected($personality, 'professionale'); ?>><?php esc_html_e('professionale', 'affiliate-link-manager-ai'); ?></option>
                        <option value="amichevole" <?php selected($personality, 'amichevole'); ?>><?php esc_html_e('amichevole', 'affiliate-link-manager-ai'); ?></option>
                        <option value="tecnico" <?php selected($personality, 'tecnico'); ?>><?php esc_html_e('tecnico', 'affiliate-link-manager-ai'); ?></option>
                        <option value="commerciale" <?php selected($personality, 'commerciale'); ?>><?php esc_html_e('commerciale', 'affiliate-link-manager-ai'); ?></option>
                        <option value="personalizzato" <?php selected($personality, 'personalizzato'); ?>><?php esc_html_e('personalizzato', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <textarea name="personality_custom" id="alma-personality-custom" rows="4" cols="80" placeholder="<?php esc_attr_e('Definisci la personalità personalizzata', 'affiliate-link-manager-ai'); ?>" style="<?php echo ($personality === 'personalizzato') ? '' : 'display:none;'; ?>"><?php echo isset($settings['personality_custom']) ? esc_textarea($settings['personality_custom']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Stile con cui l\'AI interagisce. Es: professionale.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field alma-required">
                    <label for="alma-company-name"><strong><?php esc_html_e('Nome Azienda', 'affiliate-link-manager-ai'); ?></strong><span class="required">*</span></label>
                    <input type="text" id="alma-company-name" name="company_name" value="<?php echo isset($settings['company_name']) ? esc_attr($settings['company_name']) : ''; ?>" required placeholder="<?php esc_attr_e('Es: ACME Corp', 'affiliate-link-manager-ai'); ?>" />
                    <p class="description"><?php esc_html_e('Nome dell\'azienda utilizzato nelle risposte.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field">
                    <label for="alma-company-sector"><strong><?php esc_html_e('Settore', 'affiliate-link-manager-ai'); ?></strong></label>
                    <select name="company_sector" id="alma-company-sector">
                        <?php $sector = $settings['company_sector'] ?? ''; ?>
                        <option value="e-commerce" <?php selected($sector, 'e-commerce'); ?>><?php esc_html_e('e-commerce', 'affiliate-link-manager-ai'); ?></option>
                        <option value="servizi" <?php selected($sector, 'servizi'); ?>><?php esc_html_e('servizi', 'affiliate-link-manager-ai'); ?></option>
                        <option value="blog" <?php selected($sector, 'blog'); ?>><?php esc_html_e('blog', 'affiliate-link-manager-ai'); ?></option>
                        <option value="altro" <?php selected($sector, 'altro'); ?>><?php esc_html_e('altro', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Settore operativo dell\'azienda. Es: e-commerce.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field">
                    <label for="alma-support-hours"><strong><?php esc_html_e('Orari Supporto', 'affiliate-link-manager-ai'); ?></strong></label>
                    <input type="text" id="alma-support-hours" name="support_hours" value="<?php echo isset($settings['support_hours']) ? esc_attr($settings['support_hours']) : ''; ?>" placeholder="<?php esc_attr_e('Es: Lun-Ven 9-18', 'affiliate-link-manager-ai'); ?>" />
                    <p class="description"><?php esc_html_e('Orari in cui è disponibile il servizio clienti.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field alma-required">
                    <label for="alma-support-email"><strong><?php esc_html_e('Email Supporto', 'affiliate-link-manager-ai'); ?></strong><span class="required">*</span></label>
                    <input type="email" id="alma-support-email" name="support_email" value="<?php echo isset($settings['support_email']) ? esc_attr($settings['support_email']) : ''; ?>" required placeholder="<?php esc_attr_e('Es: supporto@azienda.com', 'affiliate-link-manager-ai'); ?>" />
                    <p class="description"><?php esc_html_e('Indirizzo email per contattare il supporto.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field">
                    <label for="alma-support-phone"><strong><?php esc_html_e('Telefono Supporto', 'affiliate-link-manager-ai'); ?></strong></label>
                    <input type="text" id="alma-support-phone" name="support_phone" value="<?php echo isset($settings['support_phone']) ? esc_attr($settings['support_phone']) : ''; ?>" placeholder="<?php esc_attr_e('Es: +39 012 345 6789', 'affiliate-link-manager-ai'); ?>" />
                    <p class="description"><?php esc_html_e('Numero telefonico per il supporto clienti.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field">
                    <label for="alma-support-whatsapp"><strong><?php esc_html_e('Whatsapp', 'affiliate-link-manager-ai'); ?></strong></label>
                    <input type="text" id="alma-support-whatsapp" name="support_whatsapp" value="<?php echo isset($settings['support_whatsapp']) ? esc_attr($settings['support_whatsapp']) : ''; ?>" placeholder="<?php esc_attr_e('Es: +39 012 345 6789', 'affiliate-link-manager-ai'); ?>" />
                    <p class="description"><?php esc_html_e('Numero Whatsapp per il supporto clienti.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <h2><?php esc_html_e('Regole Comportamentali', 'affiliate-link-manager-ai'); ?></h2>

                <div class="alma-field">
                    <label for="alma-response-length"><strong><?php esc_html_e('Lunghezza Risposta', 'affiliate-link-manager-ai'); ?></strong></label>
                    <?php $resp_len = $settings['response_length'] ?? ''; ?>
                    <select name="response_length" id="alma-response-length">
                        <option value="brevi" <?php selected($resp_len, 'brevi'); ?>><?php esc_html_e('brevi', 'affiliate-link-manager-ai'); ?></option>
                        <option value="medie" <?php selected($resp_len, 'medie'); ?>><?php esc_html_e('medie', 'affiliate-link-manager-ai'); ?></option>
                        <option value="dettagliate" <?php selected($resp_len, 'dettagliate'); ?>><?php esc_html_e('dettagliate', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Determina la lunghezza delle risposte generate.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field alma-required">
                    <label for="alma-primary-language"><strong><?php esc_html_e('Lingua Principale', 'affiliate-link-manager-ai'); ?></strong><span class="required">*</span></label>
                    <?php $lang = $settings['primary_language'] ?? ''; ?>
                    <select name="primary_language" id="alma-primary-language" required>
                        <option value="italiano" <?php selected($lang, 'italiano'); ?>><?php esc_html_e('italiano', 'affiliate-link-manager-ai'); ?></option>
                        <option value="inglese" <?php selected($lang, 'inglese'); ?>><?php esc_html_e('inglese', 'affiliate-link-manager-ai'); ?></option>
                        <option value="auto" <?php selected($lang, 'auto'); ?>><?php esc_html_e('rileva la lingua dell\'utente', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Lingua in cui l\'AI risponde di default.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <div class="alma-field">
                    <label for="alma-off-topic"><strong><?php esc_html_e('Richieste Off Topic', 'affiliate-link-manager-ai'); ?></strong></label>
                    <?php $off = $settings['off_topic'] ?? ''; ?>
                    <select name="off_topic" id="alma-off-topic">
                        <option value="rifiuta" <?php selected($off, 'rifiuta'); ?>><?php esc_html_e('Rifiuta educatamente', 'affiliate-link-manager-ai'); ?></option>
                        <option value="default" <?php selected($off, 'default'); ?>><?php esc_html_e('Risposta di default', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Comportamento dell\'AI per domande fuori contesto.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <h2><?php esc_html_e('Contesti Specifici', 'affiliate-link-manager-ai'); ?></h2>
                <p class="description"><?php esc_html_e('Definisci istruzioni personalizzate per diversi scenari.', 'affiliate-link-manager-ai'); ?></p>
                <h3 class="nav-tab-wrapper">
                    <?php $contexts = $settings['contexts'] ?? array(); ?>
                    <a href="#" class="nav-tab nav-tab-active" data-context="customer_service"><?php esc_html_e('Customer Service', 'affiliate-link-manager-ai'); ?></a>
                    <a href="#" class="nav-tab" data-context="sales"><?php esc_html_e('Vendite', 'affiliate-link-manager-ai'); ?></a>
                    <a href="#" class="nav-tab" data-context="support"><?php esc_html_e('Supporto Tecnico', 'affiliate-link-manager-ai'); ?></a>
                    <a href="#" class="nav-tab" data-context="general"><?php esc_html_e('Informazioni Generali', 'affiliate-link-manager-ai'); ?></a>
                </h3>
                <div class="alma-context" id="context-customer_service">
                    <textarea name="contexts[customer_service]" rows="6" cols="80" placeholder="<?php esc_attr_e('Es: Rispondi con tono empatico e orientato alla soluzione.', 'affiliate-link-manager-ai'); ?>"><?php echo isset($contexts['customer_service']) ? esc_textarea($contexts['customer_service']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Indicazioni per richieste di assistenza clienti.', 'affiliate-link-manager-ai'); ?></p>
                </div>
                <div class="alma-context" id="context-sales" style="display:none;">
                    <textarea name="contexts[sales]" rows="6" cols="80" placeholder="<?php esc_attr_e('Es: Suggerisci prodotti e promozioni rilevanti.', 'affiliate-link-manager-ai'); ?>"><?php echo isset($contexts['sales']) ? esc_textarea($contexts['sales']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Indicazioni per conversazioni orientate alla vendita.', 'affiliate-link-manager-ai'); ?></p>
                </div>
                <div class="alma-context" id="context-support" style="display:none;">
                    <textarea name="contexts[support]" rows="6" cols="80" placeholder="<?php esc_attr_e('Es: Fornisci istruzioni tecniche dettagliate.', 'affiliate-link-manager-ai'); ?>"><?php echo isset($contexts['support']) ? esc_textarea($contexts['support']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Indicazioni per supporto tecnico.', 'affiliate-link-manager-ai'); ?></p>
                </div>
                <div class="alma-context" id="context-general" style="display:none;">
                    <textarea name="contexts[general]" rows="6" cols="80" placeholder="<?php esc_attr_e('Es: Fornisci informazioni generali sull\'azienda.', 'affiliate-link-manager-ai'); ?>"><?php echo isset($contexts['general']) ? esc_textarea($contexts['general']) : ''; ?></textarea>
                    <p class="description"><?php esc_html_e('Indicazioni generali.', 'affiliate-link-manager-ai'); ?></p>
                </div>

                <h2><?php esc_html_e('Prompt Personalizzati', 'affiliate-link-manager-ai'); ?></h2>
                <p class="description"><?php esc_html_e('Crea prompt aggiuntivi con nome e testo.', 'affiliate-link-manager-ai'); ?></p>
                <div id="alma-custom-prompts">
                    <?php if (!empty($settings['custom_prompts']) && is_array($settings['custom_prompts'])) :
                        foreach ($settings['custom_prompts'] as $index => $cp) : ?>
                            <div class="alma-custom-prompt">
                                <input type="text" name="custom_prompt_names[]" value="<?php echo esc_attr($cp['name']); ?>" placeholder="<?php esc_attr_e('Nome', 'affiliate-link-manager-ai'); ?>" />
                                <textarea name="custom_prompt_texts[]" rows="4" cols="60" placeholder="<?php esc_attr_e('Prompt', 'affiliate-link-manager-ai'); ?>"><?php echo esc_textarea($cp['text']); ?></textarea>
                                <button class="button remove-custom-prompt"><?php esc_html_e('Elimina', 'affiliate-link-manager-ai'); ?></button>
                            </div>
                        <?php endforeach; endif; ?>
                </div>
                <p><button id="alma-add-custom-prompt" class="button"><?php esc_html_e('Aggiungi Prompt', 'affiliate-link-manager-ai'); ?></button></p>

                <p><button class="button button-primary" id="alma-save-settings"><?php esc_html_e('Salva Impostazioni', 'affiliate-link-manager-ai'); ?></button></p>
            </form>

            <hr />
            <h2><?php esc_html_e('Test Prompt', 'affiliate-link-manager-ai'); ?></h2>
            <form id="alma-test-form">
                <div class="alma-field">
                    <label for="alma-test-message"><strong><?php esc_html_e('Messaggio di test', 'affiliate-link-manager-ai'); ?></strong></label>
                    <input type="text" name="test_message" id="alma-test-message" placeholder="<?php esc_attr_e('Es: Come posso tracciare il mio ordine?', 'affiliate-link-manager-ai'); ?>" size="80" />
                    <p class="description"><?php esc_html_e('Inserisci un messaggio per simulare la risposta dell\'AI.', 'affiliate-link-manager-ai'); ?></p>
                </div>
                <div class="alma-field">
                    <label for="alma-test-context"><strong><?php esc_html_e('Contesto', 'affiliate-link-manager-ai'); ?></strong></label>
                    <select name="test_context" id="alma-test-context">
                        <option value="customer_service"><?php esc_html_e('Customer Service', 'affiliate-link-manager-ai'); ?></option>
                        <option value="sales"><?php esc_html_e('Vendite', 'affiliate-link-manager-ai'); ?></option>
                        <option value="support"><?php esc_html_e('Supporto Tecnico', 'affiliate-link-manager-ai'); ?></option>
                        <option value="general"><?php esc_html_e('Informazioni Generali', 'affiliate-link-manager-ai'); ?></option>
                        <?php if (!empty($settings['custom_prompts'])) :
                            foreach ($settings['custom_prompts'] as $cp) : ?>
                                <option value="custom_<?php echo esc_attr($cp['name']); ?>"><?php echo esc_html($cp['name']); ?></option>
                            <?php endforeach; endif; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Scegli il contesto da utilizzare nel test.', 'affiliate-link-manager-ai'); ?></p>
                </div>
                <p><button class="button" id="alma-test-claude"><?php esc_html_e('Testa comportamento AI', 'affiliate-link-manager-ai'); ?></button></p>
            </form>
            <div id="alma-test-result" style="display:none;">
                <h3><?php esc_html_e('Risposta Claude', 'affiliate-link-manager-ai'); ?></h3>
                <div id="alma-claude-response"></div>
                <div id="alma-affiliate-links"></div>
                <details>
                    <summary><?php esc_html_e('Prompt Finale', 'affiliate-link-manager-ai'); ?></summary>
                    <pre id="alma-final-prompt"></pre>
                </details>
            </div>
        </div>
        <?php
    }

    /**
     * Salvataggio impostazioni via AJAX
     */
    public function ajax_save_settings() {
        check_ajax_referer('alma_prompt_ai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti', 'affiliate-link-manager-ai'));
        }

        $settings = array();
        $settings['base_prompt'] = isset($_POST['base_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['base_prompt'])) : '';
        $settings['personality'] = isset($_POST['personality']) ? sanitize_text_field(wp_unslash($_POST['personality'])) : '';
        $settings['personality_custom'] = isset($_POST['personality_custom']) ? sanitize_textarea_field(wp_unslash($_POST['personality_custom'])) : '';
        $settings['company_name'] = isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : '';
        $settings['company_sector'] = isset($_POST['company_sector']) ? sanitize_text_field(wp_unslash($_POST['company_sector'])) : '';
        $settings['support_hours'] = isset($_POST['support_hours']) ? sanitize_text_field(wp_unslash($_POST['support_hours'])) : '';
        $settings['support_email'] = isset($_POST['support_email']) ? sanitize_email(wp_unslash($_POST['support_email'])) : '';
        $settings['support_phone'] = isset($_POST['support_phone']) ? sanitize_text_field(wp_unslash($_POST['support_phone'])) : '';
        $settings['support_whatsapp'] = isset($_POST['support_whatsapp']) ? sanitize_text_field(wp_unslash($_POST['support_whatsapp'])) : '';
        $settings['response_length'] = isset($_POST['response_length']) ? sanitize_text_field(wp_unslash($_POST['response_length'])) : '';
        $settings['primary_language'] = isset($_POST['primary_language']) ? sanitize_text_field(wp_unslash($_POST['primary_language'])) : '';
        $settings['off_topic'] = isset($_POST['off_topic']) ? sanitize_text_field(wp_unslash($_POST['off_topic'])) : '';

        $settings['contexts'] = array();
        if (isset($_POST['contexts']) && is_array($_POST['contexts'])) {
            foreach ($_POST['contexts'] as $key => $val) {
                $settings['contexts'][sanitize_key($key)] = sanitize_textarea_field(wp_unslash($val));
            }
        }

        $settings['custom_prompts'] = array();
        if (!empty($_POST['custom_prompt_names']) && is_array($_POST['custom_prompt_names'])) {
            $names = array_map('sanitize_text_field', wp_unslash($_POST['custom_prompt_names']));
            $texts = isset($_POST['custom_prompt_texts']) ? array_map('sanitize_textarea_field', wp_unslash($_POST['custom_prompt_texts'])) : array();
            foreach ($names as $i => $name) {
                if ($name === '' && empty($texts[$i])) {
                    continue;
                }
                $settings['custom_prompts'][] = array(
                    'name' => $name,
                    'text' => $texts[$i] ?? ''
                );
            }
        }

        if (empty($settings['base_prompt']) || empty($settings['company_name'])) {
            wp_send_json_error(__('Campi obbligatori mancanti', 'affiliate-link-manager-ai'));
        }

        update_option(self::OPTION_NAME, $settings);
        wp_send_json_success(__('Impostazioni salvate', 'affiliate-link-manager-ai'));
    }

    /**
     * Test del prompt via AJAX
     */
    public function ajax_test_prompt() {
        check_ajax_referer('alma_prompt_ai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti', 'affiliate-link-manager-ai'));
        }

        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        $context = isset($_POST['context']) ? sanitize_text_field(wp_unslash($_POST['context'])) : 'general';

        $final_prompt = self::build_prompt($message, $context);
        $response     = $this->call_claude_api($final_prompt);

        if (empty($response['success'])) {
            wp_send_json_error($response['error'] ?? __('Errore AI', 'affiliate-link-manager-ai'));
        }

        $links = $this->find_affiliate_links($message);
        if (is_wp_error($links)) {
            wp_send_json_error($links->get_error_message());
        }

        wp_send_json_success(array(
            'response' => $response['response'],
            'prompt'   => $final_prompt,
            'links'    => $links,
        ));
    }

    /**
     * Costruisce il prompt finale
     */
    public static function build_prompt($message, $context) {
        $settings = get_option(self::OPTION_NAME, array());
        $parts = array();
        if (!empty($settings['base_prompt'])) {
            $parts[] = $settings['base_prompt'];
        }
        switch ($settings['personality'] ?? '') {
            case 'professionale':
                $parts[] = 'Usa un tono formale e cortese.';
                break;
            case 'amichevole':
                $parts[] = 'Usa un tono informale e caloroso.';
                break;
            case 'tecnico':
                $parts[] = 'Usa un linguaggio tecnico, preciso e dettagliato.';
                break;
            case 'commerciale':
                $parts[] = 'Usa uno stile persuasivo orientato alle vendite.';
                break;
            case 'personalizzato':
                if (!empty($settings['personality_custom'])) {
                    $parts[] = $settings['personality_custom'];
                }
                break;
        }

        $company = array();
        if (!empty($settings['company_name'])) {
            $company[] = 'Nome azienda: ' . $settings['company_name'];
        }
        if (!empty($settings['company_sector'])) {
            $company[] = 'Settore: ' . $settings['company_sector'];
        }
        if (!empty($settings['support_hours'])) {
            $company[] = 'Orari supporto: ' . $settings['support_hours'];
        }
        if (!empty($settings['support_email'])) {
            $company[] = 'Email: ' . $settings['support_email'];
        }
        if (!empty($settings['support_phone'])) {
            $company[] = 'Telefono: ' . $settings['support_phone'];
        }
        if (!empty($settings['support_whatsapp'])) {
            $company[] = 'Whatsapp: ' . $settings['support_whatsapp'];
        }
        if ($company) {
            $parts[] = 'Informazioni azienda: ' . implode(', ', $company) . '.';
        }

        if (!empty($settings['response_length'])) {
            $parts[] = 'Lunghezza risposte: ' . $settings['response_length'] . '.';
        }
        if (!empty($settings['primary_language'])) {
            $parts[] = 'Lingua principale: ' . $settings['primary_language'] . '.';
        }
        if (!empty($settings['off_topic'])) {
            if ($settings['off_topic'] === 'rifiuta') {
                $parts[] = 'Se la domanda è fuori topic rifiuta educatamente.';
            } else {
                $parts[] = 'Se la domanda è fuori topic usa una risposta di default.';
            }
        }

        $contexts = $settings['contexts'] ?? array();
        if (strpos($context, 'custom_') === 0) {
            $name = substr($context, 7);
            if (!empty($settings['custom_prompts'])) {
                foreach ($settings['custom_prompts'] as $cp) {
                    if ($cp['name'] === $name) {
                        $parts[] = $cp['text'];
                    }
                }
            }
        } elseif (!empty($contexts[$context])) {
            $parts[] = $contexts[$context];
        }

        $parts[] = 'Informazioni sito: nome ' . get_bloginfo('name') . ', URL ' . home_url() . ', descrizione ' . get_bloginfo('description') . ', data ' . current_time('Y-m-d') . '.';
        if ($message) {
            $parts[] = 'Messaggio utente: ' . $message;
        }
        return implode("\n\n", array_filter($parts));
    }

    /**
     * Chiamata a Claude API
     */
    private function call_claude_api($prompt) {
        $api_key = get_option('alma_claude_api_key');
        if (empty($api_key)) {
            return array('success' => false, 'error' => 'API Key non configurata');
        }

        $model       = get_option('alma_claude_model', 'claude-3-haiku-20240307');
        $temperature = (float) get_option('alma_claude_temperature', 0.7);

        $body = array(
            'model'       => $model,
            'max_tokens'  => 300,
            'temperature' => $temperature,
            'messages'    => array(
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $prompt,
                        )
                    )
                )
            )
        );

        $response = wp_safe_remote_post('https://api.anthropic.com/v1/messages', array(
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== $code || empty($data['content'][0]['text'])) {
            return array('success' => false, 'error' => 'Risposta non valida da Claude');
        }
        return array(
            'success'  => true,
            'response' => $data['content'][0]['text'],
        );
    }

    private function find_affiliate_links($query) {
        $links = get_posts(array(
            'post_type'   => 'affiliate_link',
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ));

        if (empty($links)) {
            return array('summary' => '', 'results' => array());
        }

        $max_results = intval(get_option('alma_chat_max_results', 5));

        $message = "Richiesta utente: {$query}\n\nLinks disponibili:\n";
        foreach ($links as $link) {
            $terms = get_the_terms($link->ID, 'link_type');
            $types = array();
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $types[] = $term->name;
                }
            }
            $message .= 'ID ' . $link->ID . ': ' . $link->post_title;
            if ($types) {
                $message .= ' [' . implode(', ', $types) . ']';
            }
            $message .= "\n";
        }
        $message .= "\nRispondi esclusivamente con un oggetto JSON con i campi \"summary\" e \"results\". \"summary\" deve contenere una breve frase in italiano che spiega perché hai scelto i link. \"results\" è un array con massimo {$max_results} oggetti{\"id\":ID,\"description\":\"testo\",\"score\":COERENZA} dove COERENZA è 0-100. Non includere testo fuori dal JSON.\n";

        $prompt   = self::build_prompt($message, 'search');
        $response = $this->call_claude_api($prompt);

        if (empty($response['success'])) {
            return new \WP_Error('claude_error', $response['error'] ?? __('Errore AI', 'affiliate-link-manager-ai'));
        }

        $clean = $this->extract_first_json($response['response']);
        $items = json_decode($clean, true);
        if (!is_array($items) || !isset($items['results']) || !is_array($items['results'])) {
            return new \WP_Error('claude_parse_error', __('Risposta non valida da Claude', 'affiliate-link-manager-ai'));
        }

        $summary = sanitize_text_field($items['summary']);
        $results = array();
        foreach (array_slice($items['results'], 0, $max_results) as $item) {
            if (!isset($item['id'])) {
                continue;
            }
            $id          = intval($item['id']);
            $description = isset($item['description']) ? wp_strip_all_tags($item['description']) : '';
            $score       = isset($item['score']) ? floatval($item['score']) : 0;

            $post = get_post($id);
            if (!$post || $post->post_type !== 'affiliate_link') {
                continue;
            }

            $affiliate_url = get_post_meta($id, '_affiliate_url', true);
            $results[]     = array(
                'title'       => get_the_title($id),
                'url'         => $affiliate_url,
                'description' => $description,
                'score'       => max(0, min(100, round($score))),
            );
        }

        return array(
            'summary' => $summary,
            'results' => $results,
        );
    }

    private function extract_first_json($text) {
        $text = preg_replace('/```json\s*(.+?)\s*```/is', '$1', $text);
        $text = preg_replace('/```\s*(.+?)\s*```/is', '$1', $text);

        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            if ($char !== '{' && $char !== '[') {
                continue;
            }

            $open      = $char;
            $close     = $char === '{' ? '}' : ']';
            $depth     = 0;
            $in_string = false;
            $escape    = false;

            for ($j = $i; $j < $len; $j++) {
                $c = $text[$j];

                if ($in_string) {
                    if ($c === '\\' && !$escape) {
                        $escape = true;
                        continue;
                    }
                    if ($c === '"' && !$escape) {
                        $in_string = false;
                    }
                    $escape = false;
                    continue;
                }

                if ($c === '"') {
                    $in_string = true;
                    continue;
                }

                if ($c === $open) {
                    $depth++;
                } elseif ($c === $close) {
                    $depth--;
                    if ($depth === 0) {
                        return substr($text, $i, $j - $i + 1);
                    }
                }
            }
        }

        return '';
    }
}
