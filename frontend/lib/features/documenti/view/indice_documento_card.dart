import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// Scheda "Trovato nel testo" di un documento non cifrato: dati estratti
/// (emittente, data, importo, numero, intestatario), tag, riferimenti e
/// documenti simili. Per i documenti cifrati spiega perché non c'è.
class IndiceDocumentoCard extends StatefulWidget {
  final String tenantId;
  final Documento documento;

  /// Chi può modificare il documento può chiederne una nuova analisi.
  final bool puoRianalizzare;

  const IndiceDocumentoCard({super.key, required this.tenantId, required this.documento, this.puoRianalizzare = false});

  @override
  State<IndiceDocumentoCard> createState() => _IndiceDocumentoCardState();
}

class _IndiceDocumentoCardState extends State<IndiceDocumentoCard> {
  late Future<List<DocumentoSimile>> _simili;
  bool _inCoda = false;

  Documento get d => widget.documento;

  @override
  void initState() {
    super.initState();
    _simili = d.cifrato || d.indice == null ? Future.value(const []) : DocumentoApi.simili(widget.tenantId, d.id);
  }

  Future<void> _rianalizza() async {
    try {
      await DocumentoApi.rianalizza(widget.tenantId, d.id);
      if (!mounted) return;
      setState(() => _inCoda = true);
      mostraMessaggio(context, 'Analisi del testo in coda: ricarica la pagina tra poco.');
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final indice = d.indice;
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(LucideIcons.sparkles, size: 18, color: context.colori.primary),
          SizedBox(width: 8),
          Expanded(child: Testo.titleSmall('Trovato nel testo', fontWeight: FontWeight.w600)),
          if (widget.puoRianalizzare && !d.cifrato && d.ocrStatus == 'completato')
            IconButton(
              tooltip: 'Analizza di nuovo',
              onPressed: _inCoda ? null : _rianalizza,
              icon: const Icon(LucideIcons.refresh_cw, size: 16),
            ),
        ]),
        SizedBox(height: 8),
        if (d.cifrato)
          Testo.bodySmall('I documenti cifrati non vengono analizzati: i dati estratti rivelerebbero il contenuto. Si trovano cercando il titolo.', tenue: true)
        else if (indice == null)
          Testo.bodySmall(d.ocrStatus == 'completato' ? 'Analisi del testo non ancora disponibile.' : 'L\'analisi parte quando il testo del documento è stato letto.', tenue: true)
        else ...[
          if (indice.vuoto) Testo.bodySmall('Nessun dato riconosciuto nel testo.', tenue: true),
          if (indice.emittente != null) _riga('Emesso da', indice.emittente!),
          if (indice.data != null) _riga('Data', formatDataBreve(indice.data!)),
          if (indice.importoLeggibile != null) _riga('Importo', indice.importoLeggibile!),
          if (indice.numero != null) _riga('Numero', indice.numero!),
          if (indice.intestatario != null) _riga('Intestato a', indice.intestatario!),
          if (indice.riferimenti.isNotEmpty) _riga('Codici', indice.riferimenti.join(' · ')),
          if (indice.tag.isNotEmpty) ...[
            SizedBox(height: 4),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final t in indice.tag) Chip(label: Text(t), avatar: const Icon(LucideIcons.tag, size: 14), visualDensity: VisualDensity.compact),
            ]),
          ],
          SizedBox(height: 8),
          Testo.bodySmall('Dati letti automaticamente: controllali sul documento.', tenue: true),
          _documentiSimili(),
        ],
      ]));
  }

  Widget _documentiSimili() => FutureBuilder<List<DocumentoSimile>>(
        future: _simili,
        builder: (context, snapshot) {
          final simili = snapshot.data ?? const [];
          // In caricamento, in errore o vuoto: la sezione semplicemente non compare.
          if (simili.isEmpty) return const SizedBox.shrink();
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(height: 16),
            Testo.labelMedium('Documenti collegati', fontWeight: FontWeight.w600),
            SizedBox(height: 4),
            for (final s in simili)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                leading: Icon(s.motivo == 'riferimento' ? LucideIcons.link_2 : LucideIcons.files, size: 18, color: context.colori.primary),
                title: Text(s.titolo, maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text([s.motivoLeggibile, if (s.categoria != null) s.categoria!.nome, if (s.dataDocumento != null) formatDataBreve(s.dataDocumento!)].join(' · ')),
                onTap: () => Get.toNamed('/documento/${s.id}'),
              ),
          ]);
        },
      );

  Widget _riga(String etichetta, String valore) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 110, child: Testo.bodySmall(etichetta, tenue: true)),
          Expanded(child: SelectableText(valore)),
        ]),
      );
}
