import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;

/// Avviso in cima a ogni pagina quando la famiglia è in eliminazione (sola lettura).
class EliminazioneBanner extends StatelessWidget {
  const EliminazioneBanner({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SessionService>(builder: (session) {
      final data = session.currentTenant?.eliminazioneProgrammataIl;
      if (!session.isReadOnly || data == null) {
        return const SizedBox.shrink();
      }
      return Container(
        margin: EdgeInsets.fromLTRB(spazioPagina, 0, spazioPagina, spazioPagina),
        padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(
          color: context.colori.danger.withAlpha(24),
          border: Border.all(color: context.colori.danger.withAlpha(80)),
          borderRadius: BorderRadius.circular(6),
        ),
        child: Row(children: [
          Icon(LucideIcons.triangle_alert, color: context.colori.danger, size: 20),
          SizedBox(width: 12),
          Expanded(
            child: Testo.bodyMedium('Questa famiglia sarà eliminata il ${formatDataBreve(data)}. Fino ad allora è in sola lettura: puoi consultare e scaricare i documenti.', color: context.colori.danger),
          ),
          if (session.currentMembership?.isOwner == true && session.currentTenant?.eliminazioneDalPortale != true)
            TextButton(onPressed: () => Get.toNamed('/famiglia/impostazioni', arguments: 'dati'), child: Text('Annulla l\'eliminazione')),
        ]),
      );
    });
  }
}
