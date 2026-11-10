import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';

// Le schede dell'area ente usano spesso erroreCard e numeroCard.
export 'package:cartellaviva/widgets/schede.dart';

/// Pagina dell'area ente: titolo, percorso e contenuto (con controllo del tipo di tenant).
class PaginaEnte extends StatelessWidget {
  final String titolo;
  final String sezione;
  final Widget child;
  final List<Widget> azioni;

  const PaginaEnte({super.key, required this.titolo, required this.sezione, required this.child, this.azioni = const []});

  @override
  Widget build(BuildContext context) {
    final membership = SessionService.to.currentMembership;
    return Layout(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, spacing: 12, runSpacing: 8, children: [
            Testo.titleMedium(titolo, fontSize: 18, fontWeight: FontWeight.w500),
            Row(mainAxisSize: MainAxisSize.min, children: [
              ...azioni.expand((a) => [a, SizedBox(width: 8)]),
              Briciole(voci: [Briciola(nome: 'Ente', rotta: '/ente'), Briciola(nome: sezione, attiva: true)]),
            ]),
          ]),
        ),
        SizedBox(height: spazioPagina),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: membership?.tenantType == 'azienda'
              ? child
              : Scheda(paddingAll: 24, child: Testo.bodyMedium('Seleziona un ente dal menu per usare questa pagina.')),
        ),
      ]),
    );
  }
}

