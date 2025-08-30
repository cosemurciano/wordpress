# Affiliate Link Manager AI

Questo plugin gestisce link affiliati e ora include una procedura guidata per l'importazione di massa.

## Importare link
1. Vai su **Link affiliati → Importa link** nel pannello di amministrazione.
2. Prepara un file **CSV** o **TSV** con intestazioni nella prima riga. I campi obbligatori sono `post_title` e `_affiliate_url`. Campi opzionali: `_link_rel`, `_link_target`, `_link_title` e `link_type` (più termini separati da virgole).
3. Carica il file, associa le colonne e verifica l'anteprima delle prime righe.
4. Conferma l'importazione. Al termine ti verrà mostrato un **ID importazione**: conservalo per poter eliminare tutti i link di quel batch in futuro.

### Eliminare link importati
Nella pagina di importazione puoi inserire l'ID fornito al termine dell'operazione per cancellare tutti i link creati in quell'import.
=======
2. Carica un file CSV o TSV e mappa le colonne richieste (`post_title` e `_affiliate_url`).
3. Visualizza l'anteprima e conferma per creare i link affiliati.

