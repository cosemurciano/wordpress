## 2.76.0 - 2026-07-06

### Regia AI v2: piano con data di inizio, consigli strategici accorpati, stop dell'agente, via il tetto bozze
- **Consigli strategici accorpati nella Regia**: il consiglio del piano ora usa TUTTE le informazioni disponibili — il prompt statistico completo della dashboard (lo stesso dei vecchi "Consigli strategici AI", che si trasferiscono qui), le opportunità Search Console, i gap geografici e il ritmo di produzione — e restituisce **piano consigliato** (articoli/giorni/obiettivo) + motivazione + **3-6 consigli strategici prioritizzati**. «Applica il piano» compila i campi modificabili (Quanti articoli, In quanti giorni, data, obiettivo/suggerimenti dell'admin), poi si rivede e si preme «Avvia il piano». Il box in Dashboard rimanda alla Regia (con gli ultimi consigli in un dettaglio); il comando Telegram `/consigli` riceve i nuovi consigli della Regia (compatibilità mantenuta).
- **Data di inizio del piano** (nuovo campo, default oggi; date passate → oggi): ogni piano è indipendente e **si somma** a quelli già programmati — le idee ricevono date distribuite dalla data scelta. Guardia deterministica: se l'AI assegna date fuori dalla finestra [inizio, inizio+giorni), vengono ridistribuite in sequenza dentro la finestra (test standalone 11/11).
- **Via il tetto giornaliero di bozze** (era 3, nascosto in Importazione massiva): ogni idea creata genera la sua bozza — quelle programmate a oggi subito, le future nel giorno previsto dal runner giornaliero, che ora processa TUTTE le idee in scadenza a run brevi (budget 180s) auto-programmandosi ogni 2 minuti finché ce ne sono. Se la pubblicazione automatica è attiva, la bozza viene pubblicata. Il ritmo lo decide solo la programmazione dei piani. Contatore bozze mantenuto come statistica.
- **Limiti di guardia rimossi** dalla Regia (in conflitto con la nuova logica): niente più tetto di esecuzioni giornaliere (il lock impedisce comunque esecuzioni sovrapposte); le option restano nel database per compatibilità.
- **Stato esecuzione + pulsante Ferma**: la Regia mostra chiaramente "AGENTE IN ESECUZIONE…" (con auto-refresh della pagina ogni 15s) e un pulsante **🛑 Ferma l'agente**: lo stop agisce ai confini di ogni round del loop e prima di ogni bozza (il passo in corso si conclude, poi il run si interrompe pulitamente registrando "Interrotto dall'amministratore"); le idee già create restano e le loro bozze arriveranno nei giorni programmati.
- Problematiche valutate e gestite: piani sovrapposti si sommano (più bozze nello stesso giorno → il runner le smaltisce in catena); data nel passato normalizzata a oggi; stop a run non partito neutralizzato dal riavvio pulito del flag; massimo 10 articoli per piano (limite strutturale del loop agente: per piani più grandi, lanciare più piani con date successive).
- Versione plugin aggiornata a `2.76.0`.

## 2.75.1 - 2026-07-06

### Filtro Sources senza archiviate + verifica dei percorsi d'import
- **Filtro "Sources" nell'elenco Link Affiliati**: le source archiviate non compaiono più nel menu a tendina (prima erano elencate con suffisso "(eliminata)"). Il filtro per ID resta applicabile via URL, così i link di una source archiviata restano raggiungibili all'occorrenza.
- **Verifica dei prossimi import (documentata)**: il percorso **Viator API** usa il `productUrl` restituito dall'API partner così com'è (già con `pid`, `mcid` e `medium=api`) e lo salva senza alcuna trasformazione; il percorso **GetYourGuide CSV** passa dal builder che normalizza al dominio ufficiale `.it` e aggiunge `partner_id` (v2.74.0); l'import su una source archiviata è bloccato; la deduplica lavora per `external_id` e per URL; la normalizzazione dominio non tocca URL non-GetYourGuide. I prossimi import producono quindi URL corretti su entrambi i canali.
- Versione plugin aggiornata a `2.75.1`.

## 2.75.0 - 2026-07-06

### Verifica link: bonifica anche per Viator + protezione esplicita dei link Travelpayouts manuali
- **Verificato di nuovo che il plugin non altera i link**: nella stessa pagina pubblica convivono i link Viator importati via API (pubblicati intatti: `viator.com/it-IT/…?pid=…&mcid=…&medium=api`) e i vecchi link salvati come short Travelpayouts (`viator.tpx.li`, stesso `trs` degli short inseriti a mano) — sono post diversi, non una riscrittura.
- **Bonifica per programma**: oltre a GetYourGuide, ora anche **Viator** — gli short link `viator.tpx.li` vengono risolti fino alla pagina prodotto (solo codici prodotto reali `dNNN-NNNPNN`, mai pagine città), il locale viene normalizzato a `it-IT` come nei link API, e viene applicato `?pid=…&mcid=42383&medium=link` (il `pid` attribuisce la commissione; precompilato leggendolo dai link API già presenti). Card di bonifica separata per programma con conteggi e ID dedicati; report con etichetta del programma.
- **Gli short link Travelpayouts inseriti volontariamente restano intoccabili**: booking, expedia, tripadvisor, agoda e qualsiasi altro sottodominio `*.tpx.li` non vengono né modificati né marcati — in tabella domini compaiono come "link Travelpayouts manuale — non viene toccato". Nulla viene aggiunto né modificato su di essi.
- Test standalone 10/10 Viator (locale en→it-IT e query rimossa, aggiunta locale mancante, it-IT invariato, rifiuto di tpx.li/pagine città/domini estranei, builder pid+mcid+medium, booking.tpx.li estraneo a entrambi i cleaner) + 13/13 GYG di non-regressione.
- Versione plugin aggiornata a `2.75.0`.

## 2.74.1 - 2026-07-06

### Verifica link: solo GetYourGuide, batch più massivi
- **Perimetro ristretto ai soli link GetYourGuide**: la bonifica ora intercetta esclusivamente gli short link `getyourguide.tpx.li` — gli eventuali short link Travelpayouts di altri programmi (altri sottodomini `*.tpx.li`) non vengono più toccati né marcati come falliti; nella tabella dei domini compaiono come "altro programma — per ora non toccato". Il convertitore di dominio era già limitato a `getyourguide.*`.
- **Batch della bonifica portato da 15 a 50 link per click**, con budget tempo a 40 secondi per giro (i link non elaborati restano in coda per il click successivo) e pausa tra le richieste dimezzata. Testi della pagina aggiornati.
- Versione plugin aggiornata a `2.74.1`.

## 2.74.0 - 2026-07-06

### Dominio ufficiale GetYourGuide: riferimento a www.getyourguide.it
- **Nuova preferenza dominio** (`alma_gyg_preferred_domain`, default `www.getyourguide.it`): il programma partner dell'editore è italiano e i link ufficiali sono su `.it` — gli ID delle attività (`t…`) sono indipendenti dal dominio e GYG reindirizza allo slug italiano mantenendo il `partner_id`, quindi lo spostamento è una pura sostituzione dell'host (percorso, query e fragment conservati). Configurabile nella pagina "Verifica link" (`.it` consigliato / `.com` / "Mantieni il dominio originale" per il comportamento storico).
- **Applicata ovunque si generino URL**: dentro `build_affiliate_url()` dell'import CSV, quindi vale per tutti gli import futuri E per la bonifica tpx.li (che la riusa) — i link bonificati escono direttamente su `.it`.
- **Convertitore per l'archivio esistente**: nella pagina "Verifica link", batch da 50 link per click che sposta i link `getyourguide.*` già salvati sul dominio preferito — nessuna chiamata HTTP, backup dell'URL precedente nel meta `_alma_url_pre_bonifica` (mai sovrascritto), cache del widget contestuale invalidata a fine giro. I tpx.li restano esclusi (li gestisce la bonifica dedicata).
- Test standalone 13/13 (conservazione path/query/fragment, host senza www, `.it` già corretto invariato, tpx.li e domini estranei non toccati, opzione invalida → fallback sicuro, "keep" → comportamento storico, integrazione nel builder dell'import CSV nei due modi).
- Versione plugin aggiornata a `2.74.0`.

## 2.73.0 - 2026-07-06

### Verifica link affiliati: audit degli URL salvati + bonifica tpx.li
- **Diagnosi del caso "0 visite su GetYourGuide"**: alcuni Link Affiliati erano salvati nel database come short link Travelpayouts (`getyourguide.tpx.li/…`) invece del deep link ufficiale con `partner_id` — i click finivano tracciati da Travelpayouts e il programma partner GetYourGuide non li vedeva. Verificato che **il plugin non altera mai gli URL in uscita** (pubblica esattamente il valore di `_affiliate_url`; nel codice non esiste alcun riferimento a tpx.li/Travelpayouts) e che l'import CSV corrente produce URL corretti (`getyourguide.com` + `partner_id`): il problema è nei dati storici di quei link.
- **Nuova pagina "Verifica link"** (menu Affiliate Link AI): riepilogo dei domini realmente in uso nei link pubblicati (con ⚠️ sui tpx.li), conteggio dei link getyourguide.* senza partner_id, elenco dei link da bonificare.
- **Bonifica guidata**: batch da 15 link per click (interrompibile, lock atomico, budget 25s) — per ogni link il server segue i redirect fino al prodotto getyourguide.*, elimina i parametri di tracciamento Travelpayouts e riapplica `partner_id`/`utm_medium` ufficiali riusando il builder dell'import CSV. **L'URL originale viene salvato nel meta di backup `_alma_url_pre_bonifica`** prima di ogni modifica (mai sovrascritto se già presente); i link irrisolvibili vengono marcati e esclusi dai giri successivi (con pulsante "Ritenta i falliti"); il Partner ID viene precompilato da un link GYG già corretto. Report dell'ultimo giro con esito per link. La cache del widget contestuale viene invalidata a fine giro.
- Test standalone 13/13 (pulizia URL attività con parametri Travelpayouts, domini .it/.com, rifiuto tpx.li/pagine città/domini estranei, risoluzione Location assoluti/relativi/scheme-relative, estrazione partner_id, flusso completo fino all'URL ufficiale).
- Versione plugin aggiornata a `2.73.0`.

## 2.72.0 - 2026-07-06

### Fase 7.2 (PR D): territorio OpenStreetMap + aeroporto più vicino nelle schede località
- **Quarta fonte sulle schede località: OpenStreetMap** (Overpass API, gratuita, senza chiave). Per ogni località la scheda fotografa il **territorio pratico entro 10 km**: spiagge, punti panoramici, porti turistici, campeggi, riserve naturali, terme/sorgenti e sentieri escursionistici — con totale ed esempi per categoria (nome italiano preferito, doppioni nodo+area deduplicati per nome) e sintesi in italiano. Un territorio poco mappato è un risultato legittimo ("Nessun POI rilevante"), non un errore. Una sola query per località, solo tag (niente geometrie), pausa di 2 secondi nel warmer, validità 3 mesi.
- **Aeroporto più vicino nella scheda Wikidata**: entità con codice IATA entro 150 km via SPARQL `wikibase:around` (il filtro IATA seleziona da solo gli aeroporti reali), distanza haversine, il più vicino vince — es. Cefalù → "aeroporto più vicino: Palermo-Punta Raisi (PMO), 65 km". Sostituisce OurAirports/OpenFlights senza importare alcun dataset (le rotte OpenFlights sono ferme al 2014). Le schede fatti già salvate si arricchiranno al rinnovo naturale o al fetch on-demand.
- Lo strumento dell'agente `scheda_localita` include la nuova sezione `territorio` (fetch on-demand mai bloccante) e l'aeroporto nei fatti; descrizione del tool aggiornata (articoli pratici + scoperta di ciò che il sito non copre).
- Tab "Schede località" aggiornata: quarta riga di stato "Territorio (OpenStreetMap)" e TTL documentati.
- Come deciso: scartati OurAirports/OpenFlights (dati rotte obsoleti), UNWTO/Eurostat (valore basso rispetto a GSC+Trends) e Overture/Foursquare (bulk download/API commerciale); chip faccette e affinamenti Fase 4 rimandati.
- Test standalone 18/18 (classificazione OSM con doppioni e name:it, payload territorio pieno/vuoto, aeroporto più vicino su coordinate reali Cefalù→PMO con eliporti e IATA invalidi ignorati, retrocompatibilità payload Wikidata senza aeroporto).
- Versione plugin aggiornata a `2.72.0`.

## 2.71.0 - 2026-07-06

### Warmer schede località: report affidabile, turni garantiti e run in catena
- **Bug 1 — report azzerato**: quando il budget di 60 secondi si esauriva a metà run, un `break` usciva da entrambi i cicli prima del salvataggio dei contatori e il report mostrava "elaborate 0, ok 0, errori 0" per tutte le fonti nonostante le schede venissero realmente create. Ora i contatori di ogni fonte vengono salvati **anche se parziali**.
- **Bug 2 — starvation delle fonti**: Open-Meteo (con più località in attesa) consumava l'intero budget a ogni run e Wikidata/Google Trends non arrivavano quasi mai al proprio turno (nella pratica: 56 schede clima vs 19 fatti vs 1 tendenze). Ora ogni fonte ha un **budget dedicato di 20 secondi per run**: il turno è garantito.
- **Run in catena**: un solo run notturno non basta per smaltire migliaia di schede su hosting condiviso. Finché resta lavoro (e il run corrente ha prodotto qualcosa), il warmer **si auto-programma** un nuovo run dopo 90 secondi, fino a **30 run al giorno** — tanti run brevi invece di uno lungo, il pattern giusto per Aruba. A regime: centinaia di schede al giorno, backlog smaltito in pochi giorni. Contatore giornaliero con tetto anti-loop (si azzera al cambio data, tollera opzioni corrotte) e guardia sul cooldown di Google Trends (niente giri a vuoto).
- La tab "Schede località" spiega il nuovo comportamento e mostra i run di recupero usati oggi (X/30).
- Test standalone 6/6 sul contatore della catena (tetto giornaliero, reset al cambio data, opzione corrotta).
- Versione plugin aggiornata a `2.71.0`.

## 2.70.1 - 2026-07-06

### Fix precisione widget contestuale: basta link di altre regioni sugli articoli localizzati
- **Bug**: su un articolo geolocalizzato correttamente (es. Cefalù) il widget contestuale mostrava link di tutt'altra zona (Lecce, Civitavecchia, Milano). Causa: due località puntuali nello stesso paese valevano **+10 senza alcuna penalità inter-regione** — su un blog italiano "stesso paese" è quasi sempre vero — e le keyword generiche condivise col titolo ("tour", "centro storico") valevano doppio, facendo superare la soglia a link di altre regioni, specie quando i link davvero pertinenti erano esclusi perché già presenti nell'articolo.
- **Fix 1 — penalità inter-regione**: stesso paese ma regioni dichiarate da entrambe le parti e senza intersezione → **-10** (era +10). Con metadati regione incompleti resta il neutro-positivo +10 di prima.
- **Fix 2 — dominanza geografica**: se almeno un risultato ha un segnale locale (stessa città, contenimento paese/regione o stessa regione, geo ≥ 20), i risultati **senza** segnale locale vengono scartati: meglio 1-2 proposte pertinenti che 4 riempitive. Se non esistono risultati locali il comportamento resta invariato.
- `MATCHER_VERSION` portata a 6: le cache del widget calcolate col vecchio algoritmo si invalidano da sole.
- Test standalone 12/12, incluso il caso reale del bug (Milano/Lecce su Cefalù → penalizzati; Palermo stessa regione → 20; Cefalù stessa località → 45; contenimento paese invariato a 35; dominanza nei 4 scenari).
- Versione plugin aggiornata a `2.70.1`.

## 2.70.0 - 2026-07-05

### Regia AI: camera di regia dell'agente di ideazione + Telegram potenziato
- **Nuova pagina "Regia AI"** nel menu, subito dopo la Dashboard (`ALMA_AI_Agent_Control_Room`):
  - **Piano editoriale**: comunichi all'agente quanti articoli creare (1-10), in quanti giorni distribuirli (1-60), un obiettivo opzionale, e se creare subito anche le bozze (**spuntato di default**); le idee oltre la quota bozze giornaliera vengono generate automaticamente nei giorni programmati dal job notturno.
  - **Consiglio AI del piano**: un pulsante chiede all'AI di proporre quanti articoli, in quanti giorni e con quale focus, sulla base dei dati reali (click, gap geografici, opportunità Search Console, ritmo attuale); «Applica al piano» precompila il form.
  - **Grafico attività** (Chart.js): idee create e bozze AI generate per giorno negli ultimi 30 giorni.
  - **Storico esecuzioni** (ultime 30: data, idee, bozze, obiettivo, costo, esito, lancio manuale ✋) + report dettagliato dell'ultima esecuzione.
  - **Limiti di guardia** (idee per esecuzione, esecuzioni automatiche/giorno) spostati qui.
- **I lanci manuali partono sempre**: dalla Regia e da Telegram l'agente viene eseguito anche oltre il limite giornaliero di esecuzioni (con nota informativa); il limite continua a proteggere le esecuzioni non presidiate. Il piano richiesto (numero idee, giorni) guida i prompt dell'agente ("crea ESATTAMENTE N idee distribuite in X giorni").
- **Telegram**: `/agente <argomento>` ora crea idee **e relative bozze** sul tema indicato (prima non generava bozze), parte anche oltre il limite giornaliero e lo segnala in chat; guida comandi aggiornata.
- Il pannello "🤖 Agente ideazione AI" in Tutte le idee diventa una card compatta con lo stato e il pulsante «Apri la Regia AI» (la gestione completa vive nella nuova pagina).
- Versione plugin aggiornata a `2.70.0`.

## 2.69.0 - 2026-07-05

### Fase 7.2 (PR C): tendenze Google Trends nelle schede località + tool tendenze_google
- **Terza fonte sulle schede località: Google Trends** (nuova classe `ALMA_Google_Trends`). Per ogni località: **in quali mesi gli italiani la cercano** (stagionalità della domanda — spesso anticipa i mesi di viaggio: indica quando pubblicare), **trend dell'interesse** ultimo anno vs precedente, e **query correlate top e in crescita** (gli angoli emergenti). Dati Italia, ultimi 5 anni, distillati in sintesi italiana; validità 30 giorni.
- **Nuovo strumento dell'agente `tendenze_google`**: analizza QUALSIASI termine o tema (non solo località) per validare un'idea — la domanda esiste? sta crescendo? quando pubblicare? — con cache per termine di 7 giorni. Il prompt di sistema guida l'agente a usarlo per scegliere cosa proporre e quando (prima del picco di ricerche).
- **Nota importante**: Google Trends non ha un'API ufficiale — si usano gli endpoint interni del sito (gli stessi di pytrends). Difese integrate: **circuit breaker** (al primo HTTP 429 la fonte si sospende da sola per 6 ore, senza marcare le località come in errore), cookie NID recuperato e riusato (1 giorno), pausa di 2 secondi tra le chiamate nel warmer, e degrado con messaggio chiaro se l'endpoint cambia — il resto del plugin non ne risente. L'avvertenza è riportata anche nella tab.
- Warmer notturno esteso a tre fonti (clima → fatti → tendenze) con stato e report separati nella tab "Schede località"; la scheda `scheda_localita` dell'agente include la nuova sezione `tendenze_ricerca`.
- Test standalone 22/22 (decodifica prefisso anti-hijacking, stagionalità su 5 anni sintetici con picchi estivi e crescita ultimo anno, serie corte/sporche, parsing query correlate, payload con etichette trend crescita/calo/stabile).
- Versione plugin aggiornata a `2.69.0`.

## 2.68.0 - 2026-07-05

### Fase 7.2 (PR B): fatti Wikidata nelle schede località
- **Seconda fonte sulle schede località: Wikidata** (senza API key). Per ogni località la scheda si arricchisce con la "carta d'identità": descrizione, popolazione, paese, altitudine, **patrimonio UNESCO**, link alla Wikipedia italiana e **attrazioni notevoli entro 10 km** (musei, chiese, castelli, siti archeologici, parchi…) ordinate per notorietà (numero di sitelink), il tutto distillato in una sintesi in italiano pronta per il prompt.
- **Disambiguazione tra omonimi per prossimità**: l'entità viene cercata per nome (API `wbsearchentities`, lingua italiana) e scelta tra i candidati per distanza haversine dalle coordinate già geocodificate del gazetteer (max 100 km) — es. "Barcellona" siciliana vs spagnola. I dettagli arrivano con una singola query SPARQL per i candidati; le attrazioni con una query `wikibase:around` su tipologie fisse (niente ricorsioni lente).
- **Warmer a due fonti**: il job notturno riempie prima il clima poi i fatti Wikidata (stesso lock, stesso budget di 60s, pausa di 1s tra le chiamate SPARQL come da policy WDQS), con report per fonte (elaborate/ok/errori/rimanenti) nella tab. Validità 6 mesi; errori ritentati dopo 7 giorni.
- Lo strumento dell'agente `scheda_localita` ora restituisce anche la sezione `fatti` (con fetch on-demand se mancante, mai bloccante) e il prompt di sistema chiede di usare UNESCO e attrazioni per angoli accurati e non ancora coperti.
- Tab "Schede località" aggiornata: stato separato per Clima (Open-Meteo) e Fatti (Wikidata).
- Test standalone 18/18 (parsing WKT, haversine Roma-Milano, disambiguazione per prossimità nei due versi, candidati senza coordinate, payload con UNESCO/popolazione formattata, casi minimi).
- Versione plugin aggiornata a `2.68.0`.

## 2.67.0 - 2026-07-05

### Fase 7.2 (PR A): schede località con clima Open-Meteo per l'agente AI
- **Nuova infrastruttura "schede località"** (`ALMA_Geo_Facts`, tabella `alma_geo_facts`): fatti da fonti esterne agganciati alle località dell'indice geografico del plugin. Non si importano dataset: si salva solo la scheda compatta già distillata in italiano (pochi KB per località), con scadenza per fonte.
- **Prima fonte: Open-Meteo** (archivio ERA5, gratuito, senza API key): dati giornalieri degli ultimi 3 anni completi aggregati in medie mensili (temperature min/max, pioggia, giorni di pioggia) da cui derivano i **mesi migliori per visitare** e i mesi da evitare, con sintesi pronta per il prompt. Validità 9 mesi (il clima è ~statico); gli errori API vengono marcati con TTL breve (7 giorni) per non martellare la fonte.
- **Warmer notturno interrompibile** (cron giornaliero ~05:00): elabora N località per run (default 10, configurabile 1-50) con lock atomico via `add_option` con TTL e budget di 60 secondi; la condizione "scheda mancante o scaduta" fa avanzare il lavoro da sola. Priorità alle località più usate nei contenuti. Pausa di cortesia di 0,5s tra le chiamate. Report dell'ultimo run (elaborate/ok/errori/rimanenti) visibile in admin.
- **Nuovo strumento dell'agente `scheda_localita`**: clima reale + dati interni della zona (numero e esempi di link affiliati, articoli pubblicati). Se la scheda non è pronta viene creata al volo (mai bloccante). Il prompt di sistema ora chiede all'agente di consultarla per le idee legate a una destinazione e di usare la stagionalità nel taglio editoriale.
- **Nuova tab "Schede località"** in Impostazioni AI Content: spiegazione, stato (schede pronte / in attesa), attivazione del riempimento automatico, località per run, pulsante «Esegui ora un run».
- Tabella creata in attivazione e upgrade (schema DB versione 6); cron rimosso alla disattivazione. Test standalone dell'aggregazione climatica 15/15 (profilo mediterraneo sintetico, valori null, dati insufficienti).
- Versione plugin aggiornata a `2.67.0`.

## 2.66.0 - 2026-07-05

### Search Console: aggiornamento automatico ogni 5 giorni
- **Nuovo cron WP** (`alma_gsc_cron_refresh`, intervallo dedicato di 5 giorni): lo snapshot Search Console si rigenera da solo ogni 5 giorni, senza dipendere dall'esecuzione dell'agente. Se le credenziali non sono configurate il job esce in silenzio; se l'API fallisce resta valido lo snapshot precedente. Il job viene rimosso alla disattivazione del plugin.
- Il TTL dello snapshot letto dall'agente è allineato a 5 giorni (prima 1 giorno): meno chiamate all'API di Google, dati comunque freschi per l'ideazione (le query GSC hanno già ~2 giorni di ritardo alla fonte).
- La tab Search Console mostra la data del prossimo aggiornamento automatico; «Aggiorna dati ora» resta disponibile per forzare il refresh.
- Versione plugin aggiornata a `2.66.0`.

## 2.65.5 - 2026-07-05

### Search Console: diagnosi dei 403 con elenco proprietà visibili al service account
- Quando l'API risponde **HTTP 403** ("User does not have sufficient permission for site …"), il plugin ora interroga `sites.list` e mostra **le proprietà che il service account vede davvero** con il relativo livello di permesso: se l'elenco non contiene la proprietà configurata, il messaggio suggerisce di copiarne una esattamente (caso tipico: l'email è stata aggiunta a una proprietà di tipo **Dominio** → `sc-domain:sothra.it`, mentre nel plugin era configurato il prefisso URL `https://www.sothra.it/`, o viceversa). Se il service account non vede alcuna proprietà, il messaggio indica dove aggiungere l'email e ricorda la latenza di propagazione.
- Nuovo metodo pubblico `ALMA_GSC_Connector::list_sites()` (Webmasters API `sites.list`), riutilizzabile per future diagnostiche.
- Versione plugin aggiornata a `2.65.5`.

## 2.65.4 - 2026-07-05

### Search Console: percorso relativo per hosting condivisi + diagnosi chiave privata
- **Percorso relativo alla cartella di WordPress**: la costante `ALMA_GSC_SERVICE_ACCOUNT_FILE` accetta ora anche un percorso relativo (es. `searchconsole-privata/chiave.json`), risolto automaticamente rispetto ad ABSPATH — indispensabile sugli hosting condivisi (es. Aruba) dove il percorso assoluto del server non è visibile da FTP/File Manager. I percorsi assoluti Unix e Windows restano invariati.
- La diagnostica "file NON esiste" e la guida nella tab Search Console mostrano ora la cartella di WordPress del server (ABSPATH) e spiegano l'uso del percorso relativo, con le istruzioni `.htaccess` (`Require all denied` + `Deny from all`) per proteggere la cartella dentro la webroot e la verifica del 403 nel browser.
- **Diagnosi della chiave privata**: quando la firma JWT fallisce, l'errore ora spiega il motivo concreto ispezionando il PEM — marker BEGIN/END mancanti, caratteri non base64 (valore alterato), chiave troncata (byte decodificati insufficienti) o corpo formalmente valido ma non PKCS#8 — indicando di scaricare una nuova chiave JSON e caricarla via FTP senza modificarla. Verificato con test standalone (8/8: firma con chiave RSA reale, 4 casi di diagnosi, 3 casi di risoluzione percorso).
- Versione plugin aggiornata a `2.65.4`.

## 2.65.3 - 2026-07-05

### Search Console: ricostruzione marker PEM mancanti
- La normalizzazione della chiave privata ripara anche il caso in cui i marker `-----BEGIN/END PRIVATE KEY-----` siano stati rimossi per errore durante l'inserimento in wp-config (errore OpenSSL "DECODER routines::unsupported"): se il valore è il solo corpo base64, il PEM PKCS#8 viene ricostruito. Verificato con chiave RSA reale (4/4 formati firmano).
- Versione plugin aggiornata a `2.65.3`.

## 2.65.2 - 2026-07-05

### Fix "Firma JWT fallita" con la costante JSON di Search Console
- **Normalizzazione automatica della chiave privata**: incollando il JSON del service account in wp-config.php gli `\n` del PEM possono arrivare a PHP come backslash letterali (o con `\r`, o la chiave su una riga sola) e OpenSSL rifiutava la firma. Ora la chiave viene riparata automaticamente (backslash-n → a-capo, ricostruzione righe da 64 caratteri) — verificato con chiave RSA reale nei tre formati rotti tipici.
- Il messaggio d'errore di firma include ora il dettaglio OpenSSL e suggerisce l'alternativa `ALMA_GSC_SERVICE_ACCOUNT_FILE` (file originale non modificato, sempre preferibile).
- Versione plugin aggiornata a `2.65.2`.

## 2.65.1 - 2026-07-05

### Diagnostica granulare credenziali Search Console
- Lo stato credenziali nella tab Search Console ora distingue i casi invece del generico "costante non definita": **costante mancante** (con promemoria: il `define()` va PRIMA della riga `/* That's all, stop editing! */` di wp-config.php), **file inesistente per PHP** (percorso/open_basedir), **file non leggibile** (permessi), **JSON non valido**, **tipo sbagliato** (es. client OAuth invece di service account), **campi mancanti** — e in caso di successo mostra l'email del service account da aggiungere come utente della proprietà.
- Versione plugin aggiornata a `2.65.1`.

## 2.65.0 - 2026-07-05

### Fase 7.1 — Google Search Console per l'agente AI + sospensione chip faccette
- **Nuovo connettore Search Console** (`ALMA_GSC_Connector`) con autenticazione **service account** (JWT RS256 firmato in PHP, nessuna libreria esterna; credenziali SOLO via costanti `ALMA_GSC_SERVICE_ACCOUNT_FILE` o `ALMA_GSC_SERVICE_ACCOUNT_JSON` in wp-config.php, mai nel database; token in cache 50 minuti).
- **Tab "Search Console"** in Impostazioni AI Content: guida passo-passo (service account, API, utente della proprietà, costanti), campo proprietà (URL o `sc-domain:`), pulsanti **Verifica connessione** e **Aggiorna dati ora**, anteprima dell'ultimo snapshot.
- **I dati realmente utili all'agente** (snapshot giornaliero in option, max 1 rigenerazione/giorno): `top_queries` (con quali ricerche gli utenti trovano il sito, 90gg), `rising_queries` (query in crescita, 28gg vs 28gg precedenti → trend), **`opportunities`** (query con impression ≥50 e posizione 8-30: domanda dimostrata senza contenuto adeguato — il segnale a più alto ritorno), `top_pages` (contesto/link interni).
- **Nuovo tool dell'agente `analizza_ricerche_google`**: l'agente di ideazione legge lo snapshot e il prompt di sistema gli impone di partire dalle "opportunità" e citare le query target nel prompt delle idee create.
- **Chip faccette negli articoli sospese**: le chip (es. "Perché: Viaggi di Nozze · Quando: Autunno") non vengono più stampate nei post; opzione e codice conservati per la riattivazione futura (impostazione marcata come sospesa).
- Versione plugin aggiornata a `2.65.0`.

## 2.64.0 - 2026-07-05

### Fase 6 — Arricchimento automatico in background dei post pubblicati
- **Nuova tab "Arricchimento"** in Impostazioni AI Content: attivazione on/off, **articoli al giorno** da analizzare (default 5, 1 chiamata OpenAI per articolo, contatore odierno visibile), **cooldown ri-analisi** (default 60 giorni), pulsante "Esegui ora", coda stimata e **report attività** (ultimi 100 articoli: esito, link aggiunti/sostituiti, note/errori, link di modifica).
- **Applicazione diretta senza revisione manuale**: il runner giornaliero analizza gli articoli e **aggiorna il post pubblicato aggiungendo i link** — il contenuto esistente non viene mai riscritto (le anchor si accodano al paragrafo scelto, bottoni/card come blocchi autonomi; inserimenti in ordine di paragrafo decrescente per non spostare gli indici). Ogni aggiornamento crea una **revisione WordPress** (rollback nativo).
- **Ciclo di copertura con ri-analisi**: prima tutti gli articoli mai analizzati, poi il ciclo riparte automaticamente dai più vecchi di analisi — mai prima del cooldown. Nelle **ri-analisi** l'AI riceve gli shortcode esistenti con la loro coerenza geografica e può proporre **sostituzioni** (link incoerenti/non validi o candidati nettamente migliori), applicate preservando pattern e struttura dello shortcode (max 3 per articolo). Motore condiviso con il metabox "AI Affiliati" (stesse Regole inserimento, densità, paragrafi protetti, soli link candidati).
- **Niente notifiche per articolo**: a fine esecuzione un solo **digest Telegram** (analizzati/aggiornati/link aggiunti/sostituiti), se il bot è abilitato.
- Lock anti-concorrenza, budget tempo per esecuzione (150s), costi registrati nell'usage logger (task `post_enricher`), cron rimosso alla disattivazione. Test standalone per la logica di sostituzione.
- Versione plugin aggiornata a `2.64.0`.

## 2.63.0 - 2026-07-05

### Fase 5 — Bot Telegram: regia, monitoraggio e strategia
- **Nuova integrazione Telegram** (tab "Telegram" in Impostazioni AI Content) con **guida alla configurazione passo-passo**: bot via @BotFather, costanti `ALMA_TELEGRAM_BOT_TOKEN` e `ALMA_TELEGRAM_SECRET` in wp-config.php (le credenziali non toccano mai il database), abilitazione, pulsanti **Registra webhook / Verifica stato webhook / Invia istruzioni su Telegram** (usano le costanti, senza terminale), URL webhook visibile, scoperta della Chat ID con `/id` e pannello **"Chat ID viste di recente"** con pulsante Aggiungi.
- **Comandi di regia** (solo chat autorizzate): `/agente <obiettivo>` avvia l'agente di ideazione con l'obiettivo indicato; `/report` esito dell'ultima esecuzione (idee, bozze, costo, riepilogo); `/bozze` ultime bozze AI con **pulsanti inline Pubblica/Cestina/Anteprima**; `/top` report sintetico su click 7/30 giorni, top link e gap geografici dallo snapshot Dashboard; `/consigli` ultimi consigli strategici AI; `/id` e `/help` aperti a tutti.
- **Notifiche push**: ogni nuova bozza AI arriva in chat con i pulsanti di revisione (disattivabile); a fine esecuzione dell'agente arriva il report completo con i link di anteprima delle bozze.
- **Sicurezza**: webhook REST `alma/v1/telegram` protetto dal secret di Telegram (header verificato con hash_equals) + whitelist di Chat ID; i pulsanti Pubblica/Cestina agiscono SOLO su post generati dall'agente (meta verificata), mai su altri contenuti.
- Versione plugin aggiornata a `2.63.0`.

## 2.62.0 - 2026-07-05

### Bozze AI complete: immagine in evidenza, meta SEO via All in One SEO, pubblicazione diretta opzionale
- **Fix immagine in evidenza**: il flusso salvava l'ID scelto dall'AI solo come meta ma **non impostava mai la thumbnail** — ora `set_post_thumbnail` viene chiamata sempre, con fallback alla prima immagine candidata della Media Library quando l'AI non sceglie (e warning esplicito se non ci sono candidate).
- **Meta title e description per ricerca e social**: nuovo bridge `ALMA_AI_Seo_Bridge` che scrive `seo_title`/`seo_description` generati dall'AI in **All in One SEO** — tramite il modello ufficiale del plugin se attivo (title, description, OG title/description, Twitter da OG), oppure upsert diretto sulla tabella `aioseo_posts` se presente; i valori restano comunque nei meta `_alma_ai_seo_title/_alma_ai_seo_description` per tracciabilità (warning nel report se AIOSEO non è rilevato). Applicato a entrambe le pipeline di generazione.
- **Pubblicazione diretta opzionale**: nuova impostazione "Pubblicazione diretta delle bozze AI" in Impostazioni → Generale (default **No**): con "Sì" gli articoli generati (workspace, runner programmato, agente) vengono pubblicati immediatamente con immagine e SEO già applicati; il messaggio di esito indica lo stato reale (Bozza / Pubblicato).
- Versione plugin aggiornata a `2.62.0`.

## 2.61.1 - 2026-07-05

### Fix "Contenuto troppo breve" nel metabox AI Affiliati (contenuti classic editor / CRLF)
- **Fix rilevamento paragrafi**: il contenuto salvato dal classic editor (e da WPBakery, come su sothra.it) non contiene `</p>` e usa newline Windows `\r\n`; lo splitter cercava solo `\n\n` e vedeva l'intero articolo come UN paragrafo → "Contenuto troppo breve per proporre inserimenti" anche su articoli lunghi. Ora i paragrafi sono delimitati da `</p>` (HTML/Gutenberg) **oppure** da riga vuota con qualunque newline (`\r\n` incluso, anche con spazi), e viene scelta automaticamente la modalità che rileva più paragrafi. Riprodotto e verificato: contenuto CRLF passa da 1 a N paragrafi.
- **Stesso fix nel QA `enforce()`** (Regole inserimento): con contenuto senza `</p>` la protezione dei primi paragrafi degradava TUTTI gli shortcode dell'articolo; ora usa la stessa delimitazione doppia.
- **Fix conteggio parole per la densità**: `str_word_count` spezza le parole accentate italiane ("città" contata come due); nuovo conteggio Unicode-safe usato sia dal budget del metabox sia dal QA — la densità configurata (es. ogni 100 parole) ora è calcolata correttamente sui testi italiani.
- Test standalone estesi: CRLF, WPBakery, righe vuote con spazi, inserimenti inline/blocco su contenuto classic (9 nuovi casi, tutte le suite verdi).
- Versione plugin aggiornata a `2.61.1`.

## 2.61.0 - 2026-07-05

### Fase 4 (PR 2) — Metabox "AI Affiliati" nell'editor del post
- **Nuovo metabox "AI Affiliati"** nell'editor dei post con due sezioni:
- **Diagnostica shortcode**: tabella degli shortcode affiliati presenti nel contenuto salvato — pattern rilevato (anchor/bottone/card/widget), validità (link pubblicato con URL, widget esistente) e **coerenza geografica** con la località del post (stessa località / stesso paese / località diversa, colorata).
- **"Proponi ottimizzazioni AI"**: il modello analizza i paragrafi dell'articolo e i link candidati (area geografica del post + match sul titolo) e propone nuovi inserimenti nel rispetto delle Regole inserimento — budget calcolato dalla densità meno gli shortcode già presenti, paragrafi protetti rispettati, solo link candidati (ID inventati scartati), anchor come frase completa che prosegue il paragrafo.
- **Le proposte non modificano nulla**: vengono salvate in meta e mostrate una a una con pattern, link, posizione, motivazione e anteprima; l'editore le **applica o scarta singolarmente**. Ogni applicazione passa da `wp_update_post` → **revisione WordPress** (rollback nativo) e ricarica la pagina; se l'editor ha modifiche non salvate l'applicazione viene bloccata con un avviso (niente conflitti di contenuto).
- Inserimento chirurgico: le anchor si accodano al paragrafo scelto, bottoni e card diventano blocchi autonomi subito dopo; supportati sia contenuti HTML (Gutenberg) sia testo classico. Logica testata standalone (10/10).
- Ogni chiamata AI registrata nell'usage logger con costo stimato (task `post_optimizer`).
- Versione plugin aggiornata a `2.61.0`.

## 2.60.0 - 2026-07-05

### Fase 4 (PR 1) — Regole inserimento shortcode, widget creati dall'AI, bozze dirette dall'agente
- **Nuova tab "Regole inserimento"** in Impostazioni AI Content: pattern abilitati (anchor nel testo, bottone CTA, card con immagine, widget di raccolta), densità massima (default 1 inserimento ogni 300 parole), paragrafi iniziali protetti (default 2), link massimi nel widget, testo bottone di default, regole per le anchor. Un'unica fonte di verità per bozze manuali, runner programmato e agente.
- **Vocabolario dei pattern nel payload OpenAI**: l'AI ora conosce tutte le leve degli shortcode — `text=` per anchor naturali nella frase, `button="yes"` per CTA a fine sezione, `img+fields` per card prodotto — con le regole d'uso orientate alla conversione senza rompere l'eleganza della lettura.
- **QA deterministico post-generazione** (`ALMA_AI_Insertion_Rules::enforce`): densità applicata dal codice (le anchor in eccesso tornano testo semplice, gli altri pattern vengono rimossi), nessun inserimento nei paragrafi protetti, mai due shortcode consecutivi, massimo un widget per articolo. Testato standalone (12/12).
- **Widget creati dall'AI**: la bozza può includere il segnaposto `[[ALMA_WIDGET]]` e una `widget_request` (titolo, 2-N link candidati, testo bottone, titoli/descrizioni riscritti per-link nel tono dell'articolo); il plugin crea l'istanza reale nell'option standard dei widget (visibile e modificabile in Elenco Widget Link, con layout automatico in base al numero di link) e sostituisce il segnaposto con `[affiliate_links_widget id="X"]`. Solo link candidati del payload: ID inventati scartati.
- **Bozze dirette dall'agente di ideazione**: nuova casella "Crea subito anche le bozze" nel pannello agente — dopo la creazione delle idee genera immediatamente le bozze nel rispetto del **limite giornaliero di bozze automatiche** (stesso contatore del runner programmato); il report mostra le bozze generate con link di modifica e segnala quando il limite è raggiunto (le idee restanti restano in coda).
- Versione plugin aggiornata a `2.60.0`.

## 2.59.0 - 2026-07-05

### Storage OpenAI e Media Library per l'agente + pulizia impostazioni
- **Storage OpenAI (Vector Store)**: nuovo campo in Impostazioni → OpenAI API per l'ID del Vector Store con pulsante **"Verifica accesso"** (controlla via API che la chiave possa leggerlo e mostra nome, stato, file completati/totali e dimensione). Con l'ID configurato, l'**Agente di ideazione consulta lo storage con lo strumento ospitato `file_search`**: linee guida, brief e documenti caricati su OpenAI Platform entrano nel processo decisionale (il prompt di sistema lo istruisce a consultarli prima di decidere le idee).
- **Accesso ai media WordPress per l'agente**: nuovo strumento `cerca_media` che interroga l'indice media (solo metadati, immagini editoriali candidate) — l'agente verifica se un'idea ha già immagini utilizzabili nella Media Library e lo segnala nel prompt dell'idea.
- **Pulizia Impostazioni (audit coerenza)**: rimosso il tab **"AI Settings"** (conteneva solo due checkbox decorative disabilitate); il resto dei tab è coerente con le funzionalità attuali.
- **Pulizia Impostazioni AI Content**: nel tab **Reindicizza** rimosso il pannello segnaposto disabilitato "Reindicizza selezionati / Disponibile nella prossima fase" (restano le azioni reali, incluso l'indice link interni); nel tab **Stato/log** le card "Nessun dato" sono state sostituite da **contatori reali dei job** (in corso/completati/con errori, con avanzamento e conteggio errori per riga — la tabella jobs è ora alimentata dal runner delle idee programmate) e dal conteggio degli errori AI recenti.
- Versione plugin aggiornata a `2.59.0`.

## 2.58.0 - 2026-07-05

### Agente AI di ideazione (tool calling OpenAI)
- **Nuovo agente autonomo di ideazione** in "Tutte le idee": analizza i **dati reali del sito** e crea nuove idee contenuto motivate dai numeri. Funziona con il tool calling della Responses API di OpenAI (loop agentico, max 12 round): il modello decide quali strumenti usare, il plugin li esegue e gli restituisce i risultati.
- **Strumenti dell'agente** (tipizzati, in sola lettura + un'unica azione): `analizza_performance` (trend click 7/30/180gg, top link/articoli, località più cliccate — dallo snapshot della Dashboard), `trova_gap_geografici` (località con link senza click, con articoli senza link), `cerca_link_affiliati` (Knowledge Search con boost geografico), `elenca_articoli_esistenti` (anti-duplicazione) e `crea_idea` (unica azione permessa: crea l'idea con località risolta sull'indice geografico, keywords, prompt editoriale e data programmata — poi il runner esistente genererà la bozza nei limiti giornalieri).
- **Guard-rail**: l'agente non genera bozze e non pubblica mai; massimo idee per esecuzione (default 5) e massimo esecuzioni al giorno (default 2) configurabili; lock atomico anti-concorrenza; esecuzione in background (evento cron immediato); ogni chiamata OpenAI registrata nell'usage logger con costo stimato.
- **Pannello in "Tutte le idee"**: campo obiettivo opzionale (es. "concentrati sull'Italia"), pulsante Esegui agente, limiti configurabili e report dell'ultima esecuzione (idee create con link diretto al workspace, chiamate strumento, costo stimato, riepilogo dell'agente, eventuali errori).
- **Servizio OpenAI esteso** (retrocompatibile): supporto a `input_items` (input grezzo multi-turno della Responses API) e restituzione delle `function_calls` richieste dal modello; una risposta senza testo ma con tool call in sospeso non è più considerata errore.
- Le idee create dall'agente hanno origine `agent` e sono normali idee: modificabili nel workspace, programmabili, eliminabili.
- Versione plugin aggiornata a `2.58.0`.

## 2.57.0 - 2026-07-05

### Aggiungi idea — UI ridisegnata sul modello dell'editor Post
- **Header come nell'editor dei Post**: campo Titolo idea grande a tutta larghezza (con placeholder che chiarisce che ispira il titolo dell'articolo) e accanto solo le azioni essenziali: **Salva idea** (primario), **Crea bozza**, **Apri bozza** (se esiste) ed **Elimina a sola icona** con conferma. Rimosso "Crea nuova idea" (doppione della voce di menu Aggiungi idea); i download JSON sono negli Strumenti avanzati della sidebar.
- **"1. Cerca contenuti" ripulita**: ora contiene solo ciò che serve alla ricerca — campo "Cosa cerchi" e Località affiancati con pulsante "Cerca link" sulla stessa riga; una nota chiarisce che la ricerca serve **solo a trovare i link affiliati più coerenti**.
- **Prompt per OpenAI spostato nella sidebar** (fuori dall'area di ricerca): verificato che NON influenza la ricerca dei link — guida solo la stesura della bozza. Ora appartiene direttamente al form "Salva idea" (attributo `form`, senza sincronizzazioni JavaScript fragili) e la ricerca **non azzera più il prompt salvato**.
- **Profilo istruzioni AI spostato sopra "3. Sessione contenuto"** nella sidebar, anch'esso conservato con Salva idea.
- **Colonna "Idea attiva" eliminata** (le sue informazioni sono nell'header): lo spazio va alla colonna di ricerca, con **risultati su due colonne** per vedere e selezionare più link a colpo d'occhio.
- **Titolo idea → titolo articolo**: verificato che `idea_title` arriva già all'AI; ora il titolo di default "Nuova idea" non viene più inviato come titolo (fallback alla query) e una regola esplicita impone all'AI di ispirare il titolo dell'articolo al Titolo idea, usando la query di ricerca SOLO per la selezione dei link.
- Versione plugin aggiornata a `2.57.0`.

## 2.56.0 - 2026-07-04

### Idee sul modello "Post" + geolocalizzazione idee (Fase 2) + importazione massiva CSV programmata (Fase 3)
- **Menu ristrutturato sul modello dei Post**: dopo la Dashboard ora ci sono **"Tutte le idee"** (elenco) e **"Aggiungi idea"** (workspace); la voce "AI Content Agent" è stata rinominata **"Impostazioni AI Content"** e spostata prima di Impostazioni. La tab "Idee contenuto" è stata eliminata (redirect alla Dashboard per i vecchi link).
- **"Aggiungi idea"**: aprendo la pagina dal menu si parte con una nuova idea (l'ultima "Nuova idea" mai toccata viene riusata, per non accumulare bozze vuote); con `idea_id` si modifica un'idea esistente — è la destinazione di "Apri nel workspace" da Tutte le idee. I redirect delle azioni mantengono sempre l'idea corrente nell'URL.
- **Geolocalizzazione delle idee (Fase 2)**: nuovo campo **Località** nel workspace con autocomplete sull'indice geografico. Con una località impostata: i link affiliati della sua area (stessa località, omonimi, paese per località-nazione) ricevono un **boost dominante (+40)** nella ricerca e vengono **inclusi anche senza match testuale**; il payload OpenAI riceve un blocco `geo_context` che impone coerenza geografica alla bozza. Colonna Località in Tutte le idee.
- **Importazione massiva CSV con programmazione (Fase 3)**: pulsante "Importazione massiva (CSV)" in Tutte le idee → pagina dedicata con **download del CSV di esempio** (Titolo, Localita, Tema, Keyword principale, Keyword secondarie, Profilo istruzioni, Data programmata, Note AI; separatore , o ; autorilevato, max 500 righe). Ogni riga crea un'idea con località risolta sull'indice geografico (le non risolte vengono segnalate), keywords, profilo (per nome o ID) e data programmata; report di import con errori riga per riga.
- **Generazione automatica in background**: runner WP-Cron giornaliero (con partenza immediata dopo ogni import) che per le idee programmate in scadenza seleziona automaticamente i migliori link affiliati (geo-first + keyword), li salva come selezione dell'idea e genera la bozza con la pipeline esistente — entro un **limite di bozze/giorno configurabile (default 3)** per controllare i costi OpenAI. Lock anti-concorrenza via `add_option` atomica, esiti registrati nella tabella jobs e contatore giornaliero visibile nella pagina di import. Cron rimosso alla disattivazione.
- Retrocompatibilità: CPT e meta esistenti invariati (solo nuove meta), azioni admin esistenti riusate, vecchi URL della tab reindirizzati.
- Versione plugin aggiornata a `2.56.0`.

## 2.55.0 - 2026-07-04

### AI Content Agent — Fase 1: riorganizzazione (menu + pagina Elenco Idee)
- **AI Content Agent spostato subito dopo la Dashboard** nel menu del plugin, seguito dalla nuova voce "Elenco Idee".
- **Nuova pagina dedicata "Elenco Idee"**: tutte le idee contenuto in tabella con ricerca per titolo, filtri per stato (non eseguite / eseguite / con bozza) e autore (tutte / solo le mie), profilo istruzioni, numero contenuti selezionati, stato bozza collegata, paginazione. Azioni per riga: **Apri nel workspace** (carica l'idea e porta al tab Idee contenuto), **Genera bozza** (per idee con contenuti selezionati e senza bozza), **Elimina** (con conferma).
- **Tab "Idee contenuto" alleggerito**: ora è solo il workspace dell'idea attiva (ricerca contenuti, sessione, creazione bozza); l'elenco laterale con paginazione è stato sostituito da un link alla pagina Elenco Idee. Se non c'è un'idea attiva viene caricata automaticamente la più recente.
- Nessun cambiamento al modello dati (CPT `alma_content_idea` invariato): retrocompatibilità completa.
- Prima fase del piano di evoluzione dell'agente (seguiranno: geolocalizzazione idee, import CSV programmato, regole inserimento affiliati, bot Telegram, arricchimento in background).
- Versione plugin aggiornata a `2.55.0`.

## 2.54.0 - 2026-07-04

### Dashboard strategica — snapshot in background, trend, gap geografici, consigli AI
- **Nessuna query pesante al rendering**: tutte le elaborazioni (trend, top, gap geografici) girano **una volta al giorno via WP-Cron** (~03:30, con lock anti-concorrenza) e il risultato è salvato in uno snapshot; la Dashboard legge solo lo snapshot. Pulsante "Aggiorna ora" per ricostruirlo su richiesta; data/durata dell'ultima elaborazione sempre visibili.
- **Andamento click con grafici** (Chart.js): per giorno (30 giorni, barre), per settimana (26) e per mese (12, linea), con selettore del periodo. KPI 7/30/180 giorni con **confronto sul periodo precedente** (variazione % verde/rossa).
- **Top Link migliorato**: classifica per click negli **ultimi 30 giorni** (non più solo lo storico cumulato) con tipologia link e click storici a confronto.
- **Nuovo Top Articoli**: gli articoli che generano più click sui link affiliati che contengono. Il tracking ora registra il **post di provenienza** del click (nuova colonna `post_id` in `alma_analytics`, migrazione automatica; il frontend invia l'URL della pagina corrente — il solo referrer indicava la pagina precedente). I click storici restano validi ma senza attribuzione articolo.
- **Copertura geografica strategica**: località più cliccate (90 giorni); località **con link affiliati ma senza click** (offerta che non produce, con conteggio link e articoli); località **con articoli ma senza link affiliati** (contenuto non monetizzato). KPI "link senza click negli ultimi 90 giorni".
- **Consigli strategici AI su richiesta**: il riepilogo aggregato dello snapshot (nessun dato personale: IP e user agent non lasciano il sito) viene inviato al modello OpenAI configurato che restituisce 5 raccomandazioni prioritizzate con motivazione sui numeri; risultato salvato con data, modello e costo stimato. Mai chiamate automatiche.
- Retrocompatibilità: gli endpoint AJAX esistenti della dashboard restano invariati; il cron viene rimosso alla disattivazione del plugin.
- Versione plugin aggiornata a `2.54.0`.

## 2.53.0 - 2026-07-03

### Trova il tuo viaggio (ricerca a faccette) e integrazione con il tema (BeTheme)
- **Nuovo shortcode `[alma_trip_finder]`**: ricerca a faccette combinate sulle tassonomie degli articoli (su sothra.it: Dove, Come, Cosa, Perché, Quando, Durata). Il visitatore incrocia più dimensioni (es. Quando=Primavera + Durata=Weekend + Perché=Enogastronomia) e vede solo gli articoli che le soddisfano tutte — cosa impossibile con il menu attuale, una dimensione alla volta.
- **Contatori intelligenti**: ogni opzione mostra quanti articoli restano scegliendola (es. "Primavera (43)"), calcolati sulla selezione corrente delle altre faccette; le combinazioni senza risultati sono disabilitate. Le tassonomie gerarchiche (es. Dove: Italia → Puglia → Salento) sono indentate e un articolo taggato sulla foglia conta anche per gli antenati, senza doppi conteggi. Conteggi in cache 10 minuti, invalidata al salvataggio degli articoli.
- **Aggiornamento senza ricaricare la pagina**: risultati e contatori si aggiornano via AJAX, l'URL resta condivisibile (`?alma_f[dove]=…`); **senza JavaScript il form funziona comunque** con l'invio GET classico. Griglia risultati con miniature, chip dei filtri attivi rimovibili, paginazione.
- **Pagina impostazioni "Trova Viaggio"**: scelta delle tassonomie faccetta (tutte quelle pubbliche dei post, incluse le custom), pagina che ospita lo shortcode, categorie da escludere, articoli per pagina, attivazione chip.
- **Chip faccette negli articoli (opt-in, default disattivo)**: in cima a ogni articolo le sue faccette come chip cliccabili (es. "Dove: Algeria · Quando: Primavera") che portano alla pagina Trova Viaggio pre-filtrata (fallback: archivio del termine). Nessun cambiamento agli articoli finché non viene attivato.
- **Colore accento configurabile** (impostazioni Mappa Geografica): pulsanti ed evidenziazioni di popup mappa, pagina elenco e Trova Viaggio si allineano alla palette del tema (es. `#2E8CCB` per BeTheme di sothra.it). Default `#2271b1` invariato (retrocompatibile).
- **Integrazione BeTheme/Muffin Builder**: classi CSS stabili su tutti gli elementi del popup mappa (`alma-geo-popup-*`) e del Trova Viaggio (`alma-trip-finder__*`) personalizzabili dal Custom CSS del tema, come già avviene per gli altri componenti ALMA; nelle impostazioni istruzioni per l'inserimento degli shortcode in sezioni full-width del Builder.
- Versione plugin aggiornata a `2.53.0`.

## 2.52.0 - 2026-07-03

### Mappa Geografica — zoom di riempimento, popup rifinito, fix entità HTML
- **Zoom minimo calcolato da Leaflet**: al caricamento (e a ogni resize) la mappa calcola lo zoom al quale il planisfero riempie esattamente il contenitore — mai bande vuote, a qualunque larghezza/altezza, Europa correttamente centrata alla vista iniziale.
- **Fix redirect involontari**: il "secondo click sul marker" apriva la pagina dei risultati anche quando si voleva solo chiudere il popup; la navigazione ora avviene esclusivamente dal pulsante "Vedi tutti gli articoli →".
- **Popup**: rimosso il conteggio articoli sotto il nome della località; larghezza fissa (280px) così il caricamento di miniature e titoli non fa "saltare" la finestrella; pulsante di chiusura più evidente (cerchio grigio con X grande, rosso al passaggio del mouse).
- **Fix caratteri speciali**: titoli ed estratti con entità HTML (es. "dell&amp;#8217;Isola") ora vengono decodificati e mostrati correttamente ("dell'Isola") sia nel popup sia nella pagina elenco.
- Versione plugin aggiornata a `2.52.0`.

## 2.51.1 - 2026-07-03

### Mappa Geografica — planisfero ripetuto ed Europa al centro
- Ripristinata la ripetizione orizzontale del planisfero: con i confini rigidi le proporzioni del mondo singolo non riempivano i contenitori a larghezza piena (100%) e restavano bande vuote ai lati.
- Vista iniziale centrata sull'Europa (48°N, 10°E) invece che sull'equatore; il reset della ricerca torna alla stessa vista.
- Versione plugin aggiornata a `2.51.1`.

## 2.51.0 - 2026-07-03

### Mappa Geografica — mondo singolo, popup ridisegnato e "Consigliati"
- **Il planisfero non si ripete più**: tile con `noWrap`, confini rigidi sul mondo (`maxBounds` con viscosità piena) e zoom minimo 2 — niente copie dei continenti ai lati.
- **Popup articoli ridisegnato**: eliminato l'elenco puntato; ogni articolo è una riga cliccabile con **miniatura a sinistra** (48px, angoli arrotondati, placeholder 📄 se assente) e titolo in evidenza; pulsante "Vedi tutti gli articoli →" a bottone.
- **Riga "Consigliati" per località**: sotto il titolo della località, nel popup e nella pagina elenco, compare ad es. "🎯 Consigliati: 3 tour, 2 avventure" — il conteggio dei link affiliati associati a quella località raggruppati per Tipologia Link (max 6 tipologie, ordinate per quantità).
- Versione plugin aggiornata a `2.51.0`.

## 2.50.1 - 2026-07-03

### Fix Mappa Geografica — tile non visualizzate
- Fix mappa grigia con soli marker visibili: l'URL template delle tile passava per `esc_url_raw`, che rimuove le parentesi graffe dei placeholder — `https://tile.openstreetmap.org/{z}/{x}/{y}.png` diventava `.../z/x/y.png` e tutte le richieste tile andavano in 404. Nuova validazione dedicata che preserva `{z}/{x}/{y}`, impone https e blocca input pericolosi (fallback al default OpenStreetMap).
- Versione plugin aggiornata a `2.50.1`.

## 2.50.0 - 2026-07-03

### Mappa Geografica — motore Leaflet + OpenStreetMap (niente API key)
- La mappa frontend non usa più Maps JavaScript API (non utilizzabile): il rendering avviene con **Leaflet** (libreria open source BSD inclusa nel plugin, nessun CDN) e tile **OpenStreetMap** — nessuna chiave, nessun costo, nessun servizio Google nel frontend.
- Tutto il resto è invariato: shortcode `[alma_geo_map]` con width/height anche in %, ricerca località sopra la mappa, popup con articoli e link alla pagina elenco, categorie escluse, cache marker.
- Rimosso il campo API key browser dalla pagina impostazioni (il geocoding server-side con Google resta invariato); tile server personalizzabile per siti ad alto traffico con i filtri `alma_geo_map_tile_url` e `alma_geo_map_tile_attribution`.
- Zoom con rotella disattivato finché la mappa non riceve il focus, per non intrappolare lo scroll della pagina.
- Versione plugin aggiornata a `2.50.0`.

## 2.49.0 - 2026-07-02

### Mappa Geografica frontend (nuova)
- Nuovo shortcode `[alma_geo_map width="100%" height="600px"]`: mappa Google a vista mondo con marker sulle località che contengono **articoli geolocalizzati**; `width`/`height` accettano px, %, vh, vw, em, rem; `zoom` iniziale configurabile; `search="no"` per nascondere la ricerca.
- **Ricerca ampia sopra la mappa**: campo di ricerca sui nomi delle località (con suggerimenti), zoom e apertura automatica del popup sulla località trovata — nessuna API aggiuntiva, zero costi extra.
- **Click sulla località**: popup con i primi articoli e link "Vedi tutti gli articoli" verso la pagina elenco configurata; il secondo click sul marker naviga direttamente alla pagina. Nuovo shortcode `[alma_geo_location_articles per_page="20"]` per la pagina elenco: titolo località, conteggio, griglia articoli con immagine/estratto/data e paginazione.
- **Pagina impostazioni dedicata "Mappa Geografica"**: API key browser di Google Maps (separata da quella server del geocoding, con istruzioni per la restrizione per referrer), **categorie da escludere** (gli articoli in quelle categorie spariscono da marker ed elenchi), pagina elenco articoli, dimensioni di default.
- Le righe-località duplicate (import diversi) vengono unificate in un solo marker (raggruppamento per coordinate); i dati marker sono in cache 15 minuti, invalidata al salvataggio di articoli e impostazioni.
- Versione plugin aggiornata a `2.49.0`.

## 2.48.0 - 2026-07-02

### Modale "Inserisci Link Affiliato" — fix inserimento e filtro località
- **Fix "Errore durante l'inserimento"**: l'inserimento in Gutenberg poteva fallire per API blocchi non disponibili al momento del click e per il contenuto RichText dei paragrafi (WP 6.5+) trattato come stringa. Ora ogni strategia (Gutenberg → TinyMCE → textarea) è isolata con fallback a cascata, in Gutenberg lo shortcode viene inserito come blocco dedicato subito dopo il blocco selezionato, e gli script `wp-data`/`wp-blocks` sono dipendenze garantite nel block editor.
- **Niente più lavoro perso**: se nessun editor è raggiungibile, lo shortcode viene copiato negli appunti e mostrato nel messaggio, invece del solo errore.
- **Nuovo filtro "📍 Località"**: il modale elenca le località con link affiliati collegati (ordinate per numero di link) e filtra i risultati per area geografica — la località scelta viene espansa a righe omonime e, per i paesi, a tutte le località di quel paese.
- **Preselezione automatica**: se l'articolo in modifica è geolocalizzato, il filtro parte già sulla sua località primaria (con avviso e possibilità di rimuoverlo), mostrando subito i link della zona giusta.
- **Badge località nei risultati**: ogni link mostra la sua località primaria (📍 Città, Paese) accanto a tipologia, click e utilizzi.
- Versione plugin aggiornata a `2.48.0`.

## 2.47.0 - 2026-07-02

### Località — azioni corrette, sync degli stati e selezione massiva
- **Fix "Azione non eseguita: la località non è nello stato previsto"**: "Geocodifica" funzionava solo su località `pending` e "Riprova" solo su `failed`; ora geocodifica qualunque stato riprocessabile (pending, ambiguous, retry_later, failed, manual_required) e le località `verified` sono protette a meno dell'opzione di sovrascrittura.
- **Lo stato geocoding si applica sempre ai contenuti collegati**: la sincronizzazione scriveva solo `verified`; ora link affiliati e post riflettono l'esito reale (ambiguous, failed, retry_later inclusi) invece di restare "In attesa di geocoding" con la località già geocodificata.
- **Nuova azione "Conferma verificata"**: una località ambigua con coordinate e Place ID corretti (es. partial match di Google) si promuove a `verified` con un click e si sincronizza subito sui contenuti.
- **Selezione massiva delle località**: checkbox con "seleziona tutto" e azioni in blocco (Geocodifica / Conferma come verificate / Richiede verifica manuale) fino a 50 località per invio, disponibile sia nella tab Località sia in Impostazioni & Log.

### Widget Contestuale — matcher v5
- La penalità geografica (-30) scatta anche sui **nomi paese** quando i country code mancano (località non ancora geocodificate): un link "Parigi, Francia" in attesa di geocoding non può più comparire su un articolo localizzato in Algeria. Verificato che la data di pubblicazione del link NON ha alcun peso nello scoring: il sintomo era causato dal segnale geografico neutro delle località incomplete.
- `MATCHER_VERSION=5` invalida la cache dei risultati precedenti.
- Versione plugin aggiornata a `2.47.0`.

## 2.46.1 - 2026-07-02

### Widget Link Contestuale — coerenza geografica (matcher v4)
- Fix del caso segnalato: articolo "Maldive, Seychelles o Mauritius" (localizzato su interi paesi) mostrava esperienze di Parigi. Due cause: le località a livello paese non "contenevano" le città (un link su Malé prendeva solo il generico +10 e restava sotto soglia, e non entrava nemmeno tra i candidati), mentre i link geograficamente estranei risalivano con segnali testuali generici nonostante la penalità.
- Nuovo punteggio di **contenimento** (35 punti): articolo localizzato su un paese/regione + link con località in quel paese (per country code o, in mancanza, per nome) — vale anche al contrario (articolo su una città, link sul paese intero).
- **Penalità rafforzata a -30** per paesi dichiarati da entrambe le parti e disgiunti: un link di Parigi non può più superare la soglia 40 con soli segnali testuali generici (keyword+tipologia+contesto+click ≈ 48 − 30 = 18). I metadati incompleti restano neutri.
- I **candidati geografici** includono ora tutti i link con località nei paesi dell'articolo (match per country code e nome paese), non solo per ID/nome città.
- `MATCHER_VERSION=4`: i risultati in cache calcolati con la logica precedente vengono invalidati al deploy.
- Versione plugin aggiornata a `2.46.1`.

## 2.46.0 - 2026-07-02

### Geocoding automatico immediato
- Fix "Stato geocoding: In attesa" sui nuovi link importati: l'evento WP-Cron della coda veniva schedulato ma partiva solo alla pageview successiva (o mai, su siti a basso traffico). Ora l'evento nasce già scaduto e viene eseguito subito a fine richiesta con `spawn_cron` non bloccante.
- Watchdog su `admin_init` (throttle 2 minuti): se restano località pending senza drain in programma lo riarma, e forza lo spawn degli eventi in ritardo.
- Nuovo pulsante **"Geocodifica ora le località in attesa"** nella Panoramica: esegue subito un lotto in modo sincrono con report visibile; funziona anche con l'automatismo disattivato (richiede solo la API key).
- Avviso in Panoramica quando `DISABLE_WP_CRON` è attivo e ci sono località in attesa.

### Rimozione BotAffiliate Post
- Rimossa la sezione **BotAffiliate Post** (pagina impostazioni, metabox, popup frontend con chiamata OpenAI, asset JS/CSS): funzionalità non correttamente sviluppata. Le option `alma_bot_affiliate_*` e i meta salvati restano nel database ma non vengono più letti; nessun'altra funzionalità dipendeva dal modulo.
- Versione plugin aggiornata a `2.46.0`.

## 2.45.0 - 2026-07-02

### Geolocalizzazione completa dei link importati (Viator API e CSV)
- **Risoluzione destinazioni Viator**: i prodotti Viator espongono solo ref numerici di destinazione (es. `684`); il nuovo resolver scarica una volta il catalogo `/destinations` (cachato 30 giorni in option), risale l'albero città→regione→paese e salva sul link i meta `_alma_destination`, `_alma_viator_destination_name/region/country`. I link importati da Viator API vengono così geolocalizzati e geocodificati automaticamente come quelli GYG.
- **Fallback per i link Viator già importati**: durante l'indicizzazione automatica i ref nel `_alma_metadata_json` vengono risolti con il catalogo cachato, senza dover re-importare.
- **Regione e paese nel geocoding**: la colonna Regione del CSV GetYourGuide (`_alma_gyg_csv_region`) e i dati Viator arricchiscono la località (regione, paese e query di geocoding suggerita), migliorando la disambiguazione delle città omonime e la qualità del geocoding Google.
- `format_location_for_json` conserva ora `suggested_geocoding_query` lungo il flusso di salvataggio.
- Versione plugin aggiornata a `2.45.0`.

## 2.44.0 - 2026-07-02

### Indice Geografico — geocoding automatico e interfaccia razionalizzata
- **Niente più doppio passaggio associazione → geocoding manuale**: le località che restano `pending` dopo un'associazione (import CSV massivi, import da API, auto-indicizzazione, metabox, creazione post/link) vengono geocodificate automaticamente in background da una coda WP-Cron a lotti (20 per run), con lock condiviso con i batch manuali, backoff su rate limit e stop su `REQUEST_DENIED`.
- **Automatismo visibile e controllabile**: toggle "Geocoding automatico" nelle impostazioni (attivo di default, richiede API key); la tab Panoramica mostra stato, località in attesa, prossima esecuzione e ultimo report. I batch manuali restano come fallback.
- **Località dal metabox già verificate**: le località scelte tramite la ricerca Google (che arrivano con coordinate e Place ID) vengono salvate direttamente come `verified`, senza passare dalla coda.
- **Merge conservativo in upsert_location**: un re-import con dati meno completi non degrada più una località `verified` a `pending` azzerandone le coordinate (evitando ri-geocoding e costi ripetuti).
- **UI da 7 a 4 tab**: Panoramica (stato geocoding automatico + copertura + revisione + dashboard), Import (Link Affiliati e articoli con sotto-navigazione), Località, Impostazioni & Log (configurazione, strumenti manuali, log e ultimi report raggruppati). I vecchi slug delle tab restano come alias: nessun link o form esistente si rompe.
- Versione plugin aggiornata a `2.44.0`.

## 2.43.0 - 2026-07-02

### Indice Geografico — Copertura e indicizzazione automatica
- Nuova tab **Copertura** con contatori di indicizzazione geografica per articoli e Link Affiliati pubblicati (totale/indicizzati/non indicizzati/da rivedere) ed elaborazione batch AJAX interrompibile con cursore persistente e lock.
- Pipeline di associazione automatica a 3 livelli: meta provider (auto, confidenza alta), gazetteer sulle località conosciute (titolo univoco = auto; slug/heading/contenuto o ambigui = coda revisione), AI opzionale sui contenuti irrisolti (sempre in revisione, mai auto-applicata, costo tracciato nel log usage).
- Coda di revisione con conferma/scarto in blocco; le associazioni manuali non vengono mai sovrascritte; le località nuove nascono in `pending` geocoding.
- Auto-indicizzazione deterministica dei nuovi Link Affiliati importati e degli articoli pubblicati al salvataggio (elaborata a shutdown, dopo la scrittura dei meta provider).
- Colonna "Geo" e filtro "senza località / con località / in revisione" nelle liste admin di articoli e Link Affiliati.

### Widget Link Contestuale — fix matching geografico (matcher v3)
- Fix del caso "stessa città, metadati diversi": un articolo su Copenaghen (località geocodificata con paese DK) e link su Copenaghen importati senza country code non facevano match e subivano perfino la penalità "località diverse", tenendo il widget sotto soglia. Ora le città si confrontano per nome con paesi "compatibili" (paese sconosciuto = jolly) e la penalità -15 scatta solo quando entrambe le parti dichiarano paesi noti e disgiunti.
- I candidati geografici vengono trovati anche quando la stessa città esiste come righe località diverse (import differenti): il join confronta anche city/canonical_name, non solo il location_id.
- Fix invalidazione cache: le associazioni geografiche scritte da import GEO, auto-indexer e metabox ora invalidano la cache del widget (meta `_alma_geo_updated_at` nell'hash per gli articoli, bump globale per i Link Affiliati); prima il widget poteva servire per giorni risultati calcolati prima dell'associazione.
- Versione plugin aggiornata a `2.43.0`.

## 2.42.0 - 2026-07-02

### Widget Link Contestuale — matching contestuale reale
- Il Geo Index è ora il segnale dominante del matching: località condivise tra articolo e Link Affiliato valgono fino a 45 punti (città/località), 20 (regione), 10 (paese), con penalità per località esplicitamente diverse; senza dati geo il segnale è neutro e vale il matching testuale.
- I candidati sono selezionati per pertinenza (località condivise + keyword via indice affiliati AI + recenti come riempimento) invece dei soli ultimi 200 link per data.
- Scoring keyword graduato per quantità e rarità sul pool di candidati, con raddoppio per match nel titolo/heading; match solo a parola intera (niente più "roma" dentro "romantico").
- Cache invalidata per singolo articolo al salvataggio (bump globale solo per Link Affiliati e impostazioni); l'opzione "Escludi link già presenti = No" ora funziona (penalità lieve invece dell'azzeramento); i click storici pesano al massimo 3 punti.
- Aggiornata la descrizione nella pagina admin del Widget Contestuale con il nuovo funzionamento e il suggerimento di associare le località.

### Tracking click affidabile
- Incremento atomico del contatore `_click_count`: i click concorrenti non vengono più persi e le eventuali righe meta duplicate del primo click vengono ripulite automaticamente.
- L'opzione "Non tracciare utenti anonimi" è ora applicata anche lato server, non solo in JavaScript.
- Aggiunto filtro bot sullo user agent (mirato ai crawler noti, personalizzabile con il filtro `alma_is_bot_user_agent`) e rate limit breve per IP+user agent+link contro doppi eventi e replay.
- Header `X-Forwarded-For` multi-valore ora parsato correttamente (primo IP pubblico valido).
- La verifica della tabella analytics avviene una sola volta per versione (niente più `SHOW TABLES` a ogni click) e viene ritentata finché la creazione non riesce; gli insert falliti vengono loggati.
- Frontend: rimosso il tracking del tasto destro (gonfiava i conteggi), middle-click tracciato via `auxclick`, `MutationObserver` al posto del deprecato `DOMNodeInserted`, eventi Google Analytics non inviati per click rifiutati dal server.

### Fix bug
- La rimozione degli shortcode alla cancellazione di un link non tocca più i link con ID più lunghi (eliminare il link 12 non rimuove più lo shortcode del link 123); stesso fix per i widget.
- "Elimina per ID" nelle impostazioni ed eliminazione idee AI verificano il post type prima di `wp_delete_post`: non è più possibile cancellare definitivamente articoli o pagine per errore.
- Il widget WordPress `affiliate_links_widget` viene ora registrato correttamente (l'aggancio arrivava a `widgets_init` già eseguito e il widget non compariva mai).
- Rimosso l'evento cron giornaliero `alma_daily_optimization`, schedulato ma privo di handler; le occorrenze residue vengono ripulite automaticamente.
- Le colonne "Click" e "AI Score" nell'elenco Link Affiliati ora ordinano davvero, preservando il filtro Source attivo e includendo i link mai cliccati.
- Import GYG CSV: l'import non viene più marcato completato prima della fine del file; i job catturano anche errori fatali PHP e rilasciano sempre il lock.

### Sicurezza
- Le chiavi API possono essere definite in `wp-config.php` con `define('ALMA_OPENAI_API_KEY', '...')` e `define('ALMA_GEO_GOOGLE_MAPS_API_KEY', '...')`: hanno priorità sull'option e non passano dal database. La chiave OpenAI salvata via UI usa ora `autoload=no`.
- Corretto XSS DOM nella dashboard admin (titoli dei link iniettati senza escaping).
- Neutralizzata la formula injection (`=`, `+`, `-`, `@`) nei CSV esportati dal modulo GEO.
- Rate limit per utente (15/minuto, finestra fissa) e cache breve dei risultati sulla ricerca località Google del metabox.

### Modulo GEO — concorrenza e robustezza
- Claim atomico degli item staging con token univoco: due batch concorrenti non processano più gli stessi record.
- Lock anti-concorrenza sui batch di geocoding: elaborazioni parallele (due tab/utenti) non duplicano più le chiamate Google; la risposta bloccata riporta il numero reale di pending.
- `REQUEST_DENIED` è ora trattato come errore permanente di configurazione (API key non valida / API non abilitata) con messaggio esplicito e interruzione del batch, invece di `retry_later` fuorviante.
- Corretto il numero di format nell'insert degli item staging e il cap (ultime 500 righe) del report cumulativo di geocoding in user meta.

### Sottosistema AI
- `estimated_cost` è ora un costo reale in USD calcolato da una tabella prezzi per modello (estendibile con il filtro `alma_openai_model_prices`); i vecchi valori, che contenevano conteggi token, vengono azzerati una tantum per non falsare i totali.
- L'indice affiliati elimina la riga alla cancellazione definitiva del link invece di reindicizzarla (niente più record orfani).
- Il reindex della Knowledge Base rinfresca sempre i contenuti modificati di recente e in più avanza un cursore persistente di backfill sul resto del sito (prima indicizzava solo i 20 post più recenti per tipo).
- Lock anti-stampede sulla chiamata OpenAI frontend del Bot Affiliate: visitatori simultanei sulla stessa pagina non generano più chiamate API multiple.
- Whitelist dello stato documento TXT (`active`/`inactive`) nel toggle dell'AI Content Agent.
- Versione plugin aggiornata a `2.42.0`.

## 2.41.8 - 2026-06-08

- Aggiunto geocoding massivo delle località da Link Affiliati nella tab Geocoding, con sezione dedicata, progress bar, report cumulativo sessione corrente ed elaborazione interrompibile.
- Aggiunto processing batch controllato via AJAX admin-only con nonce, filtro `affiliate_links`, batch size massimo 50, timeout massimo 30 secondi, opzioni esplicite “Includi ambiguous” e “Riprova failed”.
- Aggiunti download protetti del report geocoding CSV e del log geocoding JSON, senza esporre la Google Maps API key.
- Aggiunto stop del ciclo massivo su quota/rate limit Google (`OVER_QUERY_LIMIT`, `REQUEST_DENIED`, HTTP 429), evitando retry immediati infiniti.
- Fix schema ibrido `row_number`/`csv_row_number`: gli insert staging popolano entrambe le colonne quando coesistono, mantenendo la riga CSV logica nei report/UI.
- Migliorata l’associazione delle località geocodificate ai Link Affiliati sincronizzando coordinate, provider, Place ID e formatted address sui meta GEO dei CPT collegati.
- Versione plugin aggiornata a `2.41.8`.

## 2.41.7 - 2026-06-07

### Fix GEO job items table SQL schema
- Fix sintassi SQL tabella `alma_geo_import_job_items`: la colonna fisica `row_number` è stata sostituita con `csv_row_number` nella `CREATE TABLE` e nell’indice `row_lookup` per evitare errori MySQL/MariaDB vicino a `row_number`.
- Fix falso positivo `dbDelta` “Created table”: il repair è riuscito solo se le tabelle esistono fisicamente dopo `SHOW TABLES LIKE` e non rimane un errore SQL bloccante in `$wpdb->last_error`.
- Migliorata diagnostica repair negli Strumenti avanzati con tabella richiesta, esistenza prima/dopo, risultato `dbDelta`, `$wpdb->last_error`, MySQL/SQLSTATE, varianti e messaggio operativo.
- Blocco prepare se lo schema GEO resta mancante dopo un repair difensivo, evitando insert staging destinati a fallire e senza toccare Affiliate Sources/GetYourGuide CSV.
- Versione plugin aggiornata a `2.41.7`.

## 2.41.6 - 2026-06-07

### Fix GEO schema repair for missing job items table
- Fix repair schema GEO quando `alma_geo_import_jobs` esiste ma `alma_geo_import_job_items` manca: il repair verifica fisicamente le tabelle con `SHOW TABLES LIKE`, richiama `dbDelta` e ricontrolla lo stato dopo la migrazione.
- Aggiunta diagnostica avanzata `dbDelta` negli strumenti avanzati con tabella richiesta, esistenza prima/dopo, risultato `dbDelta`, `wpdb->last_error`, eventuale errore MySQL/SQLSTATE, tabelle con nome diverso e messaggio operativo.
- Aggiunto controllo fisico delle tabelle prima di “Prepara import GEO”: l’import tenta un solo repair automatico e blocca la prepare se `alma_geo_import_job_items` resta mancante, evitando falsi successi e insert massivi falliti.
- Allineata la tabella staging GEO con `created_at`, `processed_at`, payload raw/normalizzato e indici su job, status, job/status, row lookup e creazione, senza drop e senza cancellare dati.
- Versione plugin aggiornata a `2.41.6`.

## 2.41.5 - 2026-06-07

### Fix GEO import database schema and staging inserts
- Fix creazione tabella staging GEO `alma_geo_import_job_items` con migrazione `dbDelta` idempotente per jobs e job items, senza drop e senza cancellare dati.
- Fix import GEO Link Affiliati che scartava tutte le righe per errore SQL quando la tabella staging mancava: ora lo schema viene verificato prima della prepare e l’import si blocca con messaggio chiaro se il repair fallisce.
- Aggiunto repair schema GEO in **Strumenti avanzati** con stato tabella jobs/job items, nonce, capability admin e pulsante “Ripara tabelle GEO”.
- Migliorata diagnostica SQL degli insert staging con tabella, operazione, ultimo errore aggregato ed esempi limitati.
- Fix doppio conteggio duplicati: i motivi scarto usano matching prioritario mutualmente esclusivo e `staging_duplicate_staging_item` non incrementa anche `duplicate`.
- Fix fallback località primaria: righe con `primary_city`, `primary_area`, `primary_poi`, `primary_port` o `primary_airport` ma senza `primary_name` non falliscono più con `missing_primary_name`.
- Versione plugin aggiornata a `2.41.5`.

## 2.41.4 - 2026-06-07

### Fix GEO CSV header normalization and staging creation
- Fix BOM/header CSV: preview e preparazione staging condividono la normalizzazione header con rimozione BOM UTF-8, spazi e caratteri invisibili.
- Fix creazione item staging GEO: il filtro safe import riconosce `safe_for_auto_import` boolean/stringa e `final_bucket=safe_import`, senza richiedere `primary_region`.
- Aggiunta diagnostica motivi scarto con ragioni normalizzate e ultimi 10 esempi completi.
- Fix export troncato: report CSV e log JSON usano export completo paginato fino a esaurimento righe.
- Fix pausa legacy: l’endpoint AJAX di pausa restituisce errore controllato nel workflow manuale e non segnala falso successo.
- Versione plugin aggiornata a `2.41.4`.

## Unreleased

- Fix creazione item staging GEO: preview e prepare import condividono normalizzazione header/alias CSV e la tabella staging riceve item `queued` per ogni riga processabile.
- Fix import che restava a 0/2212: una sessione con righe lette ma zero item processabili passa a `needs_review` con messaggio operativo invece di apparire pronta/successo.
- UI Import GEO semplificata in blocchi Carica CSV, Preview, Prepara import, Importazione, Report, Geocoding Google e Strumenti avanzati, con soli pulsanti principali necessari.
- Diagnostica righe scartate con motivi normalizzati (`missing_affiliate_link_id`, `invalid_affiliate_link_id`, `missing_affiliate_url`, `invalid_affiliate_url`, `missing_primary_location`, `missing_primary_name`, `missing_region`, `affiliate_link_not_found`, `object_not_affiliate_link`, `safe_import_false`, `existing_geo_skipped`, `duplicate_staging_item`, `sql_insert_failed`, `unknown_error`) ed esempi recenti.
- Export report CSV/log JSON completo senza troncamento silenzioso a 50.000 righe, usando paginazione controllata fino a esaurimento.
- Gestione corretta pausa legacy: endpoint AJAX con errore controllato nel workflow manuale, senza falso successo.
- Batch size preservato e inviato correttamente a ogni batch manuale.
- Versione plugin aggiornata a `2.41.4`.

## 2.41.0 - 2026-06-06
- Aggiunta la fondazione di geocoding admin-only per **Indice Geografico**, con tab Geocoding, impostazioni Google Maps API key mascherata, batch controllati e report in `alma_geo_geocoding_last_report`.
- Creati `includes/class-geo-index-geocoder.php` e `includes/class-geo-index-google-geocoder.php` per separare orchestrazione, provider Google, validazione risposta e salvataggio DB.
- Estesa `alma_geo_locations` con `formatted_address`, `address_components`, `geocoded_at` e `geocoding_error`, preservando dati esistenti via routine `dbDelta` idempotente.
- Gestiti gli stati `pending`, `verified`, `ambiguous`, `manual_required`, `failed` e `not_required`, senza sovrascrivere località già `verified` quando l’opzione è disattivata.
- Aggiornate tab Località e metabox Geolocalizzazione per mostrare/modificare coordinate, provider, Place ID, formatted address, stato, ultimo geocoding ed errori.
- Nessuna chiamata frontend, nessuna esposizione pubblica della API key, nessun uso AI e nessuna modifica a Widget Contestuale, shortcode, import, matching o tracking click.
- Versione plugin aggiornata a `2.41.0`.
- Chiarita la distinzione tra record Geo Index attivo e stato geocoding `pending`: i record importati correttamente vengono marcati come attivi e `pending` viene mostrato come “In attesa di geocoding”.

## 2.40.1 - 2026-06-06
- Corretto l’upload CSV del modulo **Indice Geografico** evitando il blocco MIME di WordPress su file `.csv` identificati come `text/plain`, `application/vnd.ms-excel`, `application/csv` o `application/octet-stream` valido.
- Aggiunta validazione dedicata solo al Geo Index per estensione `.csv`, MIME CSV consentiti, leggibilità, intestazioni obbligatorie e delimitatori `,`/`;`, senza filtri globali permanenti `upload_mimes` e senza salvataggio in Media Library.
- Migliorato il messaggio admin per file non CSV e documentata nella tab import la richiesta di intestazioni generate dal flusso Geo Index.
- Versione plugin aggiornata a `2.40.1`.

## 2.40.0 - 2026-06-06
- Aggiunto il modulo **Indice Geografico** come fondazione interna del plugin, con menu admin `alma-geo-index`, dashboard conteggi, import CSV safe, elenco località e log ultimo import.
- Create via `dbDelta` le nuove tabelle `alma_geo_locations` e `alma_geo_content_index`, senza rimuovere o modificare dati/tabelle esistenti.
- Aggiunto il metabox **Geolocalizzazione contenuto** su `post`, `page` e `affiliate_link`, con nonce, capability, check autosave/revision, sanitizzazione, post meta `_alma_geo_*` e sincronizzazione verso le tabelle Geo Index.
- Implementato l’import conservativo di `sothra_geo_article_index_safe_import.csv` per `post` e `page`: validazione header, preview primi 10 record, import solo se `safe_for_auto_import` è vero, gestione no-overwrite default e report in option.
- Nessun uso di AI, nessuna chiamata Google Maps API e nessuna modifica alla logica frontend del Widget Link Contestuale o agli shortcode esistenti.
- Versione plugin aggiornata a `2.40.0`.

## 2.39.1 - 2026-06-05
- Aggiunto il **Widget Link Contestuale** come widget WordPress opzionale per sidebar, attivo solo su singole `post`/`page` supportate e senza modifiche al contenuto degli articoli.
- Introdotta la pagina **Widget Contestuale** sotto il menu `affiliate_link`, con impostazioni globali WordPress-native, nonce, sanitizzazione, messaggio di salvataggio e pulsante **Svuota cache Widget Contestuale**.
- Creato il matcher locale senza AI che estrae segnali da titolo, slug, categorie, tag, contenuto, excerpt e heading H2/H3, valuta i CPT `affiliate_link` pubblicati con URL affiliato e ordina i risultati per score, click e titolo.
- Implementata cache per post con hash impostazioni, TTL configurabile e invalidazione tramite versione cache al salvataggio di articoli supportati, Link Affiliati, impostazioni e flush manuale.
- Il frontend mostra card minimali con immagine full/originale, titolo, descrizione e CTA, mantenendo URL/rel/target originali e click tracking con source `contextual_widget`.
- Documentati limiti MVP: candidati limitati a 200 Link Affiliati, fallback attivo `hide` e possibili evoluzioni con indice dedicato o fallback popular/manual/same_type.
- Versione plugin aggiornata a `2.39.1`.

## 2.39.0 - 2026-06-05
- Aggiunta la tab **Prompt Widget** in **Impostazioni - Affiliate Link Manager AI** con prompt configurabile, max output tokens, timeout richiesta, stato OpenAI e ultimi log `widget_link_rewrite`.
- Introdotta la riscrittura AI obbligatoria dei testi in **Crea Widget Link**: prima del salvataggio OpenAI riscrive titolo e descrizione dei Link Affiliati selezionati usando Contesto AI, istruzioni Source e Prompt Widget.
- Aggiunta `ALMA_Affiliate_Widget_AI_Rewriter`, che prepara payload compatti, usa `ALMA_OpenAI_Service::request()`, valida JSON Schema, registra log sintetici/diagnostici e blocca il salvataggio in caso di errore.
- Le riscritture vengono salvate solo nell'istanza widget nel nuovo campo `rewritten_links`, con `context_hash`, data e modello, senza modificare `post_title`, `post_content` o `_alma_ai_context` dei Link Affiliati.
- In modifica widget vengono inviati a OpenAI solo i nuovi Link Affiliati aggiunti, vengono mantenute le riscritture esistenti e vengono rimosse quelle relative ai link eliminati.
- Aggiornato il rendering frontend dei widget per usare titolo/descrizione riscritti quando presenti, mantenendo URL affiliato, immagine full/originale, CTA, link neri, click tracking e fallback legacy.
- Versione plugin aggiornata a `2.39.0`.

- Corretto il rendering immagini dei Widget Link: il builder passa sempre `img_size="full"` allo shortcode `[affiliate_link]`, usando l'immagine originale/full del Link Affiliato in tutti i preset.
- Corretto il blocco del browser che impediva ricerca, paginazione e selezione dei Link Affiliati prima dell'inserimento del titolo widget; il titolo viene validato solo su creazione/salvataggio finale.
- Introdotto `ALMA_Logger`, logger centralizzato con livelli `debug`, `info`, `warning` ed `error`, redazione automatica di API key, bearer token, header Authorization, URL con token e contenuti AI troppo lunghi. Il troncamento copre anche risposte AI annidate.
- Convertiti i log diretti più sensibili nelle aree OpenAI, AI draft builder, import GetYourGuide CSV, media index e internal link index in diagnostica controllata tramite logger.
- I log `debug` rispettano `WP_DEBUG`; nessun segreto viene salvato in database e non cambiano UI, schema DB, payload OpenAI o flussi di import.

## 2.38.0 - 2026-06-05
- Riprogettato il workflow **Crea Widget Link** e **Modifica Widget**: titolo, contenuto introduttivo, preset layout, testo CTA, ricerca link affiliati, ID manuali validati e riepilogo link selezionati.
- Rimossi dalla UI builder i toggle manuali di visibilità, i controlli avanzati desktop/mobile e il pulsante **Genera suggerimenti AI**; i nuovi widget salvano sempre immagine, titolo, contenuto e pulsante attivi.
- Introdotti i preset `columns_1` - `columns_6` con default `columns_3`, scelto perché offre un equilibrio leggibile tra densità desktop e semplicità per utenti non tecnici.
- Aggiunto il motore di ricerca paginato per `affiliate_link` pubblicati con keyword, filtro `link_type`, filtro fonte/provider disponibile, massimo 20 risultati per pagina e limite di 20 link per widget.
- Aggiornato il rendering frontend dei widget con preset per rispettare colonne responsive, forzare link neri e mantenere compatibilità con widget legacy e tracking click esistenti.
- Aggiunte sei anteprime SVG dei layout in `assets/` e stili admin per card layout, risultati ricerca, box link selezionati e badge layout.
- Corrette le review P2: `assets/admin.js` viene caricato anche nella pagina nascosta `admin_page_alma-edit-widget` e la rimozione link elimina gli ID sia da `links` sia da `manual_ids`.
- Rifinita la UX di **Crea Widget Link**: descrizione introduttiva, titolo obbligatorio, ricerca senza risultati iniziali, pulsante **Aggiungi selezionati al widget**, rimozione con Dashicon accessibile e shortcode subito visibile dopo la creazione.
- Il rendering frontend di `[affiliate_links_widget]` e del widget WordPress stampa il titolo in H3, poi il contenuto introduttivo solo se presente, quindi la griglia link affiliati.
- Versione plugin aggiornata a `2.38.0`.

## 2.37.0 - 2026-06-05
- Aggiunta la sezione visuale **Scegli layout widget** nelle pagine **Crea Widget Link** e **Modifica Widget**, con sei preset selezionabili e miniature SVG locali negli asset del plugin.
- I preset aggiornano i campi esistenti per colonne desktop/mobile e visibilità di immagine, titolo, contenuto e pulsante, mantenendo i controlli tecnici come opzioni avanzate.
- Introdotto il campo opzionale `layout_preset` nelle istanze widget, con deduzione automatica del layout più vicino per widget legacy privi del nuovo campo.
- La pagina **Elenco Widget Link** mostra il layout usato tramite una nuova colonna/badge, senza modificare il rendering frontend o gli shortcode esistenti.
- Versione plugin aggiornata a `2.37.0`.

## 2.36.6 - 2026-06-03
- Corretto il rendering duplicato delle progress bar GetYourGuide CSV, limitando il markup alla sola colonna **Progressi** della riga tipologia attività.
- Tradotto lo stato tecnico `not_started` in **Non importato** nel render PHP e negli aggiornamenti AJAX.
- Migliorata la leggibilità degli stati importazione e dei contatori progressivi senza modificare la logica di importazione.
- Versione plugin aggiornata a `2.36.6`.

## 2.36.5 - 2026-06-03
- Consolidato il workflow **Importa contenuti → GetYourGuide CSV / Deep Link** nella pagina `import_contents` con riepilogo tipologie attività, Mapping Sothra, progressi per riga e log importazione sotto la tabella.
- Rimossa dal workflow operativo la pagina `alma_view=gyg_csv_import_type`: i vecchi URL vengono reindirizzati in modo sicuro alla pagina principale preservando source, token e tipologia evidenziata.
- Spostato il **Mapping Sothra** direttamente nella tabella riepilogo: ogni Tipologia attività CSV può essere associata a una o più tipologie `link_type`, con persistenza per sessione/source/tipologia e fallback dai termini source.
- L’azione **Importa/continua** ora resta nella pagina principale, salva il mapping, crea o riprende un job background per la singola tipologia CSV, avvia il primo batch via AJAX e lascia WP-Cron proseguire a pagina chiusa.
- Corretta la review P2 sui selected rows fuori scope: i job GetYourGuide CSV sono limitati a una sola `activity_type_hash`, filtrano eventuali ID legacy misti, calcolano `total_records` solo sui record realmente importabili dal batch e mantengono coerenti processed/remaining.
- Aggiunti log sessione/job redatti in `alma_gyg_csv_import_logs` con eventi leggibili per mapping salvato, job creato/ripreso, batch, completamento, dedupe, record non validi ed errori import.
- Versione plugin aggiornata a `2.36.5`.

## 2.36.4 - 2026-06-01
- Migliorato il workflow **Importa contenuti → GetYourGuide CSV / Deep Link** con job background persistenti in `alma_gyg_csv_import_jobs`, batch sicuri, lock transient e continuazione via WP-Cron anche a pagina chiusa.
- Estesa la sezione **Sessioni CSV recenti** con link admin sicuro al file caricato, record totali, importati, restanti, stato importazione e ultimo aggiornamento calcolati da sessioni/progress/job persistenti.
- Semplificata la ripresa importazione CSV: la vista mostra file corrente, source corrente e il solo riepilogo delle tipologie attività, evitando step upload/configurazione già completati.
- Corretta la preview/importazione subset: i job salvano sessione, source, tipologia attività, filtri, selezione e criteri preview, così Importa/continua lavora solo sui record selezionati o filtrati e non su tutto il CSV.
- Aggiunta la colonna **Mapping Sothra** record-level nella tabella Risultati anteprima, con multi-select `link_type`, fallback da mapping tipologia/source e persistenza nel job prima dell’import.
- Aggiunta progress bar WordPress-native con conteggi processati/totali, importati, aggiornati, già presenti, saltati, errori e stato testuale del job.
- Versione plugin aggiornata a `2.36.4`.

## 2.36.3 - 2026-06-01
- Rimossa la funzionalità dismessa **Affiliate Chat AI** dal menu admin, dagli shortcode, dagli endpoint AJAX e dagli asset frontend.
- Rimosso lo shortcode `[affiliate_chat_ai]` senza fallback HTML.
- Rimosso l'endpoint AJAX dismesso `alma_affiliate_chat`, inclusa la registrazione pubblica `nopriv`, e rimossa la relativa logica di rate limit della chat pubblica.
- Eliminato l'asset dismesso `assets/chat-ai.js` e rimossi gli stili CSS usati solo dalla chat.
- Corretto `ALMA_Logger` per troncare payload OpenAI/AI annidati preservando il contesto del parent key, inclusi percorsi come `raw_response.output[].content[].text`, senza indebolire redazione secret e URL.
- Versione plugin aggiornata a `2.36.3`.

## 2.36.2 - 2026-06-01
- Rimossa la funzionalità **AI Trend Radar** dal bootstrap, dal menu admin, dalle install/upgrade routine e dalle esecuzioni programmate.
- Rimossa la funzionalità **Trend Idee contenuto** dal bootstrap, dal menu admin, dalla Bacheca WordPress, dalle install/upgrade routine e dalle esecuzioni programmate.
- Aggiunto cleanup sicuro dei WP-Cron legacy `alma_ai_trend_radar_run_profile` e `alma_trend_content_ideas_cron`, senza droppare tabelle o cancellare dati legacy.
- Corretta la duplicazione menu **Tipologie**/**Tipologie Link**: resta solo **Tipologie Link**, collegata alla schermata nativa della tassonomia `link_type` del CPT `affiliate_link`.
- AI Content Agent, Affiliate Source Manager e import affiliate non sono stati modificati funzionalmente.
- Versione plugin aggiornata a `2.36.2`.

## 2.36.1 - 2026-06-01
- Alleggerito il bootstrap principale spostando il caricamento asset admin/frontend in `includes/class-assets.php`, mantenendo invariati handle CSS/JS e localizzazioni esistenti.
- Spostata la registrazione/rendering degli shortcode in `includes/class-shortcodes.php`, mantenendo invariati `[affiliate_link]` e `[affiliate_links_widget]`; la chat AI è stata rimossa come funzionalità dismessa in `2.36.3`.
- Spostati gli endpoint AJAX editoriali `alma_search_links` e `alma_ai_suggest_links` in `includes/class-editor-ajax.php`, preservando nonce, capability e payload JSON.
- Spostato il widget Bacheca WordPress **AI Content Agent** in `includes/class-ai-content-agent-dashboard-widget.php`, senza modificare capability, ID widget o URL admin.
- Nessuna modifica a install/activation, CPT/tassonomie, schema DB, Affiliate Sources, AI Content Agent completo o Trend Radar.
- Versione plugin aggiornata a `2.36.1`.

## 2.36.0 - 2026-06-01
- Hardening tecnico degli endpoint AJAX admin/editoriali con controlli coerenti di nonce, capability, sanitizzazione input ed escaping dei payload JSON dove applicabile.
- Aggiunto rate limit configurabile per la chat AI pubblica, funzionalità poi rimossa/dismessa in `2.36.3`, con risposta JSON leggibile e logging redatto degli eventi bloccati.
- Introdotta validazione anti-SSRF centralizzata per URL esterni usati da provider Custom/Generic API e sideload immagini remote: solo HTTP/HTTPS, host obbligatorio, blocco localhost, loopback, IP privati e link-local, redirect limitati.
- Nessuna modifica ai flussi utente principali, shortcode, CPT/tassonomie o import CSV GetYourGuide server-rendered.
- Versione plugin aggiornata a `2.36.0`.

## 2.35.0 - 2026-05-10
- Corretto il bug delle metriche fonti Trend: `fonti_analizzate` non deriva più da `fonti_citate`; per `editorial_plan` senza `fonti_interrogate` il report usa lo snapshot runtime delle fonti incluse nella run.
- Le metriche distinguono fonti configurate, interrogate, citate, saltate, senza risultati, non raggiungibili e da verificare. `count_fonti_analizzate` resta solo per compatibilità come alias di `count_fonti_interrogate`.
- Il dettaglio report conserva tutte le fonti configurate/incluse nella run anche quando non citate e mostra un messaggio diagnostico per le fonti incluse ma prive di citazioni.
- Aggiunta la tab **Fonti Trend** con CRUD completo: aggiunta, modifica, duplicazione, attivazione/disattivazione, eliminazione sicura/soft delete, test accessibilità tecnico e test fonte AI esplicito.
- Esteso lo store fonti con campi diagnostici e gestionali (`enabled`, `status`, `area_geografica`, `notes`, `last_tested_at`, `last_test_status`, `last_test_message`, `last_result_count`, `last_citation_url`, `created_by`, `updated_at`) mantenendo seeding idempotente e senza sovrascrivere modifiche manuali.
- Stati fonte documentati in UI: Attiva, Disattivata, Da verificare, Funzionante, Nessun risultato recente, Non raggiungibile/bloccata, Dominio troppo generico, Dominio non coerente.
- Il test accessibilità tecnico usa solo richieste WordPress leggere verso i domini configurati, con timeout breve e senza OpenAI; il test fonte AI aggiorna la diagnostica solo su azione esplicita.
- Le run complete e i piani editoriali usano solo fonti abilitate e non in revisione; le fonti disattivate/inactive sono escluse, mentre una fonte specifica resta testabile manualmente.
- Report e dashboard mostrano la diagnostica fonti, incluse fino a 5 “Fonti da verificare” in dashboard leggendo solo dati salvati.
- Versione plugin aggiornata a `2.35.0`.

## 2.34.0 - 2026-05-10
- Arricchito il modulo **Trend Idee contenuto**: `source_test` resta diagnostico e compatto, `full_test` usa uno schema più ampio per report utili a Sothra, `editorial_plan` diventa il profilo operativo per pianificazione editoriale.
- `full_test` ora richiede, se i dati lo consentono, almeno 6 trend, 8 idee editoriali, 5 bisogni viaggiatori, 5 opportunità affiliate, 4 rischi/limiti e 6 citazioni, con warning espliciti quando le fonti non bastano.
- `editorial_plan` richiede 10-20 idee, 7-14 contenuti settimanali, 8-12 opportunità affiliate, 8-12 bisogni viaggiatori e destinazioni reali quando disponibili, includendo priorità, intento di ricerca, categoria editoriale, CTA/link affiliato e fonti collegate.
- Introdotte metriche fonti distinte: `count_fonti_configurate`, `count_fonti_interrogate`, `count_fonti_citate`, `count_fonti_saltate`, `count_fonti_senza_risultati`; `count_fonti_analizzate` resta per compatibilità come alias coerente di `count_fonti_interrogate`.
- Chiarita la differenza tra fonti configurate (salvate in WordPress), interrogate/richieste (passate alla run Web Search) e citate (con URL/citazioni nel report), con dettaglio per fonte nella tabella del report.
- Potenziata la box **Trend Idee contenuto** nella Bacheca WordPress: legge solo l’ultimo report salvato, mostra data/ora report, stato, modello, profilo runtime, metriche, top trend, destinazioni, idee, opportunità affiliate, bisogni, alert e link al report/generazione.
- Ogni report salvato conserva data creazione, stato, modello, profilo runtime, metriche, JSON tecnico, sintesi, idee, destinazioni, opportunità affiliate, bisogni viaggiatori, citazioni e alert.
- Migliorata la pagina report completa con sezioni per sintesi, metriche, fonti usate, trend, destinazioni/città, piano editoriale, bisogni, opportunità affiliate, rischi/limiti, fonti citate e JSON tecnico, con messaggi utili per sezioni vuote.
- Le destinazioni prioritarie non vengono più popolate automaticamente con titoli generici di trend: devono essere città, località, regioni, paesi, aree geografiche o luoghi reali emersi dalle fonti.
- Versione plugin aggiornata a `2.34.0`.

## 2.33.2 - 2026-05-10
- Aggiunto uno schema JSON compatto per il profilo `source_test`, separato dallo schema editoriale completo usato da `full_test`/`editorial_plan`.
- Documentata e rinforzata la distinzione tra contenuti informativi analizzati dalla fonte, trend restituiti e idee editoriali generate.
- Il `source_test` produce output diagnostico breve con limiti rigidi: massimo 2 trend, 2 idee contenuto, 3 citazioni, 3 warning, summary fino a 350 caratteri e descrizioni fino a 250 caratteri.
- Migliorata la gestione `truncated_output` quando OpenAI termina con `status: incomplete` o `finish_reason: max_output_tokens`, distinguendola dagli errori JSON genericamente malformati.
- Il retry `json_invalid_retry` ora usa prompt e schema compatti, `tool_choice: auto`, nessun `include`, mantiene `web_search` e conserva eventuali domini ammessi senza introdurre retry multipli.
- I report tecnici includono categoria, modello, response id, status, finish reason, max output tokens, attempt label, tool choice, include, profilo, raw excerpt limitato e warning leggibili sul retry compatto.
- Valori token consigliati per profilo: `source_test` circa 2200, retry compatto 1400-2200, `full_test` intermedio e `editorial_plan` più alto.
- Versione plugin aggiornata a `2.33.2`.

## 2.33.1

- Corretto il flag manuale del modello Trend: il valore legacy automatico `gpt-5.5` non viene più marcato come scelta manuale durante salvataggi ordinari della pagina impostazioni.
- Il valore legacy `gpt-5.5` viene ignorato in modo idempotente finché l’admin non inserisce intenzionalmente un modello Trend; la UI mostra il campo modello vuoto e una nota dedicata quando il legacy è ignorato.
- Rafforzato l’output JSON OpenAI del modulo Trend con Structured Outputs su Responses API tramite `text.format` JSON Schema, schema stabile e istruzioni esplicite solo-JSON.
- Aggiunto un singolo retry vincolato quando la risposta AI non è JSON valido o ha schema incompleto; il retry mantiene `web_search` e non reintroduce il vecchio tool preview.
- I report tecnici di errore JSON salvano metadati utili e un excerpt raw sanificato massimo 1500 caratteri, senza esporre chiavi API o dati sensibili.
- Mantenuti i fallback Web Search esistenti: senza `filters`, `tool_choice` da `required` ad `auto`, normalizzazione sampling, omissione temperature per GPT-5.x/reasoning e retry timeout alleggerito.
- Versione plugin aggiornata a `2.33.1`.

## 2.33.0 — Limiti contenuti e priorità fonti Trend Idee contenuto
- Aggiunto per ogni fonte Trend il campo persistente `max_contents_per_run`, configurabile da 1 a 10, con default 3 e valori iniziali conservativi: 4 per fonti prioritarie e 3 per fonti medie.
- Chiarito in UI e prompt che per “contenuti” si intendono risultati, pagine, articoli, comunicati, report o documenti informativi consultabili dalla ricerca web durante una singola analisi Trend; non indica articoli WordPress generati, bozze o idee editoriali finali.
- Resa editabile la priorità fonte in admin con valori 1 = alta, 2 = media, 3 = bassa e normalizzazione dei valori non validi.
- Il prompt OpenAI ora include `max_contents_per_run` per ogni fonte e istruisce Web Search a non superare il limite per singola fonte e singola run.
- I report Trend mostrano per ogni fonte nome, priorità, quantità contenuti configurata, domini consentiti e fonti consultate/citate quando disponibili.
- Impatto atteso: limiti più bassi riducono token, tempi di risposta e rischio timeout; valori consigliati 2 per fonti secondarie, 3 per fonti standard, 4 per fonti ad alta priorità, evitando valori oltre 5 salvo necessità specifiche.
- Versione plugin aggiornata a `2.33.0`.

## 2.32.2 — Fix compatibilità modelli OpenAI Trend Idee contenuto
- Corretto l’errore OpenAI `Unsupported parameter: temperature` omettendo automaticamente i parametri sampling non compatibili con modelli GPT-5.x/reasoning.
- Rimosso il default hardcoded `gpt-5.5` durante install/upgrade del modulo **Trend Idee contenuto**: se il modello Trend è vuoto viene usato il modello globale OpenAI.
- Aggiunto fallback conservativo `gpt-5.4-mini` solo quando non esiste alcun modello globale configurato.
- Centralizzata la normalizzazione del payload OpenAI e preservata l’eventuale configurazione `reasoning` solo sui modelli compatibili.
- Aggiunto retry singolo tracciato senza parametri sampling incompatibili dopo errori OpenAI di compatibilità modello.
- Aggiornata la UI Trend per mostrare il modello effettivo e la nota sull’omissione automatica di `temperature` con modelli GPT-5.x/reasoning.
- Preservato l’uso di `web_search`, i fallback senza `filters` e da `tool_choice: required` ad `auto`, e il salvataggio delle fonti/citazioni Web Search.

## 2.32.1 — Fix Trend Idee contenuto Web Search
- Corretto l’errore OpenAI `Unsupported parameter 'filters'` nel test fonti del modulo **Trend Idee contenuto**.
- Migrata l’integrazione Responses API del modulo da il vecchio tool preview a `web_search`, applicando `filters.allowed_domains` solo al nuovo tool compatibile.
- Aggiunta normalizzazione dei domini sorgente prima dell’invio a OpenAI, con deduplica, rimozione di protocollo/path/query/fragment e limite massimo dei domini ammessi.
- Aggiunto `include: ["web_search_call.action.sources"]` per salvare nel report citazioni e fonti Web Search consultate quando disponibili.
- Aggiunto fallback automatico: se OpenAI rifiuta `filters`, la stessa run viene ripetuta una sola volta senza filtro dominio e registra un warning nel report/log.
- Migliorati messaggi errore UI e log admin, mantenendo il dettaglio tecnico in una sezione collassabile del report.
- Aggiornata la lista dei modelli consigliati includendo `gpt-5.5`, senza bloccare modelli già salvati come `gpt-5.4`.
- Versione plugin aggiornata a `2.32.1`.

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
