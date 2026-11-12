import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Paginazione del telefono: precedente / "Pagina 2 di 5" / successiva.
/// Sta sempre nella larghezza dello schermo (a differenza di `ListaPaginazione`).
/// [dettaglio], se c'è, va sotto (es. "11–20 di 43").
class PaginazioneTelefono extends StatelessWidget {
  final int pagina;
  final int pagine;
  final ValueChanged<int> onPagina;
  final String? dettaglio;

  const PaginazioneTelefono({super.key, required this.pagina, required this.pagine, required this.onPagina, this.dettaglio});

  @override
  Widget build(BuildContext context) {
    if (pagine <= 1) return const SizedBox.shrink();
    return Row(children: [
      IconButton(tooltip: 'Pagina precedente', onPressed: pagina > 1 ? () => onPagina(pagina - 1) : null, icon: const Icon(LucideIcons.chevron_left)),
      Expanded(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Testo.bodyMedium('Pagina $pagina di $pagine', fontWeight: FontWeight.w500),
          if (dettaglio != null) Testo.bodySmall(dettaglio!, tenue: true),
        ]),
      ),
      IconButton(tooltip: 'Pagina successiva', onPressed: pagina < pagine ? () => onPagina(pagina + 1) : null, icon: const Icon(LucideIcons.chevron_right)),
    ]);
  }
}
