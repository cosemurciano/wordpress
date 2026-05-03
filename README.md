
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

Versione 2.15.0


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
