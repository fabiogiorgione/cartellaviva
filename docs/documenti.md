# Documenti

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

```
Tenant ──< Documento >── Categoria (di sistema o del tenant; isSensibile)
              │   └── owner_user_id (chi l'ha caricato)
              └──< documento_membro_famiglia >── MembroFamiglia ── Anagrafica
```

- **MembroFamiglia**: persona della famiglia **senza account** (es. un figlio minore), con un'Anagrafica propria.
  - Obbligatorio solo il nome; nessun limite di età.
  - Il codice fiscale è facoltativo; se presente è verificato come nell'anagrafica dell'account, ed è unico **nel tenant**: lo stesso figlio può stare nei tenant di entrambi i genitori.
  - Si può collegare facoltativamente a un account (es. il coniuge).
- **Un documento può riguardare più membri** (relazione molti-a-molti), oppure nessuno: è il caso dei documenti della famiglia in generale.
- **Categorie di sistema** (`config/packages/categorie.yaml`): Bollette, Documenti Identità*, Salute*, Farmaci*, Veicoli, Casa, Scuola. L'asterisco indica le categorie sensibili. I tenant possono crearne di proprie.
- **Documenti → Categorie** (`/categorie`): lista con filtri, icona, colore, protezione e numero di documenti. Il numero arriva solo a chi legge i documenti, e solo per le categorie che vede. Chi ha `categoria:manage` crea, modifica ed elimina le categorie della famiglia.
  - Eliminare una categoria con documenti richiede `?spostaIn={id}`: documenti e scadenze passano nella categoria scelta.
  - La cifratura dei documenti già caricati non cambia.
  - La categoria esce dagli elenchi dell'accesso d'emergenza.

## Caricamento

Endpoint: `POST /api/tenants/{id}/documenti`, multipart con i campi `file`, `categoriaId`, `membriIds[]`, `titolo` e `dataDocumento`.

1. il voter verifica `documento:create` sul tenant **prima** di leggere il file;
2. il tipo di file si ricava dal contenuto, con un massimo di 20 MB:
   - formati accettati: PDF, immagini (JPEG, PNG, HEIC, GIF, WEBP), documenti Office (DOCX, XLSX, PPTX, ODT, ODS, ODP, DOC, XLS, PPT, RTF), Pages, Numbers, Keynote, TXT e ZIP;
   - per i formati che sono archivi ZIP (OOXML, ODF, iWork) si controlla anche la struttura interna. Pages, Numbers e Keynote hanno la stessa struttura (`Index/Document.iwa`): il contenuto dice che è un documento iWork, l'estensione dice quale dei tre;
   - gli archivi ZIP si conservano e si scaricano soltanto: il server non li apre mai (niente testo, niente anteprima), quindi il loro contenuto non è controllato;
   - i file con macro VBA sono rifiutati;
