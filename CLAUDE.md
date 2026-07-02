# Convenzioni di lavoro — Affiliate Link Manager AI

- **Ogni blocco di modifiche richiede sempre**: bump della versione del plugin (header `Version:` e costante `ALMA_VERSION` in `affiliate-link-manager-ai.php`), voce dedicata in `CHANGELOG.md` e `README.md` (in italiano, nuova sezione `## X.Y.Z - data` in cima), e una **pull request** verso `main`.
- Feature nuove: bump minor (2.42.0 → 2.43.0). Soli bugfix: bump patch.
- Mantenere la retrocompatibilità: nessuna rimozione di option/API esistenti, fallback quando si cambia comportamento, mai sovrascrivere dati inseriti manualmente dagli utenti.
- Documentazione e stringhe UI in italiano; text domain `affiliate-link-manager-ai`.
- Verificare sempre con `php -l` i file modificati; per la logica pura aggiungere test standalone con shim WordPress (vedi pattern usati per matcher e auto-indexer).
- Batch admin su grandi dataset: AJAX iterativi interrompibili con cursore persistente e lock via `add_option` atomica con TTL (pattern esistente in geocoding/auto-indexer), mai job invisibili.
