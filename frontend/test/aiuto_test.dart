import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/features/aiuto/contenuti_aiuto.dart';

void main() {
  test('ogni passo calcolato dal server ha testo e destinazione nell\'app', () {
    const dalServer = [
      'profilo', 'persone', 'documento', 'scadenza', 'famigliare', 'sicurezza', 'emergenza', //
      'ente_verificato', 'ente_gruppi', 'ente_iscritti', 'ente_inviti', 'ente_richiesta', 'ente_operatori',
    ];
    for (final id in dalServer) {
      expect(testiPassi[id], isNotNull, reason: id);
      expect(testiPassi[id]!.$4, startsWith('/'), reason: id);
    }
  });

  test('primi passi: conteggi', () {
    final s = PrimiPassiStato.fromJson({
      'tipo': 'famiglia',
      'nascosto': false,
      'passi': [
        {'id': 'profilo', 'fatto': true},
        {'id': 'documento', 'fatto': false},
      ],
    });
    expect([s.fatti, s.completati], [1, false]);
  });

  test('guide e FAQ: id unici, ricerca, guide per segreterie presenti', () {
    expect(guide.map((g) => g.id).toSet().length, guide.length);
    expect(guide.where((g) => g.pubblico == PubblicoAiuto.ente).length, greaterThanOrEqualTo(4));
    expect(guidaPer('ente-import-csv')?.passi, isNotEmpty);
    expect(guidaPer('inesistente'), isNull);
    expect(faq.where((f) => f.corrisponde('password')), isNotEmpty);
    expect(faq.where((f) => f.corrisponde('zzzz-nessuna')), isEmpty);
  });
}
