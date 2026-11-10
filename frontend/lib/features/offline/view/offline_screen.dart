import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/offline/offline_store.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;

/// Salva (o toglie) un documento sul telefono. True se ora è offline.
Future<bool> impostaOffline(Documento d, bool attiva) async {
  final userId = SessionService.to.me?.id;
  final tenantId = SessionService.to.currentTenantId;
  if (userId == null || tenantId == null) return false;
  await OfflineStore.utente(userId);
  if (!attiva) {
    await OfflineStore.rimuovi(userId, d.id);
    return false;
  }
  final bytes = await DocumentoApi.download(tenantId, d.id);
  await OfflineStore.salva(
    userId,
    DocumentoOffline(
      id: d.id,
      titolo: d.titolo,
      nomeFile: d.nomeFile ?? '${d.titolo}.${d.isPdf ? 'pdf' : 'jpg'}',
      mimeType: d.mimeType ?? 'application/octet-stream',
      categoria: d.categoria?.nome,
      dimensione: bytes.length,
      salvatoIl: DateTime.now(),
    ),
    bytes,
  );
  return true;
}

/// Interruttore "Disponibile offline" per la scheda documento (solo Android/iOS).
class OfflineSwitch extends StatefulWidget {
  final Documento documento;

  const OfflineSwitch({super.key, required this.documento});

  @override
  State<OfflineSwitch> createState() => _OfflineSwitchState();
}

class _OfflineSwitchState extends State<OfflineSwitch> {
  bool? attivo;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    final userId = SessionService.to.me?.id;
    if (userId != null) {
      OfflineStore.contiene(userId, widget.documento.id).then((v) {
        if (mounted) setState(() => attivo = v);
      }, onError: (_) {});
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!OfflineStore.supportato) return const SizedBox.shrink();
    return SwitchListTile(
      contentPadding: EdgeInsets.zero,
      secondary: const Icon(LucideIcons.smartphone),
      title: const Text('Disponibile offline su questo telefono'),
      subtitle: const Text('Cifrato sul telefono; si apre con impronta, volto o PIN anche senza rete.'),
      value: attivo ?? false,
      onChanged: busy || attivo == null
          ? null
          : (v) async {
              setState(() => busy = true);
              try {
                attivo = await impostaOffline(widget.documento, v);
              } on ApiException catch (e) {
                Get.snackbar('Offline', e.message, snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16));
              } catch (e) {
                Get.snackbar('Offline', '$e', snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16));
              }
              if (mounted) setState(() => busy = false);
            },
    );
  }
}

/// /offline: elenco dei documenti salvati sul telefono, dopo lo sblocco.
/// Raggiungibile anche dal login (senza rete o con la sessione scaduta).
class OfflineScreen extends StatefulWidget {
  const OfflineScreen({super.key});

  @override
  State<OfflineScreen> createState() => _OfflineScreenState();
}

class _OfflineScreenState extends State<OfflineScreen> {
  String? userId;
  List<DocumentoOffline>? documenti;
  String? errore;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    _apri();
  }

  Future<void> _apri() async {
    setState(() {
      errore = null;
      busy = true;
    });
    try {
      await OfflineStore.pulisciTemporanei();
      userId = await OfflineStore.utente(SessionService.to.me?.id);
      if (userId == null) {
        documenti = [];
      } else if (await OfflineStore.sblocca()) {
        documenti = await OfflineStore.elenco(userId!);
      } else {
        errore = 'Sblocco non riuscito. Serve un blocco schermo (impronta, volto o PIN) attivo sul telefono.';
      }
    } catch (e) {
      errore = '$e';
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _mostra(DocumentoOffline d) async {
    if (!await OfflineStore.sblocca()) return;
    try {
      await OfflineStore.apri(d.nomeFile, d.mimeType, await OfflineStore.leggi(userId!, d.id));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Impossibile aprire il file: $e')));
    }
  }

  Future<void> _togli(DocumentoOffline d) async {
    await OfflineStore.rimuovi(userId!, d.id);
    documenti = await OfflineStore.elenco(userId!);
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final lista = documenti;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Documenti offline'),
        actions: [IconButton(tooltip: 'Blocca', icon: const Icon(LucideIcons.lock), onPressed: () {
          OfflineStore.blocca();
          Get.back();
        })],
      ),
      body: SafeArea(
        child: ListView(padding: EdgeInsets.all(16), children: [
          if (!OfflineStore.supportato)
            Scheda(paddingAll: 20, child: Testo.bodyMedium('I documenti offline sono disponibili nell\'app per Android e iPhone.'))
          else if (busy)
            const Center(child: CircularProgressIndicator())
          else if (errore != null) ...[
            Scheda(paddingAll: 20, child: Testo.bodyMedium(errore!)),
            SizedBox(height: 12),
            FilledButton.icon(onPressed: _apri, icon: const Icon(Icons.fingerprint), label: const Text('Riprova a sbloccare')),
          ] else if (lista == null || lista.isEmpty)
            Scheda(paddingAll: 20, child: Testo.bodyMedium('Nessun documento salvato sul telefono. Apri un documento e attiva "Disponibile offline": ad esempio carta d\'identità, tessera sanitaria, patente, polizza auto.'))
          else ...[
            Testo.bodySmall('Cifrati sul telefono. Restano qui finché non li togli o esci dall\'account.', tenue: true),
            SizedBox(height: 8),
            for (final d in lista)
              Card(
                child: ListTile(
                  onTap: () => _mostra(d),
                  leading: Icon(d.mimeType == 'application/pdf' ? LucideIcons.file_text : LucideIcons.image),
                  title: Text(d.titolo),
                  subtitle: Text([if (d.categoria != null) d.categoria!, formatBytes(d.dimensione), 'salvato il ${formatDataBreve(d.salvatoIl)}'].join(' · ')),
                  trailing: IconButton(tooltip: 'Togli dal telefono', icon: const Icon(LucideIcons.trash), onPressed: () => _togli(d)),
                ),
              ),
          ],
        ]),
      ),
    );
  }
}
