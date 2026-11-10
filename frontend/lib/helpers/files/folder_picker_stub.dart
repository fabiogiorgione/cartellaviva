import 'dart:async';
import 'dart:typed_data';

import 'package:universal_html/html.dart' as html;

import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/files/folder_picker.dart';
import 'package:cartellaviva/helpers/files/upload_batch.dart';

/// Web: <input type="file" webkitdirectory> (Chrome, Edge, Firefox, Safari).
/// Il browser chiede conferma e restituisce tutti i file, sottocartelle comprese.
/// Nome e dimensione bastano per il dialogo: ogni file si legge solo quando
/// tocca a lui nella coda di caricamento.
bool get cartellaSupportata => true;

Future<CartellaScelta?> scegliCartella() async {
  final input = html.FileUploadInputElement()
    ..multiple = true
    ..setAttribute('webkitdirectory', '')
    ..style.display = 'none';
  html.document.body?.append(input);
  final scelta = Completer<List<html.File>?>();
  final subs = [
    input.onChange.listen((_) {
      if (!scelta.isCompleted) scelta.complete(input.files ?? const []);
    }),
    // Annullato (browser recenti): senza questo evento la scelta resterebbe in attesa.
    input.on['cancel'].listen((_) {
      if (!scelta.isCompleted) scelta.complete(null);
    }),
  ];
  input.click();
  final files = await scelta.future;
  for (final s in subs) {
    await s.cancel();
  }
  input.remove();
  if (files == null || files.isEmpty) return null;

  String percorso(html.File f) {
    // webkitRelativePath: "Cartella/sotto/file.pdf" (vuoto se il browser non lo dà).
    final String? relativo = f.relativePath;
    return relativo != null && relativo.isNotEmpty ? relativo : f.name;
  }

  final percorsi = [for (final f in files) percorso(f)];
  final sel = selezionaDaCartella([for (var i = 0; i < files.length; i++) (percorso: percorsi[i], dimensione: files[i].size)]);
  final primo = percorsi.first;
  final radice = primo.contains('/') ? primo.split('/').first : 'Cartella';
  return (
    nome: radice,
    files: [
      for (final i in sel.daLeggere)
        FileDaCaricare.daLeggere(
          nome: files[i].name,
          mimeType: mimeTypeFor(files[i].name)!,
          dimensione: files[i].size,
          // Senza il nome della cartella principale, già nel titolo del dialogo.
          percorso: percorsi[i].startsWith('$radice/') ? percorsi[i].substring(radice.length + 1) : percorsi[i],
          leggi: () => _leggi(files[i]),
        ),
    ],
    scartati: sel.scartati,
  );
}

Future<Uint8List> _leggi(html.File file) {
  final reader = html.FileReader();
  final fatto = Completer<Uint8List>();
  reader.onLoad.first.then((_) {
    final r = reader.result;
    if (r is Uint8List) {
      fatto.complete(r);
    } else if (r is ByteBuffer) {
      fatto.complete(r.asUint8List());
    } else {
      fatto.completeError(StateError('contenuto non leggibile'));
    }
  });
  reader.onError.first.then((_) => fatto.completeError(StateError('file non leggibile (spostato o cancellato?)')));
  reader.readAsArrayBuffer(file);
  return fatto.future;
}
