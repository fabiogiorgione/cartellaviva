import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/api/condivisione_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

void main() {
  test('condivisione: stato leggibile e documenti', () {
    final c = Condivisione.fromJson({
      'id': 'c1',
      'stato': 'esaurita',
      'nota': 'Dott. Bianchi',
      'documenti': [
        {'id': 'd1', 'titolo': 'Referto'},
      ],
      'scadeIl': '2026-10-09T10:00:00+00:00',
      'maxDownload': 2,
      'download': 2,
      'creataDa': 'Mario Rossi',
    });
    expect([c.attiva, c.statoLabel, c.documenti.single.titolo, c.primoAccessoIl], [false, 'Download esauriti', 'Referto', null]);
    expect(Condivisione.fromJson({'id': 'x', 'stato': 'attiva', 'scadeIl': '2026-10-09T10:00:00Z'}).attiva, isTrue);
  });

  test('la pagina del destinatario non richiede l\'anagrafica completa', () {
    expect(SessionService.isOnboardingExempt('/condivisione?token=abc'), isTrue);
    expect(SessionService.isOnboardingExempt('/condivisioni'), isFalse);
  });
}
