import 'dart:math' as math;
import 'dart:typed_data';

import 'package:image/image.dart' as img;

/// Pagina pronta per il PDF: immagine codificata più dimensioni in pixel.
class ProcessedPage {
  final Uint8List bytes;

  /// 'image/jpeg' (colore) oppure 'image/png' (bianco e nero).
  final String mimeType;
  final int width;
  final int height;

  const ProcessedPage({required this.bytes, required this.mimeType, required this.width, required this.height});

  String get extension => mimeType == 'image/png' ? 'png' : 'jpg';
}

/// Richiesta per [processPage], passabile a un isolate con `compute`.
class PageProcessingRequest {
  final Uint8List source;
  final bool binarize;
  final int maxSide;

  const PageProcessingRequest(this.source, {required this.binarize, this.maxSide = 2000});
}

/// Elabora una pagina già ritagliata dallo scanner:
/// - applica l'orientamento EXIF e riduce il lato lungo a [PageProcessingRequest.maxSide]
///   (2000 px ≈ 240 dpi su un A4: leggibile e adatto all'OCR del backend);
/// - colore: JPEG qualità 85;
/// - bianco e nero: binarizzazione adattiva ([sauvolaBinarize]) salvata in PNG,
///   che per un'immagine a due toni pesa molto meno di un JPEG.
///
/// Funzione top-level: va eseguita fuori dal thread della UI (`compute`).
ProcessedPage processPage(PageProcessingRequest request) {
  img.Image? image;
  try {
    image = img.decodeImage(request.source);
  } catch (_) {
    image = null; // alcuni decoder lanciano eccezioni proprie su dati non validi
  }
  if (image == null) {
    throw const FormatException('Immagine non leggibile.');
  }
  image = img.bakeOrientation(image);
  final longSide = math.max(image.width, image.height);
  if (longSide > request.maxSide) {
    image = image.width >= image.height
        ? img.copyResize(image, width: request.maxSide, interpolation: img.Interpolation.average)
        : img.copyResize(image, height: request.maxSide, interpolation: img.Interpolation.average);
  }

  if (!request.binarize) {
    return ProcessedPage(bytes: img.encodeJpg(image, quality: 85), mimeType: 'image/jpeg', width: image.width, height: image.height);
  }

  final gray = luminance(image);
  final bw = sauvolaBinarize(gray, image.width, image.height);
  final out = img.Image.fromBytes(width: image.width, height: image.height, bytes: bw.buffer, numChannels: 1);
  return ProcessedPage(bytes: img.encodePng(out, level: 6), mimeType: 'image/png', width: image.width, height: image.height);
}

/// Luminanza (Rec. 601) di ogni pixel, 0 = nero, 255 = bianco.
Uint8List luminance(img.Image image) {
  final rgb = image.convert(format: img.Format.uint8, numChannels: 3).getBytes(order: img.ChannelOrder.rgb);
  final out = Uint8List(image.width * image.height);
  for (var i = 0, p = 0; i < out.length; i++, p += 3) {
    out[i] = (rgb[p] * 299 + rgb[p + 1] * 587 + rgb[p + 2] * 114) ~/ 1000;
  }
  return out;
}

/// Binarizzazione adattiva di Sauvola: per ogni pixel la soglia dipende da
/// media `m` e deviazione standard `s` della finestra intorno al pixel:
///
///     T = m · (1 + k · (s / R − 1))
///
/// A differenza di una soglia unica (o di una semplice scala di grigi) regge
/// ombre, illuminazione non uniforme e carta ingiallita: lo sfondo diventa
/// bianco anche dove la foto è scura, il testo resta nero.
/// Media e varianza della finestra si calcolano in O(1) con immagini integrali.
///
/// [window] lato della finestra in pixel (default: 1/24 del lato corto, min 15).
Uint8List sauvolaBinarize(Uint8List gray, int width, int height, {int? window, double k = 0.25, double r = 128}) {
  final w1 = width + 1;
  // Somme fino a 255 · 4M pixel: stanno in un Uint32; i quadrati richiedono un double.
  final sum = Uint32List(w1 * (height + 1));
  final sumSq = Float64List(w1 * (height + 1));
  for (var y = 0; y < height; y++) {
    var rowSum = 0;
    var rowSq = 0.0;
    final row = y * width;
    final above = y * w1;
    final current = (y + 1) * w1;
    for (var x = 0; x < width; x++) {
      final v = gray[row + x];
      rowSum += v;
      rowSq += v * v;
      sum[current + x + 1] = sum[above + x + 1] + rowSum;
      sumSq[current + x + 1] = sumSq[above + x + 1] + rowSq;
    }
  }

  final half = math.max(7, (window ?? math.min(width, height) ~/ 24) ~/ 2);
  final out = Uint8List(width * height);
  for (var y = 0; y < height; y++) {
    final y0 = math.max(0, y - half);
    final y1 = math.min(height, y + half + 1);
    for (var x = 0; x < width; x++) {
      final x0 = math.max(0, x - half);
      final x1 = math.min(width, x + half + 1);
      final n = (x1 - x0) * (y1 - y0);
      final a = y0 * w1 + x0, b = y0 * w1 + x1, c = y1 * w1 + x0, d = y1 * w1 + x1;
      final mean = (sum[d] - sum[b] - sum[c] + sum[a]) / n;
      final variance = (sumSq[d] - sumSq[b] - sumSq[c] + sumSq[a]) / n - mean * mean;
      final threshold = mean * (1 + k * (math.sqrt(math.max(0.0, variance)) / r - 1));
      out[y * width + x] = gray[y * width + x] > threshold ? 255 : 0;
    }
  }
  return out;
}
