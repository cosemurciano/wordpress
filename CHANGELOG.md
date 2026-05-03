
## 2.23.0 — PR 6 — AI Content Agent Single OpenAI Draft Workflow
- Workflow idee contenuto semplificato: ricerca locale, selezione manuale, profilo istruzioni AI e creazione bozza.
- Unica chiamata OpenAI al click su Crea Bozza; nessuna chiamata AI in ricerca/selezione/download JSON payload AI.
- Aggiunto download “Scarica JSON payload AI” dalla sessione contenuto.
- Deduplicazione con canonical session keys (post/page/affiliate_link/document_txt/source_online/media).
- Disattivato step operativo brief AI separato e rimossi riferimenti operativi a Claude/Anthropic nella UI.
- Confermato post_status=draft e nessuna pubblicazione automatica.
## 2.22.2 — PR 5.1.1 — AI Content Agent Review Fixes
- Fix P1 Documenti TXT: gestione `knowledge_item_id` stabile dalla ricerca alla Selection Session e resolver difensivo nel Draft Builder per key normalizzate (`document_txt:kb_document_txt_123`, `kb:document_txt:123`, `kb_document_txt_123`).
- Fix P2 tab/CTA Istruzioni AI: rimosso remap verso Idee, tab dedicata visibile e routing diretto a `render_instructions_tab()`.
- Migliorata notice risultato creazione bozza da Selection Session con dettagli completi (stato draft, link azione, profilo, modello, conteggi fonti, warning QA).
- Confermato: nessuno scheduler, nessuna pubblicazione automatica, nessun crawling/scraping/web search.

## 2.22.1 — PR 5.1 — AI Content Agent Draft Creation from Selection Session
- Pulsante **Crea bozza articolo** operativo nella tab Idee contenuto.
- Generazione bozza da Selection Session con uso dei soli risultati selezionati.
- Content Budget applicato con limite massimo 3 Post e contesto compatto strutturato.
- Payload AI strutturato e richiesta output JSON (`title`, `content`, `slug`) con validazione robusta.
- Creazione post WordPress in stato `draft` con permalink/preview generati da WordPress.
- Salvataggio meta provenance AI, warning QA locali e logging AI usage.
- Nessuna pubblicazione automatica, nessuna programmazione/scheduler.
- Nessun crawling/scraping e nessuna web search.



## 2.21.1

- PR 4.1 — AI Content Agent Knowledge Search Review Fixes.
- Fix Media Search: ordinamento su `indexed_at` (tabella `media_index`).
- Fix deduplica Documenti TXT con `source_id=0` (chiave distinta per knowledge item).
- Hardening Media Search: fallback a array vuoto se query non iterabile/errore.
- Nessuna nuova chiamata OpenAI, nessuna creazione bozza articolo.
- Nessuna modifica scheduler, nessun crawling/scraping.

## 2.21.0

- PR 4 — AI Content Agent Cumulative Selection Session.
- Sessione cumulativa di selezione con Aggiungi nuova ricerca, deduplicazione, Salva selezione, Svuota sessione e riepilogo sessione.
- Limite massimo 3 Post validato lato UI e server-side.
- Preparazione context package interno per PR future.
- Nessuna chiamata OpenAI, nessuna creazione bozza articolo, nessuna programmazione.

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

## 2.19.0
- Step 4 MVP: Draft Builder end-to-end (idea→brief→draft WordPress) con sola bozza e apertura editor.
- Nuova validazione QA locale per bozza, disclosure affiliate obbligatoria, validazione shortcode affiliate e featured image candidate.
- Tab Bozze operativa e azione "Genera bozza" nella tab Idee (con prevenzione duplicati).
- Fix review: logging fallimenti save_ideas come unsuccessful; overview evita COUNT su tabelle mancanti.
- Architettura confermata OpenAI-only (nessun Claude/router/provider esterni).
## 2.18.1
- Step 3.5.1 Admin Regression Fix: ripristinate tab operative Step 1/2 (Overview, Documenti, Fonti, Knowledge Base, Media Library, Reindicizzazione, Stato/Log).
- Ripristinate action admin Step 2 (reindex_knowledge, reindex_media, index_document, save_note, save_source) con nonce/capability/sanitizzazione e notice post-action.
- Ripristinata tab Idee contenuto con azioni Approva/Scarta/Archivia/Genera brief e dettagli brief esistente.
- Migliorata tab Istruzioni AI con gestione profili multipli (lista, modifica, creazione, attivazione/disattivazione).
- Fix fallback JSON (`extract_first_json`) per evitare fatal con output AI non pulito.
- Fix `save_ideas()`: conta solo insert riusciti e restituisce errori DB sanitizzati.
- Aggiunte diagnostiche `required_tables()` / `missing_tables()` e hardening Context Builder sui chunk ammessi.
- Nessuna bozza/pubblicazione/scheduler in questa release.

