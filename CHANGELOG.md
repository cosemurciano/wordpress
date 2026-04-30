# Changelog

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
