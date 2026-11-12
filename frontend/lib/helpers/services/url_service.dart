import 'package:get/get.dart';

class UrlService {
  /// Percorso della pagina corrente (es. `/documenti`), per evidenziare il menu.
  /// Viene dalla rotta di GetX e non dall'indirizzo del browser: nell'app per
  /// telefono l'indirizzo è sempre "/".
  static String getCurrentUrl() => Uri.parse(Get.currentRoute).path;
}
