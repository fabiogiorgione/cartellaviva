import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/features/documenti/scan/document_capture.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/richieste/api/richieste_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve, formatIsoDate;
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;
import 'package:cartellaviva/features/documenti/view/caricamento_dialog.dart' show scegliFile;
import 'package:cartellaviva/features/richieste/view/firma_codice_dialog.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// Una richiesta di un ente: cosa chiede e come rispondere.
class RichiestaScreen extends StatefulWidget {
  const RichiestaScreen({super.key});

  @override
  State<RichiestaScreen> createState() => _RichiestaScreenState();
}

class _RichiestaScreenState extends State<RichiestaScreen> {
  final String id = Get.parameters['id'] ?? '';
  SessionService get session => SessionService.to;
  String get tenantId => session.currentTenantId ?? '';
  late Future<RichiestaFamiglia> _future = RichiesteApi.get(tenantId, id);
  bool busy = false;

  void _reload() => setState(() => _future = RichiesteApi.get(tenantId, id));

  void _snack(String m) => mostraMessaggio(context, m);

  Future<void> _run(Future<void> Function() action, {String? ok}) async {
    setState(() => busy = true);
    try {
      await action();
      if (ok != null) _snack(ok);
      _reload();
    } on ApiException catch (e) {
      _snack(e.message);
    } catch (e) {
      _snack(e.toString());
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _apriAllegato(RichiestaFamiglia r) => _run(() async {
        final bytes = await RichiesteApi.allegato(tenantId, r.id);
        await saveOrOpenFile(bytes, r.allegatoNome ?? 'documento.pdf', 'application/pdf');
      });

  /// Chi ha firmato / chi consegna / chi ha letto.
  Future<List<String>?> _firmatari(RichiestaFamiglia r, String titolo) async {
    final scelti = r.referenti.map((x) => x.id).toSet();
    return showDialog<List<String>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text(titolo),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            for (final ref in r.referenti)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: scelti.contains(ref.id),
                title: Text(ref.nome),
                onChanged: (v) => setLocal(() => v == true ? scelti.add(ref.id) : scelti.remove(ref.id)),
              ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Annulla')),
            FilledButton(onPressed: scelti.isEmpty ? null : () => Navigator.pop(ctx, scelti.toList()), child: const Text('Conferma')),
          ],
        ),
      ),
    );
  }

  /// Se l'ente la chiede: fino a quando vale il documento. null = annullato (o non richiesta).
  Future<({bool ok, String? data})> _validita(RichiestaFamiglia r) async {
    if (!r.chiediValidita || r.tipo == 'presa_visione') return (ok: true, data: null);
    final d = await showDatePicker(
      context: context,
      helpText: 'Il documento è valido fino al',
      initialDate: DateTime.now().add(const Duration(days: 365)),
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 3650)),
    );
    return d == null ? (ok: false, data: null) : (ok: true, data: formatIsoDate(d));
  }

  Future<void> _caricaFile(RichiestaFamiglia r) async {
    final files = await scegliFile();
    if (files.isEmpty) return;
    final f = files.first;
    final mime = mimeTypeFor(f.nome);
    if (mime == null) {
      _snack('Tipo di file non supportato: si possono caricare $formatiAccettati.');
      return;
    }
    final firmatari = await _firmatari(r, r.tipo == 'firma' ? 'Chi ha firmato?' : 'Chi consegna?');
    if (firmatari == null) return;
    final validita = await _validita(r);
    if (!validita.ok) return;
    await _run(() => RichiesteApi.rispondiConFile(tenantId, r.id, f.bytes, fileName: f.nome, mimeType: mime, firmatari: firmatari, validoFino: validita.data), ok: 'Inviato all\'ente.');
  }

  Future<void> _dallArchivio(RichiestaFamiglia r) async {
    final doc = await showDialog<Documento>(context: context, builder: (_) => _SceltaDocumento(tenantId: tenantId));
    if (doc == null) return;
    final firmatari = await _firmatari(r, 'Chi consegna?');
    if (firmatari == null) return;
    final validita = await _validita(r);
    if (!validita.ok) return;
    await _run(() => RichiesteApi.rispondi(tenantId, r.id, firmatari: firmatari, documentoId: doc.id, validoFino: validita.data), ok: 'Inviato all\'ente.');
  }

  Future<void> _presaVisione(RichiestaFamiglia r) async {
    final firmatari = await _firmatari(r, 'Chi ha letto?');
    if (firmatari == null) return;
    await _run(() => RichiesteApi.rispondi(tenantId, r.id, firmatari: firmatari), ok: 'Presa visione confermata.');
  }

  Future<void> _nonPosso(RichiestaFamiglia r) async {
    final messaggio = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(r.tipo == 'firma' ? 'Non firmo / non autorizzo' : 'Non posso rispondere'),
        content: SizedBox(
          width: 420,
          child: TextField(
            controller: messaggio,
            maxLines: 4,
            maxLength: 1000,
            decoration: const InputDecoration(labelText: 'Messaggio per l\'ente', hintText: 'Es. Leo non parteciperà all\'uscita.'),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Invia')),
        ],
      ),
    );
    if (ok == true) {
      await _run(() => RichiesteApi.rifiuta(tenantId, r.id, messaggio.text), ok: 'Messaggio inviato all\'ente.');
    }
    messaggio.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Flexible(child: Testo.titleMedium('Richiesta', fontSize: 18, fontWeight: FontWeight.w500)),
            Briciole(voci: [Briciola(nome: 'Richieste', rotta: '/richieste'), Briciola(nome: 'Dettaglio', attiva: true)]),
          ]),
        ),
        SizedBox(height: spazioPagina),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: FutureBuilder<RichiestaFamiglia>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
              }
              if (snap.hasError) {
                return Scheda(paddingAll: 24, child: Testo.bodyMedium(snap.error.toString()));
              }
              return _dettaglio(snap.data!);
            },
          ),
        ),
      ]),
    );
  }

  Widget _dettaglio(RichiestaFamiglia r) {
    final canRespond = session.can('richiesta:respond') && r.daFare;
    final firmano = r.referenti.map((x) => x.nome).join(' e ');
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.bodySmall('${r.enteNome} · ${r.tipoLabel}', tenue: true),
          SizedBox(height: 6),
          Testo.titleMedium(r.titolo, fontWeight: FontWeight.w500),
          SizedBox(height: 8),
          Testo.bodyMedium([
            '${r.tipo == 'presa_visione' ? 'Per' : 'Firma'}: $firmano${r.firmatari == 'uno' && r.referenti.length > 1 ? ' (basta uno)' : ''}',
            'riguarda ${r.iscritto}',
            if (r.entroIl != null) 'entro il ${formatDataBreve(r.entroIl!)}${r.scaduta ? ' (scaduta: puoi ancora rispondere)' : ''}',
          ].join(' · ')),
          if ((r.istruzioni ?? '').isNotEmpty) ...[
            SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: EdgeInsets.all(12),
              decoration: BoxDecoration(color: context.colori.primary.withAlpha(18), borderRadius: BorderRadius.circular(6)),
              child: Testo.bodyMedium('"${r.istruzioni}"'),
            ),
          ],
          if (r.stato == 'da_rifirmare' && r.motivoEnte != null) ...[
            SizedBox(height: 12),
            Testo.bodyMedium('L\'ente chiede di correggere: "${r.motivoEnte}"', color: context.colori.danger),
          ],
          SizedBox(height: 12),
          Chip(label: Text(r.statoLabel)),
        ])),
      if (r.allegatoNome != null) ...[
        SizedBox(height: 12),
        Scheda(paddingAll: 16, child: Row(children: [
            const Icon(LucideIcons.file_text),
            SizedBox(width: 12),
            Expanded(child: Testo.bodyMedium('${r.allegatoNome} · ${formatBytes(r.allegatoDimensione ?? 0)} · dall\'ente')),
            OutlinedButton.icon(onPressed: busy ? null : () => _apriAllegato(r), icon: const Icon(LucideIcons.download, size: 16), label: const Text('Apri')),
          ])),
      ],
      SizedBox(height: 12),
      if (canRespond) _azioni(r) else _esito(r),
    ]);
  }

  Future<void> _firmaCodice(RichiestaFamiglia r, RichiestaReferente io) async {
    final ok = await showFirmaCodiceDialog(
      context,
      titolo: r.titolo,
      firmatario: io.nome,
      apriDocumento: () async => saveOrOpenFile(await RichiesteApi.allegato(tenantId, r.id), r.allegatoNome ?? 'documento.pdf', 'application/pdf'),
      chiediCodice: () => RichiesteApi.codiceFirma(tenantId, r.id),
      firma: (codice) => RichiesteApi.firma(tenantId, r.id, codice),
    );
    if (ok) {
      _snack('Firmato.');
      _reload();
    }
  }

  /// Firma con codice: ognuno firma per sé; resta la carta come alternativa.
  Widget _azioniCodice(RichiestaFamiglia r) {
    final io = r.daFirmareIo;
    final mancano = r.referenti.where((x) => !x.firmato).map((x) => x.nome).toList();
    final hoFirmato = r.referenti.any((x) => x.tuo && x.firmato);
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Testo.bodyMedium('L\'ente chiede una firma elettronica con codice: leggi il documento e conferma con il codice che ricevi via email. Niente stampa né scansione.'),
        SizedBox(height: 12),
        if (io != null)
          FilledButton.icon(onPressed: busy ? null : () => _firmaCodice(r, io), icon: const Icon(LucideIcons.pen_line, size: 18), label: Text('Firma come ${io.nome}'))
        else if (hoFirmato)
          Testo.bodyMedium('Hai firmato. ${mancano.isEmpty ? '' : 'Manca: ${mancano.join(', ')} (firma dal proprio account).'}')
        else
          Testo.bodyMedium('Ogni referente firma dal proprio account: ${r.referenti.map((x) => x.nome).join(', ')}.'),
        const Divider(height: 28),
        Testo.bodySmall('Preferisci firmare su carta?', tenue: true),
        Wrap(spacing: 8, children: [
          TextButton(onPressed: busy ? null : () => _apriAllegato(r), child: const Text('Scarica e stampa')),
          TextButton(onPressed: busy ? null : () => _caricaFile(r), child: const Text('Carica la scansione firmata')),
          TextButton(onPressed: busy ? null : () => _nonPosso(r), child: const Text('Non firmo / non autorizzo')),
        ]),
      ]));
  }

  Widget _azioni(RichiestaFamiglia r) {
    if (r.firmaConCodice) return _azioniCodice(r);
    final scanner = DocumentCapture().isSupported;
    final passi = switch (r.tipo) {
      'firma' => ['Apri il PDF e stampalo', 'Firma su carta nelle pagine indicate', 'Scansiona tutte le pagine (anche quelle senza firma)'],
      'invio' => ['Scansiona il documento, caricalo da un file o sceglilo dal tuo archivio'],
      _ => ['Leggi il documento, poi conferma la presa visione'],
    };
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        for (var i = 0; i < passi.length; i++) Padding(padding: EdgeInsets.only(bottom: 6), child: Testo.bodyMedium('${i + 1}. ${passi[i]}')),
        SizedBox(height: 12),
        Wrap(spacing: 12, runSpacing: 12, children: [
          if (r.tipo == 'presa_visione')
            FilledButton.icon(onPressed: busy ? null : () => _presaVisione(r), icon: const Icon(LucideIcons.check, size: 18), label: const Text('Confermo la presa visione'))
          else ...[
            if (scanner)
              FilledButton.icon(
                onPressed: busy ? null : () async {
                  await Get.toNamed('/documenti/nuovo?richiesta=${r.id}');
                  _reload();
                },
                icon: const Icon(LucideIcons.scan_line, size: 18),
                label: Text(r.tipo == 'firma' ? 'Scansiona il documento firmato' : 'Scansiona'),
              ),
            OutlinedButton.icon(onPressed: busy ? null : () => _caricaFile(r), icon: const Icon(LucideIcons.upload, size: 18), label: Text(r.tipo == 'firma' ? 'Carica un PDF già pronto' : 'Carica un file')),
            if (r.tipo == 'invio')
              OutlinedButton.icon(onPressed: busy ? null : () => _dallArchivio(r), icon: const Icon(LucideIcons.folder_open, size: 18), label: const Text('Scegli dall\'archivio')),
          ],
          TextButton(onPressed: busy ? null : () => _nonPosso(r), child: Text(r.tipo == 'firma' ? 'Non firmo / non autorizzo' : 'Non posso rispondere')),
        ]),
        SizedBox(height: 8),
        Testo.bodySmall('Quello che invii resta anche all\'ente, che deve conservarlo. Una copia resta a te, in "Condivisi con enti".', tenue: true),
      ]));
  }

  Widget _esito(RichiestaFamiglia r) {
    final testo = switch (r.stato) {
      'da_verificare' => 'Inviata${r.rispostaDa != null ? ' da ${r.rispostaDa}' : ''}${r.rispostaIl != null ? ' il ${formatDataBreve(r.rispostaIl!)}' : ''}: l\'ente la sta verificando.',
      'completata' => 'Completata: l\'ente ha accettato la risposta.',
      'rifiutata' => 'Hai risposto all\'ente: "${r.messaggioFamiglia ?? ''}"',
      'annullata' => 'La richiesta è stata annullata o non serve più.',
      _ => 'Il tuo ruolo permette solo di consultare questa richiesta.',
    };
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Testo.bodyMedium(testo),
        if (r.documentoId != null) ...[
          SizedBox(height: 8),
          TextButton.icon(onPressed: () => Get.toNamed('/documento/${r.documentoId}'), icon: const Icon(LucideIcons.file_text, size: 16), label: const Text('La tua copia')),
        ],
      ]));
  }
}