## 2.18.0
- Step 3.5 AI Content Agent: rimossa area legacy "PROMPT PER AI DEVELOPER" e relativi asset/menu.
- Aggiunta tab "Istruzioni AI" con profili editoriali e profilo default.
- Planner/Brief integrano profilo istruzioni attivo con snapshot/hash (senza log prompt completi).
- OpenAI-only confermato; nessuna creazione bozze/pubblicazioni/scheduler in questo step.


## 2.17.0
- AI Content Agent Step 3: Editorial Planner and Briefs (OpenAI-only).
## 2.15.0
- OpenAI-only core: rimosse chiamate operative Claude/Anthropic, introdotto servizio unico OpenAI Responses API via `wp_remote_post`.
- Nuove impostazioni OpenAI in pagina Impostazioni con test connessione dedicato, stato configurazione, model selector (gpt-5.4-mini default).
- Aggiunto logging utilizzo AI su tabella dedicata `*_alma_ai_usage`.
- Aggiunto shell admin `AI Content Agent` con tab preparatorie non operative.

## 2.14.3
- Pagina **Importa contenuti**: aggiunta sezione **Filtri risultati** con checkbox `Solo nuovi nel plugin`, `Mostra anche già importati` e `Riempi automaticamente preview con nuovi item`.
- Nella tabella anteprima aggiunta colonna **Link affiliato** (usa `productUrl` originale): link `Apri` in nuova tab con `rel="noopener noreferrer"`, tooltip URL completo e fallback `N/D` se assente.
- Aggiornati JS/CSS per mantenere i filtri tra preview/paginazione e migliorare layout/allineamento in stile admin WordPress.

## 2.14.2
- Completamento UX Importa contenuti: supporto start incrementale Viator, filtro Solo nuovi da API, toggle mostra già importati, criteria token transient e miglioramenti preview/import selezionati.
- Correzioni su import_include_automatic_translations default=1 e limite richiesta Viator a max 50 risultati per chiamata.
- Migliorata nomenclatura UI e basi per Carica altri risultati.

## 2.14.1
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


## 2.13.0
- Import contenuti con criteri runtime e limiti Viator aggiornati.

# Changelog

## 2.12.1 - Importa contenuti (admin) + fix manual import
- aggiunta azione `Importa contenuti` nella colonna Azioni delle Affiliate Sources non archiviate
- nuova pagina admin `alma_view=import_contents` con riepilogo source, preview import, checkbox, seleziona/deseleziona tutti e contatore selezionati
- submit import sicuro con soli `nonce`, `source_id`, `selected_external_ids[]`; nessun payload raw o credenziale nel form
- nuova vista risultato `alma_view=import_result` (PRG) con contatori create/update/skip/error
- fix Viator import limit: builder body con contesto/max count (preview/import fino a 100, discovery invariata)
- fix AI context: disattivare rigenerazione non cancella `_alma_ai_context`
- applicazione `import_link_type_term_ids` ai link importati con merge termini esistenti
- deduplicazione mantenuta su `_alma_source_id` + `_alma_external_id`
- versione plugin aggiornata a `2.12.1`

## 2.12.0 - Import manuale con anteprima controllata
- aggiunta base per regole import a livello Source (`import_limit` con clamp 1-100, policy duplicati/editoriali, rigenerazione contesto AI, tipologie link da assegnare)
- introdotti servizi `ALMA_Affiliate_Source_Import_Preview_Service` e `ALMA_Affiliate_Source_Manual_Import_Service` per flusso manuale
- client Viator esteso con `fetch_items_for_import_preview(...)` per recupero sicuro lista prodotti in anteprima (no booking/checkout)
- aggiornata versione plugin a `2.12.0`

## 2.11.0 - Contesto AI interno per Affiliate Link
- aggiunto builder dedicato `ALMA_Affiliate_Link_AI_Context_Builder` per generare `_alma_ai_context` interno e non pubblicato
- aggiunti meta interni su `affiliate_link`: `_alma_ai_context`, `_alma_ai_context_updated_at`, `_alma_ai_context_hash`
- integrazione nel flusso importer: calcolo hash sorgente, policy rigenerazione e TTL configurabili a livello Source
- aggiunta sezione UI `Istruzioni e aggiornamento AI` in Affiliate Sources con campi:
  - `ai_source_instructions`
  - `ai_context_refresh_interval`
  - `api_sync_interval`
  - `ai_context_regeneration_policy`
