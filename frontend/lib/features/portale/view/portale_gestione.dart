part of 'portale_screens.dart';

// ---------------------------------------------------------------------------
// Gestione manuale (solo superadmin): nuove famiglie ed enti, membri, inviti.
// Il backend ricontrolla tutto; qui si raccolgono i dati e si mostrano gli esiti.
// ---------------------------------------------------------------------------

bool get _superadmin => SessionService.to.me?.platformRole == 'superadmin';

const _ruoliFamiglia = [('member', 'Membro'), ('viewer', 'Sola lettura'), ('owner', 'Proprietario')];
const _ruoliEnte = [('staff', 'Staff'), ('segreteria', 'Segreteria'), ('owner', 'Proprietario')];

/// Una persona da inserire: se l'email ha già un account bastano email e ruolo.
class _Persona {
  final email = TextEditingController();
  final nome = TextEditingController();
  final cognome = TextEditingController();
  String ruolo;

  _Persona(this.ruolo);

  Map<String, String> toJson({bool conRuolo = true}) => {
        'email': email.text.trim(),
        if (nome.text.trim().isNotEmpty) 'nome': nome.text.trim(),
        if (cognome.text.trim().isNotEmpty) 'cognome': cognome.text.trim(),
        if (conRuolo) 'ruolo': ruolo,
      };

  void dispose() {
    email.dispose();
    nome.dispose();
    cognome.dispose();
  }
}

InputDecoration _dec(String label, {String? hint}) => InputDecoration(labelText: label, hintText: hint, isDense: true);

Widget _campo(TextEditingController c, String label, {double width = 220, String? hint, int maxLines = 1}) =>
    SizedBox(width: width, child: TextField(controller: c, maxLines: maxLines, decoration: _dec(label, hint: hint)));

Widget _personaCampi(_Persona p, {List<(String, String)>? ruoli, VoidCallback? onRuolo, VoidCallback? onRemove}) => Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        _campo(p.email, 'Email', width: 230),
        _campo(p.nome, 'Nome', width: 140),
        _campo(p.cognome, 'Cognome', width: 140),
        if (ruoli != null)
          SizedBox(
            width: 150,
            child: DropdownButtonFormField<String>(
              initialValue: p.ruolo,
              decoration: _dec('Ruolo'),
              items: [for (final r in ruoli) DropdownMenuItem(value: r.$1, child: Text(r.$2))],
              onChanged: (v) {
                p.ruolo = v ?? p.ruolo;
                onRuolo?.call();
              },
            ),
          ),
        if (onRemove != null) IconButton(tooltip: 'Togli', onPressed: onRemove, icon: const Icon(LucideIcons.x, size: 18)),
      ],
    );

Widget _errore(String? e) => e == null ? const SizedBox.shrink() : Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall(e, color: ColoreStato.errore));

Future<bool> _conferma(BuildContext context, String titolo, String testo, String azione) =>
    chiediConferma(context, titolo: titolo, testo: testo, azione: azione);

// ---- Nuova famiglia / nuovo ente -------------------------------------------

class _NuovoTenantDialog extends StatefulWidget {
  final bool ente;

  const _NuovoTenantDialog({required this.ente});

  @override
  State<_NuovoTenantDialog> createState() => _NuovoTenantDialogState();
}

class _NuovoTenantDialogState extends State<_NuovoTenantDialog> {
  final nome = TextEditingController();
  final codiceFiscale = TextEditingController();
  final codiceMeccanografico = TextEditingController();
  final numeroRunts = TextEditingController();
  final emailIstituzionale = TextEditingController();
  final sedeLegale = TextEditingController();
  final notaVerifica = TextEditingController();
  final proprietario = _Persona('owner');
  final List<_Persona> altri = [];
  String tipo = 'scuola';
  String? errore;
  bool busy = false;

  bool get ente => widget.ente;
  List<(String, String)> get _ruoli => ente ? _ruoliEnte : _ruoliFamiglia;

  @override
  void dispose() {
    for (final c in [nome, codiceFiscale, codiceMeccanografico, numeroRunts, emailIstituzionale, sedeLegale, notaVerifica]) {
      c.dispose();
    }
    proprietario.dispose();
    for (final p in altri) {
      p.dispose();
    }
    super.dispose();
  }

