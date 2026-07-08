<?php
/**
 * Automatic geographic indexing for published posts and imported affiliate links.
 *
 * Pipeline a livelli:
 * 1. Meta strutturati dei provider (_alma_destination, GYG CSV city, metadata JSON)
 *    → confidenza alta → associazione automatica.
 * 2. Gazetteer testuale contro le località conosciute (alma_geo_locations):
 *    match nel titolo con località univoca → alta → automatica;
 *    match in slug/heading/contenuto o ambigui → media/bassa → coda di revisione.
 * 3. AI (opzionale, solo batch): estrazione località da titolo+estratto,
 *    sempre in coda di revisione, mai applicata automaticamente.
 *
 * Le associazioni manuali esistenti non vengono mai toccate: il motore lavora
 * esclusivamente sugli oggetti privi di righe nel content index.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Geo_Auto_Indexer {
    const STATE_OPTION = 'alma_geo_auto_index_state';
    const LOCK_OPTION = 'alma_geo_auto_index_lock';
    const LOCK_TTL = 600;
    const STATUS_META = '_alma_geo_auto_status';        // suggested | rejected | unresolved
    const SUGGESTION_META = '_alma_geo_auto_suggestion'; // payload proposte per la revisione
    const SOURCE_PROVIDER = 'auto_provider';
    const SOURCE_GAZETTEER = 'auto_gazetteer';
    const SOURCE_AI = 'auto_ai';
    const SOURCE_CONFIRMED = 'auto_confirmed';

    // Integrazione delle località citate nel contenuto dei post già
    // indicizzati (per la mappa articolo): cron asincrono, mai in save_post.
    const INTEGRATION_CRON_HOOK = 'alma_geo_integrate_post_locations';
    const INTEGRATION_OPTION = 'alma_geo_post_integration';
    const INTEGRATION_HASH_META = '_alma_geo_integration_hash';
    const INTEGRATION_NOTE_META = '_alma_geo_integration_note';
    const INTEGRATION_MAX_ADDITIONS = 8;

    const CONFIDENCE_HIGH = 0.9;
    const CONFIDENCE_MEDIUM = 0.6;
    const CONFIDENCE_LOW = 0.4;

    private $store;
    private $gazetteer = null;

    /** Coda di oggetti salvati in questa request, processati a shutdown. */
    private static $deferred_ids = array();

    public function __construct($store = null) {
        $this->store = $store instanceof ALMA_Geo_Index_Store ? $store : new ALMA_Geo_Index_Store();
    }

    public static function targets() {
        return array(
            'posts' => array('post_type' => 'post', 'object_type' => ALMA_Geo_Index_Store::OBJECT_TYPE_POST),
            'affiliate_links' => array('post_type' => 'affiliate_link', 'object_type' => ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK),
        );
    }

    /* ---------------------------------------------------------------------
     * Copertura
     * ------------------------------------------------------------------ */

    public function get_coverage() {
        global $wpdb;
        $coverage = array();
        if (!$this->store->tables_exist()) {
            return $coverage;
        }
        foreach (self::targets() as $key => $target) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
                $target['post_type']
            ));
            $indexed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                 INNER JOIN {$this->store->table_content_index()} ci ON ci.object_id = p.ID AND ci.object_type = %s
                 WHERE p.post_type = %s AND p.post_status = 'publish'",
                $target['object_type'],
                $target['post_type']
            ));
            $suggested = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = 'suggested'
                 WHERE p.post_type = %s AND p.post_status = 'publish'",
                self::STATUS_META,
                $target['post_type']
            ));
            $coverage[$key] = array(
                'total' => $total,
                'indexed' => $indexed,
                'unindexed' => max(0, $total - $indexed),
                'suggested' => $suggested,
            );
        }
        return $coverage;
    }

    /**
     * Oggetti pubblicati senza alcuna riga nel content index.
     * $skip_statuses esclude quelli già marcati (suggested/rejected/unresolved).
     */
    public function get_unindexed_ids($target_key, $limit = 50, $after_id = 0, $skip_statuses = array('suggested', 'rejected', 'unresolved')) {
        global $wpdb;
        $targets = self::targets();
        if (!isset($targets[$target_key]) || !$this->store->tables_exist()) {
            return array();
        }
        $target = $targets[$target_key];
        $skip_statuses = array_values(array_filter(array_map('sanitize_key', (array) $skip_statuses)));

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$this->store->table_content_index()} ci ON ci.object_id = p.ID AND ci.object_type = %s
                LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s
                WHERE p.post_type = %s AND p.post_status = 'publish' AND ci.id IS NULL AND p.ID > %d";
        $params = array($target['object_type'], self::STATUS_META, $target['post_type'], absint($after_id));
        if (!empty($skip_statuses)) {
            $placeholders = implode(',', array_fill(0, count($skip_statuses), '%s'));
            $sql .= " AND (st.meta_value IS NULL OR st.meta_value = '' OR st.meta_value NOT IN ($placeholders))";
            $params = array_merge($params, $skip_statuses);
        }
        $sql .= ' ORDER BY p.ID ASC LIMIT %d';
        $params[] = max(1, min(200, absint($limit)));

        return array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
    }

    /* ---------------------------------------------------------------------
     * Batch runner
     * ------------------------------------------------------------------ */

    public function process_batch($args = array()) {
        $target_key = sanitize_key($args['target'] ?? 'affiliate_links');
        $targets = self::targets();
        if (!isset($targets[$target_key])) {
            return array('success' => false, 'message' => __('Target non valido.', 'affiliate-link-manager-ai'));
        }
        $batch_size = max(1, min(100, absint($args['batch_size'] ?? 50)));
        $use_ai = !empty($args['use_ai']);
        $retry_unresolved = !empty($args['retry_unresolved']);
        $retry_rejected = !empty($args['retry_rejected']);

        if (!$this->acquire_lock()) {
            return array('success' => false, 'locked' => true, 'message' => __('Un\'altra indicizzazione automatica è già in corso: riprova tra qualche minuto.', 'affiliate-link-manager-ai'));
        }

        $report = array(
            'success' => true,
            'target' => $target_key,
            'processed' => 0,
            'auto_applied' => 0,
            'suggested' => 0,
            'unresolved' => 0,
            'errors' => 0,
            'done' => false,
            'examples' => array(),
        );

        try {
            $state = get_option(self::STATE_OPTION, array());
            if (!is_array($state)) {
                $state = array();
            }
            $cursor = absint($state[$target_key]['cursor'] ?? 0);

            $skip = array('suggested', 'rejected', 'unresolved');
            if ($retry_unresolved) {
                $skip = array_diff($skip, array('unresolved'));
            }
            if ($retry_rejected) {
                $skip = array_diff($skip, array('rejected'));
            }

            $ids = $this->get_unindexed_ids($target_key, $batch_size, $cursor, $skip);
            if (empty($ids)) {
                // Fine della coda: reset del cursore per la prossima esecuzione completa.
                $state[$target_key] = array('cursor' => 0, 'last_run' => current_time('mysql'));
                update_option(self::STATE_OPTION, $state, false);
                $report['done'] = true;
                return $report;
            }

            foreach ($ids as $post_id) {
                $result = $this->resolve_object($post_id, $use_ai);
                $report['processed']++;
                $cursor = max($cursor, $post_id);
                if (isset($report[$result['outcome']])) {
                    $report[$result['outcome']]++;
                }
                if (count($report['examples']) < 10) {
                    $report['examples'][] = array(
                        'id' => $post_id,
                        'title' => get_the_title($post_id),
                        'outcome' => $result['outcome'],
                        'method' => $result['method'],
                        'location' => $result['location_label'],
                    );
                }
            }

            $state[$target_key] = array('cursor' => $cursor, 'last_run' => current_time('mysql'));
            update_option(self::STATE_OPTION, $state, false);
        } finally {
            $this->release_lock();
        }

        $coverage = $this->get_coverage();
        $report['remaining'] = (int) ($coverage[$target_key]['unindexed'] ?? 0);
        $report['suggested_total'] = (int) ($coverage[$target_key]['suggested'] ?? 0);
        return $report;
    }

    /* ---------------------------------------------------------------------
     * Risoluzione del singolo oggetto
     * ------------------------------------------------------------------ */

    /**
     * @return array{outcome:string, method:string, location_label:string}
     *   outcome: auto_applied | suggested | unresolved | errors
     */
    public function resolve_object($post_id, $use_ai = false) {
        $none = array('outcome' => 'errors', 'method' => '', 'location_label' => '');
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return $none;
        }
        $object_type = $post->post_type === 'affiliate_link' ? ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK : ($post->post_type === 'page' ? ALMA_Geo_Index_Store::OBJECT_TYPE_PAGE : ALMA_Geo_Index_Store::OBJECT_TYPE_POST);

        // Mai sovrascrivere associazioni esistenti (manuali o importate).
        if ($this->object_is_indexed($post->ID, $object_type)) {
            return array('outcome' => 'auto_applied', 'method' => 'already_indexed', 'location_label' => '');
        }

        // Tipologie universali (assicurazioni, eSIM…): per definizione senza
        // geolocalizzazione — niente tentativi di indicizzazione né
        // geocoding a vuoto, e nessun falso "senza località".
        if ($post->post_type === 'affiliate_link' && class_exists('ALMA_Universal_Link_Types') && ALMA_Universal_Link_Types::is_universal_link($post->ID)) {
            update_post_meta($post->ID, self::STATUS_META, 'universal');
            return array('outcome' => 'auto_applied', 'method' => 'universal_type', 'location_label' => '');
        }

        // Livello 1: meta strutturati del provider (solo link affiliati).
        if ($post->post_type === 'affiliate_link') {
            $provider_locations = $this->locations_from_provider_meta($post->ID);
            if (!empty($provider_locations)) {
                $applied = $this->apply_locations($post, $object_type, $provider_locations, self::SOURCE_PROVIDER);
                if ($applied) {
                    return array('outcome' => 'auto_applied', 'method' => 'provider_meta', 'location_label' => $provider_locations[0]['name']);
                }
            }
        }

        // Livello 2: gazetteer testuale.
        $gazetteer_result = $this->resolve_via_gazetteer($post);
        if ($gazetteer_result['confidence'] === 'high' && !empty($gazetteer_result['locations'])) {
            $applied = $this->apply_locations($post, $object_type, $gazetteer_result['locations'], self::SOURCE_GAZETTEER);
            if ($applied) {
                return array('outcome' => 'auto_applied', 'method' => 'gazetteer_title', 'location_label' => $gazetteer_result['locations'][0]['name']);
            }
        }
        if (!empty($gazetteer_result['locations'])) {
            $this->save_suggestion($post->ID, $gazetteer_result['locations'], 'gazetteer', $gazetteer_result['confidence']);
            return array('outcome' => 'suggested', 'method' => 'gazetteer_' . $gazetteer_result['confidence'], 'location_label' => $gazetteer_result['locations'][0]['name']);
        }

        // Livello 3: AI (solo su richiesta esplicita del batch, mai auto-applicata).
        if ($use_ai && class_exists('ALMA_Geo_AI_Location_Extractor')) {
            $ai_locations = ALMA_Geo_AI_Location_Extractor::extract($post);
            if (is_array($ai_locations) && !empty($ai_locations)) {
                $ai_locations = $this->enrich_with_gazetteer($ai_locations);
                $this->save_suggestion($post->ID, $ai_locations, 'ai', 'medium');
                return array('outcome' => 'suggested', 'method' => 'ai', 'location_label' => $ai_locations[0]['name']);
            }
        }

        update_post_meta($post->ID, self::STATUS_META, 'unresolved');
        return array('outcome' => 'unresolved', 'method' => 'none', 'location_label' => '');
    }

    private function object_is_indexed($object_id, $object_type) {
        global $wpdb;
        if (!$this->store->tables_exist()) {
            return false;
        }
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->store->table_content_index()} WHERE object_id = %d AND object_type = %s LIMIT 1",
            absint($object_id),
            sanitize_key($object_type)
        ));
    }

    /* ---------------------------------------------------------------------
     * Livello 1 — meta strutturati provider
     * ------------------------------------------------------------------ */

    public function locations_from_provider_meta($post_id) {
        // Contesto geografico aggiuntivo dichiarato dal provider: regione e paese
        // migliorano il geocoding e la disambiguazione delle città omonime.
        $provider_region = trim((string) get_post_meta($post_id, '_alma_gyg_csv_region', true));
        if ($provider_region === '') {
            $provider_region = trim((string) get_post_meta($post_id, '_alma_viator_destination_region', true));
        }
        $provider_country = trim((string) get_post_meta($post_id, '_alma_viator_destination_country', true));

        $names = array();
        foreach (array('_alma_destination', '_alma_gyg_csv_city', '_alma_viator_destination_name') as $meta_key) {
            $value = trim((string) get_post_meta($post_id, $meta_key, true));
            if ($value !== '') {
                $names[] = $value;
            }
        }
        foreach (array('_alma_gyg_raw_summary_json', '_alma_metadata_json') as $meta_key) {
            $raw = get_post_meta($post_id, $meta_key, true);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach (array('destination', 'city', 'location') as $field) {
                    if (!empty($decoded[$field]) && is_scalar($decoded[$field])) {
                        $names[] = (string) $decoded[$field];
                    }
                }
            }
        }

        // Fallback per i link Viator importati prima della risoluzione automatica:
        // i ref numerici nel metadata JSON vengono risolti in nomi tramite il
        // catalogo destinazioni (cachato in option, una sola chiamata API).
        if (empty($names)) {
            $viator_location = $this->resolve_viator_refs_for_post($post_id);
            if (!empty($viator_location['name'])) {
                $names[] = $viator_location['name'];
                if ($provider_region === '' && $viator_location['region'] !== '') {
                    $provider_region = $viator_location['region'];
                }
                if ($provider_country === '' && $viator_location['country'] !== '') {
                    $provider_country = $viator_location['country'];
                }
            }
        }

        $locations = array();
        $seen = array();
        foreach ($names as $name) {
            $name = trim(wp_strip_all_tags($name));
            $normalized = $this->normalize_text($name);
            if ($normalized === '' || strlen($normalized) < 2 || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $matches = $this->gazetteer_lookup($normalized);
            if (count($matches) === 1) {
                // Località già conosciuta: riusa i dati canonici.
                $locations[] = $this->location_from_gazetteer_row($matches[0], self::CONFIDENCE_HIGH, self::SOURCE_PROVIDER);
            } else {
                // Nome nuovo o ambiguo: il dato è comunque strutturato (dichiarato dal
                // provider), quindi si crea/associa come città in pending geocoding.
                $locations[] = array(
                    'name' => $name,
                    'canonical_name' => $name,
                    'type' => 'city',
                    'city' => $name,
                    'country' => $provider_country,
                    'country_code' => '',
                    'region' => $provider_region,
                    'suggested_geocoding_query' => trim(implode(', ', array_filter(array($name, $provider_region, $provider_country)))),
                    'geocoding_status' => 'pending',
                    'confidence' => self::CONFIDENCE_HIGH,
                    'source' => self::SOURCE_PROVIDER,
                );
            }
        }
        if (!empty($locations)) {
            $locations[0]['is_primary'] = true;
        }
        return $locations;
    }

    /**
     * Risolve i ref destinazione Viator (metadata JSON) per link importati
     * prima dell'introduzione della risoluzione in fase di import.
     */
    private function resolve_viator_refs_for_post($post_id) {
        if (!class_exists('ALMA_Affiliate_Source_Viator_Destination_Resolver')) {
            return array();
        }
        if (get_post_meta($post_id, '_alma_provider', true) !== 'viator') {
            return array();
        }
        $raw = get_post_meta($post_id, '_alma_metadata_json', true);
        $item = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($item) || empty($item['destinations'])) {
            return array();
        }
        $source_id = absint(get_post_meta($post_id, '_alma_source_id', true));
        $source = array();
        if ($source_id > 0) {
            global $wpdb;
            $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}alma_affiliate_sources WHERE id = %d", $source_id), ARRAY_A) ?: array();
        }
        $location = ALMA_Affiliate_Source_Viator_Destination_Resolver::resolve_primary_location($item, $source);
        return is_array($location) ? $location : array();
    }

    /* ---------------------------------------------------------------------
     * Livello 2 — gazetteer testuale
     * ------------------------------------------------------------------ */

    /**
     * @return array{confidence:string, locations:array}
     *   confidence: high (titolo, match univoco) | medium (slug/heading o ambiguo) | low (solo contenuto)
     */
    public function resolve_via_gazetteer($post) {
        $empty = array('confidence' => 'none', 'locations' => array());
        $gazetteer = $this->get_gazetteer();
        if (empty($gazetteer)) {
            return $empty;
        }

        $title = $this->normalize_text(get_the_title($post));
        $slug = $this->normalize_text(str_replace(array('-', '_'), ' ', (string) $post->post_name));
        $headings = array();
        if (preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/is', (string) $post->post_content, $m)) {
            foreach ($m[1] as $h) {
                $headings[] = $this->normalize_text(wp_strip_all_tags($h));
            }
        }
        $headings_text = implode(' ', $headings);
        $content = $this->normalize_text(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));

        $title_hay = ' ' . $title . ' ';
        $slug_hay = ' ' . $slug . ' ';
        $headings_hay = ' ' . $headings_text . ' ';
        $content_hay = ' ' . $content . ' ';

        $found = array(); // name_norm => array(level, rows)
        foreach ($gazetteer as $name_norm => $rows) {
            $needle = ' ' . $name_norm . ' ';
            if (strpos($title_hay, $needle) !== false) {
                $found[$name_norm] = array('level' => 'title', 'rows' => $rows);
            } elseif (strpos($slug_hay, $needle) !== false || strpos($headings_hay, $needle) !== false) {
                $found[$name_norm] = array('level' => 'secondary', 'rows' => $rows);
            } elseif (substr_count($content_hay, $needle) >= 2) {
                // Nel corpo serve almeno una ripetizione: una singola menzione è
                // troppo debole per proporre un'associazione.
                $found[$name_norm] = array('level' => 'content', 'rows' => $rows);
            }
        }
        if (empty($found)) {
            return $empty;
        }

        // Ordina per livello (titolo > slug/heading > contenuto) e nome più lungo
        // (più specifico) per scegliere la primaria.
        $level_rank = array('title' => 0, 'secondary' => 1, 'content' => 2);
        uksort($found, function ($a, $b) use ($found, $level_rank) {
            $ra = $level_rank[$found[$a]['level']];
            $rb = $level_rank[$found[$b]['level']];
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strlen($b) <=> strlen($a);
        });

        $locations = array();
        $best_level = null;
        $best_unique = false;
        foreach (array_slice($found, 0, 5, true) as $name_norm => $match) {
            $unique = count($match['rows']) === 1;
            if ($best_level === null) {
                $best_level = $match['level'];
                $best_unique = $unique;
            }
            // Per i match ambigui (stesso nome → più località) proponi le prime opzioni.
            foreach (array_slice($match['rows'], 0, $unique ? 1 : 3) as $row) {
                $confidence = $match['level'] === 'title' ? ($unique ? self::CONFIDENCE_HIGH : self::CONFIDENCE_MEDIUM) : ($match['level'] === 'secondary' ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW);
                $locations[] = $this->location_from_gazetteer_row($row, $confidence, self::SOURCE_GAZETTEER);
            }
        }
        if (empty($locations)) {
            return $empty;
        }
        $locations[0]['is_primary'] = true;

        if ($best_level === 'title' && $best_unique) {
            $confidence = 'high';
        } elseif ($best_level === 'content') {
            $confidence = 'low';
        } else {
            $confidence = 'medium';
        }
        return array('confidence' => $confidence, 'locations' => $locations);
    }

    /**
     * Gazetteer: mappa nome_normalizzato => righe località dalla tabella
     * alma_geo_locations (canonical_name, city, poi, area + alias JSON).
     */
    public function get_gazetteer() {
        if ($this->gazetteer !== null) {
            return $this->gazetteer;
        }
        $this->gazetteer = array();
        if (!$this->store->tables_exist()) {
            return $this->gazetteer;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, canonical_name, type, country, country_code, region, city, area, poi, geocoding_status, aliases FROM {$this->store->table_locations()}",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $names = array($row['canonical_name'], $row['city'], $row['poi'], $row['area']);
            $aliases = json_decode((string) ($row['aliases'] ?? ''), true);
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    if (is_scalar($alias)) {
                        $names[] = (string) $alias;
                    }
                }
            }
            foreach ($names as $name) {
                $normalized = $this->normalize_text((string) $name);
                // Nomi cortissimi (es. "po", "ba") generano solo falsi positivi.
                if (strlen($normalized) < 4) {
                    continue;
                }
                $existing_ids = wp_list_pluck($this->gazetteer[$normalized] ?? array(), 'id');
                if (!in_array($row['id'], $existing_ids, true)) {
                    $this->gazetteer[$normalized][] = $row;
                }
            }
        }
        return $this->gazetteer;
    }

    private function gazetteer_lookup($normalized_name) {
        $gazetteer = $this->get_gazetteer();
        return $gazetteer[$normalized_name] ?? array();
    }

    private function location_from_gazetteer_row($row, $confidence, $source) {
        return array(
            'location_id' => absint($row['id'] ?? 0),
            'name' => (string) ($row['canonical_name'] ?? ''),
            'canonical_name' => (string) ($row['canonical_name'] ?? ''),
            'type' => (string) ($row['type'] ?? 'unknown'),
            'city' => (string) ($row['city'] ?? ''),
            'region' => (string) ($row['region'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'country_code' => (string) ($row['country_code'] ?? ''),
            'geocoding_status' => (string) ($row['geocoding_status'] ?? 'pending'),
            'confidence' => (float) $confidence,
            'source' => $source,
        );
    }

    /**
     * Aggancia le proposte AI alle località già conosciute quando i nomi combaciano.
     */
    private function enrich_with_gazetteer($locations) {
        foreach ($locations as &$location) {
            $matches = $this->gazetteer_lookup($this->normalize_text((string) ($location['name'] ?? '')));
            if (count($matches) === 1) {
                $known = $this->location_from_gazetteer_row($matches[0], $location['confidence'] ?? self::CONFIDENCE_MEDIUM, self::SOURCE_AI);
                $location = array_merge($location, $known);
            }
        }
        unset($location);
        return $locations;
    }

    /* ---------------------------------------------------------------------
     * Applicazione e coda di revisione
     * ------------------------------------------------------------------ */

    private function apply_locations($post, $object_type, $locations, $source) {
        $result = $this->store->save_geo_meta_for_object($post->ID, $object_type, array(
            'locations' => $locations,
            'widget_eligible' => 'yes',
        ), $source);
        $applied = !empty($result['location_id']) || !empty($result['locations']);
        if ($applied) {
            delete_post_meta($post->ID, self::STATUS_META);
            delete_post_meta($post->ID, self::SUGGESTION_META);
        }
        return $applied;
    }

    public function save_suggestion($post_id, $locations, $method, $confidence) {
        update_post_meta($post_id, self::SUGGESTION_META, array(
            'locations' => array_values($locations),
            'method' => sanitize_key($method),
            'confidence' => sanitize_key($confidence),
            'generated_at' => current_time('mysql'),
        ));
        update_post_meta($post_id, self::STATUS_META, 'suggested');
    }

    public function get_pending_suggestions($limit = 50, $offset = 0) {
        $query = new WP_Query(array(
            'post_type' => array('post', 'page', 'affiliate_link'),
            'post_status' => 'publish',
            'posts_per_page' => max(1, min(200, absint($limit))),
            'offset' => absint($offset),
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
            'meta_query' => array(array('key' => self::STATUS_META, 'value' => 'suggested')),
        ));
        $items = array();
        foreach ($query->posts as $post) {
            $suggestion = get_post_meta($post->ID, self::SUGGESTION_META, true);
            if (!is_array($suggestion)) {
                continue;
            }
            $items[] = array(
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'post_type' => $post->post_type,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
                'method' => sanitize_key($suggestion['method'] ?? ''),
                'confidence' => sanitize_key($suggestion['confidence'] ?? ''),
                'locations' => is_array($suggestion['locations'] ?? null) ? $suggestion['locations'] : array(),
                'generated_at' => sanitize_text_field($suggestion['generated_at'] ?? ''),
            );
        }
        return array('items' => $items, 'total' => (int) $query->found_posts);
    }

    public function approve_suggestion($post_id) {
        $post = get_post($post_id);
        $suggestion = get_post_meta($post_id, self::SUGGESTION_META, true);
        if (!$post instanceof WP_Post || !is_array($suggestion) || empty($suggestion['locations'])) {
            return false;
        }
        $object_type = $post->post_type === 'affiliate_link' ? ALMA_Geo_Index_Store::OBJECT_TYPE_AFFILIATE_LINK : ($post->post_type === 'page' ? ALMA_Geo_Index_Store::OBJECT_TYPE_PAGE : ALMA_Geo_Index_Store::OBJECT_TYPE_POST);
        if ($this->object_is_indexed($post_id, $object_type)) {
            // Indicizzato manualmente nel frattempo: la proposta decade.
            delete_post_meta($post_id, self::SUGGESTION_META);
            delete_post_meta($post_id, self::STATUS_META);
            return true;
        }
        // La primaria è la prima proposta; eventuali alternative ambigue non
        // confermate vengono scartate (stesso nome → si tiene la prima opzione).
        $locations = array_values($suggestion['locations']);
        $locations[0]['is_primary'] = true;
        return $this->apply_locations($post, $object_type, $locations, self::SOURCE_CONFIRMED);
    }

    public function reject_suggestion($post_id) {
        delete_post_meta($post_id, self::SUGGESTION_META);
        update_post_meta($post_id, self::STATUS_META, 'rejected');
        return true;
    }

    /* ---------------------------------------------------------------------
     * Hook automatici per i nuovi contenuti
     * ------------------------------------------------------------------ */

    public function init_hooks() {
        add_action('save_post_affiliate_link', array($this, 'queue_deferred_index'), 200);
        add_action('save_post_post', array($this, 'queue_deferred_index'), 200);
        // Alla pubblicazione: copre wp_publish_post (es. pulsante Pubblica su
        // Telegram) che non passa da save_post, e le bozze che al salvataggio
        // erano ancora draft (l'indexer lavora solo sui post pubblicati).
        add_action('transition_post_status', array($this, 'queue_index_on_publish'), 20, 3);
        add_action('shutdown', array($this, 'run_deferred_index'));
        add_action(self::INTEGRATION_CRON_HOOK, array(__CLASS__, 'cron_integrate_post'));

        if (is_admin()) {
            foreach (array('post', 'affiliate_link') as $post_type) {
                add_filter("manage_{$post_type}_posts_columns", array($this, 'add_geo_column'));
                add_action("manage_{$post_type}_posts_custom_column", array($this, 'render_geo_column'), 10, 2);
            }
            add_action('restrict_manage_posts', array($this, 'render_geo_filter'));
            add_action('pre_get_posts', array($this, 'apply_geo_filter'));
        }
    }

    /* ---------------------------------------------------------------------
     * Colonna e filtro "Geo" nelle liste admin
     * ------------------------------------------------------------------ */

    public function add_geo_column($columns) {
        $columns['alma_geo'] = __('Geo', 'affiliate-link-manager-ai');
        return $columns;
    }

    public function render_geo_column($column, $post_id) {
        if ($column !== 'alma_geo') {
            return;
        }
        $primary = trim((string) get_post_meta($post_id, '_alma_geo_primary_name', true));
        if ($primary !== '') {
            echo '<span title="' . esc_attr__('Località primaria', 'affiliate-link-manager-ai') . '">📍 ' . esc_html($primary) . '</span>';
            return;
        }
        $status = get_post_meta($post_id, self::STATUS_META, true);
        if ($status === 'universal') {
            echo '<span title="' . esc_attr__('Tipologia universale: valido per qualsiasi articolo', 'affiliate-link-manager-ai') . '">🌍 ' . esc_html__('Universale', 'affiliate-link-manager-ai') . '</span>';
        } elseif ($status === 'suggested') {
            echo '<span style="color:#996800;">' . esc_html__('In revisione', 'affiliate-link-manager-ai') . '</span>';
        } else {
            echo '<span style="color:#999;">—</span>';
        }
    }

    public function render_geo_filter($post_type) {
        if (!in_array($post_type, array('post', 'affiliate_link'), true)) {
            return;
        }
        $current = isset($_GET['alma_geo_filter']) ? sanitize_key($_GET['alma_geo_filter']) : '';
        $choices = array(
            '' => __('Geo: tutti', 'affiliate-link-manager-ai'),
            'without' => __('Geo: senza località', 'affiliate-link-manager-ai'),
            'with' => __('Geo: con località', 'affiliate-link-manager-ai'),
            'suggested' => __('Geo: in revisione', 'affiliate-link-manager-ai'),
        );
        echo '<select name="alma_geo_filter">';
        foreach ($choices as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function apply_geo_filter($query) {
        if (!is_admin() || !$query->is_main_query() || empty($_GET['alma_geo_filter'])) {
            return;
        }
        if (!in_array($query->get('post_type'), array('post', 'affiliate_link'), true)) {
            return;
        }
        $filter = sanitize_key($_GET['alma_geo_filter']);
        $meta_query = (array) $query->get('meta_query');
        if ($filter === 'with') {
            $meta_query[] = array('key' => '_alma_geo_primary_name', 'value' => '', 'compare' => '!=');
        } elseif ($filter === 'without') {
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => '_alma_geo_primary_name', 'compare' => 'NOT EXISTS'),
                array('key' => '_alma_geo_primary_name', 'value' => '', 'compare' => '='),
            );
        } elseif ($filter === 'suggested') {
            $meta_query[] = array('key' => self::STATUS_META, 'value' => 'suggested', 'compare' => '=');
        } else {
            return;
        }
        $query->set('meta_query', $meta_query);
    }

    /**
     * L'elaborazione avviene a shutdown perché gli importer scrivono i meta
     * (destinazione inclusa) dopo wp_insert_post: durante save_post i dati
     * del provider non sono ancora disponibili.
     */
    public function queue_index_on_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if (!($post instanceof WP_Post) || !in_array($post->post_type, array('post', 'affiliate_link'), true)) {
            return;
        }
        $this->queue_deferred_index($post->ID);
    }

    public function queue_deferred_index($post_id) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (count(self::$deferred_ids) >= 100) {
            return; // Import massivi passano dal batch runner, non dall'hook.
        }
        self::$deferred_ids[absint($post_id)] = true;
    }

    public function run_deferred_index() {
        if (empty(self::$deferred_ids)) {
            return;
        }
        $ids = array_keys(self::$deferred_ids);
        self::$deferred_ids = array();
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
                continue;
            }
            // Gli ARTICOLI già indicizzati (es. località dell'idea assegnata
            // dall'agent) venivano saltati del tutto: le altre destinazioni
            // citate nel contenuto non finivano mai su indice e mappa.
            // L'integrazione gira in un cron asincrono dedicato (mai qui a
            // shutdown: può chiamare OpenAI) e non tocca la primaria.
            if ($post->post_type === 'post') {
                self::schedule_integration($post_id);
            }
            // Deterministico soltanto: mai chiamate AI fuori dai batch
            // espliciti e dal cron di integrazione.
            $status = get_post_meta($post_id, self::STATUS_META, true);
            if (in_array($status, array('suggested', 'rejected'), true)) {
                continue;
            }
            $this->resolve_object($post_id, false);
        }
    }

    /* ---------------------------------------------------------------------
     * Integrazione località dal contenuto (mappa articolo)
     * ------------------------------------------------------------------ */

    /**
     * Programma l'integrazione asincrona delle località citate da un post.
     * Idempotente: se un evento per lo stesso post è già in coda non ne
     * aggiunge un altro; il cambio-contenuto è gestito dall'hash nel cron.
     */
    public static function schedule_integration($post_id) {
        $post_id = absint($post_id);
        if ($post_id < 1 || get_option(self::INTEGRATION_OPTION, '1') !== '1') {
            return false;
        }
        if (wp_next_scheduled(self::INTEGRATION_CRON_HOOK, array($post_id))) {
            return false;
        }
        $scheduled = wp_schedule_single_event(time() + 15, self::INTEGRATION_CRON_HOOK, array($post_id));
        if (false !== $scheduled && function_exists('spawn_cron')) {
            spawn_cron();
        }
        return false !== $scheduled;
    }

    public static function cron_integrate_post($post_id) {
        $indexer = new self();
        $indexer->integrate_post_locations($post_id);
    }

    /**
     * Completa l'indice geografico di un ARTICOLO già indicizzato con le
     * altre località citate nel contenuto, senza toccare le associazioni
     * esistenti (la primaria — es. località dell'idea — resta intatta).
     *
     * Fonti: gazetteer sul testo (solo match non ambigui) + estrattore AI
     * (con i titoli H2/H3, dove vivono le destinazioni degli elenchi).
     * Le località nuove nascono in geocoding "pending": è il geocoding a
     * validarle, e la mappa mostra solo quelle con coordinate verificate.
     * Un hash del contenuto evita ri-scansioni (e costi AI) sui salvataggi
     * senza modifiche.
     *
     * @return array{added:int,skipped:string} Esito sintetico.
     */
    public function integrate_post_locations($post_id, $force = false) {
        $none = array('added' => 0, 'skipped' => '', 'names' => array());
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            $none['skipped'] = 'post_non_valido';
            return $none;
        }
        // Il force salta l'opzione globale: è un'azione admin esplicita
        // (revisione dal metabox "AI Affiliati").
        if (!$force && get_option(self::INTEGRATION_OPTION, '1') !== '1') {
            $none['skipped'] = 'disabilitata';
            return $none;
        }
        // Solo post GIÀ indicizzati: i non indicizzati seguono il flusso
        // normale (resolve/suggerimenti); integrare senza primaria
        // creerebbe associazioni orfane che il flusso di revisione
        // cancellerebbe alla conferma.
        if (!$this->object_is_indexed($post->ID, ALMA_Geo_Index_Store::OBJECT_TYPE_POST)) {
            $none['skipped'] = 'non_indicizzato';
            return $none;
        }
        $hash = md5($post->post_title . '|' . $post->post_content);
        if (!$force && get_post_meta($post->ID, self::INTEGRATION_HASH_META, true) === $hash) {
            $none['skipped'] = 'contenuto_invariato';
            return $none;
        }

        $seen = $this->associated_location_norms($post->ID, ALMA_Geo_Index_Store::OBJECT_TYPE_POST);
        $additions = array();

        // Fonte 1 — gazetteer sul testo: solo nomi che risolvono su UNA
        // località (i toponimi ambigui non vanno auto-applicati alla mappa).
        $gazetteer_result = $this->resolve_via_gazetteer($post);
        $by_name = array();
        foreach ((array) $gazetteer_result['locations'] as $location) {
            $norm = $this->normalize_text((string) ($location['name'] ?? ''));
            if ($norm === '') { continue; }
            $by_name[$norm][] = $location;
        }
        foreach ($by_name as $norm => $rows) {
            if (count($rows) !== 1 || isset($seen[$norm])) { continue; }
            $location = $rows[0];
            $location['is_primary'] = false;
            $location['role'] = 'mentioned_destination';
            $additions[] = $location;
            $seen[$norm] = true;
        }

        // Fonte 2 — estrattore AI con i titoli di sezione (una sola chiamata
        // per versione del contenuto grazie all'hash; costi nel log AI).
        if (class_exists('ALMA_Geo_AI_Location_Extractor') && count($additions) < self::INTEGRATION_MAX_ADDITIONS) {
            $ai_locations = ALMA_Geo_AI_Location_Extractor::extract($post, array(
                'max_locations' => self::INTEGRATION_MAX_ADDITIONS,
                'include_headings' => true,
            ));
            $ai_locations = $this->enrich_with_gazetteer($ai_locations);
            foreach ($ai_locations as $location) {
                $norm = $this->normalize_text((string) ($location['name'] ?? ''));
                if ($norm === '' || isset($seen[$norm])) { continue; }
                $location['is_primary'] = false;
                $location['role'] = 'mentioned_destination';
                $location['source'] = self::SOURCE_AI;
                $additions[] = $location;
                $seen[$norm] = true;
            }
        }

        $added = array();
        if (!empty($additions)) {
            $additions = array_slice($additions, 0, self::INTEGRATION_MAX_ADDITIONS);
            $added = $this->store->append_locations_for_object($post->ID, ALMA_Geo_Index_Store::OBJECT_TYPE_POST, $additions, 'auto_content');
        }
        update_post_meta($post->ID, self::INTEGRATION_HASH_META, $hash);
        update_post_meta($post->ID, self::INTEGRATION_NOTE_META, sprintf(
            '%s — %s',
            empty($added) ? __('Nessuna località aggiuntiva trovata nel contenuto', 'affiliate-link-manager-ai') : sprintf(__('+%d località dal contenuto: %s', 'affiliate-link-manager-ai'), count($added), implode(', ', wp_list_pluck($added, 'name'))),
            current_time('mysql')
        ));
        return array('added' => count($added), 'skipped' => '', 'names' => wp_list_pluck($added, 'name'));
    }

    /**
     * Revisione geo ON-DEMAND dal metabox "AI Affiliati": stabilisce la
     * località primaria se manca (livelli deterministici + AI su azione
     * esplicita) e completa l'articolo con le altre destinazioni citate.
     * A differenza del cron, forza l'analisi (ignora hash e opzione globale).
     *
     * @return array{ok:bool,message:string,primary:string,added:array,pending_geocoding:int}
     */
    public function review_post_locations($post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return array('ok' => false, 'message' => __('Articolo non valido.', 'affiliate-link-manager-ai'), 'primary' => '', 'added' => array(), 'pending_geocoding' => 0);
        }
        if ($post->post_status !== 'publish') {
            return array('ok' => false, 'message' => __('La revisione geo è disponibile solo per gli articoli pubblicati.', 'affiliate-link-manager-ai'), 'primary' => '', 'added' => array(), 'pending_geocoding' => 0);
        }
        // Nessuna località primaria: prova prima i livelli deterministici e,
        // se non bastano, l'estrazione AI (qui l'azione è esplicita).
        if (!$this->object_is_indexed($post->ID, ALMA_Geo_Index_Store::OBJECT_TYPE_POST)) {
            $this->resolve_object($post->ID, true);
        }
        if (!$this->object_is_indexed($post->ID, ALMA_Geo_Index_Store::OBJECT_TYPE_POST)) {
            return array('ok' => false, 'message' => __('Nessuna località riconosciuta nel titolo o nel contenuto: aggiungine una dalla metabox Geo per popolare la mappa.', 'affiliate-link-manager-ai'), 'primary' => '', 'added' => array(), 'pending_geocoding' => 0);
        }
        $primary = html_entity_decode((string) get_post_meta($post->ID, '_alma_geo_primary_name', true), ENT_QUOTES, 'UTF-8');
        $integration = $this->integrate_post_locations($post->ID, true);
        $added = (array) ($integration['names'] ?? array());

        // Quante località dell'articolo attendono ancora coordinate: finché
        // sono in geocoding pending non compaiono sulla mappa.
        $pending = 0;
        if ($this->store->tables_exist()) {
            global $wpdb;
            $pending = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT l.id) FROM {$this->store->table_content_index()} ci
                 INNER JOIN {$this->store->table_locations()} l ON l.id = ci.location_id
                 WHERE ci.object_id = %d AND ci.object_type = %s AND l.geocoding_status = 'pending'",
                $post->ID,
                ALMA_Geo_Index_Store::OBJECT_TYPE_POST
            ));
        }
        $parts = array();
        if ($primary !== '') { $parts[] = sprintf(__('Località primaria: %s.', 'affiliate-link-manager-ai'), $primary); }
        $parts[] = empty($added)
            ? __('Nessuna nuova località trovata nel contenuto.', 'affiliate-link-manager-ai')
            : sprintf(__('Aggiunte %d località dal contenuto: %s.', 'affiliate-link-manager-ai'), count($added), implode(', ', $added));
        if ($pending > 0) { $parts[] = sprintf(__('%d in attesa di geocoding: compariranno sulla mappa una volta ottenute le coordinate.', 'affiliate-link-manager-ai'), $pending); }
        return array('ok' => true, 'message' => implode(' ', $parts), 'primary' => $primary, 'added' => $added, 'pending_geocoding' => $pending);
    }

    /**
     * Nomi normalizzati (canonico + alias) delle località già associate a un
     * oggetto: base di dedup dell'integrazione.
     */
    private function associated_location_norms($object_id, $object_type) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.canonical_name, l.aliases FROM {$this->store->table_content_index()} ci
             INNER JOIN {$this->store->table_locations()} l ON l.id = ci.location_id
             WHERE ci.object_id = %d AND ci.object_type = %s",
            absint($object_id),
            sanitize_key($object_type)
        ), ARRAY_A);
        $norms = array();
        foreach ((array) $rows as $row) {
            $names = array((string) ($row['canonical_name'] ?? ''));
            $aliases = json_decode((string) ($row['aliases'] ?? ''), true);
            if (is_array($aliases)) {
                foreach ($aliases as $alias) { $names[] = (string) $alias; }
            }
            foreach ($names as $name) {
                $norm = $this->normalize_text($name);
                if ($norm !== '') { $norms[$norm] = true; }
            }
        }
        return $norms;
    }

    /* ---------------------------------------------------------------------
     * Lock e utilità
     * ------------------------------------------------------------------ */

    private function acquire_lock() {
        $now = time();
        if (add_option(self::LOCK_OPTION, $now, '', 'no')) {
            return true;
        }
        $existing = (int) get_option(self::LOCK_OPTION, 0);
        if ($existing && ($now - $existing) > self::LOCK_TTL) {
            update_option(self::LOCK_OPTION, $now, false);
            return true;
        }
        return false;
    }

    private function release_lock() {
        delete_option(self::LOCK_OPTION);
    }

    private function normalize_text($text) {
        $text = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }
}
