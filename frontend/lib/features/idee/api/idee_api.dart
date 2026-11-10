import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/idee/model/idea.dart';

/// Versione dell'app inviata con le idee (flutter build --dart-define=APP_VERSION=...).
const String appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '0.1.0');

/// web | android | ios | windows | macos | linux
String get piattaformaCorrente => kIsWeb ? 'web' : defaultTargetPlatform.name.toLowerCase();

/// Portale delle idee: invio e "le mie idee" (utente), gestione (portale).
class IdeeApi {
  static Dio get _dio => ApiClient.dio;

  static Future<IdeaMia> invia({required String tipo, String? area, required String testo, required bool contattabile, String? tenantId}) => chiamaApi(() async {
        final response = await _dio.post('/idee', data: {
          'tipo': tipo,
          if (area != null) 'area': area,
          'testo': testo.trim(),
          'contattabile': contattabile,
          if (tenantId != null) 'tenantId': tenantId,
          'piattaforma': piattaformaCorrente,
          'versioneApp': appVersion,
        });
        return IdeaMia.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  static Future<List<IdeaMia>> mie() => chiamaApi(() async =>
      ((await _dio.get('/idee')).data as List).map((e) => IdeaMia.fromJson(Map<String, dynamic>.from(e as Map))).toList());

  static Future<List<IdeaPortale>> elenco({String? stato, String? tipo, String? area, String? spazio, String? q}) => chiamaApi(() async {
        final response = await _dio.get('/admin/idee', queryParameters: {
          if (stato != null) 'stato': stato,
          if (tipo != null) 'tipo': tipo,
          if (area != null) 'area': area,
          if (spazio != null) 'spazio': spazio,
          if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
        });
        return (response.data as List).map((e) => IdeaPortale.fromJson(Map<String, dynamic>.from(e as Map))).toList();
      });

  static Future<IdeaPortale> dettaglio(String id) => chiamaApi(() async => IdeaPortale.fromJson(Map<String, dynamic>.from((await _dio.get('/admin/idee/$id')).data as Map)));

  /// Solo le chiavi passate vengono modificate ('' svuota risposta e nota).
  static Future<IdeaPortale> aggiorna(String id, {String? stato, String? rispostaPubblica, String? notaInterna}) => chiamaApi(() async {
        final response = await _dio.patch('/admin/idee/$id', data: {
          if (stato != null) 'stato': stato,
          if (rispostaPubblica != null) 'rispostaPubblica': rispostaPubblica,
          if (notaInterna != null) 'notaInterna': notaInterna,
        });
        return IdeaPortale.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  /// principaleId null = separa un duplicato.
  static Future<void> unisci(String id, String? principaleId) => chiamaApi(() => _dio.post('/admin/idee/$id/unisci', data: {'principaleId': principaleId}));

  static Future<void> elimina(String id) => chiamaApi(() => _dio.delete('/admin/idee/$id'));

  static Future<Uint8List> esporta() => chiamaApi(() async =>
      Uint8List.fromList((await _dio.get<List<int>>('/admin/idee-export', options: Options(responseType: ResponseType.bytes))).data ?? const []));
}
