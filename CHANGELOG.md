## 2.32.0 — Trend Idee contenuto
- Aggiunto il nuovo modulo admin **Trend Idee contenuto** per analizzare fonti pubbliche sui trend di viaggio con OpenAI Web Search e generare report editoriali strutturati per Sothra.
- Introdotte tabelle dedicate per configurazione fonti, report storici e log run; le fonti predefinite vengono inizializzate in modo idempotente su attivazione/upgrade.
- Aggiunto registry iniziale di fonti Priorità 1 e Priorità 2 con domini ammessi, categoria, intervallo default, prompt specifico e stato abilitato.
- La UI permette salvataggio prompt globale, prompt per fonte, enable/disable, intervallo giorni, test singola fonte, test completo e generazione manuale del piano editoriale.
- Lo scheduler WP-Cron controlla solo fonti abilitate e scadute, aggiorna last/next run e usa transient lock per evitare run parallele; il caricamento admin/dashboard non avvia chiamate OpenAI.
- I report salvano JSON validato, fonti, metriche predisposte per grafici, stato, modello e token quando disponibili; la vista report mostra sintesi, destinazioni, piano settimanale, bisogni, opportunità affiliate, rischi, fonti citate e JSON tecnico collassabile.
- Aggiunto box **Trend Idee contenuto** nella dashboard principale del plugin con ultimo report, conteggi, top destinazioni, top idee, alert e link rapidi.
- Versione plugin aggiornata a `2.32.0`.

## 2.31.0 — Filtro CSV GYG prima dell’importazione
- Aggiunto il box **Filtra contenuti prima dell’importazione** nella schermata aperta dopo **Importa / continua**, sopra la tabella **Risultati Anteprima**.
- La ricerca lavora sull’intero CSV normalizzato della sessione persistente, non solo sulla pagina visibile, e restituisce solo la pagina corrente per evitare rendering di migliaia di righe.
- Disponibili filtri per parole chiave, campo di ricerca, modalità (almeno una parola, tutte le parole, frase esatta), città, regione, tipologia attività opzionale, stato importazione e risultati per pagina 25/50.
- La città funziona anche senza tipologia selezionata; la tipologia è solo un filtro aggiuntivo e non limita da sola la ricerca globale nel file.
- Lo stato importazione è gestito dalla select **Solo non importati / Mostra anche già importati / Solo già importati**, che sostituisce il vecchio comportamento a checkbox nella preview GYG CSV.
- Aggiunti contatori per record totali, trovati, non importati, già importati, selezionati, selezionati non visibili e pagina corrente.
- Aggiunte azioni **Seleziona risultati visibili**, **Deseleziona risultati visibili** e **Seleziona tutti i risultati filtrati** con conferma oltre 100 record; filtrare e selezionare non avvia mai l’import automaticamente.
- La selezione resta basata su `external_id`, persiste al cambio filtro/pagina tramite UI admin, e l’import selettivo continua a passare al backend solo gli `external_id` scelti.
- Aggiunto helper riusabile `ALMA_Affiliate_Source_Import_Record_Filter` per normalizzazione testo, parole chiave, filtri e paginazione, con log diagnostico sintetico e sicuro.
- Note performance: la preview fa una scansione server-side della sessione CSV, batch query per lo stato già importato, nessuna chiamata OpenAI/API esterna e nessun payload CSV completo nei log.
- Versione plugin aggiornata a `2.31.0`.

## 2.30.2 - 2026-05-09
### Fixed
- Fixed GYG CSV selective import to process the exact selected `external_id` values instead of importing only the first compatible rows by count.
- Hardened GYG CSV dedupe validation so stale post IDs, wrong CPT matches, and trashed posts no longer count as already present.
- Made create/update/skip counters explicit for new records, existing records with update disabled, and existing records with update enabled.
- Split activity-title counters into titles read from the CSV and titles actually saved to affiliate links.
- Honored `manual_only` AI context regeneration policy for both created and updated GYG CSV links without touching existing AI context meta.
- Expanded final GYG CSV reports and safe diagnostics for selected IDs, found/missing IDs, valid/stale dedupe matches, created, updated, and skipped records.

## 2.30.1 — Import CSV: Titolo Attività e Contesto AI locale
- Aggiunto riconoscimento esplicito di `Titolo Attività` e alias tecnici/senza accenti nel flusso `gyg_csv`, mappandolo al titolo del CPT **Link affiliato** con priorità sui fallback.
- Aggiornata la revisione colonne dello Step 2 per mostrare `Titolo Attività` anche quando riconosciuto automaticamente, insieme a colonna originale, mapping interno, esempio e stato.
- Conservato il mapping esistente: `Descrizione attività` popola il contenuto, URL affiliato resta gestito dal deep link esistente e le **Tipologie Link** continuano a essere associate in merge.
- Generato localmente il **Contesto AI** da titolo, descrizione, città, regione, tipologia attività e Tipologie Link associate, salvandolo nei meta `_alma_ai_context`, `_alma_ai_context_updated_at` e `_alma_ai_context_hash` senza chiamate OpenAI.
- Aggiunti contatori e log diagnostici sicuri per titoli popolati/mancanti, contesti AI popolati/non popolati e Tipologie Link associate.
- Versione plugin aggiornata a `2.30.1`.

## 2.30.0 — AI Trend Radar
- Aggiunto il modulo **AI Trend Radar** in **Affiliate Link Manager AI → Trend Radar** per eseguire ricerche web programmate con OpenAI e proporre trend editoriali travel/turismo per Sothra.
- La sezione admin mostra report generati, card/azioni rapide, profili di ricerca, impostazioni pianificazione, log esecuzioni e avviso sul funzionamento di WP-Cron.
- I profili permettono di configurare lingua, mercato target, tema, focus editoriale, query seed, fonti preferite/escluse, frequenza/orario, numero massimo trend, profondità, obiettivo editoriale e riepilogo email.
- Lo scheduler usa WP-Cron con un evento per ogni profilo attivo, pulsante manuale “Esegui ricerca ora”, nonce/capability check e lock temporaneo anti doppia esecuzione. Per esecuzioni puntuali è consigliato un cron server reale che richiami `wp-cron.php`.
- Il modulo riusa la configurazione OpenAI esistente, invia richieste Responses API con web search quando disponibile, richiede JSON strutturato, valida l’output e registra errori/log senza API key o dati sensibili.
- Ogni report salva titolo trend, sintesi, perché ora, destinazioni, stagionalità, audience, punteggi normalizzati 1-10, titoli consigliati, keyword, outline, fonti, note fonte, link affiliati suggeriti, stato e data creazione.
- Dopo la generazione viene eseguito un matching locale limitato sui Link Affiliati esistenti; all’AI viene passato solo un contesto compatto di candidati, mai l’intero database.
- Bridge leggero con AI Content Agent: da un trend si può creare un’idea contenuto precompilata; se disponibile, è possibile anche creare una bozza articolo con sintesi, fonti, keyword, outline e link affiliati suggeriti.
- Se OpenAI non è configurata, la UI mostra un avviso chiaro e disabilita l’esecuzione manuale; se web search/Responses API non è disponibile, l’errore viene salvato nei log e i profili restano gestibili.
- Costi e limiti API: ogni esecuzione può consumare token e ricerche web OpenAI in base a modello, profondità, numero massimo trend e fonti analizzate; usare frequenze conservative e monitorare il billing OpenAI.
- Versione plugin aggiornata a `2.30.0`.

## 2.29.0 — GetYourGuide CSV import server-rendered
- Sostituito il flusso operativo `gyg_csv` basato su modale AJAX con la vista admin server-rendered `alma_view=gyg_csv_import_type`: lo Step 3 ora usa un link normale “Importa / continua” e non richiede JavaScript per caricare mapping, anteprima o import.
- Aggiunta pagina PHP con validazione capability/source/preset/token/file/hash, riepilogo sessione persistente, dettagli tipologia CSV, checklist multipla delle Tipologie Link Sothra da `link_type`, quantità clampata a massimo 1000 e modalità deduplica.
- Implementata importazione tramite form POST con nonce e PRG redirect, salvataggio mapping/progressi/ultimo report e report server-side con importati, aggiornati, già presenti, saltati, errori, URL non validi, record senza città/regione e quantità processata.
- Mantenute deduplica `source_id + external_id`, sessioni CSV persistenti, generazione URL affiliato esistente e preservazione dominio `.com`/`.it`; nessuna modifica a provider diversi da `gyg_csv`, CPT o tassonomie.
- Versione plugin aggiornata a `2.29.0`.

