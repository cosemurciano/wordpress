<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Trend_Content_Ideas_Prompt_Builder {
    public static function default_global_prompt() {
        return 'Analizza trend di viaggio attuali e recenti utili per il sito Sothra, rivolto a viaggiatori italiani. Privilegia destinazioni, esperienze e bisogni concreti che possano generare contenuti editoriali pratici, autorevoli e monetizzabili tramite link affiliati pertinenti. Non copiare testi dalle fonti. Non inventare dati. Distingui sempre tra trend forte, medio e debole. Evidenzia bisogni pratici del viaggiatore: budget, periodo migliore, durata, trasporti, alloggi, sicurezza, esperienze, cosa prenotare prima.';
    }

    public static function system_prompt() {
        return 'Sei il modulo Trend Idee contenuto di Sothra. Devi usare OpenAI Web Search solo sulle fonti/domìni ammessi quando indicati. Per contenuti informativi si intendono risultati, pagine, articoli, comunicati, report o documenti informativi consultabili dalla ricerca web; non sono articoli WordPress generati, bozze o idee editoriali finali. Distingui sempre fonti configurate, fonti richieste/interrogate, fonti effettivamente citate, fonti senza risultati e fonti saltate. Non considerare mai “fonti configurate” uguale a “fonti analizzate”. Per ogni fonte rispetta max_contents_per_run: non analizzare né sintetizzare più di quel numero di contenuti informativi provenienti dalla fonte indicata durante una singola run. Il limite è per singola fonte e per singola run e non rappresenta il numero di trend restituiti o di idee editoriali generate. Non copiare testi dalle fonti. Non inventare dati, numeri, link, città, destinazioni o citazioni. Estrai città, regioni, paesi, aree geografiche o luoghi reali solo se presenti nelle fonti. Le destinazioni_prioritarie devono essere luoghi reali: se non emergono luoghi reali lascia array vuoto e aggiungi warning. Se i dati sono parziali devi dichiararlo. Devi indicare livello di confidenza, fonti citate e limiti. Proponi contenuti per viaggiatori italiani e coerenti con Sothra. Collega le idee editoriali a opportunità affiliate pertinenti, bisogni viaggiatori, rischi e fonti collegate. Non generare bozze WordPress, post, HTML o contenuti da pubblicare automaticamente. Restituisci esclusivamente JSON valido conforme allo schema richiesto: nessun markdown, nessun blocco ```json, nessun testo prima o dopo il JSON. Se una fonte non produce risultati usa array vuoti e aggiungi warning. Non inventare URL, titoli, date o fonti.';
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
        $profile = self::profile_name($run_type, $sources);
        $profile_instructions = self::profile_instructions($profile);
        return "Prompt globale admin:\n" . $global . "\n\nTipo run: " . sanitize_key($run_type) . "\nProfilo runtime: " . $profile . "\nPeriodo: ultimi 30-90 giorni, privilegiando segnali recenti e verificabili.\n\nDefinizioni operative:\n- contenuti informativi analizzati = pagine, articoli, comunicati, report, documenti o risultati consultati/sintetizzati tramite Web Search; non bozze WordPress o idee finali;\n- fonti configurate = elenco qui sotto salvato in WordPress;\n- fonti richieste/interrogate = fonti/domìni che provi a usare nella Web Search;\n- fonti effettivamente citate = fonti con URL o citazioni presenti nel JSON;\n- trend = segnali tematici emersi dai contenuti;\n- destinazioni = città, località, regioni, paesi, aree geografiche o luoghi reali citati dalle fonti;\n- idee editoriali = proposte operative per Sothra;\n- opportunità affiliate = categorie di servizi linkabili coerenti con le idee.\n\nPer ogni fonte rispetta max_contents_per_run: non analizzare né sintetizzare più di quel numero di contenuti informativi della fonte indicata nella singola run. Distingui sempre contenuti informativi analizzati, fonti configurate, fonti interrogate, fonti citate, trend restituiti, destinazioni e idee editoriali. Non considerare fonti configurate uguale a fonti analizzate. Se una fonte non produce dati o viene saltata, segnalalo chiaramente in warnings/fonti_saltate.\n\nFonti abilitate da analizzare:\n" . implode("\n", $source_lines) . "\n\n" . $profile_instructions . "\n\nOutput richiesto: restituisci esclusivamente JSON valido con i campi obbligatori dello schema, nessun markdown, nessun blocco ```json e nessun testo fuori dal JSON. Rispetta esattamente lo schema richiesto. Estrai nomi di città, regioni, paesi o aree quando presenti; non inventare destinazioni se non sono presenti. Collega ogni idea editoriale a bisogni viaggiatori, possibili affiliazioni, priorità editoriale, intento di ricerca e fonti collegate quando disponibili. Se i dati non bastano per raggiungere le quantità richieste, spiega perché nei warning. Ogni idea deve essere concreta, utile a viaggiatori italiani e non deve creare bozze WordPress. Ricorda che priority definisce ordine/peso fonte e max_contents_per_run limita i contenuti informativi analizzati per fonte nella singola run, non il numero di articoli WordPress.";
    }

    public static function build_json_retry($sources, $run_type = 'manual') {
        $source_lines = array();
        foreach ($sources as $src) {
            $source_lines[] = '- ' . $src['name'] . ' [' . $src['source_key'] . '] priority ' . ALMA_Trend_Content_Ideas_Store::normalize_priority($src['priority'] ?? 2) . ', max_contents_per_run ' . ALMA_Trend_Content_Ideas_Store::normalize_max_contents_per_run($src['max_contents_per_run'] ?? 3);
        }
        return "Retry compatto JSON per run " . sanitize_key($run_type) . ". Restituisci SOLO JSON valido, senza markdown, senza blocchi ```json e senza testo prima o dopo. Usa lo schema compatto source_test: status, summary, source_quality, trends, content_ideas, citations, warnings. summary massimo 350 caratteri; massimo 2 trend; massimo 2 idee; massimo 3 citazioni; massimo 3 warning; ogni descrizione trend massimo 250 caratteri; ogni descrizione idea massimo 250 caratteri. Niente spiegazioni discorsive lunghe, niente paragrafi estesi. Se non hai dati sufficienti usa array vuoti e warning breve. Non inventare URL, titoli, date o fonti. Fonti e limiti (max_contents_per_run = contenuti informativi analizzati, non trend restituiti o idee editoriali):\n" . implode("\n", $source_lines);
    }

    private static function profile_name($run_type, $sources) {
        if ($run_type === 'test' && count((array)$sources) <= 1) { return 'source_test'; }
        if ($run_type === 'test') { return 'full_test'; }
        return 'editorial_plan';
    }

    private static function profile_instructions($profile) {
        if ($profile === 'source_test') {
            return 'Profilo source_test: output diagnostico breve per testare una fonte. Mantieni il report compatto: massimo 2 trend, massimo 2 idee contenuto, massimo 3 citazioni, massimo 3 warning. Restituisci solo i campi essenziali: status, summary, source_quality, trends, content_ideas, citations, warnings. summary massimo 350 caratteri. Ogni descrizione trend massimo 250 caratteri. Ogni descrizione idea massimo 250 caratteri. Niente spiegazioni discorsive lunghe. Niente paragrafi estesi. Niente markdown. Niente testo fuori dal JSON. Restituisci solo JSON valido. Se i dati non bastano, usa array vuoti e warning breve.';
        }
        if ($profile === 'full_test') {
            return 'Profilo full_test: genera un report utile a Sothra, non una sintesi minimalista. Mantieni struttura chiara e concisa, ma se i dati lo consentono includi almeno 6 trend, almeno 8 idee editoriali, almeno 5 bisogni viaggiatori, almeno 5 opportunità affiliate, almeno 4 rischi/limiti e almeno 6 citazioni. Includi destinazioni/città/aree geografiche quando presenti nelle fonti, ma non inventarle. Genera più idee se i dati lo consentono e non limitarti a 2 idee salvo dati insufficienti. Distingui fonti configurate, fonti interrogate, fonti citate, fonti saltate e fonti senza risultati. Segnala chiaramente nei warning se le fonti non producono dati o non bastano per raggiungere le quantità richieste.';
        }
        return 'Profilo editorial_plan: output editoriale operativo per pianificazione contenuti. Se i dati lo consentono produci 10-20 idee editoriali totali, 7-14 contenuti nel piano settimanale, 8-12 opportunità affiliate, 8-12 bisogni viaggiatori e 10-20 destinazioni/città/aree reali disponibili nelle fonti. Per ogni contenuto includi priorità editoriale, intento di ricerca, categoria editoriale, suggerimento CTA/link affiliato e fonti collegate. Per ogni idea editoriale includi titolo, descrizione, destinazioni_collegate, categoria_editoriale, intento_ricerca, priorita, opportunita_affiliate, fonti_collegate e note_per_sothra. Per ogni opportunità affiliata includi categoria, esempi_servizi, idee_collegate, priorita, rischio_claim e nota_editoriale. Per ogni bisogno viaggiatore includi bisogno, descrizione, contenuti_utili e affiliate_possibili. Per ogni destinazione prioritaria includi nome, tipo, paese_o_area, perche_rilevante, trend_collegati, idee_collegate e confidence_score. Non riempire destinazioni_prioritarie con titoli di trend generici.';
    }

    public static function response_schema($profile = 'editorial_plan') {
        if ($profile === 'source_test' || $profile === 'json_invalid_retry') { return self::compact_source_test_schema(); }
        if ($profile === 'full_test') { return self::full_test_schema(); }
        return self::editorial_plan_schema();
    }

    private static function compact_source_test_schema() {
        $text = array('type'=>'string');
        $string_array = array('type'=>'array','maxItems'=>3,'items'=>$text);
        return array(
            'type'=>'json_schema',
            'name'=>'sothra_trend_source_test_compact',
            'strict'=>false,
            'schema'=>array(
                'type'=>'object',
                'additionalProperties'=>false,
                'properties'=>array(
                    'status'=>$text,
                    'summary'=>array('type'=>'string','maxLength'=>350),
                    'source_quality'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('rating'=>$text,'notes'=>array('type'=>'string','maxLength'=>250))),
                    'trends'=>array('type'=>'array','maxItems'=>2,'items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('title'=>$text,'description'=>array('type'=>'string','maxLength'=>250),'confidence'=>$text))),
                    'content_ideas'=>array('type'=>'array','maxItems'=>2,'items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('title'=>$text,'description'=>array('type'=>'string','maxLength'=>250),'intent'=>$text))),
                    'citations'=>array('type'=>'array','maxItems'=>3,'items'=>array('type'=>'object','additionalProperties'=>true,'properties'=>array('title'=>$text,'url'=>$text,'source'=>$text,'date'=>$text))),
                    'warnings'=>$string_array,
                ),
                'required'=>array('status','summary','source_quality','trends','content_ideas','citations','warnings'),
            ),
        );
    }

    private static function full_test_schema() {
        $base = self::shared_rich_schema('sothra_trend_full_test');
        $base['schema']['required'] = array('status','summary','source_quality','fonti_configurate','fonti_interrogate','fonti_citate','fonti_saltate','trends','destinazioni_prioritarie','temi_editoriali','piano_editoriale_settimanale','content_ideas','bisogni_viaggiatori','opportunita_affiliate','rischi_e_limiti','citations','warnings','dati_per_grafici');
        return $base;
    }

    private static function editorial_plan_schema() {
        $base = self::shared_rich_schema('sothra_trend_editorial_plan');
        $base['schema']['required'] = array('summary','scenario_editoriale','destinazioni_prioritarie','trend_principali','piano_editoriale_settimanale','idee_editoriali_extra','cluster_tematici','opportunita_affiliate','bisogni_viaggiatori','rischi_e_limiti','fonti_citate','dati_per_grafici','warnings');
        return $base;
    }

    private static function shared_rich_schema($name) {
        $text = array('type'=>'string');
        $num = array('type'=>'number');
        $string_array = array('type'=>'array','items'=>$text);
        $rich_idea = array('type'=>'object','additionalProperties'=>true,'properties'=>array('titolo'=>$text,'descrizione'=>$text,'destinazioni_collegate'=>$string_array,'categoria_editoriale'=>$text,'intento_ricerca'=>$text,'priorita'=>$text,'priorita_editoriale'=>$text,'opportunita_affiliate'=>$string_array,'fonti_collegate'=>$string_array,'note_per_sothra'=>$text,'suggerimento_cta_link_affiliato'=>$text));
        $destination = array('type'=>'object','additionalProperties'=>true,'properties'=>array('nome'=>$text,'tipo'=>$text,'paese_o_area'=>$text,'perche_rilevante'=>$text,'trend_collegati'=>$string_array,'idee_collegate'=>$string_array,'confidence_score'=>$num));
        $affiliate = array('type'=>'object','additionalProperties'=>true,'properties'=>array('categoria'=>$text,'esempi_servizi'=>$string_array,'idee_collegate'=>$string_array,'priorita'=>$text,'rischio_claim'=>$text,'nota_editoriale'=>$text));
        $need = array('type'=>'object','additionalProperties'=>true,'properties'=>array('bisogno'=>$text,'descrizione'=>$text,'contenuti_utili'=>$string_array,'affiliate_possibili'=>$string_array));
        $citation = array('type'=>'object','additionalProperties'=>true,'properties'=>array('titolo'=>$text,'title'=>$text,'url'=>$text,'fonte'=>$text,'source'=>$text,'date'=>$text));
        return array(
            'type'=>'json_schema',
            'name'=>$name,
            'strict'=>false,
            'schema'=>array(
                'type'=>'object',
                'additionalProperties'=>false,
                'properties'=>array(
                    'status'=>$text,
                    'summary'=>$text,
                    'source_quality'=>array('type'=>'object','additionalProperties'=>true),
                    'scenario_editoriale'=>$text,
                    'fonti_configurate'=>$string_array,
                    'fonti_interrogate'=>$string_array,
                    'fonti_saltate'=>$string_array,
                    'fonti_analizzate'=>$string_array,
                    'trends'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'trend_principali'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'destinazioni_prioritarie'=>array('type'=>'array','items'=>$destination),
                    'temi_editoriali'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'cluster_tematici'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'piano_editoriale_settimanale'=>array('type'=>'array','items'=>$rich_idea),
                    'content_ideas'=>array('type'=>'array','items'=>$rich_idea),
                    'idee_editoriali_extra'=>array('type'=>'array','items'=>$rich_idea),
                    'opportunita_affiliate'=>array('type'=>'array','items'=>$affiliate),
                    'bisogni_viaggiatori'=>array('type'=>'array','items'=>$need),
                    'rischi_e_limiti'=>array('type'=>'array','items'=>array('type'=>'object','additionalProperties'=>true)),
                    'citations'=>array('type'=>'array','items'=>$citation),
                    'warnings'=>$string_array,
                    'dati_per_grafici'=>array('type'=>'object','additionalProperties'=>true),
                    'livello_confidenza'=>$text,
                    'alert'=>$string_array,
                    'fonti_citate'=>array('type'=>'array','items'=>$citation),
                ),
            ),
        );
    }
}
