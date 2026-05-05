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

    private static function allowed_groups() {
        return array('affiliate_link','post','document_txt','source_online','page','media','other');
    }

    private static function normalize_group($group) {
        $group = sanitize_key((string)$group);
        return in_array($group, self::allowed_groups(), true) ? $group : 'other';
    }

    private static function extract_result_key($row) {
        if (!is_array($row)) { return ''; }
        $candidates = array($row['result_key'] ?? '', $row['key'] ?? '', $row['raw_key'] ?? '', $row['origin_key'] ?? '', $row['result_id'] ?? '');
        foreach ($candidates as $candidate) {
            $key = sanitize_text_field((string)$candidate);
            if ($key !== '') { return $key; }
        }
        return '';
    }

    private static function normalize_session_rows($rows, $force_selected = false) {
        if (!is_array($rows)) { return array(); }
        $normalized = array();
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $key = self::extract_result_key($row);
            if ($key === '') { continue; }
            $row['result_key'] = $key;
            $row['source_group'] = self::normalize_group($row['source_group'] ?? '');
            $row['selected'] = $force_selected ? true : !empty($row['selected']);
            $normalized[$key] = $row;
        }
        return $normalized;
    }

    private static function normalize_session_payload($session) {
        if (!is_array($session)) { $session = array(); }
        $session = wp_parse_args($session, self::empty_session());
        $search_rows = is_array($session['search_results']) ? $session['search_results'] : (is_array($session['results'] ?? null) ? $session['results'] : array());
        $session['search_results'] = self::normalize_session_rows($search_rows, false);
        $session['selected_results'] = self::normalize_session_rows($session['selected_results'], true);
        $current_scope = sanitize_key((string)($session['last_query']['search_scope'] ?? ''));
        if ($current_scope !== 'affiliate_links_only') {
            foreach ($session['selected_results'] as $key => $row) {
                if (!isset($session['search_results'][$key])) { $session['search_results'][$key] = $row; }
            }
        }
        $session['query_history'] = is_array($session['query_history']) ? $session['query_history'] : array();
        $session['last_query'] = is_array($session['last_query']) ? $session['last_query'] : array();
        $session['openai_prompt'] = sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['openai_prompt'] ?? ''));
        $session['updated_at'] = sanitize_text_field($session['updated_at'] ?? '');
        $session['status'] = empty($session['search_results']) ? 'empty' : 'active';
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        return $session;
    }

    public static function get_session() {
        $s = get_transient(self::key());
        return self::normalize_session_payload($s);
    }

    public static function clear() { delete_transient(self::key()); }

    private static function empty_session() {
        return array('status'=>'empty','last_query'=>array(),'query_history'=>array(),'search_results'=>array(),'selected_results'=>array(),'counts'=>array(),'updated_at'=>'','instruction_profile_id'=>0,'instruction_profile_name'=>'','instruction_snapshot_hash'=>'','openai_prompt'=>'');
    }

    public static function add_search_results($payload, $search_results) {
        $session = self::get_session();
        $search_scope = sanitize_key((string)($payload['search_scope'] ?? ''));
        if ($search_scope === 'affiliate_links_only') {
            // Nuova ricerca affiliate-only: sostituisce i risultati precedenti preservando la selezione corrente.
            $session['search_results'] = array();
        }
        $added = 0; $duplicates = 0; $found = 0;
        foreach ((array)($search_results['groups'] ?? array()) as $group => $rows) {
            foreach ((array)$rows as $row) {
                $found++;
                $normalized = self::normalize_result($group, $row);
                if (empty($normalized['result_key'])) { continue; }
                $k = $normalized['result_key'];
                if (isset($session['search_results'][$k])) {
                    $duplicates++;
                    $existing = $session['search_results'][$k];
                    $merged = ((int)($normalized['score'] ?? 0) > (int)($existing['score'] ?? 0)) ? $normalized : $existing;
                    $merged['selected'] = !empty($existing['selected']) || !empty($normalized['selected']);
                    $merged['origin_key'] = sanitize_text_field($merged['origin_key'] ?? ($existing['origin_key'] ?? ($normalized['origin_key'] ?? '')));
                    $merged['raw_key'] = sanitize_text_field($existing['raw_key'] ?? ($normalized['raw_key'] ?? ''));
                    $old_reason = array_filter(array_map('trim', explode('|', (string)($existing['reason'] ?? ''))));
                    $new_reason = array_filter(array_map('trim', explode('|', (string)($normalized['reason'] ?? ''))));
                    $merged['reason'] = implode(' | ', array_values(array_unique(array_merge($old_reason, $new_reason))));
                    $session['search_results'][$k] = $merged;
                    continue;
                }
                $session['search_results'][$k] = $normalized;
                $added++;
            }
        }
        if (count($session['search_results']) > self::MAX_RESULTS) {
            $session['search_results'] = array_slice($session['search_results'], -self::MAX_RESULTS, null, true);
        }
        $openai_prompt = sanitize_textarea_field($payload['openai_prompt'] ?? ($payload['temporary_instructions'] ?? ''));
        $query = array('content_search_query'=>sanitize_text_field($payload['content_search_query'] ?? ($payload['search_terms'] ?? '')),'theme'=>sanitize_text_field($payload['theme'] ?? ''),'destination'=>sanitize_text_field($payload['destination'] ?? ''),'search_terms'=>sanitize_text_field($payload['search_terms'] ?? ''),'temporary_instructions'=>sanitize_textarea_field($payload['temporary_instructions'] ?? ''),'openai_prompt'=>$openai_prompt,'search_scope'=>$search_scope);
        $session['last_query'] = $query;
        $session['query_history'][] = array('at'=>current_time('mysql'),'content_search_query'=>$query['content_search_query']);
        if (count($session['query_history']) > 20) { $session['query_history'] = array_slice($session['query_history'], -20); }
        $session['status'] = empty($session['search_results']) ? 'empty' : 'active';
        $session['updated_at'] = current_time('mysql');
        $session['instruction_profile_id'] = absint($payload['instruction_profile_id'] ?? 0);
        $session['instruction_profile_name'] = sanitize_text_field($payload['instruction_profile_name'] ?? '');
        $session['instruction_snapshot_hash'] = sanitize_text_field($payload['instruction_snapshot_hash'] ?? '');
        $session['openai_prompt'] = $openai_prompt;
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        $session = self::normalize_session_payload($session);
        set_transient(self::key(), $session, self::TTL);
        return array('found'=>$found,'added'=>$added,'duplicates'=>$duplicates,'total'=>count($session['search_results']));
    }

    public static function add_selected_results($selected_keys) {
        $session = self::get_session();
        $selected = array_fill_keys(array_map('sanitize_text_field', (array)$selected_keys), true);
        $next = (array)$session['selected_results'];
        $counts = array('post'=>0,'page'=>0,'affiliate_link'=>0,'document_txt'=>0,'source_online'=>0,'media'=>0);
        $limits = array('post'=>self::MAX_SELECTED_POSTS,'page'=>self::MAX_SELECTED_PAGES,'affiliate_link'=>self::MAX_SELECTED_AFFILIATE_LINKS,'document_txt'=>self::MAX_SELECTED_DOCUMENT_TXT,'source_online'=>self::MAX_SELECTED_SOURCE_ONLINE,'media'=>self::MAX_SELECTED_MEDIA);
        foreach ($next as $row) { if (!is_array($row)) { continue; } $g = self::normalize_group($row['source_group'] ?? ''); if (isset($counts[$g])) { $counts[$g]++; } }
        $added = 0; $duplicates = 0;
        foreach ($selected as $k => $truev) {
            if (!isset($session['search_results'][$k])) { continue; }
            if (isset($next[$k])) { $duplicates++; continue; }
            $row = (array)$session['search_results'][$k];
            $g = sanitize_key($row['source_group'] ?? '');
            if (!isset($counts[$g], $limits[$g]) || $counts[$g] >= $limits[$g]) { continue; }
            $counts[$g]++;
            $next[$k] = $row;
            $next[$k]['selected'] = true;
            $added++;
        }
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
        $session['selected_results'] = $next;
        $session['updated_at'] = current_time('mysql');
        $session['openai_prompt'] = sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['openai_prompt'] ?? ''));
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        $session['status'] = empty($session['search_results']) ? 'empty' : 'active';
        $session = self::normalize_session_payload($session);
        set_transient(self::key(), $session, self::TTL);
        $message = 'Aggiunti '.(int)$added.' elementi all’idea.';
        if ($duplicates > 0) { $message .= ' '.(int)$duplicates.' duplicati ignorati.'; }
        if (!empty($warnings)) { $message .= ' '.implode(' ', $warnings); }
        return array('success'=>true,'message'=>$message);
    }

    public static function add_single_result($result_key) {
        return self::add_selected_results(array($result_key));
    }


    public static function clear_search_results() {
        $session = self::get_session();
        $session = self::normalize_session_payload($session);
        $session['search_results'] = array();
        $session['last_query'] = array();
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        $session['status'] = empty($session['search_results']) ? 'empty' : 'active';
        $session['updated_at'] = current_time('mysql');
        $session['openai_prompt'] = sanitize_textarea_field($session['openai_prompt'] ?? '');
        $session = self::normalize_session_payload($session);
        set_transient(self::key(), $session, self::TTL);
        return $session;
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
            'link_types' => self::normalize_link_types($row['link_types'] ?? array()),
            'provenance' => sanitize_text_field((string)($row['provenance'] ?? '')),
            'provider' => sanitize_text_field((string)($row['provider'] ?? '')),
            'source' => sanitize_text_field((string)($row['source'] ?? '')),
            'selectable' => true,
            'selected' => !empty($row['preselected']),
        );
    }

    private static function normalize_link_types($raw_link_types) {
        $values = array();
        if (is_array($raw_link_types)) {
            $values = $raw_link_types;
        } elseif (is_string($raw_link_types)) {
            $raw = trim($raw_link_types);
            if ($raw !== '') {
                $values = strpos($raw, ',') !== false ? explode(',', $raw) : array($raw);
            }
        }

        $normalized = array();
        foreach ($values as $value) {
            if (!is_scalar($value)) { continue; }
            $clean = sanitize_text_field((string)$value);
            if ($clean === '') { continue; }
            $normalized[$clean] = $clean;
        }

        return array_values($normalized);
    }

    public static function grouped_results($selected_only = false) {
        $session = self::get_session();
        $order = array('affiliate_link','post','document_txt','source_online','page','media','other');
        $groups = array_fill_keys($order, array());
        $rows_source = $selected_only ? (array)$session['selected_results'] : (array)$session['search_results'];
        foreach ($rows_source as $row) {
            if (!is_array($row)) { continue; }
            $group_key = self::normalize_group($row['source_group'] ?? '');
            $groups[$group_key][] = $row;
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

    private static function count_summary($search_rows, $selected_rows = array()) {
        $c = array('total_results'=>0,'selected_total'=>0,'selected_post'=>0,'selected_page'=>0,'selected_affiliate_link'=>0,'selected_document_txt'=>0,'selected_source_online'=>0,'selected_media'=>0);
        foreach ((array)$search_rows as $r) { if (!is_array($r)) { continue; } $c['total_results']++; }
        foreach ((array)$selected_rows as $r) {
            if (!is_array($r)) { continue; }
            $c['selected_total']++;
            $key = 'selected_' . self::normalize_group($r['source_group'] ?? 'other');
            if (isset($c[$key])) { $c[$key]++; }
        }
        return $c;
    }


    public static function persist_to_idea($idea_id) {
        $idea_id = absint($idea_id); if ($idea_id < 1) { return; }
        $session = self::get_session();
        $session = self::normalize_session_payload($session);
        $idea = ALMA_AI_Content_Agent_Ideas::get($idea_id);
        if (empty($idea)) { return; }
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_LAST_QUERY, (array)$session['last_query']);
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_RESULTS, array_values($session['search_results']));
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_SELECTION, array_values($session['selected_results']));

        $snapshot_hash = sanitize_text_field($session['instruction_snapshot_hash'] ?? '');
        if ($snapshot_hash === '') { $snapshot_hash = sanitize_text_field($idea['instruction_snapshot_hash'] ?? ''); }
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_INSTRUCTION_SNAPSHOT_HASH, $snapshot_hash);

        $snapshot = sanitize_textarea_field($idea['instruction_snapshot'] ?? '');
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_INSTRUCTION_SNAPSHOT, $snapshot);

        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_PROFILE_ID, absint($idea['instruction_profile_id'] ?? ($idea['profile_id'] ?? 0)));
        update_post_meta($idea_id, ALMA_AI_Content_Agent_Ideas::META_PROMPT, sanitize_textarea_field((string)($idea['prompt'] ?? '')));

        $session['instruction_profile_id'] = absint($idea['instruction_profile_id'] ?? ($idea['profile_id'] ?? 0));
        $session['openai_prompt'] = sanitize_textarea_field((string)($idea['prompt'] ?? ''));
        if (!is_array($session['last_query'])) { $session['last_query'] = array(); }
        $session['last_query']['openai_prompt'] = $session['openai_prompt'];
        set_transient(self::key(), self::normalize_session_payload($session), self::TTL);
    }

    public static function load_from_idea($idea) {
        if (empty($idea['ID'])) { self::clear(); return; }
        $session = self::empty_session();
        $session['last_query'] = is_array($idea['last_query'] ?? null) ? $idea['last_query'] : array();
        $session['search_results'] = self::normalize_session_rows($idea['results'] ?? array(), false);
        $session['selected_results'] = self::normalize_session_rows($idea['selection'] ?? array(), true);
        $session['instruction_profile_id'] = absint($idea['profile_id'] ?? 0);
        $profile = $session['instruction_profile_id'] > 0 ? ALMA_AI_Content_Agent_Instructions_Manager::get_profile($session['instruction_profile_id']) : array();
        $session['instruction_profile_name'] = sanitize_text_field($profile['profile_name'] ?? '');
        $session['instruction_snapshot_hash'] = sanitize_text_field($idea['instruction_snapshot_hash'] ?? '');
        $session['updated_at'] = current_time('mysql');
        $session['openai_prompt'] = sanitize_textarea_field($idea['prompt'] ?? ($session['last_query']['openai_prompt'] ?? ''));
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        $session = self::normalize_session_payload($session);
        set_transient(self::key(), $session, self::TTL);
    }

    public static function remove_selected_item($result_key) {
        $session = self::get_session();
        $result_key = sanitize_text_field($result_key);
        if (isset($session['selected_results'][$result_key])) { unset($session['selected_results'][$result_key]); }
        $session['openai_prompt'] = sanitize_textarea_field($session['openai_prompt'] ?? ($session['last_query']['openai_prompt'] ?? ''));
        $session['updated_at'] = current_time('mysql');
        $session['status'] = empty($session['search_results']) ? 'empty' : 'active';
        $session['counts'] = self::count_summary($session['search_results'], $session['selected_results']);
        $session = self::normalize_session_payload($session);
        set_transient(self::key(), $session, self::TTL);
        return array('success'=>true,'message'=>'Elemento rimosso dalla sessione contenuto.');
    }
    public static function build_context_package() {
        $s = self::get_session();
        $s = self::normalize_session_payload($s);
        $selected = array_values((array)$s['selected_results']);
        return array('last_query'=>$s['last_query'],'query_history'=>$s['query_history'],'selected_results'=>$selected,'counts'=>$s['counts'],'updated_at'=>$s['updated_at'],'instruction_profile_id'=>$s['instruction_profile_id'],'instruction_profile_name'=>sanitize_text_field($s['instruction_profile_name'] ?? ''),'instruction_snapshot_hash'=>sanitize_text_field($s['instruction_snapshot_hash'] ?? ''),'openai_prompt'=>sanitize_textarea_field($s['openai_prompt'] ?? ''));
    }
}