## 2.28.1 — Hotfix GYG CSV modal AJAX diagnostics and fallback
- Corretto il loading infinito del modale `gyg_csv` con stati progressivi, timeout AJAX e gestione esplicita di risposte non valide.
- Aggiunta diagnostica AJAX sicura nel modale, console error admin limitato e health check `alma_gyg_csv_modal_healthcheck`.
- Aggiunto fallback server-side “Apri importazione in modalità semplice” per importare una tipologia CSV senza dipendere dal modale AJAX.
- Migliorato cache busting/enqueue JS admin: `assets/affiliate-sources.js` usa la versione `ALMA_VERSION` aggiornata a `2.28.1`.
- Se il modale `gyg_csv` non carica le Tipologie Link Sothra, usare “Test caricamento modale” o “Apri importazione in modalità semplice”.
- Versione plugin aggiornata a `2.28.1`.

## 2.28.0 — Persistent GetYourGuide CSV import sessions
- Aggiunte sessioni CSV `gyg_csv` persistenti in `wp-content/uploads/alma-imports/gyg-csv/` con file CSV a nome non prevedibile, validazione estensione/MIME e protezioni `.htaccess`/`index.html` contro listing o esecuzione.
- Aggiunte le tabelle `alma_gyg_csv_import_sessions` e `alma_gyg_csv_import_progress` via `dbDelta` per salvare token sicuri, colonne/summary CSV, mapping per tipologia, conteggi importati/aggiornati/già presenti/saltati/errori e cursore ultimo batch.
- Aggiunta la sezione “Sessioni CSV recenti” nella pagina Importa contenuti `gyg_csv`, con ripresa importazione senza nuovo upload ed eliminazione sicura della sessione/file/progressi senza eliminare Link affiliati importati.
- Persistito il mapping Tipologia attività CSV → Tipologie Link Sothra per sessione/tipologia e mantenuta compatibilità con i mapping già salvati nella configurazione source.
- Allineati AJAX prepare/import batch alle sessioni persistenti e all’hash stabile `activity_type_hash`, preservando la deduplica `source_id + external_id`, il limite massimo 1000 e la generazione link affiliato esistente senza conversione dominio.
- Rafforzato il modale admin: la chiamata prepare parte all’apertura, mostra “Richiesta in corso…”, gestisce risposte `0`, `-1`, HTML/non JSON, `success:false` e termini mancanti, espone diagnostica sicura e registra dettagli tecnici in `console.error`.

## 2.27.2 — Hotfix GetYourGuide CSV modal loading
- Corretto il blocco del modale `gyg_csv` su “Caricamento tipologie…” quando il caricamento AJAX prepare fallisce o restituisce payload incompleto.
- Migliorata la gestione errori AJAX del modale con messaggi leggibili, disattivazione sicura del pulsante import e log admin-side minimo.
- Allineato il payload prepare import tra PHP e JavaScript includendo `terms`, `mapped_term_ids`, `counts`, `preview`, `activity_type`, `source_id`, `token` e `max_quantity`.
- Verificata la tassonomia reale delle Tipologie Link: il flusso `gyg_csv` usa la tassonomia esistente `link_type` del CPT `affiliate_link`.
- Versione plugin aggiornata a `2.27.2`.

## 2.27.1 — Hotfix GetYourGuide CSV modal fatal
- Corretto il fatal nello Step 3 `gyg_csv` causato dal metodo mancante di normalizzazione mapping Tipologia attività CSV → Tipologie Link Sothra.
- Corretto il fatal in apertura del modale “Importa questa tipologia” causato dall’helper mancante per il conteggio record già importati.
- Versione plugin aggiornata a `2.27.1`.

## 2.27.0 — GetYourGuide CSV modal import progressivo
- Migliorato il flusso `gyg_csv` con modale admin sul pulsante “Importa questa tipologia”: riepilogo source, Partner ID, UTM medium, record totali/già importati/ancora da importare e anteprima sintetica limitata.
- Aggiunta selezione multipla obbligatoria delle Tipologie Link Sothra, con mapping persistente per Tipologia attività CSV e colonna “Mapping Sothra” aggiornata con tutti i nomi salvati.
- Rimossa dalla UI source `gyg_csv` la configurazione batch size: la quantità si sceglie solo nel modale, default 100, minimo 1 e massimo 1000 con clamp JavaScript e limite server-side.
- Implementato import progressivo via AJAX in sotto-batch da 100 record, progress bar, blocco doppi click, report finale con importati/aggiornati/già presenti/saltati/errori/URL non validi/record senza città o regione/durata e log errori leggibile.
- Mantenuta la deduplica `source_id + external_id`; default “Importa solo nuovi record” con opzione per aggiornare anche record già importati usando solo i dati importabili dalla source.
- Aggiornata la documentazione operativa e confermata la compatibilità con provider diversi da `gyg_csv`.
- Versione plugin aggiornata a `2.27.0`.

## 2.26.0 — GetYourGuide CSV / Deep Link importer
- Aggiunta source preset `gyg_csv` visualizzata come **GetYourGuide CSV / Deep Link**, separata dalla Partner API ufficiale e senza chiamate esterne, scraping, OpenAI, booking o checkout.
- Nuova configurazione source: Nome source, Partner ID, UTM medium (default `online_publisher`), batch size con limite assoluto 500 e mapping riusabile Tipologia attività CSV → Tipologia Link Sothra.
- Nuovo wizard admin in **Affiliate Sources → Importa contenuti** per upload CSV, rilevamento colonne, riepilogo tipologie, mapping, anteprima filtrata e import selettivo batch.
- Formato CSV riconosciuto: colonne obbligatorie `URL`, `Tipologia attività`, `Descrizione attività`; opzionali `Città` e `Regione di appartenenza`. Sono supportate varianti senza accento e con maiuscole/minuscole diverse, ad esempio `url`, `Citta`, `Regione`, `Tipologia attivita`, `Descrizione attivita`.
- Generazione deep link: aggiunge `partner_id` dalla source e `utm_medium` solo se assenti, conserva query string esistenti, slug, lingua e dominio originale. Un URL `.com` resta `.com` e un URL `.it` resta `.it`; il link originale è salvato separatamente come meta tecnico.
- Import nel CPT esistente `affiliate_link` con deduplica `source_id + external_id`, dove `external_id` è hash stabile della URL originale normalizzata. I reimport aggiornano URL affiliato e meta tecnici senza creare duplicati.
- Meta tecnici salvati: URL originale, URL affiliato, città, regione, tipologia CSV originale, descrizione, provider/source `gyg_csv`, source ID, external ID e seed contesto AI; nessuna pubblicazione frontend automatica oltre al CPT già esistente.
- Report batch con importati, aggiornati, già presenti, saltati, errori, URL non validi, record senza città/regione e durata.
- Test manuali consigliati: caricare CSV valido; verificare errori per assenza di URL/Tipologia/Descrizione; rilevare Città/Regione; mappare una tipologia; filtrare anteprima; importare meno di 1000 record; provare quantità oltre 1000; verificare URL con/senza query, mancata duplicazione di `partner_id`/`utm_medium`, dominio `.com`/`.it` preservato, deduplica, tassonomia, meta e report finale.
- Versione plugin aggiornata a `2.26.0`.

## 2.25.51 — Harden GetYourGuide API provider configuration
- Rifinito il provider ufficiale `getyourguide` come sola source API `GetYourGuide API`, con descrizione allineata alla Partner API ufficiale e senza modalità manual, deeplink, CSV, scraping o fallback Travelpayouts.
- Aggiunto il campo guidato `timeout` per GetYourGuide e rafforzato il clamp server-side di `limit` e `timeout`; il token `access_token` resta preservato se lasciato vuoto e mostrato solo come stato configurato/non configurato.
- Bloccato l'import GetYourGuide se manca un URL prodotto/affiliato restituito dalla API, evitando home generiche o URL inventati e mantenendo la preview come fase selettiva senza sideload.
- Ridotto il metadata JSON salvato per GetYourGuide a un riepilogo sicuro, mantenendo i meta `_alma_gyg_*`, immagini candidate, sideload in import, deduplica e compatibilità AI Content Agent.
- Confermati limiti: nessun manual/deeplink/CSV, scraping, booking, cart, checkout, availability detail o chiamate extra fuori `GET /1/tours`.
- Versione plugin aggiornata a `2.25.51`.

