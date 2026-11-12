# CartellaViva

**L'AI che sbriga la burocrazia di casa senza portarsi via i tuoi documenti.**

## La missione

CartellaViva esiste per dimostrare che l'AI può semplificare la vita quotidiana senza chiedere in cambio i dati personali.

Legge i documenti di famiglie ed enti, li classifica, trova scadenze e importi: lo fa con modelli open weight eseguiti in locale, e nessun documento viene mandato a servizi di AI di terzi. È un'adozione consapevole dell'AI: utile, verificabile, con l'ultima parola alla persona.

Una missione vale quanto la sua verificabilità. Per questo il codice è aperto, con licenza AGPL-3.0, e ogni impegno qui sotto dice dove controllarlo nel codice.

## I quattro impegni

Lo stato è quello di questa versione del codice. Quando un impegno non è ancora mantenuto per intero, lo diciamo.

| | Impegno | Stato oggi | Dove si verifica |
|---|---|---|---|
| 1 | **Solo modelli open weight**, eseguiti sul server di CartellaViva in UE o sul tuo. Nessuna API di AI esterna. | Rispettato | Tutte le chiamate AI vanno a Ollama (`OLLAMA_URL`): `AiCategoryClassifier`, `AiEstrattoreDati`, `AiScadenzaSuggeritore`, `OllamaEmbedder`. |
| 2 | **Nessun addestramento** sui documenti degli utenti. | Rispettato | Il codice non contiene alcun addestramento: le correzioni restano nell'archivio della famiglia. |
| 3 | **Modelli dichiarati**: una pagina pubblica con nome, versione, licenza e compito di ogni modello; in app, per ogni dato estratto, quale modello e con che sicurezza. | In parte | Il modello usato per i dati estratti e per la ricerca è registrato per ogni documento (`documento_indice.modello_dati`, `modello_embedding`); i modelli consigliati e le loro licenze sono in [THIRD_PARTY.md](THIRD_PARTY.md). Da fare: la pagina pubblica, e modello e sicurezza visibili nell'app. |
| 4 | **Codice aperto**, così chiunque può verificare i tre punti precedenti. | Rispettato | Questo repository, licenza AGPL-3.0. |

## Cosa vuol dire "terzi"

Con il **self-host** (CartellaViva sul tuo server, con Ollama) nessun terzo vede i documenti: è il cuore della missione, non un regalo.

Nel servizio cloud, in preparazione in UE, restano il fornitore dell'infrastruttura (oggi Microsoft Azure), come responsabile del trattamento, e Apple o Google per gli acquisti e per i token delle notifiche, senza contenuti. La promessa corretta è quindi: **nessun dato a terzi per AI, analisi o pubblicità**.

Due funzioni facoltative parlano con Google solo se le configuri e le usi: l'accesso con Google e l'import da Google Drive.

Sulla cifratura serve precisione. I documenti delle categorie sensibili sono cifrati a riposo, con una chiave per documento, e il server li legge in memoria per OCR e classificazione. Si può dire "l'AI gira sul server, nessun servizio esterno vede i documenti", non "neanche il server può leggerli".

## Come lavora l'AI

1. Il documento arriva dall'app (caricamento o scansione) o da Google Drive. Se la categoria è sensibile, viene cifrato prima di arrivare allo storage.
2. Un OCR open source (Tesseract) legge il testo; un modello open weight via Ollama propone categoria, data, mittente, importi e scadenze.
3. L'elaborazione avviene in coda, non in tempo reale: con modelli piccoli basta una CPU, la GPU serve solo quando l'attesa lo richiede.
4. Le scadenze trovate nei documenti e le categorie proposte sui documenti selezionati si confermano prima di diventare definitive; la data del documento, se manca, e la categoria dei documenti importati da Google Drive con l'opzione "Categoria scelta leggendo il contenuto" si applicano subito e si correggono quando serve.
5. Le correzioni restano nell'archivio della famiglia e non addestrano nessun modello.

## Licenza e marchio

- **Codice, server e app: AGPL-3.0-or-later** ([LICENSE](LICENSE)). È la licenza che tiene verificabile la missione anche nei servizi derivati: chi offre CartellaViva come servizio in rete deve rendere disponibile il codice, con le sue modifiche. L'app mostra il link al codice sorgente nel menu dell'account; chi pubblica una versione modificata lo fa puntare al proprio repository con `--dart-define=SOURCE_URL=...`.
- **Contributi**: per accettare contributi esterni servirà un accordo di licenza del contributore (CLA), in preparazione. Finché non c'è, le pull request esterne non vengono accettate; segnalazioni e proposte sono benvenute come issue. Vedi [CONTRIBUTING.md](CONTRIBUTING.md).
- **Sicurezza**: le vulnerabilità si segnalano in privato, non con una issue pubblica. Vedi [SECURITY.md](SECURITY.md).
- **Marchio**: il nome CartellaViva non è coperto dalla licenza del codice. Il codice è libero, il nome no: un fork deve chiamarsi in un altro modo.
- **Componenti di terze parti** e loro licenze: [THIRD_PARTY.md](THIRD_PARTY.md).

Copyright (C) 2026 Fabio Giorgione.

## Stato del progetto

