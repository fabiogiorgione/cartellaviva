import 'package:flutter/widgets.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Per le schermate che eseguono un'operazione alla volta: [busy] mentre è in
/// corso, [errore] con il messaggio se fallisce.
mixin OperazioneConErrore<T extends StatefulWidget> on State<T> {
  bool busy = false;
  String? errore;

  Future<void> esegui(Future<void> Function() azione) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await azione();
    } on ApiException catch (e) {
      errore = e.message;
    } catch (e) {
      errore = e.toString();
    }
    if (mounted) setState(() => busy = false);
  }
}
