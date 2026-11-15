# CartellaViva

**L'AI che sbriga la burocrazia di casa senza portarsi via i tuoi documenti.**

## La missione

CartellaViva esiste per dimostrare che l'AI può semplificare la vita quotidiana senza chiedere in cambio i dati personali.

Legge i documenti di famiglie ed enti, li classifica, trova scadenze e importi, prepara il 730 e l'ISEE. Lo fa con modelli eseguiti sul server di CartellaViva o su un dispositivo della famiglia, e nessun documento viene mandato a servizi di AI di terzi. È un'adozione consapevole dell'AI: utile, verificabile, con l'ultima parola alla persona.

Una missione vale quanto la sua verificabilità. Per questo il codice è aperto, con licenza AGPL-3.0, e ogni impegno qui sotto dice dove controllarlo nel codice.

## I quattro impegni

Lo stato è quello di questa versione del codice. Quando un impegno non è ancora mantenuto per intero, lo diciamo.

| | Impegno | Stato oggi | Dove si verifica |
|---|---|---|---|
| 1 | **Nessuna AI di terzi.** Sul server, solo modelli open weight, sul server di CartellaViva in UE o sul tuo. In più, due opzioni che scegli tu: il tuo Mac, con gli stessi modelli open weight, e il tuo iPhone, con il modello di Apple che gira sul telefono (non è open weight, ed è dichiarato). Nessuna API di AI esterna. | Rispettato | Sul server tutte le chiamate AI vanno a Ollama (`OLLAMA_URL`): `AiCategoryClassifier`, `AiEstrattoreDati`, `AiScadenzaSuggeritore`, `AiVociSuggeritore`, `OllamaEmbedder`. Mac: `connettore-mac/`, con Ollama sul Mac. iPhone: `frontend/ios/Runner/AppDelegate.swift` (Foundation Models), usato solo se lo attivi in Sicurezza e accesso. |
| 2 | **Nessun addestramento automatico, sotto il tuo controllo.** Nessun modello impara da solo dai documenti degli utenti. L'unico strumento è manuale: chi gestisce l'installazione (con il self-host, tu) può esportare esempi anonimizzati dalle pratiche già riviste, per misurare o migliorare il proprio modello. Il file resta sul server. | Rispettato | Il codice non esegue addestramenti. L'esportazione è il comando `app:pratiche:esporta-esempi`: anonimizza di default e scrive un file con permessi 600 sul server ([docs/pratiche.md](docs/pratiche.md)). Le correzioni restano nell'archivio della famiglia. |
| 3 | **Modelli dichiarati**: una pagina pubblica con nome, versione, licenza e compito di ogni modello; nell'app, per ogni dato estratto, quale modello e con che sicurezza. | In parte | Il modello è registrato per ogni documento indicizzato (`documento_indice.modello_dati`, `modello_embedding`) e per ogni analisi di una pratica (`pratica_analisi`: modello, durata, Mac che l'ha fatta). Nell'app le voci lette sul Mac lo dicono. Modelli consigliati e licenze: [THIRD_PARTY.md](THIRD_PARTY.md). Da fare: la pagina pubblica, e la sicurezza di ogni dato visibile nell'app. |
| 4 | **Codice aperto**, così chiunque può verificare i tre punti precedenti. | Rispettato | Questo repository, licenza AGPL-3.0. |

## Cosa vuol dire "terzi"

Con il **self-host** (CartellaViva sul tuo server, con Ollama) nessun terzo vede i documenti: è il cuore della missione, non un regalo.

Nel servizio cloud, in preparazione in UE, restano il fornitore dell'infrastruttura (oggi Microsoft Azure), come responsabile del trattamento, e Apple o Google per gli acquisti e per i token delle notifiche, senza contenuti. La promessa corretta è quindi: **nessun dato a terzi per AI, analisi o pubblicità**.

Il Mac e l'iPhone della famiglia non sono terzi: sono dispositivi tuoi. Se scegli di far leggere i documenti a uno di loro, il server gli manda il testo da analizzare e riceve indietro solo i valori letti, che ricontrolla con le stesse regole di sempre. Sull'iPhone il modello è quello di Apple, eseguito sul telefono.

Due funzioni facoltative parlano con Google solo se le configuri e le usi: l'accesso con Google e l'import da Google Drive.

