import 'dart:convert';

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
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/features/ente/view/iscritto_dialog.dart';
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// Iscritti dell'ente (dei propri gruppi per un operatore), con referenti e
/// stato degli inviti; import da CSV; inviti; richiesta ai selezionati.
class EnteIscrittiScreen extends StatefulWidget {
  const EnteIscrittiScreen({super.key});

  @override
  State<EnteIscrittiScreen> createState() => _EnteIscrittiScreenState();
}

class _EnteIscrittiScreenState extends State<EnteIscrittiScreen> {
  SessionService get session => SessionService.to;
  String get t => session.currentTenantId ?? '';

  List<Gruppo> gruppi = [];
  String? gruppoId = Get.arguments is String ? Get.arguments as String : null;
  String filtro = '';
  String q = '';
  List<Iscritto> iscritti = [];
  TotaliIscritti totali = const TotaliIscritti();
  final Set<String> selezionati = {};
  bool loading = true;
  String? errore;

  bool get canManage => session.can('iscritto:manage');
  bool get canInvite => session.can('iscritto:invite');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      if (gruppi.isEmpty) gruppi = await EnteApi.gruppi(t);
      final res = await EnteApi.iscritti(t, gruppoId: gruppoId, q: q, filtro: filtro.isEmpty ? null : filtro);
      iscritti = res.iscritti;
      totali = res.totali;
      selezionati.retainWhere((id) => iscritti.any((i) => i.id == id));
      errore = null;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  void _snack(String m) => mostraMessaggio(context, m);

  Future<void> _apri([Iscritto? i]) async {
    final changed = await showIscrittoDialog(context, tenantId: t, iscritto: i, gruppi: gruppi, gruppoPredefinito: gruppoId, canManage: canManage);
    if (changed) await _load();
  }

