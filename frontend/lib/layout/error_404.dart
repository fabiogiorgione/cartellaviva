import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/auth_layout.dart';

/// Pagina per gli indirizzi che non esistono (unknownRoute di GetMaterialApp).
class Error404Screen extends StatelessWidget {
  const Error404Screen({super.key});

  @override
  Widget build(BuildContext context) {
    final loggato = SessionService.to.isLoggedIn;
    return AuthLayout(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(LucideIcons.folder_search, size: 56, color: Theme.of(context).colorScheme.primary),
            SizedBox(height: 20),
            Testo.titleLarge('Pagina non trovata', fontWeight: FontWeight.w600),
            SizedBox(height: 8),
            Testo.bodyMedium('L\'indirizzo non esiste o non è più valido. Se l\'hai aperto da un\'email, il link potrebbe essere scaduto.', textAlign: TextAlign.center, tenue: true),
            SizedBox(height: 24),
            FilledButton.icon(
              onPressed: () => Get.offAllNamed(loggato ? SessionService.to.homeRoute() : '/auth/login'),
              icon: const Icon(LucideIcons.house, size: 18),
              label: Text(loggato ? 'Torna alla home' : 'Vai all\'accesso'),
            ),
          ],
        ),
      ),
    );
  }
}
