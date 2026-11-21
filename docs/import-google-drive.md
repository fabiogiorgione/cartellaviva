# Import da Google Drive

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

Una **sorgente** (`ImportSource`) collega una cartella di Google Drive a un tenant. Gestirla richiede `import:manage` (solo owner). La sincronizzazione è **solo manuale**: non esiste un import pianificato.

## Configurazione

1. In Google Cloud Console:
   - abilita la **Google Drive API**;
   - crea un client OAuth di tipo "Applicazione web";
   - come redirect URI autorizzato registra `<URL pubblico API>/api/import-sources/oauth/callback` (in sviluppo `http://localhost:8090/api/import-sources/oauth/callback`).
2. Imposta le variabili in `backend/.env.local` o nell'ambiente, **non** nei file versionati:
   ```
   GOOGLE_OAUTH_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_OAUTH_CLIENT_SECRET=...
   GOOGLE_OAUTH_REDIRECT_URI=http://localhost:8090/api/import-sources/oauth/callback
   ```
   Se mancano, `POST /import-sources` risponde **422 "Provider non configurato"**.

**Scope `drive.readonly`**: per Google è uno scope *restricted*.
- Finché l'app è "in test" funziona per al massimo 100 utenti di test dichiarati nella console.
- Per aprire l'app al pubblico servono la verifica di Google e un audit di sicurezza CASA annuale.

## Collegamento (OAuth)

Un redirect del browser non porta il JWT, quindi il collegamento avviene in due passi:

1. `POST /api/tenants/{id}/import-sources/{sid}/connect` (autenticato) restituisce `authorizationUrl`: il frontend lo apre.
2. Google rimanda a `GET /api/import-sources/oauth/callback`. L'endpoint è **pubblico** ma vale solo con lo `state` emesso al passo 1, che è:
   - casuale e monouso;
   - valido 10 minuti;
   - legato alla sorgente e all'utente;
   - salvato a DB solo come hash.

   Alla fine rimanda a `<FRONTEND_URL>/import/esito?esito=ok|errore&motivo=...`. La pagina Flutter corrispondente non esiste ancora.

**Token:**
- sono cifrati come i documenti sensibili: chiave propria della sorgente, cifrata con la chiave del tenant, con dati associati propri;
- non compaiono mai nelle risposte;
- `app:crypto:key rewrap` e `status` includono anche le sorgenti.

## Sincronizzazione

`POST .../import-sources/{sid}/sync` risponde 202 e mette in coda `ImportJobMessage`. Il worker (`ImportRunner`) legge la cartella radice e le sue **sottocartelle di primo livello**; le cartelle più profonde vengono ignorate.

Per ogni file:

1. **Duplicati, prima del download.**
   - File normali: l'`md5Checksum` di Drive viene confrontato con `documento.hash_md5`. La colonna contiene l'HMAC dell'MD5 con la chiave del tenant, mai l'MD5 in chiaro, ed è calcolata anche per i caricamenti manuali.
   - File nativi Google (Documenti, Fogli, Presentazioni, Disegni): vengono esportati in PDF e riconosciuti per id + data di modifica. Se il file viene modificato su Drive, viene reimportato come nuovo documento.
2. **Categoria**, secondo la strategia:
   - `cartelle`: il nome della sottocartella viene cercato, senza distinguere maiuscole e minuscole, tra le categorie di sistema **e** del tenant (quindi "SALUTE" finisce in Salute, che è sensibile). Se non ne esiste nessuna viene creata una categoria del tenant non sensibile. I file nella radice vanno in "Da categorizzare".
   - `ai_contenuto`: tutto va in **"Da categorizzare"**, una categoria di sistema **sensibile**, quindi il documento viene cifrato.
3. Il documento viene creato con lo stesso percorso del caricamento manuale (controllo del tipo, cifratura, OCR in coda). Il proprietario è l'utente che ha creato la sorgente, e il job verifica che abbia ancora `import:manage`.

**Esito ed errori:**
- l'esito (`trovati`, `importati`, `duplicati`, `saltati`, `errori`) viene salvato sulla sorgente;
- un errore su un singolo file non ferma gli altri;
- un errore della sorgente (accesso revocato, cartella sparita) porta lo stato a `errore` e salva il motivo in `ultimoErrore`;
- rilanciare la sincronizzazione è sempre sicuro.

## Classificazione automatica (strategia `ai_contenuto`)

1. Il worker OCR emette `DocumentoOcrCompletatoEvent`, un aggancio generico per le elaborazioni che seguono l'OCR.
2. Il listener mette in coda `ClassificaDocumentoMessage` solo per i documenti che si trovano in "Da categorizzare" **e** provengono da una sorgente `ai_contenuto`. È un job separato: se Ollama è lento o spento non tocca lo stato dell'OCR, e Messenger lo ritenta.
3. `AiCategoryClassifier` chiama l'**Ollama locale** (servizio `ollama`, senza porte pubblicate). Gli invia:
   - il nome del file;
   - il testo OCR, troncato a 6000 caratteri;
   - i nomi delle categorie del tenant.

   La risposta è vincolata da uno schema JSON (`enum` = categorie + "nessuna") e poi ricontrollata contro l'elenco reale.
