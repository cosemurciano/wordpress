<?php
if (!defined('ABSPATH')) {
    exit;
}

class ALMA_Dashboard_Stats {
    const CACHE_GROUP = 'alma_dashboard';

    private $wpdb;
    private $analytics_table;

    public function __construct($wpdb) {
        $this->wpdb = $wpdb;
        $this->analytics_table = $wpdb->prefix . 'alma_analytics';
    }

    public function get_cache_ttl() {
        return (int) apply_filters('alma_dashboard_cache_ttl', 10 * MINUTE_IN_SECONDS);
    }

    public function clear_cache() {
        $keys = array(
            'summary', 'posts_state', 'top_links_5', 'top_links_10', 'shortcode_usage_map',
            'total_occurrences', 'unused_links', 'avg_ctr'
        );
        foreach ($keys as $key) {
            delete_transient($this->transient_key($key));
        }
        $metric_whitelist = array('clicks', 'sources', 'links', 'ctr');
        $range_whitelist = array('daily', 'weekly', 'monthly');
        foreach ($metric_whitelist as $m) {
            foreach ($range_whitelist as $r) {
                delete_transient($this->transient_key('chart_' . $m . '_' . $r));
            }
        }
    }

    public function get_summary($top_limit = 5) {
        $cache_key = $this->transient_key('summary');
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $posts_state = $this->get_posts_state();
        $summary = array(
            'total_clicks' => $this->get_total_clicks(),
            'total_links' => (int) wp_count_posts('affiliate_link')->publish,
            'unused_links' => $this->get_unused_links_count(),
            'avg_ctr' => $this->get_average_ctr(),
            'top_links' => $this->get_top_performing_links($top_limit),
            'posts_state' => $posts_state,
            'no_affiliate_url' => admin_url('edit.php?post_type=post&alma_no_affiliates=1'),
        );

        set_transient($cache_key, $summary, $this->get_cache_ttl());
        return $summary;
    }

    public function get_posts_state() {
        $cache_key = $this->transient_key('posts_state');
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $posts = $this->wpdb->get_results(
            "SELECT post_content FROM {$this->wpdb->posts} WHERE post_type='post' AND post_status='publish'",
            ARRAY_A
        );

        $with_links = 0;
        foreach ($posts as $post) {
            if (strpos((string) $post['post_content'], '[affiliate_link') !== false) {
                $with_links++;
            }
        }

        $total = count($posts);
        $state = array(
            'total_posts' => (int) $total,
            'posts_with_links' => (int) $with_links,
            'posts_without_links' => (int) max(0, $total - $with_links),
        );

        set_transient($cache_key, $state, $this->get_cache_ttl());
        return $state;
    }

    public function get_chart_data($metric, $range) {
        $metric_whitelist = array('clicks', 'sources', 'links', 'ctr');
        $range_whitelist = array('daily', 'weekly', 'monthly');

        if (!in_array($metric, $metric_whitelist, true)) {
            return new WP_Error('invalid_metric', __('Metrica non valida.', 'affiliate-link-manager-ai'));
        }
        if (!in_array($range, $range_whitelist, true)) {
            return new WP_Error('invalid_range', __('Intervallo non valido.', 'affiliate-link-manager-ai'));
        }

        $cache_key = $this->transient_key('chart_' . $metric . '_' . $range);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = array('labels' => array(), 'data' => array());
        switch ($metric) {
            case 'clicks':
                $result = $this->get_clicks_chart($range);
                break;
            case 'sources':
                $rows = $this->wpdb->get_results("SELECT source, COUNT(*) as c FROM {$this->analytics_table} GROUP BY source", ARRAY_A);
                foreach ((array) $rows as $row) {
                    $result['labels'][] = !empty($row['source']) ? $row['source'] : 'unknown';
                    $result['data'][] = (int) $row['c'];
                }
                break;
            case 'links':
                $result = $this->get_links_monthly_chart();
                break;
            case 'ctr':
                $result = $this->get_ctr_monthly_chart();
                break;
        }

        set_transient($cache_key, $result, $this->get_cache_ttl());
        return $result;
    }

