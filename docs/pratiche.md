# Pratiche

Le **Pratiche** raccolgono le cose da sbrigare che nascono dai documenti: prima i **pagamenti** (avvisi PagoPA, bollettini), poi il **730** e l'**ISEE**. L'AI propone, la persona conferma: niente entra in una pratica senza conferma.

Stato: fase 1 (Pagamenti), 2a (motore comune e 730), 2b (ISEE) e 2c (analisi sull'iPhone con il modello di Apple) fatte.

## Fase 1: Pagamenti

### Cosa vede l'utente

- **Menu a sinistra**, sezione "Da sbrigare": **Da confermare** (con il numero di proposte), Scadenze, **Pratiche** (Panoramica, Pagamenti), Richieste dagli enti.
- **Da confermare** (`/da-confermare`): una sola coda con le proposte dell'AI, oggi scadenze e pagamenti. Ogni proposta dice da quale documento viene, mostra i dati letti e ha tre azioni: **Conferma**, **Correggi** (modulo già compilato), **Ignora**.
- **Pratiche** (`/pratiche`): una scheda per pratica con lo stato in breve e una sola azione (Pagamenti: "2 da pagare · 310,40 €, la prima scade il 30/11").
- **Pagamenti** (`/pratiche/pagamenti`): schede "Da pagare" e "Pagati". Ogni pagamento: titolo, ente, importo, scadenza (con i giorni che mancano), codice avviso **copiabile**, documento d'origine; azioni **Segna come pagato** (data, ricevuta facoltativa scelta tra i documenti), Modifica, Elimina; "Nuovo pagamento" a mano.
- **Documento** (dettaglio): se il documento ha una proposta di pagamento aperta, una scheda "Avviso di pagamento trovato" con Conferma/Ignora; se è collegato a un pagamento, "Usato in: Pagamenti".

### Modello dati

`Pagamento` (`backend/src/Pratiche/Entity/Pagamento.php`, tabella `pagamento`):

| Campo | Tipo | Note |
| --- | --- | --- |
| `id` | uuid v7 | |
| `tenant` | FK tenant | |
| `titolo` | string 200 | es. "TARI 2026, seconda rata" |
| `ente` | string 200, null | ente creditore come scritto nell'avviso |
| `importoCentesimi` | int | sempre > 0 |
| `scadenza` | date, null | |
| `codiceAvviso` | string 18, null | solo cifre (spazi tolti) |
| `codiceFiscaleEnte` | string 11, null | solo cifre |
| `stato` | enum `da_pagare` \| `pagato` | |
| `pagatoIl` | date, null | |
| `documento` | FK documento, null, ON DELETE SET NULL | documento d'origine |
| `ricevuta` | FK documento, null, ON DELETE SET NULL | |
| `scadenzaCollegata` | FK scadenza, null, ON DELETE SET NULL | promemoria creato con il pagamento |
| `origine` | enum `documento` \| `manuale` | |
| `owner` (nel contratto `creatoDa`) | FK user, non null | chi l'ha creato; con `manage:own` gestisce solo i propri (come le scadenze); non esposto dall'API |
| `createdAt`, `updatedAt` | datetime | |

Importo massimo 10.000.000 €. Proposta sul documento, come le scadenze proposte: colonne `pagamento_proposta` (JSON; **cifrato con la chiave del documento se il documento è cifrato**, prefisso `enc:`) e `pagamento_proposta_stato` (`aperta` \| `accettata` \| `ignorata`) su `documento`.

### Permessi

Risorsa `pagamento` dichiarata sull'entità con `PermissionResource`, come `Scadenza`: `pagamento:read`, `pagamento:manage` (con gli stessi ruoli che oggi hanno `scadenza:read` / `scadenza:manage`; il ruolo emergenza solo `read`). Un pagamento collegato a un documento è visibile solo a chi può leggere quel documento (categorie consentite, accesso d'emergenza limitato): stesso filtro dei documenti.

### API

Tutte sotto `/api/tenants/{tenantId}`; importi come stringa decimale con il punto (`"180.40"`), date `YYYY-MM-DD`.

Oggetto `Pagamento`:
```json
{
  "id": "…", "titolo": "TARI 2026, seconda rata", "ente": "Comune di Milano",
  "importo": "180.40", "scadenza": "2026-11-30", "codiceAvviso": "301200004567891234",
  "codiceFiscaleEnte": "01199250158", "stato": "da_pagare", "pagatoIl": null,
  "documento": {"id": "…", "titolo": "Avviso TARI"}, "ricevuta": null,
  "scadenzaId": "…", "origine": "documento", "createdAt": "2026-11-12T09:00:00+01:00"
}
```

