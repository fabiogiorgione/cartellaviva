import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/features/ente/view/modalita_firma_selector.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve, formatIsoDate;
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Nuova richiesta a un gruppo o agli iscritti selezionati, con anteprima di
/// chi verrà raggiunto (solo le famiglie collegate).
class EnteNuovaRichiestaScreen extends StatefulWidget {
  const EnteNuovaRichiestaScreen({super.key});

  @override
  State<EnteNuovaRichiestaScreen> createState() => _EnteNuovaRichiestaScreenState();
}

class _EnteNuovaRichiestaScreenState extends State<EnteNuovaRichiestaScreen> {
  String get t => SessionService.to.currentTenantId ?? '';
  final List<String>? iscrittoIds = (Get.arguments is Map ? (Get.arguments as Map)['iscrittoIds'] as List? : null)?.cast<String>();

  String tipo = 'firma';
  final titolo = TextEditingController();
  final istruzioni = TextEditingController();
  DateTime? entroIl;
  String firmatari = 'tutti';
  String? gruppoId;
  List<Gruppo> gruppi = [];
  Uint8List? pdf;
  String? pdfNome;
  AnteprimaInvio? anteprima;
  bool busy = false;
  String? errore;

  /// Da un modello ("Usa"): contenuto e regole precompilati; il PDF è quello del modello.
  final ModelloRichiesta? modello = Get.arguments is Map ? (Get.arguments as Map)['modello'] as ModelloRichiesta? : null;
  bool chiediValidita = false;
  String modalitaFirma = 'cartacea';
  final rinnovo = TextEditingController();
  final conservazione = TextEditingController();

  @override
  void initState() {
    super.initState();
    final m = modello;
    if (m != null) {
      tipo = m.tipo;
      titolo.text = m.titolo;
      istruzioni.text = m.istruzioni ?? '';
      firmatari = m.firmatari;
      chiediValidita = m.chiediValidita;
      rinnovo.text = m.rinnovoGiorni?.toString() ?? '';
      conservazione.text = m.conservazioneMesi?.toString() ?? '';
    }
    EnteApi.gruppi(t).then((g) {
      if (!mounted) return;
      setState(() => gruppi = g);
      if (iscrittoIds != null) _anteprima();
    });
  }

  @override
  void dispose() {
    titolo.dispose();
    istruzioni.dispose();
    rinnovo.dispose();
    conservazione.dispose();
    super.dispose();
  }

