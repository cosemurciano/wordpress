<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_AI_Content_Agent_Opportunity_Scorer {
    public static function score($idea = array()) {
        $seo = min(100, 40 + (empty($idea['primary_keyword']) ? 0 : 25) + min(25, count((array)($idea['secondary_keywords'] ?? array())) * 5));
        $mon = min(100, 30 + min(40, count((array)($idea['affiliate_candidates'] ?? array())) * 15));
        $media = min(100, 20 + min(60, count((array)($idea['image_candidates'] ?? array())) * 20));
        $knowledge = min(100, 30 + min(60, (int)($idea['knowledge_count'] ?? 0) * 10));
        $dup = max(0, 80 - ($seo / 2));
        $priority = (int) round(($seo + $mon + $media + $knowledge + (100 - $dup)) / 5);
        return array(
            'seo_score'=>$seo,'seo_reason'=>'Keyword coverage locale',
            'monetization_score'=>$mon,'monetization_reason'=>'Disponibilità link affiliati',
            'media_score'=>$media,'media_reason'=>'Disponibilità media index',
            'knowledge_score'=>$knowledge,'knowledge_reason'=>'Copertura knowledge/chunk',
            'duplication_risk'=>$dup,'duplication_reason'=>'Stima locale su similarità keyword',
            'priority_score'=>$priority,'priority_reason'=>'Media normalizzata dei punteggi'
        );
    }
}
