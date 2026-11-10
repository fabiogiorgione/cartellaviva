import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

class SecurityStatus {
  final bool twoFactorEnabled;
  final int backupCodesRemaining;
  final bool googleLinked;
  final bool hasPassword;

  SecurityStatus({required this.twoFactorEnabled, required this.backupCodesRemaining, required this.googleLinked, required this.hasPassword});

  factory SecurityStatus.fromJson(Map<String, dynamic> json) => SecurityStatus(
        twoFactorEnabled: json['twoFactorEnabled'] as bool? ?? false,
        backupCodesRemaining: json['backupCodesRemaining'] as int? ?? 0,
        googleLinked: json['googleLinked'] as bool? ?? false,
        hasPassword: json['hasPassword'] as bool? ?? true,
      );
}

/// Voce di una select (stato, comune, tipo documento...).
class Option {
  final String value;
  final String label;

  Option(this.value, this.label);

  @override
  String toString() => label;
}

/// Endpoint del proprio account: sicurezza (2FA, Google), anagrafica, geo.
class AccountApi {
  static final Dio _dio = ApiClient.dio;

  // ---- Sicurezza ----------------------------------------------------------

  static Future<SecurityStatus> security() => chiamaApi(() async {
        final r = await _dio.get('/me/security');
        return SecurityStatus.fromJson(r.data as Map<String, dynamic>);
      });

  /// Restituisce {secret, otpauthUri} per il QR code.
  static Future<Map<String, String>> startTwoFactorSetup() => chiamaApi(() async {
        final r = await _dio.post('/me/2fa/setup', data: {});
        return {'secret': r.data['secret'] as String, 'otpauthUri': r.data['otpauthUri'] as String};
      });

  static Future<List<String>> confirmTwoFactor(String code) => chiamaApi(() async {
        final r = await _dio.post('/me/2fa/enable', data: {'code': code.trim()});
        return (r.data['backupCodes'] as List).cast<String>();
      });

  static Future<void> disableTwoFactor(String code) => chiamaApi(() async {
        await _dio.post('/me/2fa/disable', data: {'code': code.trim()});
      });

  static Future<List<String>> regenerateBackupCodes(String code) => chiamaApi(() async {
        final r = await _dio.post('/me/2fa/backup-codes', data: {'code': code.trim()});
        return (r.data['backupCodes'] as List).cast<String>();
      });

  static Future<void> linkGoogle(String idToken) => chiamaApi(() async {
        await _dio.post('/me/google', data: {'idToken': idToken});
      });

  static Future<void> unlinkGoogle() => chiamaApi(() async {
        await _dio.delete('/me/google');
      });

  // ---- Credenziali ----------------------------------------------------------

  /// Pubblico: false se sul server la registrazione è chiusa (si entra solo su invito).
  static Future<bool> registrazioneAperta() =>
      chiamaApi(() async => (await _dio.get('/auth/registrazione', options: ApiClient.anonymous)).data['aperta'] == true);

  /// Pubbliche (dai link delle email): messaggio da mostrare.
  static Future<String> passwordDimenticata(String email) =>
      chiamaApi(() async => (await _dio.post('/auth/password/dimenticata', data: {'email': email.trim()}, options: ApiClient.anonymous)).data['messaggio'] as String);

  /// Email mascherata dell'account, o 410 se il link è scaduto.
  static Future<String> anteprimaReset(String token) =>
      chiamaApi(() async => (await _dio.post('/auth/password/anteprima', data: {'token': token}, options: ApiClient.anonymous)).data['email'] as String);

  static Future<String> reimpostaPassword(String token, String password) =>
      chiamaApi(() async => (await _dio.post('/auth/password/reimposta', data: {'token': token, 'password': password}, options: ApiClient.anonymous)).data['messaggio'] as String);

