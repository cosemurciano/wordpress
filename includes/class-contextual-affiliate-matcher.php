<?php
/**
 * Deterministic contextual affiliate link matcher for the sidebar widget.
 */
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Contextual_Affiliate_Matcher {
    const DEFAULT_CANDIDATE_LIMIT = 200;

    private $settings;

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
        $candidates = $this->get_candidates(absint($settings['candidate_limit']));
        $results = array();
        $min_score = max(0, min(100, absint($settings['min_score'])));

        foreach ($candidates as $candidate) {
            $scored = $this->score_candidate($candidate, $signals, $settings);
            if (!$scored || $scored['score'] < $min_score) {
                continue;
            }
            $results[] = $scored;
        }

        usort($results, array($this, 'sort_results'));

        return array_slice($results, 0, max(1, absint($settings['max_links'])));
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
        );
    }

    private function get_candidates($limit) {
        $limit = max(1, min(self::DEFAULT_CANDIDATE_LIMIT, $limit));
        return get_posts(array(
            'post_type' => 'affiliate_link',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ));
    }

    private function score_candidate($candidate, $signals, $settings) {
        if (!$candidate instanceof WP_Post || $candidate->post_type !== 'affiliate_link' || $candidate->post_status !== 'publish') {
            return null;
        }

        $affiliate_url = trim((string) get_post_meta($candidate->ID, '_affiliate_url', true));
        if ($affiliate_url === '') {
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
        $candidate_keywords = $this->extract_keywords(trim($link_title . ' ' . $link_content . ' ' . $ai_context . ' ' . $source_text . ' ' . implode(' ', $link_types)), 30);
        $score = 0;

        if ($link_title !== '' && $this->contains_phrase($signals['title'], $link_title)) {
            $score += 35;
        }

        if ($this->has_keyword_overlap($candidate_keywords, array($signals['title']))) {
            $score += 25;
        }

        if (($link_title !== '' && $this->contains_phrase(implode(' ', $signals['headings']), $link_title)) || $this->has_keyword_overlap($candidate_keywords, $signals['headings'])) {
            $score += 25;
        }

        if ($this->has_term_overlap($link_types, $signals['taxonomy_terms'])) {
            $score += 25;
        }

        if ($this->has_keyword_overlap($candidate_keywords, array($signals['content']))) {
            $score += 15;
        }

        if ($ai_context !== '' && $this->context_matches($ai_context, $signals)) {
            $score += 20;
        }

        $click_count = absint(get_post_meta($candidate->ID, '_click_count', true));
        if ($click_count > 0) {
            $score += min(5, (int) floor(log($click_count + 1, 2)));
        }

        if ($already_present) {
            $score -= 100;
        }

        $score = max(0, min(100, $score));

        return array(
            'id' => (int) $candidate->ID,
            'score' => $score,
            'click_count' => $click_count,
            'title' => get_the_title($candidate),
            'affiliate_url' => $affiliate_url,
            'has_image' => has_post_thumbnail($candidate->ID),
        );
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

        return strpos(' ' . $haystack . ' ', ' ' . $needle . ' ') !== false || strpos($haystack, $needle) !== false;
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

        $words = array('alla','allo','agli','alle','anche','avere','come','con','dai','dal','dalla','delle','degli','dei','del','dell','dello','dove','dopo','esta','fare','gli','hai','che','chi','cosa','come','dalla','delle','dentro','essere','il','la','lo','le','li','un','una','uno','per','piu','nel','nella','nelle','negli','non','sono','sul','sulla','sulle','tra','fra','the','and','for','with','from','this','that','your','you','are','was','were','have','has','will','not');
        $stopwords = array_fill_keys($words, true);
        return $stopwords;
    }
}
