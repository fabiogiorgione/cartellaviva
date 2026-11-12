import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/features/pratiche/controller/da_confermare_service.dart';
import 'package:cartellaviva/features/pratiche/view/proposta_card.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Da confermare (`/da-confermare`): una sola coda con le proposte dell'AI
/// (scadenze e pagamenti trovati nei documenti). Nulla entra senza conferma.
class DaConfermareScreen extends StatefulWidget {
  const DaConfermareScreen({super.key});

  @override
  State<DaConfermareScreen> createState() => _DaConfermareScreenState();
}

class _DaConfermareScreenState extends State<DaConfermareScreen> {
  @override
  void initState() {
    super.initState();
    // La coda può essere cambiata altrove: si rilegge ogni volta che si apre la pagina.
    WidgetsBinding.instance.addPostFrameCallback((_) => DaConfermareService.to.ricarica());
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<DaConfermareService>(
        init: DaConfermareService.to,
        builder: (s) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Flexible(child: Testo.titleMedium('Da confermare', fontSize: 18, fontWeight: FontWeight.w500)),
              Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Da confermare', attiva: true)]),
            ]),
          ),
          SizedBox(height: spazioPagina),
          Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: _corpo(s)),
          SizedBox(height: spazioPagina),
        ]),
      ),
    );
  }

  Widget _corpo(DaConfermareService s) {
    if (!s.caricato) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    final voci = s.voci;
    if (voci.isEmpty) {
      return const StatoVuoto(
        icona: LucideIcons.circle_check,
        titolo: 'Niente da confermare',
        testo: 'Quando leggiamo una scadenza, un avviso di pagamento o una spesa per il 730 in un documento te lo proponiamo qui: entra tra le tue cose da fare solo se lo confermi.',
        guida: 'primi-passi-famiglia',
      );
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Testo.bodySmall(
          '${voci.length} ${voci.length == 1 ? 'proposta letta' : 'proposte lette'} nei documenti. Controlla i dati: diventano scadenze, pagamenti o voci delle pratiche solo se confermi.',
          tenue: true,
        ),
      ),
      for (final v in voci)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Scheda(
            paddingAll: 16,
            child: v.scadenza != null
                ? propostaScadenzaCard(context, v.scadenza!)
                : v.pagamento != null
                    ? propostaPagamentoCard(context, v.pagamento!)
                    : propostaPraticaCard(context, v.pratica!),
          ),
        ),
    ]);
  }
}
