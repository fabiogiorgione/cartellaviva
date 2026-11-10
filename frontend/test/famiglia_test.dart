import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/files/upload_batch.dart';
import 'package:cartellaviva/helpers/services/deep_link_service.dart';
import 'package:cartellaviva/features/import/model/import_source.dart';
import 'package:cartellaviva/features/famiglia/model/persona.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart';
import 'package:cartellaviva/features/import/view/import_esito_screen.dart' show messaggioEsitoImport;

void main() {
  group('caricamento multiplo', () {
    Uint8List bytes(int n) => Uint8List(n)..fillRange(0, n, 1);

    test('un file accettato = un documento; scarta tipi, vuoti, troppo grandi e doppioni', () {
      final r = preparaCaricamento([
        (nome: 'bolletta.PDF', bytes: bytes(10)),
        (nome: 'foto.jpeg', bytes: bytes(20)),
        (nome: 'note.docx', bytes: bytes(5)),
        (nome: 'film.mp4', bytes: bytes(5)),
        (nome: 'vuoto.png', bytes: Uint8List(0)),
        (nome: 'enorme.pdf', bytes: Uint8List(maxUploadBytes + 1)),
        (nome: 'bolletta.PDF', bytes: bytes(10)),
      ]);
      // I documenti Office si caricano (l'anteprima è un PDF ricavato dal server).
      expect(r.accettati.map((f) => f.nome), ['bolletta.PDF', 'foto.jpeg', 'note.docx']);
      expect(r.accettati.map((f) => f.mimeType), ['application/pdf', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
      expect(r.scartati.map((s) => s.nome), ['film.mp4', 'vuoto.png', 'enorme.pdf', 'bolletta.PDF']);
      expect(r.scartati.last.motivo, 'già nella selezione');
    });

    test('mimeTypeFor riconosce solo i tipi accettati dal backend', () {
      expect(mimeTypeFor('scan.HEIC'), 'image/heic');
      expect(mimeTypeFor('contratto.ODT'), 'application/vnd.oasis.opendocument.text');
      expect(mimeTypeFor('bilancio.xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      expect(mimeTypeFor('senza_estensione'), isNull);
      expect(mimeTypeFor('archivio.zip'), 'application/zip');
      expect(mimeTypeFor('slide.KEY'), 'application/vnd.apple.keynote');
      expect(mimeTypeFor('film.mp4'), isNull);
    });
  });

  test('deep link: solo cartellaviva://import/esito diventa una rotta', () {
    expect(DeepLinkService.routeFor(Uri.parse('cartellaviva://import/esito?esito=ok&tenant=t1')), '/import/esito?esito=ok&tenant=t1');
    expect(DeepLinkService.routeFor(Uri.parse('cartellaviva://account/security')), isNull);
    expect(DeepLinkService.routeFor(Uri.parse('https://evil.example/import/esito')), isNull);
  });

  test('messaggi di esito del collegamento a Google', () {
    expect(messaggioEsitoImport('ok', null), contains('collegato'));
    expect(messaggioEsitoImport('errore', 'annullato'), contains('annullato'));
    expect(messaggioEsitoImport('errore', 'qualcosa'), contains('riprova'));
  });

  test('sorgente di import: esito leggibile', () {
    final s = ImportSource.fromJson({
      'id': 's1',
      'provider': 'google_drive',
      'cartellaRadiceId': 'abc',
      'strategia': 'cartelle',
      'stato': 'attivo',
      'collegata': true,
      'ultimoEsito': {'trovati': 15, 'importati': 12, 'duplicati': 3, 'saltati': 0, 'errori': 0},
    });
    expect(s.esitoLabel, '12 importati, 3 già presenti');
    expect(s.statoLabel, 'Collegata');
  });

  test('tenant in eliminazione e spazio usato', () {
    final t = TenantDettaglio.fromJson({
      'id': 't1',
      'name': 'Famiglia Rossi',
      'type': 'privato',
      'modalitaAvanzata': true,
      'eliminazioneProgrammataIl': '2026-10-31T12:00:00+00:00',
      'solaLettura': true,
      'spazioUsatoBytes': 412 * 1024 * 1024,
    });
    expect(t.solaLettura, isTrue);
    expect(t.eliminazioneProgrammataIl, isNotNull);
    expect(t.eliminazioneDalPortale, isFalse);
    expect(TenantDettaglio.fromJson({'id': 't2', 'name': 'X', 'type': 'privato', 'eliminazioneDalPortale': true}).eliminazioneDalPortale, isTrue,
        reason: 'decisa dal portale: il proprietario non vede "Annulla"');
    expect(formatBytes(t.spazioUsatoBytes!), '412,0 MB');
    expect(formatBytes(830 * 1024), '830 KB');
  });

  test('persona: JSON pulito per il backend', () {
    final p = Persona(nome: ' Luca ', cognome: 'Rossi', dataNascita: DateTime(2015, 3, 5), relazione: '  ', codiceFiscale: 'rssLca15c05h501x');
    expect(p.toJson(), {
      'nome': 'Luca',
      'cognome': 'Rossi',
      'sesso': null,
      'dataNascita': '2015-03-05',
      'relazione': null,
      'codiceFiscale': 'RSSLCA15C05H501X',
    });
  });
}
