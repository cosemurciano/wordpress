# Changelog

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

