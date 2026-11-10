import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/controller/ente_gruppi_controller.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/widgets/messaggio.dart';

/// Gruppi (classi, corsi, squadre) dell'anno, con operatori e sedi; passaggio d'anno.
class EnteGruppiScreen extends StatefulWidget {
  const EnteGruppiScreen({super.key});

  @override
  State<EnteGruppiScreen> createState() => _EnteGruppiScreenState();
}

class _EnteGruppiScreenState extends State<EnteGruppiScreen> {
  SessionService get session => SessionService.to;
  bool get canManage => session.can('ente:manage');
  late final EnteGruppiController c = EnteGruppiController(tenantId: session.currentTenantId ?? '');

  void _snack(String m) => mostraMessaggio(context, m);

  /// Esito di un'azione: in caso di errore, il messaggio.
  void _esito(String? errore) {
    if (errore != null && mounted) _snack(errore);
  }

  Future<void> _gruppo([Gruppo? g]) async {
    final nome = TextEditingController(text: g?.nome ?? '');
    var tipo = g?.tipo ?? 'classe';
    String? sedeId = g?.sede?.id;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text(g == null ? 'Nuovo gruppo' : 'Modifica gruppo'),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: nome, decoration: const InputDecoration(labelText: 'Nome (es. 3ªB, Minivolley)', isDense: true)),
              SizedBox(height: 12),
              SegmentedButton<String>(
                segments: const [ButtonSegment(value: 'classe', label: Text('Classe')), ButtonSegment(value: 'attivita', label: Text('Attività o corso'))],
                selected: {tipo},
                onSelectionChanged: (s) => setLocal(() => tipo = s.first),
              ),
              if (c.sedi.isNotEmpty) ...[
                SizedBox(height: 12),
                DropdownButtonFormField<String?>(
                  initialValue: sedeId,
                  decoration: const InputDecoration(labelText: 'Sede', isDense: true),
                  items: [const DropdownMenuItem<String?>(value: null, child: Text('Nessuna')), for (final s in c.sedi) DropdownMenuItem<String?>(value: s.id, child: Text(s.nome))],
                  onChanged: (v) => setLocal(() => sedeId = v),
                ),
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Salva')),
          ],
        ),
      ),
    );
    if (ok == true) _esito(await c.salvaGruppo(id: g?.id, nome: nome.text, tipo: tipo, sedeId: sedeId));
    nome.dispose();
  }

  Future<void> _operatori(Gruppo g) async {
    final membri = await c.membriAttivi();
    final scelti = g.operatori.map((o) => o.membershipId).toSet();
    if (!mounted) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text('Operatori di ${g.nome}'),
          content: SizedBox(
            width: 420,
            child: ListView(shrinkWrap: true, children: [
              Testo.bodySmall('Vedono gli iscritti di questo gruppo e le sue richieste. La segreteria vede sempre tutto.', tenue: true),
              for (final m in membri)
                CheckboxListTile(
                  value: scelti.contains(m.id),
                  title: Text(m.fullName.isEmpty ? m.email : m.fullName),
                  subtitle: Text(m.roleName),
                  onChanged: (v) => setLocal(() => v == true ? scelti.add(m.id) : scelti.remove(m.id)),
                ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Salva')),
          ],
        ),
      ),
    );
    if (ok == true) _esito(await c.assegnaOperatori(g, scelti.toList()));
  }

  Future<void> _sede([Sede? s]) async {
    final nome = TextEditingController(text: s?.nome ?? '');
    final indirizzo = TextEditingController(text: s?.indirizzo ?? '');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s == null ? 'Nuova sede' : 'Modifica sede'),
        content: SizedBox(
          width: 400,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: nome, decoration: const InputDecoration(labelText: 'Nome (es. Plesso Centro)', isDense: true)),
            SizedBox(height: 12),
            TextField(controller: indirizzo, decoration: const InputDecoration(labelText: 'Indirizzo (facoltativo)', isDense: true)),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Salva')),
        ],
      ),
    );
    if (ok == true) _esito(await c.salvaSede(id: s?.id, nome: nome.text, indirizzo: indirizzo.text.trim().isEmpty ? null : indirizzo.text.trim()));
    nome.dispose();
    indirizzo.dispose();
  }

  /// Passaggio d'anno: per ogni gruppo il nome nel nuovo anno (vuoto = esce).
  Future<void> _passaggio() async {
    final a = c.anno;
    if (a == null) return;
    final nuovoNome = TextEditingController(text: annoSuccessivo(a.nome));
    final nomi = {for (final g in c.gruppi) g.id: TextEditingController(text: _successivo(g.nome))};
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Passaggio dal ${a.nome}'),
        content: SizedBox(
          width: 520,
          height: 480,
          child: ListView(children: [
            TextField(controller: nuovoNome, decoration: const InputDecoration(labelText: 'Nuovo anno', isDense: true)),
            SizedBox(height: 12),
            Testo.bodySmall('Ogni gruppo passa al nuovo anno con iscritti e operatori, con il nome indicato. Lascia vuoto per i gruppi che escono (es. le quinte). '
              'L\'anno ${a.nome} diventa di sola lettura; i collegamenti con le famiglie restano. Bocciature e trasferimenti si correggono poi a mano.', tenue: true),
            SizedBox(height: 12),
            for (final g in c.gruppi)
              Padding(
                padding: EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  SizedBox(width: 140, child: Text('${g.nome} (${g.iscritti})')),
                  const Icon(LucideIcons.arrow_right, size: 16),
                  SizedBox(width: 8),
                  Expanded(child: TextField(controller: nomi[g.id], decoration: const InputDecoration(isDense: true, hintText: 'esce'))),
                ]),
              ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Conferma il passaggio')),
        ],
      ),
    );
    if (ok == true) _esito(await c.passaggio(a, nuovoNome.text, {for (final e in nomi.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim()}));
    nuovoNome.dispose();
    for (final c in nomi.values) {
      c.dispose();
    }
  }

  static String _successivo(String nome) => gruppoSuccessivo(nome);

  @override
  Widget build(BuildContext context) => GetBuilder<EnteGruppiController>(init: c, global: false, builder: (c) => _pagina(c));

  Widget _pagina(EnteGruppiController c) {
    final archiviato = c.archiviato;
    return PaginaEnte(
      titolo: 'Gruppi e classi',
      sezione: 'Gruppi',
      azioni: [
        if (c.anni.length > 1)
          DropdownButton<String>(
            value: c.annoId,
            items: [for (final a in c.anni) DropdownMenuItem(value: a.id, child: Text(a.archiviato ? '${a.nome} (archiviato)' : '${a.nome} (in corso)'))],
            onChanged: c.scegliAnno,
          ),
        if (canManage && !archiviato) ...[
          OutlinedButton(onPressed: _passaggio, child: const Text('Passaggio d\'anno…')),
          FilledButton.icon(onPressed: () => _gruppo(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuovo gruppo')),
        ],
      ],
      child: c.loading
          ? const Center(child: CircularProgressIndicator())
          : c.errore != null
              ? erroreCard(c.errore!, c.load)
              : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  if (c.gruppi.isEmpty) Scheda(paddingAll: 24, child: Testo.bodyMedium(canManage ? 'Nessun gruppo: crea le classi o i corsi dell\'anno.' : 'Non sei ancora assegnato a un gruppo.')),
                  for (final tipo in ['classe', 'attivita'])
                    if (c.gruppi.any((g) => g.tipo == tipo)) ...[
                      Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Testo.labelMedium(tipo == 'classe' ? 'CLASSI' : 'ATTIVITÀ E CORSI', fontWeight: FontWeight.w600, tenue: true)),
                      Wrap(spacing: 12, runSpacing: 12, children: [for (final g in c.gruppi.where((g) => g.tipo == tipo)) _card(g, archiviato)]),
                    ],
                  if (canManage) ...[
                    SizedBox(height: 24),
                    Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Testo.titleSmall('Sedi', fontWeight: FontWeight.w600)),
                          TextButton.icon(onPressed: () => _sede(), icon: const Icon(LucideIcons.plus, size: 16), label: const Text('Nuova sede')),
                        ]),
                        if (c.sedi.isEmpty) Testo.bodySmall('Nessuna sede: servono solo se l\'ente ha più plessi.', tenue: true),
                        for (final s in c.sedi)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(LucideIcons.map_pin),
                            title: Text(s.nome),
                            subtitle: s.indirizzo == null ? null : Text(s.indirizzo!),
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              IconButton(icon: const Icon(LucideIcons.pencil, size: 16), onPressed: () => _sede(s)),
                              IconButton(icon: const Icon(LucideIcons.trash, size: 16), onPressed: () async => _esito(await c.eliminaSede(s))),
                            ]),
                          ),
                      ])),
                  ],
                ]),
    );
  }

  Widget _card(Gruppo g, bool archiviato) => SizedBox(
        width: 300,
        child: Scheda(paddingAll: 16, onTap: () => Get.toNamed('/ente/iscritti', arguments: g.id), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Testo.titleSmall(g.nome, fontWeight: FontWeight.w600)),
              if (canManage && !archiviato)
                PopupMenuButton<String>(
                  onSelected: (v) => switch (v) {
                    'modifica' => _gruppo(g),
                    'operatori' => _operatori(g),
                    _ => c.eliminaGruppo(g).then(_esito),
                  },
                  itemBuilder: (_) => const [
                    PopupMenuItem(value: 'modifica', child: Text('Modifica')),
                    PopupMenuItem(value: 'operatori', child: Text('Operatori')),
                    PopupMenuItem(value: 'elimina', child: Text('Elimina')),
                  ],
                ),
            ]),
            Testo.bodySmall('${g.iscritti} iscritti${g.sede != null ? ' · ${g.sede!.nome}' : ''}', tenue: true),
            SizedBox(height: 6),
            Testo.bodySmall(g.operatori.isEmpty ? 'Nessun operatore assegnato' : 'Operatori: ${g.operatori.map((o) => o.nome).join(', ')}'),
          ])),
      );
}