- mapping Viator nel contesto AI con dati aggregati (prezzo/rating/durata/destinazione/tag/flags/policy/inclusioni/esclusioni/lingue/supplier) senza creare nuovi meta provider-specific
- metabox tecnico Link Affiliato esteso con sezione Contesto AI, timestamp, hash abbreviato e placeholder pulsante rigenerazione
- regole compliance nel builder: no recensioni testuali, no raw provider lunghi, no segreti/API key, nota anti-copia provider
- nessuna pubblicazione frontend del contesto AI
- versione plugin aggiornata a `2.11.0`

## 2.10.3 - Viator importable fields: catalogo esteso e note compliance
- pagina `Campi importabili` aggiornata con 4 box informativi: Destination ID Viator, recensioni/compliance, booking/pagamenti non implementati, campi rilevati vs documentati
- chiarimento esplicito su errore `missing_destination_id`: richiesto ID numerico Viator (`/destinations`), non categoria WordPress né nome città
- catalogo `ALMA_Affiliate_Source_Viator_Field_Catalog` ampliato con campi di:
  - identità prodotto, immagini, video, prezzi, recensioni aggregate, durata, destinazioni/tag/flags, traduzioni
  - dettaglio `/products/{product-code}` (ticketing, pricing ageBands, logistics, inclusioni/esclusioni, policy cancellazione, booking requirements, options, supplier, viatorUniqueContent)
  - reference data (`/destinations`, `/products/tags`, `/locations/bulk`)
  - endpoint transazionali (`/availability/check`, `/bookings/*`) marcati `transazionale / non implementato`
- recensioni testuali non implementate: solo dati aggregati diagnostici/compliance
- nessun booking/checkout/pagamento/cancellazione implementato in questa release
- nessun impatto su frontend, tracking, shortcode e widget

## 2.10.2 - Hotfix robustezza Campi importabili Viator
- fix fatal in `Campi importabili` quando discovery Viator restituisce `WP_Error` o valore inatteso: rendering sempre sicuro con notice e fallback `n/d`
- la tabella runtime resta renderizzata anche con discovery fallita; empty state esplicito quando non ci sono campi nel campione API
- catalogo Viator documentato reso robusto (guard su classe/metodo/output array, fallback chiavi mancanti, skip righe non valide)
- fix `sort_order` nel client Viator: priorità a `sort_order`, fallback legacy `order`, cache transient aggiornata includendo `sort_order`
- normalizzazione backward-compatible `ASC/DESC` -> `ASCENDING/DESCENDING`; `order` inviato solo quando `sort` è valorizzato e non `DEFAULT`
- nessun impatto su frontend, tracking click, shortcode e widget
- versione plugin aggiornata a `2.10.2`

## 2.10.1 - Hotfix Viator importable fields discovery
- fix fatal nella pagina Campi importabili quando discovery Viator fallisce o catalogo non è disponibile
- discovery Viator aggiornata: POST JSON body corretto per /products/search e /search/freetext, query string limitata a campaign-value/target-lander
- gestione status HTTP Viator con mapping errori leggibili (400/401/403/429/500/503)
- normalizzazione risposta runtime per forme diverse products/search e freetext_search
- fix required dei campi password guidati quando il segreto è già salvato
- safe truncation senza dipendenza obbligatoria da mbstring
- versione plugin aggiornata a 2.10.1

## 2.10.0 - Integrazione Viator Partner API v2 (Affiliate Sources)
- aggiunto client dedicato `ALMA_Affiliate_Source_Provider_Client_Viator` con gestione environment sandbox/production e header Viator
- preset Viator aggiornato: supporto test connessione + field discovery, provider type `commercial_api`, sola credenziale `api_key`
- UI guided fields estesa in backward compatibility con metadati (`label`, `type`, `options`, `default`, `help`, `required`, `placeholder`)
- rimossi dalla UI Viator i campi `base_url_production` e `base_url_sandbox` (gestione interna client)
- test connessione Viator su `/products/tags` con mapping errori (`missing_credentials`, `invalid_environment`, `invalid_api_version`, `unauthorized`, `forbidden`, `rate_limited`, `timeout`, `api_error`, `invalid_json`, `internal_error`)
- field discovery Viator su `/products/search` o `/search/freetext` con criteri minimi, count limitato e transient cache senza segreti
- normalizzazione aggiornata per fallback Viator (`productUrl`, `productCode`) e mapping hint per product summary
- versione plugin aggiornata a `2.10.0`

