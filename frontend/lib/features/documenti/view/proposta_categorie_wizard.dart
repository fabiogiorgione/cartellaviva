import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/icone_categoria.dart';
import 'package:cartellaviva/features/documenti/model/proposte_categoria.dart';
import 'package:cartellaviva/features/documenti/view/documenti_screen.dart' show hexColor;
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

typedef ProponiCategoria = Future<PropostaCategoria> Function(String documentoId, List<String> nuove);
typedef CreaCategoria = Future<Categoria> Function(NuovaCategoriaSuggerita n);
typedef SpostaDocumenti = Future<({int fatti, List<({String? titolo, String motivo})> saltati})> Function(List<String> ids, String categoriaId);

/// Wizard "Proponi categoria con l'AI" sui documenti selezionati:
/// 1. analisi, un documento per volta (il modello locale impiega decine di secondi);
/// 2. revisione: proposta per documento, modificabile, e categorie nuove suggerite;
/// 3. applica: crea le categorie spuntate e sposta i documenti (operazione di gruppo).
/// Fino a "Applica" non cambia nulla. true se qualcosa è cambiato.
Future<bool> showPropostaCategorieWizard(
  BuildContext context, {
  required String tenantId,
  required List<Documento> documenti,
  required List<Categoria> categorie,
  required bool puoCreareCategorie,
  ProponiCategoria? proponi,
  CreaCategoria? creaCategoria,
  SpostaDocumenti? sposta,
}) async {
  final scelti = documenti.take(ProposteCategorie.max).toList();
  // I documenti in revisione insieme non fanno da esempio delle categorie: spesso stanno tutti in quella sbagliata.
  final ids = [for (final d in scelti) d.id];
  return await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (_) => PropostaCategorieWizard(
          stato: ProposteCategorie(scelti, categorie, puoCreareCategorie: puoCreareCategorie),
          proponi: proponi ?? (id, nuove) => DocumentoApi.propostaCategoria(tenantId, id, nuove, escludi: ids),
          creaCategoria: creaCategoria ?? (n) => DocumentoApi.saveCategoria(tenantId, nome: n.nome, icona: n.icona, colore: n.colore, isSensibile: n.sensibile),
          sposta: sposta ?? (ids, categoriaId) => DocumentoApi.gruppo(tenantId, 'sposta', ids, categoriaId: categoriaId),
        ),
      ) ??
      false;
}

enum _Passo { analisi, revisione, applicazione, fatto }

class PropostaCategorieWizard extends StatefulWidget {
  final ProposteCategorie stato;
  final ProponiCategoria proponi;
  final CreaCategoria creaCategoria;
  final SpostaDocumenti sposta;

  /// Attesa prima di riprovare dopo un 429 (iniettabile nei test).
  final Future<void> Function(Duration) attendi;

  const PropostaCategorieWizard({super.key, required this.stato, required this.proponi, required this.creaCategoria, required this.sposta, this.attendi = _attendi});

  static Future<void> _attendi(Duration d) => Future<void>.delayed(d);

  @override
  State<PropostaCategorieWizard> createState() => _PropostaCategorieWizardState();
}

class _PropostaCategorieWizardState extends State<PropostaCategorieWizard> {
  ProposteCategorie get stato => widget.stato;

  _Passo passo = _Passo.analisi;
  RigaProposta? inCorso;
  String? bloccante;

  /// Messaggio mentre si aspetta un turno libero (429 con Retry-After breve).
  String? inAttesa;

  /// Oltre questa attesa (secondi) un 429 non si riprova: è il limite orario.
  static const maxAttesaRiprova = 60;
  bool fermato = false;
  final Stopwatch cronometro = Stopwatch();
  final Map<String, TextEditingController> nomi = {};

  // Esito dell'applicazione.
  int spostati = 0;
  int create = 0;
  final List<String> problemi = [];

  @override
  void initState() {
    super.initState();
    _analizza();
  }

