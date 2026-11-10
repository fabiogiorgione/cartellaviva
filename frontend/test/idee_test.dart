import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/idee/model/idea.dart';

void main() {
  test('idea dell\'autore: stato della principale e risposta', () {
    final i = IdeaMia.fromJson({
      'id': 'i1',
      'tipo': 'funzionalita',
      'area': 'documenti',
      'testo': 'Cartelle condivise con i nonni',
      'contattabile': false,
      'stato': 'pianificata',
      'rispostaPubblica': 'Arriva in primavera.',
      'unita': true,
      'createdAt': '2026-10-02T10:00:00+00:00',
    });
    expect([i.statoLabel, i.rispostaPubblica, i.unita, i.contattabile], ['Pianificata', 'Arriva in primavera.', true, false]);
    expect(IdeaMia.fromJson({'id': 'x', 'tipo': 'miglioramento', 'testo': 't'}).statoLabel, 'Inviata');
  });

  test('idea nel portale: contesto, contatto e duplicati', () {
    final p = IdeaPortale.fromJson({
      'id': 'p1',
      'tipo': 'funzionalita',
      'area': 'import',
      'testo': 'Import da OneDrive',
      'stato': 'nuova',
      'tipoSpazio': 'famiglia',
      'piattaforma': 'android',
      'versioneApp': '1.4.0',
      'richieste': 3,
      'anonima': false,
      'contatto': {'nome': 'Mario Rossi', 'email': 'mario@example.com'},
      'duplicati': [
        {'id': 'd1', 'tipo': 'funzionalita', 'testo': 'OneDrive!', 'duplicatoDi': 'p1', 'anonima': true, 'contatto': null},
      ],
    });
    expect(p.contesto, 'Nuova funzionalità · Import da Drive · Famiglia · android 1.4.0');
    expect([p.richieste, p.contatto?.email, p.duplicati.single.anonima, p.duplicati.single.duplicatoDi], [3, 'mario@example.com', true, 'p1']);
    expect(IdeaPortale.fromJson({'id': 'x', 'tipo': 'miglioramento', 'testo': 't'}).contesto, 'Miglioramento');
  });
}
