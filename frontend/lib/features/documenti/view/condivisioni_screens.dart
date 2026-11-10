import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter/services.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/condivisione_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/famiglia/model/tenant.dart' show formatBytes;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

String _quando(DateTime d) => '${formatDataBreve(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

const Map<int, String> durateCondivisione = {1: '1 ora', 24: '1 giorno', 72: '3 giorni', 168: '1 settimana', 720: '30 giorni'};
const List<int> limitiDownload = [1, 3, 10, 50];

// ---------------------------------------------------------------------------
// "Condividi" da un documento
// ---------------------------------------------------------------------------

/// Crea una condivisione esterna e mostra link e codice (una volta sola).
Future<void> showCondividiDialog(BuildContext context, {required List<({String id, String titolo})> documenti}) =>
    showDialog<void>(context: context, barrierDismissible: false, builder: (_) => _CondividiDialog(documenti: documenti));

class _CondividiDialog extends StatefulWidget {
  final List<({String id, String titolo})> documenti;

  const _CondividiDialog({required this.documenti});

  @override
  State<_CondividiDialog> createState() => _CondividiDialogState();
}

class _CondividiDialogState extends State<_CondividiDialog> {
  final nota = TextEditingController();
  int ore = 168;
  int maxDownload = 10;
  bool busy = false;
  String? errore;
  ({String url, String codice})? creata;

  @override
  void dispose() {
    nota.dispose();
    super.dispose();
  }

  Future<void> _crea() async {
    final tenantId = SessionService.to.currentTenantId;
    if (tenantId == null) return;
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final r = await CondivisioneApi.crea(tenantId, documentiIds: widget.documenti.map((d) => d.id).toList(), ore: ore, maxDownload: maxDownload, nota: nota.text);
      creata = (url: r.url, codice: r.codice);
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  Widget _copiabile(String etichetta, String valore, {bool grande = false}) => Container(
        margin: EdgeInsets.only(bottom: 10),
        padding: EdgeInsets.all(12),
        decoration: BoxDecoration(border: Border.all(color: Colors.grey.shade400), borderRadius: BorderRadius.circular(6)),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.labelSmall(etichetta, tenue: true),
              SelectableText(valore, style: TextStyle(fontSize: grande ? 22 : 13, fontWeight: grande ? FontWeight.w700 : FontWeight.w400, letterSpacing: grande ? 3 : 0)),
            ]),
          ),
          IconButton(
            tooltip: 'Copia',
            icon: const Icon(LucideIcons.copy, size: 18),
            onPressed: () {
              Clipboard.setData(ClipboardData(text: valore));
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$etichetta copiato')));
            },
          ),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    final c = creata;
    return AlertDialog(
      title: Text(c == null ? 'Condividi con una persona esterna' : 'Condivisione pronta'),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: c != null
              ? Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  _copiabile('Link', c.url),
                  _copiabile('Codice', c.codice, grande: true),
                  Container(
                    padding: EdgeInsets.all(10),
                    decoration: BoxDecoration(color: Colors.orange.withAlpha(30), borderRadius: BorderRadius.circular(6)),
                    child: Testo.bodySmall('Manda link e codice su canali diversi (es. il link per email e il codice a voce o per SMS). '
                      'Il codice non sarà più mostrato: se lo perdi, crea una nuova condivisione.'),
                  ),
                ])
              : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Testo.bodySmall(widget.documenti.length == 1 ? '"${widget.documenti.first.titolo}"' : '${widget.documenti.length} documenti', fontWeight: FontWeight.w500),
                  SizedBox(height: 4),
                  Testo.bodySmall('Chi riceve il link non ha bisogno di un account: per aprirlo serve anche il codice che ti mostreremo.', tenue: true),
                  SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: ore,
                    decoration: const InputDecoration(labelText: 'Valida per', isDense: true),
                    items: [for (final e in durateCondivisione.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                    onChanged: (v) => setState(() => ore = v ?? ore),
                  ),
                  SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: maxDownload,
                    decoration: const InputDecoration(labelText: 'Numero massimo di download', isDense: true),
                    items: [for (final n in limitiDownload) DropdownMenuItem(value: n, child: Text('$n'))],
                    onChanged: (v) => setState(() => maxDownload = v ?? maxDownload),
                  ),
                  SizedBox(height: 12),
                  TextField(
                    controller: nota,
                    maxLength: 120,
                    decoration: const InputDecoration(labelText: 'Per chi è (solo per te, facoltativo)', hintText: 'Dott. Bianchi', isDense: true),
                  ),
                  if (errore != null) Testo.bodySmall(errore!, color: ColoreStato.errore),
                ]),
        ),
      ),
      actions: c != null
          ? [FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Fatto'))]
          : [
              TextButton(onPressed: busy ? null : () => Navigator.pop(context), child: const Text('Annulla')),
              FilledButton.icon(onPressed: busy ? null : _crea, icon: const Icon(LucideIcons.link, size: 16), label: const Text('Crea link e codice')),
            ],
    );
  }
}

// ---------------------------------------------------------------------------
// Elenco delle proprie condivisioni (/condivisioni)
// ---------------------------------------------------------------------------

class CondivisioniScreen extends StatefulWidget {
  const CondivisioniScreen({super.key});

  @override
  State<CondivisioniScreen> createState() => _CondivisioniScreenState();
}

class _CondivisioniScreenState extends State<CondivisioniScreen> {
  String? get tenantId => SessionService.to.currentTenantId;
  late Future<List<Condivisione>> _future = _carica();

  Future<List<Condivisione>> _carica() => tenantId == null ? Future.value(const []) : CondivisioneApi.elenco(tenantId!);

