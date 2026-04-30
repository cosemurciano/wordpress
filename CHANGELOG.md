# Changelog

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
