import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/model/me.dart';

TenantMembership membership(String type) => TenantMembership(
      membershipId: 'm-$type',
      tenantId: 't-$type',
      tenantName: type == 'azienda' ? 'Scuola Primaria Verdi' : 'Famiglia Rossi',
      tenantType: type,
      roleId: 'r',
      roleSlug: 'owner',
      roleName: 'Proprietario',
      permissions: {},
    );

Me me({bool profileCompleted = true, List<TenantMembership> memberships = const [], bool superAdmin = false}) => Me(
      id: 'u1',
      email: 'a@example.com',
      firstName: 'Anna',
      lastName: 'Rossi',
      isSuperAdmin: superAdmin,
      memberships: memberships,
      profileCompleted: profileCompleted,
    );

void main() {
  test('il team del portale senza famiglie né enti va al portale', () {
    final me = Me(id: 'u', email: 'supporto@x.it', firstName: 'S', lastName: 'P', isSuperAdmin: false, memberships: const [], platformRole: 'supporto');
    expect(SessionService.resolveHome(me, null), '/admin');
    expect(me.isSupporto, isTrue);
    final lettore = Me(id: 'u', email: 'l@x.it', firstName: 'L', lastName: 'P', isSuperAdmin: false, memberships: const [], platformRole: 'sola_lettura');
    expect(lettore.isSupporto, isFalse);
  });

  test('promemoria 2FA: solo a chi opera in un ente senza 2FA, una volta per accesso', () {
    final session = SessionService();
    session.me = me(memberships: [membership('privato')]);
    expect(session.serve2faPromemoria, isFalse, reason: 'solo famiglie');
    session.me = me(memberships: [membership('privato'), membership('azienda')]);
    expect(session.serve2faPromemoria, isTrue);
    session.segnaPromemoria2faMostrato();
    expect(session.serve2faPromemoria, isFalse, reason: 'già mostrato in questo accesso');
    final con2fa = SessionService()
      ..me = Me(id: 'u', email: 'a@x.it', firstName: 'A', lastName: 'B', isSuperAdmin: false, memberships: [membership('azienda')], twoFactorEnabled: true);
    expect(con2fa.serve2faPromemoria, isFalse);
  });

  group('SessionService.resolveHome', () {
    test('famiglia -> dashboard, ente -> area ente', () {
      final famiglia = membership('privato');
      final ente = membership('azienda');
      expect(SessionService.resolveHome(me(memberships: [famiglia, ente]), famiglia), '/dashboard');
      expect(SessionService.resolveHome(me(memberships: [famiglia, ente]), ente), '/ente');
    });

    test('nessuna famiglia né ente -> benvenuto (il superadmin va al portale)', () {
      expect(SessionService.resolveHome(me(), null), '/benvenuto');
      final admin = Me(id: 'a', email: 'a@x.it', firstName: 'A', lastName: 'B', isSuperAdmin: true, memberships: const [], platformRole: 'superadmin');
      expect(SessionService.resolveHome(admin, null), '/admin');
    });

    test('anagrafica da completare: profilo, ma inviti e richieste restano raggiungibili', () {
      final incompleto = me(profileCompleted: false, memberships: [membership('privato')]);
      expect(SessionService.resolveHome(incompleto, null), '/account/profile');
      expect(SessionService.resolveHome(incompleto, null, next: '/auth/invite?token=abc'), '/auth/invite?token=abc');
      expect(SessionService.resolveHome(incompleto, null, next: '/richieste/r1'), '/richieste/r1');
      expect(SessionService.resolveHome(incompleto, null, next: '/documenti/nuovo'), '/account/profile');
    });

    test('una pagina richiesta ha la precedenza sulla home', () {
      final famiglia = membership('privato');
      expect(SessionService.resolveHome(me(memberships: [famiglia]), famiglia, next: '/tenant/members'), '/tenant/members');
      expect(SessionService.resolveHome(me(memberships: [famiglia]), famiglia, next: '/dashboard'), '/dashboard');
    });
  });
}