  Future<void> _invita() async {
    final ids = <String>[];
    for (final i in iscritti.where((i) => selezionati.contains(i.id))) {
      for (final r in i.referenti) {
        if (r.id != null && r.email != null && const ['da_invitare', 'invitato', 'revocato'].contains(r.stato)) ids.add(r.id!);
      }
    }
    if (ids.isEmpty && gruppoId == null) {
      _snack('Seleziona gli iscritti (o filtra un gruppo) con referenti da invitare.');
      return;
    }
    final perGruppo = ids.isEmpty;
    final gruppoNome = gruppi.where((g) => g.id == gruppoId).firstOrNull?.nome;
    final ok = await chiediConferma(
      context,
      titolo: 'Invitare i referenti a collegarsi?',
      testo: perGruppo
          ? 'Riceveranno l\'invito per email tutti i referenti del gruppo $gruppoNome non ancora collegati.'
          : 'Riceveranno l\'invito per email ${ids.length} referenti degli iscritti selezionati. Chi l\'aveva già ricevuto riceve un nuovo link.',
      azione: 'Invia gli inviti',
    );
    if (!ok) return;
    try {
      final esito = perGruppo ? await EnteApi.invita(t, gruppoId: gruppoId) : await EnteApi.invita(t, referenteIds: ids);
      _snack('Inviti inviati: ${esito.inviati}${esito.saltati > 0 ? ' (${esito.saltati} saltati: già collegati o senza email)' : ''}.');
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  Future<void> _importa() async {
    final picked = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: ['csv', 'txt'], withData: true);
    final bytes = picked?.files.firstOrNull?.bytes;
    if (bytes == null) return;
    final csv = utf8.decode(bytes, allowMalformed: true);
    try {
      final anteprima = await EnteApi.importaCsv(t, csv, applica: false);
      if (!mounted) return;
      final righe = (anteprima['righe'] as List).cast<Map<String, dynamic>>();
      final conErrori = righe.where((r) => (r['errori'] as List).isNotEmpty).toList();
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Anteprima dell\'import'),
          content: SizedBox(
            width: 560,
            height: 420,
            child: ListView(children: [
              Testo.bodyMedium('${anteprima['nuovi']} nuovi iscritti, ${anteprima['esistenti']} già presenti (aggiunti al gruppo), ${anteprima['errori']} righe con errori.'),
              SizedBox(height: 6),
              Testo.bodySmall('Gli inviti alle famiglie NON partono con l\'import: li confermi dopo dalla lista.', tenue: true),
              if (conErrori.isNotEmpty) ...[
                SizedBox(height: 12),
                Testo.labelMedium('Righe che non verranno importate'),
                for (final r in conErrori) Testo.bodySmall('Riga ${r['riga']}: ${r['nome']} ${r['cognome']} — ${(r['errori'] as List).join(', ')}'),
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(onPressed: (anteprima['valide'] as num) > 0 ? () => Navigator.pop(ctx, true) : null, child: Text('Importa ${anteprima['valide']} righe')),
          ],
        ),
      );
      if (ok != true) return;
      final esito = await EnteApi.importaCsv(t, csv, applica: true);
      _snack('Importati ${esito['nuovi']} nuovi iscritti.');
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: 'Iscritti',
      sezione: 'Iscritti',
      azioni: [
        const AiutoLink(guida: 'ente-import-csv', etichetta: 'Guida all\'import'),
        if (canManage) ...[
          OutlinedButton.icon(onPressed: _importa, icon: const Icon(LucideIcons.file_spreadsheet, size: 18), label: const Text('Importa CSV')),
          FilledButton.icon(onPressed: () => _apri(), icon: const Icon(LucideIcons.user_plus, size: 18), label: const Text('Nuovo iscritto')),
        ],
      ],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 12, runSpacing: 12, children: [
          numeroCard('Iscritti', '${totali.iscritti}'),
          numeroCard('Referenti collegati', '${totali.referentiCollegati} su ${totali.referenti}'),
          numeroCard('Senza email', '${totali.iscrittiSenzaEmail}'),
        ]),
        SizedBox(height: 16),
        Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
          SizedBox(
            width: 240,
            child: TextField(
              decoration: const InputDecoration(prefixIcon: Icon(LucideIcons.search, size: 18), hintText: 'Cerca iscritto', isDense: true),
              onSubmitted: (v) {
                q = v;
                _load();
              },
            ),
          ),
          DropdownButton<String?>(
            value: gruppoId,
            hint: const Text('Tutti i gruppi'),
            items: [const DropdownMenuItem<String?>(value: null, child: Text('Tutti i gruppi')), for (final g in gruppi) DropdownMenuItem<String?>(value: g.id, child: Text(g.nome))],
            onChanged: (v) {
              gruppoId = v;
              _load();
            },
          ),
          DropdownButton<String>(
            value: filtro,
            items: const [
              DropdownMenuItem(value: '', child: Text('Tutti i referenti')),
              DropdownMenuItem(value: 'da_invitare', child: Text('Da invitare')),
              DropdownMenuItem(value: 'invitati', child: Text('Invito non accettato')),
              DropdownMenuItem(value: 'non_collegati', child: Text('Non tutti collegati')),
              DropdownMenuItem(value: 'senza_email', child: Text('Senza email')),
            ],
            onChanged: (v) {
              filtro = v ?? '';
              _load();
            },
          ),
          if (canInvite) OutlinedButton.icon(onPressed: _invita, icon: const Icon(LucideIcons.mail, size: 18), label: Text(selezionati.isEmpty ? 'Invita il gruppo' : 'Invita i selezionati (${selezionati.length})')),
          if (session.can('richiesta_ente:send') && selezionati.isNotEmpty)
            FilledButton.icon(
              onPressed: () => Get.toNamed('/ente/richieste/nuova', arguments: {'iscrittoIds': selezionati.toList()}),
              icon: const Icon(LucideIcons.send, size: 18),
              label: Text('Richiesta ai selezionati (${selezionati.length})'),
            ),
        ]),
        SizedBox(height: 16),
        if (loading)
          const Center(child: CircularProgressIndicator())
        else if (errore != null)
          erroreCard(errore!, _load)
        else if (iscritti.isEmpty)
          StatoVuoto(
            icona: LucideIcons.users,
            titolo: 'Nessun iscritto',
            testo: 'Inserite gli iscritti con i loro referenti (genitori o tutori): uno alla volta oppure importandoli da un file esportato da Excel o dal registro elettronico.',
            azione: canManage ? 'Importa da file' : null,
            onAzione: canManage ? _importa : null,
            guida: 'ente-import-csv',
          )
        else
          Scheda(paddingAll: 0, child: Column(children: [
              for (var k = 0; k < iscritti.length; k++) ...[if (k > 0) const Divider(height: 1), _riga(iscritti[k])],
            ])),
        SizedBox(height: 8),
        Testo.bodySmall('● collegato · ◐ invito inviato · ○ da invitare · ! manca l\'email', tenue: true),
      ]),
    );
  }

  Widget _riga(Iscritto i) {
    String simbolo(Referente r) => switch (r.stato) {
          'collegato' => '●',
          'invitato' => '◐',
          'non_sono_io' || 'rifiutato' => '✕',
          _ => r.email == null ? '!' : '○',
        };
    return CheckboxListTile(
      controlAffinity: ListTileControlAffinity.leading,
      value: selezionati.contains(i.id),
      onChanged: (v) => setState(() => v == true ? selezionati.add(i.id) : selezionati.remove(i.id)),
      title: InkWell(onTap: () => _apri(i), child: Text(i.nomeCompleto, style: const TextStyle(fontWeight: FontWeight.w600))),
      subtitle: Text([
        i.gruppi.where((g) => !g.archiviato).map((g) => g.nome).join(', '),
        if (i.referenti.isEmpty) '! nessun referente' else i.referenti.map((r) => '${simbolo(r)} ${r.nomeCompleto}').join('   '),
      ].join(' · ')),
      secondary: IconButton(icon: const Icon(LucideIcons.chevron_right, size: 18), onPressed: () => _apri(i)),
    );
  }
}
