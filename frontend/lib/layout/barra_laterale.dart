import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/services/url_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/tenant_switcher.dart';
import 'package:cartellaviva/route/route_method.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Menu dell'app a sinistra. [compatta]: solo le icone (larga 60 invece di 240).
class BarraLaterale extends StatelessWidget {
  final bool compatta;

  const BarraLaterale({super.key, this.compatta = false});

  @override
  Widget build(BuildContext context) {
    final colori = context.colori;
    return AnimatedContainer(
      width: compatta ? 60 : 240,
      duration: const Duration(milliseconds: 300),
      curve: Curves.easeIn,
      color: colori.barraSfondo,
      child: ScrollConfiguration(
        behavior: ScrollConfiguration.of(context).copyWith(scrollbars: false),
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(height: compatta ? 20 : 12),
            if (!compatta) const TenantSwitcher(),
            GetBuilder<SessionService>(builder: (session) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: _voci(session))),
            _sezione(context, 'Account'),
            _voce(LucideIcons.id_card, 'Dati anagrafici', route.accountProfile),
            _voce(LucideIcons.shield_check, 'Sicurezza', route.accountSecurity),
            const SizedBox(height: 32),
          ]),
        ),
      ),
    );
  }

  Widget _voce(IconData icona, String titolo, String rotta) => VoceMenu(icona: icona, titolo: titolo, rotta: rotta, compatta: compatta);

  Widget _sezione(BuildContext context, String titolo) => compatta
      ? const SizedBox.shrink()
      : Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
          child: Testo.labelSmall(titolo.toUpperCase(), color: context.colori.barraEtichetta, tenue: true, maxLines: 1, overflow: TextOverflow.clip, fontWeight: FontWeight.w500),
        );

  /// Voci secondo il tipo di spazio e i permessi (solo aspetto: il backend ricontrolla).
  List<Widget> _voci(SessionService session) {
    final membership = session.currentMembership;
    final me = session.me;
    Widget sezione(String t) => Builder(builder: (context) => _sezione(context, t));
    final portale = me == null || !me.isPortale
        ? <Widget>[]
        : [
            sezione('Portale'),
            _voce(LucideIcons.activity, 'Salute e da fare', '/admin'),
            if (me.isSupporto) ...[
              _voce(LucideIcons.building, 'Famiglie ed enti', '/admin/tenant'),
              _voce(LucideIcons.shield_check, 'Enti da verificare', route.adminEnti),
              _voce(LucideIcons.user_search, 'Utenti e supporto', '/admin/utenti'),
              _voce(LucideIcons.key_round, "Recuperi d'accesso", '/admin/recuperi'),
            ],
            _voce(LucideIcons.lightbulb, 'Idee degli utenti', '/admin/idee'),
            _voce(LucideIcons.scroll_text, 'Registro globale', '/admin/registro'),
            if (me.platformRole == 'superadmin') _voce(LucideIcons.users_round, 'Team del portale', '/admin/team'),
          ];
    if (membership == null) {
      return [
        ...portale,
        if (!(me?.isPortale ?? false)) ...[sezione('Inizia'), _voce(LucideIcons.house, 'Benvenuto', route.benvenuto)],
      ];
    }
    if (membership.tenantType == 'azienda') {
      return [
        sezione('Ente'),
        _voce(LucideIcons.layout_dashboard, 'Cruscotto', route.ente),
        if (session.can('iscritto:read')) _voce(LucideIcons.users, 'Iscritti', route.enteIscritti),
        if (session.can('ente:read')) _voce(LucideIcons.layers, 'Gruppi e classi', route.enteGruppi),
        if (session.can('richiesta_ente:read')) _voce(LucideIcons.send, 'Richieste', route.enteRichieste),
        if (session.can('richiesta_ente:read')) _voce(LucideIcons.file_stack, 'Modelli e regole', '/ente/modelli'),
        if (session.can('richiesta_ente:verify')) _voce(LucideIcons.list_checks, 'Verifica risposte', '/ente/verifica'),
        if (session.can('audit:read')) _voce(LucideIcons.scroll_text, 'Registro attività', '/ente/registro'),
        sezione('Organizzazione'),
        if (session.can('membro:read')) _voce(LucideIcons.user_cog, 'Operatori', route.tenantMembers),
        if (session.can('ente:read')) _voce(LucideIcons.badge_check, 'Profilo e verifica', route.enteProfilo),
        ...portale,
      ];
    }
    return [
      sezione('Principale'),
      _voce(LucideIcons.layout_dashboard, 'Dashboard', route.dashboard),
      if (session.can('documento:read'))
        GruppoMenu(icona: LucideIcons.files, titolo: 'Documenti', compatta: compatta, voci: [
          (titolo: 'Tutti i documenti', rotta: route.documenti),
          (titolo: 'Categorie', rotta: route.categorie),
        ]),
      if (session.can('documento:create')) _voce(LucideIcons.scan_line, 'Scansiona', route.nuovoDocumento),
      if (session.can('scadenza:read')) _voce(LucideIcons.calendar_clock, 'Scadenze', route.scadenze),
      if (session.can('richiesta:read')) _voce(LucideIcons.inbox, 'Richieste', route.richieste),
      ...portale,
      sezione('Famiglia'),
      if (session.can('membro:read')) _voce(LucideIcons.users, 'Membri e accessi', route.tenantMembers),
      if (session.can('membro_famiglia:read') || membership.isOwner) _voce(LucideIcons.settings, 'Impostazioni famiglia', route.famigliaImpostazioni),
    ];
  }
}

