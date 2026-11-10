import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/widgets/lista_crud.dart';

void main() {
  // Colonne come nell'elenco dei documenti prima della correzione: con 4 icone
  // la colonna "Azioni" (flex 3 su 21, tabella larga 1100) era stretta di 23 px.
  testWidgets('azioni della riga: in una colonna stretta vanno a capo, senza overflow', (tester) async {
    tester.view.physicalSize = const Size(1100, 600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    Widget icona(String t) => listaAzione(Icons.star, t, () {});
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ListaTabella(
          selezionati: <String>{},
          colonne: const [
            ListaColonna('Titolo', flex: 5),
            ListaColonna('Categoria', flex: 3),
            ListaColonna('Riguarda', flex: 3),
            ListaColonna('Data', flex: 2),
            ListaColonna('Caricato', flex: 2),
            ListaColonna('Protezione e testo', flex: 3),
            ListaColonna('Azioni', flex: 3, destra: true),
          ],
          righe: const [{'id': 'd1'}],
          celle: (_) => [
            const Text('Bolletta'),
            const Text('Casa'),
            const Text('Tutta la famiglia'),
            const Text('—'),
            const Text('07/11/2026'),
            const Text('Non cifrato'),
            listaAzioni([icona('Apri'), icona('Modifica'), icona('Condividi'), icona('Elimina')]),
          ],
        ),
      ),
    ));

    expect(tester.takeException(), isNull);
    expect(find.byTooltip('Elimina'), findsOneWidget);
    // L'ultima icona va sulla seconda riga, dentro la cella.
    expect(tester.getTopLeft(find.byTooltip('Elimina')).dy, greaterThan(tester.getTopLeft(find.byTooltip('Apri')).dy));
  });
}
