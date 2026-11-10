part of 'portale_screens.dart';

// Dialoghi della lista utenti: modifica e aggiunta di un utente.

class _ModificaUtenteDialog extends StatefulWidget {
  final Map<String, dynamic> utente;

  const _ModificaUtenteDialog({required this.utente});

  @override
  State<_ModificaUtenteDialog> createState() => _ModificaUtenteDialogState();
}

class _ModificaUtenteDialogState extends State<_ModificaUtenteDialog> {
  late final nome = TextEditingController(text: widget.utente['nomeProprio'] as String? ?? '');
  late final cognome = TextEditingController(text: widget.utente['cognome'] as String? ?? '');
  late final email = TextEditingController(text: widget.utente['email'] as String);
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    for (final c in [nome, cognome, email]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await PortaleApi.modificaUtente(widget.utente['id'] as String, nome: nome.text.trim(), cognome: cognome.text.trim(), email: email.text.trim());
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final emailCambiata = email.text.trim().toLowerCase() != (widget.utente['email'] as String);
    return AlertDialog(
      title: const Text('Modifica utente'),
      content: SizedBox(
        width: 480,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 8, runSpacing: 8, children: [
            _campo(nome, 'Nome', width: 220),
            _campo(cognome, 'Cognome', width: 220),
            SizedBox(width: 448, child: TextField(controller: email, onChanged: (_) => setState(() {}), decoration: _dec('Email'))),
          ]),
          if (emailCambiata) ...[
            SizedBox(height: 8),
            Testo.bodySmall('Cambiando l\'email: le sessioni aperte si chiudono, il vecchio indirizzo riceve un avviso e il nuovo va confermato con il link che riceverà '
              '(o con l\'attivazione, se non ha ancora una password).', color: ColoreStato.avviso),
          ],
          _errore(errore),
        ]),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}

/// Aggiunge una persona (nuova o esistente) a una famiglia o a un ente scelto per nome.
class _AggiungiUtenteDialog extends StatefulWidget {
  const _AggiungiUtenteDialog();

  @override
  State<_AggiungiUtenteDialog> createState() => _AggiungiUtenteDialogState();
}

class _AggiungiUtenteDialogState extends State<_AggiungiUtenteDialog> {
  final cerca = TextEditingController();
  final persona = _Persona('');
  List<Map<String, dynamic>> trovati = [];
  Map<String, dynamic>? tenant;
  List<Map<String, dynamic>> ruoli = [];
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    cerca.dispose();
    persona.dispose();
    super.dispose();
  }

  Future<void> _cerca() async {
    try {
      final r = await PortaleApi.elencoTenant({'nome': cerca.text, 'perPagina': '10'});
      setState(() => trovati = (r['righe'] as List).cast<Map<String, dynamic>>().where((t) => t['stato'] != 'in_eliminazione').toList());
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
  }

  Future<void> _scegli(Map<String, dynamic> t) async {
    try {
      final r = await PortaleApi.ruoliTenant(t['id'] as String);
      setState(() {
        tenant = t;
        ruoli = r;
        persona.ruolo = (r.where((x) => x['proprietario'] != true).firstOrNull?['slug'] as String?) ?? (r.first['slug'] as String);
      });
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await PortaleApi.aggiungiMembro(tenant!['id'] as String, email: persona.email.text.trim(), nome: persona.nome.text.trim(), cognome: persona.cognome.text.trim(), ruolo: persona.ruolo);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = tenant;
    return AlertDialog(
      title: const Text('Aggiungi utente'),
      content: SizedBox(
        width: 620,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.labelMedium('1. A quale famiglia o ente?', fontWeight: FontWeight.w500),
            SizedBox(height: 8),
            if (t == null) ...[
              Row(children: [
                Expanded(child: TextField(controller: cerca, onSubmitted: (_) => _cerca(), decoration: _dec('Cerca per nome'))),
                SizedBox(width: 8),
                FilledButton(onPressed: _cerca, child: const Text('Cerca')),
              ]),
              for (final r in trovati)
                ListTile(
                  dense: true,
                  leading: Icon(r['tipo'] == 'privato' ? LucideIcons.house : LucideIcons.school, size: 18),
                  title: Text(r['nome'] as String),
                  subtitle: Text('${r['membri']} membri · ${_statoTenantLabel(r['stato'] as String)}'),
                  onTap: () => _scegli(r),
                ),
            ] else
              Row(children: [
                Icon(t['tipo'] == 'privato' ? LucideIcons.house : LucideIcons.school, size: 18),
                SizedBox(width: 8),
                Expanded(child: Testo.bodyMedium(t['nome'] as String, fontWeight: FontWeight.w500)),
                TextButton(onPressed: () => setState(() => tenant = null), child: const Text('Cambia')),
              ]),
            if (t != null) ...[
              SizedBox(height: 16),
              Testo.labelMedium('2. Chi', fontWeight: FontWeight.w500),
              SizedBox(height: 8),
              _personaCampi(persona, ruoli: [for (final r in ruoli) (r['slug'] as String, r['nome'] as String)], onRuolo: () => setState(() {})),
              SizedBox(height: 8),
              Testo.bodySmall('Nome e cognome servono solo se non ha ancora un account: in quel caso riceve "Attiva il tuo account". I proprietari ricevono un avviso.', tenue: true),
            ],
            _errore(errore),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy || t == null ? null : _salva, child: const Text('Aggiungi')),
      ],
    );
  }
}
