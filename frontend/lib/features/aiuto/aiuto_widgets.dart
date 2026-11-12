import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/contenuti_aiuto.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

// ---------------------------------------------------------------------------
// API dell'accoglienza
// ---------------------------------------------------------------------------

class PrimiPassiStato {
  final String tipo;
  final Map<String, bool> passi;
  final bool nascosto;

  PrimiPassiStato({required this.tipo, required this.passi, this.nascosto = false});

  factory PrimiPassiStato.fromJson(Map<String, dynamic> j) => PrimiPassiStato(
        tipo: j['tipo'] as String,
        passi: {for (final p in (j['passi'] as List).cast<Map>()) p['id'] as String: p['fatto'] == true},
        nascosto: j['nascosto'] == true,
      );

  int get fatti => passi.values.where((v) => v).length;
  bool get completati => passi.isNotEmpty && fatti == passi.length;
}

class AccoglienzaApi {
  static Dio get _dio => ApiClient.dio;

  static Future<PrimiPassiStato> primiPassi(String tenantId) => chiamaApi(() async => PrimiPassiStato.fromJson(Map<String, dynamic>.from((await _dio.get('/tenants/$tenantId/primi-passi')).data as Map)));

  static Future<void> nascondi(String tenantId, bool nascosto) => chiamaApi(() => _dio.post('/tenants/$tenantId/primi-passi', data: {'nascosto': nascosto}));

  static Future<void> introVista() => chiamaApi(() => _dio.post('/me/intro-vista'));
}

// ---------------------------------------------------------------------------
// Primi passi (dashboard famiglia e home ente)
// ---------------------------------------------------------------------------

/// Testo e destinazione di ogni passo calcolato dal server.
const Map<String, (IconData, String, String, String, Object?)> testiPassi = {
  'profilo': (LucideIcons.user_round, 'Completa il tuo profilo', 'Serve a riconoscerti nei documenti e negli enti.', '/account/profile', null),
  'persone': (LucideIcons.users, 'Aggiungi le persone della famiglia', 'Anche i bambini: ogni documento si collega a chi riguarda.', '/famiglia/impostazioni', 'persone'),
  'documento': (LucideIcons.scan_line, 'Carica il primo documento', 'Carta d\'identità, tessera sanitaria, patente...', '/documenti/nuovo', null),
  'scadenza': (LucideIcons.calendar, 'Aggiungi una scadenza', 'Ti avvisiamo per email prima della data.', '/scadenze', null),
  'famigliare': (LucideIcons.user_plus, 'Invita chi condivide la famiglia', 'Partner come membro, nonni come lettori.', '/tenant/members', null),
  'sicurezza': (LucideIcons.shield_check, 'Attiva la verifica in due passaggi', 'La protezione più utile per i tuoi documenti.', '/account/security', null),
  'emergenza': (LucideIcons.life_buoy, 'Scegli un contatto d\'emergenza', 'Facoltativo: chi può accedere se voi non potete.', '/famiglia/impostazioni', 'emergenza'),
  'ente_verificato': (LucideIcons.badge_check, 'Completa la verifica dell\'ente', 'Senza verifica non si inviano inviti alle famiglie.', '/ente/profilo', null),
  'ente_gruppi': (LucideIcons.layers, 'Crea i gruppi dell\'anno', 'Classi, sezioni o corsi.', '/ente/gruppi', null),
  'ente_iscritti': (LucideIcons.users, 'Inserisci gli iscritti', 'Uno per volta o importandoli da un file.', '/ente/iscritti', null),
  'ente_inviti': (LucideIcons.mail, 'Invita i referenti', 'Le famiglie si collegano con il loro account.', '/ente/iscritti', null),
  'ente_richiesta': (LucideIcons.send, 'Invia la prima richiesta', 'Una firma, un documento o una presa visione.', '/ente/richieste/nuova', null),
  'ente_operatori': (LucideIcons.user_plus, 'Aggiungi gli operatori', 'Insegnanti o allenatori, ognuno con i suoi gruppi.', '/tenant/members', null),
};

class PrimiPassiCard extends StatefulWidget {
  const PrimiPassiCard({super.key});

  @override
  State<PrimiPassiCard> createState() => _PrimiPassiCardState();
}

class _PrimiPassiCardState extends State<PrimiPassiCard> {
  PrimiPassiStato? stato;

