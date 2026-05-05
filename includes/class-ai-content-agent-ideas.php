<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Ideas {
    const CPT = 'alma_content_idea';
    const META_STATUS = '_alma_idea_status';
    const META_PROFILE_ID = '_alma_idea_profile_id';
    const META_PROMPT = '_alma_idea_openai_prompt';
    const META_LAST_QUERY = '_alma_idea_last_query';
    const META_RESULTS = '_alma_idea_search_results';
    const META_SELECTION = '_alma_idea_selected_results';
    const META_EXECUTED_AT = '_alma_idea_executed_at';
    const META_DRAFT_POST_ID = '_alma_idea_draft_post_id';
    const META_INSTRUCTION_SNAPSHOT_HASH = '_alma_idea_instruction_snapshot_hash';
    const META_INSTRUCTION_SNAPSHOT = '_alma_idea_instruction_snapshot';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_cpt'));
    }

    public static function register_cpt() {
        register_post_type(self::CPT, array(
            'label' => 'Idee contenuto',
            'public' => false,
            'show_ui' => false,
            'supports' => array('title', 'author'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
    }

    private static function sanitize_instruction_profile_id($value) {
        $profile_id = absint($value);
        if ($profile_id < 1) { return 0; }
        $profile = ALMA_AI_Content_Agent_Instructions_Manager::get_profile($profile_id);
        return !empty($profile['id']) ? absint($profile['id']) : 0;
    }

    public static function create($title = 'Nuova idea', $instruction_profile_id = 0) {
        $id = wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'publish','post_title'=>sanitize_text_field($title),'post_author'=>get_current_user_id()), true);
        if (is_wp_error($id) || !$id) { return 0; }
        update_post_meta($id, self::META_PROFILE_ID, self::sanitize_instruction_profile_id($instruction_profile_id));
        update_post_meta($id, self::META_PROMPT, '');
        update_post_meta($id, self::META_LAST_QUERY, array());
        update_post_meta($id, self::META_RESULTS, array());
        update_post_meta($id, self::META_SELECTION, array());
        update_post_meta($id, self::META_EXECUTED_AT, '');
        update_post_meta($id, self::META_DRAFT_POST_ID, 0);
        update_post_meta($id, self::META_INSTRUCTION_SNAPSHOT_HASH, '');
        update_post_meta($id, self::META_INSTRUCTION_SNAPSHOT, '');
        return (int)$id;
    }

    private static function normalize_meta_rows($value) {
        if (!is_array($value)) { return array(); }
        $normalized = array();
        foreach ($value as $row) {
            if (!is_array($row)) { continue; }
            $normalized[] = $row;
        }
        return $normalized;
    }

    private static function normalize_meta_query($value) {
        return is_array($value) ? $value : array();
    }

    public static function get($id) {
        $p = get_post(absint($id)); if (!$p || $p->post_type !== self::CPT) { return array(); }
        $last_query = self::normalize_meta_query(get_post_meta($p->ID, self::META_LAST_QUERY, true));
        $results = self::normalize_meta_rows(get_post_meta($p->ID, self::META_RESULTS, true));
        $selection = self::normalize_meta_rows(get_post_meta($p->ID, self::META_SELECTION, true));
        $stored_profile_id = absint(get_post_meta($p->ID, self::META_PROFILE_ID, true));
        return array('ID'=>$p->ID,'title'=>$p->post_title,'profile_id'=>$stored_profile_id,'instruction_profile_id'=>$stored_profile_id,'has_instruction_profile_meta'=>metadata_exists('post', $p->ID, self::META_PROFILE_ID),'prompt'=>get_post_meta($p->ID,self::META_PROMPT,true),'last_query'=>$last_query,'results'=>$results,'selection'=>$selection,'instruction_snapshot_hash'=>sanitize_text_field((string)get_post_meta($p->ID,self::META_INSTRUCTION_SNAPSHOT_HASH,true)),'instruction_snapshot'=>sanitize_textarea_field((string)get_post_meta($p->ID,self::META_INSTRUCTION_SNAPSHOT,true)),'executed_at'=>sanitize_text_field((string)get_post_meta($p->ID,self::META_EXECUTED_AT,true)),'draft_post_id'=>absint(get_post_meta($p->ID,self::META_DRAFT_POST_ID,true)),'modified'=>$p->post_modified);
    }

    public static function save_from_request($idea_id, $data) {
        $idea_id = absint($idea_id); if ($idea_id < 1) { return false; }
        if (isset($data['idea_title'])) { wp_update_post(array('ID'=>$idea_id,'post_title'=>sanitize_text_field($data['idea_title']))); }
        if (array_key_exists('instruction_profile_id', (array)$data)) {
            $raw_profile_id = sanitize_text_field((string)$data['instruction_profile_id']);
            $next_profile_id = self::sanitize_instruction_profile_id($raw_profile_id);
            if ($next_profile_id > 0) {
                update_post_meta($idea_id, self::META_PROFILE_ID, $next_profile_id);
            } elseif ($raw_profile_id === '' || $raw_profile_id === '0' || !empty($data['clear_instruction_profile'])) {
                update_post_meta($idea_id, self::META_PROFILE_ID, 0);
            }
        }
        if (array_key_exists('openai_prompt', (array)$data)) {
            update_post_meta($idea_id, self::META_PROMPT, sanitize_textarea_field((string)$data['openai_prompt']));
        }
        return true;
    }
}
ALMA_AI_Content_Agent_Ideas::init();
