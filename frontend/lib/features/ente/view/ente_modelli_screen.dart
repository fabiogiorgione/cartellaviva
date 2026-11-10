import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/features/aiuto/aiuto_widgets.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/features/ente/view/modalita_firma_selector.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// "Modelli e regole": richieste riusabili con validità, rinnovo alla scadenza,
/// conservazione delle copie e invio ricorrente a tutto l'ente.
class EnteModelliScreen extends StatefulWidget {
  const EnteModelliScreen({super.key});

  @override
  State<EnteModelliScreen> createState() => _EnteModelliScreenState();
}

class _EnteModelliScreenState extends State<EnteModelliScreen> {
  SessionService get session => SessionService.to;
  String get t => session.currentTenantId ?? '';
  late Future<List<ModelloRichiesta>> _future = EnteApi.modelli(t);

  bool get canSend => session.can('richiesta_ente:send');

  void _reload() => setState(() => _future = EnteApi.modelli(t));

  Future<void> _modifica([ModelloRichiesta? m]) async {
    final saved = await showDialog<bool>(context: context, builder: (_) => _ModelloDialog(tenantId: t, modello: m, segreteria: session.can('ente:tutti_i_gruppi')));
    if (saved == true) _reload();
  }

  Future<void> _elimina(ModelloRichiesta m) async {
    try {
      await EnteApi.eliminaModello(t, m.id);
      _reload();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: 'Modelli e regole',
      sezione: 'Modelli',
      azioni: [const AiutoLink(guida: 'ente-modelli'), if (canSend) FilledButton.icon(onPressed: () => _modifica(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuovo modello'))],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.bodySmall('Un modello si riusa per le richieste di ogni anno. Può chiedere la data di validità (es. certificato medico) e ripartire da solo al solo interessato prima della scadenza, '
          'partire ogni anno in una data verso tutto l\'ente, ed eliminare le copie ricevute dopo un certo numero di mesi.', tenue: true),
        SizedBox(height: 16),
        FutureBuilder<List<ModelloRichiesta>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
            final modelli = snap.data!;
            if (modelli.isEmpty) {
              return StatoVuoto(
                icona: LucideIcons.layout_template,
                titolo: 'Nessun modello',
                testo: 'Un modello serve per le richieste che tornano ogni anno (es. certificato medico sportivo): può chiedere la validità, ripartire da solo prima della scadenza e cancellare le copie dopo qualche mese.',
                azione: canSend ? 'Crea il primo modello' : null,
                onAzione: canSend ? () => _modifica() : null,
                guida: 'ente-modelli',
              );
            }
            return Scheda(paddingAll: 0, child: Column(children: [
                for (final m in modelli)
                  ListTile(
                    leading: Icon(m.tipo == 'firma' ? LucideIcons.pen_line : (m.tipo == 'invio' ? LucideIcons.upload : LucideIcons.eye)),
                    title: Text('${m.nome} · ${tipoRichiestaLabel(m.tipo)}'),
                    subtitle: Text([
                      if (m.regole.isNotEmpty) m.regole,
                      if (m.prossimoInvio != null) 'prossimo invio ${formatDataBreve(m.prossimoInvio!)}',
                      if (m.allegatoNome != null) 'PDF: ${m.allegatoNome}',
                    ].join(' · ')),
                    trailing: canSend
                        ? Wrap(spacing: 4, children: [
                            TextButton(onPressed: () => Get.toNamed('/ente/richieste/nuova', arguments: {'modello': m}), child: const Text('Usa')),
                            IconButton(icon: const Icon(LucideIcons.pencil, size: 18), onPressed: () => _modifica(m)),
                            IconButton(icon: const Icon(LucideIcons.trash, size: 18), onPressed: () => _elimina(m)),
                          ])
                        : null,
                  ),
              ]));
          },
        ),
      ]),
    );
  }
}

class _ModelloDialog extends StatefulWidget {
  final String tenantId;
  final ModelloRichiesta? modello;
  final bool segreteria;

  const _ModelloDialog({required this.tenantId, this.modello, required this.segreteria});

  @override
  State<_ModelloDialog> createState() => _ModelloDialogState();
}

