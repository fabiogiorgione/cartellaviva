# Limiti noti e da fare

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

- **Installazione in proprio (produzione)**: `compose.prod.yaml`, `scripts/installa-server.sh`, `scripts/backup.sh` e `scripts/ripristina.sh`, con la guida [installazione-server.md](installazione-server.md).
  - il percorso è stato provato da zero su un Mac (`localhost`, certificato interno di Caddy) e poi su un server Linux vero: una VM Azure da 2 vCPU e 8 GB con Let's Encrypt, dove gira l'installazione di collaudo (novembre 2026). Su una macchina così l'AI va tenuta stretta: Ollama al massimo 5 GB, un modello e un'analisi alla volta, swap per PHP e worker (`compose.prod.yaml`);
  - una sola macchina con Docker Compose: niente alta disponibilità, e i backup vanno copiati fuori dal server a mano o con un proprio cron;
  - `compose.yaml` resta solo per lo sviluppo (codice montato, Mailpit, password del database predefinita);
  - l'app Android in release è firmata con la chiave di debug (`frontend/android/app/build.gradle.kts`): per distribuirla serve la propria chiave;
  - l'app per Mac (`connettore-mac/`) è firmata ad hoc: per distribuirla fuori dal proprio Mac servono un Developer ID e la notarizzazione Apple; mancano anche aggiornamenti automatici, icona e installer.
- **Test**:
  - backend: 328 test al 18/11/2026 (frontend 129, connettore Mac 14); alla versione 0.1.0 erano 240 e coprivano l'84% delle righe di `backend/src`;
  - frontend (misura alla 0.1.0): 110 test, che toccano 89 file su 185 di `frontend/lib` e, di quelli, il 34% delle righe. Le parti meno coperte sono l'account (13%) e il layout (9%).
- **Analisi statica**: PHPStan è al livello 5, con 51 segnalazioni ancora in `backend/phpstan-baseline.neon`. L'analizzatore di Flutter dà 8 avvisi informativi.
- **Dipendenze**:
  - cinque pacchetti in `backend/composer.json` hanno vincolo `*` (`api-platform/symfony`, `gesdinet/jwt-refresh-token-bundle`, `lexik/jwt-authentication-bundle`, `nelmio/cors-bundle`, `symfony/monolog-bundle`): le versioni installate sono quelle fissate da `composer.lock`;
  - `composer.json` dichiara PHP ≥ 8.2, ma il progetto è provato solo con PHP 8.4, la versione dell'immagine Docker;
  - le immagini di base della build non sono fissate a una versione precisa: `dunglas/frankenphp:1-php8.4` segue l'ultima 1.x con PHP 8.4, `ghcr.io/cirruslabs/flutter:3.44.0` è fissata alla versione di Flutter ma non al digest. Ricostruendo si possono ottenere versioni più recenti;
  - `devnix/belfiore-code` 0.1 (solo dati: comuni e stati per il codice fiscale) dichiara di richiedere PHP 7, quindi `composer install` va lanciato con `--ignore-platform-req=php`. Le versioni successive dichiarano PHP 8: l'aggiornamento è da fare.
- **Flutter**: l'interfaccia è solo in italiano (testi nel codice). Per aggiungere una lingua servono le traduzioni (es. `flutter_localizations` con file ARB) e `supportedLocales` in `main.dart`.
- **Token sul web**: `flutter_secure_storage` sul web usa localStorage cifrato, che resta esposto a XSS. In futuro si può spostare il refresh token in un cookie HttpOnly (Gesdinet supporta `cookie:`).
- **Identificativi**: package Dart `cartellaviva`; `applicationId` Android e bundle id iOS/macOS `it.cartellaviva.app`. Non cambiarli dopo la prima pubblicazione sugli store (si crea un'app diversa). Le app Android e iOS registrate in Firebase devono usare questo identificativo.
- **Analisi dei documenti**: solo le regole ISEE 2026 sono verificate sulle istruzioni INPS; quelle dal 2020 al 2025 e del 2027 sono copie provvisorie delle 2026 (`verificato` vuoto nei file di `config/pratiche/`). L'analisi con il modello Apple sull'iPhone è provata sul simulatore; il connettore Mac su un solo Mac.
- **Produzione**:
  - con `compose.prod.yaml`, `JWT_PASSPHRASE`, `APP_SECRET`, `APP_ENCRYPTION_KEY`, la password del database e `MAILER_DSN` stanno nel file `.env` del server (permessi 600, fuori dal repository) e arrivano ai container come variabili d'ambiente: chi può usare Docker sul server (root, gruppo `docker`) li vede con `docker inspect`. La master key invece è un secret Docker (file montato in sola lettura). Miglioramento possibile: passare anche gli altri come secret, letti con `%env(file:...)%`. `APP_ENCRYPTION_KEY` non va mai cambiata senza ri-cifrare i segreti TOTP;
  - le attività pianificate (`src/Schedule.php`, oggi la pulizia oraria di challenge 2FA, `state` OAuth e link di esportazione scaduti, l'eliminazione delle famiglie arrivate a scadenza e i promemoria giornalieri delle scadenze) girano nel worker sul trasporto `scheduler_default`: il worker deve essere sempre attivo;
  - **master key dei documenti:** secret della VM con backup separato. È il punto più critico: vedi [gestione-chiavi.md](gestione-chiavi.md);
  - backup di DB e volume `document_storage` insieme: `scripts/backup.sh` (ripristino: `scripts/ripristina.sh`), da copiare fuori dal server;
  - la retention (`data_conservazione_fino`) per ora è solo un campo: nessuna cancellazione automatica;
  - valutare l'obbligo di 2FA per il superadmin;
  - `MAILER_DSN` reale e `CORS_ALLOW_ORIGIN` sul dominio li imposta `scripts/installa-server.sh`.
