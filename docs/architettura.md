# Architettura del codice

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

## Componenti

- `backend/`: Symfony 7.4 + API Platform, PostgreSQL 16 con pgvector, JWT (Lexik) + refresh token (Gesdinet), worker Messenger, Ollama, Gotenberg.
- `frontend/`: app Flutter (web, Android, iOS) con GetX, package `cartellaviva`.
- `connettore-mac/`: app per macOS (Swift) che fa l'analisi dei documenti sul Mac della famiglia, con Ollama. Vedi [connettore-ai.md](connettore-ai.md).

## Struttura del codice

Backend e frontend sono organizzati per **dominio** (non per tipo di file): tutto
ciò che riguarda, per esempio, le scadenze sta in una cartella sola.

### Backend (`backend/src`)

| Dominio | Contenuto |
|---|---|
| `Account` | utente, login con password o Google, verifica in due passaggi, sessioni, anagrafica personale, comuni/stati, dispositivi e notifiche push |
| `Tenancy` | famiglie (tenant), membri, ruoli e permessi (catalogo, voter, `TenantAccess`), inviti, membri della famiglia, accesso d'emergenza, export e cancellazione |
| `Documenti` | documenti, categorie, cifratura dei file, anteprime, OCR, classificazione, condivisioni esterne |
| `Scadenze` | scadenze, promemoria, scadenze proposte dal testo dei documenti |
| `Import` | sorgenti Google Drive, collegamento OAuth, job di importazione |
| `Ente` | anagrafe dell'ente (anni, sedi, gruppi, iscritti, referenti), collegamenti con le famiglie, richieste, firme con codice, automazioni |
| `Pratiche` | pratiche da sbrigare: pagamenti, 730, ISEE; modelli YAML in `config/pratiche/`, analisi dei documenti (`EstrattoreVoci`, `TestoVoci`), banco di prova, esempi per misurare e allenare |
| `ConnettoreAi` | collegamento dei Mac della famiglia che fanno l'analisi (flusso a codice RFC 8628, token a portata ridotta, coda dei lavori con ritorno al server) |
| `Portale` | portale del team: gestione manuale, procedure di accesso, approvazione enti, idee |
| `Shared` | ciò che serve a tutti: audit, crittografia, contratti delle entità, errori di dominio, helper (posta, token, risposte con file, Doctrine) |

Dentro ogni dominio: `Entity/`, `Repository/`, `Enum/`, `Controller/`,
`ApiResource/` + `State/` (API Platform: risorse, provider, processor),
`Message/` + `MessageHandler/` (job asincroni), `Command/`, e i servizi.
`Kernel.php` e `Schedule.php` (job pianificati) stanno nella radice.

Convenzioni:

- **Errori**: i servizi lanciano errori di dominio (`Shared\Errore`: `DatiNonValidi`
  422, `Conflitto` 409, `NonTrovato` 404, `NonConsentito` 403, `NonPiuValido` 410,
  `TroppiTentativi` 429), tradotti in risposta HTTP da `ErroreDominioListener`.
  Le eccezioni HTTP restano solo nello strato HTTP (controller, processor).
  Con il debug spento (produzione) il messaggio arriva lo stesso a chi usa l'app, ma solo
  per gli errori 4xx creati in `src/` (`MessaggioErroreNormalizer`): quelli creati da
  Symfony e tutti i 5xx restano generici ("Not Found", "Internal Server Error").
