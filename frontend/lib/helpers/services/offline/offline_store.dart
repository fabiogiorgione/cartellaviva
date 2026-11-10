/// Documenti offline: implementazione mobile (dart:io) o stub per il web.
library;

export 'offline_store_stub.dart' if (dart.library.io) 'offline_store_io.dart';
