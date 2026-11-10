import 'package:cartellaviva/helpers/files/upload_batch.dart';

import 'package:cartellaviva/helpers/files/folder_picker_stub.dart' if (dart.library.io) 'package:cartellaviva/helpers/files/folder_picker_io.dart' as impl;

/// Una cartella scelta dall'utente: i file da caricare (anche delle
/// sottocartelle, letti solo al momento del caricamento) e quelli scartati con il motivo.
typedef CartellaScelta = ({String nome, List<FileDaCaricare> files, List<FileScartato> scartati});

/// Si può scegliere una cartella? Nel browser e sul computer sì; sul telefono no
/// (Android e iOS non danno accesso a una cartella intera in modo affidabile).
bool get cartellaSupportata => impl.cartellaSupportata;

/// Fa scegliere una cartella ed elenca i file caricabili, senza leggerli; null se l'utente annulla.
Future<CartellaScelta?> scegliCartella() => impl.scegliCartella();
