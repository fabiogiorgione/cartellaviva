import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Avviso in cima a ogni pagina finché l'email non è verificata, con "invia di nuovo".
class VerificaEmailBanner extends StatelessWidget {
  const VerificaEmailBanner({super.key});

  Future<void> _invia() async {
    try {
      final messaggio = await AccountApi.inviaVerificaEmail();
      Get.snackbar('Email di verifica', messaggio, snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16), maxWidth: 480);
    } on ApiException catch (e) {
      Get.snackbar('Email di verifica', e.message, snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16), maxWidth: 480);
    }
  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SessionService>(builder: (session) {
      final me = session.me;
      if (me == null || me.emailVerificata) {
        return const SizedBox.shrink();
      }
      return Container(
        margin: EdgeInsets.fromLTRB(spazioPagina, 0, spazioPagina, spazioPagina),
        padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: context.colori.warning.withAlpha(30),
          border: Border.all(color: context.colori.warning.withAlpha(90)),
          borderRadius: BorderRadius.circular(6),
        ),
        child: Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Icon(LucideIcons.mail_warning, color: context.colori.warning, size: 20),
          Testo.bodyMedium('Conferma il tuo indirizzo ${me.email}: apri il link che ti abbiamo mandato. Fino ad allora non puoi invitare persone né collegarti con un ente.'),
          TextButton(onPressed: _invia, child: const Text('Invia di nuovo')),
          TextButton(onPressed: () => session.loadMe(), child: const Text('L\'ho già confermato')),
        ]),
      );
    });
  }
}