Sulla cifratura serve precisione. I documenti delle categorie sensibili sono cifrati a riposo, con una chiave per documento, e il server li legge in memoria per OCR e classificazione. Si può dire "l'AI gira sul server, nessun servizio esterno vede i documenti", non "neanche il server può leggerli".

## Cosa fa

**Per le famiglie.** Un archivio di documenti condiviso tra i familiari, con ruoli e permessi:
- caricamento, scansione dal telefono, import da Google Drive;
- categorie, con cifratura per quelle sensibili (identità, salute, farmaci);
- ricerca per significato e documenti simili;
- scadenze con promemoria, anche trovate dall'AI nei documenti;
- condivisione temporanea con chi non ha un account (medico, commercialista);
- documenti offline cifrati sul telefono, accesso d'emergenza per un contatto fidato, esportazione completa.

**Pratiche: pagamenti, 730, ISEE.** L'AI legge i documenti della famiglia e propone cosa serve, persona per persona:
- gli avvisi PagoPA da pagare;
- le spese e i redditi del 730;
- conti, giacenze, polizze, immobili e veicoli per l'ISEE.

Ogni proposta dice da quale documento viene e si conferma, si corregge o si scarta: niente entra in una pratica senza conferma. Alla fine c'è un riepilogo da portare al CAF o da confrontare con la precompilata.

Le regole di ogni pratica sono file YAML con le fonti ufficiali (`backend/config/pratiche/`). L'analisi ha un banco di prova su cartelle vere. Sulle pratiche ISEE dell'autore dal 2020 al 2026 (circa 150 file), i documenti giusti vengono riconosciuti in tutte le annate, e saldi e giacenze coincidono con le DSU presentate al CAF.

**Per gli enti** (scuole e associazioni verificate):
- richieste alle famiglie: firma, invio di un documento, presa visione;
- firma con codice via email, che è una firma elettronica semplice;
- verifica affiancata delle risposte, modelli, rinnovi automatici, registro delle attività.

L'ente riceve solo la copia consegnata: non vede mai l'archivio della famiglia.

**Sicurezza.**
- Verifica in due passaggi (TOTP) e sblocco con Face ID sul telefono.
- Chiave per documento per le categorie sensibili.
- Registro delle attività append-only.
- Portale del team che non apre mai i documenti.

## Come lavora l'AI

1. Il documento arriva dall'app (caricamento o scansione) o da Google Drive. Se la categoria è sensibile, viene cifrato prima di arrivare allo storage.
2. Un OCR open source (Tesseract) legge il testo. Un modello open weight via Ollama propone categoria, data, mittente, importi e scadenze.
3. Per le pratiche, il modello legge i documenti che parlano dei requisiti e propone le voci. Regole deterministiche ricontrollano ogni valore sul testo:
   - l'importo deve comparire accanto alla sua etichetta;
   - l'anno deve essere quello giusto;
   - l'IBAN deve essere quello del conto;
   - i doppioni si scartano.
4. L'elaborazione avviene in coda, non in tempo reale: con modelli piccoli basta una CPU.
5. Le proposte si confermano prima di diventare definitive. La data del documento, se manca, e la categoria dei documenti importati da Google Drive con "Categoria scelta leggendo il contenuto" si applicano subito e si correggono quando serve.
6. Le correzioni restano nell'archivio della famiglia e non addestrano nessun modello.

**Dove gira l'AI**

| Dove | Modello | Quando |
|---|---|---|
| Server di CartellaViva o tuo | open weight via Ollama: `qwen3:4b-instruct-2507-q4_K_M` per leggere, `bge-m3` per la ricerca | sempre, se non scegli altro |
| Il tuo Mac | open weight via Ollama, con il connettore in `connettore-mac/` | se colleghi un Mac: prende le analisi delle pratiche, e se è spento il lavoro torna al server |
| Il tuo iPhone | modello di Apple sul telefono (iOS 26, Apple Intelligence) | se lo attivi per quel telefono: analizza le pratiche mentre l'app è aperta |

I documenti cifrati vanno al Mac o all'iPhone solo con il consenso dato nella pratica. Al server tornano solo i valori.

## Stato del progetto

Versione **0.1.0**, in collaudo. Un'installazione gira su un server Linux in UE (2 vCPU, 8 GB), con registrazione su invito. L'app per iPhone si installa per ora in sviluppo; l'app per Mac è una prima versione, firmata per un solo Mac. Cosa manca: [docs/limiti.md](docs/limiti.md).

