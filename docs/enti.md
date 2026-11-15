# Enti: scuole e associazioni

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

**Registrazione e verifica.**
- `POST /api/enti`: chiunque abbia un account registra l'ente e ne diventa proprietario.
- Viene creato l'anno in corso (`2026/27`).
- Un codice di 6 cifre (30 minuti, 5 tentativi) va all'email istituzionale:
  - scuole: `<codice meccanografico>@istruzione.it`, ricavata dal codice e non scelta dall'utente;
  - associazioni: l'email o PEC del RUNTS.
- Confermato il codice, l'ente è "in verifica".
- Il superadmin (`/api/admin/enti`) approva solo con il controllo sul registro pubblico spuntato; la telefonata è facoltativa. Può anche chiedere integrazioni o respingere, con una nota.
- Cambiare codici o email riporta alla conferma.
- Finché l'ente non è verificato può preparare gruppi, iscritti e operatori, ma non invitare né inviare richieste (409).

**Ruoli dell'ente.**
- **Proprietario**: tutto.
- **Segreteria**: `ente:tutti_i_gruppi`, gestione di struttura, iscritti e operatori.
- **Operatore** (`staff`): vede solo iscritti e richieste dei gruppi a cui è assegnato (`PUT …/gruppi/{id}/operatori`).
- Una richiesta la vedono la segreteria, gli operatori del gruppo destinatario e chi l'ha creata.

**Struttura.**
- Anni: `POST …/anni/{id}/passaggio` copia i gruppi indicati, con iscritti e operatori, nel nuovo anno e archivia il vecchio in sola lettura.
- Sedi e gruppi (classi o attività).
- Iscritti, sensibili per default: ogni accesso concesso va nell'audit. Ciascuno ha 1..n referenti.
- Import CSV con anteprima (`applica: false`), poi conferma. Gli inviti non partono con l'import.

**Collegamento con le famiglie.**
- L'operatore invia gli inviti (`POST …/inviti`) solo da ente verificato.
- L'email parte sempre: l'ente non scopre se esiste un account.
- Dal link (`/collegamenti/invito?token=`) il referente può:
  - accettare con l'account della stessa email, scegliendo famiglia e persona (l'ente non vede questa scelta);
  - rifiutare;
  - dire "non sono io": funziona anche senza account.
- La famiglia può interrompere il collegamento in qualsiasi momento.

**Richieste.**
- Tipi: firma (PDF obbligatorio), invio di un documento, presa visione.
- Destinatari: un gruppo o una selezione di iscritti.
- Firmatari: "tutti" o "uno".
- C'è un destinatario per famiglia e iscritto: genitori in famiglie diverse ricevono ciascuno la propria richiesta.
- Gli iscritti senza referenti collegati compaiono tra i "non raggiunti".
- Con "entro il", nella famiglia nasce una scadenza (origine `richiesta`) che:
  - non si spunta a mano;
  - passa "in verifica" alla risposta;
  - si chiude quando l'ente accetta;
  - si riapre se l'ente chiede di rifirmare.
- **Risposta della famiglia:**
  - scansione legata alla richiesta (bozza separata per richiesta), file, o documento dall'archivio (solo per l'invio);
  - per la presa visione basta la conferma;
  - "non firmo / non autorizzo" è una risposta valida, con messaggio.
- **Consegna a due copie:**
  - l'ente riceve un `FileCifrato` cifrato con la sua chiave, e lo conserva anche dopo una revoca;
  - la famiglia conserva la sua copia nella categoria di sistema "Condivisi con enti" (sensibile).
  - L'ente non accede mai all'archivio della famiglia.
- **Lato ente:** avanzamento per iscritto, verifica (accetta o chiedi di rifirmare con motivo), sollecito (tutti o uno), ZIP delle risposte accettate (generato al volo), annullamento.
- Le email dicono solo "hai una richiesta da <ente>". Audit su entrambi i lati.
- `app:crypto:key rewrap/status` include i file degli enti.

## link senza account, modelli, rinnovi e ricorrenze

**Risposta senza account.** I referenti con email, non collegati, ricevono un link sicuro per quella richiesta. Ne sono esclusi:
- chi ha detto "non sono io";
- chi ha rifiutato l'invito;
- chi ha interrotto il collegamento.

Il link (`/risposta?token=`) da solo non mostra nulla.
- `POST /api/link/{t}/codice` invia un codice di 6 cifre all'email del referente (10 minuti, 5 tentativi).
- `POST …/verifica` restituisce una sessione di 2 ore, da mandare nell'header `X-Link-Sessione`.
- Con la sessione si legge la richiesta, si scarica il PDF, si risponde (file o presa visione) o si rifiuta.
- Il link scade 7 giorni dopo "entro il" (30 giorni senza data). Un sollecito o una rifirma generano un nuovo link e annullano il precedente.
- La copia consegnata resta solo all'ente.

**Dall'account.** Dopo l'invio il referente può creare l'account, o accedere, con la stessa email. Con `POST /api/link/{t}/collega` (autenticato, con sessione):
- il referente diventa collegato alla famiglia scelta;
- tutte le sue risposte date dal link, anche per altre richieste, vengono copiate in "Condivisi con enti";
- le richieste ancora aperte passano nell'app, con la loro scadenza.

