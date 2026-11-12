# Navigazione dell'app

Come ci si muove in CartellaViva, su telefono e su schermo largo. Ogni schermata nuova segue queste regole.

## Regole

1. **Due tipi di pagina.**
   - **Primo livello**: le voci del menu a sinistra (Home, Documenti, Scadenze…). Si aprono con `apriDalMenu(rotta)` (`lib/layout/navigazione.dart`): la pila riparte dalla Home, quindi "indietro" da una voce del menu porta sempre alla Home.
   - **Dettaglio**: tutto ciò che si apre da una pagina (un documento, una richiesta, una guida). Si apre con `Get.toNamed` e dichiara il genitore: `Layout(indietro: '/documenti', ...)`.
2. **Sempre una via d'uscita.** Su telefono il primo livello ha il menu ☰, il dettaglio la freccia indietro (`tornaIndietro`: pagina precedente o, se manca, il genitore). Su schermo largo il dettaglio mostra il percorso (`Briciole`) con un collegamento al genitore.
3. **Fogli e pannelli** (filtri, scelte, azioni) con `apriFoglio` (`lib/widgets/foglio.dart`): titolo, "Chiudi", contenuto che scorre, pulsante principale fisso in fondo (es. "Mostra 24 documenti"). Mai a tutto schermo, mai senza modo visibile di chiuderli.
4. **Il telefono non è un desktop stretto.** Sotto i 769 pixel (`larghezzaTelefono`):
   - liste come elenco di righe toccabili (titolo, una riga di dettagli, al massimo un'azione); niente tabelle che scorrono di lato;
   - azioni secondarie nel menu ⋯ della riga o della barra in alto, non in file di pulsanti;
   - selezione multipla e azioni di gruppo solo dopo "Seleziona";
   - paginazione semplice (precedente / successiva, o "Carica altri"), mai larga più dello schermo;
   - in una riga il testo ha sempre la precedenza: i pulsanti vanno sotto, non a destra, se il testo non ci sta.
5. **Il menu evidenzia la pagina corrente** (su telefono e desktop) leggendo la rotta di GetX (`UrlService.getCurrentUrl`), non l'indirizzo del browser.

## Menu a sinistra (famiglia)

Uguale su schermo largo e nel cassetto del telefono (`lib/layout/barra_laterale.dart`):

- **Home**
- **Archivio**: Documenti (Tutti i documenti, Categorie, Analisi AI) · Scansiona o carica · Documenti offline (solo app) · Condivisioni esterne
- **Da sbrigare**: Da confermare (con il numero di proposte, nascosto se 0) · Scadenze · Pratiche (Panoramica, Pagamenti) · Richieste dagli enti
- **Famiglia**: Membri e accessi · Impostazioni famiglia · Contatto d'emergenza
- **Aiuto**: Aiuto e guide · Suggerisci un'idea

Il menu dell'avatar contiene solo l'account: Dati anagrafici, Sicurezza e accesso, Codice sorgente (AGPL), Blocca schermo, Esci. Ente e portale hanno i loro menu, con le stesse regole.

## Schermate

| Rotta | Tipo | Genitore |
| --- | --- | --- |
| `/dashboard` | primo livello (Home) | — |
| `/documenti`, `/categorie`, `/documenti/analisi`, `/documenti/nuovo`, `/offline`, `/condivisioni` | primo livello | — |
| `/documento/:id` | dettaglio | `/documenti` |
| `/import/esito` | dettaglio | `/documenti` |
| `/scadenze`, `/richieste` | primo livello | — |
| `/da-confermare`, `/pratiche`, `/pratiche/pagamenti` | primo livello | — |
| `/pratiche/pratica?id=…` | dettaglio | `/pratiche` |
| `/richieste/:id` | dettaglio | `/richieste` |
| `/tenant/members`, `/famiglia/impostazioni`, `/emergenza` | primo livello | — |
| `/aiuto`, `/idee` | primo livello | — |
| `/aiuto/guida` | dettaglio | `/aiuto` |
| `/account/profile`, `/account/security` | primo livello (menu account) | — |
| `/ente`, `/ente/iscritti`, `/ente/gruppi`, `/ente/richieste`, `/ente/modelli`, `/ente/verifica`, `/ente/registro`, `/ente/profilo` | primo livello (ente) | — |
| `/ente/richieste/nuova`, `/ente/richieste/:id`, `/ente/verifica` | dettaglio | `/ente/richieste` |
| `/admin/...` | primo livello (portale) | — |

## Problemi trovati (12/11/2026, simulatore iPhone 17 e codice)

Telefono:
- Nessun dettaglio aveva la freccia indietro: con il cassetto, la barra in alto mostrava sempre ☰ (es. documento → lista documenti). *Corretto.*
- Filtri dei documenti: foglio a tutto schermo senza titolo, "Chiudi" o "Applica"; non si chiude nemmeno trascinando, si restava bloccati. *Corretto: foglio con titolo, Chiudi e «Mostra N documenti».*
- Lista documenti: la paginazione esce dallo schermo (overflow di 24 pixel); azioni di gruppo, "per pagina" e ordinamento spingevano i documenti sotto la piega. *Corretto: una riga con ricerca, Filtri e menu ⋯; selezione dopo «Seleziona».*
- Scadenze: nelle "Scadenze trovate nei documenti" i pulsanti a destra schiacciano il testo in una colonna di poche lettere; la pagina era inutilizzabile. *Corretto.*
- Membri e accessi: tabella tagliata a destra (colonna Stato fuori schermo). *Corretto: elenco con menu ⋯.*
- Categorie: filtri e tabella da desktop. *Corretto: elenco e foglio dei filtri.*
- Il menu non evidenziava mai la pagina corrente (leggeva l'indirizzo del browser, che nell'app è sempre `/`). *Corretto.*
- Il menu dell'avatar mescolava account, pagine della famiglia e documenti (14 voci). *Corretto: solo account.*
- "Documenti offline" ha una barra sua, diversa dalle altre: resta così, perché funziona anche senza accesso e senza rete.
- Tabelle del portale che scorrevano di lato: *corretto*, sul telefono `ListaTabella` mostra una scheda per riga e i filtri stanno chiusi sotto "Filtri". Etichetta "Invio documento" che andava a capo e logo sovrapposto in "A presto!": *corretti*. Dettagli delle richieste (famiglia ed ente): *provati*.
- Regola per le tabelle nuove: usare `ListaTabella` e `listaPannelloFiltri` (`lib/widgets/lista_crud.dart`), che sul telefono passano da sole a schede e filtri chiusi.

Schermo largo:
- Le voci del menu impilavano le pagine (`Get.toNamed`): "indietro" ripercorreva tutte le pagine viste. *Corretto con `apriDalMenu`.*
- Nei dettagli il ritorno alla lista dipendeva dal percorso in alto, presente solo in alcune pagine. *Corretto: «← genitore» in cima a ogni dettaglio.*
- Voce attiva del menu spenta nei dettagli, barra in alto sotto l'orologio dell'iPad, filtri a lato che stringevano la tabella, pulsante "schermo intero" anche nell'app. *Corretto.*
- Nella tabella documenti stretta (accanto al menu, sotto i 1100 pixel) la data andava a capo: *corretto*, "Riguarda" si vede nel dettaglio. "Contatto d'emergenza" ora spiega cosa mostra e rimanda a Impostazioni famiglia › Emergenza.

## Come si prova

- `dart analyze` senza problemi e `flutter test` verde.
- Simulatori: iPhone 17 per il telefono, iPad Pro 13" in orizzontale per lo schermo largo (sopra i 769 pixel l'app usa il layout desktop). L'app si avvia con `flutter run -d <simulatore> -t test_driver/app.dart --dart-define=API_BASE_URL=http://127.0.0.1:8090/api`; `test_driver/app.dart` apre la rotta scritta nel file `tmp/vai.txt` della cartella dell'app (vedi il commento nel file), e `xcrun simctl io <simulatore> screenshot` fotografa lo schermo.
- Non aprire "Documenti offline" nel simulatore: chiede il codice del dispositivo e blocca il simulatore.
- Utenti di prova: `mario.rossi@example.com` (proprietario), `giulia.rossi@example.com` (membro), `nonno.rossi@example.com` (sola lettura), `segreteria@scuola-demo.it` (ente). La password è quella dei dati di prova del database locale.
