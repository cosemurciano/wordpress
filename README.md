# Affiliate Link Manager AI

Versione 2.6

Questo plugin gestisce e ottimizza i link affiliati all'interno di WordPress.

## Funzionalità principali

- Gestione dei link affiliati tramite un Custom Post Type dedicato.
- Tracciamento dei click con report e statistiche nel pannello di controllo.
- Suggerimenti AI per la generazione di titoli ottimizzati per la SEO e le conversioni.
- Importazione massiva di link tramite un flusso guidato a step.
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

