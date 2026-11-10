#!/usr/bin/env bash
# CartellaViva — installa un server di PRODUZIONE, o lo aggiorna (rilanciato).
#
#   bash scripts/installa-server.sh            prima installazione, oppure aggiornamento
#   bash scripts/installa-server.sh --modelli  in più scarica i modelli AI (~3,7 GB, una volta sola)
#
# Guida: docs/installazione-server.md. Alla prima installazione chiede dominio, email e
# server SMTP, poi genera QUI tutti i segreti (master key, chiavi, password del database):
# ogni installazione ha i suoi, nessuno è nel repository. Non carica mai dati di prova.
#
# Senza terminale (automazione) i valori si passano come variabili d'ambiente:
#   DOMINIO, ACME_EMAIL, MAILER_DSN, MAILER_FROM (facoltativa), SUPERADMIN_EMAIL (facoltativa).
set -euo pipefail
cd "$(dirname "$0")/.."

MODELLI=0
[ "${1:-}" = "--modelli" ] && MODELLI=1

errore() { echo "ERRORE: $*" >&2; exit 1; }
passo() { echo; echo "== $*"; }
casuale_b64() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }
casuale_alnum() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# --- Requisiti ------------------------------------------------------------------
command -v docker >/dev/null || errore "Docker non è installato (docs/installazione-server.md, passo 2)."
docker compose version >/dev/null 2>&1 || errore "Manca il plugin 'docker compose' (Compose v2)."
docker info >/dev/null 2>&1 || errore "Docker non risponde, o questo utente non può usarlo (gruppo 'docker', oppure sudo)."

# I container girano con l'utente 1000: su Linux la master key deve essere leggibile da lui.
# (Docker Desktop su Mac e Windows non applica i permessi dell'host ai file condivisi.)
UTENTE=$(id -u)
if [ "$(uname -s)" = Linux ] && [ "$UTENTE" != 0 ] && [ "$UTENTE" != 1000 ]; then
  errore "Lancia lo script come l'utente con uid 1000 (di solito il primo utente della VM) o con sudo."
fi

# --- File .env: valori e segreti di questa installazione --------------------------
PRIMA_VOLTA=0
if [ -f .env ]; then
  grep -q '^COMPOSE_FILE=compose.prod.yaml$' .env \
    || errore "C'è già un file .env che non è di produzione (forse di sviluppo): spostalo prima di continuare."
  echo "File .env trovato: aggiorno l'installazione esistente (i segreti non cambiano)."
else
  PRIMA_VOLTA=1
  passo "Prima installazione: dati del server"
  chiedi() { # chiedi VARIABILE "domanda" [predefinito]
    local nome=$1 domanda=$2 predefinito=${3:-} valore=${!1:-}
    if [ -z "$valore" ] && [ -t 0 ]; then
      read -r -p "$domanda${predefinito:+ [$predefinito]}: " valore
    fi
    valore=${valore:-$predefinito}
    [ -n "$valore" ] || [ -t 0 ] || errore "Manca $nome (senza terminale va passato come variabile d'ambiente)."
    [ -n "$valore" ] || errore "$nome è obbligatorio."
    printf -v "$nome" '%s' "$valore"
  }
  chiedi DOMINIO "Dominio dell'app (es. app.example.org), già puntato all'IP di questo server"
  [[ "$DOMINIO" =~ ^[A-Za-z0-9.-]+$ ]] || errore "Dominio non valido: $DOMINIO"
  chiedi ACME_EMAIL "Email per Let's Encrypt (avvisi sui certificati)"
  chiedi MAILER_DSN "Server SMTP in formato DSN (es. smtp://utente:password@smtp.example.org:587)"
  # Solo l'indirizzo: il nome "CartellaViva" lo aggiunge l'app.
  chiedi MAILER_FROM "Indirizzo mittente delle email" "no-reply@$DOMINIO"
  [[ "$MAILER_FROM" =~ ^[^[:space:]\<\>@]+@[^[:space:]\<\>@]+$ ]] || errore "Mittente non valido: scrivi solo l'indirizzo (es. no-reply@$DOMINIO)."

  passo "Genero i segreti di questa installazione"
  CORS_REGEX="^https://${DOMINIO//./\\.}\$"
  (
    umask 077
    {
      echo "# CartellaViva — PRODUZIONE. Segreti di QUESTO server: mai nel repository, mai copiati altrove."
      echo "COMPOSE_FILE=compose.prod.yaml"
      echo "DOMINIO=$DOMINIO"
      echo "ACME_EMAIL=$ACME_EMAIL"
      echo "MAILER_DSN='$MAILER_DSN'"
      echo "MAILER_FROM='$MAILER_FROM'"
      echo "CORS_ALLOW_ORIGIN='$CORS_REGEX'"
      echo "# 0: si entra solo su invito (README, \"Registrazione aperta o chiusa\")."
      echo "REGISTRAZIONE_APERTA=0"
      echo "POSTGRES_PASSWORD=$(casuale_alnum 40)"
      echo "APP_SECRET=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
      echo "JWT_PASSPHRASE=$(casuale_alnum 40)"
      echo "APP_ENCRYPTION_KEY=$(casuale_b64 32)"
    } > .env
  )
  echo "Scritto .env (permessi 600)."
