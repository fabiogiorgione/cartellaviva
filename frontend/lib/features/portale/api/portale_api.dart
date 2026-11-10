import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// API del portale. Le risposte sono metadati: si usano come mappe nelle schermate.
class PortaleApi {
  static Dio get _dio => ApiClient.dio;

  static Future<Map<String, dynamic>> salute() => _map(() => _dio.get('/admin/salute'));

  static Future<List<Map<String, dynamic>>> tenants({String? tipo, String? stato, String? q}) =>
      _list(() => _dio.get('/admin/tenants', queryParameters: {if (tipo != null) 'tipo': tipo, if (stato != null) 'stato': stato, if (q != null && q.isNotEmpty) 'q': q}));

  static Future<Map<String, dynamic>> tenant(String id) => _map(() => _dio.get('/admin/tenants/$id'));

  static Future<Map<String, dynamic>> nuovoProprietario(String tenantId, {required String membershipId, required String documento, required String come, String? nota}) =>
      _map(() => _dio.post('/admin/tenants/$tenantId/nuovo-proprietario', data: {'membershipId': membershipId, 'documento': documento, 'come': come, 'nota': nota}));

  static Future<List<Map<String, dynamic>>> utenti(String q) => _list(() => _dio.get('/admin/utenti', queryParameters: {'q': q}));

  static Future<Map<String, dynamic>> utente(String id) => _map(() => _dio.get('/admin/utenti/$id'));

  static Future<Map<String, dynamic>> statoUtente(String id, {required bool attivo, String? motivo}) =>
      _map(() => _dio.post('/admin/utenti/$id/stato', data: {'attivo': attivo, 'motivo': motivo}));

  static Future<List<Map<String, dynamic>>> team() => _list(() => _dio.get('/admin/team'));

  static Future<Map<String, dynamic>> ruolo(String userId, String? ruolo) => _map(() => _dio.put('/admin/utenti/$userId/ruolo-piattaforma', data: {'ruolo': ruolo}));

  static Future<List<Map<String, dynamic>>> procedure({String? stato}) => _list(() => _dio.get('/admin/procedure', queryParameters: {if (stato != null) 'stato': stato}));

  static Future<Map<String, dynamic>> verificaIdentita(String id, {required bool verificata, String? documento, String? come, String? nota}) =>
      _map(() => _dio.post('/admin/procedure/$id/identita', data: {'esito': verificata ? 'verificata' : 'respinta', 'documento': documento, 'come': come, 'nota': nota}));

  static Future<Map<String, dynamic>> annullaProcedura(String id, {String? nota}) => _map(() => _dio.post('/admin/procedure/$id/annulla', data: {'nota': nota}));

  static Future<List<Map<String, dynamic>>> registro({String? outcome, String? action, String? before}) => _list(() => _dio.get('/admin/audit_logs', queryParameters: {
        'limit': 100,
        if (outcome != null) 'outcome': outcome,
        if (action != null && action.isNotEmpty) 'action': action,
        if (before != null) 'before': before,
      }));

  // ---- Gestione manuale (solo superadmin) -----------------------------------

  /// [proprietario] e ogni membro: {email, nome, cognome}; i membri hanno anche "ruolo" (slug).
  static Future<Map<String, dynamic>> creaFamiglia({required String nome, required Map<String, String> proprietario, List<Map<String, String>> membri = const []}) =>
      _map(() => _dio.post('/admin/famiglie', data: {'nome': nome, 'proprietario': proprietario, 'membri': membri}));

  static Future<Map<String, dynamic>> creaEnte({required Map<String, String?> ente, required String notaVerifica, required Map<String, String> proprietario, List<Map<String, String>> staff = const []}) =>
      _map(() => _dio.post('/admin/enti', data: {'ente': ente, 'notaVerifica': notaVerifica, 'proprietario': proprietario, 'staff': staff}));

  static Future<List<Map<String, dynamic>>> ruoliTenant(String tenantId) => _list(() => _dio.get('/admin/tenants/$tenantId/ruoli'));

