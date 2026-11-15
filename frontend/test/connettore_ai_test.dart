import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/connettore_ai/connettori_api.dart';

void main() {
  test('un Mac collegato si legge e si descrive in una riga', () {
    final m = ConnettoreAi.fromJson({
      'id': 'c1',
      'nome': 'MacBook di Mario',
      'online': true,
      'ultimoContatto': '2026-11-15T10:00:00+01:00',
      'chip': 'Apple M3',
      'memoriaGb': 16,
      'modello': 'qwen3:4b-instruct-2507-q4_K_M',
      'durataMediaMs': 7400,
      'lavoriFatti': 46,
    });
    expect(m.online, isTrue);
    expect(m.lavoriFatti, 46);
    expect(m.descrizione, 'Apple M3 · 16 GB · qwen3:4b-instruct-2507-q4_K_M · 7,4 s a documento');
    expect(ConnettoreAi.fromJson({'id': 'c2', 'nome': 'Mac'}).descrizione, '');
    expect(ConnettoreAi.fromJson({'id': 'c3', 'nome': 'Mac', 'durataMediaMs': 18000}).descrizione, '18 s a documento');
  });
}
