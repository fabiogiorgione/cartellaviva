# CartellaViva — avvio in sviluppo sul PC

Guida pratica per far girare l'app sul proprio PC (Windows + WSL Ubuntu), in modalità debug, con dati di prova già pronti.

Su Mac e su Linux i comandi sono gli stessi: si lanciano dal terminale invece che da WSL, e "il browser di Windows" è il browser del computer. Su Mac la porta 5000 è spesso occupata da macOS: vedi la nota nel paragrafo 5.

## In breve

```bash
git clone https://github.com/fabiogiorgione/cartellaviva.git   # solo la prima volta
cd cartellaviva
bash scripts/setup-sviluppo.sh          # solo la prima volta, o per ripartire da zero
cd frontend
flutter run -d web-server --web-port 5000 --web-hostname 0.0.0.0 \
  --dart-define=API_BASE_URL=http://localhost:8090/api
```

In questa guida `cartellaviva` è la cartella in cui hai clonato il repository: i comandi partono da lì.

Poi apri **http://localhost:5000** nel browser di Windows ed entra con uno degli utenti di demo (password `CartellaViva!2026`).

---

## 1. Cosa c'è e dove gira

| Parte | Dove gira | Indirizzo |
| --- | --- | --- |
| API (Symfony) | container `php` | http://localhost:8090/api, documentazione su `/api/docs` |
| Worker (OCR, import, email, job pianificati) | container `worker` | — |
| Database PostgreSQL | container `database` | interno ai container |
| Email di prova (Mailpit) | container `mailer` | http://localhost:8025 |
| Modello AI locale (Ollama) | container `ollama` | interno ai container |
| Convertitore Office → PDF per le anteprime (Gotenberg) | container `convertitore` | interno, rete senza internet |
| App (Flutter) | WSL Ubuntu, `flutter run` | http://localhost:5000 |

Il backend gira tutto in Docker; l'app gira con Flutter installato direttamente in WSL.

## 2. Requisiti (una volta sola)

- **Docker Desktop** su Windows, con l'integrazione WSL attiva per la distribuzione *Ubuntu* (Settings → Resources → WSL integration). Deve essere acceso quando lavori: se `docker info` in WSL dà errore, avvia Docker Desktop.
- **Flutter 3.44.0** in WSL. In un terminale nuovo, `flutter --version` deve rispondere `Flutter 3.44.0`.
  - Per installarlo, clona la versione precisa, aggiungila al `PATH` e scarica gli strumenti per il web:

    ```bash
    git clone --branch 3.44.0 https://github.com/flutter/flutter.git ~/flutter
    echo 'export PATH="$HOME/flutter/bin:$PATH"' >> ~/.bashrc
    source ~/.bashrc
    flutter precache --web
    ```
  - Pacchetti di sistema facoltativi, che chiedono la password di `sudo`: `sudo apt install -y zip libglu1-mesa`. Non servono per il web.
  - `flutter doctor` segnala un "canale sconosciuto" perché è installata una versione precisa e non l'ultima "stable": è normale.
  - Segnala anche che mancano Chrome, Android SDK e Linux toolchain: per lavorare sul web non servono (vedi sotto).
- Un browser su Windows (Chrome, Edge, Firefox).

## 3. Primo avvio: container e database di prova

```bash
cd cartellaviva
bash scripts/setup-sviluppo.sh
```

Lo script fa questi passi:
1. crea i **segreti di questa installazione**, se mancano: la master key dei documenti cifrati in `secrets/`, e passphrase JWT, chiave di cifratura e `APP_SECRET` in `backend/.env.local`. Sono casuali, generati sulla tua macchina e mai nel repository. **Fai una copia della master key** (vedi `docs/gestione-chiavi.md`): senza, i documenti cifrati del database di sviluppo diventano illeggibili;
2. avvia i container (`docker compose up -d --build`);
3. installa le dipendenze PHP nel container (`composer install --ignore-platform-req=php`: la cartella `vendor/` non è nel repository, e il flag serve per un pacchetto di soli dati che dichiara PHP 7);
4. genera le chiavi JWT, applica le migrazioni e sincronizza permessi, ruoli e categorie;
5. **cancella e ricarica i dati di prova**: comuni ISTAT, utenti, famiglie, ente di demo.

Per avere anche la classificazione automatica e le scadenze proposte dall'AI, aggiungi `--modello`. Scarica circa 2,5 GB, una volta sola:

```bash
bash scripts/setup-sviluppo.sh --modello
```

> Lo stesso script serve per **riportare il database allo stato iniziale**. Attenzione: cancella i dati di sviluppo. Non va mai usato su un server reale.

## 4. Utenti di prova

Password per tutti: **`CartellaViva!2026`**

