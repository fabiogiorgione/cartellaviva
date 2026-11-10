import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/emergenza/api/emergenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

void main() {
  test('contatto d\'emergenza: stati leggibili', () {
    ContattoEmergenza c(String stato, [String? richiesta]) => ContattoEmergenza.fromJson({'id': 'c', 'email': 'zia@example.com', 'stato': stato, 'richiestaStato': richiesta, 'giorniAttesa': 7});
    expect(c('invitato').statoLabel, 'Invito inviato');
    expect(c('attivo', 'in_attesa').inAttesa, isTrue);
    expect(c('attivo', 'concessa').statoLabel, 'Accesso concesso');
    expect(c('revocato').gestibile, isFalse);
    expect(ContattoEmergenza.fromJson({'id': 'c', 'email': 'e', 'stato': 'attivo', 'categorie': ['a', 'b']}).categorie, ['a', 'b']);
  });

  test('i link dell\'emergenza non richiedono l\'anagrafica completa', () {
    expect(SessionService.isOnboardingExempt('/emergenza/accetta?token=x'), isTrue);
    expect(SessionService.isOnboardingExempt('/emergenza'), isTrue);
  });
}
