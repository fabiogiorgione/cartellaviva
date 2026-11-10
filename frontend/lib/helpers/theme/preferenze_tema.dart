import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Preferenze di aspetto: tema chiaro o scuro e barra laterale compatta.
/// Il tema si ricorda tra un avvio e l'altro.
abstract final class PreferenzeTema {
  static const _chiave = 'tema_scuro';

  static final modo = ValueNotifier<ThemeMode>(ThemeMode.light);
  static final barraCompatta = ValueNotifier<bool>(false);

  static bool get scuro => modo.value == ThemeMode.dark;

  static Future<void> carica() async {
    final preferenze = await SharedPreferences.getInstance();
    modo.value = (preferenze.getBool(_chiave) ?? false) ? ThemeMode.dark : ThemeMode.light;
  }

  static Future<void> alternaModo() async {
    modo.value = scuro ? ThemeMode.light : ThemeMode.dark;
    Get.changeThemeMode(modo.value);
    // Ridisegna anche ciò che non dipende dal tema del contesto (es. ColoreStato).
    Get.forceAppUpdate();
    final preferenze = await SharedPreferences.getInstance();
    await preferenze.setBool(_chiave, scuro);
  }

  static void alternaBarra() => barraCompatta.value = !barraCompatta.value;
}
