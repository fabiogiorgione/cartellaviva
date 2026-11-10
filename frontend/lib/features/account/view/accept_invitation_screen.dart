import 'package:flutter/material.dart';
import 'package:cartellaviva/features/account/view/modulo_accesso.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/accept_invitation_controller.dart';
import 'package:cartellaviva/layout/auth_layout.dart';

class AcceptInvitationScreen extends StatefulWidget {
  const AcceptInvitationScreen({super.key});

  @override
  State<AcceptInvitationScreen> createState() => _AcceptInvitationScreenState();
}

class _AcceptInvitationScreenState extends State<AcceptInvitationScreen> {
  late AcceptInvitationController controller;
  @override

  @override
  void initState() {
    controller = AcceptInvitationController();
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
                body(),
                  SizedBox(),
              ],
            );
        },
      ),
    );
  }

  Widget body() {
    if (controller.loading) {
      return Center(child: CircularProgressIndicator());
    }
    final preview = controller.preview;
    if (controller.error != null || preview == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Testo.titleMedium("Invito non disponibile", fontWeight: FontWeight.w500),
          SizedBox(height: 8),
          Testo.bodySmall(controller.error ?? 'Link di invito non valido.', tenue: true),
          SizedBox(height: 20),
          SizedBox(width: double.infinity, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: context.colori.primary, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: () => Get.offAllNamed(controller.isLoggedIn ? '/dashboard' : '/auth/login'), child: Testo.bodyMedium(controller.isLoggedIn ? 'Vai alla dashboard' : 'Vai al login', color: context.colori.onPrimary))),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.titleMedium("Sei stato invitato", fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        Testo.bodySmall('${preview.invitedBy ?? 'Un familiare'} ti ha invitato in "${preview.tenantName}" con il ruolo ${preview.roleName}.', tenue: true),
        SizedBox(height: 4),
        Testo.bodySmall('Email invitata: ${preview.email}', fontWeight: FontWeight.w500),
        SizedBox(height: 20),
        ...action(preview.accountExists),
      ],
    );
  }

  List<Widget> action(bool accountExists) {
    if (controller.isLoggedIn) {
      if (controller.emailMatchesSession) {
        return [primaryButton('Accetta invito', controller.acceptAsCurrentUser)];
      }
      return [
        Testo.bodySmall('Sei connesso come ${controller.session.me!.email}: per accettare devi accedere con l\'email invitata.', tenue: true),
        SizedBox(height: 16),
        primaryButton('Cambia account', controller.switchAccount),
      ];
    }
    if (accountExists) {
      return [
        Testo.bodySmall('Hai già un account: accedi per accettare l\'invito.', tenue: true),
        SizedBox(height: 16),
        primaryButton('Accedi', controller.goToLogin),
      ];
    }
    return [
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: field('first_name', 'Nome')),
          SizedBox(width: 12),
          Expanded(child: field('last_name', 'Cognome')),
        ],
      ),
      SizedBox(height: 12),
      field('password', 'Scegli una password (almeno 10 caratteri)', obscure: true),
      SizedBox(height: 20),
      primaryButton('Crea account e accetta', controller.registerAndAccept),
    ];
  }

  Widget primaryButton(String label, VoidCallback onPressed) {
    return SizedBox(width: double.infinity, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: context.colori.primary, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: controller.submitting ? null : onPressed, child: Testo.bodyMedium(controller.submitting ? 'Attendere...' : label, color: context.colori.onPrimary)));
  }

  Widget field(String name, String label, {bool obscure = false}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium(label, fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        TextFormField(
          controller: controller.basicValidator.getController(name),
          validator: controller.basicValidator.getValidation(name),
          obscureText: obscure,
          style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500, tenue: true),
          decoration: InputDecoration(
            isDense: true,
            contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            border: bordoCampoAccesso,
            disabledBorder: bordoCampoAccesso,
            errorBorder: bordoCampoAccesso,
            focusedBorder: bordoCampoAccesso,
            enabledBorder: bordoCampoAccesso,
          ),
        ),
      ],
    );
  }
}
