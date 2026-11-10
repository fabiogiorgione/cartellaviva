import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';

/// Colori per testi e icone di stato (errore, avviso, riuscita), leggibili sia
/// sul tema chiaro sia su quello scuro. Le tinte del tema (es. warning, un
/// giallo) sono pensate per i fondi pieni, non per il testo.
abstract final class ColoreStato {
  static bool get _scuro => PreferenzeTema.scuro;

  static Color get errore => _scuro ? Colors.red.shade300 : Colors.red.shade700;

  static Color get avviso => _scuro ? Colors.orange.shade300 : Colors.orange.shade800;

  static Color get successo => _scuro ? Colors.green.shade300 : Colors.green.shade700;
}