  static Future<Map<String, dynamic>> aggiungiMembro(String tenantId, {required String email, String? nome, String? cognome, required String ruolo}) =>
      _map(() => _dio.post('/admin/tenants/$tenantId/membri', data: {'email': email, 'nome': nome, 'cognome': cognome, 'ruolo': ruolo}));

  static Future<Map<String, dynamic>> modificaMembro(String tenantId, String membershipId, {String? ruolo, bool? attivo}) =>
      _map(() => _dio.put('/admin/tenants/$tenantId/membri/$membershipId', data: {if (ruolo != null) 'ruolo': ruolo, if (attivo != null) 'attivo': attivo}));

  static Future<void> rimuoviMembro(String tenantId, String membershipId) => _void(() => _dio.delete('/admin/tenants/$tenantId/membri/$membershipId'));

  static Future<Map<String, dynamic>> reinviaAttivazione(String userId) => _map(() => _dio.post('/admin/utenti/$userId/attivazione'));

  static Future<List<Map<String, dynamic>>> gruppiEnte(String enteId) => _list(() => _dio.get('/admin/enti/$enteId/gruppi'));

  static Future<Map<String, dynamic>> invitaFamiglia(String enteId, {required Map<String, String> iscritto, required Map<String, String?> referente, String? gruppoId}) =>
      _map(() => _dio.post('/admin/enti/$enteId/inviti-famiglia', data: {'iscritto': iscritto, 'referente': referente, if (gruppoId != null) 'gruppoId': gruppoId}));

  // ---- Liste con filtri, modifica ed eliminazione ------------------------------

  /// {totale, pagina, perPagina, righe}. I filtri vuoti non vengono inviati.
  static Future<Map<String, dynamic>> elencoTenant(Map<String, String?> filtri) => _map(() => _dio.get('/admin/tenants/elenco', queryParameters: _pieni(filtri)));

  static Future<Map<String, dynamic>> elencoUtenti(Map<String, String?> filtri) => _map(() => _dio.get('/admin/utenti/elenco', queryParameters: _pieni(filtri)));

  /// CSV dei risultati filtrati (solo superadmin).
  static Future<Uint8List> esporta(String cosa, Map<String, String?> filtri) async {
    try {
      final r = await _dio.get<List<int>>('/admin/$cosa/export', queryParameters: _pieni(filtri), options: Options(responseType: ResponseType.bytes));
      return Uint8List.fromList(r.data ?? const []);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  static Future<Map<String, dynamic>> modificaUtente(String id, {required String nome, required String cognome, required String email}) =>
      _map(() => _dio.put('/admin/utenti/$id', data: {'nome': nome, 'cognome': cognome, 'email': email}));

  static Future<void> eliminaUtente(String id) => _void(() => _dio.delete('/admin/utenti/$id'));

  /// azione: blocca | sblocca | attivazione. Risponde {fatti, saltati: [{id, email, motivo}]}.
  static Future<Map<String, dynamic>> bulkUtenti(String azione, List<String> ids) => _map(() => _dio.post('/admin/utenti/bulk', data: {'azione': azione, 'ids': ids}));

  static Future<Map<String, dynamic>> modificaTenant(String id, Map<String, String?> dati) => _map(() => _dio.put('/admin/tenants/$id', data: dati));

  static Future<Map<String, dynamic>> eliminaTenant(String id, String motivo) => _map(() => _dio.delete('/admin/tenants/$id', data: {'motivo': motivo}));

  static Future<Map<String, dynamic>> annullaEliminazione(String id) => _map(() => _dio.post('/admin/tenants/$id/annulla-eliminazione'));

  static Map<String, String> _pieni(Map<String, String?> filtri) => {
        for (final e in filtri.entries)
          if (e.value != null && e.value!.trim().isNotEmpty) e.key: e.value!.trim(),
      };

  static Future<void> _void(Future<Response<dynamic>> Function() call) async {
    try {
      await call();
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  static Future<Map<String, dynamic>> _map(Future<Response<dynamic>> Function() call) =>
      chiamaApi(() async => Map<String, dynamic>.from((await call()).data as Map));

  static Future<List<Map<String, dynamic>>> _list(Future<Response<dynamic>> Function() call) async {
    try {
      return ((await call()).data as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }
}