| Metodo e percorso | Permesso | Corpo / risposta |
| --- | --- | --- |
| `GET /pagamenti?stato=da_pagare\|pagato` | `pagamento:read` | `{"pagamenti": [Pagamento], "totaleDaPagare": "310.40"}`; da pagare per scadenza crescente (senza scadenza in fondo), pagati per `pagatoIl` decrescente |
| `POST /pagamenti` | `pagamento:manage` | `{titolo, importo, ente?, scadenza?, codiceAvviso?, codiceFiscaleEnte?, documentoId?, promemoria?: bool = true}` → 201 `Pagamento`. Con `scadenza` e `promemoria` crea una `Scadenza` collegata ("Pagare: <titolo>", promemoria 7 giorni prima). Con `documentoId` la proposta aperta di quel documento diventa `accettata` |
| `PATCH /pagamenti/{id}` | `pagamento:manage` | stessi campi, tutti facoltativi → `Pagamento`; aggiorna anche titolo e data della scadenza collegata se è ancora aperta |
| `POST /pagamenti/{id}/pagato` | `pagamento:manage` | `{pagatoIl?: date = oggi, ricevutaDocumentoId?}` → `Pagamento`; la scadenza collegata diventa fatta |
| `POST /pagamenti/{id}/da-pagare` | `pagamento:manage` | annulla "pagato" → `Pagamento` |
| `DELETE /pagamenti/{id}` | `pagamento:manage` | 204; elimina anche la scadenza collegata se è ancora aperta |
| `GET /pagamenti-proposte` | `pagamento:manage` | `{"proposte": [{documentoId, documentoTitolo, categoria: {id, nome}\|null, titoloSuggerito, ente, importo, scadenza, codiceAvviso, codiceFiscaleEnte}]}` (campi non trovati: `null`) |
| `POST /documenti/{id}/pagamento-proposta/ignora` | `pagamento:manage` | 204 |

Dettagli decisi in sviluppo (oltre alla tabella):

- `GET /pagamenti/{id}` → `Pagamento` (permesso `pagamento:read`). `totaleDaPagare` somma tutti i pagamenti da pagare visibili all'utente, anche con `?stato=pagato`.
- `PATCH`: solo i campi presenti cambiano; `null` o stringa vuota svuotano ente, scadenza, codici, `documentoId`. Togliendo la scadenza, la scadenza collegata ancora aperta viene eliminata. Il promemoria nasce in modifica solo con `promemoria: true` o quando la scadenza compare per la prima volta.
- La scadenza collegata ("Pagare: <titolo>", promemoria 7 giorni prima) è collegata anche al documento d'origine e chiude l'eventuale scadenza proposta dello stesso documento (niente doppioni in "Da confermare"). `da-pagare` riapre la scadenza collegata se era stata chiusa.
- `pagato` su un pagamento già pagato e `da-pagare` su uno da pagare danno 409. `pagatoIl` nel futuro: 422. `ricevuta` e `documento` sono `{id, titolo}`; la ricevuta in una categoria non visibile all'utente compare come `null`.
- `GET /pagamenti-proposte` per chi non può gestire i pagamenti (lettore, emergenza) risponde 200 con `{"proposte": []}` (come le scadenze); `POST …/pagamento-proposta/ignora` dà 403. Elenco ordinato per scadenza crescente (senza scadenza in fondo); `titoloSuggerito` è sempre valorizzato, `importo` è una stringa decimale.
- Dettaglio documento (`GET /documenti/{id}`): nuovi campi `pagamentoProposta` (stessa forma di un elemento di `pagamenti-proposte`, o `null`; solo per chi può gestire i pagamenti) e `pagamenti` (`[{id, titolo, stato, ruolo: "avviso"|"ricevuta"}]`, per "Usato in: Pagamenti"; vuoto senza `pagamento:read`).
- Validazione (422): titolo e importo obbligatori, importo `> 0` con al massimo due decimali (accetta anche la virgola), date `AAAA-MM-GG`, codice avviso 18 cifre e codice fiscale ente 11 cifre (spazi e trattini tolti), `documentoId`/`ricevutaDocumentoId` di un documento non trovato o non leggibile.

Errori di validazione: 422 con il messaggio in italiano, come il resto dell'API (es. "L'importo deve essere maggiore di zero.", "Il codice avviso ha 18 cifre."). Audit: `pagamento.creato`, `pagamento.modificato`, `pagamento.pagato`, `pagamento.eliminato`, `documento.pagamento_proposto`, `documento.pagamento_proposta_ignorata`, senza importi né codici nel contesto.

### Come si trova un avviso nel testo

Dopo l'OCR (`DocumentoOcrCompletatoEvent`), per i documenti senza proposta né pagamento collegato, come `ProponiScadenzaHandler`:

1. **Riconoscimento deterministico** (`AvvisoPagamentoEstrattore`): il testo contiene almeno uno tra "pagoPA", "codice avviso", "avviso di pagamento", "CBILL", "bollettino"; e un **codice avviso** di 18 cifre (anche a gruppi separati da spazi) che inizia con 0, 1, 2 o 3, vicino a "codice avviso" / "numero avviso" / "IUV". **Senza codice avviso non si propone nulla** (scelta più prudente del contratto iniziale: una bolletta con solo importo e scadenza darebbe troppi falsi positivi); l'importo, se manca, resta `null` nella proposta.
2. **Campi** cercati vicino alle loro etichette: importo ("importo", "totale da pagare", "€", formato italiano 1.234,56; vale solo se compare nel testo), scadenza ("entro il", "scadenza", "da pagare entro"; data futura entro 5 anni o scaduta da non più di 30 giorni), codice fiscale dell'ente (11 cifre vicino a "ente creditore" / "codice fiscale ente"), ente (riga vicina a "ente creditore" o intestazione).
3. **AI facoltativa** (Ollama, se attivo): dal testo propone solo `titolo` breve ed `ente` come scritti, con schema JSON e temperatura 0; il testo è un dato non fidato. Importo, codice e scadenza li decide sempre il codice deterministico. Se Ollama non risponde: titolo = ente + "avviso di pagamento", o il nome del file.
4. Il comando `app:pagamenti:proponi [--tenant=<id famiglia>] [--tutti]` mette in coda la ricerca anche sui documenti già caricati con l'OCR completato: senza `--tutti` solo quelli mai valutati, con `--tutti` anche quelli con proposta aperta o ignorata (che torna aperta). Mai i documenti che hanno già un pagamento collegato.

