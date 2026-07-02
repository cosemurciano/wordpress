<?php
/**
 * Estrazione località via OpenAI per l'indicizzazione geografica automatica.
 *
 * Usato solo nei batch admin espliciti (mai su richieste frontend né su save_post)
 * e solo per i contenuti che i livelli deterministici non hanno risolto.
 * I risultati non vengono mai applicati automaticamente: finiscono nella coda
 * di revisione dell'auto-indexer.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Geo_AI_Location_Extractor {
    const MAX_CONTENT_CHARS = 1200;
    const MAX_LOCATIONS = 4;

    /**
     * @return array Elenco di località proposte (può essere vuoto) nel formato
     *               accettato da ALMA_Geo_Auto_Indexer::save_suggestion().
     */
    public static function extract($post) {
        if (!$post instanceof WP_Post) {
            $post = get_post($post);
        }
        if (!$post instanceof WP_Post || trim((string) get_option('alma_openai_api_key', '')) === '') {
            return array();
        }

        $title = wp_strip_all_tags(get_the_title($post));
        $excerpt = wp_strip_all_tags(strip_shortcodes((string) ($post->post_excerpt ?: $post->post_content)));
        $excerpt = mb_substr(preg_replace('/\s+/', ' ', $excerpt), 0, self::MAX_CONTENT_CHARS);

        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'locations' => array(
                    'type' => 'array',
                    'maxItems' => self::MAX_LOCATIONS,
                    'items' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'type' => array('type' => 'string', 'enum' => array('city', 'region', 'country', 'poi', 'area')),
                            'city' => array('type' => 'string'),
                            'region' => array('type' => 'string'),
                            'country' => array('type' => 'string'),
                            'country_code' => array('type' => 'string'),
                            'is_primary' => array('type' => 'boolean'),
                        ),
                        'required' => array('name', 'type', 'city', 'region', 'country', 'country_code', 'is_primary'),
                    ),
                ),
            ),
            'required' => array('locations'),
        );

        $res = ALMA_OpenAI_Service::request(array(
            'system_prompt' => 'Sei un estrattore di località geografiche per un sito di viaggi. Individua solo le località di cui il contenuto parla realmente (destinazioni del viaggio), non luoghi citati di passaggio. Se il contenuto non riguarda una località specifica restituisci un elenco vuoto. country_code in formato ISO 3166-1 alpha-2, vuoto se incerto.',
            'user_prompt' => "TITOLO: {$title}\nESTRATTO: {$excerpt}",
            'response_format' => array(
                'type' => 'json_schema',
                'name' => 'geo_locations',
                'strict' => true,
                'schema' => $schema,
            ),
            'max_output_tokens' => 400,
            'timeout' => 30,
            'model' => apply_filters('alma_geo_ai_extractor_model', ''),
        ));

        $success = !empty($res['success']);
        if (class_exists('ALMA_AI_Usage_Logger')) {
            ALMA_AI_Usage_Logger::log(array(
                'task' => 'geo_location_extraction',
                'success' => $success,
                'model' => $res['model'] ?? '',
                'response_time' => $res['response_time'] ?? null,
                'input_tokens' => $res['usage']['input_tokens'] ?? null,
                'output_tokens' => $res['usage']['output_tokens'] ?? null,
                'estimated_cost' => $res['estimated_cost'] ?? null,
                'reference_id' => 'post:' . $post->ID,
                'error' => $success ? '' : sanitize_text_field($res['error'] ?? ''),
            ));
        }
        if (!$success) {
            return array();
        }

        $parsed = json_decode((string) $res['response'], true);
        if (!is_array($parsed) || empty($parsed['locations']) || !is_array($parsed['locations'])) {
            return array();
        }

        $locations = array();
        foreach (array_slice($parsed['locations'], 0, self::MAX_LOCATIONS) as $location) {
            if (!is_array($location)) {
                continue;
            }
            $name = sanitize_text_field((string) ($location['name'] ?? ''));
            if ($name === '' || mb_strlen($name) < 2) {
                continue;
            }
            $type = sanitize_key((string) ($location['type'] ?? 'city'));
            if (!in_array($type, array('city', 'region', 'country', 'poi', 'area'), true)) {
                $type = 'city';
            }
            $locations[] = array(
                'name' => $name,
                'canonical_name' => $name,
                'type' => $type,
                'city' => sanitize_text_field((string) ($location['city'] ?? ($type === 'city' ? $name : ''))),
                'region' => sanitize_text_field((string) ($location['region'] ?? '')),
                'country' => sanitize_text_field((string) ($location['country'] ?? '')),
                'country_code' => strtoupper(sanitize_text_field((string) ($location['country_code'] ?? ''))),
                'geocoding_status' => 'pending',
                'is_primary' => !empty($location['is_primary']),
                'confidence' => ALMA_Geo_Auto_Indexer::CONFIDENCE_MEDIUM,
                'source' => ALMA_Geo_Auto_Indexer::SOURCE_AI,
            );
        }
        if (!empty($locations)) {
            $has_primary = (bool) array_filter(wp_list_pluck($locations, 'is_primary'));
            if (!$has_primary) {
                $locations[0]['is_primary'] = true;
            }
        }
        return $locations;
    }
}
