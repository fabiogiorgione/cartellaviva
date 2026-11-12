import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/controller/pagamenti_controller.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_form.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_widgets.dart';
import 'package:cartellaviva/features/pratiche/view/segna_pagato.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/schede.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Pagamenti (`/pratiche/pagamenti`): "Da pagare" e "Pagati".
class PagamentiScreen extends StatefulWidget {
  const PagamentiScreen({super.key});

  @override
  State<PagamentiScreen> createState() => _PagamentiScreenState();
}

class _PagamentiScreenState extends State<PagamentiScreen> {
  // Se il controller c'era già (pagina aperta prima e ancora nella pila), i dati
  // possono essere vecchi: un pagamento confermato da "Da confermare" non
  // comparirebbe. Allora si ricarica in silenzio.
  final bool _giaInMemoria = Get.isRegistered<PagamentiController>();
  late final PagamentiController controller = Get.put(PagamentiController());

  @override
  void initState() {
    super.initState();
    if (_giaInMemoria) controller.load(silenzioso: true);
  }

  bool get _telefono => MediaQuery.sizeOf(context).width < larghezzaTelefono;

  Future<void> _nuovo() async {
    final id = controller.tenantId;
    if (id == null) return;
    final salvato = await mostraPagamentoForm(context, tenantId: id);
    if (salvato != null) {
      if (mounted) mostraMessaggio(context, 'Pagamento aggiunto.');
      await controller.load(silenzioso: true);
    }
  }

  Future<void> _modifica(Pagamento p) async {
    final id = controller.tenantId;
    if (id == null) return;
    final salvato = await mostraPagamentoForm(context, tenantId: id, pagamento: p);
    if (salvato != null) await controller.load(silenzioso: true);
  }

  Future<void> _segnaPagato(Pagamento p) async {
    final id = controller.tenantId;
    if (id == null) return;
    final esito = await chiediPagato(context, tenantId: id, pagamento: p);
    if (esito == null) return;
    await _prova(p, (t) => PagamentiApi.pagato(t, p.id, pagatoIl: esito.data, ricevutaDocumentoId: esito.ricevutaId), 'Segnato come pagato.');
  }

  Future<void> _tornaDaPagare(Pagamento p) => _prova(p, (t) => PagamentiApi.daPagare(t, p.id), 'Di nuovo da pagare.');

  Future<void> _elimina(Pagamento p) async {
    final si = await chiediConferma(context,
        titolo: 'Eliminare il pagamento?',
        testo: '«${p.titolo}» sparisce dall\'elenco${p.scadenzaId != null && !p.pagato ? ', insieme al promemoria nelle scadenze' : ''}. Il documento resta nell\'archivio.',
        azione: 'Elimina',
        distruttiva: true);
    if (!si) return;
    await _prova(p, (t) => PagamentiApi.delete(t, p.id), 'Pagamento eliminato.');
  }