| Chi | Email | Cosa vede |
| --- | --- | --- |
| **Amministratore della piattaforma** | `admin@cartellaviva.local` | Il portale (`/admin`): salute del sistema, famiglie ed enti, utenti, recuperi d'accesso, idee, registro. Non vede mai i documenti delle famiglie. |
| **Ente di demo**: segreteria | `segreteria@scuola.example.org` | "Scuola Primaria Demo", già verificata. Ha l'anno in corso, le classi 3ªB e 4ªA e 4 iscritti; può inviare richieste, verificare le risposte e consultare il registro attività. |
| Ente di demo: operatrice | `maestra.bianchi@scuola.example.org` | Solo la 3ªB, cioè i suoi iscritti e le loro richieste. |
| **Utente semplice** | `mario.rossi@example.com` | Proprietario di "Famiglia Rossi", con il figlio Leo. Leo è iscritto in 3ªB e **già collegato** alla scuola, quindi le richieste della scuola arrivano a Mario. |
| Altri familiari | `giulia.rossi@example.com` (membro), `nonno.rossi@example.com` (sola lettura) | Famiglia Rossi con permessi diversi. |
| Altra famiglia | `luca.bianchi@example.com` | "Famiglia Bianchi", non vede nulla dei Rossi. È anche referente (da invitare) di Sara in 3ªB. |
| Nuovo utente | `nuovo.utente@example.com` | Senza famiglia e con l'anagrafica da compilare: per provare il primo accesso. |

Nella scuola c'è anche Anna Verdi (4ªA), con la mamma `paola.verdi@example.com` **senza account**. Serve a provare la risposta da link con codice via email.

Un flusso completo in 5 minuti:
1. entra come **segreteria** e invia una richiesta di firma alla 3ªB (con un PDF qualsiasi);
2. entra come **Mario**: la trovi in "Richieste". Firmala, con il codice se l'hai scelto, oppure carica la scansione;
3. torna come **segreteria** e usa "Verifica risposte" per accettarla o chiedere una correzione;
4. le email (inviti, codici, avvisi) arrivano su **http://localhost:8025**.

## 5. Avviare l'app in modalità sviluppo (debug)

```bash
cd cartellaviva/frontend
flutter run -d web-server --web-port 5000 --web-hostname 0.0.0.0 \
  --dart-define=API_BASE_URL=http://localhost:8090/api
```

- La prima compilazione richiede circa 1 minuto. Quando compare `lib/main.dart is being served at http://0.0.0.0:5000`, apri **http://localhost:5000** su Windows.
- È la modalità **debug**: con le asserzioni attive, gli errori completi nel terminale e nella console del browser (F12).
- Comandi nel terminale dove gira `flutter run`:

  | Tasto | Effetto |
  | --- | --- |
  | `r` | ricarica a caldo (hot reload), si mantiene lo stato |
  | `R` | riavvio completo dell'app |
  | `q` | esce |

  Dopo `r` o `R` aggiorna la pagina nel browser.
- Si usa `web-server` e non `chrome` perché Chrome non è installato dentro WSL. Il server gira in WSL e lo apri con il browser di Windows: `localhost` è condiviso.
- La porta **5000** non è casuale: è quella dei link nelle email (`FRONTEND_URL`). Il CORS di sviluppo accetta qualsiasi porta di `localhost`.
- **Su Mac** la 5000 è spesso occupata dal Ricevitore AirPlay di macOS ("Address already in use"). Disattivalo nelle Impostazioni di Sistema (cerca "AirPlay"), oppure usa un'altra porta, ad esempio `--web-port 5050`, e scrivila nel file `.env` accanto a `compose.yaml` (`FRONTEND_URL=http://localhost:5050`), poi lancia `docker compose up -d`.
- Opzionale: con `--dart-define=GOOGLE_WEB_CLIENT_ID=…` si attiva l'accesso con Google (vedi README, "Accesso con Google").

### Debug con VS Code

