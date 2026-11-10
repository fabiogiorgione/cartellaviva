import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Sicurezza dell'account: verifica in due passaggi (TOTP) e collegamento Google.
class AccountSecurityController extends GetxController {
  bool loading = true;
  String? error;
  SecurityStatus? status;

  /// Attivazione in corso: segreto e URI per il QR code.
  Map<String, String>? setup;

  /// Codici di recupero appena generati (mostrati una sola volta).
  List<String>? freshBackupCodes;

  final TextEditingController code = TextEditingController();
  bool busy = false;
  String? codeError;
  StreamSubscription<String>? _googleTokens;

  @override
  void onInit() {
    super.onInit();
    // Web: collegamento tramite il pulsante ufficiale di Google.
    _googleTokens = GoogleAuthService.idTokens.listen(_linkGoogle);
    load();
  }

  @override
  void onClose() {
    _googleTokens?.cancel();
    code.dispose();
    super.onClose();
  }

  Future<void> load() async {
    loading = true;
    update();
    try {
      status = await AccountApi.security();
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    loading = false;
    update();
  }

  Future<void> startSetup() => _busy(() async {
        setup = await AccountApi.startTwoFactorSetup();
        freshBackupCodes = null;
      });

  void cancelSetup() {
    setup = null;
    code.clear();
    codeError = null;
    update();
  }

  Future<void> confirmSetup() => _busy(() async {
        freshBackupCodes = await AccountApi.confirmTwoFactor(code.text);
        setup = null;
        code.clear();
        await _refresh('Verifica in due passaggi attivata.');
      }, withCode: true);

  Future<void> disable() => _busy(() async {
        await AccountApi.disableTwoFactor(code.text);
        code.clear();
        freshBackupCodes = null;
        await _refresh('Verifica in due passaggi disattivata.');
      }, withCode: true);

  Future<void> regenerateBackupCodes() => _busy(() async {
        freshBackupCodes = await AccountApi.regenerateBackupCodes(code.text);
        code.clear();
        await _refresh('Nuovi codici di recupero generati: i precedenti non valgono più.');
      }, withCode: true);

  Future<void> copyBackupCodes() async {
    await Clipboard.setData(ClipboardData(text: (freshBackupCodes ?? []).join('\n')));
    _notify('Copiati', 'Conservali in un posto sicuro (password manager).');
  }

  /// Mobile: selettore account nativo.
  Future<void> linkGoogleNative() async {
    final idToken = await GoogleAuthService.signIn();
    if (idToken != null) {
      await _linkGoogle(idToken);
    }
  }

  Future<void> _linkGoogle(String idToken) => _busy(() async {
        await AccountApi.linkGoogle(idToken);
        await _refresh('Account Google collegato.');
      });

  Future<void> unlinkGoogle() => _busy(() async {
        await AccountApi.unlinkGoogle();
        await _refresh('Account Google scollegato.');
      });

  Future<void> _refresh(String message) async {
    status = await AccountApi.security();
    await SessionService.to.loadMe();
    _notify('Fatto', message);
  }

  Future<void> _busy(Future<void> Function() action, {bool withCode = false}) async {
    if (busy) {
      return;
    }
    if (withCode && code.text.trim().isEmpty) {
      codeError = 'Inserisci il codice';
      update();
      return;
    }
    busy = true;
    codeError = null;
    update();
    try {
      await action();
    } on ApiException catch (e) {
      if (withCode) {
        codeError = e.message;
      } else {
        _notify('Operazione non riuscita', e.message, isError: true);
      }
    }
    busy = false;
    update();
  }

  void _notify(String title, String message, {bool isError = false}) {
    Get.snackbar(title, message,
        snackPosition: SnackPosition.BOTTOM,
        margin: EdgeInsets.all(16),
        maxWidth: 480,
        backgroundColor: isError ? Colors.red.shade50 : Colors.green.shade50,
        colorText: isError ? Colors.red.shade900 : Colors.green.shade900);
  }
}