## 2.25.50 — Add GetYourGuide affiliate source provider
- Aggiunto provider ufficiale GetYourGuide nel modulo Affiliate Source Manager, con configurazione guidata per Access token GetYourGuide, lingua contenuti, valuta, query predefinita, limite risultati e ordinamento opzionale.
- Aggiunto client Partner API GetYourGuide per `GET /1/tours` con header `X-ACCESS-TOKEN`, `Accept: application/json`, parametri `cnt_language`, `currency`, `q`, `limit` e `offset`, gestione errori HTTP/WP_Error/JSON non valido e nessun logging del token.
- Integrata anteprima import GetYourGuide con checkbox selettiva, miniatura quando disponibile, titolo, URL prodotto/affiliato diretto, prezzo, valuta, rating, external ID, stato duplicato e warning per URL o immagini mancanti.
- Aggiunti normalizzazione item, mapping metadati `_alma_gyg_*`, deduplicazione provider/source external ID, sincronizzazione `_affiliate_url` e `_alma_affiliate_url` e import selettivo nel CPT `affiliate_link`.
- Aggiunto resolver media GetYourGuide per estrarre una immagine candidata dal payload senza download in preview; durante l’import viene riusato il servizio esistente di sideload, con featured image e meta media affiliato.
- Aggiunto catalogo campi GetYourGuide e contesto AI prudente per AI Content Agent, includendo provider, descrizione sintetica, prezzo/rating/durata e immagine quando disponibili, con nota di non copiare testo provider e di non inventare disponibilità.
- Confermati limiti: nessun booking, cart, checkout, scraping, ingest catalogo completo, availability o price-breakdown GetYourGuide in questa release.
- Versione plugin aggiornata a `2.25.50`.

## 2.25.49 — Fix dashboard pending counts and media QA consistency
- Corretto il conteggio pending della Dashboard per i Link affiliati: `non_active_candidate_records` contribuisce agli aggiornamenti disponibili, abilita la CTA di sync incrementale ed è mostrato come link candidabili da sincronizzare.
- Corretto il confronto timezone dei pending Link interni: `post_modified_gmt` viene confrontato con il nuovo `indexed_at_gmt`, salvato in GMT, mantenendo `indexed_at` locale per compatibilità/UI.
- Corretto il confronto timezone dei pending Media Library: `post_modified_gmt` viene confrontato con il nuovo `indexed_at_gmt`, salvato in GMT, mantenendo `indexed_at` locale per compatibilità/UI.
- Aggiunta migrazione schema difensiva per `indexed_at_gmt` sugli indici Link interni e Media, con `SHOW COLUMNS` e `ALTER TABLE` idempotente se la colonna manca.
- Riallineato `media_used` all’HTML finale dopo la rimozione di immagini editoriali oltre limite, così un `<figure>` condiviso rimosso non lascia immagini non più presenti nei metadati.
- Nessuna modifica al payload OpenAI, alla generazione contenuti, allo scoring media/link interni, agli import Viator/GetYourGuide o all’applicazione automatica featured image.
- Versione plugin aggiornata a `2.25.49`.

## 2.25.48 — Fix AI media payload QA regressions
- Corretta la validazione legacy di `featured_image_id`: quando `featured_image_candidates` è assente o vuoto resta valido il fallback su `candidate_image_ids` attachment.
- Salvata nei meta della bozza selection-session la featured image scelta e validata da OpenAI con ID, URL risolto e source quando disponibili, senza applicare automaticamente `set_post_thumbnail`.
- Applicato il limite massimo di immagini editoriali anche al contenuto HTML finale, rimuovendo le immagini editoriali eccedenti e mantenendo il default `max_editorial_media_used=5`.
- Preservate le immagini affiliate valide provenienti da `affiliate_links[].image`: non contano nel limite editoriale e non vengono rimosse perché assenti da `media_candidates`.
- Allineato `media_used` al contenuto finale: contiene solo immagini editoriali candidate rimaste nel contenuto, senza duplicati, senza immagini rimosse e senza immagini affiliate.
- Versione plugin aggiornata a `2.25.48`.

## 2.25.47 — Dashboard index update alerts
- Aggiunti alert operativi in alto nella Dashboard AI Content Agent per Link affiliati, Link interni e Media Library.
- Gli alert mostrano stato, conteggi nuovi/modificati/obsoleti e totale indicizzato, con CTA per aggiornare solo i pendenti.
- Aggiunti conteggi pending e sync incrementale per Link interni, confrontando post pubblicati con l’indice senza indicizzare il contenuto completo.
- Aggiunti conteggi pending e sync incrementale per Media Library su attachment immagine, senza OCR, download o lettura file binari.
- Riusata la sync incrementale esistente dei Link affiliati nella nuova UI alert.
- Le ricostruzioni complete e reset/svuota indice restano disponibili nella sezione Manutenzione avanzata.
- Versione plugin aggiornata a `2.25.47`.

## 2.25.46 — AI Content Agent dashboard UI operativa
- Riorganizzata la dashboard **AI Content Agent** con quick actions in alto per Idee contenuto, creazione idea, Istruzioni AI e Stato/log.
- Aggiunte card riepilogative per OpenAI, Idee contenuto, Link affiliati, Link interni, Media Library e Stato/log.
- Raggruppati gli indici nella sezione **Stato degli indici** con dati essenziali, progress bar accessibile e testi operativi più chiari.
- Spostati dettagli tecnici e azioni di reset/sync/ricostruzione nella sezione collassabile **Manutenzione avanzata**.
- Rimossi dalla vista principale i pulsanti vecchi/duplicati “Reindicizza fonti” e “Reindicizza media”, mantenendo handler, nonce e capability esistenti.
- Nessuna modifica ai flussi AI, ai payload OpenAI, agli indexer o alle tabelle DB.
- Versione plugin aggiornata a `2.25.46`.

## 2.25.45
- Aggiunti `featured_image_candidates` e `media_candidates` editoriali nel payload OpenAI, alimentati dall’indice Media Library senza inviare file, base64 o dati binari.
- Escluse le immagini affiliate dai candidati media editoriali (`is_editorial_candidate=1` e `is_affiliate_media=0`); le immagini affiliate restano gestite separatamente tramite `affiliate_links[].image`.
- Aggiunto `featured_image_id` al contratto di output e regole dedicate per scegliere solo immagini presenti nel payload.
- Impostato a 5 il limite massimo di immagini editoriali nel corpo articolo, con filtro `alma_ai_max_editorial_media_used` e rispetto di eventuali limiti profilo più bassi.
- Aggiunta validazione QA minima per azzerare `featured_image_id` invalido, rimuovere `media_used` non candidato e tagliare i media editoriali al limite.
- Nessuna generazione immagini AI e nessuna applicazione automatica featured image editoriale: l’applicazione finale è rimandata a una PR successiva.

## 2.25.44 — Fix media index schema migration
- Allineato lo schema dichiarato di `alma_ai_media_index` alle colonne usate da insert/update, mantenendo `post_status` e tutte le colonne tecniche dell’indice media.
- Aggiunta migrazione difensiva `ensure_schema()` per verificare le colonne esistenti con `SHOW COLUMNS` e aggiungere in sicurezza quelle mancanti con `ALTER TABLE`, senza affidarsi solo a `dbDelta()`.
- Il rebuild dell’Indice Media aggiorna/verifica lo schema prima di processare gli attachment e si interrompe con errore admin chiaro se la tabella resta incompleta, evitando migliaia di insert/update destinati a fallire.
- Aggiunta diagnostica sintetica quando vengono aggiunte colonne mancanti allo schema indice media.
- Versione plugin aggiornata a `2.25.44`.

