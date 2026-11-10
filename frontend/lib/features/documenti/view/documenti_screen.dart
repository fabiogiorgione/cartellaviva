import 'dart:typed_data';

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/features/documenti/controller/documenti_controller.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';
import 'package:cartellaviva/features/documenti/model/icone_categoria.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/documenti/view/condivisioni_screens.dart';
import 'package:cartellaviva/features/documenti/view/caricamento_dialog.dart';
import 'package:cartellaviva/helpers/files/folder_picker.dart';
import 'package:cartellaviva/features/documenti/view/documento_form.dart';
import 'package:cartellaviva/features/documenti/view/pannello_filtri.dart';
import 'package:cartellaviva/features/documenti/view/proposta_categorie_wizard.dart';
import 'package:cartellaviva/features/documenti/view/vista_gruppi.dart';
import 'package:cartellaviva/features/documenti/model/proposte_categoria.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/lista_crud.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

part 'documenti_gruppo.dart';
part 'documenti_righe.dart';

/// Colore "#RRGGBB" delle categorie.
Color hexColor(String hex, {Color fallback = Colors.blueGrey}) {
  final value = int.tryParse(hex.replaceFirst('#', ''), radix: 16);
  return value == null ? fallback : Color(0xFF000000 | value);
}

/// Icona del tipo di file (PDF, foto, Word, Excel, PowerPoint...).
IconData iconaTipoFile(Documento d) => switch (d.tipoFile) {
      'PDF' => LucideIcons.file_text,
      'Foto' => LucideIcons.file_image,
      'Foglio di calcolo' => LucideIcons.file_spreadsheet,
      'Presentazione' => LucideIcons.presentation,
      'Documento di testo' => LucideIcons.file_pen,
      'Testo' => LucideIcons.scroll_text,
      'Archivio ZIP' => LucideIcons.file_archive,
      _ => LucideIcons.file_type,
    };

/// Archivio documenti: pannello dei filtri con i conteggi, tabella ordinabile a
/// pagine (10/25/50) o gruppi richiudibili, azioni per riga e sui selezionati.
/// Su schermo stretto, una scheda per documento e i filtri in un foglio a comparsa.
/// Su desktop e web si possono trascinare più file sulla pagina: ogni file
/// diventa un documento, con una sola conferma.
class DocumentiScreen extends StatefulWidget {
  const DocumentiScreen({super.key});

  @override
  State<DocumentiScreen> createState() => _DocumentiScreenState();
}

class _DocumentiScreenState extends State<DocumentiScreen> {
  final DocumentiController controller = Get.put(DocumentiController());
  final TextEditingController searchField = TextEditingController();
  bool dragging = false;
  String? azioneGruppo;
  bool pannelloAperto = true;

  SessionService get session => SessionService.to;

  @override
  void initState() {
    super.initState();
    searchField.text = controller.query;
  }

  @override
  void dispose() {
    searchField.dispose();
    super.dispose();
  }

  void _snack(String m, {bool errore = false}) => mostraMessaggio(context, m, errore: errore);

  Future<void> _carica(List<({String nome, Uint8List bytes})> files) async {
    final id = controller.tenantId;
    if (id == null || files.isEmpty || !controller.canUpload) return;
    final creati = await showCaricamentoDialog(context, tenantId: id, files: files, categorie: controller.categorie, persone: controller.persone);
    if (creati > 0) await controller.load();
  }

  Future<void> _caricaCartella() async {
    final id = controller.tenantId;
    if (id == null || !controller.canUpload) return;
    final CartellaScelta? scelta;
    try {
      scelta = await scegliCartella();
    } catch (e) {
      _snack('Non è stato possibile leggere la cartella: $e', errore: true);
      return;
    }
    if (scelta == null || !mounted) return;
    final creati = await showCaricamentoCartellaDialog(context, tenantId: id, cartella: scelta, categorie: controller.categorie, persone: controller.persone);
    if (creati > 0) await controller.load();
  }

