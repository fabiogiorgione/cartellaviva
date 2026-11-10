import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter/services.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/widgets/visualizzatore_anteprima.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Risposta in attesa di verifica (GET /api/enti/{t}/da-verificare).
class DaVerificare {
  final String richiestaId;
  final String tipo;
  final String titolo;
  final String? istruzioni;
  final String firmatari;
  final bool chiediValidita;
  final String? gruppo;
  final String destinatarioId;
  final String iscritto;
  final List<({String nome, bool firmato})> referenti;
  final String? messaggioFamiglia;
  final DateTime? rispostaIl;
  final DateTime? validoFino;
  final bool viaLink;
  final ({String nome, String mimeType})? file;

  DaVerificare({
    required this.richiestaId,
    required this.tipo,
    required this.titolo,
    this.istruzioni,
    this.firmatari = 'tutti',
    this.chiediValidita = false,
    this.gruppo,
    required this.destinatarioId,
    required this.iscritto,
    this.referenti = const [],
    this.messaggioFamiglia,
    this.rispostaIl,
    this.validoFino,
    this.viaLink = false,
    this.file,
  });

  factory DaVerificare.fromJson(Map<String, dynamic> j) {
    final r = Map<String, dynamic>.from(j['richiesta'] as Map);
    final d = Map<String, dynamic>.from(j['destinatario'] as Map);
    final f = d['file'] as Map?;
    return DaVerificare(
      richiestaId: r['id'] as String,
      tipo: r['tipo'] as String,
      titolo: r['titolo'] as String,
      istruzioni: r['istruzioni'] as String?,
      firmatari: (r['firmatari'] as String?) ?? 'tutti',
      chiediValidita: r['chiediValidita'] == true,
      gruppo: r['gruppo'] as String?,
      destinatarioId: d['id'] as String,
      iscritto: d['iscritto'] as String,
      referenti: (d['referenti'] as List? ?? []).whereType<Map>().map((x) => (nome: x['nome'] as String, firmato: x['firmato'] == true)).toList(),
      messaggioFamiglia: d['messaggioFamiglia'] as String?,
      rispostaIl: d['rispostaIl'] is String ? DateTime.tryParse(d['rispostaIl'] as String)?.toLocal() : null,
      validoFino: d['validoFino'] is String ? DateTime.tryParse(d['validoFino'] as String) : null,
      viaLink: d['viaLink'] == true,
      file: f == null ? null : (nome: f['nome'] as String, mimeType: f['mimeType'] as String),
    );
  }

  /// Cosa controllare, in base al tipo di richiesta.
  List<String> get controlli => [
        'È il documento richiesto ("$titolo")',
        'È leggibile e completo (tutte le pagine)',
        if (tipo == 'firma' && firmatari == 'tutti')
          for (final r in referenti) 'Firma di ${r.nome} presente${r.firmato ? '' : ' (non dichiarata dalla famiglia)'}',
        if (tipo == 'firma' && firmatari != 'tutti') 'È presente almeno una firma di un referente',
        if (chiediValidita && validoFino != null) 'La validità indicata (fino al ${formatDataBreve(validoFino!)}) corrisponde al documento',
      ];
}

/// Verifica affiancata: documento a sinistra, controlli e decisione a destra,
/// poi subito la risposta successiva. Scorciatoie da tastiera: A accetta,
/// R chiedi correzione, freccia destra salta.
class VerificaAffiancataScreen extends StatefulWidget {
  const VerificaAffiancataScreen({super.key});

  @override
  State<VerificaAffiancataScreen> createState() => _VerificaAffiancataScreenState();
}

class _VerificaAffiancataScreenState extends State<VerificaAffiancataScreen> {
  String get t => SessionService.to.currentTenantId ?? '';
  final String? richiestaId = Get.parameters['richiesta'];
  final focus = FocusNode();
  final motivoLibero = TextEditingController();
  List<DaVerificare>? coda;
  int indice = 0;
  int verificate = 0;
  Set<int> spuntati = {};
  String? motivo;
  bool correzione = false;
  bool busy = false;
  String? errore;

