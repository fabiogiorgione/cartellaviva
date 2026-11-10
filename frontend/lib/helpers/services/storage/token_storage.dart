import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Access token (JWT, 15 min) e refresh token (monouso, ruotato a ogni refresh).
/// Salvati in storage sicuro: Keychain su iOS/macOS, Keystore su Android,
/// DPAPI su Windows, WebCrypto + localStorage sul web.
/// Una copia in memoria evita letture asincrone a ogni richiesta.
class TokenStorage {
  static const FlutterSecureStorage _storage = FlutterSecureStorage();
  static const String _accessKey = 'fv_access_token';
  static const String _refreshKey = 'fv_refresh_token';

  static String? accessToken;
  static String? refreshToken;

  static bool get hasSession => refreshToken != null;

  static Future<void> load() async {
    try {
      accessToken = await _storage.read(key: _accessKey);
      refreshToken = await _storage.read(key: _refreshKey);
    } catch (_) {
      // Storage illeggibile (es. chiavi cambiate dopo reinstallazione): nuova sessione.
      await clear();
    }
  }

  static Future<void> save(String access, String refresh) async {
    accessToken = access;
    refreshToken = refresh;
    await _storage.write(key: _accessKey, value: access);
    await _storage.write(key: _refreshKey, value: refresh);
  }

  static Future<void> clear() async {
    accessToken = null;
    refreshToken = null;
    try {
      await _storage.delete(key: _accessKey);
      await _storage.delete(key: _refreshKey);
    } catch (_) {}
  }
}
