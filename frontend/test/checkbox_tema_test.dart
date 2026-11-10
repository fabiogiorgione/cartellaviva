import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('caselle: pieno solo se spuntate (le vuote non sembrano selezionate)', () {
    final fill = TemaApp.chiaro.checkboxTheme.fillColor!;
    expect(fill.resolve({}), Colors.transparent);
    expect(fill.resolve({WidgetState.hovered}), Colors.transparent);
    expect(fill.resolve({WidgetState.selected}), TemaApp.primario);
    expect(fill.resolve({WidgetState.selected, WidgetState.disabled}), TemaApp.primario.withAlpha(100));
  });
}
