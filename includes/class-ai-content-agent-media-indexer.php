<?php
if (!defined('ABSPATH')) { exit; }
class ALMA_AI_Content_Agent_Media_Indexer {
    public static function reindex_batch($limit = 100) {
        $stats = ALMA_AI_Content_Agent_Media_Index::rebuild_index($limit);
        return (int)($stats['indexed'] ?? 0);
    }
}
