import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;
import 'package:cartellaviva/features/documenti/view/documento_form.dart';
import 'package:cartellaviva/features/documenti/view/indice_documento_card.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/features/scadenze/view/prossime_scadenze_card.dart';
import 'package:cartellaviva/features/scadenze/view/scadenza_form.dart';
import 'package:cartellaviva/features/scadenze/view/scadenze_proposte.dart';
import 'package:cartellaviva/features/documenti/view/condivisioni_screens.dart';
import 'package:cartellaviva/features/offline/view/offline_screen.dart';
import 'package:cartellaviva/widgets/visualizzatore_anteprima.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// Scheda di un documento: dati, file (apri/scarica), modifica, eliminazione,
/// testo letto e scadenze collegate.
class DocumentoScreen extends StatefulWidget {
  const DocumentoScreen({super.key});

  @override
  State<DocumentoScreen> createState() => _DocumentoScreenState();
}

class _DocumentoScreenState extends State<DocumentoScreen> {
  late Future<Documento> _documento;
  final GlobalKey<ProssimeScadenzeCardState> _scadenzeKey = GlobalKey<ProssimeScadenzeCardState>();
  bool _opening = false;

  SessionService get session => SessionService.to;

  @override
  void initState() {
    super.initState();
    _documento = _load();
  }

  Future<Documento> _load() {
    final tenantId = session.currentTenantId;
    final id = Get.parameters['id'];
    if (tenantId == null || id == null) {
      return Future.error(ApiException('Documento non trovato.'));
    }
    return DocumentoApi.documento(tenantId, id);
  }

  void _snack(String message, {bool error = false}) => mostraMessaggio(context, message, errore: error);

  Future<void> _apri(Documento d) async {
    setState(() => _opening = true);
    try {
      final bytes = await DocumentoApi.download(session.currentTenantId!, d.id);
      await saveOrOpenFile(bytes, d.nomeFile ?? '${d.titolo}.${d.isPdf ? 'pdf' : 'jpg'}', d.mimeType ?? 'application/octet-stream');
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } catch (e) {
      _snack(e.toString(), error: true);
    }
    if (mounted) setState(() => _opening = false);
  }

  Future<void> _modifica(Documento d) async {
    final tenantId = session.currentTenantId!;
    final saved = await showDocumentoForm(context, tenantId: tenantId, documento: d);
    if (saved != null) {
      setState(() => _documento = Future.value(saved));
      _snack('Documento aggiornato.');
    }
  }

