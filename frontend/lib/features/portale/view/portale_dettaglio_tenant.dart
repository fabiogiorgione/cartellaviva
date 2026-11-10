part of 'portale_screens.dart';

// Dettaglio di una famiglia o di un ente, con le azioni di gestione manuale.

// ---- Dettaglio di una famiglia o di un ente ----------------------------------

class _DettaglioTenantDialog extends StatefulWidget {
  final String tenantId;

  const _DettaglioTenantDialog({required this.tenantId});

  @override
  State<_DettaglioTenantDialog> createState() => _DettaglioTenantDialogState();
}

class _DettaglioTenantDialogState extends State<_DettaglioTenantDialog> {
  Map<String, dynamic>? dettaglio;
  List<Map<String, dynamic>> ruoli = [];
  String? errore;
  String? info;
  bool modificato = false;

  // Nuovo proprietario (famiglie senza proprietario).
  String? membershipId;
  final documento = TextEditingController();
  final come = TextEditingController(text: 'videochiamata');
  final nota = TextEditingController();

  String get id => widget.tenantId;
  bool get isEnte => dettaglio?['tipo'] == 'azienda';

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    for (final c in [documento, come, nota]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _carica() async {
    try {
      final d = await PortaleApi.tenant(id);
      final r = _superadmin ? await PortaleApi.ruoliTenant(id) : <Map<String, dynamic>>[];
      if (mounted) {
        setState(() {
          dettaglio = d;
          ruoli = r;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  /// Esegue un'azione, mostra l'esito e ricarica.
  Future<void> _azione(Future<void> Function() fn, String ok) async {
    setState(() {
      errore = null;
      info = null;
    });
    try {
      await fn();
      modificato = true;
      info = ok;
      await _carica();
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  Future<void> _membro(Map m, String scelta) async {
    final mid = m['membershipId'] as String;
    switch (scelta) {
      case 'sospendi':
        await _azione(() => PortaleApi.modificaMembro(id, mid, attivo: false), 'Accesso sospeso.');
      case 'riattiva':
        await _azione(() => PortaleApi.modificaMembro(id, mid, attivo: true), 'Accesso riattivato.');
      case 'attivazione':
        await _azione(() => PortaleApi.reinviaAttivazione(m['userId'] as String), 'Link di attivazione inviato di nuovo.');
      case 'rimuovi':
        if (await _conferma(context, 'Togliere l\'accesso?', '${m['nome']} non vedrà più "${dettaglio!['nome']}". I suoi documenti passano a un proprietario. Ricevono un avviso sia lei/lui sia i proprietari.', 'Togli l\'accesso')) {
          await _azione(() => PortaleApi.rimuoviMembro(id, mid), 'Accesso tolto.');
        }
      default:
        if (scelta.startsWith('ruolo:')) {
          await _azione(() => PortaleApi.modificaMembro(id, mid, ruolo: scelta.substring(6)), 'Ruolo cambiato.');
        }
    }
  }

  Future<void> _aggiungiMembro() async {
    final p = _Persona((ruoli.where((r) => r['proprietario'] != true).firstOrNull?['slug'] as String?) ?? 'member');
    String? err;
    final fatto = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text('Aggiungi a ${dettaglio!['nome']}'),
          content: SizedBox(
            width: 600,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              _personaCampi(p, ruoli: [for (final r in ruoli) (r['slug'] as String, r['nome'] as String)], onRuolo: () => setLocal(() {})),
              SizedBox(height: 8),
              Testo.bodySmall('Nome e cognome servono solo se non ha ancora un account. I proprietari ricevono un avviso.', tenue: true),
              _errore(err),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(
              onPressed: () async {
                try {
                  await PortaleApi.aggiungiMembro(id, email: p.email.text.trim(), nome: p.nome.text.trim(), cognome: p.cognome.text.trim(), ruolo: p.ruolo);
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Aggiungi'),
            ),
          ],
        ),
      ),
    );
    p.dispose();
    if (fatto == true) {
      modificato = true;
      info = 'Membro aggiunto.';
      await _carica();
    }
  }

  Future<void> _invitaFamiglia() async {
    final gruppi = await PortaleApi.gruppiEnte(id).catchError((_) => <Map<String, dynamic>>[]);
    if (!mounted) return;
    final iNome = TextEditingController();
    final iCognome = TextEditingController();
    final referente = _Persona('');
    final relazione = TextEditingController();
    String? gruppoId;
    String? err;
    final fatto = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Invita una famiglia a collegarsi'),
          content: SizedBox(
            width: 600,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                Testo.labelMedium('Iscritto', fontWeight: FontWeight.w500),
                SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  _campo(iNome, 'Nome', width: 180),
                  _campo(iCognome, 'Cognome', width: 180),
                  if (gruppi.isNotEmpty)
                    SizedBox(
                      width: 200,
                      child: DropdownButtonFormField<String?>(
                        initialValue: gruppoId,
                        decoration: _dec('Classe o gruppo'),
                        items: [
                          const DropdownMenuItem(value: null, child: Text('Nessuno')),
                          for (final g in gruppi) DropdownMenuItem(value: g['id'] as String, child: Text(g['nome'] as String)),
                        ],
                        onChanged: (v) => setLocal(() => gruppoId = v),
                      ),
                    ),
                ]),
                SizedBox(height: 16),
                Testo.labelMedium('Genitore o tutore che riceve l\'invito', fontWeight: FontWeight.w500),
                SizedBox(height: 8),
                _personaCampi(referente),
                SizedBox(height: 8),
                _campo(relazione, 'Relazione (facoltativa)', width: 220, hint: 'madre, padre, tutore…'),
                SizedBox(height: 8),
                Testo.bodySmall('Il collegamento nasce solo quando la famiglia accetta dall\'email: sceglie lei la propria famiglia e la persona. '
                  'Se l\'iscritto è già nell\'ente, è meglio che l\'invito parta dalla segreteria per non creare doppioni.', tenue: true),
                _errore(err),
              ]),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(
              onPressed: () async {
                try {
                  await PortaleApi.invitaFamiglia(id,
                      iscritto: {'nome': iNome.text.trim(), 'cognome': iCognome.text.trim()},
                      referente: {...referente.toJson(conRuolo: false), 'relazione': relazione.text.trim()},
                      gruppoId: gruppoId);
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Invia l\'invito'),
            ),
          ],
        ),
      ),
    );
    for (final c in [iNome, iCognome, relazione]) {
      c.dispose();
    }
    referente.dispose();
    if (fatto == true && mounted) setState(() => info = 'Invito inviato: la famiglia si collega accettandolo dall\'email.');
  }

  Future<void> _nuovoProprietario() async {
    setState(() => errore = null);
    try {
      await PortaleApi.nuovoProprietario(id, membershipId: membershipId!, documento: documento.text, come: come.text, nota: nota.text);
      if (mounted) Navigator.pop(context, 'Procedura avviata: tra 72 ore il membro diventa proprietario.');
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = dettaglio;
    if (d == null) {
      return AlertDialog(
        content: SizedBox(width: 300, height: 80, child: Center(child: errore != null ? Text(errore!) : const CircularProgressIndicator())),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Chiudi'))],
      );
    }
    final membri = (d['membri'] as List).cast<Map>();
    final orfana = d['stato'] == 'senza_proprietario';
    final gestibile = _superadmin && d['stato'] != 'in_eliminazione';
    return AlertDialog(
      title: Text(d['nome'] as String),
      content: SizedBox(
        width: 620,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.bodySmall('${isEnte ? 'Ente' : 'Famiglia'} · creato il ${_data(d['createdAt'])} · ${formatBytes((d['spazioBytes'] as num).toInt())} · ${_PortaleTenantScreenState._statoLabel(d['stato'] as String)}'),
            SizedBox(height: 12),
            Row(children: [
              Expanded(child: Testo.labelMedium('Membri', fontWeight: FontWeight.w500)),
              if (gestibile) TextButton.icon(onPressed: _aggiungiMembro, icon: const Icon(LucideIcons.user_plus, size: 16), label: const Text('Aggiungi')),
              if (gestibile && isEnte) TextButton.icon(onPressed: _invitaFamiglia, icon: const Icon(LucideIcons.link, size: 16), label: const Text('Invita una famiglia')),
            ]),
            for (final m in membri)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text('${m['nome']} · ${m['email']}'),
                subtitle: Text([
                  m['ruolo'],
                  if (m['stato'] != 'active') 'sospeso',
                  if (m['utenteAttivo'] != true) 'account bloccato',
                  if (m['inAttesaAttivazione'] == true) 'in attesa di attivazione',
                ].join(' · ')),
                trailing: !gestibile
                    ? null
                    : PopupMenuButton<String>(
                        tooltip: 'Gestisci',
                        onSelected: (v) => _membro(m, v),
                        itemBuilder: (_) => [
                          for (final r in ruoli.where((r) => r['id'] != m['ruoloId'])) PopupMenuItem(value: 'ruolo:${r['id']}', child: Text('Ruolo: ${r['nome']}')),
                          const PopupMenuDivider(),
                          if (m['inAttesaAttivazione'] == true) const PopupMenuItem(value: 'attivazione', child: Text('Reinvia il link di attivazione')),
                          if (m['stato'] == 'active') const PopupMenuItem(value: 'sospendi', child: Text('Sospendi l\'accesso')) else const PopupMenuItem(value: 'riattiva', child: Text('Riattiva l\'accesso')),
                          const PopupMenuItem(value: 'rimuovi', child: Text('Togli dal tenant')),
                        ],
                      ),
              ),
            if (gestibile)
              Testo.bodySmall('Ogni modifica è registrata e i proprietari ricevono un avviso. Per bloccare l\'account in tutta la piattaforma usa "Utenti e supporto".', tenue: true),
            if (info != null) Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall(info!, color: ColoreStato.successo)),
            if (orfana) ...[
              const Divider(height: 24),
              Testo.labelMedium('Nuovo proprietario'),
              Testo.bodySmall('Verifica l\'identità del membro (videochiamata con documento, confronto con l\'anagrafica). Non chiedere mai password o codici. '
                'Dopo 72 ore, con avviso a tutti i membri, diventa proprietario. Del documento si annota solo tipo e ultime cifre.', tenue: true),
              SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: membershipId,
                decoration: _dec('Membro da promuovere'),
                items: [
                  for (final m in membri.where((m) => m['stato'] == 'active' && m['utenteAttivo'] == true))
                    DropdownMenuItem(value: m['membershipId'] as String, child: Text('${m['nome']} (${m['ruolo']})')),
                ],
                onChanged: (v) => setState(() => membershipId = v),
              ),
              SizedBox(height: 8),
              TextField(controller: documento, decoration: _dec('Documento verificato (tipo e ultime cifre)')),
              SizedBox(height: 8),
              TextField(controller: come, decoration: _dec('Come')),
              SizedBox(height: 8),
              TextField(controller: nota, decoration: _dec('Nota (facoltativa)')),
            ],
            _errore(errore),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, modificato ? '' : null), child: const Text('Chiudi')),
        if (orfana) FilledButton(onPressed: membershipId == null ? null : _nuovoProprietario, child: const Text('Identità verificata: avvia le 72 ore')),
      ],
    );
  }
}
