<?php
/**
 * Template: Content Analyzer Widget
 * Mostra il widget per l'analisi del contenuto e suggerimenti AI
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Ottieni impostazioni
$max_suggestions = get_option('alma_analyzer_max_suggestions', 5);
$relevance_threshold = get_option('alma_analyzer_relevance_threshold', 60);
$auto_analyze = get_option('alma_analyzer_auto_analyze', 1);
$post_types = get_option('alma_analyzer_post_types', array('post', 'page'));

// Verifica se abbiamo link affiliati disponibili
$available_links_count = wp_count_posts('affiliate_link')->publish;

// Check se Claude API è configurata
$claude_api_key = get_option('alma_claude_api_key');
$has_claude = !empty($claude_api_key);

// Security nonce
wp_nonce_field('alma_content_analyzer', 'alma_content_analyzer_nonce');
?>

<div id="alma-content-analyzer" class="alma-fade-in">
    <!-- Header del Widget -->
    <div class="alma-analyzer-header">
        <h4>
            🤖 AI Content Analyzer
            <span class="alma-version">v1.3.0</span>
        </h4>
        <div class="alma-analyzer-status"></div>
    </div>
    
    <div class="alma-analyzer-body">
        
        <!-- Panel di Controllo Rapido -->
        <div class="alma-control-panel">
            <div class="alma-control-row">
                <button type="button" id="alma-analyze-content-btn" class="button button-primary">
                    🤖 <?php _e('Analizza Contenuto', 'affiliate-link-manager-ai'); ?>
                </button>
            </div>
            
            <div class="alma-control-row">
                <div class="alma-control-group">
                    <label for="alma-max-suggestions">
                        <?php _e('Max suggerimenti:', 'affiliate-link-manager-ai'); ?>
                    </label>
                    <select id="alma-max-suggestions">
                        <option value="3" <?php selected($max_suggestions, 3); ?>>3</option>
                        <option value="5" <?php selected($max_suggestions, 5); ?>>5</option>
                        <option value="7" <?php selected($max_suggestions, 7); ?>>7</option>
                        <option value="10" <?php selected($max_suggestions, 10); ?>>10</option>
                    </select>
                </div>
                
                <button type="button" id="alma-refresh-suggestions" class="button" disabled>
                    🔄 <?php _e('Refresh', 'affiliate-link-manager-ai'); ?>
                </button>
            </div>
            
            <!-- Info Stato Sistema -->
            <div class="alma-system-info">
                <div class="alma-info-row">
                    <span class="alma-info-label">
                        <?php _e('Link disponibili:', 'affiliate-link-manager-ai'); ?>
                    </span>
                    <span class="alma-info-value" id="alma-available-links-count">
                        <?php echo $available_links_count; ?>
                    </span>
                </div>
                
                <div class="alma-info-row">
                    <span class="alma-info-label">
                        <?php _e('AI Engine:', 'affiliate-link-manager-ai'); ?>
                    </span>
                    <span class="alma-info-value">
                        <?php if ($has_claude): ?>
                            🧠 Claude AI
                        <?php else: ?>
                            🤖 Algoritmi Interni
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Avvisi e Suggerimenti Quick -->
        <?php if ($available_links_count === 0): ?>
            <div class="alma-notice alma-notice-warning">
                <p>
                    <strong>⚠️ <?php _e('Nessun link affiliato trovato', 'affiliate-link-manager-ai'); ?></strong>
                </p>
                <p>
                    <?php _e('Crea alcuni link affiliati per utilizzare l\'AI Content Analyzer.', 'affiliate-link-manager-ai'); ?>
                </p>
                <p>
                    <a href="<?php echo admin_url('post-new.php?post_type=affiliate_link'); ?>" 
                       class="button button-small" target="_blank">
                        ➕ <?php _e('Crea Link Affiliato', 'affiliate-link-manager-ai'); ?>
                    </a>
                </p>
            </div>
        <?php elseif (!$has_claude): ?>
            <div class="alma-notice alma-notice-info">
                <p>
                    <strong>💡 <?php _e('Suggerimento:', 'affiliate-link-manager-ai'); ?></strong>
                </p>
                <p>
                    <?php _e('Configura Claude AI per suggerimenti più precisi e intelligenti.', 'affiliate-link-manager-ai'); ?>
                </p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=affiliate-ai-settings&tab=claude'); ?>" 
                       class="button button-small" target="_blank">
                        🧠 <?php _e('Configura Claude', 'affiliate-link-manager-ai'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
        
        <!-- Sezione Risultati Analisi -->
        <div id="alma-analysis-results" class="alma-analysis-results" style="display: none;">
            <div class="alma-section-header">
                <h4>📊 <?php _e('Risultati Analisi', 'affiliate-link-manager-ai'); ?></h4>
            </div>
            
            <!-- I risultati verranno popolati via JavaScript -->
        </div>
        
        <!-- Sezione Suggerimenti -->
        <div class="alma-suggestions-section">
            <div class="alma-suggestions-header">
                <h4 class="alma-suggestions-title">
                    💡 <?php _e('Suggerimenti AI', 'affiliate-link-manager-ai'); ?>
                    <span class="alma-suggestions-count" id="alma-suggestions-count">0</span>
                </h4>
                
                <div class="alma-suggestions-controls">
                    <button type="button" id="alma-batch-insert" class="button button-small" 
                            style="display: none;" title="<?php _e('Inserisci tutti i suggerimenti', 'affiliate-link-manager-ai'); ?>">
                        📝 <?php _e('Batch', 'affiliate-link-manager-ai'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Lista Suggerimenti -->
            <div id="alma-suggestions-list">
                <!-- Stato iniziale -->
                <div class="alma-initial-state">
                    <div class="alma-initial-icon">🤖</div>
                    <p><strong><?php _e('Pronto per l\'analisi!', 'affiliate-link-manager-ai'); ?></strong></p>
                    <p><?php _e('Clicca "Analizza Contenuto" per ottenere suggerimenti AI personalizzati basati sul tuo testo.', 'affiliate-link-manager-ai'); ?></p>
                    
                    <?php if ($auto_analyze): ?>
                        <p>
                            <small>
                                💡 <?php _e('L\'analisi automatica è attiva - i suggerimenti appariranno mentre scrivi!', 'affiliate-link-manager-ai'); ?>
                            </small>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Sezione Analytics Rapide (se necessario) -->
        <div class="alma-quick-analytics" style="display: none;">
            <div class="alma-section-header">
                <h4>📈 <?php _e('Quick Analytics', 'affiliate-link-manager-ai'); ?></h4>
            </div>
            
            <div class="alma-analytics-grid">
                <div class="alma-analytics-item">
                    <span class="alma-analytics-value" id="alma-session-analyses">0</span>
                    <span class="alma-analytics-label"><?php _e('Analisi', 'affiliate-link-manager-ai'); ?></span>
                </div>
                
                <div class="alma-analytics-item">
                    <span class="alma-analytics-value" id="alma-session-suggestions">0</span>
                    <span class="alma-analytics-label"><?php _e('Suggerimenti', 'affiliate-link-manager-ai'); ?></span>
                </div>
                
                <div class="alma-analytics-item">
                    <span class="alma-analytics-value" id="alma-session-insertions">0</span>
                    <span class="alma-analytics-label"><?php _e('Inseriti', 'affiliate-link-manager-ai'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Sezione Help e Shortcuts -->
        <div class="alma-help-section">
            <details class="alma-help-details">
                <summary class="alma-help-summary">
                    ❓ <?php _e('Guida Rapida', 'affiliate-link-manager-ai'); ?>
                </summary>
                
                <div class="alma-help-content">
                    <h5><?php _e('🚀 Come usare l\'AI Analyzer:', 'affiliate-link-manager-ai'); ?></h5>
                    <ol class="alma-help-list">
                        <li><?php _e('Scrivi il tuo contenuto nell\'editor', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('Clicca "Analizza Contenuto" (o attendi l\'analisi automatica)', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('Rivedi i suggerimenti AI personalizzati', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('Clicca "Inserisci" sui link più rilevanti', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('I link vengono aggiunti automaticamente nel punto ottimale', 'affiliate-link-manager-ai'); ?></li>
                    </ol>
                    
                    <h5><?php _e('⌨️ Shortcuts:', 'affiliate-link-manager-ai'); ?></h5>
                    <ul class="alma-shortcuts-list">
                        <li><kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>A</kbd> = <?php _e('Analisi rapida', 'affiliate-link-manager-ai'); ?></li>
                    </ul>
                    
                    <h5><?php _e('💡 Tips:', 'affiliate-link-manager-ai'); ?></h5>
                    <ul class="alma-tips-list">
                        <li><?php _e('Più contenuto = suggerimenti più precisi', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('L\'AI impara dalle tue scelte per migliorare', 'affiliate-link-manager-ai'); ?></li>
                        <li><?php _e('Usa Claude AI per risultati superiori', 'affiliate-link-manager-ai'); ?></li>
                    </ul>
                    
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=affiliate-ai-analytics'); ?>" 
                           target="_blank" class="button button-small">
                            📊 <?php _e('Vai ad AI Analytics', 'affiliate-link-manager-ai'); ?>
                        </a>
                        
                        <a href="<?php echo admin_url('admin.php?page=affiliate-ai-settings'); ?>" 
                           target="_blank" class="button button-small">
                            ⚙️ <?php _e('Impostazioni', 'affiliate-link-manager-ai'); ?>
                        </a>
                    </p>
                </div>
            </details>
        </div>
    </div>
</div>

<!-- Stili aggiuntivi per il widget -->
<style>
/* Notice Styles */
.alma-notice {
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
    font-size: 13px;
    line-height: 1.4;
}