## Provarlo

Serve Docker. In sviluppo:

```bash
git clone https://github.com/fabiogiorgione/cartellaviva.git
cd cartellaviva
bash scripts/setup-sviluppo.sh            # con --modello scarica anche i modelli AI in Docker (~3,7 GB)
```

Lo script crea i segreti se mancano, avvia i container, applica le migrazioni e carica i dati di prova. Rilanciato, **cancella i dati di sviluppo**. La guida passo-passo, con l'app in debug e gli utenti di prova, è in [docs/avvio-sviluppo.md](docs/avvio-sviluppo.md); gli stessi passi a mano sono in [docs/avvio-sviluppo-a-mano.md](docs/avvio-sviluppo-a-mano.md).

**Ogni installazione ha i suoi segreti.** Nel repository non ce n'è nessuno: ognuno li genera sulla propria macchina, e non vanno mai condivisi o committati. Della master key va fatto il backup ([docs/gestione-chiavi.md](docs/gestione-chiavi.md)): senza, i documenti cifrati diventano illeggibili.

Su un proprio server, con HTTPS: [docs/installazione-server.md](docs/installazione-server.md).

## Manuale tecnico

| Guida | Contenuto |
|---|---|
| [avvio-sviluppo.md](docs/avvio-sviluppo.md) · [avvio-sviluppo-a-mano.md](docs/avvio-sviluppo-a-mano.md) | ambiente di sviluppo, utenti di prova, configurazione, test |
| [installazione-server.md](docs/installazione-server.md) | installazione in produzione, backup, aggiornamenti |
| [gestione-chiavi.md](docs/gestione-chiavi.md) | cifratura dei documenti, master key, rotazione |
| [architettura.md](docs/architettura.md) | struttura del codice, convenzioni, modello dei permessi, audit log |
| [accesso.md](docs/accesso.md) | password, Google, verifica in due passaggi, Face ID, registrazione, anagrafica |
| [documenti.md](docs/documenti.md) | caricamento, cifratura, anteprime, OCR, scansione, ricerca per significato |
| [import-google-drive.md](docs/import-google-drive.md) | import da Drive e classificazione automatica |
| [famiglia.md](docs/famiglia.md) | scadenze, condivisione, notifiche, offline, emergenza, aiuto, impostazioni |
| [pratiche.md](docs/pratiche.md) | pagamenti, 730, ISEE: motore delle regole, analisi, banco di prova, iPhone |
| [connettore-ai.md](docs/connettore-ai.md) | l'AI sul Mac della famiglia: protocollo, sicurezza, app per macOS |
| [enti.md](docs/enti.md) | scuole e associazioni: verifica, richieste, firma con codice |
| [portale.md](docs/portale.md) | portale della piattaforma e delle idee |
| [navigazione.md](docs/navigazione.md) | menu e percorsi dell'app |
| [api.md](docs/api.md) | endpoint principali (l'elenco completo è su `/api/docs`) |
| [limiti.md](docs/limiti.md) | limiti noti e cose da fare |

## Licenza e marchio

- **Codice, server e app: AGPL-3.0-or-later** ([LICENSE](LICENSE)). È la licenza che tiene verificabile la missione anche nei servizi derivati: chi offre CartellaViva come servizio in rete deve rendere disponibile il codice, con le sue modifiche. L'app mostra il link al codice sorgente nel menu dell'account; chi pubblica una versione modificata lo fa puntare al proprio repository con `--dart-define=SOURCE_URL=...`.
- **Contributi**: per accettare contributi esterni servirà un accordo di licenza del contributore (CLA), in preparazione. Finché non c'è, le pull request esterne non vengono accettate; segnalazioni e proposte sono benvenute come issue. Vedi [CONTRIBUTING.md](CONTRIBUTING.md).
- **Sicurezza**: le vulnerabilità si segnalano in privato, non con una issue pubblica. Vedi [SECURITY.md](SECURITY.md).
- **Marchio**: il nome CartellaViva non è coperto dalla licenza del codice. Il codice è libero, il nome no: un fork deve chiamarsi in un altro modo.
- **Componenti di terze parti** e loro licenze: [THIRD_PARTY.md](THIRD_PARTY.md).

Copyright (C) 2026 Fabio Giorgione.
