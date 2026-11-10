import 'dart:math';
import 'dart:typed_data';

import 'package:cartellaviva/features/documenti/scan/page_processing.dart';

import 'scan_draft_store_stub.dart' if (dart.library.io) 'scan_draft_store_io.dart' as impl;

/// Pagina confermata della bozza (il file vive nello [ScanDraftStore]).
class ScanPage {
  final String id;
  final String fileName;
  final String mimeType;
  final int width;
  final int height;
  final bool biancoNero;

  const ScanPage({required this.id, required this.fileName, required this.mimeType, required this.width, required this.height, this.biancoNero = false});

  factory ScanPage.fromJson(Map<String, dynamic> json) => ScanPage(
        id: json['id'] as String,
        fileName: json['fileName'] as String,
        mimeType: json['mimeType'] as String,
        width: json['width'] as int,
        height: json['height'] as int,
        biancoNero: json['biancoNero'] == true,
      );

  Map<String, dynamic> toJson() => {'id': id, 'fileName': fileName, 'mimeType': mimeType, 'width': width, 'height': height, 'biancoNero': biancoNero};
}

/// Scansione in corso: pagine (nell'ordine del PDF) e scelte della schermata di
/// conferma. Resta salvata finché l'upload non riesce o l'utente la annulla
/// esplicitamente, anche se l'app viene chiusa o l'upload fallisce.
class ScanDraft {
  final String id;

  /// Utente e tenant a cui appartiene: una bozza di un altro utente sullo stesso
  /// dispositivo non viene mai mostrata (viene scartata al caricamento).
  final String userId;
  String tenantId;
  final List<ScanPage> pages;
  String? categoriaId;
  List<String> membriIds;
  String titolo;
  final DateTime createdAt;

  ScanDraft({
    required this.id,
    required this.userId,
    required this.tenantId,
    List<ScanPage>? pages,
    this.categoriaId,
    List<String>? membriIds,
    this.titolo = '',
    DateTime? createdAt,
  })  : pages = pages ?? [],
        membriIds = membriIds ?? [],
        createdAt = createdAt ?? DateTime.now();

  factory ScanDraft.create({required String userId, required String tenantId}) =>
      ScanDraft(id: newScanId(), userId: userId, tenantId: tenantId);

  factory ScanDraft.fromJson(Map<String, dynamic> json) => ScanDraft(
        id: json['id'] as String,
        userId: json['userId'] as String,
        tenantId: json['tenantId'] as String,
        pages: (json['pages'] as List).map((p) => ScanPage.fromJson(Map<String, dynamic>.from(p as Map))).toList(),
        categoriaId: json['categoriaId'] as String?,
        membriIds: (json['membriIds'] as List? ?? []).cast<String>(),
        titolo: (json['titolo'] as String?) ?? '',
        createdAt: DateTime.tryParse((json['createdAt'] as String?) ?? ''),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'userId': userId,
        'tenantId': tenantId,
        'pages': pages.map((p) => p.toJson()).toList(),
        'categoriaId': categoriaId,
        'membriIds': membriIds,
        'titolo': titolo,
        'createdAt': createdAt.toIso8601String(),
      };

  /// Sposta una pagina (indici come in ReorderableListView.onReorder).
  void movePage(int oldIndex, int newIndex) {
    if (newIndex > oldIndex) {
      newIndex -= 1;
    }
    pages.insert(newIndex, pages.removeAt(oldIndex));
  }

  /// Nome del PDF caricato, es. "Scansione 2026-10-01 14.32.pdf".
  String get pdfFileName {
    String two(int n) => n.toString().padLeft(2, '0');
    final d = createdAt;
    return 'Scansione ${d.year}-${two(d.month)}-${two(d.day)} ${two(d.hour)}.${two(d.minute)}.pdf';
  }
}

String newScanId() {
  final random = Random.secure();
  return List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
}

/// Persistenza della bozza. Su Android/iOS i file stanno nella cartella privata
/// dell'app; sul web solo in memoria. [nome] separa le bozze: una per i nuovi
/// documenti e una per ciascuna richiesta di un ente a cui si sta rispondendo.
abstract class ScanDraftStore {
  factory ScanDraftStore({String nome = 'scan_draft'}) => impl.createStore(nome);

  Future<ScanDraft?> load();

  Future<void> save(ScanDraft draft);

  /// Salva il file della pagina e restituisce la pagina da aggiungere alla bozza.
  Future<ScanPage> writePage(ProcessedPage page, {required bool biancoNero});

  Future<Uint8List> readPage(ScanPage page);

  Future<void> deletePage(ScanPage page);

  /// Elimina bozza e file (upload riuscito o annullamento esplicito).
  Future<void> discard();
}
