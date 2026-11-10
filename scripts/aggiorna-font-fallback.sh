#!/usr/bin/env bash
# Caratteri di riserva dell'app web (Roboto, Noto Sans, simboli, matematica,
# emoji) in frontend/web/font-fallback/, con i percorsi che usa il motore di
# Flutter. Da rilanciare dopo ogni aggiornamento di Flutter: se il motore cambia
# versione dei caratteri, i file nuovi vanno scaricati.
# Restano esclusi i caratteri cinesi, giapponesi e coreani (centinaia di file).
set -euo pipefail
cd "$(dirname "$0")/../frontend"
flutter build web --no-tree-shake-icons >/dev/null
elenco=$(grep -oE '"(notosans|notosanssymbols|notosanssymbols2|notosansmath|notocoloremoji|roboto)/v[0-9]+/[A-Za-z0-9_.-]+\.(woff2|ttf)"' build/web/main.dart.js | tr -d '"' | sort -u)
rm -rf web/font-fallback.nuovo && mkdir -p web/font-fallback.nuovo
for p in $elenco; do
  mkdir -p "web/font-fallback.nuovo/$(dirname "$p")"
  curl -sfL "https://fonts.gstatic.com/s/$p" -o "web/font-fallback.nuovo/$p"
done
cp web/font-fallback/README.md web/font-fallback/OFL-*.txt web/font-fallback.nuovo/ 2>/dev/null || true
rm -rf web/font-fallback && mv web/font-fallback.nuovo web/font-fallback
echo "Caratteri di riserva: $(echo "$elenco" | wc -l | tr -d ' ') file in frontend/web/font-fallback"
