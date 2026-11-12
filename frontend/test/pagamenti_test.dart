import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_widgets.dart';
import 'package:cartellaviva/features/pratiche/view/pratiche_screen.dart' show riepilogoPagamenti;
import 'package:cartellaviva/layout/barra_laterale.dart';

Map<String, dynamic> pagamentoJson({String stato = 'da_pagare', String scadenza = '2026-11-30'}) => {
      'id': 'p1',
      'titolo': 'TARI 2026, seconda rata',
      'ente': 'Comune di Milano',
      'importo': '180.40',
      'scadenza': scadenza,
      'codiceAvviso': '301200004567891234',
      'codiceFiscaleEnte': '01199250158',
      'stato': stato,
      'pagatoIl': null,
      'documento': {'id': 'd1', 'titolo': 'Avviso TARI'},
      'ricevuta': null,
      'scadenzaId': 's1',
      'origine': 'documento',
      'createdAt': '2026-11-12T09:00:00+01:00',
    };

void main() {
  test('Pagamento dal JSON del contratto', () {
    final p = Pagamento.fromJson(pagamentoJson());
    expect([p.titolo, p.importoLeggibile, p.scadenza, p.documento?.titolo, p.ricevuta, p.pagato], ['TARI 2026, seconda rata', '180,40 €', DateTime(2026, 11, 30), 'Avviso TARI', null, false]);
  });

  test('importi in formato italiano e per l\'API', () {
    expect(formatEuro('1287.4'), '1.287,40 €');
    expect(formatEuro(null), '');
    expect(importoPerApi('180,40'), '180.40');
    expect(importoPerApi('1.234,56 €'), '1234.56');
    expect(importoPerApi('1.234'), '1234.00');
    expect(importoPerApi('12.5'), '12.50');
    expect(importoPerApi('abc'), isNull);
  });

  test('codice avviso a gruppi di 4', () {
    expect(raggruppaCodiceAvviso('301200004567891234'), '3012 0000 4567 8912 34');
    expect(raggruppaCodiceAvviso('3012 0000 4567 8912 34'), '3012 0000 4567 8912 34');
  });

  test('giorni che mancano', () {
    final oggi = DateTime(2026, 11, 12, 15);
    expect(giorniAl(DateTime(2026, 11, 30), oggi: oggi), 18);
    expect(giorniAl(DateTime(2026, 11, 12), oggi: oggi), 0);
    expect([quantoMancaA(0), quantoMancaA(1), quantoMancaA(18), quantoMancaA(-1), quantoMancaA(-3)], ['oggi', 'domani', 'tra 18 giorni', 'scaduto ieri', 'scaduto da 3 giorni']);
    expect(coloreScadenza(10), isNull);
    expect(coloreScadenza(6), isNotNull);
    expect(coloreScadenza(-1), isNotNull);
  });

  test('proposta senza importo non si conferma direttamente', () {
    final p = PropostaPagamento.fromJson({'documentoId': 'd1', 'documentoTitolo': 'Avviso', 'categoria': null, 'titoloSuggerito': 'Avviso', 'ente': null, 'importo': null, 'scadenza': null, 'codiceAvviso': null, 'codiceFiscaleEnte': null});
    expect(p.completa, isFalse);
    final q = PropostaPagamento.fromJson({'documentoId': 'd1', 'documentoTitolo': 'Avviso', 'categoria': {'id': 'c', 'nome': 'Tasse'}, 'titoloSuggerito': 'TARI', 'importo': '180.40', 'scadenza': '2026-11-30', 'codiceAvviso': '301200004567891234'});
    expect([q.completa, q.categoriaNome, q.toInput().toJson()['importo'], q.toInput().toJson()['documentoId'], q.toInput().toJson()['scadenza']], [true, 'Tasse', '180.40', 'd1', '2026-11-30']);
  });

  test('riepilogo della scheda Pagamenti', () {
    final e = ElencoPagamenti.fromJson({
      'pagamenti': [pagamentoJson(scadenza: '2026-12-10'), pagamentoJson(scadenza: '2026-11-30')],
      'totaleDaPagare': '310.40',
    });
    expect(riepilogoPagamenti(e), '2 da pagare · 310,40 €, la prima scade il 30/11');
    expect(riepilogoPagamenti(const ElencoPagamenti(pagamenti: [], totaleDaPagare: '0.00')), 'Niente da pagare.');
  });

  testWidgets('il contatore del menu si vede solo se maggiore di zero', (tester) async {
    Future<void> monta(int n) => tester.pumpWidget(MaterialApp(home: Scaffold(body: VoceMenu(icona: Icons.star, titolo: 'Da confermare', rotta: '/da-confermare', contatore: n))));
    await monta(0);
    expect(find.text('0'), findsNothing);
    await monta(3);
    expect(find.text('3'), findsOneWidget);
  });

  testWidgets('Copia mette il codice avviso (solo cifre) negli appunti', (tester) async {
    String? copiato;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') copiato = (call.arguments as Map)['text'] as String;
      return null;
    });
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: CodiceAvvisoRiga(codice: '301200004567891234'))));
    expect(find.text('3012 0000 4567 8912 34'), findsOneWidget);
    await tester.tap(find.text('Copia'));
    await tester.pump();
    expect(copiato, '301200004567891234');
    expect(find.text('Codice avviso copiato'), findsOneWidget);
  });
}
