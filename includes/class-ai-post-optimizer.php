<?php
/**
 * Fase 4 (PR 2) — Metabox "AI Affiliati" nell'editor del post.
 *
 * Due funzioni:
 * 1. DIAGNOSTICA: elenco degli shortcode affiliati presenti nel contenuto
 *    (pattern usato, validità del link, coerenza geografica con la località
 *    del post).
 * 2. OTTIMIZZAZIONE A PROPOSTE: "Proponi ottimizzazioni AI" chiede al modello
 *    dove inserire nuovi shortcode (anchor/bottone/card) usando i link
 *    geo-coerenti e le Regole inserimento; le proposte NON modificano nulla:
 *    vengono salvate in meta e l'editore le applica UNA A UNA. Ogni
 *    applicazione passa da wp_update_post → revision di WordPress (rollback
 *    nativo). Il pulsante di applicazione avvisa se l'editor ha modifiche
 *    non salvate (il contenuto lato server diventerebbe obsoleto).
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Post_Optimizer {
    const META_PROPOSALS = '_alma_ai_optimizer_proposals';
    const MAX_PROPOSALS = 6;
    const MAX_CANDIDATES = 12;

    public static function init() {
        add_action('add_meta_boxes_post', array(__CLASS__, 'register_metabox'));
        add_action('wp_ajax_alma_post_optimizer_propose', array(__CLASS__, 'ajax_propose'));
        add_action('wp_ajax_alma_post_optimizer_apply', array(__CLASS__, 'ajax_apply'));
        add_action('wp_ajax_alma_post_optimizer_reject', array(__CLASS__, 'ajax_reject'));
    }

    public static function register_metabox() {
        add_meta_box('alma-ai-affiliati', __('AI Affiliati', 'affiliate-link-manager-ai'), array(__CLASS__, 'render_metabox'), 'post', 'normal', 'low');
    }

    /* ---------------------------------------------------------------------
     * Diagnostica shortcode nel contenuto
     * ------------------------------------------------------------------ */

    public static function detect_pattern($shortcode) {
        if (strpos($shortcode, '[affiliate_links_widget') === 0) { return 'widget'; }
        if (preg_match('/\bimg="yes"/', $shortcode)) { return 'card'; }
        if (preg_match('/\bbutton="yes"/', $shortcode)) { return 'button'; }
        return 'anchor';
    }

    private static function pattern_label($pattern) {
        $labels = array('anchor' => 'Anchor nel testo', 'button' => 'Bottone CTA', 'card' => 'Card', 'widget' => 'Widget');
        return $labels[$pattern] ?? $pattern;
    }

    private static function geo_store() {
        return class_exists('ALMA_Geo_Index_Store') ? new ALMA_Geo_Index_Store() : null;
    }

    private static function object_location_ids($object_id, $object_type) {
        global $wpdb;
        $store = self::geo_store();
        if (!$store || !$store->tables_exist()) { return array(); }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT location_id FROM {$store->table_content_index()} WHERE object_type = %s AND object_id = %d",
            $object_type, absint($object_id)
        ));
        return array_values(array_filter(array_map('absint', (array)$ids)));
    }

    private static function location_country_codes($location_ids) {
        global $wpdb;
        $store = self::geo_store();
        if (!$store || empty($location_ids)) { return array(); }
        $placeholders = implode(',', array_fill(0, count($location_ids), '%d'));
        $codes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT country_code FROM {$store->table_locations()} WHERE id IN ($placeholders) AND country_code <> ''",
            $location_ids
        ));
        return array_values(array_filter(array_map('strval', (array)$codes)));
    }

    /**
     * Coerenza geografica link/post: stessa località, stesso paese, diversa,
     * o non determinabile.
     */
    private static function geo_coherence($post_location_ids, $link_id) {
        if (empty($post_location_ids)) { return array('label' => '—', 'ok' => null); }
        $link_locations = self::object_location_ids($link_id, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
        if (empty($link_locations)) { return array('label' => __('Link senza località', 'affiliate-link-manager-ai'), 'ok' => null); }
        if (!empty(array_intersect($post_location_ids, $link_locations))) { return array('label' => __('Stessa località', 'affiliate-link-manager-ai'), 'ok' => true); }
        $post_countries = self::location_country_codes($post_location_ids);
        $link_countries = self::location_country_codes($link_locations);
        if (!empty($post_countries) && !empty(array_intersect($post_countries, $link_countries))) { return array('label' => __('Stesso paese', 'affiliate-link-manager-ai'), 'ok' => true); }
        return array('label' => __('Località diversa', 'affiliate-link-manager-ai'), 'ok' => false);
    }

    public static function analyze_content($post_id, $content) {
        $post_location_ids = self::object_location_ids($post_id, class_exists('ALMA_Geo_Index_Store') ? ALMA_Geo_Index_Store::OBJECT_TYPE_POST : 'post');
        $rows = array();
        if (!preg_match_all('/\[affiliate_link(?:s_widget)?[^\]]*\]/', (string)$content, $matches)) {
            return array('rows' => $rows, 'post_location_ids' => $post_location_ids);
        }
        foreach ($matches[0] as $shortcode) {
            $pattern = self::detect_pattern($shortcode);
            $link_id = 0;
            if (preg_match('/\bid="?(\d+)"?/', $shortcode, $m)) { $link_id = (int)$m[1]; }
            if ($pattern === 'widget') {
                $instances = get_option('widget_affiliate_links_widget', array());
                $valid = isset($instances[$link_id]);
                $rows[] = array('shortcode' => $shortcode, 'pattern' => self::pattern_label($pattern), 'title' => $valid ? sanitize_text_field((string)($instances[$link_id]['title'] ?? 'Widget #' . $link_id)) : 'Widget #' . $link_id, 'status' => $valid ? 'ok' : 'broken', 'status_label' => $valid ? __('Valido', 'affiliate-link-manager-ai') : __('Widget inesistente', 'affiliate-link-manager-ai'), 'geo' => '—', 'geo_ok' => null);
                continue;
            }
            $link = $link_id > 0 ? get_post($link_id) : null;
            $valid = $link && $link->post_type === 'affiliate_link' && $link->post_status === 'publish' && get_post_meta($link_id, '_affiliate_url', true) !== '';
            $geo = $valid ? self::geo_coherence($post_location_ids, $link_id) : array('label' => '—', 'ok' => null);
            $rows[] = array(
                'shortcode' => $shortcode,
                'pattern' => self::pattern_label($pattern),
                'title' => $link ? html_entity_decode(get_the_title($link), ENT_QUOTES, 'UTF-8') : 'Link #' . $link_id,
                'status' => $valid ? 'ok' : 'broken',
                'status_label' => $valid ? __('Valido', 'affiliate-link-manager-ai') : __('Link mancante o senza URL', 'affiliate-link-manager-ai'),
                'geo' => $geo['label'],
                'geo_ok' => $geo['ok'],
            );
        }
        return array('rows' => $rows, 'post_location_ids' => $post_location_ids);
    }

    /* ---------------------------------------------------------------------
     * Metabox
     * ------------------------------------------------------------------ */

    public static function render_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) { return; }
        $analysis = self::analyze_content($post->ID, $post->post_content);
        $proposals = self::get_open_proposals($post->ID);
        $nonce = wp_create_nonce('alma_post_optimizer_' . $post->ID);

        echo '<div id="alma-ai-affiliati-box" data-post="'.(int)$post->ID.'" data-nonce="'.esc_attr($nonce).'" data-ajax="'.esc_url(admin_url('admin-ajax.php')).'">';

        echo '<h4 style="margin:4px 0 6px;">'.esc_html__('Shortcode affiliati nel contenuto', 'affiliate-link-manager-ai').'</h4>';
        if (empty($analysis['rows'])) {
            echo '<p class="description">'.esc_html__('Nessuno shortcode affiliato presente nel contenuto salvato.', 'affiliate-link-manager-ai').'</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>'.esc_html__('Link', 'affiliate-link-manager-ai').'</th><th>'.esc_html__('Pattern', 'affiliate-link-manager-ai').'</th><th>'.esc_html__('Stato', 'affiliate-link-manager-ai').'</th><th>'.esc_html__('Geografia', 'affiliate-link-manager-ai').'</th></tr></thead><tbody>';
            foreach ($analysis['rows'] as $row) {
                $status_color = $row['status'] === 'ok' ? '#1a7f37' : '#d63638';
                $geo_color = $row['geo_ok'] === false ? '#d63638' : ($row['geo_ok'] === true ? '#1a7f37' : '#666');
                echo '<tr><td>'.esc_html($row['title']).'</td><td>'.esc_html($row['pattern']).'</td><td style="color:'.esc_attr($status_color).';">'.esc_html($row['status_label']).'</td><td style="color:'.esc_attr($geo_color).';">'.esc_html($row['geo']).'</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h4 style="margin:14px 0 6px;">'.esc_html__('Ottimizzazione AI', 'affiliate-link-manager-ai').'</h4>';
        echo '<p class="description" style="margin-top:0;">'.esc_html__('L\'AI propone nuovi inserimenti (anchor, bottoni, card) con i link geograficamente coerenti, secondo le Regole inserimento. Le proposte si applicano una a una: ogni applicazione crea una revisione del post (rollback con "Ripristina revisione") e ricarica la pagina.', 'affiliate-link-manager-ai').'</p>';
        echo '<p><button type="button" class="button button-primary" id="alma-optimizer-propose">🤖 '.esc_html__('Proponi ottimizzazioni AI', 'affiliate-link-manager-ai').'</button> <span id="alma-optimizer-feedback" style="color:#2271b1;"></span></p>';

        echo '<div id="alma-optimizer-proposals">';
        self::render_proposals_rows($proposals);
        echo '</div>';

        ?>
        <script>
        (function(){
            var box = document.getElementById('alma-ai-affiliati-box');
            if (!box) { return; }
            var postId = box.getAttribute('data-post'), nonce = box.getAttribute('data-nonce'), ajaxUrl = box.getAttribute('data-ajax');
            var feedback = document.getElementById('alma-optimizer-feedback');

            function editorIsDirty() {
                try {
                    if (window.wp && wp.data && wp.data.select('core/editor')) {
                        return wp.data.select('core/editor').isEditedPostDirty();
                    }
                } catch (e) {}
                return false;
            }

            function post(action, extra, onDone) {
                var params = new URLSearchParams();
                params.set('action', action);
                params.set('nonce', nonce);
                params.set('post_id', postId);
                Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: params.toString() })
                    .then(function (r) { return r.json(); })
                    .then(onDone)
                    .catch(function () { if (feedback) { feedback.textContent = 'Errore di connessione.'; } });
            }

            box.addEventListener('click', function (event) {
                var target = event.target;
                if (target.id === 'alma-optimizer-propose') {
                    if (editorIsDirty()) { window.alert('Hai modifiche non salvate: salva o aggiorna il post prima di generare le proposte.'); return; }
                    target.disabled = true;
                    if (feedback) { feedback.textContent = 'L\'AI sta analizzando l\'articolo…'; }
                    post('alma_post_optimizer_propose', {}, function (resp) {
                        target.disabled = false;
                        if (resp && resp.success) {
                            if (feedback) { feedback.textContent = ''; }
                            document.getElementById('alma-optimizer-proposals').innerHTML = resp.data.html || '';
                        } else if (feedback) {
                            feedback.textContent = (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore.';
                        }
                    });
                    return;
                }
                var key = target.getAttribute && target.getAttribute('data-proposal');
                if (!key) { return; }
                if (target.classList.contains('alma-proposal-apply')) {
                    if (editorIsDirty()) { window.alert('Hai modifiche non salvate: salva o aggiorna il post prima di applicare (la pagina verrà ricaricata).'); return; }
                    target.disabled = true;
                    post('alma_post_optimizer_apply', { proposal: key }, function (resp) {
                        if (resp && resp.success) { window.location.reload(); }
                        else { target.disabled = false; if (feedback) { feedback.textContent = (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore applicazione.'; } }
                    });
                } else if (target.classList.contains('alma-proposal-reject')) {
                    post('alma_post_optimizer_reject', { proposal: key }, function (resp) {
                        if (resp && resp.success) { var row = target.closest('.alma-proposal'); if (row) { row.remove(); } }
                    });
                }
            });
        })();
        </script>
        <?php
        echo '</div>';
    }

    private static function render_proposals_rows($proposals) {
        if (empty($proposals)) {
            echo '<p class="description">'.esc_html__('Nessuna proposta in sospeso.', 'affiliate-link-manager-ai').'</p>';
            return;
        }
        foreach ($proposals as $key => $proposal) {
            echo '<div class="alma-proposal" style="border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin-bottom:8px;background:#fff;">';
            echo '<p style="margin:0 0 4px;"><strong>'.esc_html(self::pattern_label($proposal['pattern'])).'</strong> · '.esc_html($proposal['link_title']).' · '.esc_html(sprintf(__('dopo il paragrafo %d', 'affiliate-link-manager-ai'), (int)$proposal['paragraph'] + 1)).'</p>';
            if (!empty($proposal['reason'])) { echo '<p class="description" style="margin:0 0 6px;">'.esc_html($proposal['reason']).'</p>'; }
            echo '<pre style="white-space:pre-wrap;font-size:12px;background:#f6f7f7;border:1px solid #eee;border-radius:4px;padding:6px 8px;margin:0 0 8px;">'.esc_html($proposal['insertion']).'</pre>';
            echo '<button type="button" class="button button-primary button-small alma-proposal-apply" data-proposal="'.esc_attr($key).'">'.esc_html__('Applica (crea revisione)', 'affiliate-link-manager-ai').'</button> ';
            echo '<button type="button" class="button button-small alma-proposal-reject" data-proposal="'.esc_attr($key).'">'.esc_html__('Scarta', 'affiliate-link-manager-ai').'</button>';
            echo '</div>';
        }
    }

    private static function get_open_proposals($post_id) {
        $stored = get_post_meta($post_id, self::META_PROPOSALS, true);
        if (!is_array($stored)) { return array(); }
        return array_filter($stored, function ($p) { return is_array($p) && empty($p['applied']) && empty($p['rejected']); });
    }

    /* ---------------------------------------------------------------------
     * Paragrafi: split/join compatibile con Gutenberg e classic
     * ------------------------------------------------------------------ */

    /**
     * Un "delimitatore" è la chiusura di paragrafo HTML oppure una riga
     * vuota (anche con newline Windows \r\n, come salva il classic editor).
     */
    public static function is_delimiter($part, $mode) {
        if ($mode === 'html') {
            return strcasecmp((string)$part, '</p>') === 0;
        }
        return (bool) preg_match('/^\r?\n[ \t]*\r?\n$/', (string)$part);
    }

    /**
     * Sceglie lo split più ricco tra HTML (</p>, Gutenberg) e testo (riga
     * vuota, classic editor / WPBakery): il contenuto classic con \r\n non
     * contiene </p> e prima veniva visto come UN solo paragrafo.
     */
    public static function split_paragraphs($content) {
        $content = (string) $content;
        $text_parts = preg_split("/(\r?\n[ \t]*\r?\n)/", $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text_count = self::count_paragraph_parts($text_parts, 'text');
        if (stripos($content, '</p>') !== false) {
            $html_parts = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $html_count = self::count_paragraph_parts($html_parts, 'html');
            if ($html_count >= $text_count) {
                return array('parts' => $html_parts, 'mode' => 'html');
            }
        }
        return array('parts' => $text_parts, 'mode' => 'text');
    }

    private static function count_paragraph_parts($parts, $mode) {
        $count = 0;
        foreach ((array)$parts as $part) {
            if (self::is_delimiter($part, $mode)) { continue; }
            if (trim(wp_strip_all_tags($part)) !== '') { $count++; }
        }
        return $count;
    }

    public static function paragraph_texts($content) {
        $split = self::split_paragraphs($content);
        $texts = array();
        foreach ($split['parts'] as $part) {
            if (self::is_delimiter($part, $split['mode'])) { continue; }
            $text = trim(wp_strip_all_tags($part));
            if ($text !== '') { $texts[] = $text; }
        }
        return $texts;
    }

    /**
     * Inserisce dopo il paragrafo indicato: le anchor come frase in coda al
     * paragrafo, bottoni/card come blocco autonomo subito dopo.
     */
    public static function insert_after_paragraph($content, $paragraph_index, $insertion, $inline) {
        $split = self::split_paragraphs($content);
        $parts = $split['parts'];
        $current = -1;
        foreach ($parts as $i => $part) {
            if (self::is_delimiter($part, $split['mode'])) { continue; }
            if (trim(wp_strip_all_tags($part)) === '') { continue; }
            $current++;
            if ($current === (int)$paragraph_index) {
                if ($inline) {
                    $parts[$i] = rtrim($part) . ' ' . $insertion;
                } else {
                    $has_delimiter = isset($parts[$i + 1]) && self::is_delimiter($parts[$i + 1], $split['mode']);
                    if ($split['mode'] === 'html') {
                        array_splice($parts, $i + 1 + ($has_delimiter ? 1 : 0), 0, array("\n" . $insertion . "\n"));
                    } elseif ($has_delimiter) {
                        // Dopo il delimitatore \n\n esistente, con separazione dal blocco successivo.
                        array_splice($parts, $i + 2, 0, array($insertion . "\n\n"));
                    } else {
                        array_splice($parts, $i + 1, 0, array("\n\n" . $insertion));
                    }
                }
                return implode('', $parts);
            }
        }
        // Paragrafo non trovato: accoda in fondo.
        return $content . "\n\n" . $insertion;
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    private static function verify_request() {
        $post_id = absint($_POST['post_id'] ?? 0);
        if ($post_id < 1 || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('Permessi insufficienti.', 'affiliate-link-manager-ai')), 403);
        }
        if (!check_ajax_referer('alma_post_optimizer_' . $post_id, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Nonce non valido: ricarica la pagina.', 'affiliate-link-manager-ai')), 400);
        }
        return $post_id;
    }

    public static function ajax_propose() {
        $post_id = self::verify_request();
        $post = get_post($post_id);
        if (!$post) { wp_send_json_error(array('message' => __('Post non trovato.', 'affiliate-link-manager-ai')), 404); }
        $result = self::generate_proposals($post);
        if (!empty($result['error'])) {
            wp_send_json_error(array('message' => $result['error']));
        }
        $proposals = (array) $result['proposals'];
        update_post_meta($post_id, self::META_PROPOSALS, $proposals);

        ob_start();
        self::render_proposals_rows($proposals);
        wp_send_json_success(array('html' => (string) ob_get_clean(), 'count' => count($proposals)));
    }

    /**
     * Motore proposte, riusato dal metabox (AJAX) e dall'arricchimento in
     * background (Fase 6). $args:
     * - allow_replacements: consente proposte di SOSTITUZIONE di shortcode
     *   esistenti (link geograficamente incoerenti o nettamente peggiori);
     * - task: etichetta per l'usage logger.
     * Ritorna array('proposals' => array, 'error' => string).
     */
    public static function generate_proposals($post, $args = array()) {
        $allow_replacements = !empty($args['allow_replacements']);
        $task = sanitize_key($args['task'] ?? 'post_optimizer');
        if (empty(get_option('alma_openai_api_key', ''))) {
            return array('proposals' => array(), 'error' => __('OpenAI non è configurata.', 'affiliate-link-manager-ai'));
        }

        $rules = ALMA_AI_Insertion_Rules::get_rules();
        $paragraphs = self::paragraph_texts($post->post_content);
        if (count($paragraphs) < max(3, $rules['min_paragraphs_before'] + 1)) {
            return array('proposals' => array(), 'error' => __('Contenuto troppo breve per proporre inserimenti.', 'affiliate-link-manager-ai'));
        }

        // Budget inserimenti: densità meno gli shortcode già presenti.
        $word_count = ALMA_AI_Insertion_Rules::count_words(wp_strip_all_tags($post->post_content));
        $existing = preg_match_all('/\[affiliate_link(?:s_widget)?[^\]]*\]/', $post->post_content, $m);
        $budget = max(0, (int)floor($word_count / $rules['density_words']) - (int)$existing);
        if ($budget < 1 && !$allow_replacements) {
            return array('proposals' => array(), 'error' => __('Densità massima già raggiunta: nessun nuovo inserimento consigliabile.', 'affiliate-link-manager-ai'));
        }
        $budget = min($budget, self::MAX_PROPOSALS);

        // Candidati: link dell'area geografica del post + match testuale sul titolo.
        $candidates = self::collect_candidates($post);
        if (empty($candidates)) {
            return array('proposals' => array(), 'error' => __('Nessun link affiliato candidato (né geografico né per keyword) per questo articolo.', 'affiliate-link-manager-ai'));
        }

        $allowed_patterns = array_values(array_intersect($rules['patterns'], array('anchor', 'button', 'card')));
        // Pattern widget (2.83.0): proponibile solo se abilitato nelle regole
        // e l'articolo non contiene già un widget di link affiliati.
        $widget_allowed = in_array('widget', $rules['patterns'], true)
            && !preg_match('/\[affiliate_links_widget[\s\]]/', $post->post_content)
            && class_exists('ALMA_Affiliate_Widget_Layout_Registry');
        if ($widget_allowed) { $allowed_patterns[] = 'widget'; }
        $context = array(
            'titolo_articolo' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'paragrafi' => array_map(function ($text, $i) { return array('indice' => $i, 'testo' => mb_substr($text, 0, 320)); }, $paragraphs, array_keys($paragraphs)),
            'link_candidati' => $candidates,
            'pattern_permessi' => $allowed_patterns,
            'max_proposte' => $budget,
            'primo_paragrafo_utilizzabile' => (int)$rules['min_paragraphs_before'],
            'regole_anchor' => $rules['anchor_rules'],
        );
        $prompt = 'Analizza l\'articolo e proponi al massimo ' . $budget . ' inserimenti di link affiliati che aumentino la conversione SENZA rompere il flusso di lettura. '
            . 'Rispondi SOLO JSON: {"proposte":[{"paragrafo":int (indice del paragrafo DOPO il quale inserire, >= primo_paragrafo_utilizzabile),"pattern":"anchor|button|card","link_id":int (solo da link_candidati),"frase":string (SOLO per anchor: una frase completa e naturale che prosegue il paragrafo e contiene lo shortcode [affiliate_link id="ID" text="anchor descrittiva"]),"button_text":string (per button/card),"frase_intro":string (per button/card: 1-2 frasi nel tono dell\'articolo che introducono il link partendo dalla sua "descrizione" — RISCRIVILA adattandola al contesto, non copiarla; niente shortcode né parentesi quadre),"motivo":string (perché qui, orientato alla conversione)}]}. ';
        if ($widget_allowed) {
            $prompt .= 'DOVE POSSIBILE proponi anche UN widget di link affiliati (blocco grafico a card, massimo uno): serve a SPEZZARE VISIVAMENTE il testo, quindi scegli un paragrafo INTERMEDIO dell\'articolo (dopo una sezione centrale pertinente), NON necessariamente la chiusura. Aggiungi alle proposte {"pattern":"widget","paragrafo":int,"layout":"destination_cards|experience_cards|hero_spotlight","link_ids":[int (solo da link_candidati)],"titolo":string,"button_text":string,"motivo":string}. Layout: destination_cards = griglia di mete/destinazioni (2-6 link); experience_cards = card di tour/attività specifiche (2-8 link); hero_spotlight = UNA sola esperienza di punta (1 link). ';
        }
        $prompt .= 'I link_candidati con "universale":true (assicurazione viaggio, eSIM…) sono pertinenti in QUALSIASI articolo di viaggio: DEVI includere UNA proposta con uno di questi, nel punto più naturale — consigli pratici, preparativi, informazioni utili. Usa il pattern card (mostra immagine, titolo e descrizione del link) SEMPRE con frase_intro che riscrive la descrizione nel tono dell\'articolo, oppure anchor con una frase che integra il servizio nel discorso. MAI un bottone nudo senza contesto. Ometti l\'universale SOLO se l\'articolo lo rende assurdo. ';
        if ($allow_replacements) {
            $existing_analysis = self::analyze_content($post->ID, $post->post_content);
            $existing_links = array();
            foreach ((array)$existing_analysis['rows'] as $row) {
                if ($row['pattern'] === 'Widget') { continue; }
                if (preg_match('/\bid="?(\d+)"?/', $row['shortcode'], $mm)) {
                    $existing_links[] = array('link_id' => (int)$mm[1], 'titolo' => $row['title'], 'coerenza_geografica' => $row['geo'], 'valido' => $row['status'] === 'ok');
                }
            }
            $context['shortcode_esistenti'] = $existing_links;
            $prompt .= 'Puoi inoltre proporre la SOSTITUZIONE di uno shortcode esistente SOLO se il suo link è geograficamente incoerente, non valido, o esiste un candidato nettamente più pertinente: aggiungi alle proposte {"azione":"sostituisci","vecchio_link_id":int (da shortcode_esistenti),"nuovo_link_id":int (da link_candidati),"testo_anchor":string (nuova anchor descrittiva se il pattern è anchor),"motivo":string}. Non sostituire link già coerenti solo per variare. ';
            if ($budget < 1) { $prompt .= 'La densità massima è già raggiunta: NON proporre nuovi inserimenti, valuta solo eventuali sostituzioni. '; }
        }
        $prompt .= 'Meglio poche proposte eccellenti che tante mediocri. CONTEXT: ' . wp_json_encode($context);

        $res = ALMA_OpenAI_Service::request(array(
            'system_prompt' => 'Sei un editor esperto di monetizzazione affiliate per blog di viaggi. Proponi inserimenti eleganti e pertinenti. Output solo JSON valido.',
            'user_prompt' => $prompt,
            'json_output' => true,
            'max_output_tokens' => 1200,
            'temperature' => 0.4,
            'timeout' => 60,
        ));
        ALMA_AI_Usage_Logger::log(array('task' => $task, 'success' => !empty($res['success']), 'model' => $res['model'] ?? '', 'response_time' => $res['response_time'] ?? null, 'input_tokens' => $res['usage']['input_tokens'] ?? null, 'output_tokens' => $res['usage']['output_tokens'] ?? null, 'estimated_cost' => $res['estimated_cost'] ?? null, 'error' => $res['error'] ?? '', 'reference_id' => 'post:' . $post->ID));
        if (empty($res['success'])) {
            return array('proposals' => array(), 'error' => sanitize_text_field((string)($res['error'] ?? __('Errore AI', 'affiliate-link-manager-ai'))));
        }
        $parsed = json_decode((string)$res['response'], true);
        if (!is_array($parsed)) { $parsed = json_decode(ALMA_AI_Content_Agent_Text_Utils::extract_first_json((string)$res['response']), true); }
        $raw_proposals = is_array($parsed['proposte'] ?? null) ? $parsed['proposte'] : array();

        $proposals = self::validate_proposals($raw_proposals, $candidates, $paragraphs, $rules, $budget, $allowed_patterns);
        if ($allow_replacements) {
            $proposals = array_merge($proposals, self::validate_replacements($raw_proposals, $candidates, $post->post_content));
        }
        // Garanzia deterministica: se l'AI ha ignorato i link universali
        // (assicurazioni/eSIM) e l'articolo non ne contiene già uno, la
        // proposta viene aggiunta dal sistema — come per i widget garantiti.
        $proposals = self::ensure_universal_proposal($proposals, $candidates, $paragraphs, $rules, $post);
        if (empty($proposals)) {
            return array('proposals' => array(), 'error' => __('L\'AI non ha prodotto proposte valide per questo articolo.', 'affiliate-link-manager-ai'));
        }
        return array('proposals' => $proposals, 'error' => '');
    }

    /**
     * Se nessuna proposta usa un link di tipologia universale e il contenuto
     * non ne contiene già uno, aggiunge una proposta card deterministica
     * (immagine + titolo + descrizione del link, non un bottone nudo) col
     * miglior universale, a circa due terzi dell'articolo (zona consigli
     * pratici), oltre il budget di densità se necessario (max 1).
     */
    private static function ensure_universal_proposal($proposals, $candidates, $paragraphs, $rules, $post) {
        $universal_ids = array();
        foreach ((array) $candidates as $candidate) {
            if (!empty($candidate['universale'])) { $universal_ids[(int) $candidate['id']] = sanitize_text_field((string) $candidate['titolo']); }
        }
        if (empty($universal_ids)) { return $proposals; }
        foreach ((array) $proposals as $proposal) {
            if (isset($universal_ids[(int) ($proposal['link_id'] ?? 0)])) { return $proposals; }
            foreach ((array) ($proposal['widget_request']['link_ids'] ?? array()) as $wid) {
                if (isset($universal_ids[(int) $wid])) { return $proposals; }
            }
        }
        // L'articolo contiene già uno shortcode con un link universale?
        foreach (array_keys($universal_ids) as $uid) {
            if (preg_match('/\[affiliate_link[^\]]*\bid="?' . $uid . '"?[\s"\]]/', $post->post_content)) { return $proposals; }
        }
        reset($universal_ids);
        $link_id = (int) key($universal_ids);
        $min_paragraph = (int) $rules['min_paragraphs_before'];
        $paragraph = max($min_paragraph, (int) floor(count($paragraphs) * 0.7));
        $paragraph = min($paragraph, max($min_paragraph, count($paragraphs) - 1));
        $button_text = sanitize_text_field((string) $rules['button_text']);
        $proposal = array(
            'pattern' => 'card',
            'link_id' => $link_id,
            'link_title' => $universal_ids[$link_id],
            'paragraph' => $paragraph,
            'insertion' => '[affiliate_link id="' . $link_id . '" img="yes" fields="title,content" button="yes" button_text="' . esc_attr($button_text) . '"]',
            'inline' => false,
            'reason' => __('Tipologia universale (assicurazioni/eSIM): card con immagine, titolo e descrizione — proposta garantita dal sistema perché l\'AI non l\'aveva inclusa.', 'affiliate-link-manager-ai'),
            'created_at' => current_time('mysql'),
        );
        $proposals[md5(wp_json_encode(array('universal_guarantee', $link_id, $paragraph)))] = $proposal;
        return $proposals;
    }

    /**
     * Valida le proposte di sostituzione: il vecchio ID deve essere in uno
     * shortcode del contenuto, il nuovo tra i candidati, e diversi tra loro.
     */
    private static function validate_replacements($raw, $candidates, $content) {
        $candidate_map = array();
        foreach ($candidates as $candidate) { $candidate_map[(int)$candidate['id']] = $candidate['titolo']; }
        $proposals = array();
        foreach ((array)$raw as $row) {
            if (!is_array($row) || sanitize_key((string)($row['azione'] ?? '')) !== 'sostituisci') { continue; }
            $old_id = absint($row['vecchio_link_id'] ?? 0);
            $new_id = absint($row['nuovo_link_id'] ?? 0);
            if ($old_id < 1 || $new_id < 1 || $old_id === $new_id) { continue; }
            if (!isset($candidate_map[$new_id])) { continue; }
            if (!preg_match('/\[affiliate_link[^\]]*\bid="?' . $old_id . '"?[\s"\]]/', $content)) { continue; }
            $proposal = array(
                'pattern' => 'replace',
                'link_id' => $new_id,
                'link_title' => $candidate_map[$new_id],
                'old_link_id' => $old_id,
                'paragraph' => 0,
                'insertion' => sprintf('Sostituzione link #%d → #%d (%s)', $old_id, $new_id, $candidate_map[$new_id]),
                'inline' => false,
                'anchor_text' => str_replace('"', '', sanitize_text_field((string)($row['testo_anchor'] ?? ''))),
                'reason' => sanitize_text_field((string)($row['motivo'] ?? '')),
                'created_at' => current_time('mysql'),
            );
            $proposals[md5(wp_json_encode(array('replace', $old_id, $new_id)))] = $proposal;
            if (count($proposals) >= 3) { break; }
        }
        return $proposals;
    }

    /**
     * Sostituisce il primo shortcode del vecchio link con il nuovo ID
     * (stessa struttura/pattern); per le anchor aggiorna anche text=.
     */
    public static function apply_replacement($content, $old_link_id, $new_link_id, $anchor_text = '') {
        $done = false;
        return array('content' => preg_replace_callback(
            '/\[affiliate_link([^\]]*)\]/',
            function ($m) use (&$done, $old_link_id, $new_link_id, $anchor_text) {
                if ($done || !preg_match('/\bid="?' . (int)$old_link_id . '"?(\s|$)/', $m[1] . ' ')) { return $m[0]; }
                $done = true;
                $inner = preg_replace('/\bid="?' . (int)$old_link_id . '"?/', 'id="' . (int)$new_link_id . '"', $m[1]);
                if ($anchor_text !== '' && preg_match('/\stext="[^"]*"/', $inner)) {
                    $inner = preg_replace('/(\s)text="[^"]*"/', '$1text="' . $anchor_text . '"', $inner);
                }
                return '[affiliate_link' . $inner . ']';
            },
            $content
        ), 'replaced' => $done);
    }

    private static function collect_candidates($post) {
        $candidates = array();
        $location_ids = self::object_location_ids($post->ID, class_exists('ALMA_Geo_Index_Store') ? ALMA_Geo_Index_Store::OBJECT_TYPE_POST : 'post');
        $geo_link_ids = array();
        $store = self::geo_store();
        if ($store && !empty($location_ids)) {
            foreach (array_slice($location_ids, 0, 3) as $location_id) {
                $geo_link_ids = array_merge($geo_link_ids, $store->get_affiliate_link_ids_for_area($location_id));
            }
            $geo_link_ids = array_values(array_unique($geo_link_ids));
        }
        $search = ALMA_AI_Content_Agent_Knowledge_Search::search(array(
            'content_search_query' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'search_scope' => 'affiliate_links_only',
            'geo_link_ids' => $geo_link_ids,
        ));
        foreach (array_slice((array)($search['groups']['affiliate_link'] ?? array()), 0, self::MAX_CANDIDATES) as $row) {
            $candidates[] = array(
                'id' => (int)$row['source_id'],
                'titolo' => sanitize_text_field((string)$row['title']),
                'tipologie' => is_array($row['link_types'] ?? null) ? implode(', ', $row['link_types']) : '',
                'descrizione' => self::candidate_description((int)$row['source_id']),
            );
        }
        // I migliori link di tipologia UNIVERSALE (Assicurazioni, eSIM…)
        // sono sempre candidati: valgono per qualsiasi articolo.
        if (class_exists('ALMA_Universal_Link_Types')) {
            $existing_ids = array_map(function ($c) { return (int) $c['id']; }, $candidates);
            foreach (ALMA_Universal_Link_Types::top_universal_links(2, $existing_ids) as $universal_id) {
                $terms = get_the_terms($universal_id, 'link_type');
                $candidates[] = array(
                    'id' => (int) $universal_id,
                    'titolo' => sanitize_text_field((string) get_the_title($universal_id)),
                    'tipologie' => (is_array($terms) ? implode(', ', wp_list_pluck($terms, 'name')) : ''),
                    'descrizione' => self::candidate_description((int) $universal_id),
                    'universale' => true,
                );
            }
        }
        return $candidates;
    }

    /**
     * Estratto breve della descrizione del link: è il materiale con cui
     * l'AI scrive la frase introduttiva nel tono dell'articolo.
     */
    private static function candidate_description($link_id) {
        $text = trim(wp_strip_all_tags((string) get_post_field('post_content', $link_id)));
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) > 240) {
            $text = rtrim(mb_substr($text, 0, 239)) . '…';
        }
        return $text;
    }

    /**
     * Antepone la frase introduttiva scritta dall'AI (tono dell'articolo)
     * al blocco button/card: paragrafo autonomo, senza shortcode né markup.
     */
    private static function prepend_intro_sentence($insertion, $row) {
        $intro = sanitize_text_field((string)($row['frase_intro'] ?? ''));
        $intro = trim(str_replace(array('[', ']'), '', $intro));
        if ($intro === '') { return $insertion; }
        if (mb_strlen($intro) > 400) {
            $intro = rtrim(mb_substr($intro, 0, 399)) . '…';
        }
        return '<p>' . esc_html($intro) . '</p>' . "\n" . $insertion;
    }

    private static function validate_proposals($raw, $candidates, $paragraphs, $rules, $budget, $allowed_patterns) {
        $candidate_map = array();
        foreach ($candidates as $candidate) { $candidate_map[(int)$candidate['id']] = $candidate['titolo']; }
        $proposals = array();
        $widget_proposed = false;
        foreach ((array)$raw as $row) {
            if (count($proposals) >= $budget) { break; }
            if (!is_array($row)) { continue; }
            $pattern = sanitize_key((string)($row['pattern'] ?? ''));
            $link_id = absint($row['link_id'] ?? 0);
            $paragraph = absint($row['paragrafo'] ?? 0);
            if (!in_array($pattern, $allowed_patterns, true)) { continue; }
            if ($paragraph < (int)$rules['min_paragraphs_before'] || $paragraph >= count($paragraphs)) { continue; }

            if ($pattern === 'widget') {
                // Max 1 widget per articolo; l'istanza reale viene creata solo
                // all'applicazione (mai widget orfani per proposte rifiutate).
                if ($widget_proposed) { continue; }
                $widget_link_ids = array_values(array_unique(array_filter(array_map('absint', (array)($row['link_ids'] ?? array())))));
                $widget_link_ids = array_values(array_filter($widget_link_ids, function ($id) use ($candidate_map) { return isset($candidate_map[$id]); }));
                $layout = sanitize_key((string)($row['layout'] ?? ''));
                $selectable = ALMA_Affiliate_Widget_Layout_Registry::get_selectable_presets();
                if (!isset($selectable[$layout])) { $layout = ALMA_Affiliate_Widget_Layout_Registry::get_default_preset(); }
                $preset = $selectable[$layout];
                $widget_link_ids = array_slice($widget_link_ids, 0, absint($preset['max_links']));
                if (count($widget_link_ids) < max(1, absint($preset['min_links']))) { continue; }
                $titles = array_map(function ($id) use ($candidate_map) { return $candidate_map[$id]; }, $widget_link_ids);
                $proposal = array(
                    'pattern' => 'widget',
                    'link_id' => $widget_link_ids[0],
                    'link_title' => implode(', ', array_slice($titles, 0, 3)) . (count($titles) > 3 ? '…' : ''),
                    'paragraph' => $paragraph,
                    'insertion' => sprintf('Widget "%s" (%s) con %d link', sanitize_text_field((string)($row['titolo'] ?? '')), $preset['label'], count($widget_link_ids)),
                    'inline' => false,
                    'widget_request' => array(
                        'title' => sanitize_text_field((string)($row['titolo'] ?? '')),
                        'layout' => $layout,
                        'link_ids' => $widget_link_ids,
                        'button_text' => sanitize_text_field((string)($row['button_text'] ?? '')),
                    ),
                    'reason' => sanitize_text_field((string)($row['motivo'] ?? '')),
                    'created_at' => current_time('mysql'),
                );
                $proposals[md5(wp_json_encode(array('widget', $widget_link_ids, $paragraph, $layout)))] = $proposal;
                $widget_proposed = true;
                continue;
            }

            if (!isset($candidate_map[$link_id])) { continue; }

            if ($pattern === 'anchor') {
                $sentence = sanitize_text_field((string)($row['frase'] ?? ''));
                // La frase deve contenere ESATTAMENTE uno shortcode per il link proposto.
                if (!preg_match('/^[^\[]*\[affiliate_link id="?' . $link_id . '"?\s[^\]]*\stext="[^"]+"[^\]]*\][^\[]*$/', $sentence)) { continue; }
                $insertion = $sentence;
                $inline = true;
            } elseif ($pattern === 'button') {
                $button_text = sanitize_text_field((string)($row['button_text'] ?? '')) ?: $rules['button_text'];
                $insertion = self::prepend_intro_sentence('[affiliate_link id="' . $link_id . '" button="yes" button_text="' . esc_attr($button_text) . '"]', $row);
                $inline = false;
            } else { // card
                $button_text = sanitize_text_field((string)($row['button_text'] ?? '')) ?: $rules['button_text'];
                $insertion = self::prepend_intro_sentence('[affiliate_link id="' . $link_id . '" img="yes" fields="title,content" button="yes" button_text="' . esc_attr($button_text) . '"]', $row);
                $inline = false;
            }
            $proposal = array(
                'pattern' => $pattern,
                'link_id' => $link_id,
                'link_title' => $candidate_map[$link_id],
                'paragraph' => $paragraph,
                'insertion' => $insertion,
                'inline' => $inline,
                'reason' => sanitize_text_field((string)($row['motivo'] ?? '')),
                'created_at' => current_time('mysql'),
            );
            $proposals[md5(wp_json_encode(array($pattern, $link_id, $paragraph, $insertion)))] = $proposal;
        }
        return $proposals;
    }

    /**
     * Crea l'istanza widget di una proposta 'widget' e ritorna lo shortcode
     * da inserire (o un errore). Chiamata SOLO all'applicazione.
     */
    public static function materialize_widget_proposal($proposal) {
        $request = is_array($proposal['widget_request'] ?? null) ? $proposal['widget_request'] : array();
        $link_ids = array_map('absint', (array)($request['link_ids'] ?? array()));
        if (empty($link_ids) || !class_exists('ALMA_AI_Insertion_Rules')) {
            return array('error' => __('Proposta widget non valida.', 'affiliate-link-manager-ai'));
        }
        $created = ALMA_AI_Insertion_Rules::create_widget_from_request($request, $link_ids);
        if (!empty($created['error'])) {
            return array('error' => sanitize_text_field((string)$created['error']));
        }
        return array('shortcode' => (string)$created['shortcode'], 'widget_id' => (int)$created['widget_id']);
    }

    public static function ajax_apply() {
        $post_id = self::verify_request();
        $key = sanitize_text_field(wp_unslash($_POST['proposal'] ?? ''));
        $stored = get_post_meta($post_id, self::META_PROPOSALS, true);
        if (!is_array($stored) || empty($stored[$key]) || !empty($stored[$key]['applied']) || !empty($stored[$key]['rejected'])) {
            wp_send_json_error(array('message' => __('Proposta non trovata o già gestita: rigenera le proposte.', 'affiliate-link-manager-ai')), 404);
        }
        $proposal = $stored[$key];
        $post = get_post($post_id);
        if (!$post) { wp_send_json_error(array('message' => __('Post non trovato.', 'affiliate-link-manager-ai')), 404); }

        $insertion = (string)$proposal['insertion'];
        if (($proposal['pattern'] ?? '') === 'widget') {
            $created = self::materialize_widget_proposal($proposal);
            if (!empty($created['error'])) {
                wp_send_json_error(array('message' => $created['error']));
            }
            $insertion = $created['shortcode'];
        }
        $new_content = self::insert_after_paragraph($post->post_content, (int)$proposal['paragraph'], $insertion, !empty($proposal['inline']));
        // wp_update_post crea automaticamente una revisione: rollback nativo.
        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => $new_content), true);
        if (is_wp_error($updated)) {
            wp_send_json_error(array('message' => sanitize_text_field($updated->get_error_message())));
        }
        $stored[$key]['applied'] = current_time('mysql');
        update_post_meta($post_id, self::META_PROPOSALS, $stored);
        // Immagini AI on-demand per i link inseriti senza immagine in evidenza.
        if (class_exists('ALMA_AI_Image_Generator')) {
            $applied_ids = ($proposal['pattern'] ?? '') === 'widget'
                ? array_map('absint', (array)($proposal['widget_request']['link_ids'] ?? array()))
                : array(absint($proposal['link_id'] ?? 0));
            ALMA_AI_Image_Generator::queue_links($applied_ids);
        }
        wp_send_json_success(array('applied' => true));
    }

    public static function ajax_reject() {
        $post_id = self::verify_request();
        $key = sanitize_text_field(wp_unslash($_POST['proposal'] ?? ''));
        $stored = get_post_meta($post_id, self::META_PROPOSALS, true);
        if (is_array($stored) && isset($stored[$key])) {
            $stored[$key]['rejected'] = current_time('mysql');
            update_post_meta($post_id, self::META_PROPOSALS, $stored);
        }
        wp_send_json_success(array('rejected' => true));
    }
}
