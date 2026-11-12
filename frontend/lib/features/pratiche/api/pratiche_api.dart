import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Endpoint delle pratiche (fase 2a: motore comune e 730).
class PraticheApi {
  static Dio get _dio => ApiClient.dio;

  static Map<String, dynamic> _m(Object? data) => Map<String, dynamic>.from(data as Map);

  static Future<List<ModelloPratica>> modelli(String t) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/pratiche-modelli');
        return (r.data['modelli'] as List).map((j) => ModelloPratica.fromJson(_m(j))).toList();
      });

  static Future<({List<Pratica> pratiche, bool puoAvviare})> elenco(String t) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/pratiche');
        return (pratiche: (r.data['pratiche'] as List).map((j) => Pratica.fromJson(_m(j))).toList(), puoAvviare: r.data['puoAvviare'] == true);
      });

  static Future<Pratica> avvia(String t, {required String modello, required int anno, required List<String> persone, String analisiSu = 'server'}) => chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche', data: {'modello': modello, 'anno': anno, 'persone': persone, 'analisiSu': analisiSu});
        return Pratica.fromJson(_m(r.data));
      });

  static Future<Pratica> dettaglio(String t, String id) => chiamaApi(() async => Pratica.fromJson(_m((await _dio.get('/tenants/$t/pratiche/$id')).data)));

  static Future<Pratica> modifica(String t, String id, {String? stato, List<String>? persone, String? analisiSu}) => chiamaApi(() async {
        final r = await _dio.patch('/tenants/$t/pratiche/$id', data: {if (stato != null) 'stato': stato, if (persone != null) 'persone': persone, if (analisiSu != null) 'analisiSu': analisiSu});
        return Pratica.fromJson(_m(r.data));
      });

  static Future<void> elimina(String t, String id) => chiamaApi(() async => _dio.delete('/tenants/$t/pratiche/$id'));

  static Future<Pratica> consensoCifrati(String t, String id, bool consenso) => chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche/$id/consenso-cifrati', data: {'consenso': consenso});
        return Pratica.fromJson(_m(r.data));
      });

  static Future<int> analizza(String t, String id) => chiamaApi(() async => ((await _dio.post('/tenants/$t/pratiche/$id/analizza')).data['inCoda'] as num).toInt());

  /// "Non serve" per un requisito di una persona, o per un codice scoperto ([dettaglio]: IBAN, targa).
  static Future<Pratica> nonServe(String t, String id, {required String requisito, String? personaId, String? dettaglio, required bool nonServe}) => chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche/$id/non-serve', data: {'requisito': requisito, 'personaId': personaId, 'dettaglio': dettaglio, 'nonServe': nonServe});
        return Pratica.fromJson(_m(r.data));
      });

  static Future<VocePratica> creaVoce(String t, String id, {required String requisito, String? personaId, String? documentoId, required Map<String, String?> valori}) =>
      chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche/$id/voci', data: {'requisito': requisito, 'personaId': personaId, 'documentoId': documentoId, 'valori': valori});
        return VocePratica.fromJson(_m(r.data));
      });

  /// Correzione; con [conferma] la voce diventa anche confermata.
  static Future<VocePratica> modificaVoce(String t, String id, String voceId, {String? requisito, String? personaId, bool cambiaPersona = false, Map<String, String?>? valori, bool conferma = false}) =>
      chiamaApi(() async {
        final r = await _dio.patch('/tenants/$t/pratiche/$id/voci/$voceId', data: {
          if (requisito != null) 'requisito': requisito,
          if (cambiaPersona) 'personaId': personaId,
          if (valori != null) 'valori': valori,
          if (conferma) 'conferma': true,
        });
        return VocePratica.fromJson(_m(r.data));
      });

  /// [azione]: conferma, escludi, riproponi.
  static Future<VocePratica> statoVoce(String t, String id, String voceId, String azione) => chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche/$id/voci/$voceId/$azione');
        return VocePratica.fromJson(_m(r.data));
      });

  static Future<void> eliminaVoce(String t, String id, String voceId) => chiamaApi(() async => _dio.delete('/tenants/$t/pratiche/$id/voci/$voceId'));

  static Future<Uint8List> pacchetto(String t, String id) => chiamaApi(() async {
        final r = await _dio.get<List<int>>('/tenants/$t/pratiche/$id/pacchetto', options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(minutes: 5)));
        return Uint8List.fromList(r.data ?? const []);
      });

  static Future<({String id, String titolo})> archivia(String t, String id) => chiamaApi(() async {
        final d = (await _dio.post('/tenants/$t/pratiche/$id/archivia', options: Options(receiveTimeout: const Duration(minutes: 5)))).data['documento'] as Map;
        return (id: d['id'] as String, titolo: d['titolo'] as String);
      });

  /// Analisi sull'iPhone: i prossimi documenti (istruzioni, prompt, schema JSON).
  static Future<({List<LavoroTelefono> lavori, int restano})> lavori(String t, String id, {required int caratteri, int limite = 2}) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/pratiche/$id/da-analizzare', queryParameters: {'caratteri': caratteri, 'limite': limite});
        return (
          lavori: (r.data['lavori'] as List).map((j) => LavoroTelefono.fromJson(_m(j))).toList(),
          restano: (r.data['restano'] as num).toInt(),
        );
      });

  /// Risposta del modello del telefono (o l'errore) per un documento: il server la ricontrolla.
  static Future<int> risultato(String t, String id, {required String documentoId, String? risposta, String? errore, required String modello}) => chiamaApi(() async {
        final r = await _dio.post('/tenants/$t/pratiche/$id/risultati', data: {'documentoId': documentoId, 'risposta': risposta, 'errore': errore, 'modello': modello});
        return (r.data['voci'] as num).toInt();
      });

  /// Voci proposte di tutte le pratiche gestibili, per "Da confermare".
  static Future<List<VocePratica>> proposte(String t) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/pratiche-proposte');
        return (r.data['proposte'] as List).map((j) => VocePratica.fromJson(_m(j))).toList();
      });
}
