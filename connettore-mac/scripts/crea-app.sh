#!/usr/bin/env bash
# Crea "CartellaViva Connettore.app" in connettore-mac/build/.
#
#   bash scripts/crea-app.sh                 firma locale (ad hoc): per provarla su questo Mac
#   FIRMA="Developer ID Application: …" bash scripts/crea-app.sh
#                                            firma per la distribuzione (poi notarizzazione,
#                                            vedi README); l'identità non va nel repository.
set -euo pipefail
cd "$(dirname "$0")/.."

NOME="CartellaViva Connettore"
APP="build/$NOME.app"
VERSIONE=$(grep -o 'versione = "[^"]*"' Sources/ConnettoreCore/Connettore.swift | cut -d'"' -f2)

swift build -c release --arch arm64
rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
BIN=$(swift build -c release --arch arm64 --show-bin-path)
cp "$BIN/CartellaVivaConnettore" "$APP/Contents/MacOS/CartellaVivaConnettore"
cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleIdentifier</key><string>it.cartellaviva.connettore</string>
  <key>CFBundleName</key><string>$NOME</string>
  <key>CFBundleDisplayName</key><string>$NOME</string>
  <key>CFBundleExecutable</key><string>CartellaVivaConnettore</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$VERSIONE</string>
  <key>CFBundleVersion</key><string>$VERSIONE</string>
  <key>CFBundleDevelopmentRegion</key><string>it</string>
  <key>LSMinimumSystemVersion</key><string>13.0</string>
  <!-- Solo nella barra dei menu, niente icona nel Dock. -->
  <key>LSUIElement</key><true/>
  <key>NSHumanReadableCopyright</key><string>CartellaViva · AGPL-3.0</string>
</dict>
</plist>
PLIST
FIRMA=${FIRMA:--}
codesign --force --options runtime --timestamp=none --sign "$FIRMA" "$APP"
codesign --verify --verbose=1 "$APP"
echo "Fatto: $APP (versione $VERSIONE, firma: $FIRMA)"
