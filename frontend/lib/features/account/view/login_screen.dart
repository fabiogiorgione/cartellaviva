import 'package:flutter/material.dart';
import 'package:cartellaviva/features/account/view/modulo_accesso.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/login_controller.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/helpers/services/offline/offline_store.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/widgets/google_sign_in_button.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with SingleTickerProviderStateMixin {
  late LoginController controller;
  @override

  @override
  void initState() {
    controller = LoginController();
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
          return ModuloAccesso(
              formKey: controller.basicValidator.formKey,
              children: [
                Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Testo.titleMedium("Accedi", fontWeight: FontWeight.w500),
                      SizedBox(height: 8),
                      Testo.bodySmall("Inserisci email e password per accedere a CartellaViva.", tenue: true),
                      SizedBox(height: 16),
                      email(),
                      SizedBox(height: 20),
                      password(),
                      SizedBox(height: 24),
                      loginBtn(),
                      if (GoogleAuthService.isAvailable) ...[
                        SizedBox(height: 20),
                        Row(children: [
                          Expanded(child: Divider()),
                          Padding(padding: EdgeInsets.symmetric(horizontal: 12), child: Testo.bodySmall('oppure', tenue: true)),
                          Expanded(child: Divider()),
                        ]),
                        SizedBox(height: 20),
                        Center(child: GoogleSignInButton(onPressed: controller.loading ? null : controller.onGoogleLogin)),
                      ],
                      // Senza rete o con la sessione scaduta: i documenti salvati sul telefono restano apribili.
                      if (OfflineStore.supportato) ...[
                        SizedBox(height: 16),
                        Center(
                          child: TextButton.icon(
                            onPressed: () => Get.toNamed('/offline'),
                            icon: const Icon(Icons.phonelink_lock, size: 18),
                            label: const Text('Documenti offline sul telefono'),
                          ),
                        ),
                      ],
                    ],
                  ),
                  if (controller.registrazioneAperta)
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Testo.bodyMedium("Non hai un account? ", fontWeight: FontWeight.w500, tenue: true),
                      InkWell(
                        onTap: () => controller.goToRegisterScreen(),
                        child: Testo.bodyMedium("Registrati", fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                      )
                    ],
                  ),
              ],
            );
        },
      ),
    );
  }

  Widget loginBtn() {
    return SizedBox(width: double.infinity, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: context.colori.primary, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: controller.loading ? null : () => controller.onLogin(), child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (controller.loading)
              SizedBox(
                  height: 14,
                  width: 14,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: context.colori.onPrimary))
            else
              Icon(LucideIcons.log_in, color: context.colori.onPrimary, size: 16),
            SizedBox(width: 8),
            Testo.bodyMedium(controller.loading ? "Accesso in corso..." : "Accedi", color: context.colori.onPrimary),
          ],
        )));
  }

  Widget email() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium("Email", fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController('email'), validator: controller.basicValidator.getValidation('email'), hint: "nome@esempio.it", keyboardType: TextInputType.emailAddress, autofillHints: const [AutofillHints.email]),
      ],
    );
  }

  Widget password() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Testo.bodyMedium("Password", fontWeight: FontWeight.w500),
            forgotButton(),
          ],
        ),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController('password'), validator: controller.basicValidator.getValidation('password'), hint: "La tua password", autofillHints: const [AutofillHints.password], obscureText: true, onFieldSubmitted: (_) => controller.onLogin()),
      ],
    );
  }

  Widget forgotButton() {
    return InkWell(
        onTap: () => controller.gotoForgotPasswordScreen(),
        child: Testo.bodySmall("Password dimenticata?", tenue: true));
  }
}