Il testo dei documenti cifrati si legge solo in memoria e va solo all'Ollama locale, come per le scadenze.

## Fase 2: motore delle pratiche, 730 e ISEE

### Decisioni prese

- Le voci di una pratica le vede **chi vede i documenti** da cui vengono; le voci aggiunte a mano, chi ha `pratica:read`.
- Documenti cifrati (Salute, Farmaci, Documenti identità…): l'analisi parte **solo su richiesta, con consenso**, una volta per pratica. Il testo si decifra in memoria e non si conserva; si salvano solo i valori della voce (importo, data, tipo, persona…), cifrati con la chiave del tenant.
- L'AI legge e classifica; **i calcoli e le regole li fa il codice**. L'app non calcola imposte né l'indicatore ISEE: raccoglie, controlla, mette in ordine e prepara il pacchetto. I calcoli ufficiali restano al CAF, alla precompilata, all'INPS.

### Il motore comune

Una pratica nasce da un **modello** (oggi `730` e `isee`; poi bonus nido, iscrizioni, assegno unico) per un anno.

- **Modello di pratica**: un file di regole versionato per anno, `backend/config/pratiche/<modello>-<anno>.yaml`, con in testa la fonte ufficiale (link alle istruzioni dell'Agenzia delle Entrate o dell'INPS) e la data dell'ultima verifica. Elenca i **requisiti**:
  - `codice` (es. `730.spesa_sanitaria`, `isee.giacenza_media`), etichetta, se è per persona o per famiglia, se è obbligatorio;
  - **come si riconosce** un documento candidato (categorie, tag, parole chiave, tipi di documento);
  - **cosa se ne legge**: i campi con il loro tipo (importo, data, persona, testo breve, scelta da un elenco) e i controlli deterministici (importo presente nel testo, data nell'anno giusto, IBAN o codice fiscale validi);
  - per il 730, le regole dell'anno da mostrare come controllo (es. obbligo di pagamento tracciabile), mai come calcolo della detrazione.
  Le regole si rivedono ogni anno prima di attivare il modello nuovo; senza file per quell'anno il modello non si può avviare.
- **Pratica** (`pratica`): tenant, modello, anno, stato (`in_corso`, `pronta`, `inviata`, `archiviata`), persone coinvolte (membri della famiglia), consenso per i cifrati (chi e quando), creata da, date.
- **Voce** (`pratica_voce`): pratica, requisito, persona (facoltativa), documento (facoltativo, ON DELETE SET NULL), **valori** (JSON cifrato con la chiave del tenant, dati associati: id della voce), stato (`proposta`, `confermata`, `esclusa`), origine (`ai_server`, `ai_telefono`, `manuale`), nota. Un documento può dare più voci (una CU con più redditi, un estratto conto con più rapporti).
- **Flusso**:
  1. *Avvia pratica*: modello, anno, persone. L'app propone l'anno giusto (730: spese dell'anno precedente; ISEE: l'anno in corso).
  2. *Ricerca*: i documenti non cifrati si analizzano subito (in coda, come oggi); per i cifrati compare la richiesta di consenso. Si riusano i dati già estratti dall'indice (data, importo, intestatario, riferimenti come IBAN e targhe, tag) e l'AI interviene solo sui campi che mancano.
  3. *Revisione*: tabella delle voci per persona e per requisito, con Conferma / Correggi / Escludi; le proposte compaiono anche nella coda **Da confermare**.
  4. *Cosa manca*: requisiti obbligatori senza voci confermate, e confronto con la pratica dello stesso modello dell'anno prima ("c'era la quietanza della polizza vita").
  5. *Pacchetto*: ZIP generato al momento (PDF ordinati per persona e requisito + riepilogo PDF con le voci confermate), da scaricare o da inviare con le **condivisioni esterne** che esistono già (link con codice e scadenza, per il CAF). Non si conserva sul server, se non quando l'utente sceglie "Archivia" (allora diventa un documento cifrato in "Condivisi con enti").
- **Permessi**: `pratica:read`, `pratica:manage` (stessi ruoli delle scadenze; emergenza solo lettura).
- **Audit**: `pratica.avviata`, `pratica.consenso_cifrati`, `pratica.pacchetto`, `pratica.inviata`, senza valori nel contesto.
- **Schermate**: in Pratiche, una scheda per ogni pratica aperta ("730 · spese 2026: 1.240 € confermati, 3 da vedere, 2 mancanti"); pagina della pratica con filtro per persona, sezioni per requisito, "Cosa manca", "Prepara pacchetto". Prototipo di riferimento: l'artifact "CartellaViva Pratiche".

### Fase 2a: com'è fatta (12/11/2026)

**Regole.** `backend/config/pratiche/730-2025.yaml` (730/2026, verificato il 12/11/2026 sulle pagine dell'Agenzia delle Entrate citate nel file) e `730-2026.yaml` (**provvisorio**: copia del 2025 con `verificato: ~`; l'app lo dice). Ogni requisito ha `per` (persona o famiglia), `obbligatorio`, `somma` (se entra nelle somme), `riconosci` (`categorie`, `parole`, `nomi`, `senza`), `ancheVecchi`, `tipi` (con `tracciabile`: `richiesto` / `non_richiesto` / `dipende`, `nota`, `parole`) e `campi` (`importo`, `data`, `testo`, `scelta` per il tipo, `tracciabile`). Il formato lo controlla `ModelliPratica::leggi` (test `ModelliPraticaTest`). Anno proposto per il 730: fino a settembre le spese dell'anno prima, da ottobre quelle dell'anno in corso.

**Quali documenti si guardano** (`CandidatiPratica`): data dal 1° gennaio dell'anno al 30 giugno dell'anno dopo (o senza data e caricati dal 1° gennaio), OCR completato.
- In chiaro: solo se il testo contiene una parola chiave di un requisito, a inizio parola ("farmac" trova "farmacia", "retta" non trova "corretta"). La sola categoria non basta: nei dati di prova la categoria Casa avrebbe mandato all'AI 102 documenti invece di 9.
- Cifrati: solo con il consenso della pratica e solo nelle categorie dei requisiti o "Da categorizzare"; senza consenso si contano e basta (`analisi.cifratiInAttesa`).

**Analisi** (`AnalizzaDocumentoPraticaMessage`, un documento alla volta, in coda): all'avvio, dopo il consenso, con "Cerca di nuovo" e dopo l'OCR di ogni documento nuovo per le pratiche in corso. `pratica_analisi` ricorda i documenti già visti (esito `voci` / `nessuna` / `errore`; gli errori si riprovano con "Cerca di nuovo"). Con l'AI attiva, Ollama classifica e legge i campi (schema JSON, temperatura 0); senza AI, regole: una voce sul requisito con più parole chiave, tipo dalle parole del tipo. In ogni caso: l'importo deve comparire nel testo (altrimenti si usa il totale trovato dalle regole), la data deve comparire ed essere dell'anno (una spesa con sole date di altri anni non si propone), emittente e persona devono essere scritti nel testo, il pagamento tracciabile letto dalle regole (carta, bancomat, bonifico / contanti) vale più di quello dell'AI. Senza importo una spesa non si propone. La persona: quella del documento se è una sola tra le persone della pratica, altrimenti il codice fiscale o il nome nel testo, altrimenti nessuna (controllo "Indica a quale persona si riferisce").

**Dati.** `pratica` (modello, anno, stato, persone, consenso cifrati: chi e quando, `non_serve`, DEK della pratica cifrata con la chiave del tenant), `pratica_voce` (requisito, persona, documento, `valori_cifrati`: JSON con importo, data, tipo, emittente, tracciabile, descrizione, nota, cifrato con la DEK della pratica e legato all'id della voce; stato; origine), `pratica_analisi`. La DEK delle pratiche segue `app:crypto:key rewrap` e compare in `status`.

**API** (sotto `/api/tenants/{tenantId}`):

| Metodo e percorso | Permesso | Note |
| --- | --- | --- |
| `GET /pratiche-modelli` | `pratica:read` | modelli e anni disponibili, `provvisorio`, `proposto` |
| `GET /pratiche` | `pratica:read` | riassunti (`confermate`, `proposte`, `mancanti`, `totaleConfermato`) e `puoAvviare` |
| `POST /pratiche` | `pratica:manage` | `{modello, anno, persone: [id]}` → 201; 409 se c'è già per quell'anno; avvia la ricerca |
| `GET /pratiche/{id}` | `pratica:read` | riassunto + `modelloInfo` (requisiti, tipi, campi, fonti), `voci` (con `controlli`), `cosaManca`, `annoPrima`, `riepilogo`, `analisi` (`inCoda`, `analizzati`, `cifratiInAttesa`), `consensoCifrati`, `nonServe`, `puoGestire` |
| `PATCH /pratiche/{id}` | `pratica:manage` | `{stato?, persone?}`; `inviata` va nell'audit come `pratica.inviata` |
| `DELETE /pratiche/{id}` | `pratica:manage` | 204; i documenti restano |
| `POST /pratiche/{id}/consenso-cifrati` | `pratica:manage` | `{consenso: bool}`; con `true` mette in coda i cifrati |
| `POST /pratiche/{id}/analizza` | `pratica:manage` | `{inCoda: n}` |
| `POST /pratiche/{id}/non-serve` | `pratica:manage` | `{requisito, personaId?, nonServe: bool}` (es. la CU di un figlio) |
| `POST /pratiche/{id}/voci` | `pratica:manage` | voce a mano, nasce confermata: servono i campi obbligatori |
| `PATCH /pratiche/{id}/voci/{voceId}` | `pratica:manage` | `{requisito?, personaId?, documentoId?, valori?, conferma?}` |
| `POST /pratiche/{id}/voci/{voceId}/conferma\|escludi\|riproponi` | `pratica:manage` | conferma: 422 "Per confermare manca: …" |
| `DELETE /pratiche/{id}/voci/{voceId}` | `pratica:manage` | 204 |
| `GET /pratiche/{id}/pacchetto` | `pratica:read` | ZIP al momento: `Riepilogo.pdf` + `<Persona>/<Requisito>/<data> <emittente> <importo>.<ext>` |
| `POST /pratiche/{id}/archivia` | `pratica:manage` + `documento:create` | il pacchetto diventa un documento cifrato in "Condivisi con enti" → 201 `{documento: {id, titolo}}` |
| `GET /pratiche-proposte` | `pratica:read` | voci proposte delle pratiche in corso che l'utente può gestire, con `praticaId`, `praticaTitolo`, `requisitoEtichetta`, `tipoEtichetta` (per "Da confermare"); vuoto per chi non gestisce |

Le voci di documenti che l'utente non può leggere per lui non esistono: non compaiono, non contano nelle somme e in "Cosa manca", non vanno nel pacchetto. Validazione con messaggi in italiano (422). Audit: `pratica.avviata`, `pratica.modificata`, `pratica.inviata`, `pratica.eliminata`, `pratica.consenso_cifrati`, `pratica.voce_aggiunta`, `pratica.pacchetto` (`archiviato: bool`), senza valori.

**Controlli** (`ControlliPratica`, deterministici): campi obbligatori mancanti, persona non indicata, data di un altro anno (per la CU l'anno dopo), pagamento non tracciabile dove serve (avviso) o "dipende" (informazione con la nota del tipo), doppioni (stesso requisito, importo, data ed emittente). "Cosa manca": requisiti obbligatori senza voci confermate per ogni persona, salvo "non serve". "L'anno scorso c'erano anche": voci confermate nella pratica dell'anno prima (stesso requisito, emittente e persona) che quest'anno mancano.

**App.** Pratiche (`/pratiche`): sezione "730 e dichiarazioni" con una scheda per pratica e "Avvia una pratica" (anno e persone). Pagina della pratica (`/pratiche/pratica?id=…`, dettaglio di `/pratiche`): avvisi (regole provvisorie, analisi in corso con aggiornamento automatico, consenso per i cifrati), numeri (confermato, da vedere, mancano), filtro per persona, "Da vedere" con Conferma / Correggi / Escludi, voci confermate per requisito (menu ⋯: Correggi, Escludi, Elimina se a mano), "Cosa manca" con Aggiungi e Non serve, somme dei documenti, Prepara pacchetto, Archivia una copia, Segna come inviata, Cerca di nuovo, Revoca consenso, Elimina pratica. Le voci proposte compaiono anche in "Da confermare" (Correggi apre la pagina della pratica).

Non ancora: confronto con la pratica dell'anno prima per tipo di spesa senza emittente, condivisione diretta del pacchetto con un link per il CAF (oggi: "Archivia" e poi la condivisione esterna del documento), analisi sul telefono (2c).

### Fase 2b: ISEE, com'è fatta (12/11/2026)

**Motore generalizzato** (vale anche per il 730):
- `riferimento` nel modello: anni dall'anno della pratica a quello dei dati (730: 0; ISEE: -2, la DSU 2027 usa redditi e patrimonio del 2025). La finestra dei documenti va dal 1° gennaio dell'anno di riferimento al 30 giugno dopo, o al 31 dicembre dell'anno della pratica se è più tardi (ISEE 2027: dal 2025 al 2027).
- `data` per requisito: `riferimento` (la data deve essere dell'anno di riferimento: le spese), `dopo` (dell'anno dopo: la CU, i redditi), `libera` (nessun controllo dell'anno: documenti d'identità, conti, veicoli).
- Campi nuovi: `iban` (controllo mod 97), `targa` (AA000AA); per gli importi `etichette` (dove cercarli senza AI, anche con data o anno in mezzo: "Giacenza media annua 2024 euro 2.874,10"); `libero` per i testi che non devono comparire nel documento (descrizione); `soloUtente` per i campi che l'AI non compila mai; `confronta` per confrontare due importi.
- Lo schema JSON per Ollama si costruisce dai campi dei requisiti candidati; ogni valore si ricontrolla secondo il tipo del campo.
- Contro gli errori tipici di un modello piccolo: dallo stesso documento una sola voce per requisito e importo (o IBAN, targa); le descrizioni che ripetono le etichette dell'elenco si scartano. Nei dati di prova la visura dava tre "immobili", uno per tipo: ora due, con importi diversi.

**Regole.** `isee-2026.yaml` (DSU 2026, dati 2024; verificato il 12/11/2026 sulle istruzioni INPS della DSU, sul Portale unico ISEE e sulla guida della DSU precompilata) e `isee-2027.yaml` (**provvisorio**). Requisiti per persona del nucleo: documento e codice fiscale, redditi (CU, 730, Redditi PF), assegni e altri trattamenti, conti e rapporti finanziari (saldo al 31/12 e giacenza media, con i valori della precompilata), casa e altri immobili (rendita o valore IMU, mutuo residuo), veicoli (targa), casa in affitto (per famiglia), disabilità. Le novità di legge sul calcolo (es. esclusione dei titoli di Stato) restano note da ricontrollare: l'app non calcola l'indicatore.

**Rapporti e targhe scoperti** (`scopri: iban | targa`, `CandidatiPratica::scoperti`): IBAN validi e targhe trovati nei documenti in chiaro che parlano del requisito (estratti conto, comunicazioni annuali; carte di circolazione, bollo), non in tutti: in una bolletta l'IBAN è del fornitore. Ognuno senza una voce compare in "Cosa manca" con il codice ("trovato nei documenti: IT74 L123…"), con **Completa** (modulo con l'IBAN già scritto) e **Non serve** (`POST /non-serve` con `dettaglio`, es. un conto chiuso).

**Confronto con la DSU precompilata.** Nei conti, "Saldo nella DSU precompilata" e "Giacenza nella DSU precompilata" li scrive la persona; se diversi da quelli del documento compare l'avviso "Giacenza nella DSU precompilata: 2.900,00 € contro 2.874,10 € del documento. Controlla quale è giusto prima di firmare."

**Rinnovo.** `rinnovo` nel modello: quando la pratica è segnata come inviata ("Segna DSU presentata") nasce una volta sola la scadenza "Rinnovare l'ISEE" al 31 dicembre dell'anno, con il promemoria 30 giorni prima (colonna `pratica.scadenza_rinnovo_id`, nel dettaglio `scadenzaRinnovo`).

**App.** Gli stessi schermi del 730: "Il nucleo familiare" nell'avvio, IBAN e targa nel modulo e nelle righe, "Somma dei saldi" con la nota "Non è il calcolo dell'ISEE", l'avviso della scadenza del rinnovo.

Provato con i dati di sviluppo: 4 documenti analizzati (polizza auto, visura, verbale con due targhe), 5 voci proposte con targhe e rendite lette dai documenti.

### Fase 2c: analisi sull'iPhone, com'è fatta (12/11/2026)

**Spunta per dispositivo** in Sicurezza e accesso (menu del profilo), solo su iOS: "Analizza i documenti su questo iPhone quando possibile", con lo stato del modello (disponibile, o perché no: iOS sotto 26, iPhone non supportato, Apple Intelligence spenta, modello in scaricamento, lingua). La scelta resta sul telefono (`shared_preferences`, `ai_locale_attiva`).

**Ponte nativo** in `ios/Runner/AppDelegate.swift` (dentro AppDelegate per non toccare il progetto Xcode), canale `cartellaviva/ai_locale`, compilato solo se c'è `FoundationModels` e usato solo da iOS 26: `stato` (`SystemLanguageModel.default.availability`, `supportsLocale`, `contextSize`) ed `estrai` (`LanguageModelSession(instructions:)`, `respond(to:schema:options:)` con temperatura 0; lo schema JSON ridotto del server diventa un `DynamicGenerationSchema`: oggetti, stringhe, scelte da un elenco, liste; `x-order` tiene l'ordine dei campi). API verificate sull'SDK iOS 27.2 di Xcode. Su Android e sul web lo stato è "non disponibile".

**Pratica `analisiSu`** (`server` | `telefono`, colonna `pratica.analisi_su`): si sceglie all'avvio (telefono se la spunta è attiva e il modello è pronto). Con `telefono` il worker non manda nulla a Ollama (nemmeno i messaggi già in coda o i documenti nuovi dopo l'OCR). `PATCH /pratiche/{id}` con `analisiSu: server` riporta l'analisi sul server e mette in coda i documenti rimasti (nell'app: "Analizza sul server", quando la pratica si apre da un dispositivo senza il modello).

