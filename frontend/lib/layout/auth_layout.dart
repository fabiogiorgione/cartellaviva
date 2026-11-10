import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Pagine di accesso (login, registrazione, password dimenticata...): sugli
/// schermi larghi il marchio a sinistra e il modulo in una scheda a destra.
class AuthLayout extends StatelessWidget {
  final Widget? child;

  const AuthLayout({super.key, this.child});

  @override
  Widget build(BuildContext context) => MediaQuery.of(context).size.width < 576 ? mobileScreen(context) : largeScreen(context);

  Widget mobileScreen(BuildContext context) {
    return Scaffold(
      body: Container(
        padding: EdgeInsets.only(top: MediaQuery.of(context).padding.top + 20),
        height: MediaQuery.of(context).size.height,
        color: Theme.of(context).cardTheme.color,
        child: SingleChildScrollView(child: child),
      ),
    );
  }

  /// Colori del marchio CartellaViva (proposta "Germoglio").
  static const _verdeChiaro = Color(0xFFEEF4F1);
  static const _verde = Color(0xFF0F5C4C);
  static const _scuro = Color(0xFF13241F);
  static const _testo = Color(0xFF4A5A55);

  Widget largeScreen(BuildContext context) {
    final size = MediaQuery.of(context).size;
    // La scheda del form: un terzo dello schermo, mai troppo stretta né troppo larga.
    final larghezzaScheda = (size.width * .32).clamp(380.0, 480.0);
    return Scaffold(
      backgroundColor: _verdeChiaro,
      body: Row(
        children: [
          Expanded(child: Center(child: SingleChildScrollView(padding: EdgeInsets.all(32), child: marchio(size)))),
          Padding(
            padding: EdgeInsets.symmetric(vertical: size.height * .035, horizontal: 24),
            child: SizedBox(
              width: larghezzaScheda,
              height: size.height * .93,
              child: Stack(
                children: [
                  Positioned(
                    top: 0,
                    right: 2,
                    left: 2,
                    child: Riquadro(color: _verde, borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
                  ),
                  Padding(
                    padding: EdgeInsets.only(top: 4),
                    child: Riquadro(width: double.infinity, borderRadiusAll: 20, child: child),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Lato sinistro delle pagine di accesso: simbolo grande, nome e cosa fa l'app.
  Widget marchio(Size size) {
    final simbolo = (size.width * .22).clamp(200.0, 360.0);
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 560),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SvgPicture.asset('assets/brand/mark.svg', width: simbolo, semanticsLabel: 'CartellaViva'),
          SizedBox(height: 32),
          Text.rich(
            TextSpan(children: const [
              TextSpan(text: 'Cartella', style: TextStyle(color: _scuro)),
              TextSpan(text: 'Viva', style: TextStyle(color: _verde)),
            ]),
            style: TextStyle(fontFamily: famigliaMarchio, fontSize: 48, fontWeight: FontWeight.w800, letterSpacing: -1),
          ),
          SizedBox(height: 12),
          Text(
            'I documenti di famiglia, al sicuro e sempre aggiornati.',
            textAlign: TextAlign.center,
            style: TextStyle(fontFamily: famigliaMarchio, fontSize: 18, fontWeight: FontWeight.w500, color: _testo),
          ),
          SizedBox(height: 28),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            alignment: WrapAlignment.center,
            children: [
              for (final (icona, testo) in const [
                (Icons.lock_outline, 'Documenti cifrati'),
                (Icons.event_available_outlined, 'Scadenze sotto controllo'),
                (Icons.school_outlined, 'Collegata a scuole e associazioni'),
              ])
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(999), border: Border.all(color: const Color(0xFFDCE6E1))),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(icona, size: 16, color: _verde),
                    const SizedBox(width: 8),
                    Text(testo, style: TextStyle(fontFamily: famigliaMarchio, fontSize: 13, fontWeight: FontWeight.w700, color: _scuro)),
                  ]),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
