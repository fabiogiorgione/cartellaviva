import 'dart:typed_data';

import 'file_output_stub.dart' if (dart.library.io) 'file_output_io.dart' as impl;

/// Consegna all'utente il file scaricato: sul web lo salva con il download del
/// browser, nelle app lo scrive nella cartella temporanea e lo apre con l'app
/// di sistema. Il contenuto non resta nella memoria persistente dell'app.
Future<void> saveOrOpenFile(Uint8List bytes, String fileName, String mimeType) => impl.saveOrOpen(bytes, fileName, mimeType);

/// Tipi di file accettati dal backend (UploadPolicy), per estensione.
const Map<String, String> acceptedFileTypes = {
  'pdf': 'application/pdf',
  'jpg': 'image/jpeg',
  'jpeg': 'image/jpeg',
  'png': 'image/png',
  'heic': 'image/heic',
  'heif': 'image/heif',
  'gif': 'image/gif',
  'webp': 'image/webp',
  // Documenti Office: si conservano così, l'anteprima è un PDF ricavato dal server.
  'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'pptx': 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'odt': 'application/vnd.oasis.opendocument.text',
  'ods': 'application/vnd.oasis.opendocument.spreadsheet',
  'odp': 'application/vnd.oasis.opendocument.presentation',
  'doc': 'application/msword',
  'xls': 'application/vnd.ms-excel',
  'ppt': 'application/vnd.ms-powerpoint',
  'rtf': 'text/rtf',
  // Pages, Numbers e Keynote: come Office (anteprima PDF ricavata dal server).
  'pages': 'application/vnd.apple.pages',
  'numbers': 'application/vnd.apple.numbers',
  'key': 'application/vnd.apple.keynote',
  'txt': 'text/plain',
  // Archivi: si conservano e si scaricano, il server non li apre.
  'zip': 'application/zip',
};

/// Per i messaggi "tipo non supportato".
const String formatiAccettati = 'PDF, foto (JPG, PNG, HEIC, GIF, WEBP), documenti Office (Word, Excel, PowerPoint, LibreOffice), Pages, Numbers, Keynote, TXT e ZIP';

/// MIME dal nome del file, o null se il tipo non è accettato.
String? mimeTypeFor(String fileName) {
  final dot = fileName.lastIndexOf('.');
  if (dot < 0) return null;
  return acceptedFileTypes[fileName.substring(dot + 1).toLowerCase()];
}
