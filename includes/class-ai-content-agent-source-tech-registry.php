<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Source_Tech_Registry {
    public static function choices() {
        return array(
            'rss' => 'RSS',
            'atom' => 'Atom',
            'sitemap_xml' => 'Sitemap XML',
            'single_web_page' => 'Pagina web singola',
            'website' => 'Sito web',
            'blog_news_site' => 'Blog/News site',
            'api_json' => 'API JSON',
            'api_xml' => 'API XML',
            'remote_txt' => 'File TXT remoto',
            'remote_csv_tsv' => 'File CSV/TSV remoto',
            'podcast_rss' => 'Podcast RSS',
            'youtube_video' => 'YouTube/Video',
            'newsletter_archive' => 'Newsletter archive',
            'social_web_profile' => 'Social/Web profile',
            'other' => 'Altro',
        );
    }

    public static function is_valid($value) {
        return array_key_exists(sanitize_key($value), self::choices());
    }
}
