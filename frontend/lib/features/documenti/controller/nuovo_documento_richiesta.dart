part of 'nuovo_documento_controller.dart';

/// Scansione come risposta alla richiesta di un ente: chi ha firmato e invio del PDF.
extension RispostaRichiesta on NuovoDocumentoController {
  void toggleFirmatario(String id, bool selected) {
    selected ? firmatari.add(id) : firmatari.remove(id);
    update();
  }

  /// Risposta alla richiesta: PDF delle pagine all'ente (e copia nella famiglia).
  /// true se riuscita (bozza eliminata); altrimenti messaggio in [uploadError].
  Future<bool> inviaRichiesta() async {
    final current = draft;
    if (current == null || uploading || current.pages.isEmpty || richiestaId == null) {
      return false;
    }
    if (firmatari.isEmpty) {
      uploadError = 'Indica chi ha firmato.';
      update();
      return false;
    }
    if (serveValidita && validoFino == null) {
      uploadError = 'Indica fino a quando è valido il documento.';
      update();
      return false;
    }
    uploading = true;
    progress = 0;
    uploadError = null;
    update();
    try {
      final pdf = await _creaPdf(current);
      await RichiesteApi.rispondiConFile(current.tenantId, richiestaId!, pdf,
          fileName: current.pdfFileName,
          mimeType: 'application/pdf',
          firmatari: firmatari.toList(),
          validoFino: validoFino == null ? null : formatIsoDate(validoFino!),
          onProgress: (sent, total) {
            if (total > 0) {
              progress = sent / total;
              update();
            }
          });
      await store.discard();
      draft = null;
      thumbnails.clear();
      uploading = false;
      update();
      return true;
    } on ApiException catch (e) {
      uploadError = e.message;
    } catch (_) {
      uploadError = 'Preparazione del PDF non riuscita: riprova.';
    }
    uploading = false;
    update();
    return false;
  }

  void cancelUpload() => _cancelToken?.cancel();
}
