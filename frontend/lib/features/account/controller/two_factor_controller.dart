import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/login_controller.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Secondo passo del login: codice a 6 cifre dell'app di autenticazione
/// (Google Authenticator & co.) oppure un codice di recupero.
class TwoFactorController extends GetxController {
  final TextEditingController code = TextEditingController();
  bool useBackupCode = false;
  bool loading = false;
  String? error;

  @override
  void onReady() {
    super.onReady();
    if (SessionService.to.pendingChallenge == null) {
      // Pagina aperta direttamente o dopo un refresh: si riparte dal login.
      Get.offAllNamed('/auth/login');
    }
  }

  @override
  void onClose() {
    code.dispose();
    super.onClose();
  }

  void toggleBackupCode() {
    useBackupCode = !useBackupCode;
    code.clear();
    error = null;
    update();
  }

  Future<void> submit() async {
    final value = code.text.trim();
    if (loading || value.isEmpty) {
      return;
    }
    loading = true;
    error = null;
    update();
    try {
      await SessionService.to.completeTwoFactor(value);
      Get.offAllNamed(SessionService.to.homeRoute(next: LoginController.safeNextUrl(Get.parameters['next'])));
    } on ApiException catch (e) {
      error = e.message;
      code.clear();
      if (e.message.contains('ripeti il login')) {
        SessionService.to.pendingChallenge = null;
      }
    }
    loading = false;
    update();
  }

  void backToLogin() {
    SessionService.to.pendingChallenge = null;
    Get.offAllNamed('/auth/login');
  }
}
