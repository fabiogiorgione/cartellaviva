import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/scadenze/controller/scadenze_controller.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/features/scadenze/view/calendario_mese.dart';
import 'package:cartellaviva/features/scadenze/view/scadenza_form.dart';
import 'package:cartellaviva/features/scadenze/view/scadenze_proposte.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';

/// Scadenziario: elenco raggruppato (scadute, prossimi 30 giorni, più avanti,
/// in verifica, fatte) e calendario mensile.
class ScadenzeScreen extends StatefulWidget {
  const ScadenzeScreen({super.key});

  @override
  State<ScadenzeScreen> createState() => _ScadenzeScreenState();
}

class _ScadenzeScreenState extends State<ScadenzeScreen> {
  final ScadenzeController controller = Get.put(ScadenzeController());

  Future<void> _apri({Scadenza? scadenza}) async {
    final id = controller.tenantId;
    if (id == null) return;
    final saved = await showScadenzaForm(context,
        tenantId: id, scadenza: scadenza, categorie: controller.categorie, persone: controller.persone);
    if (saved != null) {
      await controller.load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<ScadenzeController>(
        init: controller,
        builder: (c) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: EdgeInsets.symmetric(horizontal: spazioPagina),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Flexible(child: Testo.titleMedium('Scadenze', fontSize: 18, fontWeight: FontWeight.w500)),
                  Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Scadenze', attiva: true)]),
                ],
              ),
            ),
            SizedBox(height: spazioPagina),
            Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: _body(c)),
          ],
        ),
      ),
    );
  }

  Widget _body(ScadenzeController c) {
    if (c.loading) {
      return Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    }
    if (c.error != null) {
      return Scheda(paddingAll: 24, child: Row(children: [
        Icon(LucideIcons.triangle_alert, color: context.colori.danger),
        SizedBox(width: 12),
        Expanded(child: Testo.bodyMedium(c.error!)),
        TextButton(onPressed: c.load, child: Text('Riprova')),
      ]));
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (c.canCreate) ScadenzeProposteCard(onCambiate: c.load),
        Wrap(
          spacing: 12,
          runSpacing: 12,
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            SegmentedButton<VistaScadenze>(
              segments: const [
                ButtonSegment(value: VistaScadenze.elenco, label: Text('Elenco'), icon: Icon(LucideIcons.list)),
                ButtonSegment(value: VistaScadenze.calendario, label: Text('Calendario'), icon: Icon(LucideIcons.calendar)),
              ],
              selected: {c.vista},
              onSelectionChanged: (s) => c.setVista(s.first),
            ),
            if (c.canCreate)
              FilledButton.icon(onPressed: () => _apri(), icon: Icon(LucideIcons.plus, size: 18), label: Text('Nuova scadenza')),
          ],
        ),
        SizedBox(height: 16),
        c.vista == VistaScadenze.elenco ? _elenco(c) : _calendario(c),
      ],
    );
  }

  Widget _elenco(ScadenzeController c) {
    final gruppi = c.gruppi;
    if (c.scadenze.isEmpty) {
      return StatoVuoto(
        icona: LucideIcons.calendar_check,
        titolo: 'Nessuna scadenza',
        testo: 'Aggiungi bollo, revisione, rinnovi dei documenti, rate: ti avvisiamo per email (e sul telefono) prima della data. Quando carichi un documento con una data di scadenza, te la proponiamo noi.',
        azione: c.canCreate ? 'Aggiungi una scadenza' : null,
        onAzione: c.canCreate ? () => _apri() : null,
        guida: 'primi-passi-famiglia',
      );
    }
    const titoli = {
      GruppoScadenze.scadute: 'SCADUTE',
      GruppoScadenze.prossimi30: 'PROSSIMI 30 GIORNI',
      GruppoScadenze.piuAvanti: 'PIÙ AVANTI',
      GruppoScadenze.inVerifica: 'INVIATE, IN VERIFICA',
      GruppoScadenze.fatte: 'FATTE DI RECENTE',
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final g in GruppoScadenze.values)
          if (gruppi[g]!.isNotEmpty) ...[
            Padding(
              padding: EdgeInsets.only(top: 8, bottom: 8),
              child: Testo.labelMedium(titoli[g]!, fontWeight: FontWeight.w500, color: g == GruppoScadenze.scadute ? context.colori.danger : null, letterSpacing: 0.8),
            ),
            Scheda(paddingAll: 0, child: Column(children: [
                for (final (i, s) in gruppi[g]!.indexed) ...[
                  if (i > 0) Divider(height: 1),
                  _riga(c, s),
                ],
              ])),
          ],
      ],
    );
  }

  Widget _riga(ScadenzeController c, Scadenza s) {
    final colore = s.scaduta ? context.colori.danger : (s.giorniMancanti <= 7 && s.aperta ? context.colori.warning : context.colori.secondary);
    final dettagli = [
      s.quando,
      if (s.categoriaNome != null) s.categoriaNome!,
      if (s.membroNome != null) s.membroNome!,
      if (s.ricorrenzaLabel.isNotEmpty) s.ricorrenzaLabel,
      if (s.documentoRimosso) 'documento eliminato' else if (s.documentoTitolo != null) s.documentoTitolo!,
    ].join(' · ');
    final manage = c.canManage(s);
    return ListTile(
      contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      leading: Container(
        width: 46,
        padding: EdgeInsets.symmetric(vertical: 4),
        decoration: BoxDecoration(color: colore.withAlpha(30), borderRadius: BorderRadius.circular(6)),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(s.data.day.toString().padLeft(2, '0'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          Text(_mese(s.data.month), style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600)),
        ]),
      ),
      title: Text(s.titolo, style: TextStyle(fontWeight: FontWeight.w500, decoration: s.stato == 'fatta' ? TextDecoration.lineThrough : null)),
      subtitle: Text(dettagli),
      // Le scadenze delle richieste degli enti portano alla richiesta (non si modificano qui).
      onTap: s.richiestaId != null ? () => Get.toNamed('/richieste/${s.richiestaId}') : (manage ? () => _apri(scadenza: s) : null),
      trailing: s.aperta && manage
          ? IconButton(
              tooltip: 'Segna come fatta',
              icon: c.busy.contains(s.id) ? SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Icon(LucideIcons.check),
              onPressed: c.busy.contains(s.id) ? null : () => c.fatta(s),
            )
          : null,
    );
  }

  Widget _calendario(ScadenzeController c) {
    return Scheda(paddingAll: 12, child: CalendarioMese<Scadenza>(
        eventi: [
          for (final s in c.scadenze)
            EventoCalendario(
              giorno: s.data,
              titolo: s.titolo,
              colore: s.stato == 'fatta' ? context.colori.success : (s.scaduta ? context.colori.danger : context.colori.primary),
              valore: s,
            ),
        ],
        onEvento: (s) {
          if (c.canManage(s)) _apri(scadenza: s);
        },
      ));
  }

  String _mese(int m) => const ['GEN', 'FEB', 'MAR', 'APR', 'MAG', 'GIU', 'LUG', 'AGO', 'SET', 'OTT', 'NOV', 'DIC'][m - 1];
}
