import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/richieste/api/richieste_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/layout/layout.dart';

/// Richieste degli enti collegati: da fare, in verifica, concluse.
class RichiesteScreen extends StatefulWidget {
  const RichiesteScreen({super.key});

  @override
  State<RichiesteScreen> createState() => _RichiesteScreenState();
}

class _RichiesteScreenState extends State<RichiesteScreen> {
  static const filtri = [('da_fare', 'Da fare'), ('in_verifica', 'In verifica'), ('concluse', 'Concluse')];
  String filtro = 'da_fare';
  late Future<List<RichiestaFamiglia>> _future = _load();

  Future<List<RichiestaFamiglia>> _load() {
    final id = SessionService.to.currentTenantId;
    if (id == null) return Future.error(ApiException('Seleziona una famiglia.'));
    return RichiesteApi.list(id, stato: filtro);
  }

  void _setFiltro(String f) => setState(() {
        filtro = f;
        _future = _load();
      });

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Flexible(child: Testo.titleMedium('Richieste dagli enti', fontSize: 18, fontWeight: FontWeight.w500)),
            Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Richieste', attiva: true)]),
          ]),
        ),
        SizedBox(height: spazioPagina),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Align(
              alignment: Alignment.centerLeft,
              child: SegmentedButton<String>(
                segments: [for (final f in filtri) ButtonSegment(value: f.$1, label: Text(f.$2))],
                selected: {filtro},
                onSelectionChanged: (s) => _setFiltro(s.first),
              ),
            ),
            SizedBox(height: 16),
            FutureBuilder<List<RichiestaFamiglia>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
                }
                if (snap.hasError) {
                  return Scheda(paddingAll: 24, child: Testo.bodyMedium(snap.error.toString()));
                }
                final richieste = snap.data!;
                if (richieste.isEmpty) {
                  return Scheda(paddingAll: 24, child: Testo.bodyMedium(filtro == 'da_fare'
                        ? 'Nessuna richiesta da fare. Quando una scuola o un\'associazione collegata ti chiede una firma o un documento, la trovi qui.'
                        : 'Nessuna richiesta.'));
                }
                return Column(children: [for (final r in richieste) _card(r)]);
              },
            ),
            SizedBox(height: 12),
            Testo.bodySmall('Le richieste con una data compaiono anche nelle tue Scadenze.', tenue: true),
          ]),
        ),
      ]),
    );
  }

  Widget _card(RichiestaFamiglia r) {
    final colore = switch (r.stato) {
      'da_rifirmare' || 'rifiutata' => context.colori.danger,
      'da_verificare' => context.colori.info,
      'completata' => context.colori.success,
      _ => context.colori.warning,
    };
    final firmano = r.referenti.map((x) => x.nome).join(' e ');
    return Scheda(margin: EdgeInsets.only(bottom: 12), paddingAll: 16, onTap: () async {
        await Get.toNamed('/richieste/${r.id}');
        setState(() => _future = _load());
      }, child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(r.tipo == 'firma' ? LucideIcons.pen_line : (r.tipo == 'invio' ? LucideIcons.upload : LucideIcons.eye), color: colore),
        SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                padding: EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(color: colore.withAlpha(30), borderRadius: BorderRadius.circular(4)),
                child: Testo.labelSmall(r.statoLabel, color: colore, fontWeight: FontWeight.w600),
              ),
              SizedBox(width: 8),
              Expanded(child: Testo.bodySmall(r.enteNome, tenue: true, overflow: TextOverflow.ellipsis)),
            ]),
            SizedBox(height: 6),
            Testo.bodyMedium(r.titolo, fontWeight: FontWeight.w500),
            if (r.motivoEnte != null && r.stato == 'da_rifirmare') ...[SizedBox(height: 4), Testo.bodySmall('"${r.motivoEnte}"', color: context.colori.danger)],
            SizedBox(height: 4),
            Testo.bodySmall([
              'Per $firmano · ${r.iscritto}',
              if (r.entroIl != null) '${r.scaduta ? 'scaduta il' : 'entro il'} ${formatDataBreve(r.entroIl!)}',
            ].join(' · '), tenue: true),
          ]),
        ),
        const Icon(LucideIcons.chevron_right, size: 18),
      ]));
  }
}
