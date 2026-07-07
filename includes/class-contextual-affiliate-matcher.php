<?php
/**
 * Deterministic contextual affiliate link matcher for the sidebar widget.
 *
 * v2: il matching è ancorato alla pagina corrente su tre livelli:
 * 1. Geo Index (segnale dominante): località condivise tra articolo e link.
 * 2. Selezione candidati per pertinenza (Geo Index + affiliate index AI),
 *    non più solo gli ultimi N link per data.
 * 3. Scoring graduato: le keyword in comune pesano per quantità e rarità
 *    sul pool di candidati, con match a parola intera.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Contextual_Affiliate_Matcher {
    const DEFAULT_CANDIDATE_LIMIT = 200;
    const MAX_POOL_SIZE = 300;
    // Inclusa nell'hash della cache del widget: cambiarla invalida i risultati
    // calcolati con versioni precedenti dell'algoritmo.
    const MATCHER_VERSION = 8;
    // Sopra questa soglia il segnale geografico è "locale" (stessa città,
    // contenimento paese/regione o stessa regione): quando almeno un
    // risultato è locale, quelli senza segnale locale vengono scartati.
    const GEO_DOMINANCE_THRESHOLD = 20;
    // Bonus di compatibilità dei link di tipologia UNIVERSALE (assicurazioni,
    // eSIM…): neutro-positivo, competono con le keyword ovunque.
    const UNIVERSAL_GEO_SCORE = 15;
    // Nel widget al massimo 1 link universale: le esperienze locali restano
    // protagoniste, l'universale è il complemento.
    const MAX_UNIVERSAL_RESULTS = 1;

    private $settings;
    private $geo_store = null;
    private $geo_tables_exist = null;

    public function __construct($settings = array()) {
        $this->settings = wp_parse_args($settings, array(
            'max_links' => 4,
            'min_score' => 40,
            'exclude_existing_links' => 'yes',
            'candidate_limit' => self::DEFAULT_CANDIDATE_LIMIT,
        ));
    }

    public function match($post, $settings = array()) {
        if (!$post instanceof WP_Post) {
            $post = get_post($post);
        }

        if (!$post || !in_array($post->post_status, array('publish', 'private'), true)) {
            return array();
        }

        $settings = wp_parse_args($settings, $this->settings);
        $signals = $this->extract_post_signals($post);
        $signals['locations'] = $this->get_object_locations($post->ID, $post->post_type);
        $candidates = $this->get_candidates($signals, absint($settings['candidate_limit']));
        if (empty($candidates)) {
            return array();
        }

        $profiles = array();
        foreach ($candidates as $candidate) {
            $profile = $this->build_candidate_profile($candidate, $signals, $settings);
            if ($profile) {
                $profiles[] = $profile;
            }
        }
        if (empty($profiles)) {
            return array();
        }

        // Document frequency delle keyword sul pool: le parole presenti in quasi
        // tutti i candidati (es. "tour") pesano poco, quelle rare (es. un nome
        // di città) pesano molto.
        $df = array();
        foreach ($profiles as $profile) {
            foreach (array_unique($profile['keywords']) as $keyword) {
                $df[$keyword] = ($df[$keyword] ?? 0) + 1;
            }
        }
        $pool_size = count($profiles);

        $geo_map = $this->get_locations_for_links(wp_list_pluck($profiles, 'id'));

        $results = array();
        $min_score = max(0, min(100, absint($settings['min_score'])));
        foreach ($profiles as $profile) {
            $universal = class_exists('ALMA_Universal_Link_Types') && ALMA_Universal_Link_Types::is_universal_link($profile['id']);
            $link_locations = $geo_map[$profile['id']] ?? array();
            // Tipologia universale: nessuna località per definizione, riceve
            // un bonus di compatibilità neutro invece del punteggio geo.
            $geo = $universal ? self::UNIVERSAL_GEO_SCORE : $this->geo_score($signals['locations'], $link_locations);
            $score = $this->score_profile($profile, $signals, $df, $pool_size, $geo);
            if ($score < $min_score) {
                continue;
            }
            $results[] = array(
                'id' => $profile['id'],
                'score' => $score,
                'geo' => $geo,
                'universal' => $universal,
                'click_count' => $profile['click_count'],
                'title' => $profile['display_title'],
                'affiliate_url' => $profile['affiliate_url'],
                'has_image' => $profile['has_image'],
            );
        }

        // Dominanza geografica: su un articolo localizzato (es. Cefalù), se
        // esistono risultati locali non devono comparire link di altre zone
        // saliti solo con keyword generiche ("tour", "centro storico"…).
        // I link universali sono esenti (validi ovunque per definizione).
        $results = self::apply_geo_dominance($results, self::GEO_DOMINANCE_THRESHOLD);

        usort($results, array($this, 'sort_results'));

        // Slot universale: al massimo 1 nei risultati, con rotazione
        // giornaliera tra gli universali quasi a pari punteggio.
        return self::apply_universal_slot($results, max(1, absint($settings['max_links'])), (int) current_time('z'), self::MAX_UNIVERSAL_RESULTS);
    }

    public function extract_post_signals($post) {
        if (!$post instanceof WP_Post) {
            $post = get_post($post);
        }

        $raw_content = (string) $post->post_content;
        $plain_content = $this->normalize_text(wp_strip_all_tags(strip_shortcodes($raw_content)));
        $title = $this->normalize_text(get_the_title($post));
        $slug = $this->normalize_text((string) $post->post_name);
        $excerpt = $this->normalize_text(wp_strip_all_tags(get_the_excerpt($post)));
        $headings = $this->extract_headings($raw_content);
        $taxonomy_terms = $this->get_post_taxonomy_terms($post->ID);
        $combined = trim($title . ' ' . $slug . ' ' . $excerpt . ' ' . implode(' ', $headings) . ' ' . implode(' ', $taxonomy_terms) . ' ' . $plain_content);

        return array(
            'id' => (int) $post->ID,
            'post_type' => (string) $post->post_type,
            'title' => $title,
            'slug' => $slug,
            'content' => $plain_content,
            'raw_content' => $raw_content,
            'excerpt' => $excerpt,
            'headings' => $headings,
            'taxonomy_terms' => array_values(array_unique($taxonomy_terms)),
            'keywords' => $this->extract_keywords($combined, 60),
            'combined' => $combined,
            'locations' => array(),
        );
    }

    /**
     * Selezione dei candidati orientata alla pagina: prima i link che condividono
     * una località con l'articolo, poi quelli pertinenti per keyword secondo
     * l'affiliate index AI, infine i più recenti come riempimento (comportamento
     * storico, garantisce che il pool non sia mai peggiore di prima).
     */
    private function get_candidates($signals, $limit) {
        $limit = max(1, min(self::DEFAULT_CANDIDATE_LIMIT, $limit));
        $ids = array();

        foreach ($this->get_geo_candidate_ids($signals['locations'], 150) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->get_keyword_candidate_ids($signals['keywords'], 150) as $id) {
            $ids[$id] = true;
        }

        if (count($ids) < self::MAX_POOL_SIZE) {
            $recent = get_posts(array(
                'post_type' => 'affiliate_link',
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
                'fields' => 'ids',
            ));
            foreach ($recent as $id) {
                if (count($ids) >= self::MAX_POOL_SIZE) {
                    break;
                }
                $ids[absint($id)] = true;
            }
        }

        $ids = array_slice(array_keys($ids), 0, self::MAX_POOL_SIZE);
        if (empty($ids)) {
            return array();
        }

        return get_posts(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'post__in' => $ids,
            'posts_per_page' => count($ids),
            'orderby' => 'post__in',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ));
    }

    private function get_geo_candidate_ids($post_locations, $limit) {
        if (empty($post_locations) || !$this->geo_tables_available()) {
            return array();
        }
        $location_ids = array();
        $location_names = array();
        $country_codes = array();
        $country_names = array();
        foreach ($post_locations as $location) {
            $location = (array) $location;
            $location_id = absint($location['location_id'] ?? 0);
            if ($location_id > 0) {
                $location_ids[] = $location_id;
            }
            foreach (array('city', 'canonical_name', 'name') as $field) {
                $name = trim((string) ($location[$field] ?? ''));
                if ($name !== '' && mb_strlen($name) >= 3) {
                    $location_names[] = $name;
                }
            }
            $code = strtoupper(trim((string) ($location['country_code'] ?? '')));
            if ($code !== '') {
                $country_codes[] = $code;
            }
            $country_name = trim((string) ($location['country'] ?? ''));
            if ($country_name !== '') {
                $country_names[] = $country_name;
            }
            // Articolo su un intero paese: il nome della località è il paese stesso.
            if (sanitize_key((string) ($location['type'] ?? '')) === 'country') {
                $canonical = trim((string) ($location['canonical_name'] ?? ($location['name'] ?? '')));
                if ($canonical !== '') {
                    $country_names[] = $canonical;
                }
            }
        }
        $location_ids = array_values(array_unique($location_ids));
        $location_names = array_slice(array_values(array_unique($location_names)), 0, 10);
        $country_codes = array_slice(array_values(array_unique($country_codes)), 0, 10);
        $country_names = array_slice(array_values(array_unique($country_names)), 0, 10);
        if (empty($location_ids) && empty($location_names) && empty($country_codes) && empty($country_names)) {
            return array();
        }

        global $wpdb;
        $store = $this->get_geo_store();
        $conditions = array();
        $params = array(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK);
        if (!empty($location_ids)) {
            $conditions[] = 'ci.location_id IN (' . implode(',', array_fill(0, count($location_ids), '%d')) . ')';
            $params = array_merge($params, $location_ids);
        }
        if (!empty($location_names)) {
            // Stesse città salvate come righe località diverse (import differenti,
            // es. "Copenaghen" da CSV e da geocoding): match anche per nome.
            $name_placeholders = implode(',', array_fill(0, count($location_names), '%s'));
            $conditions[] = "l.city IN ($name_placeholders)";
            $conditions[] = "l.canonical_name IN ($name_placeholders)";
            $params = array_merge($params, $location_names, $location_names);
        }
        if (!empty($country_codes)) {
            // Articolo localizzato su interi paesi (es. "Maldive vs Seychelles"):
            // candidati tutti i link con località in quei paesi.
            $code_placeholders = implode(',', array_fill(0, count($country_codes), '%s'));
            $conditions[] = "l.country_code IN ($code_placeholders)";
            $params = array_merge($params, $country_codes);
        }
        if (!empty($country_names)) {
            $cname_placeholders = implode(',', array_fill(0, count($country_names), '%s'));
            $conditions[] = "l.country IN ($cname_placeholders)";
            $params = array_merge($params, $country_names);
        }
        $params[] = max(1, absint($limit));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ci.object_id FROM {$store->table_content_index()} ci
             LEFT JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_type = %s AND (" . implode(' OR ', $conditions) . ') LIMIT %d',
            $params
        ));
        return array_map('absint', (array) $ids);
    }

    private function get_keyword_candidate_ids($keywords, $limit) {
        if (empty($keywords) || !class_exists('ALMA_AI_Content_Agent_Affiliate_Index')) {
            return array();
        }
        $table = ALMA_AI_Content_Agent_Affiliate_Index::table_name();
        if (in_array($table, ALMA_AI_Content_Agent_Store::missing_tables(), true)) {
            return array();
        }

        global $wpdb;
        $conditions = array();
        $params = array(ALMA_AI_Content_Agent_Affiliate_Index::STATUS_ACTIVE);
        foreach (array_slice((array) $keywords, 0, 10) as $keyword) {
            $keyword = $this->normalize_text($keyword);
            if (strlen($keyword) < 4) {
                continue;
            }
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $conditions[] = '(title LIKE %s OR keywords LIKE %s OR normalized_text LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (empty($conditions)) {
            return array();
        }
        $params[] = max(1, absint($limit));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT affiliate_link_id FROM {$table} WHERE status = %s AND post_status = 'publish' AND affiliate_url_present = 1 AND affiliate_link_id > 0 AND (" . implode(' OR ', $conditions) . ") LIMIT %d",
            $params
        ));
        return array_map('absint', (array) $ids);
    }

    private function build_candidate_profile($candidate, $signals, $settings) {
        if (!$candidate instanceof WP_Post || $candidate->post_type !== 'affiliate_link' || $candidate->post_status !== 'publish') {
            return null;
        }

        $affiliate_url = trim((string) get_post_meta($candidate->ID, '_affiliate_url', true));
        if ($affiliate_url === '') {
            return null;
        }

        // Mai proporre link in quarantena (prodotto rimosso/404 confermato).
        if (class_exists('ALMA_Link_Health_Checker') && ALMA_Link_Health_Checker::is_dead($candidate->ID)) {
            return null;
        }

        $already_present = $this->is_link_already_present($candidate->ID, $affiliate_url, $signals['raw_content']);
        if ($already_present && ($settings['exclude_existing_links'] ?? 'yes') === 'yes') {
            return null;
        }

        $link_title = $this->normalize_text(get_the_title($candidate));
        $link_content = $this->normalize_text(wp_strip_all_tags(strip_shortcodes((string) $candidate->post_content)));
        $ai_context = $this->normalize_text((string) get_post_meta($candidate->ID, '_alma_ai_context', true));
        $source_text = $this->normalize_text($this->get_candidate_source_text($candidate->ID));
        $link_types = $this->get_link_type_terms($candidate->ID);

        return array(
            'id' => (int) $candidate->ID,
            'title' => $link_title,
            'display_title' => get_the_title($candidate),
            'affiliate_url' => $affiliate_url,
            'ai_context' => $ai_context,
            'link_types' => $link_types,
            'keywords' => $this->extract_keywords(trim($link_title . ' ' . $link_content . ' ' . $ai_context . ' ' . $source_text . ' ' . implode(' ', $link_types)), 30),
            'click_count' => absint(get_post_meta($candidate->ID, '_click_count', true)),
            'has_image' => has_post_thumbnail($candidate->ID),
            'already_present' => $already_present,
        );
    }

    /**
     * Se almeno un risultato ha un segnale geografico "locale" (>= soglia:
     * stessa città, contenimento o stessa regione), i risultati senza quel
     * segnale vengono scartati: un link di Milano non deve riempire il
     * widget di un articolo su Cefalù solo perché condivide keyword
     * generiche. Senza risultati locali il comportamento resta invariato.
     * Pura, testabile.
     */
    public static function apply_geo_dominance($results, $threshold = self::GEO_DOMINANCE_THRESHOLD) {
        $best_geo = 0;
        foreach ((array) $results as $result) {
            if (!empty($result['universal'])) { continue; }
            $best_geo = max($best_geo, (int) ($result['geo'] ?? 0));
        }
        if ($best_geo < $threshold) {
            return $results;
        }
        return array_values(array_filter((array) $results, function ($result) use ($threshold) {
            return !empty($result['universal']) || (int) ($result['geo'] ?? 0) >= $threshold;
        }));
    }

    /**
     * Slot universale: nei primi $max_links risultati possono entrare al
     * massimo $max_universal link di tipologie universali. Tra gli
     * universali quasi a pari punteggio (entro 5 punti dal migliore) si
     * ruota in modo deterministico col giorno dell'anno, per non mostrare
     * sempre la stessa assicurazione ovunque. Pura, testabile.
     */
    public static function apply_universal_slot($results, $max_links, $day_seed = 0, $max_universal = self::MAX_UNIVERSAL_RESULTS) {
        $results = array_values((array) $results);
        $universal_indexes = array();
        foreach ($results as $i => $result) {
            if (!empty($result['universal'])) { $universal_indexes[] = $i; }
        }
        if (count($universal_indexes) > $max_universal && $max_universal >= 1) {
            // Rotazione tra i quasi-migliori.
            $best_score = (int) $results[$universal_indexes[0]]['score'];
            $near_best = array();
            foreach ($universal_indexes as $i) {
                if ((int) $results[$i]['score'] >= $best_score - 5) { $near_best[] = $i; }
            }
            $chosen = $near_best[absint($day_seed) % count($near_best)];
            $keep = array_slice(array_merge(array($chosen), array_values(array_diff($universal_indexes, array($chosen)))), 0, $max_universal);
            foreach ($universal_indexes as $i) {
                if (!in_array($i, $keep, true)) { unset($results[$i]); }
            }
            $results = array_values($results);
        } elseif ($max_universal < 1 && !empty($universal_indexes)) {
            foreach ($universal_indexes as $i) { unset($results[$i]); }
            $results = array_values($results);
        }
        return array_slice($results, 0, max(1, absint($max_links)));
    }

    private function score_profile($profile, $signals, $df, $pool_size, $geo_score) {
        $score = 0;

        // 1) Geo Index: il segnale dominante (fino a 45, penalità se le località
        //    sono esplicitamente diverse). 0 quando una delle due parti non ha dati.
        $score += (int) $geo_score;

        // 2) Frase esatta: titolo del link contenuto nel titolo o negli heading.
        if ($profile['title'] !== '' && $this->contains_phrase($signals['title'], $profile['title'])) {
            $score += 25;
        } elseif ($profile['title'] !== '' && $this->contains_phrase(implode(' ', $signals['headings']), $profile['title'])) {
            $score += 18;
        }

        // 3) Keyword graduate per quantità e rarità (fino a 30).
        $score += $this->keyword_score($profile['keywords'], $signals, $df, $pool_size);

        // 4) Tipologia link ↔ categorie/tag dell'articolo.
        if ($this->has_term_overlap($profile['link_types'], $signals['taxonomy_terms'])) {
            $score += 10;
        }

        // 5) Contesto AI del link presente nel titolo/contenuto dell'articolo.
        if ($profile['ai_context'] !== '' && $this->context_matches($profile['ai_context'], $signals)) {
            $score += 10;
        }

        // 6) Popolarità: contributo minimo, non deve dominare la contestualità.
        if ($profile['click_count'] > 0) {
            $score += min(3, (int) floor(log($profile['click_count'] + 1, 2)));
        }

        // Con "escludi link già presenti" = no, il link può comparire ma viene
        // leggermente penalizzato (il vecchio -100 lo azzerava sempre, rendendo
        // l'opzione di fatto inefficace).
        if ($profile['already_present']) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    /**
     * Confronta le località dell'articolo con quelle del link.
     * Punteggio migliore trovato (non additivo):
     * - stessa località/città: 40 (+5 se primaria per entrambi)
     * - contenimento paese/regione: 35 — l'articolo parla di un intero paese
     *   (es. confronto "Maldive vs Seychelles") e il link è in quel paese
     *   (es. escursione a Malé). Senza questo livello i link corretti si
     *   fermavano al generico +10 e non superavano la soglia.
     * - stessa regione: 20; stesso paese tra due località puntuali: 10,
     *   ma -10 se ENTRAMBE dichiarano regioni e non ne condividono nessuna
     *   (blog italiano: "stesso paese" è quasi sempre vero e non basta)
     * - paesi dichiarati da entrambe le parti e disgiunti: -30 (un link di
     *   Parigi su un articolo sulle Maldive non deve poter risalire con i
     *   soli segnali testuali generici). Metadati incompleti restano neutri.
     */
    private function geo_score($post_locations, $link_locations) {
        if (empty($post_locations) || empty($link_locations)) {
            return 0;
        }

        $post_index = $this->build_location_index($post_locations);
        $link_index = $this->build_location_index($link_locations);

        if (!empty(array_intersect($post_index['location_ids'], $link_index['location_ids']))) {
            $primary_match = !empty(array_intersect($post_index['primary_location_ids'], $link_index['primary_location_ids']));
            return $primary_match ? 45 : 40;
        }

        // Match per nome città: i paesi devono essere "compatibili" (uguali,
        // oppure sconosciuti da almeno una parte), non necessariamente identici.
        $city_match = false;
        $city_primary_match = false;
        foreach ($post_index['cities'] as $name => $post_entry) {
            if (!isset($link_index['cities'][$name])) {
                continue;
            }
            $link_entry = $link_index['cities'][$name];
            if (!$this->countries_compatible($post_entry['countries'], $link_entry['countries'])) {
                continue;
            }
            $city_match = true;
            if ($post_entry['primary'] && $link_entry['primary']) {
                $city_primary_match = true;
                break;
            }
        }
        if ($city_match) {
            return $city_primary_match ? 45 : 40;
        }

        // Contenimento: una delle due parti È un paese/regione e l'altra ha
        // località in quel paese (per codice o, in mancanza, per nome).
        if (!empty(array_intersect($post_index['scope_countries'], $link_index['countries']))
            || !empty(array_intersect($post_index['countries'], $link_index['scope_countries']))
            || !empty(array_intersect($post_index['scope_names'], $link_index['country_names']))
            || !empty(array_intersect($post_index['country_names'], $link_index['scope_names']))) {
            return 35;
        }

        foreach ($post_index['regions'] as $name => $post_countries) {
            if (isset($link_index['regions'][$name]) && $this->countries_compatible($post_countries, $link_index['regions'][$name])) {
                return 20;
            }
        }

        if (!empty(array_intersect($post_index['countries'], $link_index['countries']))
            || !empty(array_intersect($post_index['country_names'], $link_index['country_names']))) {
            // Stesso paese ma regioni dichiarate da ENTRAMBE le parti e senza
            // intersezione (il match stessa-regione è già stato tentato sopra):
            // su un blog italiano "stesso paese" è quasi sempre vero, quindi un
            // link di Milano su un articolo su Cefalù è esplicitamente altrove.
            // Con regioni incomplete si resta neutri-positivi come prima.
            if (!empty($post_index['regions']) && !empty($link_index['regions'])) {
                return -10;
            }
            return 10;
        }

        // Penalità solo per disaccordo esplicito: entrambe le parti dichiarano
        // paesi noti e non hanno nulla in comune. Vale sia per i country code
        // sia, in loro assenza, per i NOMI paese: una località "Parigi, Francia"
        // non ancora geocodificata (senza codice) non deve sfuggire alla
        // penalità su un articolo localizzato in Algeria.
        if (!empty($post_index['countries']) && !empty($link_index['countries'])) {
            return -30;
        }
        if (!empty($post_index['country_names']) && !empty($link_index['country_names'])) {
            return -30;
        }
        return 0;
    }

    /**
     * Due insiemi di country code sono compatibili se almeno uno è vuoto
     * (paese sconosciuto = jolly) o se hanno un codice in comune.
     */
    private function countries_compatible($left, $right) {
        if (empty($left) || empty($right)) {
            return true;
        }
        return !empty(array_intersect($left, $right));
    }

    private function build_location_index($locations) {
        $index = array(
            'location_ids' => array(),
            'primary_location_ids' => array(),
            'cities' => array(),          // nome normalizzato => array('countries' => [], 'primary' => bool)
            'regions' => array(),         // nome normalizzato => array di country code
            'countries' => array(),       // country code di tutte le località
            'country_names' => array(),   // nomi paese normalizzati di tutte le località
            'scope_countries' => array(), // country code delle località che SONO un paese/regione
            'scope_names' => array(),     // nomi delle località paese/regione (fallback senza codice)
        );
        foreach ((array) $locations as $location) {
            $location = (array) $location;
            $location_id = absint($location['location_id'] ?? 0);
            $type = sanitize_key((string) ($location['type'] ?? ''));
            $canonical = $this->normalize_text((string) ($location['canonical_name'] ?? ($location['name'] ?? '')));
            $city = $this->normalize_text((string) ($location['city'] ?? ''));
            if ($city === '') {
                // Per località non-città (POI, aree) usa il nome canonico come chiave.
                $city = $canonical;
            }
            $country = $this->normalize_text((string) ($location['country_code'] ?? ''));
            $country_name = $this->normalize_text((string) ($location['country'] ?? ''));
            $region = $this->normalize_text((string) ($location['region'] ?? ''));
            $is_primary = !empty($location['is_primary']) || (($location['role'] ?? '') === 'main_destination');

            if ($location_id > 0) {
                $index['location_ids'][] = $location_id;
                if ($is_primary) {
                    $index['primary_location_ids'][] = $location_id;
                }
            }
            if ($city !== '') {
                if (!isset($index['cities'][$city])) {
                    $index['cities'][$city] = array('countries' => array(), 'primary' => false);
                }
                if ($country !== '') {
                    $index['cities'][$city]['countries'][] = $country;
                }
                if ($is_primary) {
                    $index['cities'][$city]['primary'] = true;
                }
            }
            if ($country_name !== '') {
                $index['country_names'][] = $country_name;
            }
            if (in_array($type, array('country', 'region'), true)) {
                // La località stessa è un paese/una regione: abilita il match di
                // contenimento con le località puntuali dell'altra parte.
                if ($country !== '') {
                    $index['scope_countries'][] = $country;
                }
                if ($canonical !== '') {
                    $index['scope_names'][] = $canonical;
                }
                if ($type === 'country' && $canonical !== '') {
                    $index['country_names'][] = $canonical;
                }
            }
            if ($region !== '') {
                if (!isset($index['regions'][$region])) {
                    $index['regions'][$region] = array();
                }
                if ($country !== '') {
                    $index['regions'][$region][] = $country;
                }
            }
            if ($country !== '') {
                $index['countries'][] = $country;
            }
        }
        $index['location_ids'] = array_values(array_unique($index['location_ids']));
        $index['primary_location_ids'] = array_values(array_unique($index['primary_location_ids']));
        $index['countries'] = array_values(array_unique($index['countries']));
        $index['country_names'] = array_values(array_unique($index['country_names']));
        $index['scope_countries'] = array_values(array_unique($index['scope_countries']));
        $index['scope_names'] = array_values(array_unique($index['scope_names']));
        foreach ($index['cities'] as $name => $entry) {
            $index['cities'][$name]['countries'] = array_values(array_unique($entry['countries']));
        }
        foreach ($index['regions'] as $name => $countries) {
            $index['regions'][$name] = array_values(array_unique($countries));
        }
        return $index;
    }

    /**
     * Punteggio keyword graduato (0-30): ogni keyword del link trovata come
     * parola intera nell'articolo vale 1-6 punti in base alla sua rarità sul
     * pool di candidati; il valore raddoppia se compare nel titolo o negli heading.
     */
    private function keyword_score($keywords, $signals, $df, $pool_size) {
        if ($pool_size < 1) {
            return 0;
        }
        $content_hay = ' ' . $signals['combined'] . ' ';
        $title_hay = ' ' . $signals['title'] . ' ';
        $headings_hay = ' ' . implode(' ', $signals['headings']) . ' ';
        $total = 0.0;
        foreach (array_unique((array) $keywords) as $keyword) {
            if (strlen($keyword) < 4) {
                continue;
            }
            $needle = ' ' . $keyword . ' ';
            if (strpos($content_hay, $needle) === false) {
                continue;
            }
            $rarity = 1 - (($df[$keyword] ?? 1) / max(1, $pool_size));
            $points = 1 + 5 * $rarity;
            if (strpos($title_hay, $needle) !== false || strpos($headings_hay, $needle) !== false) {
                $points *= 2;
            }
            $total += $points;
        }
        return (int) min(30, round($total));
    }

    private function get_object_locations($object_id, $object_type) {
        if (!$this->geo_tables_available()) {
            return array();
        }
        $object_type = sanitize_key($object_type);
        if (!in_array($object_type, array(ALMA_Geo_Index_Store::OBJECT_TYPE_POST, ALMA_Geo_Index_Store::OBJECT_TYPE_PAGE, ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK), true)) {
            return array();
        }
        return (array) $this->get_geo_store()->get_associated_locations_for_object($object_id, $object_type);
    }

    /**
     * Località di tutti i link candidati in una sola query (evita una query per candidato).
     * Ritorna una mappa link_id => array di località.
     */
    private function get_locations_for_links($link_ids) {
        $link_ids = array_values(array_filter(array_map('absint', (array) $link_ids)));
        if (empty($link_ids) || !$this->geo_tables_available()) {
            return array();
        }

        global $wpdb;
        $store = $this->get_geo_store();
        $placeholders = implode(',', array_fill(0, count($link_ids), '%d'));
        $params = array_merge(array(ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK), $link_ids);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ci.object_id, ci.is_primary, l.id AS location_id, l.canonical_name, l.type, l.city, l.region, l.country, l.country_code
             FROM {$store->table_content_index()} ci
             LEFT JOIN {$store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_type = %s AND ci.object_id IN ($placeholders)",
            $params
        ), ARRAY_A);

        $map = array();
        foreach ((array) $rows as $row) {
            $object_id = absint($row['object_id'] ?? 0);
            if ($object_id < 1) {
                continue;
            }
            $map[$object_id][] = array(
                'location_id' => absint($row['location_id'] ?? 0),
                'canonical_name' => (string) ($row['canonical_name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'country_code' => (string) ($row['country_code'] ?? ''),
                'is_primary' => !empty($row['is_primary']),
            );
        }
        return $map;
    }

    private function get_geo_store() {
        if ($this->geo_store === null) {
            $this->geo_store = new ALMA_Geo_Index_Store();
        }
        return $this->geo_store;
    }

    private function geo_tables_available() {
        if (!class_exists('ALMA_Geo_Index_Store')) {
            return false;
        }
        if ($this->geo_tables_exist === null) {
            $this->geo_tables_exist = $this->get_geo_store()->tables_exist();
        }
        return $this->geo_tables_exist;
    }

    private function sort_results($a, $b) {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        if ($a['click_count'] !== $b['click_count']) {
            return $b['click_count'] <=> $a['click_count'];
        }
        return strcasecmp((string) $a['title'], (string) $b['title']);
    }

    private function is_link_already_present($link_id, $affiliate_url, $raw_content) {
        $raw_content = (string) $raw_content;
        if ($raw_content === '') {
            return false;
        }

        if (preg_match('/\[affiliate_link\b[^\]]*\bid=["\']?' . preg_quote((string) absint($link_id), '/') . '\b/i', $raw_content)) {
            return true;
        }

        return $affiliate_url !== '' && strpos($raw_content, $affiliate_url) !== false;
    }

    private function extract_headings($content) {
        $headings = array();
        if (preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $content, $matches)) {
            foreach ($matches[1] as $heading) {
                $normalized = $this->normalize_text(wp_strip_all_tags($heading));
                if ($normalized !== '') {
                    $headings[] = $normalized;
                }
            }
        }
        return $headings;
    }

    private function get_post_taxonomy_terms($post_id) {
        $terms = array();
        foreach (array('category', 'post_tag') as $taxonomy) {
            $objects = get_the_terms($post_id, $taxonomy);
            if (is_wp_error($objects) || empty($objects)) {
                continue;
            }
            foreach ($objects as $term) {
                $terms[] = $this->normalize_text($term->name);
                $terms[] = $this->normalize_text($term->slug);
            }
        }
        return array_filter($terms);
    }

    private function get_link_type_terms($post_id) {
        $terms = get_the_terms($post_id, 'link_type');
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $names = array();
        foreach ($terms as $term) {
            $names[] = $this->normalize_text($term->name);
            $names[] = $this->normalize_text($term->slug);
        }
        return array_values(array_unique(array_filter($names)));
    }

    private function get_candidate_source_text($post_id) {
        $pieces = array();
        foreach (array('_alma_source_name', '_alma_provider', '_alma_source_provider', '_alma_source_type', '_alma_source_id') as $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (is_scalar($value) && (string) $value !== '') {
                $pieces[] = (string) $value;
            }
        }
        return implode(' ', $pieces);
    }

    private function context_matches($context, $signals) {
        if ($this->contains_phrase($signals['title'], $context) || $this->contains_phrase($signals['content'], $context)) {
            return true;
        }

        $context_keywords = $this->extract_keywords($context, 20);
        return $this->has_keyword_overlap($context_keywords, array($signals['title'], $signals['content']));
    }

    private function has_term_overlap($left, $right) {
        $left = array_filter(array_map(array($this, 'normalize_text'), (array) $left));
        $right = array_filter(array_map(array($this, 'normalize_text'), (array) $right));
        foreach ($left as $term) {
            if (in_array($term, $right, true)) {
                return true;
            }
        }
        return false;
    }

    private function has_keyword_overlap($keywords, $haystacks) {
        foreach ((array) $keywords as $keyword) {
            $keyword = $this->normalize_text($keyword);
            if ($keyword === '' || strlen($keyword) < 4) {
                continue;
            }
            foreach ((array) $haystacks as $haystack) {
                if ($this->contains_phrase($haystack, $keyword)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function contains_phrase($haystack, $needle) {
        $haystack = $this->normalize_text($haystack);
        $needle = $this->normalize_text($needle);
        if ($haystack === '' || $needle === '') {
            return false;
        }

        if (strlen($needle) > 70) {
            return false;
        }

        // Solo match a parola/frasi intere: il fallback substring produceva
        // falsi positivi sistematici ("roma" dentro "romantico").
        return strpos(' ' . $haystack . ' ', ' ' . $needle . ' ') !== false;
    }

    private function extract_keywords($text, $limit = 30) {
        $text = $this->normalize_text($text);
        if ($text === '') {
            return array();
        }

        preg_match_all('/[a-z0-9àèéìòùáíóúäëïöüñç]{4,}/iu', $text, $matches);
        $stopwords = $this->get_stopwords();
        $counts = array();
        foreach ($matches[0] as $word) {
            $word = $this->normalize_text($word);
            if ($word === '' || isset($stopwords[$word])) {
                continue;
            }
            if (!isset($counts[$word])) {
                $counts[$word] = 0;
            }
            $counts[$word]++;
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, max(1, absint($limit)));
    }

    private function normalize_text($text) {
        $text = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function get_stopwords() {
        static $stopwords = null;
        if ($stopwords !== null) {
            return $stopwords;
        }

        $words = array('alla','allo','agli','alle','anche','avere','come','con','dai','dal','dalla','delle','degli','dei','del','dell','dello','dove','dopo','esta','fare','gli','hai','che','chi','cosa','come','dalla','delle','dentro','essere','il','la','lo','le','li','un','una','uno','per','piu','nel','nella','nelle','negli','non','sono','sul','sulla','sulle','tra','fra','the','and','for','with','from','this','that','your','you','are','was','were','have','has','will','not','quando','perche','ancora','molto','sempre','tutti','tutte','tutto','questa','questo','queste','questi','loro','essere','stato','stata','della','dalle','dagli','migliori','migliore','guida','cose','vedere','giorni','giorno');
        $stopwords = array_fill_keys($words, true);
        return $stopwords;
    }
}
