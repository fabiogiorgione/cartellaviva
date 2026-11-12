// Punto d'ingresso per i test di navigazione: attiva Flutter Driver e avvia
// l'app vera. Non entra nelle build normali (lib/main.dart resta invariato).
//
// Solo qui: se nella cartella temporanea dell'app compare il file "vai.txt"
// con una rotta (es. "/scadenze"), l'app la apre e cancella il file. Serve a
// raggiungere ogni schermata dal simulatore senza toccare l'interfaccia:
//   echo /scadenze > "$(xcrun simctl get_app_container booted it.cartellaviva.app data)/tmp/vai.txt"
import 'dart:async';
import 'dart:io';

import 'package:flutter_driver/driver_extension.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/main.dart' as app;

Future<void> main() async {
  enableFlutterDriverExtension();
  await app.main();
  final file = File('${Directory.systemTemp.path}/vai.txt');
  Timer.periodic(const Duration(milliseconds: 500), (_) {
    if (!file.existsSync()) return;
    final rotta = file.readAsStringSync().trim();
    file.deleteSync();
    if (rotta == 'indietro') {
      Get.back();
    } else if (rotta.startsWith('/')) {
      Get.toNamed(rotta);
    }
  });
}
