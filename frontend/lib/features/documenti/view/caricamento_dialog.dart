import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/files/folder_picker.dart';
import 'package:cartellaviva/helpers/files/upload_batch.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Scelta dei file dal dispositivo (più file insieme).
Future<List<({String nome, Uint8List bytes})>> scegliFile() async {
  final result = await FilePicker.platform.pickFiles(
    allowMultiple: true,
    withData: true,
    type: FileType.custom,
    allowedExtensions: acceptedFileTypes.keys.toList(),
  );
  if (result == null) return [];
  return [
    for (final f in result.files)
      if (f.bytes != null) (nome: f.name, bytes: f.bytes!),
  ];
}

/// Conferma unica per più file: stessa categoria e stesse persone per tutti,
/// poi un documento per file. Restituisce quanti documenti sono stati creati.
Future<int> showCaricamentoDialog(
  BuildContext context, {
  required String tenantId,
  required List<({String nome, Uint8List bytes})> files,
  required List<Categoria> categorie,
  required List<MembroFamiglia> persone,
}) {
  final prepared = preparaCaricamento(files);
  return _mostra(context, tenantId: tenantId, accettati: prepared.accettati, scartati: prepared.scartati, categorie: categorie, persone: persone);
}

/// Come [showCaricamentoDialog] per tutti i file di una cartella: il dialogo si
/// apre subito e ogni file si legge solo quando tocca a lui nella coda.
Future<int> showCaricamentoCartellaDialog(
  BuildContext context, {
  required String tenantId,
  required CartellaScelta cartella,
  required List<Categoria> categorie,
  required List<MembroFamiglia> persone,
}) =>
    _mostra(context, tenantId: tenantId, accettati: cartella.files, scartati: cartella.scartati, categorie: categorie, persone: persone, cartella: cartella.nome);

Future<int> _mostra(
  BuildContext context, {
  required String tenantId,
  required List<FileDaCaricare> accettati,
  required List<FileScartato> scartati,
  required List<Categoria> categorie,
  required List<MembroFamiglia> persone,
  String? cartella,
}) async {
  if (accettati.isEmpty) {
    if (context.mounted) {
      final elenco = scartati.take(8).map((s) => '${s.nome}: ${s.motivo}').join('\n');
      final altri = scartati.length > 8 ? '\n… e altri ${scartati.length - 8}' : '';
      final vuoto = cartella != null ? 'Nella cartella "$cartella" non ci sono file da caricare.' : 'Nessun file selezionato.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(scartati.isEmpty ? vuoto : 'Nessun file caricabile.\n$elenco$altri')));
    }
    return 0;
  }
  final created = await showDialog<int>(
    context: context,
    barrierDismissible: false,
    builder: (_) => _CaricamentoDialog(tenantId: tenantId, files: accettati, scartati: scartati, categorie: categorie, persone: persone, cartella: cartella),
  );
  return created ?? 0;
}

class _CaricamentoDialog extends StatefulWidget {
  final String tenantId;
  final List<FileDaCaricare> files;
  final List<FileScartato> scartati;
  final List<Categoria> categorie;
  final List<MembroFamiglia> persone;
  final String? cartella;

  const _CaricamentoDialog({required this.tenantId, required this.files, required this.scartati, required this.categorie, required this.persone, this.cartella});

  @override
  State<_CaricamentoDialog> createState() => _CaricamentoDialogState();
}

class _CaricamentoDialogState extends State<_CaricamentoDialog> {
  String? categoriaId;
  final Set<String> personeIds = {};
  bool running = false;
  bool finished = false;
  bool cancelled = false;

  List<FileDaCaricare> get files => widget.files;

  int get caricati => files.where((f) => f.stato == StatoCaricamento.caricato).length;

