import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

/// Colori dell'app oltre a quelli di Material: stati (successo, avviso,
/// pericolo...), barra laterale e barra superiore. Si leggono con `context.colori`.
@immutable
class ColoriApp extends ThemeExtension<ColoriApp> {
  final Color primary, onPrimary, secondary, success, warning, danger, info;

  /// Testo principale sullo sfondo delle pagine.
  final Color onBackground;

  /// Barra laterale: sfondo, testo, etichette delle sezioni, voce attiva.
  final Color barraSfondo, barraTesto, barraEtichetta, barraAttiva;

  /// Barra superiore: sfondo e testo.
  final Color testataSfondo, testataTesto;

  const ColoriApp({
    required this.primary,
    required this.onPrimary,
    required this.secondary,
    required this.success,
    required this.warning,
    required this.danger,
    required this.info,
    required this.onBackground,
    required this.barraSfondo,
    required this.barraTesto,
    required this.barraEtichetta,
    required this.barraAttiva,
    required this.testataSfondo,
    required this.testataTesto,
  });

  static const chiaro = ColoriApp(
    primary: TemaApp.primario,
    onPrimary: Colors.white,
    secondary: Color(0xff6c757d),
    success: Color(0xff17a497),
    warning: Color(0xfffec20d),
    danger: Color(0xffd4526e),
    info: Color(0xff33b2df),
    onBackground: Color(0xff313a46),
    barraSfondo: Color(0xffeaebef),
    barraTesto: Color(0xff313a46),
    barraEtichetta: Color(0xff6c757d),
    barraAttiva: TemaApp.primario,
    testataSfondo: Colors.white,
    testataTesto: Color(0xff313a46),
  );

  static const scuro = ColoriApp(
    primary: TemaApp.primario,
    onPrimary: Colors.white,
    secondary: Color(0xff6c757d),
    success: Color(0xff17a497),
    warning: Color(0xfffec20d),
    danger: Color(0xffd4526e),
    info: Color(0xff33b2df),
    onBackground: Color(0xfff1f1f2),
    barraSfondo: Color(0xff282c32),
    barraTesto: Color(0xffdcdcdc),
    barraEtichetta: Color(0xff879baf),
    barraAttiva: TemaApp.primario,
    testataSfondo: Color(0xff2c3036),
    testataTesto: Color(0xffdcdcdc),
  );

  @override
  ColoriApp copyWith() => this;

  @override
  ColoriApp lerp(ColoriApp? other, double t) => t < .5 || other == null ? this : other;
}

extension ColoriContesto on BuildContext {
  /// Colori dell'app per il tema corrente (chiaro o scuro).
  ColoriApp get colori => Theme.of(this).extension<ColoriApp>() ?? ColoriApp.chiaro;
}

/// Spazio attorno al contenuto delle pagine: meno sul telefono.
double get spazioPagina => !kIsWeb && (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS) ? 16 : 24;

/// Temi chiaro e scuro dell'app (Material 2, colore principale blu CartellaViva).
abstract final class TemaApp {
  static const primario = Color(0xff275ac5);

  static final ThemeData chiaro = _tema(
    luminosita: Brightness.light,
    sfondo: const Color(0xfff5f5f5),
    card: Colors.white,
    menu: Colors.white,
    divisore: const Color(0xffdddddd),
    colori: ColoriApp.chiaro,
  );

  static final ThemeData scuro = _tema(
    luminosita: Brightness.dark,
    sfondo: const Color(0xff262729),
    card: const Color(0xff1b1b1c),
    // Più chiaro delle card: un menu aperto si distingue da ciò che copre.
    menu: const Color(0xff2f3033),
    divisore: const Color(0xff393a41),
    colori: ColoriApp.scuro,
  );

  static ThemeData _tema({
    required Brightness luminosita,
    required Color sfondo,
    required Color card,
    required Color menu,
    required Color divisore,
    required ColoriApp colori,
  }) {
    final scuro = luminosita == Brightness.dark;
    final schema = ColorScheme.fromSeed(
      seedColor: primario,
      brightness: luminosita,
      surface: scuro ? sfondo : null,
      onSurface: scuro ? const Color(0xffd7d7d7) : null,
    );
    return ThemeData(
      useMaterial3: false,
      brightness: luminosita,
      colorScheme: schema,
      primaryColor: primario,
      scaffoldBackgroundColor: sfondo,
      // Sfondo di menu delle select, popup e bottom sheet: opaco, mai trasparente.
      canvasColor: menu,
      cardColor: card,
      cardTheme: CardThemeData(color: card),
      appBarTheme: AppBarTheme(backgroundColor: sfondo, foregroundColor: schema.onSurface, iconTheme: IconThemeData(color: schema.onSurface)),
      dividerColor: divisore,
      dividerTheme: DividerThemeData(color: divisore, thickness: 1),
      // Tutti i campi con il bordo: nei moduli non serve ripeterlo.
      inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      tabBarTheme: const TabBarThemeData(
        labelColor: primario,
        unselectedLabelColor: Color(0xff495057),
        indicatorSize: TabBarIndicatorSize.label,
        indicator: UnderlineTabIndicator(borderSide: BorderSide(color: primario, width: 2)),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(2)),
        checkColor: const WidgetStatePropertyAll(Colors.white),
        // Piena solo se spuntata: con un colore fisso le caselle vuote sembrerebbero selezionate.
        fillColor: WidgetStateProperty.resolveWith((stati) {
          if (!stati.contains(WidgetState.selected)) return Colors.transparent;
          return stati.contains(WidgetState.disabled) ? primario.withAlpha(100) : primario;
        }),
      ),
      switchTheme: SwitchThemeData(thumbColor: WidgetStateProperty.resolveWith((stati) => stati.contains(WidgetState.selected) ? primario : Colors.white)),
      snackBarTheme: const SnackBarThemeData(actionTextColor: Colors.white),
      extensions: [colori],
    );
  }
}
