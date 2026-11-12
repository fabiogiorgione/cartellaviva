import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/controller/da_confermare_service.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Scheda "Da fare" della Home: proposte da confermare e pagamenti in scadenza
/// entro 30 giorni (o già scaduti). Invisibile se non c'è nulla.
class DaFareCard extends StatefulWidget {
  const DaFareCard({super.key});

  @override
  State<DaFareCard> createState() => _DaFareCardState();
}

class _DaFareCardState extends State<DaFareCard> {
  List<Pagamento> inScadenza = const [];

  @override
  void initState() {
    super.initState();
    _carica();
  }

  Future<void> _carica() async {
    final session = SessionService.to;
    final tenantId = session.currentTenantId;
    if (tenantId == null || !session.can('pagamento:read')) return;
    try {
      final e = await PagamentiApi.list(tenantId, stato: 'da_pagare');
      final vicini = e.pagamenti.where((p) => p.giorniMancanti != null && p.giorniMancanti! <= 30).toList();
      if (mounted) setState(() => inScadenza = vicini);
    } on ApiException {
      // Solo un promemoria: se non risponde, la Home resta com'è.
    }
  }

  @override
  Widget build(BuildContext context) => GetBuilder<DaConfermareService>(
        init: DaConfermareService.to,
        builder: (s) {
          final proposte = s.totale;
          if (proposte == 0 && inScadenza.isEmpty) return const SizedBox.shrink();
          final scaduti = inScadenza.where((p) => p.giorniMancanti! < 0).length;
          final totale = inScadenza.fold<double>(0, (a, p) => a + (double.tryParse(p.importo) ?? 0));
          return Scheda(
            paddingAll: 16,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Testo.titleSmall('Da fare', fontWeight: FontWeight.w600),
              const SizedBox(height: 4),
              if (proposte > 0)
                _riga(
                  LucideIcons.sparkles,
                  proposte == 1 ? '1 proposta da confermare' : '$proposte proposte da confermare',
                  'Scadenze, pagamenti e voci delle pratiche letti nei documenti',
                  '/da-confermare',
                ),
              if (inScadenza.isNotEmpty)
                _riga(
                  LucideIcons.receipt,
                  inScadenza.length == 1 ? '1 pagamento in scadenza' : '${inScadenza.length} pagamenti in scadenza',
                  '${formatEuro(totale.toStringAsFixed(2))} entro 30 giorni${scaduti > 0 ? ', $scaduti già scadut${scaduti == 1 ? 'o' : 'i'}' : ''}',
                  '/pratiche/pagamenti',
                  allarme: scaduti > 0,
                ),
            ]),
          );
        },
      );

  Widget _riga(IconData icona, String titolo, String dettaglio, String rotta, {bool allarme = false}) => ListTile(
        contentPadding: EdgeInsets.zero,
        dense: true,
        onTap: () => apriDalMenu(rotta),
        leading: Icon(icona, color: allarme ? context.colori.danger : context.colori.primary),
        title: Text(titolo, style: const TextStyle(fontWeight: FontWeight.w500)),
        subtitle: Text(dettaglio),
        trailing: const Icon(LucideIcons.chevron_right, size: 18),
      );
}
