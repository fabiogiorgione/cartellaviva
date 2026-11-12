import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Colore della scadenza: rosso se passata, arancio sotto i 7 giorni, altrimenti normale.
Color? coloreScadenza(int? giorni) {
  if (giorni == null) return null;
  if (giorni < 0) return ColoreStato.errore;
  if (giorni < 7) return ColoreStato.avviso;
  return null;
}

/// "Scade il 30/11/2026 · tra 18 giorni" (colorata se vicina o scaduta).
class ScadenzaPagamento extends StatelessWidget {
  final DateTime? scadenza;
  final bool pagato;

  const ScadenzaPagamento({super.key, required this.scadenza, this.pagato = false});

  @override
  Widget build(BuildContext context) {
    if (scadenza == null) return const Testo.bodySmall('Nessuna scadenza indicata', tenue: true);
    final giorni = giorniAl(scadenza!);
    final colore = pagato ? null : coloreScadenza(giorni);
    return Testo.bodySmall(
      pagato ? 'Scadeva il ${formatDataBreve(scadenza!)}' : 'Scade il ${formatDataBreve(scadenza!)} · ${quantoMancaA(giorni)}',
      color: colore,
      tenue: colore == null,
      fontWeight: colore == null ? FontWeight.w400 : FontWeight.w600,
    );
  }
}

/// Copia il codice avviso (solo cifre) negli appunti.
Future<void> copiaCodiceAvviso(BuildContext context, String codice) async {
  await Clipboard.setData(ClipboardData(text: codice.replaceAll(RegExp(r'\s'), '')));
  if (context.mounted) mostraMessaggio(context, 'Codice avviso copiato');
}

/// "Codice avviso 3012 0000 4567 8912 34" con il pulsante Copia.
class CodiceAvvisoRiga extends StatelessWidget {
  final String codice;

  const CodiceAvvisoRiga({super.key, required this.codice});

  @override
  Widget build(BuildContext context) => Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 0, children: [
        const Testo.bodySmall('Codice avviso', tenue: true),
        SelectableText(raggruppaCodiceAvviso(codice), style: stileTesto(context, TipoTesto.bodyMedium, fontWeight: FontWeight.w500, letterSpacing: .5)),
        TextButton.icon(
          style: TextButton.styleFrom(visualDensity: VisualDensity.compact, padding: const EdgeInsets.symmetric(horizontal: 8)),
          onPressed: () => copiaCodiceAvviso(context, codice),
          icon: const Icon(LucideIcons.copy, size: 16),
          label: const Text('Copia'),
        ),
      ]);
}

/// Collegamento al documento: "Dal documento «Avviso TARI»".
class LinkDocumento extends StatelessWidget {
  final String documentoId;
  final String prefisso;
  final String titolo;

  const LinkDocumento({super.key, required this.documentoId, required this.titolo, this.prefisso = 'Dal documento'});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () => Get.toNamed('/documento/$documentoId'),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Testo.bodySmall('$prefisso «$titolo»', tenue: true, decoration: TextDecoration.underline),
        ),
      );
}

/// Pillola colorata ("Scadenza", "Pagamento").
class Pillola extends StatelessWidget {
  final String testo;
  final Color colore;

  const Pillola(this.testo, {super.key, required this.colore});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: colore.withAlpha(30), borderRadius: BorderRadius.circular(10)),
        child: Testo.labelSmall(testo, color: colore, fontWeight: FontWeight.w600),
      );
}
