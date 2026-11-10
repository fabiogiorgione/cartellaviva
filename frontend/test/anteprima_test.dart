import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/anteprima.dart';
import 'package:cartellaviva/widgets/visualizzatore_anteprima.dart';

/// PNG 1x1 valido, per l'anteprima di un'immagine.
final _png = Uint8List.fromList([
  0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D, 0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, 0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4,
  0x89, 0x00, 0x00, 0x00, 0x0D, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9C, 0x63, 0x00, 0x01, 0x00, 0x00, 0x05, 0x00, 0x01, 0x0D, 0x0A, 0x2D, 0xB4, 0x00, 0x00, 0x00, 0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE,
  0x42, 0x60, 0x82,
]);

Widget _app(Widget figlio) => MaterialApp(home: Scaffold(body: SizedBox(width: 600, height: 500, child: figlio)));

void main() {
  testWidgets('immagine: si vede, con le azioni sopra', (tester) async {
    await tester.pumpWidget(_app(VisualizzatoreAnteprima(
      carica: () async => AnteprimaPronta(_png, 'image/png'),
      azioni: [TextButton(onPressed: () {}, child: const Text('Apri il file'))],
    )));
    await tester.pumpAndSettle();
    expect(find.byType(Image), findsOneWidget);
    expect(find.text('Apri il file'), findsOneWidget);
  });

  testWidgets('in preparazione: messaggio, poi riprova da solo finché è pronta', (tester) async {
    var chiamate = 0;
    await tester.pumpWidget(_app(VisualizzatoreAnteprima(
      carica: () async => ++chiamate < 2 ? const AnteprimaInPreparazione() : AnteprimaPronta(_png, 'image/png'),
    )));
    await tester.pump();
    expect(find.text('Anteprima in preparazione'), findsOneWidget);
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
    expect(chiamate, 2);
    expect(find.byType(Image), findsOneWidget);
  });

  testWidgets('non disponibile: il motivo del server e "Riprova la conversione" solo a chi può', (tester) async {
    var rigenerata = false;
    await tester.pumpWidget(_app(VisualizzatoreAnteprima(
      carica: () async => const AnteprimaNonDisponibile('Convertitore dei documenti non configurato.'),
      onRigenera: () async => rigenerata = true,
    )));
    await tester.pump();
    expect(find.textContaining('non configurato'), findsOneWidget);
    await tester.tap(find.text('Riprova la conversione'));
    await tester.pump();
    expect(rigenerata, isTrue);
    expect(find.text('Anteprima in preparazione'), findsOneWidget);

    await tester.pumpWidget(_app(VisualizzatoreAnteprima(carica: () async => const AnteprimaNonDisponibile('x'))));
    await tester.pump();
    expect(find.text('Riprova la conversione'), findsNothing);
  });
}