  String? get tenantId => SessionService.to.currentTenantId;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final t = tenantId;
    if (t == null) return;
    try {
      final s = await AccoglienzaApi.primiPassi(t);
      if (mounted) setState(() => stato = s);
    } on ApiException {
      // Non bloccante.
    }
  }

  Future<void> _nascondi() async {
    final t = tenantId;
    if (t == null) return;
    setState(() => stato = null);
    try {
      await AccoglienzaApi.nascondi(t, true);
    } on ApiException {
      // Si ripresenterà al prossimo caricamento.
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = stato;
    if (s == null || s.nascosto || s.completati) return const SizedBox.shrink();
    final guida = s.tipo == 'ente' ? 'ente-primi-passi' : 'primi-passi-famiglia';
    return Padding(
      padding: EdgeInsets.only(bottom: 16),
      child: Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            const Icon(LucideIcons.rocket, size: 20),
            SizedBox(width: 8),
            Expanded(child: Testo.titleSmall('Primi passi · ${s.fatti} di ${s.passi.length}', fontWeight: FontWeight.w600)),
            TextButton(onPressed: () => Get.toNamed('/aiuto/guida', parameters: {'id': guida}), child: const Text('Guida')),
            IconButton(tooltip: 'Nascondi', icon: const Icon(LucideIcons.x, size: 18), onPressed: _nascondi),
          ]),
          SizedBox(height: 4),
          LinearProgressIndicator(value: s.passi.isEmpty ? 0 : s.fatti / s.passi.length),
          SizedBox(height: 8),
          for (final e in s.passi.entries)
            if (testiPassi[e.key] != null)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(e.value ? LucideIcons.circle_check : testiPassi[e.key]!.$1, color: e.value ? ColoreStato.successo : null, size: 20),
                title: Text(testiPassi[e.key]!.$2, style: e.value ? const TextStyle(decoration: TextDecoration.lineThrough) : null),
                subtitle: e.value ? null : Text(testiPassi[e.key]!.$3),
                trailing: e.value ? null : const Icon(LucideIcons.chevron_right, size: 18),
                onTap: e.value
                    ? null
                    : () async {
                        await Get.toNamed(testiPassi[e.key]!.$4, arguments: testiPassi[e.key]!.$5);
                        _load();
                      },
              ),
        ])),
    );
  }
}

// ---------------------------------------------------------------------------
// Introduzione al primo accesso
// ---------------------------------------------------------------------------

/// Da chiamare nella home: se l'utente non l'ha mai vista, mostra 3 schermate e la segna come vista.
Future<void> mostraIntroSeServe(BuildContext context, {required bool ente}) async {
  final me = SessionService.to.me;
  if (me == null || me.introVista) return;
  await showDialog<void>(context: context, barrierDismissible: false, builder: (_) => _IntroDialog(ente: ente));
  try {
    await AccoglienzaApi.introVista();
    await SessionService.to.loadMe();
  } on ApiException {
    // Al massimo si rivede al prossimo accesso.
  }
}

/// Dopo l'introduzione: chi opera in un ente senza 2FA riceve il promemoria a ogni accesso.
Future<void> mostraPromemoria2faSeServe(BuildContext context) async {
  final session = SessionService.to;
  if (!session.serve2faPromemoria || !context.mounted) return;
  session.segnaPromemoria2faMostrato();
  final attiva = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: Icon(LucideIcons.shield_alert, size: 40, color: Theme.of(ctx).colorScheme.primary),
      title: const Text('Proteggi l\'accesso dell\'ente'),
      content: const SizedBox(
        width: 420,
        child: Text(
          'Il tuo account vede dati delle famiglie e dei minori iscritti. Con la verifica in due passaggi, oltre alla password serve '
          'un codice dal telefono: se qualcuno scopre la password non può entrare. Bastano due minuti.',
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Più tardi')),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Attiva ora')),
      ],
    ),
  );
  if (attiva == true) Get.toNamed('/account/security');
}

class _IntroDialog extends StatefulWidget {
  final bool ente;

  const _IntroDialog({required this.ente});

  @override
  State<_IntroDialog> createState() => _IntroDialogState();
}

class _IntroDialogState extends State<_IntroDialog> {
  int pagina = 0;

