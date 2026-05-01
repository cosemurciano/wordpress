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

        $checks = array();
        if ($source_id !== '0' && $external_id !== '') {
            $checks[] = array('sql'=>"SELECT pm1.post_id FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id=pm2.post_id WHERE pm1.meta_key='_alma_source_id' AND pm1.meta_value=%s AND pm2.meta_key='_alma_external_id' AND pm2.meta_value=%s LIMIT 1",'args'=>array($source_id,$external_id),'type'=>'source_external_id','label'=>'Già importato tramite external ID');
        }
        if ($provider !== '' && $external_id !== '') {
            $checks[] = array('sql'=>"SELECT pm1.post_id FROM {$wpdb->postmeta} pm1 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id=pm2.post_id WHERE pm1.meta_key='_alma_provider' AND pm1.meta_value=%s AND pm2.meta_key='_alma_external_id' AND pm2.meta_value=%s LIMIT 1",'args'=>array($provider,$external_id),'type'=>'provider_external_id','label'=>'Già importato tramite external ID provider');
        }
        if ($sync_hash !== '') {
            $checks[] = array('sql'=>"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_alma_sync_hash' AND meta_value=%s LIMIT 1",'args'=>array($sync_hash),'type'=>'sync_hash','label'=>'Già importato tramite sync hash');
        }
        if ($affiliate_url !== '') {
            $checks[] = array('sql'=>"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_alma_affiliate_url','_affiliate_url') AND meta_value=%s LIMIT 1",'args'=>array($affiliate_url),'type'=>'affiliate_url','label'=>'Già importato tramite URL affiliato');
        }

        foreach ($checks as $check) {
            $post_id = (int)$wpdb->get_var($wpdb->prepare($check['sql'], $check['args']));
            if ($post_id > 0) {
                $skip = sanitize_key($duplicate_policy) === 'skip_existing';
                return array('post_id'=>$post_id,'match_type'=>$check['type'],'match_label'=>$check['label'],'updatable'=>!$skip,'skip_existing'=>$skip);
            }
        }
        return array('post_id'=>0,'match_type'=>'none','match_label'=>'Nuovo item','updatable'=>false,'skip_existing'=>false);
    }

    public function normalize_url($url){ $url=trim((string)$url); return filter_var($url,FILTER_VALIDATE_URL)?$url:''; }
}
