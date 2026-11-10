import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';

/// Deep link dell'app (schema "cartellaviva://"), oggi solo il ritorno
/// dall'autorizzazione di Google Drive. Sul web non serve: il backend rimanda
/// direttamente alla pagina /import/esito.
class DeepLinkService {
  static StreamSubscription<Uri>? _subscription;

  /// Solo i percorsi noti diventano rotte: un link esterno non può aprire pagine a caso.
  static String? routeFor(Uri uri) {
    if (uri.scheme != 'cartellaviva') return null;
    final path = '/${uri.host}${uri.path}';
    if (path != '/import/esito') return null;
    return uri.query.isEmpty ? path : '$path?${uri.query}';
  }

  static Future<void> init() async {
    if (kIsWeb) return;
    final links = AppLinks();
    // Anche il link che ha avviato l'app arriva da questo stream.
    _subscription ??= links.uriLinkStream.listen((uri) {
      final route = routeFor(uri);
      if (route != null) Get.toNamed(route);
    });
  }
}
