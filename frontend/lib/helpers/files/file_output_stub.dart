import 'dart:typed_data';

import 'package:universal_html/html.dart' as html;

/// Web: download tramite un link temporaneo a un Blob (il file non passa da altri server).
Future<void> saveOrOpen(Uint8List bytes, String fileName, String mimeType) async {
  final url = html.Url.createObjectUrlFromBlob(html.Blob([bytes], mimeType));
  final anchor = html.AnchorElement(href: url)
    ..download = fileName
    ..style.display = 'none';
  html.document.body?.append(anchor);
  anchor.click();
  anchor.remove();
  // Il browser ha già avviato il download: si può liberare l'URL.
  Future.delayed(const Duration(seconds: 30), () => html.Url.revokeObjectUrl(url));
}
