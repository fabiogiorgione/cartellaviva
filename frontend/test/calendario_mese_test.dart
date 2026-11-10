import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/scadenze/view/calendario_mese.dart';

void main() {
  testWidgets('mese da lunedì, pallini sugli eventi, elenco del giorno scelto, cambio mese', (tester) async {
    tester.view.physicalSize = const Size(800, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final aperti = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: CalendarioMese<String>(
            mese: DateTime(2026, 9),
            eventi: [
              EventoCalendario(giorno: DateTime(2026, 9, 15), titolo: 'Bollo auto', colore: Colors.red, valore: 'bollo'),
              EventoCalendario(giorno: DateTime(2026, 9, 15), titolo: 'Revisione', colore: Colors.blue, valore: 'revisione'),
              EventoCalendario(giorno: DateTime(2026, 10, 1), titolo: 'Rata', colore: Colors.green, valore: 'rata'),
            ],
            onEvento: aperti.add,
          ),
        ),
      ),
    ));

    expect(find.text('Settembre 2026'), findsOneWidget);
    expect(find.text('30'), findsOneWidget, reason: 'settembre ha 30 giorni');
    expect(find.text('31'), findsNothing);

    await tester.tap(find.text('15'));
    await tester.pump();
    expect(find.text('Bollo auto'), findsOneWidget);
    expect(find.text('Revisione'), findsOneWidget);
    await tester.tap(find.text('Revisione'));
    expect(aperti, ['revisione']);

    await tester.tap(find.byTooltip('Mese successivo'));
    await tester.pump();
    expect(find.text('Ottobre 2026'), findsOneWidget);
    await tester.tap(find.text('1'));
    await tester.pump();
    expect(find.text('Rata'), findsOneWidget);
  });
}