  Future<void> _crea() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final membri = [for (final p in altri) if (p.email.text.trim().isNotEmpty) p.toJson()];
      final creato = ente
          ? await PortaleApi.creaEnte(
              ente: {
                'tipo': tipo,
                'denominazione': nome.text.trim(),
                'codiceFiscale': codiceFiscale.text.trim(),
                if (tipo == 'scuola') 'codiceMeccanografico': codiceMeccanografico.text.trim(),
                if (tipo == 'associazione') 'numeroRunts': numeroRunts.text.trim(),
                if (tipo == 'associazione') 'emailIstituzionale': emailIstituzionale.text.trim(),
                'sedeLegale': sedeLegale.text.trim(),
              },
              notaVerifica: notaVerifica.text.trim(),
              proprietario: proprietario.toJson(conRuolo: false),
              staff: membri,
            )
          : await PortaleApi.creaFamiglia(nome: nome.text.trim(), proprietario: proprietario.toJson(conRuolo: false), membri: membri);
      if (mounted) Navigator.pop(context, creato['id'] as String);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(ente ? 'Nuovo ente' : 'Nuova famiglia'),
      content: SizedBox(
        width: 640,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (ente) ...[
              SegmentedButton<String>(
                segments: const [ButtonSegment(value: 'scuola', label: Text('Scuola')), ButtonSegment(value: 'associazione', label: Text('Associazione'))],
                selected: {tipo},
                onSelectionChanged: (s) => setState(() => tipo = s.first),
              ),
              SizedBox(height: 12),
            ],
            Wrap(spacing: 8, runSpacing: 8, children: [
              _campo(nome, ente ? 'Denominazione' : 'Nome della famiglia', width: ente ? 300 : 320, hint: ente ? null : 'Famiglia Rossi'),
              if (ente) ...[
                _campo(codiceFiscale, 'Codice fiscale (11 cifre)', width: 200),
                if (tipo == 'scuola') _campo(codiceMeccanografico, 'Codice meccanografico', width: 200, hint: 'MIEE000000'),
                if (tipo == 'associazione') ...[
                  _campo(numeroRunts, 'Numero RUNTS', width: 200),
                  _campo(emailIstituzionale, 'Email o PEC del RUNTS', width: 300),
                ],
                _campo(sedeLegale, 'Sede legale (facoltativa)', width: 300),
              ],
            ]),
            if (ente) ...[
              SizedBox(height: 12),
              _campo(notaVerifica, 'Come avete verificato l\'ente', width: 608, maxLines: 2, hint: 'Es. contratto firmato il…, telefonata alla segreteria del…'),
              SizedBox(height: 4),
              Testo.bodySmall('L\'ente nasce già verificato: la nota resta nel registro al posto del codice all\'email istituzionale.', tenue: true),
            ],
            const Divider(height: 28),
            Testo.labelMedium(ente ? 'Proprietario (segreteria)' : 'Proprietario', fontWeight: FontWeight.w500),
            SizedBox(height: 8),
            _personaCampi(proprietario),
            SizedBox(height: 16),
            Row(children: [
              Expanded(child: Testo.labelMedium(ente ? 'Altri operatori' : 'Altri membri', fontWeight: FontWeight.w500)),
              TextButton.icon(
                onPressed: () => setState(() => altri.add(_Persona(_ruoli.first.$1))),
                icon: const Icon(LucideIcons.user_plus, size: 16),
                label: const Text('Aggiungi'),
              ),
            ]),
            for (final p in altri) ...[
              _personaCampi(p, ruoli: _ruoli, onRuolo: () => setState(() {}), onRemove: () => setState(() {
                    altri.remove(p);
                    p.dispose();
                  })),
              SizedBox(height: 8),
            ],
            SizedBox(height: 8),
            Testo.bodySmall('Chi non ha un account lo riceve senza password, con l\'email "Attiva il tuo account" (link valido 7 giorni). '
              'Per chi ha già un account basta l\'email: riceve un avviso. Gli account del team del portale non possono essere membri.', tenue: true),
            _errore(errore),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context), child: const Text('Annulla')),
        FilledButton(onPressed: busy ? null : _crea, child: Text(ente ? 'Crea l\'ente' : 'Crea la famiglia')),
      ],
    );
  }
}
