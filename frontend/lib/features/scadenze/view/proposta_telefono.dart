import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Telefono: la proposta ha il testo a tutta larghezza e i pulsanti sotto.
class PropostaTelefono extends StatelessWidget {
  final ScadenzaProposta proposta;
  final bool mostraDocumento;
  final VoidCallback onIgnora;
  final VoidCallback onCrea;

  const PropostaTelefono({super.key, required this.proposta, required this.mostraDocumento, required this.onIgnora, required this.onCrea});

  @override
  Widget build(BuildContext context) {
    final p = proposta;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Padding(padding: EdgeInsets.only(top: 2), child: Icon(LucideIcons.calendar_search, size: 20)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodyMedium('${p.tipo ?? p.documentoTitolo} · scade il ${formatDataBreve(p.data)}', fontWeight: FontWeight.w500),
              const SizedBox(height: 2),
              if (mostraDocumento)
                InkWell(
                  onTap: () => Get.toNamed('/documento/${p.documentoId}'),
                  child: Testo.bodySmall('Dal documento "${p.documentoTitolo}"', tenue: true, decoration: TextDecoration.underline),
                )
              else
                Testo.bodySmall('Trovata nel testo del documento: controlla che sia giusta.', tenue: true),
            ]),
          ),
        ]),
        const SizedBox(height: 8),
        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: onIgnora, child: const Text('Ignora')),
          const SizedBox(width: 8),
          FilledButton(onPressed: onCrea, child: const Text('Crea scadenza')),
        ]),
      ]),
    );
  }
}
