import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Avanzamento di una richiesta: per iscritto e famiglia, con verifica delle
/// risposte, solleciti, ZIP delle risposte accettate, annullamento.
class EnteRichiestaScreen extends StatefulWidget {
  const EnteRichiestaScreen({super.key});

  @override
  State<EnteRichiestaScreen> createState() => _EnteRichiestaScreenState();
}

class _EnteRichiestaScreenState extends State<EnteRichiestaScreen> {
  SessionService get session => SessionService.to;
  String get t => session.currentTenantId ?? '';
  final String id = Get.parameters['id'] ?? '';
  late Future<RichiestaEnte> _future = EnteApi.richiesta(t, id);
  bool soloDaGestire = true;
  bool busy = false;

  void _reload() => setState(() => _future = EnteApi.richiesta(t, id));

  void _snack(String m) => mostraMessaggio(context, m);

  Future<void> _run(Future<void> Function() action, {String? ok, bool reload = true}) async {
    setState(() => busy = true);
    try {
      await action();
      if (ok != null) _snack(ok);
      if (reload) _reload();
    } on ApiException catch (e) {
      _snack(e.message);
    } catch (e) {
      _snack(e.toString());
    }
    if (mounted) setState(() => busy = false);
  }

  /// Il file consegnato con il suo nome e tipo veri (PDF, foto o documento Office).
  Future<void> _apriFile(RigaAvanzamento riga, DestinatarioEnte d) => _run(() async {
        final f = await EnteApi.fileRispostaConNome(t, id, d.id);
        await saveOrOpenFile(f.bytes, f.nome, f.mime);
      }, reload: false);

