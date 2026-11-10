import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/scan/pdf_builder.dart';
import 'package:image/image.dart' as img;

PdfPageImage page(int w, int h) => PdfPageImage(img.encodeJpg(img.Image(width: w, height: h)), w, h);

void main() {
  test('una pagina PDF per immagine, nell\'ordine dato, con le proporzioni della foto', () async {
    final pdf = await buildPdf([page(1000, 1414), page(1414, 1000), page(500, 500)], compress: false);
    final text = latin1.decode(pdf);

    expect(text.startsWith('%PDF-'), isTrue);
    final boxes = RegExp(r'/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]')
        .allMatches(text)
        .map((m) => (double.parse(m.group(1)!).round(), double.parse(m.group(2)!).round()))
        .toList();
    // Lato lungo = A4 (842 pt): verticale, orizzontale, quadrata — nell'ordine delle foto.
    expect(boxes, [(595, 842), (842, 595), (842, 842)]);
  });

  test('nessuna pagina: errore', () {
    expect(() => buildPdf([]), throwsArgumentError);
  });
}
