# Gestione della master key dei documenti

La master key protegge **tutti i documenti delle categorie sensibili** (Documenti Identità, Salute, Farmaci, Da categorizzare e quelle personalizzate con "sensibile"). Protegge sia i file sia il loro testo OCR, e anche i **token OAuth delle sorgenti di import** (Google Drive).

> **Se la master key va persa, quei documenti sono irrecuperabili.**
> Nessun backup del database o dei file può sostituirla.

## Come funziona

```
master key (file secret, fuori dal DB)
   └─ HKDF(master key, id tenant, versione) = chiave del tenant   (mai salvata: si ricalcola)
         └─ cifra la chiave casuale di ogni documento (DEK)        (salvata nel DB solo cifrata)
               └─ cifra il file e il testo OCR                      (XChaCha20-Poly1305)
```

- **Dove sta:** in un file montato come Docker secret (`/run/secrets/master_key`). Non sta nel codice, nel `.env` o nel database.
- **Formato:** una riga per versione, `versione:chiave_base64`. La versione più alta è quella usata per i nuovi documenti.
- **Controllo di sicurezza:** la tabella `crypto_key_version` contiene solo l'impronta (non segreta) di ogni versione, ed è immutabile. Se viene montato un file di chiave diverso, l'applicazione si rifiuta di cifrare e decifrare. `app:crypto:key status` mostra l'esito.

## Cosa va salvato, e dove

| Cosa | Contiene | Dove conservarlo |
| --- | --- | --- |
| Backup del DB (`pg_dump`) | dati, chiavi dei documenti cifrate, impronte | backup ordinario |
| Volume `document_storage` | file (quelli sensibili cifrati) | backup ordinario, insieme al DB |
| **Backup della master key** | la master key, cifrata con una passphrase | **separato**, vedi sotto |
| **Passphrase del backup** | — | **separata dal backup della chiave** |

Chi ottiene il backup del DB e dei file, senza la chiave, non legge i documenti sensibili. Per questo **la chiave non deve mai finire nello stesso backup, bucket o disco**.

Conservazione consigliata del backup della chiave:
- **due copie offline** del file di export, per esempio una chiavetta in cassaforte e una copia stampata;
- **passphrase** in un password manager personale, non sulla VM e non accanto alle copie.

## Prima installazione

```bash
# sull'host, nella cartella del progetto (./secrets è in .gitignore)
mkdir -p secrets && chmod 700 secrets
umask 077
printf '# CartellaViva master key\n1:%s\n' "$(head -c 32 /dev/urandom | base64)" > secrets/master_key
docker compose up -d
```

Poi, **subito**, fai il backup (vedi la sezione successiva). L'impronta viene registrata nel DB al primo documento cifrato; per verificarla:

```bash
docker compose exec php php bin/console app:crypto:key status
```

## Backup della chiave

```bash
docker compose exec php php bin/console app:crypto:key export --file=/tmp/cartellaviva.fvkey-backup
docker compose cp php:/tmp/cartellaviva.fvkey-backup .   # poi spostalo fuori dalla VM
docker compose exec php rm /tmp/cartellaviva.fvkey-backup
```

- Viene chiesta una passphrase di almeno 16 caratteri. Il file è protetto con Argon2id + XSalsa20-Poly1305 e riporta in chiaro solo le impronte, per riconoscerlo.
- **Rifai l'export dopo ogni rotazione** (nuova versione della chiave).

### Prova di ripristino (ogni 3 mesi)

Un backup mai provato non è un backup. La prova va fatta su un file temporaneo, senza toccare la chiave in uso:

```bash
docker compose cp cartellaviva.fvkey-backup php:/tmp/b
docker compose exec php php bin/console app:crypto:key restore --file=/tmp/b --output=/tmp/prova.key
docker compose exec php sh -c 'cmp /tmp/prova.key /run/secrets/master_key && echo OK; rm /tmp/b /tmp/prova.key'
```

Il `restore` confronta anche la chiave ripristinata con le impronte registrate nel DB.

## Disaster recovery (VM persa)

1. Ripristina il DB e il volume `document_storage` dai backup ordinari.
2. Ripristina la chiave dal backup separato:
   ```bash
   mkdir -p secrets && chmod 700 secrets
   docker compose run --rm --no-deps -v "$PWD/secrets:/out" -v "$PWD/cartellaviva.fvkey-backup:/tmp/b:ro" \
     php php bin/console app:crypto:key restore --file=/tmp/b --output=/out/master_key
   ```
   Se `./secrets/master_key` non esiste ancora, Docker potrebbe creare al suo posto una cartella vuota: cancellala prima del comando.
3. Esegui `docker compose up -d`, poi `app:crypto:key status`: tutte le versioni devono risultare **OK**.
4. Scarica un documento sensibile di prova.

## Rotazione della chiave

Da fare periodicamente (ogni anno), o subito se si sospetta che il file della chiave sia stato esposto.

```bash
# 1. aggiunge la versione N+1 al file (sull'host: il secret nel container è in sola lettura)
docker compose run --rm --no-deps -v "$PWD/secrets:/out" php php bin/console app:crypto:key add-version --file=/out/master_key
# 2. riavvia API e worker: i nuovi documenti usano la nuova versione
docker compose restart php worker
# 3. ricifra con la nuova versione le chiavi dei documenti esistenti (i file non si riscrivono)
docker compose exec php php bin/console app:crypto:key rewrap
# 4. nuovo backup
docker compose exec php php bin/console app:crypto:key export --file=/tmp/cartellaviva.fvkey-backup
```

Una versione vecchia si può eliminare dal file solo quando `status` mostra **0 documenti e 0 sorgenti di import** per quella versione. Si possono rimuovere le versioni ≥ 2; la **versione 1 non va mai rimossa**, perché serve anche all'HMAC dei duplicati.

**Limite:** la rotazione ricifra le chiavi dei documenti, non i file. Se un attaccante ha avuto insieme la vecchia master key e una copia del DB, può aver ricavato le chiavi dei documenti. In quel caso serve una ricifratura completa dei file, che non è ancora implementata.

## Da non fare

- Mettere la chiave nel `.env`, nel repository, in una variabile d'ambiente di CI o nel backup del DB.
- Tenere il backup della chiave e la sua passphrase nello stesso posto.
- Sostituire il file della chiave "per provare": l'app lo rifiuta, ma i documenti caricati nel frattempo potrebbero fallire.
- Cancellare righe da `crypto_key_version` (un trigger lo impedisce).

## Migrazione futura su AKS

Il percorso resta lo stesso: la chiave si monta in `/run/secrets/master_key` da un Secret Kubernetes o, meglio, da Azure Key Vault tramite il driver CSI. Il codice non cambia.
