import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';

/// Cruscotto dell'ente: stato della verifica, numeri, richieste aperte.
class EnteHomeScreen extends StatefulWidget {
  const EnteHomeScreen({super.key});

  @override
  State<EnteHomeScreen> createState() => _EnteHomeScreenState();
}

class _EnteHomeScreenState extends State<EnteHomeScreen> {
  SessionService get session => SessionService.to;
  String get t => session.currentTenantId ?? '';
  late Future<_Cruscotto> _future = _load();
  final codice = TextEditingController();
  bool busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await mostraIntroSeServe(context, ente: true);
      if (mounted) await mostraPromemoria2faSeServe(context);
    });
  }

  @override
  void dispose() {
    codice.dispose();
    super.dispose();
  }

  Future<_Cruscotto> _load() async {
    final profilo = await EnteApi.profilo(t);
    final iscritti = session.can('iscritto:read') ? (await EnteApi.iscritti(t)).totali : null;
    final leggeRichieste = session.can('richiesta_ente:read');
    final richieste = leggeRichieste ? await EnteApi.richieste(t, soloAperte: true) : <RichiestaEnte>[];
    final inScadenza = leggeRichieste ? await EnteApi.documentiInScadenza(t) : <DocumentoInScadenza>[];
    return _Cruscotto(profilo, iscritti, richieste, inScadenza);
  }

  void _reload() => setState(() => _future = _load());

  Future<void> _run(Future<void> Function() action, {String? ok}) async {
    setState(() => busy = true);
    try {
      await action();
      if (ok != null && mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ok)));
      _reload();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: session.currentMembership?.tenantName ?? 'Ente',
      sezione: 'Cruscotto',
      azioni: [
        if (session.can('richiesta_ente:send'))
          FilledButton.icon(onPressed: () => Get.toNamed('/ente/richieste/nuova'), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuova richiesta')),
      ],
      child: FutureBuilder<_Cruscotto>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
          if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
          final c = snap.data!;
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (!c.profilo.verificato) ...[_verifica(c.profilo), SizedBox(height: 16)],
            if (session.can('ente:manage')) const PrimiPassiCard(),
            if (session.can('richiesta_ente:verify') && c.richieste.any((r) => r.totali.daVerificare > 0)) ...[
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton.icon(
                  onPressed: () async {
                    await Get.toNamed('/ente/verifica');
                    _reload();
                  },
                  icon: const Icon(LucideIcons.list_checks, size: 18),
                  label: Text('Verifica le risposte (${c.richieste.fold<int>(0, (s, r) => s + r.totali.daVerificare)})'),
                ),
              ),
              SizedBox(height: 16),
            ],
            Wrap(spacing: 12, runSpacing: 12, children: [
              numeroCard('Da verificare', '${c.richieste.fold<int>(0, (s, r) => s + r.totali.daVerificare)}'),
              numeroCard('Documenti in scadenza 30 gg', '${c.inScadenza.length}', nota: c.inScadenza.any((d) => d.rinnovoAutomatico) ? 'rinnovo automatico attivo' : null),
              if (c.iscritti != null) ...[
                numeroCard('Iscritti', '${c.iscritti!.iscritti}'),
                numeroCard('Referenti collegati', c.iscritti!.referenti == 0 ? '-' : '${(c.iscritti!.referentiCollegati * 100 / c.iscritti!.referenti).round()}%',
                    nota: '${c.iscritti!.referentiCollegati} su ${c.iscritti!.referenti}'),
                numeroCard('Iscritti senza email', '${c.iscritti!.iscrittiSenzaEmail}'),
              ],
            ]),
            SizedBox(height: 16),
            Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Testo.titleSmall('Richieste aperte', fontWeight: FontWeight.w600)),
                  TextButton(onPressed: () => Get.toNamed('/ente/richieste'), child: const Text('Tutte')),
                ]),
                if (c.richieste.isEmpty) Testo.bodySmall('Nessuna richiesta aperta.', tenue: true),
                for (final r in c.richieste)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    onTap: () => Get.toNamed('/ente/richieste/${r.id}'),
                    title: Text('${tipoRichiestaLabel(r.tipo)} · ${r.titolo}'),
                    subtitle: Text([r.gruppoNome ?? 'iscritti selezionati', if (r.entroIl != null) 'entro il ${formatDataBreve(r.entroIl!)}'].join(' · ')),
                    trailing: Text('${r.totali.completate}/${r.totali.raggiunti}', style: const TextStyle(fontWeight: FontWeight.w600)),
                  ),
              ])),
            if (c.inScadenza.isNotEmpty) ...[
              SizedBox(height: 16),
              Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Testo.titleSmall('Documenti in scadenza', fontWeight: FontWeight.w600),
                  for (final d in c.inScadenza)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      onTap: () => Get.toNamed('/ente/richieste/${d.richiestaId}'),
                      title: Text('${d.iscrittoNome} · ${d.titolo}'),
                      subtitle: Text([
                        if (d.validoFino != null) '${d.scaduto ? 'scaduto il' : 'valido fino al'} ${formatDataBreve(d.validoFino!)}',
                        if (d.rinnovato) 'rinnovo già inviato' else if (d.rinnovoAutomatico) 'rinnovo automatico attivo',
                      ].join(' · ')),
                    ),
                ])),
            ],
          ]);
        },
      ),
    );
  }

  Widget _verifica(EnteProfilo p) {
    final colore = p.stato == 'respinto' ? context.colori.danger : context.colori.warning;
    return Container(
      padding: EdgeInsets.all(16),
      decoration: BoxDecoration(color: colore.withAlpha(22), border: Border.all(color: colore.withAlpha(90)), borderRadius: BorderRadius.circular(6)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(LucideIcons.shield_alert, color: colore),
          SizedBox(width: 10),
          Expanded(child: Testo.bodyMedium('Ente ${p.statoLabel.toLowerCase()}', fontWeight: FontWeight.w600)),
        ]),
        SizedBox(height: 8),
        if (p.stato == 'email_da_confermare') ...[
          Testo.bodyMedium('Abbiamo inviato un codice a ${p.emailIstituzionale}. Inseriscilo per confermare l\'email istituzionale.'),
          SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(width: 160, child: TextField(controller: codice, maxLength: 6, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Codice', isDense: true, counterText: ''))),
            FilledButton(onPressed: busy ? null : () => _run(() => EnteApi.confermaEmail(t, codice.text), ok: 'Email confermata.'), child: const Text('Conferma')),
            TextButton(onPressed: busy ? null : () => _run(() => EnteApi.nuovoCodice(t), ok: 'Nuovo codice inviato.'), child: const Text('Invia un nuovo codice')),
          ]),
        ] else if (p.stato == 'in_verifica')
          Testo.bodyMedium('Stiamo controllando i dati sul registro pubblico. Intanto puoi preparare gruppi, iscritti e operatori: inviti e richieste si attivano dopo l\'approvazione.')
        else ...[
          Testo.bodyMedium(p.notaPortale ?? ''),
          if (p.stato == 'integrazioni') TextButton(onPressed: () => Get.toNamed('/ente/profilo'), child: const Text('Correggi i dati')),
        ],
      ]),
    );
  }
}

class _Cruscotto {
  final EnteProfilo profilo;
  final TotaliIscritti? iscritti;
  final List<RichiestaEnte> richieste;
  final List<DocumentoInScadenza> inScadenza;

  _Cruscotto(this.profilo, this.iscritti, this.richieste, this.inScadenza);
}
