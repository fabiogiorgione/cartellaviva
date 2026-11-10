import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/griglia.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/documenti/view/documenti_screen.dart' show hexColor;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/features/scadenze/view/prossime_scadenze_card.dart';
import 'package:cartellaviva/features/scadenze/view/scadenze_proposte.dart';

/// Home della famiglia: azioni rapide, prossime scadenze, ultimi documenti.
class FamigliaDashboardScreen extends StatefulWidget {
  const FamigliaDashboardScreen({super.key});

  @override
  State<FamigliaDashboardScreen> createState() => _FamigliaDashboardScreenState();
}

class _FamigliaDashboardScreenState extends State<FamigliaDashboardScreen> {
  SessionService get session => SessionService.to;
  Future<List<Documento>>? _ultimi;
  final _scadenzeKey = GlobalKey<ProssimeScadenzeCardState>();

  @override
  void initState() {
    super.initState();
    final id = session.currentTenantId;
    if (id != null && session.can('documento:read')) {
      _ultimi = DocumentoApi.list(id, perPage: 6);
    }
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await mostraIntroSeServe(context, ente: false);
      if (mounted) await mostraPromemoria2faSeServe(context);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<SessionService>(builder: (s) {
        final nome = s.me?.firstName ?? '';
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: Testo.titleMedium(nome.isEmpty ? 'Ciao' : 'Ciao $nome', fontSize: 18, fontWeight: FontWeight.w500),
          ),
          SizedBox(height: 4),
          Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: Testo.bodySmall(s.currentMembership?.tenantName ?? '', tenue: true)),
          SizedBox(height: spazioPagina),
          Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: _azioni(s)),
          if (s.currentMembership?.isOwner == true || s.currentMembership?.roleSlug == 'member') ...[
            SizedBox(height: spazioPagina),
            Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: const PrimiPassiCard()),
          ],
          SizedBox(height: spazioPagina / 2),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina / 2),
            child: Griglia(spaziatura: spazioPagina, celle: [
              if (s.can('scadenza:read'))
                CellaGriglia(colonne: 6, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (s.can('scadenza:manage', resourceOwnerId: s.me?.id)) ScadenzeProposteCard(onCambiate: () => _scadenzeKey.currentState?.reload()),
                    ProssimeScadenzeCard(key: _scadenzeKey),
                  ])),
              if (_ultimi != null) CellaGriglia(colonne: 6, child: _ultimiDocumenti()),
            ]),
          ),
        ]);
      }),
    );
  }

  Widget _azioni(SessionService s) {
    final azioni = <(IconData, String, String)>[
      if (s.can('documento:create')) (LucideIcons.scan_line, 'Scansiona', '/documenti/nuovo'),
      if (s.can('documento:read')) (LucideIcons.files, 'Documenti', '/documenti'),
      if (s.can('scadenza:read')) (LucideIcons.calendar_clock, 'Scadenze', '/scadenze'),
      if (s.can('import:manage')) (LucideIcons.cloud_download, 'Google Drive', '/famiglia/impostazioni?scheda=drive'),
    ];
    return Wrap(spacing: 12, runSpacing: 12, children: [
      for (final a in azioni)
        SizedBox(
          width: 150,
          child: Scheda(onTap: () => Get.toNamed(a.$3), paddingAll: 16, child: Column(children: [
              Icon(a.$1, color: context.colori.primary, size: 26),
              SizedBox(height: 8),
              Testo.labelLarge(a.$2, fontWeight: FontWeight.w500),
            ])),
        ),
    ]);
  }

  Widget _ultimiDocumenti() {
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Testo.titleSmall('Ultimi documenti', fontWeight: FontWeight.w600)),
          TextButton(onPressed: () => Get.toNamed('/documenti'), child: const Text('Tutti')),
        ]),
        FutureBuilder<List<Documento>>(
          future: _ultimi,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()));
            }
            if (snap.hasError) {
              return Testo.bodySmall(snap.error is ApiException ? (snap.error as ApiException).message : 'Errore di caricamento.', tenue: true);
            }
            final docs = snap.data!;
            if (docs.isEmpty) {
              return Testo.bodySmall('Nessun documento ancora: scansionane uno o caricalo dalla pagina Documenti.', tenue: true);
            }
            return Column(children: [
              for (final d in docs)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  onTap: () => Get.toNamed('/documento/${d.id}'),
                  leading: Icon(d.isPdf ? LucideIcons.file_text : LucideIcons.image, color: hexColor(d.categoria?.colore ?? '#64748b')),
                  title: Text(d.titolo, maxLines: 1, overflow: TextOverflow.ellipsis),
                  subtitle: Text([d.categoria?.nome ?? '', if (d.createdAt != null) formatDataBreve(d.createdAt!.toLocal())].join(' · ')),
                ),
            ]);
          },
        ),
      ]));
  }
}
