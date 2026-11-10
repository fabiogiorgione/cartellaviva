class UrlService {
  /// Percorso della pagina corrente (es. `/documenti`), per evidenziare il menu.
  static String getCurrentUrl() => Uri.base.path;
}