fi

# --- Master key dei documenti sensibili ---------------------------------------------
if [ ! -s secrets/master_key ]; then
  [ "$PRIMA_VOLTA" = 1 ] || errore "Manca secrets/master_key su un'installazione esistente: ripristinala dal backup (docs/gestione-chiavi.md). Senza, i documenti cifrati sono illeggibili."
  passo "Creo la master key dei documenti sensibili"
  mkdir -p secrets && chmod 700 secrets
  (umask 077; printf '# CartellaViva master key\n1:%s\n' "$(casuale_b64 32)" > secrets/master_key)
fi
if [ "$(uname -s)" = Linux ] && [ "$UTENTE" = 0 ]; then
  chown 1000:1000 secrets secrets/master_key
fi

# --- Immagini e avvio -----------------------------------------------------------------
passo "Costruisco le immagini (la prima volta anche l'app web: alcuni minuti)"
docker compose build --pull

passo "Avvio database e API"
docker compose up -d --wait database php

run() { echo "\$ $*"; docker compose exec -T php "$@"; }
run php bin/console lexik:jwt:generate-keypair --skip-if-exists
run php bin/console doctrine:migrations:migrate -n --allow-no-migration
run php bin/console app:permissions:sync
run php bin/console app:roles:sync
run php bin/console app:categorie:sync
# Stati e comuni: solo su un database nuovo (l'importazione svuota e ricarica le tabelle).
COMUNI=$(docker compose exec -T database sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*) from geo_comune"')
if [ "$COMUNI" = 0 ]; then
  run php bin/console app:geo:import
fi

passo "Avvio gli altri servizi"
docker compose up -d --remove-orphans
# Il worker riparte con il codice e lo schema nuovi.
docker compose restart worker >/dev/null

if [ "$MODELLI" = 1 ]; then
  passo "Scarico i modelli AI (classificazione e scadenze; ricerca per significato)"
  docker compose exec -T ollama ollama pull qwen3:4b-instruct-2507-q4_K_M
  docker compose exec -T ollama ollama pull bge-m3
fi

# --- Primo accesso ------------------------------------------------------------------
DOMINIO=$(grep '^DOMINIO=' .env | cut -d= -f2-)
if [ "$PRIMA_VOLTA" = 1 ]; then
  passo "Amministratore della piattaforma (superadmin)"
  if [ -z "${SUPERADMIN_EMAIL:-}" ] && [ -t 0 ]; then
    read -r -p "Email del superadmin (vuoto per farlo dopo): " SUPERADMIN_EMAIL
  fi
  if [ -n "${SUPERADMIN_EMAIL:-}" ] && [ -t 0 ]; then
    docker compose exec php php bin/console app:user:create-superadmin "$SUPERADMIN_EMAIL"
  else
    echo "Da fare dopo: docker compose exec php php bin/console app:user:create-superadmin tua@email.it"
  fi
fi

passo "Controllo HTTPS su https://$DOMINIO"
OK=0
for _ in $(seq 1 30); do
  if curl -fsS -o /dev/null "https://$DOMINIO/api/auth/registrazione" 2>/dev/null; then OK=1; break; fi
  sleep 4
done
if [ "$OK" = 1 ]; then
  echo "L'app risponde su https://$DOMINIO"
else
  echo "ATTENZIONE: https://$DOMINIO non risponde ancora. Controlla che il DNS punti a questo server" >&2
  echo "e che le porte 80 e 443 siano aperte, poi: docker compose logs php" >&2
fi

cat <<TXT

Fatto. Prossimi passi (docs/installazione-server.md):
  1. BACKUP DELLA MASTER KEY, adesso e fuori da questo server:
       docker compose exec php php bin/console app:crypto:key export --file=/tmp/cartellaviva.fvkey-backup
     (docs/gestione-chiavi.md: due copie offline, passphrase in un password manager).
  2. Come superadmin, dal portale: "Nuova famiglia" con l'email del proprietario.
  3. Backup periodico: bash scripts/backup.sh (va copiato fuori dal server).
Per aggiornare: git pull, poi di nuovo bash scripts/installa-server.sh
TXT
