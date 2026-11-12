import 'package:flutter/material.dart';
import 'package:cartellaviva/features/account/view/modulo_accesso.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/register_account_controller.dart';
import 'package:cartellaviva/layout/auth_layout.dart';

class RegisterAccountScreen extends StatefulWidget {
  const RegisterAccountScreen({super.key});

  @override
  State<RegisterAccountScreen> createState() => _RegisterAccountScreenState();
}

class _RegisterAccountScreenState extends State<RegisterAccountScreen>
    with SingleTickerProviderStateMixin {
  late RegisterAccountController controller;
  @override

  @override
  void initState() {
    controller = RegisterAccountController();
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
                if (!controller.registrazioneAperta)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Testo.titleMedium("Registrazione chiusa", fontWeight: FontWeight.w500),
                      SizedBox(height: 8),
                      Testo.bodyMedium("Su questo server non ci si registra da soli: si entra solo su invito. "
                          "Chiedi a chi gestisce lo spazio della tua famiglia di invitarti: riceverai un'email con il link per creare l'account.", tenue: true),
                    ],
                  )
                else
                Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Testo.titleMedium("Crea il tuo account", fontWeight: FontWeight.w500),
                      SizedBox(height: 8),
                      Testo.bodySmall("Creeremo anche lo spazio della tua famiglia: potrai invitare gli altri membri in seguito.", tenue: true),
                      SizedBox(height: 16),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(child: textField('first_name', 'Nome', 'Mario')),
                          SizedBox(width: 12),
                          Expanded(child: textField('last_name', 'Cognome', 'Rossi')),
                        ],
                      ),
                      SizedBox(height: 12),
                      email(),
                      SizedBox(height: 12),
                      password(),
                      SizedBox(height: 12),
                      rememberButton(),
                      SizedBox(height: 12),
                      signUpBtn(),
                    ],
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Testo.bodyMedium("Hai già un account?", fontWeight: FontWeight.w500, tenue: true),
                      SizedBox(width: 8),
                      InkWell(
                        onTap: () => controller.gotoLogin(),
                        child: Testo.bodyMedium("Accedi", fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                      )
                    ],
                  ),
              ],
            );
        },
      ),
    );
  }

  Widget signUpBtn() {
    return SizedBox(width: double.infinity, child: FilledButton(onPressed: controller.loading ? null : () => controller.onRegister(), child: Testo.bodyMedium(controller.loading ? "Creazione in corso..." : "Crea account", color: Theme.of(context).colorScheme.onPrimary)));
  }

  Widget rememberButton() {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Theme(
          data: ThemeData(unselectedWidgetColor: Colors.transparent),
          child: Checkbox(
            value: controller.termAndConditions,
            onChanged: (value) => controller.termAndConditionsToggle(),
          ),
        ),
        Testo.bodyMedium("Accetto termini e condizioni", fontWeight: FontWeight.w500)
      ],
    );
  }

  Widget textField(String field, String label, String hint) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium(label, fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController(field), validator: controller.basicValidator.getValidation(field), hint: hint),
      ],
    );
  }

  Widget email() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium("Email", fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController('email'), validator: controller.basicValidator.getValidation('email'), hint: "nome@esempio.it", keyboardType: TextInputType.emailAddress),
      ],
    );
  }

  Widget password() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium("Password", fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        CampoAccesso(controller: controller.basicValidator.getController('password'), validator: controller.basicValidator.getValidation('password'), hint: "Almeno 10 caratteri", obscureText: true),
      ],
    );
  }
}