  Future<void> _anteprima() async {
    if (gruppoId == null && iscrittoIds == null) return;
    try {
      final a = await EnteApi.anteprima(t, gruppoId: gruppoId, iscrittoIds: gruppoId == null ? iscrittoIds : null);
      if (mounted) setState(() => anteprima = a);
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  Future<void> _scegliPdf() async {
    final res = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: ['pdf'], withData: true);
    final f = res?.files.firstOrNull;
    if (f?.bytes != null) {
      setState(() {
        pdf = f!.bytes;
        pdfNome = f.name;
      });
    }
  }

  Future<void> _invia() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final r = await EnteApi.inviaRichiesta(t,
          tipo: tipo,
          titolo: titolo.text,
          istruzioni: istruzioni.text,
          entroIl: entroIl == null ? null : formatIsoDate(entroIl!),
          firmatari: firmatari,
          gruppoId: gruppoId,
          iscrittoIds: gruppoId == null ? iscrittoIds : null,
          pdf: pdf,
          pdfNome: pdfNome,
          modelloId: modello?.id,
          chiediValidita: chiediValidita,
          rinnovoGiorni: int.tryParse(rinnovo.text.trim()),
          conservazioneMesi: int.tryParse(conservazione.text.trim()),
          modalitaFirma: tipo == 'firma' && modello == null ? modalitaFirma : null);
      Get.offNamed('/ente/richieste/${r.id}');
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final destinatarioOk = gruppoId != null || iscrittoIds != null;
    final haPdf = pdf != null || modello?.allegatoNome != null;
    final raggiunti = (anteprima?.famiglie ?? 0) + (anteprima?.viaLink ?? 0);
    final pronto = titolo.text.trim().isNotEmpty && destinatarioOk && (tipo != 'firma' || haPdf) && raggiunti > 0;
    return PaginaEnte(
      titolo: 'Nuova richiesta',
      sezione: 'Richieste',
      child: Wrap(spacing: 20, runSpacing: 20, crossAxisAlignment: WrapCrossAlignment.start, children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 620),
          child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Testo.labelMedium('Tipo'),
              SizedBox(height: 6),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'firma', label: Text('Firma'), icon: Icon(LucideIcons.pen_line)),
                  ButtonSegment(value: 'invio', label: Text('Invio documento'), icon: Icon(LucideIcons.upload)),
                  ButtonSegment(value: 'presa_visione', label: Text('Presa visione'), icon: Icon(LucideIcons.eye)),
                ],
                selected: {tipo},
                onSelectionChanged: (s) => setState(() => tipo = s.first),
              ),
              SizedBox(height: 16),
              TextField(controller: titolo, maxLength: 200, onChanged: (_) => setState(() {}), decoration: const InputDecoration(labelText: 'Titolo *', hintText: 'Autorizzazione uscita al museo')),
              TextField(controller: istruzioni, maxLines: 3, maxLength: 2000, decoration: const InputDecoration(labelText: 'Istruzioni (facoltative)', hintText: 'Firmi a pagina 2 dove indicato con la X.')),
              SizedBox(height: 8),
              Testo.labelMedium('Destinatari *'),
              if (iscrittoIds != null && gruppoId == null)
                Testo.bodyMedium('${iscrittoIds!.length} iscritti selezionati')
              else
                DropdownButtonFormField<String>(
                  initialValue: gruppoId,
                  decoration: const InputDecoration(isDense: true, hintText: 'Scegli il gruppo'),
                  items: [for (final g in gruppi) DropdownMenuItem(value: g.id, child: Text('${g.nome} (${g.iscritti} iscritti)'))],
                  onChanged: (v) {
                    setState(() => gruppoId = v);
                    _anteprima();
                  },
                ),
              SizedBox(height: 12),
              Testo.labelMedium(tipo == 'presa_visione' ? 'Chi deve leggere' : 'Chi deve firmare'),
              RadioGroup<String>(
                groupValue: firmatari,
                onChanged: (v) => setState(() => firmatari = v ?? 'tutti'),
                child: const Column(children: [
                  RadioListTile(contentPadding: EdgeInsets.zero, dense: true, value: 'tutti', title: Text('Tutti i referenti (es. entrambi i genitori)')),
                  RadioListTile(contentPadding: EdgeInsets.zero, dense: true, value: 'uno', title: Text('Almeno un referente')),
                ]),
              ),
              Row(children: [
                Expanded(child: Text(entroIl == null ? 'Entro il: nessuna data' : 'Entro il ${formatDataBreve(entroIl!)} (compare nelle scadenze delle famiglie)')),
                TextButton(
                  onPressed: () async {
                    final d = await showDatePicker(context: context, initialDate: entroIl ?? DateTime.now().add(const Duration(days: 7)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)));
                    if (d != null) setState(() => entroIl = d);
                  },
                  child: const Text('Scegli'),
                ),
                if (entroIl != null) TextButton(onPressed: () => setState(() => entroIl = null), child: const Text('Togli')),
              ]),
              SizedBox(height: 8),
              Row(children: [
                const Icon(LucideIcons.file_text, size: 18),
                SizedBox(width: 8),
                Expanded(child: Text(pdf != null ? pdfNome! : (modello?.allegatoNome != null ? '${modello!.allegatoNome} (dal modello)' : (tipo == 'firma' ? 'PDF da firmare *' : 'PDF allegato (facoltativo)')))),
                OutlinedButton(onPressed: _scegliPdf, child: Text(pdf == null ? 'Scegli PDF' : 'Cambia')),
              ]),
              SizedBox(height: 12),
              if (tipo == 'firma' && modello == null) ...[
                Testo.labelMedium('Come si firma'),
                SizedBox(height: 6),
                ModalitaFirmaSelector(value: modalitaFirma, onChanged: (v) => setState(() => modalitaFirma = v)),
                SizedBox(height: 12),
              ],
              if (tipo != 'presa_visione' && modello == null) ...[
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: chiediValidita,
                  onChanged: (v) => setState(() => chiediValidita = v),
                  title: const Text('Chiedi fino a quando è valido il documento'),
                ),
                if (chiediValidita)
                  TextField(
                    controller: rinnovo,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Richiedilo in automatico: giorni prima della scadenza (facoltativo)', isDense: true),
                  ),
                SizedBox(height: 8),
              ],
              if (modello != null) Testo.bodySmall('Dal modello "${modello!.nome}": ${modello!.regole.isEmpty ? 'nessuna regola' : modello!.regole}.', tenue: true),
              SizedBox(height: 4),
              Testo.bodySmall('Le risposte sono cifrate e visibili solo alla segreteria e agli operatori del gruppo.', tenue: true),
              if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
              SizedBox(height: 16),
              FilledButton.icon(
                onPressed: busy || !pronto ? null : _invia,
                icon: const Icon(LucideIcons.send, size: 18),
                label: Text(anteprima == null ? 'Invia' : 'Invia a ${anteprima!.iscritti - anteprima!.nonRaggiunti.length} iscritti'),
              ),
            ])),
        ),
        if (anteprima != null)
          SizedBox(
            width: 320,
            child: Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Testo.titleSmall('Anteprima dell\'invio', fontWeight: FontWeight.w600),
                SizedBox(height: 12),
                Testo.bodyMedium('${anteprima!.iscritti} iscritti · ${anteprima!.referenti} referenti'),
                Testo.bodyMedium('${anteprima!.referentiCollegati} referenti nell\'app (${anteprima!.famiglie} famiglie)'),
                Testo.bodyMedium('${anteprima!.viaLink} via link sicuro (senza account: rispondono dal browser con un codice via email)'),
                if (anteprima!.nonRaggiunti.isNotEmpty) ...[
                  SizedBox(height: 12),
                  Testo.bodySmall('${anteprima!.nonRaggiunti.length} iscritti senza referenti raggiungibili (né collegati né con un\'email) non riceveranno la richiesta: ${anteprima!.nonRaggiunti.join(', ')}.', color: ColoreStato.avviso),
                  Testo.bodySmall('Invitali dalla pagina Iscritti.', tenue: true),
                ],
              ])),
          ),
      ]),
    );
  }
}
