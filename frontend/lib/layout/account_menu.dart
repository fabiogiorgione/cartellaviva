import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Avatar con le iniziali dell'utente collegato.
/// Dove si trova il codice sorgente di questa versione (AGPL, sezione 13).
/// Chi pubblica una versione modificata deve indicare la sua: --dart-define=SOURCE_URL=...
const indirizzoCodiceSorgente = String.fromEnvironment('SOURCE_URL', defaultValue: 'https://github.com/fabiogiorgione/cartellaviva');

class AccountAvatar extends StatelessWidget {
  final double size;

  const AccountAvatar({super.key, this.size = 28});

  @override
  Widget build(BuildContext context) {
    return Riquadro.cerchio(paddingAll: 0, height: size, width: size, color: context.colori.primary.withAlpha(40), child: Center(
        child: Testo.labelMedium(SessionService.to.me?.initials ?? '', fontWeight: FontWeight.w600, color: context.colori.primary),
      ));
  }
}

/// Menu dell'account, uguale su desktop e mobile.
class AccountMenu extends StatelessWidget {
  /// Chiude il popup prima di navigare.
  final VoidCallback? onClose;

  const AccountMenu({super.key, this.onClose});

  @override
  Widget build(BuildContext context) {
    final session = SessionService.to;
    // Solo l'account: le pagine della famiglia e dei documenti sono nel menu a
    // sinistra (sul telefono, nel cassetto).
    final voci = <_Voce>[
      _Voce(LucideIcons.id_card, 'Dati anagrafici', () => apriDalMenu('/account/profile')),
      _Voce(LucideIcons.shield_check, 'Sicurezza e accesso', () => apriDalMenu('/account/security')),
      // AGPL: chi usa l'app in rete deve poter avere il codice sorgente.
      _Voce(LucideIcons.code, 'Codice sorgente', () => launchUrl(Uri.parse(indirizzoCodiceSorgente), mode: LaunchMode.externalApplication)),
      _Voce(LucideIcons.lock_keyhole, 'Blocca schermo', () => Get.offAllNamed('/auth/lock')),
      _Voce(LucideIcons.log_out, 'Esci', () => Get.offAllNamed('/auth/log_out'), pericolo: true),
    ];

    return Riquadro(borderRadiusAll: 8, width: 240, child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Testo.labelMedium("Ciao ${session.me?.firstName ?? ''}!", fontWeight: FontWeight.w500),
          SizedBox(height: 12),
          for (final (i, voce) in voci.indexed) ...[
            if (i > 0) SizedBox(height: 8),
            _bottone(context, voce),
          ],
        ],
      ));
  }

  Widget _bottone(BuildContext context, _Voce voce) {
    final colore = voce.pericolo ? context.colori.danger : context.colori.onBackground;
    return FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.transparent, padding: EdgeInsets.symmetric(horizontal: 8, vertical: 4), tapTargetSize: MaterialTapTargetSize.shrinkWrap, overlayColor: voce.pericolo ? context.colori.danger.withAlpha(28) : Theme.of(context).colorScheme.onSurface.withAlpha(20), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: () {
        onClose?.call();
        voce.apri();
      }, child: Row(
        children: [
          Icon(voce.icona, size: 14, color: colore),
          SizedBox(width: 8),
          Testo.labelMedium(voce.titolo, fontWeight: FontWeight.w500, color: voce.pericolo ? colore : null),
        ],
      ));
  }
}

class _Voce {
  final IconData icona;
  final String titolo;
  final VoidCallback apri;
  final bool pericolo;

  _Voce(this.icona, this.titolo, this.apri, {this.pericolo = false});
}
