# Endpoint principali

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

La documentazione completa (OpenAPI) è servita dall'API stessa, su `/api/docs`.

| Metodo e percorso | Descrizione |
| --- | --- |
| `POST /api/auth/login` | `{email, password}` → `{token, refresh_token}` oppure `{twoFactorRequired, challenge}` |
| `POST /api/auth/google` | `{idToken}` → come il login, più `isNewUser` |
| `POST /api/auth/2fa` | `{challenge, code}` → `{token, refresh_token}` (code = TOTP o codice di recupero) |
| `GET /api/me/security` | stato 2FA e Google |
| `POST /api/me/2fa/setup` · `enable` · `disable` · `backup-codes` | gestione della 2FA |
| `POST\|DELETE /api/me/google` | collega/scollega Google |
| `GET\|PUT /api/me/anagrafica` | anagrafica propria; il primo salvataggio completa il profilo |
| `GET /api/anagrafica/options`, `POST /api/anagrafica/codice-fiscale` | valori ammessi, calcolo del CF |
| `GET /api/geo/countries?q=`, `GET /api/geo/comuni?q=&storici=1` | autocompletamento |
| `GET\|POST /api/tenants/{id}/documenti` | lista (filtri `categoriaId`, `membroId`, `q`, `modo=significato`; `page`, `itemsPerPage`; `ordina` = creato\|titolo\|data, `verso`; totale nell'header `X-Total-Count`) / caricamento multipart |
| `POST /api/tenants/{id}/documenti/gruppo` | sui selezionati: `{azione: sposta, ids, categoriaId}` o `{azione: elimina, ids}`; stessi permessi delle operazioni singole, gli altri vengono saltati con il motivo |
| `GET\|PATCH\|DELETE /api/tenants/{id}/documenti/{docId}` | dettaglio (con testo OCR) / modifica / cancellazione |
| `GET /api/tenants/{id}/documenti/{docId}/file` | download (decifrato se necessario) |
| `POST /api/tenants/{id}/documenti/{docId}/ocr` | rimette in coda l'OCR |
| `GET /api/tenants/{id}/documenti/{docId}/simili` · `POST .../indice` | documenti simili (stesso riferimento o contenuto) / rianalizza il testo (solo non cifrati) |
| `GET\|POST\|PATCH\|DELETE /api/tenants/{id}/categorie[/{catId}]` | categorie (di sistema in sola lettura) |
| `GET\|POST\|PATCH\|DELETE /api/tenants/{id}/membri_famiglia[/{membroId}]` | membri della famiglia senza account |
| `GET\|POST\|PATCH\|DELETE /api/tenants/{id}/scadenze[/{sid}]` | scadenze (filtri `da`, `a`, `stato`, `documentoId`, `membroId`) |
| `POST /api/tenants/{id}/scadenze/{sid}/fatta` | segna fatta → `{scadenza, prossima}` |
| `POST /api/tenants/{id}/esportazioni` · `GET /api/esportazioni/{token}` | link monouso (24 h) / ZIP di tutti i documenti (solo proprietari) |
| `POST\|DELETE /api/tenants/{id}/eliminazione` | programma (nome + 2FA) / annulla l'eliminazione della famiglia |
| `POST /api/enti` · `GET\|PUT /api/enti/{t}/profilo` · `POST …/email/codice\|conferma` | registrazione e verifica email dell'ente |
| `GET /api/admin/enti?stato=` · `POST /api/admin/enti/{t}/decisione` | portale: integrazioni (supporto), approva e respingi (superadmin) |
| `GET /api/admin/salute` · `GET /api/admin/audit_logs?outcome=&action=` | salute e registro globale (tutto il team del portale) |
| `GET /api/admin/tenants[/{id}]` · `POST /api/admin/tenants/{id}/nuovo-proprietario` | famiglie ed enti, nuovo proprietario (supporto) |
| `GET /api/admin/utenti?q=` · `GET /api/admin/utenti/{id}` · `POST …/stato` | utenti: ricerca, dettaglio, blocco (supporto) |
| `GET /api/admin/team` · `PUT /api/admin/utenti/{id}/ruolo-piattaforma` | team del portale (superadmin) |
| `POST /api/admin/famiglie` · `POST /api/admin/enti` | gestione manuale: nuova famiglia, nuovo ente già verificato (superadmin) |
| `GET /api/admin/tenants/{id}/ruoli` · `POST /api/admin/tenants/{id}/membri` · `PUT\|DELETE …/membri/{mid}` | gestione manuale dei membri (superadmin) |
| `POST /api/admin/utenti/{id}/attivazione` · `GET /api/admin/enti/{id}/gruppi` · `POST /api/admin/enti/{id}/inviti-famiglia` | reinvio dell'attivazione, invito di una famiglia a un ente (superadmin) |
| `POST /api/auth/attivazione/anteprima` · `POST /api/auth/attivazione` | pubblici: prima password di un account creato dal portale |
| `GET /api/admin/procedure` · `POST /api/admin/procedure/{id}/identita\|annulla` | recuperi d'accesso e nuovi proprietari (supporto) |
| `POST /api/auth/recupero[/conferma]` · `POST /api/procedure/annulla` | pubblici: richiesta e conferma del recupero, annullamento dall'avviso |
| `GET /api/enti/{t}/anni` · `POST …/anni/{id}/passaggio` · `GET\|POST\|PUT\|DELETE …/sedi`, `…/gruppi`, `PUT …/gruppi/{id}/operatori` | struttura dell'ente |
| `GET\|POST\|PUT\|DELETE /api/enti/{t}/iscritti[/{id}]` · `POST …/iscritti/{id}/referenti` · `PUT\|DELETE …/referenti/{id}` · `POST …/iscritti/import` · `POST …/inviti` | iscritti, referenti, import CSV, inviti |
| `GET /api/collegamenti/anteprima` · `POST /api/collegamenti/accetta\|rifiuta` · `GET\|DELETE /api/tenants/{id}/collegamenti[/{cid}]` | collegamento lato famiglia (anteprima e rifiuto pubblici) |
| `POST /api/enti/{t}/richieste[/anteprima]` · `GET …/richieste[/{id}]` · `…/allegato` · `…/destinatari/{d}/file\|verifica` · `…/sollecito` · `…/zip` · `…/annulla` | richieste lato ente |
| `GET /api/tenants/{id}/richieste[/{d}]` · `…/allegato` · `POST …/risposta\|rifiuta` | richieste lato famiglia (`validoFino` se l'ente lo chiede) |
| `GET /api/link/{t}` · `POST …/codice\|verifica` · `GET …/richiesta\|allegato` · `POST …/risposta\|rifiuta` · `POST …/collega` | risposta senza account (pubblica con codice email; `collega` autenticato) |
| `GET\|POST\|DELETE /api/enti/{t}/modelli[/{id}]` · `GET /api/enti/{t}/documenti-in-scadenza` | modelli e regole, documenti in scadenza |
| `GET\|POST\|DELETE /api/tenants/{id}/import-sources[/{sid}]` | sorgenti di import (`import:manage`) |
| `POST /api/tenants/{id}/import-sources/{sid}/connect` · `sync` | URL di autorizzazione Google / sincronizzazione manuale (202) |
| `GET /api/import-sources/oauth/callback` | pubblico, ritorno da Google (vale solo con lo `state` monouso) |
| `POST /api/auth/refresh` | `{refresh_token}` → nuova coppia (token monouso, ruotato) |
| `POST /api/auth/logout` | `{refresh_token}` → invalida il refresh token |
| `POST /api/auth/register` | crea l'utente e il suo tenant privato (owner); 403 se la registrazione è chiusa |
| `GET /api/auth/registrazione` | pubblico: `{"aperta": true\|false}` (`REGISTRAZIONE_APERTA`) |
| `GET /api/me` | utente, membership e permessi effettivi per tenant |
| `GET /api/tenants`, `GET\|PATCH /api/tenants/{id}` | tenant |
| `GET\|PATCH\|DELETE /api/tenants/{id}/members[/{memberId}]` | membri |
| `GET\|POST\|PATCH\|DELETE /api/tenants/{id}/roles[/{roleId}]` | ruoli |
| `GET\|POST\|DELETE /api/tenants/{id}/invitations[/{invitationId}]` | inviti |
| `GET /api/invitations/preview?token=`, `POST /api/invitations/accept` | pubblici, il token fa da credenziale |
