import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

/// Regole di navigazione dell'app, uguali su telefono e desktop.
///
/// - **Pagine di primo livello** (le voci del menu): si aprono con [apriDalMenu].
///   La pila riparte dalla prima pagina (la Home): da qualunque voce, "indietro"
///   porta alla Home, mai a una lunga catena di pagine già viste.
/// - **Dettagli** (un documento, una richiesta, una sotto-pagina): si aprono con
///   `Get.toNamed` e dichiarano la pagina genitore con `Layout(indietro: ...)`.
///   La freccia indietro usa [tornaIndietro]: torna alla pagina di prima e, se
///   non c'è (link diretto, ricarica sul web), apre il genitore.
/// - **Fogli e pannelli** (filtri, scelte): hanno sempre un titolo e un modo
///   visibile per chiuderli; vedi `FoglioTelefono`.

/// Rotta della pagina corrente, senza parametri (es. `/documenti`).
String rottaCorrente() => Uri.parse(Get.currentRoute).path;

/// Tiene il nome della prima pagina della pila (di solito la Home), che
/// [apriDalMenu] non toglie mai. Va in `GetMaterialApp(navigatorObservers: ...)`.
class OsservatorePila extends NavigatorObserver {
  static final pagine = <Route<dynamic>>[];

  static String? get prima => pagine.isEmpty ? null : pagine.first.settings.name;

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    if (route is PageRoute) pagine.add(route);
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) => pagine.remove(route);

  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) => pagine.remove(route);

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    final i = oldRoute == null ? -1 : pagine.indexOf(oldRoute);
    if (i >= 0 && newRoute is PageRoute) {
      pagine[i] = newRoute;
    } else {
      if (oldRoute != null) pagine.remove(oldRoute);
      if (newRoute is PageRoute) pagine.add(newRoute);
    }
  }
}

/// Apre una pagina di primo livello dal menu (laterale o dell'account).
/// Chi lo chiama da un cassetto o da un popup li chiude prima.
void apriDalMenu(String rotta) {
  if (rottaCorrente() == rotta) return;
  if (OsservatorePila.prima == rotta) {
    Get.until((r) => r.isFirst);
  } else {
    Get.offNamedUntil(rotta, (r) => r.isFirst);
  }
}

/// Torna alla pagina precedente o, se non c'è, al [genitore].
void tornaIndietro(String genitore) {
  final navigator = Get.key.currentState;
  if (navigator != null && navigator.canPop()) {
    navigator.pop();
  } else {
    Get.offNamed(genitore);
  }
}
