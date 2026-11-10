/// Contenuti del centro Aiuto: domande frequenti e guide passo-passo.
/// Testi brevi e concreti; ogni guida porta alla pagina dove si fa la cosa.
library;

enum PubblicoAiuto { famiglia, ente, tutti }

class Faq {
  final String domanda;
  final String risposta;
  final String area;
  final PubblicoAiuto pubblico;

  const Faq(this.area, this.domanda, this.risposta, {this.pubblico = PubblicoAiuto.tutti});

  bool corrisponde(String q) {
    final t = q.trim().toLowerCase();
    return t.isEmpty || domanda.toLowerCase().contains(t) || risposta.toLowerCase().contains(t) || area.toLowerCase().contains(t);
  }
}

class PassoGuida {
  final String titolo;
  final String testo;

  /// Pagina dell'app dove si fa questo passo (facoltativa).
  final String? route;
  final Object? arguments;

  const PassoGuida(this.titolo, this.testo, {this.route, this.arguments});
}

class Guida {
  final String id;
  final String titolo;
  final String sommario;
  final PubblicoAiuto pubblico;
  final List<PassoGuida> passi;

  const Guida({required this.id, required this.titolo, required this.sommario, required this.pubblico, required this.passi});
}

const List<Faq> faq = [
  // ---- Documenti -----------------------------------------------------------
  Faq('Documenti', 'Come carico un documento?',
      'Da "Documenti" usa "Carica" (anche più file insieme, o trascinandoli sulla pagina dal computer) oppure "Scansiona" dal telefono: inquadri le pagine, l\'app le raddrizza e crea un PDF.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Documenti', 'Cosa vuol dire "cifrato sul server"?',
      'I documenti delle categorie sensibili (salute, identità, finanze...) sono cifrati prima di essere salvati: sul server non sono leggibili, si decifrano solo al momento dell\'apertura da parte di chi ha il permesso. Ogni apertura viene registrata.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Documenti', 'Perché alcuni documenti hanno una scadenza proposta?',
      'Dopo la lettura del testo, l\'app cerca date come "scadenza" o "valido fino al". Te la propone: diventa una scadenza solo se la confermi.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Documenti', 'Posso importare i documenti da Google Drive?',
      'Sì: in Impostazioni della famiglia → Google Drive colleghi il tuo account e scegli le cartelle. L\'importazione è in sola lettura: su Drive non viene mai modificato nulla.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Documenti', 'Come mando un documento al medico o al commercialista?',
      'Apri il documento e scegli "Condividi": ottieni un link e un codice. Manda il link per email e il codice a voce o per SMS. Decidi tu quanto dura e quanti download sono permessi, e puoi revocarlo quando vuoi.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Documenti', 'Posso vedere i documenti senza connessione?',
      'Sull\'app per telefono, apri il documento e attiva "Disponibile offline". Lo trovi in "Documenti offline", anche dalla pagina di accesso: si apre con impronta, volto o PIN del telefono.',
      pubblico: PubblicoAiuto.famiglia),
  // ---- Famiglia --------------------------------------------------------------
  Faq('Famiglia', 'Che differenza c\'è tra "persone" e "membri"?',
      'Le persone sono chi riguarda i documenti (anche i bambini, che non hanno un account). I membri sono chi accede all\'app: proprietari, membri e lettori.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Famiglia', 'Cosa può fare un "lettore"?',
      'Vede documenti e scadenze ma non modifica nulla: va bene, ad esempio, per un nonno. I membri modificano ciò che caricano; i proprietari gestiscono tutto.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Famiglia', 'Cos\'è il contatto d\'emergenza?',
      'Una persona di fiducia, con un account, che può chiedere di consultare i documenti se nessuno di voi può farlo. Ricevete un avviso e potete opporvi; senza opposizione, dopo i giorni di attesa scelti, ottiene l\'accesso in sola lettura.',
      pubblico: PubblicoAiuto.famiglia),
  Faq('Famiglia', 'Come rispondo a una richiesta della scuola?',
      'In "Richieste" trovi cosa ti chiede l\'ente. Puoi firmare con il codice ricevuto via email (se l\'ente lo prevede), oppure stampare, firmare e caricare la scansione. Puoi anche prendere visione o rifiutare.',
      pubblico: PubblicoAiuto.famiglia),
  // ---- Account e sicurezza ----------------------------------------------------
  Faq('Sicurezza', 'Ho dimenticato la password', 'Dalla pagina di accesso scegli "Password dimenticata?": ricevi un link valido un\'ora. Se hai la verifica in due passaggi, servirà anche il codice.'),
  Faq('Sicurezza', 'Ho perso il telefono con l\'app di autenticazione',
      'Usa uno dei codici di recupero. Se non li hai, dalla schermata del codice avvia il recupero dell\'accesso: il supporto verifica la tua identità e, dopo 72 ore di attesa con avviso, disattiva la verifica in due passaggi.'),
  Faq('Sicurezza', 'Perché devo confermare la mia email?',
      'Per invitare persone o collegarti a un ente serve essere sicuri che l\'indirizzo sia tuo. Il link arriva subito dopo la registrazione; puoi farlo rinviare dall\'avviso in cima all\'app.'),
  Faq('Sicurezza', 'Il supporto di CartellaViva può vedere i miei documenti?',
      'No. Il portale della piattaforma vede solo dati tecnici e conteggi. Nessuno del supporto ti chiederà mai password o codici.'),
  // ---- Privacy ------------------------------------------------------------------
  Faq('Privacy', 'Cosa arriva per email?', 'Solo avvisi senza contenuto ("hai una nuova richiesta", "hai 2 scadenze in arrivo"): titoli e documenti si leggono solo nell\'app.'),
  Faq('Privacy', 'Come scarico o elimino tutti i dati della famiglia?',
      'In Impostazioni della famiglia → Dati: puoi esportare tutto in un file ZIP o chiedere l\'eliminazione, che diventa definitiva dopo 30 giorni (nel frattempo puoi annullarla).',
      pubblico: PubblicoAiuto.famiglia),
  // ---- Enti ------------------------------------------------------------------------
  Faq('Ente', 'Chi vede le risposte delle famiglie?',
      'La segreteria vede tutto l\'ente; gli operatori solo i gruppi (classi, corsi) a cui sono assegnati. I documenti ricevuti sono cifrati.',
      pubblico: PubblicoAiuto.ente),
  Faq('Ente', 'Una famiglia non ha l\'app: come la raggiungo?',
      'Se il referente ha un\'email, riceve un link sicuro: risponde con un codice inviato alla sua casella, senza creare un account. Se non ha email, l\'anteprima della richiesta lo segnala come "non raggiunto".',
      pubblico: PubblicoAiuto.ente),
  Faq('Ente', 'La firma con codice ha valore legale?',
      'È una firma elettronica semplice: valida, ma liberamente valutabile in giudizio. Va bene per autorizzazioni, liberatorie e circolari. Dove la legge chiede la firma autografa usa la modalità su carta.',
      pubblico: PubblicoAiuto.ente),
  Faq('Ente', 'Cosa succede con il passaggio d\'anno?',
      'In "Gruppi" avvii il passaggio all\'anno successivo: per ogni gruppo scegli il nuovo nome (la 3ªB diventa 4ªB) oppure lo fai uscire (es. le quinte). I gruppi passano con iscritti e operatori; l\'anno precedente resta archiviato. Bocciature e trasferimenti si sistemano poi a mano.',
      pubblico: PubblicoAiuto.ente),
];

