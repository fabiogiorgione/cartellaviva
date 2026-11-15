# Accesso e anagrafica

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

## Accesso: password, Google, verifica in due passaggi

```
password ─┐                       ┌─ 2FA non attiva ─> token JWT + refresh token
          ├─> primo fattore OK ───┤
Google  ──┘                       └─ 2FA attiva ─────> challenge (5 min, max 5 tentativi)
                                                         └─ POST /api/auth/2fa {challenge, code} ─> token
```

### Verifica in due passaggi (TOTP)

È compatibile con Google Authenticator, Microsoft Authenticator e app analoghe (SHA1, 6 cifre, 30 s).
- **Attivazione:** dalla pagina "Sicurezza" si scansiona un QR code e si conferma con il primo codice. Vengono poi mostrati **10 codici di recupero** monouso.
- **Protezioni:**
  - il segreto è cifrato a DB con libsodium, chiave `APP_ENCRYPTION_KEY`;
  - un codice già usato non vale una seconda volta (anti-replay);
  - la challenge vive 5 minuti, con al massimo 5 tentativi;
  - disattivare la 2FA o rigenerare i codici richiede un codice valido.
- **Senza sessione:** l'API è stateless (JWT), quindi la challenge è salvata a DB: funziona anche con più istanze.
- **Refresh:** il refresh token si ottiene solo dopo il secondo fattore, quindi i refresh successivi non lo richiedono di nuovo.

### Accesso con Google

L'app ottiene un ID token da Google Sign-In e il backend lo verifica: firma con le chiavi pubbliche di Google, emittente, audience tra `GOOGLE_CLIENT_IDS`, scadenza, email verificata.

Il collegamento all'account avviene così:
1. l'account è già collegato (stesso `sub` Google) → login;
2. esiste un account con la stessa email (verificata da Google) → collegamento automatico. Se quell'account non aveva mai verificato l'email, chi l'aveva registrato perde password, 2FA e sessioni: la casella è di chi ha Google;
3. nessun account → nuovo utente senza password e senza tenant. Al primo accesso l'app porta alla pagina **"Completa il tuo profilo"**; al salvataggio dell'anagrafica viene creato lo spazio "Famiglia (cognome)". Con la registrazione chiusa (vedi sotto) questo caso è rifiutato con 403.

Se l'account ha la 2FA attiva, anche con Google serve il secondo fattore.

Configurazione (Google Cloud Console → API e servizi → Credenziali):
1. schermata di consenso OAuth;
2. client **Applicazione web**: origini JavaScript autorizzate `http://localhost:5000` (sviluppo) e il dominio di produzione. Il suo ID va in `GOOGLE_WEB_CLIENT_ID` (frontend) e in `GOOGLE_CLIENT_IDS` (backend);
3. client **Android** (package + SHA-1 del keystore) e **iOS** (bundle id). L'ID iOS va in `GOOGLE_IOS_CLIENT_ID` (frontend); gli ID Android/iOS si aggiungono a `GOOGLE_CLIENT_IDS` solo se compaiono come `aud` del token.

Sul web Google impone il proprio pulsante; su Windows e Linux il plugin non è supportato e il pulsante non compare.

### Registrazione aperta o chiusa

`REGISTRAZIONE_APERTA` in `backend/.env` vale `1` (aperta): va bene in sviluppo. **Su un server raggiungibile da internet va messa a `0`**, altrimenti chiunque trovi l'indirizzo può crearsi un account e caricare file.

