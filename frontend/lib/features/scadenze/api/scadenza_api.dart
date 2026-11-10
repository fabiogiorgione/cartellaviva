import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart' show ScadenzaProposta;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';

/// Endpoint dello scadenziario.
class ScadenzaApi {
  static Dio get _dio => ApiClient.dio;

  static Future<List<Scadenza>> list(String tenantId, {DateTime? da, DateTime? a, String? stato, String? documentoId, String? membroId}) =>
      chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/scadenze', queryParameters: {
          if (da != null) 'da': formatIsoDate(da),
          if (a != null) 'a': formatIsoDate(a),
          if (stato != null) 'stato': stato,
          if (documentoId != null) 'documentoId': documentoId,
          if (membroId != null) 'membroId': membroId,
        });
        return (response.data as List).map((j) => Scadenza.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<Scadenza> create(String tenantId, ScadenzaInput input) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/scadenze', data: input.toJson());
        return Scadenza.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<Scadenza> update(String tenantId, String id, ScadenzaInput input) => chiamaApi(() async {
        final response = await _dio.patch('/tenants/$tenantId/scadenze/$id',
            data: input.toJson(), options: Options(contentType: 'application/merge-patch+json'));
        return Scadenza.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> delete(String tenantId, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/scadenze/$id');
      });

  /// Segna come fatta; restituisce la ricorrenza successiva, se creata.
  static Future<Scadenza?> fatta(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/scadenze/$id/fatta');
        final prossima = response.data['prossima'];
        return prossima is Map<String, dynamic> ? Scadenza.fromJson(prossima) : null;
      });

  /// Scadenze trovate nei documenti, ancora da confermare (vuota senza scadenza:manage).
  static Future<List<ScadenzaProposta>> proposte(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/scadenze-proposte');
        return (response.data as List).map((j) => ScadenzaProposta.fromJson(Map<String, dynamic>.from(j as Map))).toList();
      });

  static Future<void> ignoraProposta(String tenantId, String documentoId) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/documenti/$documentoId/scadenza-proposta/ignora');
      });
}
