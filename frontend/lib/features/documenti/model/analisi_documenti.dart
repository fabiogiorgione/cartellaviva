/// Avanzamento dell'analisi AI dei documenti della famiglia (GET /documenti/analisi).
/// Solo conteggi: nessun contenuto dei documenti.
class AnalisiDocumenti {
  final int totale;
  final int analizzati;
  final int daAnalizzare;
  final int testoInLettura;
  final int senzaTesto;
  final int cifrati;

  /// Stato del modello che estrae dati e tag: disattivato, pronto, mancante, non_raggiungibile.
  final String modelloDati;

  /// Stato del modello della ricerca per significato (stessi valori).
  final String modelloRicerca;

  const AnalisiDocumenti({
    required this.totale,
    required this.analizzati,
    required this.daAnalizzare,
    required this.testoInLettura,
    required this.senzaTesto,
    required this.cifrati,
    required this.modelloDati,
    required this.modelloRicerca,
  });

  factory AnalisiDocumenti.fromJson(Map<String, dynamic> json) {
    final modelli = Map<String, dynamic>.from(json['modelli'] as Map? ?? const {});
    int n(String k) => (json[k] as num?)?.toInt() ?? 0;
    return AnalisiDocumenti(
      totale: n('totale'),
      analizzati: n('analizzati'),
      daAnalizzare: n('daAnalizzare'),
      testoInLettura: n('testoInLettura'),
      senzaTesto: n('senzaTesto'),
      cifrati: n('cifrati'),
      modelloDati: modelli['dati'] as String? ?? 'disattivato',
      modelloRicerca: modelli['ricerca'] as String? ?? 'disattivato',
    );
  }

  /// Documenti che l'AI può analizzare: testo letto e non cifrati.
  int get analizzabili => analizzati + daAnalizzare;

  /// Da 0 a 1; null se non c'è niente da analizzare.
  double? get progresso => analizzabili == 0 ? null : analizzati / analizzabili;

  bool get aiSpenta => modelloDati == 'disattivato' && modelloRicerca == 'disattivato';

  /// Un modello attivo non è scaricato o Ollama non risponde: l'analisi è ferma.
  bool get modelliNonPronti => [modelloDati, modelloRicerca].any((s) => s == 'mancante' || s == 'non_raggiungibile');
}
