import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';

/// Richieste degli enti e collegamenti, lato famiglia.
class RichiesteApi {
  static Dio get _dio => ApiClient.dio;

  /// [stato]: da_fare | in_verifica | concluse (null = tutte).
  static Future<List<RichiestaFamiglia>> list(String tenantId, {String? stato}) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/richieste', queryParameters: {if (stato != null) 'stato': stato});
        return (response.data as List).map((j) => RichiestaFamiglia.fromJson(j as Map<String, dynamic>)).toList();
      });

  /// Il dettaglio segna la richiesta come "vista" per l'ente.
  static Future<RichiestaFamiglia> get(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/richieste/$id');
        return RichiestaFamiglia.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<Uint8List> allegato(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.get<List<int>>('/tenants/$tenantId/richieste/$id/allegato', options: Options(responseType: ResponseType.bytes));
        return Uint8List.fromList(response.data ?? const []);
      });

  /// Risposta con un file (scansione o PDF già pronto).
  static Future<RichiestaFamiglia> rispondiConFile(String tenantId, String id, Uint8List bytes,
          {required String fileName, required String mimeType, required List<String> firmatari, String? validoFino, void Function(int, int)? onProgress}) =>
      chiamaApi(() async {
        final response = await _dio.post(
          '/tenants/$tenantId/richieste/$id/risposta',
          data: FormData()
            ..files.add(MapEntry('file', MultipartFile.fromBytes(bytes, filename: fileName, contentType: DioMediaType.parse(mimeType))))
            ..fields.addAll(firmatari.map((f) => MapEntry('firmatari[]', f)))
            ..fields.addAll([if (validoFino != null) MapEntry('validoFino', validoFino)]),
          options: Options(contentType: Headers.multipartFormDataContentType, sendTimeout: const Duration(minutes: 5), extra: {'retried': true}),
          onSendProgress: onProgress,
        );
        return RichiestaFamiglia.fromJson(response.data as Map<String, dynamic>);
      });

  /// Invio di un documento già nell'archivio, oppure presa visione (senza documento).
  static Future<RichiestaFamiglia> rispondi(String tenantId, String id, {required List<String> firmatari, String? documentoId, String? validoFino}) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/richieste/$id/risposta',
            data: {'firmatari': firmatari, if (documentoId != null) 'documentoId': documentoId, if (validoFino != null) 'validoFino': validoFino});
        return RichiestaFamiglia.fromJson(response.data as Map<String, dynamic>);
      });

  /// Firma con codice: invia il codice all'email del mio referente. Restituisce impronta e dichiarazione.
  static Future<({String sha256, String dichiarazione})> codiceFirma(String tenantId, String id) => chiamaApi(() async {
        final data = (await _dio.post('/tenants/$tenantId/richieste/$id/firma/codice')).data as Map;
        return (sha256: data['documentoSha256'] as String, dichiarazione: data['dichiarazione'] as String);
      });

  static Future<RichiestaFamiglia> firma(String tenantId, String id, String codice) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/richieste/$id/firma', data: {'codice': codice.trim(), 'accetto': true});
        return RichiestaFamiglia.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<RichiestaFamiglia> rifiuta(String tenantId, String id, String messaggio) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/richieste/$id/rifiuta', data: {'messaggio': messaggio.trim()});
        return RichiestaFamiglia.fromJson(response.data as Map<String, dynamic>);
      });

  // ---- Collegamenti --------------------------------------------------------

  static Future<List<Collegamento>> collegamenti(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/collegamenti');
        return (response.data as List).map((j) => Collegamento.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<void> revocaCollegamento(String tenantId, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/collegamenti/$id');
      });

  /// Pubblico: il token dell'invito fa da credenziale.
  static Future<InvitoEnte> anteprimaInvito(String token) => chiamaApi(() async {
        final response = await _dio.get('/collegamenti/anteprima', queryParameters: {'token': token}, options: ApiClient.anonymous);
        return InvitoEnte.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> accettaInvito(String token, {required String tenantId, String? personaId, String? nuovoNome, String? nuovoCognome}) => chiamaApi(() async {
        await _dio.post('/collegamenti/accetta', data: {
          'token': token,
          'tenantId': tenantId,
          if (personaId != null) 'personaId': personaId,
          if (personaId == null) 'nuovaPersona': {'nome': nuovoNome, 'cognome': nuovoCognome},
        });
      });

  static Future<void> rifiutaInvito(String token, {required bool nonSonoIo}) => chiamaApi(() async {
        await _dio.post('/collegamenti/rifiuta', data: {'token': token, 'nonSonoIo': nonSonoIo}, options: ApiClient.anonymous);
      });
}
