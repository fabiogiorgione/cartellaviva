#!/usr/bin/env bash
# CartellaViva — backup di database e documenti (produzione o sviluppo).
#
#   bash scripts/backup.sh [cartella]     predefinita: ./backups
#   CONSERVA=14 bash scripts/backup.sh    quanti backup tenere (predefiniti 14, 0 = tutti)
#
# Crea due file con la stessa data:
#   cartellaviva-AAAAMMGG-HHMMSS.db.dump          database (pg_dump, formato custom)
#   cartellaviva-AAAAMMGG-HHMMSS.documenti.tar.gz file dei documenti (i sensibili restano cifrati)
#
# La master key NON è nel backup, di proposito: chi ottiene il backup senza la chiave non
# legge i documenti sensibili. La chiave ha un backup separato (docs/gestione-chiavi.md).
# Questo script salva sul server: i file vanno poi copiati altrove (guida, "Backup").
# Ripristino: bash scripts/ripristina.sh <cartella>/cartellaviva-AAAAMMGG-HHMMSS
set -euo pipefail
cd "$(dirname "$0")/.."

CARTELLA=${1:-backups}
CONSERVA=${CONSERVA:-14}
DATA=$(date +%Y%m%d-%H%M%S)
BASE="$CARTELLA/cartellaviva-$DATA"
umask 077
mkdir -p "$CARTELLA"

docker compose ps --status running --services | grep -qx database || { echo "ERRORE: il database non è avviato." >&2; exit 1; }
docker compose ps --status running --services | grep -qx php || { echo "ERRORE: il servizio php non è avviato." >&2; exit 1; }

echo "== Database -> $BASE.db.dump"
docker compose exec -T database sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$BASE.db.dump.tmp"
mv "$BASE.db.dump.tmp" "$BASE.db.dump"

echo "== Documenti -> $BASE.documenti.tar.gz"
docker compose exec -T php tar -C /var/lib/cartellaviva/documents -cf - . | gzip > "$BASE.documenti.tar.gz.tmp"
mv "$BASE.documenti.tar.gz.tmp" "$BASE.documenti.tar.gz"

# Controllo minimo: il dump si legge e l'archivio si apre.
docker compose exec -T database pg_restore --list < "$BASE.db.dump" > /dev/null
gzip -t "$BASE.documenti.tar.gz"
ls -lh "$BASE".*

if [ "$CONSERVA" -gt 0 ]; then
  # Tiene gli ultimi CONSERVA backup (per data nel nome), cancella i più vecchi.
  ls -1 "$CARTELLA"/cartellaviva-*.db.dump 2>/dev/null | sort -r | tail -n +"$((CONSERVA + 1))" | while read -r vecchio; do
    rm -f "$vecchio" "${vecchio%.db.dump}.documenti.tar.gz"
    echo "Rimosso il backup vecchio ${vecchio%.db.dump}"
  done
fi
echo "Backup completato. Ricorda: copialo fuori dal server; la master key ha un backup a parte."