- **Helper comuni**: `Shared\Mail\Posta` (email, layout comune: [email.md](email.md)), `Shared\TokenCasuale` (link e
  sessioni), `Shared\Http\RispostaFile` (download e anteprime), `Shared\Doctrine\PerId`
  (caricamento per id), `Shared\Doctrine\ElaborazioneIsolata` (job che lavorano
  un'entità alla volta).
- **Nuovo dominio**: aggiungerne la mappatura in `config/packages/doctrine.yaml` e,
  se ha risorse API, il percorso in `config/packages/api_platform.yaml`. Il catalogo
  dei permessi legge da solo ogni cartella `Entity/`.
- **Deploy**: i messaggi in coda (Messenger) contengono il nome completo della
  classe; se una classe di messaggio cambia nome o cartella, svuotare la coda prima
  di aggiornare il server.

### Frontend (`frontend/lib`)

| Cartella | Contenuto |
|---|---|
| `features/<area>/` | `account`, `famiglia`, `documenti`, `scadenze`, `import`, `ente`, `richieste` (lato famiglia e link pubblico), `emergenza`, `idee`, `portale`, `offline`, `aiuto`, `pratiche`, `connettore_ai`; ognuna con `api/`, `model/`, `controller/`, `view/` |
| `helpers/` | infrastruttura: client API (`helpers/services/api`), sessione, file, tema (`helpers/theme`: `TemaApp`, `ColoriApp`, `PreferenzeTema`) |
| `widgets/` | widget comuni dell'app |
| `layout/` | layout, barre, pagina 404 |
| `route/` | rotte |

Una schermata grande si divide in parti della stessa libreria (`part`) con estensioni private della classe di stato (esempio: `documenti_screen.dart` con `documenti_gruppo.dart` e `documenti_righe.dart`). La logica e le chiamate API delle schermate più ricche stanno in un controller con le letture sostituibili nei test (esempio: `EnteGruppiController`).

Componenti di base (`widgets/`): `Testo` e `stileTesto()` per i testi, `Scheda` e `Riquadro` per i riquadri, `Briciole` per il percorso della pagina, `Griglia` per affiancare le schede, `ModuloValidato` per i moduli con le regole dei campi. I colori dell'app si leggono con `context.colori` (estensione del tema), lo spazio attorno al contenuto con `spazioPagina`.

Da usare invece di riscrivere: `chiamaApi()` per le chiamate API (errori →
`ApiException`), `chiediConferma()` per le conferme, `mostraMessaggio()` per gli
esiti, `ColoreStato` per testi di errore/avviso/riuscita (leggibili anche nel tema
scuro), `UltimaRichiesta` per gli elenchi con filtri, `erroreCard`/`numeroCard`
(`widgets/schede.dart`). Il bordo dei campi è nel tema: non serve ripeterlo.

## Modello dei permessi

```
User ──< Membership >── Tenant
             │
             └── Role ──< role_permission >── Permission ("risorsa:azione[:scope]")
                  └──< role_permission_group >── PermissionGroup ──< Permission
```

- **Nessun permesso globale.** Un permesso vale solo attraverso una Membership **attiva** sul tenant della risorsa. Senza Membership l'accesso è sempre negato, qualunque ruolo si abbia altrove.
- **Ruoli di sistema** (`owner`, `member`, `viewer`, e `staff` solo per i tenant `azienda`): sono definiti in `config/packages/rbac.yaml`, hanno `tenant_id = NULL` e non si modificano via API. Ogni tenant può creare ruoli personalizzati (`POST /api/tenants/{id}/roles`).
- **Scope `:own` / `:all`.** Nel codice si chiede sempre il permesso senza scope, per esempio `isGranted('documento:write', $documento)`. Il voter concede se il ruolo ha `documento:write:all`, oppure se ha `documento:write:own` e l'utente è `owner_user_id` della risorsa.
- **Superadmin** (`ROLE_SUPER_ADMIN`): amministra tenant, membri, ruoli e audit di qualsiasi tenant, ma **non** ha accesso a documenti e scadenze delle famiglie.
- **Regole di dominio:**
  - c'è sempre almeno un owner attivo (409 se si prova a rimuovere, declassare o sospendere l'ultimo);
  - solo un owner tocca un altro owner;
  - nessuno concede un ruolo con permessi che non possiede.
- **Permessi letti dal DB a ogni richiesta** (il JWT contiene solo l'identità): una rimozione ha effetto subito, senza aspettare la scadenza del token.

### Aggiungere una risorsa (es. Documento)

1. L'entità usa `TenantOwnedTrait` e implementa `OwnedResourceInterface`: colonne `tenant_id` e `owner_user_id` NOT NULL. `TenantOwnershipGuard` verifica che l'owner sia membro attivo e impedisce di cambiare tenant.
2. I permessi `documento:*` sono già nel catalogo (`permissions.yaml`). Si possono spostare in `#[PermissionResource]` sull'entità.
3. Negli endpoint si chiama `isGranted('documento:read', $documento)` (o `TenantAccess::require`) e si filtrano le collection per Membership attiva.
4. `bin/console app:permissions:sync && bin/console app:roles:sync`

## Audit log

La tabella `audit_log` è append-only: un trigger PostgreSQL blocca UPDATE e DELETE. Viene scritta via DBAL, quindi la riga resta anche se la richiesta poi fallisce.

Cosa viene registrato:
- login (password o Google), secondo fattore e refresh, riusciti e falliti;
- attivazione e disattivazione della 2FA, collegamento Google, modifiche all'anagrafica;
- logout;
- ogni decisione **negata** del voter;
- ogni decisione concessa su risorse `sensitive`;
- modifiche a membership, ruoli, inviti e tenant, comprese quelle bloccate dalle regole di dominio.

Consultazione:
- `GET /api/tenants/{id}/audit_logs`, con permesso `audit:read`;
- `GET /api/admin/audit_logs`, solo superadmin.
