import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/scan/document_capture.dart';
import 'package:cartellaviva/features/documenti/scan/page_processing.dart';
import 'package:cartellaviva/features/documenti/scan/pdf_builder.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/richieste/api/richieste_api.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatIsoDate;
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';

part 'nuovo_documento_richiesta.dart';

enum ScanStep { pagine, revisione, conferma }

/// Caricamento del PDF (sostituibile nei test).
typedef DocumentUploader = Future<Documento> Function(
  String tenantId,
  Uint8List pdf, {
  required String fileName,
  required String categoriaId,
  List<String> membriIds,
  String? titolo,
  void Function(int sent, int total)? onProgress,
  CancelToken? cancelToken,
});

/// "Nuovo documento" da fotocamera o galleria.
///
/// 1. cattura di UNA pagina (bordi automatici + angoli regolabili: scanner nativo);
/// 2. revisione: filtro bianco e nero (soglia adattiva) attivabile con anteprima;
/// 3. pagine confermate in bozza persistente: miniature, riordino, eliminazione;
/// 4. finalizzazione: categoria (obbligatoria) e membri (facoltativi);
/// 5. PDF unico (una pagina per foto, nell'ordine mostrato) e upload con progresso.
///
/// Se l'upload fallisce la bozza resta salvata (anche chiudendo l'app) finché
/// l'utente non riprova o la annulla esplicitamente.
///
/// Con [richiestaId] la scansione è la risposta a una richiesta di un ente:
/// bozza separata (una per richiesta), niente categoria, e il PDF va all'ente
/// indicando chi ha firmato.
class NuovoDocumentoController extends GetxController {
  NuovoDocumentoController({
    ScanDraftStore? store,
    DocumentCapture? capture,
    DocumentUploader? uploader,
    Future<List<Categoria>> Function(String tenantId)? loadCategorie,
    Future<List<MembroFamiglia>> Function(String tenantId)? loadMembri,
    this.runInBackground = true,
    this.richiestaId,
  })  : store = store ?? ScanDraftStore(nome: richiestaId == null ? 'scan_draft' : 'scan_richiesta_$richiestaId'),
        capturer = capture ?? DocumentCapture(),
        _upload = uploader ?? DocumentoApi.upload,
        _loadCategorie = loadCategorie ?? DocumentoApi.categorie,
        _loadMembri = loadMembri ?? DocumentoApi.membriFamiglia;

  /// Massimo accettato dal backend (app.document_max_bytes).
  static const int maxUploadBytes = 20 * 1024 * 1024;

  final ScanDraftStore store;
  final DocumentCapture capturer;
  final DocumentUploader _upload;
  final Future<List<Categoria>> Function(String) _loadCategorie;
  final Future<List<MembroFamiglia>> Function(String) _loadMembri;

  /// false nei test: niente isolate per elaborazione e PDF.
  final bool runInBackground;

  SessionService get session => SessionService.to;

  bool loading = true;
  String? error;
  ScanStep step = ScanStep.pagine;
  ScanDraft? draft;

  /// Miniature delle pagine confermate (id pagina -> bytes).
  final Map<String, Uint8List> thumbnails = {};

  // ---- Revisione della pagina appena catturata -----------------------------
  Uint8List? _captured;
  bool biancoNero = false;
  final Map<bool, ProcessedPage> _variants = {};
  bool processing = false;

  ProcessedPage? get preview => _variants[biancoNero];

  // ---- Conferma e upload ---------------------------------------------------
  List<Categoria> categorie = [];
  List<MembroFamiglia> membri = [];
  bool loadingOptions = false;
  bool uploading = false;
  double progress = 0;
  String? uploadError;
  CancelToken? _cancelToken;

  /// Richiesta di un ente a cui si risponde con questa scansione (null = nuovo documento).
  final String? richiestaId;
  RichiestaFamiglia? richiesta;
  final Set<String> firmatari = {};

  /// Se l'ente la chiede: fino a quando vale il documento consegnato.
  DateTime? validoFino;

  bool get serveValidita => richiesta?.chiediValidita == true && richiesta?.tipo != 'presa_visione';

  void setValidoFino(DateTime? d) {
    validoFino = d;
    update();
  }

