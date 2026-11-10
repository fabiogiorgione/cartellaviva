# pdf.js (copia locale)

pdfjs-dist 6.2.108, dal registro npm, con l'integrità verificata:
sha512-YxFb+SQcodN2rnX9Tn3dHYlqfb7NjlzzfONPpJd+AKoKtUjEdevTfbC07d5TcczzOK6261auRkP/M8OBHs9vFQ==

Lo usa il pacchetto `printing` per l'anteprima dei PDF sul web. Senza questa copia
lo scaricherebbe a ogni avvio da unpkg.com, senza controllo di integrità: un CDN
compromesso eseguirebbe codice dentro l'app, con accesso ai token di sessione.
`pdfjs_config.js` (nella cartella `web/`) indica a `printing` di usare questa cartella.

Contiene solo `pdf.min.mjs`, `pdf.worker.min.mjs`, `cmaps/` (font CJK) e la licenza (Apache-2.0).

Per aggiornare: tenere la stessa versione di `_pdfJsVersion` in
`printing/lib/printing_web.dart`, scaricare il pacchetto da
https://registry.npmjs.org/pdfjs-dist/<versione>, verificare `dist.integrity`
e sostituire questi file.
