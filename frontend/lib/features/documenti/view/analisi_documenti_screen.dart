import 'dart:async';

import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/analisi_documenti.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// "Analisi AI dei documenti": quanti documenti della famiglia sono già stati
/// analizzati (dati, tag, ricerca per significato) e quanti mancano.
/// Chi può modificare i documenti può rimettere in coda i mancanti.
class AnalisiDocumentiScreen extends StatefulWidget {
  const AnalisiDocumentiScreen({super.key});

  @override
  State<AnalisiDocumentiScreen> createState() => _AnalisiDocumentiScreenState();
}

class _AnalisiDocumentiScreenState extends State<AnalisiDocumentiScreen> {
  /// Mentre l'analisi procede la pagina si aggiorna da sola.
  static const _intervallo = Duration(seconds: 15);

  AnalisiDocumenti? _stato;
  String? _errore;
  bool _inCoda = false;
  Timer? _timer;

  String? get _tenantId => SessionService.to.currentTenantId;

  @override
  void initState() {
    super.initState();
    _carica();
    _timer = Timer.periodic(_intervallo, (_) {
      final s = _stato;
      if (s != null && (s.daAnalizzare > 0 || s.testoInLettura > 0)) _carica();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _carica() async {
    final tenantId = _tenantId;
    if (tenantId == null) return;
    try {
      final stato = await DocumentoApi.analisi(tenantId);
      if (mounted) {
        setState(() {
          _stato = stato;
          _errore = null;
        });
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _errore = e.message);
    }
  }

  Future<void> _analizzaMancanti() async {
    final tenantId = _tenantId;
    if (tenantId == null) return;
    setState(() => _inCoda = true);
    try {
      final n = await DocumentoApi.analizzaMancanti(tenantId);
      if (!mounted) return;
      mostraMessaggio(context, n == 0 ? 'Nessun documento da analizzare.' : n == 1 ? 'Un documento in coda per l\'analisi.' : '$n documenti in coda per l\'analisi.');
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _inCoda = false);
        mostraMessaggio(context, e.message, errore: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final stato = _stato;
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Analisi AI dei documenti', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: spazioPagina),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640),
            child: _errore != null && stato == null
                ? Scheda(paddingAll: 20, child: Testo.bodyMedium(_errore!))
                : stato == null
                    ? const Center(child: CircularProgressIndicator())
                    : _contenuto(stato),
          ),
        ]),
      ),
    );
  }

  Widget _contenuto(AnalisiDocumenti s) {
    final puoAnalizzare = SessionService.to.can('documento:write');
    final progresso = s.progresso;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(LucideIcons.sparkles, size: 18, color: context.colori.primary),
          SizedBox(width: 8),
          Expanded(child: Testo.titleSmall(progresso == null ? 'Nessun documento da analizzare' : '${s.analizzati} di ${s.analizzabili} documenti analizzati', fontWeight: FontWeight.w600)),
          IconButton(tooltip: 'Aggiorna', onPressed: _carica, icon: const Icon(LucideIcons.refresh_cw, size: 16)),
        ]),
        if (progresso != null) ...[
          SizedBox(height: 12),
          ClipRRect(borderRadius: BorderRadius.circular(4), child: LinearProgressIndicator(value: progresso, minHeight: 8)),
          SizedBox(height: 8),
          Testo.bodySmall(s.daAnalizzare == 0 ? 'Analisi completata.' : 'L\'analisi gira su questo server, un documento alla volta: può richiedere qualche minuto per documento.', tenue: true),
        ],
        if (s.aiSpenta || s.modelliNonPronti) ...[
          SizedBox(height: 12),
          _avviso(s),
        ],
        if (puoAnalizzare && s.daAnalizzare > 0 && !s.aiSpenta) ...[
          SizedBox(height: 16),
          Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.icon(
              onPressed: _inCoda ? null : _analizzaMancanti,
              icon: const Icon(LucideIcons.play, size: 16),
              label: Text(_inCoda ? 'Analisi in coda' : 'Analizza i mancanti'),
            ),
          ),
        ],
      ])),
      SizedBox(height: 16),
      Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.titleSmall('Dettaglio', fontWeight: FontWeight.w600),
        SizedBox(height: 4),
        _riga(LucideIcons.circle_check, 'Analizzati', s.analizzati),
        _riga(LucideIcons.clock, 'In attesa di analisi', s.daAnalizzare),
        _riga(LucideIcons.scan_text, 'Testo in lettura', s.testoInLettura),
        _riga(LucideIcons.file_x, 'Testo non leggibile', s.senzaTesto),
        _riga(LucideIcons.lock, 'Cifrati, mai analizzati', s.cifrati),
        Divider(height: 24),
        _riga(LucideIcons.files, 'Totale', s.totale, grassetto: true),
        SizedBox(height: 8),
        Testo.bodySmall('I documenti delle categorie sensibili sono cifrati: l\'AI non li legge mai, si trovano cercando il titolo. Il testo degli altri non esce da questo server.', tenue: true),
      ])),
    ]);
  }

  Widget _avviso(AnalisiDocumenti s) {
    final testo = s.aiSpenta
        ? 'L\'analisi AI è spenta su questo server.'
        : [s.modelloDati, s.modelloRicerca].contains('non_raggiungibile')
            ? 'Il servizio AI del server non risponde: l\'analisi riprenderà quando torna disponibile.'
            : 'Su questo server mancano dei modelli AI: chi gestisce il server deve scaricarli. Fino ad allora i documenti restano in attesa.';
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(LucideIcons.triangle_alert, size: 16, color: context.colori.danger),
      SizedBox(width: 8),
      Expanded(child: Testo.bodySmall(testo)),
    ]);
  }

  Widget _riga(IconData icona, String titolo, int n, {bool grassetto = false}) {
    return Padding(
      padding: EdgeInsets.only(top: 10),
      child: Row(children: [
        Icon(icona, size: 16),
        SizedBox(width: 10),
        Expanded(child: Testo.bodyMedium(titolo, fontWeight: grassetto ? FontWeight.w600 : FontWeight.w400)),
        Testo.bodyMedium('$n', fontWeight: FontWeight.w600),
      ]),
    );
  }
}
