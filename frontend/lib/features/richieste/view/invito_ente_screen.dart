import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/richieste/api/richieste_api.dart';
import 'package:cartellaviva/helpers/services/auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/model/me.dart';
import 'package:cartellaviva/features/famiglia/model/persona.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Invito di un ente a collegarsi (/collegamenti/invito?token=...).
///
/// Pubblica: anche senza account si può rifiutare o dire "non sono io".
/// Per accettare serve l'account dell'email invitata; si sceglie la famiglia e
/// a quale persona corrisponde l'iscritto (l'ente non vede questa scelta).
class InvitoEnteScreen extends StatefulWidget {
  const InvitoEnteScreen({super.key});

  @override
  State<InvitoEnteScreen> createState() => _InvitoEnteScreenState();
}

class _InvitoEnteScreenState extends State<InvitoEnteScreen> {
  final String token = Get.parameters['token'] ?? '';
  late final Future<InvitoEnte> _future = RichiesteApi.anteprimaInvito(token);
  String? famigliaId;
  List<Persona> persone = [];
  String personaScelta = '_nuova';
  bool busy = false;
  String? esito;

  bool get loggedIn => AuthService.isLoggedIn && SessionService.to.me != null;

  List<TenantMembership> get famiglie =>
      (SessionService.to.me?.memberships ?? []).where((m) => m.tenantType == 'privato' && m.permissions.contains('collegamento:manage')).toList();

  @override
  void initState() {
    super.initState();
    if (loggedIn && famiglie.isNotEmpty) {
      final current = SessionService.to.currentMembership;
      famigliaId = famiglie.any((f) => f.tenantId == current?.tenantId) ? current!.tenantId : famiglie.first.tenantId;
      _loadPersone();
    }
  }

  Future<void> _loadPersone() async {
    try {
      final list = await DocumentoApi.persone(famigliaId!);
      if (mounted) setState(() => persone = list);
    } on ApiException {
      // senza permesso di lettura: si potrà solo creare una nuova persona
    }
  }

