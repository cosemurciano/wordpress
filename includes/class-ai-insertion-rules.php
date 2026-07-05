<?php
/**
 * Fase 4 — Regole di inserimento degli shortcode affiliati nelle bozze AI.
 *
 * Due livelli:
 * 1. PROMPT: vocabolario dei pattern di inserimento (anchor nel testo,
 *    bottone CTA, card, widget di raccolta) con le regole d'uso, costruito
 *    dalle impostazioni della tab "Regole inserimento".
 * 2. QA DETERMINISTICO: dopo la generazione, enforce() garantisce nel codice
 *    ciò che il prompt chiede — densità massima, niente inserimenti nei primi
 *    paragrafi, mai due shortcode consecutivi, massimo un widget. Le anchor
 *    in eccesso diventano testo semplice (la lettura non si rompe), gli
 *    altri pattern in eccesso vengono rimossi.
 *
 * La creazione del widget dall'AI passa da create_widget_instance(): scrive
 * una nuova istanza nell'option widget_affiliate_links_widget (la stessa
 * usata da Crea Widget Link) e restituisce l'ID per [affiliate_links_widget].
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Insertion_Rules {
    const OPTION_DENSITY = 'alma_ai_insert_density_words';
    const OPTION_PATTERNS = 'alma_ai_insert_patterns';
    const OPTION_MIN_PARAGRAPHS = 'alma_ai_insert_min_paragraphs';
    const OPTION_WIDGET_MAX_LINKS = 'alma_ai_insert_widget_max_links';
    const OPTION_BUTTON_TEXT = 'alma_ai_insert_button_text';
    const OPTION_ANCHOR_RULES = 'alma_ai_insert_anchor_rules';
    const WIDGET_PLACEHOLDER = '[[ALMA_WIDGET]]';

    public static function all_patterns() {
        return array(
            'anchor' => 'Anchor nel testo',
            'button' => 'Bottone CTA',
            'card' => 'Card con immagine',
            'widget' => 'Widget di raccolta finale',
        );
    }

    public static function get_rules() {
        $patterns = array_values(array_intersect(
            array_filter(array_map('sanitize_key', (array) get_option(self::OPTION_PATTERNS, array_keys(self::all_patterns())))),
            array_keys(self::all_patterns())
        ));
        if (empty($patterns)) { $patterns = array('anchor'); }
        return array(
            'density_words' => max(100, min(2000, absint(get_option(self::OPTION_DENSITY, 300)))),
            'patterns' => $patterns,
            'min_paragraphs_before' => max(0, min(10, absint(get_option(self::OPTION_MIN_PARAGRAPHS, 2)))),
            'widget_max_links' => max(2, min(8, absint(get_option(self::OPTION_WIDGET_MAX_LINKS, 4)))),
            'button_text' => sanitize_text_field(get_option(self::OPTION_BUTTON_TEXT, __('Scopri di più', 'affiliate-link-manager-ai'))) ?: __('Scopri di più', 'affiliate-link-manager-ai'),
            'anchor_rules' => sanitize_textarea_field(get_option(self::OPTION_ANCHOR_RULES, 'Anchor descrittive e naturali nella frase; mai "clicca qui", mai il solo nome del provider.')),
        );
    }

    public static function save_from_request($post) {
        update_option(self::OPTION_DENSITY, max(100, min(2000, absint($post[self::OPTION_DENSITY] ?? 300))), false);
        $patterns = array_values(array_intersect(array_map('sanitize_key', (array)($post['alma_ai_insert_patterns'] ?? array())), array_keys(self::all_patterns())));
        update_option(self::OPTION_PATTERNS, !empty($patterns) ? $patterns : array('anchor'), false);
        update_option(self::OPTION_MIN_PARAGRAPHS, max(0, min(10, absint($post[self::OPTION_MIN_PARAGRAPHS] ?? 2))), false);
        update_option(self::OPTION_WIDGET_MAX_LINKS, max(2, min(8, absint($post[self::OPTION_WIDGET_MAX_LINKS] ?? 4))), false);
        update_option(self::OPTION_BUTTON_TEXT, sanitize_text_field(wp_unslash($post[self::OPTION_BUTTON_TEXT] ?? '')), false);
        update_option(self::OPTION_ANCHOR_RULES, sanitize_textarea_field(wp_unslash($post[self::OPTION_ANCHOR_RULES] ?? '')), false);
    }

    /**
     * Vocabolario dei pattern per il payload OpenAI: descrive COME inserire
     * gli shortcode in modo coerente col flusso di lettura e orientato alla
     * conversione, secondo la configurazione.
     */
    public static function payload_rules() {
        $rules = self::get_rules();
        $lines = array(
            'PATTERN DI INSERIMENTO AFFILIATI (usa SOLO questi, scegliendo il pattern in base al punto dell\'articolo):',
        );
        if (in_array('anchor', $rules['patterns'], true)) {
            $lines[] = '- anchor nel testo: [affiliate_link id="ID" text="anchor naturale"] dentro un paragrafo, dove il prodotto/esperienza è citato nel discorso. ' . $rules['anchor_rules'];
        }
        if (in_array('button', $rules['patterns'], true)) {
            $lines[] = '- bottone CTA: [affiliate_link id="ID" button="yes" button_text="testo azione"] da solo dopo la chiusura di una sezione che ha creato interesse (mai a metà frase). Default button_text: "' . $rules['button_text'] . '".';
        }
        if (in_array('card', $rules['patterns'], true)) {
            $lines[] = '- card prodotto: [affiliate_link id="ID" img="yes" fields="title,content" button="yes"] quando dedichi un blocco a una singola esperienza importante (max 1-2 per articolo).';
        }
        if (in_array('widget', $rules['patterns'], true)) {
            $lines[] = '- widget di raccolta: UNA sola volta, in chiusura dell\'articolo, inserisci il segnaposto ' . self::WIDGET_PLACEHOLDER . ' e compila il campo widget_request dell\'output con: title (es. "Le migliori esperienze a X"), link_ids (2-' . $rules['widget_max_links'] . ' ID dei link più pertinenti), button_text, e per ogni link rewritten[{id,title,description}] con titolo e descrizione riscritti nel tono dell\'articolo (descrizione 1-2 frasi orientate al beneficio).';
        }
        $lines[] = 'REGOLE DI DENSITÀ E POSIZIONE (verranno comunque applicate dal sistema):';
        $lines[] = '- massimo 1 inserimento ogni ' . $rules['density_words'] . ' parole di contenuto;';
        $lines[] = '- nessun inserimento nei primi ' . $rules['min_paragraphs_before'] . ' paragrafi (l\'introduzione crea fiducia, non vende);';
        $lines[] = '- mai due shortcode consecutivi senza testo tra loro; distribuisci gli inserimenti lungo l\'articolo;';
        $lines[] = '- l\'eleganza della lettura viene prima della conversione: se un inserimento non è naturale, non farlo.';
        return $lines;
    }

    /* ---------------------------------------------------------------------
     * QA deterministico post-generazione (logica pura, testabile standalone)
     * ------------------------------------------------------------------ */

    /**
     * Applica le regole al contenuto HTML generato. Ritorna array con
     * 'content' e 'warnings'. Pure: nessuna dipendenza WordPress.
     */
    public static function enforce($content, $rules) {
        $warnings = array();
        $content = (string) $content;
        $density = max(100, (int) ($rules['density_words'] ?? 300));
        $min_paragraphs = max(0, (int) ($rules['min_paragraphs_before'] ?? 2));

        // 1. Massimo un widget per articolo.
        $widget_count = preg_match_all('/\[affiliate_links_widget[^\]]*\]/', $content, $widget_matches);
        if ($widget_count > 1) {
            $first = true;
            $content = preg_replace_callback('/\[affiliate_links_widget[^\]]*\]/', function ($m) use (&$first) {
                if ($first) { $first = false; return $m[0]; }
                return '';
            }, $content);
            $warnings[] = 'Widget in eccesso rimossi: massimo uno per articolo.';
        }

        // 2. Nessuno shortcode nei primi N paragrafi: le anchor diventano
        //    testo semplice, gli altri pattern vengono rimossi. I paragrafi
        //    sono delimitati da </p> (HTML/Gutenberg) O da riga vuota (classic
        //    editor, anche con newline Windows \r\n).
        if ($min_paragraphs > 0) {
            $parts = preg_split('/(<\/p>|\r?\n[ \t]*\r?\n)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $paragraph_index = 0;
            foreach ($parts as $i => $part) {
                if (strcasecmp($part, '</p>') === 0 || preg_match('/^\r?\n[ \t]*\r?\n$/', $part)) { $paragraph_index++; continue; }
                if ($paragraph_index < $min_paragraphs && preg_match('/\[affiliate_link(?:s_widget)?[\s\]]/', $part)) {
                    $stripped = self::strip_shortcodes_to_text($part);
                    if ($stripped !== $part) {
                        $parts[$i] = $stripped;
                        $warnings[] = 'Inserimenti rimossi dai primi ' . $min_paragraphs . ' paragrafi.';
                    }
                }
            }
            $content = implode('', $parts);
        }

        // 3. Densità: massimo floor(parole/densità) inserimenti (minimo 1).
        $word_count = self::count_words(wp_strip_all_tags($content));
        $max_insertions = max(1, (int) floor($word_count / $density));
        $found = preg_match_all('/\[affiliate_link(?:s_widget)?[^\]]*\]/', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($found > $max_insertions) {
            // Conserva i primi max_insertions; gli eccessi: anchor → testo, altri → rimossi.
            $keep = $max_insertions;
            $content = preg_replace_callback('/\[affiliate_link(?:s_widget)?[^\]]*\]/', function ($m) use (&$keep) {
                if ($keep > 0) { $keep--; return $m[0]; }
                return self::shortcode_to_text($m[0]);
            }, $content);
            $warnings[] = sprintf('Densità applicata: %d inserimenti mantenuti su %d (1 ogni %d parole su %d).', $max_insertions, $found, $density, $word_count);
        }

        // 4. Mai due shortcode consecutivi senza testo tra loro: il secondo
        //    viene degradato (anchor → testo, altri → rimossi).
        $content = preg_replace_callback(
            '/(\[affiliate_link(?:s_widget)?[^\]]*\])(\s*(?:<\/?p[^>]*>|<br[^>]*>|\s)*)(\[affiliate_link(?:s_widget)?[^\]]*\])/',
            function ($m) use (&$warnings) {
                $warnings[] = 'Shortcode consecutivi: il secondo è stato degradato a testo.';
                return $m[1] . $m[2] . self::shortcode_to_text($m[3]);
            },
            $content
        );

        return array('content' => $content, 'warnings' => array_values(array_unique($warnings)));
    }

    /**
     * Conteggio parole Unicode-safe: str_word_count spezza le parole
     * accentate italiane ("città" → 2), falsando la densità.
     */
    public static function count_words($text) {
        return (int) preg_match_all('/\p{L}[\p{L}\p{N}\'\x{2019}]*/u', (string) $text, $m);
    }

    /**
     * Converte uno shortcode in testo leggibile: le anchor mantengono il
     * loro testo (la frase resta di senso compiuto), gli altri spariscono.
     */
    public static function shortcode_to_text($shortcode) {
        // \s prima di text=" evita il falso positivo su button_text=".
        if (preg_match('/\[affiliate_link[^\]]*\stext="([^"]*)"/', $shortcode, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function strip_shortcodes_to_text($html) {
        return preg_replace_callback('/\[affiliate_link(?:s_widget)?[^\]]*\]/', function ($m) {
            return self::shortcode_to_text($m[0]);
        }, $html);
    }

    /* ---------------------------------------------------------------------
     * Creazione widget dall'AI
     * ------------------------------------------------------------------ */

    /**
     * Valida la widget_request dell'AI e crea l'istanza widget nell'option
     * standard (visibile e modificabile in Elenco Widget Link).
     * Ritorna array('widget_id' => N, 'shortcode' => '[affiliate_links_widget id="N"]')
     * oppure array('error' => ...).
     */
    public static function create_widget_from_request($request, $candidate_affiliate_ids) {
        $rules = self::get_rules();
        if (!in_array('widget', $rules['patterns'], true)) {
            return array('error' => 'Pattern widget disabilitato nelle Regole inserimento.');
        }
        $request = is_array($request) ? $request : array();
        $link_ids = array_values(array_unique(array_filter(array_map('absint', (array)($request['link_ids'] ?? array())))));
        // Solo link candidati del payload: l'AI non può inventare ID.
        $link_ids = array_values(array_intersect($link_ids, array_map('absint', (array)$candidate_affiliate_ids)));
        $link_ids = array_slice($link_ids, 0, $rules['widget_max_links']);
        if (count($link_ids) < 2) {
            return array('error' => 'widget_request ignorata: servono almeno 2 link candidati validi.');
        }

        $rewritten = array();
        foreach ((array)($request['rewritten'] ?? array()) as $row) {
            if (!is_array($row)) { continue; }
            $rid = absint($row['id'] ?? 0);
            $rtitle = sanitize_text_field((string)($row['title'] ?? ''));
            $rdesc = sanitize_textarea_field((string)($row['description'] ?? ''));
            if ($rid > 0 && in_array($rid, $link_ids, true) && $rtitle !== '' && $rdesc !== '') {
                $rewritten[(string)$rid] = array('title' => $rtitle, 'description' => $rdesc);
            }
        }

        $columns = max(1, min(3, count($link_ids)));
        $instance = array(
            'title' => sanitize_text_field((string)($request['title'] ?? '')) ?: __('Esperienze consigliate', 'affiliate-link-manager-ai'),
            'custom_content' => '',
            'show_image' => 1,
            'show_title' => 1,
            'show_content' => 1,
            'show_button' => 1,
            'button_text' => sanitize_text_field((string)($request['button_text'] ?? '')) ?: $rules['button_text'],
            'template_desktop_columns' => $columns,
            'template_mobile_columns' => 1,
            'layout_preset' => 'columns_' . $columns,
            'links' => $link_ids,
            'rewritten_links' => $rewritten,
            'alma_created_by' => 'ai_agent',
        );

        $instances = get_option('widget_affiliate_links_widget', array());
        if (!is_array($instances)) { $instances = array(); }
        $multiwidget = $instances['_multiwidget'] ?? 1;
        unset($instances['_multiwidget']);
        $numeric_ids = array_filter(array_map('intval', array_keys($instances)));
        $next_id = empty($numeric_ids) ? 2 : (max($numeric_ids) + 1);
        $instances[$next_id] = $instance;
        $instances['_multiwidget'] = $multiwidget;
        update_option('widget_affiliate_links_widget', $instances);

        return array(
            'widget_id' => $next_id,
            'shortcode' => '[affiliate_links_widget id="' . $next_id . '"]',
        );
    }

    /**
     * Applica la widget_request al contenuto: crea il widget e sostituisce il
     * segnaposto (o accoda in fondo se il segnaposto manca). Ritorna
     * array('content','warnings','widget_id').
     */
    public static function apply_widget_request($content, $request, $candidate_affiliate_ids) {
        $warnings = array();
        $widget_id = 0;
        $has_placeholder = strpos($content, self::WIDGET_PLACEHOLDER) !== false;

        if (empty($request) && !$has_placeholder) {
            return array('content' => $content, 'warnings' => $warnings, 'widget_id' => 0);
        }
        if (empty($request)) {
            // Segnaposto senza richiesta: va rimosso, non deve arrivare in pagina.
            return array('content' => str_replace(self::WIDGET_PLACEHOLDER, '', $content), 'warnings' => array('Segnaposto widget senza widget_request: rimosso.'), 'widget_id' => 0);
        }

        $created = self::create_widget_from_request($request, $candidate_affiliate_ids);
        if (!empty($created['error'])) {
            $warnings[] = $created['error'];
            $content = str_replace(self::WIDGET_PLACEHOLDER, '', $content);
            return array('content' => $content, 'warnings' => $warnings, 'widget_id' => 0);
        }
        $widget_id = (int) $created['widget_id'];
        if ($has_placeholder) {
            $content = str_replace(self::WIDGET_PLACEHOLDER, $created['shortcode'], $content);
        } else {
            $content .= "\n\n" . $created['shortcode'];
            $warnings[] = 'Segnaposto widget mancante: widget aggiunto in coda all\'articolo.';
        }
        return array('content' => $content, 'warnings' => $warnings, 'widget_id' => $widget_id);
    }
}