.alma-notice-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-left: 4px solid #dba617;
    color: #856404;
}

.alma-notice-info {
    background: #e7f3ff;
    border: 1px solid #b8daff;
    border-left: 4px solid #2271b1;
    color: #0c5460;
}

.alma-notice p {
    margin: 6px 0;
}

.alma-notice p:first-child {
    margin-top: 0;
}

.alma-notice p:last-child {
    margin-bottom: 0;
}

/* System Info */
.alma-system-info {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e0e0e0;
}

.alma-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    margin-bottom: 4px;
}

.alma-info-label {
    color: #646970;
}

.alma-info-value {
    font-weight: 600;
    color: #2271b1;
}

/* Initial State */
.alma-initial-state {
    text-align: center;
    padding: 30px 20px;
    background: #f9f9f9;
    border-radius: 8px;
    border: 2px dashed #ddd;
}

.alma-initial-icon {
    font-size: 36px;
    margin-bottom: 15px;
    opacity: 0.7;
}

.alma-initial-state p {
    color: #646970;
    margin: 8px 0;
    line-height: 1.5;
}

.alma-initial-state p:first-of-type {
    font-weight: 600;
    color: #1d2327;
}

/* Section Headers */
.alma-section-header {
    background: #f0f6fc;
    padding: 10px 15px;
    border-radius: 6px;
    border: 1px solid #2271b1;
    margin-bottom: 15px;
}

