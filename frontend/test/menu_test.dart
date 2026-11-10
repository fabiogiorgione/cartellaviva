import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/layout/barra_laterale.dart';

void main() {
  testWidgets('le voci dei sottomenu restano su una riga e si leggono intere', (tester) async {
    // Spazio di una voce di sottomenu nella barra laterale (240 px meno i rientri).
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: Center(child: SizedBox(width: 156, child: SottoVoceMenu(titolo: 'Tutti i documenti', rotta: '/documenti')))),
    ));
    // Nei test ogni lettera è larga quanto il corpo del font: la larghezza non è
    // realistica. Si verifica la causa del bug: il testo andava a capo e se ne
    // vedeva solo la prima riga ("Tutti i"). Ora non va a capo e, se proprio non
    // ci sta, finisce con "…".
    final testo = tester.renderObject<RenderParagraph>(find.text('Tutti i documenti'));
    expect(testo.softWrap, isFalse);
    expect(testo.overflow, TextOverflow.ellipsis);
    expect(testo.size.height, lessThan(24), reason: 'una riga sola');
  });
}
