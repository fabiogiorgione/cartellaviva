part of 'portale_screens.dart';

// ---------------------------------------------------------------------------
// Registro globale
// ---------------------------------------------------------------------------

class PortaleRegistroScreen extends StatefulWidget {
  const PortaleRegistroScreen({super.key});

  @override
  State<PortaleRegistroScreen> createState() => _PortaleRegistroScreenState();
}

class _PortaleRegistroScreenState extends State<PortaleRegistroScreen> {
  String? outcome;
  String action = '';
  List<Map<String, dynamic>> righe = [];
  bool loading = true;
  bool altre = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool append = false}) async {
    setState(() => loading = true);
    try {
      final page = await PortaleApi.registro(outcome: outcome, action: action, before: append && righe.isNotEmpty ? righe.last['id'].toString() : null);
      righe = append ? [...righe, ...page] : page;
      altre = page.length == 100;
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    return _PaginaPortale(
      titolo: 'Registro globale',
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
          DropdownButton<String?>(
            value: outcome,
            items: const [
              DropdownMenuItem(value: null, child: Text('Tutti gli esiti')),
              DropdownMenuItem(value: 'denied', child: Text('Negati')),
              DropdownMenuItem(value: 'failure', child: Text('Falliti')),
              DropdownMenuItem(value: 'success', child: Text('Riusciti')),
            ],
            onChanged: (v) {
              outcome = v;
              _load();
            },
          ),
          SizedBox(
            width: 260,
            child: TextField(
              decoration: const InputDecoration(hintText: 'Azione che inizia con… (es. auth., procedura.)', isDense: true),
              onSubmitted: (v) {
                action = v.trim();
                _load();
              },
            ),
          ),
        ]),
        SizedBox(height: 16),
        Scheda(paddingAll: 0, child: Column(children: [
            for (final r in righe)
              ListTile(
                dense: true,
                leading: Icon(
                  r['outcome'] == 'denied' || r['outcome'] == 'failure' ? LucideIcons.circle_x : LucideIcons.circle_check,
                  color: r['outcome'] == 'denied' || r['outcome'] == 'failure' ? context.colori.danger : context.colori.success,
                  size: 18,
                ),
                title: Text('${r['action']}${r['permission'] != null ? ' · ${r['permission']}' : ''}'),
                subtitle: Text([r['actorEmail'] ?? 'sistema', if (r['resourceType'] != null) r['resourceType'], if (r['ip'] != null) r['ip']].join(' · ')),
                trailing: Text(_dataOra(r['occurredAt'])),
              ),
            if (loading) const Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()),
            if (!loading && altre) TextButton(onPressed: () => _load(append: true), child: const Text('Mostra altri')),
          ])),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Team del portale (solo superadmin)
// ---------------------------------------------------------------------------

class PortaleTeamScreen extends StatefulWidget {
  const PortaleTeamScreen({super.key});

  @override
  State<PortaleTeamScreen> createState() => _PortaleTeamScreenState();
}

class _PortaleTeamScreenState extends State<PortaleTeamScreen> {
  late Future<List<Map<String, dynamic>>> _future = PortaleApi.team();

  void _reload() => setState(() => _future = PortaleApi.team());

  Future<void> _ruolo(String userId, String? ruolo) async {
    try {
      await PortaleApi.ruolo(userId, ruolo);
      _reload();
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  Future<void> _aggiungi() async {
    final email = TextEditingController();
    var ruolo = 'supporto';
    List<Map<String, dynamic>> trovati = [];
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Aggiungi al team'),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(
                controller: email,
                decoration: const InputDecoration(labelText: 'Cerca l\'utente (deve avere già un account)', isDense: true),
                onSubmitted: (v) async {
                  final r = await PortaleApi.utenti(v);
                  setLocal(() => trovati = r);
                },
              ),
              SizedBox(height: 8),
              SegmentedButton<String>(
                segments: const [ButtonSegment(value: 'supporto', label: Text('Supporto')), ButtonSegment(value: 'sola_lettura', label: Text('Sola lettura'))],
                selected: {ruolo},
                onSelectionChanged: (s) => setLocal(() => ruolo = s.first),
              ),
              for (final u in trovati)
                ListTile(
                  title: Text(u['nome'] as String),
                  subtitle: Text(u['email'] as String),
                  trailing: TextButton(
                    onPressed: () async {
                      await _ruolo(u['id'] as String, ruolo);
                      if (ctx.mounted) Navigator.pop(ctx);
                    },
                    child: const Text('Assegna'),
                  ),
                ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi'))],
        ),
      ),
    );
    email.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _PaginaPortale(
      titolo: 'Team del portale',
      soloSuperadmin: true,
      azioni: [FilledButton.icon(onPressed: _aggiungi, icon: const Icon(LucideIcons.user_plus, size: 18), label: const Text('Aggiungi'))],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.bodySmall('Supporto: tenant e utenti (solo metadati), recuperi d\'accesso, nuovo proprietario, preparazione della verifica degli enti. '
          'Sola lettura: salute e registro globale. Nessuno apre i documenti. Il superadmin si assegna dalla console del server.', tenue: true),
        SizedBox(height: 16),
        FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
            return Scheda(paddingAll: 0, child: Column(children: [
                for (final u in snap.data!)
                  ListTile(
                    title: Text(u['nome'] as String),
                    subtitle: Text('${u['email']} · ultimo accesso ${_data(u['ultimoAccesso'])}'),
                    trailing: u['ruoloPiattaforma'] == 'superadmin'
                        ? const Chip(label: Text('superadmin'))
                        : DropdownButton<String?>(
                            value: u['ruoloPiattaforma'] as String?,
                            items: const [
                              DropdownMenuItem(value: 'supporto', child: Text('Supporto')),
                              DropdownMenuItem(value: 'sola_lettura', child: Text('Sola lettura')),
                              DropdownMenuItem(value: null, child: Text('Togli dal team')),
                            ],
                            onChanged: (v) => _ruolo(u['id'] as String, v),
                          ),
                  ),
              ]));
          },
        ),
      ]),
    );
  }
}