/// Scelta di un documento dell'archivio (con ricerca).
class _SceltaDocumento extends StatefulWidget {
  final String tenantId;

  const _SceltaDocumento({required this.tenantId});

  @override
  State<_SceltaDocumento> createState() => _SceltaDocumentoState();
}

class _SceltaDocumentoState extends State<_SceltaDocumento> {
  late Future<List<Documento>> _future = DocumentoApi.list(widget.tenantId);

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Scegli dall\'archivio'),
      content: SizedBox(
        width: 520,
        height: 420,
        child: Column(children: [
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(LucideIcons.search, size: 18), hintText: 'Cerca', isDense: true),
            onSubmitted: (q) => setState(() => _future = DocumentoApi.list(widget.tenantId, q: q)),
          ),
          SizedBox(height: 8),
          Expanded(
            child: FutureBuilder<List<Documento>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                if (snap.hasError) return Center(child: Text(snap.error.toString()));
                final docs = snap.data!;
                if (docs.isEmpty) return const Center(child: Text('Nessun documento.'));
                return ListView(children: [
                  for (final d in docs)
                    ListTile(
                      leading: Icon(d.isPdf ? LucideIcons.file_text : LucideIcons.image),
                      title: Text(d.titolo, maxLines: 1, overflow: TextOverflow.ellipsis),
                      subtitle: Text(d.categoria?.nome ?? ''),
                      onTap: () => Navigator.pop(context, d),
                    ),
                ]);
              },
            ),
          ),
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Annulla'))],
    );
  }
}
