/// Richieste di un elenco (filtri, ordinamento, pagine cambiati in fretta):
/// vale solo l'ultima partita, anche se le risposte arrivano in disordine.
class UltimaRichiesta {
  int _ultima = 0;

  /// Il risultato di [richiesta], o null se nel frattempo ne è partita un'altra
  /// (anche i suoi errori, a quel punto, non interessano più).
  Future<T?> esegui<T>(Future<T> Function() richiesta) async {
    final mia = ++_ultima;
    try {
      final risultato = await richiesta();
      return mia == _ultima ? risultato : null;
    } catch (_) {
      if (mia == _ultima) rethrow;
      return null;
    }
  }
}