  /// "Carica" apre un menu: singoli file oppure tutti i file di una cartella
  /// (sottocartelle comprese; non sul telefono, dove una cartella non si può leggere).
  Widget _menuCarica() => MenuAnchor(
        menuChildren: [
          MenuItemButton(leadingIcon: const Icon(LucideIcons.file_up, size: 18), onPressed: () async => _carica(await scegliFile()), child: const Text('Carica file')),
          if (cartellaSupportata) MenuItemButton(leadingIcon: const Icon(LucideIcons.folder_up, size: 18), onPressed: _caricaCartella, child: const Text('Carica cartella')),
        ],
        builder: (context, menu, _) => FilledButton.icon(
          onPressed: () => menu.isOpen ? menu.close() : menu.open(),
          icon: const Icon(LucideIcons.upload, size: 18),
          label: const Row(mainAxisSize: MainAxisSize.min, children: [Text('Carica'), SizedBox(width: 4), Icon(LucideIcons.chevron_down, size: 16)]),
        ),
      );

  Future<void> _onDrop(DropDoneDetails details) async {
    setState(() => dragging = false);
    final files = <({String nome, Uint8List bytes})>[];
    for (final item in details.files) {
      if (item is DropItemDirectory) continue;
      files.add((nome: item.name, bytes: await item.readAsBytes()));
    }
    await _carica(files);
  }

  // ---- Azioni sulla riga ----------------------------------------------------

  bool _puoModificare(Documento d) => session.can('documento:write', resourceOwnerId: d.ownerId);
  bool _puoEliminare(Documento d) => session.can('documento:delete', resourceOwnerId: d.ownerId);
  bool get _puoCondividere => session.can('condivisione:manage', resourceOwnerId: session.me?.id);

  Future<void> _apri(Documento d) async {
    try {
      final bytes = await DocumentoApi.download(controller.tenantId!, d.id);
      await saveOrOpenFile(bytes, d.nomeFile ?? d.titolo, d.mimeType ?? 'application/octet-stream');
    } on ApiException catch (e) {
      _snack(e.message, errore: true);
    }
  }

  Future<void> _modifica(Documento d) async {
    final salvato = await showDocumentoForm(context, tenantId: controller.tenantId!, documento: d);
    if (salvato != null) {
      _snack('Documento aggiornato.');
      await controller.load();
    }
  }

  Future<void> _elimina(Documento d) async {
    if (!await _conferma(
        'Eliminare "${d.titolo}"?', 'Il documento e il suo file saranno cancellati definitivamente. Le scadenze collegate restano, con l\'indicazione "documento eliminato".')) {
      return;
    }
    try {
      await DocumentoApi.delete(controller.tenantId!, d.id);
      _snack('Documento eliminato.');
      await controller.load();
    } on ApiException catch (e) {
      _snack(e.message, errore: true);
    }
  }

  Future<bool> _conferma(String titolo, String testo, {String azione = 'Elimina'}) => chiediConferma(context, titolo: titolo, testo: testo, azione: azione, distruttiva: true);