  static const motivi = ['Manca una firma', 'Pagina mancante o illeggibile', 'Documento sbagliato', 'Validità non corrispondente'];

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    focus.dispose();
    motivoLibero.dispose();
    super.dispose();
  }

  Future<void> _carica() async {
    try {
      final r = await ApiClient.dio.get('/enti/$t/da-verificare', queryParameters: {if (richiestaId != null) 'richiestaId': richiestaId});
      coda = (r.data as List).map((e) => DaVerificare.fromJson(Map<String, dynamic>.from(e as Map))).toList();
      indice = 0;
      _prepara();
    } on DioException catch (e) {
      errore = ApiException.fromDio(e).message;
    }
    if (mounted) setState(() {});
  }

  DaVerificare? get corrente => coda != null && indice < coda!.length ? coda![indice] : null;

  void _prepara() {
    spuntati = {};
    motivo = null;
    correzione = false;
    motivoLibero.clear();
    WidgetsBinding.instance.addPostFrameCallback((_) => focus.requestFocus());
  }

  void _salta() {
    if (coda == null || coda!.isEmpty) return;
    setState(() {
      indice = (indice + 1) % coda!.length;
      _prepara();
    });
  }

  Future<void> _decidi(bool accetta) async {
    final c = corrente;
    if (c == null || busy) return;
    final testo = motivo ?? motivoLibero.text.trim();
    if (!accetta && testo.isEmpty) return;
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await EnteApi.verifica(t, c.richiestaId, c.destinatarioId, accetta: accetta, motivo: accetta ? null : testo);
      coda!.removeAt(indice);
      verificate++;
      if (indice >= coda!.length) indice = 0;
      _prepara();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  bool get tuttiSpuntati => corrente != null && spuntati.length == corrente!.controlli.length;

  KeyEventResult _tasto(FocusNode _, KeyEvent e) {
    if (e is! KeyDownEvent || busy) return KeyEventResult.ignored;
    // Mentre si scrive il motivo le lettere vanno nel campo.
    if (correzione) return KeyEventResult.ignored;
    if (e.logicalKey == LogicalKeyboardKey.keyA && tuttiSpuntati) {
      _decidi(true);
      return KeyEventResult.handled;
    }
    if (e.logicalKey == LogicalKeyboardKey.keyR) {
      setState(() => correzione = true);
      return KeyEventResult.handled;
    }
    if (e.logicalKey == LogicalKeyboardKey.arrowRight) {
      _salta();
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: 'Verifica delle risposte',
      sezione: 'Verifica',
      azioni: const [AiutoLink(guida: 'ente-richiesta')],
      child: Focus(focusNode: focus, autofocus: true, onKeyEvent: _tasto, child: _corpo()),
    );
  }

  Widget _corpo() {
    if (errore != null && coda == null) return erroreCard(errore!, _carica);
    if (coda == null) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    final c = corrente;
    if (c == null) {
      return StatoVuoto(
        icona: LucideIcons.circle_check,
        titolo: verificate > 0 ? 'Fatto: $verificate risposte verificate' : 'Nessuna risposta da verificare',
        testo: 'Quando le famiglie consegnano nuove risposte le trovi qui, dalla più vecchia.',
        azione: 'Torna alle richieste',
        onAzione: () => Get.offNamed(richiestaId != null ? '/ente/richieste/$richiestaId' : '/ente/richieste'),
      );
    }
    final largo = MediaQuery.of(context).size.width >= 1100;
    final documento = SizedBox(height: largo ? 720 : 520, child: _anteprima(c));
    final pannello = _pannello(c);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Testo.bodyMedium('${indice + 1} di ${coda!.length} da verificare', fontWeight: FontWeight.w500),
        if (verificate > 0) Testo.bodySmall('  ·  $verificate verificate in questa sessione', tenue: true),
        const Spacer(),
        Testo.bodySmall('Tastiera: A accetta · R chiedi correzione · → salta', tenue: true),
      ]),
      SizedBox(height: 12),
      if (largo)
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(flex: 3, child: documento),
          SizedBox(width: 16),
          Expanded(flex: 2, child: pannello),
        ])
      else ...[documento, SizedBox(height: 16), pannello],
    ]);
  }

  Widget _anteprima(DaVerificare c) {
    final f = c.file;
    if (f == null) return Scheda(paddingAll: 24, child: Testo.bodyMedium('La famiglia non ha caricato un file.'));
    // PDF e foto come sono; DOCX & co. convertiti in PDF dal server (l'originale resta com'è).
    return Scheda(paddingAll: 0, child: VisualizzatoreAnteprima(
        key: ValueKey(c.destinatarioId),
        carica: () => EnteApi.anteprimaRisposta(t, c.richiestaId, c.destinatarioId),
        azioni: [
          TextButton.icon(
            onPressed: () async {
              try {
                final o = await EnteApi.fileRispostaConNome(t, c.richiestaId, c.destinatarioId);
                await saveOrOpenFile(o.bytes, o.nome, o.mime);
              } on ApiException catch (e) {
                if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
              }
            },
            icon: const Icon(LucideIcons.external_link, size: 16),
            label: const Text('Apri a parte'),
          ),
        ],
      ));
  }

  Widget _pannello(DaVerificare c) {
    final controlli = c.controlli;
    final testo = motivo ?? motivoLibero.text.trim();
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.titleSmall(c.iscritto, fontWeight: FontWeight.w600),
        Testo.bodySmall([
          '${tipoRichiestaLabel(c.tipo)} · ${c.titolo}',
          if (c.gruppo != null) c.gruppo!,
          if (c.rispostaIl != null) 'consegnata il ${formatDataBreve(c.rispostaIl!)}',
          if (c.viaLink) 'dal link senza account',
        ].join(' · '), tenue: true),
        if ((c.istruzioni ?? '').isNotEmpty) ...[SizedBox(height: 8), Testo.bodySmall('Istruzioni date: "${c.istruzioni}"')],
        if ((c.messaggioFamiglia ?? '').isNotEmpty) ...[SizedBox(height: 8), Testo.bodySmall('Messaggio della famiglia: "${c.messaggioFamiglia}"', fontWeight: FontWeight.w500)],
        const Divider(height: 24),
        Testo.labelMedium('Controlla'),
        for (var i = 0; i < controlli.length; i++)
          CheckboxListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: spuntati.contains(i),
            onChanged: busy ? null : (v) => setState(() => v == true ? spuntati.add(i) : spuntati.remove(i)),
            title: Text(controlli[i]),
          ),
        SizedBox(height: 8),
        if (!correzione)
          Wrap(spacing: 12, runSpacing: 8, children: [
            FilledButton.icon(
              onPressed: busy || !tuttiSpuntati ? null : () => _decidi(true),
              icon: const Icon(LucideIcons.check, size: 18),
              label: const Text('Accetta'),
            ),
            OutlinedButton.icon(onPressed: busy ? null : () => setState(() => correzione = true), icon: const Icon(LucideIcons.rotate_ccw, size: 18), label: const Text('Chiedi correzione')),
            TextButton(onPressed: busy || coda!.length < 2 ? null : _salta, child: const Text('Salta')),
          ])
        else ...[
          Testo.labelMedium('Cosa deve correggere la famiglia (lo legge lei)'),
          SizedBox(height: 6),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final m in motivi) ChoiceChip(label: Text(m), selected: motivo == m, onSelected: (_) => setState(() => motivo = motivo == m ? null : m)),
          ]),
          SizedBox(height: 8),
          TextField(
            controller: motivoLibero,
            maxLength: 500,
            onChanged: (_) => setState(() => motivo = null),
            decoration: const InputDecoration(labelText: 'Oppure scrivi tu', isDense: true),
          ),
          Wrap(spacing: 12, children: [
            FilledButton(onPressed: busy || testo.isEmpty ? null : () => _decidi(false), child: const Text('Invia la richiesta di correzione')),
            TextButton(onPressed: busy ? null : () => setState(() => correzione = false), child: const Text('Annulla')),
          ]),
        ],
        if (!tuttiSpuntati && !correzione) Testo.bodySmall('Spunta tutti i controlli per accettare.', tenue: true),
        if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
      ]));
  }
}
