import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/view/indice_documento_card.dart';

Widget _app(Widget figlio) => MaterialApp(home: Scaffold(body: SingleChildScrollView(child: figlio)));

void main() {
  test('dettaglio con i dati trovati nel testo', () {
    final d = Documento.fromJson({
      'id': 'd1',
      'titolo': 'Bolletta',
      'indice': {
        'dati': {'emittente': 'ENEL ENERGIA S.p.A.', 'data': '2025-03-15', 'importo': '1287.40', 'numero': '004512378', 'intestatario': null},
        'tag': ['luce', 'energia'],
        'riferimenti': ['IT001E12345678'],
        'aggiornatoIl': '2026-11-07T11:00:00+00:00',
      },
    });
    final i = d.indice!;
    expect([i.emittente, i.data, i.numero, i.intestatario], ['ENEL ENERGIA S.p.A.', DateTime(2025, 3, 15), '004512378', null]);
    expect(i.importoLeggibile, '1.287,40 €');
    expect(i.tag, ['luce', 'energia']);
    expect(i.riferimenti, ['IT001E12345678']);
    expect(i.vuoto, isFalse);

    expect(Documento.fromJson({'id': 'd2', 'titolo': 'x'}).indice, isNull);
    expect(IndiceDocumento.fromJson({'dati': {'importo': '87.4'}}).importoLeggibile, '87,40 €');
    expect(IndiceDocumento.fromJson({'dati': {'emittente': ' '}, 'tag': []}).vuoto, isTrue);
  });

  test('tipo di file in parole, anche per i formati Apple, TXT e ZIP', () {
    String tipo(String mime) => Documento(id: 'd', titolo: 'x', mimeType: mime).tipoFile;
    expect(tipo('application/vnd.apple.pages'), 'Documento di testo');
    expect(tipo('application/vnd.apple.numbers'), 'Foglio di calcolo');
    expect(tipo('application/vnd.apple.keynote'), 'Presentazione');
    expect(tipo('text/plain'), 'Testo');
    expect(tipo('application/zip'), 'Archivio ZIP');
    expect(tipo('image/webp'), 'Foto');
  });

  test('documento simile: motivo leggibile', () {
    final s = DocumentoSimile.fromJson({
      'id': 'd2',
      'titolo': 'Libretto Panda',
      'categoria': {'id': 'c1', 'nome': 'Veicoli'},
      'dataDocumento': null,
      'motivo': 'riferimento',
      'riferimento': 'AB123CD',
    });
    expect(s.motivoLeggibile, 'Stesso codice AB123CD');
    expect(s.categoria!.nome, 'Veicoli');
    expect(DocumentoSimile.fromJson({'id': 'd3', 'titolo': 'x', 'motivo': 'contenuto', 'riferimento': null}).motivoLeggibile, 'Contenuto simile');
  });

  testWidgets('documento cifrato: spiega perché non viene analizzato, senza pulsante', (tester) async {
    await tester.pumpWidget(_app(IndiceDocumentoCard(
      tenantId: 't1',
      documento: Documento(id: 'd1', titolo: 'Referto', cifrato: true, ocrStatus: 'completato'),
      puoRianalizzare: true,
    )));
    await tester.pumpAndSettle();
    expect(find.textContaining('I documenti cifrati non vengono analizzati'), findsOneWidget);
    expect(find.byTooltip('Analizza di nuovo'), findsNothing);
  });

  testWidgets('non ancora analizzato: messaggio e pulsante solo a chi può modificare', (tester) async {
    final d = Documento(id: 'd1', titolo: 'Bolletta', ocrStatus: 'completato');
    await tester.pumpWidget(_app(IndiceDocumentoCard(tenantId: 't1', documento: d)));
    await tester.pumpAndSettle();
    expect(find.text('Analisi del testo non ancora disponibile.'), findsOneWidget);
    expect(find.byTooltip('Analizza di nuovo'), findsNothing);

    await tester.pumpWidget(_app(IndiceDocumentoCard(key: const ValueKey('b'), tenantId: 't1', documento: d, puoRianalizzare: true)));
    await tester.pumpAndSettle();
    expect(find.byTooltip('Analizza di nuovo'), findsOneWidget);
  });
}
