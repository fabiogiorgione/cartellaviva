import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Esito della richiesta di anteprima di un documento (o di una risposta all'ente).
sealed class EsitoAnteprima {
  const EsitoAnteprima();
}

/// Contenuto da mostrare: PDF o immagine (per un DOCX è il PDF ricavato dal server).
class AnteprimaPronta extends EsitoAnteprima {
  final Uint8List bytes;
  final String mime;

  const AnteprimaPronta(this.bytes, this.mime);

  bool get isPdf => mime == 'application/pdf';

  bool get isImmagine => mime.startsWith('image/');

  bool get isTesto => mime == 'text/plain';

  /// Il testo di un TXT: il server lo manda in UTF-8; se non lo è, Latin-1.
  String get testo {
    try {
      return utf8.decode(bytes);
    } on FormatException {
      return latin1.decode(bytes);
    }
  }
}

/// Il server sta ancora preparando la copia (conversione in coda).
class AnteprimaInPreparazione extends EsitoAnteprima {
  const AnteprimaInPreparazione();
}

/// Copia non disponibile: il motivo arriva dal server (es. convertitore non configurato).
class AnteprimaNonDisponibile extends EsitoAnteprima {
  final String motivo;

  const AnteprimaNonDisponibile(this.motivo);
}

/// GET di un'anteprima: 200 = file, 202 = in preparazione, 409 = non disponibile.
Future<EsitoAnteprima> leggiAnteprima(Dio dio, String path) async {
  try {
    final r = await dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(minutes: 2)));
    if (r.statusCode == 202) return const AnteprimaInPreparazione();
    final tipo = (r.headers.value('content-type') ?? 'application/octet-stream').split(';').first.trim();
    return AnteprimaPronta(Uint8List.fromList(r.data ?? const []), tipo);
  } on DioException catch (e) {
    if (e.response?.statusCode == 409) {
      return AnteprimaNonDisponibile(_motivo(e.response?.data) ?? 'Anteprima non disponibile per questo file.');
    }
    throw ApiException.fromDio(e);
  }
}

String? _motivo(Object? data) {
  try {
    final testo = data is List<int> ? utf8.decode(data) : data?.toString();
    final json = testo == null ? null : jsonDecode(testo);
    return json is Map ? json['anteprimaErrore'] as String? : null;
  } catch (_) {
    return null;
  }
}