  @override
  void dispose() {
    fermato = true;
    for (final c in nomi.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _analizza() async {
    cronometro.start();
    var erroriDiFila = 0;
    for (final r in stato.righe) {
      if (fermato) return;
      setState(() => inCorso = r);
      while (true) {
        try {
          final p = await widget.proponi(r.documento.id, stato.nomiNuove);
          if (fermato) return; // "Ferma e rivedi" o wizard chiuso: la risposta arrivata dopo non conta
          stato.registra(r, p);
          erroriDiFila = 0;
        } on ApiException catch (e) {
          if (fermato) return;
          // 429 con Retry-After breve: un'altra proposta (anche di altri) occupa l'AI. Si aspetta e si riprova lo stesso documento.
          final attesa = e.statusCode == 429 ? e.riprovaTra : null;
          if (attesa != null && attesa <= maxAttesaRiprova) {
            setState(() => inAttesa = e.message);
            await widget.attendi(Duration(seconds: attesa));
            if (!mounted || fermato) return;
            setState(() => inAttesa = null);
            continue;
          }
          stato.errore(r, e.message);
          // 409: AI non configurata; 429 lungo: limite orario. Tre errori di fila: il modello non risponde, inutile insistere.
          if (e.isConflict || e.statusCode == 429 || ++erroriDiFila >= 3) {
            bloccante = e.message;
          }
        }
        break;
      }
      if (bloccante != null) break;
      if (mounted) setState(() {});
    }
    cronometro.stop();
    if (!mounted || fermato) return;
    setState(() {
      inCorso = null;
      if (bloccante == null || stato.righe.any((r) => r.proposta != null)) passo = _Passo.revisione;
    });
  }

  void _fermaERivedi() => setState(() {
        fermato = true;
        cronometro.stop();
        inCorso = null;
        passo = _Passo.revisione;
      });

  Future<void> _applica() async {
    setState(() => passo = _Passo.applicazione);
    final idNuove = <String, String>{};
    for (final n in stato.daCreare) {
      try {
        final c = await widget.creaCategoria(n);
        idNuove['n:${n.chiave}'] = c.id;
        create++;
      } on ApiException catch (e) {
        problemi.add('Categoria "${n.nome}" non creata: ${e.message} I suoi documenti non sono stati spostati.');
      }
    }
    for (final e in stato.spostamenti.entries) {
      final categoriaId = e.key.startsWith('c:') ? e.key.substring(2) : idNuove[e.key];
      if (categoriaId == null) continue;
      try {
        final esito = await widget.sposta(e.value, categoriaId);
        spostati += esito.fatti;
        problemi.addAll([for (final s in esito.saltati) '${s.titolo ?? 'Documento'}: ${s.motivo}']);
      } on ApiException catch (err) {
        problemi.add('${e.value.length} documenti non spostati: ${err.message}');
      }
    }
    if (mounted) setState(() => passo = _Passo.fatto);
  }

  @override
  Widget build(BuildContext context) {
    final schermo = MediaQuery.of(context).size;
    return Dialog(
      insetPadding: const EdgeInsets.all(16),
      child: SizedBox(
        width: math.min(980, schermo.width - 32),
        height: math.min(760, schermo.height - 32),
        child: Padding(
          padding: EdgeInsets.all(20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              const Icon(LucideIcons.sparkles, size: 20),
              SizedBox(width: 8),
              Expanded(child: Testo.titleMedium(_titolo, fontWeight: FontWeight.w500)),
            ]),
            SizedBox(height: 12),
            Expanded(
              child: switch (passo) {
                _Passo.analisi => _analisi(),
                _Passo.revisione => _revisione(),
                _Passo.applicazione => const Center(child: Column(mainAxisSize: MainAxisSize.min, children: [CircularProgressIndicator(), SizedBox(height: 12), Text('Applico le modifiche…')])),
                _Passo.fatto => _fatto(),
              },
            ),
            SizedBox(height: 12),
            _azioni(),
          ]),
        ),
      ),
    );
  }

  String get _titolo => switch (passo) {
        _Passo.analisi => 'Proponi categoria con l\'AI: analisi di ${stato.righe.length} documenti',
        _Passo.revisione => 'Controlla le proposte',
        _Passo.applicazione => 'Applico le modifiche',
        _Passo.fatto => 'Fatto',
      };

  Widget _azioni() {
    final chiudi = TextButton(onPressed: () => Navigator.pop(context, passo == _Passo.fatto && (spostati > 0 || create > 0)), child: Text(passo == _Passo.fatto ? 'Chiudi' : 'Annulla'));
    return switch (passo) {
      _Passo.analisi => Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          chiudi,
          SizedBox(width: 8),
          FilledButton.tonal(onPressed: stato.analizzate == 0 ? null : _fermaERivedi, child: const Text('Ferma e rivedi')),
        ]),
      _Passo.revisione => Wrap(alignment: WrapAlignment.end, crossAxisAlignment: WrapCrossAlignment.center, spacing: 12, runSpacing: 8, children: [
          Testo.bodyMedium(stato.problemaNomi ?? stato.riepilogo, color: stato.problemaNomi != null ? Theme.of(context).colorScheme.error : null),
          chiudi,
          FilledButton(onPressed: stato.daSpostare == 0 || stato.problemaNomi != null ? null : _applica, child: const Text('Applica')),
        ]),
      _Passo.applicazione => const SizedBox.shrink(),
      _Passo.fatto => Row(mainAxisAlignment: MainAxisAlignment.end, children: [chiudi]),
    };
  }

  // ---- 1. Analisi -----------------------------------------------------------

  Widget _analisi() {
    final fatte = stato.analizzate;
    final totale = stato.righe.length;
    String stima = '';
    if (fatte > 0 && fatte < totale) {
      final minuti = (cronometro.elapsed.inSeconds / fatte * (totale - fatte) / 60).ceil();
      stima = ' · circa $minuti ${minuti == 1 ? 'minuto' : 'minuti'} alla fine';
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Testo.bodySmall('L\'AI legge ogni documento e sceglie una categoria tra le tue, oppure ne suggerisce una nuova. '
        'Il modello gira sul server della famiglia: ci vogliono decine di secondi per documento. Non cambia nulla finché non confermi.', tenue: true),
      SizedBox(height: 12),
      LinearProgressIndicator(value: totale == 0 ? 0 : fatte / totale),
      SizedBox(height: 6),
      Testo.bodySmall('Analizzati $fatte di $totale$stima'),
      if (inAttesa != null) ...[
        SizedBox(height: 4),
        Testo.bodySmall('In attesa: $inAttesa', tenue: true),
      ],
      if (bloccante != null) ...[
        SizedBox(height: 8),
        Testo.bodyMedium(bloccante!, color: Theme.of(context).colorScheme.error),
      ],
      SizedBox(height: 12),
      Expanded(
        child: ListView.builder(
          itemCount: stato.righe.length,
          itemBuilder: (_, i) {
            final r = stato.righe[i];
            final Widget icona = r == inCorso
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                : r.errore != null
                    ? Icon(LucideIcons.circle_alert, size: 16, color: Theme.of(context).colorScheme.error)
                    : r.proposta != null
                        ? const Icon(LucideIcons.circle_check, size: 16, color: Colors.green)
                        : Icon(LucideIcons.circle, size: 16, color: Theme.of(context).disabledColor);
            return ListTile(
              dense: true,
              leading: icona,
              title: Text(r.documento.titolo, overflow: TextOverflow.ellipsis),
              subtitle: r.analizzata ? Text(_descrizione(r), overflow: TextOverflow.ellipsis) : null,
            );
          },
        ),
      ),
    ]);
  }

  // ---- 2. Revisione ---------------------------------------------------------

  Widget _revisione() {
    final nuove = stato.nuove.values.toList();
    return ListView(children: [
      Testo.bodySmall('Per ogni documento c\'è la categoria proposta: cambiala se non ti convince, o scegli "Non spostare". '
          'Si applicano solo le righe con una categoria diversa da quella attuale.', tenue: true),
      if (bloccante != null) ...[
        SizedBox(height: 8),
        Testo.bodySmall('Analisi interrotta: $bloccante', color: Theme.of(context).colorScheme.error),
      ],
      if (nuove.isNotEmpty) ...[
        SizedBox(height: 16),
        Testo.labelLarge('Nuove categorie suggerite', fontWeight: FontWeight.w600),
        SizedBox(height: 4),
        Testo.bodySmall('Si creano solo quelle spuntate. Togliendo la spunta, i loro documenti restano dove sono.', tenue: true),
        SizedBox(height: 8),
        for (final n in nuove) _nuova(n),
      ],
      SizedBox(height: 16),
      Testo.labelLarge('Documenti', fontWeight: FontWeight.w600),
      SizedBox(height: 4),
      for (final r in stato.righe) _riga(r),
    ]);
  }

  Widget _nuova(NuovaCategoriaSuggerita n) {
    final nome = nomi.putIfAbsent(n.chiave, () => TextEditingController(text: n.nome));
    final quanti = stato.documentiPer(n);
    return Card(
      margin: EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: 8, vertical: 6),
        child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [
          Checkbox(value: n.crea, onChanged: (v) => setState(() => stato.cambiaCrea(n, v ?? false))),
          PopupMenuButton<String>(
            tooltip: 'Icona',
            enabled: n.crea,
            onSelected: (v) => setState(() => n.icona = v),
            itemBuilder: (_) => [for (final i in iconeProposte) PopupMenuItem(value: i, child: Icon(iconaCategoria(i), size: 18))],
            child: Padding(padding: EdgeInsets.all(6), child: Icon(iconaCategoria(n.icona), color: hexColor(n.colore))),
          ),
          SizedBox(
            width: 220,
            child: TextField(
              controller: nome,
              enabled: n.crea,
              maxLength: 80,
              decoration: const InputDecoration(labelText: 'Nome', isDense: true, counterText: ''),
              onChanged: (v) => setState(() => n.nome = v),
            ),
          ),
          for (final c in ProposteCategorie.colori)
            InkWell(
              onTap: n.crea ? () => setState(() => n.colore = c) : null,
              customBorder: const CircleBorder(),
              child: Container(
                width: 18,
                height: 18,
                decoration: BoxDecoration(color: hexColor(c), shape: BoxShape.circle, border: n.colore == c ? Border.all(width: 2, color: Theme.of(context).colorScheme.onSurface) : null),
              ),
            ),
          Row(mainAxisSize: MainAxisSize.min, children: [
            Switch(value: n.sensibile, onChanged: n.crea ? (v) => setState(() => n.sensibile = v) : null),
            const Text('Sensibile (cifrata)'),
          ]),
          Testo.bodySmall('$quanti ${quanti == 1 ? 'documento' : 'documenti'}', tenue: true),
        ]),
      ),
    );
  }

  Widget _riga(RigaProposta r) {
    final attuale = r.documento.categoria;
    final giaGiusta = r.proposta?.tipo == 'esistente' && r.proposta?.categoriaId == attuale?.id;
    final valori = <String?>[
      null,
      for (final c in stato.categorie) 'c:${c.id}',
      for (final n in stato.nuove.values)
        if (n.crea) 'n:${n.chiave}'
    ];
    final destinazione = valori.contains(r.destinazione) ? r.destinazione : null;
    final tema = Theme.of(context);
    return Container(
      padding: EdgeInsets.symmetric(vertical: 6),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: tema.dividerColor.withAlpha(90)))),
      child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 12, runSpacing: 6, alignment: WrapAlignment.spaceBetween, children: [
        SizedBox(
          width: 380,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(r.documento.titolo, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
            Testo.bodySmall('Ora in: ${attuale?.nome ?? '—'}', tenue: true),
            if (giaGiusta)
              Testo.bodySmall('L\'AI la conferma: è già nella categoria giusta.', tenue: true)
            else if (r.analizzata)
              Testo.bodySmall(_descrizione(r), tenue: true, color: r.errore != null ? tema.colorScheme.error : null),
          ]),
        ),
        SizedBox(
          width: 300,
          child: DropdownButtonFormField<String?>(
            key: ValueKey('${r.documento.id}=$destinazione'),
            initialValue: destinazione,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Sposta in', isDense: true),
            items: [
              for (final v in valori) DropdownMenuItem(value: v, child: _voce(v)),
            ],
            onChanged: (v) => setState(() => r.destinazione = v),
          ),
        ),
      ]),
    );
  }

  Widget _voce(String? v) {
    if (v == null) return const Text('Non spostare');
    final (nome, icona, colore, sensibile, nuova) = v.startsWith('c:')
        ? switch (stato.categoria(v.substring(2))) { final c? => (c.nome, c.icona, c.colore, c.isSensibile, false), null => ('—', 'folder', '#64748b', false, false) }
        : switch (stato.nuove[v.substring(2)]) { final n? => (n.nome, n.icona, n.colore, n.sensibile, true), null => ('—', 'folder', '#64748b', false, true) };
    return Row(children: [
      Icon(iconaCategoria(icona), size: 16, color: hexColor(colore)),
      SizedBox(width: 8),
      Flexible(child: Text('$nome${nuova ? ' (nuova)' : ''}${sensibile ? ' (cifrata)' : ''}', overflow: TextOverflow.ellipsis)),
    ]);
  }

  /// Cosa ha detto l'AI, in breve.
  String _descrizione(RigaProposta r) {
    if (r.errore != null) return 'Non analizzato: ${r.errore}';
    final p = r.proposta!;
    final dalNome = p.dalNome ? ' (dedotta solo dal nome: il testo non è disponibile)' : '';
    return switch (p.tipo) {
      'esistente' => 'Proposta: ${p.nome}$dalNome',
      'nuova' when !stato.puoCreareCategorie => 'L\'AI suggerisce una categoria nuova, "${p.nome}": chiedi a chi gestisce le categorie di crearla.',
      'nuova' => 'Proposta una categoria nuova: ${p.nome}$dalNome',
      _ => p.motivo ?? 'Nessuna proposta.',
    };
  }

  // ---- 3. Esito -------------------------------------------------------------

  Widget _fatto() => ListView(children: [
        Testo.bodyMedium([
          if (create > 0) 'Create $create ${create == 1 ? 'categoria' : 'categorie'}.',
          'Spostati $spostati ${spostati == 1 ? 'documento' : 'documenti'}.',
        ].join(' ')),
        if (problemi.isNotEmpty) ...[
          SizedBox(height: 12),
          Testo.labelMedium('Non applicati (${problemi.length}):'),
          SizedBox(height: 6),
          for (final p in problemi) Testo.bodySmall('• $p'),
        ],
      ]);
}
