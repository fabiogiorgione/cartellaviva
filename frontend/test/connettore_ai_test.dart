import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/account/model/me.dart';
import 'package:cartellaviva/features/connettore_ai/collega_mac_screen.dart';
import 'package:cartellaviva/features/connettore_ai/connettori_api.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';

void main() {
  test('un Mac collegato si legge e si descrive in una riga', () {
    final m = ConnettoreAi.fromJson({
      'id': 'c1',
      'nome': 'MacBook di Mario',
      'online': true,
      'ultimoContatto': '2026-11-15T10:00:00+01:00',
      'chip': 'Apple M3',
      'memoriaGb': 16,
      'modello': 'qwen3:4b-instruct-2507-q4_K_M',
      'durataMediaMs': 7400,
      'lavoriFatti': 46,
    });
    expect(m.online, isTrue);
    expect(m.lavoriFatti, 46);
    expect(m.descrizione, 'Apple M3 · 16 GB · qwen3:4b-instruct-2507-q4_K_M · 7,4 s a documento');
    expect(ConnettoreAi.fromJson({'id': 'c2', 'nome': 'Mac'}).descrizione, '');
    expect(ConnettoreAi.fromJson({'id': 'c3', 'nome': 'Mac', 'durataMediaMs': 18000}).descrizione, '18 s a documento');
  });

  group('approvazione di un Mac', mainApprovazione);
}

Widget _app(Widget figlio) => MaterialApp(theme: TemaApp.chiaro, home: Scaffold(body: SingleChildScrollView(child: figlio)));

Me _me({bool twoFactor = false, bool password = true}) =>
    Me(id: 'u', email: 'a@example.com', firstName: 'A', lastName: 'B', isSuperAdmin: false, memberships: const [], twoFactorEnabled: twoFactor, hasPassword: password);

void mainApprovazione() {
  test('la conferma: 2FA se attiva, altrimenti la password, senza nessuna delle due non si approva', () {
    expect(confermaMac(_me(twoFactor: true)), ConfermaMac.codice2fa);
    expect(confermaMac(_me(twoFactor: true, password: false)), ConfermaMac.codice2fa);
    expect(confermaMac(_me()), ConfermaMac.password);
    expect(confermaMac(_me(password: false)), ConfermaMac.impossibile);
  });

  test('il corpo dell\'approvazione porta la password, mai campi vuoti', () {
    expect(ConnettoriApi.corpoApprovazione('WDJB-MJHT', password: 'segreta'), {'userCode': 'WDJB-MJHT', 'password': 'segreta'});
    expect(ConnettoriApi.corpoApprovazione('WDJB-MJHT', nome: '', codice2fa: '', password: ''), {'userCode': 'WDJB-MJHT'});
    expect(ConnettoriApi.corpoApprovazione('WDJB-MJHT', nome: 'Mac', codice2fa: '123456'), {'userCode': 'WDJB-MJHT', 'nome': 'Mac', 'codice2fa': '123456'});
  });

  test('da quanto è stata fatta la richiesta', () {
    final ora = DateTime(2026, 11, 17, 10, 0);
    expect(fattaDa(ora.subtract(const Duration(seconds: 20)), ora), 'adesso');
    expect(fattaDa(ora.subtract(const Duration(minutes: 1)), ora), '1 minuto fa');
    expect(fattaDa(ora.subtract(const Duration(minutes: 7)), ora), '7 minuti fa');
  });

  ApprovazioneMac schermata(ConfermaMac conferma, {required TextEditingController password, required void Function(bool) onRisposta}) => ApprovazioneMac(
        famiglia: 'Famiglia Rossi',
        codice: 'WDJB-MJHT',
        nome: TextEditingController(text: 'MacBook'),
        conferma: conferma,
        codice2fa: TextEditingController(),
        password: password,
        onRisposta: onRisposta,
      );

  testWidgets('senza 2FA: campo della password e avviso sopra i pulsanti', (tester) async {
    final password = TextEditingController();
    final risposte = <bool>[];
    await tester.pumpWidget(_app(schermata(ConfermaMac.password, password: password, onRisposta: risposte.add)));
    final campo = find.byKey(const ValueKey('password-collega-mac'));
    expect(campo, findsOneWidget);
    expect(tester.widget<TextField>(campo).obscureText, isTrue);
    expect(find.text('Codice della verifica in due passaggi'), findsNothing);
    final avviso = find.byKey(const ValueKey('avviso-collega-mac'));
    expect(find.textContaining('un Mac tuo, davanti a te'), findsOneWidget);
    expect(find.textContaining('riceverà il testo dei documenti'), findsOneWidget);
    expect(tester.getBottomLeft(avviso).dy, lessThan(tester.getTopLeft(find.text('Approva')).dy));
    await tester.enterText(campo, 'segreta');
    await tester.tap(find.text('Approva'));
    expect(risposte, [true]);
    expect(password.text, 'segreta');
  });

  testWidgets('con la 2FA: il codice, non la password', (tester) async {
    await tester.pumpWidget(_app(schermata(ConfermaMac.codice2fa, password: TextEditingController(), onRisposta: (_) {})));
    expect(find.text('Codice della verifica in due passaggi'), findsOneWidget);
    expect(find.byKey(const ValueKey('password-collega-mac')), findsNothing);
  });

  testWidgets('solo Google: niente Approva, si manda a Sicurezza e accesso', (tester) async {
    final risposte = <bool>[];
    await tester.pumpWidget(_app(schermata(ConfermaMac.impossibile, password: TextEditingController(), onRisposta: risposte.add)));
    expect(find.text('Approva'), findsNothing);
    expect(find.text('Vai a Sicurezza e accesso'), findsOneWidget);
    await tester.tap(find.text('Rifiuta'));
    expect(risposte, [false]);
  });
}
