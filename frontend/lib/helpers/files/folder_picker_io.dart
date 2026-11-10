import 'dart:io';

import 'package:file_picker/file_picker.dart';

import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/files/folder_picker.dart';
import 'package:cartellaviva/helpers/files/upload_batch.dart';

/// Computer (macOS, Windows, Linux): selettore di cartelle di sistema, poi
/// l'elenco dei file con le sottocartelle. Ogni file si legge solo quando tocca
/// a lui nella coda di caricamento. Sul telefono non è disponibile.
bool get cartellaSupportata => Platform.isMacOS || Platform.isWindows || Platform.isLinux;

Future<CartellaScelta?> scegliCartella() async {
  final path = await FilePicker.platform.getDirectoryPath(dialogTitle: 'Scegli la cartella da caricare');
  if (path == null) return null;
  final radice = Directory(path);
  final files = <File>[];
  final dimensioni = <int>[];
  final scartati = <FileScartato>[];
  await for (final e in radice.list(recursive: true, followLinks: false)) {
    if (e is! File) continue;
    try {
      dimensioni.add(await e.length());
      files.add(e);
    } on FileSystemException {
      scartati.add(FileScartato(_relativo(radice, e), 'non leggibile'));
    }
  }
  final percorsi = [for (final f in files) _relativo(radice, f)];
  final sel = selezionaDaCartella([for (var i = 0; i < files.length; i++) (percorso: percorsi[i], dimensione: dimensioni[i])]);
  return (
    nome: nomeDaPercorso(path),
    files: [
      for (final i in sel.daLeggere)
        FileDaCaricare.daLeggere(
          nome: nomeDaPercorso(files[i].path),
          mimeType: mimeTypeFor(files[i].path)!,
          dimensione: dimensioni[i],
          percorso: percorsi[i],
          leggi: files[i].readAsBytes,
        ),
    ],
    scartati: [...scartati, ...sel.scartati],
  );
}

String _relativo(Directory radice, File f) {
  final base = radice.path.endsWith(Platform.pathSeparator) ? radice.path : radice.path + Platform.pathSeparator;
  return f.path.startsWith(base) ? f.path.substring(base.length).replaceAll('\\', '/') : nomeDaPercorso(f.path);
}
