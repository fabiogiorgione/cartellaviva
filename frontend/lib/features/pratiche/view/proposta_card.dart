import 'package:flutter/material.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart' show ScadenzaProposta;
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/features/pratiche/controller/da_confermare_service.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_form.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_widgets.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';
import 'package:cartellaviva/features/scadenze/view/scadenze_proposte.dart' show confermaProposta, ignoraProposta;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Una proposta dell'AI: tipo, documento d'origine, dati letti e le tre azioni
/// Conferma / Correggi / Ignora. Sul telefono il testo ha tutta la larghezza e
/// i pulsanti stanno sotto. Ogni azione restituisce true se è andata a buon
/// fine: allora si aggiorna la coda condivisa e si chiama [onDecisa].
class PropostaCard extends StatefulWidget {
  final String tipo;
  final Color coloreTipo;
  final String titolo;
  final String? documentoId;
  final String? documentoTitolo;

  /// Dati letti, come coppie etichetta / valore.
  final List<(String, String)> dati;
  final String? nota;
  final String confermaEtichetta;
  final Future<bool> Function() onConferma;
  final Future<bool> Function() onCorreggi;
  final Future<bool> Function() onIgnora;
  final VoidCallback? onDecisa;

  const PropostaCard({
    super.key,
    required this.tipo,
    required this.coloreTipo,
    required this.titolo,
    this.documentoId,
    this.documentoTitolo,
    required this.dati,
    this.nota,
    this.confermaEtichetta = 'Conferma',
    required this.onConferma,
    required this.onCorreggi,
    required this.onIgnora,
    this.onDecisa,
  });

  @override
  State<PropostaCard> createState() => _PropostaCardState();
}

class _PropostaCardState extends State<PropostaCard> {
  bool occupato = false;

  Future<void> _esegui(Future<bool> Function() azione) async {
    if (occupato) return;
    setState(() => occupato = true);
    final fatto = await azione();
    if (fatto) {
      await DaConfermareService.to.ricarica();
      widget.onDecisa?.call();
    }
    if (mounted) setState(() => occupato = false);
  }

  @override
  Widget build(BuildContext context) {
    final w = widget;
    final telefono = MediaQuery.sizeOf(context).width < larghezzaTelefono;
    final testo = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Pillola(w.tipo, colore: w.coloreTipo),
      const SizedBox(height: 6),
      Testo.titleSmall(w.titolo, fontWeight: FontWeight.w600),
      if (w.documentoTitolo != null && w.documentoId != null) LinkDocumento(documentoId: w.documentoId!, titolo: w.documentoTitolo!),
      const SizedBox(height: 6),
      Wrap(spacing: 20, runSpacing: 4, children: [
        for (final (etichetta, valore) in w.dati)
          Row(mainAxisSize: MainAxisSize.min, children: [
            Testo.bodySmall('$etichetta ', tenue: true),
            Flexible(child: Testo.bodyMedium(valore, fontWeight: FontWeight.w500)),
          ]),
      ]),
      if (w.nota != null) Padding(padding: const EdgeInsets.only(top: 6), child: Testo.bodySmall(w.nota!, color: context.colori.danger)),
    ]);
    final bottoni = Wrap(spacing: 8, runSpacing: 8, alignment: telefono ? WrapAlignment.start : WrapAlignment.end, crossAxisAlignment: WrapCrossAlignment.center, children: [
      if (occupato) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
      FilledButton(onPressed: occupato ? null : () => _esegui(w.onConferma), child: Text(w.confermaEtichetta)),
      OutlinedButton(onPressed: occupato ? null : () => _esegui(w.onCorreggi), child: const Text('Correggi')),
      TextButton(onPressed: occupato ? null : () => _esegui(w.onIgnora), child: const Text('Ignora')),
    ]);
    if (telefono) {
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [testo, const SizedBox(height: 12), bottoni]);
    }
    return Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
      Expanded(child: testo),
      const SizedBox(width: 16),
      ConstrainedBox(constraints: const BoxConstraints(maxWidth: 300), child: bottoni),
    ]);
  }
}

/// PropostaCard di una scadenza trovata in un documento.
Widget propostaScadenzaCard(BuildContext context, ScadenzaProposta p, {VoidCallback? onDecisa}) => PropostaCard(
      key: ValueKey('s-${p.documentoId}'),
      tipo: 'Scadenza',
      coloreTipo: context.colori.primary,
      titolo: p.tipo ?? p.titoloSuggerito,
      documentoId: p.documentoId,
      documentoTitolo: p.documentoTitolo,
      dati: [('Scade il', formatDataBreve(p.data)), if (p.membri.isNotEmpty) ('Riguarda', p.membri.map((m) => m.nome).join(', '))],
      onConferma: () => creaScadenzaProposta(context, p),
      onCorreggi: () => confermaProposta(context, p),
      onIgnora: () => ignoraProposta(p),
      onDecisa: onDecisa,
    );