**Modelli e regole** (`/api/enti/{t}/modelli`). Contenuto riusabile più tre regole:
- **validità**: chi consegna indica fino a quando vale il documento (`validoFino`, obbligatorio se richiesto);
- **rinnovo automatico**: N giorni prima della scadenza parte una nuova richiesta al solo interessato, una volta sola per iscritto. `GET …/documenti-in-scadenza` elenca i documenti in scadenza per il cruscotto;
- **conservazione**: dopo N mesi dalla conclusione la copia dell'ente viene eliminata, e la consegna resta marcata "copia eliminata".

Le stesse regole si possono indicare su una singola richiesta, o prenderle dal modello con `modelloId`.

**Ricorrenze.** Un modello con giorno e mese (solo la segreteria) parte ogni anno verso tutti gli iscritti dell'anno in corso, con "entro il" a N giorni.

**Job.** Lo esegue `AutomazioniEntiMessage`, ogni giorno alle 06:00 (Europe/Rome), nel worker.

## verifica affiancata e registro attività

**Verifica affiancata** (`/ente/verifica`, oppure `?richiesta=` per una sola richiesta). Una coda di tutte le risposte "da verificare" che l'utente può vedere (tutto l'ente o solo i suoi gruppi), dalla più vecchia.
- **Disposizione:** a sinistra il documento consegnato. Le immagini hanno lo zoom; i PDF sono resi pagina per pagina con il pacchetto `printing` (pdf.js sul web, renderer nativo su mobile). A destra la richiesta, il messaggio della famiglia e i controlli da spuntare.
- **Controlli:** documento giusto, leggibile e completo, poi in base al tipo di richiesta:
  - una firma per ogni referente se servono tutte, segnalando quelle non dichiarate dalla famiglia;
  - "almeno una firma" se ne basta una;
  - la validità dichiarata, se richiesta.
- **Decisione:** "Accetta" si attiva solo con tutti i controlli spuntati. "Chiedi correzione" richiede un motivo, scelto tra i più comuni o scritto a mano. Poi si passa subito alla risposta successiva.
- **Scorciatoie:** A accetta, R chiedi correzione, freccia destra salta.
- **API:** `GET /api/enti/{t}/da-verificare[?richiestaId=]` restituisce solo metadati: il file si apre con l'endpoint di sempre, che resta registrato.

**Registro attività** (`/ente/registro`). È il registro del tenant (`GET /api/tenants/{t}/audit_logs`) con due nuovi filtri:
- `attivita=1` toglie i controlli tecnici dei permessi e gli elenchi;
- `attore=` cerca per email di chi ha agito.

Le azioni compaiono in italiano e si filtrano per argomento: richieste, collegamenti, iscritti, ente, persone, inviti. Lo vedono proprietari e segreteria (`audit:read` aggiunto al gruppo della segreteria), non gli operatori.

## firma con codice (FES)

Per le richieste di tipo firma l'ente sceglie `modalitaFirma`: `cartacea` (predefinita: stampa, firma e scansione) o `codice`. Si può indicare sulla richiesta o nel modello. Con `codice` l'allegato deve essere un PDF.

**Valore legale.** È una firma elettronica semplice (art. 20 CAD, eIDAS): valida, ma "liberamente valutabile" in giudizio. Va bene per autorizzazioni, liberatorie e circolari. Non sostituisce la firma autografa dove la legge la richiede: per quei casi la modalità cartacea resta sempre disponibile, anche sulle richieste con codice. La FEA tramite fornitore esterno (TSP) è progettata ma non implementata.

**Flusso**, uguale dall'app e dal link senza account:
1. il referente apre il PDF;
2. chiede un codice di 6 cifre, che arriva alla sua email (10 minuti, 5 tentativi). Viene registrata l'impronta SHA-256 dell'originale;
3. spunta la dichiarazione di presa visione e inserisce il codice.

**Evidenze.** Per ogni firma `FirmaCodice` registra:
- nome ed email del firmatario, account o link;
- data e ora, IP, user agent;
- testo della dichiarazione;
- SHA-256 del documento originale e del PDF firmato;
- un codice di verifica di 12 caratteri.

**Completamento.** Quando firmano tutti i referenti (o uno, se basta uno), il PDF originale riceve in coda una pagina di attestazione con le firme (dompdf + `pdfunite`). Il file viene salvato cifrato:
- per l'ente, come consegna;
- per la famiglia, in "Condivisi con enti".

**Endpoint**
- Famiglia: `POST /api/tenants/{t}/richieste/{id}/firma/codice` e `POST …/firma {codice, accetto}`.
- Link: `POST /api/link/{token}/firma/codice` e `POST …/firma`, con `X-Link-Sessione`.
- Verifica pubblica: `GET /api/verifica-firma/{codice}` (con rate limit). Mostra solo i dati già stampati sull'attestazione, con l'email mascherata. La pagina dell'app è `/verifica-firma?codice=`.
