# Caratteri di riserva dell'app web

Il motore di Flutter web usa Roboto come carattere predefinito e i caratteri Noto
per i simboli che mancano agli altri (frecce, ✓, ●, emoji...). Di norma li
scarica da fonts.gstatic.com; qui sono serviti dall'app (`fontFallbackBaseUrl`
in `web/flutter_bootstrap.js`), con gli stessi percorsi.

- Origine: Google Fonts, licenza SIL Open Font License 1.1 (`OFL-Noto.txt`, `OFL-Roboto.txt`).
- Aggiornamento, dopo ogni aggiornamento di Flutter: `scripts/aggiorna-font-fallback.sh`.
- Non inclusi: i caratteri cinesi, giapponesi e coreani. Quei testi resterebbero senza glifi.
