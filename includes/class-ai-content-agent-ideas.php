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

    public static function statuses() { return array('bozza'=>'Bozza','in_lavorazione'=>'In lavorazione','pronta'=>'Pronta','archiviata'=>'Archiviata'); }

    public static function create($title = 'Nuova idea') {
        $id = wp_insert_post(array('post_type'=>self::CPT,'post_status'=>'publish','post_title'=>sanitize_text_field($title),'post_author'=>get_current_user_id()), true);
        if (is_wp_error($id) || !$id) { return 0; }
        update_post_meta($id, self::META_STATUS, 'bozza');
        update_post_meta($id, self::META_PROFILE_ID, 0);
        update_post_meta($id, self::META_PROMPT, '');
        update_post_meta($id, self::META_LAST_QUERY, array());
        update_post_meta($id, self::META_RESULTS, array());
        update_post_meta($id, self::META_SELECTION, array());
        return (int)$id;
    }

    public static function get($id) {
        $p = get_post(absint($id)); if (!$p || $p->post_type !== self::CPT) { return array(); }
        return array('ID'=>$p->ID,'title'=>$p->post_title,'status'=>get_post_meta($p->ID,self::META_STATUS,true) ?: 'bozza','profile_id'=>absint(get_post_meta($p->ID,self::META_PROFILE_ID,true)),'prompt'=>get_post_meta($p->ID,self::META_PROMPT,true),'last_query'=>get_post_meta($p->ID,self::META_LAST_QUERY,true),'results'=>(array)get_post_meta($p->ID,self::META_RESULTS,true),'selection'=>(array)get_post_meta($p->ID,self::META_SELECTION,true),'modified'=>$p->post_modified);
    }

    public static function save_from_request($idea_id, $data) {
        $idea_id = absint($idea_id); if ($idea_id < 1) { return false; }
        $status = sanitize_key($data['idea_status'] ?? 'bozza');
        if (!isset(self::statuses()[$status])) { $status = 'bozza'; }
        wp_update_post(array('ID'=>$idea_id,'post_title'=>sanitize_text_field($data['idea_title'] ?? 'Nuova idea')));
        update_post_meta($idea_id, self::META_STATUS, $status);
        update_post_meta($idea_id, self::META_PROFILE_ID, absint($data['instruction_profile_id'] ?? 0));
        update_post_meta($idea_id, self::META_PROMPT, sanitize_textarea_field($data['openai_prompt'] ?? ''));
        return true;
    }
}
ALMA_AI_Content_Agent_Ideas::init();
