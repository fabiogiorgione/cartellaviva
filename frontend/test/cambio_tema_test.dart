import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:cartellaviva/helpers/theme/preferenze_tema.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';

/// Pagina con stato che non dipende dal tema (come le liste del portale).
class _Pagina extends StatefulWidget {
  const _Pagina();

  @override
  State<_Pagina> createState() => _PaginaState();
}

class _PaginaState extends State<_Pagina> {
  @override
  Widget build(BuildContext context) => const Scheda(child: Text('lista'));
}

void main() {
  test('colori di stato: invariati nel tema chiaro, chiari nel tema scuro', () {
    PreferenzeTema.modo.value = ThemeMode.light;
    expect([ColoreStato.errore, ColoreStato.avviso, ColoreStato.successo], [Colors.red.shade700, Colors.orange.shade800, Colors.green.shade700]);
    PreferenzeTema.modo.value = ThemeMode.dark;
    expect([ColoreStato.errore, ColoreStato.avviso, ColoreStato.successo], [Colors.red.shade300, Colors.orange.shade300, Colors.green.shade300]);
    PreferenzeTema.modo.value = ThemeMode.light;
  });

  test('i colori dell\'app sono nel tema, diversi per chiaro e scuro', () {
    expect(TemaApp.chiaro.extension<ColoriApp>(), ColoriApp.chiaro);
    expect(TemaApp.scuro.extension<ColoriApp>(), ColoriApp.scuro);
    expect(ColoriApp.scuro.barraSfondo, isNot(ColoriApp.chiaro.barraSfondo));
  });

  test('menu di select, popup e autocomplete hanno uno sfondo (canvasColor opaco)', () {
    // Con Material 2 i menu delle select si dipingono con canvasColor: trasparente = menu illeggibili.
    for (final tema in [TemaApp.chiaro, TemaApp.scuro]) {
      expect(tema.canvasColor.a, 1.0, reason: '${tema.brightness}');
    }
    expect(TemaApp.scuro.canvasColor, isNot(TemaApp.scuro.cardColor), reason: 'nel tema scuro il menu si stacca dalla card');
  });

  testWidgets('al cambio chiaro/scuro le schede prendono i colori del nuovo tema', (tester) async {
    Future<void> app(ThemeData tema) => tester.pumpWidget(MaterialApp(theme: tema, home: const Scaffold(body: _Pagina())));
    Color? sfondo() => (tester.widget<Container>(find.descendant(of: find.byType(Scheda), matching: find.byType(Container)).first).decoration as BoxDecoration).color;

    await app(TemaApp.chiaro);
    expect(sfondo(), TemaApp.chiaro.cardTheme.color);
    await app(TemaApp.scuro);
    await tester.pumpAndSettle();
    expect(sfondo(), TemaApp.scuro.cardTheme.color);
  });
}
