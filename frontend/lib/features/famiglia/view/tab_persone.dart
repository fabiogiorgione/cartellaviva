import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/famiglia/model/persona.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Persone della famiglia (anche senza account): a loro si collegano documenti e scadenze.
class TabPersone extends StatefulWidget {
  const TabPersone({super.key});

  @override
  State<TabPersone> createState() => _TabPersoneState();
}

class _TabPersoneState extends State<TabPersone> {
  SessionService get session => SessionService.to;
  List<Persona> persone = [];
  bool loading = true;
  String? error;

  bool get canManage => session.can('membro_famiglia:manage');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      persone = await DocumentoApi.persone(session.currentTenantId!);
    } on ApiException catch (e) {
      error = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> _edit([Persona? persona]) async {
    final saved = await showDialog<Persona>(context: context, builder: (_) => _PersonaDialog(tenantId: session.currentTenantId!, persona: persona));
    if (saved != null) await _load();
  }

  Future<void> _delete(Persona p) async {
    final ok = await chiediConferma(
      context,
      titolo: 'Togliere ${p.nomeCompleto}?',
      testo: 'I documenti restano; perdono solo il collegamento a questa persona.',
      azione: 'Togli',
    );
    if (!ok) return;
    try {
      await DocumentoApi.deletePersona(session.currentTenantId!, p.id!);
      await _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Expanded(child: Testo.bodySmall('Figli, nonni, chiunque riguardino i documenti: non serve che abbiano un account.', tenue: true)),
        if (canManage) FilledButton.icon(onPressed: () => _edit(), icon: const Icon(LucideIcons.user_plus, size: 18), label: const Text('Aggiungi')),
      ]),
      SizedBox(height: 12),
      if (error != null) Testo.bodySmall(error!, color: ColoreStato.errore),
      if (persone.isEmpty && error == null) Testo.bodyMedium('Nessuna persona ancora.'),
      for (final p in persone)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: CircleAvatar(child: Text(p.nomeCompleto.isEmpty ? '?' : p.nomeCompleto[0].toUpperCase())),
          title: Text(p.nomeCompleto.isEmpty ? 'Senza nome' : p.nomeCompleto),
          subtitle: Text([
            if ((p.relazione ?? '').isNotEmpty) p.relazione!,
            if (p.dataNascita != null) 'nato/a il ${formatDataBreve(p.dataNascita!)}',
            if (p.userId != null) 'ha un account',
          ].join(' · ')),
          trailing: canManage
              ? Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(tooltip: 'Modifica', icon: const Icon(LucideIcons.pencil, size: 18), onPressed: () => _edit(p)),
                  IconButton(tooltip: 'Togli', icon: const Icon(LucideIcons.trash, size: 18), onPressed: () => _delete(p)),
                ])
              : null,
        ),
    ]);
  }
}

class _PersonaDialog extends StatefulWidget {
  final String tenantId;
  final Persona? persona;

  const _PersonaDialog({required this.tenantId, this.persona});

  @override
  State<_PersonaDialog> createState() => _PersonaDialogState();
}

class _PersonaDialogState extends State<_PersonaDialog> {
  late final Persona p = widget.persona == null
      ? Persona()
      : Persona(
          id: widget.persona!.id,
          nome: widget.persona!.nome,
          cognome: widget.persona!.cognome,
          sesso: widget.persona!.sesso,
          dataNascita: widget.persona!.dataNascita,
          relazione: widget.persona!.relazione,
          codiceFiscale: widget.persona!.codiceFiscale,
          userId: widget.persona!.userId,
        );
  bool saving = false;
  String? error;

  Future<void> _save() async {
    if (p.nome.trim().isEmpty || p.cognome.trim().isEmpty) {
      setState(() => error = 'Nome e cognome sono obbligatori.');
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final saved = await DocumentoApi.savePersona(widget.tenantId, p);
      if (mounted) Navigator.of(context).pop(saved);
    } on ApiException catch (e) {
      setState(() {
        saving = false;
        error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    InputDecoration deco(String label) => InputDecoration(labelText: label, isDense: true);
    return AlertDialog(
      title: Text(widget.persona == null ? 'Nuova persona' : 'Modifica persona'),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextFormField(initialValue: p.nome, decoration: deco('Nome'), onChanged: (v) => p.nome = v),
            SizedBox(height: 12),
            TextFormField(initialValue: p.cognome, decoration: deco('Cognome'), onChanged: (v) => p.cognome = v),
            SizedBox(height: 12),
            TextFormField(initialValue: p.relazione, decoration: deco('Relazione (es. figlio, nonna)'), onChanged: (v) => p.relazione = v),
            SizedBox(height: 12),
            DropdownButtonFormField<String?>(
              initialValue: p.sesso,
              decoration: deco('Sesso'),
              items: const [
                DropdownMenuItem(value: null, child: Text('Non indicato')),
                DropdownMenuItem(value: 'M', child: Text('M')),
                DropdownMenuItem(value: 'F', child: Text('F')),
              ],
              onChanged: (v) => setState(() => p.sesso = v),
            ),
            SizedBox(height: 12),
            Row(children: [
              Expanded(child: Text(p.dataNascita == null ? 'Data di nascita: non indicata' : 'Nato/a il ${formatDataBreve(p.dataNascita!)}')),
              TextButton(
                onPressed: () async {
                  final d = await showDatePicker(context: context, initialDate: p.dataNascita ?? DateTime(2010), firstDate: DateTime(1900), lastDate: DateTime.now());
                  if (d != null) setState(() => p.dataNascita = d);
                },
                child: const Text('Scegli'),
              ),
            ]),
            SizedBox(height: 12),
            TextFormField(
              initialValue: p.codiceFiscale,
              decoration: deco('Codice fiscale (facoltativo)'),
              textCapitalization: TextCapitalization.characters,
              maxLength: 16,
              onChanged: (v) => p.codiceFiscale = v,
            ),
            if (error != null) Testo.bodySmall(error!, color: ColoreStato.errore),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: saving ? null : () => Navigator.pop(context), child: const Text('Annulla')),
        FilledButton(onPressed: saving ? null : _save, child: const Text('Salva')),
      ],
    );
  }
}
