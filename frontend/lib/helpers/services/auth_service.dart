import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Facciata usata dai controller di autenticazione.
/// Mantiene la convenzione originale: i metodi restituiscono una mappa
/// campo -> errore (da passare a ModuloValidato.addErrors) oppure null.
/// Il login (con eventuale secondo fattore) è in LoginController/SessionService.
class AuthService {
  static bool get isLoggedIn => SessionService.to.isLoggedIn;

  static Future<Map<String, String>?> registerUser(Map<String, dynamic> data) async {
    try {
      await SessionService.to.register(
        email: data['email'].toString(),
        password: data['password'].toString(),
        firstName: data['first_name'].toString(),
        lastName: data['last_name'].toString(),
      );
      return null;
    } on ApiException catch (e) {
      if (e.fieldErrors.isNotEmpty) {
        // Il backend usa camelCase, i moduli snake_case.
        return e.fieldErrors.map((key, value) => MapEntry(
              switch (key) { 'firstName' => 'first_name', 'lastName' => 'last_name', _ => key },
              value,
            ));
      }
      return {'email': e.message};
    }
  }
}
