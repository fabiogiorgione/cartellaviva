# Funzioni per la famiglia

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

## Scadenze

Lo scadenziario della famiglia. Ogni scadenza ha titolo, data e note. Può avere anche:
- una ricorrenza: nessuna, mensile o annuale;
- un promemoria N giorni prima;
- un collegamento a un documento, a una persona o a una categoria.

Permessi:
- `scadenza:read` per vedere le scadenze;
- `scadenza:manage:own` / `:all` per gestirle. Il viewer è in sola lettura.

Comportamento:
- **Fatta**: segna la scadenza come fatta. Se è ricorrente, crea l'occorrenza successiva. Il 31 gennaio mensile diventa il 28 o 29 febbraio.
- **Promemoria**: ogni giorno alle 07:00 (Europe/Rome) lo scheduler invia una sola email per utente. La ricevono il proprietario della scadenza e la persona collegata, se ha un account con `scadenza:read`. L'email contiene solo il numero di scadenze, mai i titoli, e ogni promemoria parte una volta sola. Se cambiano la data o i giorni di preavviso, il promemoria viene ripianificato.
- **Documento cancellato**: la scadenza resta, segnata come "documento rimosso".
- **Origine `richiesta`**: è riservata alle richieste degli enti (fase 3). Queste scadenze non si modificano a mano: si ottiene 409.

App: pagina `/scadenze` con elenco e calendario. Le prossime scadenze compaiono anche nella dashboard e nella scheda del documento.

**Scadenze proposte dai documenti.** Dopo l'OCR, `ProponiScadenzaMessage` cerca nel testo le date future (fino a 20 anni) vicine a parole come "scadenza", "valido fino", "expiry". Il punteggio lo decide la parola più vicina alla data: "nascita" ed "emissione" contano contro.
- Con Ollama attivo, il modello sceglie **solo tra le date trovate nel testo**, con un output vincolato a un elenco chiuso, e suggerisce il tipo di documento. Se Ollama non risponde, valgono le regole.
- La proposta resta sul documento finché non viene confermata o ignorata. Nei documenti cifrati è cifrata con la stessa chiave del testo OCR, e nell'audit non compare la data.
- API:
  - `GET /api/tenants/{t}/scadenze-proposte` elenca le proposte, solo a chi può creare scadenze;
  - `POST /api/tenants/{t}/documenti/{id}/scadenza-proposta/ignora` ignora una proposta;
  - per confermarla si crea una scadenza normale collegata al documento, che chiude la proposta.
- App: riquadro "Scadenze trovate nei documenti" in dashboard e nello scadenziario, più la proposta nella scheda del documento. Il modulo si apre già compilato.

## Condivisione esterna temporanea

