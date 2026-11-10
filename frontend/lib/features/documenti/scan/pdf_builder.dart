import 'dart:typed_data';

import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

/// Immagine di una pagina, nell'ordine in cui va nel PDF.
class PdfPageImage {
  final Uint8List bytes;
  final int width;
  final int height;

  const PdfPageImage(this.bytes, this.width, this.height);
}

/// Lato lungo della pagina PDF: quello di un A4 (297 mm), in punti.
const double _longSidePt = 841.89;

/// Un'unica pagina PDF per ogni immagine, nello stesso ordine, senza margini.
/// La pagina ha le proporzioni dell'immagine (niente bande bianche) con il
/// lato lungo di un A4, così stampa e visualizzazione restano naturali.
///
/// Funzione top-level: va eseguita fuori dal thread della UI (`compute`).
Future<Uint8List> buildPdf(List<PdfPageImage> pages, {bool compress = true}) async {
  if (pages.isEmpty) {
    throw ArgumentError('Nessuna pagina da inserire nel PDF.');
  }
  final doc = pw.Document(title: 'Scansione CartellaViva', creator: 'CartellaViva', compress: compress);
  for (final page in pages) {
    final scale = _longSidePt / (page.width >= page.height ? page.width : page.height);
    final format = PdfPageFormat(page.width * scale, page.height * scale, marginAll: 0);
    final image = pw.MemoryImage(page.bytes);
    doc.addPage(pw.Page(
      pageFormat: format,
      margin: pw.EdgeInsets.zero,
      build: (_) => pw.Image(image, fit: pw.BoxFit.fill),
    ));
  }
  return doc.save();
}