3. controllo duplicati nel tenant: un documento con lo stesso contenuto viene segnalato in `possibiliDuplicati`, senza bloccare il caricamento;
4. se la categoria è sensibile, il file viene **cifrato prima di arrivare allo storage** (vedi [gestione-chiavi.md](gestione-chiavi.md));
5. il documento viene salvato con `ocr_status = in_coda`;
6. parte un messaggio Messenger (contiene solo l'id).

## Documenti cifrati

Oltre al file:
- il **testo OCR** si salva cifrato, in `ocr_text_cifrato`; `ocr_text` resta NULL. La ricerca testuale su questi documenti vale quindi solo sul titolo;
- l'**hash per i duplicati** è un HMAC con la chiave del tenant, non uno SHA-256 in chiaro.

Se si sposta un documento **verso** una categoria sensibile, viene cifrato. **Verso** una non sensibile resta cifrato.

## Download

Il download passa solo da `GET /api/tenants/{id}/documenti/{docId}/file`, che verifica il permesso, decifra in memoria e risponde con `no-store`. Lo storage non è mai esposto.

## Anteprima

La scheda del documento ha due schede, "Anteprima" (predefinita) e "Testo" (OCR). L'originale non cambia mai: download, export, Google Drive e consegne all'ente usano sempre il file vero.

- **PDF, JPEG, PNG, GIF, WEBP, TXT**: l'anteprima è l'originale (`anteprima_stato = non_serve`). I TXT si mandano convertiti in UTF-8 (da Windows-1252 o UTF-16); il download resta il file originale.
- **ZIP**: nessuna anteprima (409 con il motivo).
- **HEIC e Office** (compresi Pages, Numbers e Keynote, che LibreOffice importa): il worker ricava una copia per la sola visualizzazione (`AnteprimaDocumentoMessage`):
  - le foto HEIC diventano JPEG con ImageMagick, via pipe;
  - i documenti Office diventano PDF con il servizio `convertitore` (Gotenberg/LibreOffice). Gira su una rete Docker `internal` senza internet, in sola lettura, con i temporanei in tmpfs, e ha attiva solo la route LibreOffice.
  - La copia si salva accanto all'originale (`…-anteprima.bin`), cifrata con la stessa chiave se il documento è cifrato (scopo `anteprima`). Se il documento passa a una categoria sensibile, si cifra anche la copia.
- **Office e OCR**: l'OCR dei documenti Office parte dopo l'anteprima e legge il PDF ricavato. Se l'anteprima fallisce, il testo risulta "non disponibile" con il motivo.
- **Endpoint**:
  - `GET .../documenti/{docId}/anteprima` risponde con il file (inline, `no-store`), 202 se la copia è in preparazione, 409 con `anteprimaErrore` se non è disponibile;
  - `POST .../documenti/{docId}/anteprima` rigenera la copia (`documento:write`).
- **Convertitore non configurato** (`GOTENBERG_URL` vuoto): anteprima "fallita" con il motivo esplicito, mai un 500. I file restano caricabili e scaricabili. Lo stato del convertitore compare in "Salute e da fare" del portale.
- **Documenti già caricati**: `php bin/console app:anteprime:genera` mette in coda le anteprime mancanti; con `--fallite` anche quelle fallite.
- **Risposte delle famiglie all'ente** (`FileCifrato`): `GET /api/enti/{t}/richieste/{id}/destinatari/{did}/anteprima`. La copia si crea alla prima apertura, cifrata con la DEK del file dell'ente, e vale anche per le consegne passate.
- **pdf.js sul web**: i PDF si disegnano con pdf.js, servito dall'app stessa da `frontend/web/pdfjs/` e non scaricato a ogni avvio da unpkg.com, senza controllo di integrità, come farebbe il pacchetto `printing`. Chi ospita l'app web deve servire i file `.mjs` come `text/javascript`, altrimenti il browser rifiuta i moduli. Per aggiornarlo vedi `frontend/web/pdfjs/README.md`.
- **Caratteri**: Poppins e Plus Jakarta Sans sono inclusi nell'app (`frontend/assets/fonts/`). Sul web anche il motore grafico (CanvasKit) e i caratteri di riserva (Roboto e Noto per simboli ed emoji, `frontend/web/font-fallback/`) sono serviti dall'app, configurati in `frontend/web/flutter_bootstrap.js`: l'app non scarica nulla da Google per disegnare. Dopo un aggiornamento di Flutter si rilancia `scripts/aggiorna-font-fallback.sh`.

## OCR

- Il servizio `worker` del compose esegue `messenger:consume async`, con la coda su PostgreSQL.
- Tesseract (ita+eng) è usato solo via pipe: il contenuto decifrato non tocca il disco.
- Per i PDF: se c'è già un livello di testo si usa quello, altrimenti le pagine vengono rasterizzate con `pdftocairo` e passate a Tesseract. HEIC, GIF e WEBP vengono convertiti in PNG con ImageMagick (delle immagini animate, il primo fotogramma).
- I TXT non passano da Tesseract: il testo si legge così com'è, convertito in UTF-8.
- In caso di errore: `ocr_status = fallito`, con il motivo in `ocr_errore`, nel log e nell'audit. Si rilancia con `POST .../documenti/{docId}/ocr`.

## File temporanei

`/tmp` dei container è un tmpfs: il file che PHP riceve in upload resta in RAM.

## Permessi

| Permesso | owner | member | viewer |
| --- | --- | --- | --- |
| `documento:read` | ✓ | ✓ | ✓ |
| `documento:create` | ✓ | ✓ | – |
| `documento:write` / `documento:delete` | tutti | solo i propri (`:own`) | – |
| `membro_famiglia:read` | ✓ | ✓ | ✓ |
| `membro_famiglia:manage` | ✓ | ✓ | – |
| `categoria:manage` | ✓ | – | – |
| `import:manage` | ✓ | – | – |

## Audit

- Letture di documenti sensibili (dettaglio, download, lista che li contiene): sempre registrate.
- Scritture su qualsiasi documento (creazione, modifica, cancellazione, cifratura, rilancio OCR): sempre registrate, con il flag `sensibile`.
- Accessi negati: sempre registrati.

## App: scansione di un documento

Solo nelle app **Android e iOS**: sul web la pagina mostra un avviso. Si apre dal menu utente, voce **Nuovo documento** (`/documenti/nuovo`).

1. **Cattura di una pagina** con `cunning_document_scanner`, che usa VisionKit su iOS e ML Kit su Android:
   - la fonte è la fotocamera o una singola immagine dalla galleria;
   - i bordi si rilevano in automatico, gli angoli si possono spostare a mano e la prospettiva viene corretta.

   Lo scanner viene aperto con una pagina alla volta e **senza i suoi filtri**: pagine e filtro li gestisce l'app.
2. **Filtro bianco e nero** facoltativo, con anteprima prima di confermare la pagina. È una binarizzazione **adattiva di Sauvola**, scritta in Dart in `lib/features/documenti/scan/page_processing.dart` ed eseguita in un isolate:
   - la soglia si calcola per ogni pixel dalla media e dalla deviazione della zona intorno;
   - ombre e carta scura diventano bianche, il testo resta nero;
   - le pagine in bianco e nero si salvano in PNG, quelle a colori in JPEG 85;
   - il lato lungo viene ridotto a 2000 px.
3. **Pagine**: le miniature si possono riordinare (pressione lunga e trascinamento) o eliminare.
4. **PDF** generato con il pacchetto `pdf`:
   - una pagina per foto, nell'ordine mostrato;
   - ogni pagina ha le proporzioni della foto e il lato lungo di un A4.

   Oltre 20 MB il caricamento viene bloccato prima dell'invio.
5. **Conferma**: la categoria è obbligatoria, i membri della famiglia sono facoltativi.
6. **Upload** multipart verso `POST /api/tenants/{id}/documenti` con il client unico (`ApiClient`):
   - una barra mostra l'avanzamento;
   - un 401 durante l'upload viene gestito rinnovando il token e reinviando un nuovo body;
   - se l'upload riesce, l'app apre la scheda del documento (`/documento/{id}`).

**Bozza**:
- pagine, ordine, categoria e membri si salvano subito nella cartella di supporto privata dell'app (`scan_draft/`);
- se l'upload fallisce, o l'app viene chiusa, la bozza resta finché l'upload non riesce o l'utente la annulla esplicitamente;
- una bozza di un altro utente sullo stesso dispositivo viene scartata e non mostrata.

**Requisiti nativi**:
- Android: minSdk 24, permesso `CAMERA`;
- iOS: deployment target 15.0, `NSCameraUsageDescription` e `NSPhotoLibraryUsageDescription`.

**Progetto Android**:
- la cartella `android/` è generata da Flutter 3.44 (Gradle 9.1, AGP 9.0.1, Kotlin 2.3, Java 17).

## Ricerca per significato, dati estratti e documenti simili

Solo per i documenti **non cifrati**: dati estratti e vettori rivelano il contenuto, quindi un documento cifrato non viene mai analizzato, e uno che diventa cifrato (spostato in una categoria sensibile) perde subito l'indice (`DocumentService::encryptExisting`).

**Indicizzazione.** Dopo ogni OCR riuscito, `IndicizzaDocumentoHandler` (job `IndicizzaDocumentoMessage`, nel worker):
1. `AiEstrattoreDati` chiede all'Ollama locale (`OLLAMA_MODEL`) emittente, data, importo, numero, intestatario, riferimenti e tag. La risposta è vincolata da uno schema JSON e poi **ricontrollata sul testo**:
   - la data si sceglie solo tra quelle presenti nel testo;
   - emittente, numero, intestatario e importo valgono solo se compaiono nel testo;
   - i **riferimenti** (targa, POD, PDR, polizza, contratto, IBAN) si normalizzano in maiuscole e cifre e devono comparire nel testo; esclusi codice fiscale, parole senza cifre e frasi;
   - i tag sono etichette brevi ripulite.
2. `SuddivisoreTesto` divide il testo in parti (fino a 8 da 1500 caratteri) e `OllamaEmbedder` ne calcola i vettori con `OLLAMA_EMBED_MODEL` (consigliato `bge-m3`, multilingue, ~1,2 GB, meno di 2 GB di RAM).
3. Prima di salvare si ricontrolla, con il documento bloccato, che non sia stato cifrato nel frattempo.

Se Ollama non risponde, Messenger ritenta. Se un modello non è scaricato (HTTP 404) quella parte si salta senza ritentare. Nell'audit (`documento.indicizzato`) ci sono solo i modelli e il numero di parti, mai i dati estratti.

**Tabelle.** `documento_indice` (dati, tag, riferimenti) e `documento_embedding` (un vettore per parte) usano l'estensione **pgvector**: il servizio `database` è `postgres:16-alpine` con pgvector compilato (`docker/database/Dockerfile`, versione fissata). Sono gestite con SQL diretto (`Indice\IndiceDocumenti`) ed escluse dal confronto dello schema dell'ORM (`schema_filter` in `doctrine.yaml`). Nessun indice HNSW: si cerca sempre dentro una famiglia, dove la ricerca esatta è veloce e non perde risultati.

**Ricerca.** `GET .../documenti?q=...&modo=significato` (`RicercaPerSignificato`) restituisce, in ordine:
1. i documenti vicini per significato. Un documento resta se la distanza coseno non supera `app.ricerca_distanza_max` (0,58) né quella del migliore più `app.ricerca_margine` (0,1). I valori sono tarati su bge-m3: con questo modello le distanze dei documenti pertinenti e degli altri sono vicine, quindi conta il distacco;
2. i documenti con un tag uguale a una parola cercata;
3. i risultati della ricerca per parole di sempre, così i documenti cifrati restano trovabili per titolo.

Valgono gli stessi filtri e restrizioni della lista (categoria, persona, accesso d'emergenza). Sono al massimo 100 risultati, divisi in pagine; l'ordinamento scelto non si applica. Se il modello è spento o Ollama non risponde entro 30 secondi si usa la ricerca per parole. Le ricerche di una sola parola rendono meno di una frase.

**Documenti simili.** `GET .../documenti/{docId}/simili` (`DocumentiSimili`) restituisce:
- prima i documenti con un riferimento in comune ("stessa targa AB123CD");
- poi quelli con contenuto vicino (distanza entro `app.simili_distanza_max`, 0,4).

Il permesso di lettura si verifica documento per documento.

**Documenti già presenti.** `bin/console app:documenti:indicizza` mette in coda quelli mai indicizzati o indicizzati con un altro modello di embedding (`--tutti`: tutti). Da lanciare dopo aver scaricato `bge-m3` o cambiato modello. `POST .../documenti/{docId}/indice` (`documento:write`) rianalizza un documento.

**Avanzamento nell'app.** Menu dell'account, "Analisi AI dei documenti" (`documento:read`): quanti documenti della famiglia sono analizzati, in attesa, con il testo ancora da leggere o illeggibile, e quanti sono cifrati (mai analizzati). Avvisa se un modello non è scaricato o Ollama non risponde: in quel caso l'analisi è ferma e i documenti restano in attesa. Chi ha `documento:write` ha il pulsante "Analizza i mancanti", che fa lo stesso di `app:documenti:indicizza` per la sola famiglia. Le API sono `GET` e `POST /api/tenants/{id}/documenti/analisi`: restituiscono solo conteggi e, con l'accesso d'emergenza limitato, contano solo le categorie concesse. Un documento con il testo vuoto, una volta indicizzato, conta come analizzato e il comando non lo rimette più in coda.

**Da sapere:**
- una categoria resa sensibile in seguito non cifra i documenti che contiene già: restano in chiaro, e quindi indicizzati, come oggi restano ricercabili per testo;
- "dell'anno scorso" non filtra per data: la ricerca per significato non interpreta le date.
