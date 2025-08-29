<?php
/**
 * Template: Content Analyzer Settings Section
 * Sezione impostazioni per Content Analyzer (opzionale)
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Ottieni impostazioni attuali
$settings = alma_get_analyzer_settings();
$enabled = $settings['enabled'];
$max_suggestions = $settings['max_suggestions'];
$relevance_threshold = $settings['relevance_threshold'];
$auto_analyze = $settings['auto_analyze'];
$post_types = $settings['post_types'];

// Salva impostazioni se form inviato
if (isset($_POST['alma_save_analyzer_settings'])) {
    check_admin_referer('alma_analyzer_settings', 'alma_analyzer_nonce');
    
    $new_settings = array(
        'alma_analyzer_enabled' => isset($_POST['analyzer_enabled']) ? 1 : 0,
        'alma_analyzer_max_suggestions' => intval($_POST['max_suggestions']),
        'alma_analyzer_relevance_threshold' => intval($_POST['relevance_threshold']),
        'alma_analyzer_auto_analyze' => isset($_POST['auto_analyze']) ? 1 : 0,
        'alma_analyzer_post_types' => isset($_POST['post_types']) ? $_POST['post_types'] : array('post')
    );
    
    foreach ($new_settings as $option => $value) {
        update_option($option, $value);
    }
    
    echo '<div class="notice notice-success is-dismissible"><p>' . __('Impostazioni Content Analyzer salvate!', 'affiliate-link-manager-ai') . '</p></div>';
    
    // Ricarica impostazioni
    $settings = alma_get_analyzer_settings();
    extract($settings);
}
?>

<div class="alma-analyzer-settings">
    <h2>🤖 <?php _e('Content Analyzer Settings', 'affiliate-link-manager-ai'); ?></h2>
    
    <form method="post" action="">
        <?php wp_nonce_field('alma_analyzer_settings', 'alma_analyzer_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Abilita Content Analyzer', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="analyzer_enabled" value="1" <?php checked($enabled, 1); ?>>
                            <?php _e('Attiva analisi AI del contenuto e suggerimenti automatici', 'affiliate-link-manager-ai'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Disabilita per nascondere il widget Content Analyzer dall\'editor.', 'affiliate-link-manager-ai'); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Suggerimenti Massimi', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <select name="max_suggestions">
                        <option value="3" <?php selected($max_suggestions, 3); ?>>3 <?php _e('suggerimenti', 'affiliate-link-manager-ai'); ?></option>
                        <option value="5" <?php selected($max_suggestions, 5); ?>>5 <?php _e('suggerimenti', 'affiliate-link-manager-ai'); ?></option>
                        <option value="7" <?php selected($max_suggestions, 7); ?>>7 <?php _e('suggerimenti', 'affiliate-link-manager-ai'); ?></option>
                        <option value="10" <?php selected($max_suggestions, 10); ?>>10 <?php _e('suggerimenti', 'affiliate-link-manager-ai'); ?></option>
                    </select>
                    <p class="description">
                        <?php _e('Numero massimo di link suggeriti per ogni analisi.', 'affiliate-link-manager-ai'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Soglia Rilevanza', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <input type="range" name="relevance_threshold" value="<?php echo $relevance_threshold; ?>" 
                           min="30" max="90" step="5" style="width: 200px;" 
                           oninput="this.nextElementSibling.value = this.value + '%'">
                    <output><?php echo $relevance_threshold; ?>%</output>
                    <p class="description">
                        <?php _e('Solo link con rilevanza superiore a questa soglia verranno suggeriti.', 'affiliate-link-manager-ai'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Analisi Automatica', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="auto_analyze" value="1" <?php checked($auto_analyze, 1); ?>>
                            <?php _e('Analizza automaticamente il contenuto mentre scrivi', 'affiliate-link-manager-ai'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Se disabilitato, dovrai cliccare manualmente "Analizza Contenuto".', 'affiliate-link-manager-ai'); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Tipi di Post', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <fieldset>
                        <?php 
                        $available_post_types = get_post_types(array('public' => true), 'objects');
                        foreach ($available_post_types as $post_type): 
                            if (in_array($post_type->name, array('attachment'))) continue;
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="post_types[]" value="<?php echo $post_type->name; ?>" 
                                       <?php checked(in_array($post_type->name, $post_types)); ?>>
                                <?php echo $post_type->labels->name; ?> (<?php echo $post_type->name; ?>)
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php _e('Seleziona i tipi di post dove mostrare il Content Analyzer.', 'affiliate-link-manager-ai'); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('🧠 Integrazione AI', 'affiliate-link-manager-ai'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Claude API Status', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <?php 
                    $claude_api_key = get_option('alma_claude_api_key');
                    if (!empty($claude_api_key)): 
                    ?>
                        <span style="color: #00a32a; font-weight: 600;">✅ <?php _e('Configurata', 'affiliate-link-manager-ai'); ?></span>
                        <p class="description">
                            <?php _e('Il Content Analyzer utilizzerà Claude AI per analisi più precise.', 'affiliate-link-manager-ai'); ?>
                        </p>
                    <?php else: ?>
                        <span style="color: #d63638; font-weight: 600;">❌ <?php _e('Non configurata', 'affiliate-link-manager-ai'); ?></span>
                        <p class="description">
                            <?php _e('Verrà utilizzato l\'algoritmo interno. ', 'affiliate-link-manager-ai'); ?>
                            <a href="<?php echo admin_url('admin.php?page=affiliate-ai-settings&tab=claude'); ?>">
                                <?php _e('Configura Claude API', 'affiliate-link-manager-ai'); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <h3><?php _e('📊 Statistiche Sistema', 'affiliate-link-manager-ai'); ?></h3>
        <?php
        // Ottieni statistiche uso
        $widget_usage = get_option('alma_content_analyzer_widget_usage', 0);
        $suggestion_log = get_option('alma_suggestion_usage_log', array());
        $total_suggestions = count($suggestion_log);
        $inserted_suggestions = count(array_filter($suggestion_log, function($log) { 
            return $log['action'] === 'inserted'; 
        }));
        $available_links = wp_count_posts('affiliate_link')->publish;
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Utilizzo Widget', 'affiliate-link-manager-ai'); ?></th>
                <td><strong><?php echo $widget_usage; ?></strong> <?php _e('volte caricato', 'affiliate-link-manager-ai'); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Suggerimenti Totali', 'affiliate-link-manager-ai'); ?></th>
                <td><strong><?php echo $total_suggestions; ?></strong> <?php _e('generati', 'affiliate-link-manager-ai'); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Tasso Inserimento', 'affiliate-link-manager-ai'); ?></th>
                <td>
                    <strong>
                        <?php echo $total_suggestions > 0 ? round(($inserted_suggestions / $total_suggestions) * 100, 1) : 0; ?>%
                    </strong>
                    (<?php echo $inserted_suggestions; ?> / <?php echo $total_suggestions; ?>)
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Link Disponibili', 'affiliate-link-manager-ai'); ?></th>
                <td><strong><?php echo $available_links; ?></strong> <?php _e('link affiliati pubblicati', 'affiliate-link-manager-ai'); ?></td>
            </tr>
        </table>
        
        <?php submit_button(__('Salva Impostazioni Content Analyzer', 'affiliate-link-manager-ai'), 'primary', 'alma_save_analyzer_settings'); ?>
    </form>
    
    <!-- Test Section -->
    <div class="alma-test-section" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
        <h3><?php _e('🧪 Test Funzionalità', 'affiliate-link-manager-ai'); ?></h3>
        <p><?php _e('Verifica che il Content Analyzer funzioni correttamente:', 'affiliate-link-manager-ai'); ?></p>
        
        <ol>
            <li><?php _e('Vai a creare/modificare un post o pagina', 'affiliate-link-manager-ai'); ?></li>
            <li><?php _e('Cerca il widget "🤖 AI Content Analyzer" nella sidebar destra', 'affiliate-link-manager-ai'); ?></li>
            <li><?php _e('Scrivi del contenuto (minimo 100 caratteri)', 'affiliate-link-manager-ai'); ?></li>
            <li><?php _e('Clicca "Analizza Contenuto"', 'affiliate-link-manager-ai'); ?></li>
            <li><?php _e('Verifica che appaiano i suggerimenti AI', 'affiliate-link-manager-ai'); ?></li>
        </ol>
        
        <p>
            <a href="<?php echo admin_url('post-new.php'); ?>" class="button button-secondary" target="_blank">
                📝 <?php _e('Test su Nuovo Post', 'affiliate-link-manager-ai'); ?>
            </a>
            
            <a href="<?php echo admin_url('admin.php?page=affiliate-ai-analytics'); ?>" class="button button-secondary">
                📊 <?php _e('Vai ad Analytics', 'affiliate-link-manager-ai'); ?>
            </a>
        </p>
    </div>
    
    <!-- Debug Info -->
    <?php if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')): ?>
    <div class="alma-debug-section" style="margin-top: 20px; padding: 15px; background: #f0f0f0; border-radius: 6px; font-family: monospace; font-size: 12px;">
        <h4><?php _e('🔧 Debug Info', 'affiliate-link-manager-ai'); ?></h4>
        <ul>
            <li><strong>Content Analyzer Version:</strong> 1.3.0</li>
            <li><strong>WordPress:</strong> <?php echo get_bloginfo('version'); ?></li>
            <li><strong>PHP:</strong> <?php echo PHP_VERSION; ?></li>
            <li><strong>Plugin Directory:</strong> <?php echo ALMA_PLUGIN_DIR; ?></li>
            <li><strong>Files Status:</strong>
                <ul style="margin-left: 20px;">
                    <li>content-analyzer.php: <?php echo file_exists(ALMA_PLUGIN_DIR . 'content-analyzer/content-analyzer.php') ? '✅' : '❌'; ?></li>
                    <li>ai-content-analysis.js: <?php echo file_exists(ALMA_PLUGIN_DIR . 'content-analyzer/ai-content-analysis.js') ? '✅' : '❌'; ?></li>
                    <li>content-analyzer.css: <?php echo file_exists(ALMA_PLUGIN_DIR . 'content-analyzer/content-analyzer.css') ? '✅' : '❌'; ?></li>
                    <li>suggestion-widget.php: <?php echo file_exists(ALMA_PLUGIN_DIR . 'content-analyzer/templates/suggestion-widget.php') ? '✅' : '❌'; ?></li>
                </ul>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<style>
.alma-analyzer-settings .form-table th {
    width: 200px;
    vertical-align: top;
    padding-top: 20px;
}

.alma-analyzer-settings .form-table td {
    padding-top: 15px;
}

.alma-analyzer-settings fieldset label {
    margin-bottom: 8px;
    display: block;
}

.alma-test-section ol {
    margin-left: 20px;
}

.alma-test-section li {
    margin-bottom: 5px;
}

.alma-debug-section ul {
    margin: 10px 0;
}

.alma-debug-section li {
    margin-bottom: 3px;
}
</style>