  bool get canCreate => richiestaId != null ? session.can('richiesta:respond') : session.can('documento:create');

  bool get isSupported => capturer.isSupported;

  List<ScanPage> get pages => draft?.pages ?? const [];

  @override
  void onInit() {
    super.onInit();
    load();
  }

  /// Il PDF delle pagine scansionate (in background se possibile), entro il limite di caricamento.
  Future<Uint8List> _creaPdf(ScanDraft bozza) async {
    final images = <PdfPageImage>[];
    for (final page in bozza.pages) {
      images.add(PdfPageImage(thumbnails[page.id] ?? await store.readPage(page), page.width, page.height));
    }
    final pdf = runInBackground ? await compute(buildPdf, images) : await buildPdf(images);
    if (pdf.length > maxUploadBytes) {
      throw ApiException('Il PDF supera i 20 MB: elimina qualche pagina o usa il filtro bianco e nero.');
    }
    return pdf;
  }

  Future<void> load() async {
    loading = true;
    update();
    final me = session.me;
    final tenantId = session.currentTenantId;
    if (me == null || tenantId == null) {
      error = 'Seleziona una famiglia prima di caricare documenti.';
      loading = false;
      update();
      return;
    }
    var saved = await store.load();
    if (saved != null && saved.userId != me.id) {
      // Bozza di un altro utente su questo dispositivo: non si mostra mai.
      await store.discard();
      saved = null;
    }
    if (saved != null && saved.tenantId != tenantId) {
      // Famiglia cambiata nel frattempo: le pagine restano, categoria e membri no.
      saved
        ..tenantId = tenantId
        ..categoriaId = null
        ..membriIds = [];
      await store.save(saved);
    }
    draft = saved ?? ScanDraft.create(userId: me.id, tenantId: tenantId);
    resumed = saved != null && saved.pages.isNotEmpty;
    for (final page in draft!.pages) {
      thumbnails[page.id] = await store.readPage(page);
    }
    loading = false;
    update();
  }

  /// Bozza ripresa da una sessione precedente (da segnalare all'utente).
  bool resumed = false;

  // ---- Cattura e revisione ---------------------------------------------------

  Future<void> capture(CaptureSource source) async {
    error = null;
    try {
      final bytes = await capturer.capture(source);
      if (bytes == null) {
        return; // annullato dall'utente
      }
      _captured = bytes;
      _variants.clear();
      biancoNero = false;
      step = ScanStep.revisione;
      update();
      await _ensureVariant(false);
    } on CaptureException catch (e) {
      error = e.message;
      update();
    }
  }

  /// Attiva/disattiva il filtro bianco e nero con anteprima (elaborato una volta sola).
  Future<void> setBiancoNero(bool value) async {
    biancoNero = value;
    update();
    await _ensureVariant(value);
  }

  Future<void> _ensureVariant(bool bw) async {
    final source = _captured;
    if (source == null || _variants.containsKey(bw)) {
      return;
    }
    processing = true;
    update();
    try {
      final request = PageProcessingRequest(source, binarize: bw);
      _variants[bw] = runInBackground ? await compute(processPage, request) : processPage(request);
    } catch (_) {
      error = 'Immagine non leggibile: riprova la scansione.';
      step = ScanStep.pagine;
      _captured = null;
    }
    processing = false;
    update();
  }

  /// Conferma la pagina in revisione: viene salvata subito nella bozza.
  Future<void> confirmPage() async {
    final processed = preview;
    if (processed == null || processing) {
      return;
    }
    final page = await store.writePage(processed, biancoNero: biancoNero);
    draft!.pages.add(page);
    thumbnails[page.id] = processed.bytes;
    await store.save(draft!);
    _discardCaptured();
    step = ScanStep.pagine;
    update();
  }

  void discardCaptured() {
    _discardCaptured();
    step = ScanStep.pagine;
    update();
  }

  void _discardCaptured() {
    _captured = null;
    _variants.clear();
    biancoNero = false;
  }

  // ---- Pagine ---------------------------------------------------------------

  Future<void> reorderPages(int oldIndex, int newIndex) async {
    draft!.movePage(oldIndex, newIndex);
    update();
    await store.save(draft!);
  }

