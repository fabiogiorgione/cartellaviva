import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/model/me.dart';

void main() {
  Map<String, dynamic> me({Object? verificata}) => {
        'id': 'u1',
        'email': 'anna@example.com',
        'firstName': 'Anna',
        'lastName': 'Verdi',
        'memberships': [],
        if (verificata != null) 'emailVerificata': verificata,
      };

  test('email da verificare solo se il server lo dice esplicitamente', () {
    expect(Me.fromJson(me(verificata: false)).emailVerificata, isFalse);
    expect(Me.fromJson(me(verificata: true)).emailVerificata, isTrue);
    // Backend precedente senza il campo: nessun avviso.
    expect(Me.fromJson(me()).emailVerificata, isTrue);
  });

  test('i link delle email dell\'account si aprono anche con l\'anagrafica da completare', () {
    for (final r in ['/auth/verifica-email?token=x', '/auth/cambio-email?token=x', '/auth/reset-password?token=x']) {
      expect(SessionService.isOnboardingExempt(r), isTrue, reason: r);
    }
    final incompleto = Me.fromJson({...me(verificata: false), 'profileCompleted': false});
    expect(SessionService.resolveHome(incompleto, null, next: '/auth/verifica-email?token=x'), '/auth/verifica-email?token=x');
  });
}
