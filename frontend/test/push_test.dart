import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/services/push_service.dart';

void main() {
  test('una notifica apre solo pagine interne dell\'app', () {
    expect(PushService.routeSicura('/richieste'), '/richieste');
    expect(PushService.routeSicura('https://esempio.it'), isNull);
    expect(PushService.routeSicura('//esempio.it/x'), isNull);
    expect(PushService.routeSicura(null), isNull);
  });

  test('senza --dart-define di Firebase le push restano spente', () {
    expect(PushService.configurato, isFalse);
  });
}
