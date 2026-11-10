import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';

/// Notifiche push (Firebase Cloud Messaging), solo su Android e iOS.
///
/// La configurazione arriva da --dart-define (mai file di Firebase nel
/// repository): FIREBASE_API_KEY, FIREBASE_PROJECT_ID, FIREBASE_SENDER_ID,
/// FIREBASE_ANDROID_APP_ID, FIREBASE_IOS_APP_ID. Se manca, l'app funziona
/// come prima e gli avvisi arrivano solo per email.
///
/// Le notifiche non contengono dati riservati: toccandole si apre la pagina
/// indicata dal server (es. /richieste), dove i dettagli si leggono da autenticati.
class PushService {
  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const _androidAppId = String.fromEnvironment('FIREBASE_ANDROID_APP_ID');
  static const _iosAppId = String.fromEnvironment('FIREBASE_IOS_APP_ID');

  static String? _token;
  static bool _inAscolto = false;
  static StreamSubscription<String>? _refresh;

  static String? get _piattaforma => kIsWeb
      ? null
      : switch (defaultTargetPlatform) {
          TargetPlatform.android => 'android',
          TargetPlatform.iOS => 'ios',
          _ => null,
        };

  static String get _appId => _piattaforma == 'ios' ? _iosAppId : _androidAppId;

  static bool get configurato => _piattaforma != null && _apiKey.isNotEmpty && _projectId.isNotEmpty && _senderId.isNotEmpty && _appId.isNotEmpty;

  /// Dopo l'accesso: chiede il permesso (una volta) e registra il telefono.
  static Future<void> attiva() async {
    if (!configurato) return;
    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp(options: FirebaseOptions(apiKey: _apiKey, appId: _appId, messagingSenderId: _senderId, projectId: _projectId));
      }
      final messaging = FirebaseMessaging.instance;
      final permesso = await messaging.requestPermission();
      if (permesso.authorizationStatus == AuthorizationStatus.denied) return;
      final token = await messaging.getToken();
      if (token == null) return;
      await _registra(token);
      _refresh ??= messaging.onTokenRefresh.listen(_registra);
      if (!_inAscolto) {
        _inAscolto = true;
        FirebaseMessaging.onMessageOpenedApp.listen(_apri);
        FirebaseMessaging.onMessage.listen(_inPrimoPiano);
        final iniziale = await messaging.getInitialMessage();
        if (iniziale != null) _apri(iniziale);
      }
    } catch (e) {
      // Le push sono un di più: un errore non deve bloccare l'app.
      debugPrint('Push non attivate: $e');
    }
  }

  /// Prima del logout (ancora autenticati): il telefono non riceve più avvisi per questo account.
  static Future<void> disattiva() async {
    final token = _token;
    if (token == null) return;
    _token = null;
    try {
      await ApiClient.dio.delete('/me/dispositivi', data: {'token': token});
      await FirebaseMessaging.instance.deleteToken();
    } catch (e) {
      debugPrint('Rimozione del dispositivo non riuscita: $e');
    }
  }

  static Future<void> _registra(String token) async {
    _token = token;
    await ApiClient.dio.post('/me/dispositivi', data: {'token': token, 'piattaforma': _piattaforma});
  }

  /// Solo percorsi interni dell'app: il dato arriva da fuori, non si apre altro.
  static String? routeSicura(Object? route) => route is String && route.startsWith('/') && !route.startsWith('//') ? route : null;

  static void _apri(RemoteMessage m) {
    final route = routeSicura(m.data['route']);
    if (route != null) Get.toNamed(route);
  }

  static void _inPrimoPiano(RemoteMessage m) {
    final n = m.notification;
    if (n == null) return;
    final route = routeSicura(m.data['route']);
    Get.snackbar(
      n.title ?? 'CartellaViva',
      n.body ?? '',
      snackPosition: SnackPosition.TOP,
      margin: const EdgeInsets.all(16),
      maxWidth: 480,
      onTap: route == null ? null : (_) => Get.toNamed(route),
    );
  }
}
