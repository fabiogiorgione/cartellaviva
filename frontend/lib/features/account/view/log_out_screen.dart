import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/log_out_controller.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';

class LogOutScreen extends StatefulWidget {
  const LogOutScreen({super.key});

  @override
  State<LogOutScreen> createState() => _LogOutScreenState();
}

class _LogOutScreenState extends State<LogOutScreen> {

  late LogOutController controller = Get.put(LogOutController());

  @override
  Widget build(BuildContext context) {
    return AuthLayout(
    child: GetBuilder(
      init: controller,
      global: false,
      // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
      dispose: (_) => controller.onDelete(),
      builder: (controller) {
      return Padding(
        padding: EdgeInsets.all(20),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Riquadro(paddingAll: 0, height: 28, child: const LogoCartellaViva(altezza: 28)),
            Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Testo.titleMedium("A presto!", fontWeight: FontWeight.w500),
                  SizedBox(height: 8),
                  Testo.bodySmall("Sei uscito: la sessione è stata chiusa su questo dispositivo.", tenue: true),
                  SizedBox(height: 16),
                  Icon(LucideIcons.circle_check, size: 96, color: ColoreStato.successo),
                ],
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Testo.bodyMedium("Torna al ", fontWeight: FontWeight.w500, tenue: true),
                InkWell(
                  onTap: () => controller.goToLogInScreen(),
                  child: Testo.bodyMedium("login", fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                )
              ],
            )
          ],
        ),
      );
    },),
    );
  }
}
