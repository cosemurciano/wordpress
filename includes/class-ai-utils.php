<?php
if (!defined('ABSPATH')) { exit; }
require_once ALMA_PLUGIN_DIR . 'includes/class-openai-service.php';
require_once ALMA_PLUGIN_DIR . 'includes/class-ai-usage-logger.php';
class ALMA_AI_Utils {
    public static function call_openai_api($user_prompt, $system_prompt = '', $conversation = array(), $args = array()) {
        $result = ALMA_OpenAI_Service::request(array_merge($args, array('user_prompt'=>$user_prompt,'system_prompt'=>$system_prompt,'conversation'=>$conversation)));
        ALMA_AI_Usage_Logger::log(array('model'=>$result['model'] ?? ($args['model'] ?? ''),'task'=>$args['task'] ?? 'generic','input_tokens'=>$result['usage']['input_tokens'] ?? null,'output_tokens'=>$result['usage']['output_tokens'] ?? null,'response_time'=>$result['response_time'] ?? null,'success'=>!empty($result['success']),'error'=>$result['error'] ?? null,'reference_id'=>$args['reference_id'] ?? ''));
        return $result;
    }
    public static function call_claude_api($user_prompt, $system_prompt = '', $conversation = array()) {
        return self::call_openai_api($user_prompt, $system_prompt, $conversation, array('task' => 'legacy_claude_alias'));
    }
    public static function extract_first_json($text) { /* unchanged */
        $text = preg_replace('/```json\s*(.+?)\s*```/is', '$1', $text); $text = preg_replace('/```\s*(.+?)\s*```/is', '$1', $text); $len = strlen($text);
        for ($i=0;$i<$len;$i++){ $char=$text[$i]; if($char!=='{'&&$char!=='[') continue; $open=$char; $close=$char==='{'?'}':']'; $depth=0; $in_string=false; $escape=false;
            for($j=$i;$j<$len;$j++){ $c=$text[$j]; if($in_string){ if($c==='\\'&&!$escape){$escape=true;continue;} if($c==='"'&&!$escape){$in_string=false;} $escape=false; continue; }
                if($c==='"'){ $in_string=true; continue; } if($c===$open){$depth++;} elseif($c===$close){$depth--; if($depth===0){return substr($text,$i,$j-$i+1);} }
            }
        } return ''; }
}