class _ModelloDialogState extends State<_ModelloDialog> {
  late final m = widget.modello;
  late final nome = TextEditingController(text: m?.nome ?? '');
  late final titolo = TextEditingController(text: m?.titolo ?? '');
  late final istruzioni = TextEditingController(text: m?.istruzioni ?? '');
  late final rinnovo = TextEditingController(text: m?.rinnovoGiorni?.toString() ?? '');
  late final conservazione = TextEditingController(text: m?.conservazioneMesi?.toString() ?? '');
  late final giorniRisposta = TextEditingController(text: (m?.giorniRisposta ?? 14).toString());
  late String tipo = m?.tipo ?? 'firma';
  late String firmatari = m?.firmatari ?? 'tutti';
  late bool chiediValidita = m?.chiediValidita ?? false;
  late String modalitaFirma = m?.modalitaFirma ?? 'cartacea';
  late bool ricorrente = m?.ricorrenzaGiorno != null;
  late int giorno = m?.ricorrenzaGiorno ?? 15;
  late int mese = m?.ricorrenzaMese ?? 9;
  Uint8List? pdf;
  String? pdfNome;
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    for (final c in [nome, titolo, istruzioni, rinnovo, conservazione, giorniRisposta]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await EnteApi.salvaModello(widget.tenantId, id: m?.id, pdf: pdf, pdfNome: pdfNome, campi: {
        'nome': nome.text,
        'tipo': tipo,
        'titolo': titolo.text,
        'istruzioni': istruzioni.text,
        'firmatari': firmatari,
        'modalitaFirma': tipo == 'firma' ? modalitaFirma : null,
        'chiediValidita': chiediValidita ? '1' : null,
        'rinnovoGiorni': rinnovo.text.trim(),
        'conservazioneMesi': conservazione.text.trim(),
        'giorniRisposta': giorniRisposta.text.trim(),
        'ricorrenzaGiorno': ricorrente ? '$giorno' : null,
        'ricorrenzaMese': ricorrente ? '$mese' : null,
      });
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    InputDecoration deco(String l, {String? h}) => InputDecoration(labelText: l, helperText: h, isDense: true);
    return AlertDialog(
      title: Text(m == null ? 'Nuovo modello' : 'Modifica modello'),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: nome, decoration: deco('Nome del modello', h: 'Es. Certificato medico sportivo')),
            SizedBox(height: 12),
            SegmentedButton<String>(
              segments: const [ButtonSegment(value: 'firma', label: Text('Firma')), ButtonSegment(value: 'invio', label: Text('Invio')), ButtonSegment(value: 'presa_visione', label: Text('Presa visione'))],
              selected: {tipo},
              onSelectionChanged: (s) => setState(() => tipo = s.first),
            ),
            SizedBox(height: 12),
            TextField(controller: titolo, decoration: deco('Titolo della richiesta')),
            SizedBox(height: 12),
            TextField(controller: istruzioni, maxLines: 2, decoration: deco('Istruzioni (facoltative)')),
            SizedBox(height: 8),
            Row(children: [
              Expanded(child: Text(pdf != null ? pdfNome! : (m?.allegatoNome ?? (tipo == 'firma' ? 'PDF da firmare *' : 'PDF (facoltativo)')))),
              OutlinedButton(
                onPressed: () async {
                  final r = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: ['pdf'], withData: true);
                  final f = r?.files.firstOrNull;
                  if (f?.bytes != null) {
                    setState(() {
                      pdf = f!.bytes;
                      pdfNome = f.name;
                    });
                  }
                },
                child: const Text('Scegli PDF'),
              ),
            ]),
            RadioGroup<String>(
              groupValue: firmatari,
              onChanged: (v) => setState(() => firmatari = v ?? 'tutti'),
              child: const Row(children: [
                Expanded(child: RadioListTile(dense: true, value: 'tutti', title: Text('Tutti i referenti'))),
                Expanded(child: RadioListTile(dense: true, value: 'uno', title: Text('Almeno uno'))),
              ]),
            ),
            if (tipo == 'firma') ...[
              ModalitaFirmaSelector(value: modalitaFirma, onChanged: (v) => setState(() => modalitaFirma = v)),
              SizedBox(height: 8),
            ],
            const Divider(),
            if (tipo != 'presa_visione') ...[
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: chiediValidita || rinnovo.text.trim().isNotEmpty,
                onChanged: (v) => setState(() => chiediValidita = v),
                title: const Text('Chiedi fino a quando è valido il documento'),
              ),
              TextField(controller: rinnovo, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: deco('Rinnovo automatico: giorni prima della scadenza', h: 'Vuoto = nessun rinnovo. La nuova richiesta va al solo interessato.')),
              SizedBox(height: 12),
            ],
            TextField(controller: conservazione, keyboardType: TextInputType.number, decoration: deco('Conservazione delle copie ricevute (mesi)', h: 'Vuoto = senza limite. Poi la copia dell\'ente viene eliminata.')),
            SizedBox(height: 12),
            if (widget.segreteria) ...[
              SwitchListTile(contentPadding: EdgeInsets.zero, value: ricorrente, onChanged: (v) => setState(() => ricorrente = v), title: const Text('Invia ogni anno a tutto l\'ente')),
              if (ricorrente)
                Row(children: [
                  const Text('Il giorno '),
                  DropdownButton<int>(value: giorno, items: [for (var g = 1; g <= 31; g++) DropdownMenuItem(value: g, child: Text('$g'))], onChanged: (v) => setState(() => giorno = v ?? giorno)),
                  const Text(' del mese '),
                  DropdownButton<int>(value: mese, items: [for (var x = 1; x <= 12; x++) DropdownMenuItem(value: x, child: Text('$x'))], onChanged: (v) => setState(() => mese = v ?? mese)),
                  SizedBox(width: 12),
                  Expanded(child: TextField(controller: giorniRisposta, keyboardType: TextInputType.number, decoration: deco('Giorni per rispondere'))),
                ]),
            ],
            if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}
