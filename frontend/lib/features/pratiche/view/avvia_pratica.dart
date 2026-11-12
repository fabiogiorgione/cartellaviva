import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart' show MembroFamiglia;
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/helpers/services/ai_locale.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/widgets/foglio.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Foglio "Avvia una pratica": anno (proposto quello giusto) e persone della
/// famiglia. Se va a buon fine apre la pagina della pratica.
Future<void> avviaPratica(BuildContext context, String tenantId, List<Pratica> esistenti) async {
  late final List<ModelloPratica> modelli;
  late final List<MembroFamiglia> persone;
  // Con la spunta in Sicurezza e il modello di Apple pronto, si analizza su questo iPhone.
  final telefono = await AiLocale.utilizzabile() != null;
  try {
    final r = await Future.wait([PraticheApi.modelli(tenantId), DocumentoApi.membriFamiglia(tenantId)]);
    modelli = r[0] as List<ModelloPratica>;
    persone = r[1] as List<MembroFamiglia>;
  } on ApiException catch (e) {
    if (context.mounted) mostraMessaggio(context, e.message, errore: true);
    return;
  }
  if (!context.mounted) return;
  final liberi = modelli.where((m) => !esistenti.any((p) => p.modello == m.modello && p.anno == m.anno)).toList();
  var scelto = liberi.where((m) => m.proposto).firstOrNull ?? liberi.firstOrNull;
  final selezionate = <String>{if (persone.length == 1) persone.first.id};
  var invio = false;

  await apriFoglio<void>(
    context,
    titolo: 'Avvia una pratica',
    contenuto: (ctx) => StatefulBuilder(
      builder: (ctx, setState) {
        if (liberi.isEmpty) {
          return const Padding(padding: EdgeInsets.all(20), child: Testo.bodyMedium('Hai già una pratica per ogni anno disponibile.'));
        }
        if (persone.isEmpty) {
          return Padding(
            padding: const EdgeInsets.all(20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Testo.bodyMedium('Per avviare una pratica servono le persone della famiglia (anche senza account): aggiungile prima in Impostazioni famiglia.'),
              const SizedBox(height: 12),
              FilledButton(onPressed: () {
                Navigator.pop(ctx);
                apriDalMenu('/famiglia/impostazioni');
              }, child: const Text('Vai a Impostazioni famiglia')),
            ]),
          );
        }
        return Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Testo.titleSmall('Quale pratica', fontWeight: FontWeight.w600),
            RadioGroup<ModelloPratica>(
              groupValue: scelto,
              onChanged: (m) => setState(() => scelto = m),
              child: Column(children: [
                for (final m in liberi)
                  RadioListTile<ModelloPratica>(
                    contentPadding: EdgeInsets.zero,
                    value: m,
                    title: Text(m.titolo),
                    subtitle: Text(m.provvisorio ? '${m.descrizione}\nRegole provvisorie (dell\'anno prima), da ricontrollare.' : m.descrizione),
                  ),
              ]),
            ),
            const SizedBox(height: 8),
            Testo.titleSmall(scelto?.modello == 'isee' ? 'Il nucleo familiare' : 'Per chi', fontWeight: FontWeight.w600),
            Testo.bodySmall(
              scelto?.modello == 'isee'
                  ? 'Le persone del nucleo della DSU. Chi manca va prima aggiunto alla famiglia, anche senza account.'
                  : 'Le persone della famiglia di cui raccogliere spese e redditi.',
              tenue: true,
            ),
            for (final p in persone)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: selezionate.contains(p.id),
                title: Text(p.nome),
                subtitle: p.relazione == null ? null : Text(p.relazione!),
                onChanged: (v) => setState(() => v == true ? selezionate.add(p.id) : selezionate.remove(p.id)),
              ),
            if (telefono)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Testo.bodySmall('I documenti si analizzano su questo iPhone con il modello di Apple: tieni l\'app aperta durante l\'analisi.', tenue: true),
              ),
          ]),
        );
      },
    ),
    azione: (ctx) => StatefulBuilder(
      builder: (ctx, setState) => FilledButton(
        onPressed: invio || scelto == null || selezionate.isEmpty
            ? null
            : () async {
                setState(() => invio = true);
                try {
                  final p = await PraticheApi.avvia(tenantId, modello: scelto!.modello, anno: scelto!.anno, persone: selezionate.toList(), analisiSu: telefono ? 'telefono' : 'server');
                  if (ctx.mounted) Navigator.pop(ctx);
                  Get.toNamed('/pratiche/pratica', parameters: {'id': p.id});
                } on ApiException catch (e) {
                  setState(() => invio = false);
                  if (ctx.mounted) mostraMessaggio(ctx, e.message, errore: true);
                }
              },
        child: Text(invio ? 'Avvio…' : 'Avvia e cerca nei documenti'),
      ),
    ),
  );
}
