import 'package:cryptography/cryptography.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/services/offline/offline_store_io.dart';

void main() {
  test('i file offline sono cifrati AES-GCM e non leggibili con un\'altra chiave', () async {
    final chiave = SecretKey(List<int>.generate(32, (i) => i));
    final altra = SecretKey(List<int>.generate(32, (i) => 255 - i));
    final chiaro = 'CARTA DI IDENTITA - ROSSI MARIO'.codeUnits;

    final blob = await OfflineStore.cifra(chiaro, chiave);
    expect(String.fromCharCodes(blob).contains('ROSSI'), isFalse);
    expect(await OfflineStore.decifra(blob, chiave), chiaro);
    await expectLater(OfflineStore.decifra(blob, altra), throwsA(isA<SecretBoxAuthenticationError>()));

    // Ogni salvataggio usa un nonce nuovo.
    expect(await OfflineStore.cifra(chiaro, chiave), isNot(blob));
  });

  test('metadati: andata e ritorno', () {
    final d = DocumentoOffline(id: 'd1', titolo: 'Patente', nomeFile: 'patente.pdf', mimeType: 'application/pdf', categoria: 'Identità', dimensione: 10, salvatoIl: DateTime(2026, 10, 2));
    final r = DocumentoOffline.fromJson(d.toJson());
    expect([r.id, r.titolo, r.categoria, r.salvatoIl], ['d1', 'Patente', 'Identità', DateTime(2026, 10, 2)]);
  });
}