  /// Account creato dal portale: email mascherata, o 410 se il link è scaduto.
  static Future<String> anteprimaAttivazione(String token) =>
      chiamaApi(() async => (await _dio.post('/auth/attivazione/anteprima', data: {'token': token}, options: ApiClient.anonymous)).data['email'] as String);

  static Future<String> attiva(String token, String password) =>
      chiamaApi(() async => (await _dio.post('/auth/attivazione', data: {'token': token, 'password': password}, options: ApiClient.anonymous)).data['messaggio'] as String);

  static Future<String> verificaEmail(String token) =>
      chiamaApi(() async => (await _dio.post('/auth/verifica-email', data: {'token': token}, options: ApiClient.anonymous)).data['messaggio'] as String);

  static Future<({String messaggio, String email})> confermaCambioEmail(String token) => chiamaApi(() async {
        final data = (await _dio.post('/auth/cambio-email/conferma', data: {'token': token}, options: ApiClient.anonymous)).data as Map;
        return (messaggio: data['messaggio'] as String, email: data['email'] as String);
      });

  /// Autenticate.
  static Future<String> inviaVerificaEmail() => chiamaApi(() async => (await _dio.post('/me/email/verifica')).data['messaggio'] as String);

  /// Restituisce i nuovi token di questa sessione (le altre vengono chiuse).
  static Future<Map<String, dynamic>> cambiaPassword({String? attuale, required String nuova}) => chiamaApi(() async {
        final r = await _dio.post('/me/password', data: {if (attuale != null) 'attuale': attuale, 'nuova': nuova});
        return Map<String, dynamic>.from(r.data as Map);
      });

  static Future<String> cambiaEmail(String email, String password) =>
      chiamaApi(() async => (await _dio.post('/me/email', data: {'email': email.trim(), 'password': password})).data['messaggio'] as String);

  // ---- Anagrafica -----------------------------------------------------------

  /// {profileCompleted, anagrafica (mappa o null)}
  static Future<Map<String, dynamic>> anagrafica() => chiamaApi(() async {
        final r = await _dio.get('/me/anagrafica');
        return r.data as Map<String, dynamic>;
      });

  /// Salva; in caso di 422 l'ApiException contiene gli errori per campo (propertyPath).
  static Future<Map<String, dynamic>> saveAnagrafica(Map<String, dynamic> data) => chiamaApi(() async {
        final r = await _dio.put('/me/anagrafica', data: data);
        return r.data as Map<String, dynamic>;
      });

  static Future<Map<String, List<Option>>> anagraficaOptions() => chiamaApi(() async {
        final r = await _dio.get('/anagrafica/options');
        return (r.data as Map<String, dynamic>).map((key, value) => MapEntry(
              key,
              (value as List).map((o) => Option(o['value'].toString(), o['label'].toString())).toList(),
            ));
      });

  static Future<String> calcolaCodiceFiscale(Map<String, dynamic> data) => chiamaApi(() async {
        final r = await _dio.post('/anagrafica/codice-fiscale', data: data);
        return r.data['codiceFiscale'] as String;
      });

  // ---- Geo --------------------------------------------------------------------

  static List<Option>? _countries;

  /// Gli stati sono pochi (~225): si caricano una volta e si filtrano in locale.
  static Future<List<Option>> countries() async {
    final cached = _countries;
    if (cached != null) {
      return cached;
    }
    final loaded = await chiamaApi(() async {
      final r = await _dio.get('/geo/countries');
      return (r.data as List).map((c) => Option(c['istatId'].toString(), c['name'] as String)).toList();
    });
    _countries = loaded;
    return loaded;
  }

  static Future<List<Option>> comuni(String query, {bool storici = false}) => chiamaApi(() async {
        if (query.trim().length < 2) {
          return <Option>[];
        }
        final r = await _dio.get('/geo/comuni', queryParameters: {'q': query.trim(), if (storici) 'storici': 1});
        return (r.data as List).map((c) => Option(c['id'].toString(), c['name'] as String)).toList();
      });
}
