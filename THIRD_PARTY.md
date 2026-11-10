# Componenti di terze parti

CartellaViva è distribuito con licenza **AGPL-3.0-or-later** (vedi `LICENSE`).
Usa componenti di terze parti, ognuno con la propria licenza. Qui quelli che
richiedono un'attribuzione o hanno condizioni da conoscere; l'elenco completo:

- backend: `docker compose exec php composer licenses`
- app: `cd frontend && flutter pub deps` (le licenze sono nella cartella di ogni pacchetto)

## Inclusi nel repository

| Componente | Dove | Licenza |
|---|---|---|
| [pdf.js](https://github.com/mozilla/pdf.js) 6.2.108 (Mozilla) | `frontend/web/pdfjs/` | Apache-2.0 (testo in `frontend/web/pdfjs/LICENSE`) |
| Caratteri Poppins e Plus Jakarta Sans (Google Fonts) | `frontend/assets/fonts/` | SIL Open Font License 1.1 (testi `OFL-*.txt` nella cartella) |
| Caratteri di riserva Roboto e Noto (simboli, matematica, emoji) | `frontend/web/font-fallback/` | SIL Open Font License 1.1 (testi `OFL-*.txt` nella cartella) |

## Dati

| Dati | Uso | Licenza |
|---|---|---|
| Codici catastali (Belfiore) di comuni e stati, dal pacchetto [devnix/belfiore-code](https://github.com/devnix/belfiore-code) | `app:geo:import`: comuni e stati per l'anagrafica e il codice fiscale | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) |

## Dipendenze con condizioni particolari

| Pacchetto | Uso | Licenza |
|---|---|---|
| [davidepastore/codice-fiscale](https://github.com/DavidePastore/codice-fiscale) | verifica e calcolo del codice fiscale | GPL-2.0-or-later (compatibile con AGPL-3.0) |
| [dompdf](https://github.com/dompdf/dompdf) e le sue librerie | PDF generati dal server | LGPL-2.1 / LGPL-3.0 |
| [Lucide](https://lucide.dev) (pacchetto `flutter_lucide`) | icone dell'app | ISC |
| `gtk`, `dbus` (dipendenze indirette, solo app desktop Linux) | integrazione con il sistema | MPL-2.0 (compatibile con AGPL-3.0) |

Tutte le altre dipendenze dirette e indirette hanno licenze MIT, BSD, Apache-2.0 o ISC
(verificato con `composer licenses` e sui file `LICENSE` dei pacchetti Dart).

## Servizi esterni (non inclusi)

I modelli AI si scaricano a parte in Ollama (`ollama pull`) e hanno le proprie
licenze: per esempio Qwen3 (Apache-2.0) e bge-m3 (MIT). Gotenberg, PostgreSQL,
pgvector e Tesseract girano nei loro container, con le loro licenze.
