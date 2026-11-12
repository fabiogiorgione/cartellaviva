import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/import/api/import_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';

/// Messaggio per l'esito del collegamento a Google (parametro "motivo" del callback).
String messaggioEsitoImport(String? esito, String? motivo) {
  if (esito == 'ok') return 'Google Drive è collegato. Ora puoi importare i documenti della cartella.';
  return switch (motivo) {
    'annullato' => 'Hai annullato l\'autorizzazione su Google: nessun accesso è stato concesso.',
    'scaduto' => 'Il collegamento è scaduto (oltre 10 minuti): riprova dalle impostazioni della famiglia.',
    'non_valido' => 'Il collegamento non è valido o è già stato usato: riprova dalle impostazioni della famiglia.',
    _ => 'Google non ha completato il collegamento: riprova tra qualche minuto.',
  };
}

/// Ritorno dall'autorizzazione di Google (web: /import/esito; app: deep link).
class ImportEsitoScreen extends StatefulWidget {
  const ImportEsitoScreen({super.key});

  @override
  State<ImportEsitoScreen> createState() => _ImportEsitoScreenState();
}

class _ImportEsitoScreenState extends State<ImportEsitoScreen> {
  final String? esito = Get.parameters['esito'];
  final String? motivo = Get.parameters['motivo'];
  final String? tenantId = Get.parameters['tenant'];
  final String? sorgenteId = Get.parameters['sorgente'];
  bool syncing = false;
  bool synced = false;

  bool get ok => esito == 'ok';

  @override
  void initState() {
    super.initState();
    // Torna sulla famiglia della sorgente, se ne faccio parte.
    final id = tenantId;
    if (ok && id != null && SessionService.to.currentTenantId != id) {
      SessionService.to.selectTenant(id);
    }
  }

  Future<void> _sync() async {
    setState(() => syncing = true);
    try {
      await ImportApi.sync(tenantId!, sorgenteId!);
      synced = true;
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
    if (mounted) setState(() => syncing = false);
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      indietro: '/documenti',
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(ok ? LucideIcons.circle_check : LucideIcons.circle_x, color: ok ? context.colori.success : context.colori.danger, size: 28),
              SizedBox(width: 12),
              Expanded(child: Testo.titleMedium(ok ? 'Collegamento riuscito' : 'Collegamento non riuscito', fontWeight: FontWeight.w500)),
            ]),
            SizedBox(height: 12),
            Testo.bodyMedium(messaggioEsitoImport(esito, motivo)),
            if (synced) ...[
              SizedBox(height: 8),
              Testo.bodyMedium('Importazione avviata: i documenti compaiono man mano. Puoi seguire l\'avanzamento nelle impostazioni.'),
            ],
            SizedBox(height: 20),
            Wrap(spacing: 12, runSpacing: 8, children: [
              if (ok && sorgenteId != null && tenantId != null && !synced)
                FilledButton.icon(
                  onPressed: syncing ? null : _sync,
                  icon: const Icon(LucideIcons.refresh_cw, size: 18),
                  label: const Text('Importa adesso'),
                ),
              OutlinedButton(onPressed: () => Get.offNamed('/famiglia/impostazioni?scheda=drive'), child: const Text('Vai a Google Drive')),
            ]),
          ])),
      ),
    );
  }
}
