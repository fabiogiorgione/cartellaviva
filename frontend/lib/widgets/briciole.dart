import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Una voce del percorso: con [rotta] diventa un link.
class Briciola {
  final String nome;
  final String? rotta;
  final bool attiva;

  const Briciola({required this.nome, this.rotta, this.attiva = false});
}

/// Percorso della pagina ("CartellaViva › Famiglia › Documenti"), nascosto sul telefono.
class Briciole extends StatelessWidget {
  static const inizio = Briciola(nome: 'CartellaViva', rotta: '/dashboard');

  final List<Briciola> voci;

  const Briciole({super.key, required this.voci});

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).size.width < 576) return const SizedBox.shrink();
    final tutte = [inizio, ...voci];
    return Row(mainAxisSize: MainAxisSize.min, children: [
      for (final (i, v) in tutte.indexed) ...[
        if (i > 0) const Padding(padding: EdgeInsets.symmetric(horizontal: 12), child: Icon(LucideIcons.chevron_right, size: 14)),
        if (v.attiva || v.rotta == null)
          Testo.labelMedium(v.nome, fontSize: 13, letterSpacing: 0)
        else
          InkWell(onTap: () => Get.offNamed(v.rotta!), child: Testo.labelMedium(v.nome, fontSize: 13, letterSpacing: 0, color: Theme.of(context).colorScheme.primary)),
      ],
    ]);
  }
}
