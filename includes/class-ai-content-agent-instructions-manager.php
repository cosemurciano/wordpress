<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Instructions_Manager {
    public static function core_rules() {
        return array(
            'Nessuna pubblicazione automatica.',
            'In Step 3.5 non creare bozze WordPress.',
            'Disclosure affiliata obbligatoria nei contenuti futuri.',
            'Non inventare prezzi o disponibilità.',
            'Non copiare contenuti da fonti/provider/Sothra.',
            'Non esporre segreti o chiavi API.',
            'Non loggare prompt completi.'
        );
    }

    public static function get_profiles($limit = 50, $offset = 0) {
        global $wpdb;
        $t = ALMA_AI_Content_Agent_Store::table('instruction_profiles');
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)); if($exists!==$t){return array();} $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY is_default DESC, is_active DESC, id DESC LIMIT %d OFFSET %d", max(1, absint($limit)), max(0, absint($offset))), ARRAY_A); return is_array($rows)?$rows:array();
    }
    public static function get_profile($id){ global $wpdb; $t=ALMA_AI_Content_Agent_Store::table('instruction_profiles'); $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)); if($exists!==$t){return array();} $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",absint($id)),ARRAY_A); return is_array($r)?$r:array(); }
    public static function get_active_profiles($limit = 100, $offset = 0){ global $wpdb; $t=ALMA_AI_Content_Agent_Store::table('instruction_profiles'); $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t)); if($exists!==$t){return array();} $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE is_active=1 ORDER BY is_default DESC, profile_name ASC, id DESC LIMIT %d OFFSET %d", max(1, absint($limit)), max(0, absint($offset))),ARRAY_A); return is_array($rows)?$rows:array(); }
    public static function get_active_profile(){ $profiles=self::get_active_profiles(1,0); return !empty($profiles[0]) && is_array($profiles[0]) ? $profiles[0] : array(); }

    public static function sanitize_profile_textarea($value) {
        if (is_array($value) || is_object($value)) {
            $value = '';
        }

        $text = (string) $value;
        $text = wp_check_invalid_utf8($text, true);
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return is_string($text) ? $text : '';
    }

    public static function save_profile($data, $id = 0) {
        global $wpdb; $t = ALMA_AI_Content_Agent_Store::table('instruction_profiles');
        $data = is_array($data) ? $data : array();
        $payload = array(
            'profile_name'=>sanitize_text_field($data['profile_name'] ?? 'Profilo editoriale'),
            'language_code'=>sanitize_text_field($data['language_code'] ?? 'it'),
            'tone_of_voice'=>self::sanitize_profile_textarea($data['tone_of_voice'] ?? ''),
            'target_audience'=>self::sanitize_profile_textarea($data['target_audience'] ?? ''),
            'editorial_style'=>self::sanitize_profile_textarea($data['editorial_style'] ?? ''),
            'seo_rules'=>self::sanitize_profile_textarea($data['seo_rules'] ?? ''),
            'affiliate_rules'=>self::sanitize_profile_textarea($data['affiliate_rules'] ?? ''),
            'image_rules'=>self::sanitize_profile_textarea($data['image_rules'] ?? ''),
            'source_rules'=>self::sanitize_profile_textarea($data['source_rules'] ?? ''),
            'anti_duplication_rules'=>self::sanitize_profile_textarea($data['anti_duplication_rules'] ?? ''),
            'avoid_rules'=>self::sanitize_profile_textarea($data['avoid_rules'] ?? ''),
            'disclosure_policy'=>self::sanitize_profile_textarea($data['disclosure_policy'] ?? ''),
            'custom_prompt'=>self::sanitize_profile_textarea($data['custom_prompt'] ?? ''),
            'internal_notes'=>self::sanitize_profile_textarea($data['internal_notes'] ?? ''),
            'updated_by'=>get_current_user_id(),
            'updated_at'=>current_time('mysql')
        );
        if ($id > 0) { $wpdb->update($t, $payload, array('id'=>absint($id))); return $id; }
        $payload['is_active'] = !empty($data['is_active']) ? 1 : 0; $payload['is_default']=!empty($data['is_default']) ? 1 : 0; $payload['created_by']=get_current_user_id(); $payload['created_at']=current_time('mysql');
        $wpdb->insert($t, $payload); return (int)$wpdb->insert_id;
    }
    public static function set_active($id){ global $wpdb; $id=absint($id); if($id<1){return false;} $t=ALMA_AI_Content_Agent_Store::table('instruction_profiles'); return (bool)$wpdb->update($t,array('is_active'=>1,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')),array('id'=>$id)); }
    public static function set_inactive($id){ global $wpdb; $t=ALMA_AI_Content_Agent_Store::table('instruction_profiles'); return (bool)$wpdb->update($t,array('is_active'=>0,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')),array('id'=>absint($id))); }
    public static function ensure_default_profile(){ global $wpdb; $t=ALMA_AI_Content_Agent_Store::table('instruction_profiles'); $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t"); if($count>0){return;} self::save_profile(array('profile_name'=>'Default Editoriale','is_active'=>1,'is_default'=>1,'language_code'=>'it','tone_of_voice'=>'Tono chiaro, autorevole e accessibile.','target_audience'=>'Viaggiatori italiani con diversi livelli di esperienza.','editorial_style'=>'Stile magazine di viaggio pratico e leggibile.','seo_rules'=>'SEO naturale, senza keyword stuffing.','affiliate_rules'=>'Inserire link affiliati solo se pertinenti.','image_rules'=>'Usare immagini solo se pertinenti al contenuto.','source_rules'=>'Citare fonti affidabili, non copiare testi.','anti_duplication_rules'=>'Evitare duplicazioni con contenuti esistenti.','avoid_rules'=>'Evitare claim assoluti non verificabili.','disclosure_policy'=>'Disclosure affiliata obbligatoria nei contenuti futuri. Non inventare prezzi/disponibilità.','custom_prompt'=>'','internal_notes'=>'')); }
    public static function build_compact_instruction_block($profile, $temporary = '') {
        $parts = array('Regole Core: ' . implode(' | ', self::core_rules()));
        if ($profile) {
            $parts[] = 'Istruzioni Admin: Lingua '.$profile['language_code'].'; Tono '.$profile['tone_of_voice'].'; Target '.$profile['target_audience'].'; Stile '.$profile['editorial_style'].'; SEO '.$profile['seo_rules'].'; Affiliate '.$profile['affiliate_rules'].'; Immagini '.$profile['image_rules'].'; Fonti '.$profile['source_rules'].'; Anti-duplicazione '.$profile['anti_duplication_rules'].'; Evitare '.$profile['avoid_rules'].'; Disclosure '.$profile['disclosure_policy'].'; Extra '.$profile['custom_prompt'];
        }
        if ($temporary !== '') { $parts[] = 'Istruzioni temporanee: '.self::sanitize_profile_textarea($temporary); }
        return trim(implode("\n", $parts));
    }
    public static function snapshot_hash($text){ return hash('sha256', (string) $text); }
}
