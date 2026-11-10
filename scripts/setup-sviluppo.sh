#!/usr/bin/env bash
# CartellaViva — prepara l'ambiente di SVILUPPO da zero (o lo riporta allo stato iniziale).
#
#   bash scripts/setup-sviluppo.sh            avvia i container e ricarica i dati di demo
#   bash scripts/setup-sviluppo.sh --modello  in più scarica i modelli AI di Ollama (~2,5 + 1,2 GB)
#
# ATTENZIONE: ricarica le fixture, quindi CANCELLA i dati del database di sviluppo
# (tranne audit, versioni della chiave e coda dei messaggi). Mai su un server vero.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! docker info >/dev/null 2>&1; then
  echo "Docker non risponde: avvia Docker Desktop (con l'integrazione WSL attiva) e riprova." >&2
  exit 1
fi

# 1. Master key dei documenti cifrati: si crea una volta sola e va salvata a parte.
if [ ! -s secrets/master_key ]; then
  echo "== Creo la master key in secrets/ (FANNE IL BACKUP: docs/gestione-chiavi.md)"
  mkdir -p secrets && chmod 700 secrets
  (umask 077; printf '# CartellaViva master key\n1:%s\n' "$(head -c 32 /dev/urandom | base64)" > secrets/master_key)
fi

# 2. Segreti di questa installazione (fuori dal repository): si generano se mancano.
#    backend/.env.local: dev; backend/.env.test.local: i test usano la stessa chiave JWT.
if [ ! -s backend/.env.local ]; then
  echo "== Genero i segreti locali in backend/.env.local"
  (umask 077; {
    echo '# Segreti di QUESTA installazione: mai nel repository (.gitignore).'
    echo "JWT_PASSPHRASE=$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 40)"
    echo "APP_ENCRYPTION_KEY=$(head -c 32 /dev/urandom | base64)"
    echo "APP_SECRET=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  } > backend/.env.local)
fi
if [ ! -s backend/.env.test.local ]; then
  (umask 077; { echo '# Test: stessa passphrase della chiave JWT locale.'; grep '^JWT_PASSPHRASE=' backend/.env.local; } > backend/.env.test.local)
fi

echo "== Avvio dei container"
docker compose up -d --build

echo "== Attendo che l'API sia pronta"
for _ in $(seq 1 60); do
  docker compose exec -T php php -v >/dev/null 2>&1 && break
  sleep 2
done

run() { echo "\$ $*"; docker compose exec -T php "$@"; }
# Le dipendenze PHP (vendor/) non sono nel repository: si installano nel container.
# --ignore-platform-req=php: devnix/belfiore-code 0.1 (solo dati: comuni e stati) dichiara
# PHP 7; le versioni installate restano quelle di composer.lock.
run composer install --no-interaction --ignore-platform-req=php
run php bin/console lexik:jwt:generate-keypair --skip-if-exists
run php bin/console doctrine:migrations:migrate -n
run php bin/console app:permissions:sync
run php bin/console app:roles:sync
run php bin/console app:categorie:sync
echo "== Dati di demo (comuni, utenti, famiglie, ente)"
run php bin/console doctrine:fixtures:load -n \
  --purge-exclusions=audit_log --purge-exclusions=crypto_key_version --purge-exclusions=messenger_messages
docker compose restart worker >/dev/null

if [ "${1:-}" = "--modello" ]; then
  echo "== Scarico i modelli AI (classificazione, scadenze, dati estratti; ricerca per significato)"
  docker compose exec -T ollama ollama pull qwen3:4b-instruct-2507-q4_K_M
  docker compose exec -T ollama ollama pull bge-m3
fi

cat <<'TXT'

Fatto. Password di tutti gli utenti di demo: CartellaViva!2026
  admin@cartellaviva.local            amministratore della piattaforma (portale)
  segreteria@scuola.example.org       segreteria di "Scuola Primaria Demo" (ente verificato)
  maestra.bianchi@scuola.example.org  operatrice della 3ªB
  mario.rossi@example.com             utente semplice: proprietario di "Famiglia Rossi"
API http://localhost:8090/api/docs · Email http://localhost:8025
TXT
