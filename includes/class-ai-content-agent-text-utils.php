<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Text_Utils {
    public static function normalize_text($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    public static function detect_language($text) {
        $text = mb_strtolower(self::normalize_text($text));
        if ($text === '') { return ''; }
        $it = preg_match_all('/\b(il|la|gli|con|per|che|non|una|nelle|degli)\b/u', $text);
        $en = preg_match_all('/\b(the|with|for|and|from|into|this|that|travel)\b/u', $text);
        if ($it > $en) { return 'it'; }
        if ($en > $it) { return 'en'; }
        return '';
    }

    public static function extract_first_json($text) {
        return ALMA_AI_Utils::extract_first_json((string) $text);
    }

    public static function extract_keywords($text, $limit = 15) {
        $stop = array('the','and','for','with','this','that','from','your','you','sono','come','della','delle','degli','dallo','alla','alle','per','con','nel','nella','dove','quando','sulla','sulle','una','uno','gli','dei','del','che','non','tra');
        $text = mb_strtolower(self::normalize_text($text));
        $text = preg_replace('/[^\p{L}\p{N}\-\s]/u', ' ', $text);
        $parts = preg_split('/\s+/u', $text);
        $freq = array();
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, $stop, true)) { continue; }
            if (!isset($freq[$p])) { $freq[$p] = 0; }
            $freq[$p]++;
        }
        arsort($freq);
        return array_slice(array_keys($freq), 0, $limit);
    }
}