  Future<void> _elimina(Documento d) async {
    final ok = await chiediConferma(
      context,
      titolo: 'Eliminare il documento?',
      testo: '"${d.titolo}" e il suo file saranno cancellati definitivamente. Le scadenze collegate restano, con l\'indicazione "documento eliminato".',
      azione: 'Elimina',
      distruttiva: true,
    );
    if (!ok) return;
    try {
      await DocumentoApi.delete(session.currentTenantId!, d.id);
      Get.offNamed('/documenti');
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Flexible(child: Testo.titleMedium('Documento', fontSize: 18, fontWeight: FontWeight.w500)),
                Briciole(voci: [Briciola(nome: 'Documenti', rotta: '/documenti'), Briciola(nome: 'Dettaglio', attiva: true)]),
              ],
            ),
          ),
          SizedBox(height: spazioPagina),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: FutureBuilder<Documento>(
              future: _documento,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return Padding(padding: EdgeInsets.all(40), child: const Center(child: CircularProgressIndicator()));
                }
                if (snapshot.hasError) {
                  return Scheda(paddingAll: 24, child: Row(children: [
                      Icon(LucideIcons.triangle_alert, color: context.colori.danger),
                      SizedBox(width: 12),
                      Expanded(child: Testo.bodyMedium(snapshot.error.toString())),
                      TextButton(onPressed: () => setState(() => _documento = _load()), child: const Text('Riprova')),
                    ]));
                }
                final d = snapshot.data!;
                final largo = MediaQuery.of(context).size.width >= 1200;
                final colonna = Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _details(d),
                    if (!largo) ...[SizedBox(height: 16), _contenuto(d, altezza: 560)],
                    SizedBox(height: 16),
                    IndiceDocumentoCard(
                      key: ValueKey('indice-${d.id}'),
                      tenantId: session.currentTenantId!,
                      documento: d,
                      puoRianalizzare: session.can('documento:write', resourceOwnerId: d.ownerId),
                    ),
                    if (d.scadenzaProposta != null && session.can('scadenza:manage', resourceOwnerId: session.me?.id)) ...[
                      SizedBox(height: 16),
                      Scheda(paddingAll: 20, child: PropostaTile(
                          proposta: d.scadenzaProposta!,
                          mostraDocumento: false,
                          onDecisa: () {
                            setState(() => _documento = _load());
                            _scadenzeKey.currentState?.reload();
                          },
                        )),
                    ],
                    SizedBox(height: 16),
                    ProssimeScadenzeCard(
                      key: _scadenzeKey,
                      documentoId: d.id,
                      onAggiungi: session.can('scadenza:manage', resourceOwnerId: session.me?.id)
                          ? () async {
                              final tenantId = session.currentTenantId;
                              if (tenantId == null) return;
                              final saved = await showScadenzaForm(context, tenantId: tenantId, documentoId: d.id, documentoTitolo: d.titolo);
                              if (saved != null) _scadenzeKey.currentState?.reload();
                            }
                          : null,
                    ),
                  ],
                );
                if (!largo) return colonna;
                // Schermo largo: dati a sinistra, il documento a destra.
                return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(flex: 2, child: colonna),
                  SizedBox(width: 16),
                  Expanded(flex: 3, child: _contenuto(d, altezza: 760)),
                ]);
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _details(Documento d) {
    final canWrite = session.can('documento:write', resourceOwnerId: d.ownerId);
    final canDelete = session.can('documento:delete', resourceOwnerId: d.ownerId);
    return Scheda(paddingAll: 20, child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(d.isImage ? LucideIcons.image : LucideIcons.file_text, color: context.colori.primary),
            SizedBox(width: 12),
            Expanded(child: Testo.titleMedium(d.titolo, fontWeight: FontWeight.w500)),
          ]),
          SizedBox(height: 16),
          _row('Categoria', d.categoria?.nome ?? '-'),
          _row('Riguarda', d.membri.isEmpty ? 'Tutta la famiglia' : d.membri.map((m) => m.nome).join(', ')),
          if (d.dataDocumento != null) _row('Data', formatDataBreve(d.dataDocumento!)),
          _row('File', '${d.nomeFile ?? '-'} · ${formatBytes(d.dimensioneBytes)}'),
          _row('Protezione', d.cifrato ? 'Cifrato sul server' : 'Non cifrato'),
          _row('Testo', d.ocrStatusLabel),
          if (d.ownerName != null) _row('Caricato da', '${d.ownerName!}${d.createdAt != null ? ' il ${formatDataBreve(d.createdAt!.toLocal())}' : ''}'),
          if (d.possibiliDuplicati.isNotEmpty) ...[
            SizedBox(height: 12),
            Testo.bodySmall('Attenzione: nella famiglia c\'è già un documento con lo stesso contenuto.', color: context.colori.warning),
          ],
          SizedBox(height: 16),
          Wrap(spacing: 12, runSpacing: 8, children: [
            FilledButton.icon(
              onPressed: _opening ? null : () => _apri(d),
              icon: _opening ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(LucideIcons.download, size: 18),
              label: const Text('Apri il file'),
            ),
            if (session.can('condivisione:manage', resourceOwnerId: session.me?.id))
              OutlinedButton.icon(
                onPressed: () => showCondividiDialog(context, documenti: [(id: d.id, titolo: d.titolo)]),
                icon: const Icon(LucideIcons.share_2, size: 18),
                label: const Text('Condividi'),
              ),
            if (canWrite) OutlinedButton.icon(onPressed: () => _modifica(d), icon: const Icon(LucideIcons.pencil, size: 18), label: const Text('Modifica')),
            if (canDelete)
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: context.colori.danger),
                onPressed: () => _elimina(d),
                icon: const Icon(LucideIcons.trash, size: 18),
                label: const Text('Elimina'),
              ),
          ]),
          OfflineSwitch(documento: d),
        ],
      ));
  }

  /// Schede "Anteprima" (il documento come appare; per i file Office il PDF
  /// ricavato dal server) e "Testo" (quello letto dall'OCR, selezionabile).
  Widget _contenuto(Documento d, {required double altezza}) {
    final tenantId = session.currentTenantId!;
    final canWrite = session.can('documento:write', resourceOwnerId: d.ownerId);
    final testo = (d.ocrText ?? '').trim();
    return Scheda(paddingAll: 0, child: DefaultTabController(
        length: 2,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TabBar(
            labelColor: context.colori.primary,
            indicatorColor: context.colori.primary,
            tabs: const [
              Tab(icon: Icon(LucideIcons.eye, size: 18), text: 'Anteprima'),
              Tab(icon: Icon(LucideIcons.scan_text, size: 18), text: 'Testo'),
            ],
          ),
          SizedBox(
            height: altezza,
            child: TabBarView(
              // Niente scorrimento laterale: dentro c'è lo zoom del documento.
              physics: const NeverScrollableScrollPhysics(),
              children: [
                VisualizzatoreAnteprima(
                  key: ValueKey('${d.id}-${d.anteprimaStato}'),
                  carica: () => DocumentoApi.anteprima(tenantId, d.id),
                  azioni: [
                    TextButton.icon(onPressed: _opening ? null : () => _apri(d), icon: const Icon(LucideIcons.external_link, size: 16), label: const Text('Apri il file')),
                  ],
                  onRigenera: canWrite ? () => DocumentoApi.rigeneraAnteprima(tenantId, d.id) : null,
                ),
                testo.isNotEmpty
                    ? SingleChildScrollView(padding: EdgeInsets.all(20), child: SelectableText(testo, style: const TextStyle(fontSize: 13, height: 1.5)))
                    : Center(
                        child: Padding(
                          padding: EdgeInsets.all(24),
                          child: Column(mainAxisSize: MainAxisSize.min, children: [
                            Icon(LucideIcons.scan_text, size: 36, color: context.colori.secondary),
                            SizedBox(height: 12),
                            Testo.titleSmall(d.ocrStatus == 'completato' ? 'Nessun testo trovato nel documento' : d.ocrStatusLabel, fontWeight: FontWeight.w600, textAlign: TextAlign.center),
                            if (d.ocrStatus == 'fallito' && canWrite) ...[
                              SizedBox(height: 16),
                              OutlinedButton.icon(
                                onPressed: () async {
                                  try {
                                    await DocumentoApi.rileggiTesto(tenantId, d.id);
                                    _snack('Lettura del testo rimessa in coda: ricarica la pagina tra poco.');
                                  } on ApiException catch (e) {
                                    _snack(e.message, error: true);
                                  }
                                },
                                icon: const Icon(LucideIcons.refresh_cw, size: 16),
                                label: const Text('Rileggi il testo'),
                              ),
                            ],
                          ]),
                        ),
                      ),
              ],
            ),
          ),
        ]),
      ));
  }

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 110, child: Testo.bodySmall(label, tenue: true)),
          Expanded(child: Testo.bodyMedium(value)),
        ]),
      );
}
