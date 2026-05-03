<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Selection_Session {
    const MAX_SELECTED_POSTS = 3;
    const MAX_SELECTED_PAGES = 2;
    const MAX_SELECTED_AFFILIATE_LINKS = 20;
    const MAX_SELECTED_DOCUMENT_TXT = 5;
    const MAX_SELECTED_SOURCE_ONLINE = 5;
    const MAX_SELECTED_MEDIA = 5;
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
        return array('status'=>'empty','last_query'=>array(),'query_history'=>array(),'results'=>array(),'counts'=>array(),'updated_at'=>'','instruction_profile_id'=>0,'instruction_profile_name'=>'','instruction_snapshot_hash'=>'');
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
                    $existing = $session['results'][$k];
                    $merged = ((int)($normalized['score'] ?? 0) > (int)($existing['score'] ?? 0)) ? $normalized : $existing;
                    $merged['selected'] = !empty($existing['selected']) || !empty($normalized['selected']);
                    $merged['origin_key'] = sanitize_text_field($merged['origin_key'] ?? ($existing['origin_key'] ?? ($normalized['origin_key'] ?? '')));
                    $merged['raw_key'] = sanitize_text_field($existing['raw_key'] ?? ($normalized['raw_key'] ?? ''));
                    $old_reason = array_filter(array_map('trim', explode('|', (string)($existing['reason'] ?? ''))));
                    $new_reason = array_filter(array_map('trim', explode('|', (string)($normalized['reason'] ?? ''))));
                    $merged['reason'] = implode(' | ', array_values(array_unique(array_merge($old_reason, $new_reason))));
                    $session['results'][$k] = $merged;
                    continue;
                }
                $session['results'][$k] = $normalized;
                $added++;
            }
        }
        if (count($session['results']) > self::MAX_RESULTS) {
            $session['results'] = array_slice($session['results'], -self::MAX_RESULTS, null, true);
        }
        $query = array('content_search_query'=>sanitize_text_field($payload['content_search_query'] ?? ($payload['search_terms'] ?? '')),'theme'=>sanitize_text_field($payload['theme'] ?? ''),'destination'=>sanitize_text_field($payload['destination'] ?? ''),'search_terms'=>sanitize_text_field($payload['search_terms'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($payload['temporary_instructions'] ?? ''));
        $session['last_query'] = $query;
        $session['query_history'][] = array('at'=>current_time('mysql'),'content_search_query'=>$query['content_search_query']);
        if (count($session['query_history']) > 20) { $session['query_history'] = array_slice($session['query_history'], -20); }
        $session['status'] = empty($session['results']) ? 'empty' : 'active';
        $session['updated_at'] = current_time('mysql');
        $session['instruction_profile_id'] = absint($payload['instruction_profile_id'] ?? 0);
        $session['instruction_profile_name'] = sanitize_text_field($payload['instruction_profile_name'] ?? '');
        $session['instruction_snapshot_hash'] = sanitize_text_field($payload['instruction_snapshot_hash'] ?? '');
        $session['counts'] = self::count_summary($session['results']);
        set_transient(self::key(), $session, self::TTL);
        return array('found'=>$found,'added'=>$added,'duplicates'=>$duplicates,'total'=>count($session['results']));
    }

    public static function save_selection($selected_keys) {
        $session = self::get_session();
        $selected = array_fill_keys(array_map('sanitize_text_field', (array)$selected_keys), true);
        $next = $session['results'];
        $counts = array('post'=>0,'page'=>0,'affiliate_link'=>0,'document_txt'=>0,'source_online'=>0,'media'=>0);
        foreach ($next as $k => $row) {
            $is_selected = isset($selected[$k]);
            if ($is_selected) { $g = sanitize_key($row['source_group'] ?? ''); if (isset($counts[$g])) { $counts[$g]++; } }
            $next[$k]['selected'] = $is_selected;
        }
        $limits = array('post'=>self::MAX_SELECTED_POSTS,'page'=>self::MAX_SELECTED_PAGES,'affiliate_link'=>self::MAX_SELECTED_AFFILIATE_LINKS,'document_txt'=>self::MAX_SELECTED_DOCUMENT_TXT,'source_online'=>self::MAX_SELECTED_SOURCE_ONLINE,'media'=>self::MAX_SELECTED_MEDIA);
        $warnings = array();
        foreach ($limits as $g => $max) {
            if (($counts[$g] ?? 0) <= $max) { continue; }
            $labels = array('affiliate_link'=>'Link Affiliati','post'=>'Post','document_txt'=>'File TXT','source_online'=>'Fonti online','page'=>'Pagine','media'=>'Media');
            $warnings[] = sprintf('Hai selezionato %d %s: ne sono stati mantenuti %d.', (int)($counts[$g] ?? 0), $labels[$g] ?? $g, $max);
            $kept = 0;
            foreach ($next as $k => $row) {
                if (($row['source_group'] ?? '') !== $g || empty($next[$k]['selected'])) { continue; }
                $kept++;
                if ($kept > $max) { $next[$k]['selected'] = false; }
            }
        }
        $session['results'] = $next;
        $session['updated_at'] = current_time('mysql');
        $session['counts'] = self::count_summary($session['results']);
        $session['status'] = empty($session['results']) ? 'empty' : 'active';
        set_transient(self::key(), $session, self::TTL);
        return array('success'=>true,'message'=>empty($warnings)?'Selezione salvata.':'Selezione salvata con limiti applicati: '.implode(' ', $warnings));
    }


    private static function infer_knowledge_item_id($group, $row, $fallback_result_id = '') {
        $group = sanitize_key((string)$group);
        $candidates = array(
            $row['knowledge_item_id'] ?? '',
            $row['result_key'] ?? '',
            $row['key'] ?? '',
            $row['result_id'] ?? $fallback_result_id,
        );
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $id = absint($candidate);
                if ($id > 0) { return $id; }
            }
            $value = sanitize_text_field((string)$candidate);
            if ($value === '') { continue; }
            if ($group === 'document_txt') {
                if (preg_match('/(?:^|:)(?:kb_document_txt_)(\d+)$/', $value, $m)) { return absint($m[1]); }
                if (preg_match('/^(?:kb:)?document_txt:(\d+)$/', $value, $m)) { return absint($m[1]); }
            }
            if (preg_match('/(?:^|:)kb:[a-z_]+:(\d+)$/', $value, $m)) { return absint($m[1]); }
        }
        return 0;
    }

    private static function normalize_result($group, $row) {
        $source_group = sanitize_key($group);
        $source_type = sanitize_text_field($row['source_type'] ?? $group);
        $source_id = absint($row['source_id'] ?? 0);
        $wp_id = absint($row['wp_id'] ?? 0);
        if ($wp_id <= 0 && in_array($source_group, array('post','page','affiliate_link','media'), true)) { $wp_id = $source_id; }
        $result_id = sanitize_text_field($row['result_id'] ?? '');
        $knowledge_item_id = self::infer_knowledge_item_id($group, $row, $result_id);
        $origin_key = sanitize_text_field($row['key'] ?? ($row['result_key'] ?? ''));

        if ($source_group === 'post' && $wp_id > 0) { $result_key = 'post:' . $wp_id; }
        elseif ($source_group === 'page' && $wp_id > 0) { $result_key = 'page:' . $wp_id; }
        elseif ($source_group === 'affiliate_link' && $wp_id > 0) { $result_key = 'affiliate_link:' . $wp_id; }
        elseif ($source_group === 'document_txt' && $knowledge_item_id > 0) { $result_key = 'document_txt:' . $knowledge_item_id; }
        elseif ($source_group === 'source_online' && $source_id > 0) { $result_key = 'source_online:' . $source_id; }
        elseif ($source_group === 'media' && $wp_id > 0) { $result_key = 'media:' . $wp_id; }
        else { $result_key = $source_group . ':' . ($source_id ?: ($knowledge_item_id ?: ($wp_id ?: substr(md5(wp_json_encode($row)), 0, 12)))); }

        return array(
            'result_key' => sanitize_text_field($result_key),
            'origin_key' => $origin_key,
            'raw_key' => sanitize_text_field($row['result_key'] ?? ''),
            'source_group' => $source_group,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'knowledge_item_id' => $knowledge_item_id,
            'wp_id' => $wp_id,
            'title' => sanitize_text_field($row['title'] ?? ''),
            'excerpt' => sanitize_text_field($row['excerpt'] ?? ''),
            'score' => (int)($row['score'] ?? 0),
            'reason' => sanitize_text_field($row['reason'] ?? ''),
            'admin_url' => esc_url_raw($row['admin_url'] ?? ($row['edit_url'] ?? '')),
            'selectable' => true,
            'selected' => !empty($row['preselected']),
        );
    }

    public static function grouped_results($selected_only = false) {
        $session = self::get_session();
        $order = array('affiliate_link','post','document_txt','source_online','page','media','other');
        $groups = array_fill_keys($order, array());
        foreach ($session['results'] as $row) {
            if ($selected_only && empty($row['selected'])) { continue; }
            $k = isset($groups[$row['source_group']]) ? $row['source_group'] : 'other';
            $groups[$k][] = $row;
        }
        foreach ($groups as $k => $rows) {
            usort($rows, function($a, $b){
                $score = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
                if ($score !== 0) { return $score; }
                return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
            });
            $groups[$k] = $rows;
        }
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
        return array('last_query'=>$s['last_query'],'query_history'=>$s['query_history'],'selected_results'=>$selected,'counts'=>$s['counts'],'updated_at'=>$s['updated_at'],'instruction_profile_id'=>$s['instruction_profile_id'],'instruction_profile_name'=>sanitize_text_field($s['instruction_profile_name'] ?? ''),'instruction_snapshot_hash'=>sanitize_text_field($s['instruction_snapshot_hash'] ?? ''));
    }
}