/// PropostaCard di un avviso di pagamento trovato in un documento.
Widget propostaPagamentoCard(BuildContext context, PropostaPagamento p, {VoidCallback? onDecisa, bool mostraDocumento = true}) => PropostaCard(
      key: ValueKey('p-${p.documentoId}'),
      tipo: 'Pagamento',
      coloreTipo: ColoreStato.avviso,
      titolo: p.titoloSuggerito,
      documentoId: p.documentoId,
      documentoTitolo: mostraDocumento ? p.documentoTitolo : null,
      dati: [
        if (p.completa) ('Importo', formatEuro(p.importo)),
        if (p.ente != null) ('Ente', p.ente!),
        if (p.scadenza != null) ('Scadenza', formatDataBreve(p.scadenza!)),
        if (p.codiceAvviso != null) ('Codice avviso', raggruppaCodiceAvviso(p.codiceAvviso!)),
      ],
      nota: p.completa ? null : "L'importo non è stato trovato: aggiungilo con Correggi.",
      confermaEtichetta: p.completa ? 'Conferma' : 'Completa',
      onConferma: () => p.completa ? creaPagamentoProposto(context, p) : correggiPagamentoProposto(context, p),
      onCorreggi: () => correggiPagamentoProposto(context, p),
      onIgnora: () => ignoraPagamentoProposto(context, p),
      onDecisa: onDecisa,
    );

String? _tenant() => SessionService.to.currentTenantId;

/// Conferma diretta di una scadenza trovata: la crea con i dati proposti.
Future<bool> creaScadenzaProposta(BuildContext context, ScadenzaProposta p) async {
  final tenantId = _tenant();
  if (tenantId == null) return false;
  try {
    await ScadenzaApi.create(
      tenantId,
      ScadenzaInput(
        titolo: p.titoloSuggerito,
        data: p.data,
        promemoriaGiorni: 30,
        documentoId: p.documentoId,
        membroId: p.membri.length == 1 ? p.membri.first.id : null,
        categoriaId: p.categoriaId,
      ),
    );
    if (context.mounted) mostraMessaggio(context, 'Scadenza creata.');
    return true;
  } on ApiException catch (e) {
    if (context.mounted) mostraMessaggio(context, e.message, errore: true);
    return false;
  }
}

/// Conferma diretta di un avviso di pagamento: lo crea con i dati proposti (con il promemoria).
Future<bool> creaPagamentoProposto(BuildContext context, PropostaPagamento p) async {
  final tenantId = _tenant();
  if (tenantId == null) return false;
  try {
    await PagamentiApi.create(tenantId, p.toInput());
    if (context.mounted) mostraMessaggio(context, 'Pagamento aggiunto.');
    return true;
  } on ApiException catch (e) {
    if (context.mounted) mostraMessaggio(context, e.message, errore: true);
    return false;
  }
}

/// Apre il modulo già compilato con i dati letti.
Future<bool> correggiPagamentoProposto(BuildContext context, PropostaPagamento p) async {
  final tenantId = _tenant();
  if (tenantId == null) return false;
  final salvato = await mostraPagamentoForm(context, tenantId: tenantId, iniziale: p.toInput(), documentoTitolo: p.documentoTitolo);
  if (salvato != null && context.mounted) mostraMessaggio(context, 'Pagamento aggiunto.');
  return salvato != null;
}

Future<bool> ignoraPagamentoProposto(BuildContext context, PropostaPagamento p) async {
  final tenantId = _tenant();
  if (tenantId == null) return false;
  try {
    await PagamentiApi.ignoraProposta(tenantId, p.documentoId);
    return true;
  } on ApiException catch (e) {
    if (context.mounted) mostraMessaggio(context, e.message, errore: true);
    return false;
  }
}

/// PropostaCard di una voce di pratica (es. una spesa del 730) trovata in un documento.
/// "Correggi" apre la pagina della pratica, dove c'è il modulo completo.
Widget propostaPraticaCard(BuildContext context, VocePratica v, {VoidCallback? onDecisa}) => PropostaCard(
      key: ValueKey('v-${v.id}'),
      tipo: v.praticaTitolo ?? 'Pratica',
      coloreTipo: ColoreStato.successo,
      titolo: [v.requisitoEtichetta ?? v.requisito, if (v.tipoEtichetta != null && v.tipoEtichetta != v.requisitoEtichetta) v.tipoEtichetta!].join(' · '),
      documentoId: v.documento?.id,
      documentoTitolo: v.documento?.titolo,
      dati: [
        if (v.valori['importo'] != null) ('Importo', formatEuro(v.valori['importo'])),
        if (v.data != null) ('Data', formatDataBreve(v.data!)),
        if (v.valori['emittente'] != null) ('Da', v.valori['emittente']!),
        if (v.valori['targa'] != null) ('Targa', v.valori['targa']!),
        if (v.valori['iban'] != null) ('IBAN', v.valori['iban']!),
        if (v.persona != null) ('Per', v.persona!),
      ],
      nota: v.controlli.where((c) => c.avviso).map((c) => c.testo).firstOrNull,
      onConferma: () => _azionePratica(context, v, 'conferma'),
      onCorreggi: () async {
        await Get.toNamed('/pratiche/pratica', parameters: {'id': v.praticaId!});
        return true;
      },
      onIgnora: () => _azionePratica(context, v, 'escludi'),
      onDecisa: onDecisa,
    );

Future<bool> _azionePratica(BuildContext context, VocePratica v, String azione) async {
  final t = _tenant();
  if (t == null || v.praticaId == null) return false;
  try {
    await PraticheApi.statoVoce(t, v.praticaId!, v.id, azione);
    if (context.mounted) mostraMessaggio(context, azione == 'conferma' ? 'Aggiunta alla pratica.' : 'Esclusa dalla pratica.');
    return true;
  } on ApiException catch (e) {
    if (context.mounted) mostraMessaggio(context, e.message, errore: true);
    return false;
  }
}
