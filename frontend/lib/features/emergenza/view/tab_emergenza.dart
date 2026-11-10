import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/emergenza/api/emergenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

String _quando(DateTime d) => '${formatDataBreve(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

/// Contatti d'emergenza della famiglia: i proprietari li gestiscono, i membri
/// vedono le richieste in corso e possono opporsi.
class TabEmergenza extends StatefulWidget {
  const TabEmergenza({super.key});

  @override
  State<TabEmergenza> createState() => _TabEmergenzaState();
}

class _TabEmergenzaState extends State<TabEmergenza> {
  SessionService get session => SessionService.to;
  String get t => session.currentTenantId!;
  bool get owner => session.currentMembership?.isOwner == true;
  List<ContattoEmergenza> contatti = [];
  List<Categoria> categorie = [];
  bool loading = true;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      contatti = await EmergenzaApi.contatti(t);
      if (owner && categorie.isEmpty) categorie = await DocumentoApi.categorie(t);
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> _azione(Future<void> Function() f) async {
    try {
      await f();
      await _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _aggiungi() async {
    final ok = await showDialog<bool>(context: context, builder: (_) => _InvitaDialog(tenantId: t, categorie: categorie));
    if (ok == true) await _load();
  }

  String _categorie(ContattoEmergenza c) {
    final ids = c.categorie;
    if (ids == null) return 'tutte le categorie';
    final nomi = categorie.where((x) => ids.contains(x.id)).map((x) => x.nome).toList();
    return nomi.isEmpty ? '${ids.length} categorie' : nomi.join(', ');
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    if (error != null) return Testo.bodyMedium(error!);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Testo.bodySmall('Un contatto fidato (con un account CartellaViva) può chiedere di consultare i documenti se nessuno di voi può farlo. '
        'Proprietari e membri ricevono un avviso e possono opporsi; senza opposizione, dopo i giorni di attesa l\'accesso è concesso in sola lettura. '
        'Finché non lo chiede, il contatto non vede nulla.', tenue: true),
      SizedBox(height: 12),
      if (owner)
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(onPressed: _aggiungi, icon: const Icon(LucideIcons.life_buoy, size: 18), label: const Text('Aggiungi un contatto d\'emergenza')),
        ),
      SizedBox(height: 8),
      if (contatti.isEmpty) Testo.bodyMedium('Nessun contatto d\'emergenza.'),
      for (final c in contatti)
        Card(
          color: c.inAttesa ? Colors.orange.withAlpha(30) : null,
          child: Padding(
            padding: EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(c.concesso ? LucideIcons.lock_open : LucideIcons.life_buoy, size: 18),
                SizedBox(width: 8),
                Expanded(child: Testo.bodyMedium(c.nome != null ? '${c.nome} (${c.email})' : c.email, fontWeight: FontWeight.w500)),
                Testo.bodySmall(c.statoLabel, tenue: true),
              ]),
              Testo.bodySmall('Attesa ${c.giorniAttesa} giorni · ${_categorie(c)}', tenue: true),
              if (c.inAttesa) ...[
                SizedBox(height: 6),
                Testo.bodyMedium('Ha chiesto l\'accesso${c.richiestaIl != null ? ' il ${_quando(c.richiestaIl!)}' : ''}: sarà concesso il ${c.eseguibileIl != null ? _quando(c.eseguibileIl!) : '-'} se nessuno si oppone.'),
                if (c.motivo != null) Testo.bodySmall('Motivo: "${c.motivo}"'),
              ],
              Wrap(spacing: 8, children: [
                if (c.inAttesa)
                  FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore),
                    onPressed: () => _azione(() => EmergenzaApi.nega(t, c.id)),
                    child: const Text('Mi oppongo'),
                  ),
                if (owner && c.gestibile)
                  TextButton(
                    onPressed: () => _azione(() => EmergenzaApi.revoca(t, c.id)),
                    child: Text(c.concesso ? 'Revoca contatto e accesso' : 'Revoca', style: TextStyle(color: ColoreStato.errore)),
                  ),
              ]),
            ]),
          ),
        ),
    ]);
  }
}

class _InvitaDialog extends StatefulWidget {
  final String tenantId;
  final List<Categoria> categorie;

  const _InvitaDialog({required this.tenantId, required this.categorie});

  @override
  State<_InvitaDialog> createState() => _InvitaDialogState();
}

class _InvitaDialogState extends State<_InvitaDialog> {
  final email = TextEditingController();
  int giorni = 7;
  bool tutte = true;
  final Set<String> scelte = {};
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    email.dispose();
    super.dispose();
  }

  Future<void> _invia() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await EmergenzaApi.invita(widget.tenantId, email: email.text, giorniAttesa: giorni, categorieIds: tutte ? null : scelte.toList());
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final valido = email.text.contains('@') && (tutte || scelte.isNotEmpty);
    return AlertDialog(
      title: const Text('Contatto d\'emergenza'),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(
              controller: email,
              keyboardType: TextInputType.emailAddress,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(labelText: 'Email della persona di fiducia', isDense: true),
            ),
            SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: giorni,
              decoration: const InputDecoration(labelText: 'Giorni di attesa per opporsi', isDense: true),
              items: [for (final g in const [3, 7, 14, 30]) DropdownMenuItem(value: g, child: Text('$g giorni'))],
              onChanged: (v) => setState(() => giorni = v ?? giorni),
            ),
            SwitchListTile(contentPadding: EdgeInsets.zero, value: tutte, onChanged: (v) => setState(() => tutte = v), title: const Text('Tutte le categorie di documenti')),
            if (!tutte)
              for (final c in widget.categorie.where((c) => c.id.isNotEmpty))
                CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  value: scelte.contains(c.id),
                  onChanged: (v) => setState(() => v == true ? scelte.add(c.id) : scelte.remove(c.id)),
                  title: Text(c.nome),
                ),
            Testo.bodySmall('Riceverà un invito via email: deve accettarlo con un account CartellaViva di quell\'indirizzo.', tenue: true),
            if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy || !valido ? null : _invia, child: const Text('Invia l\'invito')),
      ],
    );
  }
}
