/// Sorgente di import (oggi solo Google Drive).
class ImportSource {
  final String id;
  final String provider;
  final String cartellaRadiceId;
  final String strategia;
  final String stato;
  final bool collegata;
  final String? accountEsterno;
  final bool sincronizzazioneInCorso;
  final DateTime? ultimaSincronizzazione;
  final Map<String, int> ultimoEsito;
  final String? ultimoErrore;
  final String? creatoDa;

  ImportSource({
    required this.id,
    required this.provider,
    required this.cartellaRadiceId,
    required this.strategia,
    required this.stato,
    this.collegata = false,
    this.accountEsterno,
    this.sincronizzazioneInCorso = false,
    this.ultimaSincronizzazione,
    this.ultimoEsito = const {},
    this.ultimoErrore,
    this.creatoDa,
  });

  factory ImportSource.fromJson(Map<String, dynamic> json) => ImportSource(
        id: json['id'] as String,
        provider: json['provider'] as String,
        cartellaRadiceId: json['cartellaRadiceId'] as String,
        strategia: json['strategia'] as String,
        stato: json['stato'] as String,
        collegata: json['collegata'] == true,
        accountEsterno: json['accountEsterno'] as String?,
        sincronizzazioneInCorso: json['sincronizzazioneInCorso'] == true,
        ultimaSincronizzazione: json['ultimaSincronizzazione'] is String ? DateTime.tryParse(json['ultimaSincronizzazione'] as String)?.toLocal() : null,
        ultimoEsito: (json['ultimoEsito'] as Map? ?? {}).map((k, v) => MapEntry(k.toString(), (v as num).toInt())),
        ultimoErrore: json['ultimoErrore'] as String?,
        creatoDa: json['creatoDa'] as String?,
      );

  String get strategiaLabel => strategia == 'ai_contenuto' ? 'Categoria scelta leggendo il contenuto' : 'Categoria dal nome della sottocartella';

  String get statoLabel => switch (stato) {
        'attivo' => 'Collegata',
        'errore' => 'Errore',
        'disattivato' => 'Disattivata',
        _ => 'Da collegare',
      };

  /// "12 importati, 3 già presenti" dall'esito dell'ultima sincronizzazione.
  String get esitoLabel {
    if (ultimoEsito.isEmpty) return '';
    final parts = <String>[];
    void add(String key, String label) {
      final n = ultimoEsito[key] ?? 0;
      if (n > 0) parts.add('$n $label');
    }

    add('importati', 'importati');
    add('duplicati', 'già presenti');
    add('saltati', 'saltati');
    add('errori', 'con errori');
    return parts.isEmpty ? 'nessun file nuovo' : parts.join(', ');
  }
}
