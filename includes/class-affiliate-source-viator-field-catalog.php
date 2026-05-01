<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Viator_Field_Catalog {
    public static function get_catalog() {
        $items = array(
            self::row('products[]','Risultato ricerca','/products/search, /search/freetext','array','Lista prodotti restituiti dalla ricerca.','','—','documented'),
            self::row('totalCount','Risultato ricerca','/products/search, /search/freetext','integer','Numero totale dei prodotti trovati.','128','—','documented'),
            self::row('products[].productCode','Identità','/products/search, /search/freetext','string','Codice univoco del prodotto Viator.','ABC123','external_id','documented'),
            self::row('products[].title','Contenuti','/products/search, /search/freetext','string','Titolo dell’esperienza mostrato all’utente.','Tour guidato al Colosseo','titolo Link Affiliato','documented'),
            self::row('products[].description','Contenuti','/products/search, /search/freetext','string','Descrizione breve del prodotto.','Esperienza con guida locale...','descrizione','documented'),
            self::row('products[].productUrl','Compliance','/products/search, /search/freetext, /products/{product-code}','string','URL affiliato Viator da salvare senza modifiche.','https://www.viator.com/...','URL affiliato','protected','Salvare completo: non alterare tracking/parametri.'),
            self::row('products[].pricing.summary.fromPrice','Prezzi','/products/search, /search/freetext','number','Prezzo minimo visualizzabile per il prodotto.','49.99','prezzo','documented'),
            self::row('products[].pricing.currency','Prezzi','/products/search, /search/freetext','string','Valuta del prezzo.','EUR','valuta','documented'),
            self::row('products[].reviews.combinedAverageRating','Recensioni','/products/search, /search/freetext','number','Valutazione media aggregata.','4.7','rating','conditional','Campo disponibile: no pubblicazione recensioni in questa PR.'),
            self::row('products[].reviews.totalReviews','Recensioni','/products/search, /search/freetext','integer','Numero totale recensioni.','2510','numero recensioni','conditional'),
            self::row('products[].tags[]','Classificazione','/products/search, /search/freetext','array','ID categoria/tag Viator da risolvere con /products/tags.','[18,42]','categorie/tag provider','documented'),
            self::row('products[].destinations[].ref','Classificazione','/products/search, /search/freetext','string','ID destinazione Viator.','d57','destinazione Viator','documented'),
            self::row('products[].flags[]','Metadati','/products/search, /search/freetext','array','Flag prodotto (es. cancellazione gratuita).','["FREE_CANCELLATION"]','metadati prodotto','conditional'),
            self::row('products[].duration.fixedDurationInMinutes','Durata','/products/search, /search/freetext','integer','Durata fissa in minuti.','180','durata','conditional'),
            self::row('products[].confirmationType','Operativo','/products/search, /search/freetext','string','Tipo conferma prenotazione.','INSTANT','tipo conferma','conditional'),
            self::row('products[].itineraryType','Operativo','/products/search, /search/freetext','string','Tipo itinerario.','STANDARD','tipo itinerario','conditional'),
            self::row('supplier.name','Dettaglio prodotto','/products/{product-code}','string','Nome fornitore.','Rome Tours SRL','fornitore','documented'),
            self::row('cancellationPolicy.description','Dettaglio prodotto','/products/{product-code}','string','Testo localizzato della policy di cancellazione.','Cancellazione gratuita fino a 24 ore prima.','policy cancellazione','conditional'),
            self::row('bookingRequirements.minTravelersPerBooking','Dettaglio prodotto','/products/{product-code}','integer','Minimo viaggiatori per prenotazione.','1','minimo viaggiatori','conditional'),
            self::row('bookingRequirements.maxTravelersPerBooking','Dettaglio prodotto','/products/{product-code}','integer','Massimo viaggiatori per prenotazione.','12','massimo viaggiatori','conditional'),
            self::row('viatorUniqueContent','Compliance','/products/{product-code}','string','Contenuto unico Viator: gestire con attenzione e note compliance.','','—','protected','Contenuto soggetto a regole Viator; non pubblicare senza corretta compliance.'),
            self::row('tags[].name','Reference data','/products/tags','string','Nome categoria/tag leggibile.','Cultural Tours','—','documented'),
            self::row('destinations[].name','Reference data','/destinations','string','Nome destinazione leggibile.','Rome','—','documented'),
            self::row('locations[].center.latitude','Reference data','/locations/bulk','number','Latitudine location referenziata.','41.9028','—','documented'),
        );
        return $items;
    }

    private static function row($path,$group,$origin,$type,$description,$example,$mapping,$status,$note='') {
        return array('path'=>$path,'group'=>$group,'origin'=>$origin,'type'=>$type,'description'=>$description,'example'=>$example,'mapping_hint'=>$mapping,'status'=>$status,'note'=>$note);
    }
}