## 2.25.43 — Fix media index image detection
- Corretta la ricostruzione dell’Indice Media: gli attachment vengono letti con stati WordPress compatibili, incluso `inherit`, e il riconoscimento immagini accetta tutti i MIME che iniziano con `image/`.
- Il rebuild carica sempre il post completo prima di indicizzare, non scarta immagini con alt/caption/description vuoti, parent assente, metadata dimensioni mancanti o URL large/medium non disponibili, e salta solo immagini senza URL full.
- Aggiunta diagnostica admin dettagliata con attachment processati, immagini rilevate, immagini indicizzate, non immagini saltati, attachment senza URL, errori insert/update e record rimossi; gli errori DB mostrano un warning sintetico basato su `wpdb->last_error`.
- Rafforzata la verifica tabella: il rebuild tenta `install_table()` e restituisce un errore chiaro se la tabella media index non è disponibile.
- Versione plugin aggiornata a `2.25.43`.

## 2.25.42 — AI Media Library index and affiliate media separation
- Aggiunto indice leggero della Media Library per AI Content Agent nella tabella `alma_ai_media_index`, creato via dbDelta su attivazione/upgrade.
- Indicizzati attachment immagine con title, alt, caption, description, filename, URL full/large/medium, dimensioni, mime type, parent post e testo aggregato di ricerca.
- Aggiunta distinzione esplicita tra media editoriale e media affiliato: le immagini affiliate hanno `is_affiliate_media=1` e `is_editorial_candidate=0`, così non diventano candidate editoriali future.
- Aggiunti meta espliciti al sideload immagini affiliate: `_alma_media_origin=affiliate_source`, `_alma_media_role=affiliate_featured_image`, `_alma_related_post_id` e `_alma_related_post_type=affiliate_link`, mantenendo i meta storici esistenti.
- Aggiunta compatibilità retroattiva nella ricostruzione indice per riconoscere immagini affiliate già importate tramite provider, hash remoto, external ID o post affiliato collegato.
- Aggiunto box admin **Indice Media** con conteggi, ultima ricostruzione e pulsante con nonce/capability per ricostruzione sincrona paginata.
- Confermati limiti PR: nessun invio immagini a OpenAI, nessuna generazione immagini, nessun OCR/embeddings e nessun inserimento automatico immagini nella bozza.
- Versione plugin aggiornata a `2.25.42`.

## 2.25.41 — AI taxonomy assignment and internal link QA fixes
- Allineata la versione plugin tra header WordPress `Version:` e costante `ALMA_VERSION`, aggiornando la documentazione alla stessa release.
- Corretto il QA dei link interni relativi: gli URL same-site che iniziano con `/` vengono normalizzati con `home_url()`, confrontati con `internal_links`, convertiti all’URL assoluto autorizzato e registrati in `internal_urls_used`; i relativi non autorizzati vengono de-linkati lasciando il testo.
- Esclusi dal riconoscimento interno gli URL protocol-relative, `mailto:`, `tel:`, `javascript:` e ancore pure.
- Aggiunta separazione HTML sicura dopo immagini affiliate cliccabili quando il testo prosegue immediatamente dopo `</a>`, preservando link, immagini e markup valido.
- Aggiunta penalizzazione filtrabile dei link interni stagionali/datati per contenuti evergreen, con filtri `alma_ai_internal_link_time_sensitive_terms` e `alma_ai_internal_link_time_sensitive_penalty`.
- Aggiunti `category_candidates` e `tag_candidates` compatti al payload OpenAI, derivati solo da tassonomie WordPress esistenti e da match coerenti con prompt, destinazione, link interni e affiliati.
- Aggiunte `taxonomy_rules` e i campi obbligatori `category_ids`, `tag_ids`, `new_tags` al contratto/output requirements.
- Aggiunto QA locale per accettare solo categorie candidate esistenti, validare tag esistenti candidati, bloccare tag generici, limitare i nuovi tag e riusare tag esistenti quando coincidono.
- Salvate categorie e tag validati sulla bozza con meta diagnostici `_alma_ai_category_ids`, `_alma_ai_tag_ids`, `_alma_ai_new_tags` e `_alma_ai_taxonomy_warnings`; le categorie restano solo esistenti e i nuovi tag vengono creati solo dopo QA e capability adeguata.
- Aggiornato il riepilogo admin con candidati/applicazioni tassonomie e warning sintetici.
- Versione plugin aggiornata a `2.25.41`.

## 2.25.40 — AI Content Agent internal link relevance
- La selezione dei link interni ora combina i tre campi delle Idee contenuto: `content_search_query` / Cerca contenuti, Titolo idea e `openai_prompt` / Prompt per OpenAI.
- Aggiunta distinzione tra termini forti e termini deboli/generici, con stoplist travel filtrabile tramite `alma_ai_internal_link_stop_terms`.
- Aggiunta soglia minima di pertinenza filtrabile tramite `alma_ai_internal_link_min_score`: i candidati sotto soglia non entrano in `internal_links` e non viene più forzato il riempimento fino a 8 risultati.
- Aggiunti termini correlati geografici filtrabili tramite `alma_ai_internal_link_related_terms`, con piccola mappa iniziale `lecce => salento, puglia, otranto, gallipoli, galatina, leuca`.
- Rafforzato lo scoring: match forti in titolo/slug/categorie/tag/excerpt pesano più della recenza, i termini deboli non bastano da soli e i candidati includono `score`, `matched_terms` e `reason`.
- Migliorata la diagnostica debug con `internal_link_debug`/`internal_link_diagnostics` contenente `raw_terms`, `strong_terms`, `weak_terms`, `related_terms`, candidati trovati/passati e soglia minima.
- Ridotto il rischio di link interni fuori contesto, preferendo `internal_links: []` quando non esistono candidati pertinenti alla destinazione richiesta.
- Versione plugin aggiornata a `2.25.40`.

## 2.25.39 — AI Content Agent internal linking MVP
- Aggiunto MVP internal linking per AI Content Agent: indice leggero `alma_ai_internal_link_index` dei post pubblicati con titolo, permalink assoluto, slug, excerpt, categorie, tag e date, senza indicizzare il contenuto completo e senza embeddings.
- Aggiunto selettore deterministico di candidati link interni (massimo 8) basato su match in titolo, destinazione/keyword, slug, categorie/tag, excerpt e recenza, senza chiamate OpenAI.
- Aggiunti `internal_links` e `internal_link_rules` al payload OpenAI compatto, più il campo obbligatorio `internal_urls_used` separato da `affiliate_urls_used` e `affiliate_shortcodes_used`.
- Aggiunto QA locale per consentire solo URL interni presenti in `internal_links`, de-linkare wrapper `<a>` inventati lasciando il testo, rimuovere URL interni visibili e aggiornare `internal_urls_used` con warning diagnostici.
- Aggiunta card admin “Link interni” con stato indice, conteggio post indicizzati, ultima ricostruzione e pulsante con nonce/capability per ricostruzione manuale sincrona; aggiunti hook leggeri `save_post`/trash/delete per aggiornamento singolo.
- Aggiornati JSON payload/debug per includere candidati interni, regole, campo richiesto `internal_urls_used` e diagnostica selector nel debug completo.
## 2.25.38 — AI draft QA affiliate hardening
- Rafforzate le regole operative inviate a OpenAI: vietate disclosure affiliate generiche, tag `<a>` senza `href`, shortcode usati come `href` e richiesto allineamento tra `affiliate_urls_used`, shortcode e URL diretti realmente inseriti.
- Aggiunta rimozione automatica dal solo campo `content` delle disclosure affiliate generiche generate dall’AI, senza inserire disclosure sostitutive.
- Aggiunta correzione locale degli `href` mancanti per immagini affiliate quando il `src` corrisponde a un’immagine del payload, preservando `<img>`, classi, URL e parametri di tracking.
- Aggiunta correzione dei link testuali affiliati senza `href` quando il testo corrisponde in modo affidabile al titolo di un link affiliato del payload; i link non associabili vengono trasformati in testo semplice.
- Reso coerente `affiliate_urls_used` con gli URL affiliati diretti effettivamente presenti negli `href` finali, mantenendo separati gli shortcode in `affiliate_shortcodes_used`.
- Reso coerente `media_used` con le immagini affiliate cliccabili realmente presenti nel contenuto finale, includendo `image_url`, ID link affiliato, `affiliate_url`, alt e source quando disponibili.
- Rafforzata la sanitizzazione HTML finale per preservare in modo sicuro `href`, `target`, `rel`, attributi immagine consentiti e `figure class`, senza consentire attributi pericolosi o URL JavaScript/data non necessari.
- Versione plugin aggiornata a `2.25.38`.

