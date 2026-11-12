import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/controller/da_confermare_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/services/url_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/helpers/services/offline/offline_store.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/layout/tenant_switcher.dart';
import 'package:cartellaviva/route/route_method.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Voce del menu a cui appartiene la pagina corrente: un dettaglio conta come
/// la sua lista (un documento è sotto "Documenti").
String rottaDelMenu() {
  final url = UrlService.getCurrentUrl();
  const sotto = {'/documento/': '/documenti', '/import/': '/documenti', '/richieste/': '/richieste', '/aiuto/': '/aiuto', '/ente/richieste/': '/ente/richieste'};
  for (final e in sotto.entries) {
    if (url.startsWith(e.key)) return e.value;
  }
  return url;
}

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
            const SizedBox(height: 32),
          ]),
        ),
      ),
    );
  }

  Widget _voce(IconData icona, String titolo, String rotta) => VoceMenu(icona: icona, titolo: titolo, rotta: rotta, compatta: compatta);

  Widget _sezione(BuildContext context, String titolo) => compatta
      ? Padding(padding: const EdgeInsets.fromLTRB(14, 2, 14, 8), child: Divider(height: 1, color: context.colori.barraEtichetta.withAlpha(90)))
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
      _voce(LucideIcons.house, 'Home', route.dashboard),
      sezione('Archivio'),
      if (session.can('documento:read'))
        GruppoMenu(icona: LucideIcons.files, titolo: 'Documenti', compatta: compatta, voci: [
          (titolo: 'Tutti i documenti', rotta: route.documenti),
          (titolo: 'Categorie', rotta: route.categorie),
          (titolo: 'Analisi AI', rotta: route.analisiDocumenti),
        ]),
      if (session.can('documento:create')) _voce(LucideIcons.scan_line, 'Scansiona o carica', route.nuovoDocumento),
      if (OfflineStore.supportato) _voce(LucideIcons.smartphone, 'Documenti offline', '/offline'),
      if (session.can('condivisione:manage', resourceOwnerId: me?.id)) _voce(LucideIcons.share_2, 'Condivisioni esterne', '/condivisioni'),
      sezione('Da sbrigare'),
      if (session.can('scadenza:manage', resourceOwnerId: me?.id) || session.can('pagamento:manage'))
        GetBuilder<DaConfermareService>(
          init: DaConfermareService.to,
          builder: (s) => VoceMenu(icona: LucideIcons.sparkles, titolo: 'Da confermare', rotta: route.daConfermare, compatta: compatta, contatore: s.totale),
        ),
      if (session.can('scadenza:read')) _voce(LucideIcons.calendar_clock, 'Scadenze', route.scadenze),
      if (session.can('pagamento:read'))
        GruppoMenu(icona: LucideIcons.folder_open, titolo: 'Pratiche', compatta: compatta, voci: [
          (titolo: 'Panoramica', rotta: route.pratiche),
          (titolo: 'Pagamenti', rotta: route.pagamenti),
        ]),
      if (session.can('richiesta:read')) _voce(LucideIcons.inbox, 'Richieste dagli enti', route.richieste),
      sezione('Famiglia'),
      if (session.can('membro:read')) _voce(LucideIcons.users, 'Membri e accessi', route.tenantMembers),
      if (session.can('membro_famiglia:read') || membership.isOwner) _voce(LucideIcons.settings, 'Impostazioni famiglia', route.famigliaImpostazioni),
      _voce(LucideIcons.life_buoy, "Contatto d'emergenza", '/emergenza'),
      ...portale,
      sezione('Aiuto'),
      _voce(LucideIcons.circle_question_mark, 'Aiuto e guide', '/aiuto'),
      _voce(LucideIcons.lightbulb, "Suggerisci un'idea", '/idee'),
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

  /// Numero in un piccolo tondo accanto al titolo (es. proposte da confermare); nascosto se 0.
  final int contatore;

  const VoceMenu({super.key, required this.icona, required this.titolo, required this.rotta, this.compatta = false, this.contatore = 0});

  @override
  Widget build(BuildContext context) {
    final riga = _Riga(
      attiva: rottaDelMenu() == rotta,
      onTap: () => _apri(context, rotta),
      padding: compatta ? const EdgeInsets.symmetric(vertical: 10) : const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      contenuto: (colore) => compatta
          ? Center(
              child: Stack(clipBehavior: Clip.none, children: [
                Icon(icona, size: 20, color: colore),
                if (contatore > 0) Positioned(right: -4, top: -3, child: Container(width: 9, height: 9, decoration: BoxDecoration(color: context.colori.danger, shape: BoxShape.circle))),
              ]),
            )
          : Row(children: [
              Icon(icona, size: 20, color: colore),
              const SizedBox(width: 16),
              Expanded(child: Testo.titleSmall(titolo, fontWeight: FontWeight.w400, color: colore, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis)),
              if (contatore > 0) _Contatore(contatore),
            ]),
    );
    return compatta ? Tooltip(message: contatore > 0 ? '$titolo ($contatore)' : titolo, child: riga) : riga;
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
  late bool aperto = widget.voci.any((v) => v.rotta == rottaDelMenu());

  @override
  Widget build(BuildContext context) {
    final colori = context.colori;
    final attivo = widget.voci.any((v) => v.rotta == rottaDelMenu());
    if (widget.compatta) {
      return PopupMenuButton<String>(
        tooltip: widget.titolo,
        position: PopupMenuPosition.over,
        offset: const Offset(60, 0),
        onSelected: apriDalMenu,
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
        attiva: rottaDelMenu() == rotta,
        onTap: () => _apri(context, rotta),
        padding: const EdgeInsets.only(left: 48, right: 16, top: 8, bottom: 8),
        contenuto: (colore) => Row(children: [
          Container(width: 6, height: 6, decoration: BoxDecoration(color: colore, shape: BoxShape.circle)),
          const SizedBox(width: 12),
          // Una riga sola: andando a capo si vedrebbe solo la prima parte ("Tutti i").
          Expanded(child: Testo.titleSmall(titolo, fontWeight: FontWeight.w400, color: colore, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis)),
        ]),
      );
}

/// Apre una voce del menu: chiude il cassetto (sul telefono) e apre la pagina
/// di primo livello, vedi [apriDalMenu].
void _apri(BuildContext context, String rotta) {
  Scaffold.maybeOf(context)?.closeDrawer();
  apriDalMenu(rotta);
}

/// Numero piccolo in un tondo, accanto a una voce del menu.
class _Contatore extends StatelessWidget {
  final int numero;

  const _Contatore(this.numero);

  @override
  Widget build(BuildContext context) => Container(
        constraints: const BoxConstraints(minWidth: 20),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
        decoration: BoxDecoration(color: context.colori.danger, borderRadius: BorderRadius.circular(10)),
        child: Text(numero > 99 ? '99+' : '$numero', textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600, height: 1.3)),
      );
}
