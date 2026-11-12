import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Una voce del percorso: con [rotta] diventa un link.
class Briciola {
  final String nome;
  final String? rotta;
  final bool attiva;

  const Briciola({required this.nome, this.rotta, this.attiva = false});
}

/// Il [Layout] largo lo mette sopra le pagine di dettaglio: lì il ritorno al
/// genitore è il collegamento "← Documenti", quindi le [Briciole] si nascondono.
class DettaglioLargo extends InheritedWidget {
  const DettaglioLargo({super.key, required super.child});

  @override
  bool updateShouldNotify(DettaglioLargo oldWidget) => false;
}

/// Percorso della pagina ("CartellaViva › Famiglia › Documenti"), nascosto sul telefono
/// e nei dettagli. I collegamenti portano a pagine di primo livello: [apriDalMenu].
class Briciole extends StatelessWidget {
  static const inizio = Briciola(nome: 'CartellaViva', rotta: '/dashboard');

  final List<Briciola> voci;

  const Briciole({super.key, required this.voci});

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).size.width < larghezzaTelefono || context.getInheritedWidgetOfExactType<DettaglioLargo>() != null) return const SizedBox.shrink();
    final tutte = [inizio, ...voci];
    return Row(mainAxisSize: MainAxisSize.min, children: [
      for (final (i, v) in tutte.indexed) ...[
        if (i > 0) const Padding(padding: EdgeInsets.symmetric(horizontal: 12), child: Icon(LucideIcons.chevron_right, size: 14)),
        if (v.attiva || v.rotta == null)
          Testo.labelMedium(v.nome, fontSize: 13, letterSpacing: 0)
        else
          InkWell(onTap: () => apriDalMenu(v.rotta!), child: Testo.labelMedium(v.nome, fontSize: 13, letterSpacing: 0, color: Theme.of(context).colorScheme.primary)),
      ],
    ]);
  }
}