**API per il telefono** (`pratica:manage`):
- `GET /pratiche/{id}/da-analizzare?caratteri=&limite=` → `{lavori: [{documentoId, titolo, istruzioni, prompt, schema}], restano}`; il prompt contiene le parti utili del testo entro `caratteri` (`TestoVoci::porzioni`: tutto se ci sta, altrimenti l'inizio e le finestre intorno alle parole chiave). L'app calcola `caratteri` dal contesto del modello: (contesto − 1500) × 3, tra 1.500 e 12.000. I cifrati solo con il consenso della pratica: il testo si decifra in memoria e va solo al telefono.
- `POST /pratiche/{id}/risultati` `{documentoId, risposta: JSON del modello, modello}` o `{documentoId, errore: codice}` → `{voci: n}`. Il server ricontrolla le voci con le stesse regole dell'analisi sul server (`EstrattoreVoci::daGrezze`) e le salva come proposte con origine `ai_telefono`; 409 se il documento non è da analizzare o è già stato analizzato. Gli errori si registrano e "Cerca di nuovo" li rimette tra i lavori. Al server dell'errore arriva solo il codice, mai il messaggio.

**App.** Nella pagina della pratica, se è "sul telefono" e questo iPhone ha il modello, l'analisi parte da sola, un documento alla volta, con l'avanzamento ("3 fatti, 6 da analizzare. Tieni l'app aperta su questa pagina"); si ferma uscendo dalla pagina e riprende alla prossima apertura.

**Provato** sul simulatore iPhone 17 (iOS 27.2, Apple Intelligence del Mac, contesto 8.192 token), sui 9 documenti del 730/2025 dei dati di prova: 40 secondi in tutto (Ollama qwen3 4B sul server: circa 3 minuti). Trovato il bonifico della ristrutturazione (stesso importo e data di Ollama; come emittente la banca che ordina invece della ditta); non trovata la quietanza dell'assicurazione (Ollama sì); nessun falso "interessi del mutuo" (Ollama aveva preso il capitale estinto). Dopo il consenso, il referto cifrato in Salute è stato letto sul telefono: nessuna voce, giusto. Da provare su un iPhone vero.

### Misurare e allenare l'analisi dei documenti (12/11/2026)

Due comandi da console sul server, per le pratiche **già riviste** (proposte confermate, corrette o escluse nell'app):

- `bin/console app:pratiche:valuta --pratica=<id> [--tenant=<id>] [--negativi] [--limite=N] [--json=file]` rilancia l'analisi (Ollama, o le regole se l'AI è spenta) su ogni documento e la confronta con le voci confermate. Dà, per requisito, le voci giuste, proposte, indovinate, in più e mancate, con precisione e richiamo; per campo quante volte il valore coincide; e le voci giuste che l'analisi non poteva trovare perché mancano parole chiave o categoria nelle regole. Non salva nulla. Con Ollama sul server ogni documento richiede da qualche secondo a un minuto (nei dati di prova: 2 minuti e 41 secondi per 10 documenti).
- `bin/console app:pratiche:esporta-esempi --pratica=<id> [--tenant=<id>] --uscita=<file.jsonl> [--negativi] [--in-chiaro]` scrive gli esempi per allenare il modello (LoRA) nel formato chat (`{"messages": [system, user, assistant]}`, quello di `mlx-lm`): lo stesso prompt dell'analisi e la risposta giusta, con importi e date come sono scritti nel testo. Il file nasce con permessi 600 e resta sul server.

Quali documenti (`EsempiPratica`): con voci confermate (positivi); con proposte tutte escluse ("qui non c'è nulla"); con `--negativi` anche quelli analizzati senza proposte (attenzione: una voce mancata aggiunta a mano senza documento li fa sembrare negativi). Si saltano quelli con proposte ancora da vedere.

**Anonimizzazione** (`Anonimizzatore`, predefinita; `--in-chiaro` solo per prove e chiede conferma): stesse sostituzioni nel testo e nella risposta, con valori finti dello stesso formato. Si sostituiscono:
- nomi e cognomi delle persone della pratica, degli utenti della famiglia, degli intestatari riconosciuti dall'indice e di chi segue etichette come Intestatario, Sig., Nominativo, Cognome e nome, Beneficiario, Ordinante, Dichiarante, Paziente;
- codici fiscali, IBAN (finti ma con il controllo valido), targhe, email, telefoni, numeri di 8 cifre o più;
- importi, moltiplicati per un fattore casuale (stesso ordine di grandezza);
- nome del file.

Restano date, nomi di aziende ed enti, il resto del testo. Nei dati di prova, prima delle etichette restavano i nomi veri delle persone scritti nei documenti; ora no. Non è perfetto (un indirizzo resta): il file va guardato prima di farlo uscire dal server. Lo strato LoRA allenato sui dati veri sarebbe a sua volta un dato personale; allenato sugli esempi anonimizzati no.

**Regole ISEE per gli anni passati**: `isee-2024.yaml` (dati 2022) e `isee-2025.yaml` (dati 2023), provvisorie (stesse regole della DSU 2026), per ricostruire gli anni passati come banco di prova.

### Selezione dei documenti: banco di prova e correzioni (13/11/2026)

**Perché.** Sulla prima pratica ISEE vera quasi tutti i documenti persi non erano mai arrivati al modello: li scartava prima la selezione (parole chiave, finestra di date, OCR). Prima di allenare il modello va misurata e corretta quella.

**Banco di prova.** `php bin/console app:pratiche:prova-cartella <cartella> --anno=2026 [--modello=isee] [--attesi=attesi.json] [--ai] [--json=risultato.json]` prende una cartella di documenti raccolti a mano per una pratica, li legge con lo stesso OCR dei documenti caricati e dice per ogni file per quale requisito verrebbe selezionato; con `--ai` chiede anche le voci al modello. `--attesi` è un JSON `{"percorso/file.pdf": "requisito" | "nessuno"}` scritto a mano una volta: il comando conta giusti, persi, sbagliati e selezionati senza motivo. Non salva nulla nel database. I documenti veri e i file degli attesi restano fuori dal repository (in sviluppo: copia nella `/tmp` del container, che è in RAM, e attesi in `backend/var/banco/`). I documenti si considerano dentro la finestra di date.

**Correzioni alla selezione** (tutte misurate sul banco):

- **Confronto delle parole chiave** su testo "normale" (`Requisito::normale`): minuscolo, senza accenti, apostrofi dritti, spazi e a capo ridotti a uno spazio. Le parole dei file YAML passano dalla stessa funzione al caricamento.
- **Nome del file** come indizio: le `parole` si cercano anche lì (`stato-di-famiglia.pdf`), e `riconosci.nomi` elenca parole cercate solo nel nome, intere (`ci`, `cf`). Anche i `tipi` hanno `nomi`. Un documento senza testo (una foto che l'OCR non ha letto) riconosciuto dal nome diventa una voce con il solo tipo, senza passare dall'AI; la persona si prende dal nome del file se è di una sola persona della pratica.
- **`riconosci.senza`**: parole che escludono il requisito (le lettere delle assicurazioni citano la non autosufficienza, ma non sono certificazioni di disabilità; il rendiconto di una polizza non è un conto).
- **`ancheVecchi: true`** sul requisito: valgono anche i documenti più vecchi della finestra (documento d'identità, veicoli, immobili, affitto, disabilità, nucleo). Per gli altri (redditi, conti, polizze) la finestra resta.
- **Requisiti ISEE nuovi**: `polizze` (polizze vita a contenuto finanziario) e `nucleo` (stato di famiglia). Parole in più per CU, visure e note di trascrizione.
- **Testo per il modello** (`AiVociSuggeritore::testoPerModello`): oltre il limite di caratteri non si taglia più all'inizio, ma si tengono l'inizio e i tratti intorno alle etichette dei campi e alle parole chiave (lo stesso per il server, il telefono e gli esempi esportati).
- **Importo prima dell'etichetta** (`TestoVoci::importoDopo`): negli estratti conto a colonne ("0,00 SALDO FINALE").
- **"Cerca di nuovo nei documenti"** riprova anche i documenti scartati dalle sole regole, così un aggiornamento delle regole ha effetto sulle pratiche già avviate.

**Limiti noti.** Le regole sono state corrette guardando una sola cartella (ISEE 2026 di una famiglia): il risultato su quella cartella non è una misura indipendente, servono altre cartelle. Tesseract non legge alcune foto di tessere con nessuna impostazione provata: lì resta solo il nome del file. Il modello a volte sbaglia il tipo (una CU proposta come 730).

### Modello 730

Anno: **spese dell'anno d'imposta** (730 presentato l'anno dopo). Requisiti, per persona:
- Certificazione Unica (redditi).
- Spese detraibili, ognuna con tipo, importo, data, persona a cui si riferisce, emittente, pagamento tracciabile (sì / no / non risulta): sanitarie (farmacia, ticket, visite, esami, dispositivi), veterinarie, istruzione, attività sportive dei ragazzi, assicurazioni vita e infortuni, interessi del mutuo (certificazione della banca), affitto (es. studenti fuori sede), erogazioni liberali, spese per la casa con bonifico "parlante".
- Controlli mostrati: spesa senza pagamento tracciabile dove serve, persona non indicata, documento di un altro anno, doppioni (stesso importo, data ed emittente).
- Riepilogo: somme per tipo e per persona, dichiaratamente "somme dei documenti, non il calcolo della detrazione".

### Modello ISEE

Anno: **anno di presentazione della DSU**; redditi e patrimonio sono quelli del **secondo anno precedente** (per la DSU 2027: redditi 2025, patrimonio al 31/12/2025, giacenza media 2025). Da ricontrollare ogni anno sulle istruzioni INPS. Requisiti, per persona del nucleo:
- Redditi: CU, 730 o Redditi PF dell'anno di riferimento; altri trattamenti (assegni di mantenimento…).
- Patrimonio mobiliare, **per ogni rapporto** (conto, libretto, carta con IBAN, deposito titoli): saldo al 31/12 e giacenza media annua, dalla comunicazione annuale o dall'estratto conto della banca; valore di titoli e fondi; criptovalute. I rapporti si scoprono dagli IBAN già estratti dai documenti: ogni IBAN diverso è un rapporto da completare.
- Patrimonio immobiliare: visure o valori ai fini IMU, mutuo residuo.
- Veicoli: targhe (già riconosciute nei libretti).
- Casa in affitto: contratto e canone.
- Disabilità e non autosufficienza: certificazioni.
Risultato: checklist e pacchetto per il CAF; una tabella di confronto per controllare la **DSU precompilata** dell'INPS (soprattutto saldi e giacenze); promemoria del rinnovo (l'ISEE scade il 31 dicembre), come scadenza collegata alla pratica.

### Analisi sul telefono (iPhone, modello Apple)

Opzione per dispositivo, scelta dall'utente in **Sicurezza e accesso**: "Analizza i documenti su questo telefono quando possibile".

- **Quando è disponibile**: iOS 26 o successivo, iPhone con Apple Intelligence attiva e modello scaricato, lingua di sistema supportata (l'italiano lo è). L'app lo verifica a ogni avvio (`SystemLanguageModel.default.availability`) e legge il contesto disponibile (`contextSize`); se manca qualcosa, l'analisi resta sul server senza chiedere nulla.
- **Ponte nativo nostro** (canale `cartellaviva/ai_locale`, poche righe Swift con il framework Foundation Models) invece dei plugin Flutter disponibili, tutti ancora sotto la 1.0: `disponibile()` e `estrai(schema, testo) → JSON` con generazione guidata dallo schema del requisito.
- **Flusso**: l'app chiede al server i lavori da fare (`GET /pratiche/{id}/da-analizzare?dispositivo=1`: documento, requisito, schema, porzioni utili del testo entro il contesto del modello; solo documenti che l'utente può leggere, i cifrati solo con il consenso della pratica), esegue il modello sul telefono e manda **solo i valori** (`POST /pratiche/{id}/risultati`, con origine `ai_telefono` e la versione del modello). Il server li ricontrolla con le stesse regole deterministiche e li salva come proposte. Il testo vive solo in memoria sul telefono: mai nei documenti offline né su disco.
- **Perché**: l'AI del server non legge i documenti sensibili di chi sceglie l'opzione, e il server (2 vCPU) non si carica; sul telefono l'analisi è molto più veloce.
- **Limiti**: con l'app aperta (iOS non lascia lavorare a lungo in sottofondo), consumo di batteria, qualità simile a qwen 4B; la pagina della pratica mostra l'avanzamento e riprende da dove si era fermata.
- **Android**: lo stesso contratto potrà usare Gemini Nano (ML Kit) in seguito.

### Ordine di sviluppo

1. **2a**: motore comune (modello, pratica, voci, consenso, revisione, pacchetto) con il **730**, analisi sul server.
2. **2b**: **ISEE** sullo stesso motore (rapporti dagli IBAN, confronto con la precompilata, rinnovo).
3. **2c**: **analisi sul telefono** con il modello Apple, prima sul 730.

### Decisioni sul disegno (12/11/2026)

- Il nucleo ISEE comprende **solo persone della famiglia** di CartellaViva: chi non c'è va aggiunto alla famiglia (anche senza account) prima di avviare la pratica.
- Il pacchetto inviato si archivia **a scelta** dell'utente ("Archivia"), come documento cifrato in "Condivisi con enti".
- I file di regole li verifica ogni anno **chi mantiene il progetto**, prima di attivarli, con la fonte e la data di verifica scritte nel file.