  Future<void> _run(Future<void> Function() action, String messaggio) async {
    setState(() => busy = true);
    try {
      await action();
      setState(() => esito = messaggio);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _accetta(InvitoEnte invito) => _run(() async {
        await RichiesteApi.accettaInvito(token,
            tenantId: famigliaId!,
            personaId: personaScelta == '_nuova' ? null : personaScelta,
            nuovoNome: invito.iscrittoNome,
            nuovoCognome: invito.iscrittoCognome);
        await SessionService.to.selectTenant(famigliaId!);
      }, 'Collegamento attivo: le richieste di ${invito.enteNome} arriveranno nella pagina Richieste.');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: FutureBuilder<InvitoEnte>(
                future: _future,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                  if (snap.hasError) {
                    return Scheda(paddingAll: 24, child: Testo.bodyMedium(snap.error.toString()));
                  }
                  return esito != null ? _fatto() : _invito(snap.data!);
                },
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _fatto() => Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.bodyMedium(esito!),
          SizedBox(height: 16),
          if (loggedIn) FilledButton(onPressed: () => Get.offAllNamed('/richieste'), child: const Text('Vai alle richieste')),
        ]));

  Widget _invito(InvitoEnte invito) {
    return Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(LucideIcons.building, size: 28),
          SizedBox(width: 12),
          Expanded(child: Testo.titleMedium(invito.enteNome, fontWeight: FontWeight.w600)),
          if (invito.verificato) Chip(avatar: const Icon(LucideIcons.badge_check, size: 16), label: const Text('verificato')),
        ]),
        SizedBox(height: 16),
        Testo.bodyMedium('Ti ha indicato come referente di:'),
        SizedBox(height: 4),
        Testo.titleSmall('${invito.iscrittoNome} ${invito.iscrittoCognome}${invito.gruppi.isEmpty ? '' : ' · ${invito.gruppi.join(', ')}'}', fontWeight: FontWeight.w600),
        if (invito.anno != null) Testo.bodySmall('anno ${invito.anno}', tenue: true),
        SizedBox(height: 16),
        _punto(LucideIcons.check, 'potrà inviarti richieste di firma, di documenti e di presa visione'),
        _punto(LucideIcons.x, 'non vede il tuo archivio, le tue persone né i membri della famiglia'),
        _punto(LucideIcons.x, 'riceve solo ciò che decidi di consegnargli'),
        SizedBox(height: 8),
        Testo.bodySmall('I documenti che consegni restano anche all\'ente, che deve conservarli. Puoi interrompere il collegamento quando vuoi.', tenue: true),
        const Divider(height: 32),
        if (!loggedIn) ...[
          Testo.bodyMedium('Per accettare accedi (o registrati) con ${invito.referenteEmail ?? 'l\'email che ha ricevuto l\'invito'}.'),
          SizedBox(height: 12),
          FilledButton(
            onPressed: () => Get.toNamed('/auth/login?next=${Uri.encodeComponent('/collegamenti/invito?token=$token')}'),
            child: const Text('Accedi per accettare'),
          ),
        ] else if (famiglie.isEmpty) ...[
          Testo.bodyMedium('Per accettare crea prima la tua famiglia su CartellaViva.'),
          SizedBox(height: 12),
          FilledButton(onPressed: () => Get.toNamed('/benvenuto'), child: const Text('Crea la famiglia')),
        ] else ...[
          if (famiglie.length > 1) ...[
            DropdownButtonFormField<String>(
              initialValue: famigliaId,
              decoration: const InputDecoration(labelText: 'Famiglia', isDense: true),
              items: [for (final f in famiglie) DropdownMenuItem(value: f.tenantId, child: Text(f.tenantName))],
              onChanged: (v) {
                setState(() {
                  famigliaId = v;
                  personaScelta = '_nuova';
                  persone = [];
                });
                _loadPersone();
              },
            ),
            SizedBox(height: 12),
          ],
          Testo.labelMedium('Chi è ${invito.iscrittoNome} nella tua famiglia?'),
          RadioGroup<String>(
            groupValue: personaScelta,
            onChanged: (v) => setState(() => personaScelta = v ?? '_nuova'),
            child: Column(children: [
              for (final p in persone) RadioListTile<String>(contentPadding: EdgeInsets.zero, value: p.id!, title: Text(p.nomeCompleto), subtitle: p.relazione == null ? null : Text(p.relazione!)),
              RadioListTile<String>(contentPadding: EdgeInsets.zero, value: '_nuova', title: Text('Aggiungi ${invito.iscrittoNome} ${invito.iscrittoCognome} come nuova persona')),
            ]),
          ),
          Testo.bodySmall('I documenti che consegnerai saranno collegati a questa persona nel tuo archivio. L\'ente non vede questa scelta.', tenue: true),
          SizedBox(height: 16),
          FilledButton(onPressed: busy || famigliaId == null ? null : () => _accetta(invito), child: const Text('Accetta')),
        ],
        SizedBox(height: 8),
        Wrap(spacing: 8, children: [
          TextButton(
            onPressed: busy ? null : () => _run(() => RichiesteApi.rifiutaInvito(token, nonSonoIo: false), 'Invito rifiutato. L\'ente ne sarà informato.'),
            child: const Text('Rifiuta'),
          ),
          TextButton(
            onPressed: busy ? null : () => _run(() => RichiesteApi.rifiutaInvito(token, nonSonoIo: true), 'Grazie: l\'ente correggerà l\'indirizzo.'),
            child: Text('Non sono io / non conosco ${invito.iscrittoNome}'),
          ),
        ]),
      ]));
  }

  Widget _punto(IconData icon, String testo) => Padding(
        padding: EdgeInsets.only(bottom: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 16, color: icon == LucideIcons.check ? ColoreStato.successo : ColoreStato.errore),
          SizedBox(width: 8),
          Expanded(child: Testo.bodySmall(testo)),
        ]),
      );
}
