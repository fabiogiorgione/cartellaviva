class ApiConfig {
  /// URL base dell'API Symfony. Si sovrascrive in build/run con:
  ///   flutter run --dart-define=API_BASE_URL=https://api.example.com/api
  /// Nota: dall'emulatore Android "localhost" è l'emulatore stesso, usare 10.0.2.2.
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8090/api',
  );
}
