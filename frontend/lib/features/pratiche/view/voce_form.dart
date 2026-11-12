import 'package:flutter/material.dart';
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart' show importoPerApi;
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve, formatIsoDate;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;

/// Modulo di una voce di pratica, costruito dai campi del requisito (file di
/// regole dell'anno). Senza [voce] aggiunge una voce a mano (nasce confermata);
/// con [voce] la corregge, e con [conferma] la conferma anche.
/// Sul telefono è una pagina a tutta altezza, su schermo largo una finestra.
Future<VocePratica?> mostraVoceForm(
  BuildContext context, {
  required String tenantId,
  required Pratica pratica,
  VocePratica? voce,
  String? requisito,
  String? personaId,
  Map<String, String> valori = const {},
  bool conferma = false,
}) {
  final form = _VoceForm(
    tenantId: tenantId,
    pratica: pratica,
    voce: voce,
    requisitoIniziale: voce?.requisito ?? requisito,
    personaIniziale: voce?.personaId ?? personaId,
    valoriIniziali: voce?.valori ?? valori,
    conferma: conferma,
    telefono: MediaQuery.sizeOf(context).width < larghezzaTelefono,
  );
  if (form.telefono) {
    return Navigator.of(context).push<VocePratica>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => form));
  }
  return showDialog<VocePratica>(context: context, builder: (_) => form);
}

class _VoceForm extends StatefulWidget {
  final String tenantId;
  final Pratica pratica;
  final VocePratica? voce;
  final String? requisitoIniziale, personaIniziale;
  final Map<String, String> valoriIniziali;
  final bool conferma, telefono;

  const _VoceForm({
    required this.tenantId,
    required this.pratica,
    this.voce,
    this.requisitoIniziale,
    this.personaIniziale,
    this.valoriIniziali = const {},
    required this.conferma,
    required this.telefono,
  });

  @override
  State<_VoceForm> createState() => _VoceFormState();
}

class _VoceFormState extends State<_VoceForm> {
  final formKey = GlobalKey<FormState>();
  late String requisito;
  String? persona;
  final Map<String, String?> valori = {};
  final Map<String, TextEditingController> testi = {};
  bool saving = false;
  String? error;

  ModelloPratica get modello => widget.pratica.info!;
  RequisitoPratica get req => modello.requisito(requisito)!;
  bool get nuova => widget.voce == null;

  @override
  void initState() {
    super.initState();
    requisito = widget.requisitoIniziale ?? modello.requisiti.first.codice;
    persona = widget.personaIniziale ?? (widget.pratica.persone.length == 1 ? widget.pratica.persone.first.id : null);
    valori.addAll(widget.valoriIniziali);
    _preparaTesti();
  }

  void _preparaTesti() {
    for (final c in [...req.campi.where((c) => const {'importo', 'testo', 'iban', 'targa'}.contains(c.tipo)), const CampoVoce(codice: 'nota', tipo: 'testo', etichetta: 'Nota', obbligatorio: false)]) {
      final v = valori[c.codice];
      testi.putIfAbsent(c.codice, () => TextEditingController(text: c.tipo == 'importo' && v != null ? v.replaceAll('.', ',') : v ?? ''));
    }
    if (req.tipi.isNotEmpty && req.tipo(valori['tipo']) == null) valori['tipo'] = req.tipi.first.codice;
  }

