# CartellaViva Connettore (Mac)

App per macOS che fa sul Mac della famiglia, con Ollama, le analisi AI dei documenti che altrimenti fa il server. Vive nella barra dei menu. Specifica e protocollo: [docs/connettore-ai.md](../docs/connettore-ai.md).

Il connettore riceve dal server istruzioni, prompt e schema di un documento alla volta, li passa a Ollama su `127.0.0.1` e restituisce **solo la risposta del modello**: il server la ricontrolla sul testo con le stesse regole di sempre. Il testo resta in memoria; nei log solo id e tempi; il refresh token sta nel Portachiavi (solo questo Mac, mai iCloud).

## Requisiti

- Mac con chip Apple, macOS 13 o successivo; 16 GB di memoria consigliati.
- [Ollama](https://ollama.com/download/mac) in funzione. Il modello consigliato (`qwen3:4b-instruct-2507-q4_K_M`, circa 2,5 GB) si scarica dall'app.

## Costruire e provare

```bash
swift test                     # logica: formula, abbinamento e errori con un server finto
bash scripts/crea-app.sh       # build/CartellaViva Connettore.app, firma locale (ad hoc)
open "build/CartellaViva Connettore.app"
```

Primo avvio: la finestra controlla Ollama e il modello (con "Prova il modello" su uno scontrino finto), poi "Collega alla famiglia" mostra un codice e un QR da approvare nell'app (Sicurezza e accesso → AI sul tuo Mac, solo il proprietario). Da quel momento il Mac prende i lavori da solo; "Apri all'accesso al Mac" lo fa ripartire a ogni avvio.

Per lo sviluppo, con il server locale: `defaults write it.cartellaviva.connettore server http://localhost:8090` (http è ammesso solo verso questo computer). L'opzione `--collega "Nome"` avvia il collegamento senza premere il pulsante.

## Distribuzione

Il pacchetto Swift sostituisce il progetto Xcode: nel repository non c'è un file di progetto con l'identificativo del team. Per distribuire:

1. `FIRMA="Developer ID Application: …" bash scripts/crea-app.sh` (hardened runtime già attivo);
2. notarizzazione con `xcrun notarytool submit … --wait` e `xcrun stapler staple`;
3. DMG nelle release del repository pubblico.

Da fare (fase 3 della specifica): aggiornamenti con Sparkle 2, icona, DMG. Fase 4: modello di Apple al posto di Ollama.
