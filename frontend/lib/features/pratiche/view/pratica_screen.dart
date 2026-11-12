import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show PlatformException;
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/controller/da_confermare_service.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart' show formatEuro;
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/features/pratiche/view/pagamento_widgets.dart';
import 'package:cartellaviva/features/pratiche/view/voce_form.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/ai_locale.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/schede.dart' show erroreCard;
import 'package:cartellaviva/widgets/testo.dart';

/// Pagina di una pratica (`/pratiche/pratica?id=…`), dettaglio di `/pratiche`:
/// voci da vedere, voci confermate per requisito, cosa manca, somme e pacchetto.
class PraticaScreen extends StatefulWidget {
  const PraticaScreen({super.key});

  @override
  State<PraticaScreen> createState() => _PraticaScreenState();
}

class _PraticaScreenState extends State<PraticaScreen> {
  final String id = Get.parameters['id'] ?? '';
  String? get tenant => SessionService.to.currentTenantId;
  Pratica? pratica;
  String? errore;
  bool occupato = false;

  /// Filtro per persona: null = tutti, '' = famiglia (voci senza persona).
  String? filtro;
  Timer? _aggiorna;

  /// Analisi sull'iPhone: modello pronto su questo telefono, in corso, documenti fatti.
  StatoAiLocale? _modello;
  bool _sulTelefono = false;
  int _fattiSulTelefono = 0;
  bool _chiusa = false;

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    _chiusa = true;
    _aggiorna?.cancel();
    super.dispose();
  }

  /// Analizza sul telefono i documenti ancora da fare, uno alla volta, finché la pagina è aperta.
  Future<void> _analizzaSulTelefono(Pratica p, StatoAiLocale modello) async {
    final t = tenant;
    if (t == null || _sulTelefono) return;
    setState(() => _sulTelefono = true);
    try {
      while (!_chiusa) {
        final l = await PraticheApi.lavori(t, p.id, caratteri: modello.caratteri);
        if (l.lavori.isEmpty) break;
        for (final lavoro in l.lavori) {
          if (_chiusa) return;
          String? risposta, errore;
          try {
            risposta = await AiLocale.estrai(istruzioni: lavoro.istruzioni, prompt: lavoro.prompt, schema: lavoro.schema);
          } on PlatformException catch (e) {
            // Solo il codice: il messaggio potrebbe citare il testo del documento.
            errore = e.code;
          }
          await PraticheApi.risultato(t, p.id, documentoId: lavoro.documentoId, risposta: risposta, errore: errore, modello: 'apple-${modello.contesto ?? 0}');
          if (mounted) setState(() => _fattiSulTelefono++);
        }
      }
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    } finally {
      if (mounted) {
        setState(() => _sulTelefono = false);
        await _carica();
        unawaited(DaConfermareService.to.ricarica());
      }
    }
  }

  Future<void> _carica() async {
    final t = tenant;
    if (t == null) return;
    try {
      final p = await PraticheApi.dettaglio(t, id);
      if (!mounted) return;
      setState(() {
        pratica = p;
        errore = null;
      });
      // Mentre l'analisi sul server è in corso la pagina si aggiorna da sola.
      _aggiorna?.cancel();
      if (p.sulTelefono && p.inCorso) {
        _modello ??= await AiLocale.utilizzabile();
        if (mounted) setState(() {});
        if (_modello != null && p.analisi.inCoda > 0 && p.puoGestire && !_sulTelefono) unawaited(_analizzaSulTelefono(p, _modello!));
      } else if (p.analisi.inCoda > 0) {
        _aggiorna = Timer(const Duration(seconds: 8), _carica);
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  /// Esegue un'azione e ricarica; i messaggi d'errore vanno nello snackbar.
  Future<void> _fai(Future<void> Function(String t) azione, {String? fatto}) async {
    final t = tenant;
    if (t == null || occupato) return;
    setState(() => occupato = true);
    try {
      await azione(t);
      if (fatto != null && mounted) mostraMessaggio(context, fatto);
      await _carica();
      unawaited(DaConfermareService.to.ricarica());
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    } finally {
      if (mounted) setState(() => occupato = false);
    }
  }

  bool get _telefono => MediaQuery.sizeOf(context).width < larghezzaTelefono;

  @override
  Widget build(BuildContext context) {
    final p = pratica;
    return Layout(
      indietro: '/pratiche',
      titolo: p?.titolo ?? 'Pratica',
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: errore != null
            ? erroreCard(errore!, _carica)
            : p == null
                ? const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
                : _corpo(p),
      ),
    );
  }

  Widget _corpo(Pratica p) {
    final info = p.info!;
    final voci = p.voci.where((v) => filtro == null || (filtro == '' ? v.personaId == null : v.personaId == filtro)).toList();
    final daVedere = voci.where((v) => v.proposta).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _intestazione(p),
      const SizedBox(height: 12),
      if (p.provvisorio)
        _avviso(LucideIcons.triangle_alert, ColoreStato.avviso,
            'Regole provvisorie: sono quelle dell\'anno prima. Le istruzioni ufficiali di quest\'anno escono all\'inizio dell\'anno prossimo; allora le ricontrolliamo.'),
      if (p.inCorso && p.sulTelefono && (_sulTelefono || p.analisi.inCoda > 0))
        _sulTelefono || _modello != null
            ? _avviso(LucideIcons.smartphone, context.colori.primary,
                'Analisi su questo iPhone con il modello di Apple: ${_fattiSulTelefono > 0 ? '$_fattiSulTelefono fatti, ' : ''}${(p.analisi.inCoda - _fattiSulTelefono).clamp(0, 9999)} da analizzare. Tieni l\'app aperta su questa pagina.')
            : _avviso(LucideIcons.smartphone, ColoreStato.avviso,
                '${p.analisi.inCoda} documenti da analizzare sull\'iPhone che ha avviato la pratica (modello di Apple). Aprila lì, oppure analizzali sul server.',
                azione: p.puoGestire
                    ? OutlinedButton(onPressed: occupato ? null : () => _fai((t) => PraticheApi.modifica(t, p.id, analisiSu: 'server'), fatto: 'Analisi spostata sul server.'), child: const Text('Analizza sul server'))
                    : null)
      else if (p.inCorso && p.analisi.inCoda > 0)
        _avviso(LucideIcons.loader, context.colori.primary,
            'Sto leggendo i documenti: ${p.analisi.inCoda} da analizzare. Puoi lasciare la pagina, le proposte compaiono qui e in "Da confermare".'),
      if (p.inCorso && p.analisi.cifratiInAttesa > 0 && p.consensoCifrati == null) _consenso(p),
      if (p.scadenzaRinnovo != null)
        _avviso(LucideIcons.calendar_clock, context.colori.primary,
            'Scade il ${formatDataBreve(p.scadenzaRinnovo!.data)}: il promemoria del rinnovo è nelle Scadenze.'),
      _numeri(p),
      const SizedBox(height: 16),
      if (p.persone.length > 1) ...[_filtroPersone(p), const SizedBox(height: 12)],
      if (daVedere.isNotEmpty) ...[
        _titoloSezione('Da vedere', '${daVedere.length} ${daVedere.length == 1 ? 'voce trovata' : 'voci trovate'} nei documenti: diventano parte della pratica solo se le confermi.'),
        for (final v in daVedere) _schedaProposta(p, info, v),
        const SizedBox(height: 8),
      ],
      _titoloSezione('Voci', 'Le voci confermate, per tipo.',
          azione: p.puoGestire ? TextButton.icon(onPressed: () => _aggiungi(p), icon: const Icon(LucideIcons.plus, size: 16), label: const Text('Aggiungi a mano')) : null),
      ..._vociPerRequisito(p, info, voci),
      const SizedBox(height: 16),
      _cosaManca(p),
      const SizedBox(height: 16),
      _riepilogo(p),
      const SizedBox(height: 16),
      _azioni(p),
      const SizedBox(height: 16),
      _fonti(info),
      SizedBox(height: spazioPagina),
    ]);
  }

  Widget _intestazione(Pratica p) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (!_telefono)
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Flexible(child: Testo.titleMedium(p.titolo, fontSize: 18, fontWeight: FontWeight.w500)),
            Briciole(voci: [Briciola(nome: 'Pratiche', rotta: '/pratiche'), Briciola(nome: p.titolo, attiva: true)]),
          ]),
        const SizedBox(height: 4),
        Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Pillola(statiPratica[p.stato] ?? p.stato, colore: p.inCorso ? context.colori.primary : ColoreStato.successo),
          Testo.bodySmall(p.persone.map((x) => x.nome).join(', '), tenue: true),
        ]),
        if (p.info!.descrizione.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Testo.bodySmall(p.info!.descrizione, tenue: true)),
      ]);

  Widget _avviso(IconData icona, Color colore, String testo, {Widget? azione}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Scheda(
          paddingAll: 14,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(icona, size: 18, color: colore),
              const SizedBox(width: 10),
              Expanded(child: Testo.bodyMedium(testo)),
            ]),
            if (azione != null) Padding(padding: const EdgeInsets.only(top: 10, left: 28), child: Align(alignment: Alignment.centerLeft, child: azione)),
          ]),
        ),
      );

  Widget _consenso(Pratica p) {
    final n = p.analisi.cifratiInAttesa;
    return _avviso(
      LucideIcons.lock,
      ColoreStato.avviso,
      '$n ${n == 1 ? 'documento cifrato' : 'documenti cifrati'} (es. Salute, Documenti identità) ${n == 1 ? 'potrebbe servire' : 'potrebbero servire'} per questa pratica. '
      '${p.sulTelefono ? _consensoTelefono : _consensoServer}',
      azione: p.puoGestire ? FilledButton(onPressed: occupato ? null : () => _chiediConsenso(p), child: const Text('Analizza anche questi')) : null,
    );
  }

  static const _consensoTelefono =
      'Li leggiamo solo se acconsenti: il testo si decifra in memoria e va solo a questo iPhone, dove lo legge il modello di Apple; non si conserva. Teniamo solo i valori (importo, data…), cifrati.';
  static const _consensoServer =
      'Li leggiamo solo se acconsenti: il testo si decifra in memoria sul server, va solo all\'AI locale e non si conserva; teniamo solo i valori (importo, data…), cifrati.';

  Future<void> _chiediConsenso(Pratica p) async {
    final si = await chiediConferma(context,
        titolo: 'Analizzare i documenti cifrati?',
        testo: 'Per questa pratica leggiamo anche i documenti cifrati che possono servire. Il consenso vale solo per questa pratica e lo puoi revocare.',
        azione: 'Acconsento');
    if (si) await _fai((t) => PraticheApi.consensoCifrati(t, p.id, true), fatto: 'Analisi dei documenti cifrati avviata.');
  }

  Widget _numeri(Pratica p) => Wrap(spacing: 12, runSpacing: 12, children: [
        _numero('Confermato', formatEuro(p.riepilogo.totale), context.colori.success),
        _numero('Da vedere', '${p.proposte}', p.proposte > 0 ? ColoreStato.avviso : null),
        _numero('Mancano', '${p.mancanti}', p.mancanti > 0 ? context.colori.danger : null),
      ]);

  Widget _numero(String etichetta, String valore, Color? colore) => SizedBox(
        width: _telefono ? (MediaQuery.sizeOf(context).width - spazioPagina * 2 - 24) / 3 : 180,
        child: Scheda(
          paddingAll: 12,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.bodySmall(etichetta, tenue: true),
            const SizedBox(height: 2),
            FittedBox(fit: BoxFit.scaleDown, child: Testo.titleMedium(valore, fontWeight: FontWeight.w600, color: colore)),
          ]),
        ),
      );

  Widget _filtroPersone(Pratica p) => SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          for (final (id, nome) in [(null, 'Tutti'), for (final x in p.persone) (x.id, x.nome), ('', 'Famiglia')])
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(label: Text(nome), selected: filtro == id, onSelected: (_) => setState(() => filtro = id)),
            ),
        ]),
      );

  Widget _titoloSezione(String titolo, String sotto, {Widget? azione}) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.titleSmall(titolo, fontWeight: FontWeight.w600),
              Testo.bodySmall(sotto, tenue: true),
            ]),
          ),
          if (azione != null) azione,
        ]),
      );

  /// "Farmaci · Farmacia Centrale · 12/03/2025".
  String _dettagli(RequisitoPratica? r, VocePratica v) {
    final parti = <String>[
      if (r?.tipo(v.valori['tipo']) != null) r!.tipo(v.valori['tipo'])!.etichetta,
      if (v.valori['emittente'] != null) v.valori['emittente']!,
      if (v.valori['targa'] != null) v.valori['targa']!,
      if (v.valori['iban'] != null) _raggruppa(v.valori['iban']!),
      if (v.data != null) formatDataBreve(v.data!),
      if (r?.perPersona == true) v.persona ?? 'persona non indicata',
    ];
    return parti.join(' · ');
  }

  Widget _controlli(VocePratica v) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        for (final c in v.controlli)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(c.avviso ? LucideIcons.triangle_alert : LucideIcons.info, size: 14, color: c.avviso ? ColoreStato.avviso : context.colori.info),
              const SizedBox(width: 6),
              Expanded(child: Testo.bodySmall(c.testo, color: c.avviso ? ColoreStato.avviso : null)),
            ]),
          ),
      ]);

  Widget _schedaProposta(Pratica p, ModelloPratica info, VocePratica v) {
    final r = info.requisito(v.requisito);
    final testo = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Pillola(r?.etichetta ?? v.requisito, colore: ColoreStato.avviso),
      const SizedBox(height: 6),
      Row(children: [
        Expanded(child: Testo.titleSmall(_dettagli(r, v), fontWeight: FontWeight.w600)),
        if (v.valori['importo'] != null) Testo.titleSmall(formatEuro(v.valori['importo']), fontWeight: FontWeight.w600),
      ]),
      if (v.documento != null) LinkDocumento(documentoId: v.documento!.id, titolo: v.documento!.titolo),
      _controlli(v),
    ]);
    final bottoni = p.puoGestire
        ? Wrap(spacing: 8, runSpacing: 8, children: [
            FilledButton(onPressed: occupato ? null : () => _fai((t) => PraticheApi.statoVoce(t, p.id, v.id, 'conferma'), fatto: 'Voce confermata.'), child: const Text('Conferma')),
            OutlinedButton(onPressed: occupato ? null : () => _correggi(p, v, conferma: true), child: const Text('Correggi')),
            TextButton(onPressed: occupato ? null : () => _fai((t) => PraticheApi.statoVoce(t, p.id, v.id, 'escludi')), child: const Text('Escludi')),
          ])
        : null;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Scheda(
        paddingAll: 14,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [testo, if (bottoni != null) ...[const SizedBox(height: 10), bottoni]]),
      ),
    );
  }

  List<Widget> _vociPerRequisito(Pratica p, ModelloPratica info, List<VocePratica> voci) {
    final out = <Widget>[];
    for (final r in info.requisiti) {
      final confermate = voci.where((v) => v.requisito == r.codice && v.confermata).toList();
      final escluse = voci.where((v) => v.requisito == r.codice && v.esclusa).toList();
      if (confermate.isEmpty && escluse.isEmpty) continue;
      final somma = confermate.fold<double>(0, (a, v) => a + (double.tryParse(v.valori['importo'] ?? '') ?? 0));
      out.add(Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Scheda(
          paddingAll: 14,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(child: Testo.titleSmall(r.etichetta, fontWeight: FontWeight.w600)),
              if (r.somma && confermate.isNotEmpty) Testo.titleSmall(formatEuro(somma.toStringAsFixed(2)), fontWeight: FontWeight.w600),
            ]),
            for (final v in confermate) _rigaVoce(p, r, v),
            if (escluse.isNotEmpty)
              Theme(
                data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                child: ExpansionTile(
                  tilePadding: EdgeInsets.zero,
                  title: Testo.bodySmall('${escluse.length} ${escluse.length == 1 ? 'esclusa' : 'escluse'}', tenue: true),
                  children: [for (final v in escluse) _rigaVoce(p, r, v)],
                ),
              ),
          ]),
        ),
      ));
    }
    if (out.isEmpty) {
      out.add(Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Testo.bodyMedium(p.inCorso ? 'Ancora nessuna voce confermata.' : 'Nessuna voce confermata.', tenue: true),
      ));
    }
    return out;
  }

  Widget _rigaVoce(Pratica p, RequisitoPratica r, VocePratica v) {
    final menu = p.puoGestire
        ? PopupMenuButton<String>(
            tooltip: 'Altre azioni',
            icon: const Icon(LucideIcons.ellipsis_vertical, size: 18),
            onSelected: (a) => switch (a) {
              'correggi' => _correggi(p, v),
              'elimina' => _elimina(p, v),
              _ => _fai((t) => PraticheApi.statoVoce(t, p.id, v.id, a)),
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'correggi', child: Text('Correggi')),
              if (v.esclusa) const PopupMenuItem(value: 'riproponi', child: Text('Rimetti da vedere')) else const PopupMenuItem(value: 'escludi', child: Text('Escludi')),
              if (v.origine == 'manuale') const PopupMenuItem(value: 'elimina', child: Text('Elimina')),
            ],
          )
        : null;
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Opacity(
        opacity: v.esclusa ? .6 : 1,
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodyMedium(_dettagli(r, v).isEmpty ? (v.documento?.titolo ?? r.etichetta) : _dettagli(r, v), fontWeight: FontWeight.w500),
              if (v.documento != null) LinkDocumento(documentoId: v.documento!.id, titolo: v.documento!.titolo),
              if (v.origine == 'manuale') const Testo.bodySmall('Aggiunta a mano', tenue: true),
              if (!v.esclusa) _controlli(v),
            ]),
          ),
          if (v.valori['importo'] != null) Padding(padding: const EdgeInsets.only(left: 8, top: 2), child: Testo.bodyMedium(formatEuro(v.valori['importo']), fontWeight: FontWeight.w600)),
          if (menu != null) menu,
        ]),
      ),
    );
  }

  Widget _cosaManca(Pratica p) {
    final info = p.info!;
    final nonServe = p.nonServe;
    return Scheda(
      paddingAll: 16,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.titleSmall('Cosa manca', fontWeight: FontWeight.w600),
        const SizedBox(height: 6),
        if (p.cosaManca.isEmpty) const Testo.bodyMedium('Niente: ci sono tutti i documenti obbligatori.'),
        for (final m in p.cosaManca)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Icon(m.dettaglio == null ? LucideIcons.circle_alert : LucideIcons.search, size: 16, color: m.dettaglio == null ? context.colori.danger : ColoreStato.avviso),
              Testo.bodyMedium(
                [m.etichetta, if (m.persona != null) m.persona!, if (m.dettaglio != null) 'trovato nei documenti: ${_raggruppa(m.dettaglio!)}'].join(' · '),
                fontWeight: FontWeight.w500,
              ),
              if (p.puoGestire) ...[
                TextButton(onPressed: occupato ? null : () => _aggiungi(p, requisito: m.requisito, personaId: m.personaId, valori: m.valori), child: Text(m.dettaglio == null ? 'Aggiungi' : 'Completa')),
                TextButton(
                  onPressed: occupato ? null : () => _fai((t) => PraticheApi.nonServe(t, p.id, requisito: m.requisito, personaId: m.personaId, dettaglio: m.dettaglio, nonServe: true)),
                  child: const Text('Non serve'),
                ),
              ],
            ]),
          ),
        if (p.annoPrima.isNotEmpty) ...[
          const SizedBox(height: 12),
          const Testo.bodySmall('L\'anno scorso c\'erano anche:', tenue: true),
          for (final a in p.annoPrima)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Testo.bodyMedium('• ${[a.etichetta, a.emittente, a.persona].whereType<String>().join(' · ')}'),
            ),
        ],
        if (nonServe.isNotEmpty && p.puoGestire) ...[
          const SizedBox(height: 12),
          const Testo.bodySmall('Segnati come "non serve":', tenue: true),
          for (final n in nonServe)
            Row(children: [
              Expanded(
                child: Testo.bodyMedium(
                    '${info.requisito(n.requisito)?.etichetta ?? n.requisito}${n.personaId == null ? '' : ' · ${p.persone.where((x) => x.id == n.personaId).firstOrNull?.nome ?? ''}'}${n.dettaglio == null ? '' : ' · ${_raggruppa(n.dettaglio!)}'}'),
              ),
              TextButton(
                onPressed: occupato ? null : () => _fai((t) => PraticheApi.nonServe(t, p.id, requisito: n.requisito, personaId: n.personaId, dettaglio: n.dettaglio, nonServe: false)),
                child: const Text('Serve'),
              ),
            ]),
        ],
      ]),
    );
  }

  Widget _riepilogo(Pratica p) {
    final r = p.riepilogo;
    Widget riga(String a, String b, {bool forte = false}) => Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Row(children: [
            Expanded(child: Testo.bodyMedium(a, fontWeight: forte ? FontWeight.w600 : FontWeight.w400)),
            Testo.bodyMedium(formatEuro(b), fontWeight: forte ? FontWeight.w600 : FontWeight.w500),
          ]),
        );
    return Scheda(
      paddingAll: 16,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.titleSmall(p.isee ? 'Somma dei saldi' : 'Somme dei documenti', fontWeight: FontWeight.w600),
        Testo.bodySmall(
          p.isee
              ? 'Solo le voci confermate. Non è il calcolo dell\'ISEE: quello lo fa l\'INPS sulla DSU.'
              : 'Solo le voci confermate. Non è il calcolo della detrazione: quello lo fanno il CAF o la dichiarazione precompilata.',
          tenue: true,
        ),
        const SizedBox(height: 8),
        if (r.persone.isEmpty) const Testo.bodyMedium('Ancora niente da sommare.'),
        for (final x in r.persone) riga(x.persona, x.totale),
        if (r.persone.isNotEmpty) riga('Totale', r.totale, forte: true),
        if (r.requisiti.isNotEmpty) ...[
          const SizedBox(height: 12),
          for (final req in r.requisiti)
            for (final t in req.tipi) riga(req.tipi.length == 1 && t.etichetta == req.etichetta ? req.etichetta : '${req.etichetta} · ${t.etichetta}', t.totale),
        ],
      ]),
    );
  }

  Widget _azioni(Pratica p) => Wrap(spacing: 8, runSpacing: 8, children: [
        FilledButton.icon(
          onPressed: occupato ? null : () => _fai((t) async => saveOrOpenFile(await PraticheApi.pacchetto(t, p.id), '${p.titolo.replaceAll(RegExp(r'[^\w\- ]+'), ' ').trim()}.zip', 'application/zip')),
          icon: const Icon(LucideIcons.package, size: 18),
          label: const Text('Prepara pacchetto'),
        ),
        if (p.puoGestire) ...[
          OutlinedButton(onPressed: occupato ? null : () => _archivia(p), child: const Text('Archivia una copia')),
          if (p.inCorso)
            OutlinedButton(
              onPressed: occupato ? null : () => _fai((t) => PraticheApi.modifica(t, p.id, stato: 'inviata'), fatto: p.isee ? 'DSU segnata come presentata: il promemoria del rinnovo è nelle Scadenze.' : 'Pratica segnata come inviata.'),
              child: Text(p.isee ? 'Segna DSU presentata' : 'Segna come inviata'),
            )
          else
            OutlinedButton(onPressed: occupato ? null : () => _fai((t) => PraticheApi.modifica(t, p.id, stato: 'in_corso')), child: const Text('Riapri')),
          if (p.inCorso) TextButton(onPressed: occupato ? null : () => _fai((t) => PraticheApi.analizza(t, p.id), fatto: 'Ricerca nei documenti avviata.'), child: const Text('Cerca di nuovo')),
          if (p.consensoCifrati != null)
            TextButton(
              onPressed: occupato ? null : () => _fai((t) => PraticheApi.consensoCifrati(t, p.id, false), fatto: 'Consenso revocato: i documenti cifrati non si leggono più per questa pratica.'),
              child: const Text('Revoca consenso cifrati'),
            ),
          TextButton(onPressed: occupato ? null : () => _eliminaPratica(p), child: Text('Elimina pratica', style: TextStyle(color: context.colori.danger))),
        ],
      ]);

  Widget _fonti(ModelloPratica info) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (info.note != null) Testo.bodySmall(info.note!, tenue: true),
        const SizedBox(height: 4),
        Testo.bodySmall(
          'Regole ${info.verificato == null ? 'non ancora verificate' : 'verificate il ${formatDataBreve(DateTime.parse(info.verificato!))}'} su: ${info.fonti.map((f) => f.titolo).join('; ')}.',
          tenue: true,
        ),
      ]);

  /// IBAN a gruppi di 4 per leggerlo; le targhe restano come sono.
  String _raggruppa(String codice) => codice.length > 10 ? codice.replaceAllMapped(RegExp(r'.{4}'), (m) => '${m[0]} ').trim() : codice;

  Future<void> _aggiungi(Pratica p, {String? requisito, String? personaId, Map<String, String> valori = const {}}) async {
    final t = tenant;
    if (t == null) return;
    final v = await mostraVoceForm(context, tenantId: t, pratica: p, requisito: requisito, personaId: personaId ?? (filtro?.isNotEmpty == true ? filtro : null), valori: valori);
    if (v != null) await _carica();
  }

  Future<void> _correggi(Pratica p, VocePratica v, {bool conferma = false}) async {
    final t = tenant;
    if (t == null) return;
    final salvata = await mostraVoceForm(context, tenantId: t, pratica: p, voce: v, conferma: conferma);
    if (salvata != null) {
      await _carica();
      unawaited(DaConfermareService.to.ricarica());
    }
  }

  Future<void> _elimina(Pratica p, VocePratica v) async {
    final si = await chiediConferma(context, titolo: 'Eliminare la voce?', testo: 'La voce aggiunta a mano sparisce dalla pratica.', azione: 'Elimina', distruttiva: true);
    if (si) await _fai((t) => PraticheApi.eliminaVoce(t, p.id, v.id));
  }

  Future<void> _archivia(Pratica p) async {
    final si = await chiediConferma(context,
        titolo: 'Archiviare il pacchetto?',
        testo: 'Salviamo una copia del pacchetto di oggi (documenti e riepilogo) tra i documenti, cifrata, nella categoria "Condivisi con enti".',
        azione: 'Archivia');
    if (si) await _fai((t) => PraticheApi.archivia(t, p.id), fatto: 'Copia archiviata in "Condivisi con enti".');
  }

  Future<void> _eliminaPratica(Pratica p) async {
    final si = await chiediConferma(context,
        titolo: 'Eliminare la pratica?',
        testo: 'Spariscono la pratica e le sue voci. I documenti restano nell\'archivio.',
        azione: 'Elimina',
        distruttiva: true);
    if (!si || tenant == null) return;
    try {
      await PraticheApi.elimina(tenant!, p.id);
      unawaited(DaConfermareService.to.ricarica());
      if (mounted) {
        mostraMessaggio(context, 'Pratica eliminata.');
        Get.offNamed('/pratiche');
      }
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    }
  }
}
