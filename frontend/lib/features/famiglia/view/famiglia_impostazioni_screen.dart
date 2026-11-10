import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/view/tab_categorie.dart';
import 'package:cartellaviva/features/famiglia/view/tab_dati.dart';
import 'package:cartellaviva/features/import/view/tab_drive.dart';
import 'package:cartellaviva/features/emergenza/view/tab_emergenza.dart';
import 'package:cartellaviva/features/famiglia/view/tab_enti.dart';
import 'package:cartellaviva/features/famiglia/view/tab_generale.dart';
import 'package:cartellaviva/features/famiglia/view/tab_persone.dart';
import 'package:cartellaviva/features/famiglia/view/tab_ruoli.dart';
import 'package:cartellaviva/layout/layout.dart';

/// Impostazioni della famiglia, a schede. Ogni scheda compare solo se il ruolo
/// la può usare (il backend ricontrolla comunque ogni operazione).
/// Si può aprire su una scheda precisa con ?scheda=drive (es. ritorno da Google).
class FamigliaImpostazioniScreen extends StatelessWidget {
  FamigliaImpostazioniScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<SessionService>(builder: (session) {
        final membership = session.currentMembership;
        final tenant = session.currentTenant;
        final tabs = <(String, String, Widget)>[
          ('generale', 'Generale', TabGenerale()),
          if (session.can('membro_famiglia:read')) ('persone', 'Persone', TabPersone()),
          ('categorie', 'Categorie', TabCategorie()),
          if (session.can('collegamento:read')) ('enti', 'Enti collegati', TabEnti()),
          if (membership?.permissions.contains('import:manage') == true) ('drive', 'Google Drive', TabDrive()),
          if (tenant?.modalitaAvanzata == true && membership?.permissions.contains('ruolo:manage') == true) ('ruoli', 'Ruoli', TabRuoli()),
          if (session.can('membro:read') && membership?.roleSlug != 'emergenza') ('emergenza', 'Emergenza', TabEmergenza()),
      if (membership?.isOwner == true) ('dati', 'Dati', TabDati()),
        ];
        final wanted = Get.parameters['scheda'] ?? (Get.arguments is String ? Get.arguments as String : null);
        final initial = tabs.indexWhere((t) => t.$1 == wanted);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: EdgeInsets.symmetric(horizontal: spazioPagina),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Flexible(child: Testo.titleMedium('Impostazioni della famiglia', fontSize: 18, fontWeight: FontWeight.w500)),
                  Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Impostazioni', attiva: true)]),
                ],
              ),
            ),
            SizedBox(height: spazioPagina),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: spazioPagina),
              child: membership == null
                  ? Scheda(paddingAll: 24, child: Testo.bodyMedium('Seleziona una famiglia.'))
                  : DefaultTabController(
                      // La chiave cambia con le schede disponibili (es. modalità avanzata accesa).
                      key: ValueKey('${membership.tenantId}-${tabs.map((t) => t.$1).join(',')}'),
                      length: tabs.length,
                      initialIndex: initial < 0 ? 0 : initial,
                      child: Scheda(paddingAll: 0, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          TabBar(
                            isScrollable: true,
                            tabAlignment: TabAlignment.start,
                            labelColor: context.colori.primary,
                            indicatorColor: context.colori.primary,
                            tabs: [for (final t in tabs) Tab(text: t.$2)],
                          ),
                          const Divider(height: 1),
                          // Altezza libera: ogni scheda è una colonna (la pagina scorre già).
                          Builder(builder: (context) {
                            final controller = DefaultTabController.of(context);
                            return AnimatedBuilder(
                              animation: controller,
                              builder: (_, __) => Padding(padding: EdgeInsets.all(20), child: tabs[controller.index].$3),
                            );
                          }),
                        ])),
                    ),
            ),
          ],
        );
      }),
    );
  }
}