## 2.9.2 - Conferma post-save Affiliate Sources (UX + PRG hardening)
- aggiunta vista GET di conferma dopo create/update con flusso Post/Redirect/Get completo (nessun rendering diretto dopo POST)
- schermata di conferma con messaggio esplicito, riepilogo source (nome, provider, preset, stato) e azioni rapide
- introdotti pulsanti/azioni: `Torna alla lista Sources`, `Modifica questa Source`, `Campi importabili` e `Testa connessione` (riuso endpoint AJAX esistente)
- URL di conferma ridotta a parametri sicuri (`alma_view`, `status`, `source_id`) senza esposizione dati sensibili
- gestione errori/fallback senza pagina vuota (error/invalid_json/source non trovata)
- allineamento reale versione plugin a `2.9.2` (header plugin, costante `ALMA_VERSION`, README)
- fix feedback AJAX `Testa connessione` quando il pulsante è fuori tabella (schermata conferma post-save)
- aggiunto filtro Sources nell'elenco Link Affiliati con meta key `_alma_source_id` e preservazione query admin esistenti
- compatibilità mantenuta per link manuali (`_alma_source_id=0`) e link legacy senza metadato

## 2.9.1 - Provider routing canonico e storage diagnostico sicuro
- risoluzione provider centralizzata in factory: priorità `provider_preset` valido, poi `provider`, alias legacy (`customapi -> custom_api`) e fallback client
- fix completo routing Custom API per connection test e field discovery (incluso refresh)
- introdotto storage aggregato non-autoloaded `alma_last_connection_tests` per ultimo test connessione con payload minimale/sanitizzato
- cleanup/migrazione soft delle option legacy per-source `alma_last_connection_test_{id}`
- nessun segreto salvato nello storico diagnostico (no token/header/body/raw response)

## 2.9.0 - Connection test & importable fields discovery
- aggiunta azione AJAX `Testa connessione` nella lista Affiliate Sources con nonce, capability check e source validation
- aggiunta pagina admin `Campi importabili` con field discovery diagnostica, refresh e tabella campi
- introdotti service/client/factory dedicati per separare la logica provider dalla manager class
- supporto operativo per provider `custom_api`; fallback controllato per provider non ancora supportati
- caching transient su field discovery legato a source/configurazione e hardening output sensibile


## 2.8.2 - Guided settings authoritative hotfix
- guided `settings_fields` resi autoritativi: applicati per ultimi e non sovrascrivibili da JSON avanzato/legacy
- rimossa la textarea precompilata con JSON completo `settings` dalla UI standard Affiliate Sources
- mantenuta backward compatibility: preservazione chiavi `settings` legacy/custom non renderizzate nel preset
- merge settings in edit: DB esistente -> advanced extra espliciti -> guided fields

## 2.8.1 - Stabilizzazione salvataggio Affiliate Sources
- preservazione `settings` esistenti in edit con merge sicuro tra DB, `settings_fields` e JSON avanzato valido
- preservazione `credentials` esistenti: campi password vuoti non sovrascrivono, overwrite solo su nuovo valore non vuoto
- eliminata collisione dei nomi credential fields tra UI guidata e fallback (`credentials_fields` vs `credentials_extra_fields`)
- gestione corretta dello stato `is_active` (checkbox: 1 se selezionato, 0 se non selezionato)
- flusso PRG completo dopo insert/update con redirect alla lista e admin notice di esito
- fix UX: niente pagina vuota dopo salvataggio/errore JSON avanzato

## 2.8.0 - Provider connection profiles and multi-destination Affiliate Sources
- provider trasformato in campo testo libero con `provider_label` + `provider` tecnico normalizzato
- aggiunti provider presets e schema centralizzato (`class-affiliate-source-provider-presets.php`)
- supporto multi-destination terms (`destination_term_ids`) con fallback legacy `destination_term_id`
- nuova UI guidata per settings/credentials con pannello JSON avanzato
- masking e preservazione sicura credenziali in fase di edit
- migrazione DB incrementale per nuove colonne senza rompere installazioni legacy
- importer aggiornato per assegnare tutti i termini `link_type` configurati
- backward compatibility mantenuta per `_alma_provider`, `_alma_source_id`, shortcode e tracking

## 2.7.2 - Affiliate Sources admin fatal hotfix
- fix fatal nella pagina admin `Affiliate Sources` quando la tabella `alma_affiliate_sources` manca o la source in edit non esiste
- aggiunte guard clauses su provider registry, query DB e rendering metabox tecnica per evitare errori critici in admin
- aggiunto controllo update-version per creare/riparare le tabelle anche sugli aggiornamenti plugin (non solo su prima attivazione)