const List<Guida> guide = [
  // ---- Famiglia ---------------------------------------------------------------------
  Guida(
    id: 'primi-passi-famiglia',
    titolo: 'Primi passi in famiglia',
    sommario: 'In 10 minuti: persone, primi documenti, scadenze e sicurezza.',
    pubblico: PubblicoAiuto.famiglia,
    passi: [
      PassoGuida('Completa il tuo profilo', 'Nome, data di nascita e codice fiscale servono a riconoscerti nei documenti e negli enti.', route: '/account/profile'),
      PassoGuida('Aggiungi le persone', 'Figli, partner, genitori: anche chi non userà l\'app. Così ogni documento si collega a chi riguarda.', route: '/famiglia/impostazioni', arguments: 'persone'),
      PassoGuida('Carica i documenti importanti', 'Carta d\'identità, tessera sanitaria, patente, polizze. Con il telefono puoi scansionarli.', route: '/documenti/nuovo'),
      PassoGuida('Aggiungi le scadenze', 'Bollo, revisione, rinnovi: ti avvisiamo per email prima della data. Molte le troviamo da sole nei documenti.', route: '/scadenze'),
      PassoGuida('Invita chi condivide la famiglia', 'Partner o figli maggiorenni come membri; i nonni come lettori.', route: '/tenant/members'),
      PassoGuida('Proteggi l\'account', 'Attiva la verifica in due passaggi: è la cosa più utile per la sicurezza dei documenti.', route: '/account/security'),
    ],
  ),
  Guida(
    id: 'emergenza',
    titolo: 'Preparare un accesso d\'emergenza',
    sommario: 'Chi può consultare i documenti se voi non potete.',
    pubblico: PubblicoAiuto.famiglia,
    passi: [
      PassoGuida('Scegli una persona di fiducia', 'Serve che abbia (o crei) un account CartellaViva con la sua email.'),
      PassoGuida('Indicala come contatto', 'Impostazioni della famiglia → Emergenza: scegli i giorni di attesa e quali categorie potrà vedere.', route: '/famiglia/impostazioni', arguments: 'emergenza'),
      PassoGuida('Lei accetta l\'invito', 'Dal link nell\'email. Finché non chiede l\'accesso non vede nulla.'),
      PassoGuida('Se lo chiede', 'Ricevete email e notifica: potete opporvi. Senza opposizione, a fine attesa vede i documenti in sola lettura. Potete revocarla in ogni momento.'),
    ],
  ),
  // ---- Ente (segreterie) ---------------------------------------------------------------
  Guida(
    id: 'ente-primi-passi',
    titolo: 'Avviare l\'ente su CartellaViva',
    sommario: 'Dalla verifica alla prima richiesta, in ordine.',
    pubblico: PubblicoAiuto.ente,
    passi: [
      PassoGuida('Completa la verifica', 'Codice fiscale dell\'ente e indirizzo email istituzionale: il team di CartellaViva controlla e abilita l\'ente. Prima della verifica non si inviano inviti.', route: '/ente/profilo'),
      PassoGuida('Crea l\'anno e i gruppi', 'Classi, sezioni o corsi. Le sedi servono solo se ne avete più di una.', route: '/ente/gruppi'),
      PassoGuida('Inserisci gli iscritti', 'Uno alla volta o con l\'import da file CSV (c\'è una guida dedicata).', route: '/ente/iscritti'),
      PassoGuida('Invita i referenti', 'Genitori o tutori ricevono un\'email per collegarsi alla propria famiglia su CartellaViva.', route: '/ente/iscritti'),
      PassoGuida('Assegna gli operatori', 'Insegnanti o allenatori: vedono solo i loro gruppi.', route: '/tenant/members'),
      PassoGuida('Invia la prima richiesta', 'Una firma, un documento da consegnare o una presa visione.', route: '/ente/richieste/nuova'),
    ],
  ),
  Guida(
    id: 'ente-import-csv',
    titolo: 'Importare gli iscritti da un file',
    sommario: 'Da Excel o dal gestionale della scuola, senza ricopiare a mano.',
    pubblico: PubblicoAiuto.ente,
    passi: [
      PassoGuida('Prepara il file',
          'Una riga per iscritto, con l\'intestazione: nome, cognome, data_nascita (facoltativa), gruppo, poi per ogni referente ref1_nome, ref1_cognome, ref1_email, ref1_relazione (fino a ref4). Salvalo come CSV, separato da punto e virgola o virgola.'),
      PassoGuida('Controlla i gruppi', 'Il gruppo indicato deve già esistere nell\'anno in corso, con lo stesso nome.', route: '/ente/gruppi'),
      PassoGuida('Carica il file', 'In Iscritti scegli l\'import da file: l\'app mostra un\'anteprima riga per riga con gli eventuali errori. Le righe con errori non vengono importate.', route: '/ente/iscritti'),
      PassoGuida('Conferma', 'Un iscritto già presente (stesso nome, cognome e data di nascita) non viene duplicato: viene solo aggiunto al gruppo. Gli inviti ai referenti NON partono da soli: li invii tu quando sei pronto.'),
    ],
  ),
  Guida(
    id: 'ente-richiesta',
    titolo: 'Inviare una richiesta alle famiglie',
    sommario: 'Autorizzazioni, consensi, certificati: chi deve rispondere e come seguirla.',
    pubblico: PubblicoAiuto.ente,
    passi: [
      PassoGuida('Scegli il tipo', 'Firma (un PDF da firmare), invio di un documento (es. certificato medico) o presa visione (es. circolare).', route: '/ente/richieste/nuova'),
      PassoGuida('Scegli i destinatari', 'Un gruppo intero o alcuni iscritti. L\'anteprima ti dice quante famiglie sono raggiunte nell\'app, quante con il link via email e chi non è raggiungibile.'),
      PassoGuida('Firme: su carta o con codice', 'Con il codice le famiglie firmano dal telefono e il PDF firmato ha una pagina di attestazione verificabile. Resta sempre possibile firmare su carta.'),
      PassoGuida('Segui le risposte', 'Nel dettaglio della richiesta vedi chi ha risposto; puoi sollecitare chi manca, verificare le risposte o chiedere di correggerle, e scaricare tutto in un ZIP.', route: '/ente/richieste'),
    ],
  ),
  Guida(
    id: 'ente-modelli',
    titolo: 'Modelli, rinnovi e richieste ricorrenti',
    sommario: 'Per i documenti che tornano ogni anno, come il certificato medico sportivo.',
    pubblico: PubblicoAiuto.ente,
    passi: [
      PassoGuida('Crea un modello', 'Titolo, istruzioni, eventuale PDF e modalità di firma: lo riusi ogni volta.', route: '/ente/modelli'),
      PassoGuida('Chiedi la validità', 'Chi consegna indica fino a quando vale il documento; puoi far ripartire la richiesta in automatico qualche giorno prima della scadenza.'),
      PassoGuida('Imposta la ricorrenza', 'Ad esempio ogni 15 settembre a tutto l\'ente (solo la segreteria).'),
      PassoGuida('Conservazione', 'Indica dopo quanti mesi eliminare le copie ricevute: meno dati conservati, meno rischi.'),
    ],
  ),
];

/// Guide per una pagina dell'app, per il link "Come funziona?".
Guida? guidaPer(String id) {
  for (final g in guide) {
    if (g.id == id) return g;
  }
  return null;
}
