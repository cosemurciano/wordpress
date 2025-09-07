# Affiliate Link Manager AI

Versione 2.5

Questo plugin gestisce e ottimizza i link affiliati all'interno di WordPress.

## Funzionalità principali

- Gestione dei link affiliati tramite un Custom Post Type dedicato.
- Tracciamento dei click con report e statistiche nel pannello di controllo.
- Suggerimenti AI per la generazione di titoli ottimizzati per la SEO e le conversioni.
- Importazione massiva di link tramite un flusso guidato a step.
- Assegnazione di tipologie personalizzate con creazione automatica delle categorie più comuni.
- Pulizia automatica degli shortcode quando i link vengono eliminati o spostati nel cestino.
- Dashboard riassuntiva con conteggio dei link attivi e dei click totali.
- Grafico “Origine dei Click” per visualizzare la distribuzione dei click per fonte (post, widget, bot, ecc.).

## Origine dei Click

Ogni link generato dal plugin include l'attributo `data-source`, che identifica la modalità di presentazione del link. Lo script di tracciamento invia questo valore all'endpoint AJAX `alma_track_click`, che lo salva nella tabella `wp_alma_analytics`.

La dashboard admin offre il grafico “Origine dei Click” tramite l'endpoint `alma_get_chart_data` con `metric=sources`, aggregando i click per ciascuna fonte. È possibile ottenere gli stessi dati con la seguente query SQL (sostituire `wp_` con il prefisso delle tabelle):

```sql
SELECT source, COUNT(*) AS clicks
FROM wp_alma_analytics
GROUP BY source;
```

