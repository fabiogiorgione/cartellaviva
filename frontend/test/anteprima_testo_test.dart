import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/anteprima.dart';
import 'package:cartellaviva/widgets/visualizzatore_anteprima.dart';

void main() {
  test('anteprima di un TXT: UTF-8, altrimenti Latin-1', () {
    expect(AnteprimaPronta(Uint8List.fromList(utf8.encode('Caffè')), 'text/plain').testo, 'Caffè');
    expect(AnteprimaPronta(Uint8List.fromList([0x43, 0x61, 0x66, 0x66, 0xE8]), 'text/plain').testo, 'Caffè');
    expect(AnteprimaPronta(Uint8List(0), 'image/webp').isImmagine, isTrue);
  });

  testWidgets('testo lungo: se ne mostra solo una parte, con l\'avviso', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: TestoAnteprima(testo: 'riga corta'))));
    expect(find.text('riga corta'), findsOneWidget);
    expect(find.textContaining('Mostrati i primi'), findsNothing);

    await tester.pumpWidget(MaterialApp(home: Scaffold(body: TestoAnteprima(testo: 'x' * (TestoAnteprima.maxCaratteri + 10)))));
    expect(find.textContaining('Mostrati i primi ${TestoAnteprima.maxCaratteri} caratteri'), findsOneWidget);
  });
}
