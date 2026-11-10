import 'dart:async';

import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Login: password o Google come primo fattore; se l'account ha la verifica in
/// due passaggi si prosegue su /auth/two-factor, altrimenti onboarding o app.
class LoginController extends GetxController {
  ModuloValidato basicValidator = ModuloValidato();
  bool loading = false;
  /// "Registrati" compare solo se il server la accetta: senza risposta resta nascosto.
  bool registrazioneAperta = false;
  StreamSubscription<String>? _googleTokens;

  String get _next => safeNextUrl(Get.parameters['next']);

  @override
  void onInit() {
    _caricaRegistrazione();
    basicValidator.addField('email',
        required: true, label: "Email", validators: [RegolaEmail()], controller: TextEditingController());

    basicValidator.addField('password', required: true, label: "Password", controller: TextEditingController());
    // Web: l'ID token arriva dal pulsante ufficiale di Google.
    _googleTokens = GoogleAuthService.idTokens.listen(_loginWithGoogleToken);
    super.onInit();
  }

  @override
  void onClose() {
    _googleTokens?.cancel();
    super.onClose();
  }

  Future<void> _caricaRegistrazione() async {
    try {
      registrazioneAperta = await AccountApi.registrazioneAperta();
      update();
    } on ApiException {
      // Server non raggiungibile: il link resta nascosto, l'accesso mostra già l'errore.
    }
  }

  Future<void> onLogin() async {
    if (loading || !basicValidator.validateForm()) {
      return;
    }
    await _run(() {
      final data = basicValidator.getData();
      return SessionService.to.login(data['email'].toString(), data['password'].toString());
    }, errorField: 'password');
  }

  /// Mobile: selettore account Google nativo.
  Future<void> onGoogleLogin() async {
    try {
      final idToken = await GoogleAuthService.signIn();
      if (idToken != null) {
        await _loginWithGoogleToken(idToken);
      }
    } catch (_) {
      _showError('Accesso con Google non riuscito.');
    }
  }

  Future<void> _loginWithGoogleToken(String idToken) async {
    if (loading) {
      return;
    }
    await _run(() => SessionService.to.loginWithGoogle(idToken));
  }

  Future<void> _run(Future<LoginResult> Function() attempt, {String? errorField}) async {
    loading = true;
    update();
    try {
      final result = await attempt();
      if (result == LoginResult.twoFactorRequired) {
        Get.toNamed('/auth/two-factor?next=${Uri.encodeComponent(_next)}');
      } else {
        Get.offAllNamed(SessionService.to.homeRoute(next: _next));
      }
    } on ApiException catch (e) {
      if (errorField != null) {
        basicValidator.addErrors({errorField: e.message});
        basicValidator.validateForm();
        basicValidator.clearErrors();
      } else {
        _showError(e.message);
      }
    }
    loading = false;
    update();
  }

  void _showError(String message) {
    Get.snackbar('Accesso non riuscito', message, snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
  }

  /// Accetta solo percorsi interni: evita open redirect via ?next=https://...
  /// Delle pagine /auth/ è ammessa solo l'accettazione invito.
  static String safeNextUrl(String? next) {
    if (next == null || !next.startsWith('/') || next.startsWith('//') || next.contains('\\')) {
      return '/dashboard';
    }
    if (next.startsWith('/auth/') && !next.startsWith('/auth/invite')) {
      return '/dashboard';
    }
    return next;
  }

  void gotoForgotPasswordScreen() {
    Get.toNamed('/auth/forgot_password');
  }

  void goToRegisterScreen() {
    Get.toNamed('/auth/register_account');
  }
}
