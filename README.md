# Affiliate Link Manager AI

Questo plugin gestisce link affiliati e ora include una procedura guidata per l'importazione di massa.

## Importare link
1. Vai su **Link affiliati → Importa link** nel pannello di amministrazione.
2. Prepara un file **CSV**, **XLSX** o **XML** con intestazioni nella prima riga. I campi obbligatori sono **Titolo** (`post_title`) e **URL affiliato** (`_affiliate_url`). Campi opzionali: **Rel** (`_link_rel`), **Target** (`_link_target`), **Title** (`_link_title`) e **Tipologia** (`link_type`, più termini separati da virgole). Puoi scaricare un file di esempio dalla pagina di importazione.

3. Carica il file, associa le colonne, scegli eventuali tipologie e verifica l'anteprima delle prime righe.
4. Conferma l'importazione. Al termine ti verrà mostrato un **ID importazione**: conservalo per poter eliminare tutti i link di quel batch in futuro.

### Eliminare link importati
Nella pagina di importazione puoi inserire l'ID fornito al termine dell'operazione per cancellare tutti i link creati in quell'import.

2. Carica un file CSV, XLSX o XML e mappa le colonne richieste (`post_title` e `_affiliate_url`).
3. Visualizza l'anteprima, controlla le tipologie selezionate e conferma per creare i link affiliati.


