import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/helpers/services/offline/offline_store.dart';
import 'package:cartellaviva/helpers/services/push_service.dart';
import 'package:cartellaviva/helpers/services/storage/local_storage.dart';
import 'package:cartellaviva/helpers/services/storage/token_storage.dart';
import 'package:cartellaviva/features/account/model/me.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart';

enum LoginResult { authenticated, twoFactorRequired }

/// Stato della sessione (utente corrente, tenant selezionato, permessi).
///
/// È un GetxController permanente, così le view possono ricostruirsi con
/// `GetBuilder<SessionService>` come il resto dell'app.
/// I permessi qui servono SOLO alla UI: il backend li ricontrolla sempre.
class SessionService extends GetxController {
  static SessionService get to => Get.find<SessionService>();

  Me? me;
  String? _currentTenantId;

  /// Dettaglio del tenant corrente (sola lettura, modalità avanzata...); null finché non è caricato.
  TenantDettaglio? currentTenant;

  bool get isLoggedIn => TokenStorage.hasSession;

  TenantMembership? get currentMembership => me?.membershipFor(_currentTenantId);

  String? get currentTenantId => currentMembership?.tenantId;

  /// Da chiamare in main() prima di runApp.
  static Future<void> init() async {
    final session = Get.put(SessionService(), permanent: true);
    ApiClient.onSessionExpired = session.expire;
    await GoogleAuthService.init();
    await TokenStorage.load();
    if (TokenStorage.hasSession) {
      try {
        await session.loadMe();
        unawaited(PushService.attiva());
      } on ApiException catch (e) {
        if (e.isUnauthorized) {
          await session.clear();
        }
        // Errori di rete: si resta "loggati" e si riprova alla prossima chiamata.
      }
    }
  }

  /// Challenge del secondo fattore in attesa (login riuscito al primo passo).
  String? pendingChallenge;

  /// Chi opera in un ente senza verifica in due passaggi: non è obbligatoria,
  /// ma la ricordiamo a ogni accesso (una volta per login o apertura dell'app).
  bool _promemoria2faMostrato = false;

  bool get serve2faPromemoria {
    final m = me;
    return !_promemoria2faMostrato && m != null && !m.twoFactorEnabled && !m.twoFactorObbligatoria && m.memberships.any((t) => t.tenantType == 'azienda');
  }

  void segnaPromemoria2faMostrato() => _promemoria2faMostrato = true;

  Future<LoginResult> login(String email, String password) async {
    try {
      final response = await ApiClient.dio.post(
        '/auth/login',
        data: {'email': email.trim(), 'password': password},
        options: ApiClient.anonymous,
      );
      return _handleLoginResponse(response.data as Map<String, dynamic>);
    } on DioException catch (e) {
      final error = ApiException.fromDio(e);
      if (error.isUnauthorized) {
        throw ApiException('Email o password non corretti.', statusCode: 401);
      }
      throw error;
    }
  }

  /// Primo fattore con Google: l'ID token viene verificato dal backend.
  Future<LoginResult> loginWithGoogle(String idToken) async {
    try {
      final response = await ApiClient.dio.post('/auth/google', data: {'idToken': idToken}, options: ApiClient.anonymous);
      return _handleLoginResponse(response.data as Map<String, dynamic>);
    } on DioException catch (e) {
      final error = ApiException.fromDio(e);
      if (error.isUnauthorized) {
        throw ApiException('Accesso con Google non riuscito.', statusCode: 401);
      }
      throw error;
    }
  }

