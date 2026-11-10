import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/files/upload_batch.dart';

void main() {
  test('cartella: si leggono solo i file caricabili, in ordine, nei limiti', () {
    final voci = [
      (percorso: 'Casa/Bollette/enel.pdf', dimensione: 1000),
      (percorso: 'Casa/.DS_Store', dimensione: 10),
      (percorso: 'Casa/Thumbs.db', dimensione: 10),
      (percorso: 'Casa/.git/config.pdf', dimensione: 10),
      (percorso: 'Casa/__MACOSX/enel.pdf', dimensione: 10),
      (percorso: 'Casa/foto.JPG', dimensione: 2000),
      (percorso: 'Casa/video.mp4', dimensione: 5),
      (percorso: 'Casa/vuoto.pdf', dimensione: 0),
      (percorso: 'Casa/enorme.pdf', dimensione: maxUploadBytes + 1),
      (percorso: 'Casa/Auto/libretto.pdf', dimensione: 3000),
    ];
    final r = selezionaDaCartella(voci);
    // Ordine alfabetico dei percorsi; nascosti e file di sistema saltati in silenzio.
    expect(r.daLeggere.map((i) => voci[i].percorso), ['Casa/Auto/libretto.pdf', 'Casa/Bollette/enel.pdf', 'Casa/foto.JPG']);
    expect({for (final s in r.scartati) s.nome: s.motivo}, {
      'Casa/enorme.pdf': 'più grande di 20 MB',
      'Casa/video.mp4': 'tipo non supportato',
      'Casa/vuoto.pdf': 'file vuoto',
    });
  });

  test('cartella: TXT, ZIP, GIF, WEBP e iWork come file singolo si caricano; i pacchetti iWork no', () {
    final voci = [
      (percorso: 'C/note.txt', dimensione: 5),
      (percorso: 'C/archivio.zip', dimensione: 5),
      (percorso: 'C/animazione.gif', dimensione: 5),
      (percorso: 'C/foto.webp', dimensione: 5),
      (percorso: 'C/lettera.pages', dimensione: 5),
      (percorso: 'C/conti.numbers', dimensione: 5),
      (percorso: 'C/slide.key', dimensione: 5),
      // Pacchetto (cartella): i file interni non diventano documenti, si segnala una volta.
      (percorso: 'C/Bilancio.numbers/Index.zip', dimensione: 5),
      (percorso: 'C/Bilancio.numbers/preview.jpg', dimensione: 5),
      (percorso: 'C/Bilancio.numbers/Data/foto.png', dimensione: 5),
    ];
    final r = selezionaDaCartella(voci);
    expect(r.daLeggere.map((i) => voci[i].percorso), ['C/animazione.gif', 'C/archivio.zip', 'C/conti.numbers', 'C/foto.webp', 'C/lettera.pages', 'C/note.txt', 'C/slide.key']);
    expect(r.scartati.single.nome, 'C/Bilancio.numbers');
    expect(r.scartati.single.motivo, contains('File singolo'));
  });

  test('cartella: oltre il numero massimo di file il resto si scarta', () {
    final tanti = [for (var i = 0; i < maxFileCartella + 3; i++) (percorso: 'C/f${i.toString().padLeft(3, '0')}.pdf', dimensione: 10)];
    final r = selezionaDaCartella(tanti);
    expect(r.daLeggere.length, maxFileCartella);
    expect(r.scartati.length, 3);
    expect(r.scartati.first.motivo, 'oltre i $maxFileCartella file per volta');

    // Nessun limite di byte in tutto: i file si leggono uno alla volta durante il caricamento.
    final grandi = [for (var i = 0; i < 40; i++) (percorso: 'C/g$i.pdf', dimensione: 19 * 1024 * 1024)];
    expect(selezionaDaCartella(grandi).daLeggere.length, 40);
  });

  test('cartella: stesso nome e dimensione in sottocartelle diverse = doppione', () {
    final r = selezionaDaCartella([(percorso: 'C/a/enel.pdf', dimensione: 10), (percorso: 'C/b/enel.pdf', dimensione: 10), (percorso: 'C/c/enel.pdf', dimensione: 11)]);
    expect(r.daLeggere, [0, 2]);
    expect(r.scartati.single.motivo, 'già nella selezione');
  });

  test('file da caricare: il contenuto si legge solo quando serve', () async {
    var letture = 0;
    final f = FileDaCaricare.daLeggere(nome: 'enel.pdf', mimeType: 'application/pdf', dimensione: 3, percorso: 'Bollette/enel.pdf', leggi: () async {
      letture++;
      return Uint8List.fromList([1, 2, 3]);
    });
    expect([f.dimensione, letture], [3, 0], reason: 'creato senza leggere');
    expect(await f.leggi(), [1, 2, 3]);
    expect(letture, 1);
    final inMemoria = FileDaCaricare(nome: 'x.pdf', bytes: Uint8List.fromList([9, 9]), mimeType: 'application/pdf');
    expect([inMemoria.dimensione, await inMemoria.leggi()], [2, [9, 9]]);
  });

  test('nome del file da un percorso', () {
    expect(nomeDaPercorso('Casa/Bollette/enel.pdf'), 'enel.pdf');
    expect(nomeDaPercorso(r'C:\Utenti\Mario\enel.pdf'), 'enel.pdf');
    expect(nomeDaPercorso('enel.pdf'), 'enel.pdf');
  });
}
