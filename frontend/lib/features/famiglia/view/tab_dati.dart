import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:url_launcher/url_launcher.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Esportazione di tutti i documenti ed eliminazione della famiglia (solo proprietari).
class TabDati extends StatefulWidget {
  const TabDati({super.key});

  @override
  State<TabDati> createState() => _TabDatiState();
}

class _TabDatiState extends State<TabDati> {
  SessionService get session => SessionService.to;
  bool busy = false;
  Uri? exportUrl;
  DateTime? exportScade;

  void _snack(String m) => mostraMessaggio(context, m);

  Future<void> _esporta() async {
    setState(() => busy = true);
    try {
      final export = await TenantApi.createExport(session.currentTenantId!);
      setState(() {
        exportUrl = export.url;
        exportScade = export.scadeIl;
      });
    } on ApiException catch (e) {
      _snack(e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _scarica() async {
    final url = exportUrl;
    if (url == null) return;
    // Il link è monouso: lo ZIP si scarica direttamente dal browser, in streaming.
    await launchUrl(url, mode: LaunchMode.externalApplication);
    setState(() => exportUrl = null);
  }

  Future<void> _elimina() async {
    final me = session.me;
    if (me == null) return;
    if (!me.twoFactorEnabled) {
      _snack('Per eliminare la famiglia attiva prima la verifica in due passaggi.');
      Get.toNamed('/account/security');
      return;
    }
    final nome = session.currentTenant?.name ?? session.currentMembership?.tenantName ?? '';
    final conferma = TextEditingController();
    final codice = TextEditingController();
    String? err;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Eliminare la famiglia?'),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text(
                'Per 30 giorni la famiglia resta in sola lettura e un proprietario può annullare. '
                'Poi documenti e dati spariscono per sempre. Google Drive viene scollegato subito. '
                'Tutti i membri ricevono un avviso per email.',
              ),
              SizedBox(height: 16),
              Text('Per confermare scrivi il nome della famiglia: "$nome"'),
              SizedBox(height: 8),
              TextField(controller: conferma, decoration: const InputDecoration(isDense: true)),
              SizedBox(height: 12),
              TextField(
                controller: codice,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Codice della verifica in due passaggi', isDense: true),
              ),
              if (err != null) ...[SizedBox(height: 8), Testo.bodySmall(err!, color: ColoreStato.errore)],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore),
              onPressed: () async {
                try {
                  await TenantApi.requestDeletion(session.currentTenantId!, conferma: conferma.text, codice: codice.text);
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Elimina la famiglia'),
            ),
          ],
        ),
      ),
    );
    conferma.dispose();
    codice.dispose();
    if (ok == true) {
      await session.refreshTenant();
      _snack('Eliminazione programmata.');
    }
  }

  Future<void> _annulla() async {
    setState(() => busy = true);
    try {
      await TenantApi.cancelDeletion(session.currentTenantId!);
      await session.refreshTenant();
      _snack('Eliminazione annullata. Se usavate Google Drive, va ricollegato.');
    } on ApiException catch (e) {
      _snack(e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final data = session.currentTenant?.eliminazioneProgrammataIl;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Testo.titleSmall('Esporta tutti i documenti', fontWeight: FontWeight.w600),
      SizedBox(height: 6),
      Testo.bodySmall('Un file ZIP con tutti i PDF e le immagini, decifrati, divisi per categoria, con un indice da aprire in Excel. '
        'Il link vale 24 ore e si può usare una sola volta.', tenue: true),
      SizedBox(height: 12),
      if (exportUrl == null)
        OutlinedButton.icon(onPressed: busy ? null : _esporta, icon: const Icon(LucideIcons.archive, size: 18), label: const Text('Prepara l\'esportazione'))
      else
        Wrap(spacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
          FilledButton.icon(onPressed: _scarica, icon: const Icon(LucideIcons.download, size: 18), label: const Text('Scarica lo ZIP')),
          Testo.bodySmall('valido fino al ${formatDataBreve(exportScade!)} alle ${exportScade!.hour.toString().padLeft(2, '0')}:${exportScade!.minute.toString().padLeft(2, '0')}', tenue: true),
        ]),
      const Divider(height: 40),
      Testo.titleSmall('Elimina la famiglia', fontWeight: FontWeight.w600, color: ColoreStato.errore),
      SizedBox(height: 6),
      if (data == null) ...[
        Testo.bodySmall('Hai 30 giorni per ripensarci; poi documenti e dati spariscono per sempre. Ti consigliamo di esportare prima i documenti.', tenue: true),
        SizedBox(height: 12),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(foregroundColor: ColoreStato.errore),
          onPressed: busy ? null : _elimina,
          icon: const Icon(LucideIcons.trash, size: 18),
          label: const Text('Elimina…'),
        ),
      ] else ...[
        Testo.bodyMedium('Eliminazione programmata per il ${formatDataBreve(data)}. Fino ad allora la famiglia è in sola lettura.'),
        SizedBox(height: 12),
        if (session.currentTenant?.eliminazioneDalPortale == true)
          Testo.bodySmall('L\'eliminazione è stata decisa dal team di CartellaViva: per annullarla scrivi al supporto.', tenue: true)
        else
          FilledButton.icon(onPressed: busy ? null : _annulla, icon: const Icon(LucideIcons.undo_2, size: 18), label: const Text('Annulla l\'eliminazione')),
      ],
    ]);
  }
}
