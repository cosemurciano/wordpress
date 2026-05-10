<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Prompt_Builder {
    public static function default_global_prompt() {
        return 'Analizza trend di viaggio attuali e recenti utili per il sito Sothra, rivolto a viaggiatori italiani. Privilegia destinazioni, esperienze e bisogni concreti che possano generare contenuti editoriali pratici, autorevoli e monetizzabili tramite link affiliati pertinenti. Non copiare testi dalle fonti. Non inventare dati. Distingui sempre tra trend forte, medio e debole. Evidenzia bisogni pratici del viaggiatore: budget, periodo migliore, durata, trasporti, alloggi, sicurezza, esperienze, cosa prenotare prima.';
    }

    public static function system_prompt() {
        return 'Sei il modulo Trend Idee contenuto di Sothra. Devi usare OpenAI Web Search solo sulle fonti/domìni ammessi quando indicati. Non copiare testi dalle fonti. Non inventare dati, numeri, link o citazioni. Se i dati sono parziali devi dichiararlo. Devi indicare livello di confidenza, fonti citate e limiti. Proponi contenuti per viaggiatori italiani e coerenti con Sothra. Proponi opportunità affiliate solo se pertinenti. Non generare bozze WordPress, post, HTML o contenuti da pubblicare automaticamente. Rispondi esclusivamente con JSON valido conforme allo schema richiesto.';
    }

    public static function build($sources, $run_type = 'manual') {
        $global = get_option(ALMA_Trend_Content_Ideas_Store::OPTION_GLOBAL_PROMPT, self::default_global_prompt());
        $source_lines = array();
        foreach ($sources as $src) {
            $domains = implode(', ', ALMA_Trend_Content_Ideas_Store::decode_json($src['allowed_domains'] ?? '[]'));
            $source_lines[] = '- ' . $src['name'] . ' [' . $src['source_key'] . '] priorità ' . $src['priority'] . ', categoria ' . $src['category'] . ', domini: ' . $domains . '. Prompt fonte: ' . trim((string)$src['custom_prompt']);
        }
        return "Prompt globale admin:\n" . $global . "\n\nTipo run: " . sanitize_key($run_type) . "\nPeriodo: ultimi 30-90 giorni, privilegiando segnali recenti e verificabili.\n\nFonti abilitate da analizzare:\n" . implode("\n", $source_lines) . "\n\nOutput richiesto: JSON strutturato con i campi obbligatori dello schema. Ogni idea deve essere concreta, utile a viaggiatori italiani e non deve creare bozze WordPress.";
    }

    public static function response_schema() {
        $text = array('type'=>'string');
        $num = array('type'=>'number');
        $arr = array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true));
        return array(
            'type'=>'json_schema',
            'name'=>'sothra_trend_idee_contenuto',
            'strict'=>false,
            'schema'=>array(
                'type'=>'object','additionalProperties'=>true,
                'properties'=>array(
                    'sintesi_generale'=>$text,
                    'fonti_analizzate'=>array('type'=>'array','items'=>$text),
                    'destinazioni_prioritarie'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('nome'=>$text,'paese_o_area'=>$text,'trend_score'=>$num,'confidence_score'=>$num,'fonti_collegate'=>array('type'=>'array','items'=>$text),'motivazione'=>$text,'target_viaggiatore'=>$text,'bisogni_pratici'=>array('type'=>'array','items'=>$text),'rischi'=>array('type'=>'array','items'=>$text),'opportunita_affiliate'=>array('type'=>'array','items'=>$text)))) ,
                    'temi_editoriali'=>$arr,
                    'piano_editoriale_settimanale'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('giorno_suggerito'=>$text,'titolo'=>$text,'tipo_contenuto'=>$text,'destinazione'=>$text,'paese_o_area'=>$text,'intento_ricerca'=>$text,'motivazione_trend'=>$text,'bisogni_viaggiatore'=>array('type'=>'array','items'=>$text),'outline'=>array('type'=>'array','items'=>$text),'opportunita_affiliate'=>array('type'=>'array','items'=>$text),'fonti_da_citare'=>array('type'=>'array','items'=>$text),'priorita_editoriale'=>$text,'livello_confidenza'=>$text,'azione_consigliata'=>$text))),
                    'opportunita_affiliate'=>$arr,
                    'bisogni_viaggiatori'=>$arr,
                    'rischi_e_limiti'=>$arr,
                    'dati_per_grafici'=>array('type'=>'object','additionalProperties'=>true),
                    'livello_confidenza'=>$text,
                    'alert'=>array('type'=>'array','items'=>$text),
                    'fonti_citate'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('titolo'=>$text,'url'=>$text,'fonte'=>$text))),
                ),
                'required'=>array('sintesi_generale','fonti_analizzate','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','dati_per_grafici','livello_confidenza','alert','fonti_citate'),
            ),
        );
    }
}
