import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';

/// Filtri con il numero di documenti: un tocco sceglie, un altro toglie.
/// Di ogni filtro si vedono le prime voci, le altre con "Mostra tutti".
class PannelloFiltri extends StatefulWidget {
  final List<VoceFaccetta> Function(DimensioneFiltro) voci;
  final String? Function(DimensioneFiltro) scelto;
  final void Function(DimensioneFiltro, VoceFaccetta) onScegli;

  const PannelloFiltri({super.key, required this.voci, required this.scelto, required this.onScegli});

  @override
  State<PannelloFiltri> createState() => _PannelloFiltriState();
}

class _PannelloFiltriState extends State<PannelloFiltri> {
  static const primeVoci = 6;
  final Set<DimensioneFiltro> espansi = {};

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        for (final d in DimensioneFiltro.values)
          if (widget.voci(d).isNotEmpty) _sezione(context, d, widget.voci(d)),
      ]);

  Widget _sezione(BuildContext context, DimensioneFiltro d, List<VoceFaccetta> voci) {
    final scelto = widget.scelto(d);
    final espanso = espansi.contains(d);
    // Il valore scelto resta visibile anche se è oltre le prime voci.
    final visibili = espanso ? voci : [for (final (i, v) in voci.indexed) if (i < primeVoci || v.valore == scelto) v];
    final righe = Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [for (final v in visibili) _voce(context, d, v, v.valore == scelto)]);
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Padding(padding: const EdgeInsets.fromLTRB(8, 0, 8, 4), child: Text(d.titolo, style: Theme.of(context).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w700))),
        if (espanso) ConstrainedBox(constraints: const BoxConstraints(maxHeight: 300), child: SingleChildScrollView(child: righe)) else righe,
        if (voci.length > primeVoci)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              onPressed: () => setState(() => espanso ? espansi.remove(d) : espansi.add(d)),
              child: Text(espanso ? 'Mostra meno' : 'Mostra tutti (${voci.length})'),
            ),
          ),
      ]),
    );
  }

  Widget _voce(BuildContext context, DimensioneFiltro d, VoceFaccetta v, bool scelta) {
    final tema = Theme.of(context);
    final colore = scelta ? tema.colorScheme.primary : null;
    return InkWell(
      borderRadius: BorderRadius.circular(6),
      onTap: () => widget.onScegli(d, v),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        decoration: BoxDecoration(color: scelta ? tema.colorScheme.primary.withAlpha(24) : null, borderRadius: BorderRadius.circular(6)),
        child: Row(children: [
          if (scelta) ...[Icon(LucideIcons.check, size: 14, color: colore), const SizedBox(width: 6)],
          Expanded(child: Text(v.etichetta, overflow: TextOverflow.ellipsis, style: TextStyle(color: colore, fontWeight: scelta ? FontWeight.w600 : null))),
          const SizedBox(width: 8),
          Text('${v.n}', style: tema.textTheme.bodySmall?.copyWith(color: colore ?? tema.hintColor)),
        ]),
      ),
    );
  }
}
