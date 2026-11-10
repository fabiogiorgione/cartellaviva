import 'dart:typed_data';

import 'package:cartellaviva/features/documenti/scan/document_capture.dart';

DocumentCapture createCapture() => _UnsupportedCapture();

class _UnsupportedCapture implements DocumentCapture {
  @override
  bool get isSupported => false;

  @override
  Future<Uint8List?> capture(CaptureSource source) async =>
      throw const CaptureException('La scansione è disponibile nell\'app per Android e iOS.');
}
