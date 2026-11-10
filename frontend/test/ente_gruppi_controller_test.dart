import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/ente/controller/ente_gruppi_controller.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

Gruppo _gruppo(String id, String anno) => Gruppo.fromJson({'id': id, 'nome': 'Gruppo $id', 'tipo': 'classe', 'anno': anno, 'iscritti': 0, 'operatori': []});

void main() {
  test('apre l\'anno in corso, poi l\'anno scelto; un errore di caricamento resta da mostrare', () async {
    final richiesti = <String?>[];
    var guasto = false;
    final c = EnteGruppiController(
      tenantId: 't1',
      anni: () async => [AnnoEnte.fromJson({'id': 'a1', 'nome': '2025/26', 'archiviato': true}), AnnoEnte.fromJson({'id': 'a2', 'nome': '2026/27', 'archiviato': false})],
      gruppi: (annoId) async {
        if (guasto) throw ApiException('Server non raggiungibile');
        richiesti.add(annoId);
        return [_gruppo('g-$annoId', annoId!)];
      },
      sedi: () async => [],
    );
    await c.load();
    expect(c.annoId, 'a2', reason: 'l\'anno non archiviato');
    expect(c.archiviato, isFalse);
    expect(c.gruppi.single.id, 'g-a2');

    c.scegliAnno('a1');
    await Future<void>.delayed(Duration.zero);
    expect(richiesti, ['a2', 'a1']);
    expect(c.archiviato, isTrue);

    guasto = true;
    await c.load();
    expect(c.errore, 'Server non raggiungibile');
    expect(c.loading, isFalse);
  });

  test('un\'azione riuscita ricarica, una fallita restituisce il messaggio', () async {
    var caricamenti = 0;
    final c = EnteGruppiController(tenantId: 't1', anni: () async => [], gruppi: (_) async {
      caricamenti++;
      return [];
    }, sedi: () async => []);
    expect(await c.esegui(() async {}), isNull);
    expect(caricamenti, 1);
    expect(await c.esegui(() async => throw ApiException('Il gruppo ha ancora degli iscritti')), 'Il gruppo ha ancora degli iscritti');
    expect(caricamenti, 1, reason: 'dopo un errore non si ricarica');
  });
}
