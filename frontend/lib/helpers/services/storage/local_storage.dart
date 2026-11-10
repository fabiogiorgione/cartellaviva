import 'package:shared_preferences/shared_preferences.dart';

/// Preferenze NON sensibili (ultima famiglia scelta). Il tema sta in
/// PreferenzeTema; i token di sessione in TokenStorage (storage sicuro), mai qui.
class LocalStorage {
  static const String _currentTenantKey = "current_tenant_id";

  static SharedPreferences? _preferencesInstance;

  static SharedPreferences get preferences {
    if (_preferencesInstance == null) {
      throw StateError('Chiamare LocalStorage.init() prima di usarlo');
    }
    return _preferencesInstance!;
  }

  static Future<void> init() async {
    _preferencesInstance = await SharedPreferences.getInstance();
    // Chiavi delle versioni precedenti (login finto, tema, lingua): non più usate.
    for (final k in ['user', 'theme_customizer', 'lang_code']) {
      await preferences.remove(k);
    }
  }

  static Future<bool> setCurrentTenantId(String tenantId) {
    return preferences.setString(_currentTenantKey, tenantId);
  }

  static String? getCurrentTenantId() {
    return preferences.getString(_currentTenantKey);
  }
}
