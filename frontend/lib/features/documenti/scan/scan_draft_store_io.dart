import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:cartellaviva/features/documenti/scan/page_processing.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';
import 'package:path_provider/path_provider.dart';

ScanDraftStore createStore(String nome) => FileScanDraftStore(nome: nome);

/// Bozza su file nella cartella di supporto dell'app (privata, non visibile
/// alle altre app né alla galleria): `<nome>/draft.json` + un file per pagina.
class FileScanDraftStore implements ScanDraftStore {
  /// Per i test: cartella esplicita al posto di quella dell'app.
  final Directory? baseDir;
  final String nome;

  FileScanDraftStore({this.baseDir, this.nome = 'scan_draft'});

  Future<Directory> _dir() async {
    final base = baseDir ?? await getApplicationSupportDirectory();
    final dir = Directory('${base.path}/$nome');
    if (!await dir.exists()) {
      await dir.create(recursive: true);
    }
    return dir;
  }

  Future<File> _json() async => File('${(await _dir()).path}/draft.json');

  @override
  Future<ScanDraft?> load() async {
    final file = await _json();
    if (!await file.exists()) {
      return null;
    }
    try {
      final draft = ScanDraft.fromJson(jsonDecode(await file.readAsString()) as Map<String, dynamic>);
      // Pagine il cui file non esiste più (es. cancellato dal sistema): si scartano.
      final dir = await _dir();
      draft.pages.removeWhere((p) => !File('${dir.path}/${p.fileName}').existsSync());
      return draft;
    } on FormatException {
      await discard();
      return null;
    }
  }

  @override
  Future<void> save(ScanDraft draft) async {
    final file = await _json();
    // Scrittura atomica: un crash a metà non corrompe la bozza.
    final tmp = File('${file.path}.tmp');
    await tmp.writeAsString(jsonEncode(draft.toJson()), flush: true);
    await tmp.rename(file.path);
  }

  @override
  Future<ScanPage> writePage(ProcessedPage page, {required bool biancoNero}) async {
    final id = newScanId();
    final fileName = 'page_$id.${page.extension}';
    await File('${(await _dir()).path}/$fileName').writeAsBytes(page.bytes, flush: true);
    return ScanPage(id: id, fileName: fileName, mimeType: page.mimeType, width: page.width, height: page.height, biancoNero: biancoNero);
  }

  @override
  Future<Uint8List> readPage(ScanPage page) async => File('${(await _dir()).path}/${page.fileName}').readAsBytes();

  @override
  Future<void> deletePage(ScanPage page) async {
    final file = File('${(await _dir()).path}/${page.fileName}');
    if (await file.exists()) {
      await file.delete();
    }
  }

  @override
  Future<void> discard() async {
    final dir = await _dir();
    if (await dir.exists()) {
      await dir.delete(recursive: true);
    }
  }
}
