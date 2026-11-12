import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/barra_laterale.dart';
import 'package:cartellaviva/layout/barra_superiore.dart';
import 'package:cartellaviva/layout/eliminazione_banner.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/layout/verifica_email_banner.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

/// Larghezza sotto la quale il menu diventa un cassetto e la barra in alto un'AppBar.
const larghezzaTelefono = 769.0;

/// Struttura delle pagine dell'app: barra in alto, menu a sinistra, contenuto
/// scorrevole con gli avvisi dell'account in cima.
///
/// Pagina di primo livello (una voce del menu): `Layout(child: ...)`; sul
/// telefono la barra in alto ha il menu ☰.
/// Pagina di dettaglio: `Layout(indietro: '/documenti', child: ...)`; sul
/// telefono la barra in alto ha la freccia indietro (vedi [tornaIndietro]) e
/// niente cassetto. [titolo], se c'è, va nella barra in alto del telefono.
class Layout extends StatelessWidget {
  final Widget? child;

  /// Rotta della pagina genitore: c'è solo nelle pagine di dettaglio.
  final String? indietro;

  /// Titolo nella barra in alto del telefono (facoltativo).
  final String? titolo;

  /// Azioni in più nella barra in alto del telefono, prima di tema e account.
  final List<Widget> azioniTelefono;

  const Layout({super.key, this.child, this.indietro, this.titolo, this.azioniTelefono = const []});

  Widget _contenuto(BuildContext context, {EdgeInsets padding = EdgeInsets.zero}) => SingleChildScrollView(
        padding: padding,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const VerificaEmailBanner(),
          const EliminazioneBanner(),
          if (indietro != null && MediaQuery.of(context).size.width >= larghezzaTelefono) CollegamentoIndietro(genitore: indietro!),
          if (child != null) child!,
        ]),
      );

  Widget _conDettaglio(Widget contenuto) => indietro == null ? contenuto : DettaglioLargo(child: contenuto);

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).size.width < larghezzaTelefono) {
      final dettaglio = indietro != null;
      return Scaffold(
        appBar: AppBar(
          elevation: 0,
          automaticallyImplyLeading: !dettaglio,
          leading: dettaglio ? BackButton(onPressed: () => tornaIndietro(indietro!)) : null,
          titleSpacing: dettaglio ? 0 : null,
          title: titolo == null ? null : Text(titolo!, maxLines: 1, overflow: TextOverflow.ellipsis),
          actions: [...azioniTelefono, const PulsanteTema(dimensione: 18), const MenuAccount(), const SizedBox(width: 12)],
        ),
        // SafeArea: senza, la scelta della famiglia in cima finisce sotto l'orologio.
        drawer: dettaglio ? null : const Drawer(child: SafeArea(child: BarraLaterale())),
        body: _contenuto(context),
      );
    }
    return Scaffold(
      body: Column(children: [
        // Su iPad la barra di stato sta sopra l'app: la barra in alto parte sotto.
        ColoredBox(color: context.colori.testataSfondo, child: const SafeArea(bottom: false, child: BarraSuperiore())),
        Expanded(
          child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            ValueListenableBuilder(valueListenable: PreferenzeTema.barraCompatta, builder: (_, compatta, __) => BarraLaterale(compatta: compatta)),
            const SizedBox(width: 10),
            Expanded(child: _conDettaglio(_contenuto(context, padding: EdgeInsets.symmetric(vertical: spazioPagina)))),
          ]),
        ),
      ]),
    );
  }
}

/// Nome con cui il genitore di un dettaglio compare nel collegamento "←".
const _nomiGenitori = {
  '/documenti': 'Documenti',
  '/richieste': 'Richieste dagli enti',
  '/aiuto': 'Aiuto e guide',
  '/ente/richieste': 'Richieste',
  '/pratiche': 'Pratiche',
};

/// "← Documenti" in cima ai dettagli su schermo largo; usa [tornaIndietro].
class CollegamentoIndietro extends StatelessWidget {
  final String genitore;

  const CollegamentoIndietro({super.key, required this.genitore});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(left: spazioPagina - 8, right: spazioPagina, bottom: 4),
        child: Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => tornaIndietro(genitore),
            icon: const Icon(LucideIcons.arrow_left, size: 16),
            label: Testo.labelMedium(_nomiGenitori[genitore] ?? 'Indietro', fontSize: 13, letterSpacing: 0, color: Theme.of(context).colorScheme.primary),
          ),
        ),
      );
}