Versione **0.1.0**, prima pubblicazione. Il codice si installa e si prova in sviluppo, con i passi qui sotto. Per installarlo su un proprio server, con HTTPS: **[docs/installazione-server.md](docs/installazione-server.md)** (prima versione, provata su un Mac e non ancora su un server vero). Cosa manca è scritto in [Limiti noti e da fare](#limiti-noti-e-da-fare).

## Per chi sviluppa

- `backend/`: Symfony 7.4 + API Platform, PostgreSQL 16 con pgvector, JWT (Lexik) + refresh token (Gesdinet), worker Messenger, Ollama, Gotenberg.
- `frontend/`: app Flutter (web, Android, iOS) con GetX, package `cartellaviva`.

## Avvio (sviluppo)

Serve Docker (Docker Desktop su Mac e Windows, Docker Engine su Linux). Il modo più semplice è lo script:

```bash
git clone https://github.com/fabiogiorgione/cartellaviva.git
cd cartellaviva
bash scripts/setup-sviluppo.sh            # con --modello scarica anche i modelli AI in Docker (~3,7 GB)
```

Lo script crea i segreti se mancano, avvia i container, installa le dipendenze, applica le migrazioni e carica i dati di prova. Rilanciato, riporta il database allo stato iniziale: **cancella i dati di sviluppo**. Guida passo-passo, con Flutter in debug e gli utenti di prova: **[docs/avvio-sviluppo.md](docs/avvio-sviluppo.md)**.

**Ogni installazione ha i suoi segreti.** Nel repository non ce n'è nessuno. In `backend/.env` le variabili `APP_SECRET`, `JWT_PASSPHRASE` e `APP_ENCRYPTION_KEY` sono vuote di proposito: se mancano, l'API risponde con un errore 500. Ognuno li genera casuali sulla propria macchina: lo script lo fa da solo, a mano si usano i comandi del passo 1 qui sotto. I file che li contengono (`secrets/`, `backend/.env.local`, `backend/.env.test.local`) sono esclusi da git: non vanno mai condivisi, copiati da altri o committati. Della master key va fatto il backup ([docs/gestione-chiavi.md](docs/gestione-chiavi.md)): senza, i documenti cifrati diventano illeggibili.

Gli stessi passi, a mano:

```bash
# 1. segreti di questa installazione (una volta sola)
mkdir -p secrets && chmod 700 secrets
(
  umask 077
  # master key dei documenti sensibili: FANNE IL BACKUP (docs/gestione-chiavi.md)
  printf '# CartellaViva master key\n1:%s\n' "$(head -c 32 /dev/urandom | base64)" > secrets/master_key
  {
    echo "JWT_PASSPHRASE=$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 40)"
    echo "APP_ENCRYPTION_KEY=$(head -c 32 /dev/urandom | base64)"
    echo "APP_SECRET=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  } > backend/.env.local
  # i test usano la stessa passphrase della chiave JWT
  grep '^JWT_PASSPHRASE=' backend/.env.local > backend/.env.test.local
)

# 2. container: api (php), worker (OCR, import, classificazione), database, mailer, ollama, convertitore
docker compose up -d --build
docker compose exec php composer install --ignore-platform-req=php     # dipendenze PHP: vendor/ non è nel repository

# 3. chiavi JWT, database, dati di prova
docker compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists
docker compose exec php php bin/console doctrine:migrations:migrate -n
docker compose exec php php bin/console app:permissions:sync
docker compose exec php php bin/console app:roles:sync
docker compose exec php php bin/console app:categorie:sync  # categorie di sistema
docker compose exec php php bin/console app:geo:import      # stati e comuni (ISTAT/Belfiore)
docker compose exec php php bin/console doctrine:fixtures:load -n \
  --purge-exclusions=audit_log --purge-exclusions=crypto_key_version --purge-exclusions=messenger_messages
docker compose restart worker

# 4. modelli AI (facoltativi; su Mac vedi sotto)
docker compose exec ollama ollama pull qwen3:4b-instruct-2507-q4_K_M   # modello di OLLAMA_MODEL (~2,5 GB)
docker compose exec ollama ollama pull bge-m3                           # modello di OLLAMA_EMBED_MODEL (~1,2 GB)
```

- Finché le dipendenze e le migrazioni non ci sono, il worker si riavvia con errori nei log (`Dependencies are missing`, `relation "messenger_messages" does not exist`): è normale, dopo il passo 3 riparte da solo.
- Se i segreti sono stati aggiunti dopo aver creato le chiavi JWT, le chiavi vanno rigenerate con la nuova passphrase: `docker compose exec php php bin/console lexik:jwt:generate-keypair --overwrite -n`.
- **Su Mac conviene Ollama nativo**: in Docker Ollama non vede la GPU e satura la CPU. Sul Mac usa Metal: con `qwen3:4b` su un M3 passa a circa 27 token/s.
  ```bash
  brew install ollama && brew services start ollama
  ollama pull qwen3:4b-instruct-2507-q4_K_M && ollama pull bge-m3
  echo 'OLLAMA_URL=http://host.docker.internal:11434' >> backend/.env.local
  docker compose restart php worker && docker compose stop ollama
  ```
- Facoltativo: un file `.env` accanto a `compose.yaml` cambia nomi e porte senza toccare il repository: `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `MASTER_KEY_FILE` (predefinito `./secrets/master_key`), `API_PORT` (predefinita 8090), `MAILPIT_PORT` (8025), `FRONTEND_URL`, `CORS_ALLOW_ORIGIN`. Su Linux aggiungi `UID` e `GID` (i valori di `id -u` e `id -g`, predefiniti 1000) così i container scrivono in `backend/` con il tuo utente. Dopo una modifica: `docker compose up -d`.
- Le fixture reimportano anche stati, comuni e categorie, perché la purge svuota quelle tabelle.
- La purge non cancella i file già presenti nel volume `document_storage`: in sviluppo, se serve, si ricrea il volume.

| Servizio | URL |
| --- | --- |
| API + documentazione OpenAPI | http://localhost:8090/api/docs |
| Mailpit (email degli inviti) | http://localhost:8025 |

Frontend (verificato con Flutter **3.44**):

```bash
cd frontend
flutter run -d chrome --web-port 5000 \
  --dart-define=API_BASE_URL=http://localhost:8090/api \
  --dart-define=GOOGLE_WEB_CLIENT_ID=xxx.apps.googleusercontent.com   # facoltativo, vedi "Accesso con Google"
```

**Su Mac la porta 5000 è spesso occupata** dal Ricevitore AirPlay di macOS, e `flutter run` si ferma con "Address already in use". Puoi disattivarlo nelle Impostazioni di Sistema (cerca "AirPlay"), oppure usare un'altra porta, ad esempio `--web-port 5050`. In quel caso scrivila anche nel file `.env` accanto a `compose.yaml` (`FRONTEND_URL=http://localhost:5050`) e poi lancia `docker compose up -d`, perché è l'indirizzo dei link nelle email. Il CORS di sviluppo accetta già qualsiasi porta di `localhost`.

Utenti delle fixture, tutti con password `CartellaViva!2026`:

| Email | Ruolo |
| --- | --- |
| `admin@cartellaviva.local` | superadmin (nessuna membership) |
| `mario.rossi@example.com` | owner di "Famiglia Rossi" |
| `giulia.rossi@example.com` | member di "Famiglia Rossi" |
| `nonno.rossi@example.com` | viewer di "Famiglia Rossi" |
| `luca.bianchi@example.com` | owner di "Famiglia Bianchi" (non vede i Rossi) |
| `nuovo.utente@example.com` | nessun tenant e anagrafica vuota: serve a provare l'onboarding |
| `segreteria@scuola.example.org` | owner (segreteria) di "Scuola Primaria Demo", ente verificato |
| `maestra.bianchi@scuola.example.org` | operatrice della 3ªB della scuola di demo |

Tutti tranne l'ultimo hanno un'anagrafica completa, con codice fiscale calcolato.

Superadmin reale: `bin/console app:user:create-superadmin tua@email.it`

Test:

```bash
docker compose exec php php bin/phpunit    # backend: il DB di test viene ricreato dalle migration
# copertura: docker compose exec php php -d pcov.enabled=1 bin/phpunit --coverage-text
# (pcov è spento di default: acceso nel server web lo manda in crash sotto carico)
docker compose exec php vendor/bin/phpstan # backend: analisi statica
cd frontend && flutter analyze && flutter test   # frontend
```

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
- **Helper comuni**: `Shared\Mail\Posta` (email), `Shared\TokenCasuale` (link e
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
| `features/<area>/` | `account`, `famiglia`, `documenti`, `scadenze`, `import`, `ente`, `richieste` (lato famiglia e link pubblico), `emergenza`, `idee`, `portale`, `offline`, `aiuto`; ognuna con `api/`, `model/`, `controller/`, `view/` |
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

## Accesso: password, Google, verifica in due passaggi

```
password ─┐                       ┌─ 2FA non attiva ─> token JWT + refresh token
          ├─> primo fattore OK ───┤
Google  ──┘                       └─ 2FA attiva ─────> challenge (5 min, max 5 tentativi)
                                                         └─ POST /api/auth/2fa {challenge, code} ─> token
```

### Verifica in due passaggi (TOTP)

È compatibile con Google Authenticator, Microsoft Authenticator e app analoghe (SHA1, 6 cifre, 30 s).
- **Attivazione:** dalla pagina "Sicurezza" si scansiona un QR code e si conferma con il primo codice. Vengono poi mostrati **10 codici di recupero** monouso.
- **Protezioni:**
  - il segreto è cifrato a DB con libsodium, chiave `APP_ENCRYPTION_KEY`;
  - un codice già usato non vale una seconda volta (anti-replay);
  - la challenge vive 5 minuti, con al massimo 5 tentativi;
  - disattivare la 2FA o rigenerare i codici richiede un codice valido.
- **Senza sessione:** l'API è stateless (JWT), quindi la challenge è salvata a DB: funziona anche con più istanze.
- **Refresh:** il refresh token si ottiene solo dopo il secondo fattore, quindi i refresh successivi non lo richiedono di nuovo.

### Accesso con Google

L'app ottiene un ID token da Google Sign-In e il backend lo verifica: firma con le chiavi pubbliche di Google, emittente, audience tra `GOOGLE_CLIENT_IDS`, scadenza, email verificata.

Il collegamento all'account avviene così:
1. l'account è già collegato (stesso `sub` Google) → login;
2. esiste un account con la stessa email (verificata da Google) → collegamento automatico. Se quell'account non aveva mai verificato l'email, chi l'aveva registrato perde password, 2FA e sessioni: la casella è di chi ha Google;
3. nessun account → nuovo utente senza password e senza tenant. Al primo accesso l'app porta alla pagina **"Completa il tuo profilo"**; al salvataggio dell'anagrafica viene creato lo spazio "Famiglia (cognome)". Con la registrazione chiusa (vedi sotto) questo caso è rifiutato con 403.

Se l'account ha la 2FA attiva, anche con Google serve il secondo fattore.

Configurazione (Google Cloud Console → API e servizi → Credenziali):
1. schermata di consenso OAuth;
2. client **Applicazione web**: origini JavaScript autorizzate `http://localhost:5000` (sviluppo) e il dominio di produzione. Il suo ID va in `GOOGLE_WEB_CLIENT_ID` (frontend) e in `GOOGLE_CLIENT_IDS` (backend);
3. client **Android** (package + SHA-1 del keystore) e **iOS** (bundle id). L'ID iOS va in `GOOGLE_IOS_CLIENT_ID` (frontend); gli ID Android/iOS si aggiungono a `GOOGLE_CLIENT_IDS` solo se compaiono come `aud` del token.

Sul web Google impone il proprio pulsante; su Windows e Linux il plugin non è supportato e il pulsante non compare.

### Registrazione aperta o chiusa

`REGISTRAZIONE_APERTA` in `backend/.env` vale `1` (aperta): va bene in sviluppo. **Su un server raggiungibile da internet va messa a `0`**, altrimenti chiunque trovi l'indirizzo può crearsi un account e caricare file.

Con la registrazione chiusa:
- `POST /api/auth/register` risponde 403, anche per un'email già registrata, così non si scopre quali account esistono;
- l'accesso con Google entra solo negli account che esistono già: un'email sconosciuta riceve 403 e non viene creato nessun utente;
- l'app chiede lo stato a `GET /api/auth/registrazione` (pubblico, `{"aperta": false}`): nasconde "Registrati" e, se si apre la pagina di registrazione, spiega che si entra solo su invito;
- si entra **accettando un invito in famiglia** (crea l'account) o con un account **creato dal portale**: "Nuova famiglia" in "Famiglie ed enti", che manda il link di attivazione al proprietario.

Il primo account di un server nuovo, con la registrazione chiusa:
1. `docker compose exec php php bin/console app:user:create-superadmin tua@email.it`: l'amministratore della piattaforma, che non vede i documenti delle famiglie;
2. come superadmin, dal portale, "Nuova famiglia" con l'email di chi ne sarà proprietario. Deve essere un'email diversa da quella del superadmin, perché gli account del team del portale non possono essere membri di una famiglia;
3. il proprietario attiva l'account dal link e invita gli altri familiari.

Limite: chi riceve un invito d'emergenza o il collegamento di un ente e non ha ancora un account non può crearlo finché la registrazione è chiusa. Prima va invitato in una famiglia, oppure gli si crea l'account dal portale.

### Password dimenticata, verifica e cambio dell'email

I link arrivano per email: sono monouso e a DB ne resta solo l'hash SHA-256. Un nuovo link dello stesso tipo annulla i precedenti, e ogni utente può riceverne al massimo 3 per tipo all'ora.

| Link | Durata | Pagina dell'app |
|---|---|---|
| Password dimenticata | 1 ora | `/auth/reset-password?token=` |
| Verifica dell'email | 24 ore | `/auth/verifica-email?token=` |
| Conferma del nuovo indirizzo | 24 ore | `/auth/cambio-email?token=` |

- **Password dimenticata.** `POST /api/auth/password/dimenticata {email}` risponde sempre 202 con lo stesso messaggio, che l'account esista o no.
  - `POST …/anteprima {token}` restituisce l'email mascherata; `POST …/reimposta {token, password}` salva la nuova password.
  - Funziona anche per gli account creati con Google, che così impostano la prima password.
  - La 2FA resta: dopo il reset l'accesso chiede ancora il codice.
- **Verifica dell'email.**
  - Alla registrazione parte un link (`POST /api/auth/verifica-email {token}`); `POST /api/me/email/verifica` lo invia di nuovo.
  - Finché l'email non è verificata si usa l'archivio, ma non si possono invitare persone (in famiglia o dall'ente) né registrare un ente. L'app mostra un avviso in cima a ogni pagina.
  - Nascono già verificati gli account creati da un invito o con Google. Lo diventano anche quelli di chi accetta un collegamento con un ente o completa un reset: il link è arrivato a quella casella.
  - Gli account esistenti prima della migrazione risultano verificati.
- **Cambio password** (`POST /api/me/password {attuale?, nuova}`). La password attuale non serve se l'account non ne ha una. Questa sessione riceve token nuovi; le altre vengono chiuse.
- **Cambio email** (`POST /api/me/email {email, password}`). Il link va al nuovo indirizzo e un avviso al vecchio. Alla conferma si rientra con il nuovo indirizzo.
- **Sessioni chiuse.** Ogni cambio di credenziali elimina i refresh token e invalida i JWT emessi prima (`JwtCredenzialiListener` confronta `iat` con `credenzialiCambiateIl`). Arriva sempre un'email di avviso con il link "non sei stato tu? reimposta la password".
- I link scaduti da una settimana vengono eliminati dalla pulizia oraria.
## Anagrafica

L'entità `Anagrafica` raccoglie i dati anagrafici di una persona o di un'azienda:
- **tipo:** privato o azienda;
- **dati personali:** nome, cognome, sesso, stato civile, titolo;
- **nascita:** data, stato e comune di nascita, cittadinanza;
- **codice fiscale**, con flag "manuale" per i casi di omocodia;
- **documento d'identità:** tipo, numero, rilascio e scadenza;
- **dati aziendali:** denominazione, partita IVA, SDI, capitale sociale, sito web;
- **indirizzi:** residenza/sede legale e domicilio/sede operativa;
- **contatti:** telefoni (E.164), email, PEC.

**Validazione** (`ValidAnagraficaValidator`, stessi messaggi di `AnagraficaCallbackValidator`):
- **privato:** campi obbligatori, solo maggiorenni, comune di nascita obbligatorio se nato in Italia;
- **codice fiscale:** formato, coerenza con i dati anagrafici (con `davidepastore/codice-fiscale` e codici Belfiore) e unicità;
- **azienda:** partita IVA con checksum e unicità;
- **telefono:** verificato con libphonenumber.

Titolo e tipo documento sono stringhe con valori chiusi.

**Comuni e stati** vengono dal dataset `devnix/belfiore-code` (`app:geo:import`). I comuni soppressi restano disponibili per il luogo di nascita.

## Documenti

```
Tenant ──< Documento >── Categoria (di sistema o del tenant; isSensibile)
              │   └── owner_user_id (chi l'ha caricato)
              └──< documento_membro_famiglia >── MembroFamiglia ── Anagrafica
```

- **MembroFamiglia**: persona della famiglia **senza account** (es. un figlio minore), con un'Anagrafica propria.
  - Obbligatorio solo il nome; nessun limite di età.
  - Il codice fiscale è facoltativo; se presente è verificato come nell'anagrafica dell'account, ed è unico **nel tenant**: lo stesso figlio può stare nei tenant di entrambi i genitori.
  - Si può collegare facoltativamente a un account (es. il coniuge).
- **Un documento può riguardare più membri** (relazione molti-a-molti), oppure nessuno: è il caso dei documenti della famiglia in generale.
- **Categorie di sistema** (`config/packages/categorie.yaml`): Bollette, Documenti Identità*, Salute*, Farmaci*, Veicoli, Casa, Scuola. L'asterisco indica le categorie sensibili. I tenant possono crearne di proprie.
- **Documenti → Categorie** (`/categorie`): lista con filtri, icona, colore, protezione e numero di documenti. Il numero arriva solo a chi legge i documenti, e solo per le categorie che vede. Chi ha `categoria:manage` crea, modifica ed elimina le categorie della famiglia.
  - Eliminare una categoria con documenti richiede `?spostaIn={id}`: documenti e scadenze passano nella categoria scelta.
  - La cifratura dei documenti già caricati non cambia.
  - La categoria esce dagli elenchi dell'accesso d'emergenza.

### Caricamento

Endpoint: `POST /api/tenants/{id}/documenti`, multipart con i campi `file`, `categoriaId`, `membriIds[]`, `titolo` e `dataDocumento`.

1. il voter verifica `documento:create` sul tenant **prima** di leggere il file;
2. il tipo di file si ricava dal contenuto, con un massimo di 20 MB:
   - formati accettati: PDF, immagini (JPEG, PNG, HEIC, GIF, WEBP), documenti Office (DOCX, XLSX, PPTX, ODT, ODS, ODP, DOC, XLS, PPT, RTF), Pages, Numbers, Keynote, TXT e ZIP;
   - per i formati che sono archivi ZIP (OOXML, ODF, iWork) si controlla anche la struttura interna. Pages, Numbers e Keynote hanno la stessa struttura (`Index/Document.iwa`): il contenuto dice che è un documento iWork, l'estensione dice quale dei tre;
   - gli archivi ZIP si conservano e si scaricano soltanto: il server non li apre mai (niente testo, niente anteprima), quindi il loro contenuto non è controllato;
   - i file con macro VBA sono rifiutati;
3. controllo duplicati nel tenant: un documento con lo stesso contenuto viene segnalato in `possibiliDuplicati`, senza bloccare il caricamento;
4. se la categoria è sensibile, il file viene **cifrato prima di arrivare allo storage** (vedi [docs/gestione-chiavi.md](docs/gestione-chiavi.md));
5. il documento viene salvato con `ocr_status = in_coda`;
6. parte un messaggio Messenger (contiene solo l'id).

### Documenti cifrati

Oltre al file:
- il **testo OCR** si salva cifrato, in `ocr_text_cifrato`; `ocr_text` resta NULL. La ricerca testuale su questi documenti vale quindi solo sul titolo;
- l'**hash per i duplicati** è un HMAC con la chiave del tenant, non uno SHA-256 in chiaro.

Se si sposta un documento **verso** una categoria sensibile, viene cifrato. **Verso** una non sensibile resta cifrato.

### Download

Il download passa solo da `GET /api/tenants/{id}/documenti/{docId}/file`, che verifica il permesso, decifra in memoria e risponde con `no-store`. Lo storage non è mai esposto.

### Anteprima

La scheda del documento ha due schede, "Anteprima" (predefinita) e "Testo" (OCR). L'originale non cambia mai: download, export, Google Drive e consegne all'ente usano sempre il file vero.

- **PDF, JPEG, PNG, GIF, WEBP, TXT**: l'anteprima è l'originale (`anteprima_stato = non_serve`). I TXT si mandano convertiti in UTF-8 (da Windows-1252 o UTF-16); il download resta il file originale.
- **ZIP**: nessuna anteprima (409 con il motivo).
- **HEIC e Office** (compresi Pages, Numbers e Keynote, che LibreOffice importa): il worker ricava una copia per la sola visualizzazione (`AnteprimaDocumentoMessage`):
  - le foto HEIC diventano JPEG con ImageMagick, via pipe;
  - i documenti Office diventano PDF con il servizio `convertitore` (Gotenberg/LibreOffice). Gira su una rete Docker `internal` senza internet, in sola lettura, con i temporanei in tmpfs, e ha attiva solo la route LibreOffice.
  - La copia si salva accanto all'originale (`…-anteprima.bin`), cifrata con la stessa chiave se il documento è cifrato (scopo `anteprima`). Se il documento passa a una categoria sensibile, si cifra anche la copia.
- **Office e OCR**: l'OCR dei documenti Office parte dopo l'anteprima e legge il PDF ricavato. Se l'anteprima fallisce, il testo risulta "non disponibile" con il motivo.
- **Endpoint**:
  - `GET .../documenti/{docId}/anteprima` risponde con il file (inline, `no-store`), 202 se la copia è in preparazione, 409 con `anteprimaErrore` se non è disponibile;
  - `POST .../documenti/{docId}/anteprima` rigenera la copia (`documento:write`).
- **Convertitore non configurato** (`GOTENBERG_URL` vuoto): anteprima "fallita" con il motivo esplicito, mai un 500. I file restano caricabili e scaricabili. Lo stato del convertitore compare in "Salute e da fare" del portale.
- **Documenti già caricati**: `php bin/console app:anteprime:genera` mette in coda le anteprime mancanti; con `--fallite` anche quelle fallite.
- **Risposte delle famiglie all'ente** (`FileCifrato`): `GET /api/enti/{t}/richieste/{id}/destinatari/{did}/anteprima`. La copia si crea alla prima apertura, cifrata con la DEK del file dell'ente, e vale anche per le consegne passate.
- **pdf.js sul web**: i PDF si disegnano con pdf.js, servito dall'app stessa da `frontend/web/pdfjs/` e non scaricato a ogni avvio da unpkg.com, senza controllo di integrità, come farebbe il pacchetto `printing`. Chi ospita l'app web deve servire i file `.mjs` come `text/javascript`, altrimenti il browser rifiuta i moduli. Per aggiornarlo vedi `frontend/web/pdfjs/README.md`.
- **Caratteri**: Poppins e Plus Jakarta Sans sono inclusi nell'app (`frontend/assets/fonts/`). Sul web anche il motore grafico (CanvasKit) e i caratteri di riserva (Roboto e Noto per simboli ed emoji, `frontend/web/font-fallback/`) sono serviti dall'app, configurati in `frontend/web/flutter_bootstrap.js`: l'app non scarica nulla da Google per disegnare. Dopo un aggiornamento di Flutter si rilancia `scripts/aggiorna-font-fallback.sh`.

### OCR

- Il servizio `worker` del compose esegue `messenger:consume async`, con la coda su PostgreSQL.
- Tesseract (ita+eng) è usato solo via pipe: il contenuto decifrato non tocca il disco.
- Per i PDF: se c'è già un livello di testo si usa quello, altrimenti le pagine vengono rasterizzate con `pdftocairo` e passate a Tesseract. HEIC, GIF e WEBP vengono convertiti in PNG con ImageMagick (delle immagini animate, il primo fotogramma).
- I TXT non passano da Tesseract: il testo si legge così com'è, convertito in UTF-8.
- In caso di errore: `ocr_status = fallito`, con il motivo in `ocr_errore`, nel log e nell'audit. Si rilancia con `POST .../documenti/{docId}/ocr`.

### File temporanei

`/tmp` dei container è un tmpfs: il file che PHP riceve in upload resta in RAM.

### Permessi

| Permesso | owner | member | viewer |
| --- | --- | --- | --- |
| `documento:read` | ✓ | ✓ | ✓ |
| `documento:create` | ✓ | ✓ | – |
| `documento:write` / `documento:delete` | tutti | solo i propri (`:own`) | – |
| `membro_famiglia:read` | ✓ | ✓ | ✓ |
| `membro_famiglia:manage` | ✓ | ✓ | – |
| `categoria:manage` | ✓ | – | – |
| `import:manage` | ✓ | – | – |

### Audit

- Letture di documenti sensibili (dettaglio, download, lista che li contiene): sempre registrate.
- Scritture su qualsiasi documento (creazione, modifica, cancellazione, cifratura, rilancio OCR): sempre registrate, con il flag `sensibile`.
- Accessi negati: sempre registrati.

## App: scansione di un documento

Solo nelle app **Android e iOS**: sul web la pagina mostra un avviso. Si apre dal menu utente, voce **Nuovo documento** (`/documenti/nuovo`).

1. **Cattura di una pagina** con `cunning_document_scanner`, che usa VisionKit su iOS e ML Kit su Android:
   - la fonte è la fotocamera o una singola immagine dalla galleria;
   - i bordi si rilevano in automatico, gli angoli si possono spostare a mano e la prospettiva viene corretta.

   Lo scanner viene aperto con una pagina alla volta e **senza i suoi filtri**: pagine e filtro li gestisce l'app.
2. **Filtro bianco e nero** facoltativo, con anteprima prima di confermare la pagina. È una binarizzazione **adattiva di Sauvola**, scritta in Dart in `lib/features/documenti/scan/page_processing.dart` ed eseguita in un isolate:
   - la soglia si calcola per ogni pixel dalla media e dalla deviazione della zona intorno;
   - ombre e carta scura diventano bianche, il testo resta nero;
   - le pagine in bianco e nero si salvano in PNG, quelle a colori in JPEG 85;
   - il lato lungo viene ridotto a 2000 px.
3. **Pagine**: le miniature si possono riordinare (pressione lunga e trascinamento) o eliminare.
4. **PDF** generato con il pacchetto `pdf`:
   - una pagina per foto, nell'ordine mostrato;
   - ogni pagina ha le proporzioni della foto e il lato lungo di un A4.

   Oltre 20 MB il caricamento viene bloccato prima dell'invio.
5. **Conferma**: la categoria è obbligatoria, i membri della famiglia sono facoltativi.
6. **Upload** multipart verso `POST /api/tenants/{id}/documenti` con il client unico (`ApiClient`):
   - una barra mostra l'avanzamento;
   - un 401 durante l'upload viene gestito rinnovando il token e reinviando un nuovo body;
   - se l'upload riesce, l'app apre la scheda del documento (`/documento/{id}`).

**Bozza**:
- pagine, ordine, categoria e membri si salvano subito nella cartella di supporto privata dell'app (`scan_draft/`);
- se l'upload fallisce, o l'app viene chiusa, la bozza resta finché l'upload non riesce o l'utente la annulla esplicitamente;
- una bozza di un altro utente sullo stesso dispositivo viene scartata e non mostrata.

**Requisiti nativi**:
- Android: minSdk 24, permesso `CAMERA`;
- iOS: deployment target 15.0, `NSCameraUsageDescription` e `NSPhotoLibraryUsageDescription`.

**Progetto Android**:
- la cartella `android/` è generata da Flutter 3.44 (Gradle 9.1, AGP 9.0.1, Kotlin 2.3, Java 17).

## Import da Google Drive

Una **sorgente** (`ImportSource`) collega una cartella di Google Drive a un tenant. Gestirla richiede `import:manage` (solo owner). La sincronizzazione è **solo manuale**: non esiste un import pianificato.

### Configurazione

1. In Google Cloud Console:
   - abilita la **Google Drive API**;
   - crea un client OAuth di tipo "Applicazione web";
   - come redirect URI autorizzato registra `<URL pubblico API>/api/import-sources/oauth/callback` (in sviluppo `http://localhost:8090/api/import-sources/oauth/callback`).
2. Imposta le variabili in `backend/.env.local` o nell'ambiente, **non** nei file versionati:
   ```
   GOOGLE_OAUTH_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_OAUTH_CLIENT_SECRET=...
   GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8090/api/import-sources/oauth/callback
   ```
   Se mancano, `POST /import-sources` risponde **422 "Provider non configurato"**.

**Scope `drive.readonly`**: per Google è uno scope *restricted*.
- Finché l'app è "in test" funziona per al massimo 100 utenti di test dichiarati nella console.
- Per aprire l'app al pubblico servono la verifica di Google e un audit di sicurezza CASA annuale.

### Collegamento (OAuth)

Un redirect del browser non porta il JWT, quindi il collegamento avviene in due passi:

1. `POST /api/tenants/{id}/import-sources/{sid}/connect` (autenticato) restituisce `authorizationUrl`: il frontend lo apre.
2. Google rimanda a `GET /api/import-sources/oauth/callback`. L'endpoint è **pubblico** ma vale solo con lo `state` emesso al passo 1, che è:
   - casuale e monouso;
   - valido 10 minuti;
   - legato alla sorgente e all'utente;
   - salvato a DB solo come hash.

   Alla fine rimanda a `<FRONTEND_URL>/import/esito?esito=ok|errore&motivo=...`. La pagina Flutter corrispondente non esiste ancora.

**Token:**
- sono cifrati come i documenti sensibili: chiave propria della sorgente, cifrata con la chiave del tenant, con dati associati propri;
- non compaiono mai nelle risposte;
- `app:crypto:key rewrap` e `status` includono anche le sorgenti.

### Sincronizzazione

`POST .../import-sources/{sid}/sync` risponde 202 e mette in coda `ImportJobMessage`. Il worker (`ImportRunner`) legge la cartella radice e le sue **sottocartelle di primo livello**; le cartelle più profonde vengono ignorate.

Per ogni file:

1. **Duplicati, prima del download.**
   - File normali: l'`md5Checksum` di Drive viene confrontato con `documento.hash_md5`. La colonna contiene l'HMAC dell'MD5 con la chiave del tenant, mai l'MD5 in chiaro, ed è calcolata anche per i caricamenti manuali.
   - File nativi Google (Documenti, Fogli, Presentazioni, Disegni): vengono esportati in PDF e riconosciuti per id + data di modifica. Se il file viene modificato su Drive, viene reimportato come nuovo documento.
2. **Categoria**, secondo la strategia:
   - `cartelle`: il nome della sottocartella viene cercato, senza distinguere maiuscole e minuscole, tra le categorie di sistema **e** del tenant (quindi "SALUTE" finisce in Salute, che è sensibile). Se non ne esiste nessuna viene creata una categoria del tenant non sensibile. I file nella radice vanno in "Da categorizzare".
   - `ai_contenuto`: tutto va in **"Da categorizzare"**, una categoria di sistema **sensibile**, quindi il documento viene cifrato.
3. Il documento viene creato con lo stesso percorso del caricamento manuale (controllo del tipo, cifratura, OCR in coda). Il proprietario è l'utente che ha creato la sorgente, e il job verifica che abbia ancora `import:manage`.

**Esito ed errori:**
- l'esito (`trovati`, `importati`, `duplicati`, `saltati`, `errori`) viene salvato sulla sorgente;
- un errore su un singolo file non ferma gli altri;
- un errore della sorgente (accesso revocato, cartella sparita) porta lo stato a `errore` e salva il motivo in `ultimoErrore`;
- rilanciare la sincronizzazione è sempre sicuro.

### Classificazione automatica (strategia `ai_contenuto`)

1. Il worker OCR emette `DocumentoOcrCompletatoEvent`, un aggancio generico per le elaborazioni che seguono l'OCR.
2. Il listener mette in coda `ClassificaDocumentoMessage` solo per i documenti che si trovano in "Da categorizzare" **e** provengono da una sorgente `ai_contenuto`. È un job separato: se Ollama è lento o spento non tocca lo stato dell'OCR, e Messenger lo ritenta.
3. `AiCategoryClassifier` chiama l'**Ollama locale** (servizio `ollama`, senza porte pubblicate). Gli invia:
   - il nome del file;
   - il testo OCR, troncato a 6000 caratteri;
   - i nomi delle categorie del tenant.

   La risposta è vincolata da uno schema JSON (`enum` = categorie + "nessuna") e poi ricontrollata contro l'elenco reale.
4. Il risultato:
   - **categoria valida**: il documento viene spostato. Se era cifrato **resta cifrato** anche se la nuova categoria non è sensibile: la decifratura non avviene mai in automatico;
   - **"nessuna" o qualsiasi altra risposta**: il documento resta in "Da categorizzare". L'audit registra `documento.classificazione_incerta`.

**Il modello** si imposta **solo** con `OLLAMA_MODEL`. Quello consigliato è `qwen3:4b-instruct-2507-q4_K_M`:
- ufficiale;
- 2,5 GB;
- oltre 100 lingue;
- variante senza "thinking".

Se `OLLAMA_MODEL` è vuota la classificazione è disattivata e i documenti restano da categorizzare.

Su una VM senza GPU servono circa 3–4 GB di RAM e da qualche secondo a qualche decina di secondi per documento.

**Limite**: i documenti rimasti cifrati non sono ricercabili per testo OCR (la ricerca lavora solo sul testo in chiaro).

### Proponi categoria con l'AI (wizard sui selezionati)

Nella lista dei documenti, **Sui selezionati › Proponi categoria con l'AI…** apre un wizard su al massimo 50 documenti. Serve il permesso `documento:write`.

1. **Analisi**: un documento per volta, con `POST /api/tenants/{t}/documenti/{id}/proposta-categoria`. La richiesta non cambia niente; nell'audit va `documento.categoria_proposta`. Con il modello consigliato su CPU servono da 5 a 50 secondi per documento, circa 25 minuti per 50 documenti.
2. **Revisione**: per ogni documento c'è la categoria proposta, modificabile o "Non spostare". Le **categorie nuove** suggerite si possono rinominare, con icona, colore e "sensibile". Si creano solo quelle spuntate, e solo da chi ha `categoria:manage`.
3. **Applica**: crea le categorie spuntate e sposta i documenti con l'operazione di gruppo `sposta`. Verso una categoria sensibile il documento viene cifrato; quelli già cifrati restano cifrati.

Come lavora `PropostaCategoria`, con lo stesso modello locale della classificazione automatica:
- **Scelta**: il modello sceglie tra le categorie della famiglia, escluse "Da categorizzare" e "Condivisi con enti", e tra le categorie nuove già proposte nello stesso giro, così documenti simili finiscono insieme. Per ogni categoria riceve fino a 3 titoli di esempio, esclusi i documenti in revisione e i titoli che sono solo nomi di file (`DOC_1660607446`, `scan 12`).
- **Verifica sì/no**: un modello da 4B mette quasi sempre il documento in qualche categoria, anche forzata (un volo in "Veicoli"). Una seconda domanda confronta il documento con una descrizione della categoria generata a parte, che resta in cache una settimana.
- **Categoria nuova**: se la scelta non passa la verifica, il modello propone il nome di una categoria nuova (1-4 parole, controllato da `NuovaCategoria::normalizzaNome`) con icona e sensibilità. Se il testo non è disponibile, per esempio OCR non ancora fatto o ZIP, decide solo dal titolo e non propone categorie nuove.
- **Documenti cifrati**: il testo è decifrato solo in memoria e va solo a Ollama locale. Nell'audit non finisce nulla di ricavato dal contenuto.

Ogni proposta tiene occupati per decine di secondi un thread del server web e Ollama, quindi ci sono dei limiti (`TurnoProposte`, con advisory lock di PostgreSQL):
- **una proposta alla volta per utente**;
- **al massimo `app.proposte_contemporanee` (2) insieme** su tutta la piattaforma.

Se non c'è posto la risposta è `429` con `Retry-After: 5`, e il wizard aspetta e riprova lo stesso documento. C'è anche un limite di `app.proposta_categoria_limit` (150) proposte all'ora per utente: oltre, `429` con il tempo che manca, e il wizard si ferma.

La qualità delle proposte dipende dal modello: vanno sempre ricontrollate prima di applicarle.

## Ricerca per significato, dati estratti e documenti simili

Solo per i documenti **non cifrati**: dati estratti e vettori rivelano il contenuto, quindi un documento cifrato non viene mai analizzato, e uno che diventa cifrato (spostato in una categoria sensibile) perde subito l'indice (`DocumentService::encryptExisting`).

**Indicizzazione.** Dopo ogni OCR riuscito, `IndicizzaDocumentoHandler` (job `IndicizzaDocumentoMessage`, nel worker):
1. `AiEstrattoreDati` chiede all'Ollama locale (`OLLAMA_MODEL`) emittente, data, importo, numero, intestatario, riferimenti e tag. La risposta è vincolata da uno schema JSON e poi **ricontrollata sul testo**:
   - la data si sceglie solo tra quelle presenti nel testo;
   - emittente, numero, intestatario e importo valgono solo se compaiono nel testo;
   - i **riferimenti** (targa, POD, PDR, polizza, contratto, IBAN) si normalizzano in maiuscole e cifre e devono comparire nel testo; esclusi codice fiscale, parole senza cifre e frasi;
   - i tag sono etichette brevi ripulite.
2. `SuddivisoreTesto` divide il testo in parti (fino a 8 da 1500 caratteri) e `OllamaEmbedder` ne calcola i vettori con `OLLAMA_EMBED_MODEL` (consigliato `bge-m3`, multilingue, ~1,2 GB, meno di 2 GB di RAM).
3. Prima di salvare si ricontrolla, con il documento bloccato, che non sia stato cifrato nel frattempo.

Se Ollama non risponde, Messenger ritenta. Se un modello non è scaricato (HTTP 404) quella parte si salta senza ritentare. Nell'audit (`documento.indicizzato`) ci sono solo i modelli e il numero di parti, mai i dati estratti.

**Tabelle.** `documento_indice` (dati, tag, riferimenti) e `documento_embedding` (un vettore per parte) usano l'estensione **pgvector**: il servizio `database` è `postgres:16-alpine` con pgvector compilato (`docker/database/Dockerfile`, versione fissata). Sono gestite con SQL diretto (`Indice\IndiceDocumenti`) ed escluse dal confronto dello schema dell'ORM (`schema_filter` in `doctrine.yaml`). Nessun indice HNSW: si cerca sempre dentro una famiglia, dove la ricerca esatta è veloce e non perde risultati.

**Ricerca.** `GET .../documenti?q=...&modo=significato` (`RicercaPerSignificato`) restituisce, in ordine:
1. i documenti vicini per significato. Un documento resta se la distanza coseno non supera `app.ricerca_distanza_max` (0,58) né quella del migliore più `app.ricerca_margine` (0,1). I valori sono tarati su bge-m3: con questo modello le distanze dei documenti pertinenti e degli altri sono vicine, quindi conta il distacco;
2. i documenti con un tag uguale a una parola cercata;
3. i risultati della ricerca per parole di sempre, così i documenti cifrati restano trovabili per titolo.

Valgono gli stessi filtri e restrizioni della lista (categoria, persona, accesso d'emergenza). Sono al massimo 100 risultati, divisi in pagine; l'ordinamento scelto non si applica. Se il modello è spento o Ollama non risponde entro 30 secondi si usa la ricerca per parole. Le ricerche di una sola parola rendono meno di una frase.

**Documenti simili.** `GET .../documenti/{docId}/simili` (`DocumentiSimili`) restituisce:
- prima i documenti con un riferimento in comune ("stessa targa AB123CD");
- poi quelli con contenuto vicino (distanza entro `app.simili_distanza_max`, 0,4).

Il permesso di lettura si verifica documento per documento.

**Documenti già presenti.** `bin/console app:documenti:indicizza` mette in coda quelli mai indicizzati o indicizzati con un altro modello di embedding (`--tutti`: tutti). Da lanciare dopo aver scaricato `bge-m3` o cambiato modello. `POST .../documenti/{docId}/indice` (`documento:write`) rianalizza un documento.

**Avanzamento nell'app.** Menu dell'account, "Analisi AI dei documenti" (`documento:read`): quanti documenti della famiglia sono analizzati, in attesa, con il testo ancora da leggere o illeggibile, e quanti sono cifrati (mai analizzati). Avvisa se un modello non è scaricato o Ollama non risponde: in quel caso l'analisi è ferma e i documenti restano in attesa. Chi ha `documento:write` ha il pulsante "Analizza i mancanti", che fa lo stesso di `app:documenti:indicizza` per la sola famiglia. Le API sono `GET` e `POST /api/tenants/{id}/documenti/analisi`: restituiscono solo conteggi e, con l'accesso d'emergenza limitato, contano solo le categorie concesse. Un documento con il testo vuoto, una volta indicizzato, conta come analizzato e il comando non lo rimette più in coda.

**Da sapere:**
- una categoria resa sensibile in seguito non cifra i documenti che contiene già: restano in chiaro, e quindi indicizzati, come oggi restano ricercabili per testo;
- "dell'anno scorso" non filtra per data: la ricerca per significato non interpreta le date.

## Scadenze

Lo scadenziario della famiglia. Ogni scadenza ha titolo, data e note. Può avere anche:
- una ricorrenza: nessuna, mensile o annuale;
- un promemoria N giorni prima;
- un collegamento a un documento, a una persona o a una categoria.

Permessi:
- `scadenza:read` per vedere le scadenze;
- `scadenza:manage:own` / `:all` per gestirle. Il viewer è in sola lettura.

Comportamento:
- **Fatta**: segna la scadenza come fatta. Se è ricorrente, crea l'occorrenza successiva. Il 31 gennaio mensile diventa il 28 o 29 febbraio.
- **Promemoria**: ogni giorno alle 07:00 (Europe/Rome) lo scheduler invia una sola email per utente. La ricevono il proprietario della scadenza e la persona collegata, se ha un account con `scadenza:read`. L'email contiene solo il numero di scadenze, mai i titoli, e ogni promemoria parte una volta sola. Se cambiano la data o i giorni di preavviso, il promemoria viene ripianificato.
- **Documento cancellato**: la scadenza resta, segnata come "documento rimosso".
- **Origine `richiesta`**: è riservata alle richieste degli enti (fase 3). Queste scadenze non si modificano a mano: si ottiene 409.

App: pagina `/scadenze` con elenco e calendario. Le prossime scadenze compaiono anche nella dashboard e nella scheda del documento.

**Scadenze proposte dai documenti.** Dopo l'OCR, `ProponiScadenzaMessage` cerca nel testo le date future (fino a 20 anni) vicine a parole come "scadenza", "valido fino", "expiry". Il punteggio lo decide la parola più vicina alla data: "nascita" ed "emissione" contano contro.
- Con Ollama attivo, il modello sceglie **solo tra le date trovate nel testo**, con un output vincolato a un elenco chiuso, e suggerisce il tipo di documento. Se Ollama non risponde, valgono le regole.
- La proposta resta sul documento finché non viene confermata o ignorata. Nei documenti cifrati è cifrata con la stessa chiave del testo OCR, e nell'audit non compare la data.
- API:
  - `GET /api/tenants/{t}/scadenze-proposte` elenca le proposte, solo a chi può creare scadenze;
  - `POST /api/tenants/{t}/documenti/{id}/scadenza-proposta/ignora` ignora una proposta;
  - per confermarla si crea una scadenza normale collegata al documento, che chiude la proposta.
- App: riquadro "Scadenze trovate nei documenti" in dashboard e nello scadenziario, più la proposta nella scheda del documento. Il modulo si apre già compilato.

## Condivisione esterna temporanea

Serve a mandare uno o più documenti a chi non ha un account (medico, commercialista, agenzia). Si crea dal pulsante "Condividi" nella scheda del documento.
- **Due segreti su canali diversi:** il **link** (`/condivisione?token=`, a DB solo l'hash) e un **codice** `XXXX-XXXX` (a DB solo un hash lento). Il codice si vede una volta sola, e chi condivide lo comunica a parte.
- **Limiti:** valida da 1 ora a 30 giorni, con un numero massimo di download (1-50). Si revoca in ogni momento e 5 codici errati la bloccano. Non viene salvata l'email del destinatario: la "nota" è solo un promemoria per chi condivide.
- **Prima del codice** il destinatario vede solo quanti documenti sono e il nome di chi condivide. Dopo il codice ha una sessione di 30 minuti (header `X-Condivisione-Sessione`).
- **Download e avvisi:**
  - il file si decifra in memoria al momento del download, e ogni download va nell'audit della famiglia (`documento.download_esterno`);
  - al primo accesso parte un'email di avviso a chi ha condiviso, senza titoli.
- **Permessi:** `condivisione:manage:own` (membro) o `:all` (proprietario). Il lettore non condivide.
- **Revoca automatica:**
  - i link di chi esce dalla famiglia vengono revocati;
  - quando si chiede l'eliminazione della famiglia vengono revocati tutti;
  - quelli scaduti da 30 giorni vengono cancellati.
- App: `/condivisioni` per l'elenco con revoca, `/condivisione?token=` è la pagina pubblica del destinatario.

## Notifiche push

Si affiancano alle email per le richieste degli enti (nuove, rifirma, solleciti) e per il promemoria delle scadenze, con **lo stesso testo dell'oggetto dell'email**: mai titoli di documenti o richieste. Toccandole si apre la pagina indicata (`/richieste`, `/scadenze`), solo percorsi interni.
- **Backend:** Firebase Cloud Messaging, API HTTP v1, con un service account.
  - Variabili: `FIREBASE_PROJECT_ID` e `FIREBASE_CREDENTIALS`, che è il **percorso** del JSON del service account, montato come secret: mai nel repository né a DB.
  - Se mancano le variabili, le push risultano "non configurate" nella salute del portale e restano solo le email.
  - L'invio avviene nel worker (`InviaPushMessage`); i token che FCM dichiara non validi vengono eliminati.
- **API:** `POST /api/me/dispositivi {token, piattaforma}` e `DELETE /api/me/dispositivi {token}`, chiamata al logout. Un token appartiene a un solo account: se un altro utente entra dallo stesso telefono, il token passa a lui.
- **App (Android/iOS):** la configurazione si passa con `--dart-define`: `FIREBASE_API_KEY`, `FIREBASE_PROJECT_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_ANDROID_APP_ID`, `FIREBASE_IOS_APP_ID`. Non servono `google-services.json` né `GoogleService-Info.plist` nel repository. Su iOS va configurata la chiave APNs nel progetto Firebase.

## Documenti offline sul telefono

Su Android e iOS, l'interruttore "Disponibile offline" nella scheda del documento salva una copia sul telefono. Si consultano dalla pagina `/offline`, raggiungibile anche dal login: funziona **senza rete e con la sessione scaduta**.
- **Cifratura:** file ed elenco, compresi i titoli, sono cifrati con AES-256-GCM. La chiave è generata sul telefono e resta nel Keystore/Keychain: non va mai al server.
- **Sblocco:** per aprirli serve il blocco schermo del telefono (impronta, volto o PIN, con `local_auth`), che vale 5 minuti. Senza blocco schermo i documenti offline non si aprono.
- **Apertura:** per aprire un file con un'altra app serve una copia in chiaro temporanea in cache, eliminata alla successiva apertura della pagina offline.
- **Logout:** l'uscita esplicita cancella file, elenco e chiave di quell'account.
- **Android:** `MainActivity` estende `FlutterFragmentActivity`, come richiesto da `local_auth`. Su iOS c'è `NSFaceIDUsageDescription`.

## Accesso d'emergenza

Un contatto fidato **con un account CartellaViva** può chiedere di consultare i documenti della famiglia. Il flusso:
1. Un proprietario lo indica: scheda "Emergenza" nelle impostazioni della famiglia, con email, giorni di attesa (3-30) e categorie visibili oppure tutte. Al massimo 3 contatti.
2. Il contatto accetta dal link email (`/emergenza/accetta?token=`, valido 7 giorni) con l'account di quella casella. Da quel momento può chiedere l'accesso, ma non vede nulla.
3. La richiesta (`/emergenza`) avvisa per email e push proprietari e membri, che possono **opporsi** dall'app o dal link nell'email (`/emergenza/annulla?token=`).
4. Senza opposizione, a fine attesa il job orario lo rende membro con il ruolo di sistema **`emergenza`**, in sola lettura: documenti, scadenze, persone. Se sono state scelte delle categorie, `Membership.categorieConsentite` limita sia il voter sia l'elenco dei documenti.
5. Un proprietario lo revoca in ogni momento, e la revoca toglie anche l'accesso.

Il ruolo `emergenza` non è assegnabile con gli inviti normali. Ogni passo va nell'audit della famiglia.

## Aiuto e accoglienza

- **Introduzione** al primo accesso: tre schermate, diverse per famiglia ed ente. Si vede una volta sola (`POST /api/me/intro-vista`, `introVista` in `/api/me`); chi usava già l'app prima della migrazione non la vede.
- **Primi passi:** un riquadro nella dashboard della famiglia (proprietari e membri) e nella home dell'ente (segreteria).
  - `GET /api/tenants/{t}/primi-passi` li calcola **dai dati reali**, senza spunte manuali: per la famiglia profilo, persone, primo documento, scadenza, familiare invitato, 2FA, contatto d'emergenza; per l'ente verifica, gruppi, iscritti, inviti, prima richiesta, operatori.
  - Restituisce solo id e "fatto": testi e collegamenti sono nell'app (`lib/features/aiuto/aiuto_widgets.dart`).
  - Si nasconde per utente e per spazio con `POST …/primi-passi {nascosto}`.
- **Centro Aiuto** (`/aiuto`): domande frequenti ricercabili e filtrate per area, più le guide passo-passo (`/aiuto/guida?id=`) con il pulsante "Vai" verso la pagina giusta. Le guide per le segreterie coprono avvio dell'ente, import CSV, richieste, modelli e rinnovi. I contenuti stanno in `lib/features/aiuto/contenuti_aiuto.dart`.
- **Stati vuoti** con spiegazione, azione principale e guida (documenti, scadenze, iscritti, richieste e modelli dell'ente), più il link "Come funziona?" nelle pagine dell'ente.

## Impostazioni e dati della famiglia

**Modalità avanzata.** Di default una famiglia usa solo i ruoli Proprietario, Membro e Lettore.
- Con la modalità avanzata (`PATCH /api/tenants/{id}` `{"modalitaAvanzata": true}`) si possono creare ruoli personalizzati con permessi scelti uno per uno.
- Senza modalità avanzata, creare un ruolo personalizzato dà 409. I ruoli personalizzati esistenti non si vedono e non si assegnano.
- Per spegnerla, nessun membro e nessun invito in attesa deve usare un ruolo personalizzato (altrimenti 409).
- Gli enti hanno sempre i ruoli personalizzati.

**Esportazione** (solo proprietari).
- `POST /api/tenants/{id}/esportazioni` restituisce un link monouso, valido 24 ore.
- Il link (`GET /api/esportazioni/{token}`, pubblico: il token fa da credenziale) genera lo ZIP al volo, decifrando un documento alla volta in memoria. Nessuno ZIP viene salvato sul server.
- Nello ZIP c'è una cartella per categoria più `indice.csv` (separatore `;`, UTF-8 con BOM, si apre in Excel). Le celle che iniziano con `=`, `+`, `-` o `@` vengono neutralizzate.

**Eliminazione della famiglia** (solo proprietari).
- `POST /api/tenants/{id}/eliminazione` richiede `{conferma: <nome della famiglia>, codice: <codice 2FA>}`. Serve la 2FA attiva.
- La famiglia diventa di sola lettura per 30 giorni (`app.tenant_deletion_grace_days`): il voter nega ogni permesso che non sia `:read`.
- Google Drive viene scollegato subito: i token sono revocati presso Google e cancellati.
- Tutti i membri ricevono un'email.
- `DELETE /api/tenants/{id}/eliminazione` annulla: lo può fare qualsiasi proprietario.
- Alla scadenza, la pulizia oraria nel worker cancella file, chiavi dei documenti e dati. Restano solo le righe dell'audit log e gli account degli utenti, che possono avere altre famiglie.

**Google Drive dall'app.**
- `POST …/import-sources/{sid}/connect` accetta `{"ritorno": "web"|"app"}`. Sono destinazioni fisse della configurazione, mai URL liberi.
- Con `app`, il callback rimanda a `MOBILE_RETURN_URL` (default `cartellaviva://import/esito`). Il deep link è registrato in `AndroidManifest.xml` e `Info.plist` e gestito da `DeepLinkService` (pacchetto app_links).

**App.**
- Il menu laterale mostra le voci in base ai permessi.
- Il selettore famiglia/ente è in cima al menu.
- Nella pagina `/documenti` il pannello dei filtri mostra quanti documenti ci sono per anno, mittente, tag, categoria, persona e tipo di file. I filtri si combinano. La lista si può anche vedere **a gruppi** (per mittente, anno, categoria o tag): i documenti di un gruppo si caricano quando lo si apre.
- I conteggi vengono da `GET /api/tenants/{id}/documenti/faccette`, con gli stessi filtri e le stesse restrizioni della lista. Mittente e tag vengono dall'indice AI, quindi i documenti cifrati finiscono in "Senza mittente" e "Senza tag". Lo stesso mittente scritto in modi diversi conta una volta sola: maiuscole, punteggiatura e forma societaria non contano.
- La **data del documento**, se manca, è quella trovata dall'AI durante l'indicizzazione: non sovrascrive mai una data inserita a mano e scarta le date future. Si corregge dalla modifica del documento.
- Nella pagina `/documenti` si può cercare, filtrare e caricare più file. Su web e desktop si possono anche trascinare: un file = un documento, con una sola conferma.
- La scheda documento permette di aprire/scaricare, modificare ed eliminare.
- `/famiglia/impostazioni` ha le schede Generale, Persone, Categorie (solo un rimando a Documenti → Categorie), Google Drive, Ruoli e Dati.
- Durante l'eliminazione programmata un banner avvisa che la famiglia è in sola lettura.

## Enti: scuole e associazioni

**Registrazione e verifica.**
- `POST /api/enti`: chiunque abbia un account registra l'ente e ne diventa proprietario.
- Viene creato l'anno in corso (`2026/27`).
- Un codice di 6 cifre (30 minuti, 5 tentativi) va all'email istituzionale:
  - scuole: `<codice meccanografico>@istruzione.it`, ricavata dal codice e non scelta dall'utente;
  - associazioni: l'email o PEC del RUNTS.
- Confermato il codice, l'ente è "in verifica".
- Il superadmin (`/api/admin/enti`) approva solo con il controllo sul registro pubblico spuntato; la telefonata è facoltativa. Può anche chiedere integrazioni o respingere, con una nota.
- Cambiare codici o email riporta alla conferma.
- Finché l'ente non è verificato può preparare gruppi, iscritti e operatori, ma non invitare né inviare richieste (409).

**Ruoli dell'ente.**
- **Proprietario**: tutto.
- **Segreteria**: `ente:tutti_i_gruppi`, gestione di struttura, iscritti e operatori.
- **Operatore** (`staff`): vede solo iscritti e richieste dei gruppi a cui è assegnato (`PUT …/gruppi/{id}/operatori`).
- Una richiesta la vedono la segreteria, gli operatori del gruppo destinatario e chi l'ha creata.

**Struttura.**
- Anni: `POST …/anni/{id}/passaggio` copia i gruppi indicati, con iscritti e operatori, nel nuovo anno e archivia il vecchio in sola lettura.
- Sedi e gruppi (classi o attività).
- Iscritti, sensibili per default: ogni accesso concesso va nell'audit. Ciascuno ha 1..n referenti.
- Import CSV con anteprima (`applica: false`), poi conferma. Gli inviti non partono con l'import.

**Collegamento con le famiglie.**
- L'operatore invia gli inviti (`POST …/inviti`) solo da ente verificato.
- L'email parte sempre: l'ente non scopre se esiste un account.
- Dal link (`/collegamenti/invito?token=`) il referente può:
  - accettare con l'account della stessa email, scegliendo famiglia e persona (l'ente non vede questa scelta);
  - rifiutare;
  - dire "non sono io": funziona anche senza account.
- La famiglia può interrompere il collegamento in qualsiasi momento.

**Richieste.**
- Tipi: firma (PDF obbligatorio), invio di un documento, presa visione.
- Destinatari: un gruppo o una selezione di iscritti.
- Firmatari: "tutti" o "uno".
- C'è un destinatario per famiglia e iscritto: genitori in famiglie diverse ricevono ciascuno la propria richiesta.
- Gli iscritti senza referenti collegati compaiono tra i "non raggiunti".
- Con "entro il", nella famiglia nasce una scadenza (origine `richiesta`) che:
  - non si spunta a mano;
  - passa "in verifica" alla risposta;
  - si chiude quando l'ente accetta;
  - si riapre se l'ente chiede di rifirmare.
- **Risposta della famiglia:**
  - scansione legata alla richiesta (bozza separata per richiesta), file, o documento dall'archivio (solo per l'invio);
  - per la presa visione basta la conferma;
  - "non firmo / non autorizzo" è una risposta valida, con messaggio.
- **Consegna a due copie:**
  - l'ente riceve un `FileCifrato` cifrato con la sua chiave, e lo conserva anche dopo una revoca;
  - la famiglia conserva la sua copia nella categoria di sistema "Condivisi con enti" (sensibile).
  - L'ente non accede mai all'archivio della famiglia.
- **Lato ente:** avanzamento per iscritto, verifica (accetta o chiedi di rifirmare con motivo), sollecito (tutti o uno), ZIP delle risposte accettate (generato al volo), annullamento.
- Le email dicono solo "hai una richiesta da <ente>". Audit su entrambi i lati.
- `app:crypto:key rewrap/status` include i file degli enti.

## Enti: link senza account, modelli, rinnovi e ricorrenze

**Risposta senza account.** I referenti con email, non collegati, ricevono un link sicuro per quella richiesta. Ne sono esclusi:
- chi ha detto "non sono io";
- chi ha rifiutato l'invito;
- chi ha interrotto il collegamento.

Il link (`/risposta?token=`) da solo non mostra nulla.
- `POST /api/link/{t}/codice` invia un codice di 6 cifre all'email del referente (10 minuti, 5 tentativi).
- `POST …/verifica` restituisce una sessione di 2 ore, da mandare nell'header `X-Link-Sessione`.
- Con la sessione si legge la richiesta, si scarica il PDF, si risponde (file o presa visione) o si rifiuta.
- Il link scade 7 giorni dopo "entro il" (30 giorni senza data). Un sollecito o una rifirma generano un nuovo link e annullano il precedente.
- La copia consegnata resta solo all'ente.

**Dall'account.** Dopo l'invio il referente può creare l'account, o accedere, con la stessa email. Con `POST /api/link/{t}/collega` (autenticato, con sessione):
- il referente diventa collegato alla famiglia scelta;
- tutte le sue risposte date dal link, anche per altre richieste, vengono copiate in "Condivisi con enti";
- le richieste ancora aperte passano nell'app, con la loro scadenza.

**Modelli e regole** (`/api/enti/{t}/modelli`). Contenuto riusabile più tre regole:
- **validità**: chi consegna indica fino a quando vale il documento (`validoFino`, obbligatorio se richiesto);
- **rinnovo automatico**: N giorni prima della scadenza parte una nuova richiesta al solo interessato, una volta sola per iscritto. `GET …/documenti-in-scadenza` elenca i documenti in scadenza per il cruscotto;
- **conservazione**: dopo N mesi dalla conclusione la copia dell'ente viene eliminata, e la consegna resta marcata "copia eliminata".

Le stesse regole si possono indicare su una singola richiesta, o prenderle dal modello con `modelloId`.

**Ricorrenze.** Un modello con giorno e mese (solo la segreteria) parte ogni anno verso tutti gli iscritti dell'anno in corso, con "entro il" a N giorni.

**Job.** Lo esegue `AutomazioniEntiMessage`, ogni giorno alle 06:00 (Europe/Rome), nel worker.

## Enti: verifica affiancata e registro attività

**Verifica affiancata** (`/ente/verifica`, oppure `?richiesta=` per una sola richiesta). Una coda di tutte le risposte "da verificare" che l'utente può vedere (tutto l'ente o solo i suoi gruppi), dalla più vecchia.
- **Disposizione:** a sinistra il documento consegnato. Le immagini hanno lo zoom; i PDF sono resi pagina per pagina con il pacchetto `printing` (pdf.js sul web, renderer nativo su mobile). A destra la richiesta, il messaggio della famiglia e i controlli da spuntare.
- **Controlli:** documento giusto, leggibile e completo, poi in base al tipo di richiesta:
  - una firma per ogni referente se servono tutte, segnalando quelle non dichiarate dalla famiglia;
  - "almeno una firma" se ne basta una;
  - la validità dichiarata, se richiesta.
- **Decisione:** "Accetta" si attiva solo con tutti i controlli spuntati. "Chiedi correzione" richiede un motivo, scelto tra i più comuni o scritto a mano. Poi si passa subito alla risposta successiva.
- **Scorciatoie:** A accetta, R chiedi correzione, freccia destra salta.
- **API:** `GET /api/enti/{t}/da-verificare[?richiestaId=]` restituisce solo metadati: il file si apre con l'endpoint di sempre, che resta registrato.

**Registro attività** (`/ente/registro`). È il registro del tenant (`GET /api/tenants/{t}/audit_logs`) con due nuovi filtri:
- `attivita=1` toglie i controlli tecnici dei permessi e gli elenchi;
- `attore=` cerca per email di chi ha agito.

Le azioni compaiono in italiano e si filtrano per argomento: richieste, collegamenti, iscritti, ente, persone, inviti. Lo vedono proprietari e segreteria (`audit:read` aggiunto al gruppo della segreteria), non gli operatori.

## Enti: firma con codice (FES)

Per le richieste di tipo firma l'ente sceglie `modalitaFirma`: `cartacea` (predefinita: stampa, firma e scansione) o `codice`. Si può indicare sulla richiesta o nel modello. Con `codice` l'allegato deve essere un PDF.

**Valore legale.** È una firma elettronica semplice (art. 20 CAD, eIDAS): valida, ma "liberamente valutabile" in giudizio. Va bene per autorizzazioni, liberatorie e circolari. Non sostituisce la firma autografa dove la legge la richiede: per quei casi la modalità cartacea resta sempre disponibile, anche sulle richieste con codice. La FEA tramite fornitore esterno (TSP) è progettata ma non implementata.

**Flusso**, uguale dall'app e dal link senza account:
1. il referente apre il PDF;
2. chiede un codice di 6 cifre, che arriva alla sua email (10 minuti, 5 tentativi). Viene registrata l'impronta SHA-256 dell'originale;
3. spunta la dichiarazione di presa visione e inserisce il codice.

**Evidenze.** Per ogni firma `FirmaCodice` registra:
- nome ed email del firmatario, account o link;
- data e ora, IP, user agent;
- testo della dichiarazione;
- SHA-256 del documento originale e del PDF firmato;
- un codice di verifica di 12 caratteri.

**Completamento.** Quando firmano tutti i referenti (o uno, se basta uno), il PDF originale riceve in coda una pagina di attestazione con le firme (dompdf + `pdfunite`). Il file viene salvato cifrato:
- per l'ente, come consegna;
- per la famiglia, in "Condivisi con enti".

**Endpoint**
- Famiglia: `POST /api/tenants/{t}/richieste/{id}/firma/codice` e `POST …/firma {codice, accetto}`.
- Link: `POST /api/link/{token}/firma/codice` e `POST …/firma`, con `X-Link-Sessione`.
- Verifica pubblica: `GET /api/verifica-firma/{codice}` (con rate limit). Mostra solo i dati già stampati sull'attestazione, con l'email mascherata. La pagina dell'app è `/verifica-firma?codice=`.

## Portale delle idee

Gli utenti con account propongono miglioramenti o nuove funzionalità dal menu utente ("Suggerisci un'idea", pagina `/idee`). Le idee sono **private**: le leggono solo l'autore e il team del portale. Non c'è una bacheca pubblica né si possono allegare screenshot.

**Invio** (`POST /api/idee`).
- Campi: tipo (`miglioramento` o `funzionalita`), area facoltativa, testo (20-2000 caratteri) e "potete contattarmi".
- Al massimo 5 idee al giorno per utente.
- Del contesto si registrano solo il tipo di spazio (famiglia, ente o azienda, e solo se l'utente ne fa parte), la piattaforma e la versione dell'app (`--dart-define=APP_VERSION=`). Mai il nome della famiglia o dell'ente.
- `GET /api/idee` restituisce le proprie idee, con lo stato e la risposta pubblica.

**Portale** (`/admin/idee`).

| Livello | Cosa può fare |
|---|---|
| VEDERE | legge le idee e filtra per stato, tipo, area, spazio e testo |
| SUPPORTO | cambia lo stato, scrive la risposta pubblica e la nota interna, unisce e separa i duplicati; vede nome ed email dell'autore solo se ha concesso il contatto |
| ADMIN | elimina ed esporta in CSV (`GET /api/admin/idee-export`, senza contatti, con la neutralizzazione della CSV injection) |

- Gli stati sono: nuova, letta, in valutazione, pianificata, realizzata, non prevista.
- I duplicati confluiscono nell'idea principale, che mostra "richiesta da N utenti". Gli autori dei duplicati vedono lo stato e la risposta della principale, non il suo testo.
- Le nuove idee compaiono anche nel "da fare" della salute.

**Email.** Non contengono mai né il testo dell'idea né la risposta.
- All'autore, e agli autori dei duplicati, quando cambia lo stato (tranne "letta") o la risposta: "La tua idea ha un aggiornamento".
- Ai superadmin, ogni giorno alle 08:00 (`RiepilogoIdeeMessage`): solo quante idee nuove sono arrivate, per tipo. Nessuna email se non ce ne sono.

**Privacy.** Se l'account viene eliminato, le sue idee restano ma diventano anonime.

## Portale della piattaforma

**Ruoli** (`app_user.platform_role`; il superadmin resta il flag `super_admin`):
- **superadmin**: tutto il portale, approva gli enti, gestisce il team (`PUT /api/admin/utenti/{id}/ruolo-piattaforma`).
- **supporto**: famiglie ed enti (solo metadati), utenti (ricerca, blocco/sblocco), recuperi d'accesso, nuovo proprietario, preparazione della verifica degli enti (richiesta di integrazioni).
- **sola_lettura**: salute del sistema e registro globale.
- Nessun ruolo apre i documenti: il portale non ha endpoint sui contenuti.
- I livelli li applica `App\Platform\PlatformAccess`; ogni accesso negato va nell'audit.

**Salute e da fare** (`GET /api/admin/salute`).
- Coda del worker (in attesa e falliti), OCR fallite nelle ultime 24 ore, import in errore, modello AI (Ollama raggiungibile e modello presente).
- Master key: versioni, impronta e ultimo backup. La data dell'ultimo `app:crypto:key export` ora va nell'audit.
- Da fare: backup mancante o più vecchio di 90 giorni, enti da verificare, recuperi da verificare, famiglie senza proprietario, cancellazioni programmate.
- Numeri aggregati.

**Recupero della 2FA** (persa con telefono e codici).
1. `POST /api/auth/recupero` (pubblico, sempre 202: non rivela se l'account esiste).
2. Conferma dal link via email (24 ore).
3. Il supporto verifica l'identità (videochiamata con documento; si annota solo tipo e ultime cifre).
4. 72 ore di attesa con avviso all'utente e ai proprietari delle sue famiglie. Chiunque può annullare dal link (`/procedure/annulla`), con allerta nell'audit.
5. Il job orario disattiva la 2FA, cancella i codici di recupero e tutti i refresh token, e rende la 2FA obbligatoria al prossimo accesso: l'app porta a "Sicurezza".

**Famiglie senza proprietario**: famiglie senza proprietari attivi con account attivo.
- Il supporto verifica l'identità di un membro esistente (`POST /api/admin/tenants/{id}/nuovo-proprietario`).
- Seguono 72 ore con avviso a tutti i membri, annullabili.
- Alla fine il membro diventa proprietario.

**Gestione manuale** (solo superadmin, `App\Platform\GestioneManuale`), per chi chiede di fare le cose al posto suo. Dal portale, in "Famiglie ed enti":
- **Nuova famiglia / Nuovo ente**: il tenant nasce sempre con il proprietario (nuovo o esistente) e, se servono, altri membri o operatori. Tutto viene validato prima di scrivere, in un'unica transazione.
- **L'ente nasce già verificato**, ma con una nota obbligatoria su come è stato verificato fuori dalla piattaforma (contratto, telefonata…). La nota resta nel profilo e nel registro.
- **Chi non ha un account** lo riceve senza password e con l'email "Attiva il tuo account": link di 7 giorni (`TokenAccount::ATTIVAZIONE`, pagina `/auth/attiva`) che imposta la password e verifica l'email. Dal portale si può reinviare. Chi ha già un account riceve un avviso.
- **Membri di qualunque tenant**: aggiunta, cambio di ruolo, sospensione e rimozione, con le stesse regole di `MembershipManager` (mai senza un proprietario attivo).
- **Paletti**:
  - gli account del team del portale non possono diventare membri da qui, altrimenti basterebbe un clic per leggere i documenti di una famiglia;
  - ogni modifica va nel registro (`portale.*`) e i proprietari del tenant ricevono un avviso via email.
- **Famiglia ↔ ente**: il portale inserisce iscritto e referente nell'ente e manda l'invito email al collegamento. Il collegamento nasce solo quando la famiglia accetta.
- **Verifica in due passaggi**: non è obbligatoria per gli operatori degli enti, ma l'app la ricorda a ogni accesso a chi opera in un ente senza averla attiva.

**Liste "Famiglie ed enti" e "Utenti"**. Filtri, ordinamento e paginazione si applicano lato server (`GET /api/admin/tenants/elenco`, `GET /api/admin/utenti/elenco`). Il supporto le consulta; il superadmin modifica ed elimina.
- **Modifica utente**: nome, cognome ed email. L'email cambia subito: le sessioni si chiudono, il vecchio indirizzo riceve un avviso e il nuovo va confermato, oppure riceve l'attivazione se l'utente non ha ancora una password.
- **Elimina utente**: esce da famiglie ed enti (i suoi dati passano a un proprietario). Poi l'account viene anonimizzato (`User::anonimizza`, email `<id>@utenti-eliminati.invalid`, così l'indirizzo vero torna libero) e disattivato. Non si può eliminare l'unico proprietario di qualcosa.
- **Modifica tenant**: il nome della famiglia, oppure i dati dell'ente. Se l'ente era verificato, resta verificato.
- **Elimina tenant**: l'eliminazione è programmata, come per le famiglie (`TenantDeletion::richiediDalPortale`).
  - Vale anche per gli enti e richiede un motivo, che resta nel registro.
  - Il tenant resta in sola lettura per 30 giorni e si può annullare dalla lista.
  - Le automazioni dell'ente si fermano.
- **Operazioni di gruppo sugli utenti** (`POST /api/admin/utenti/bulk`): blocca, sblocca, reinvia l'attivazione. Chi non si può modificare viene saltato, con il motivo.
- **Esportazione CSV** dei risultati filtrati (`…/export`, solo superadmin, registrata nell'audit). Solo metadati; le formule vengono neutralizzate.

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

## Endpoint principali

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

## Limiti noti e da fare

- **Installazione in proprio (produzione)**: `compose.prod.yaml`, `scripts/installa-server.sh`, `scripts/backup.sh` e `scripts/ripristina.sh`, con la guida [docs/installazione-server.md](docs/installazione-server.md).
  - il percorso è stato provato da zero su un Mac (`localhost`, certificato interno di Caddy), non ancora su un server Linux con Let's Encrypt e un SMTP reale;
  - una sola macchina con Docker Compose: niente alta disponibilità, e i backup vanno copiati fuori dal server a mano o con un proprio cron;
  - `compose.yaml` resta solo per lo sviluppo (codice montato, Mailpit, password del database predefinita);
  - l'app Android in release è firmata con la chiave di debug (`frontend/android/app/build.gradle.kts`): per distribuirla serve la propria chiave.
- **Test** (misure alla versione 0.1.0):
  - backend: 240 test, che coprono l'84% delle righe di `backend/src`;
  - frontend: 110 test, che toccano 89 file su 185 di `frontend/lib` e, di quelli, il 34% delle righe. Le parti meno coperte sono l'account (13%) e il layout (9%).
- **Analisi statica**: PHPStan è al livello 5, con 51 segnalazioni ancora in `backend/phpstan-baseline.neon`. L'analizzatore di Flutter dà 8 avvisi informativi.
- **Dipendenze**:
  - cinque pacchetti in `backend/composer.json` hanno vincolo `*` (`api-platform/symfony`, `gesdinet/jwt-refresh-token-bundle`, `lexik/jwt-authentication-bundle`, `nelmio/cors-bundle`, `symfony/monolog-bundle`): le versioni installate sono quelle fissate da `composer.lock`;
  - `composer.json` dichiara PHP ≥ 8.2, ma il progetto è provato solo con PHP 8.4, la versione dell'immagine Docker;
  - le immagini di base della build non sono fissate a una versione precisa: `dunglas/frankenphp:1-php8.4` segue l'ultima 1.x con PHP 8.4, `ghcr.io/cirruslabs/flutter:3.44.0` è fissata alla versione di Flutter ma non al digest. Ricostruendo si possono ottenere versioni più recenti;
  - `devnix/belfiore-code` 0.1 (solo dati: comuni e stati per il codice fiscale) dichiara di richiedere PHP 7, quindi `composer install` va lanciato con `--ignore-platform-req=php`. Le versioni successive dichiarano PHP 8: l'aggiornamento è da fare.
- **Flutter**: l'interfaccia è solo in italiano (testi nel codice). Per aggiungere una lingua servono le traduzioni (es. `flutter_localizations` con file ARB) e `supportedLocales` in `main.dart`.
- **Token sul web**: `flutter_secure_storage` sul web usa localStorage cifrato, che resta esposto a XSS. In futuro si può spostare il refresh token in un cookie HttpOnly (Gesdinet supporta `cookie:`).
- **Identificativi**: package Dart `cartellaviva`; `applicationId` Android e bundle id iOS/macOS `it.cartellaviva.app`. Non cambiarli dopo la prima pubblicazione sugli store (si crea un'app diversa). Le app Android e iOS registrate in Firebase devono usare questo identificativo.
- **Produzione**:
  - con `compose.prod.yaml`, `JWT_PASSPHRASE`, `APP_SECRET`, `APP_ENCRYPTION_KEY`, la password del database e `MAILER_DSN` stanno nel file `.env` del server (permessi 600, fuori dal repository) e arrivano ai container come variabili d'ambiente: chi può usare Docker sul server (root, gruppo `docker`) li vede con `docker inspect`. La master key invece è un secret Docker (file montato in sola lettura). Miglioramento possibile: passare anche gli altri come secret, letti con `%env(file:...)%`. `APP_ENCRYPTION_KEY` non va mai cambiata senza ri-cifrare i segreti TOTP;
  - le attività pianificate (`src/Schedule.php`, oggi la pulizia oraria di challenge 2FA, `state` OAuth e link di esportazione scaduti, l'eliminazione delle famiglie arrivate a scadenza e i promemoria giornalieri delle scadenze) girano nel worker sul trasporto `scheduler_default`: il worker deve essere sempre attivo;
  - **master key dei documenti:** secret della VM con backup separato. È il punto più critico: vedi [docs/gestione-chiavi.md](docs/gestione-chiavi.md);
  - backup di DB e volume `document_storage` insieme: `scripts/backup.sh` (ripristino: `scripts/ripristina.sh`), da copiare fuori dal server;
  - la retention (`data_conservazione_fino`) per ora è solo un campo: nessuna cancellazione automatica;
  - valutare l'obbligo di 2FA per il superadmin;
  - `MAILER_DSN` reale e `CORS_ALLOW_ORIGIN` sul dominio li imposta `scripts/installa-server.sh`.
