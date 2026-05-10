<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Prompt_Builder {
    public static function default_global_prompt() {
        return 'Analizza trend di viaggio attuali e recenti utili per il sito Sothra, rivolto a viaggiatori italiani. Privilegia destinazioni, esperienze e bisogni concreti che possano generare contenuti editoriali pratici, autorevoli e monetizzabili tramite link affiliati pertinenti. Non copiare testi dalle fonti. Non inventare dati. Distingui sempre tra trend forte, medio e debole. Evidenzia bisogni pratici del viaggiatore: budget, periodo migliore, durata, trasporti, alloggi, sicurezza, esperienze, cosa prenotare prima.';
    }

    public static function system_prompt() {
        return 'Sei il modulo Trend Idee contenuto di Sothra. Devi usare OpenAI Web Search solo sulle fonti/domìni ammessi quando indicati. Per contenuti informativi si intendono risultati, pagine, articoli, comunicati, report o documenti informativi consultabili dalla ricerca web; non sono articoli WordPress generati, bozze o idee editoriali finali. Per ogni fonte rispetta max_contents_per_run: non analizzare né sintetizzare più di quel numero di contenuti informativi provenienti dalla fonte indicata durante una singola run. Il limite è per singola fonte e per singola run e non rappresenta il numero di idee editoriali da generare. Non copiare testi dalle fonti. Non inventare dati, numeri, link o citazioni. Se i dati sono parziali devi dichiararlo. Devi indicare livello di confidenza, fonti citate e limiti. Proponi contenuti per viaggiatori italiani e coerenti con Sothra. Proponi opportunità affiliate solo se pertinenti. Non generare bozze WordPress, post, HTML o contenuti da pubblicare automaticamente. Restituisci esclusivamente JSON valido conforme allo schema richiesto: nessun markdown, nessun blocco ```json, nessun testo prima o dopo il JSON. Se una fonte non produce risultati usa array vuoti e aggiungi warning. Non inventare URL, titoli, date o fonti.';
    }

    public static function build($sources, $run_type = 'manual') {
        $global = get_option(ALMA_Trend_Content_Ideas_Store::OPTION_GLOBAL_PROMPT, self::default_global_prompt());
        $source_lines = array();
        foreach ($sources as $src) {
            $domains = implode(', ', ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains'] ?? '[]'));
            $max_contents = ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3);
            $priority = ALMA_Trend_Content_Ideas_Store::normalize_priority($src['priority'] ?? 2);
            $source_lines[] = '- ' . $src['name'] . ' [' . $src['source_key'] . '] priorità ' . $priority . ', max_contents_per_run ' . $max_contents . ', categoria ' . $src['category'] . ', domini: ' . $domains . '. Prompt fonte: ' . trim((string)$src['custom_prompt']);
        }
        return "Prompt globale admin:\n" . $global . "\n\nTipo run: " . sanitize_key($run_type) . "\nPeriodo: ultimi 30-90 giorni, privilegiando segnali recenti e verificabili.\n\nDefinizione operativa: i contenuti informativi sono pagine, articoli, comunicati, report, documenti o risultati che puoi consultare/sintetizzare tramite Web Search; non sono bozze WordPress, articoli WordPress generati o idee editoriali finali. Per ogni fonte rispetta max_contents_per_run: non analizzare né sintetizzare più di quel numero di contenuti informativi della fonte indicata nella singola run.\n\nFonti abilitate da analizzare:\n" . implode("\n", $source_lines) . "\n\nOutput richiesto: restituisci esclusivamente JSON valido con i campi obbligatori dello schema, nessun markdown, nessun blocco ```json e nessun testo fuori dal JSON. Rispetta esattamente lo schema richiesto. Se una fonte non produce risultati usa array vuoti e aggiungi warning. Non inventare URL, titoli, date o fonti. Ogni idea deve essere concreta, utile a viaggiatori italiani e non deve creare bozze WordPress. Ricorda che priority definisce ordine/peso fonte e max_contents_per_run limita i contenuti informativi analizzati per fonte nella singola run, non il numero di articoli WordPress.";
    }

    public static function build_json_retry($sources, $run_type = 'manual') {
        $source_lines = array();
        foreach ($sources as $src) {
            $source_lines[] = '- ' . $src['name'] . ' [' . $src['source_key'] . '] priority ' . ALMA_Trend_Content_Ideas_Store::normalize_priority($src['priority'] ?? 2) . ', max_contents_per_run ' . ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3);
        }
        return "Retry JSON per run " . sanitize_key($run_type) . ". Restituisci SOLO JSON valido, senza markdown, senza blocchi ```json e senza testo prima o dopo. Rispetta esattamente lo schema. Se non hai dati sufficienti usa array vuoti e warnings. Non inventare URL, titoli, date o fonti. Fonti e limiti (max_contents_per_run = contenuti informativi, non articoli WordPress):\n" . implode("\n", $source_lines);
    }

    public static function response_schema() {
        $text = array('type'=>'string');
        $num = array('type'=>'number');
        $string_array = array('type'=>'array','items'=>$text);
        $object_array = array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true));
        return array(
            'type'=>'json_schema',
            'name'=>'sothra_trend_idee_contenuto',
            'strict'=>false,
            'schema'=>array(
                'type'=>'object',
                'additionalProperties'=>false,
                'properties'=>array(
                    'status'=>$text,
                    'summary'=>$text,
                    'trends'=>$object_array,
                    'content_ideas'=>$object_array,
                    'citations'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('title'=>$text,'url'=>$text,'source'=>$text,'date'=>$text))),
                    'warnings'=>$string_array,
                    'sintesi_generale'=>$text,
                    'fonti_analizzate'=>$string_array,
                    'destinazioni_prioritarie'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('nome'=>$text,'paese_o_area'=>$text,'trend_score'=>$num,'confidence_score'=>$num,'fonti_collegate'=>$string_array,'motivazione'=>$text,'target_viaggiatore'=>$text,'bisogni_pratici'=>$string_array,'rischi'=>$string_array,'opportunita_affiliate'=>$string_array))),
                    'temi_editoriali'=>$object_array,
                    'piano_editoriale_settimanale'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('giorno_suggerito'=>$text,'titolo'=>$text,'tipo_contenuto'=>$text,'destinazione'=>$text,'paese_o_area'=>$text,'intento_ricerca'=>$text,'motivazione_trend'=>$text,'bisogni_viaggiatore'=>$string_array,'outline'=>$string_array,'opportunita_affiliate'=>$string_array,'fonti_da_citare'=>$string_array,'priorita_editoriale'=>$text,'livello_confidenza'=>$text,'azione_consigliata'=>$text))),
                    'opportunita_affiliate'=>$object_array,
                    'bisogni_viaggiatori'=>$object_array,
                    'rischi_e_limiti'=>$object_array,
                    'dati_per_grafici'=>array('type'=>'object','additionalProperties'=>true),
                    'livello_confidenza'=>$text,
                    'alert'=>$string_array,
                    'fonti_citate'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('titolo'=>$text,'url'=>$text,'fonte'=>$text))),
                ),
                'required'=>array('status','summary','trends','content_ideas','citations','warnings','sintesi_generale','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','dati_per_grafici','livello_confidenza','alert','fonti_citate'),
            ),
        );
    }
}
