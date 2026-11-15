import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/sblocco_biometrico.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// `/auth/sblocco?next=…`: l'app è bloccata (apertura o ritorno dopo un po'
/// in background). Chiede subito Face ID; in alternativa la password.
class SbloccoScreen extends StatefulWidget {
  const SbloccoScreen({super.key});

  @override
  State<SbloccoScreen> createState() => _SbloccoScreenState();
}

class _SbloccoScreenState extends State<SbloccoScreen> {
  String _nome = 'Face ID';
  bool _inCorso = false;

  @override
  void initState() {
    super.initState();
    SbloccoBiometrico.nome().then((n) {
      if (mounted) setState(() => _nome = n);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _sblocca());
  }

  Future<void> _sblocca() async {
    if (_inCorso) return;
    setState(() => _inCorso = true);
    final ok = await SbloccoBiometrico.verifica('Sblocca CartellaViva');
    if (!mounted) return;
    setState(() => _inCorso = false);
    if (ok) {
      SbloccoBiometrico.sblocca();
      final next = Get.parameters['next'];
      Get.offAllNamed(next != null && next.isNotEmpty && !next.startsWith('/auth/') ? next : SessionService.to.homeRoute());
    }
  }

  @override
  Widget build(BuildContext context) {
    final me = SessionService.to.me;
    return PopScope(
      canPop: false,
      child: AuthLayout(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Align(alignment: Alignment.centerLeft, child: LogoCartellaViva(altezza: 28)),
            const SizedBox(height: 40),
            Center(
              child: Riquadro.cerchio(
                height: 52,
                width: 52,
                paddingAll: 0,
                color: context.colori.primary.withAlpha(40),
                child: Center(child: Testo.titleMedium(me?.initials ?? '', fontWeight: FontWeight.w600, color: context.colori.primary)),
              ),
            ),
            const SizedBox(height: 8),
            Center(child: Testo.titleMedium('Ciao ${me?.fullName ?? ''}', fontWeight: FontWeight.w500)),
            const SizedBox(height: 4),
            const Center(child: Testo.bodySmall('CartellaViva è bloccata.', tenue: true)),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: _inCorso ? null : _sblocca,
              icon: const Icon(LucideIcons.scan_face, size: 18),
              label: Text('Sblocca con $_nome'),
            ),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: () => Get.offAllNamed('/auth/lock'), child: const Text('Entra con la password')),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () async {
                SbloccoBiometrico.sblocca();
                await SessionService.to.logout();
                Get.offAllNamed('/auth/login');
              },
              child: const Text('Esci e accedi con un altro account'),
            ),
          ]),
        ),
      ),
    );
  }
}
