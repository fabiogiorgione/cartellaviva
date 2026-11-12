import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Stato del modello di Apple su questo telefono.
class StatoAiLocale {
  final bool disponibile;

  /// Perché non c'è: ios (iOS sotto 26), dispositivo, apple_intelligence, modello (non ancora scaricato), lingua, altro.
  final String? motivo;

  /// Contesto del modello, in token.
  final int? contesto;

  const StatoAiLocale({required this.disponibile, this.motivo, this.contesto});

  static const assente = StatoAiLocale(disponibile: false, motivo: 'ios');

  /// Spiegazione per la persona.
  String get spiegazione => disponibile
      ? 'Il modello di Apple è pronto su questo iPhone.'
      : switch (motivo) {
          'dispositivo' => 'Questo iPhone non supporta Apple Intelligence.',
          'apple_intelligence' => 'Attiva Apple Intelligence in Impostazioni › Apple Intelligence e Siri.',
          'modello' => 'Il modello di Apple si sta ancora scaricando: riprova più tardi.',
          'lingua' => 'Apple Intelligence non supporta la lingua impostata sul telefono.',
          'ios' => 'Serve iOS 26 o successivo con Apple Intelligence.',
          _ => 'Il modello di Apple non è disponibile su questo telefono.',
        };

  /// Caratteri di testo da mandare al modello: il contesto meno lo spazio per
  /// istruzioni, schema e risposta (circa 3 caratteri per token in italiano).
  int get caratteri => (((contesto ?? 4096) - 1500) * 3).clamp(1500, 12000);
}

/// Analisi dei documenti sul telefono con il modello di Apple (canale nativo
/// `cartellaviva/ai_locale`, in ios/Runner/AppDelegate.swift). Il testo resta
/// in memoria; al server tornano solo i valori letti. Su Android e sul web non
/// c'è: lo stato è "non disponibile".
abstract final class AiLocale {
  static const _canale = MethodChannel('cartellaviva/ai_locale');
  static const _chiave = 'ai_locale_attiva';

  /// La scelta della persona su questo dispositivo ("Analizza i documenti su questo iPhone").
  static final attiva = ValueNotifier<bool>(false);

  static bool get supportata => !kIsWeb && defaultTargetPlatform == TargetPlatform.iOS;

  static Future<void> carica() async {
    if (!supportata) return;
    attiva.value = (await SharedPreferences.getInstance()).getBool(_chiave) ?? false;
  }

  static Future<void> imposta(bool valore) async {
    attiva.value = valore;
    await (await SharedPreferences.getInstance()).setBool(_chiave, valore);
  }

  static Future<StatoAiLocale> stato() async {
    if (!supportata) return StatoAiLocale.assente;
    try {
      final r = Map<String, dynamic>.from(await _canale.invokeMethod<Map>('stato') ?? const {});
      return StatoAiLocale(disponibile: r['disponibile'] == true, motivo: r['motivo'] as String?, contesto: (r['contesto'] as num?)?.toInt());
    } on MissingPluginException {
      return StatoAiLocale.assente;
    } on PlatformException {
      return const StatoAiLocale(disponibile: false, motivo: 'altro');
    }
  }

  /// Da usare per le pratiche: scelta attiva e modello pronto.
  static Future<StatoAiLocale?> utilizzabile() async {
    if (!supportata) return null;
    if (!attiva.value) await carica();
    if (!attiva.value) return null;
    final s = await stato();
    return s.disponibile ? s : null;
  }

  /// Esegue il modello con lo schema (JSON Schema ridotto) e restituisce il JSON della risposta.
  static Future<String> estrai({required String istruzioni, required String prompt, required String schema}) async {
    final r = await _canale.invokeMethod<String>('estrai', {'istruzioni': istruzioni, 'prompt': prompt, 'schema': schema});
    if (r == null) throw PlatformException(code: 'vuota', message: 'Risposta vuota dal modello.');
    jsonDecode(r); // dev'essere JSON valido
    return r;
  }
}
