import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/barra_laterale.dart';
import 'package:cartellaviva/layout/barra_superiore.dart';
import 'package:cartellaviva/layout/eliminazione_banner.dart';
import 'package:cartellaviva/layout/verifica_email_banner.dart';

/// Larghezza sotto la quale il menu diventa un cassetto e la barra in alto un'AppBar.
const larghezzaTelefono = 769.0;

/// Struttura delle pagine dell'app: barra in alto, menu a sinistra, contenuto
/// scorrevole con gli avvisi dell'account in cima.
class Layout extends StatelessWidget {
  final Widget? child;

  const Layout({super.key, this.child});

  Widget _contenuto({EdgeInsets padding = EdgeInsets.zero}) => SingleChildScrollView(
        padding: padding,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [const VerificaEmailBanner(), const EliminazioneBanner(), if (child != null) child!]),
      );

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).size.width < larghezzaTelefono) {
      return Scaffold(
        appBar: AppBar(elevation: 0, actions: const [PulsanteTema(dimensione: 18), MenuAccount(), SizedBox(width: 12)]),
        drawer: const Drawer(child: BarraLaterale()),
        body: _contenuto(),
      );
    }
    return Scaffold(
      body: Column(children: [
        const BarraSuperiore(),
        Expanded(
          child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            ValueListenableBuilder(valueListenable: PreferenzeTema.barraCompatta, builder: (_, compatta, __) => BarraLaterale(compatta: compatta)),
            const SizedBox(width: 10),
            Expanded(child: _contenuto(padding: EdgeInsets.symmetric(vertical: spazioPagina))),
          ]),
        ),
      ]),
    );
  }
}