Con la registrazione chiusa:
- `POST /api/auth/register` risponde 403, anche per un'email già registrata, così non si scopre quali account esistono;
- l'accesso con Google entra solo negli account che esistono già: un'email sconosciuta riceve 403 e non viene creato nessun utente;
- l'app chiede lo stato a `GET /api/auth/registrazione` (pubblico, `{"aperta": false}`): nasconde "Registrati" e, se si apre la pagina di registrazione, spiega che si entra solo su invito;
- si entra **accettando un invito in famiglia** (crea l'account) o con un account **creato dal portale**: "Nuova famiglia" in "Famiglie ed enti", che manda il link di attivazione al proprietario.

Il primo account di un server nuovo, con la registrazione chiusa:
1. `docker compose exec php php bin/console app:user:create-superadmin tua@email.it`: l'amministratore della piattaforma, che non vede i documenti delle famiglie;
2. come superadmin, dal portale, "Nuova famiglia" con l'email di chi ne sarà proprietario. Deve essere un'email diversa da quella del superadmin, perché gli account del team del portale non possono essere membri di una famiglia;
3. il proprietario attiva l'account dal link e invita gli altri familiari.

Limite: chi riceve un invito d'emergenza o il collegamento di un ente e non ha ancora un account non può crearlo finché la registrazione è chiusa. Prima va invitato in una famiglia, oppure gli si crea l'account dal portale.

### Password dimenticata, verifica e cambio dell'email

I link arrivano per email: sono monouso e a DB ne resta solo l'hash SHA-256. Un nuovo link dello stesso tipo annulla i precedenti, e ogni utente può riceverne al massimo 3 per tipo all'ora.

| Link | Durata | Pagina dell'app |
|---|---|---|
| Password dimenticata | 1 ora | `/auth/reset-password?token=` |
| Verifica dell'email | 24 ore | `/auth/verifica-email?token=` |
| Conferma del nuovo indirizzo | 24 ore | `/auth/cambio-email?token=` |

- **Password dimenticata.** `POST /api/auth/password/dimenticata {email}` risponde sempre 202 con lo stesso messaggio, che l'account esista o no.
  - `POST …/anteprima {token}` restituisce l'email mascherata; `POST …/reimposta {token, password}` salva la nuova password.
  - Funziona anche per gli account creati con Google, che così impostano la prima password.
  - La 2FA resta: dopo il reset l'accesso chiede ancora il codice.
- **Verifica dell'email.**
  - Alla registrazione parte un link (`POST /api/auth/verifica-email {token}`); `POST /api/me/email/verifica` lo invia di nuovo.
  - Finché l'email non è verificata si usa l'archivio, ma non si possono invitare persone (in famiglia o dall'ente) né registrare un ente. L'app mostra un avviso in cima a ogni pagina.
  - Nascono già verificati gli account creati da un invito o con Google. Lo diventano anche quelli di chi accetta un collegamento con un ente o completa un reset: il link è arrivato a quella casella.
  - Gli account esistenti prima della migrazione risultano verificati.
- **Cambio password** (`POST /api/me/password {attuale?, nuova}`). La password attuale non serve se l'account non ne ha una. Questa sessione riceve token nuovi; le altre vengono chiuse.
- **Cambio email** (`POST /api/me/email {email, password}`). Il link va al nuovo indirizzo e un avviso al vecchio. Alla conferma si rientra con il nuovo indirizzo.
- **Sessioni chiuse.** Ogni cambio di credenziali elimina i refresh token e invalida i JWT emessi prima (`JwtCredenzialiListener` confronta `iat` con `credenzialiCambiateIl`). Arriva sempre un'email di avviso con il link "non sei stato tu? reimposta la password".
- I link scaduti da una settimana vengono eliminati dalla pulizia oraria.
## Sblocco con Face ID

Solo nell'app per telefono, e solo per il dispositivo su cui si attiva: Sicurezza e accesso → "Sblocco con Face ID" (`SbloccoBiometrico`).
- Attivandolo, l'app chiede subito una verifica del volto.
- Con lo sblocco attivo l'app parte bloccata, e si blocca di nuovo se torna in primo piano dopo 5 minuti in background: `AuthMiddleware` manda a `/auth/sblocco?next=…`, che chiede il volto (o l'impronta) e poi torna alla pagina. In alternativa: "Entra con la password" o "Esci".
- La sessione resta la stessa: il refresh token nel Portachiavi non cambia. Lo sblocco protegge l'apertura dell'app, quindi la verifica in due passaggi non si ripete.
- Non sostituisce il login: chi entra per la prima volta su un telefono usa password o Google, più la 2FA se attiva.

## Anagrafica

L'entità `Anagrafica` raccoglie i dati anagrafici di una persona o di un'azienda:
- **tipo:** privato o azienda;
- **dati personali:** nome, cognome, sesso, stato civile, titolo;
- **nascita:** data, stato e comune di nascita, cittadinanza;
- **codice fiscale**, con flag "manuale" per i casi di omocodia;
- **documento d'identità:** tipo, numero, rilascio e scadenza;
- **dati aziendali:** denominazione, partita IVA, SDI, capitale sociale, sito web;
- **indirizzi:** residenza/sede legale e domicilio/sede operativa;
- **contatti:** telefoni (E.164), email, PEC.

**Validazione** (`ValidAnagraficaValidator`, stessi messaggi di `AnagraficaCallbackValidator`):
- **privato:** campi obbligatori, solo maggiorenni, comune di nascita obbligatorio se nato in Italia;
- **codice fiscale:** formato, coerenza con i dati anagrafici (con `davidepastore/codice-fiscale` e codici Belfiore) e unicità;
- **azienda:** partita IVA con checksum e unicità;
- **telefono:** verificato con libphonenumber.

Titolo e tipo documento sono stringhe con valori chiusi.

**Comuni e stati** vengono dal dataset `devnix/belfiore-code` (`app:geo:import`). I comuni soppressi restano disponibili per il luogo di nascita.