    public function get_top_performing_links($limit = 5) {
        $limit = max(1, (int) $limit);
        $cache_key = $this->transient_key('top_links_' . $limit);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $query = $this->wpdb->prepare(
            "SELECT p.ID, p.post_title, CAST(pm.meta_value AS UNSIGNED) as click_count
            FROM {$this->wpdb->posts} p
            INNER JOIN {$this->wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'affiliate_link' AND p.post_status = 'publish' AND pm.meta_key = '_click_count' AND CAST(pm.meta_value AS UNSIGNED) > 0
            ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC LIMIT %d",
            $limit
        );
        $rows = $this->wpdb->get_results($query, ARRAY_A);
        set_transient($cache_key, $rows, $this->get_cache_ttl());
        return $rows;
    }

    public function get_link_stats($link_id) {
        $link_id = (int) $link_id;
        $clicks = (int) (get_post_meta($link_id, '_click_count', true) ?: 0);
        $usage = $this->get_shortcode_usage_stats($link_id);
        $ctr = $usage['total_occurrences'] > 0 ? round(($clicks / $usage['total_occurrences']) * 100, 2) : 0;
        return array('clicks' => $clicks, 'ctr' => $ctr, 'usage' => $usage);
    }

    public function get_total_clicks() {
        $result = $this->wpdb->get_var("SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$this->wpdb->postmeta} WHERE meta_key = '_click_count'");
        return (int) ($result ?: 0);
    }

    public function get_average_ctr() {
        $cache_key = $this->transient_key('avg_ctr');
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (float) $cached;
        }
        $usage_map = $this->get_shortcode_usage_map();
        $total_clicks = 0;
        $total_occurrences = 0;
        foreach ($usage_map as $link_id => $occurrences) {
            $total_occurrences += (int) $occurrences;
            $total_clicks += (int) (get_post_meta((int) $link_id, '_click_count', true) ?: 0);
        }
        $value = $total_occurrences > 0 ? round(($total_clicks / $total_occurrences) * 100, 2) : 0;
        set_transient($cache_key, $value, $this->get_cache_ttl());
        return $value;
    }

