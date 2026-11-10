part of 'portale_screens.dart';

// Lista di famiglie ed enti, con il dialogo di modifica.

class PortaleTenantScreen extends StatefulWidget {
  const PortaleTenantScreen({super.key});

  @override
  State<PortaleTenantScreen> createState() => _PortaleTenantScreenState();
}

class _PortaleTenantScreenState extends State<PortaleTenantScreen> {
  final nome = TextEditingController();
  final membro = TextEditingController();
  String? tipo;
  String? stato = Get.parameters['stato'];
  int pagina = 1;
  int perPagina = 25;
  String ordina = 'nome';
  String verso = 'asc';
  Map<String, dynamic>? dati;
  String? errore;
  bool loading = true;
  final _ultima = UltimaRichiesta();

  static String _statoLabel(String s) => _statoTenantLabel(s);

  Map<String, String?> get _filtri => {'nome': nome.text, 'membro': membro.text, 'tipo': tipo, 'stato': stato, 'ordina': ordina, 'verso': verso};

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    nome.dispose();
    membro.dispose();
    super.dispose();
  }

  Future<void> _carica() async {
    setState(() {
      loading = true;
      errore = null;
    });
    try {
      final r = await _ultima.esegui(() => PortaleApi.elencoTenant({..._filtri, 'pagina': '$pagina', 'perPagina': '$perPagina'}));
      if (r == null) return; // superata da una ricerca più recente
      dati = r;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  void _cerca() {
    pagina = 1;
    _carica();
  }

  void _reset() {
    nome.clear();
    membro.clear();
    setState(() {
      tipo = null;
      stato = null;
    });
    _cerca();
  }

  void _ordina(String campo) {
    verso = ordina == campo && verso == 'asc' ? 'desc' : 'asc';
    ordina = campo;
    _cerca();
  }

  Future<void> _apri(String tenantId) async {
    final esito = await showDialog<String>(context: context, builder: (_) => _DettaglioTenantDialog(tenantId: tenantId));
    if (esito == null || !mounted) return;
    if (esito.isNotEmpty) _snack(context, esito);
    _carica();
  }

  Future<void> _nuovo({required bool ente}) async {
    final id = await showDialog<String>(context: context, barrierDismissible: false, builder: (_) => _NuovoTenantDialog(ente: ente));
    if (id == null || !mounted) return;
    _snack(context, '${ente ? 'Ente creato e verificato' : 'Famiglia creata'}: le persone indicate hanno ricevuto un\'email.');
    _carica();
    await _apri(id);
  }

  Future<void> _modifica(Map<String, dynamic> r) async {
    final fatto = await showDialog<bool>(context: context, builder: (_) => _ModificaTenantDialog(tenantId: r['id'] as String));
    if (fatto == true && mounted) {
      _snack(context, 'Dati aggiornati. I proprietari hanno ricevuto un avviso.');
      _carica();
    }
  }

  Future<void> _elimina(Map<String, dynamic> r) async {
    final motivo = TextEditingController();
    String? err;
    final fatto = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text('Eliminare "${r['nome']}"?'),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${r['tipo'] == 'privato' ? 'La famiglia' : 'L\'ente'} diventa di sola lettura per 30 giorni e tutti i membri ricevono un avviso. '
                  'Fino ad allora l\'eliminazione si annulla da questa lista; poi documenti e dati vengono cancellati per sempre.'),
              SizedBox(height: 12),
              TextField(controller: motivo, maxLines: 2, decoration: _dec('Motivo (resta nel registro)', hint: 'Es. richiesta della famiglia per telefono')),
              _errore(err),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore),
              onPressed: () async {
                try {
                  await PortaleApi.eliminaTenant(r['id'] as String, motivo.text);
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Programma l\'eliminazione'),
            ),
          ],
        ),
      ),
    );
    motivo.dispose();
    if (fatto == true && mounted) {
      _snack(context, 'Eliminazione programmata tra 30 giorni.');
      _carica();
    }
  }

  Future<void> _annullaEliminazione(Map<String, dynamic> r) async {
    try {
      await PortaleApi.annullaEliminazione(r['id'] as String);
      if (mounted) _snack(context, 'Eliminazione annullata: i membri ricevono un avviso.');
      _carica();
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = dati;
    final totale = (d?['totale'] as int?) ?? 0;
    final righe = ((d?['righe'] as List?) ?? const []).cast<Map<String, dynamic>>();
    final pagine = (totale / perPagina).ceil();
    return _PaginaPortale(
      titolo: 'Famiglie ed enti',
      soloSupporto: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        listaCarta(Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (_superadmin) ...[
              FilledButton.icon(onPressed: () => _nuovo(ente: false), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuova famiglia')),
              FilledButton.icon(onPressed: () => _nuovo(ente: true), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuovo ente')),
            ],
            OutlinedButton.icon(onPressed: () => Get.toNamed('/admin/enti'), icon: const Icon(LucideIcons.list_checks, size: 18), label: const Text('Enti da verificare')),
          ]),
          Testo.bodyMedium('Trovati: $totale', fontWeight: FontWeight.w500),
        ])),
        SizedBox(height: 16),
        listaCarta(Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 12, children: [
            listaFiltroTesto(nome, 'Nome', _cerca),
            listaFiltroTesto(membro, 'Membro (email o nome)', _cerca),
            listaFiltroScelta('Tipo', tipo, const [(null, 'Tutti'), ('privato', 'Famiglie'), ('azienda', 'Enti')], (v) => setState(() => tipo = v)),
            listaFiltroScelta('Stato', stato, const [
              (null, 'Tutti'),
              ('attivo', 'Attivi'),
              ('senza_proprietario', 'Senza proprietario'),
              ('in_eliminazione', 'In eliminazione'),
              ('ente_verificato', 'Ente: verificato'),
              ('ente_in_verifica', 'Ente: in verifica'),
              ('ente_integrazioni', 'Ente: integrazioni'),
              ('ente_email_da_confermare', 'Ente: email da confermare'),
              ('ente_respinto', 'Ente: respinto'),
            ], (v) => setState(() => stato = v)),
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
                Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  if (_superadmin) OutlinedButton.icon(onPressed: () => _scaricaCsv(context, 'tenants', _filtri), icon: const Icon(LucideIcons.download, size: 16), label: const Text('Esporta CSV')),
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
              Padding(padding: EdgeInsets.all(24), child: Testo.bodyMedium('Nessun risultato con questi filtri.', tenue: true))
            else
              ListaTabella(
                ordina: ordina,
                verso: verso,
                onOrdina: _ordina,
                colonne: const [
                  ListaColonna('Nome', flex: 4, ordina: 'nome'),
                  ListaColonna('Tipo', flex: 2),
                  ListaColonna('Stato', flex: 3),
                  ListaColonna('Membri', flex: 2),
                  ListaColonna('Proprietari', flex: 4),
                  ListaColonna('Spazio', flex: 2),
                  ListaColonna('Creato il', flex: 2, ordina: 'creato'),
                  ListaColonna('Azioni', flex: 3, destra: true),
                ],
                righe: righe,
                celle: (r) {
                  final st = r['stato'] as String;
                  return [
                    _link(r['nome'] as String, () => _apri(r['id'] as String)),
                    Row(children: [Icon(r['tipo'] == 'privato' ? LucideIcons.house : LucideIcons.school, size: 16), SizedBox(width: 6), Text(r['tipo'] == 'privato' ? 'Famiglia' : 'Ente')]),
                    listaChip(_statoLabel(st) + (st == 'in_eliminazione' ? ' il ${_data(r['eliminazioneProgrammataIl'])}' : ''), _coloreStato(st)),
                    Text('${r['membri']}'),
                    Text((r['proprietari'] as List).isEmpty ? '—' : (r['proprietari'] as List).join(', '), overflow: TextOverflow.ellipsis, maxLines: 2),
                    Text(formatBytes((r['spazioBytes'] as num).toInt())),
                    Text(_data(r['createdAt'])),
                    listaAzioni([
                      listaAzione(LucideIcons.users, 'Membri e dettagli', () => _apri(r['id'] as String)),
                      if (_superadmin) ...[
                        listaAzione(LucideIcons.pencil, 'Modifica', st == 'in_eliminazione' ? null : () => _modifica(r)),
                        if (st == 'in_eliminazione')
                          listaAzione(LucideIcons.undo_2, 'Annulla l\'eliminazione', () => _annullaEliminazione(r), colore: context.colori.success)
                        else
                          listaAzione(LucideIcons.trash, 'Elimina', () => _elimina(r), colore: context.colori.danger),
                      ],
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

/// Modifica: il nome per le famiglie, i dati identificativi per gli enti.
class _ModificaTenantDialog extends StatefulWidget {
  final String tenantId;

  const _ModificaTenantDialog({required this.tenantId});

  @override
  State<_ModificaTenantDialog> createState() => _ModificaTenantDialogState();
}

class _ModificaTenantDialogState extends State<_ModificaTenantDialog> {
  final nome = TextEditingController();
  final codiceFiscale = TextEditingController();
  final codiceMeccanografico = TextEditingController();
  final numeroRunts = TextEditingController();
  final emailIstituzionale = TextEditingController();
  final sedeLegale = TextEditingController();
  Map<String, dynamic>? ente;
  bool caricato = false;
  bool busy = false;
  String? errore;

  @override
  void initState() {
    super.initState();
    PortaleApi.tenant(widget.tenantId).then((d) {
      nome.text = d['nome'] as String;
      ente = d['ente'] as Map<String, dynamic>?;
      final e = ente;
      if (e != null) {
        codiceFiscale.text = e['codiceFiscale'] as String? ?? '';
        codiceMeccanografico.text = e['codiceMeccanografico'] as String? ?? '';
        numeroRunts.text = e['numeroRunts'] as String? ?? '';
        emailIstituzionale.text = e['emailIstituzionale'] as String? ?? '';
        sedeLegale.text = e['sedeLegale'] as String? ?? '';
      }
    }, onError: (Object e) => errore = e is ApiException ? e.message : '$e').whenComplete(() {
      if (mounted) setState(() => caricato = true);
    });
  }

  @override
  void dispose() {
    for (final c in [nome, codiceFiscale, codiceMeccanografico, numeroRunts, emailIstituzionale, sedeLegale]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    final scuola = ente?['tipo'] == 'scuola';
    try {
      await PortaleApi.modificaTenant(
        widget.tenantId,
        ente == null
            ? {'nome': nome.text.trim()}
            : {
                'denominazione': nome.text.trim(),
                'codiceFiscale': codiceFiscale.text.trim(),
                if (scuola) 'codiceMeccanografico': codiceMeccanografico.text.trim(),
                if (!scuola) 'numeroRunts': numeroRunts.text.trim(),
                if (!scuola) 'emailIstituzionale': emailIstituzionale.text.trim(),
                'sedeLegale': sedeLegale.text.trim(),
              },
      );
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final e = ente;
    final scuola = e?['tipo'] == 'scuola';
    return AlertDialog(
      title: Text(e == null ? 'Modifica famiglia' : 'Modifica ente'),
      content: SizedBox(
        width: 560,
        child: !caricato
            ? const SizedBox(height: 80, child: Center(child: CircularProgressIndicator()))
            : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                Wrap(spacing: 8, runSpacing: 8, children: [
                  _campo(nome, e == null ? 'Nome della famiglia' : 'Denominazione', width: 528),
                  if (e != null) ...[
                    _campo(codiceFiscale, 'Codice fiscale', width: 260),
                    if (scuola) _campo(codiceMeccanografico, 'Codice meccanografico', width: 260),
                    if (!scuola) ...[
                      _campo(numeroRunts, 'Numero RUNTS', width: 260),
                      _campo(emailIstituzionale, 'Email o PEC del RUNTS', width: 528),
                    ],
                    _campo(sedeLegale, 'Sede legale', width: 528),
                  ],
                ]),
                if (e != null) ...[
                  SizedBox(height: 8),
                  Testo.bodySmall('Tipo: ${scuola ? 'scuola' : 'associazione'} (non si cambia). Se l\'ente è verificato resta verificato: la modifica è registrata. '
                      '${scuola ? 'L\'email istituzionale si ricava dal codice meccanografico.' : ''}', tenue: true),
                ],
                _errore(errore),
              ]),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy || !caricato ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}
