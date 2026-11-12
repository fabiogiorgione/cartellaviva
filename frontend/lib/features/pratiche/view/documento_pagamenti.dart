import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/pratiche/view/proposta_card.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Nel dettaglio di un documento: "Avviso di pagamento trovato" (se c'è una
/// proposta aperta) e "Usato in: Pagamenti" (se è l'origine o la ricevuta di
/// un pagamento). Invisibile se non c'è nulla o mancano i permessi.
class DocumentoPagamenti extends StatelessWidget {
  final Documento documento;

  /// Chiamata dopo conferma o rifiuto: serve a rileggere il documento.
  final VoidCallback onCambiato;

  const DocumentoPagamenti({super.key, required this.documento, required this.onCambiato});

  @override
  Widget build(BuildContext context) {
    final proposta = documento.pagamentoProposta;
    final collegati = documento.pagamenti;
    if (proposta == null && collegati.isEmpty) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (proposta != null) ...[
        const SizedBox(height: 16),
        Scheda(
          paddingAll: 20,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Testo.titleSmall('Avviso di pagamento trovato', fontWeight: FontWeight.w600),
            const SizedBox(height: 2),
            const Testo.bodySmall('Letto dal testo del documento: diventa un pagamento solo se lo confermi.', tenue: true),
            const SizedBox(height: 12),
            propostaPagamentoCard(context, proposta, mostraDocumento: false, onDecisa: onCambiato),
          ]),
        ),
      ],
      if (collegati.isNotEmpty) ...[
        const SizedBox(height: 16),
        Scheda(
          paddingAll: 16,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Testo.bodyMedium('Usato in: Pagamenti', fontWeight: FontWeight.w500),
            for (final p in collegati)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: const Icon(LucideIcons.receipt, size: 20),
                title: Text(p.titolo, maxLines: 2, overflow: TextOverflow.ellipsis),
                subtitle: Text('${p.pagato ? 'pagato' : 'da pagare'} · ${p.ruolo == 'ricevuta' ? 'ricevuta' : 'avviso'}'),
                onTap: () => apriDalMenu('/pratiche/pagamenti'),
              ),
          ]),
        ),
      ],
    ]);
  }
}