  Future<void> removePage(ScanPage page) async {
    draft!.pages.removeWhere((p) => p.id == page.id);
    thumbnails.remove(page.id);
    update();
    await store.save(draft!);
    await store.deletePage(page);
  }

  // ---- Conferma e upload ----------------------------------------------------

  Future<void> finalizeScan() async {
    if (pages.isEmpty) {
      return;
    }
    step = ScanStep.conferma;
    uploadError = null;
    update();
    if (categorie.isEmpty) {
      await loadOptions();
    }
  }

  void backToPages() {
    if (uploading) {
      return;
    }
    step = ScanStep.pagine;
    update();
  }

  Future<void> loadOptions() async {
    loadingOptions = true;
    uploadError = null;
    update();
    try {
      final tenantId = draft!.tenantId;
      if (richiestaId != null) {
        richiesta = await RichiesteApi.get(tenantId, richiestaId!);
        // Proposti come firmatari tutti i referenti di questa famiglia.
        if (firmatari.isEmpty) firmatari.addAll(richiesta!.referenti.map((r) => r.id));
        loadingOptions = false;
        update();
        return;
      }
      final results = await Future.wait([_loadCategorie(tenantId), _loadMembri(tenantId)]);
      categorie = results[0] as List<Categoria>;
      membri = results[1] as List<MembroFamiglia>;
      // Scelte salvate nella bozza ma non più valide (categoria/membro eliminati).
      if (!categorie.any((c) => c.id == draft!.categoriaId)) {
        draft!.categoriaId = null;
      }
      draft!.membriIds = draft!.membriIds.where((id) => membri.any((m) => m.id == id)).toList();
    } on ApiException catch (e) {
      uploadError = e.message;
    }
    loadingOptions = false;
    update();
  }

  Categoria? get categoriaSelezionata => categorie.firstWhereOrNull((c) => c.id == draft?.categoriaId);

  Future<void> selectCategoria(String? id) async {
    draft!.categoriaId = id;
    update();
    await store.save(draft!);
  }

  Future<void> toggleMembro(String id, bool selected) async {
    final ids = draft!.membriIds.toSet();
    selected ? ids.add(id) : ids.remove(id);
    draft!.membriIds = ids.toList();
    update();
    await store.save(draft!);
  }

  Future<void> setTitolo(String value) async {
    draft!.titolo = value;
    await store.save(draft!);
  }

  /// Crea il PDF e lo carica. Restituisce il documento creato, o null in caso di
  /// errore (messaggio in [uploadError], bozza intatta per riprovare).
  Future<Documento?> upload() async {
    final current = draft;
    if (current == null || uploading || current.pages.isEmpty) {
      return null;
    }
    if (current.categoriaId == null) {
      uploadError = 'Scegli una categoria.';
      update();
      return null;
    }
    uploading = true;
    progress = 0;
    uploadError = null;
    update();
    try {
      final pdf = await _creaPdf(current);
      _cancelToken = CancelToken();
      final documento = await _upload(
        current.tenantId,
        pdf,
        fileName: current.pdfFileName,
        categoriaId: current.categoriaId!,
        membriIds: current.membriIds,
        titolo: current.titolo,
        onProgress: (sent, total) {
          if (total > 0) {
            progress = sent / total;
            update();
          }
        },
        cancelToken: _cancelToken,
      );
      // Solo ora la bozza si può eliminare.
      await store.discard();
      draft = null;
      thumbnails.clear();
      uploading = false;
      update();
      return documento;
    } on ApiException catch (e) {
      uploadError = e.message;
    } catch (e) {
      uploadError = 'Preparazione del PDF non riuscita: riprova.';
    }
    uploading = false;
    _cancelToken = null;
    update();
    return null;
  }

  /// Annullamento ESPLICITO: elimina pagine e bozza.
  Future<void> discardDraft() async {
    _cancelToken?.cancel();
    await store.discard();
    final me = session.me;
    final tenantId = session.currentTenantId;
    draft = me != null && tenantId != null ? ScanDraft.create(userId: me.id, tenantId: tenantId) : null;
    thumbnails.clear();
    _discardCaptured();
    step = ScanStep.pagine;
    resumed = false;
    uploadError = null;
    uploading = false;
    update();
  }
}