    public function get_unused_links_count() {
        $cache_key = $this->transient_key('unused_links');
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $ids = get_posts(array('post_type' => 'affiliate_link', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids'));
        $usage_map = $this->get_shortcode_usage_map();
        $unused = 0;
        foreach ((array) $ids as $id) {
            if (empty($usage_map[(int) $id])) {
                $unused++;
            }
        }
        set_transient($cache_key, $unused, $this->get_cache_ttl());
        return (int) $unused;
    }

    public function get_total_link_occurrences() {
        $cache_key = $this->transient_key('total_occurrences');
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return (int) $cached;
        }
        $usage_map = $this->get_shortcode_usage_map();
        $total = array_sum(array_map('intval', $usage_map));
        set_transient($cache_key, (int) $total, $this->get_cache_ttl());
        return (int) $total;
    }

    public function get_shortcode_usage_stats($link_id) {
        $link_id = (int) $link_id;
        $usage_map = $this->get_shortcode_usage_map();
        $occ = isset($usage_map[$link_id]) ? (int) $usage_map[$link_id] : 0;
        return array('post_count' => $occ > 0 ? 1 : 0, 'total_occurrences' => $occ);
    }

    private function get_shortcode_usage_map() {
        $cache_key = $this->transient_key('shortcode_usage_map');
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $posts = $this->wpdb->get_results(
            "SELECT post_content FROM {$this->wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_content LIKE '%[affiliate_link%'",
            ARRAY_A
        );
        $map = array();
        foreach ((array) $posts as $row) {
            if (preg_match_all('/\[affiliate_link\s+[^\]]*id=["\']?(\d+)["\']?[^\]]*\]/', (string) $row['post_content'], $matches)) {
                foreach ((array) $matches[1] as $raw_id) {
                    $id = (int) $raw_id;
                    if (!isset($map[$id])) {
                        $map[$id] = 0;
                    }
                    $map[$id]++;
                }
            }
        }

        set_transient($cache_key, $map, $this->get_cache_ttl());
        return $map;
    }

    private function get_clicks_chart($range) {
        $labels = array();
        $data = array();
        $now = current_time('timestamp');

        if ($range === 'daily') {
            $start = gmdate('Y-m-d 00:00:00', strtotime('-29 days', $now));
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT DATE(click_time) AS bucket, COUNT(*) AS c FROM {$this->analytics_table} WHERE click_time >= %s GROUP BY bucket ORDER BY bucket ASC",
                $start
            ), ARRAY_A);
            $map = array();
            foreach ((array) $rows as $r) { $map[$r['bucket']] = (int) $r['c']; }
            for ($i = 29; $i >= 0; $i--) {
                $day = wp_date('Y-m-d', strtotime("-$i days", $now));
                $labels[] = wp_date('d/m', strtotime($day));
                $data[] = isset($map[$day]) ? $map[$day] : 0;
            }
        } elseif ($range === 'weekly') {
            $start = gmdate('Y-m-d 00:00:00', strtotime('-84 days', $now));
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT YEARWEEK(click_time, 1) AS yw, COUNT(*) AS c FROM {$this->analytics_table} WHERE click_time >= %s GROUP BY yw ORDER BY yw ASC",
                $start
            ), ARRAY_A);
            foreach ((array) $rows as $r) { $labels[] = 'W' . substr((string) $r['yw'], -2); $data[] = (int) $r['c']; }
        } else {
            $start = gmdate('Y-m-01 00:00:00', strtotime('-11 months', $now));
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT DATE_FORMAT(click_time, '%%Y-%%m') AS ym, COUNT(*) AS c FROM {$this->analytics_table} WHERE click_time >= %s GROUP BY ym ORDER BY ym ASC",
                $start
            ), ARRAY_A);
            $map = array(); foreach ((array) $rows as $r) { $map[$r['ym']] = (int)$r['c']; }
            for ($i = 11; $i >= 0; $i--) {
                $ym = wp_date('Y-m', strtotime("-$i months", $now));
                $labels[] = wp_date('M', strtotime($ym . '-01'));
                $data[] = isset($map[$ym]) ? $map[$ym] : 0;
            }
        }
        return array('labels' => $labels, 'data' => $data);
    }

    private function get_links_monthly_chart() {
        $labels = array(); $data = array();
        $now = current_time('timestamp');
        $start = gmdate('Y-m-01 00:00:00', strtotime('-11 months', $now));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DATE_FORMAT(post_date, '%%Y-%%m') AS ym, COUNT(*) AS c FROM {$this->wpdb->posts} WHERE post_type='affiliate_link' AND post_status='publish' AND post_date >= %s GROUP BY ym ORDER BY ym ASC",
            $start
        ), ARRAY_A);
        $map = array(); foreach ((array) $rows as $r) { $map[$r['ym']] = (int)$r['c']; }
        for ($i = 11; $i >= 0; $i--) { $ym = wp_date('Y-m', strtotime("-$i months", $now)); $labels[] = wp_date('M', strtotime($ym . '-01')); $data[] = isset($map[$ym]) ? $map[$ym] : 0; }
        return array('labels'=>$labels,'data'=>$data);
    }

    private function get_ctr_monthly_chart() {
        $clicks = $this->get_clicks_chart('monthly');
        $total_occurrences = max(1, $this->get_total_link_occurrences());
        $data = array();
        foreach ($clicks['data'] as $value) {
            $data[] = round(((int) $value / $total_occurrences) * 100, 2);
        }
        return array('labels' => $clicks['labels'], 'data' => $data);
    }

    private function transient_key($suffix) {
        return 'alma_dash_' . md5($suffix);
    }
}