  @override
  void dispose() {
    for (final c in testi.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _scegliData() async {
    final iniziale = DateTime.tryParse(valori['data'] ?? '') ?? DateTime(widget.pratica.anno, DateTime.now().month.clamp(1, 12), 1);
    final d = await showDatePicker(context: context, initialDate: iniziale, firstDate: DateTime(1990), lastDate: DateTime(2100));
    if (d != null) setState(() => valori['data'] = formatIsoDate(d));
  }

  Map<String, String?> _valoriDaInviare() {
    final out = <String, String?>{};
    for (final c in req.campi) {
      out[c.codice] = switch (c.tipo) {
        'importo' => importoPerApi(testi[c.codice]!.text),
        'testo' || 'iban' || 'targa' => testi[c.codice]!.text.trim().isEmpty ? null : testi[c.codice]!.text.trim(),
        _ => valori[c.codice],
      };
    }
    out['nota'] = testi['nota']!.text.trim().isEmpty ? null : testi['nota']!.text.trim();
    return out;
  }

  Future<void> _salva() async {
    if (saving || !(formKey.currentState?.validate() ?? false)) return;
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final v = _valoriDaInviare();
      final p = widget.pratica;
      final salvata = nuova
          ? await PraticheApi.creaVoce(widget.tenantId, p.id, requisito: requisito, personaId: req.perPersona ? persona : null, documentoId: null, valori: v)
          : await PraticheApi.modificaVoce(widget.tenantId, p.id, widget.voce!.id, requisito: requisito, personaId: persona, cambiaPersona: true, valori: v, conferma: widget.conferma);
      if (mounted) Navigator.of(context).pop(salvata);
    } on ApiException catch (e) {
      setState(() {
        saving = false;
        error = e.message;
      });
    }
  }

  Widget _campo(CampoVoce c) {
    final etichetta = '${c.etichetta}${c.obbligatorio ? ' *' : ''}';
    switch (c.tipo) {
      case 'iban' || 'targa':
        final iban = c.tipo == 'iban';
        return TextFormField(
          controller: testi[c.codice],
          textCapitalization: TextCapitalization.characters,
          decoration: InputDecoration(labelText: etichetta, hintText: iban ? 'IT60 X054 2811 1010 0000 0123 456' : 'AB123CD'),
          validator: (v) {
            final t = (v ?? '').replaceAll(RegExp(r'\s'), '').toUpperCase();
            if (t.isEmpty) return c.obbligatorio ? 'Campo obbligatorio' : null;
            final ok = iban ? RegExp(r'^[A-Z]{2}\d{2}[0-9A-Z]{11,30}$').hasMatch(t) : RegExp(r'^[A-Z]{2}\d{3}[A-Z]{2}$').hasMatch(t);
            return ok ? null : (iban ? 'IBAN non valido' : 'Targa non valida (es. AB123CD)');
          },
        );
      case 'importo':
        return TextFormField(
          controller: testi[c.codice],
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: '$etichetta (€)',
            hintText: '45,90',
            helperText: c.soloUtente ? 'Copialo dalla DSU precompilata dell\'INPS: lo confrontiamo con il documento.' : null,
            helperMaxLines: 2,
          ),
          validator: (v) {
            if ((v ?? '').trim().isEmpty) return c.obbligatorio ? 'Indica l\'importo' : null;
            final i = importoPerApi(v!);
            return i == null || double.parse(i) <= 0 ? 'Importo non valido' : null;
          },
        );
      case 'data':
        final d = DateTime.tryParse(valori[c.codice] ?? '');
        return FormField<String>(
          validator: (_) => c.obbligatorio && valori[c.codice] == null ? 'Indica la data' : null,
          builder: (f) => InkWell(
            onTap: _scegliData,
            child: InputDecorator(
              decoration: InputDecoration(labelText: etichetta, errorText: f.errorText, suffixIcon: const Icon(Icons.calendar_today, size: 18)),
              child: Text(d == null ? 'Scegli' : formatDataBreve(d)),
            ),
          ),
        );
      case 'scelta':
        final tipo = req.tipo(valori[c.codice]);
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          DropdownButtonFormField<String>(
            initialValue: tipo?.codice,
            isExpanded: true,
            decoration: InputDecoration(labelText: etichetta),
            items: [for (final t in req.tipi) DropdownMenuItem(value: t.codice, child: Text(t.etichetta, overflow: TextOverflow.ellipsis))],
            onChanged: (v) => setState(() => valori[c.codice] = v),
          ),
          if (tipo?.nota != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tipo!.nota!, style: Theme.of(context).textTheme.bodySmall)),
        ]);
      case 'tracciabile':
        return InputDecorator(
          decoration: InputDecoration(labelText: etichetta, border: InputBorder.none, contentPadding: EdgeInsets.zero),
          child: Wrap(spacing: 8, runSpacing: 4, children: [
            for (final e in valoriTracciabile.entries)
              ChoiceChip(label: Text(e.value), selected: (valori[c.codice] ?? 'non_risulta') == e.key, onSelected: (_) => setState(() => valori[c.codice] = e.key)),
          ]),
        );
      default:
        return TextFormField(
          controller: testi[c.codice],
          maxLength: 200,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(labelText: etichetta),
          validator: (v) => c.obbligatorio && (v ?? '').trim().isEmpty ? 'Campo obbligatorio' : null,
        );
    }
  }

  Widget _campi() {
    final p = widget.pratica;
    return Form(
      key: formKey,
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        DropdownButtonFormField<String>(
          initialValue: requisito,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'Voce'),
          items: [for (final r in modello.requisiti) DropdownMenuItem(value: r.codice, child: Text(r.etichetta, overflow: TextOverflow.ellipsis))],
          onChanged: (v) => setState(() {
            requisito = v!;
            _preparaTesti();
          }),
        ),
        if (req.perPersona) ...[
          const SizedBox(height: 12),
          DropdownButtonFormField<String?>(
            initialValue: persona,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Persona'),
            items: [
              const DropdownMenuItem<String?>(value: null, child: Text('Non indicata')),
              for (final x in p.persone) DropdownMenuItem<String?>(value: x.id, child: Text(x.nome, overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => setState(() => persona = v),
          ),
        ],
        for (final c in req.campi) ...[const SizedBox(height: 12), _campo(c)],
        const SizedBox(height: 12),
        TextFormField(controller: testi['nota'], maxLength: 500, maxLines: 2, decoration: const InputDecoration(labelText: 'Nota')),
        if (widget.voce?.documento != null)
          InputDecorator(decoration: const InputDecoration(labelText: 'Documento'), child: Text(widget.voce!.documento!.titolo, overflow: TextOverflow.ellipsis)),
        if (error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final titolo = nuova ? 'Aggiungi a mano' : (widget.conferma ? 'Controlla e conferma' : 'Correggi');
    final salva = nuova ? 'Aggiungi' : (widget.conferma ? 'Conferma' : 'Salva');
    if (widget.telefono) {
      return Scaffold(
        appBar: AppBar(
          leadingWidth: 96,
          leading: Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: const Text('Annulla'))),
          title: Text(titolo, style: const TextStyle(fontSize: 16)),
          centerTitle: true,
          actions: [Padding(padding: const EdgeInsets.only(right: 8), child: TextButton(onPressed: saving ? null : _salva, child: Text(saving ? 'Salvo…' : salva, style: const TextStyle(fontWeight: FontWeight.w700))))],
        ),
        body: SafeArea(child: SingleChildScrollView(keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag, padding: const EdgeInsets.all(16), child: _campi())),
      );
    }
    return AlertDialog(
      title: Text(titolo),
      content: SizedBox(width: 480, child: SingleChildScrollView(child: _campi())),
      actions: [
        TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: const Text('Annulla')),
        FilledButton(onPressed: saving ? null : _salva, child: Text(saving ? 'Salvataggio…' : salva)),
      ],
    );
  }
}
