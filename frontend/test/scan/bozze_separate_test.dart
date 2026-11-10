import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft_store_io.dart';

void main() {
  test('la scansione per una richiesta non tocca la bozza del nuovo documento', () async {
    final dir = await Directory.systemTemp.createTemp('bozze');
    addTearDown(() => dir.delete(recursive: true));
    final documento = FileScanDraftStore(baseDir: dir);
    final richiestaA = FileScanDraftStore(baseDir: dir, nome: 'scan_richiesta_a');
    final richiestaB = FileScanDraftStore(baseDir: dir, nome: 'scan_richiesta_b');

    await documento.save(ScanDraft.create(userId: 'u', tenantId: 't')..titolo = 'Bolletta');
    await richiestaA.save(ScanDraft.create(userId: 'u', tenantId: 't')..titolo = 'Firma A');

    expect((await documento.load())?.titolo, 'Bolletta');
    expect((await richiestaA.load())?.titolo, 'Firma A');
    expect(await richiestaB.load(), isNull);

    await richiestaA.discard();
    expect(await richiestaA.load(), isNull);
    expect((await documento.load())?.titolo, 'Bolletta', reason: 'inviare la risposta non cancella la bozza del documento');
  });
}