  List<(IconData, String, String)> get pagine => widget.ente
      ? const [
          (LucideIcons.school, 'Benvenuti su CartellaViva', 'Qui la segreteria raccoglie firme, autorizzazioni e documenti dalle famiglie, senza carta e senza inseguire nessuno.'),
          (LucideIcons.send, 'Richieste in pochi clic', 'Scegliete un gruppo, allegate il PDF: le famiglie rispondono dall\'app o da un link sicuro. Vedete in tempo reale chi manca.'),
          (LucideIcons.shield_check, 'Dati protetti', 'I documenti ricevuti sono cifrati; ogni operatore vede solo i suoi gruppi. Seguite i "Primi passi" per partire.'),
        ]
      : const [
          (LucideIcons.house, 'Benvenuto su CartellaViva', 'Tutti i documenti della famiglia in un posto sicuro: identità, salute, casa, auto, scuola.'),
          (LucideIcons.calendar, 'Mai più una scadenza persa', 'Ti avvisiamo prima che scadano carta d\'identità, bollo, revisione. Molte date le troviamo da soli nei documenti.'),
          (LucideIcons.lock, 'Solo vostri', 'I documenti sensibili sono cifrati e nessuno del supporto può leggerli. Inizia dai "Primi passi" nella home.'),
        ];

  @override
  Widget build(BuildContext context) {
    final p = pagine[pagina];
    final ultima = pagina == pagine.length - 1;
    return AlertDialog(
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(p.$1, size: 48, color: Theme.of(context).colorScheme.primary),
          SizedBox(height: 16),
          Testo.titleMedium(p.$2, fontWeight: FontWeight.w600, textAlign: TextAlign.center),
          SizedBox(height: 8),
          Testo.bodyMedium(p.$3, textAlign: TextAlign.center),
          SizedBox(height: 16),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 0; i < pagine.length; i++)
              Container(
                width: 8,
                height: 8,
                margin: EdgeInsets.symmetric(horizontal: 3),
                decoration: BoxDecoration(shape: BoxShape.circle, color: i == pagina ? Theme.of(context).colorScheme.primary : Colors.grey.shade400),
              ),
          ]),
        ]),
      ),
      actions: [
        if (!ultima) TextButton(onPressed: () => Navigator.pop(context), child: const Text('Salta')),
        FilledButton(
          onPressed: () => ultima ? Navigator.pop(context) : setState(() => pagina++),
          child: Text(ultima ? 'Inizia' : 'Avanti'),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Stato vuoto e link alla guida
// ---------------------------------------------------------------------------

/// Pagina vuota che spiega cosa fare: icona, titolo, testo, azione principale e guida.
class StatoVuoto extends StatelessWidget {
  final IconData icona;
  final String titolo;
  final String testo;
  final String? azione;
  final VoidCallback? onAzione;
  final String? guida;

  const StatoVuoto({super.key, required this.icona, required this.titolo, required this.testo, this.azione, this.onAzione, this.guida});

  @override
  Widget build(BuildContext context) {
    return Scheda(paddingAll: 32, child: Column(children: [
        Icon(icona, size: 40, color: Theme.of(context).colorScheme.primary.withAlpha(180)),
        SizedBox(height: 12),
        Testo.titleSmall(titolo, fontWeight: FontWeight.w600, textAlign: TextAlign.center),
        SizedBox(height: 6),
        ConstrainedBox(constraints: const BoxConstraints(maxWidth: 480), child: Testo.bodyMedium(testo, textAlign: TextAlign.center, tenue: true)),
        SizedBox(height: 16),
        Wrap(spacing: 12, runSpacing: 8, alignment: WrapAlignment.center, children: [
          if (azione != null && onAzione != null) FilledButton(onPressed: onAzione, child: Text(azione!)),
          if (guida != null) AiutoLink(guida: guida!),
        ]),
      ]));
  }
}

/// "Come funziona?": apre la guida indicata.
class AiutoLink extends StatelessWidget {
  final String guida;
  final String etichetta;

  const AiutoLink({super.key, required this.guida, this.etichetta = 'Come funziona?'});

  @override
  Widget build(BuildContext context) => TextButton.icon(
        onPressed: () => Get.toNamed('/aiuto/guida', parameters: {'id': guida}),
        icon: const Icon(LucideIcons.circle_question_mark, size: 16),
        label: Text(etichetta),
      );
}

// ---------------------------------------------------------------------------
// Centro Aiuto (/aiuto) e guida (/aiuto/guida?id=)
// ---------------------------------------------------------------------------

class AiutoScreen extends StatefulWidget {
  const AiutoScreen({super.key});

  @override
  State<AiutoScreen> createState() => _AiutoScreenState();
}

class _AiutoScreenState extends State<AiutoScreen> {
  final cerca = TextEditingController();
  String? area;

  /// Si mostrano per prime le guide e le domande del tipo di spazio corrente.
  PubblicoAiuto get pubblico => SessionService.to.currentMembership?.tenantType == 'azienda' ? PubblicoAiuto.ente : PubblicoAiuto.famiglia;

  @override
  void dispose() {
    cerca.dispose();
    super.dispose();
  }

  bool _perMe(PubblicoAiuto p) => p == PubblicoAiuto.tutti || p == pubblico;

  @override
  Widget build(BuildContext context) {
    final aree = faq.where((f) => _perMe(f.pubblico)).map((f) => f.area).toSet().toList();
    final domande = faq.where((f) => _perMe(f.pubblico) && (area == null || f.area == area) && f.corrisponde(cerca.text)).toList();
    final mieGuide = guide.where((g) => _perMe(g.pubblico)).toList();
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Aiuto', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: spazioPagina),
          Testo.titleSmall('Guide passo-passo', fontWeight: FontWeight.w600),
          SizedBox(height: 8),
          Wrap(spacing: 12, runSpacing: 12, children: [
            for (final g in mieGuide)
              SizedBox(
                width: 300,
                child: Scheda(paddingAll: 16, child: InkWell(
                    onTap: () => Get.toNamed('/aiuto/guida', parameters: {'id': g.id}),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [const Icon(LucideIcons.book_open, size: 18), SizedBox(width: 8), Expanded(child: Testo.bodyMedium(g.titolo, fontWeight: FontWeight.w500))]),
                      SizedBox(height: 4),
                      Testo.bodySmall(g.sommario, tenue: true),
                      Testo.bodySmall('${g.passi.length} passi', tenue: true),
                    ]),
                  )),
              ),
          ]),
          SizedBox(height: 24),
          Testo.titleSmall('Domande frequenti', fontWeight: FontWeight.w600),
          SizedBox(height: 8),
          TextField(
            controller: cerca,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(hintText: 'Cerca: password, scadenze, firma...', prefixIcon: Icon(LucideIcons.search, size: 18), isDense: true),
          ),
          SizedBox(height: 8),
          Wrap(spacing: 8, children: [
            ChoiceChip(label: const Text('Tutte'), selected: area == null, onSelected: (_) => setState(() => area = null)),
            for (final a in aree) ChoiceChip(label: Text(a), selected: area == a, onSelected: (_) => setState(() => area = a)),
          ]),
          SizedBox(height: 8),
          if (domande.isEmpty) Testo.bodyMedium('Nessuna risposta trovata. Puoi scriverci dal portale delle idee ("Suggerisci un\'idea").'),
          Scheda(paddingAll: 0, child: Column(children: [
              for (final f in domande)
                ExpansionTile(
                  title: Text(f.domanda, style: const TextStyle(fontWeight: FontWeight.w600)),
                  childrenPadding: EdgeInsets.fromLTRB(16, 0, 16, 16),
                  expandedCrossAxisAlignment: CrossAxisAlignment.start,
                  children: [Testo.bodyMedium(f.risposta)],
                ),
            ])),
          SizedBox(height: 24),
        ]),
      ),
    );
  }
}

