import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatIsoDate;

/// Endpoint dei pagamenti (Pratiche, fase 1).
class PagamentiApi {
  static Dio get _dio => ApiClient.dio;

  /// [stato]: `da_pagare` o `pagato`.
  static Future<ElencoPagamenti> list(String tenantId, {String? stato}) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/pagamenti', queryParameters: {if (stato != null) 'stato': stato});
        return ElencoPagamenti.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  static Future<Pagamento> create(String tenantId, PagamentoInput input) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/pagamenti', data: input.toJson());
        return Pagamento.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  static Future<Pagamento> update(String tenantId, String id, PagamentoInput input) => chiamaApi(() async {
        final response = await _dio.patch('/tenants/$tenantId/pagamenti/$id', data: input.toJson(perModifica: true));
        return Pagamento.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  /// Segna come pagato (data di oggi se manca) con la ricevuta facoltativa.
  static Future<Pagamento> pagato(String tenantId, String id, {DateTime? pagatoIl, String? ricevutaDocumentoId}) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/pagamenti/$id/pagato', data: {
          if (pagatoIl != null) 'pagatoIl': formatIsoDate(pagatoIl),
          if (ricevutaDocumentoId != null) 'ricevutaDocumentoId': ricevutaDocumentoId,
        });
        return Pagamento.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  static Future<Pagamento> daPagare(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/pagamenti/$id/da-pagare');
        return Pagamento.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  static Future<void> delete(String tenantId, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/pagamenti/$id');
      });

  /// Avvisi di pagamento trovati nei documenti, ancora da confermare.
  static Future<List<PropostaPagamento>> proposte(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/pagamenti-proposte');
        return (response.data['proposte'] as List? ?? []).map((j) => PropostaPagamento.fromJson(Map<String, dynamic>.from(j as Map))).toList();
      });

  static Future<void> ignoraProposta(String tenantId, String documentoId) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/documenti/$documentoId/pagamento-proposta/ignora');
      });
}
