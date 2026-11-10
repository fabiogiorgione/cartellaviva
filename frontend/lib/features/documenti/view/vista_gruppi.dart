import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';

/// Documenti di un gruppo aperto, caricati a blocchi.
class GruppoDocumenti {
  List<Documento> righe = const [];
  int totale = 0;
  bool loading = true;
  String? errore;

  int get rimanenti => totale - righe.length;
}

/// Documenti divisi in gruppi richiudibili (per mittente, anno...), ognuno con
/// il suo conteggio. I documenti di un gruppo si caricano quando lo si apre.
class VistaGruppi extends StatelessWidget {
  final List<VoceFaccetta> voci;
  final Map<String, GruppoDocumenti> aperti;

  /// Null se l'utente non può fare azioni di gruppo: niente caselle.
  final Set<String>? selezionati;
  final VoidCallback onCambiaSelezione;
  final ValueChanged<String> onApriChiudi;
  final ValueChanged<String> onAltri;
  final ValueChanged<String> onRiprova;
  final Widget Function(Documento) riga;

  const VistaGruppi({
    super.key,
    required this.voci,
    required this.aperti,
    required this.selezionati,
    required this.onCambiaSelezione,
    required this.onApriChiudi,
    required this.onAltri,
    required this.onRiprova,
    required this.riga,
  });

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [for (final v in voci) _gruppo(context, v)]);

  Widget _gruppo(BuildContext context, VoceFaccetta v) {
    final tema = Theme.of(context);
    final gruppo = aperti[v.valore];
    final ids = [for (final d in gruppo?.righe ?? const <Documento>[]) d.id];
    final selezione = selezionati;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      InkWell(
        onTap: () => onApriChiudi(v.valore),
        child: Container(
          constraints: const BoxConstraints(minHeight: 46),
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(border: Border(bottom: BorderSide(color: tema.dividerColor.withAlpha(90)))),
          child: Row(children: [
            Icon(gruppo == null ? LucideIcons.chevron_right : LucideIcons.chevron_down, size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text(v.etichetta, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
            if (selezione != null && ids.isNotEmpty)
              Tooltip(
                message: 'Seleziona i documenti mostrati di questo gruppo',
                child: Checkbox(
                  value: ids.every(selezione.contains),
                  onChanged: (s) {
                    s == true ? selezione.addAll(ids) : selezione.removeAll(ids);
                    onCambiaSelezione();
                  },
                ),
              ),
            const SizedBox(width: 8),
            Text('${v.n}', style: tema.textTheme.bodySmall?.copyWith(color: tema.hintColor)),
          ]),
        ),
      ),
      if (gruppo != null) _contenuto(v, gruppo),
    ]);
  }

  Widget _contenuto(VoceFaccetta v, GruppoDocumenti g) {
    if (g.errore != null) {
      return Padding(
        padding: const EdgeInsets.all(12),
        child: Row(children: [Expanded(child: Text(g.errore!)), TextButton(onPressed: () => onRiprova(v.valore), child: const Text('Riprova'))]),
      );
    }
    return Padding(
      padding: const EdgeInsets.only(left: 12, top: 4, bottom: 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        for (final d in g.righe) riga(d),
        if (g.loading)
          const Padding(padding: EdgeInsets.all(16), child: Center(child: SizedBox.square(dimension: 24, child: CircularProgressIndicator(strokeWidth: 2))))
        else if (g.rimanenti > 0)
          Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => onAltri(v.valore), child: Text('Mostra altri (${g.rimanenti})'))),
      ]),
    );
  }
}
