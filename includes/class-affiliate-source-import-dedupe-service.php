<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Import_Dedupe_Service {
    public function find_match($normalized, $duplicate_policy = 'skip_existing') {
        global $wpdb;
        $provider = sanitize_text_field((string)($normalized['meta']['_alma_provider'] ?? ''));
        $source_id = (string)absint($normalized['meta']['_alma_source_id'] ?? 0);
        $external_id = sanitize_text_field((string)($normalized['meta']['_alma_external_id'] ?? ''));
        $sync_hash = sanitize_text_field((string)($normalized['meta']['_alma_sync_hash'] ?? ''));
        $affiliate_url = $this->normalize_url((string)($normalized['affiliate_url'] ?? ''));
        $original_url = $this->normalize_url((string)($normalized['original_url'] ?? ($normalized['meta']['_alma_original_url'] ?? '')));
        $stale_matches = 0;

        $checks = array();
        if ($source_id !== '0' && $external_id !== '') {
            $checks[] = array('sql'=>"SELECT pm1.post_id FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id=pm2.post_id WHERE pm1.meta_key='_alma_source_id' AND pm1.meta_value=%s AND pm2.meta_key='_alma_external_id' AND pm2.meta_value=%s ORDER BY pm1.post_id DESC LIMIT 5",'args'=>array($source_id,$external_id),'type'=>'source_external_id','label'=>'Già importato tramite source ID + external ID');
        }
        if ($provider !== '' && $external_id !== '') {
            $checks[] = array('sql'=>"SELECT pm1.post_id FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id=pm2.post_id WHERE pm1.meta_key='_alma_provider' AND pm1.meta_value=%s AND pm2.meta_key='_alma_external_id' AND pm2.meta_value=%s ORDER BY pm1.post_id DESC LIMIT 5",'args'=>array($provider,$external_id),'type'=>'provider_external_id','label'=>'Già importato tramite provider + external ID');
        }
        if ($original_url !== '') {
            $checks[] = array('sql'=>"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alma_original_url' AND meta_value=%s ORDER BY post_id DESC LIMIT 5",'args'=>array($original_url),'type'=>'original_url','label'=>'Già importato tramite URL originale');
        }
        if ($affiliate_url !== '') {
            $checks[] = array('sql'=>"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_alma_affiliate_url','_affiliate_url') AND meta_value=%s ORDER BY post_id DESC LIMIT 5",'args'=>array($affiliate_url),'type'=>'affiliate_url','label'=>'Già importato tramite URL affiliato');
        }
        if ($sync_hash !== '') {
            $checks[] = array('sql'=>"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alma_sync_hash' AND meta_value=%s ORDER BY post_id DESC LIMIT 5",'args'=>array($sync_hash),'type'=>'sync_hash','label'=>'Già importato tramite sync hash URL');
        }

        foreach ($checks as $check) {
            $post_ids = $wpdb->get_col($wpdb->prepare($check['sql'], $check['args']));
            foreach ((array)$post_ids as $candidate_id) {
                $post_id = (int)$candidate_id;
                if ($this->is_valid_affiliate_link_post($post_id)) {
                    $skip = sanitize_key($duplicate_policy) === 'skip_existing';
                    return array('post_id'=>$post_id,'match_type'=>$check['type'],'match_label'=>$check['label'],'updatable'=>!$skip,'skip_existing'=>$skip,'stale_matches'=>$stale_matches,'valid'=>true);
                }
                if ($post_id > 0) {
                    $stale_matches++;
                }
            }
        }
        return array('post_id'=>0,'match_type'=>'none','match_label'=>'Nuovo item','updatable'=>false,'skip_existing'=>false,'stale_matches'=>$stale_matches,'valid'=>false);
    }

    private function is_valid_affiliate_link_post($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) return false;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'affiliate_link') return false;
        if (in_array((string)$post->post_status, array('trash', 'auto-draft'), true)) return false;
        return true;
    }

    public function normalize_url($url){ $url=trim((string)$url); return filter_var($url,FILTER_VALIDATE_URL)?$url:''; }
}
