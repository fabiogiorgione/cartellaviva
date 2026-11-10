import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/account/controller/anagrafica_controller.dart';
import 'package:cartellaviva/features/account/controller/login_controller.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/account/model/me.dart';

TenantMembership membership(Set<String> permissions) => TenantMembership(
      membershipId: 'm1',
      tenantId: 't1',
      tenantName: 'Famiglia Rossi',
      tenantType: 'privato',
      roleId: 'r1',
      roleSlug: 'member',
      roleName: 'Membro',
      permissions: permissions,
    );

void main() {
  group('TenantMembership.can (specchio del voter backend)', () {
    test('codice esatto o variante :all concedono', () {
      expect(membership({'documento:read'}).can('documento:read'), isTrue);
      expect(membership({'documento:write:all'}).can('documento:write', resourceOwnerId: 'altro', currentUserId: 'io'), isTrue);
    });

    test(':own concede solo sulle risorse proprie', () {
      final m = membership({'documento:write:own'});
      expect(m.can('documento:write', resourceOwnerId: 'io', currentUserId: 'io'), isTrue);
      expect(m.can('documento:write', resourceOwnerId: 'altro', currentUserId: 'io'), isFalse);
      expect(m.can('documento:write'), isFalse, reason: 'senza risorsa lo scope :own non basta');
    });

    test('permesso assente = negato', () {
      expect(membership({'documento:read'}).can('membro:invite'), isFalse);
    });
  });

  group('LoginController.safeNextUrl', () {
    test('accetta solo percorsi interni', () {
      expect(LoginController.safeNextUrl('/tenant/members'), '/tenant/members');
      expect(LoginController.safeNextUrl('/auth/invite?token=abc'), '/auth/invite?token=abc');
      expect(LoginController.safeNextUrl('https://evil.example'), '/dashboard');
      expect(LoginController.safeNextUrl('//evil.example'), '/dashboard');
      expect(LoginController.safeNextUrl('/auth/login'), '/dashboard');
      expect(LoginController.safeNextUrl(null), '/dashboard');
    });
  });

  group('Anagrafica: errori del backend -> campi del form', () {
    test('indirizzo, contatti e campi semplici', () {
      expect(AnagraficaController.fieldKey('addresses[0].comune'), 'residenza.comune');
      expect(AnagraficaController.fieldKey('addresses'), 'residenza.indirizzo');
      expect(AnagraficaController.fieldKey('telefono[0].phoneNumber'), 'telefono');
      expect(AnagraficaController.fieldKey('pec[0].email'), 'pec');
      expect(AnagraficaController.fieldKey('codiceFiscale'), 'codiceFiscale');
    });
  });

  group('Me.fromJson', () {
    test('profilo da completare dopo il primo accesso con Google', () {
      final me = Me.fromJson({
        'id': 'u1',
        'email': 'a@example.com',
        'firstName': 'Anna',
        'lastName': 'Verdi',
        'isSuperAdmin': false,
        'profileCompleted': false,
        'twoFactorEnabled': true,
        'googleLinked': true,
        'hasPassword': false,
        'memberships': [],
      });
      expect(me.profileCompleted, isFalse);
      expect(me.twoFactorEnabled, isTrue);
      expect(me.hasPassword, isFalse);
    });
  });

  group('ApiException.fromResponse', () {
    test('problem+json con violazioni di validazione (API Platform)', () {
      final e = ApiException.fromResponse(422, {
        'detail': 'email: non valida',
        'violations': [
          {'propertyPath': 'email', 'message': 'Email non valida.'}
        ],
      });
      expect(e.fieldErrors, {'email': 'Email non valida.'});
    });

    test('violazioni Symfony (MapRequestPayload) usano "title"', () {
      final e = ApiException.fromResponse(422, {
        'violations': [
          {'propertyPath': 'password', 'title': 'Troppo corta.'}
        ],
      });
      expect(e.fieldErrors['password'], 'Troppo corta.');
    });

    test('dettaglio del 409 (es. ultimo proprietario)', () {
      final e = ApiException.fromResponse(409, {'detail': 'Il tenant deve avere almeno un proprietario attivo.'});
      expect(e.isConflict, isTrue);
      expect(e.message, contains('proprietario'));
    });

    test('errore Lexik {code, message}', () {
      final e = ApiException.fromResponse(401, {'code': 401, 'message': 'Invalid credentials.'});
      expect(e.isUnauthorized, isTrue);
    });
  });
}
