import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:printing/printing.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/model/anteprima.dart';

/// Visualizzatore della scheda "Anteprima": PDF (pagine disegnate nel browser)
/// o immagine (con zoom). Gestisce l'attesa mentre il server converte un
/// documento Office (riprova da solo) e il caso "non disponibile", con il motivo.
class VisualizzatoreAnteprima extends StatefulWidget {
  /// Richiesta dell'anteprima (es. DocumentoApi.anteprima o EnteApi.anteprimaRisposta).
  final Future<EsitoAnteprima> Function() carica;

  /// Pulsanti sopra il contenuto (es. "Apri a parte" / "Scarica l'originale").
  final List<Widget> azioni;

  /// Se presente, nel caso "non disponibile" compare "Riprova la conversione".
  final Future<void> Function()? onRigenera;

  const VisualizzatoreAnteprima({super.key, required this.carica, this.azioni = const [], this.onRigenera});

  @override
  State<VisualizzatoreAnteprima> createState() => _VisualizzatoreAnteprimaState();
}

class _VisualizzatoreAnteprimaState extends State<VisualizzatoreAnteprima> {
  /// Mentre il server converte si riprova ogni 4 secondi, per circa 2 minuti.
  static const _attesa = Duration(seconds: 4);
  static const _tentativiMax = 30;

  EsitoAnteprima? esito;
  String? errore;
  int tentativi = 0;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _carica() async {
    _timer?.cancel();
    try {
      final r = await widget.carica();
      if (!mounted) return;
      setState(() {
        esito = r;
        errore = null;
      });
      if (r is AnteprimaInPreparazione && tentativi < _tentativiMax) {
        tentativi++;
        _timer = Timer(_attesa, _carica);
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  Future<void> _rigenera() async {
    try {
      await widget.onRigenera!();
      setState(() {
        esito = const AnteprimaInPreparazione();
        tentativi = 0;
      });
      _timer = Timer(_attesa, _carica);
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  Widget _messaggio(IconData icona, String titolo, String testo, {List<Widget> pulsanti = const []}) => Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icona, size: 40, color: Theme.of(context).colorScheme.primary),
            SizedBox(height: 12),
            Testo.titleSmall(titolo, fontWeight: FontWeight.w600, textAlign: TextAlign.center),
            SizedBox(height: 6),
            Testo.bodySmall(testo, textAlign: TextAlign.center, tenue: true),
            if (pulsanti.isNotEmpty) ...[SizedBox(height: 16), Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: pulsanti)],
          ]),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final contenuto = switch ((errore, esito)) {
      (final String e, _) => _messaggio(LucideIcons.circle_alert, 'Anteprima non caricata', e, pulsanti: [OutlinedButton(onPressed: _carica, child: const Text('Riprova'))]),
      (_, null) => const Center(child: CircularProgressIndicator()),
      (_, AnteprimaInPreparazione()) => tentativi < _tentativiMax
          ? _messaggio(LucideIcons.loader, 'Anteprima in preparazione', 'Stiamo convertendo il documento per mostrarlo qui: ci vuole qualche secondo.')
          : _messaggio(LucideIcons.clock, 'Anteprima non ancora pronta', 'La conversione sta richiedendo più del solito.', pulsanti: [
              OutlinedButton(
                onPressed: () {
                  tentativi = 0;
                  _carica();
                },
                child: const Text('Controlla di nuovo'),
              ),
            ]),
      (_, AnteprimaNonDisponibile(:final motivo)) => _messaggio(LucideIcons.file_x, 'Anteprima non disponibile', '$motivo\nIl file originale si può comunque aprire o scaricare.', pulsanti: [
          if (widget.onRigenera != null) OutlinedButton.icon(onPressed: _rigenera, icon: const Icon(LucideIcons.refresh_cw, size: 16), label: const Text('Riprova la conversione')),
        ]),
      (_, final AnteprimaPronta a) when a.isPdf => PaginePdf(bytes: a.bytes, key: ValueKey(a.bytes.length)),
      (_, final AnteprimaPronta a) when a.isTesto => TestoAnteprima(testo: a.testo),
      (_, final AnteprimaPronta a) when a.mime.startsWith('image/') => InteractiveViewer(maxScale: 5, child: Center(child: Image.memory(a.bytes, gaplessPlayback: true))),
      (_, AnteprimaPronta()) => _messaggio(LucideIcons.file_question_mark, 'Anteprima non disponibile', 'Questo tipo di file non si può mostrare qui: aprilo o scaricalo.'),
    };
    if (widget.azioni.isEmpty) return contenuto;
    return Stack(children: [
      Positioned.fill(child: contenuto),
      Positioned(top: 4, right: 4, child: Wrap(spacing: 4, children: widget.azioni)),
    ]);
  }
}

/// Un file di testo (TXT), selezionabile. I file molto lunghi si mostrano solo
/// in parte: disegnare milioni di caratteri bloccherebbe la pagina.
class TestoAnteprima extends StatelessWidget {
  static const maxCaratteri = 200000;

  final String testo;

  const TestoAnteprima({super.key, required this.testo});

  @override
  Widget build(BuildContext context) {
    final tagliato = testo.length > maxCaratteri;
    return SingleChildScrollView(
      padding: EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SelectableText(tagliato ? testo.substring(0, maxCaratteri) : testo, style: const TextStyle(fontFamily: 'monospace', fontSize: 13, height: 1.45)),
        if (tagliato) ...[
          SizedBox(height: 12),
          Testo.bodySmall('Mostrati i primi $maxCaratteri caratteri su ${testo.length}: apri il file per leggerlo tutto.', tenue: true),
        ],
      ]),
    );
  }
}

/// Pagine di un PDF disegnate nel browser (pdf.js tramite "printing"), con zoom.
class PaginePdf extends StatefulWidget {
  final Uint8List bytes;

  const PaginePdf({super.key, required this.bytes});

  @override
  State<PaginePdf> createState() => _PaginePdfState();
}

class _PaginePdfState extends State<PaginePdf> {
  final List<Uint8List> pagine = [];
  String? errore;
  bool finito = false;
  StreamSubscription<PdfRaster>? _sub;

  @override
  void initState() {
    super.initState();
    _sub = Printing.raster(widget.bytes, dpi: 110).listen(
      (p) async {
        final png = await p.toPng();
        if (mounted) setState(() => pagine.add(png));
      },
      onError: (Object e) {
        if (mounted) setState(() => errore = 'Anteprima del PDF non riuscita: aprilo a parte.');
      },
      onDone: () {
        if (mounted) setState(() => finito = true);
      },
    );
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (errore != null) return Center(child: Testo.bodyMedium(errore!));
    if (pagine.isEmpty) return const Center(child: CircularProgressIndicator());
    return InteractiveViewer(
      maxScale: 4,
      child: ListView.separated(
        padding: EdgeInsets.fromLTRB(16, 48, 16, 16),
        itemCount: pagine.length + (finito ? 0 : 1),
        separatorBuilder: (_, __) => SizedBox(height: 12),
        itemBuilder: (_, i) => i < pagine.length
            ? DecoratedBox(decoration: BoxDecoration(border: Border.all(color: Colors.grey.shade300)), child: Image.memory(pagine[i]))
            : const Center(child: Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator())),
      ),
    );
  }
}
