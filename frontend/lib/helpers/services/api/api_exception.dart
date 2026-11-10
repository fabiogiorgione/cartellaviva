import 'package:dio/dio.dart';

/// Errore restituito dall'API, normalizzato dai tre formati possibili:
/// - problem+json di API Platform / Symfony ({title, detail, violations})
/// - errori di Lexik JWT ({code, message})
/// - assenza di risposta (rete, CORS, server spento)
class ApiException implements Exception {
  final int? statusCode;
  final String message;

  /// Errori di validazione per campo (propertyPath -> messaggio), da passare
  /// a ModuloValidato.addErrors().
  final Map<String, String> fieldErrors;

  /// Secondi indicati dal server con Retry-After (429, 503): dopo quanto riprovare.
  final int? riprovaTra;

  ApiException(this.message, {this.statusCode, this.fieldErrors = const {}, this.riprovaTra});

  bool get isUnauthorized => statusCode == 401;

  bool get isForbidden => statusCode == 403;

  bool get isConflict => statusCode == 409;

  factory ApiException.fromDio(DioException error) {
    final response = error.response;
    if (response == null) {
      return ApiException('Server non raggiungibile. Controlla la connessione e riprova.');
    }
    return ApiException.fromResponse(response.statusCode, response.data, riprovaTra: int.tryParse(response.headers.value('retry-after') ?? ''));
  }

  factory ApiException.fromResponse(int? statusCode, dynamic data, {int? riprovaTra}) {
    String message = switch (statusCode) {
      401 => 'Sessione non valida, effettua di nuovo l\'accesso.',
      403 => 'Non hai i permessi per questa operazione.',
      404 => 'Elemento non trovato.',
      429 => 'Troppi tentativi, riprova tra qualche minuto.',
      _ => 'Errore imprevisto ($statusCode).',
    };
    final fieldErrors = <String, String>{};

    if (data is Map) {
      final violations = data['violations'];
      if (violations is List) {
        for (final violation in violations) {
          if (violation is Map && violation['propertyPath'] != null) {
            fieldErrors[violation['propertyPath'].toString()] = (violation['message'] ?? violation['title'] ?? '').toString();
          }
        }
      }
      final detail = data['detail'] ?? data['message'];
      if (detail is String && detail.isNotEmpty && fieldErrors.isEmpty) {
        message = detail;
      } else if (fieldErrors.isNotEmpty) {
        message = 'Controlla i campi evidenziati.';
      }
    }

    return ApiException(message, statusCode: statusCode, fieldErrors: fieldErrors, riprovaTra: riprovaTra);
  }

  @override
  String toString() => message;
}

/// Esegue una chiamata all'API: un errore di rete o una risposta di errore
/// (DioException) diventa un ApiException con il messaggio per l'utente.
Future<T> chiamaApi<T>(Future<T> Function() call) async {
  try {
    return await call();
  } on DioException catch (e) {
    throw ApiException.fromDio(e);
  }
}
