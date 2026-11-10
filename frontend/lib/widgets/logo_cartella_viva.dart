import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Logo CartellaViva: simbolo vettoriale + scritta con il font del marchio.
/// Nitido a qualunque dimensione e densità di schermo (il PNG rimpicciolito
/// risultava sgranato). Colori per tema chiaro o scuro, come i master in
/// assets/brand (lockup-chiaro / lockup-scuro).
class LogoCartellaViva extends StatelessWidget {
  /// Altezza del simbolo; la scritta è in proporzione, come nel lockup.
  final double altezza;

  /// Forza la versione per sfondi scuri; se null segue il tema.
  final bool? suScuro;

  const LogoCartellaViva({super.key, this.altezza = 28, this.suScuro});

  static String _simbolo({required String dorso, required String fronte}) => '''
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 100">
<path d="M8 26a6 6 0 0 1 6-6h24l8 8h60a6 6 0 0 1 6 6v54a6 6 0 0 1-6 6H14a6 6 0 0 1-6-6z" fill="$dorso"/>
<path d="M57 31c0-13 8-22 22-25c1 14-8 23-22 25z" fill="#F2B33D"/>
<path d="M57 31c-1-7-5-11-11-13" fill="none" stroke="#F2B33D" stroke-width="4" stroke-linecap="round"/>
<path d="M8 44h104v44a6 6 0 0 1-6 6H14a6 6 0 0 1-6-6z" fill="$fronte"/>
</svg>''';

  static final _chiaro = _simbolo(dorso: '#0F5C4C', fronte: '#3A9A7C');
  static final _scuro = _simbolo(dorso: '#7FC8A9', fronte: '#FFFFFF');

  @override
  Widget build(BuildContext context) {
    final scuro = suScuro ?? Theme.of(context).brightness == Brightness.dark;
    final stile = TextStyle(fontFamily: famigliaMarchio, fontSize: altezza * 0.62, fontWeight: FontWeight.w800, height: 1, letterSpacing: -0.2);
    return Semantics(
      label: 'CartellaViva',
      child: Row(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.center, children: [
        SvgPicture.string(scuro ? _scuro : _chiaro, height: altezza, width: altezza * 1.2, excludeFromSemantics: true),
        SizedBox(width: altezza * 0.2),
        ExcludeSemantics(
          child: Text.rich(TextSpan(children: [
            TextSpan(text: 'Cartella', style: stile.copyWith(color: scuro ? Colors.white : const Color(0xFF13241F))),
            TextSpan(text: 'Viva', style: stile.copyWith(color: scuro ? const Color(0xFF7FC8A9) : const Color(0xFF0F5C4C))),
          ])),
        ),
      ]),
    );
  }
}
