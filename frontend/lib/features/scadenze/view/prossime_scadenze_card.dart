import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Riquadro "Prossime scadenze" (dashboard): scadute + prossimi 30 giorni.
/// [documentoId]: solo quelle collegate a un documento (scheda documento),
/// con il pulsante "+ Aggiungi" precompilato.
class ProssimeScadenzeCard extends StatefulWidget {
  final String? documentoId;
  final Future<void> Function()? onAggiungi;

  const ProssimeScadenzeCard({super.key, this.documentoId, this.onAggiungi});

  @override
  State<ProssimeScadenzeCard> createState() => ProssimeScadenzeCardState();
}

class ProssimeScadenzeCardState extends State<ProssimeScadenzeCard> {
  late Future<List<Scadenza>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  void reload() => setState(() => _future = _load());

  Future<List<Scadenza>> _load() async {
    final tenantId = SessionService.to.currentTenantId;
    if (tenantId == null || !SessionService.to.can('scadenza:read')) {
      return [];
    }
    if (widget.documentoId != null) {
      return ScadenzaApi.list(tenantId, documentoId: widget.documentoId);
    }
    final aperte = await ScadenzaApi.list(tenantId, stato: 'aperta', a: DateTime.now().add(Duration(days: 30)));
    return aperte.take(5).toList();
  }

  @override
  Widget build(BuildContext context) {
    final perDocumento = widget.documentoId != null;
    return Scheda(paddingAll: 16, child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            Expanded(child: Testo.bodyMedium(perDocumento ? 'Scadenze collegate' : 'Prossime scadenze', fontWeight: FontWeight.w500)),
            if (perDocumento && widget.onAggiungi != null)
              TextButton.icon(onPressed: widget.onAggiungi, icon: Icon(LucideIcons.plus, size: 16), label: Text('Aggiungi'))
            else if (!perDocumento)
              TextButton(onPressed: () => Get.toNamed('/scadenze'), child: Text('Tutte')),
          ]),
          FutureBuilder<List<Scadenza>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return Padding(padding: EdgeInsets.all(12), child: Center(child: CircularProgressIndicator()));
              }
              if (snap.hasError) {
                return Testo.bodySmall(snap.error is ApiException ? (snap.error as ApiException).message : 'Errore di caricamento.');
              }
              final list = snap.data ?? [];
              if (list.isEmpty) {
                return Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: Testo.bodySmall(perDocumento ? 'Nessuna. Esempio: "Rinnovare entro…".' : 'Nessuna scadenza nei prossimi 30 giorni.', tenue: true),
                );
              }
              return Column(
                children: [
                  for (final s in list)
                    Padding(
                      padding: EdgeInsets.symmetric(vertical: 6),
                      child: Row(children: [
                        SizedBox(
                          width: 54,
                          child: Testo.bodySmall('${s.data.day.toString().padLeft(2, '0')}/${s.data.month.toString().padLeft(2, '0')}', fontWeight: FontWeight.w500),
                        ),
                        Expanded(child: Testo.bodyMedium(s.titolo, overflow: TextOverflow.ellipsis)),
                        Testo.bodySmall(s.quando, color: s.scaduta ? ColoreStato.errore : null, tenue: !s.scaduta),
                      ]),
                    ),
                ],
              );
            },
          ),
        ],
      ));
  }
}
