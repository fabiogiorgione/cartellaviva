import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/controller/nuovo_documento_controller.dart';
import 'package:cartellaviva/features/documenti/scan/document_capture.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft.dart';
import 'package:cartellaviva/features/documenti/scan/scan_draft_store_io.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/services/storage/local_storage.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/account/model/me.dart';
import 'package:image/image.dart' as img;
import 'package:shared_preferences/shared_preferences.dart';

class FakeCapture implements DocumentCapture {
  int width = 800;

  @override
  bool get isSupported => true;

  @override
  Future<Uint8List?> capture(CaptureSource source) async {
    final image = img.Image(width: width, height: 1000);
    img.fill(image, color: img.ColorRgb8(220, 220, 210));
    width += 100; // pagine distinguibili dalla larghezza
    return img.encodeJpg(image);
  }
}

class FakeUploader {
  bool fail = true;
  int calls = 0;
  Uint8List? lastPdf;
  String? categoriaId;
  List<String>? membriIds;

  Future<Documento> call(String tenantId, Uint8List pdf,
      {required String fileName, required String categoriaId, List<String> membriIds = const [], String? titolo, void Function(int, int)? onProgress, CancelToken? cancelToken}) async {
    calls++;
    lastPdf = pdf;
    this.categoriaId = categoriaId;
    this.membriIds = membriIds;
    onProgress?.call(pdf.length ~/ 2, pdf.length);
    if (fail) {
      throw ApiException('Server non raggiungibile. Controlla la connessione e riprova.');
    }
    onProgress?.call(pdf.length, pdf.length);
    return Documento(id: 'doc-1', titolo: 'Scansione');
  }
}

Me me(String id) => Me(
      id: id,
      email: '$id@example.com',
      firstName: 'Mario',
      lastName: 'Rossi',
      isSuperAdmin: false,
      memberships: [
        TenantMembership(
          membershipId: 'm1',
          tenantId: 't1',
          tenantName: 'Famiglia Rossi',
          tenantType: 'privato',
          roleId: 'r1',
          roleSlug: 'member',
          roleName: 'Membro',
          permissions: {'documento:read', 'documento:create'},
        ),
      ],
    );

void main() {
  late Directory dir;
  late FakeCapture capture;
  late FakeUploader uploader;

  NuovoDocumentoController controller() => NuovoDocumentoController(
        store: FileScanDraftStore(baseDir: dir),
        capture: capture,
        uploader: uploader.call,
        loadCategorie: (_) async => [Categoria(id: 'cat-casa', nome: 'Casa'), Categoria(id: 'cat-salute', nome: 'Salute', isSensibile: true)],
        loadMembri: (_) async => [MembroFamiglia(id: 'leo', nome: 'Leo Rossi')],
        runInBackground: false,
      );

  Future<void> login(String userId) async {
    SessionService.to.me = me(userId);
    await SessionService.to.selectTenant('t1');
  }

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await LocalStorage.init();
    Get.put(SessionService(), permanent: true);
    await login('u1');
    dir = await Directory.systemTemp.createTemp('fv-scan-');
    capture = FakeCapture();
    uploader = FakeUploader();
  });

  tearDown(() async {
    Get.reset();
    await dir.delete(recursive: true);
  });

  Future<NuovoDocumentoController> withPages(int count, {bool biancoNero = false}) async {
    final c = controller();
    await c.load();
    for (var i = 0; i < count; i++) {
      await c.capture(CaptureSource.camera);
      expect(c.step, ScanStep.revisione);
      if (biancoNero) {
        await c.setBiancoNero(true);
      }
      await c.confirmPage();
    }
    return c;
  }

  test('pagine confermate, riordinate ed eliminate restano nella bozza', () async {
    final c = await withPages(3);
    expect(c.pages.map((p) => p.width), [800, 900, 1000]);

    await c.reorderPages(2, 0);
    await c.removePage(c.pages[1]);
    expect(c.pages.map((p) => p.width), [1000, 900]);

    final riaperto = controller();
    await riaperto.load();
    expect(riaperto.resumed, isTrue);
    expect(riaperto.pages.map((p) => p.width), [1000, 900]);
    expect(riaperto.thumbnails.length, 2);
  });

  test('filtro bianco e nero: anteprima PNG, pagina salvata come B/N', () async {
    final c = controller();
    await c.load();
    await c.capture(CaptureSource.camera);
    expect(c.preview!.mimeType, 'image/jpeg');
    await c.setBiancoNero(true);
    expect(c.preview!.mimeType, 'image/png');
    await c.setBiancoNero(false);
    expect(c.preview!.mimeType, 'image/jpeg', reason: 'si può tornare indietro prima di confermare');
    await c.setBiancoNero(true);
    await c.confirmPage();
    expect(c.pages.single.biancoNero, isTrue);
  });

  test('upload fallito: pagine e scelte restano salvate, il nuovo tentativo riesce e pulisce la bozza', () async {
    final c = await withPages(2);
    await c.finalizeScan();
    expect(c.step, ScanStep.conferma);

    expect(await c.upload(), isNull, reason: 'senza categoria non si carica');
    expect(uploader.calls, 0);

    await c.selectCategoria('cat-salute');
    await c.toggleMembro('leo', true);
    expect(await c.upload(), isNull);
    expect(c.uploadError, contains('Server non raggiungibile'));
    expect(c.pages, hasLength(2));
    expect(String.fromCharCodes(uploader.lastPdf!.take(5)), '%PDF-');

    // L'app viene chiusa: alla riapertura la bozza c'è ancora, con le scelte fatte.
    final riaperto = controller();
    await riaperto.load();
    expect(riaperto.pages, hasLength(2));
    expect(riaperto.draft!.categoriaId, 'cat-salute');
    expect(riaperto.draft!.membriIds, ['leo']);

    uploader.fail = false;
    await riaperto.finalizeScan();
    final documento = await riaperto.upload();
    expect(documento?.id, 'doc-1');
    expect(uploader.categoriaId, 'cat-salute');
    expect(uploader.membriIds, ['leo']);
    expect(riaperto.progress, 1.0);
    expect(await FileScanDraftStore(baseDir: dir).load(), isNull, reason: 'dopo il successo la bozza si elimina');
  });

  test('annullamento esplicito: bozza e file eliminati', () async {
    final c = await withPages(2);
    await c.discardDraft();
    expect(c.pages, isEmpty);
    expect(Directory('${dir.path}/scan_draft').existsSync(), isFalse);
    expect(await FileScanDraftStore(baseDir: dir).load(), isNull);
  });

  test('la bozza di un altro utente sullo stesso dispositivo non viene mostrata', () async {
    await withPages(1);
    await login('u2');

    final c = controller();
    await c.load();
    expect(c.pages, isEmpty);
    expect(c.resumed, isFalse);
  });

  test('ScanDraft.movePage segue la convenzione di ReorderableListView', () {
    final draft = ScanDraft(id: 'd', userId: 'u', tenantId: 't', pages: [
      for (final id in ['a', 'b', 'c']) ScanPage(id: id, fileName: id, mimeType: 'image/jpeg', width: 1, height: 1),
    ]);
    draft.movePage(0, 3); // trascinata in fondo
    expect(draft.pages.map((p) => p.id), ['b', 'c', 'a']);
    draft.movePage(2, 0);
    expect(draft.pages.map((p) => p.id), ['a', 'b', 'c']);
  });
}
