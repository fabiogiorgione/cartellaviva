import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/controller/nuovo_documento_controller.dart';
import 'package:cartellaviva/features/documenti/scan/document_capture.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/conferma.dart';

/// "Nuovo documento": scansione multipagina -> PDF -> upload.
class NuovoDocumentoScreen extends StatefulWidget {
  const NuovoDocumentoScreen({super.key});

  @override
  State<NuovoDocumentoScreen> createState() => _NuovoDocumentoScreenState();
}

class _NuovoDocumentoScreenState extends State<NuovoDocumentoScreen> {
  /// ?richiesta=<id>: la scansione risponde a una richiesta di un ente (bozza separata).
  final String? richiestaId = Get.parameters['richiesta'];
  late final String tag = richiestaId == null ? 'documento' : 'richiesta-$richiestaId';
  late final NuovoDocumentoController controller = Get.put(NuovoDocumentoController(richiestaId: richiestaId), tag: tag);
  final TextEditingController titolo = TextEditingController();

  @override
  void dispose() {
    titolo.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<NuovoDocumentoController>(
        init: controller,
        tag: tag,
        builder: (c) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Flexible(child: Testo.titleMedium(richiestaId == null ? 'Nuovo documento' : 'Scansiona la risposta', fontSize: 18, fontWeight: FontWeight.w500)),
                    Briciole(voci: [
                      richiestaId == null ? Briciola(nome: 'Documenti', rotta: '/documenti') : Briciola(nome: 'Richieste', rotta: '/richieste'),
                      Briciola(nome: 'Scansione', attiva: true),
                    ]),
                  ],
                ),
              ),
              SizedBox(height: spazioPagina),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina),
                child: _body(c),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _body(NuovoDocumentoController c) {
    if (c.loading) {
      return Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    }
    if (c.draft == null) {
      return _message(LucideIcons.triangle_alert, c.error ?? 'Nessuna famiglia selezionata.');
    }
    if (!c.canCreate) {
      return _message(LucideIcons.lock, richiestaId == null ? 'Il tuo ruolo non permette di caricare documenti in questa famiglia.' : 'Il tuo ruolo non permette di rispondere alle richieste degli enti.');
    }
    return switch (c.step) {
      ScanStep.pagine => _pagine(c),
      ScanStep.revisione => _revisione(c),
      ScanStep.conferma => _conferma(c),
    };
  }

  // ---- Pagine catturate --------------------------------------------------------

  Widget _pagine(NuovoDocumentoController c) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (c.resumed) _banner(LucideIcons.scan_line, 'Hai una scansione non ancora caricata (${c.pages.length} pagine): puoi continuarla o annullarla.'),
        if (c.error != null) _banner(LucideIcons.triangle_alert, c.error!, danger: true),
        if (!c.isSupported)
          _message(LucideIcons.smartphone, 'La scansione dei documenti è disponibile nell\'app per Android e iOS.')
        else
          Scheda(paddingAll: 20, child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Testo.bodyMedium(c.pages.isEmpty ? 'Inquadra la prima pagina' : 'Aggiungi un\'altra pagina', fontWeight: FontWeight.w500),
                SizedBox(height: 4),
                Testo.bodySmall('I bordi vengono rilevati in automatico; se sbagliano puoi spostare gli angoli prima di confermare.', tenue: true),
                SizedBox(height: 16),
                Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  children: [
                    FilledButton.icon(
                      onPressed: () => c.capture(CaptureSource.camera),
                      icon: Icon(LucideIcons.camera, size: 18),
                      label: Text('Scatta foto'),
                    ),
                    OutlinedButton.icon(
                      onPressed: () => c.capture(CaptureSource.gallery),
                      icon: Icon(LucideIcons.image, size: 18),
                      label: Text('Dalla galleria'),
                    ),
                  ],
                ),
              ],
            )),
        if (c.pages.isNotEmpty) ...[
          SizedBox(height: 20),
          Testo.bodyMedium('Pagine (${c.pages.length}) — tieni premuto e trascina per riordinarle', fontWeight: FontWeight.w500),
          SizedBox(height: 12),
          SizedBox(
            height: 210,
            child: ReorderableListView.builder(
              scrollDirection: Axis.horizontal,
              buildDefaultDragHandles: false,
              itemCount: c.pages.length,
              onReorder: c.reorderPages,
              itemBuilder: (context, index) => _thumbnail(c, c.pages[index], index),
            ),
          ),
          SizedBox(height: 20),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            alignment: WrapAlignment.spaceBetween,
            children: [
              TextButton.icon(
                onPressed: () => _confirmDiscard(c),
                icon: Icon(LucideIcons.trash, size: 18, color: context.colori.danger),
                label: Text('Annulla scansione', style: TextStyle(color: context.colori.danger)),
              ),
              FilledButton.icon(
                onPressed: c.finalizeScan,
                icon: Icon(LucideIcons.file_check, size: 18),
                label: Text('Crea PDF (${c.pages.length} ${c.pages.length == 1 ? 'pagina' : 'pagine'})'),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _thumbnail(NuovoDocumentoController c, ScanPage page, int index) {
    final bytes = c.thumbnails[page.id];
    return ReorderableDelayedDragStartListener(
      key: ValueKey(page.id),
      index: index,
      child: Padding(
        padding: EdgeInsets.only(right: 12),
        child: SizedBox(
          width: 130,
          child: Column(
            children: [
              Expanded(
                child: Container(
                  decoration: BoxDecoration(border: Border.all(color: context.colori.secondary.withAlpha(60)), borderRadius: BorderRadius.circular(6)),
                  clipBehavior: Clip.antiAlias,
                  child: bytes == null ? Center(child: Icon(LucideIcons.image_off)) : Image.memory(bytes, fit: BoxFit.contain, cacheWidth: 260, gaplessPlayback: true),
                ),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Testo.bodySmall('Pag. ${index + 1}${page.biancoNero ? ' · B/N' : ''}'),
                  IconButton(
                    tooltip: 'Elimina pagina',
                    visualDensity: VisualDensity.compact,
                    icon: Icon(LucideIcons.trash, size: 18, color: context.colori.danger),
                    onPressed: () => c.removePage(page),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ---- Revisione della pagina appena catturata -------------------------------------

  Widget _revisione(NuovoDocumentoController c) {
    final preview = c.preview;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Scheda(paddingAll: 12, child: SizedBox(
            height: MediaQuery.of(context).size.height * 0.55,
            child: Stack(
              alignment: Alignment.center,
              children: [
                if (preview != null) Positioned.fill(child: Image.memory(preview.bytes, fit: BoxFit.contain, gaplessPlayback: true)),
                if (c.processing) CircularProgressIndicator(),
              ],
            ),
          )),
        SizedBox(height: 12),
        Scheda(paddingAll: 4, child: SwitchListTile(
            value: c.biancoNero,
            onChanged: c.processing ? null : c.setBiancoNero,
            title: Text('Bianco e nero (effetto scanner)'),
            subtitle: Text('Alto contrasto con soglia adattiva: toglie ombre e sfondo, file più leggero. Sconsigliato per foto e documenti a colori.'),
            secondary: Icon(LucideIcons.contrast),
          )),
        SizedBox(height: 16),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          alignment: WrapAlignment.end,
          children: [
            OutlinedButton.icon(onPressed: c.discardCaptured, icon: Icon(LucideIcons.x, size: 18), label: Text('Scarta')),
            FilledButton.icon(
              onPressed: preview == null || c.processing ? null : c.confirmPage,
              icon: Icon(LucideIcons.check, size: 18),
              label: Text('Conferma pagina'),
            ),
          ],
        ),
      ],
    );
  }

  // ---- Categoria, membri e upload -----------------------------------------------

  /// Risposta a una richiesta: chi ha firmato, poi invio all'ente.
  Widget _confermaRichiesta(NuovoDocumentoController c) {
    final r = c.richiesta;
    return Scheda(paddingAll: 20, child: c.loadingOptions || r == null
          ? Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: c.uploadError != null ? Testo.bodyMedium(c.uploadError!) : const CircularProgressIndicator()),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Testo.bodyMedium('${r.titolo} · ${r.enteNome}', fontWeight: FontWeight.w500),
                SizedBox(height: 4),
                Testo.bodySmall('PDF di ${c.pages.length} ${c.pages.length == 1 ? 'pagina' : 'pagine'}. Una copia resta nella famiglia, in "Condivisi con enti".', tenue: true),
                SizedBox(height: 16),
                Testo.labelMedium(r.tipo == 'firma' ? 'Chi ha firmato' : 'Chi consegna'),
                for (final ref in r.referenti)
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: c.firmatari.contains(ref.id),
                    title: Text(ref.nome),
                    onChanged: c.uploading ? null : (v) => c.toggleFirmatario(ref.id, v == true),
                  ),
                if (c.serveValidita)
                  Row(children: [
                    Expanded(child: Text(c.validoFino == null ? 'Valido fino al: da indicare *' : 'Valido fino al ${c.validoFino!.day}/${c.validoFino!.month}/${c.validoFino!.year}')),
                    TextButton(
                      onPressed: c.uploading
                          ? null
                          : () async {
                              final d = await showDatePicker(context: context, initialDate: DateTime.now().add(const Duration(days: 365)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 3650)));
                              if (d != null) c.setValidoFino(d);
                            },
                      child: const Text('Scegli'),
                    ),
                  ]),
                if (c.uploading) ...[
                  SizedBox(height: 8),
                  LinearProgressIndicator(value: c.progress > 0 ? c.progress : null),
                ],
                if (c.uploadError != null) ...[
                  SizedBox(height: 12),
                  _banner(LucideIcons.triangle_alert, '${c.uploadError!}\nLe pagine restano salvate: puoi riprovare.', danger: true),
                ],
                SizedBox(height: 16),
                Wrap(spacing: 12, runSpacing: 12, alignment: WrapAlignment.spaceBetween, children: [
                  TextButton.icon(onPressed: c.uploading ? null : c.backToPages, icon: const Icon(LucideIcons.arrow_left, size: 18), label: const Text('Pagine')),
                  FilledButton.icon(
                    onPressed: c.uploading || c.firmatari.isEmpty
                        ? null
                        : () async {
                            if (await c.inviaRichiesta()) {
                              Get.offNamed('/richieste/${c.richiestaId}');
                            }
                          },
                    icon: const Icon(LucideIcons.send, size: 18),
                    label: Text(c.uploadError != null ? 'Riprova' : 'Invia all\'ente'),
                  ),
                ]),
              ],
            ));
  }

  Widget _conferma(NuovoDocumentoController c) {
    if (c.richiestaId != null) {
      return _confermaRichiesta(c);
    }
    final draft = c.draft!;
    if (titolo.text != draft.titolo) {
      titolo.text = draft.titolo;
    }
    final categoria = c.categoriaSelezionata;
    return Scheda(paddingAll: 20, child: c.loadingOptions
          ? Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Testo.bodyMedium('PDF di ${c.pages.length} ${c.pages.length == 1 ? 'pagina' : 'pagine'}', fontWeight: FontWeight.w500),
                SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: categoria?.id,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: 'Categoria *'),
                  items: c.categorie
                      .map((cat) => DropdownMenuItem(
                            value: cat.id,
                            child: Row(children: [
                              Expanded(child: Text(cat.nome, overflow: TextOverflow.ellipsis)),
                              if (cat.isSensibile) Icon(LucideIcons.lock, size: 14),
                            ]),
                          ))
                      .toList(),
                  onChanged: c.uploading ? null : c.selectCategoria,
                ),
                if (categoria?.isSensibile ?? false) ...[
                  SizedBox(height: 6),
                  Testo.bodySmall('Categoria sensibile: il documento verrà cifrato sul server.', tenue: true),
                ],
                SizedBox(height: 16),
                if (c.membri.isNotEmpty) ...[
                  Testo.bodySmall('Riguarda (facoltativo)', tenue: true),
                  SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: c.membri
                        .map((m) => FilterChip(
                              label: Text(m.nome),
                              selected: draft.membriIds.contains(m.id),
                              onSelected: c.uploading ? null : (selected) => c.toggleMembro(m.id, selected),
                            ))
                        .toList(),
                  ),
                  SizedBox(height: 16),
                ],
                TextField(
                  controller: titolo,
                  enabled: !c.uploading,
                  maxLength: 200,
                  decoration: InputDecoration(labelText: 'Titolo (facoltativo)', hintText: draft.pdfFileName.replaceAll('.pdf', '')),
                  onChanged: c.setTitolo,
                ),
                if (c.uploading) ...[
                  SizedBox(height: 8),
                  LinearProgressIndicator(value: c.progress > 0 ? c.progress : null),
                  SizedBox(height: 6),
                  Testo.bodySmall(c.progress > 0 ? 'Caricamento ${(c.progress * 100).round()}%' : 'Preparazione del PDF…', tenue: true),
                ],
                if (c.uploadError != null) ...[
                  SizedBox(height: 12),
                  _banner(LucideIcons.triangle_alert, '${c.uploadError!}\nLe pagine restano salvate: puoi riprovare.', danger: true),
                ],
                SizedBox(height: 16),
                Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  alignment: WrapAlignment.spaceBetween,
                  children: [
                    if (c.uploading)
                      TextButton(onPressed: c.cancelUpload, child: Text('Annulla caricamento'))
                    else
                      TextButton.icon(onPressed: c.backToPages, icon: Icon(LucideIcons.arrow_left, size: 18), label: Text('Pagine')),
                    if (!c.uploading && c.uploadError != null)
                      TextButton(
                        onPressed: () => _confirmDiscard(c),
                        child: Text('Annulla scansione', style: TextStyle(color: context.colori.danger)),
                      ),
                    FilledButton.icon(
                      onPressed: c.uploading || categoria == null ? null : () => _upload(c),
                      icon: Icon(LucideIcons.upload, size: 18),
                      label: Text(c.uploadError != null ? 'Riprova' : 'Carica'),
                    ),
                  ],
                ),
              ],
            ));
  }

  Future<void> _upload(NuovoDocumentoController c) async {
    final documento = await c.upload();
    if (documento != null) {
      Get.offNamed('/documento/${documento.id}');
    }
  }

  Future<void> _confirmDiscard(NuovoDocumentoController c) async {
    final ok = await chiediConferma(
      context,
      titolo: 'Annullare la scansione?',
      testo: 'Le ${c.pages.length} pagine catturate verranno eliminate da questo dispositivo.',
      azione: 'Elimina',
      annulla: 'No',
      distruttiva: true,
    );
    if (ok) {
      titolo.clear();
      await c.discardDraft();
    }
  }

  // ---- Elementi comuni ---------------------------------------------------------------

  Widget _banner(IconData icon, String message, {bool danger = false}) {
    final color = danger ? context.colori.danger : context.colori.primary;
    return Container(
      margin: EdgeInsets.only(bottom: 12),
      padding: EdgeInsets.all(12),
      decoration: BoxDecoration(color: color.withAlpha(25), borderRadius: BorderRadius.circular(6)),
      child: Row(children: [Icon(icon, size: 18, color: color), SizedBox(width: 10), Expanded(child: Testo.bodySmall(message))]),
    );
  }

  Widget _message(IconData icon, String message) {
    return Scheda(paddingAll: 24, child: Row(children: [Icon(icon, color: context.colori.secondary), SizedBox(width: 12), Expanded(child: Testo.bodyMedium(message))]));
  }
}
