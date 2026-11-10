/// Web: niente documenti offline (nessun archivio sicuro sul browser).
class DocumentoOffline {
  final String id;
  final String titolo;
  final String nomeFile;
  final String mimeType;
  final String? categoria;
  final int dimensione;
  final DateTime salvatoIl;

  DocumentoOffline({required this.id, required this.titolo, required this.nomeFile, required this.mimeType, this.categoria, this.dimensione = 0, required this.salvatoIl});
}

class OfflineStore {
  OfflineStore._();

  static bool get supportato => false;
  static bool get sbloccato => false;
  static Future<bool> sblocca() async => false;
  static void blocca() {}
  static Future<String?> utente([String? corrente]) async => null;
  static Future<List<DocumentoOffline>> elenco(String userId) async => [];
  static Future<bool> contiene(String userId, String docId) async => false;
  static Future<void> salva(String userId, DocumentoOffline meta, List<int> contenuto) => throw UnsupportedError('Documenti offline non disponibili sul web');
  static Future<List<int>> leggi(String userId, String docId) => throw UnsupportedError('Documenti offline non disponibili sul web');
  static Future<void> rimuovi(String userId, String docId) async {}
  static Future<void> svuota(String userId) async {}
  static Future<void> apri(String nomeFile, String mimeType, List<int> contenuto) => throw UnsupportedError('Documenti offline non disponibili sul web');
  static Future<void> pulisciTemporanei() async {}
}
