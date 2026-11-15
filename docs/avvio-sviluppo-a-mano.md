# Avvio in sviluppo: i passi a mano e la configurazione

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

La via più semplice è lo script descritto in [avvio-sviluppo.md](avvio-sviluppo.md). Qui ci sono gli stessi passi fatti a mano, le opzioni di configurazione e i test.

**Ogni installazione ha i suoi segreti.** Nel repository non ce n'è nessuno. In `backend/.env` le variabili `APP_SECRET`, `JWT_PASSPHRASE` e `APP_ENCRYPTION_KEY` sono vuote di proposito: se mancano, l'API risponde con un errore 500. Ognuno li genera casuali sulla propria macchina: lo script lo fa da solo, a mano si usano i comandi del passo 1 qui sotto. I file che li contengono (`secrets/`, `backend/.env.local`, `backend/.env.test.local`) sono esclusi da git: non vanno mai condivisi, copiati da altri o committati. Della master key va fatto il backup ([gestione-chiavi.md](gestione-chiavi.md)): senza, i documenti cifrati diventano illeggibili.

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
  --dart-define=GOOGLE_WEB_CLIENT_ID=xxx.apps.googleusercontent.com   # facoltativo, vedi docs/accesso.md
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
