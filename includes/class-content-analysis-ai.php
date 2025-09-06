<?php
// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestione analisi contenuti e caching
 */
class ALMA_Content_Analysis_AI {
    /**
     * Recupera la cache dei contenuti analizzati
     *
     * @return array Array di contenuti in cache
     */
    public static function get_cache() {
        $cache = get_transient('alma_content_analysis_cache');
        if (false === $cache) {
            $types = get_option('alma_content_analysis_post_types', array());
            $cache = self::build_cache($types);
        }
        return is_array($cache) ? $cache : array();
    }

    /**
     * Costruisce la cache dei contenuti per i content type selezionati
     *
     * @param array $post_types Tipi di contenuto da analizzare
     * @return array Dati memorizzati in cache
     */
    public static function build_cache($post_types = array()) {
        if (empty($post_types)) {
            delete_transient('alma_content_analysis_cache');
            return array();
        }

        $args = array(
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        );

        $posts = get_posts($args);
        $data  = array();
        foreach ($posts as $pid) {
            $data[$pid] = array(
                'title'   => get_the_title($pid),
                'content' => wp_strip_all_tags(get_post_field('post_content', $pid)),
            );
        }

        // Memorizza in cache per 12 ore
        set_transient('alma_content_analysis_cache', $data, 12 * HOUR_IN_SECONDS);

        return $data;
    }
}
