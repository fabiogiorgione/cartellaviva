import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/widgets/google_sign_in_button_stub.dart'
    if (dart.library.js_interop) 'package:cartellaviva/widgets/google_sign_in_button_web.dart' as platform;

/// Pulsante "Accedi con Google".
/// - Web: pulsante ufficiale di Google (obbligatorio con Google Identity Services);
///   l'ID token arriva su GoogleAuthService.idTokens.
/// - Mobile: pulsante dell'app che chiama [onPressed] (GoogleAuthService.signIn()).
/// Non mostra nulla se Google non è configurato o la piattaforma non è supportata.
class GoogleSignInButton extends StatelessWidget {
  final VoidCallback? onPressed;
  final String label;

  const GoogleSignInButton({super.key, required this.onPressed, this.label = 'Accedi con Google'});

  @override
  Widget build(BuildContext context) {
    if (!GoogleAuthService.isAvailable) {
      return SizedBox.shrink();
    }
    if (GoogleAuthService.usesPlatformButton) {
      return platform.buildGoogleWebButton();
    }
    return SizedBox(
      width: double.infinity,
      child: OutlinedButton.icon(
        onPressed: onPressed,
        icon: Text('G', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: Color(0xff4285F4))),
        label: Text(label),
        style: OutlinedButton.styleFrom(padding: EdgeInsets.symmetric(vertical: 12)),
      ),
    );
  }
}
