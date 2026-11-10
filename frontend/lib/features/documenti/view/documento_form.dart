import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Modifica di titolo, categoria, persone e data di un documento.
/// Restituisce il documento aggiornato, o null se annullata.
Future<Documento?> showDocumentoForm(BuildContext context, {required String tenantId, required Documento documento}) async {
  final opzioni = await Future.wait([DocumentoApi.categorie(tenantId), DocumentoApi.membriFamiglia(tenantId)]).catchError((_) => <List<Object>>[[], []]);
  if (!context.mounted) return null;
  return showDialog<Documento>(
    context: context,
    builder: (_) => _DocumentoForm(
      tenantId: tenantId,
      documento: documento,
      categorie: opzioni[0] as List<Categoria>,
      persone: opzioni[1] as List<MembroFamiglia>,
    ),
  );
}

class _DocumentoForm extends StatefulWidget {
  final String tenantId;
  final Documento documento;
  final List<Categoria> categorie;
  final List<MembroFamiglia> persone;

  const _DocumentoForm({required this.tenantId, required this.documento, required this.categorie, required this.persone});

  @override
  State<_DocumentoForm> createState() => _DocumentoFormState();
}

class _DocumentoFormState extends State<_DocumentoForm> {
  late final TextEditingController titolo = TextEditingController(text: widget.documento.titolo);
  late String? categoriaId = widget.documento.categoria?.id;
  late final Set<String> personeIds = widget.documento.membri.map((m) => m.id).toSet();
  late DateTime? data = widget.documento.dataDocumento;
  bool saving = false;
  String? error;

  @override
  void dispose() {
    titolo.dispose();
    super.dispose();
  }

  Categoria? get _categoria => widget.categorie.where((c) => c.id == categoriaId).firstOrNull;

  Future<void> _salva() async {
    if (titolo.text.trim().isEmpty || categoriaId == null) {
      setState(() => error = 'Titolo e categoria sono obbligatori.');
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final saved = await DocumentoApi.update(widget.tenantId, widget.documento.id,
          titolo: titolo.text, categoriaId: categoriaId!, membriIds: personeIds.toList(), dataDocumento: data);
      if (mounted) Navigator.of(context).pop(saved);
    } on ApiException catch (e) {
      setState(() {
        saving = false;
        error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final diventaCifrato = !widget.documento.cifrato && (_categoria?.isSensibile ?? false);
    return AlertDialog(
      title: const Text('Modifica documento'),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(controller: titolo, maxLength: 200, decoration: const InputDecoration(labelText: 'Titolo')),
            SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: categoriaId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Categoria'),
              items: [for (final c in widget.categorie) DropdownMenuItem(value: c.id, child: Text(c.isSensibile ? '${c.nome} (cifrata)' : c.nome))],
              onChanged: (v) => setState(() => categoriaId = v),
            ),
            if (diventaCifrato) ...[
              SizedBox(height: 6),
              Testo.bodySmall('Il documento verrà cifrato sul server. La cifratura poi resta anche se cambi di nuovo categoria.', tenue: true),
            ],
            SizedBox(height: 12),
            Row(children: [
              Expanded(child: Testo.bodyMedium(data == null ? 'Data del documento: non indicata' : 'Data del documento: ${formatDataBreve(data!)}')),
              TextButton(
                onPressed: () async {
                  final picked = await showDatePicker(context: context, initialDate: data ?? DateTime.now(), firstDate: DateTime(1900), lastDate: DateTime(2100));
                  if (picked != null) setState(() => data = picked);
                },
                child: const Text('Scegli'),
              ),
              if (data != null) TextButton(onPressed: () => setState(() => data = null), child: const Text('Togli')),
            ]),
            if (widget.persone.isNotEmpty) ...[
              SizedBox(height: 8),
              Testo.labelMedium('Riguarda'),
              SizedBox(height: 6),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final p in widget.persone)
                  FilterChip(label: Text(p.nome), selected: personeIds.contains(p.id), onSelected: (v) => setState(() => v ? personeIds.add(p.id) : personeIds.remove(p.id))),
              ]),
            ],
            if (error != null) ...[SizedBox(height: 12), Testo.bodySmall(error!, color: ColoreStato.errore)],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: saving ? null : () => Navigator.of(context).pop(), child: const Text('Annulla')),
        FilledButton(onPressed: saving ? null : _salva, child: Text(saving ? 'Salvataggio…' : 'Salva')),
      ],
    );
  }
}
