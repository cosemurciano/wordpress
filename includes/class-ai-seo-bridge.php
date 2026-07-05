<?php
/**
 * Bridge SEO per le bozze AI → All in One SEO (AIOSEO).
 *
 * Scrive meta title e meta description (ricerca + social OG/Twitter) generati
 * dall'AI nei dati di All in One SEO:
 * - se AIOSEO è attivo, tramite il suo modello ufficiale (Models\Post);
 * - altrimenti, se la tabella wp_aioseo_posts esiste (plugin disattivato
 *   temporaneamente), upsert diretto sulla tabella;
 * - in ogni caso i valori restano salvati anche come meta del plugin
 *   (_alma_ai_seo_title / _alma_ai_seo_description) per tracciabilità.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_AI_Seo_Bridge {
    const META_TITLE = '_alma_ai_seo_title';
    const META_DESCRIPTION = '_alma_ai_seo_description';

    /**
     * Applica title/description SEO e social al post. Ritorna una stringa
     * di esito per i warning del report: 'aioseo' | 'aioseo_table' | 'meta_only'.
     */
    public static function apply($post_id, $seo_title, $seo_description) {
        $post_id = absint($post_id);
        if ($post_id < 1) { return 'invalid_post'; }
        $seo_title = sanitize_text_field((string) $seo_title);
        $seo_description = sanitize_textarea_field((string) $seo_description);
        if ($seo_title === '') { $seo_title = sanitize_text_field(get_the_title($post_id)); }
        if ($seo_description === '') { $seo_description = sanitize_textarea_field(wp_trim_words(wp_strip_all_tags((string) get_post_field('post_excerpt', $post_id)), 30, '')); }
        $seo_description = mb_substr($seo_description, 0, 320);

        update_post_meta($post_id, self::META_TITLE, $seo_title);
        update_post_meta($post_id, self::META_DESCRIPTION, $seo_description);

        // 1. AIOSEO attivo: modello ufficiale (gestisce cache e colonne).
        if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            try {
                $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
                $aioseo_post->post_id = $post_id;
                $aioseo_post->title = $seo_title;
                $aioseo_post->description = $seo_description;
                $aioseo_post->og_title = $seo_title;
                $aioseo_post->og_description = $seo_description;
                $aioseo_post->twitter_use_og = true;
                $aioseo_post->save();
                return 'aioseo';
            } catch (\Throwable $e) {
                ALMA_Logger::warning('AIOSEO model save fallito', array('post_id' => $post_id, 'error' => $e->getMessage()));
            }
        }

        // 2. Tabella AIOSEO presente (plugin momentaneamente disattivato).
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $now = current_time('mysql');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (post_id, title, description, og_title, og_description, twitter_use_og, created, updated)
                 VALUES (%d, %s, %s, %s, %s, 1, %s, %s)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
                     og_title = VALUES(og_title), og_description = VALUES(og_description),
                     twitter_use_og = VALUES(twitter_use_og), updated = VALUES(updated)",
                $post_id, $seo_title, $seo_description, $seo_title, $seo_description, $now, $now
            ));
            if ($wpdb->last_error === '') {
                return 'aioseo_table';
            }
            ALMA_Logger::warning('AIOSEO table upsert fallito', array('post_id' => $post_id, 'db_error' => $wpdb->last_error));
        }

        return 'meta_only';
    }
}
