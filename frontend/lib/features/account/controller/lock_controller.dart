import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Schermata di blocco: per sbloccare si reinserisce la password dell'utente corrente
/// (nuovo login, quindi nuova coppia di token).
class LockController extends GetxController {
  ModuloValidato basicValidator = ModuloValidato();
  bool loading = false;

  String get userName => SessionService.to.me?.fullName ?? '';

  @override
  void onInit() {
    basicValidator.addField('password', required: true, label: "Password", controller: TextEditingController());
    super.onInit();
  }

  Future<void> onLogin() async {
    final email = SessionService.to.me?.email;
    if (email == null) {
      Get.offAllNamed('/auth/login');
      return;
    }
    if (loading || !basicValidator.validateForm()) {
      return;
    }
    loading = true;
    update();
    try {
      final result = await SessionService.to.login(email, basicValidator.getData()['password'].toString());
      if (result == LoginResult.twoFactorRequired) {
        Get.offAllNamed('/auth/two-factor');
      } else {
        Get.offAllNamed(SessionService.to.homeRoute());
      }
    } on ApiException catch (e) {
      basicValidator.addErrors({'password': e.message});
      basicValidator.validateForm();
      basicValidator.clearErrors();
    }
    loading = false;
    update();
  }

  Future<void> goToLoginAsOtherUser() async {
    await SessionService.to.logout();
    Get.offAllNamed('/auth/login');
  }
}