## 2.7.1 - Affiliate Sources CRUD & hardening
- aggiunto CRUD base per `Affiliate Sources` (creazione/modifica) con form admin dedicato
- aggiunti controlli sicurezza su salvataggio source (nonce, capability, sanitizzazione, JSON safe encode/decode)
- rimossa la UI legacy `Importa Link` dal menu/submenu admin (backend preservato per backward compatibility)
- aggiunta associazione visibile Source -> Affiliate Link nella UI del CPT (`Provenienza`, fallback `Manuale`)
- aggiornata metabox tecnica con provider, source name, import status, AI visibility
- hardening tracking URL: garanzia di uso `_affiliate_url` con fallback automatico da `_alma_affiliate_url`

## 2.7.0 - Affiliate Source Manager
- aggiunto modulo Affiliate Sources con submenu dedicato sotto `affiliate_link`
- introdotta architettura provider-based con interfaccia, registry, normalizer e importer
- aggiunti provider iniziali: `manual`, `csv`, `custom_api`, `generic_api`
- aggiunte tabelle DB: `alma_affiliate_sources`, `alma_affiliate_source_logs`, `alma_affiliate_category_map`
- aggiunta metabox tecnica sorgente su `affiliate_link`
- mantenuta compatibilità con `_affiliate_url`, shortcode, tracking e dashboard esistenti

## 2.6.1 - Dashboard optimization
- refactor dashboard con classe `ALMA_Dashboard_Stats`
- cache statistiche dashboard con transient e TTL filtrabile
- query analytics aggregate per grafici e metriche
- nuovi indici DB su `alma_analytics` (`link_id, click_time` e `source, click_time`)
- miglioramento UX dashboard con loading state e caricamento AJAX

## [Hotfix] - 2026-04-30
### Fixed
- Blank page dopo create/update Affiliate Source: POST ora gestito pre-render su `load-<page_hook>` con PRG.

### Added
- Flusso di archiviazione Source (soft-delete) senza cancellare i link affiliati importati.
- Conferma eliminazione Source con conteggio link associati e checkbox obbligatoria.
- Snapshot metadata source sui link associati e rimozione credenziali source archiviata.
- Gestione Source eliminate nel filtro admin Link Affiliati.


## 2.10.0
- Viator preset guidato con sola **Viator API key** (header `exp-api-key`) e nessuna richiesta OAuth/client_id/client_secret.
- Test connessione Viator dedicato su `/products/tags` con gestione errori (401/403/429/timeout/json).
- Discovery campi Viator con supporto `products_search` e `freetext_search`, cache temporanea e nessun salvataggio raw della risposta.
- Pagina Campi importabili migliorata: campi rilevati + catalogo campi documentati Viator, mapping suggeriti e note compliance.
- Note compliance: `productUrl` va conservato invariato; recensioni e `viatorUniqueContent` solo diagnostici; booking/checkout/pagamenti non inclusi.


## 2.16.1
- Hotfix compatibilità `dbDelta()`: rimosso `IF NOT EXISTS` da tutte le query `CREATE TABLE` usate nelle routine schema AI e analytics.
- Aggiornate routine activation/update per rieseguire creazione/migrazione schema AI in modo non distruttivo.
- Aggiunta diagnostica tabelle AI mancanti nel pannello AI Content Agent (tab Overview/Stato-Log).

## 2.16.0
- AI Content Agent Step 2 Data Layer: storage custom per knowledge/chunks/media/fonti/jobs, indicizzazione batch locale, document manager su Media Library nativa, source manager, knowledge/media tabs operative.
- Nessuna generazione contenuti, nessuna bozza, nessuno scheduler, nessun provider router/Claude.

## 2026-05-02 - PR 1 — AI Content Agent UI Refresh & Workflow Navigation
- Refresh UI AI Content Agent.
- Nuova struttura tab con Dashboard iniziale.
- Dashboard operativa con metriche e quick actions.
- Rinomina tab: Documenti TXT, Fonti online AI, Reindicizza.
- Rimozione dalla UI del salvataggio note manuali.
- Preparazione workflow per futura creazione bozza articolo (UI placeholder).

## PR 2 — AI Content Agent TXT Documents & Online AI Sources
- Upload TXT operativo (solo `.txt`) e indicizzazione in Knowledge Base con chunk.
- Gestione stato documenti TXT (active/inactive) ed eliminazione dal Knowledge Base.
- CRUD Fonti online AI con tecnologie supportate e validazioni sicurezza.
- Nessuna nuova logica AI, nessun crawler/scraping.
