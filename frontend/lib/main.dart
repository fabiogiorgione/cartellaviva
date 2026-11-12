import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/services/ai_locale.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/deep_link_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/services/storage/local_storage.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/error_404.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/route/routes.dart';
import 'package:cartellaviva/route/routes_name.dart';
import 'package:url_strategy/url_strategy.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  setPathUrlStrategy();

  await LocalStorage.init();
  await PreferenzeTema.carica();
  await AiLocale.carica();
  // Ripristina la sessione (token in storage sicuro + GET /api/me).
  await SessionService.init();
  runApp(const CartellaVivaApp());
  // Dopo runApp: i link aprono rotte di GetX.
  await DeepLinkService.init();
}

class CartellaVivaApp extends StatelessWidget {
  const CartellaVivaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      title: 'CartellaViva',
      debugShowCheckedModeBanner: false,
      theme: TemaApp.chiaro,
      darkTheme: TemaApp.scuro,
      themeMode: PreferenzeTema.modo.value,
      initialRoute: RoutesName().dashboard,
      getPages: getPageRoute(),
      navigatorObservers: [OsservatorePila()],
      unknownRoute: GetPage(name: '/404', page: () => const Error404Screen()),
      locale: const Locale('it'),
      supportedLocales: const [Locale('it')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
    );
  }
}
