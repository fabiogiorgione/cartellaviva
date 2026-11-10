import 'dart:io';
import 'dart:typed_data';

import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// App: file temporaneo (cancellato dal sistema) aperto con l'app predefinita.
Future<void> saveOrOpen(Uint8List bytes, String fileName, String mimeType) async {
  final dir = Directory('${(await getTemporaryDirectory()).path}/documenti_aperti');
  if (dir.existsSync()) {
    // Si tiene solo l'ultimo file aperto: i documenti in chiaro non si accumulano.
    dir.deleteSync(recursive: true);
  }
  dir.createSync(recursive: true);
  final safe = fileName.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
  final file = File('${dir.path}/$safe');
  await file.writeAsBytes(bytes, flush: true);
  final result = await OpenFilex.open(file.path, type: mimeType);
  if (result.type != ResultType.done) {
    throw Exception(result.message.isEmpty ? 'Nessuna app disponibile per aprire il file.' : result.message);
  }
}
