## 2.25.29 — PR 8.29 AI Content Agent Toolbar and Ideas Pagination UI
- Migliorata la toolbar principale di **AI Content Agent** con pulsanti scoped più distinguibili: azioni primarie blu, salvataggio verde, eliminazione rossa con icona trash e download JSON OpenAI grigio/secondario.
- Aggiunta paginazione server-side alla colonna **Idee create** con massimo 10 idee per pagina, controlli **Precedente**/**Successiva**, indicatore pagina corrente e mantenimento dell’idea attiva.
- Evidenziata l’idea attiva nella lista con bordo verde, sfondo leggero e badge **Idea attiva**, preservando form, link, nonce e azioni esistenti.
- Confermati gli **Strumenti avanzati** per il download **Scarica JSON debug completo (solo diagnostica, non inviato a OpenAI)**, visibile solo agli admin con `manage_options` e stile secondario.
- Versione plugin aggiornata a `2.25.29`.

## 2.25.10 — PR 8.10 Affiliate Index Pending Query Consistency and Pre-Test Hardening
- Allineata `sync_incremental()` alla semantica pending della Dashboard: ora include record mancanti, record obsoleti e candidabili non attivi.
- La query incrementale seleziona solo candidabili (`affiliate_link` pubblicati con URL affiliato valorizzato) con `DISTINCT`, `EXISTS`, `LIMIT` e condizione `i.status <> active`.
- La CTA/notice di **Sync incrementale** chiarisce che l’azione recupera mancanti, aggiorna obsoleti e riattiva candidabili non attivi.
- Coerenza garantita con `get_index_stats()` sulle categorie `missing_index`, `stale_index_records`, `non_active_candidate_records`.
- Nessuna modifica a batch cursor-based completo, ricerca/scoring, OpenAI, Draft Builder, provider/importer, shortcode o tracking.
## 2.25.6 — PR 8.6 Affiliate Index Guided Batch Sync UX and Progress Feedback
- Card “Indice Link affiliati” aggiornata con barra di progresso in stile admin (percentuale, candidabili, elementi da lavorare) calcolata da `get_index_stats()` evitando query pesanti aggiuntive.
- Nuovo stato operativo guidato e sezione “Prossima azione consigliata” con CTA primaria dinamica (primo batch/continua batch/sync incrementale/indice aggiornato).
- Azioni tecniche separate in “Manutenzione avanzata” (reset stato batch, svuota indice e ricomincia) con warning non distruttivo mantenuto.
- Messaggi admin post-action più chiari su avanzamento batch, completamento e sicurezza operazioni sull’indice tecnico.
- Guida operativa rapida: verifica conteggi, avvia primo batch, continua fino a completamento, usa sync incrementale dopo il primo popolamento completo, usa svuota indice solo per ricostruzione.

## 2.25.5 — PR 8.5 Affiliate Index Diagnostics Count Accuracy
- Corretto `missing_index` in `get_index_stats()` per evitare sovrastime dovute a righe duplicate in `postmeta` e contare correttamente i Link affiliati pubblicati con URL non vuoto e assenti dall’indice.
- Audit dei conteggi diagnostici della card “Indice Link affiliati” con query robuste (`EXISTS` / `NOT EXISTS` / `COUNT(DISTINCT ...)`) per prevenire moltiplicazioni da meta duplicate.
- Nessuna modifica funzionale a ricerca, scoring, batch indexing, sync incrementale, auto-sync, OpenAI, Draft Builder o provider/importer.

## 2.25.3 — PR 8.3 Affiliate Index Autosync Ordering and Native Results Pagination
- Auto-sync indice affiliate su `save_post_affiliate_link` spostato a priorità alta per garantire esecuzione dopo salvataggio meta/tassonomie del CPT `affiliate_link`, mantenendo guard autosave/revision/post type e filtro `alma_ai_affiliate_index_disable_autosync`.
- Rimossi dalla card “2. Risultati ricerca” il pulsante **Svuota ricerca** e il filtro **Tipologia contenuto** (`alma_result_type`) con relativa logica/rendering UI.
- Sostituita la paginazione custom con paginazione in stile admin WordPress (`tablenav`, `tablenav-pages`, `displaying-num`, `pagination-links`) con link prima/precedente/successiva/ultima e normalizzazione pagina corrente.

## 2.25.2 — PR 8.1 Affiliate Index Batch Progression, Search Fallback and Admin Status UI
- Nuovo indice tecnico dedicato ai soli `affiliate_link` (`{$wpdb->prefix}alma_ai_affiliate_index`) creato via Store/dbDelta.
- Batch indice affiliate con cursore ID stabile, stato robusto e statistiche leggere per dashboard/admin.
- Ricerca idee: i risultati `affiliate_link` arrivano prioritariamente dall’indice dedicato con fallback WordPress dedicato per i soli affiliate_link quando tabella indice assente, vuota o senza risultati.
- Nuovo riquadro Dashboard per stato indice link affiliati (conteggi, ultimo batch, azioni indicizzazione/sync).
- Fonte di verità invariata: CPT `affiliate_link`; nessuna estensione a Post/Pagine/TXT/Fonti online/Media in questa fase.

## 2.24.9 — PR 7.9 AI Content Agent Search Results Clear Action and Content Type Filter
- Aggiunta action admin `clear_content_idea_search` con nonce/capability check, svuotamento dei soli risultati ricerca e redirect pulito alla tab Idee.
- Nuovo metodo dedicato in Selection Session per svuotare `search_results` preservando `selected_results`, stato sessione e persistenza transient.
- Toolbar nella card “2. Risultati ricerca” con pulsante **Svuota ricerca** e filtro GET **Tipologia contenuto** (`alma_result_type`) con opzioni dinamiche.
- Filtro applicato prima della paginazione e paginazione aggiornata per preservare `alma_result_type` nei link Prev/Next.
- Compatibilità mantenuta con form bulk/single separati (nessun annidamento form).

## 2.24.8 — PR 7.8 AI Content Agent Data Normalization Fatal Fix and Ideas Layout Hardening
- Hardening runtime su meta/transient legacy o corrotti: normalizzazione difensiva di risultati/sessione e guard su rendering gruppi/conteggi.
- Fix definitivo fatal PHP 8 su accessi offset stringa in Selection Session (`grouped_results`, `count_summary`, load/persist/build context).
- Hardening tab Idee contenuto: validazione idea attiva, fallback profili/usage sicuri, stati vuoti espliciti e nessun fatal con tabelle usage mancanti.
- Consolidato layout CSS Idee contenuto in una sola sezione scoped `.alma-ai-agent-admin` con 3 colonne leggibili e fallback responsive.
- Verificato enqueue di `assets/admin.css` anche su `affiliate_link_page_alma-ai-content-agent`.

## 2.24.6 — PR 7.6 AI Content Agent Critical Error Hotfix and Ideas UI Optimization
- Fix critical error in Selection Session: rimosse variabili non definite e normalizzazione difensiva della struttura sessione.
- Stabilizzata persistenza `openai_prompt`, `status`, `counts` e `updated_at` anche con sessioni vuote/corrotte.
- Corretto invio `result_key` per azione singola Aggiungi all’idea senza interferire con selezione multipla.
- `selected_map` e usage counts ottimizzati su chiavi reali e unione risultati/sessione con fallback sicuro se tabella usage assente.
- Layout Idee contenuto ottimizzato con grid scoped, larghezze colonne più leggibili e stati vuoti chiari.

## 2.24.3 — PR 7.3 — AI Content Agent Three Column UI Restoration
- Ripristinato layout 3 colonne in Idee contenuto.
- Colonna centrale dedicata a Cerca contenuti + Risultati ricerca.
- Colonna destra dedicata a 3. Sessione contenuto e Azioni finali.
- UI riallineata al mockup senza modifiche alla logica single-call OpenAI.

## 2.24.2 — PR 7.2 — Idea Selection Fixes & Executed State
- Fix overwrite dati idea nelle azioni non-search (add/remove non sovrascrivono titolo/profilo/prompt).
- Persistenza openai_prompt in Selection Session e fallback in last_query.
- Limiti selezione incrementale calcolati su intera sessione con deduplica e messaggi chiari.
- Fix pulsante bulk Aggiungi selezionati all’idea e struttura form risultati.
- Ricerca locale filtrata per pertinenza e sola inclusione con match testuale reale.
- Rimossi stati editoriali UI; introdotti Non eseguita / Eseguita il ...
- Stato Eseguita impostato solo dopo creazione bozza OpenAI riuscita (draft_post_id + executed_at).
- Nessuna nuova chiamata AI: OpenAI solo su Crea Bozza con OpenAI.

## Versione 2.23.1
- Nuovo workflow AI Content Agent: ricerca locale contenuti, selezione manuale, profilo Istruzioni AI, download JSON payload AI e creazione bozza WordPress in stato draft.
- Unica chiamata OpenAI: solo su click esplicito “Crea Bozza con OpenAI”.
- Nessuna chiamata AI durante ricerca contenuti, selezione risultati, salvataggio sessione o download JSON payload AI.


## 2.22.2

- PR 5.1.1 — patch di stabilizzazione post-review workflow AI Content Agent → Selection Session → Draft Builder.
- Fix Documenti TXT con `result_key` normalizzati (es. `document_txt:kb_document_txt_123`) con propagazione/risoluzione stabile `knowledge_item_id`.
- Tab admin **Istruzioni AI** resa raggiungibile dalla navigazione e CTA “Gestisci Profili Istruzioni AI” corretta.
- Migliorata la card risultato bozza con stato, CTA Modifica/Anteprima, profilo istruzioni, modello AI, conteggi fonti e warning QA.
- Nessuno scheduler nuovo, nessuna pubblicazione automatica, nessun crawling/scraping/web search.

## 2.22.1

- PR 5 — Crea bozza articolo da Selection Session con uso esclusivo delle fonti selezionate.
- Output OpenAI richiesto in JSON validato (`title`, `content`, `slug`) e creazione articolo WordPress in stato bozza.
- Permalink/anteprima generati da WordPress, QA locale applicata, meta provenance salvati.
- Nessuna pubblicazione automatica, nessuna programmazione scheduler in questa PR.

- Nota manutenzione PR 4.1: stabilizzata la ricerca Media nel Knowledge Base (ordinamento su `indexed_at`).
- Corretta la deduplica dei Documenti TXT per evitare collisioni quando `source_id=0`.
- Nessuna nuova funzionalità AI e nessuna modifica alla creazione bozze articolo.

## 2.20.0
- PR 3 — AI Content Agent Knowledge Base Search Engine: motore ricerca interno Knowledge Base.
- Ricerca ibrida WordPress + tabelle custom (knowledge_items/content_chunks) + Documenti TXT + Fonti online AI + Media Index.
- Risultati raggruppati per Tipo Fonte con max 10 per gruppo, checkbox e limite massimo 3 Post selezionabili.
- Nessuna nuova chiamata OpenAI; nessuna creazione bozza articolo; nessuno scheduler.

## 2.19.1
- Step 4.1 Draft Workflow Stabilization: fix fatal tab Bozze con `render_drafts_tab()` e rendering workflow base + elenco bozze agente.
- Fix tab Idee: helper `inline_draft_form()` implementato, prevenzione duplicati e link alla bozza esistente.
- Hardening admin tab query: guard su tabelle mancanti in Documenti/Fonti/Knowledge/Media/Stato-Log per evitare query SQL su tabelle assenti.
- QA draft migliorata: parsing shortcode `[affiliate_link]` robusto (attributi in qualsiasi ordine/quote) con rimozione shortcode non validi/non candidati e warning dedicati.
- Draft Builder stabilizzato: logging failure/success coerente solo dopo `wp_insert_post()` riuscito, output normalizzato (`success`, `post_id`, `edit_url`, `error`, `warnings`).
- Confermata generazione solo in stato `draft` (nessuna pubblicazione automatica, nessuno scheduler nuovo, OpenAI-only).
## 2.18.1
- Step 3.5.1 Admin Regression Fix: ripristinate tab operative Step 1/2 (Overview, Documenti, Fonti, Knowledge Base, Media Library, Reindicizzazione, Stato/Log).
- Ripristinate action admin Step 2 (reindex_knowledge, reindex_media, index_document, save_note, save_source) con nonce/capability/sanitizzazione e notice post-action.
- Ripristinata tab Idee contenuto con azioni Approva/Scarta/Archivia/Genera brief e dettagli brief esistente.
- Migliorata tab Istruzioni AI con gestione profili multipli (lista, modifica, creazione, attivazione/disattivazione).
- Fix fallback JSON (`extract_first_json`) per evitare fatal con output AI non pulito.
- Fix `save_ideas()`: conta solo insert riusciti e restituisce errori DB sanitizzati.
- Aggiunte diagnostiche `required_tables()` / `missing_tables()` e hardening Context Builder sui chunk ammessi.
- Nessuna bozza/pubblicazione/scheduler in questa release.

## 2.14.3
- Pagina **Importa contenuti**: aggiunta sezione **Filtri risultati** con checkbox `Solo nuovi nel plugin`, `Mostra anche già importati` e `Riempi automaticamente preview con nuovi item`.
- Nella tabella anteprima aggiunta colonna **Link affiliato** (usa `productUrl` originale): link `Apri` in nuova tab con `rel="noopener noreferrer"`, tooltip URL completo e fallback `N/D` se assente.
- Aggiornati JS/CSS per mantenere i filtri tra preview/paginazione e migliorare layout/allineamento in stile admin WordPress.

## 2.14.2
- Nuova UI Importa contenuti con card/sezioni, filtri avanzati visibili, default solo nuovi, e riepilogo criteri.
- Risultati preview organizzati e azioni selezione/import più chiare.
- Aggiornati JS/CSS per UX Importa contenuti.

## Versione 2.13.2

- Nuova pagina **Comportamento agente AI** separata dalla configurazione principale Source.
- Nella preview import, di default vengono mostrati solo item nuovi con toggle per mostrare i già importati.
- Aggiunto pulsante **Carica altri risultati** nella pagina Importa contenuti.
- Deduplica centralizzata tra preview/import/importer.
- Fix `skip_existing` anche quando il duplicato è trovato via URL fallback.
- Nessun filtro avanzato 2.14.0, nessun cron/booking/checkout.

## Versione 2.13.1
- Fix Viator `productUrl`: mapping diretto dall'item (`productCode`/`productUrl`) senza ricostruzioni URL canoniche o rimozione tracking.
- Preview import mostra origine URL (`productUrl`), stato presenza URL affiliato e warning/errore item.
- `_alma_ai_context` ora include solo dati item; source instructions restano separate in Source.
- Deduplica import con fallback legacy: source+external_id, provider+external_id, sync_hash, URL affiliato.
- Campi importabili Viator: catalogo documentato disponibile anche senza criteri runtime salvati.

# Affiliate Link Manager AI

Versione 2.25.28





## Novità 2.25.28 — PR 8.28 Affiliate Context Cleanup and AI Instructions Profile UI

- Pulizia finale di `affiliate_links[].context` nel payload OpenAI normalizzato: rimossi residui tecnici Viator/legacy come fonte/provider, codice prodotto, URL affiliato diagnostico, destination/tag ID, `Durata: Array` e placeholder non utili.
- UI **Istruzioni AI → Modifica profilo** più leggibile: Nome profilo e Lingua in alto, campi principali in griglia responsive a due colonne, Prompt libero personalizzato ampio a tutta larghezza e Note interne secondarie.
- Checkbox **Attiva questo profilo dopo il salvataggio** allineata allo stato `is_active` del profilo esistente, senza modificare la logica di attivazione/predefinito.

## Novità 2.25.27 — PR 8.27 Advanced Debug Download and Rule Sentence Normalization

- **Scarica JSON payload OpenAI** resta il download principale del payload normalizzato.
- **Scarica JSON debug completo (solo diagnostica, non inviato a OpenAI)** è ora in **Strumenti avanzati** ed è visibile solo agli admin con capability adeguata.
- La normalizzazione di `affiliate_rules`, `seo_rules` e `source_policies` conserva frasi complete, unisce frammenti pendenti e ripulisce virgolette/caratteri speciali senza reintrodurre campi diagnostici nel payload OpenAI.


## Novità 2.25.26 — PR 8.26 Refine OpenAI Payload Rules and Viator Duration

- Il payload OpenAI normalizzato mantiene la struttura compatta attuale e non reintroduce campi diagnostici.
- Il contesto sintetico dei link Viator omette la durata quando il valore non è leggibile, ad esempio array o placeholder tecnici.
- Le sezioni `affiliate_rules`, `seo_rules` e `source_policies` deduplicano le regole senza troncarle con puntini di sospensione, preservando frasi complete e autonome.

## Novità 2.25.25 — PR 8.25 Separate OpenAI Payload Download from Full Debug JSON

- Il pulsante **Scarica JSON payload OpenAI** genera ora il JSON normalizzato compatto prodotto da `normalize_payload_for_openai()`, cioè la struttura usata nel contesto inviato a OpenAI.
- Aggiunto il pulsante separato **Scarica JSON debug completo**: il file contiene `payload_type`, `debug_payload_full` e `openai_payload_normalized`, così è esplicito quali dati restano solo diagnostici e quali dati entrano nel prompt OpenAI.
- Il payload OpenAI esportabile resta privo di campi diagnostici/amministrativi (`content_search_query`, `theme`, `destination`, `selected_results_count`, `selection_context`, score/reason/provider/source/provenance, Source prompt, snapshot istruzioni, `internal_notes`, audit timestamps/autori) e mantiene `slug_required: true`.
- Le regole duplicate restano deduplicate nelle sezioni finali `affiliate_rules`, `seo_rules`, `source_policies` ed `editorial_instructions.operational_rules`; il prompt personalizzato del profilo è disponibile in alto in `editorial_instructions.custom_prompt`.
- Il contesto Viator nei link affiliati viene ulteriormente sintetizzato escludendo righe tecniche su provider/source oltre a product code, rating/recensioni e note operative.

## Novità 2.25.24 — PR 8.24 Compact OpenAI Draft Payload and JSON Diagnostics

- Payload OpenAI della bozza da risultati selezionati normalizzato in sezioni compatte (`task`, `site`, `article_request`, `editorial_instructions`, `output_requirements`, `affiliate_links`, regole e warning).
- Rimossi dal payload inviato al modello campi diagnostici/deduplicati come `theme`, `destination`, `selected_results_count`, score/reason/provider/source/provenance, prompt Source completi, snapshot istruzioni ridondanti e `internal_notes`.
- Separati payload completo di debug/download e payload effettivamente inviato a OpenAI.
- `slug` ora obbligatorio nel contract JSON, con fallback locale sanificato dal titolo e warning non bloccante.
- Diagnostica JSON più specifica per risposta vuota, probabile troncamento, JSON non parsabile/testo fuori oggetto, campi obbligatori mancanti e contenuto troppo corto.

## Novità 2.25.23 — PR 8.23 Enforce Valid OpenAI Draft JSON and Improve API Error Feedback

- Creazione bozza articolo da Idea/Sessione con richiesta output JSON più vincolante (solo oggetto JSON, niente Markdown) e contratto campi esplicito.
- Supporto `response_format` nel wrapper OpenAI con uso prioritario schema JSON per `content_draft_generation` e fallback sicuro quando non supportato dal modello.
- Parser risposta AI robusto: decode diretto, rimozione code-fence, estrazione JSON bilanciato e diagnostica dettagliata su errori di parsing.
- Validazione output contract con controllo campi obbligatori e blocco creazione bozza se `title` o `content` sono vuoti.
- Distinzione esplicita errori API vs errori JSON con log/notice più utili (categoria errore, codice, preview sicura risposta).
- Nessuna modifica a indice Link affiliati, ricerca affiliate, batch/sync, provider/importer, shortcode rendering o tracking.

## Novità 2.25.22 — PR 8.22 Refine AI Draft Payload Affiliate URL Policy and Source Instructions

- Rifinita la policy payload AI per i link affiliati: shortcode WordPress preferiti per box/link, con `affiliate_url` ammesso quando serve un link testuale diretto.
- La sezione `rules` richiede esplicitamente: `prefer_affiliate_shortcodes`, `allow_affiliate_urls_for_text_links`, `do_not_invent_affiliate_urls`, `use_only_payload_affiliate_links`.
- Aggiunta sezione globale `source_agent_prompts` che raggruppa i prompt Source non vuoti, deduplicati e collegati ai relativi `link_ids`.
- Nei singoli `affiliate_links` restano campi leggeri di riferimento (`source_agent_prompt_key` / disponibilità prompt) per ridurre duplicazione dei prompt lunghi.
- Gestione non bloccante dei link manuali o legacy senza Source prompt: i link restano selezionabili/usabili se hanno dati principali (`title/content/affiliate_url/shortcode`).
- Warning payload più precisi su configurazione comportamento agente globale e disponibilità istruzioni Source.
- Nessuna modifica a OpenAI Service, indice affiliati, ricerca affiliate, batch/sync, provider/importer, shortcode rendering o tracking.

## Novità 2.25.21 — PR 8.21 Safe AI Payload JSON Builder and Download Hardening

- `ALMA_AI_Content_Agent_Selection_Session::normalize_result()` preserva ora nei risultati in sessione i metadati affiliate necessari ai filtri UI: `link_types`, `provenance`, `provider`, `source`.
- `link_types` viene normalizzato in formato stabile (array di stringhe sanificate) con supporto input array, CSV o stringa singola, deduplica e rimozione valori vuoti.
- I filtri della card **2. Risultati ricerca** (**Tipologie Link** e **Fonte / Source / Provider**) si popolano correttamente dopo una nuova ricerca affiliate e continuano a funzionare con paginazione/reset.
- Nessuna modifica a scoring, indice affiliate (batch/sync), OpenAI Service, Draft Builder, provider/importer, shortcode o tracking.

## Novità 2.25.15 — PR 8.15 Ideas Active Box UI and Affiliate Index Action Descriptions

- Box **Idea attiva** riorganizzato in sezioni leggibili: riepilogo idea attiva, lista **Idee create** con record separati e sezione **Dettagli idea**.
- Ogni record idea mostra titolo, ultima modifica, badge **Attiva** e pulsante **Carica** mantenendo invariati form/action esistenti.
- Card Dashboard **Indice Link affiliati** con descrizioni brevi per azioni batch/sync e manutenzione avanzata (reset/svuota indice).
- CSS admin migliorato con stili scoped sotto `.alma-ai-agent-admin` per sezioni, metadati, badge, record e descrizioni azioni.
- Nessuna modifica funzionale a indice affiliate, OpenAI Service, Draft Builder, provider/importer, shortcode o tracking.

## Novità 2.25.13 — PR 8.13 Ideas Instruction Profile Single Select Form Submission Fix

- Il select unico **Profilo Istruzioni AI** nella card **1. Cerca contenuti** invia ora correttamente `instruction_profile_id` al form `search_knowledge_base`.
- Il form **Salva idea** riceve lo stesso valore profilo tramite campo hidden sincronizzato in modo difensivo con JavaScript vanilla (`assets/admin.js`), senza duplicare il select visibile.
- Hardening backend: `instruction_profile_id` assente non viene interpretato come clear; il profilo esistente viene preservato, mentre `0` resta una scelta esplicita valida (**Nessun profilo**).
- Nessuna modifica a indice Link affiliati, ricerca/scoring affiliate, batch/sync affiliate o OpenAI Service.

## Novità 2.25.12 — PR 8.12 Ideas UI Instruction Profile Cleanup and Clear Profile Fix

- Rimossa la duplicazione UI del campo **Profilo Istruzioni AI** nella tab Idee contenuto: ora il select è mostrato una sola volta.
- Selezionando **Nessun profilo** (`instruction_profile_id=0`) il valore viene salvato esplicitamente sull'idea e mantenuto ai reload/azioni successive.
- Evitata la riassociazione silenziosa al profilo globale attivo quando un'idea è salvata con nessun profilo.
- Nessuna modifica a indice Link affiliati, ricerca affiliate, batch/sync affiliate, OpenAI Service, provider/importer, shortcode o tracking.

## Novità 2.25.11 — PR 8.11 Persist AI Instruction Profile on Content Ideas

- Profilo Istruzioni AI selezionato ora persistito sull'Idea contenuto e ricaricato in modo coerente dopo salvataggi/reload.
- Profilo istruzioni preservato durante azioni non correlate (ricerca, aggiunta/rimozione risultati, persistenza sessione).
- Fallback sicuro se il profilo associato non è più valido (nessun fatal, fallback al profilo attivo solo quando necessario).
- Nessuna modifica a indice Link affiliati e OpenAI Service.
- `get_index_stats()` e `sync_incremental()` condividono ora la stessa semantica robusta per i record non active: `status` diverso da `active`, vuoto (`''`) o `NULL`.
- Categorie pending mutualmente esclusive confermate: `missing_index` (nessun record), `stale_index_records` (record `active` obsoleto), `non_active_candidate_records` (record presente non active).
- La CTA **Sync incrementale** resta l’azione primaria quando `missing_index = 0` e restano record obsoleti o candidabili non attivi.
- Nessuna modifica a ricerca/scoring e nessuna modifica al batch completo cursor-based.


## Nota operativa pre-test (primo sync controllato indice Link affiliati)

1. Verifica Dashboard e conteggi (`Candidabili`, `Mancanti`, `Obsoleti`, `Candidabili non attivi`).
2. Non avviare subito sync completo su tutto il dataset reale.
3. Esegui un primo batch di indicizzazione.
4. Esegui un secondo batch e verifica avanzamento/progress bar/conteggi.
5. Prosegui batch-by-batch fino al completamento.
6. Usa **Sync incrementale** per record obsoleti o candidabili non attivi.
7. Usa **Svuota indice e ricomincia** solo se la diagnostica risulta incoerente.

## Novità 2.12.1 — Pagina Importa contenuti + fix import

- Nuova azione **Importa contenuti** nella lista Affiliate Sources (solo source attive/non archiviate).
- Nuova vista dedicata `alma_view=import_contents` con anteprima, selezione elementi e submit sicuro (solo nonce, source_id, external IDs).
- Risultato import con PRG in `alma_view=import_result`.
- Fix Viator: import limit realmente supportato fino a 100 per preview/import manuale (discovery resta limitata).
- Fix AI context: se `regenerate_ai_context_on_import=0`, il contesto esistente non viene cancellato.
- Applicazione reale delle Tipologie Link da `import_link_type_term_ids` in merge con i termini già presenti.
- Nessun cron, nessun booking/checkout/pagamenti/cancellazioni in questa release.

## Novità 2.12.0 — Import manuale con anteprima (Source)

- Introdotte regole import a livello Source: `import_limit` (max 100), `duplicate_policy`, `editorial_overwrite_policy`, `regenerate_ai_context_on_import`, `import_link_type_term_ids`.
- Aggiunti servizi dedicati per anteprima/import manuale controllato.
- Supporto anteprima provider Viator via client esistente e limite hard a 100 elementi.
- Nessun cron/scheduler in questa release: solo flusso manuale.

Questo plugin gestisce e ottimizza i link affiliati all'interno di WordPress.

## Hotfix 2.10.2 (Viator)

- Fix fatal nella pagina admin **Campi importabili** quando la discovery Viator fallisce: ora viene mostrata una notice e il rendering continua.
- Discovery error-safe con fallback robusti per endpoint/ultimo aggiornamento/campi runtime.
- Catalogo campi Viator documentati mostrato anche quando la chiamata runtime fallisce.
- Correzione `sort_order` Viator con retrocompatibilità `order` legacy e normalizzazione `ASC/DESC` verso `ASCENDING/DESCENDING`.
- Nessun impatto su frontend, tracking click, shortcode e widget.


## Novità 2.11.0 — Contesto AI interno (Affiliate Link)

- Introdotto il meta interno `_alma_ai_context` per il CPT `affiliate_link`, usato solo dall’Agente AI e **mai pubblicato nel frontend**.
- Aggiunti anche `_alma_ai_context_updated_at` (data ultimo update) e `_alma_ai_context_hash` (hash dati sorgente).
- Nuovo builder dedicato (`includes/class-affiliate-link-ai-context-builder.php`) che aggrega dati normalizzati/provider in testo sintetico conforme a compliance.
- Nessun nuovo meta provider-specific separato per prezzo/rating/destinazione: i dati restano nel contesto aggregato.
- Esclusioni compliance: niente recensioni testuali, niente raw response complete, niente API key/token, descrizioni lunghe troncate.
- In `Affiliate Sources` nuova sezione **Istruzioni e aggiornamento AI** con:
  - `ai_source_instructions`
  - `ai_context_refresh_interval` (`manual|24h|72h|7d|30d`)
  - `api_sync_interval` (`manual|24h|72h|7d|30d`)
  - `ai_context_regeneration_policy` (`only_if_hash_changed|if_hash_changed_or_expired|always_on_import|manual_only`)
- Regole rigenerazione contesto: hash cambiato, TTL scaduto, policy `always_on_import`, oppure rigenerazione forzata/manuale futura.
- Metabox tecnico del Link Affiliato esteso con sezione Contesto AI (readonly), timestamp update e hash abbreviato.

## Funzionalità principali

- Gestione dei link affiliati tramite un Custom Post Type dedicato.
- Tracciamento dei click con report e statistiche nel pannello di controllo.
- Suggerimenti AI per la generazione di titoli ottimizzati per la SEO e le conversioni.
- Assegnazione di tipologie personalizzate con creazione automatica delle categorie più comuni.
- Pulizia automatica degli shortcode quando i link vengono eliminati o spostati nel cestino.
- Dashboard riassuntiva con conteggio dei link attivi e dei click totali.
- Grafico a colonne dei click mensili sui link affiliati.

## Click Mensili sui Link Affiliati

La dashboard admin mostra un grafico a colonne dei click mensili sui link affiliati, ottenuto dall'endpoint `alma_get_chart_data` con `metric=clicks` e `range=monthly`. È possibile ottenere gli stessi dati con la seguente query SQL (sostituire `wp_` con il prefisso delle tabelle):

```sql
SELECT YEAR(click_time) AS anno, MONTH(click_time) AS mese, COUNT(*) AS clicks
FROM wp_alma_analytics
GROUP BY anno, mese;
```

## Dashboard admin ottimizzata (refactor performance)

La dashboard ora usa una classe dedicata (`includes/class-dashboard-stats.php`) che centralizza il calcolo delle statistiche e applica cache transient con TTL configurabile tramite filtro WordPress `alma_dashboard_cache_ttl`.

Miglioramenti principali:
- rendering iniziale leggero con shell in loading;
- caricamento dati via AJAX (`alma_get_dashboard_data`) con payload completo;
- grafici caricati tramite query aggregate e cache (`alma_get_chart_data`);
- riduzione query ripetute su shortcode grazie a mappa usage cache-izzata;
- invalidazione cache su save/delete/trash/untrash di post e pagine;
- indici DB aggiuntivi su `alma_analytics`: `(link_id, click_time)` e `(source, click_time)`.


## Affiliate Source Manager (2.7)

- Nuovo modulo **Affiliate Sources** sotto il menu del CPT `affiliate_link` (senza creare un archivio parallelo).
- Nuove tabelle dedicate a fonti, log sync e mapping categorie: `alma_affiliate_sources`, `alma_affiliate_source_logs`, `alma_affiliate_category_map`.
- Architettura provider-based estendibile con registry + provider nativi: `manual`, `csv`, `custom_api`, `generic_api`.
- Deduplicazione import con priorità: `provider+external_id`, `provider+sync_hash`, fallback su `_affiliate_url`.
- Sincronizzazione tra `_alma_affiliate_url` e `_affiliate_url` per garantire backward compatibility con shortcode e tracking esistenti.
- Nuova metabox tecnica nel singolo `affiliate_link` con metadati sorgente e readiness AI Agent.

## Affiliate Sources (2.7.1)

- CRUD base completo per le sorgenti: creazione e modifica dalla pagina `Affiliate Sources`.
- Form admin con campi `name`, `provider`, `is_active`, `language`, `market`, `import_mode`, `destination_term_id`, `settings` JSON, `credentials` JSON.
- Salvataggio sicuro con nonce, capability check e sanitizzazione lato server.
- Rimozione della UI legacy `Importa Link` dal menu admin (classi backend mantenute per compatibilità).
- Associazione visibile `Source -> Affiliate Link` nel CPT con campo “Provenienza” (fallback “Manuale”).
- Hardening tracking: il frontend continua a usare `_affiliate_url`; se manca, viene riallineato automaticamente da `_alma_affiliate_url`.

## Affiliate Sources hotfix (2.7.2)

- Fix fatal error nella pagina admin `Affiliate Sources` con gestione resiliente di tabella mancante, source non trovata, provider/registry non disponibili e JSON non valido.
- Aggiunto tentativo sicuro di repair/creazione tabella `alma_affiliate_sources` quando la pagina viene aperta.


## Affiliate Sources 2.8.0

- Provider editabile: campo testo libero con chiave tecnica normalizzata in `provider` e label leggibile in `provider_label`.
- Preset provider opzionali (manual, custom_api, viator, getyourguide, tiqets, booking_com, agoda, aviasales, discovercars, airalo, omio, safetywing) per compilazione guidata di settings/credentials.
- Destination term multiplo con `destination_term_ids[]` (JSON array) e retrocompatibilità su `destination_term_id`.
- UI guidata con sezioni `Configurazione provider` e `Credenziali`; JSON avanzato disponibile solo come pannello tecnico.
- Credenziali mascherate in edit: mai renderizzate in chiaro, preservate se campo vuoto, aggiornate solo su input nuovo.
- Nota: molte API reali richiedono approvazione commerciale e provisioning credenziali lato provider.


## Affiliate Sources hotfix 2.8.1

- In modifica source, i `settings` esistenti vengono preservati e uniti ai nuovi campi guidati/JSON avanzato valido senza cancellazioni involontarie.
- Le `credentials` esistenti restano salvate quando i campi password restano vuoti; i valori segreti non vengono mai precompilati o localizzati in JavaScript.
- Lo stato `Attivo/Disattivo` è ora gestito in UI e persistito correttamente in salvataggio (checkbox reale).
- Flusso Post/Redirect/Get dopo create/update con notice native WordPress (`created`, `updated`, `error`, `invalid_json`) e ritorno alla lista sorgenti.


## Affiliate Sources hotfix 2.8.2

- I campi guidati `settings_fields` sono ora autoritativi in salvataggio: nessun JSON avanzato/legacy può sovrascrivere un campo guidato inviato dall'utente.
- Rimossa dalla UI standard la textarea JSON avanzata precompilata dei `settings`; restano preservate in DB eventuali chiavi legacy/custom non renderizzate nel form.
- Merge order in edit: `settings` esistenti -> eventuale payload avanzato esplicito -> guided fields (priorità finale ai guided).


## Affiliate Sources 2.9.0

- Nuova azione **Testa connessione** nella lista sorgenti (AJAX asincrono, nonce+capability check).
- Nuova pagina **Campi importabili** esplorativa con refresh manuale e tabella field discovery.
- Architettura modulare a service/client/factory in `includes/` senza impattare il salvataggio sorgenti/importer.
- Supporto reale iniziale: `custom_api`; altri provider mostrano stato *non ancora supportato*.
- Sicurezza credenziali: nessun segreto esposto in HTML/JS/URL/notice, esempi redatti e troncati.
- Limiti discovery: payload JSON richiesto, timeout breve, risposta troppo grande/invalid JSON gestiti con messaggi chiari.


## Affiliate Sources 2.9.1

- La diagnostica provider usa `provider_preset` come chiave tecnica primaria; fallback su `provider` valido, alias legacy controllati e infine fallback client per provider non supportati.
- Fix routing `Custom API`: funzionano `provider_preset=custom_api`, `provider=custom_api`, legacy `provider=customapi` e label libera con preset valido.
- `Testa connessione`, `Campi importabili` e refresh discovery condividono la stessa risoluzione canonica del provider nella factory.
- Storico ultimo test connessione spostato in option aggregata unica `alma_last_connection_tests` non-autoloaded (chiavi per source ID, pruning automatico).
- Nessun segreto memorizzato nello storico diagnostico: solo stato controllato, codice/messaggio, timestamp, durata e provider canonico.


## Affiliate Sources 2.9.2

- Dopo creazione/modifica source, redirect PRG verso una vista di conferma GET dedicata (niente pagina vuota e niente doppio submit al refresh).
- Schermata conferma con messaggio chiaro e riepilogo source: nome, provider, preset e stato attivo/disattivo.
- Azioni rapide disponibili: `Torna alla lista Sources`, `Modifica questa Source`, `Campi importabili` e `Testa connessione`.
- URL di conferma sicura (solo parametri non sensibili: `alma_view`, `status`, `source_id`).
- Fix feedback AJAX `Testa connessione` anche nella schermata conferma post-save (fuori tabella) con target `.alma-inline-result` sempre risolto.
- Nuovo filtro **Sources** nell'elenco admin Link Affiliati (`edit.php?post_type=affiliate_link`) basato su meta key `_alma_source_id`.
- Compatibilità mantenuta per link manuali/legacy e nessun impatto su frontend/tracking click.



## Affiliate Sources 2.10.0 — Viator Partner API v2

- Integrazione dedicata Viator con **unica credenziale API key** (nessun OAuth, client_id/client_secret, username/password).
- API key trattata come segreta e inviata solo via header `exp-api-key`.
- Header `Accept` versionato (`application/json;version=2.0`) e `Accept-Language` configurabile.
- Ambiente `sandbox/production` gestito internamente dal client, senza esporre base URL nella UI guidata.
- `Testa connessione` su endpoint leggero `/products/tags` con errori mappati e messaggi chiari.
- `Campi importabili` con discovery Viator su `/products/search` o `/search/freetext` e mapping suggerito.
- Differenza esplicita tra destination term WordPress e `default_destination_id` Viator (ID Viator).
- Uso di `productUrl` completo senza ricostruzioni o alterazioni dei parametri affiliati.
- Booking/checkout/pagamenti/cancellation **non inclusi** in questa release.
## 2026-04 Hotfix Affiliate Sources
- Fix: gestione POST create/update Source spostata su hook pre-render `load-<page_hook>` con redirect PRG sicuro.
- Aggiunta archiviazione Source (soft-delete) con `deleted_at`/`deleted_by`, pulizia credenziali e mantenimento link importati.
- Aggiunta conferma eliminazione dedicata e blocchi per test connessione/discovery su Source eliminate.
- Aggiunti snapshot source sui link (`_alma_source_name`, `_alma_source_provider`, `_alma_source_provider_label`, `_alma_source_deleted_at`).
- Filtro Sources nei Link Affiliati aggiornato con etichetta `(eliminata)` e compatibilità retroattiva.
## Hotfix 2.10.3 (Viator catalogo esteso)

- Pagina **Campi importabili** Viator migliorata con box informativi dedicati: Destination ID, recensioni/compliance, booking non implementato, differenza tra campi rilevati e documentati.
- Messaggio esplicito su `default_destination_id` mancante: ID numerico Viator recuperabile da `/destinations`, senza bloccare il catalogo documentato.
- Catalogo campi Viator documentati ampliato in modo sostanziale (identità prodotto, media, prezzi, recensioni aggregate, durata, destinazioni/tag, traduzioni, dettaglio prodotto, reference data e endpoint transazionali).
- Endpoint booking/checkout/pagamenti/cancellazioni marcati come **transazionale / non implementato**.
- Nessun impatto su frontend, tracking click, shortcode o widget; nessuna chiamata remota extra aggiunta.


## Versione 2.13.0
- Nuova UX Importa contenuti con criteri ricerca runtime (max 100).



### AI Content Agent — payload OpenAI compatto e download debug separato (2.25.25)

Nel flusso **Crea Bozza con OpenAI** da risultati selezionati, il payload effettivamente inviato al modello viene ora normalizzato in una struttura compatta focalizzata sulla generazione editoriale.

- Il modello riceve sezioni dedicate per task, sito, richiesta articolo, istruzioni editoriali, requisiti output, link affiliati, regole affiliate/SEO, policy fonti e warning.
- **Scarica JSON payload OpenAI** esporta il payload normalizzato realmente usato nel contesto OpenAI (`alma-ai-openai-payload-idea-*.json`).
- **Scarica JSON debug completo** esporta un wrapper diagnostico (`alma-ai-debug-payload-idea-*.json`) con `debug_payload_full` per troubleshooting e `openai_payload_normalized` per confronto diretto.
- I dati diagnostici o amministrativi restano disponibili solo in `debug_payload_full`, ma non vengono inviati a OpenAI.
- Sono esclusi dal payload AI campi come `content_search_query`, `theme`, `destination`, `selected_results_count`, `selection_context`, score/reason/provider/source/provenance, prompt Source completi, snapshot istruzioni ridondanti, `internal_notes`, autori e timestamp amministrativi.
- Il prompt personalizzato del profilo istruzioni viene esposto in alto dentro le istruzioni editoriali, insieme a tono, pubblico e stile.
- Il contesto Viator viene sintetizzato in modo prudente, senza blocchi provider tecnici, rating/recensioni aggregate, product code o note operative lunghe.
- Il contract JSON richiede sempre `title`, `slug`, `excerpt`, `content`, `seo_title`, `seo_description`, `affiliate_shortcodes_used`, `affiliate_urls_used`, `media_used` e `warnings`. Se lo slug manca o non è valido, viene generato/sanificato localmente senza bloccare la bozza.
- La diagnostica errori distingue risposta vuota, risposta probabilmente troncata, JSON non parsabile, testo fuori dall’oggetto JSON, campi obbligatori mancanti e contenuto troppo corto, senza esporre API key o prompt completi nei messaggi admin.

## OpenAI-only AI Core (2.15.0)
- Provider AI attivo unico: OpenAI.
- Configurazione API in Impostazioni con test connessione manuale.
- Logging token/costi/tempi su storage dedicato.
- AI Content Agent: pannello admin preparatorio (senza generazione automatica contenuti in questo step).

### AI Content Agent Step 2 (Data Layer)
Questa versione introduce il Data Layer locale dell'AI Content Agent (Knowledge Base, Documenti, Fonti, Media Library Intelligence) con storage custom e indicizzazione batch manuale.
- Media Library WordPress resta la sorgente unica per immagini/documenti attachment.
- Nessuna generazione contenuti, nessuna creazione bozze, nessuno scheduler/cron nuovo, nessuna trend analysis automatica.
- OpenAI resta configurato solo in Impostazioni e non viene chiamato automaticamente durante l'indicizzazione.
- Nessun supporto Claude e nessun AI Provider Router.


### AI Content Agent Step 2.1 (dbDelta schema compatibility hotfix)
- Corrette tutte le query `CREATE TABLE` passate a `dbDelta()` rimuovendo `IF NOT EXISTS` per compatibilità con parser schema WordPress.
- Aggiunti controlli diagnostici tabelle AI mancanti nella sezione Stato/Log del pannello AI Content Agent.
- Aggiornato il flusso di update plugin per rieseguire in sicurezza le routine schema AI senza operazioni distruttive.

### Step 3: Editorial Planner and Briefs
- Generazione idee e brief su azione esplicita admin.
- Nessuna creazione bozze, pubblicazione o scheduler.
- OpenAI-only, nessun Claude/provider router.


## Step 3.5 – Istruzioni AI
- Rimossa la vecchia area legacy "PROMPT PER AI DEVELOPER".
- Nuova tab AI Content Agent: "Istruzioni AI" per regia editoriale (tono, SEO, affiliate, anti-duplicazione, disclosure).
- Profili istruzioni salvati su tabella dedicata, con profilo default e attivazione/disattivazione.
- Planner e Brief usano il profilo attivo e salvano riferimento/snapshot compatto.
- Nessuna bozza WordPress, nessuna pubblicazione e nessuno scheduler aggiunto in questo step.


## Step 4 — Draft Builder MVP (v2.19.0)
Workflow base completo: genera idee → genera brief → genera bozza → apri in editor WordPress.

- Generazione bozza disponibile solo via click admin esplicito "Genera bozza" (nessuna chiamata OpenAI al page load).
- Output AI JSON strutturato validato con fallback extract_first_json().
- Salvataggio esclusivo in `post_status=draft` (mai pubblicazione/scheduler/cron).
- Link affiliati in contenuto solo via shortcode `[affiliate_link id="ID" text="anchor"]` con validazione ID candidato.
- Featured image solo da attachment ID candidato e valido in Media Library (nessuna modifica Media Library).
- Meta AI e SEO interni ALMA salvati sul post draft; warning QA salvati in meta.
- Fix pre-Step4 inclusi: log errori save idee e overview robusta con tabelle mancanti.
- OpenAI-only: nessun Claude, nessun provider router.

## AI Content Agent UI Refresh
- Nuova UI admin AI Content Agent con Dashboard come tab iniziale e nuova navigazione workflow: Dashboard, Idee contenuto, Documenti TXT, Fonti online AI, Reindicizza, Stato/log.
- Migliorata leggibilità con cards, badge stato, empty state e CTA rapide verso i passaggi principali.

## PR 2 — AI Content Agent TXT Documents & Online AI Sources
- Documenti TXT: upload operativo solo `.txt`, salvataggio nel Knowledge Base e chunking/indexing locale.
- Fonti online AI: CRUD operativo con URL validation, tecnologia da lista consentita e stato active/inactive.
- Questa PR non introduce crawling/scraping né nuove chiamate AI/OpenAI.


## AI Content Agent 2.24.3
La tab Idee contenuto ora usa idee persistenti (CPT), layout operativo in 3 colonne, prompt OpenAI per idea, sessione contenuto associata all'idea e creazione bozza con una sola chiamata OpenAI. Il Profilo Istruzioni AI selezionato resta associato all'idea e viene mantenuto anche dopo salvataggi/reload.


## AI Content Agent 2.24.5

- Fix regressione UI/funzionale tab Idee contenuto.
- Ripristinato pulsante **Crea nuova idea** e form **1. Cerca contenuti**.
- Colonna sinistra convertita in **Idea attiva** (rimossa lista idee salvate).
- Ripristinata **3. Sessione contenuto** con rimozione elementi.
- Rimossa limitazione incompleta a 10 risultati e paginazione placeholder.
- Corretto badge **Già nell'idea** tramite `result_key`.

## AI Content Agent 2.24.4
- Paginazione risultati (10 per pagina) per tipologia con paginazione indipendente per gruppo.
- Evidenza risultati già aggiunti all'idea con badge "Già nell'idea".
- Indicatore "Utilizzato in bozze: X" su ogni risultato.
- Azioni finali spostate sopra il layout a 3 colonne.
- Nessuna modifica alla logica single-call OpenAI su Crea Bozza con OpenAI.


## Guida operativa indice Link affiliati
- Verifica i conteggi nella card **Indice Link affiliati** (totale pubblicati, candidabili, missing/needs update).
- Se l’indice è vuoto, avvia **Avvia primo batch**.
- Continua con **Continua indicizzazione** finché il batch risulta completato.
- Quando il primo popolamento è completato, usa **Sync incrementale** per allineare le modifiche successive.
- Usa **Svuota indice e ricomincia** solo quando serve ricostruire da zero l’indice tecnico.
