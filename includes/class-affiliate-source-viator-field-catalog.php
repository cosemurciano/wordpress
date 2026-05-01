<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Viator_Field_Catalog {
    public static function get_catalog() {
        return array(
            self::row('products[].productCode','Product code','Identità','/products/search, /search/freetext','string','Codice prodotto Viator.','ABC123','external_id','documented',''),
            self::row('products[].title','Titolo','Contenuti','/products/search, /search/freetext','string','Titolo esperienza.','Tour guidato','titolo','documented',''),
            self::row('products[].description','Descrizione','Contenuti','/products/search, /search/freetext','string','Descrizione prodotto.','Esperienza con guida...','descrizione','documented',''),
            self::row('products[].productUrl','Product URL','Compliance','/products/search, /search/freetext','string','URL affiliato Viator da non alterare.','https://www.viator.com/...','URL affiliato','protected','Salvare completo senza modifiche tracking.'),
            self::row('products[].pricing.summary.fromPrice','Prezzo da','Prezzi','/products/search, /search/freetext','number','Prezzo minimo.','49.99','prezzo','documented',''),
        );
    }

    private static function row($path,$label,$group,$endpoint,$type,$description,$example,$mapping_hint,$status,$compliance_note='') {
        return array('path'=>(string)$path,'label'=>(string)$label,'group'=>(string)$group,'endpoint'=>(string)$endpoint,'type'=>(string)$type,'description'=>(string)$description,'example'=>(string)$example,'mapping_hint'=>(string)$mapping_hint,'status'=>(string)$status,'compliance_note'=>(string)$compliance_note);
    }
}
