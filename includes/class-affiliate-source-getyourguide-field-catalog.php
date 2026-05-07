<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_GetYourGuide_Field_Catalog {
    public static function get_catalog() {
        return array(
            self::row('tours[].tour_id','Tour ID','Identità','/1/tours','integer/string','Identificativo tour GetYourGuide.','12345','external_id','documented',''),
            self::row('tours[].title','Titolo','Contenuto','/1/tours','string','Titolo attività/tour.','Tour guidato Lecce','title','documented','Non copiare testi lunghi provider.'),
            self::row('tours[].abstract','Abstract','Contenuto','/1/tours','string','Descrizione breve se disponibile.','Scopri...','description','documented','Usare come sintesi, non copia estesa.'),
            self::row('tours[].description','Descrizione','Contenuto','/1/tours','string','Descrizione attività se disponibile con livello API.','...','description','observed','Può mancare su Basic/Teaser.'),
            self::row('tours[].url','URL prodotto','URL affiliato','/1/tours','url','URL marketplace/prodotto restituito dalla Partner API.','https://www.getyourguide.com/...','affiliate_url','affiliate_ready','Salvare completo senza modificarlo.'),
            self::row('tours[].marketplace_url','Marketplace URL','URL affiliato','/1/tours','url','URL marketplace alternativo.','https://www.getyourguide.com/...','affiliate_url','observed','Fallback a url/product_url.'),
            self::row('tours[].price','Prezzo','Prezzo','/1/tours','number/string','Prezzo indicativo se disponibile.','49.00','price','documented','Prezzi/disponibilità possono cambiare.'),
            self::row('tours[].currency','Valuta','Prezzo','/1/tours','string','Valuta richiesta/restituita.','EUR','currency','documented',''),
            self::row('tours[].rating','Rating','Rating','/1/tours','number','Rating aggregato se disponibile.','4.7','rating','documented',''),
            self::row('tours[].reviews_count','Numero recensioni','Rating','/1/tours','integer','Conteggio recensioni se disponibile.','120','review_count','observed',''),
            self::row('tours[].duration','Durata','Dettagli','/1/tours','string/object','Durata attività se disponibile.','2 hours','duration','documented',''),
            self::row('tours[].city / destination','Destinazione','Dettagli','/1/tours','string/object','Destinazione, città o area.','Lecce','destination','observed',''),
            self::row('tours[].pictures[] / images[]','Immagini','Media','/1/tours','array','Immagini candidate nel payload.','[{url:...}]','featured_image_url','documented','Preview non scarica immagini.'),
            self::row('cnt_language','Lingua contenuti','Parametri','/1/tours','string','Parametro localizzazione contenuti.','it','runtime criteria','documented',''),
            self::row('currency','Valuta richiesta','Parametri','/1/tours','string','Parametro valuta.','EUR','runtime criteria','documented',''),
            self::row('timeout','Timeout richiesta','Parametri','/1/tours','integer','Timeout HTTP configurabile dal plugin, clamp 3-30 secondi.','10','runtime criteria','plugin','Non è un campo API, controlla solo wp_remote_get.'),
        );
    }

    private static function row($path,$label,$group,$endpoint,$type,$description,$example,$mapping,$status,$note) {
        return compact('path','label','group','endpoint','type','description','example','status') + array('mapping_hint'=>$mapping,'compliance_note'=>$note);
    }
}
