import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/import/model/import_source.dart';

/// Sorgenti di import (Google Drive): richiedono import:manage.
class ImportApi {
  static Dio get _dio => ApiClient.dio;

  static Future<List<ImportSource>> list(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/import-sources');
        return (response.data as List).map((j) => ImportSource.fromJson(j as Map<String, dynamic>)).toList();
      });

  /// [cartella] è il link della cartella di Drive (o il suo id).
  static Future<ImportSource> create(String tenantId, {required String cartella, required String strategia}) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/import-sources', data: {'provider': 'google_drive', 'cartellaRadiceId': cartella.trim(), 'strategia': strategia});
        return ImportSource.fromJson(response.data as Map<String, dynamic>);
      });

  /// URL di Google da aprire nel browser; [ritorno] "app" rimanda all'app via deep link.
  static Future<Uri> connect(String tenantId, String sourceId, {required String ritorno}) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/import-sources/$sourceId/connect', data: {'ritorno': ritorno});
        return Uri.parse(response.data['authorizationUrl'] as String);
      });

  static Future<ImportSource> sync(String tenantId, String sourceId) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/import-sources/$sourceId/sync');
        return ImportSource.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> delete(String tenantId, String sourceId) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/import-sources/$sourceId');
      });
}