## 2.25.37 — AI profile textarea preservation
- Corretta la preservazione dei contenuti textarea nei profili **Istruzioni AI**, salvando testo libero admin non escaped e normalizzando solo line ending e caratteri di controllo non validi.
- Preservati nei prompt esempi HTML testuali con `<a>`, `<img>`, placeholder come `{affiliate_url}` e `{image.image_url}`, simboli, virgolette e caratteri accentati.
- Risolto l'escape multiplo di virgolette/backslash: il JSON OpenAI usa solo l'escaping naturale di `wp_json_encode`, senza slash già salvati nello storage.
- Rimossa la normalizzazione semantica aggressiva delle regole: la conversione textarea → array mantiene righe non vuote così come scritte, salvo trim esterno e deduplica conservativa.
- Migliorata la propagazione di `image_rules` in `media_rules` nel payload OpenAI, insieme alle regole media hardcoded esistenti.
- Test manuale round-trip consigliato: salvare `affiliate_rules` e `image_rules` con HTML di esempio, ricaricare il profilo, scaricare il JSON debug e verificare `openai_payload_normalized` valido senza escape multipli o righe rimosse.
- Versione plugin aggiornata a `2.25.37`.

## 2.25.36 — AI payload profile and affiliate image consistency
- Aggiunto nel payload OpenAI normalizzato il blocco `instruction_profile` compatto (`id`, `name`, `snapshot_hash`) riferito solo al profilo istruzioni selezionato dall’utente.
- Garantito che il payload OpenAI non includa liste di profili attivi e non scelga un profilo diverso da quello associato alla sessione/idea.
- Propagate immagini affiliate coerenti in `selection_context`, sessione selezione e `affiliate_links`, usando featured image WordPress reale con fallback a `_alma_featured_image_url` solo se URL assoluto valido.
- Aggiunte `media_rules` al payload OpenAI compatto senza duplicarle inutilmente nelle istruzioni editoriali.
- Migliorata la diagnostica debug per immagini mancanti dei link affiliati con `image_debug`, utile anche per verificare link Viator senza featured image/meta URL/import status valido.
- Confermata l’assenza di download remoto, sideload, gallerie multiple, traveler photos, video o generazione immagini AI durante la generazione bozza.
- Versione plugin aggiornata a `2.25.36`.

## 2.25.35 — AI search result affiliate image thumbnails
- Aggiunta la miniatura dell'immagine affiliata nei **Risultati ricerca** dell'AI Content Agent, allineata a destra del record senza modificare i pulsanti di selezione/aggiunta.
- La miniatura usa la stessa risoluzione strutturata del payload AI: featured image WordPress reale del CPT `affiliate_link`, fallback a `_alma_featured_image_url` solo se URL assoluto valido, nessun placeholder pesante quando manca l'immagine.
- Rafforzato il payload OpenAI compatto affinché `image.image_url` sia sempre un URL assoluto valido quando disponibile e mai un attachment ID o path relativo.
- Confermati limiti PR: nessun download remoto, nessun sideload, nessuna creazione attachment, nessuna galleria multipla e nessuna generazione immagini AI.
- Versione plugin aggiornata a `2.25.35`.

## 2.25.34 — AI profiles multi-activation and affiliate images in drafts
- Corretta l'attivazione multipla dei profili **Istruzioni AI**: ogni profilo mantiene il proprio `is_active` e l'attivazione/disattivazione dalla lista modifica solo il profilo scelto.
- Aggiornato il pulsante lista profili con label contestuale **Attiva/Disattiva**, badge **Attivo/Non attivo**, nonce dedicato, capability admin e notice chiara dopo redirect.
- Nei flussi AI Content Agent il select **Profilo istruzioni AI** propone i profili attivi, preserva esplicitamente profili già salvati su vecchie idee/brief e mostra un messaggio quando non ci sono profili attivi.
- Integrate immagini affiliate nel payload AI compatto per ogni link selezionato: preferenza per featured image WordPress reale, fallback a `_alma_featured_image_url`, alt/caption/source/status e flag `can_use_in_content` senza gallerie.
- Aggiornato il prompt interno Draft Builder per usare immagini affiliate solo se pertinenti, solo dal payload, senza inventare URL, senza duplicazioni e mantenendo disclosure affiliata.
- Il QA locale della bozza accetta solo immagini candidate, rimuove immagini non autorizzate/placeholder, segnala duplicazioni e salva un riepilogo immagini candidate/usate/scartate nel risultato admin.
- Limiti confermati: nessuna generazione immagini, nessun video, nessuna traveler photo, nessuna galleria multipla e nessun download immagini durante la generazione bozza.
- Versione plugin aggiornata a `2.25.34`.

## 2.25.33 — Affiliate image admin diagnostics and retry tools
- Aggiunta sezione diagnostica **Immagine affiliata** nella metabox tecnica del CPT `affiliate_link`, con stato leggibile, URL sorgente, attachment ID, ultimo tentativo, errore sintetico, hash, anteprima e link alla Media Library.
- Aggiunto retry manuale **Riprova import immagine** con nonce e capability `edit_post`, più opzione locale **Sovrascrivi l’immagine in evidenza esistente**; il default non sovrascrive featured image già presenti.
- Aggiunta azione bulk **Importa immagini mancanti/fallite** nella pagina Affiliate Sources per una source specifica, con limite batch massimo 30 e default 20, riepilogo processati/importate/riutilizzate/saltate/fallite.
- Aggiunta colonna admin compatta **Immagine** nella lista dei Link affiliati, con miniatura quando presente e badge Importata/Riutilizzata/Mancante/Errore senza download remoto in listing.
- Il riepilogo import mantiene label utente in italiano per immagini importate, riutilizzate, non importate e warning immagini.
- Restano fuori scope video, traveler photos, gallerie multiple, cron, background queue e job asincroni.
- Versione plugin aggiornata a `2.25.33`.

## 2.25.32 — Remote media sideload and featured image assignment
- Aggiunto sideload non bloccante di una singola immagine remota prodotta dai provider durante l'import effettivo, con download in Media Library tramite API WordPress native.
- Aggiunta associazione automatica dell'attachment come **Immagine in evidenza** del CPT `affiliate_link`, senza sovrascrivere featured image esistenti di default.
- Aggiunta deduplicazione attachment tramite hash stabile dell'URL normalizzato e meta `_alma_remote_image_hash`, evitando duplicati al reimport dello stesso prodotto.
- Aggiunti meta diagnostici sul Link affiliato per stato import immagine, attachment, errore, timestamp, URL sorgente e hash; aggiunti meta di tracciabilità sugli attachment importati.
- Il fallimento di validazione/download/attachment immagine resta non bloccante: il Link affiliato viene comunque creato o aggiornato e il riepilogo import mostra un warning compatto.
- Caso primario testato: immagini Viator candidate da `_alma_featured_image_url`; il servizio resta generico per altri provider che producono `featured_image_url`.
- Video, traveler photos, gallerie multiple e download di più immagini per Link affiliato non sono ancora implementati.
- Versione plugin aggiornata a `2.25.32`.

## 2.25.31 — Viator media extraction and import preview
- Aggiunta estrazione difensiva delle immagini Viator da `images[]`/`variants[]` con selezione di un URL candidato principale senza download o creazione attachment.
- Aggiunta colonna **Immagine** nella preview import Viator con miniatura, badge Cover/Supplier e warning non bloccanti quando l'immagine manca o non ha URL valido.
- Salvati nel CPT `affiliate_link` URL candidato e metadati media sicuri (`_alma_featured_image_url`, fonte, caption, dimensioni, flag cover, conteggi e JSON media normalizzato).
- Aggiornato il fallback dell'indice AI per usare `_alma_featured_image_url` quando non esiste una featured image WordPress.
- Sideload in Media Library, `media_handle_sideload`, `download_url`, `set_post_thumbnail` e `_thumbnail_id` non sono implementati in questa PR e restano per una PR successiva.
- Versione plugin aggiornata a `2.25.31`.