  /// Secondo fattore: codice dell'app di autenticazione o codice di recupero.
  Future<void> completeTwoFactor(String code) async {
    final challenge = pendingChallenge;
    if (challenge == null) {
      throw ApiException('Sessione di accesso scaduta: ripeti il login.', statusCode: 401);
    }
    try {
      final response = await ApiClient.dio.post('/auth/2fa', data: {'challenge': challenge, 'code': code.trim()}, options: ApiClient.anonymous);
      pendingChallenge = null;
      await _handleLoginResponse(response.data as Map<String, dynamic>);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  Future<LoginResult> _handleLoginResponse(Map<String, dynamic> data) async {
    if (data['twoFactorRequired'] == true) {
      pendingChallenge = data['challenge'] as String;
      return LoginResult.twoFactorRequired;
    }
    await TokenStorage.save(data['token'] as String, data['refresh_token'] as String);
    _promemoria2faMostrato = false;
    await loadMe();
    unawaited(PushService.attiva());
    return LoginResult.authenticated;
  }

  /// Dove andare dopo il login (vedi [resolveHome]).
  String homeRoute({String? next}) => resolveHome(me, currentMembership, next: next);

  /// Percorsi raggiungibili anche con l'anagrafica da completare: rispondere a
  /// un invito o a una richiesta non deve richiedere codice fiscale e nascita.
  static const List<String> onboardingExempt = ['/account/', '/benvenuto', '/auth/invite', '/richieste', '/collegamenti', '/risposta', '/verifica-firma', '/idee', '/condivisione?', '/emergenza', '/aiuto', '/auth/verifica-email', '/auth/cambio-email', '/auth/reset-password', '/auth/attiva'];

  static bool isOnboardingExempt(String route) => onboardingExempt.any(route.startsWith);

  /// Home in base allo stato dell'utente:
  /// - pagina richiesta esplicitamente (es. invito, richiesta di un ente): ha la precedenza;
  /// - anagrafica da completare -> profilo;
  /// - nessuna famiglia né ente -> /benvenuto (crea la famiglia o attendi un invito);
  /// - tenant corrente di tipo azienda (ente) -> /ente; famiglia -> /dashboard.
  static String resolveHome(Me? me, TenantMembership? current, {String? next}) {
    final wanted = (next == null || next == '/dashboard' || next == '/') ? null : next;
    if (me != null && !me.profileCompleted) {
      return wanted != null && isOnboardingExempt(wanted) ? wanted : '/account/profile';
    }
    if (wanted != null) {
      return wanted;
    }
    if (me != null && me.memberships.isEmpty) {
      // Il team del portale senza famiglie né enti va direttamente al portale.
      return me.isPortale ? '/admin' : '/benvenuto';
    }
    return current?.tenantType == 'azienda' ? '/ente' : '/dashboard';
  }

  Future<void> register({
    required String email,
    required String password,
    required String firstName,
    required String lastName,
  }) async {
    try {
      await ApiClient.dio.post(
        '/auth/register',
        data: {'email': email.trim(), 'password': password, 'firstName': firstName.trim(), 'lastName': lastName.trim()},
        options: ApiClient.anonymous,
      );
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
    await login(email, password); // account nuovo: la 2FA non può essere attiva
  }

  /// Cambia (o imposta, per gli account Google) la password: questa sessione
  /// continua con i token nuovi, le altre vengono chiuse dal server.
  Future<void> cambiaPassword({String? attuale, required String nuova}) async {
    final data = await AccountApi.cambiaPassword(attuale: attuale, nuova: nuova);
    await TokenStorage.save(data['token'] as String, data['refresh_token'] as String);
    await loadMe();
  }

  Future<void> loadMe() async {
    try {
      final response = await ApiClient.dio.get('/me');
      me = Me.fromJson(response.data as Map<String, dynamic>);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }

    // Ripristina l'ultimo tenant scelto, se ne faccio ancora parte.
    final remembered = LocalStorage.getCurrentTenantId();
    if (me!.membershipFor(remembered) != null) {
      _currentTenantId = remembered;
    } else {
      _currentTenantId = me!.memberships.isNotEmpty ? me!.memberships.first.tenantId : null;
    }
    await refreshTenant();
  }

  Future<void> selectTenant(String tenantId) async {
    if (me?.membershipFor(tenantId) == null) {
      return;
    }
    _currentTenantId = tenantId;
    await LocalStorage.setCurrentTenantId(tenantId);
    await refreshTenant();
  }

  /// Ricarica il dettaglio del tenant corrente (dopo modifiche nelle impostazioni).
  Future<void> refreshTenant() async {
    final id = currentTenantId;
    if (id == null) {
      currentTenant = null;
    } else {
      try {
        currentTenant = await TenantApi.tenant(id);
      } on ApiException {
        // Non bloccante: la UI resta utilizzabile, il backend fa comunque fede.
        if (currentTenant?.id != id) currentTenant = null;
      }
    }
    update();
  }

  /// Famiglia in eliminazione: tutto in sola lettura fino all'annullamento.
  bool get isReadOnly => currentTenant?.id == currentTenantId && currentTenant?.solaLettura == true;

  /// Permesso nel tenant corrente (solo cosmetico, vedi TenantMembership.can).
  bool can(String permission, {String? resourceOwnerId}) {
    final user = me;
    if (user == null) {
      return false;
    }
    if (user.isSuperAdmin && platformAdminResources.contains(permission.split(':').first)) {
      return true;
    }
    // Stessa regola del voter: durante l'eliminazione programmata si può solo leggere.
    if (isReadOnly && permission.split(':')[1] != 'read') {
      return false;
    }
    return currentMembership?.can(permission, resourceOwnerId: resourceOwnerId, currentUserId: user.id) ?? false;
  }

  /// Logout esplicito: invalida il refresh token lato server, poi pulisce in locale.
  Future<void> logout() async {
    // Ancora autenticati: il telefono smette di ricevere avvisi per questo account.
    await PushService.disattiva();
    // Uscita esplicita: i documenti offline di questo account spariscono dal telefono.
    final userId = me?.id;
    if (userId != null) {
      try {
        await OfflineStore.svuota(userId);
      } catch (_) {}
    }
    final refreshToken = TokenStorage.refreshToken;
    if (refreshToken != null) {
      try {
        await ApiClient.dio.post('/auth/logout', data: {'refresh_token': refreshToken});
      } catch (_) {
        // Anche se il server non risponde, la sessione locale va chiusa.
      }
    }
    await clear();
  }

  Future<void> clear() async {
    await TokenStorage.clear();
    await GoogleAuthService.signOut();
    me = null;
    _currentTenantId = null;
    currentTenant = null;
    pendingChallenge = null;
    update();
  }

  /// Refresh token scaduto o revocato: ritorno al login mantenendo la pagina corrente.
  Future<void> expire() async {
    final wasLoggedIn = me != null;
    await clear();
    if (!wasLoggedIn) {
      return;
    }
    final current = Get.currentRoute;
    final next = current.isEmpty || current.startsWith('/auth') ? '' : '?next=${Uri.encodeComponent(current)}';
    Get.offAllNamed('/auth/login$next');
    Get.snackbar(
      'Sessione scaduta',
      'Per sicurezza è necessario accedere di nuovo.',
      snackPosition: SnackPosition.BOTTOM,
      margin: EdgeInsets.all(16),
      maxWidth: 480,
    );
  }
}
