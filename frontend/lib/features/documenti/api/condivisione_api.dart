import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Condivisione esterna vista da chi l'ha creata (mai link né codice).
class Condivisione {
  final String id;
  final String stato;
  final String? nota;
  final List<({String id, String titolo})> documenti;
  final DateTime scadeIl;
  final int maxDownload;
  final int download;
  final DateTime? primoAccessoIl;
  final String creataDa;

  Condivisione({
    required this.id,
    required this.stato,
    this.nota,
    this.documenti = const [],
    required this.scadeIl,
    this.maxDownload = 10,
    this.download = 0,
    this.primoAccessoIl,
    this.creataDa = '',
  });

  factory Condivisione.fromJson(Map<String, dynamic> json) => Condivisione(
        id: json['id'] as String,
        stato: json['stato'] as String,
        nota: json['nota'] as String?,
        documenti: (json['documenti'] as List? ?? []).whereType<Map>().map((d) => (id: d['id'] as String, titolo: d['titolo'] as String)).toList(),
        scadeIl: DateTime.parse(json['scadeIl'] as String).toLocal(),
        maxDownload: (json['maxDownload'] as num?)?.toInt() ?? 10,
        download: (json['download'] as num?)?.toInt() ?? 0,
        primoAccessoIl: json['primoAccessoIl'] is String ? DateTime.tryParse(json['primoAccessoIl'] as String)?.toLocal() : null,
        creataDa: (json['creataDa'] as String?) ?? '',
      );

  bool get attiva => stato == 'attiva';

  String get statoLabel => switch (stato) {
        'attiva' => 'Attiva',
        'scaduta' => 'Scaduta',
        'esaurita' => 'Download esauriti',
        'bloccata' => 'Bloccata (codici errati)',
        'revocata' => 'Revocata',
        _ => stato,
      };
}

/// Documento visto dal destinatario dopo il codice.
typedef DocumentoCondiviso = ({String id, String titolo, String nomeFile, String mimeType, int dimensioneBytes});

class CondivisioneApi {
  static Dio get _dio => ApiClient.dio;

  /// Restituisce link e codice: si vedono SOLO qui.
  static Future<({String url, String codice, Condivisione condivisione})> crea(String tenantId, {required List<String> documentiIds, required int ore, required int maxDownload, String? nota}) =>
      chiamaApi(() async {
        final r = await _dio.post('/tenants/$tenantId/condivisioni', data: {
          'documentiIds': documentiIds,
          'ore': ore,
          'maxDownload': maxDownload,
          if (nota != null && nota.trim().isNotEmpty) 'nota': nota.trim(),
        });
        final data = Map<String, dynamic>.from(r.data as Map);
        return (url: data['url'] as String, codice: data['codice'] as String, condivisione: Condivisione.fromJson(data));
      });

  static Future<List<Condivisione>> elenco(String tenantId) =>
      chiamaApi(() async => ((await _dio.get('/tenants/$tenantId/condivisioni')).data as List).map((e) => Condivisione.fromJson(Map<String, dynamic>.from(e as Map))).toList());

  static Future<void> revoca(String tenantId, String id) => chiamaApi(() => _dio.post('/tenants/$tenantId/condivisioni/$id/revoca'));

  // ---- Destinatario (pubbliche) ----------------------------------------------

  static Future<({String stato, DateTime scadeIl, int documenti, String condivisoDa})> anteprima(String token) => chiamaApi(() async {
        final d = (await _dio.get('/condivisioni/$token', options: ApiClient.anonymous)).data as Map;
        return (stato: d['stato'] as String, scadeIl: DateTime.parse(d['scadeIl'] as String).toLocal(), documenti: (d['documenti'] as num).toInt(), condivisoDa: d['condivisoDa'] as String);
      });

  static Future<({String sessione, String condivisoDa, int downloadRimasti, List<DocumentoCondiviso> documenti})> accedi(String token, String codice) => chiamaApi(() async {
        final d = (await _dio.post('/condivisioni/$token/accedi', data: {'codice': codice.trim()}, options: ApiClient.anonymous)).data as Map;
        return (
          sessione: d['sessione'] as String,
          condivisoDa: d['condivisoDa'] as String,
          downloadRimasti: (d['downloadRimasti'] as num).toInt(),
          documenti: (d['documenti'] as List)
              .whereType<Map>()
              .map((x) => (id: x['id'] as String, titolo: x['titolo'] as String, nomeFile: x['nomeFile'] as String, mimeType: x['mimeType'] as String, dimensioneBytes: (x['dimensioneBytes'] as num).toInt()))
              .toList(),
        );
      });

  static Future<Uint8List> scarica(String token, String sessione, String documentoId) => chiamaApi(() async {
        final r = await _dio.get<List<int>>('/condivisioni/$token/documenti/$documentoId/file',
            options: ApiClient.anonymous.copyWith(headers: {'X-Condivisione-Sessione': sessione}, responseType: ResponseType.bytes));
        return Uint8List.fromList(r.data ?? const []);
      });
}
