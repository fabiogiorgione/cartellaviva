import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/widgets/lista_crud.dart';

void main() {
  test('vale solo la risposta dell\'ultima richiesta, anche se arriva prima', () async {
    final ultima = UltimaRichiesta();
    final lenta = Completer<String>();
    final veloce = Completer<String>();

    // Filtro cambiato e subito dopo un ordinamento: la prima risposta arriva per ultima.
    final prima = ultima.esegui(() => lenta.future);
    final seconda = ultima.esegui(() => veloce.future);
    veloce.complete('ordinati');
    lenta.complete('vecchi filtri');

    expect(await seconda, 'ordinati');
    expect(await prima, isNull, reason: 'la risposta superata va scartata');
  });

  test('l\'errore di una richiesta superata non arriva, quello dell\'ultima sì', () async {
    final ultima = UltimaRichiesta();
    final vecchia = ultima.esegui<String>(() => Future.error(StateError('vecchia')));
    final nuova = ultima.esegui<String>(() => Future.error(StateError('nuova')));

    expect(await vecchia, isNull);
    await expectLater(nuova, throwsA(isA<StateError>().having((e) => e.message, 'message', 'nuova')));
  });
}
