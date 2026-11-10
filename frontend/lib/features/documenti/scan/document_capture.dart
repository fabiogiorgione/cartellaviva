import 'dart:typed_data';

import 'document_capture_stub.dart' if (dart.library.io) 'document_capture_io.dart' as impl;

enum CaptureSource { camera, gallery }

/// Errore della cattura da mostrare all'utente (permesso negato, scanner non disponibile...).
class CaptureException implements Exception {
  final String message;

  const CaptureException(this.message);

  @override
  String toString() => message;
}

/// Cattura di UNA pagina con rilevamento automatico dei bordi, ritaglio e
/// correzione prospettica, con possibilità di spostare gli angoli a mano.
/// Implementazione: cunning_document_scanner (VisionKit su iOS, ML Kit su Android).
abstract class DocumentCapture {
  factory DocumentCapture() => impl.createCapture();

  /// Disponibile solo nelle app Android e iOS.
  bool get isSupported;

  /// Immagine della pagina già ritagliata, o null se l'utente annulla.
  Future<Uint8List?> capture(CaptureSource source);
}
