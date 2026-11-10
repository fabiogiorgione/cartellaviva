import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_config.dart';
import 'package:cartellaviva/helpers/services/storage/token_storage.dart';

/// Client HTTP unico dell'app.
///
/// - aggiunge l'header `Authorization: Bearer (jwt)` a ogni richiesta;
/// - su 401 prova UNA volta il refresh del token e ripete la richiesta;
/// - richieste concorrenti che ricevono 401 condividono lo stesso refresh
///   (il refresh token è monouso: due refresh paralleli invaliderebbero la sessione);
/// - se il refresh viene rifiutato chiama [onSessionExpired].
class ApiClient {
  ApiClient._();

  /// Impostato da SessionService: pulizia sessione + ritorno al login.
  static Future<void> Function()? onSessionExpired;

  static final Dio dio = _create();

  static Future<bool>? _refreshing;

  /// Opzioni per le chiamate che non devono portare il JWT né innescare refresh
  /// (login, refresh, registrazione, anteprima invito).
  static Options get anonymous => Options(extra: {'skipAuth': true});

  static Dio _create() {
    final client = Dio(BaseOptions(
      baseUrl: ApiConfig.baseUrl,
      connectTimeout: Duration(seconds: 10),
      receiveTimeout: Duration(seconds: 20),
      contentType: Headers.jsonContentType,
      headers: {'Accept': 'application/json'},
    ));

    client.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        final token = TokenStorage.accessToken;
        if (options.extra['skipAuth'] != true && token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
      onError: (error, handler) async {
        final options = error.requestOptions;
        final canRetry = error.response?.statusCode == 401 &&
            options.extra['skipAuth'] != true &&
            options.extra['retried'] != true &&
            TokenStorage.hasSession;
        if (!canRetry) {
          return handler.next(error);
        }

        final refreshed = await refreshTokens();
        if (!refreshed) {
          // Refresh rifiutato dal server (token scaduto/revocato): sessione finita.
          if (!TokenStorage.hasSession) {
            await onSessionExpired?.call();
          }
          return handler.next(error);
        }

        options.extra['retried'] = true;
        options.headers['Authorization'] = 'Bearer ${TokenStorage.accessToken}';
        try {
          return handler.resolve(await client.fetch(options));
        } on DioException catch (retryError) {
          return handler.next(retryError);
        }
      },
    ));

    return client;
  }

  /// Rinnova la coppia di token. Più chiamanti concorrenti ricevono lo stesso Future.
  static Future<bool> refreshTokens() {
    return _refreshing ??= _doRefresh().whenComplete(() => _refreshing = null);
  }

  static Future<bool> _doRefresh() async {
    final refreshToken = TokenStorage.refreshToken;
    if (refreshToken == null) {
      return false;
    }
    try {
      final response = await dio.post(
        '/auth/refresh',
        data: {'refresh_token': refreshToken},
        options: anonymous,
      );
      await TokenStorage.save(response.data['token'] as String, response.data['refresh_token'] as String);
      return true;
    } on DioException catch (e) {
      // Solo un rifiuto esplicito chiude la sessione; un errore di rete no.
      final status = e.response?.statusCode;
      if (status == 401 || status == 400) {
        await TokenStorage.clear();
      }
      return false;
    }
  }
}
