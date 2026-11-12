import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;
import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/import/api/import_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/import/model/import_source.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:url_launcher/url_launcher.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Import da Google Drive: cartella, collegamento dell'account (nel browser,
/// con ritorno al web o all'app) e sincronizzazione manuale.
class TabDrive extends StatefulWidget {
  const TabDrive({super.key});

  @override
  State<TabDrive> createState() => _TabDriveState();
}

class _TabDriveState extends State<TabDrive> {
  SessionService get session => SessionService.to;
  String get tenantId => session.currentTenantId!;
  List<ImportSource> sources = [];
  bool loading = true;
  String? error;
  final Set<String> busy = {};
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _load({bool quiet = false}) async {
    if (!quiet) setState(() => loading = true);
    try {
      sources = await ImportApi.list(tenantId);
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    if (!mounted) return;
    setState(() => loading = false);
    // Mentre una sincronizzazione è in corso si aggiorna da sola.
    _poll?.cancel();
    if (sources.any((s) => s.sincronizzazioneInCorso)) {
      _poll = Timer(const Duration(seconds: 5), () => _load(quiet: true));
    }
  }

  Future<void> _run(String id, Future<void> Function() action) async {
    setState(() => busy.add(id));
    try {
      await action();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
    if (mounted) setState(() => busy.remove(id));
  }

  Future<void> _collega(ImportSource s) => _run(s.id, () async {
        // Web: si torna alla pagina /import/esito; app: deep link cartellaviva://.
        final url = await ImportApi.connect(tenantId, s.id, ritorno: kIsWeb ? 'web' : 'app');
        await launchUrl(url, mode: kIsWeb ? LaunchMode.platformDefault : LaunchMode.externalApplication, webOnlyWindowName: '_self');
      });

  Future<void> _sync(ImportSource s) => _run(s.id, () async {
        await ImportApi.sync(tenantId, s.id);
        await _load(quiet: true);
      });

  Future<void> _rimuovi(ImportSource s) async {
    final ok = await chiediConferma(
      context,
      titolo: 'Rimuovere la cartella?',
      testo: 'I documenti già importati restano nella famiglia. Per togliere del tutto l\'accesso, revocalo anche dal tuo account Google.',
      azione: 'Rimuovi',
    );
    if (ok) {
      await _run(s.id, () async {
        await ImportApi.delete(tenantId, s.id);
        await _load(quiet: true);
      });
    }
  }

  Future<void> _aggiungi() async {
    final link = TextEditingController();
    var strategia = 'cartelle';
    String? err;
    final created = await showDialog<ImportSource>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Importa da Google Drive'),
          content: SizedBox(
            width: 480,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodySmall('Apri la cartella in Google Drive, copia il link dalla barra del browser (o da "Condividi › Copia link") e incollalo qui.', tenue: true),
              SizedBox(height: 12),
              TextField(
                controller: link,
                decoration: const InputDecoration(labelText: 'Link della cartella', hintText: 'https://drive.google.com/drive/folders/…', isDense: true),
              ),
              SizedBox(height: 16),
              Testo.labelMedium('Come scegliere la categoria'),
              RadioGroup<String>(
                groupValue: strategia,
                onChanged: (v) => setLocal(() => strategia = v ?? strategia),
                child: const Column(children: [
                  RadioListTile(contentPadding: EdgeInsets.zero, value: 'cartelle', title: Text('Dal nome delle sottocartelle'), subtitle: Text('Es. "Bollette", "Salute": la sottocartella diventa la categoria.')),
                  RadioListTile(contentPadding: EdgeInsets.zero, value: 'ai_contenuto', title: Text('Leggendo il contenuto'), subtitle: Text('L\'assistente sul nostro server propone la categoria; i dubbi finiscono in "Da categorizzare".')),
                ]),
              ),
              if (err != null) Testo.bodySmall(err!, color: ColoreStato.errore),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Annulla')),
            FilledButton(
              onPressed: () async {
                try {
                  final s = await ImportApi.create(tenantId, cartella: link.text, strategia: strategia);
                  if (ctx.mounted) Navigator.pop(ctx, s);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Aggiungi'),
            ),
          ],
        ),
      ),
    );
    link.dispose();
    if (created != null) {
      await _load(quiet: true);
      await _collega(created);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (MediaQuery.of(context).size.width < larghezzaTelefono)
        // Telefono: il testo a tutta larghezza, il pulsante sotto.
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.bodySmall('CartellaViva legge le cartelle in sola lettura e importa i file nuovi quando premi "Sincronizza". Un file già importato non viene ricopiato.', tenue: true),
          if (!session.isReadOnly) ...[
            SizedBox(height: 12),
            FilledButton.icon(onPressed: _aggiungi, icon: const Icon(LucideIcons.folder_plus, size: 18), label: const Text('Aggiungi cartella')),
          ],
        ])
      else
        Row(children: [
          Expanded(
            child: Testo.bodySmall('CartellaViva legge le cartelle in sola lettura e importa i file nuovi quando premi "Sincronizza". Un file già importato non viene ricopiato.', tenue: true),
          ),
          if (!session.isReadOnly) FilledButton.icon(onPressed: _aggiungi, icon: const Icon(LucideIcons.folder_plus, size: 18), label: const Text('Aggiungi cartella')),
        ]),
      SizedBox(height: 12),
      if (error != null) Testo.bodySmall(error!, color: ColoreStato.errore),
      if (sources.isEmpty && error == null) Testo.bodyMedium('Nessuna cartella collegata.'),
      for (final s in sources) _card(s),
    ]);
  }

  Widget _card(ImportSource s) {
    final working = busy.contains(s.id);
    final stato = s.sincronizzazioneInCorso ? 'Sincronizzazione in corso…' : s.statoLabel;
    return Card(
      margin: EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(LucideIcons.folder, size: 20),
            SizedBox(width: 10),
            Expanded(child: Testo.bodyMedium('Cartella ${s.cartellaRadiceId}', fontWeight: FontWeight.w500, maxLines: 1, overflow: TextOverflow.ellipsis)),
            Chip(label: Text(stato), visualDensity: VisualDensity.compact),
          ]),
          SizedBox(height: 6),
          Testo.bodySmall([
            s.strategiaLabel,
            if (s.accountEsterno != null) 'account ${s.accountEsterno}',
            if (s.ultimaSincronizzazione != null) 'ultima sincronizzazione ${formatDataBreve(s.ultimaSincronizzazione!)}${s.esitoLabel.isEmpty ? '' : ': ${s.esitoLabel}'}',
          ].join(' · '), tenue: true),
          if (s.ultimoErrore != null) ...[SizedBox(height: 6), Testo.bodySmall(s.ultimoErrore!, color: ColoreStato.errore)],
          SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (!s.collegata || s.stato == 'errore')
              FilledButton.icon(onPressed: working || session.isReadOnly ? null : () => _collega(s), icon: const Icon(LucideIcons.link, size: 16), label: Text(s.collegata ? 'Ricollega' : 'Collega Google')),
            if (s.collegata)
              OutlinedButton.icon(
                onPressed: working || s.sincronizzazioneInCorso || session.isReadOnly ? null : () => _sync(s),
                icon: const Icon(LucideIcons.refresh_cw, size: 16),
                label: const Text('Sincronizza ora'),
              ),
            TextButton.icon(onPressed: working || session.isReadOnly ? null : () => _rimuovi(s), icon: const Icon(LucideIcons.trash, size: 16), label: const Text('Rimuovi')),
          ]),
        ]),
      ),
    );
  }
}