  // ---- Pagina ---------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<DocumentiController>(
        init: controller,
        builder: (c) => DropTarget(
          enable: c.canUpload,
          onDragEntered: (_) => setState(() => dragging = true),
          onDragExited: (_) => setState(() => dragging = false),
          onDragDone: _onDrop,
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Flexible(child: Testo.titleMedium('Documenti', fontSize: 18, fontWeight: FontWeight.w500)),
                  Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Documenti', attiva: true)]),
                ]),
                SizedBox(height: spazioPagina),
                listaCarta(Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    if (c.canUpload) ...[
                      _menuCarica(),
                      OutlinedButton.icon(onPressed: () => Get.toNamed('/documenti/nuovo'), icon: const Icon(LucideIcons.scan_line, size: 18), label: const Text('Scansiona')),
                    ],
                    OutlinedButton.icon(onPressed: () => Get.toNamed('/categorie'), icon: const Icon(LucideIcons.tags, size: 18), label: const Text('Categorie')),
                  ]),
                  Testo.bodyMedium('Documenti: ${c.totale}', fontWeight: FontWeight.w500),
                ])),
                SizedBox(height: 16),
                listaCarta(_filtri(c)),
                SizedBox(height: 16),
                if (dragging)
                  Padding(
                    padding: EdgeInsets.only(bottom: 16),
                    child: Container(
                      height: 120,
                      decoration: BoxDecoration(
                        color: context.colori.primary.withAlpha(20),
                        border: Border.all(color: context.colori.primary, width: 2),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Center(child: Testo.titleMedium('Rilascia qui i file: ogni file diventa un documento', color: context.colori.primary)),
                    ),
                  ),
                _contenuto(c),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _filtri(DocumentiController c) => Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SizedBox(
          width: 280,
          child: TextField(
            controller: searchField,
            onChanged: c.search,
            decoration: listaDecorazione(c.perSignificato ? 'Descrivi cosa cerchi' : 'Cerca nel titolo e nel testo').copyWith(
              prefixIcon: const Icon(LucideIcons.search, size: 18),
              suffixIcon: c.query.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(LucideIcons.x, size: 16),
                      onPressed: () {
                        searchField.clear();
                        c.search('');
                      }),
            ),
          ),
        ),
        Tooltip(
          message: 'Trova i documenti anche senza le parole esatte, per esempio "bolletta della luce", '
              'in ordine di pertinenza. I documenti cifrati si trovano solo per titolo.',
          child: FilterChip(
            avatar: const Icon(LucideIcons.sparkles, size: 16),
            label: const Text('Per significato'),
            selected: c.perSignificato,
            onSelected: c.cambiaPerSignificato,
          ),
        ),
        SegmentedButton<VistaDocumenti>(
          showSelectedIcon: false,
          segments: const [
            ButtonSegment(value: VistaDocumenti.lista, icon: Icon(LucideIcons.list, size: 16), label: Text('Lista')),
            ButtonSegment(value: VistaDocumenti.gruppi, icon: Icon(LucideIcons.layers, size: 16), label: Text('Gruppi')),
          ],
          selected: {c.vista},
          onSelectionChanged: (v) => c.cambiaVista(v.single),
        ),
        if (c.aGruppi)
          listaFiltroScelta('Raggruppa per', c.raggruppaPer.name, [for (final d in DimensioneFiltro.raggruppabili) (d.name, d.titolo)],
              (v) => c.raggruppaSecondo(DimensioneFiltro.values.byName(v!))),
        OutlinedButton(
          onPressed: () => _stretto ? _apriFoglioFiltri(c) : setState(() => pannelloAperto = !pannelloAperto),
          child: Text(c.filtri.isEmpty ? 'Filtri' : 'Filtri (${c.filtri.length})'),
        ),
        OutlinedButton.icon(
          onPressed: c.hasFilters
              ? () {
                  searchField.clear();
                  c.azzeraFiltri();
                }
              : null,
          icon: const Icon(LucideIcons.rotate_ccw, size: 16),
          label: const Text('Resetta filtri'),
        ),
        for (final d in c.filtri.keys.toList()) InputChip(label: Text('${d.titolo}: ${c.etichettaFiltro(d)}', overflow: TextOverflow.ellipsis), onDeleted: () => c.togliFiltro(d)),
      ]);

  bool get _stretto => MediaQuery.of(context).size.width < 900;

  Widget _pannello(DocumentiController c) => PannelloFiltri(voci: c.vociDi, scelto: (d) => c.filtri[d], onScegli: c.scegliFiltro);

  Future<void> _apriFoglioFiltri(DocumentiController c) => showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => GetBuilder<DocumentiController>(
          init: c,
          builder: (c) => SafeArea(child: SingleChildScrollView(padding: EdgeInsets.fromLTRB(12, 0, 12, 16), child: _pannello(c))),
        ),
      );

  /// Su schermo largo i filtri stanno in una colonna accanto ai risultati.
  Widget _contenuto(DocumentiController c) {
    if (_stretto || !pannelloAperto) return _corpo(c);
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(
        width: 250,
        child: listaCarta(
          padding: EdgeInsets.all(10),
          c.erroreFaccette != null
              ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Testo.bodySmall(c.erroreFaccette!), TextButton(onPressed: c.load, child: const Text('Riprova'))])
              : _pannello(c),
        ),
      ),
      SizedBox(width: 16),
      Expanded(child: _corpo(c)),
    ]);
  }

  Widget _corpo(DocumentiController c) {
    if (c.error != null) {
      return Scheda(paddingAll: 24, child: Row(children: [
            Icon(LucideIcons.triangle_alert, color: context.colori.danger),
            SizedBox(width: 12),
            Expanded(child: Testo.bodyMedium(c.error!)),
            TextButton(onPressed: c.load, child: const Text('Riprova')),
          ]));
    }
    if (!c.loading && c.vuoto) {
      if (c.hasFilters) {
        return StatoVuoto(
            icona: LucideIcons.search_x,
            titolo: 'Nessun documento corrisponde alla ricerca',
            testo: 'Prova con altre parole o togli i filtri.',
            azione: 'Togli i filtri',
            onAzione: () {
              searchField.clear();
              c.azzeraFiltri();
            });
      }
      return StatoVuoto(
        icona: LucideIcons.folder_open,
        titolo: 'L\'archivio è ancora vuoto',
        testo: c.canUpload
            ? 'Inizia dai documenti che servono più spesso: carta d\'identità, tessera sanitaria, patente, polizze. Puoi caricarli dal computer (anche trascinandoli qui), scansionarli con il telefono o importarli da Google Drive.'
            : 'Qui compariranno i documenti caricati dalla famiglia.',
        azione: c.canUpload ? 'Scansiona o carica' : null,
        onAzione: c.canUpload ? () => Get.toNamed('/documenti/nuovo') : null,
        guida: 'primi-passi-famiglia',
      );
    }
    final stretto = _stretto;
    final azioni = _azioniGruppo;
    return listaCarta(
      padding: EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 8),
          child: Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
            Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              if (azioni.isNotEmpty) ...[
                SizedBox(
                  width: 270,
                  child: DropdownButtonFormField<String?>(
                    initialValue: azioneGruppo,
                    isExpanded: true,
                    decoration: listaDecorazione('Sui selezionati'),
                    items: [for (final a in azioni) DropdownMenuItem(value: a.$1, child: Text(a.$2, overflow: TextOverflow.ellipsis))],
                    onChanged: (v) => setState(() => azioneGruppo = v),
                  ),
                ),
                FilledButton(
                  onPressed: azioneGruppo == null || c.selezionati.isEmpty ? null : () => _eseguiGruppo(c),
                  child: Text(c.selezionati.isEmpty ? 'Esegui' : 'Esegui (${c.selezionati.length})'),
                ),
              ],
              if (!c.aGruppi)
                DropdownButton<int>(
                  value: c.perPagina,
                  underline: const SizedBox.shrink(),
                  items: [for (final n in DocumentiController.perPaginaPossibili) DropdownMenuItem(value: n, child: Text('$n per pagina'))],
                  onChanged: (v) => c.cambiaPerPagina(v ?? 25),
                ),
              if (stretto || c.aGruppi)
                DropdownButton<String>(
                  // Un ordinamento scelto da colonna su schermo largo (es. titolo Z-A) qui non c'è: niente voce selezionata.
                  value: const ['creato-desc', 'data-desc', 'titolo-asc'].contains('${c.ordina}-${c.verso}') ? '${c.ordina}-${c.verso}' : null,
                  hint: const Text('Ordina'),
                  underline: const SizedBox.shrink(),
                  items: const [
                    DropdownMenuItem(value: 'creato-desc', child: Text('Caricati di recente')),
                    DropdownMenuItem(value: 'data-desc', child: Text('Data documento, recenti')),
                    DropdownMenuItem(value: 'titolo-asc', child: Text('Titolo A-Z')),
                  ],
                  onChanged: (v) {
                    final [campo, verso] = v!.split('-');
                    c.ordinaCome(campo, verso);
                  },
                ),
            ]),
            if (!c.aGruppi) ListaPaginazione(pagina: c.pagina, pagine: c.pagine, onPagina: c.vaiAPagina),
          ]),
        ),
        // I gruppi restano al loro posto mentre si aggiornano i conteggi.
        if (c.aGruppi && c.faccette != null)
          VistaGruppi(
            voci: c.gruppi,
            aperti: c.gruppiAperti,
            selezionati: _azioniGruppo.isEmpty ? null : c.selezionati,
            onCambiaSelezione: c.cambiaSelezione,
            onApriChiudi: c.apriChiudiGruppo,
            onAltri: (v) => c.caricaGruppo(v, altri: true),
            onRiprova: c.caricaGruppo,
            riga: (d) => _scheda(c, d),
          )
        else if (c.loading)
          const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
        else if (stretto)
          _schede(c)
        else
          LayoutBuilder(builder: (_, vincoli) => _tabella(c, compatta: vincoli.maxWidth < 1100)),
        if (!c.aGruppi && !c.loading && c.pagine > 1)
          Padding(
            padding: EdgeInsets.fromLTRB(8, 8, 8, 0),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Testo.bodySmall('${(c.pagina - 1) * c.perPagina + 1}–${((c.pagina - 1) * c.perPagina + c.documenti.length)} di ${c.totale}', tenue: true),
              ListaPaginazione(pagina: c.pagina, pagine: c.pagine, onPagina: c.vaiAPagina),
            ]),
          ),
      ]),
    );
  }
}
