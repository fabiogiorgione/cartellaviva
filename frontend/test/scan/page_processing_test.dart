import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/scan/page_processing.dart';
import 'package:image/image.dart' as img;

/// Pagina sintetica 600x400: carta che passa da chiara (230) a sinistra a molto
/// in ombra (70) a destra, con "righe di testo" (tratti al 45% della carta).
({Uint8List gray, int w, int h, bool Function(int x, int y) isText}) shadedPage() {
  const w = 600, h = 400;
  bool isText(int x, int y) => (y % 40 >= 18 && y % 40 < 24) && (x % 30 < 20) && x > 20 && x < w - 20;
  final gray = Uint8List(w * h);
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final paper = 230 - (160 * x / (w - 1));
      gray[y * w + x] = (isText(x, y) ? paper * 0.45 : paper).round();
    }
  }
  return (gray: gray, w: w, h: h, isText: isText);
}

double accuracy(Uint8List bw, int w, int h, bool Function(int, int) isText, {required int fromX, required int toX}) {
  var ok = 0, total = 0;
  for (var y = 0; y < h; y++) {
    for (var x = fromX; x < toX; x++) {
      total++;
      if ((bw[y * w + x] == 0) == isText(x, y)) {
        ok++;
      }
    }
  }
  return ok / total;
}

void main() {
  group('Binarizzazione adattiva (Sauvola)', () {
    test('sfondo bianco e testo nero anche nella zona in ombra', () {
      final page = shadedPage();
      final bw = sauvolaBinarize(page.gray, page.w, page.h);

      expect(bw.toSet(), {0, 255}, reason: 'solo due toni, non scala di grigi');
      expect(accuracy(bw, page.w, page.h, page.isText, fromX: 0, toX: 200), greaterThan(0.97), reason: 'zona chiara');
      expect(accuracy(bw, page.w, page.h, page.isText, fromX: 400, toX: 600), greaterThan(0.97), reason: 'zona in ombra');
    });

    test('una soglia unica fallisce dove quella adattiva funziona', () {
      final page = shadedPage();
      // Soglia globale a metà scala: la parte in ombra diventa tutta nera.
      final global = Uint8List.fromList(page.gray.map((v) => v > 128 ? 255 : 0).toList());
      expect(accuracy(global, page.w, page.h, page.isText, fromX: 450, toX: 600), lessThan(0.5));
      final adaptive = sauvolaBinarize(page.gray, page.w, page.h);
      expect(accuracy(adaptive, page.w, page.h, page.isText, fromX: 450, toX: 600), greaterThan(0.97));
    });
  });

  group('processPage', () {
    final photo = img.Image(width: 3000, height: 2000);
    img.fill(photo, color: img.ColorRgb8(200, 190, 170));
    final jpeg = img.encodeJpg(photo);

    test('colore: JPEG ridotto al lato lungo massimo', () {
      final page = processPage(PageProcessingRequest(jpeg, binarize: false));
      expect(page.mimeType, 'image/jpeg');
      expect((page.width, page.height), (2000, 1333));
      expect(img.decodeJpg(page.bytes), isNotNull);
    });

    test('bianco e nero: PNG a un canale con soli 0/255', () {
      final page = processPage(PageProcessingRequest(jpeg, binarize: true, maxSide: 800));
      expect(page.mimeType, 'image/png');
      final decoded = img.decodePng(page.bytes)!;
      expect(decoded.numChannels, 1);
      expect(decoded.width, 800);
      expect(decoded.getBytes().toSet().difference({0, 255}), isEmpty);
    });

    test('dati non immagine: errore esplicito', () {
      expect(() => processPage(PageProcessingRequest(Uint8List.fromList([1, 2, 3]), binarize: false)), throwsFormatException);
    });
  });
}
