import 'dart:typed_data';

import 'package:cartellaviva/helpers/files/file_output.dart';

/// Dimensione massima accettata dal backend (app.document_max_bytes).
const int maxUploadBytes = 20 * 1024 * 1024;

enum StatoCaricamento { inAttesa, inCorso, caricato, errore }

/// Un file scelto o trascinato: diventerà UN documento.
///
/// Il contenuto si legge con [leggi] solo quando tocca a lui nella coda: per
/// una cartella il dialogo si apre subito (bastano nome e dimensione) e in
/// memoria c'è un file alla volta.
class FileDaCaricare {
  final String nome;
  final String mimeType;
  final int dimensione;

  /// Percorso nella cartella scelta ("Bollette/2025/enel.pdf"), solo per mostrarlo.
  final String? percorso;
  final Future<Uint8List> Function() _leggi;
  StatoCaricamento stato = StatoCaricamento.inAttesa;
  double progresso = 0;
  String? errore;
  String? documentoId;
  bool duplicato = false;

  /// File già in memoria (scelti uno per uno o trascinati).
  FileDaCaricare({required this.nome, required Uint8List bytes, required this.mimeType})
      : dimensione = bytes.length,
        percorso = null,
        _leggi = (() async => bytes);

  /// File letto solo al momento del caricamento (cartelle).
  FileDaCaricare.daLeggere({required this.nome, required this.mimeType, required this.dimensione, required Future<Uint8List> Function() leggi, this.percorso})
      : _leggi = leggi;

  Future<Uint8List> leggi() => _leggi();
}

class FileScartato {
  final String nome;
  final String motivo;

  const FileScartato(this.nome, this.motivo);
}

/// Separa i file caricabili da quelli da scartare (tipo non supportato, troppo
/// grandi, vuoti, doppioni nella stessa selezione). Logica pura, testata.
({List<FileDaCaricare> accettati, List<FileScartato> scartati}) preparaCaricamento(Iterable<({String nome, Uint8List bytes})> files) {
  final accettati = <FileDaCaricare>[];
  final scartati = <FileScartato>[];
  final visti = <String>{};
  for (final f in files) {
    final mime = mimeTypeFor(f.nome);
    if (mime == null) {
      scartati.add(FileScartato(f.nome, 'tipo non supportato (si accettano $formatiAccettati)'));
    } else if (f.bytes.isEmpty) {
      scartati.add(FileScartato(f.nome, 'file vuoto'));
    } else if (f.bytes.length > maxUploadBytes) {
      scartati.add(FileScartato(f.nome, 'più grande di 20 MB'));
    } else if (!visti.add('${f.nome}|${f.bytes.length}')) {
      scartati.add(FileScartato(f.nome, 'già nella selezione'));
    } else {
      accettati.add(FileDaCaricare(nome: f.nome, bytes: f.bytes, mimeType: mime));
    }
  }
  return (accettati: accettati, scartati: scartati);
}

/// Al massimo questi file per una cartella (oltre, l'elenco diventa ingestibile).
const int maxFileCartella = 1000;

/// File di sistema che non interessano a nessuno: si saltano in silenzio.
const _fileDiSistema = {'.ds_store', 'thumbs.db', 'desktop.ini', 'icon\r'};

/// Documenti iWork salvati come "pacchetto" (una cartella, sul Mac sembra un
/// file): il browser ne elenca i file interni, che non vanno caricati uno a uno.
final _pacchettoIwork = RegExp(r'\.(pages|numbers|key)$', caseSensitive: false);

/// Nome del file da un percorso relativo ("Bollette/2025/enel.pdf" → "enel.pdf").
String nomeDaPercorso(String percorso) => percorso.split(RegExp(r'[/\\]')).where((p) => p.isNotEmpty).lastOrNull ?? percorso;

/// Quali file di una cartella (anche nelle sottocartelle) caricare, senza
/// leggerli: si saltano in silenzio i file nascosti e di sistema, si scartano
/// con il motivo quelli di tipo non accettato, vuoti, troppo grandi, doppi
/// (stesso nome e dimensione) o oltre il limite. Un documento Pages, Numbers o
/// Keynote salvato come pacchetto si scarta una volta sola, con la spiegazione.
/// Logica pura, testata.
///
/// Restituisce gli indici dei file da caricare, nell'ordine dei percorsi.
({List<int> daLeggere, List<FileScartato> scartati}) selezionaDaCartella(List<({String percorso, int dimensione})> voci) {
  final ordine = List<int>.generate(voci.length, (i) => i)..sort((a, b) => voci[a].percorso.toLowerCase().compareTo(voci[b].percorso.toLowerCase()));
  final daLeggere = <int>[];
  final scartati = <FileScartato>[];
  final visti = <String>{};
  final pacchetti = <String>{};
  for (final i in ordine) {
    final v = voci[i];
    final parti = v.percorso.split(RegExp(r'[/\\]')).where((p) => p.isNotEmpty).toList();
    // Cartelle nascoste (.git, __MACOSX) e file nascosti o di sistema.
    if (parti.any((p) => p.startsWith('.') || p == '__MACOSX') || _fileDiSistema.contains(nomeDaPercorso(v.percorso).toLowerCase())) continue;
    final pacchetto = parti.sublist(0, parti.length - 1).indexWhere(_pacchettoIwork.hasMatch);
    if (pacchetto >= 0) {
      final nome = parti.sublist(0, pacchetto + 1).join('/');
      if (pacchetti.add(nome)) {
        scartati.add(FileScartato(nome, 'documento salvato come pacchetto: aprilo e scegli File › Avanzate › Cambia tipo di file › File singolo'));
      }
      continue;
    }
    if (mimeTypeFor(v.percorso) == null) {
      scartati.add(FileScartato(v.percorso, 'tipo non supportato'));
    } else if (v.dimensione == 0) {
      scartati.add(FileScartato(v.percorso, 'file vuoto'));
    } else if (v.dimensione > maxUploadBytes) {
      scartati.add(FileScartato(v.percorso, 'più grande di 20 MB'));
    } else if (!visti.add('${nomeDaPercorso(v.percorso)}|${v.dimensione}')) {
      scartati.add(FileScartato(v.percorso, 'già nella selezione'));
    } else if (daLeggere.length >= maxFileCartella) {
      scartati.add(FileScartato(v.percorso, 'oltre i $maxFileCartella file per volta'));
    } else {
      daLeggere.add(i);
    }
  }
  return (daLeggere: daLeggere, scartati: scartati);
}