  void _reload() => setState(() => _future = _carica());

  Future<void> _revoca(Condivisione c) async {
    try {
      await CondivisioneApi.revoca(tenantId!, c.id);
      _reload();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Condivisioni esterne', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: 4),
          Testo.bodySmall('Link con codice per chi non ha un account. Per crearne uno apri un documento e scegli "Condividi".', tenue: true),
          SizedBox(height: spazioPagina),
          FutureBuilder<List<Condivisione>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snap.hasError) return Scheda(paddingAll: 20, child: Testo.bodyMedium(snap.error.toString()));
              final righe = snap.data!;
              if (righe.isEmpty) return Scheda(paddingAll: 24, child: Testo.bodyMedium('Nessuna condivisione.'));
              return Scheda(paddingAll: 0, child: Column(children: [
                  for (final c in righe)
                    ListTile(
                      leading: Icon(c.attiva ? LucideIcons.link : LucideIcons.link_2_off, color: c.attiva ? ColoreStato.successo : Colors.grey),
                      title: Text([if (c.nota != null) c.nota!, c.documenti.map((d) => d.titolo).join(', ')].join(' · '), maxLines: 2, overflow: TextOverflow.ellipsis),
                      subtitle: Text([
                        c.statoLabel,
                        if (c.attiva) 'scade il ${_quando(c.scadeIl)}',
                        'scaricati ${c.download}/${c.maxDownload}',
                        c.primoAccessoIl == null ? 'mai aperta' : 'aperta il ${_quando(c.primoAccessoIl!)}',
                        if (c.creataDa.isNotEmpty) 'da ${c.creataDa}',
                      ].join(' · ')),
                      trailing: c.attiva ? TextButton(onPressed: () => _revoca(c), child: Text('Revoca', style: TextStyle(color: ColoreStato.errore))) : null,
                    ),
                ]));
            },
          ),
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Pagina pubblica del destinatario (/condivisione?token=)
// ---------------------------------------------------------------------------

class CondivisioneLinkScreen extends StatefulWidget {
  const CondivisioneLinkScreen({super.key});

  @override
  State<CondivisioneLinkScreen> createState() => _CondivisioneLinkScreenState();
}

class _CondivisioneLinkScreenState extends State<CondivisioneLinkScreen> {
  final String token = Get.parameters['token'] ?? '';
  final codice = TextEditingController();
  ({String stato, DateTime scadeIl, int documenti, String condivisoDa})? anteprima;
  ({String sessione, String condivisoDa, int downloadRimasti, List<DocumentoCondiviso> documenti})? accesso;
  String? errore;
  bool busy = true;

  @override
  void initState() {
    super.initState();
    CondivisioneApi.anteprima(token).then((a) => anteprima = a, onError: (e) => errore = e is ApiException ? e.message : '$e').whenComplete(() {
      if (mounted) setState(() => busy = false);
    });
  }

  @override
  void dispose() {
    codice.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() f) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await f();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final a = anteprima;
    final acc = accesso;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                  Row(children: [const Icon(LucideIcons.shield_check), SizedBox(width: 8), Testo.titleMedium('Documenti condivisi con CartellaViva', fontWeight: FontWeight.w600)]),
                  SizedBox(height: 12),
                  if (a == null && busy) const Center(child: CircularProgressIndicator()),
                  if (a != null && a.stato != 'attiva')
                    Testo.bodyMedium(switch (a.stato) {
                      'scaduta' => 'Questa condivisione è scaduta.',
                      'esaurita' => 'Sono già stati scaricati tutti i documenti consentiti.',
                      'bloccata' => 'Bloccata dopo troppi codici errati.',
                      _ => 'Questa condivisione è stata revocata.',
                    }),
                  if (a != null && a.stato == 'attiva' && acc == null) ...[
                    Testo.bodyMedium('${a.condivisoDa} ti ha condiviso ${a.documenti == 1 ? 'un documento' : '${a.documenti} documenti'}. Il link scade il ${_quando(a.scadeIl)}.'),
                    SizedBox(height: 12),
                    TextField(
                      controller: codice,
                      textCapitalization: TextCapitalization.characters,
                      onSubmitted: (_) => _run(() async => accesso = await CondivisioneApi.accedi(token, codice.text)),
                      decoration: const InputDecoration(labelText: 'Codice ricevuto da chi ha condiviso', hintText: 'ABCD-EF23'),
                    ),
                    SizedBox(height: 12),
                    FilledButton(onPressed: busy ? null : () => _run(() async => accesso = await CondivisioneApi.accedi(token, codice.text)), child: const Text('Apri')),
                  ],
                  if (acc != null) ...[
                    Testo.bodySmall('Condivisi da ${acc.condivisoDa} · download rimasti: ${acc.downloadRimasti}', tenue: true),
                    SizedBox(height: 8),
                    for (final d in acc.documenti)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(d.mimeType == 'application/pdf' ? LucideIcons.file_text : LucideIcons.image),
                        title: Text(d.titolo),
                        subtitle: Text('${d.nomeFile} · ${formatBytes(d.dimensioneBytes)}'),
                        trailing: IconButton(
                          tooltip: 'Scarica',
                          icon: const Icon(LucideIcons.download),
                          onPressed: busy ? null : () => _run(() async => saveOrOpenFile(await CondivisioneApi.scarica(token, acc.sessione, d.id), d.nomeFile, d.mimeType)),
                        ),
                      ),
                    SizedBox(height: 8),
                    Testo.bodySmall('Ogni download viene registrato e conta nel limite deciso da chi ha condiviso.', tenue: true),
                  ],
                  if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
                ])),
            ),
          ),
        ),
      ),
    );
  }
}