  Future<void> _verifica(RigaAvanzamento riga, DestinatarioEnte d) async {
    final motivi = ['Manca una firma', 'Pagina mancante o illeggibile', 'Documento sbagliato'];
    String? motivo;
    final altro = TextEditingController();
    final esito = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text('Verifica · ${riga.iscrittoNome}'),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodyMedium('Firmatari dichiarati: ${d.referenti.where((r) => r.firmato).map((r) => r.nome).join(', ')}'),
              SizedBox(height: 8),
              TextButton.icon(onPressed: () => _apriFile(riga, d), icon: const Icon(LucideIcons.file_text, size: 16), label: const Text('Apri il documento consegnato')),
              const Divider(),
              Testo.labelMedium('Se chiedi di rifirmare, perché (lo vede la famiglia)'),
              Wrap(spacing: 6, children: [
                for (final m in motivi) ChoiceChip(label: Text(m), selected: motivo == m, onSelected: (_) => setLocal(() => motivo = m)),
              ]),
              SizedBox(height: 8),
              TextField(controller: altro, decoration: const InputDecoration(labelText: 'Altro…', isDense: true), onChanged: (_) => setLocal(() => motivo = null)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi')),
            OutlinedButton(onPressed: (motivo ?? altro.text.trim()).isEmpty ? null : () => Navigator.pop(ctx, 'rifirma'), child: const Text('Chiedi di rifirmare')),
            FilledButton(onPressed: () => Navigator.pop(ctx, 'accetta'), child: const Text('Accetta')),
          ],
        ),
      ),
    );
    final testo = motivo ?? altro.text.trim();
    altro.dispose();
    if (esito == null) return;
    await _run(() => EnteApi.verifica(t, id, d.id, accetta: esito == 'accetta', motivo: esito == 'rifirma' ? testo : null),
        ok: esito == 'accetta' ? 'Risposta accettata.' : 'Chiesto di rifirmare.');
  }

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      indietro: '/ente/richieste',
      titolo: 'Richiesta',
      sezione: 'Avanzamento',
      child: FutureBuilder<RichiestaEnte>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
          return _dettaglio(snap.data!);
        },
      ),
    );
  }

  Widget _dettaglio(RichiestaEnte r) {
    final tot = r.totali;
    final canSend = session.can('richiesta_ente:send') && !r.annullata;
    final righe = soloDaGestire ? r.avanzamento.where((x) => x.stato == 'da_verificare' || x.stato == 'mancante' || x.stato == 'rifiutata').toList() : r.avanzamento;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.titleMedium(r.titolo, fontWeight: FontWeight.w600),
          SizedBox(height: 4),
          Testo.bodySmall([
            tipoRichiestaLabel(r.tipo),
            if (r.tipo == 'firma') r.modalitaFirma == 'codice' ? 'firma con codice email' : 'firma su carta',
            r.gruppoNome ?? 'iscritti selezionati',
            r.firmatari == 'tutti' ? 'tutti i referenti' : 'almeno un referente',
            if (r.entroIl != null) 'entro il ${formatDataBreve(r.entroIl!)}',
            if (r.createdAt != null) 'inviata il ${formatDataBreve(r.createdAt!)}',
            if (r.modelloNome != null) 'modello ${r.modelloNome}',
            if (r.rinnovo) 'rinnovo automatico',
            if (r.annullata) 'ANNULLATA',
          ].join(' · '), tenue: true),
          SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 8, children: [
            if (tot.daVerificare > 0 && session.can('richiesta_ente:verify'))
              FilledButton.icon(
                onPressed: () async {
                  await Get.toNamed('/ente/verifica', parameters: {'richiesta': id});
                  _reload();
                },
                icon: const Icon(LucideIcons.list_checks, size: 16),
                label: Text('Verifica affiancata (${tot.daVerificare})'),
              ),
            if (r.allegatoNome != null)
              OutlinedButton.icon(
                onPressed: busy ? null : () => _run(() async => saveOrOpenFile(await EnteApi.allegato(t, id), r.allegatoNome!, 'application/pdf'), reload: false),
                icon: const Icon(LucideIcons.file_text, size: 16),
                label: const Text('Documento inviato'),
              ),
            if (tot.completate > 0)
              OutlinedButton.icon(
                onPressed: busy ? null : () => _run(() async => saveOrOpenFile(await EnteApi.zip(t, id), 'risposte.zip', 'application/zip'), reload: false),
                icon: const Icon(LucideIcons.archive, size: 16),
                label: const Text('Scarica le risposte (ZIP)'),
              ),
            if (canSend && tot.mancanti > 0)
              FilledButton.icon(
                onPressed: busy ? null : () => _run(() async => _snack('Sollecitate ${await EnteApi.sollecita(t, id)} famiglie.')),
                icon: const Icon(LucideIcons.bell_ring, size: 16),
                label: Text('Sollecita chi manca (${tot.mancanti})'),
              ),
            if (canSend)
              TextButton(
                onPressed: busy
                    ? null
                    : () async {
                        final ok = await chiediConferma(
                          context,
                          titolo: 'Annullare la richiesta?',
                          testo: 'Le famiglie che non hanno ancora risposto non potranno più farlo. Le risposte già arrivate restano.',
                          azione: 'Annulla la richiesta',
                          annulla: 'No',
                          distruttiva: true,
                        );
                        if (ok) await _run(() => EnteApi.annulla(t, id), ok: 'Richiesta annullata.');
                      },
                child: Text('Annulla richiesta', style: TextStyle(color: ColoreStato.errore)),
              ),
          ]),
        ])),
      SizedBox(height: 16),
      Wrap(spacing: 12, runSpacing: 12, children: [
        numeroCard('Completate', '${tot.completate} su ${tot.raggiunti}'),
        numeroCard('Da verificare', '${tot.daVerificare}'),
        numeroCard('Mancanti', '${tot.mancanti}'),
        if (tot.rifiutate > 0) numeroCard('Non firmano', '${tot.rifiutate}'),
        if (tot.nonRaggiunti > 0) numeroCard('Non raggiunti', '${tot.nonRaggiunti}', nota: 'senza referenti collegati'),
      ]),
      SizedBox(height: 16),
      Align(
        alignment: Alignment.centerLeft,
        child: SegmentedButton<bool>(
          segments: const [ButtonSegment(value: true, label: Text('Da gestire')), ButtonSegment(value: false, label: Text('Tutti'))],
          selected: {soloDaGestire},
          onSelectionChanged: (s) => setState(() => soloDaGestire = s.first),
        ),
      ),
      SizedBox(height: 12),
      Scheda(paddingAll: 0, child: Column(children: [
          if (righe.isEmpty) Padding(padding: EdgeInsets.all(20), child: Testo.bodyMedium('Niente da gestire.')),
          for (var k = 0; k < righe.length; k++) ...[if (k > 0) const Divider(height: 1), _riga(righe[k], canSend)],
          if (!soloDaGestire)
            for (final nome in r.nonRaggiunti)
              ListTile(title: Text(nome), subtitle: const Text('Nessun referente collegato: richiesta non inviata'), trailing: const Chip(label: Text('non inviata'))),
        ])),
    ]);
  }

  Widget _riga(RigaAvanzamento riga, bool canSend) {
    final canVerify = session.can('richiesta_ente:verify');
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(flex: 2, child: Testo.bodyMedium(riga.iscrittoNome, fontWeight: FontWeight.w500)),
        Expanded(
          flex: 5,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final d in riga.destinatari)
              Padding(
                padding: EdgeInsets.only(bottom: 6),
                child: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Text(d.referenti.map((r) => '${r.nome}${r.firmato ? ' ✓' : ''}').join(', ')),
                  Chip(visualDensity: VisualDensity.compact, label: Text(d.scaduta ? '${d.statoLabel} · scaduta' : d.statoLabel)),
                  if (d.viaLink) const Tooltip(message: 'Senza account: risponde dal link sicuro con un codice via email', child: Chip(visualDensity: VisualDensity.compact, label: Text('via link'))),
                  if (d.validoFino != null) Text('valido fino al ${formatDataBreve(d.validoFino!)}'),
                  if (d.fileRimosso) const Text('copia eliminata (conservazione)', style: TextStyle(fontStyle: FontStyle.italic)),
                  if (d.messaggioFamiglia != null) Text('"${d.messaggioFamiglia}"', style: const TextStyle(fontStyle: FontStyle.italic)),
                  if (d.stato == 'da_verificare' && canVerify) FilledButton(onPressed: busy ? null : () => _verifica(riga, d), child: const Text('Verifica')),
                  if (d.haFile && d.stato != 'da_verificare') TextButton(onPressed: busy ? null : () => _apriFile(riga, d), child: const Text('Apri')),
                  if (canSend && const ['inviata', 'vista', 'da_rifirmare'].contains(d.stato))
                    TextButton(
                      onPressed: busy ? null : () => _run(() => EnteApi.sollecita(t, id, destinatarioIds: [d.id]), ok: 'Sollecito inviato.'),
                      child: const Text('Sollecita'),
                    ),
                ]),
              ),
          ]),
        ),
      ]),
    );
  }
}
