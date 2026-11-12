import 'package:flutter/material.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;

/// Modulo di un pagamento. Su schermo largo è una finestra; sul telefono una
/// pagina a tutta altezza con Annulla e Salva sempre in alto. Restituisce il
/// pagamento salvato, o null se annullato.
///
/// [pagamento]: modifica. [iniziale]: dati proposti (es. avviso letto da un documento).
Future<Pagamento?> mostraPagamentoForm(BuildContext context, {required String tenantId, Pagamento? pagamento, PagamentoInput? iniziale, String? documentoTitolo}) {
  final form = _PagamentoForm(
    tenantId: tenantId,
    pagamento: pagamento,
    iniziale: iniziale,
    documentoTitolo: documentoTitolo ?? pagamento?.documento?.titolo,
    telefono: MediaQuery.sizeOf(context).width < larghezzaTelefono,
  );
  if (form.telefono) {
    return Navigator.of(context).push<Pagamento>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => form));
  }
  return showDialog<Pagamento>(context: context, builder: (_) => form);
}

class _PagamentoForm extends StatefulWidget {
  final String tenantId;
  final Pagamento? pagamento;
  final PagamentoInput? iniziale;
  final String? documentoTitolo;
  final bool telefono;

  const _PagamentoForm({required this.tenantId, this.pagamento, this.iniziale, this.documentoTitolo, required this.telefono});

  @override
  State<_PagamentoForm> createState() => _PagamentoFormState();
}

class _PagamentoFormState extends State<_PagamentoForm> {
  final formKey = GlobalKey<FormState>();
  late final PagamentoInput input;
  late final TextEditingController titolo, importo, ente, codice, codiceFiscale;
  bool saving = false;
  String? error;
  Map<String, String> fieldErrors = {};

  bool get modifica => widget.pagamento != null;

  @override
  void initState() {
    super.initState();
    final p = widget.pagamento;
    input = p != null ? PagamentoInput.from(p) : (widget.iniziale ?? PagamentoInput());
    titolo = TextEditingController(text: input.titolo);
    importo = TextEditingController(text: (double.tryParse(input.importo ?? '') ?? 0) > 0 ? input.importo!.replaceAll('.', ',') : '');
    ente = TextEditingController(text: input.ente ?? '');
    codice = TextEditingController(text: input.codiceAvviso == null ? '' : raggruppaCodiceAvviso(input.codiceAvviso!));
    codiceFiscale = TextEditingController(text: input.codiceFiscaleEnte ?? '');
  }

  @override
  void dispose() {
    for (final c in [titolo, importo, ente, codice, codiceFiscale]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _scegliData() async {
    final scelta = await showDatePicker(
      context: context,
      initialDate: input.scadenza ?? DateTime.now().add(const Duration(days: 7)),
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (scelta != null) setState(() => input.scadenza = scelta);
  }

  Future<void> _salva() async {
    if (saving || !(formKey.currentState?.validate() ?? false)) return;
    input
      ..titolo = titolo.text
      ..importo = importoPerApi(importo.text)
      ..ente = ente.text
      ..codiceAvviso = codice.text
      ..codiceFiscaleEnte = codiceFiscale.text;
    setState(() {
      saving = true;
      error = null;
      fieldErrors = {};
    });
    try {
      final salvato = modifica
          ? await PagamentiApi.update(widget.tenantId, widget.pagamento!.id, input)
          : await PagamentiApi.create(widget.tenantId, input);
      if (mounted) Navigator.of(context).pop(salvato);
    } on ApiException catch (e) {
      setState(() {
        saving = false;
        error = e.message;
        fieldErrors = e.fieldErrors;
      });
    }
  }

  Widget _campi() {
    final d = input.scadenza;
    return Form(
      key: formKey,
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        TextFormField(
          controller: titolo,
          maxLength: 200,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(labelText: 'Cosa devi pagare *', hintText: 'Es. TARI 2026, seconda rata', errorText: fieldErrors['titolo']),
          validator: (v) => (v ?? '').trim().isEmpty ? 'Indica cosa devi pagare' : null,
        ),
        const SizedBox(height: 8),
        TextFormField(
          controller: importo,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: 'Importo (€) *', hintText: '180,40', errorText: fieldErrors['importo']),
          validator: (v) {
            final i = importoPerApi(v ?? '');
            return i == null || double.parse(i) <= 0 ? "L'importo deve essere maggiore di zero." : null;
          },
        ),
        const SizedBox(height: 16),
        TextFormField(
          controller: ente,
          maxLength: 200,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(labelText: 'Ente', hintText: 'Es. Comune di Milano', errorText: fieldErrors['ente']),
        ),
        const SizedBox(height: 8),
        InkWell(
          onTap: _scegliData,
          child: InputDecorator(
            decoration: InputDecoration(
              labelText: 'Scadenza',
              errorText: fieldErrors['scadenza'],
              suffixIcon: d == null ? const Icon(Icons.calendar_today, size: 18) : IconButton(tooltip: 'Togli la data', icon: const Icon(Icons.close, size: 18), onPressed: () => setState(() => input.scadenza = null)),
            ),
            child: Text(d == null ? 'Nessuna' : formatDataBreve(d)),
          ),
        ),
        const SizedBox(height: 16),
        TextFormField(
          controller: codice,
          keyboardType: TextInputType.number,
          decoration: InputDecoration(labelText: 'Codice avviso', hintText: '18 cifre', errorText: fieldErrors['codiceAvviso']),
          validator: (v) {
            final cifre = (v ?? '').replaceAll(RegExp(r'\s'), '');
            if (cifre.isEmpty) return null;
            return RegExp(r'^\d{18}$').hasMatch(cifre) ? null : 'Il codice avviso ha 18 cifre.';
          },
        ),
        if (!modifica && input.scadenza != null) ...[
          const SizedBox(height: 8),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: input.promemoria,
            onChanged: (v) => setState(() => input.promemoria = v),
            title: const Text('Ricordamelo'),
            subtitle: const Text('Crea una scadenza con il promemoria 7 giorni prima'),
          ),
        ],
        if (input.documentoId != null) ...[
          const SizedBox(height: 8),
          InputDecorator(
            decoration: const InputDecoration(labelText: 'Documento collegato'),
            child: Text(widget.documentoTitolo ?? 'Documento', overflow: TextOverflow.ellipsis),
          ),
        ],
        if (error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final titoloPagina = modifica ? 'Modifica pagamento' : 'Nuovo pagamento';
    if (widget.telefono) {
      return Scaffold(
        appBar: AppBar(
          leadingWidth: 96,
          leading: Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: const Text('Annulla'))),
          title: Text(titoloPagina, style: const TextStyle(fontSize: 16)),
          centerTitle: true,
          actions: [Padding(padding: const EdgeInsets.only(right: 8), child: TextButton(onPressed: saving ? null : _salva, child: Text(saving ? 'Salvo…' : 'Salva', style: const TextStyle(fontWeight: FontWeight.w700))))],
        ),
        body: SafeArea(child: SingleChildScrollView(keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag, padding: const EdgeInsets.all(16), child: _campi())),
      );
    }
    return AlertDialog(
      title: Text(titoloPagina),
      content: SizedBox(width: 460, child: SingleChildScrollView(child: _campi())),
      actions: [
        TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: const Text('Annulla')),
        FilledButton(onPressed: saving ? null : _salva, child: Text(saving ? 'Salvataggio…' : 'Salva')),
      ],
    );
  }
}