class GuidaScreen extends StatelessWidget {
  const GuidaScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final g = guidaPer(Get.parameters['id'] ?? '');
    return Layout(
      indietro: '/aiuto',
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: g == null
            ? Scheda(paddingAll: 24, child: Testo.bodyMedium('Guida non trovata.'))
            : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  IconButton(onPressed: () => Get.back(), icon: const Icon(LucideIcons.arrow_left)),
                  Expanded(child: Testo.titleMedium(g.titolo, fontSize: 18, fontWeight: FontWeight.w500)),
                ]),
                Testo.bodySmall(g.sommario, tenue: true),
                SizedBox(height: spazioPagina),
                for (var i = 0; i < g.passi.length; i++)
                  Padding(
                    padding: EdgeInsets.only(bottom: 12),
                    child: Scheda(paddingAll: 16, child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        CircleAvatar(radius: 14, child: Text('${i + 1}', style: const TextStyle(fontSize: 13))),
                        SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Testo.bodyMedium(g.passi[i].titolo, fontWeight: FontWeight.w500),
                            SizedBox(height: 4),
                            Testo.bodyMedium(g.passi[i].testo),
                          ]),
                        ),
                        if (g.passi[i].route != null)
                          TextButton(onPressed: () => Get.toNamed(g.passi[i].route!, arguments: g.passi[i].arguments), child: const Text('Vai')),
                      ])),
                  ),
              ]),
      ),
    );
  }
}
