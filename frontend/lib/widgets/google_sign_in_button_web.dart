import 'package:flutter/widgets.dart';
import 'package:google_sign_in_web/web_only.dart' as web;

/// Pulsante ufficiale di Google Identity Services (solo web).
Widget buildGoogleWebButton() => web.renderButton(
      configuration: web.GSIButtonConfiguration(
        text: web.GSIButtonText.continueWith,
        shape: web.GSIButtonShape.rectangular,
        size: web.GSIButtonSize.large,
        theme: web.GSIButtonTheme.outline,
      ),
    );
