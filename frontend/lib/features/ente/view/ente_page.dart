import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';

// Le schede dell'area ente usano spesso erroreCard e numeroCard.
export 'package:cartellaviva/widgets/schede.dart';

/// Azione secondaria di una pagina ente: pulsante su schermo largo, voce del menu ⋯ sul telefono.
typedef AzioneSecondaria = ({String etichetta, IconData icona, VoidCallback azione});

/// Pagina dell'area ente: titolo, percorso e contenuto (con controllo del tipo di tenant).
class PaginaEnte extends StatelessWidget {
  final String titolo;
  final String sezione;
  final Widget child;
  final List<Widget> azioni;

  /// Azioni meno usate (guide, import, passaggio d'anno): sul telefono nel menu ⋯.
  final List<AzioneSecondaria> secondarie;

  /// Rotta della pagina genitore, se è un dettaglio (freccia indietro sul telefono).
  final String? indietro;

  const PaginaEnte({super.key, required this.titolo, required this.sezione, required this.child, this.azioni = const [], this.secondarie = const [], this.indietro});

  @override
  Widget build(BuildContext context) {
    final membership = SessionService.to.currentMembership;
    final telefono = MediaQuery.sizeOf(context).width < larghezzaTelefono;
    return Layout(
      indietro: indietro,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: telefono
              ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  // Senza azioni principali il menu ⋯ sta sulla riga del titolo.
                  if (azioni.isEmpty && secondarie.isNotEmpty)
                    Row(children: [Expanded(child: Testo.titleMedium(titolo, fontSize: 18, fontWeight: FontWeight.w500)), _menu()])
                  else
                    Testo.titleMedium(titolo, fontSize: 18, fontWeight: FontWeight.w500),
                  if (azioni.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    // Sul telefono: le azioni principali e un menu ⋯ con le altre, su una riga.
                    Row(children: [
                      Expanded(child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: azioni)),
                      if (secondarie.isNotEmpty) _menu(),
                    ]),
                  ],
                ])
              : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  // Come nelle altre pagine: titolo a sinistra, percorso a destra; i pulsanti sotto.
                  Row(children: [
                    Expanded(child: Testo.titleMedium(titolo, fontSize: 18, fontWeight: FontWeight.w500)),
                    Briciole(voci: [Briciola(nome: 'Ente', rotta: '/ente'), Briciola(nome: sezione, attiva: true)]),
                  ]),
                  if (azioni.isNotEmpty || secondarie.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      for (final a in secondarie) OutlinedButton.icon(onPressed: a.azione, icon: Icon(a.icona, size: 18), label: Text(a.etichetta)),
                      ...azioni,
                    ]),
                  ],
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

  Widget _menu() => PopupMenuButton<int>(
        tooltip: 'Altre azioni',
        icon: const Icon(Icons.more_vert),
        onSelected: (i) => secondarie[i].azione(),
        itemBuilder: (_) => [
          for (final (i, a) in secondarie.indexed) PopupMenuItem(value: i, child: Row(children: [Icon(a.icona, size: 18), const SizedBox(width: 10), Text(a.etichetta)])),
        ],
      );
}
