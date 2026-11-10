import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/features/idee/api/idee_api.dart';
import 'package:cartellaviva/features/portale/api/portale_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/idee/model/idea.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/lista_crud.dart';
import 'package:cartellaviva/widgets/schede.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

part 'portale_gestione.dart';
part 'portale_liste.dart';
part 'portale_lista_tenant.dart';
part 'portale_lista_utenti.dart';
part 'portale_idee_screen.dart';
part 'portale_dialoghi_utente.dart';
part 'portale_dettaglio_tenant.dart';
part 'portale_procedure.dart';
part 'portale_registro_team.dart';

// ---------------------------------------------------------------------------
// Struttura comune
// ---------------------------------------------------------------------------

/// Pagina del portale con controllo del livello (il backend ricontrolla sempre).
class _PaginaPortale extends StatelessWidget {
  final String titolo;
  final bool soloSupporto;
  final bool soloSuperadmin;
  final Widget child;
  final List<Widget> azioni;

  const _PaginaPortale({required this.titolo, required this.child, this.soloSupporto = false, this.soloSuperadmin = false, this.azioni = const []});

  @override
  Widget build(BuildContext context) {
    final me = SessionService.to.me;
    final ammesso = me != null && me.isPortale && (!soloSupporto || me.isSupporto) && (!soloSuperadmin || me.platformRole == 'superadmin');
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, spacing: 12, runSpacing: 8, children: [
            Testo.titleMedium('Portale · $titolo', fontSize: 18, fontWeight: FontWeight.w500),
            Row(mainAxisSize: MainAxisSize.min, children: azioni),
          ]),
          SizedBox(height: spazioPagina),
          if (ammesso) child else Scheda(paddingAll: 24, child: Testo.bodyMedium('Pagina riservata al team del portale con il ruolo adatto.')),
        ]),
      ),
    );
  }
}

String _data(dynamic iso) {
  final d = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;
  return d == null ? '-' : formatDataBreve(d);
}

String _dataOra(dynamic iso) {
  final d = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;
  return d == null ? '-' : '${formatDataBreve(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
}

void _snack(BuildContext context, String m) => mostraMessaggio(context, m);

// ---------------------------------------------------------------------------
// Salute e da fare
// ---------------------------------------------------------------------------

class PortaleSaluteScreen extends StatefulWidget {
  const PortaleSaluteScreen({super.key});

  @override
  State<PortaleSaluteScreen> createState() => _PortaleSaluteScreenState();
}

class _PortaleSaluteScreenState extends State<PortaleSaluteScreen> {
  late Future<Map<String, dynamic>> _future = PortaleApi.salute();

  void _reload() => setState(() => _future = PortaleApi.salute());

  static const _azioni = {
    'enti': ('Verifica', '/admin/enti'),
    'recuperi': ('Apri', '/admin/recuperi'),
    'senza_proprietario': ('Gestisci', '/admin/tenant'),
    'eliminazioni': ('Dettagli', '/admin/tenant'),
    'idee': ('Apri', '/admin/idee'),
  };

  @override
  Widget build(BuildContext context) {
    return _PaginaPortale(
      titolo: 'Salute e da fare',
      azioni: [IconButton(tooltip: 'Aggiorna', onPressed: _reload, icon: const Icon(LucideIcons.refresh_cw))],
      child: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
          final s = snap.data!;
          final c = Map<String, dynamic>.from(s['componenti'] as Map);
          final worker = c['worker'] as Map;
          final ocr = c['ocr'] as Map;
          final ai = c['ai'] as Map;
          final chiave = c['chiave'] as Map;
          final numeri = Map<String, dynamic>.from(s['numeri'] as Map);
          final daFare = (s['daFare'] as List).cast<Map>();
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Testo.bodySmall('Aggiornato alle ${_dataOra(s['aggiornatoIl'])}', tenue: true),
            SizedBox(height: 12),
            Wrap(spacing: 12, runSpacing: 12, children: [
              numeroCard('API', 'In funzione'),
              numeroCard('Coda del worker', '${worker['inAttesa']} in attesa', nota: '${worker['falliti']} nei falliti'),
              numeroCard('Lettura testo', '${ocr['fallite24h']} fallite / 24 h', nota: 'su ${ocr['documenti24h']} documenti · ${ocr['inCoda']} in coda'),
              numeroCard('Modello AI', switch (ai['stato']) {
                'pronto' => 'Pronto',
                'disattivato' => 'Disattivato',
                'modello_mancante' => 'Modello mancante',
                _ => 'Non raggiungibile',
              }, nota: ai['modello'] as String?),
              if (c['convertitore'] is Map)
                numeroCard('Convertitore documenti', switch ((c['convertitore'] as Map)['stato']) {
                  'pronto' => 'Pronto',
                  'non_configurato' => 'Non configurato',
                  _ => 'Non raggiungibile',
                }, nota: '${(c['convertitore'] as Map)['fallite24h']} anteprime fallite / 24 h'),
              numeroCard('Master key', chiave['ultimoBackup'] == null ? 'Backup mai fatto' : 'Backup del ${_data(chiave['ultimoBackup'])}',
                  nota: 'v${chiave['versioneCorrente']} · impronta ${chiave['improntaOk'] == true ? 'OK' : 'DIVERSA'}'),
              numeroCard('Import in errore', '${c['import']['inErrore']}'),
            ]),
            SizedBox(height: 20),
            Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Testo.titleSmall('Da fare', fontWeight: FontWeight.w600),
                if (daFare.isEmpty) Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall('Niente da fare.', tenue: true)),
                for (final v in daFare)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(v['urgente'] == true ? LucideIcons.triangle_alert : LucideIcons.circle_dot, color: v['urgente'] == true ? context.colori.danger : context.colori.primary),
                    title: Text(v['titolo'] as String),
                    subtitle: Text(v['dettaglio'] as String? ?? ''),
                    trailing: _azioni[v['tipo']] == null || (v['tipo'] != 'idee' && !(SessionService.to.me?.isSupporto ?? false))
                        ? null
                        : TextButton(onPressed: () => Get.toNamed(_azioni[v['tipo']]!.$2), child: Text(_azioni[v['tipo']]!.$1)),
                  ),
              ])),
            SizedBox(height: 20),
            Wrap(spacing: 12, runSpacing: 12, children: [
              numeroCard('Famiglie', '${numeri['famiglie']}'),
              numeroCard('Enti verificati', '${numeri['entiVerificati']}'),
              numeroCard('Iscritti negli enti', '${numeri['iscritti']}'),
              numeroCard('Utenti attivi 30 gg', '${numeri['utentiAttivi30']}'),
              numeroCard('Spazio documenti', formatBytes((numeri['spazioDocumenti'] as num).toInt())),
              numeroCard('Accessi negati 24 h', '${numeri['accessiNegati24h']}'),
            ]),
            SizedBox(height: 12),
            Testo.bodySmall('Il portale vede solo metadati e conteggi: nessun documento delle famiglie.', tenue: true),
          ]);
        },
      ),
    );
  }
}

// Famiglie ed enti, utenti: vedi portale_liste.dart.
