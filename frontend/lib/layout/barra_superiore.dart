import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:universal_html/html.dart' as html;
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/account_menu.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Pulsante del tema chiaro/scuro.
class PulsanteTema extends StatelessWidget {
  final double dimensione;

  const PulsanteTema({super.key, this.dimensione = 20});

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: PreferenzeTema.scuro ? 'Tema chiaro' : 'Tema scuro',
        onPressed: PreferenzeTema.alternaModo,
        icon: Icon(PreferenzeTema.scuro ? LucideIcons.sun : LucideIcons.moon, size: dimensione, color: context.colori.testataTesto),
      );
}

/// Avatar che apre il menu dell'account; [conNome] mostra anche nome e ruolo.
class MenuAccount extends StatelessWidget {
  final bool conNome;

  const MenuAccount({super.key, this.conNome = false});

  @override
  Widget build(BuildContext context) {
    final session = SessionService.to;
    final membership = session.currentMembership;
    final ruolo = membership == null ? ((session.me?.isSuperAdmin ?? false) ? 'Amministratore' : '') : '${membership.roleName} · ${membership.tenantName}';
    return MenuAnchor(
      alignmentOffset: const Offset(-150, 4),
      menuChildren: [Builder(builder: (context) => AccountMenu(onClose: () => MenuController.maybeOf(context)?.close()))],
      builder: (context, menu, _) => InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: () => menu.isOpen ? menu.close() : menu.open(),
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const AccountAvatar(),
            if (conNome) ...[
              const SizedBox(width: 8),
              Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Testo.labelLarge(session.me?.fullName ?? '', fontWeight: FontWeight.w400),
                Testo.bodySmall(ruolo, fontWeight: FontWeight.w400, tenue: true),
              ]),
            ],
          ]),
        ),
      ),
    );
  }
}

/// Barra in alto sugli schermi larghi: logo, menu compatto, tema, schermo intero, account.
class BarraSuperiore extends StatefulWidget {
  const BarraSuperiore({super.key});

  @override
  State<BarraSuperiore> createState() => _BarraSuperioreState();
}

class _BarraSuperioreState extends State<BarraSuperiore> {
  bool schermoIntero = false;

  void _alternaSchermoIntero() {
    schermoIntero ? html.document.exitFullscreen() : html.document.documentElement?.requestFullscreen();
    setState(() => schermoIntero = !schermoIntero);
  }

  @override
  Widget build(BuildContext context) {
    final colori = context.colori;
    return Scheda(
      height: 70,
      borderRadiusAll: 0,
      elevazione: .5,
      padding: const EdgeInsets.symmetric(horizontal: 24),
      color: colori.testataSfondo.withAlpha(246),
      child: Row(children: [
        InkWell(onTap: () => Get.toNamed('/dashboard'), child: const LogoCartellaViva(altezza: 26)),
        const SizedBox(width: 16),
        IconButton(tooltip: 'Riduci o espandi il menu', onPressed: PreferenzeTema.alternaBarra, icon: Icon(Icons.menu, color: colori.testataTesto)),
        const Spacer(),
        const PulsanteTema(),
        IconButton(
          tooltip: schermoIntero ? 'Esci dallo schermo intero' : 'Schermo intero',
          onPressed: _alternaSchermoIntero,
          icon: Icon(schermoIntero ? LucideIcons.minimize : LucideIcons.maximize, size: 20, color: colori.testataTesto),
        ),
        const SizedBox(width: 8),
        const VerticalDivider(width: 0),
        const SizedBox(width: 8),
        const MenuAccount(conNome: true),
      ]),
    );
  }
}
