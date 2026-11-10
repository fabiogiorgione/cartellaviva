#!/usr/bin/env bash
# CartellaViva — ripristina database e documenti da un backup di scripts/backup.sh.
#
#   bash scripts/ripristina.sh backups/cartellaviva-AAAAMMGG-HHMMSS
#
# SOSTITUISCE database e documenti attuali con quelli del backup. Serve la stessa master
# key dell'installazione che ha fatto il backup (secrets/master_key): con un'altra chiave
# i documenti sensibili restano illeggibili (docs/gestione-chiavi.md, "Disaster recovery").
set -euo pipefail
cd "$(dirname "$0")/.."

BASE=${1:-}
[ -n "$BASE" ] || { echo "Uso: bash scripts/ripristina.sh <cartella>/cartellaviva-AAAAMMGG-HHMMSS" >&2; exit 1; }
BASE=${BASE%.db.dump}
BASE=${BASE%.documenti.tar.gz}
DUMP="$BASE.db.dump"
DOCUMENTI="$BASE.documenti.tar.gz"
[ -s "$DUMP" ] || { echo "ERRORE: manca $DUMP" >&2; exit 1; }
[ -s "$DOCUMENTI" ] || { echo "ERRORE: manca $DOCUMENTI" >&2; exit 1; }
gzip -t "$DOCUMENTI"

echo "Ripristino da: $BASE"
echo "Database e documenti ATTUALI di questa installazione verranno sostituiti."
if [ "${CONFERMA:-}" != "RIPRISTINA" ]; then
  [ -t 0 ] || { echo "ERRORE: senza terminale serve CONFERMA=RIPRISTINA." >&2; exit 1; }
  read -r -p "Scrivi RIPRISTINA per continuare: " risposta
  [ "$risposta" = "RIPRISTINA" ] || { echo "Annullato."; exit 1; }
fi

echo "== Fermo API e worker"
docker compose stop php worker
docker compose up -d --wait database

echo "== Database"
docker compose exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner --single-transaction' < "$DUMP"

echo "== Documenti"
# Container usa e getta con lo stesso utente e lo stesso volume del servizio php.
docker compose run --rm -T --no-deps --entrypoint sh php -c \
  'find /var/lib/cartellaviva/documents -mindepth 1 -delete && tar -C /var/lib/cartellaviva/documents -xzf -' < "$DOCUMENTI"

echo "== Riavvio API e worker"
docker compose up -d --wait php
docker compose up -d worker
# Controllo della chiave: l'impronta della master key montata deve essere tra quelle del database.
docker compose exec -T php php bin/console app:crypto:key status
echo "Ripristino completato."
