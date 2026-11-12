import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Apre un foglio dal basso (filtri, scelte, azioni) con le regole dell'app:
/// - in alto sempre il [titolo] e il pulsante "Chiudi";
/// - il contenuto scorre, l'intestazione e l'[azione] in fondo restano fisse;
/// - non copre mai tutto lo schermo (al massimo il 90%), così si vede che sotto
///   c'è la pagina e si chiude anche toccando fuori o trascinando in basso.
///
/// [contenuto] riceve il contesto del foglio (per `Navigator.pop`).
/// [azione], se c'è, è il pulsante principale in fondo (es. "Mostra 24
/// risultati"): di solito chiude il foglio.
Future<T?> apriFoglio<T>(
  BuildContext context, {
  required String titolo,
  required Widget Function(BuildContext context) contenuto,
  Widget Function(BuildContext context)? azione,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: true,
    constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .9),
    builder: (ctx) => SafeArea(
      top: false,
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 8, 8),
          child: Row(children: [
            Expanded(child: Testo.titleMedium(titolo, fontWeight: FontWeight.w600)),
            IconButton(tooltip: 'Chiudi', icon: const Icon(LucideIcons.x, size: 20), onPressed: () => Navigator.of(ctx).pop()),
          ]),
        ),
        const Divider(height: 1),
        Flexible(child: SingleChildScrollView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 16), child: contenuto(ctx))),
        if (azione != null) ...[
          const Divider(height: 1),
          Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 12), child: azione(ctx)),
        ],
      ]),
    ),
  );
}