Serve a mandare uno o più documenti a chi non ha un account (medico, commercialista, agenzia). Si crea dal pulsante "Condividi" nella scheda del documento.
- **Due segreti su canali diversi:** il **link** (`/condivisione?token=`, a DB solo l'hash) e un **codice** `XXXX-XXXX` (a DB solo un hash lento). Il codice si vede una volta sola, e chi condivide lo comunica a parte.
- **Limiti:** valida da 1 ora a 30 giorni, con un numero massimo di download (1-50). Si revoca in ogni momento e 5 codici errati la bloccano. Non viene salvata l'email del destinatario: la "nota" è solo un promemoria per chi condivide.
- **Prima del codice** il destinatario vede solo quanti documenti sono e il nome di chi condivide. Dopo il codice ha una sessione di 30 minuti (header `X-Condivisione-Sessione`).
- **Download e avvisi:**
  - il file si decifra in memoria al momento del download, e ogni download va nell'audit della famiglia (`documento.download_esterno`);
  - al primo accesso parte un'email di avviso a chi ha condiviso, senza titoli.
- **Permessi:** `condivisione:manage:own` (membro) o `:all` (proprietario). Il lettore non condivide.
- **Revoca automatica:**
  - i link di chi esce dalla famiglia vengono revocati;
  - quando si chiede l'eliminazione della famiglia vengono revocati tutti;
  - quelli scaduti da 30 giorni vengono cancellati.
- App: `/condivisioni` per l'elenco con revoca, `/condivisione?token=` è la pagina pubblica del destinatario.

## Notifiche push

Si affiancano alle email per le richieste degli enti (nuove, rifirma, solleciti) e per il promemoria delle scadenze, con **lo stesso testo dell'oggetto dell'email**: mai titoli di documenti o richieste. Toccandole si apre la pagina indicata (`/richieste`, `/scadenze`), solo percorsi interni.
- **Backend:** Firebase Cloud Messaging, API HTTP v1, con un service account.
  - Variabili: `FIREBASE_PROJECT_ID` e `FIREBASE_CREDENTIALS`, che è il **percorso** del JSON del service account, montato come secret: mai nel repository né a DB.
  - Se mancano le variabili, le push risultano "non configurate" nella salute del portale e restano solo le email.
  - L'invio avviene nel worker (`InviaPushMessage`); i token che FCM dichiara non validi vengono eliminati.
- **API:** `POST /api/me/dispositivi {token, piattaforma}` e `DELETE /api/me/dispositivi {token}`, chiamata al logout. Un token appartiene a un solo account: se un altro utente entra dallo stesso telefono, il token passa a lui.
- **App (Android/iOS):** la configurazione si passa con `--dart-define`: `FIREBASE_API_KEY`, `FIREBASE_PROJECT_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_ANDROID_APP_ID`, `FIREBASE_IOS_APP_ID`. Non servono `google-services.json` né `GoogleService-Info.plist` nel repository. Su iOS va configurata la chiave APNs nel progetto Firebase.

## Documenti offline sul telefono

Su Android e iOS, l'interruttore "Disponibile offline" nella scheda del documento salva una copia sul telefono. Si consultano dalla pagina `/offline`, raggiungibile anche dal login: funziona **senza rete e con la sessione scaduta**.
- **Cifratura:** file ed elenco, compresi i titoli, sono cifrati con AES-256-GCM. La chiave è generata sul telefono e resta nel Keystore/Keychain: non va mai al server.
- **Sblocco:** per aprirli serve il blocco schermo del telefono (impronta, volto o PIN, con `local_auth`), che vale 5 minuti. Senza blocco schermo i documenti offline non si aprono.
- **Apertura:** per aprire un file con un'altra app serve una copia in chiaro temporanea in cache, eliminata alla successiva apertura della pagina offline.
- **Logout:** l'uscita esplicita cancella file, elenco e chiave di quell'account.
- **Android:** `MainActivity` estende `FlutterFragmentActivity`, come richiesto da `local_auth`. Su iOS c'è `NSFaceIDUsageDescription`.

## Accesso d'emergenza

Un contatto fidato **con un account CartellaViva** può chiedere di consultare i documenti della famiglia. Il flusso:
1. Un proprietario lo indica: scheda "Emergenza" nelle impostazioni della famiglia, con email, giorni di attesa (3-30) e categorie visibili oppure tutte. Al massimo 3 contatti.
2. Il contatto accetta dal link email (`/emergenza/accetta?token=`, valido 7 giorni) con l'account di quella casella. Da quel momento può chiedere l'accesso, ma non vede nulla.
3. La richiesta (`/emergenza`) avvisa per email e push proprietari e membri, che possono **opporsi** dall'app o dal link nell'email (`/emergenza/annulla?token=`).
4. Senza opposizione, a fine attesa il job orario lo rende membro con il ruolo di sistema **`emergenza`**, in sola lettura: documenti, scadenze, persone. Se sono state scelte delle categorie, `Membership.categorieConsentite` limita sia il voter sia l'elenco dei documenti.
5. Un proprietario lo revoca in ogni momento, e la revoca toglie anche l'accesso.

Il ruolo `emergenza` non è assegnabile con gli inviti normali. Ogni passo va nell'audit della famiglia.

## Aiuto e accoglienza

- **Introduzione** al primo accesso: tre schermate, diverse per famiglia ed ente. Si vede una volta sola (`POST /api/me/intro-vista`, `introVista` in `/api/me`); chi usava già l'app prima della migrazione non la vede.
- **Primi passi:** un riquadro nella dashboard della famiglia (proprietari e membri) e nella home dell'ente (segreteria).
  - `GET /api/tenants/{t}/primi-passi` li calcola **dai dati reali**, senza spunte manuali: per la famiglia profilo, persone, primo documento, scadenza, familiare invitato, 2FA, contatto d'emergenza; per l'ente verifica, gruppi, iscritti, inviti, prima richiesta, operatori.
  - Restituisce solo id e "fatto": testi e collegamenti sono nell'app (`lib/features/aiuto/aiuto_widgets.dart`).
  - Si nasconde per utente e per spazio con `POST …/primi-passi {nascosto}`.
- **Centro Aiuto** (`/aiuto`): domande frequenti ricercabili e filtrate per area, più le guide passo-passo (`/aiuto/guida?id=`) con il pulsante "Vai" verso la pagina giusta. Le guide per le segreterie coprono avvio dell'ente, import CSV, richieste, modelli e rinnovi. I contenuti stanno in `lib/features/aiuto/contenuti_aiuto.dart`.
- **Stati vuoti** con spiegazione, azione principale e guida (documenti, scadenze, iscritti, richieste e modelli dell'ente), più il link "Come funziona?" nelle pagine dell'ente.

## Impostazioni e dati della famiglia

**Modalità avanzata.** Di default una famiglia usa solo i ruoli Proprietario, Membro e Lettore.
- Con la modalità avanzata (`PATCH /api/tenants/{id}` `{"modalitaAvanzata": true}`) si possono creare ruoli personalizzati con permessi scelti uno per uno.
- Senza modalità avanzata, creare un ruolo personalizzato dà 409. I ruoli personalizzati esistenti non si vedono e non si assegnano.
- Per spegnerla, nessun membro e nessun invito in attesa deve usare un ruolo personalizzato (altrimenti 409).
- Gli enti hanno sempre i ruoli personalizzati.

**Esportazione** (solo proprietari).
- `POST /api/tenants/{id}/esportazioni` restituisce un link monouso, valido 24 ore.
- Il link (`GET /api/esportazioni/{token}`, pubblico: il token fa da credenziale) genera lo ZIP al volo, decifrando un documento alla volta in memoria. Nessuno ZIP viene salvato sul server.
- Nello ZIP c'è una cartella per categoria più `indice.csv` (separatore `;`, UTF-8 con BOM, si apre in Excel). Le celle che iniziano con `=`, `+`, `-` o `@` vengono neutralizzate.

**Eliminazione della famiglia** (solo proprietari).
- `POST /api/tenants/{id}/eliminazione` richiede `{conferma: <nome della famiglia>, codice: <codice 2FA>}`. Serve la 2FA attiva.
- La famiglia diventa di sola lettura per 30 giorni (`app.tenant_deletion_grace_days`): il voter nega ogni permesso che non sia `:read`.
- Google Drive viene scollegato subito: i token sono revocati presso Google e cancellati.
- Tutti i membri ricevono un'email.
- `DELETE /api/tenants/{id}/eliminazione` annulla: lo può fare qualsiasi proprietario.
- Alla scadenza, la pulizia oraria nel worker cancella file, chiavi dei documenti e dati. Restano solo le righe dell'audit log e gli account degli utenti, che possono avere altre famiglie.

**Google Drive dall'app.**
- `POST …/import-sources/{sid}/connect` accetta `{"ritorno": "web"|"app"}`. Sono destinazioni fisse della configurazione, mai URL liberi.
- Con `app`, il callback rimanda a `MOBILE_RETURN_URL` (default `cartellaviva://import/esito`). Il deep link è registrato in `AndroidManifest.xml` e `Info.plist` e gestito da `DeepLinkService` (pacchetto app_links).

**App.**
- Il menu laterale mostra le voci in base ai permessi.
- Il selettore famiglia/ente è in cima al menu.
- Nella pagina `/documenti` il pannello dei filtri mostra quanti documenti ci sono per anno, mittente, tag, categoria, persona e tipo di file. I filtri si combinano. La lista si può anche vedere **a gruppi** (per mittente, anno, categoria o tag): i documenti di un gruppo si caricano quando lo si apre.
- I conteggi vengono da `GET /api/tenants/{id}/documenti/faccette`, con gli stessi filtri e le stesse restrizioni della lista. Mittente e tag vengono dall'indice AI, quindi i documenti cifrati finiscono in "Senza mittente" e "Senza tag". Lo stesso mittente scritto in modi diversi conta una volta sola: maiuscole, punteggiatura e forma societaria non contano.
- La **data del documento**, se manca, è quella trovata dall'AI durante l'indicizzazione: non sovrascrive mai una data inserita a mano e scarta le date future. Si corregge dalla modifica del documento.
- Nella pagina `/documenti` si può cercare, filtrare e caricare più file. Su web e desktop si possono anche trascinare: un file = un documento, con una sola conferma.
- La scheda documento permette di aprire/scaricare, modificare ed eliminare.
- `/famiglia/impostazioni` ha le schede Generale, Persone, Categorie (solo un rimando a Documenti → Categorie), Google Drive, Ruoli e Dati.
- Durante l'eliminazione programmata un banner avvisa che la famiglia è in sola lettura.
