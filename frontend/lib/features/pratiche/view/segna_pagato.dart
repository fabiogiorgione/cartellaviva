import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/widgets/foglio.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Esito del foglio "Segna come pagato".
typedef EsitoPagato = ({DateTime data, String? ricevutaId});

/// Chiede la data del pagamento (oggi) e, se si vuole, la ricevuta tra i
/// documenti della famiglia. Null se si chiude il foglio.
Future<EsitoPagato?> chiediPagato(BuildContext context, {required String tenantId, required Pagamento pagamento}) {
  final scelta = _Scelta();
  return apriFoglio<EsitoPagato>(
    context,
    titolo: 'Segna come pagato',
    contenuto: (ctx) => _ContenutoPagato(tenantId: tenantId, pagamento: pagamento, scelta: scelta),
    azione: (ctx) => FilledButton(onPressed: () => Navigator.of(ctx).pop((data: scelta.data, ricevutaId: scelta.ricevuta?.id)), child: const Text('Segna come pagato')),
  );
}

class _Scelta {
  DateTime data = DateTime.now();
  Documento? ricevuta;
}

class _ContenutoPagato extends StatefulWidget {
  final String tenantId;
  final Pagamento pagamento;
  final _Scelta scelta;

  const _ContenutoPagato({required this.tenantId, required this.pagamento, required this.scelta});

  @override
  State<_ContenutoPagato> createState() => _ContenutoPagatoState();
}

class _ContenutoPagatoState extends State<_ContenutoPagato> {
  final ricerca = TextEditingController();
  Timer? _attesa;
  List<Documento> risultati = const [];
  bool cerco = false;
  String? errore;

  @override
  void dispose() {
    _attesa?.cancel();
    ricerca.dispose();
    super.dispose();
  }

  void _cambiata(String testo) {
    _attesa?.cancel();
    if (testo.trim().isEmpty) {
      setState(() {
        risultati = const [];
        errore = null;
      });
      return;
    }
    _attesa = Timer(const Duration(milliseconds: 350), () => _cerca(testo));
  }

  Future<void> _cerca(String testo) async {
    setState(() {
      cerco = true;
      errore = null;
    });
    try {
      final docs = await DocumentoApi.list(widget.tenantId, q: testo, perPage: 6);
      if (mounted && ricerca.text == testo) setState(() => risultati = docs);
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
    if (mounted) setState(() => cerco = false);
  }

  Future<void> _scegliData() async {
    final data = await showDatePicker(context: context, initialDate: widget.scelta.data, firstDate: DateTime(2000), lastDate: DateTime.now());
    if (data != null) setState(() => widget.scelta.data = data);
  }

  @override
  Widget build(BuildContext context) {
    final scelta = widget.scelta;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Testo.bodyMedium('${widget.pagamento.titolo} · ${widget.pagamento.importoLeggibile}', fontWeight: FontWeight.w500),
      const SizedBox(height: 16),
      InkWell(
        onTap: _scegliData,
        child: InputDecorator(
          decoration: const InputDecoration(labelText: 'Pagato il', suffixIcon: Icon(Icons.calendar_today, size: 18)),
          child: Text(formatDataBreve(scelta.data)),
        ),
      ),
      const SizedBox(height: 20),
      const Testo.labelLarge('Ricevuta (facoltativa)', fontWeight: FontWeight.w500),
      const SizedBox(height: 4),
      const Testo.bodySmall('Se hai già caricato la ricevuta, collegala: la ritrovi nel pagamento.', tenue: true),
      const SizedBox(height: 8),
      if (scelta.ricevuta != null)
        InputDecorator(
          decoration: const InputDecoration(),
          child: Row(children: [
            const Icon(LucideIcons.file_check, size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text(scelta.ricevuta!.titolo, overflow: TextOverflow.ellipsis)),
            IconButton(tooltip: 'Togli la ricevuta', icon: const Icon(Icons.close, size: 18), onPressed: () => setState(() => scelta.ricevuta = null)),
          ]),
        )
      else ...[
        TextField(
          controller: ricerca,
          onChanged: _cambiata,
          decoration: InputDecoration(
            hintText: 'Cerca un documento per titolo',
            prefixIcon: const Icon(LucideIcons.search, size: 18),
            suffixIcon: cerco ? const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))) : null,
          ),
        ),
        if (errore != null) Padding(padding: const EdgeInsets.only(top: 8), child: Testo.bodySmall(errore!, color: Theme.of(context).colorScheme.error)),
        if (risultati.isEmpty && ricerca.text.trim().isNotEmpty && !cerco && errore == null)
          const Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall('Nessun documento con questo titolo.', tenue: true)),
        for (final d in risultati)
          ListTile(
            contentPadding: EdgeInsets.zero,
            dense: true,
            leading: Icon(d.isPdf ? LucideIcons.file_text : LucideIcons.image, size: 20),
            title: Text(d.titolo, maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: d.categoria == null ? null : Text(d.categoria!.nome),
            onTap: () => setState(() => scelta.ricevuta = d),
          ),
      ],
    ]);
  }
}