  Future<void> _prova(Pagamento p, Future<Object?> Function(String tenantId) azione, String messaggio) async {
    try {
      await controller.esegui(p, (t) async => await azione(t));
      if (mounted) mostraMessaggio(context, messaggio);
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder<PagamentiController>(
        init: controller,
        builder: (c) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: EdgeInsets.symmetric(horizontal: spazioPagina),
            child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Flexible(child: Testo.titleMedium('Pagamenti', fontSize: 18, fontWeight: FontWeight.w500)),
              Briciole(voci: [Briciola(nome: 'Pratiche', rotta: '/pratiche'), Briciola(nome: 'Pagamenti', attiva: true)]),
            ]),
          ),
          SizedBox(height: spazioPagina),
          Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: _corpo(c)),
          SizedBox(height: spazioPagina),
        ]),
      ),
    );
  }

  Widget _corpo(PagamentiController c) {
    if (c.loading) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    if (c.error != null) return erroreCard(c.error!, c.load);
    final telefono = _telefono;
    final selettore = SegmentedButton<VistaPagamenti>(
      showSelectedIcon: false,
      segments: [
        ButtonSegment(value: VistaPagamenti.daPagare, label: Text('Da pagare (${c.daPagare.length})')),
        ButtonSegment(value: VistaPagamenti.pagati, label: Text('Pagati (${c.pagati.length})')),
      ],
      selected: {c.vista},
      onSelectionChanged: (s) => c.setVista(s.first),
    );
    final nuovo = c.canManage
        ? FilledButton.icon(onPressed: _nuovo, icon: const Icon(LucideIcons.plus, size: 18), label: Text(telefono ? 'Nuovo' : 'Nuovo pagamento'))
        : null;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (telefono)
        Row(children: [Expanded(child: selettore), if (nuovo != null) ...[const SizedBox(width: 8), nuovo]])
      else
        Wrap(spacing: 12, runSpacing: 12, alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, children: [selettore, if (nuovo != null) nuovo]),
      const SizedBox(height: 16),
      if (c.vista == VistaPagamenti.daPagare && c.daPagare.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Testo.bodyMedium('Da pagare in tutto: ${formatEuro(c.totaleDaPagare)}', fontWeight: FontWeight.w500),
        ),
      if (c.visibili.isEmpty) _vuoto(c) else for (final p in c.visibili) Padding(padding: const EdgeInsets.only(bottom: 12), child: _scheda(c, p)),
    ]);
  }

  Widget _vuoto(PagamentiController c) {
    final daPagare = c.vista == VistaPagamenti.daPagare;
    return StatoVuoto(
      icona: daPagare ? LucideIcons.circle_check : LucideIcons.receipt,
      titolo: daPagare ? 'Niente da pagare' : 'Nessun pagamento ancora',
      testo: daPagare
          ? 'Quando carichi un avviso PagoPA o un bollettino lo troviamo noi e te lo proponiamo in "Da confermare"; puoi anche aggiungere un pagamento a mano.'
          : 'Qui trovi i pagamenti che hai segnato come pagati, con la data e la ricevuta.',
      azione: daPagare && c.canManage ? 'Aggiungi un pagamento' : null,
      onAzione: daPagare && c.canManage ? _nuovo : null,
    );
  }

  Widget _scheda(PagamentiController c, Pagamento p) {
    final telefono = _telefono;
    final occupato = c.busy.contains(p.id);
    final importo = Testo.titleSmall(p.importoLeggibile, fontWeight: FontWeight.w600);
    final info = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (p.ente != null) Testo.bodySmall(p.ente!, tenue: true),
      const SizedBox(height: 2),
      if (p.pagato)
        Testo.bodySmall('Pagato${p.pagatoIl == null ? '' : ' il ${formatDataBreve(p.pagatoIl!)}'}', color: context.colori.success, fontWeight: FontWeight.w600)
      else
        ScadenzaPagamento(scadenza: p.scadenza),
      if (p.codiceAvviso != null && !p.pagato) CodiceAvvisoRiga(codice: p.codiceAvviso!),
      if (p.documento != null) LinkDocumento(documentoId: p.documento!.id, titolo: p.documento!.titolo),
      if (p.ricevuta != null) LinkDocumento(documentoId: p.ricevuta!.id, titolo: p.ricevuta!.titolo, prefisso: 'Ricevuta:'),
    ]);
    final azioni = c.canManage ? _azioni(p, occupato, telefono) : null;
    return Scheda(
      paddingAll: 16,
      child: telefono
          ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: Testo.titleSmall(p.titolo, fontWeight: FontWeight.w600)),
                const SizedBox(width: 12),
                importo,
              ]),
              const SizedBox(height: 4),
              info,
              if (azioni != null) ...[const SizedBox(height: 8), azioni],
            ])
          : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Testo.titleSmall(p.titolo, fontWeight: FontWeight.w600), const SizedBox(height: 4), info])),
              const SizedBox(width: 16),
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Testo.titleMedium(p.importoLeggibile, fontWeight: FontWeight.w600),
                if (azioni != null) ...[const SizedBox(height: 8), azioni],
              ]),
            ]),
    );
  }

  Widget _azioni(Pagamento p, bool occupato, bool telefono) {
    final principale = p.pagato
        ? OutlinedButton(onPressed: occupato ? null : () => _tornaDaPagare(p), child: const Text('Torna da pagare'))
        : FilledButton(onPressed: occupato ? null : () => _segnaPagato(p), child: const Text('Segna come pagato'));
    final spinner = occupato ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : null;
    if (telefono) {
      return Row(children: [
        Expanded(child: principale),
        PopupMenuButton<String>(
          tooltip: 'Altre azioni',
          icon: spinner ?? const Icon(LucideIcons.ellipsis_vertical),
          enabled: !occupato,
          onSelected: (v) => v == 'modifica' ? _modifica(p) : _elimina(p),
          itemBuilder: (_) => const [PopupMenuItem(value: 'modifica', child: Text('Modifica')), PopupMenuItem(value: 'elimina', child: Text('Elimina'))],
        ),
      ]);
    }
    return Wrap(spacing: 4, runSpacing: 4, alignment: WrapAlignment.end, crossAxisAlignment: WrapCrossAlignment.center, children: [
      if (spinner != null) spinner,
      TextButton(onPressed: occupato ? null : () => _modifica(p), child: const Text('Modifica')),
      TextButton(onPressed: occupato ? null : () => _elimina(p), child: Text('Elimina', style: TextStyle(color: context.colori.danger))),
      principale,
    ]);
  }
}
