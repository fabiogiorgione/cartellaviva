import 'dart:io';
import 'dart:typed_data';

import 'package:cunning_document_scanner/cunning_document_scanner.dart';
import 'package:cartellaviva/features/documenti/scan/document_capture.dart';

DocumentCapture createCapture() => _NativeCapture();

/// - una pagina per volta (`noOfPages: 1`): pagine, ordine e filtro li gestisce l'app;
/// - nessun filtro dello scanner (Android `base`, iOS `original` senza barra dei
///   filtri): la binarizzazione è la nostra (page_processing.dart), con anteprima;
/// - i file temporanei del plugin vengono letti in memoria e cancellati subito.
class _NativeCapture implements DocumentCapture {
  @override
  bool get isSupported => Platform.isAndroid || Platform.isIOS;

  @override
  Future<Uint8List?> capture(CaptureSource source) async {
    if (!isSupported) {
      throw const CaptureException('La scansione è disponibile nell\'app per Android e iOS.');
    }
    List<String>? paths;
    try {
      paths = await CunningDocumentScanner.getPictures(
        noOfPages: 1,
        scannerSource: source == CaptureSource.camera ? ScannerSource.camera : ScannerSource.gallery,
        androidScannerMode: AndroidScannerMode.base,
        iosScannerOptions: IosScannerOptions(
          imageFormat: IosImageFormat.jpg,
          jpgCompressionQuality: 0.9,
          defaultFilter: IosDocumentFilter.original,
          showFilterBar: false,
        ),
      );
      if (paths == null || paths.isEmpty) {
        return null;
      }
      return await File(paths.first).readAsBytes();
    } on CunningDocumentScannerException catch (e) {
      throw CaptureException(e.code == 'permission_denied'
          ? 'Permesso della fotocamera negato: abilitalo nelle impostazioni del telefono.'
          : 'Scansione non riuscita: ${e.message}');
    } finally {
      try {
        await CunningDocumentScanner.cleanCache();
      } catch (_) {
        // pulizia best effort: i file sono comunque nella cache privata dell'app
      }
    }
  }
}
