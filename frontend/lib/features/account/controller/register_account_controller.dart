import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/features/account/controller/login_controller.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Registrazione: crea l'account e il tenant privato "Famiglia (cognome)",
/// di cui l'utente diventa proprietario.
class RegisterAccountController extends GetxController {
  ModuloValidato basicValidator = ModuloValidato();
  bool termAndConditions = false;
  bool loading = false;
  /// false: il server ha la registrazione chiusa e la pagina lo spiega al posto del modulo.
  /// Finché non risponde resta true: se poi rifiuta, il messaggio arriva dall'invio.
  bool registrazioneAperta = true;

  @override
  void onInit() {
    super.onInit();
    _caricaRegistrazione();
    basicValidator.addField('email',
        required: true, label: "Email", validators: [RegolaEmail()], controller: TextEditingController());
    basicValidator.addField('first_name',
        required: true, label: 'Nome', validators: [RegolaLunghezza(max: 100)], controller: TextEditingController());
    basicValidator.addField('last_name',
        required: true, label: 'Cognome', validators: [RegolaLunghezza(max: 100)], controller: TextEditingController());
    // Stessa regola del backend: almeno 10 caratteri (e non compromessa, verificato dal server).
    basicValidator.addField('password',
        required: true, label: 'Password', validators: [RegolaLunghezza(min: 10, max: 4096)], controller: TextEditingController());
  }

  Future<void> _caricaRegistrazione() async {
    try {
      registrazioneAperta = await AccountApi.registrazioneAperta();
      update();
    } on ApiException {
      // Nessuna risposta: si lascia il modulo, decide il server all'invio.
    }
  }

  void termAndConditionsToggle() {
    termAndConditions = !termAndConditions;
    update();
  }

  Future<void> onRegister() async {
    if (loading || !basicValidator.validateForm()) {
      return;
    }
    if (!termAndConditions) {
      Get.snackbar('Termini e condizioni', 'Per continuare accetta termini e condizioni.',
          snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
      return;
    }
    loading = true;
    update();

    final errors = await AuthService.registerUser(basicValidator.getData());
    loading = false;
    if (errors != null) {
      basicValidator.addErrors(errors);
      basicValidator.validateForm();
      basicValidator.clearErrors();
      update();
      return;
    }
    update();
    // Account nuovo: prima si completa l'anagrafica, salvo pagine che non la
    // richiedono (es. collegare il link di una risposta appena inviata).
    Get.offAllNamed(SessionService.to.homeRoute(next: LoginController.safeNextUrl(Get.parameters['next'])));
  }

  void gotoLogin() {
    Get.toNamed('/auth/login');
  }
}
