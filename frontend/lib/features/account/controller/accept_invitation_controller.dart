import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';

/// Pagina aperta dal link dell'email: /auth/invite?token=...
///
/// - nessuna sessione, email senza account -> form di registrazione;
/// - nessuna sessione, account esistente   -> "accedi per accettare";
/// - sessione con la stessa email          -> accettazione con un clic;
/// - sessione con un'altra email           -> invito a cambiare account.
class AcceptInvitationController extends GetxController {
  final SessionService session = SessionService.to;
  final ModuloValidato basicValidator = ModuloValidato();

  late final String token = Get.parameters['token'] ?? '';
  bool loading = true;
  bool submitting = false;
  String? error;
  InvitationPreview? preview;

  bool get isLoggedIn => session.me != null;

  bool get emailMatchesSession => session.me?.email == preview?.email;

  @override
  void onInit() {
    super.onInit();
    basicValidator.addField('first_name', required: true, label: 'Nome', validators: [RegolaLunghezza(max: 100)], controller: TextEditingController());
    basicValidator.addField('last_name', required: true, label: 'Cognome', validators: [RegolaLunghezza(max: 100)], controller: TextEditingController());
    basicValidator.addField('password', required: true, label: 'Password', validators: [RegolaLunghezza(min: 10, max: 4096)], controller: TextEditingController());
    load();
  }

  Future<void> load() async {
    if (token.isEmpty) {
      loading = false;
      error = 'Link di invito non valido.';
      update();
      return;
    }
    try {
      preview = await TenantApi.previewInvitation(token);
      if (!preview!.isPending) {
        error = 'Questo invito è scaduto, è stato revocato o è già stato utilizzato.';
      }
    } on ApiException catch (e) {
      error = e.statusCode == 404 ? 'Link di invito non valido.' : e.message;
    }
    loading = false;
    update();
  }

  /// Utente già autenticato con la stessa email.
  Future<void> acceptAsCurrentUser() async {
    await _submit(() => TenantApi.acceptInvitation(token, authenticated: true));
  }

  /// Nuovo account: registrazione + accesso automatico.
  Future<void> registerAndAccept() async {
    if (!basicValidator.validateForm()) {
      return;
    }
    final data = basicValidator.getData();
    await _submit(() async {
      final tenantId = await TenantApi.acceptInvitation(
        token,
        firstName: data['first_name'].toString(),
        lastName: data['last_name'].toString(),
        password: data['password'].toString(),
      );
      await session.login(preview!.email, data['password'].toString());
      return tenantId;
    }, fieldErrors: true);
  }

  void goToLogin() {
    Get.toNamed('/auth/login?next=${Uri.encodeComponent('/auth/invite?token=$token')}');
  }

  Future<void> switchAccount() async {
    await session.logout();
    goToLogin();
  }

  Future<void> _submit(Future<String> Function() action, {bool fieldErrors = false}) async {
    if (submitting) {
      return;
    }
    submitting = true;
    update();
    try {
      final tenantId = await action();
      await session.loadMe();
      await session.selectTenant(tenantId);
      Get.offAllNamed('/tenant/members');
      Get.snackbar('Benvenuto!', 'Ora fai parte di "${preview?.tenantName}".',
          snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
    } on ApiException catch (e) {
      if (fieldErrors && e.fieldErrors.isNotEmpty) {
        basicValidator.addErrors(e.fieldErrors.map((k, v) => MapEntry(
              switch (k) { 'firstName' => 'first_name', 'lastName' => 'last_name', _ => k },
              v,
            )));
        basicValidator.validateForm();
        basicValidator.clearErrors();
      } else {
        error = e.message;
      }
    }
    submitting = false;
    update();
  }
}
