part of 'portale_screens.dart';

// ===========================================================================
// Utenti
// ===========================================================================

class PortaleUtentiScreen extends StatefulWidget {
  const PortaleUtentiScreen({super.key});

  @override
  State<PortaleUtentiScreen> createState() => _PortaleUtentiScreenState();
}

class _PortaleUtentiScreenState extends State<PortaleUtentiScreen> {
  final nome = TextEditingController();
  final email = TextEditingController();
  final tenant = TextEditingController();
  String? tipo;
  String? stato;
  String? team;
  String? dueFattori;
  int pagina = 1;
  int perPagina = 25;
  String ordina = 'email';
  String verso = 'asc';
  Map<String, dynamic>? dati;
  String? errore;
  bool loading = true;
  final Set<String> selezionati = {};
  String? azioneBulk;

  Map<String, String?> get _filtri => {
        'nome': nome.text,
        'email': email.text,
        'tenant': tenant.text,
        'tipo': tipo,
        'stato': stato,
        'team': team,
        'dueFattori': dueFattori,
        'ordina': ordina,
        'verso': verso,
      };
  final _ultima = UltimaRichiesta();

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    nome.dispose();
    email.dispose();
    tenant.dispose();
    super.dispose();
  }

  Future<void> _carica() async {
    setState(() {
      loading = true;
      errore = null;
    });
    try {
      final r = await _ultima.esegui(() => PortaleApi.elencoUtenti({..._filtri, 'pagina': '$pagina', 'perPagina': '$perPagina'}));
      if (r == null) return; // superata da una ricerca più recente
      dati = r;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  void _cerca() {
    pagina = 1;
    selezionati.clear();
    _carica();
  }

  void _reset() {
    for (final c in [nome, email, tenant]) {
      c.clear();
    }
    setState(() {
      tipo = null;
      stato = null;
      team = null;
      dueFattori = null;
    });
    _cerca();
  }

  void _ordina(String campo) {
    verso = ordina == campo && verso == 'asc' ? 'desc' : 'asc';
    ordina = campo;
    _cerca();
  }

  /// Esegue un'azione su una riga e ricarica, mostrando l'esito.
  Future<void> _esegui(Future<void> Function() fn, String ok) async {
    try {
      await fn();
      if (mounted) _snack(context, ok);
      _carica();
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  Future<void> _blocca(Map<String, dynamic> u, bool attivo) => _esegui(
        () => PortaleApi.statoUtente(u['id'] as String, attivo: attivo, motivo: attivo ? null : 'bloccato dal portale'),
        attivo ? 'Account sbloccato.' : 'Account bloccato: le sessioni aperte sono state chiuse.',
      );

  Future<void> _modifica(Map<String, dynamic> u) async {
    final fatto = await showDialog<bool>(context: context, builder: (_) => _ModificaUtenteDialog(utente: u));
    if (fatto == true && mounted) {
      _snack(context, 'Utente aggiornato.');
      _carica();
    }
  }

  Future<void> _elimina(Map<String, dynamic> u) async {
    final ok = await _conferma(
      context,
      'Eliminare ${u['nome']}?',
      'Esce da tutte le famiglie e gli enti (i documenti che ha caricato passano a un proprietario), nome ed email vengono cancellati '
          'e non potrà più accedere. Riceve un avviso all\'indirizzo attuale. Non si può annullare.',
      'Elimina l\'utente',
    );
    if (ok) await _esegui(() => PortaleApi.eliminaUtente(u['id'] as String), 'Utente eliminato.');
  }

  Future<void> _bulk() async {
    final azione = azioneBulk;
    if (azione == null || selezionati.isEmpty) return;
    final etichetta = switch (azione) { 'blocca' => 'Bloccare', 'sblocca' => 'Sbloccare', _ => 'Reinviare l\'attivazione a' };
    if (!await _conferma(context, '$etichetta ${selezionati.length} utenti?', 'Chi non si può modificare viene saltato e lo vedrai nell\'esito.', 'Esegui')) return;
    try {
      final esito = await PortaleApi.bulkUtenti(azione, selezionati.toList());
      final saltati = (esito['saltati'] as List).cast<Map>();
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text('Fatto per ${esito['fatti']} utenti'),
          content: SizedBox(
            width: 460,
            child: saltati.isEmpty
                ? const Text('Nessuno saltato.')
                : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('Saltati (${saltati.length}):'),
                    SizedBox(height: 8),
                    for (final s in saltati) Text('• ${s['email'] ?? s['id']}: ${s['motivo']}'),
                  ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi'))],
        ),
      );
      selezionati.clear();
      _carica();
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  Future<void> _aggiungi() async {
    final fatto = await showDialog<bool>(context: context, builder: (_) => const _AggiungiUtenteDialog());
    if (fatto == true && mounted) {
      _snack(context, 'Utente aggiunto: ha ricevuto un\'email.');
      _carica();
    }
  }

  Future<void> _apri(String id) async {
    var u = await PortaleApi.utente(id);
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) {
          final appartenenze = (u['appartenenze'] as List).cast<Map>();
          return AlertDialog(
            title: Text(u['nome'] as String),
            content: SizedBox(
              width: 520,
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                _riga('Email', u['email'] as String),
                _riga('Famiglie ed enti', appartenenze.isEmpty ? '-' : appartenenze.map((a) => '${a['tenant']} (${a['ruolo']}${a['stato'] == 'active' ? '' : ', ${a['stato']}'})').join(', ')),
                if ((u['referentePresso'] as List).isNotEmpty) _riga('Referente presso', (u['referentePresso'] as List).join(', ')),
                _riga('Verifica in due passaggi', u['dueFattori'] == true ? 'attiva dal ${_data(u['dueFattoriDal'])}' : (u['dueFattoriObbligatoria'] == true ? 'da riattivare (dopo un recupero)' : 'non attiva')),
                _riga('Google', u['google'] == true ? 'collegato' : 'non collegato'),
                _riga('Ultimo accesso', _dataOra(u['ultimoAccesso'])),
                _riga('Account', [u['attivo'] == true ? 'attivo' : 'BLOCCATO', if (u['inAttesaAttivazione'] == true) 'in attesa di attivazione'].join(' · ')),
                if (u['ruoloPiattaforma'] != null) _riga('Portale', u['ruoloPiattaforma'] as String),
                SizedBox(height: 8),
                Testo.bodySmall('Nessun accesso ai documenti: il portale vede solo questi dati. Se ha perso la 2FA, chiedigli di avviare il recupero dalla schermata del codice.', tenue: true),
              ]),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi'))],
          );
        },
      ),
    );
  }

  static Widget _riga(String label, String value) => Padding(
        padding: EdgeInsets.only(bottom: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 170, child: Testo.bodySmall(label, tenue: true)),
          Expanded(child: Testo.bodyMedium(value)),
        ]),
      );

  static String _statoLabel(String s) => switch (s) { 'in_attesa' => 'in attesa di attivazione', _ => s };

  @override
  Widget build(BuildContext context) {
    final d = dati;
    final totale = (d?['totale'] as int?) ?? 0;
    final righe = ((d?['righe'] as List?) ?? const []).cast<Map<String, dynamic>>();
    final pagine = (totale / perPagina).ceil();
    final mioId = SessionService.to.me?.id;
    bool gestibile(Map<String, dynamic> u) => u['stato'] != 'eliminato' && u['id'] != mioId && u['ruoloPiattaforma'] != 'superadmin';
    return _PaginaPortale(
      titolo: 'Utenti',
      soloSupporto: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        listaCarta(Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (_superadmin) FilledButton.icon(onPressed: _aggiungi, icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Aggiungi utente')),
            OutlinedButton.icon(onPressed: () => Get.toNamed('/admin/recuperi'), icon: const Icon(LucideIcons.key_round, size: 18), label: const Text('Recuperi d\'accesso')),
          ]),
          Testo.bodyMedium('Utenti trovati: $totale', fontWeight: FontWeight.w500),
        ])),
        SizedBox(height: 16),
        listaPannelloFiltri(Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 12, children: [
            listaFiltroTesto(nome, 'Nome', _cerca),
            listaFiltroTesto(email, 'Email', _cerca),
            listaFiltroTesto(tenant, 'Famiglia o ente', _cerca),
            listaFiltroScelta('Appartiene a', tipo, const [(null, 'Tutti'), ('privato', 'Famiglie'), ('azienda', 'Enti'), ('nessuno', 'Nessuna famiglia né ente')], (v) => setState(() => tipo = v)),
            listaFiltroScelta('Stato', stato, const [(null, 'Tutti (non eliminati)'), ('attivi', 'Attivi'), ('bloccati', 'Bloccati'), ('in_attesa', 'In attesa di attivazione'), ('eliminati', 'Eliminati')],
                (v) => setState(() => stato = v)),
            listaFiltroScelta('Team del portale', team, const [(null, 'Tutti'), ('si', 'Solo il team'), ('no', 'Escludi il team')], (v) => setState(() => team = v)),
            listaFiltroScelta('Verifica in due passaggi', dueFattori, const [(null, 'Tutti'), ('si', 'Attiva'), ('no', 'Non attiva')], (v) => setState(() => dueFattori = v)),
          ]),
          SizedBox(height: 12),
          _pulsantiFiltri(onReset: _reset, onCerca: _cerca),
        ])),
        SizedBox(height: 16),
        listaCarta(
          padding: EdgeInsets.symmetric(horizontal: 8, vertical: 8),
          Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Padding(
              padding: EdgeInsets.symmetric(horizontal: 8),
              child: Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
                Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  if (_superadmin) ...[
                    SizedBox(
                      width: 220,
                      child: DropdownButtonFormField<String?>(
                        initialValue: azioneBulk,
                        isExpanded: true,
                        decoration: _dec('Operazioni di gruppo'),
                        items: const [
                          DropdownMenuItem(value: 'blocca', child: Text('Blocca')),
                          DropdownMenuItem(value: 'sblocca', child: Text('Sblocca')),
                          DropdownMenuItem(value: 'attivazione', child: Text('Reinvia l\'attivazione')),
                        ],
                        onChanged: (v) => setState(() => azioneBulk = v),
                      ),
                    ),
                    FilledButton(onPressed: azioneBulk == null || selezionati.isEmpty ? null : _bulk, child: Text(selezionati.isEmpty ? 'Esegui' : 'Esegui (${selezionati.length})')),
                    OutlinedButton.icon(onPressed: () => _scaricaCsv(context, 'utenti', _filtri), icon: const Icon(LucideIcons.download, size: 16), label: const Text('Esporta CSV')),
                  ],
                  _selettorePerPagina(perPagina, (n) {
                    perPagina = n;
                    _cerca();
                  }),
                ]),
                ListaPaginazione(pagina: pagina, pagine: pagine, onPagina: (p) {
                  pagina = p;
                  _carica();
                }),
              ]),
            ),
            if (loading)
              const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
            else if (errore != null)
              erroreCard(errore!, _carica)
            else if (righe.isEmpty)
              Padding(padding: EdgeInsets.all(24), child: Testo.bodyMedium('Nessun utente con questi filtri.', tenue: true))
            else
              ListaTabella(
                ordina: ordina,
                verso: verso,
                onOrdina: _ordina,
                selezionati: _superadmin ? selezionati : null,
                selezionabile: gestibile,
                onCambiaSelezione: () => setState(() {}),
                colonne: const [
                  ListaColonna('Email', flex: 4, ordina: 'email'),
                  ListaColonna('Nome', flex: 3, ordina: 'nome'),
                  ListaColonna('Famiglie ed enti', flex: 5),
                  ListaColonna('Portale', flex: 2),
                  ListaColonna('2FA', flex: 1),
                  ListaColonna('Ultimo accesso', flex: 2, ordina: 'ultimoAccesso'),
                  ListaColonna('Stato', flex: 3),
                  ListaColonna('Azioni', flex: 3, destra: true),
                ],
                righe: righe,
                celle: (u) {
                  final st = u['stato'] as String;
                  final appartenenze = (u['appartenenze'] as List).cast<Map>();
                  final ok = gestibile(u);
                  return [
                    _link(u['email'] as String, () => _apri(u['id'] as String)),
                    Text(u['nome'] as String, overflow: TextOverflow.ellipsis),
                    appartenenze.isEmpty
                        ? Testo.bodySmall('—', tenue: true)
                        : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            for (final a in appartenenze.take(3))
                              Text('${a['tipo'] == 'privato' ? '🏠' : '🏫'} ${a['tenant']} · ${a['ruolo']}${a['stato'] == 'active' ? '' : ' (sospeso)'}', overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13)),
                            if (appartenenze.length > 3) Testo.bodySmall('e altri ${appartenenze.length - 3}', tenue: true),
                          ]),
                    Text(u['ruoloPiattaforma'] as String? ?? ''),
                    u['dueFattori'] == true ? Icon(LucideIcons.circle_check, size: 18, color: context.colori.success) : const SizedBox.shrink(),
                    Text(_data(u['ultimoAccesso'])),
                    listaChip(_statoLabel(st), _coloreStato(st)),
                    listaAzioni([
                      if (_superadmin) listaAzione(LucideIcons.pencil, 'Modifica', ok ? () => _modifica(u) : null),
                      if (st == 'bloccato')
                        listaAzione(LucideIcons.lock_open, 'Sblocca', ok ? () => _blocca(u, true) : null, colore: context.colori.success)
                      else
                        listaAzione(LucideIcons.lock, 'Blocca', ok ? () => _blocca(u, false) : null),
                      if (_superadmin && st == 'in_attesa')
                        listaAzione(LucideIcons.mail, 'Reinvia l\'attivazione', () => _esegui(() => PortaleApi.reinviaAttivazione(u['id'] as String), 'Link di attivazione inviato di nuovo.')),
                      if (_superadmin) listaAzione(LucideIcons.trash, 'Elimina', ok ? () => _elimina(u) : null, colore: context.colori.danger),
                    ]),
                  ];
                },
              ),
          ]),
        ),
      ]),
    );
  }
}