/// Riga del menu con lo sfondo evidenziato quando è attiva o sotto il puntatore.
class _Riga extends StatefulWidget {
  final bool attiva;
  final VoidCallback onTap;
  final Widget Function(Color colore) contenuto;
  final EdgeInsets padding;

  const _Riga({required this.attiva, required this.onTap, required this.contenuto, required this.padding});

  @override
  State<_Riga> createState() => _RigaState();
}

class _RigaState extends State<_Riga> {
  bool sopra = false;

  @override
  Widget build(BuildContext context) {
    final colori = context.colori;
    final evidenziata = widget.attiva || sopra;
    return MouseRegion(
      cursor: SystemMouseCursors.click,
      onEnter: (_) => setState(() => sopra = true),
      onExit: (_) => setState(() => sopra = false),
      child: GestureDetector(
        onTap: widget.onTap,
        child: Container(
          margin: const EdgeInsets.only(bottom: 6),
          padding: widget.padding,
          color: evidenziata ? colori.barraAttiva.withAlpha(36) : Colors.transparent,
          child: widget.contenuto(evidenziata ? colori.barraAttiva : colori.barraTesto),
        ),
      ),
    );
  }
}

/// Una voce del menu: icona e titolo; nella barra compatta solo l'icona.
class VoceMenu extends StatelessWidget {
  final IconData icona;
  final String titolo;
  final String rotta;
  final bool compatta;

  const VoceMenu({super.key, required this.icona, required this.titolo, required this.rotta, this.compatta = false});

  @override
  Widget build(BuildContext context) {
    final riga = _Riga(
      attiva: UrlService.getCurrentUrl() == rotta,
      onTap: () => Get.toNamed(rotta),
      padding: compatta ? const EdgeInsets.symmetric(vertical: 10) : const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      contenuto: (colore) => compatta
          ? Center(child: Icon(icona, size: 20, color: colore))
          : Row(children: [
              Icon(icona, size: 20, color: colore),
              const SizedBox(width: 16),
              Expanded(child: Testo.titleSmall(titolo, fontWeight: FontWeight.w400, color: colore, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis)),
            ]),
    );
    return compatta ? Tooltip(message: titolo, child: riga) : riga;
  }
}

/// Voce con sottovoci (es. Documenti › Tutti i documenti, Categorie): aperta
/// se una delle sottovoci è la pagina corrente. Nella barra compatta, un menu.
class GruppoMenu extends StatefulWidget {
  final IconData icona;
  final String titolo;
  final bool compatta;
  final List<({String titolo, String rotta})> voci;

  const GruppoMenu({super.key, required this.icona, required this.titolo, required this.voci, this.compatta = false});

  @override
  State<GruppoMenu> createState() => _GruppoMenuState();
}

class _GruppoMenuState extends State<GruppoMenu> {
  late bool aperto = widget.voci.any((v) => v.rotta == UrlService.getCurrentUrl());

  @override
  Widget build(BuildContext context) {
    final colori = context.colori;
    final attivo = widget.voci.any((v) => v.rotta == UrlService.getCurrentUrl());
    if (widget.compatta) {
      return PopupMenuButton<String>(
        tooltip: widget.titolo,
        position: PopupMenuPosition.over,
        offset: const Offset(60, 0),
        onSelected: Get.toNamed,
        itemBuilder: (_) => [for (final v in widget.voci) PopupMenuItem(value: v.rotta, child: Text(v.titolo))],
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10),
          child: Center(child: Icon(widget.icona, size: 20, color: attivo ? colori.barraAttiva : colori.barraTesto)),
        ),
      );
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _Riga(
        attiva: false,
        onTap: () => setState(() => aperto = !aperto),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        contenuto: (colore) => Row(children: [
          Icon(widget.icona, size: 20, color: attivo ? colori.barraAttiva : colore),
          const SizedBox(width: 16),
          Expanded(child: Testo.titleSmall(widget.titolo, fontWeight: FontWeight.w400, color: attivo ? colori.barraAttiva : colore, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis)),
          AnimatedRotation(turns: aperto ? .5 : 0, duration: const Duration(milliseconds: 200), child: Icon(LucideIcons.chevron_down, size: 18, color: colore)),
        ]),
      ),
      if (aperto)
        for (final v in widget.voci) SottoVoceMenu(titolo: v.titolo, rotta: v.rotta),
    ]);
  }
}

/// Sottovoce di un [GruppoMenu]: un punto e il titolo, su una sola riga.
class SottoVoceMenu extends StatelessWidget {
  final String titolo;
  final String rotta;

  const SottoVoceMenu({super.key, required this.titolo, required this.rotta});

  @override
  Widget build(BuildContext context) => _Riga(
        attiva: UrlService.getCurrentUrl() == rotta,
        onTap: () => Get.toNamed(rotta),
        padding: const EdgeInsets.only(left: 48, right: 16, top: 8, bottom: 8),
        contenuto: (colore) => Row(children: [
          Container(width: 6, height: 6, decoration: BoxDecoration(color: colore, shape: BoxShape.circle)),
          const SizedBox(width: 12),
          // Una riga sola: andando a capo si vedrebbe solo la prima parte ("Tutti i").
          Expanded(child: Testo.titleSmall(titolo, fontWeight: FontWeight.w400, color: colore, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis)),
        ]),
      );
}
