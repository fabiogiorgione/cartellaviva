import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';

/// Accesso con Google (google_sign_in 7).
///
/// Configurazione a build time:
///   --dart-define=GOOGLE_WEB_CLIENT_ID=xxx.apps.googleusercontent.com   (obbligatorio)
///   --dart-define=GOOGLE_IOS_CLIENT_ID=yyy.apps.googleusercontent.com   (solo iOS/macOS)
/// Il web client ID è anche il "serverClientId" su Android: l'ID token ha come
/// audience il client web, che il backend accetta (GOOGLE_CLIENT_IDS).
///
/// - Android/iOS/macOS: [signIn] apre il selettore account e restituisce l'ID token.
/// - Web: Google impone il proprio pulsante (GoogleSignInButton); l'ID token
///   arriva sullo stream [idTokens].
/// - Windows/Linux: non supportato dal plugin, il pulsante non viene mostrato.
class GoogleAuthService {
  static const String webClientId = String.fromEnvironment('GOOGLE_WEB_CLIENT_ID');
  static const String iosClientId = String.fromEnvironment('GOOGLE_IOS_CLIENT_ID');

  static bool _initialized = false;
  static bool _available = false;
  static final StreamController<String> _idTokens = StreamController<String>.broadcast();

  /// ID token ottenuti tramite il pulsante web (o altri eventi di login Google).
  static Stream<String> get idTokens => _idTokens.stream;

  static bool get isAvailable => _available;

  /// Sul web si usa il pulsante di Google invece di [signIn].
  static bool get usesPlatformButton => kIsWeb;

  static Future<void> init() async {
    if (_initialized || webClientId.isEmpty) {
      return;
    }
    _initialized = true;
    final isApple = !kIsWeb && (defaultTargetPlatform == TargetPlatform.iOS || defaultTargetPlatform == TargetPlatform.macOS);
    final isSupported = kIsWeb ||
        defaultTargetPlatform == TargetPlatform.android ||
        (isApple && iosClientId.isNotEmpty);
    if (!isSupported) {
      return;
    }
    try {
      await GoogleSignIn.instance.initialize(
        clientId: kIsWeb ? webClientId : (isApple ? iosClientId : null),
        serverClientId: kIsWeb ? null : webClientId,
      );
      GoogleSignIn.instance.authenticationEvents.listen(
        (event) {
          if (event is GoogleSignInAuthenticationEventSignIn) {
            final idToken = event.user.authentication.idToken;
            if (idToken != null) {
              _idTokens.add(idToken);
            }
          }
        },
        onError: (Object _) {},
      );
      _available = true;
    } catch (_) {
      _available = false;
    }
  }

  /// Flusso nativo (non web). Restituisce l'ID token, o null se l'utente annulla.
  static Future<String?> signIn() async {
    if (!_available || kIsWeb) {
      return null;
    }
    try {
      final account = await GoogleSignIn.instance.authenticate();
      return account.authentication.idToken;
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled) {
        return null;
      }
      rethrow;
    }
  }

  /// Chiude la sessione Google locale (al logout dall'app).
  static Future<void> signOut() async {
    if (_available) {
      try {
        await GoogleSignIn.instance.signOut();
      } catch (_) {}
    }
  }
}
