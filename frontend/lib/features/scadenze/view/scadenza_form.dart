import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';

/// Modulo di creazione/modifica di una scadenza, in un dialog. Restituisce la
/// scadenza salvata, o null se annullato. [documentoId] precompila il
/// collegamento (es. "+ Aggiungi" dalla scheda documento).
Future<Scadenza?> showScadenzaForm(
  BuildContext context, {
  required String tenantId,
  Scadenza? scadenza,
  String? documentoId,
  String? documentoTitolo,
  List<Categoria> categorie = const [],
  List<MembroFamiglia> persone = const [],
  ScadenzaInput? iniziale,
}) {
  return showDialog<Scadenza>(
    context: context,
    builder: (_) => _ScadenzaFormDialog(
      tenantId: tenantId,
      scadenza: scadenza,
      documentoId: documentoId,
      documentoTitolo: documentoTitolo,
      categorie: categorie,
      persone: persone,
      iniziale: iniziale,
    ),
  );
}

/// Anticipi proposti per il promemoria (null = nessuno).
const Map<int?, String> promemoriaOpzioni = {
  null: 'Nessun promemoria',
  0: 'Il giorno stesso',
  1: '1 giorno prima',
  3: '3 giorni prima',
  7: '1 settimana prima',
  30: '1 mese prima',
  60: '2 mesi prima',
};

class _ScadenzaFormDialog extends StatefulWidget {
  final String tenantId;
  final Scadenza? scadenza;
  final String? documentoId;
  final String? documentoTitolo;
  final List<Categoria> categorie;
  final List<MembroFamiglia> persone;

  /// Valori proposti (es. scadenza trovata in un documento): l'utente li conferma.
  final ScadenzaInput? iniziale;

  const _ScadenzaFormDialog({
    required this.tenantId,
    this.scadenza,
    this.documentoId,
    this.documentoTitolo,
    required this.categorie,
    required this.persone,
    this.iniziale,
  });

  @override
  State<_ScadenzaFormDialog> createState() => _ScadenzaFormDialogState();
}

class _ScadenzaFormDialogState extends State<_ScadenzaFormDialog> {
  final formKey = GlobalKey<FormState>();
  late final ScadenzaInput input;
  late final TextEditingController titolo;
  late final TextEditingController note;
  bool saving = false;
  String? error;
  Map<String, String> fieldErrors = {};

  @override
  void initState() {
    super.initState();
    final s = widget.scadenza;
    input = s != null ? ScadenzaInput.from(s) : (widget.iniziale ?? ScadenzaInput(data: DateTime.now().add(Duration(days: 7)), documentoId: widget.documentoId));
    titolo = TextEditingController(text: input.titolo);
    note = TextEditingController(text: input.note ?? '');
  }

  @override
  void dispose() {
    titolo.dispose();
    note.dispose();
    super.dispose();
  }

  String? get _documentoLabel => widget.scadenza?.documentoTitolo ?? widget.documentoTitolo;

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: input.data,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked != null) {
      setState(() => input.data = picked);
    }
  }

  Future<void> _save() async {
    if (saving || !(formKey.currentState?.validate() ?? false)) {
      return;
    }
    input
      ..titolo = titolo.text
      ..note = note.text;
    setState(() {
      saving = true;
      error = null;
      fieldErrors = {};
    });
    try {
      final saved = widget.scadenza == null
          ? await ScadenzaApi.create(widget.tenantId, input)
          : await ScadenzaApi.update(widget.tenantId, widget.scadenza!.id, input);
      if (mounted) {
        Navigator.of(context).pop(saved);
      }
    } on ApiException catch (e) {
      setState(() {
        saving = false;
        error = e.message;
        fieldErrors = e.fieldErrors;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = input.data;
    return AlertDialog(
      title: Text(widget.scadenza == null ? 'Nuova scadenza' : 'Modifica scadenza'),
      content: SizedBox(
        width: 460,
        child: Form(
          key: formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextFormField(
                  controller: titolo,
                  maxLength: 200,
                  decoration: InputDecoration(labelText: 'Cosa scade *', errorText: fieldErrors['titolo']),
                  validator: (v) => (v ?? '').trim().isEmpty ? 'Indica cosa scade' : null,
                ),
                SizedBox(height: 8),
                InkWell(
                  onTap: _pickDate,
                  child: InputDecorator(
                    decoration: InputDecoration(labelText: 'Data *', errorText: fieldErrors['data']),
                    child: Text('${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}'),
                  ),
                ),
                SizedBox(height: 12),
                Row(children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: input.ricorrenza,
                      decoration: InputDecoration(labelText: 'Si ripete'),
                      items: const [
                        DropdownMenuItem(value: 'nessuna', child: Text('No')),
                        DropdownMenuItem(value: 'mensile', child: Text('Ogni mese')),
                        DropdownMenuItem(value: 'annuale', child: Text('Ogni anno')),
                      ],
                      onChanged: (v) => setState(() => input.ricorrenza = v ?? 'nessuna'),
                    ),
                  ),
                  SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<int?>(
                      initialValue: promemoriaOpzioni.containsKey(input.promemoriaGiorni) ? input.promemoriaGiorni : null,
                      isExpanded: true,
                      decoration: InputDecoration(labelText: 'Promemoria via email'),
                      items: promemoriaOpzioni.entries.map((e) => DropdownMenuItem<int?>(value: e.key, child: Text(e.value))).toList(),
                      onChanged: (v) => setState(() => input.promemoriaGiorni = v),
                    ),
                  ),
                ]),
                SizedBox(height: 12),
                DropdownButtonFormField<String?>(
                  initialValue: widget.categorie.any((c) => c.id == input.categoriaId) ? input.categoriaId : null,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: 'Categoria'),
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text('—')),
                    ...widget.categorie.map((c) => DropdownMenuItem<String?>(value: c.id, child: Text(c.nome))),
                  ],
                  onChanged: (v) => setState(() => input.categoriaId = v),
                ),
                SizedBox(height: 12),
                DropdownButtonFormField<String?>(
                  initialValue: widget.persone.any((p) => p.id == input.membroId) ? input.membroId : null,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: 'Riguarda'),
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text('Tutta la famiglia')),
                    ...widget.persone.map((p) => DropdownMenuItem<String?>(value: p.id, child: Text(p.nome))),
                  ],
                  onChanged: (v) => setState(() => input.membroId = v),
                ),
                if (input.documentoId != null) ...[
                  SizedBox(height: 12),
                  InputDecorator(
                    decoration: InputDecoration(labelText: 'Documento collegato'),
                    child: Row(children: [
                      Expanded(child: Text(_documentoLabel ?? 'Documento', overflow: TextOverflow.ellipsis)),
                      IconButton(
                        tooltip: 'Scollega il documento',
                        icon: Icon(Icons.close, size: 18),
                        onPressed: () => setState(() => input.documentoId = null),
                      ),
                    ]),
                  ),
                ],
                SizedBox(height: 12),
                TextFormField(
                  controller: note,
                  maxLines: 3,
                  maxLength: 2000,
                  decoration: InputDecoration(labelText: 'Note'),
                ),
                if (error != null) Padding(padding: EdgeInsets.only(top: 4), child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
              ],
            ),
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: Text('Annulla')),
        FilledButton(onPressed: saving ? null : _save, child: Text(saving ? 'Salvataggio…' : 'Salva')),
      ],
    );
  }
}
