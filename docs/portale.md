# Portale

Questa guida fa parte del manuale tecnico; la presentazione del progetto è nel [README](../README.md).

## Portale delle idee

Gli utenti con account propongono miglioramenti o nuove funzionalità dal menu utente ("Suggerisci un'idea", pagina `/idee`). Le idee sono **private**: le leggono solo l'autore e il team del portale. Non c'è una bacheca pubblica né si possono allegare screenshot.

**Invio** (`POST /api/idee`).
- Campi: tipo (`miglioramento` o `funzionalita`), area facoltativa, testo (20-2000 caratteri) e "potete contattarmi".
- Al massimo 5 idee al giorno per utente.
- Del contesto si registrano solo il tipo di spazio (famiglia, ente o azienda, e solo se l'utente ne fa parte), la piattaforma e la versione dell'app (`--dart-define=APP_VERSION=`). Mai il nome della famiglia o dell'ente.
- `GET /api/idee` restituisce le proprie idee, con lo stato e la risposta pubblica.

**Portale** (`/admin/idee`).

| Livello | Cosa può fare |
|---|---|
| VEDERE | legge le idee e filtra per stato, tipo, area, spazio e testo |
| SUPPORTO | cambia lo stato, scrive la risposta pubblica e la nota interna, unisce e separa i duplicati; vede nome ed email dell'autore solo se ha concesso il contatto |
| ADMIN | elimina ed esporta in CSV (`GET /api/admin/idee-export`, senza contatti, con la neutralizzazione della CSV injection) |

- Gli stati sono: nuova, letta, in valutazione, pianificata, realizzata, non prevista.
- I duplicati confluiscono nell'idea principale, che mostra "richiesta da N utenti". Gli autori dei duplicati vedono lo stato e la risposta della principale, non il suo testo.
- Le nuove idee compaiono anche nel "da fare" della salute.

**Email.** Non contengono mai né il testo dell'idea né la risposta.
- All'autore, e agli autori dei duplicati, quando cambia lo stato (tranne "letta") o la risposta: "La tua idea ha un aggiornamento".
- Ai superadmin, ogni giorno alle 08:00 (`RiepilogoIdeeMessage`): solo quante idee nuove sono arrivate, per tipo. Nessuna email se non ce ne sono.

**Privacy.** Se l'account viene eliminato, le sue idee restano ma diventano anonime.

## Portale della piattaforma

**Ruoli** (`app_user.platform_role`; il superadmin resta il flag `super_admin`):
- **superadmin**: tutto il portale, approva gli enti, gestisce il team (`PUT /api/admin/utenti/{id}/ruolo-piattaforma`).
- **supporto**: famiglie ed enti (solo metadati), utenti (ricerca, blocco/sblocco), recuperi d'accesso, nuovo proprietario, preparazione della verifica degli enti (richiesta di integrazioni).
- **sola_lettura**: salute del sistema e registro globale.
- Nessun ruolo apre i documenti: il portale non ha endpoint sui contenuti.
- I livelli li applica `App\Portale\PlatformAccess`; ogni accesso negato va nell'audit.

**Salute e da fare** (`GET /api/admin/salute`).
- Coda del worker (in attesa e falliti), OCR fallite nelle ultime 24 ore, import in errore, modello AI (Ollama raggiungibile e modello presente).
- Master key: versioni, impronta e ultimo backup. La data dell'ultimo `app:crypto:key export` ora va nell'audit.
- Da fare: backup mancante o più vecchio di 90 giorni, enti da verificare, recuperi da verificare, famiglie senza proprietario, cancellazioni programmate.
- Numeri aggregati.

**Recupero della 2FA** (persa con telefono e codici).
1. `POST /api/auth/recupero` (pubblico, sempre 202: non rivela se l'account esiste).
2. Conferma dal link via email (24 ore).
3. Il supporto verifica l'identità (videochiamata con documento; si annota solo tipo e ultime cifre).
4. 72 ore di attesa con avviso all'utente e ai proprietari delle sue famiglie. Chiunque può annullare dal link (`/procedure/annulla`), con allerta nell'audit.
5. Il job orario disattiva la 2FA, cancella i codici di recupero e tutti i refresh token, e rende la 2FA obbligatoria al prossimo accesso: l'app porta a "Sicurezza".

**Famiglie senza proprietario**: famiglie senza proprietari attivi con account attivo.
- Il supporto verifica l'identità di un membro esistente (`POST /api/admin/tenants/{id}/nuovo-proprietario`).
- Seguono 72 ore con avviso a tutti i membri, annullabili.
- Alla fine il membro diventa proprietario.

**Gestione manuale** (solo superadmin, `App\Portale\GestioneManuale`), per chi chiede di fare le cose al posto suo. Dal portale, in "Famiglie ed enti":
- **Nuova famiglia / Nuovo ente**: il tenant nasce sempre con il proprietario (nuovo o esistente) e, se servono, altri membri o operatori. Tutto viene validato prima di scrivere, in un'unica transazione.
- **L'ente nasce già verificato**, ma con una nota obbligatoria su come è stato verificato fuori dalla piattaforma (contratto, telefonata…). La nota resta nel profilo e nel registro.
- **Chi non ha un account** lo riceve senza password e con l'email "Attiva il tuo account": link di 7 giorni (`TokenAccount::ATTIVAZIONE`, pagina `/auth/attiva`) che imposta la password e verifica l'email. Dal portale si può reinviare. Chi ha già un account riceve un avviso.
- **Membri di qualunque tenant**: aggiunta, cambio di ruolo, sospensione e rimozione, con le stesse regole di `MembershipManager` (mai senza un proprietario attivo).
- **Paletti**:
  - gli account del team del portale non possono diventare membri da qui, altrimenti basterebbe un clic per leggere i documenti di una famiglia;
  - ogni modifica va nel registro (`portale.*`) e i proprietari del tenant ricevono un avviso via email.
- **Famiglia ↔ ente**: il portale inserisce iscritto e referente nell'ente e manda l'invito email al collegamento. Il collegamento nasce solo quando la famiglia accetta.
- **Verifica in due passaggi**: non è obbligatoria per gli operatori degli enti, ma l'app la ricorda a ogni accesso a chi opera in un ente senza averla attiva.

**Liste "Famiglie ed enti" e "Utenti"**. Filtri, ordinamento e paginazione si applicano lato server (`GET /api/admin/tenants/elenco`, `GET /api/admin/utenti/elenco`). Il supporto le consulta; il superadmin modifica ed elimina.
- **Modifica utente**: nome, cognome ed email. L'email cambia subito: le sessioni si chiudono, il vecchio indirizzo riceve un avviso e il nuovo va confermato, oppure riceve l'attivazione se l'utente non ha ancora una password.
- **Elimina utente**: esce da famiglie ed enti (i suoi dati passano a un proprietario). Poi l'account viene anonimizzato (`User::anonimizza`, email `<id>@utenti-eliminati.invalid`, così l'indirizzo vero torna libero) e disattivato. Non si può eliminare l'unico proprietario di qualcosa.
- **Modifica tenant**: il nome della famiglia, oppure i dati dell'ente. Se l'ente era verificato, resta verificato.
- **Elimina tenant**: l'eliminazione è programmata, come per le famiglie (`TenantDeletion::richiediDalPortale`).
  - Vale anche per gli enti e richiede un motivo, che resta nel registro.
  - Il tenant resta in sola lettura per 30 giorni e si può annullare dalla lista.
  - Le automazioni dell'ente si fermano.
- **Operazioni di gruppo sugli utenti** (`POST /api/admin/utenti/bulk`): blocca, sblocca, reinvia l'attivazione. Chi non si può modificare viene saltato, con il motivo.
- **Esportazione CSV** dei risultati filtrati (`…/export`, solo superadmin, registrata nell'audit). Solo metadati; le formule vengono neutralizzate.