4. **Verifica sì/no** (dal 21/11/2026): una seconda domanda confronta il documento con la descrizione della categoria scelta (`DescrizioneCategoria`, la stessa del wizard: scritta a mano per le categorie di sistema, generata dai titoli della categoria per quelle della famiglia). Senza verifica il modello sceglie quasi sempre una categoria, anche forzata: su 87 casi inventati (misura del 21/11/2026 con qwen3 4B) i documenti spostati nella categoria sbagliata scendono da 27 a 3, i giusti salgono dal 69% al 92%. Costa una domanda in più per documento.
5. Il risultato:
   - **categoria valida e confermata**: il documento viene spostato. Se era cifrato **resta cifrato** anche se la nuova categoria non è sensibile: la decifratura non avviene mai in automatico;
   - **"nessuna", qualsiasi altra risposta o scelta scartata dalla verifica**: il documento resta in "Da categorizzare". L'audit registra `documento.classificazione_incerta` (con `motivo: verifica` se l'ha scartata la verifica).

**Il modello** si imposta **solo** con `OLLAMA_MODEL`. Quello consigliato è `qwen3:4b-instruct-2507-q4_K_M`:
- ufficiale;
- 2,5 GB;
- oltre 100 lingue;
- variante senza "thinking".

Se `OLLAMA_MODEL` è vuota la classificazione è disattivata e i documenti restano da categorizzare.

Con il Mac della famiglia collegato e acceso, la classificazione e la proposta di categoria del wizard le fa il Mac ([connettore-ai.md](connettore-ai.md), "Classificazione").

Su una VM senza GPU servono circa 3–4 GB di RAM e da qualche secondo a qualche decina di secondi per documento.

**Limite**: i documenti rimasti cifrati non sono ricercabili per testo OCR (la ricerca lavora solo sul testo in chiaro).

## Proponi categoria con l'AI (wizard sui selezionati)

Nella lista dei documenti, **Sui selezionati › Proponi categoria con l'AI…** apre un wizard su al massimo 50 documenti. Serve il permesso `documento:write`.

1. **Analisi**: un documento per volta, con `POST /api/tenants/{t}/documenti/{id}/proposta-categoria`. La richiesta non cambia niente; nell'audit va `documento.categoria_proposta`. Con il modello consigliato su CPU servono da 5 a 50 secondi per documento, circa 25 minuti per 50 documenti.
2. **Revisione**: per ogni documento c'è la categoria proposta, modificabile o "Non spostare". Le **categorie nuove** suggerite si possono rinominare, con icona, colore e "sensibile". Si creano solo quelle spuntate, e solo da chi ha `categoria:manage`.
3. **Applica**: crea le categorie spuntate e sposta i documenti con l'operazione di gruppo `sposta`. Verso una categoria sensibile il documento viene cifrato; quelli già cifrati restano cifrati.

Come lavora `PropostaCategoria`, con lo stesso modello locale della classificazione automatica:
- **Scelta**: il modello sceglie tra le categorie della famiglia, escluse "Da categorizzare" e "Condivisi con enti", e tra le categorie nuove già proposte nello stesso giro, così documenti simili finiscono insieme. Per ogni categoria riceve fino a 3 titoli di esempio, esclusi i documenti in revisione e i titoli che sono solo nomi di file (`DOC_1660607446`, `scan 12`).
- **Verifica sì/no**: un modello da 4B mette quasi sempre il documento in qualche categoria, anche forzata (un volo in "Veicoli"). Una seconda domanda confronta il documento con una descrizione della categoria. Per le categorie di sistema è scritta a mano (`descrizione` in `config/packages/categorie.yaml`, dal 21/11/2026); per quelle della famiglia e per le nuove la genera il modello a parte e resta in cache una settimana. Quelle generate dimenticavano generi interi (un rogito scartato da "Casa"): sugli stessi 87 casi inventati le descrizioni scritte a mano portano il wizard dall'86% al 91% dei casi giusti.
- **Categoria nuova**: se la scelta non passa la verifica, il modello propone il nome di una categoria nuova (1-4 parole, controllato da `NuovaCategoria::normalizzaNome`) con icona e sensibilità. Se il testo non è disponibile, per esempio OCR non ancora fatto o ZIP, decide solo dal titolo e non propone categorie nuove.
- **Documenti cifrati**: il testo è decifrato solo in memoria e va solo a Ollama locale. Nell'audit non finisce nulla di ricavato dal contenuto.

Ogni proposta tiene occupati per decine di secondi un thread del server web e Ollama, quindi ci sono dei limiti (`TurnoProposte`, con advisory lock di PostgreSQL):
- **una proposta alla volta per utente**;
- **al massimo `app.proposte_contemporanee` (2) insieme** su tutta la piattaforma.

Se non c'è posto la risposta è `429` con `Retry-After: 5`, e il wizard aspetta e riprova lo stesso documento. C'è anche un limite di `app.proposta_categoria_limit` (150) proposte all'ora per utente: oltre, `429` con il tempo che manca, e il wizard si ferma.

La qualità delle proposte dipende dal modello: vanno sempre ricontrollate prima di applicarle.
