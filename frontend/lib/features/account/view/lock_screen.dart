import 'package:flutter/material.dart';
import 'package:cartellaviva/features/account/view/modulo_accesso.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/lock_controller.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';

class LockScreen extends StatefulWidget {
  const LockScreen({super.key});

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> {
  late LockController controller;

  @override
  @override
  void initState() {
    controller = Get.put(LockController());
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    return AuthLayout(
      child: GetBuilder(
        init: controller,
        global: false,
        // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
        dispose: (_) => controller.onDelete(),
        builder: (controller) {
          return Form(
            key: controller.basicValidator.formKey,
            child: Padding(
              padding: EdgeInsets.all(20),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Riquadro(paddingAll: 0, height: 28, child: const LogoCartellaViva(altezza: 28)),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Center(
                        child: Riquadro.cerchio(height: 52, width: 52, paddingAll: 0, color: context.colori.primary.withAlpha(40), child: Center(
                            child: Testo.titleMedium(SessionService.to.me?.initials ?? '', fontWeight: FontWeight.w600, color: context.colori.primary),
                          )),
                      ),
                      SizedBox(height: 8),
                      Center(
                          child: Testo.titleMedium("Ciao ${controller.userName}", fontWeight: FontWeight.w500)),
                      SizedBox(height: 8),
                      Center(
                        child: Testo.bodySmall("Sessione bloccata: inserisci la password per continuare.", tenue: true),
                      ),
                      SizedBox(height: 16),
                      Testo.bodyMedium("Password", fontWeight: FontWeight.w500),
                      SizedBox(height: 8),
                      TextFormField(
                        controller:
                            controller.basicValidator.getController('password'),
                        validator:
                            controller.basicValidator.getValidation('password'),
                        obscureText: true,
                        onFieldSubmitted: (_) => controller.onLogin(),
                        style:
                            stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500, tenue: true),
                        decoration: InputDecoration(
                          hintText: "La tua password",
                          hintStyle:
                              stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500, tenue: true),
                          isDense: true,
                          contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                          border: bordoCampoAccesso,
                          disabledBorder: bordoCampoAccesso,
                          errorBorder: bordoCampoAccesso,
                          focusedBorder: bordoCampoAccesso,
                          enabledBorder: bordoCampoAccesso,
                        ),
                      ),
                      SizedBox(height: 20),
                      SizedBox(width: double.infinity, child: FilledButton(onPressed: () => controller.onLogin(), child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(LucideIcons.log_in,
                                  color: context.colori.onPrimary, size: 16),
                              SizedBox(width: 8),
                              Testo.bodyMedium(controller.loading ? "Verifica..." : "Sblocca", color: context.colori.onPrimary),
                            ],
                          ))),
                    ],
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Testo.bodyMedium("Non sei tu? ", fontWeight: FontWeight.w500, tenue: true),
                      InkWell(
                        onTap: () => controller.goToLoginAsOtherUser(),
                        child: Testo.bodyMedium("Accedi con un altro account", fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                      )
                    ],
                  )
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
