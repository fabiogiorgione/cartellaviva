import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Password dimenticata: il server risponde sempre allo stesso modo, senza
/// rivelare se l'indirizzo ha un account.
class ForgotPasswordController extends GetxController {
  ModuloValidato basicValidator = ModuloValidato();
  bool busy = false;
  String? messaggio;
  String? errore;

  @override
  void onInit() {
    super.onInit();
    basicValidator.addField(
      'email',
      required: true,
      label: "Email",
      validators: [RegolaEmail()],
      controller: TextEditingController(),
    );
  }

  Future<void> onLogin() async {
    if (busy || !basicValidator.validateForm()) {
      return;
    }
    busy = true;
    errore = null;
    update();
    try {
      messaggio = await AccountApi.passwordDimenticata(basicValidator.getController('email')!.text);
    } on ApiException catch (e) {
      errore = e.message;
    }
    busy = false;
    update();
  }

  void gotoLogIn() {
    Get.offNamed('/auth/login');
  }
}
