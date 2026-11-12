import 'package:flutter/material.dart';
import 'package:cartellaviva/features/account/view/modulo_accesso.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/forgot_password_controller.dart';
import 'package:cartellaviva/layout/auth_layout.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen>
    with SingleTickerProviderStateMixin {
  late ForgotPasswordController controller;
  @override

  @override
  void initState() {
    controller = ForgotPasswordController();
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
                      Testo.titleMedium("Password dimenticata", fontWeight: FontWeight.w500),
                      SizedBox(height: 8),
                      Testo.bodySmall("Inserisci l'email del tuo account: ti mandiamo un link per sceglierne una nuova. Il link vale un'ora.", tenue: true),
                      SizedBox(height: 16),
                      if (controller.messaggio != null)
                        Testo.bodyMedium(controller.messaggio!, color: context.colori.success)
                      else ...[
                        email(),
                        SizedBox(height: 16),
                        resetBtn(),
                      ],
                      if (controller.errore != null) ...[SizedBox(height: 8), Testo.bodySmall(controller.errore!, color: context.colori.danger)],
                    ],
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Testo.bodyMedium("Torna al", fontWeight: FontWeight.w500, tenue: true),
                      SizedBox(width: 4),
                      InkWell(
                        onTap: () => controller.gotoLogIn(),
                        child: Testo.bodyMedium("login", fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                      )
                    ],
                  ),
              ],
            );
        },
      ),
    );
  }

  Widget resetBtn() {
    return SizedBox(width: double.infinity, child: FilledButton(onPressed: controller.onLogin, child: Testo.bodyMedium(controller.busy ? "Invio…" : "Mandami il link", color: Theme.of(context).colorScheme.onPrimary)));
  }

  Widget email() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium("Email", fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController('email'), validator: controller.basicValidator.getValidation('email'), hint: "nome@esempio.it", keyboardType: TextInputType.emailAddress, onFieldSubmitted: (_) => controller.onLogin()),
      ],
    );
  }
}
