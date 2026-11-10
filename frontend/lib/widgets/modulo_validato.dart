import 'package:flutter/material.dart';

/// Una regola su un campo di testo: restituisce il messaggio d'errore o null.
abstract class RegolaCampo {
  const RegolaCampo();

  String? verifica(String valore);
}

/// Indirizzo email nella forma nome@dominio.estensione.
class RegolaEmail extends RegolaCampo {
  const RegolaEmail();

  @override
  String? verifica(String valore) => valore.isEmpty || emailValida(valore) ? null : 'Inserisci un indirizzo email valido';
}

/// Lunghezza minima e/o massima.
class RegolaLunghezza extends RegolaCampo {
  final int? min, max;

  const RegolaLunghezza({this.min, this.max});

  @override
  String? verifica(String valore) {
    if (valore.isEmpty) return null;
    if (min != null && valore.length < min!) return 'Deve contenere almeno $min caratteri';
    if (max != null && valore.length > max!) return 'Deve contenere al massimo $max caratteri';
    return null;
  }
}

bool emailValida(String valore) => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(valore.trim());

/// Campi di un modulo con le loro regole, e gli errori arrivati dal server
/// (mostrati sotto il campo alla validazione successiva).
class ModuloValidato {
  final formKey = GlobalKey<FormState>();
  final Map<String, TextEditingController> _campi = {};
  final Map<String, FormFieldValidator<String>> _validatori = {};
  final Map<String, String> _erroriServer = {};

  void addField(String nome, {bool required = false, required String label, List<RegolaCampo> validators = const [], TextEditingController? controller}) {
    _campi[nome] = controller ?? TextEditingController();
    _validatori[nome] = (valore) {
      final errore = _erroriServer[nome];
      if (errore != null) return errore;
      final testo = valore ?? '';
      if (required && testo.isEmpty) return '$label è obbligatorio';
      for (final r in validators) {
        final e = r.verifica(testo);
        if (e != null) return e;
      }
      return null;
    };
  }

  TextEditingController? getController(String nome) => _campi[nome];

  FormFieldValidator<String>? getValidation(String nome) => _validatori[nome];

  Map<String, String> getData() => {for (final e in _campi.entries) e.key: e.value.text};

  bool validateForm() => formKey.currentState?.validate() ?? false;

  void addErrors(Map<String, dynamic> errori) {
    for (final e in errori.entries) {
      final v = e.value;
      _erroriServer[e.key] = v is List && v.isNotEmpty ? v.first.toString() : v.toString();
    }
  }

  void clearErrors() => _erroriServer.clear();
}