.alma-section-header h4 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #1d2327;
}

/* Quick Analytics */
.alma-analytics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-top: 10px;
}

.alma-analytics-item {
    text-align: center;
    background: white;
    padding: 10px;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
}

.alma-analytics-value {
    display: block;
    font-size: 18px;
    font-weight: 700;
    color: #2271b1;
    line-height: 1;
}

.alma-analytics-label {
    display: block;
    font-size: 10px;
    color: #646970;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

/* Help Section */
.alma-help-section {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
}

.alma-help-details {
    background: #f9f9f9;
    border-radius: 6px;
    overflow: hidden;
}

.alma-help-summary {
    padding: 10px 15px;
    background: #f0f0f1;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    color: #1d2327;
    border: none;
    outline: none;
    user-select: none;
    transition: background-color 0.2s;
}

.alma-help-summary:hover {
    background: #e8e8e8;
}

.alma-help-content {
    padding: 15px;
    font-size: 12px;
    line-height: 1.5;
}

.alma-help-content h5 {
    margin: 0 0 8px 0;
    font-size: 13px;
    color: #1d2327;
}

.alma-help-list,
.alma-shortcuts-list,
.alma-tips-list {
    margin: 8px 0 15px 0;
    padding-left: 20px;
}

.alma-help-list li,
.alma-shortcuts-list li,
.alma-tips-list li {
    margin-bottom: 4px;
    color: #646970;
}

.alma-shortcuts-list {
    list-style: none;
    padding-left: 0;
}

.alma-shortcuts-list li {
    display: flex;
    align-items: center;
    gap: 8px;
}

.alma-shortcuts-list kbd {
    background: #f1f1f1;
    border: 1px solid #ccc;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 10px;
    font-family: monospace;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

/* Responsive Adjustments per Widget */
@media (max-width: 1200px) {
    .alma-analytics-grid {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    
    .alma-analytics-item {
        padding: 8px;
    }
    
    .alma-analytics-value {
        font-size: 16px;
    }
}

@media (max-width: 768px) {
    .alma-initial-state {
        padding: 20px 15px;
    }
    
    .alma-initial-icon {
        font-size: 28px;
        margin-bottom: 10px;
    }
    
    .alma-help-content {
        padding: 12px;
    }
    
    .alma-system-info {
        font-size: 11px;
    }
}
</style>

<?php
// Log utilizzo widget per analytics
if (function_exists('update_option')) {
    $widget_usage_count = get_option('alma_content_analyzer_widget_usage', 0);
    update_option('alma_content_analyzer_widget_usage', $widget_usage_count + 1);
}
?>