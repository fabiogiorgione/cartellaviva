import 'dart:typed_data';

import 'package:cartellaviva/features/documenti/scan/page_processing.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';

ScanDraftStore createStore(String nome) => MemoryScanDraftStore();

/// Web e test: bozza solo in memoria (la scansione è disponibile solo su Android/iOS).
class MemoryScanDraftStore implements ScanDraftStore {
  ScanDraft? _draft;
  final Map<String, Uint8List> _files = {};

  @override
  Future<ScanDraft?> load() async => _draft == null ? null : ScanDraft.fromJson(_draft!.toJson());

  @override
  Future<void> save(ScanDraft draft) async => _draft = ScanDraft.fromJson(draft.toJson());

  @override
  Future<ScanPage> writePage(ProcessedPage page, {required bool biancoNero}) async {
    final id = newScanId();
    final fileName = 'page_$id.${page.extension}';
    _files[fileName] = page.bytes;
    return ScanPage(id: id, fileName: fileName, mimeType: page.mimeType, width: page.width, height: page.height, biancoNero: biancoNero);
  }

  @override
  Future<Uint8List> readPage(ScanPage page) async => _files[page.fileName] ?? (throw StateError('Pagina non trovata'));

  @override
  Future<void> deletePage(ScanPage page) async => _files.remove(page.fileName);

  @override
  Future<void> discard() async {
    _draft = null;
    _files.clear();
  }
}