## 2.25.30 — PR 8.30 AI Content Agent Dashboard Shortcut Widget
- Aggiunto nella Bacheca WordPress il widget leggero **AI Content Agent**, registrato con `wp_dashboard_setup` in contesto principale ad alta priorità per apparire nella fascia alta al primo caricamento.
- Il widget mostra una descrizione sintetica, icona Dashicons e pulsante primario **Apri AI Content Agent** verso l'URL admin già registrato (`alma-ai-content-agent`).
- La scorciatoia è visibile solo agli utenti autorizzati con la stessa capability della pagina AI Content Agent (`manage_options`).
- Nessun impatto su OpenAI, payload AI, CPT, shortcode, tracking, import provider o logica AI; il widget espone solo link di navigazione e testo statico.
- Versione plugin aggiornata a `2.25.30`.

## 2.25.29 — PR 8.29 AI Content Agent Toolbar and Ideas Pagination UI
- Migliorata la toolbar principale di **AI Content Agent** con pulsanti scoped più distinguibili: azioni primarie blu, salvataggio verde, eliminazione rossa con icona trash e download JSON OpenAI grigio/secondario.
- Aggiunta paginazione server-side alla colonna **Idee create** con massimo 10 idee per pagina, controlli **Precedente**/**Successiva**, indicatore pagina corrente e mantenimento dell’idea attiva.
- Evidenziata l’idea attiva nella lista con bordo verde, sfondo leggero e badge **Idea attiva**, preservando form, link, nonce e azioni esistenti.
- Confermati gli **Strumenti avanzati** per il download **Scarica JSON debug completo (solo diagnostica, non inviato a OpenAI)**, visibile solo agli admin con `manage_options` e stile secondario.
- Versione plugin aggiornata a `2.25.29`.

## 2.25.28 — PR 8.28 Affiliate Context Cleanup and AI Instructions Profile UI
- Rafforzata la pulizia finale di `affiliate_links[].context` nel payload OpenAI normalizzato per rimuovere residui Viator/legacy come fonte/provider, codice prodotto, URL affiliato diagnostico, destination/tag ID, `Durata: Array` e placeholder tecnici.
- Migliorata la UI di **Istruzioni AI → Modifica profilo**: Nome profilo e Lingua restano in alto, i campi principali sono organizzati in griglia responsive a due colonne, il Prompt libero personalizzato è ampio e a tutta larghezza e le Note interne restano secondarie.
- Corretta la checkbox **Attiva questo profilo dopo il salvataggio** per riflettere lo stato `is_active` del profilo in modifica, mantenendo la logica esistente di attivazione.
- Versione plugin aggiornata a `2.25.28`.

## 2.25.27 — PR 8.27 Advanced Debug Download and Rule Sentence Normalization
- Spostato il download **Scarica JSON debug completo** negli **Strumenti avanzati**, con label diagnostica esplicita e visibilità limitata agli admin con capability adeguata.
- Rifinita la normalizzazione di `affiliate_rules`, `seo_rules` e `source_policies` per preservare frasi/listati naturali, completare frammenti pendenti e pulire virgolette/caratteri speciali.
- Il payload OpenAI normalizzato mantiene la struttura pubblica compatta e resta privo di diagnostica; il debug completo continua a includere `debug_payload_full` e `openai_payload_normalized` per confronto.
- Versione plugin aggiornata a `2.25.27`.

## 2.25.26 — PR 8.26 Refine OpenAI Payload Rules and Viator Duration
- Rifinito il payload OpenAI normalizzato del flusso `create_article_draft_from_selected_sources` senza modificare la struttura pubblica né reintrodurre campi diagnostici.
- Omessa la durata dal contesto sintetico Viator quando il valore non è scalare o non è leggibile, evitando output tecnici come `Durata: Array`.
- Aggiornata la normalizzazione di `affiliate_rules`, `seo_rules` e `source_policies` per deduplicare regole complete senza troncamenti con puntini di sospensione.
- Versione plugin aggiornata a `2.25.26`.

## 2.25.25 — PR 8.25 Separate OpenAI Payload Download from Full Debug JSON
- Corretto il download **Scarica JSON payload OpenAI**: ora esporta il payload compatto prodotto da `normalize_payload_for_openai()`, non il payload diagnostico completo.
- Aggiunto il download separato **Scarica JSON debug completo**, con wrapper esplicito `debug_payload_full` + `openai_payload_normalized` per confrontare diagnostica interna e payload realmente inviato al modello.
- Confermata esclusione dal payload OpenAI normalizzato di `content_search_query`, `theme`, `destination`, `selected_results_count`, `selection_context`, score/reason/provider/source/provenance dei risultati sorgente, Source prompt, snapshot istruzioni, `internal_notes`, autori e timestamp amministrativi.
- Rafforzata la sintesi del contesto Viator escludendo anche righe tecniche provider/source dal contesto breve dei link affiliati.
- Versione plugin aggiornata a `2.25.25`.

## 2.25.24 — PR 8.24 Compact OpenAI Draft Payload and JSON Diagnostics
- Normalizzato il payload inviato a OpenAI nel flusso `create_article_draft_from_selected_sources`: dati editoriali, profilo, regole e link affiliati vengono inviati in sezioni compatte dedicate, senza campi diagnostici o duplicazioni del prompt.
- Separati payload completo diagnostico/download e payload effettivamente inviato a OpenAI, preservando i dati tecnici per debug interno senza esporli al modello.
- Ripuliti i link affiliati nel payload AI: mantenuti solo ID, titolo, descrizione sintetica, URL affiliato, shortcode, tipologie e contesto breve; rimossi score, reason, provider/source/provenance, Source prompt e note interne.
- Aggiunta sintesi prudente del contesto Viator per evitare blocchi tecnici/provider-specific e istruzioni operative lunghe nel prompt AI.
- Reso `slug` obbligatorio nel contract OpenAI con fallback locale sanificato dal titolo e warning non bloccante se mancante o non valido.
- Migliorata la diagnostica JSON admin distinguendo risposta vuota, probabile troncamento, JSON non parsabile/testo fuori oggetto, campi obbligatori mancanti e contenuto troppo corto.

## 2.25.23 — PR 8.23 Enforce Valid OpenAI Draft JSON and Improve API Error Feedback
- Verificato e aggiornato il wrapper OpenAI su endpoint `POST /v1/responses` con gestione strutturata di `response_format`, `max_output_tokens`, `timeout` e codifica errori API.
- Implementata richiesta output JSON tecnica per `content_draft_generation` con schema contract e fallback automatico a JSON object mode se `response_format` non supportato.
- Aggiornato prompt finale Draft Builder: risposta solo JSON object, niente Markdown/code-fence, campi contract obbligatori e array vuoti quando non usati.
- Parser risposta AI reso robusto (decode diretto, strip code-fence, estrazione primo JSON bilanciato) senza retry automatici e senza fatal.
- Validazione output contract rafforzata con blocco draft su `title/content` vuoti e normalizzazione difensiva dei campi array/stringa non critici.
- Logging e feedback admin migliorati con distinzione errori API vs JSON (error category/code, `json_last_error_msg`, lunghezza risposta, preview sanitizzata, campi mancanti, uso `response_format`).
- Confermato uso effettivo di `max_output_tokens` configurato nel task draft generation; nessuna modifica a indice affiliate, ricerca affiliate, batch/sync, provider/importer, shortcode/tracking.

## 2.25.22 — PR 8.22 Refine AI Draft Payload Affiliate URL Policy and Source Instructions
- Rifinita la policy payload per link affiliati nel Draft Builder: shortcode WordPress preferiti, `affiliate_url` consentito per link testuali diretti, divieto di URL inventati e uso esclusivo dei link presenti nel payload.
- Aggiunta sezione globale `source_agent_prompts` con prompt Source non vuoti, deduplicati e collegati ai `link_ids`, per ridurre duplicazioni nei singoli `affiliate_links`.
- Warnings aggiornati con messaggi più precisi su comportamento agente globale e disponibilità istruzioni Source, inclusa nota sintetica per link manuali/legacy senza prompt dedicato.
- Gestione esplicita non bloccante dei link manuali/legacy senza Source prompt (link mantenuti utilizzabili nel payload).
- Nessuna modifica a OpenAI Service, endpoint/chiamata modello, indice Link affiliati, ricerca affiliate, batch/sync, provider/importer, shortcode rendering o tracking.

## 2.25.21 — PR 8.21 Safe AI Payload JSON Builder and Download Hardening
- `ALMA_AI_Content_Agent_Selection_Session::normalize_result()` ora preserva nei risultati di sessione i metadati affiliate utili alla UI: `link_types`, `provenance`, `provider`, `source`.
- `link_types` viene normalizzato in forma stabile (array di stringhe sanificate), con supporto input array/CSV/stringa singola, rimozione vuoti e deduplica.
- I filtri **Tipologie Link** e **Fonte / Source / Provider** nella card **2. Risultati ricerca** si popolano correttamente dopo una nuova ricerca affiliate.
- Nessuna modifica a scoring, indice affiliate (batch/sync), OpenAI Service, Draft Builder o provider/importer.

## 2.25.18 — PR 8.18 Affiliate Results Filters and Stable Idea Title
- Aggiunti filtri UI nella card **2. Risultati ricerca** per **Tipologie Link** (`alma_link_type_filter`) e **Fonte / Source / Provider** (`alma_source_filter`), costruiti dinamicamente dai risultati presenti in sessione.
- Filtri applicati lato rendering/sessione, prima della paginazione, senza rifare la ricerca e senza modificare `search_results` o `selected_results`.
- Introdotti i comandi **Applica filtri** e **Reset filtri** (GET), con reset non distruttivo che non cancella risultati/selezioni e non chiama OpenAI.
- Stato vuoto filtrato dedicato: “Nessun Link affiliato corrisponde ai filtri selezionati.”
- Payload risultati affiliate esteso con metadati stabili per UI (`link_types`, `provenance`/`provider`/`source`) da indice/fallback esistenti.
- Fix bug titolo idea: `content_search_query` aggiorna solo `last_query`; le nuove ricerche non sovrascrivono più il titolo dell’idea esistente.
- Nessuna modifica a indice affiliate (batch/sync/scoring), OpenAI Service, Draft Builder, provider/importer o shortcode/tracking.

## 2.25.16 — PR 8.16 Restrict Ideas Search to Affiliate Links
- La ricerca Idee usa ora uno scope esplicito `affiliate_links_only` e interroga solo `search_affiliate_links()`.
- Escluse temporaneamente dalla card “2. Risultati ricerca” le sorgenti Post/Pagine/TXT/Fonti online/Media e risultati Knowledge Base editoriali.
- Preservato fallback WordPress solo su CPT `affiliate_link` pubblicati con URL affiliato valido quando l’indice dedicato è vuoto/non disponibile.
- Nuova ricerca affiliate-only sostituisce i risultati precedenti in sessione per evitare residui legacy (es. Post) senza svuotare i contenuti già aggiunti alla colonna 3.
- Nessuna modifica a indice affiliate, batch/sync, scoring, OpenAI Service o Draft Builder.

## 2.25.15 — PR 8.15 Ideas Active Box UI and Affiliate Index Action Descriptions
- Migliorata la leggibilità del box **Idea attiva** nella tab Idee contenuto con sezioni distinte: riepilogo, lista idee create e dettagli idea.
- Ogni idea creata viene mostrata come record/card separato con titolo, ultima modifica, badge **Attiva** e pulsante **Carica** invariato lato azioni.
- Spostati i dettagli tecnici (contenuti aggiunti, profilo istruzioni AI, prompt OpenAI) in una sezione dedicata separata dalla lista idee.
- Nella card **Indice Link affiliati** aggiunte descrizioni brevi alle azioni operative e separazione visiva tra azioni principali e manutenzione avanzata.
- Aggiornati solo markup/copy/CSS admin scoped sotto `.alma-ai-agent-admin`; nessuna modifica funzionale a flussi, salvataggi, indice affiliate o OpenAI Service.

## 2.25.13 — PR 8.13 Ideas Instruction Profile Single Select Form Submission Fix
- Corretto il submit del select unico **Profilo Istruzioni AI** nella tab Idee: il form **Cerca contenuti** invia sempre `instruction_profile_id` (incluso `0` per **Nessun profilo**).
- Il form **Salva idea** mantiene il profilo tramite hidden `instruction_profile_id` sincronizzato con il select visibile (JS vanilla difensivo in `assets/admin.js`).
- Hardening backend nel flusso `search_knowledge_base`: se `instruction_profile_id` è assente o invalido, il profilo idea esistente viene preservato e non azzerato implicitamente.
- Nessuna modifica a indice Link affiliati, batch/sync affiliate o OpenAI Service.

## 2.25.12 — PR 8.12 Ideas UI Instruction Profile Cleanup and Clear Profile Fix
- Rimossa la duplicazione UI del campo **Profilo Istruzioni AI** nella tab Idee contenuto (resta un solo select visibile).
- Corretto il salvataggio esplicito di **Nessun profilo**: `instruction_profile_id=0` viene persistito sull'idea senza richiedere `clear_instruction_profile`.
- Le idee con `instruction_profile_id=0` non vengono più riassociate in modo silenzioso al profilo globale attivo al reload o nelle azioni successive della tab Idee.
- Nessuna modifica a indice Link affiliati e nessuna modifica a OpenAI Service.

## 2.25.11 — PR 8.11 Persist AI Instruction Profile on Content Ideas
- Persistenza del Profilo Istruzioni AI sull'Idea contenuto (`instruction_profile_id`/meta idea) durante creazione e salvataggio idea.
- Preservazione del profilo istruzioni durante azioni non correlate nella tab Idee contenuto (ricerca, aggiunta/rimozione risultati, persistenza sessione).
- Fallback sicuro quando il profilo associato non è più valido o non esiste (nessun warning/fatal; fallback controllato in UI).
- Nessuna modifica all'indice Link affiliati e nessuna modifica a OpenAI Service.

## 2.25.10 — PR 8.10 Affiliate Index Pending Query Consistency and Pre-Test Hardening
- Allineata in modo rigoroso la semantica pending tra Dashboard e `sync_incremental()`: i candidabili da lavorare restano `missing_index + stale_index_records + non_active_candidate_records`.
- Hardening SQL su `non_active_candidate_records`: i record non active includono ora in modo esplicito status diverso da `active`, vuoto (`''`) o `NULL` (inclusi valori anomali/non previsti).
- `sync_incremental()` aggiornato con categorie pending mutualmente esclusive e coerenti: mancanti, obsoleti solo se `active`, non active con status nullo/vuoto/anomalo.
- Migliorato messaggio admin post-sync: se un batch processa 0 record ma restano pending, non comunica “tutto aggiornato” e suggerisce verifica indice + nuovo sync/batch.
- Aggiunta nota operativa pre-test in README: primo avvio guidato a batch progressivi e uso di “Svuota indice e ricomincia” solo in caso di diagnostica incoerente.
- Nessuna modifica a ricerca/scoring, OpenAI, Draft Builder, provider/importer o batch completo cursor-based.

## 2.25.9 — PR 8.9 Affiliate Incremental Sync Covers Non-Active Candidates
- Allineata `sync_incremental()` alla semantica pending della Dashboard: ora include record mancanti, record obsoleti e candidabili non attivi.
- La query incrementale seleziona solo candidabili (`affiliate_link` pubblicati con URL affiliato valorizzato) con `DISTINCT`, `EXISTS`, `LIMIT` e condizione `i.status <> active`.
- La CTA/notice di **Sync incrementale** chiarisce che l’azione recupera mancanti, aggiorna obsoleti e riattiva candidabili non attivi.
- Coerenza garantita con `get_index_stats()` sulle categorie `missing_index`, `stale_index_records`, `non_active_candidate_records`.
- Nessuna modifica a batch cursor-based completo, ricerca/scoring, OpenAI, Draft Builder, provider/importer, shortcode o tracking.
## 2.25.7 — PR 8.7 Affiliate Index Progress Count Fix and Pending Work Semantics
- Fix doppio conteggio pending nella Dashboard: rimosso l'errore semantico `missing_index + needs_update` che duplicava i mancanti.
- Aggiunto conteggio `stale_index_records` per separare i Link affiliati mancanti dall'indice da quelli da aggiornare dopo modifica.
- `needs_update` mantenuto per backward compatibility come totale operativo (`missing_index + stale_index_records`).
- Barra progresso resa coerente: “Da lavorare totale” non può superare “Candidabili”; percentuale clampata tra 0 e 100.
- Stato operativo guidato, prossima azione consigliata e CTA primaria riallineati ai nuovi conteggi (batch prima, sync incrementale dopo).
- Nessuna modifica a ricerca/scoring/batch cursor-based, OpenAI, Draft Builder, provider/importer, shortcode o tracking.

## 2.25.6 — PR 8.6 Affiliate Index Guided Batch Sync UX and Progress Feedback
- Aggiunta barra progresso indicizzazione nella card “Indice Link affiliati” con percentuale, candidabili e pending calcolati dai dati già disponibili in `get_index_stats()`.
- Aggiunto stato operativo guidato e blocco “Prossima azione consigliata” con CTA primaria dinamica per primo batch, continuazione batch e sync incrementale.
- Separata la sezione “Manutenzione avanzata” per azioni tecniche (`reset_affiliate_index_state`, `clear_affiliate_index`) mantenendo warning non distruttivo.
- Migliorati i messaggi admin post-action per batch/sync/reset/clear con esito più esplicito e indicazioni operative successive.
- Nessuna modifica a schema DB, query cursor-based batch, ricerca/scoring, OpenAI, Draft Builder, provider/importer, shortcode o tracking.

## 2.25.5 — PR 8.5 Affiliate Index Diagnostics Count Accuracy
- Corretto il conteggio `missing_index` in `ALMA_AI_Content_Agent_Affiliate_Index::get_index_stats()` per contare post unici non indicizzati con URL affiliato valido, evitando sovrastime dovute a righe duplicate in `postmeta`.
- Eseguito audit dei conteggi diagnostici sensibili ai metadati (`without_affiliate_url`, `needs_update`, `missing_index`, `active_invalid_records`) con query robuste basate su `EXISTS` / `NOT EXISTS` / `COUNT(DISTINCT ...)` per prevenire moltiplicazioni da join.
- Nessuna modifica funzionale a ricerca, scoring, batch indexing, sync incrementale, auto-sync, OpenAI, Draft Builder, provider/importer.

## 2.25.4 — PR 8.4 Affiliate Index Safe Maintenance and Pre-Sync Validation
- Aggiunte azioni operative sicure in Dashboard per `reset_affiliate_index_state` (non distruttiva) e `clear_affiliate_index` (svuota solo indice tecnico).
- Implementato svuotamento sicuro di `{$wpdb->prefix}alma_ai_affiliate_index` con fallback table-missing senza fatal e reset automatico di `alma_ai_affiliate_index_state`.
- Estesa validazione pre-sync con conteggi diagnostici aggregati: `missing_index`, `orphan_index_records`, `active_invalid_records`, oltre a `needs_update`, `without_affiliate_url`, `inactive_index_records`.
- Nuovo stato operativo leggibile nella card “Indice Link affiliati” per guidare primo sync completo e batch progressivi.
- Confermata retrocompatibilità: nessuna modifica a OpenAI, Draft Builder, provider/API, importer, shortcode, tracking, Post/Pagine/TXT/Fonti online/Media.

## 2.25.3 — PR 8.3 Affiliate Index Autosync Ordering and Native Results Pagination
- Auto-sync indice affiliate su `save_post_affiliate_link` spostato a priorità alta per garantire esecuzione dopo salvataggio meta/tassonomie del CPT `affiliate_link`, mantenendo guard autosave/revision/post type e filtro `alma_ai_affiliate_index_disable_autosync`.
- Rimossi dalla card “2. Risultati ricerca” il pulsante **Svuota ricerca** e il filtro **Tipologia contenuto** (`alma_result_type`) con relativa logica/rendering UI.
- Sostituita la paginazione custom con paginazione in stile admin WordPress (`tablenav`, `tablenav-pages`, `displaying-num`, `pagination-links`) con link prima/precedente/successiva/ultima e normalizzazione pagina corrente.

## 2.25.2 — PR 8.2 Affiliate Index Relevance Scoring, Auto Sync Hooks and Result Diagnostics
- Migliorato scoring `affiliate_link` con pesi per titolo, Contesto AI, tipologie, contenuto e provider; esclusi record non pubblicati/inattivi/senza URL valido.
- Validazione URL affiliato centralizzata in `ALMA_AI_Content_Agent_Affiliate_Index::get_affiliate_url_data()` riusata da indicizzazione e fallback ricerca.
- Aggiunte motivazioni diagnostiche leggibili nei risultati affiliate (indice e fallback), con schema uniforme risultati e ordinamento per score desc.
- Aggiunti hook di auto-sync leggero su `save_post_affiliate_link` (con guard autosave/revision/filtro disable) e gestione coerenza stato indice su delete/trash/status tramite re-index del singolo record.
- Estese statistiche indice con conteggio `needs_update` e visualizzazione “Da aggiornare” nella card Dashboard.

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

## 2.24.5 — PR 7.5 — Ideas UI Regression Fixes
- Ripristinato il blocco **1. Cerca contenuti** nella colonna centrale.
- Ripristinata **3. Sessione contenuto** con rendering reale elementi selezionati e pulsanti Rimuovi.
- Ripristinato il pulsante **Crea nuova idea** nella toolbar superiore.
- Rimossa la lista **Idee salvate** dalla colonna sinistra, sostituita con card **Idea attiva**.
- Titolo idea reso più grande e prioritario.
- Rimosso il testo **4. Azioni finali**.
- Rimossa limitazione visuale a 10 risultati e slicing/paginazione incompleta.
- Corretto `selected_map` basato su `result_key` per badge **Già nell’idea**.
- Nessuna nuova chiamata AI introdotta.

## 2.24.4 — PR 7.4 — Results Pagination & Usage Indicators
- paginazione per tipologia (10)
- evidenziazione risultati già aggiunti
- conteggio utilizzi in bozze
- azioni finali in riga superiore
- nessuna nuova chiamata AI

## 2.24.3 — PR 7.3 — Three Column UI Restoration
- Ripristino layout UI a 3 colonne nella tab Idee contenuto.
- Colonna destra dedicata a Sessione contenuto + Azioni finali.
- Risultati ricerca mantenuti nella colonna centrale.
- Miglioramento grafico card, badge, spaziature e leggibilità liste.
- Mantenute funzioni Aggiungi all’idea e Aggiungi selezionati all’idea.
- Nessuna nuova chiamata AI (single-call OpenAI invariata su Crea Bozza).

## 2.24.2 — PR 7.2 — Idea Selection Fixes & Executed State
- Fix overwrite dati idea nelle azioni non-search (add/remove non sovrascrivono titolo/profilo/prompt).
- Persistenza openai_prompt in Selection Session e fallback in last_query.
- Limiti selezione incrementale calcolati su intera sessione con deduplica e messaggi chiari.
- Fix pulsante bulk Aggiungi selezionati all’idea e struttura form risultati.
- Ricerca locale filtrata per pertinenza e sola inclusione con match testuale reale.
- Rimossi stati editoriali UI; introdotti Non eseguita / Eseguita il ...
- Stato Eseguita impostato solo dopo creazione bozza OpenAI riuscita (draft_post_id + executed_at).
- Nessuna nuova chiamata AI: OpenAI solo su Crea Bozza con OpenAI.

## 2.24.1
- Introduzione entità persistente Idee contenuto come CPT interno (`alma_content_idea`).
- Nuova UI a 3 colonne (Idee salvate, Cerca/Risultati, Sessione contenuto).
- Prompt editoriale per OpenAI salvato per singola idea.
- Azioni Aggiungi selezionati e gestione contenuti associati per idea.
- Rimozione flusso Duplica/Proposta di sviluppo e mantenimento single-call OpenAI in creazione bozza.

## 2.23.1 — PR 6.1 — Search Session UX & Payload Refinement
- fix selezione persa in dedupe preservando selected=true.
- fix collegamento reale query di ricerca locale (campo unico).
- ordinamento gruppi risultati: Link Affiliati, Post, File TXT, Fonti online, Pagine, Media.
- nuovi limiti selezionabili: 20/3/5/5/2/5 con warning admin per gruppo.
- Sessione contenuto persistente separata da Risultati ricerca.
- rimossa dalla UI principale la tabella legacy idee/brief.
- payload JSON basato sulla Sessione contenuto reale (single-call OpenAI solo su Crea Bozza).


## 2.23.1 — PR 6 — AI Content Agent Single OpenAI Draft Workflow
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
