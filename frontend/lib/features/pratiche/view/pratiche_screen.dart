import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/features/pratiche/view/avvia_pratica.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_widgets.dart' show Pillola;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/navigazione.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/griglia.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/layout/layout.dart';

/// Frase di stato della scheda Pagamenti: "2 da pagare · 310,40 €, la prima scade il 30/11".
String riepilogoPagamenti(ElencoPagamenti e) {
  final n = e.pagamenti.length;
  if (n == 0) return 'Niente da pagare.';
  final prima = e.pagamenti.where((p) => p.scadenza != null).map((p) => p.scadenza!).fold<DateTime?>(null, (a, d) => a == null || d.isBefore(a) ? d : a);
  final scade = prima == null ? '' : ', la prima scade il ${prima.day.toString().padLeft(2, '0')}/${prima.month.toString().padLeft(2, '0')}';
  return '$n da pagare · ${formatEuro(e.totaleDaPagare)}$scade';
}

/// Pratiche (`/pratiche`): una scheda per pratica con lo stato in breve e una sola azione.
class PraticheScreen extends StatefulWidget {
  const PraticheScreen({super.key});

  @override
  State<PraticheScreen> createState() => _PraticheScreenState();
}

class _PraticheScreenState extends State<PraticheScreen> {
  SessionService get session => SessionService.to;
  late Future<ElencoPagamenti> _pagamenti;
  Future<({List<Pratica> pratiche, bool puoAvviare})>? _pratiche;

  @override
  void initState() {
    super.initState();
    _pagamenti = _carica();
    _pratiche = _caricaPratiche();
  }

  Future<({List<Pratica> pratiche, bool puoAvviare})>? _caricaPratiche() {
    final id = session.currentTenantId;
    if (id == null || !session.can('pratica:read')) return null;
    return PraticheApi.elenco(id);
  }

  Future<void> _apri(Pratica p) async {
    await Get.toNamed('/pratiche/pratica', parameters: {'id': p.id});
    if (mounted) _ricarica();
  }

  void _ricarica() => setState(() {
        _pratiche = _caricaPratiche();
      });

  Future<ElencoPagamenti> _carica() {
    final id = session.currentTenantId;
    if (id == null) return Future.error(ApiException('Seleziona una famiglia.'));
    return PagamentiApi.list(id, stato: 'da_pagare');
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Flexible(child: Testo.titleMedium('Pratiche', fontSize: 18, fontWeight: FontWeight.w500)),
            Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Pratiche', attiva: true)]),
          ]),
        ),
        SizedBox(height: 4),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: const Testo.bodySmall('Le cose da sbrigare che nascono dai documenti. L\'AI propone, tu confermi.', tenue: true),
        ),
        SizedBox(height: spazioPagina),
        // Prima le pratiche (730, ISEE), poi i pagamenti. Le richieste degli enti hanno la loro voce nel menu.
        if (_pratiche != null) ...[
          Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: _pratiche730()),
          SizedBox(height: spazioPagina),
        ],
        if (session.can('pagamento:read'))
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina / 2),
            child: Griglia(spaziatura: spazioPagina, celle: [CellaGriglia(colonne: 4, child: _schedaPagamenti())]),
          ),
        SizedBox(height: spazioPagina),
      ]),
    );
  }

  Widget _intestazione(IconData icona, String titolo) => Row(children: [
        Icon(icona, size: 20, color: context.colori.primary),
        const SizedBox(width: 10),
        Expanded(child: Testo.titleSmall(titolo, fontWeight: FontWeight.w600)),
      ]);

  Widget _schedaPagamenti() => Scheda(
        paddingAll: 20,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _intestazione(LucideIcons.receipt, 'Pagamenti'),
          const SizedBox(height: 12),
          FutureBuilder<ElencoPagamenti>(
            future: _pagamenti,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: LinearProgressIndicator());
              }
              if (snap.hasError) {
                final msg = snap.error is ApiException ? (snap.error as ApiException).message : 'Errore di caricamento.';
                return Row(children: [
                  Expanded(child: Testo.bodyMedium(msg)),
                  TextButton(onPressed: () => setState(() => _pagamenti = _carica()), child: const Text('Riprova')),
                ]);
              }
              return Testo.bodyMedium(riepilogoPagamenti(snap.data!), fontWeight: FontWeight.w500);
            },
          ),
          const SizedBox(height: 16),
          Align(alignment: Alignment.centerLeft, child: FilledButton(onPressed: () => apriDalMenu('/pratiche/pagamenti'), child: const Text('Vedi i pagamenti'))),
        ]),
      );

  Widget _pratiche730() => FutureBuilder<({List<Pratica> pratiche, bool puoAvviare})>(
        future: _pratiche,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const LinearProgressIndicator();
          if (snap.hasError) {
            final msg = snap.error is ApiException ? (snap.error as ApiException).message : 'Errore di caricamento.';
            return Row(children: [Expanded(child: Testo.bodyMedium(msg)), TextButton(onPressed: _ricarica, child: const Text('Riprova'))]);
          }
          final r = snap.data!;
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(child: Testo.titleSmall('730, ISEE e dichiarazioni', fontWeight: FontWeight.w600)),
              if (r.puoAvviare)
                FilledButton.icon(
                  onPressed: () async {
                    await avviaPratica(context, session.currentTenantId!, r.pratiche);
                    if (mounted) _ricarica();
                  },
                  icon: const Icon(LucideIcons.plus, size: 18),
                  label: const Text('Avvia una pratica'),
                ),
            ]),
            const SizedBox(height: 4),
            const Testo.bodySmall('Spese da detrarre, redditi e patrimonio, raccolti dai documenti per persona. L\'app raccoglie e controlla; i calcoli li fanno il CAF, la precompilata o l\'INPS.', tenue: true),
            const SizedBox(height: 12),
            if (r.pratiche.isEmpty)
              Scheda(
                paddingAll: 20,
                child: Testo.bodyMedium(r.puoAvviare ? 'Nessuna pratica ancora. Avvia il 730 o l\'ISEE: cerchiamo nei documenti scontrini, fatture sanitarie, CU, estratti conto e gli altri documenti che servono.' : 'Nessuna pratica ancora.'),
              ),
            for (final p in r.pratiche)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Scheda(
                  paddingAll: 0,
                  child: InkWell(
                    onTap: () => _apri(p),
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Row(children: [
                        Icon(LucideIcons.file_spreadsheet, size: 22, color: context.colori.primary),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                              Testo.titleSmall(p.titolo, fontWeight: FontWeight.w600),
                              Pillola(statiPratica[p.stato] ?? p.stato, colore: p.inCorso ? context.colori.primary : ColoreStato.successo),
                            ]),
                            const SizedBox(height: 4),
                            Testo.bodyMedium(riepilogoPratica(p, formatEuro), fontWeight: FontWeight.w500),
                            Testo.bodySmall(p.persone.map((x) => x.nome).join(', '), tenue: true),
                          ]),
                        ),
                        const Icon(LucideIcons.chevron_right, size: 18),
                      ]),
                    ),
                  ),
                ),
              ),
          ]);
        },
      );
}
