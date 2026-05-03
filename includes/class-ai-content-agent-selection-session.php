<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Selection_Session {
    const MAX_SELECTED_POSTS = 3;
    const TTL = DAY_IN_SECONDS;
    const MAX_RESULTS = 250;

    private static function key() { return 'alma_ai_agent_selection_session_' . get_current_user_id(); }

    public static function get_session() {
        $s = get_transient(self::key());
        if (!is_array($s)) { return self::empty_session(); }
        $s = wp_parse_args($s, self::empty_session());
        if (!is_array($s['results'])) { $s['results'] = array(); }
        if (!is_array($s['query_history'])) { $s['query_history'] = array(); }
        return $s;
    }

    public static function clear() { delete_transient(self::key()); }

    private static function empty_session() {
        return array('status'=>'empty','last_query'=>array(),'query_history'=>array(),'results'=>array(),'counts'=>array(),'updated_at'=>'','instruction_profile_id'=>0);
    }

    public static function add_search_results($payload, $search_results) {
        $session = self::get_session();
        $added = 0; $duplicates = 0; $found = 0;
        foreach ((array)($search_results['groups'] ?? array()) as $group => $rows) {
            foreach ((array)$rows as $row) {
                $found++;
                $normalized = self::normalize_result($group, $row);
                if (empty($normalized['result_key'])) { continue; }
                $k = $normalized['result_key'];
                if (isset($session['results'][$k])) {
                    $duplicates++;
                    continue;
                }
                $session['results'][$k] = $normalized;
                $added++;
            }
        }
        if (count($session['results']) > self::MAX_RESULTS) {
            $session['results'] = array_slice($session['results'], -self::MAX_RESULTS, null, true);
        }
        $query = array('theme'=>sanitize_text_field($payload['theme'] ?? ''),'destination'=>sanitize_text_field($payload['destination'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($payload['temporary_instructions'] ?? ''));
        $session['last_query'] = $query;
        $session['query_history'][] = array('at'=>current_time('mysql'),'theme'=>$query['theme'],'destination'=>$query['destination']);
        if (count($session['query_history']) > 20) { $session['query_history'] = array_slice($session['query_history'], -20); }
        $session['status'] = empty($session['results']) ? 'empty' : 'active';
        $session['updated_at'] = current_time('mysql');
        $session['instruction_profile_id'] = absint($payload['instruction_profile_id'] ?? 0);
        $session['counts'] = self::count_summary($session['results']);
        set_transient(self::key(), $session, self::TTL);
        return array('found'=>$found,'added'=>$added,'duplicates'=>$duplicates,'total'=>count($session['results']));
    }

    public static function save_selection($selected_keys) {
        $session = self::get_session();
        $selected = array_fill_keys(array_map('sanitize_text_field', (array)$selected_keys), true);
        $next = $session['results'];
        $post_count = 0;
        foreach ($next as $k => $row) {
            $is_selected = isset($selected[$k]);
            if ($is_selected && ($row['source_group'] ?? '') === 'post') { $post_count++; }
            $next[$k]['selected'] = $is_selected;
        }
        if ($post_count > self::MAX_SELECTED_POSTS) {
            return array('success'=>false,'message'=>'Puoi selezionare massimo 3 Post. Salvataggio bloccato.');
        }
        $session['results'] = $next;
        $session['updated_at'] = current_time('mysql');
        $session['counts'] = self::count_summary($session['results']);
        $session['status'] = empty($session['results']) ? 'empty' : 'active';
        set_transient(self::key(), $session, self::TTL);
        return array('success'=>true,'message'=>'Selezione salvata.');
    }

    private static function normalize_result($group, $row) {
        $source_id = absint($row['source_id'] ?? 0);
        $wp_id = absint($row['wp_id'] ?? 0);
        $result_id = sanitize_text_field($row['result_id'] ?? '');
        $result_key = sanitize_key($group) . ':' . ($source_id ?: ($wp_id ?: $result_id));
        if (empty($result_key)) { $result_key = sanitize_key($group) . ':' . substr(md5(wp_json_encode($row)), 0, 12); }
        return array(
            'result_key' => $result_key,
            'source_group' => sanitize_key($group),
            'source_type' => sanitize_text_field($row['source_type'] ?? $group),
            'source_id' => $source_id,
            'wp_id' => $wp_id,
            'title' => sanitize_text_field($row['title'] ?? ''),
            'excerpt' => sanitize_text_field($row['excerpt'] ?? ''),
            'score' => (int)($row['score'] ?? 0),
            'reason' => sanitize_text_field($row['reason'] ?? ''),
            'admin_url' => esc_url_raw($row['admin_url'] ?? ''),
            'selectable' => true,
            'selected' => !empty($row['preselected']),
        );
    }

    public static function grouped_results() {
        $session = self::get_session(); $groups = array();
        foreach ($session['results'] as $row) { $groups[$row['source_group']][] = $row; }
        return $groups;
    }

    public static function summary() {
        $session = self::get_session();
        return array_merge(array('status'=>$session['status'],'search_count'=>count($session['query_history']),'updated_at'=>$session['updated_at']), $session['counts']);
    }

    private static function count_summary($rows) {
        $c = array('total_results'=>0,'selected_total'=>0,'selected_post'=>0,'selected_page'=>0,'selected_affiliate_link'=>0,'selected_document_txt'=>0,'selected_source_online'=>0,'selected_media'=>0);
        foreach ($rows as $r) {
            $c['total_results']++;
            if (empty($r['selected'])) { continue; }
            $c['selected_total']++;
            $key = 'selected_' . sanitize_key($r['source_group']);
            if (isset($c[$key])) { $c[$key]++; }
        }
        return $c;
    }

    public static function build_context_package() {
        $s = self::get_session();
        $selected = array_values(array_filter($s['results'], function($r){ return !empty($r['selected']); }));
        return array('last_query'=>$s['last_query'],'query_history'=>$s['query_history'],'selected_results'=>$selected,'counts'=>$s['counts'],'updated_at'=>$s['updated_at'],'instruction_profile_id'=>$s['instruction_profile_id']);
    }
}
