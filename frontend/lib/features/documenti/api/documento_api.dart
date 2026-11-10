import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/storage/token_storage.dart';
import 'package:cartellaviva/features/documenti/model/analisi_documenti.dart';
import 'package:cartellaviva/features/documenti/model/anteprima.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';
import 'package:cartellaviva/features/documenti/model/proposte_categoria.dart';
import 'package:cartellaviva/features/famiglia/model/persona.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatIsoDate;

/// Endpoint dei documenti, categorie e membri della famiglia (client HTTP unico: ApiClient).
class DocumentoApi {
  static Dio get _dio => ApiClient.dio;

  static Future<List<Categoria>> categorie(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/categorie');
        return (response.data as List).map((j) => Categoria.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<List<MembroFamiglia>> membriFamiglia(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/membri_famiglia');
        return (response.data as List).map((j) => MembroFamiglia.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<Documento> documento(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti/$id');
        return Documento.fromJson(response.data as Map<String, dynamic>);
      });

  static const int pageSize = 30;

  /// Una pagina dell'elenco (più recenti prima); [q] cerca nel titolo e nel testo letto.
  static Future<List<Documento>> list(String tenantId, {String? q, String? categoriaId, String? membroId, int page = 1, int perPage = pageSize}) =>
      chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti', queryParameters: {
          if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
          if (categoriaId != null) 'categoriaId': categoriaId,
          if (membroId != null) 'membroId': membroId,
          'page': page,
          'itemsPerPage': perPage,
        });
        return (response.data as List).map((j) => Documento.fromJson(j as Map<String, dynamic>)).toList();
      });

  /// Una pagina della lista con il totale (header X-Total-Count), per la paginazione.
  /// [ordina]: creato | titolo | data; [verso]: asc | desc.
  /// [perSignificato]: con una ricerca, i risultati vanno in ordine di pertinenza
  /// (anche senza le parole esatte) e l'ordinamento scelto non si applica.
  static Future<({List<Documento> righe, int totale})> pagina(String tenantId,
          {String? q,
          String? categoriaId,
          String? membroId,
          required int page,
          required int perPage,
          String ordina = 'creato',
          String verso = 'desc',
          bool perSignificato = false,
          Map<String, String> filtri = const {}}) =>
      chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti', queryParameters: {
          if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
          if (perSignificato && q != null && q.trim().isNotEmpty) 'modo': 'significato',
          if (categoriaId != null) 'categoriaId': categoriaId,
          if (membroId != null) 'membroId': membroId,
          ...filtri,
          'page': page,
          'itemsPerPage': perPage,
          'ordina': ordina,
          'verso': verso,
        });
        final righe = (response.data as List).map((j) => Documento.fromJson(j as Map<String, dynamic>)).toList();
        return (righe: righe, totale: int.tryParse(response.headers.value('x-total-count') ?? '') ?? righe.length);
      });

  /// Conteggi per filtro: stessa ricerca e stessi [filtri] della lista.
  static Future<Faccette> faccette(String tenantId, {String? q, bool perSignificato = false, Map<String, String> filtri = const {}}) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti/faccette', queryParameters: {
          if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
          if (perSignificato && q != null && q.trim().isNotEmpty) 'modo': 'significato',
          ...filtri,
        });
        return Faccette.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  /// Operazioni sui selezionati: azione "sposta" (con [categoriaId]) o "elimina".
  /// Chi non si può toccare viene saltato: {fatti, saltati: [{id, titolo, motivo}]}.
  static Future<({int fatti, List<({String? titolo, String motivo})> saltati})> gruppo(String tenantId, String azione, List<String> ids, {String? categoriaId}) =>
      chiamaApi(() async {
        final r = await _dio.post('/tenants/$tenantId/documenti/gruppo', data: {'azione': azione, 'ids': ids, if (categoriaId != null) 'categoriaId': categoriaId});
        final data = Map<String, dynamic>.from(r.data as Map);
        return (
          fatti: (data['fatti'] as num).toInt(),
          saltati: [for (final s in (data['saltati'] as List).cast<Map>()) (titolo: s['titolo'] as String?, motivo: s['motivo'] as String)],
        );
      });

  /// Proposta di categoria con l'AI per un documento (non lo cambia). [nuove]: le
  /// categorie nuove già proposte per gli altri documenti, da riusare se adatte;
  /// [escludi]: i documenti in revisione insieme, che non devono fare da esempio.
  /// Il modello locale può metterci minuti: attesa lunga.
  static Future<PropostaCategoria> propostaCategoria(String tenantId, String id, List<String> nuove, {List<String> escludi = const []}) => chiamaApi(() async {
        final r = await _dio.post(
          '/tenants/$tenantId/documenti/$id/proposta-categoria',
          data: {'nuove': nuove, 'escludi': escludi},
          options: Options(receiveTimeout: const Duration(minutes: 12)),
        );
        return PropostaCategoria.fromJson(Map<String, dynamic>.from(r.data as Map));
      });

  static Future<Documento> update(String tenantId, String id, {required String titolo, required String categoriaId, required List<String> membriIds, DateTime? dataDocumento}) =>
      chiamaApi(() async {
        final response = await _dio.patch(
          '/tenants/$tenantId/documenti/$id',
          data: {
            'titolo': titolo.trim(),
            'categoriaId': categoriaId,
            'membriIds': membriIds,
            'dataDocumento': dataDocumento == null ? null : formatIsoDate(dataDocumento),
          },
          options: Options(contentType: 'application/merge-patch+json'),
        );
        return Documento.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> delete(String tenantId, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/documenti/$id');
      });

  /// Contenuto del file (decifrato dal server se il documento è cifrato).
  static Future<Uint8List> download(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.get<List<int>>(
          '/tenants/$tenantId/documenti/$id/file',
          options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(minutes: 2)),
        );
        return Uint8List.fromList(response.data ?? const []);
      });

  /// Scheda Anteprima: l'originale (PDF, immagini) o la copia ricavata dal server (Office, HEIC).
  static Future<EsitoAnteprima> anteprima(String tenantId, String id) => leggiAnteprima(_dio, '/tenants/$tenantId/documenti/$id/anteprima');

  /// Rilegge il testo del documento (dopo un errore). Richiede documento:write.
  static Future<void> rileggiTesto(String tenantId, String id) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/documenti/$id/ocr');
      });

  /// Altri documenti simili (stesso riferimento o contenuto vicino); vuoto per i cifrati.
  static Future<List<DocumentoSimile>> simili(String tenantId, String id) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti/$id/simili');
        return (response.data as List).map((j) => DocumentoSimile.fromJson(Map<String, dynamic>.from(j as Map))).toList();
      });

  /// Rimette in coda l'analisi del testo (dati, tag, ricerca). Richiede documento:write.
  static Future<void> rianalizza(String tenantId, String id) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/documenti/$id/indice');
      });

  /// Avanzamento dell'analisi AI dei documenti della famiglia. Richiede documento:read.
  static Future<AnalisiDocumenti> analisi(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/documenti/analisi');
        return AnalisiDocumenti.fromJson(Map<String, dynamic>.from(response.data as Map));
      });

  /// Rimette in coda i documenti non ancora analizzati; restituisce quanti. Richiede documento:write.
  static Future<int> analizzaMancanti(String tenantId) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/documenti/analisi');
        return ((response.data as Map)['inCoda'] as num?)?.toInt() ?? 0;
      });

  /// Rigenera la copia per l'anteprima (dopo un errore). Richiede documento:write.
  static Future<void> rigeneraAnteprima(String tenantId, String id) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/documenti/$id/anteprima');
      });

  static Future<Categoria> saveCategoria(String tenantId, {String? id, required String nome, required String icona, required String colore, required bool isSensibile}) =>
      chiamaApi(() async {
        final body = {'nome': nome.trim(), 'icona': icona, 'colore': colore, 'isSensibile': isSensibile};
        final response = id == null
            ? await _dio.post('/tenants/$tenantId/categorie', data: body)
            : await _dio.patch('/tenants/$tenantId/categorie/$id', data: body, options: Options(contentType: 'application/merge-patch+json'));
        return Categoria.fromJson(response.data as Map<String, dynamic>);
      });

  /// Con documenti serve [spostaIn]: la categoria in cui finiscono documenti e scadenze.
  static Future<void> deleteCategoria(String tenantId, String id, {String? spostaIn}) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/categorie/$id', queryParameters: {if (spostaIn != null) 'spostaIn': spostaIn});
      });

  static Future<List<Persona>> persone(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/membri_famiglia');
        return (response.data as List).map((j) => Persona.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<Persona> savePersona(String tenantId, Persona persona) => chiamaApi(() async {
        final response = persona.id == null
            ? await _dio.post('/tenants/$tenantId/membri_famiglia', data: persona.toJson())
            : await _dio.patch('/tenants/$tenantId/membri_famiglia/${persona.id}', data: persona.toJson(), options: Options(contentType: 'application/merge-patch+json'));
        return Persona.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> deletePersona(String tenantId, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/membri_famiglia/$id');
      });

  /// Caricamento multipart di un file (es. il PDF della scansione).
  ///
  /// Il body multipart non si può reinviare: per questo la richiesta è marcata
  /// `retried` (l'interceptor di ApiClient non la ripete) e il rinnovo del token
  /// su 401 avviene qui, ricostruendo il FormData da capo.
  static Future<Documento> upload(
    String tenantId,
    Uint8List bytes, {
    required String fileName,
    required String categoriaId,
    List<String> membriIds = const [],
    String? titolo,
    String mimeType = 'application/pdf',
    void Function(int sent, int total)? onProgress,
    CancelToken? cancelToken,
  }) async {
    // Il tipo reale lo verifica comunque il server sui primi byte del file.
    final type = DioMediaType.parse(mimeType);
    Future<Response<dynamic>> send() => _dio.post(
          '/tenants/$tenantId/documenti',
          data: FormData()
            ..files.add(MapEntry('file', MultipartFile.fromBytes(bytes, filename: fileName, contentType: type)))
            ..fields.add(MapEntry('categoriaId', categoriaId))
            ..fields.addAll(membriIds.map((id) => MapEntry('membriIds[]', id)))
            ..fields.addAll([if (titolo != null && titolo.trim().isNotEmpty) MapEntry('titolo', titolo.trim())]),
          options: Options(
            contentType: Headers.multipartFormDataContentType,
            sendTimeout: const Duration(minutes: 5),
            receiveTimeout: const Duration(minutes: 2),
            extra: {'retried': true},
          ),
          onSendProgress: onProgress,
          cancelToken: cancelToken,
        );

    try {
      try {
        return Documento.fromJson((await send()).data as Map<String, dynamic>);
      } on DioException catch (e) {
        if (e.response?.statusCode != 401 || !await ApiClient.refreshTokens()) {
          if (e.response?.statusCode == 401 && !TokenStorage.hasSession) {
            await ApiClient.onSessionExpired?.call();
          }
          rethrow;
        }
        return Documento.fromJson((await send()).data as Map<String, dynamic>);
      }
    } on DioException catch (e) {
      if (CancelToken.isCancel(e)) {
        throw ApiException('Caricamento annullato.');
      }
      throw ApiException.fromDio(e);
    }
  }
}