  Future<void> _carica() async {
    setState(() => running = true);
    for (final f in files) {
      if (cancelled) break;
      if (f.stato == StatoCaricamento.caricato) continue;
      setState(() {
        f.stato = StatoCaricamento.inCorso;
        f.errore = null;
      });
      try {
        // Letto adesso, non prima: in memoria c'è solo il file in caricamento.
        final Uint8List bytes;
        try {
          bytes = await f.leggi();
        } catch (e) {
          throw ApiException('File non leggibile (spostato o cancellato?)');
        }
        final doc = await DocumentoApi.upload(
          widget.tenantId,
          bytes,
          fileName: f.nome,
          mimeType: f.mimeType,
          categoriaId: categoriaId!,
          membriIds: personeIds.toList(),
          onProgress: (sent, total) => setState(() => f.progresso = total > 0 ? sent / total : 0),
        );
        f.documentoId = doc.id;
        f.duplicato = doc.possibiliDuplicati.isNotEmpty;
        f.stato = StatoCaricamento.caricato;
      } on ApiException catch (e) {
        f.stato = StatoCaricamento.errore;
        f.errore = e.message;
      }
      if (mounted) setState(() {});
    }
    if (mounted) {
      setState(() {
        running = false;
        finished = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final errori = files.where((f) => f.stato == StatoCaricamento.errore).length;
    return AlertDialog(
      title: Text(finished
          ? 'Caricamento completato'
          : 'Carica ${files.length} ${files.length == 1 ? 'documento' : 'documenti'}${widget.cartella != null ? ' dalla cartella "${widget.cartella}"' : ''}'),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Testo.bodySmall('Ogni file diventa un documento, con il nome del file come titolo. Potrai modificarli uno per uno dopo.', tenue: true),
            SizedBox(height: 16),
            DropdownButtonFormField<String>(
              initialValue: categoriaId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Categoria per tutti i file', isDense: true),
              items: [
                for (final c in widget.categorie)
                  DropdownMenuItem(value: c.id, child: Text(c.isSensibile ? '${c.nome} (cifrata)' : c.nome, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: running || finished ? null : (v) => setState(() => categoriaId = v),
            ),
            if (widget.persone.isNotEmpty) ...[
              SizedBox(height: 12),
              Testo.labelMedium('Riguardano (facoltativo)'),
              SizedBox(height: 6),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final p in widget.persone)
                  FilterChip(
                    label: Text(p.nome),
                    selected: personeIds.contains(p.id),
                    onSelected: running || finished ? null : (v) => setState(() => v ? personeIds.add(p.id) : personeIds.remove(p.id)),
                  ),
              ]),
            ],
            SizedBox(height: 16),
            // Molti file (una cartella): elenco a scorrimento, costruito solo per le righe visibili.
            if (files.length <= 8)
              for (final f in files) _fileRow(f)
            else
              SizedBox(height: 360, child: ListView.builder(itemCount: files.length, itemBuilder: (_, i) => _fileRow(files[i]))),
            if (widget.scartati.isNotEmpty) ...[
              SizedBox(height: 12),
              Testo.labelMedium('Non caricati (${widget.scartati.length})'),
              for (final s in widget.scartati.take(30)) Testo.bodySmall('• ${s.nome}: ${s.motivo}', tenue: true),
              if (widget.scartati.length > 30) Testo.bodySmall('… e altri ${widget.scartati.length - 30}', tenue: true),
            ],
            if (finished) ...[
              SizedBox(height: 12),
              Testo.bodyMedium(errori == 0 ? 'Caricati $caricati documenti.' : 'Caricati $caricati documenti, $errori non riusciti: puoi riprovare.'),
            ],
          ]),
        ),
      ),
      actions: [
        if (running)
          TextButton(onPressed: () => setState(() => cancelled = true), child: const Text('Interrompi dopo il file in corso'))
        else
          TextButton(onPressed: () => Navigator.of(context).pop(caricati), child: Text(finished ? 'Chiudi' : 'Annulla')),
        if (!running && (!finished || errori > 0))
          FilledButton(
            onPressed: categoriaId == null
                ? null
                : () {
                    cancelled = false;
                    _carica();
                  },
            child: Text(finished ? 'Riprova i non riusciti' : 'Carica'),
          ),
      ],
    );
  }

  Widget _fileRow(FileDaCaricare f) {
    final icon = switch (f.stato) {
      StatoCaricamento.caricato => Icon(LucideIcons.circle_check, size: 18, color: ColoreStato.successo),
      StatoCaricamento.errore => Icon(LucideIcons.circle_x, size: 18, color: ColoreStato.errore),
      StatoCaricamento.inCorso => const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
      StatoCaricamento.inAttesa => Icon(f.mimeType == 'application/pdf' ? LucideIcons.file_text : LucideIcons.image, size: 18),
    };
    return Padding(
      padding: EdgeInsets.only(bottom: 8),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        icon,
        SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.bodyMedium(f.nome, maxLines: 1, overflow: TextOverflow.ellipsis),
            if (f.percorso != null && f.percorso != f.nome) Testo.bodySmall(f.percorso!, tenue: true, maxLines: 1, overflow: TextOverflow.ellipsis),
            if (f.stato == StatoCaricamento.inCorso) LinearProgressIndicator(value: f.progresso),
            if (f.errore != null) Testo.bodySmall(f.errore!, color: ColoreStato.errore),
            if (f.duplicato) Testo.bodySmall('C\'era già un documento con lo stesso contenuto.', color: ColoreStato.avviso),
          ]),
        ),
        SizedBox(width: 8),
        Testo.bodySmall(formatBytes(f.dimensione), tenue: true),
        if (!running && !finished)
          IconButton(
            tooltip: 'Togli',
            visualDensity: VisualDensity.compact,
            icon: const Icon(LucideIcons.x, size: 16),
            onPressed: files.length > 1 ? () => setState(() => files.remove(f)) : null,
          ),
      ]),
    );
  }
}
