import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// La pagina di logout chiude davvero la sessione: il refresh token viene
/// invalidato sul server e i token locali cancellati.
class LogOutController extends GetxController {
  @override
  void onInit() {
    super.onInit();
    SessionService.to.logout();
  }

  void goToLogInScreen() {
    Get.offAllNamed('/auth/login');
  }
}
