import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:get/get.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Sblocco dell'app con Face ID (o l'impronta) sul telefono, attivabile in
/// Sicurezza e accesso, solo per questo dispositivo.
///
/// La sessione resta quella di sempre (refresh token nel Portachiavi): lo
/// sblocco chiede il volto all'apertura dell'app e al ritorno dopo
/// [dopoInBackground] in background, prima di mostrare i dati. In alternativa
/// si entra con la password (schermata di blocco).
class SbloccoBiometrico with WidgetsBindingObserver {
  SbloccoBiometrico._();

  static const _chiave = 'sblocco_biometrico';
  static const dopoInBackground = Duration(minutes: 5);
  static const rotta = '/auth/sblocco';

  static final attivo = ValueNotifier<bool>(false);
  static final _istanza = SbloccoBiometrico._();
  static final _auth = LocalAuthentication();

  /// L'app è bloccata finché non si sblocca (con il volto o la password).
  static bool bloccato = false;
  static DateTime? _inBackgroundDa;

  static bool get supportato => !kIsWeb && (defaultTargetPlatform == TargetPlatform.iOS || defaultTargetPlatform == TargetPlatform.android);

  /// All'avvio, dopo il ripristino della sessione: se attivo, l'app parte bloccata.
  static Future<void> avvia() async {
    if (!supportato) return;
    attivo.value = (await SharedPreferences.getInstance()).getBool(_chiave) ?? false;
    bloccato = attivo.value && SessionService.to.isLoggedIn;
    WidgetsBinding.instance.addObserver(_istanza);
  }

  /// "Face ID", "Touch ID" o "impronta", per i testi.
  static Future<String> nome() async {
    if (!supportato) return 'Face ID';
    final tipi = await _auth.getAvailableBiometrics();
    if (tipi.contains(BiometricType.face)) return defaultTargetPlatform == TargetPlatform.iOS ? 'Face ID' : 'riconoscimento del volto';
    if (tipi.contains(BiometricType.fingerprint)) return defaultTargetPlatform == TargetPlatform.iOS ? 'Touch ID' : 'impronta';
    return 'Face ID';
  }

  /// Il telefono ha il volto o l'impronta registrati?
  static Future<bool> disponibile() async => supportato && await _auth.isDeviceSupported() && (await _auth.getAvailableBiometrics()).isNotEmpty;

  /// Attivazione con una verifica subito (così si sa che funziona); disattivazione libera.
  static Future<bool> imposta(bool valore) async {
    if (valore && !await verifica('Attiva lo sblocco di CartellaViva')) return false;
    attivo.value = valore;
    await (await SharedPreferences.getInstance()).setBool(_chiave, valore);
    return true;
  }

  static Future<bool> verifica(String motivo) async {
    try {
      return await _auth.authenticate(localizedReason: motivo, biometricOnly: true, persistAcrossBackgrounding: true);
    } catch (_) {
      return false;
    }
  }

  static void sblocca() {
    bloccato = false;
    _inBackgroundDa = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!attivo.value || !SessionService.to.isLoggedIn) return;
    if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      _inBackgroundDa ??= DateTime.now();
    } else if (state == AppLifecycleState.resumed) {
      final da = _inBackgroundDa;
      _inBackgroundDa = null;
      if (bloccato || (da != null && DateTime.now().difference(da) >= dopoInBackground)) {
        bloccato = true;
        if (Get.currentRoute.split('?').first != rotta) {
          Get.toNamed(rotta, parameters: {'next': Get.currentRoute});
        }
      }
    }
  }
}
