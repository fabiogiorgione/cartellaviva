import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';

/// Richieste dell'ente (visibili all'utente: tutte per la segreteria, quelle dei
/// propri gruppi per un operatore).
class EnteRichiesteScreen extends StatefulWidget {
  const EnteRichiesteScreen({super.key});

  @override
  State<EnteRichiesteScreen> createState() => _EnteRichiesteScreenState();
}

class _EnteRichiesteScreenState extends State<EnteRichiesteScreen> {
  String get t => SessionService.to.currentTenantId ?? '';
  bool soloAperte = true;
  late Future<List<RichiestaEnte>> _future = EnteApi.richieste(t, soloAperte: soloAperte);

  void _reload() => setState(() => _future = EnteApi.richieste(t, soloAperte: soloAperte));

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: 'Richieste',
      sezione: 'Richieste',
      azioni: [
        const AiutoLink(guida: 'ente-richiesta'),
        if (SessionService.to.can('richiesta_ente:send'))
          FilledButton.icon(
            onPressed: () async {
              await Get.toNamed('/ente/richieste/nuova');
              _reload();
            },
            icon: const Icon(LucideIcons.plus, size: 18),
            label: const Text('Nuova richiesta'),
          ),
      ],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Align(
          alignment: Alignment.centerLeft,
          child: SegmentedButton<bool>(
            segments: const [ButtonSegment(value: true, label: Text('Aperte')), ButtonSegment(value: false, label: Text('Tutte'))],
            selected: {soloAperte},
            onSelectionChanged: (s) {
              soloAperte = s.first;
              _reload();
            },
          ),
        ),
        SizedBox(height: 16),
        FutureBuilder<List<RichiestaEnte>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
            final richieste = snap.data!;
            if (richieste.isEmpty) {
              final puo = SessionService.to.can('richiesta_ente:send');
              return StatoVuoto(
                icona: LucideIcons.send,
                titolo: 'Nessuna richiesta',
                testo: 'Chiedete alle famiglie una firma (autorizzazioni, liberatorie), un documento (es. certificato medico) o una presa visione (circolari). Vedrete in tempo reale chi ha risposto.',
                azione: puo ? 'Nuova richiesta' : null,
                onAzione: puo ? () => Get.toNamed('/ente/richieste/nuova') : null,
                guida: 'ente-richiesta',
              );
            }
            return Scheda(paddingAll: 0, child: Column(children: [
                for (var k = 0; k < richieste.length; k++) ...[
                  if (k > 0) const Divider(height: 1),
                  _riga(richieste[k]),
                ],
              ]));
          },
        ),
      ]),
    );
  }

  Widget _riga(RichiestaEnte r) {
    final tot = r.totali;
    final progresso = tot.raggiunti == 0 ? 0.0 : tot.completate / tot.raggiunti;
    return ListTile(
      onTap: () async {
        await Get.toNamed('/ente/richieste/${r.id}');
        _reload();
      },
      title: Text('${tipoRichiestaLabel(r.tipo)} · ${r.titolo}', style: TextStyle(fontWeight: FontWeight.w600, decoration: r.annullata ? TextDecoration.lineThrough : null)),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text([
          r.gruppoNome ?? 'iscritti selezionati',
          if (r.entroIl != null) 'entro il ${formatDataBreve(r.entroIl!)}',
          if (r.createdAt != null) 'inviata il ${formatDataBreve(r.createdAt!)}',
          if (tot.daVerificare > 0) '${tot.daVerificare} da verificare',
        ].join(' · ')),
        SizedBox(height: 6),
        LinearProgressIndicator(value: progresso),
      ]),
      trailing: Text('${tot.completate}/${tot.raggiunti}', style: const TextStyle(fontWeight: FontWeight.w700)),
    );
  }
}