Con VS Code collegato a WSL (estensione *WSL*) e l'estensione *Flutter* installata in WSL, aggiungi a `frontend/.vscode/launch.json`:

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "CartellaViva (web, debug)",
      "request": "launch",
      "type": "dart",
      "program": "lib/main.dart",
      "deviceId": "web-server",
      "args": ["--web-port", "5000", "--web-hostname", "0.0.0.0",
               "--dart-define=API_BASE_URL=http://localhost:8090/api"]
    }
  ]
}
```

Con F5 parte il debug, con breakpoint e ispezione delle variabili. L'app si apre su http://localhost:5000 e l'hot reload scatta al salvataggio.

### Versione compilata (senza hot reload)

```bash
flutter build web --release
python3 -m http.server 5000 -d build/web
```

È più veloce da aprire, ma ricaricando direttamente un indirizzo interno (es. un link email) si ottiene un 404: partire sempre dalla home.

## 6. Il backend: log, comandi, debug

```bash
cd cartellaviva
docker compose ps                          # stato dei container
docker compose logs -f php worker          # log in tempo reale
docker compose exec php php bin/console    # elenco dei comandi
docker compose restart worker              # dopo modifiche a job pianificati, email, OCR
```

- Il codice PHP in `backend/` è montato nel container: le modifiche valgono subito, senza riavviare.
- Dopo una modifica alle entità, crea la migrazione con `docker compose exec php php bin/console doctrine:migrations:diff` e applicala con `… doctrine:migrations:migrate -n`.
- Gli errori dell'API in sviluppo contengono il dettaglio completo (classe, file, riga) nella risposta JSON: lo vedi negli strumenti del browser (F12 → Rete).

## 7. Test

```bash
docker compose exec php php bin/phpunit    # backend (ricrea il DB di test)
cd frontend && flutter analyze && flutter test
```

## 8. App per telefono (Android)

Per compilare l'app Android serve l'Android SDK, che in WSL non c'è: lo installa Android Studio. Dove l'SDK è presente, un APK di prova si compila così:

```bash
cd cartellaviva/frontend
flutter build apk --debug      # risultato: build/app/outputs/flutter-apk/app-debug.apk
```

Per provarlo su un telefono o su un emulatore con il debug collegato, il modo più semplice è installare Android Studio **su Windows** e aprire lì la cartella `frontend`.

Da un telefono o da un emulatore, `localhost` non è il PC: compila con `--dart-define=API_BASE_URL=http://<IP del PC>:8090/api`, oppure `http://10.0.2.2:8090/api` dall'emulatore Android.

Notifiche push e documenti offline funzionano solo nell'app per telefono; per le push servono le credenziali Firebase (README, "Notifiche push").

## 9. Problemi comuni

| Sintomo | Soluzione |
| --- | --- |
| `docker: command not found` o "failed to connect to the docker API" | Docker Desktop è spento o non ha l'integrazione WSL attiva. Avvialo e riprova. |
| L'app si apre ma l'accesso fallisce (errore di rete) | L'API non è su: `docker compose ps`, poi `docker compose up -d`. |
| "address already in use" sulla porta 5000 | C'è già un'altra istanza: chiudila (`q`), oppure trova il processo con `ss -ltnp \| grep 5000` (su Mac `lsof -nP -iTCP:5000 -sTCP:LISTEN`). Su Mac, se il processo è `ControlCenter`, è il Ricevitore AirPlay: vedi la nota sulla porta nel paragrafo 5. |
| L'API risponde con errore 500 (`APP_ENCRYPTION_KEY deve essere una chiave di 32 byte`) | Mancano i segreti di questa installazione in `backend/.env.local`: lancia `bash scripts/setup-sviluppo.sh`, oppure generali come nel README ("Avvio (sviluppo)", passo 1) e rigenera le chiavi JWT con `lexik:jwt:generate-keypair --overwrite -n`. |
| Le email non arrivano | Sono tutte su Mailpit, http://localhost:8025: in sviluppo nessuna email esce davvero. |
| Un campo nuovo del backend non compare nelle risposte (o l'app dà errori dopo un aggiornamento) | API Platform tiene in cache la descrizione delle risorse: `docker compose exec php php bin/console cache:clear`, **poi** `docker compose restart worker` (il worker usa la stessa cache: se la si svuota mentre gira, si blocca sul messaggio che sta elaborando). |
| Anteprima dei DOCX "non disponibile" | Controlla che giri il convertitore (`docker compose ps convertitore`) e la voce "Convertitore documenti" in Salute e da fare; poi "Riprova la conversione" nella scheda, oppure `docker compose exec php php bin/console app:anteprime:genera --fallite`. |
| Documenti cifrati illeggibili dopo un reset | È cambiata `secrets/master_key`: ripristina la copia o ricarica i dati con lo script. |
| Classificazione o scadenze AI assenti | Manca il modello: `bash scripts/setup-sviluppo.sh --modello`, oppure `docker compose exec ollama ollama pull qwen3:4b-instruct-2507-q4_K_M`. |
| Ricerca "Per significato" uguale a quella normale, nessun dato "Trovato nel testo" | Manca `bge-m3`: `docker compose exec ollama ollama pull bge-m3`, poi `docker compose exec php php bin/console app:documenti:indicizza`. I documenti cifrati non vengono mai analizzati. |
| `flutter: command not found` | Apri un terminale nuovo o esegui `source ~/.bashrc`. |
