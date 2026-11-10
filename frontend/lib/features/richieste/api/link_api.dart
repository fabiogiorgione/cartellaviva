import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Sessione della risposta senza account, tenuta solo in memoria: serve anche
/// per collegare il link all'account creato subito dopo l'invio.
class LinkSessione {
  static String? token;
  static String? sessione;

  static void clear() {
    token = null;
    sessione = null;
  }
}

/// Risposta dal link sicuro (/api/link/{token}…): pubblica, con il codice email.
class LinkApi {
  static Dio get _dio => ApiClient.dio;

  static Options _opts({String? sessione, bool bytes = false}) => ApiClient.anonymous.copyWith(
        headers: {if (sessione != null) 'X-Link-Sessione': sessione},
        responseType: bytes ? ResponseType.bytes : null,
      );

  static Future<({String ente, String email})> apri(String token) => chiamaApi(() async {
        final data = (await _dio.get('/link/$token', options: _opts())).data as Map;
        return (ente: data['ente'] as String, email: data['email'] as String);
      });

  static Future<void> inviaCodice(String token) => chiamaApi(() async => _dio.post('/link/$token/codice', options: _opts()));

  static Future<String> verifica(String token, String codice) =>
      chiamaApi(() async => (await _dio.post('/link/$token/verifica', data: {'codice': codice.trim()}, options: _opts())).data['sessione'] as String);

  static Future<Map<String, dynamic>> richiesta(String token, String sessione) =>
      chiamaApi(() async => Map<String, dynamic>.from((await _dio.get('/link/$token/richiesta', options: _opts(sessione: sessione))).data as Map));

  static Future<Uint8List> allegato(String token, String sessione) =>
      chiamaApi(() async => Uint8List.fromList(((await _dio.get<List<int>>('/link/$token/allegato', options: _opts(sessione: sessione, bytes: true))).data) ?? const []));

  static Future<Map<String, dynamic>> rispondi(String token, String sessione, {Uint8List? bytes, String? fileName, String? mimeType, String? validoFino}) => chiamaApi(() async {
        final Object data = bytes == null
            ? {if (validoFino != null) 'validoFino': validoFino}
            : (FormData()
              ..files.add(MapEntry('file', MultipartFile.fromBytes(bytes, filename: fileName, contentType: DioMediaType.parse(mimeType ?? 'application/pdf'))))
              ..fields.addAll([if (validoFino != null) MapEntry('validoFino', validoFino)]));
        final response = await _dio.post('/link/$token/risposta', data: data, options: _opts(sessione: sessione));
        return Map<String, dynamic>.from(response.data as Map);
      });

  static Future<({String sha256, String dichiarazione})> codiceFirma(String token, String sessione) => chiamaApi(() async {
        final data = (await _dio.post('/link/$token/firma/codice', options: _opts(sessione: sessione))).data as Map;
        return (sha256: data['documentoSha256'] as String, dichiarazione: data['dichiarazione'] as String);
      });

  static Future<Map<String, dynamic>> firma(String token, String sessione, String codice) =>
      chiamaApi(() async => Map<String, dynamic>.from((await _dio.post('/link/$token/firma', data: {'codice': codice.trim(), 'accetto': true}, options: _opts(sessione: sessione))).data as Map));

  /// Verifica pubblica di un'attestazione di firma (codice stampato sul PDF).
  static Future<Map<String, dynamic>> verificaFirma(String codice) =>
      chiamaApi(() async => Map<String, dynamic>.from((await _dio.get('/verifica-firma/${codice.trim().toUpperCase()}', options: _opts())).data as Map));

  static Future<Map<String, dynamic>> rifiuta(String token, String sessione, String messaggio) =>
      chiamaApi(() async => Map<String, dynamic>.from((await _dio.post('/link/$token/rifiuta', data: {'messaggio': messaggio.trim()}, options: _opts(sessione: sessione))).data as Map));

  /// Con l'account (stessa email): collega il referente alla famiglia e vi copia le risposte passate.
  static Future<int> collega(String token, String sessione, {required String tenantId, String? personaId, String? nuovoNome, String? nuovoCognome}) => chiamaApi(() async {
        final response = await _dio.post('/link/$token/collega',
            data: {
              'tenantId': tenantId,
              if (personaId != null) 'personaId': personaId,
              if (personaId == null) 'nuovaPersona': {'nome': nuovoNome, 'cognome': nuovoCognome},
            },
            options: Options(headers: {'X-Link-Sessione': sessione}));
        return (response.data['risposte'] as num).toInt();
      });
}
