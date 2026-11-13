# Installare CartellaViva su un proprio server

Guida per chi vuole far girare CartellaViva su un server suo, raggiungibile da internet, con HTTPS. Tutto gira con Docker Compose su una sola macchina: l'API, l'app web, il database, i modelli AI e il convertitore delle anteprime. I documenti e il loro testo non escono dal server.

> **Stato:** è la prima versione della guida. Il percorso è stato provato da zero:
> - su un Mac, con `localhost` e il certificato interno di Caddy: installazione, app web e API, registrazione chiusa, caricamento e download di un documento cifrato, riavvio, backup e ripristino, aggiornamento;
> - su una VM Azure D2s v5 con Ubuntu 24.04: installazione con i modelli AI e certificato Let's Encrypt.
>
> Non è ancora stato provato con un servizio SMTP reale. Se qualcosa non torna, apri una issue (senza dati personali).

## Cosa serve

- **Un server Linux a 64 bit** (amd64 o arm64), con Ubuntu Server 24.04 LTS o un'altra distribuzione con Docker. Per una famiglia, con l'AI accesa: 2 vCPU e 8 GB di RAM (su Azure, ad esempio, una D2s v5). Il modello di classificazione da solo usa 3-4 GB di RAM; su CPU è lento: con 2 vCPU l'analisi di ogni documento richiede tempo.
- **Disco**: almeno 64 GB. Le immagini occupano circa 11,5 GB (app 1,4, database 0,4, Ollama 7,2, convertitore 2,4; misure su arm64), i modelli AI 3,7 GB, e la build lascia una cache di alcuni GB (`docker builder prune` la libera). Poi servono lo spazio dei documenti e dei backup. Su Azure il disco di sistema predefinito di Ubuntu è di 30 GB: aumentalo alla creazione della VM.
- **Un nome di dominio** (es. `app.example.org`) con un record DNS `A` che punta all'IP pubblico del server. Va bene anche un sottodominio.
- **Swap.** Lo script crea uno swap di 4 GB (`/swapfile`, swappiness 10) se la VM non ne ha: senza, un picco di memoria del modello AI blocca tutta la VM. Serve `sudo` senza password; altrimenti, a mano: `sudo fallocate -l 4G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile`, poi la riga `/swapfile none swap sw 0 0` in `/etc/fstab`. I container `php` e `worker` hanno un tetto di memoria (1 GB e 1,5 GB): se lo superano si riavvia solo quel container. `ollama` ha 5 GB senza swap e un contesto fisso di 8.192 token: oltre il tetto il kernel chiude solo il modello, il documento in analisi finisce in errore e "Cerca di nuovo" lo riprova.
- **Un servizio SMTP** per le email (verifica dell'indirizzo, inviti, codici, promemoria), con porta 587 o 465. Su Azure la porta 25 in uscita è bloccata nella maggior parte delle sottoscrizioni.
- **Docker Engine con il plugin Compose v2** (`docker compose version` deve rispondere).

## 1. Rete e DNS

Sul firewall del server (su Azure: il gruppo di sicurezza di rete della VM):

| Porta | Da dove | A cosa serve |
| --- | --- | --- |
| 80/tcp | ovunque | Let's Encrypt (verifica del dominio) e rimando a HTTPS |
| 443/tcp e 443/udp | ovunque | l'app (HTTPS e HTTP/3) |
| 22/tcp | solo il tuo IP | SSH, con chiave e senza password |

Nient'altro: database, modelli AI e convertitore non pubblicano porte e sono raggiungibili solo dalla rete interna dei container.

Crea il record DNS `A` del dominio verso l'IP pubblico del server **prima** dell'installazione: al primo avvio Caddy chiede il certificato a Let's Encrypt e il dominio deve già arrivare al server.

## 2. Docker

Installa Docker Engine e il plugin Compose seguendo la guida ufficiale per la tua distribuzione: <https://docs.docker.com/engine/install/>. Poi, per usarlo senza `sudo`:

```bash
sudo usermod -aG docker "$USER"   # poi esci e rientra
docker compose version
```

## 3. Codice

```bash
git clone https://github.com/fabiogiorgione/cartellaviva.git
cd cartellaviva
```

Lancia gli script come il primo utente della VM (uid 1000, di solito quello creato all'installazione) o con `sudo`: i container girano con l'utente 1000 e devono poter leggere la master key.

## 4. Installazione

```bash
bash scripts/installa-server.sh            # aggiungi --modelli per scaricare i modelli AI (~3,7 GB)
```

Lo script chiede:
- il **dominio** (es. `app.example.org`);
- l'**email per Let's Encrypt**, a cui arrivano gli avvisi sui certificati;
- il **server SMTP** in formato DSN, ad esempio `smtp://utente:password@smtp.example.org:587` (se la password contiene caratteri speciali, vanno codificati come in un URL);
- l'**indirizzo mittente** delle email, solo l'indirizzo (predefinito `no-reply@<dominio>`): il nome "CartellaViva" lo aggiunge l'app. Il servizio SMTP deve accettare quel mittente.

Poi, sul server:
1. **genera i segreti di questa installazione**: la master key dei documenti in `secrets/master_key` e, nel file `.env`, la password del database, `APP_SECRET`, la passphrase delle chiavi JWT e la chiave di cifratura. Sono casuali, nessuno è nel repository, e i file hanno permessi `600`. **Non vanno mai condivisi né copiati da un'altra installazione**;
2. costruisce le immagini, compresa l'app web compilata per il tuo dominio (la prima volta richiede alcuni minuti);
3. avvia i servizi, crea le chiavi JWT, applica le migrazioni, carica categorie, stati e comuni. **Non carica mai dati di prova**;
4. chiede l'email dell'amministratore della piattaforma (superadmin) e la sua password;
5. controlla che `https://<dominio>` risponda.

La registrazione libera è **chiusa** (`REGISTRAZIONE_APERTA=0` nel file `.env`): nessuno può crearsi un account da solo, si entra solo su invito. Vedi il README, "Registrazione aperta o chiusa".

## 5. Subito dopo: backup della master key

La master key protegge i documenti delle categorie sensibili. **Senza, sono illeggibili per sempre**, anche con tutti gli altri backup. Esportala subito e portala fuori dal server:

```bash
docker compose exec php php bin/console app:crypto:key export --file=/tmp/cartellaviva.fvkey-backup
docker compose cp php:/tmp/cartellaviva.fvkey-backup .
docker compose exec php rm /tmp/cartellaviva.fvkey-backup
# dal tuo computer: scp utente@server:cartellaviva/cartellaviva.fvkey-backup .   poi cancellalo dal server
```

Il file è protetto da una passphrase che scegli tu (almeno 16 caratteri). Tienine due copie offline e la passphrase in un password manager, separata dalle copie. Dettagli, prova di ripristino e rotazione: [gestione-chiavi.md](gestione-chiavi.md).

## 6. Primo accesso

1. Apri `https://<dominio>` ed entra come superadmin: arrivi al portale della piattaforma, che non vede mai i documenti delle famiglie.
2. In "Famiglie ed enti" usa **"Nuova famiglia"** con l'email di chi ne sarà proprietario. Deve essere diversa da quella del superadmin: gli account del team del portale non possono essere membri di una famiglia.
3. Il proprietario riceve l'email di attivazione, sceglie la password ed entra.
4. Dalla sua famiglia invita gli altri: ognuno crea l'account dal link dell'invito.

Se non hai creato il superadmin durante l'installazione:

```bash
docker compose exec php php bin/console app:user:create-superadmin tua@email.it
```

## 6 bis. Import da Google Drive (facoltativo)

Serve solo se le famiglie vogliono importare documenti da una cartella di Google Drive. Senza, l'app funziona e l'import da Drive risponde "provider non configurato".

1. In [Google Cloud Console](https://console.cloud.google.com/) crea un progetto e abilita la **Google Drive API**.
2. Configura la **schermata di consenso OAuth**: tipo **Esterno**, scope `.../auth/drive.readonly`, e aggiungi come **utenti di test** gli account Google di chi importerà.
3. Crea le credenziali: **ID client OAuth** di tipo **Applicazione web**, con URI di reindirizzamento autorizzato `https://<dominio>/api/import-sources/oauth/callback`.
4. Sul server, nel file `.env`, aggiungi:
   ```
   GOOGLE_OAUTH_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_OAUTH_CLIENT_SECRET=...
   ```
   poi `docker compose up -d`. L'URI di reindirizzamento lo ricava `compose.prod.yaml` dal dominio.

`drive.readonly` per Google è uno scope *restricted*. Finché l'app resta "in test":
- possono collegarsi solo gli utenti di test dichiarati (al massimo 100);
- Google fa scadere il collegamento dopo 7 giorni: per sincronizzare di nuovo bisogna ricollegare la cartella.

Per aprirla a tutti servono la verifica di Google e un audit di sicurezza annuale (CASA).

## 7. Backup periodici

```bash
bash scripts/backup.sh                 # in ./backups; CONSERVA=14 (predefinito) tiene gli ultimi 14
```

Ogni backup sono due file con la stessa data: il database (`.db.dump`) e i documenti (`.documenti.tar.gz`, con quelli sensibili ancora cifrati). **La master key non c'è, di proposito**: ha il suo backup separato (passo 5).

I backup sul server non bastano: se si perde il server si perdono anche loro. Copiali altrove, per esempio con `scp`, `rsync` o `rclone` verso un altro computer o uno spazio cloud, ogni giorno. Un esempio con cron (ogni notte alle 2):

```bash
crontab -e
0 2 * * * cd /home/<utente>/cartellaviva && bash scripts/backup.sh >> backups/backup.log 2>&1
```

**Prova il ripristino** ogni tanto, meglio su un'installazione nuova:

```bash
bash scripts/ripristina.sh backups/cartellaviva-AAAAMMGG-HHMMSS
```

Il ripristino **sostituisce** database e documenti dell'installazione su cui lo lanci e chiede di scrivere `RIPRISTINA` per conferma. Serve la stessa master key dell'installazione che ha fatto il backup: alla fine lo script mostra lo stato della chiave, e tutte le versioni devono risultare OK. Server perso del tutto: [gestione-chiavi.md](gestione-chiavi.md), "Disaster recovery".

## 8. Aggiornamenti

```bash
bash scripts/backup.sh
git pull
bash scripts/installa-server.sh
```

Rilanciato, lo script non cambia i segreti: ricostruisce le immagini, applica le migrazioni nuove e riavvia i servizi.

## 9. Comandi utili

```bash
docker compose ps                         # stato dei servizi
docker compose logs -f php worker         # log in tempo reale (API e lavori in coda)
docker compose restart worker             # riavvia i lavori in coda
docker compose exec php php bin/console   # comandi dell'applicazione
```

Nella cartella del progetto `docker compose` usa già `compose.prod.yaml`: lo dice la riga `COMPOSE_FILE` del file `.env`.

## 10. Problemi comuni

| Sintomo | Cosa controllare |
| --- | --- |
| Il browser segnala un certificato non valido, o `https://` non risponde | Il DNS punta all'IP del server (`dig +short <dominio>`)? Le porte 80 e 443 sono aperte? `docker compose logs php` mostra il motivo. Dopo molti tentativi falliti Let's Encrypt blocca le richieste per un po': correggi e aspetta. |
| Le email non arrivano | Il valore di `MAILER_DSN` nel file `.env`; i log del worker (`docker compose logs worker`). Su Azure usa la porta 587 o 465, non la 25. Dopo una modifica: `docker compose up -d`. |
| L'API risponde con errore 500 | `docker compose logs php`: l'errore completo è nei log, non nella risposta. |
| Classificazione e scadenze proposte non compaiono | Mancano i modelli: `bash scripts/installa-server.sh --modelli`. Su CPU l'analisi richiede tempo: guarda `docker compose logs worker`. |
| "C'è già un file .env che non è di produzione" | Nella cartella c'è un `.env` di sviluppo. Usa un clone separato per il server. |
