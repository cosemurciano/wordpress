# Affiliate Link Manager AI

Versione 2.9.2

Questo plugin gestisce e ottimizza i link affiliati all'interno di WordPress.

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

## 2026-04 Hotfix Affiliate Sources
- Fix: gestione POST create/update Source spostata su hook pre-render `load-<page_hook>` con redirect PRG sicuro.
- Aggiunta archiviazione Source (soft-delete) con `deleted_at`/`deleted_by`, pulizia credenziali e mantenimento link importati.
- Aggiunta conferma eliminazione dedicata e blocchi per test connessione/discovery su Source eliminate.
- Aggiunti snapshot source sui link (`_alma_source_name`, `_alma_source_provider`, `_alma_source_provider_label`, `_alma_source_deleted_at`).
- Filtro Sources nei Link Affiliati aggiornato con etichetta `(eliminata)` e compatibilità retroattiva.

