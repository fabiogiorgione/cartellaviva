import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';
import 'package:cartellaviva/features/documenti/view/pannello_filtri.dart';
import 'package:cartellaviva/features/documenti/view/vista_gruppi.dart';

Widget _app(Widget figlio) => MaterialApp(home: Scaffold(body: SingleChildScrollView(child: figlio)));

VoceFaccetta _v(String valore, int n, [String? etichetta]) => VoceFaccetta(valore: valore, etichetta: etichetta ?? valore, n: n);

void main() {
  test('i conteggi arrivano dall\'API divisi per criterio', () {
    final f = Faccette.fromJson({
      'totale': 3,
      'anni': [
        {'valore': '2024', 'etichetta': '2024', 'n': 2},
        {'valore': filtroNessuno, 'etichetta': 'Senza data', 'n': 1},
      ],
      'emittenti': [
        {'valore': 'edilnord', 'etichetta': 'Edilnord srl', 'n': 2},
      ],
    });
    expect(f.totale, 3);
    expect(f.voci[DimensioneFiltro.anno]!.last.etichetta, 'Senza data');
    expect(f.voci[DimensioneFiltro.emittente]!.single.valore, 'edilnord');
    expect(f.voci[DimensioneFiltro.tag], isEmpty, reason: 'un criterio assente non è un errore');
  });

  testWidgets('pannello: un tocco sceglie, le voci oltre le prime si aprono a richiesta', (tester) async {
    final scelte = <String>[];
    final voci = [for (var i = 0; i < 9; i++) _v('m$i', 20 - i, 'Mittente $i')];
    await tester.pumpWidget(_app(PannelloFiltri(
      voci: (d) => d == DimensioneFiltro.emittente ? voci : const [],
      scelto: (d) => d == DimensioneFiltro.emittente ? 'm8' : null,
      onScegli: (d, v) => scelte.add('${d.name}=${v.valore}'),
    )));

    expect(find.text('Mittente'), findsOneWidget);
    expect(find.text('Anno'), findsNothing, reason: 'un filtro senza voci non si mostra');
    expect(find.text('Mittente 5'), findsOneWidget);
    expect(find.text('Mittente 6'), findsNothing);
    expect(find.text('Mittente 8'), findsOneWidget, reason: 'quello scelto resta visibile');

    await tester.tap(find.text('Mittente 1'));
    expect(scelte, ['emittente=m1']);

    await tester.tap(find.text('Mostra tutti (9)'));
    await tester.pump();
    expect(find.text('Mittente 6'), findsOneWidget);
    expect(find.text('Mostra meno'), findsOneWidget);
  });

  testWidgets('gruppi: conteggi, apertura, altri documenti e selezione del gruppo', (tester) async {
    final eventi = <String>[];
    final selezionati = <String>{};
    final gruppo = GruppoDocumenti()
      ..loading = false
      ..totale = 5
      ..righe = [Documento(id: 'a', titolo: 'Documento A'), Documento(id: 'b', titolo: 'Documento B')];
    await tester.pumpWidget(_app(VistaGruppi(
      voci: [_v('edilnord', 5, 'Edilnord'), _v(filtroNessuno, 3, 'Senza mittente')],
      aperti: {'edilnord': gruppo},
      selezionati: selezionati,
      onCambiaSelezione: () => eventi.add('selezione'),
      onApriChiudi: (v) => eventi.add('apri:$v'),
      onAltri: (v) => eventi.add('altri:$v'),
      onRiprova: (v) => eventi.add('riprova:$v'),
      riga: (d) => Text(d.titolo),
    )));

    expect(find.text('Edilnord'), findsOneWidget);
    expect(find.text('5'), findsOneWidget);
    expect(find.text('Documento A'), findsOneWidget);

    await tester.tap(find.text('Senza mittente'));
    expect(eventi, ['apri:$filtroNessuno']);

    await tester.tap(find.text('Mostra altri (3)'));
    expect(eventi.last, 'altri:edilnord');

    await tester.tap(find.byType(Checkbox));
    expect(selezionati, {'a', 'b'});
    expect(eventi.last, 'selezione');
  });

  testWidgets('gruppi: senza permessi niente caselle, un errore si può riprovare', (tester) async {
    final eventi = <String>[];
    final gruppo = GruppoDocumenti()
      ..loading = false
      ..errore = 'Connessione assente';
    await tester.pumpWidget(_app(VistaGruppi(
      voci: [_v('g', 1)],
      aperti: {'g': gruppo},
      selezionati: null,
      onCambiaSelezione: () {},
      onApriChiudi: (_) {},
      onAltri: (_) {},
      onRiprova: eventi.add,
      riga: (d) => Text(d.titolo),
    )));
    expect(find.byType(Checkbox), findsNothing);
    expect(find.text('Connessione assente'), findsOneWidget);
    await tester.tap(find.text('Riprova'));
    expect(eventi, ['g']);
  });
}
