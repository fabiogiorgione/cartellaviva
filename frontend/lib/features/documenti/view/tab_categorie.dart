import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';

/// Le categorie si gestiscono da Documenti → Categorie: qui resta solo il rimando
/// (la scheda rimane per i link già esistenti alle impostazioni).
class TabCategorie extends StatelessWidget {
  const TabCategorie({super.key});

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Testo.titleSmall('Categorie dei documenti', fontWeight: FontWeight.w600),
      SizedBox(height: 8),
      Testo.bodyMedium('Le categorie, predefinite e della famiglia, ora si gestiscono da Documenti → Categorie: lì trovi anche quanti documenti contiene ciascuna.'),
      SizedBox(height: 16),
      FilledButton.icon(onPressed: () => Get.toNamed('/categorie'), icon: const Icon(LucideIcons.tags, size: 18), label: const Text('Apri le categorie')),
    ]);
  }
}
