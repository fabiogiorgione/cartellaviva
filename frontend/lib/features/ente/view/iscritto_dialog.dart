import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatIsoDate, formatDataBreve;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Scheda di un iscritto: dati, gruppi e referenti. Restituisce true se qualcosa è cambiato.
Future<bool> showIscrittoDialog(BuildContext context,
    {required String tenantId, Iscritto? iscritto, required List<Gruppo> gruppi, String? gruppoPredefinito, required bool canManage}) async {
  final changed = await showDialog<bool>(
    context: context,
    builder: (_) => _IscrittoDialog(tenantId: tenantId, iscritto: iscritto, gruppi: gruppi, gruppoPredefinito: gruppoPredefinito, canManage: canManage),
  );
  return changed ?? false;
}

class _IscrittoDialog extends StatefulWidget {
  final String tenantId;
  final Iscritto? iscritto;
  final List<Gruppo> gruppi;
  final String? gruppoPredefinito;
  final bool canManage;

  const _IscrittoDialog({required this.tenantId, this.iscritto, required this.gruppi, this.gruppoPredefinito, required this.canManage});

  @override
  State<_IscrittoDialog> createState() => _IscrittoDialogState();
}

class _IscrittoDialogState extends State<_IscrittoDialog> {
  late final nome = TextEditingController(text: widget.iscritto?.nome ?? '');
  late final cognome = TextEditingController(text: widget.iscritto?.cognome ?? '');
  late DateTime? nascita = widget.iscritto?.dataNascita;
  late final Set<String> gruppiIds = widget.iscritto?.gruppi.where((g) => !g.archiviato).map((g) => g.id).toSet() ?? {if (widget.gruppoPredefinito != null) widget.gruppoPredefinito!};
  late final List<Referente> referenti = widget.iscritto?.referenti.toList() ?? [];
  bool busy = false;
  bool changed = false;
  String? errore;

  bool get nuovo => widget.iscritto == null;

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await EnteApi.salvaIscritto(widget.tenantId,
          id: widget.iscritto?.id,
          nome: nome.text,
          cognome: cognome.text,
          dataNascita: nascita == null ? null : formatIsoDate(nascita!),
          gruppiIds: gruppiIds.toList(),
          referenti: referenti);
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _referente([Referente? r]) async {
    final edit = Referente(id: r?.id, nome: r?.nome ?? '', cognome: r?.cognome ?? '', email: r?.email, relazione: r?.relazione, stato: r?.stato ?? 'da_invitare');
    final form = GlobalKey<FormState>();
    String? erroreReferente;
    var salvataggio = false;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, aggiorna) {
          // Il modulo resta aperto finché i dati non vanno bene: niente da riscrivere.
          Future<void> conferma() async {
            if (!(form.currentState?.validate() ?? false)) return;
            if (nuovo) {
              Navigator.pop(ctx, true);
              return;
            }
            aggiorna(() {
              salvataggio = true;
              erroreReferente = null;
            });
            try {
              await EnteApi.salvaReferente(widget.tenantId, iscrittoId: widget.iscritto!.id, referente: edit);
              changed = true;
              if (ctx.mounted) Navigator.pop(ctx, true);
            } on ApiException catch (e) {
              aggiorna(() {
                salvataggio = false;
                erroreReferente = e.fieldErrors.isNotEmpty ? e.fieldErrors.values.first : e.message;
              });
            }
          }

          return AlertDialog(
            title: Text(r == null ? 'Nuovo referente' : 'Modifica referente'),
            content: SizedBox(
              width: 400,
              child: Form(
                key: form,
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  TextFormField(
                    initialValue: edit.nome,
                    decoration: const InputDecoration(labelText: 'Nome', isDense: true),
                    textCapitalization: TextCapitalization.words,
                    onChanged: (v) => edit.nome = v,
                    validator: (v) => (v ?? '').trim().isEmpty ? 'Scrivi il nome.' : null,
                  ),
                  SizedBox(height: 10),
                  TextFormField(
                    initialValue: edit.cognome,
                    decoration: const InputDecoration(labelText: 'Cognome', isDense: true),
                    textCapitalization: TextCapitalization.words,
                    onChanged: (v) => edit.cognome = v,
                    validator: (v) => (v ?? '').trim().isEmpty ? 'Scrivi il cognome.' : null,
                  ),
                  SizedBox(height: 10),
                  TextFormField(
                    initialValue: edit.email,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email (per l\'invito)', isDense: true),
                    onChanged: (v) => edit.email = v.trim().isEmpty ? null : v.trim(),
                    validator: (v) => (v ?? '').trim().isEmpty || RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v!.trim()) ? null : 'Email non valida.',
                  ),
                  SizedBox(height: 10),
                  TextFormField(initialValue: edit.relazione, decoration: const InputDecoration(labelText: 'Relazione (madre, padre, tutore…)', isDense: true), onChanged: (v) => edit.relazione = v),
                  if (r?.stato == 'collegato') ...[
                    SizedBox(height: 8),
                    Testo.bodySmall('Cambiando l\'email di un referente già collegato il collegamento resta; un invito in corso invece si annulla.', tenue: true),
                  ],
                  if (erroreReferente != null) ...[SizedBox(height: 8), Testo.bodySmall(erroreReferente!, color: ColoreStato.errore)],
                ]),
              ),
            ),
            actions: [
              TextButton(onPressed: salvataggio ? null : () => Navigator.pop(ctx, false), child: const Text('Annulla')),
              FilledButton(onPressed: salvataggio ? null : conferma, child: const Text('OK')),
            ],
          );
        },
      ),
    );
    if (ok != true) return;
    setState(() {
      errore = null;
      r == null ? referenti.add(edit) : referenti[referenti.indexOf(r)] = edit;
    });
  }

  Future<void> _togliReferente(Referente r) async {
    if (!nuovo && r.id != null) {
      try {
        await EnteApi.eliminaReferente(widget.tenantId, r.id!);
        changed = true;
      } on ApiException catch (e) {
        setState(() => errore = e.message);
        return;
      }
    }
    setState(() => referenti.remove(r));
  }

  Future<void> _elimina() async {
    try {
      await EnteApi.eliminaIscritto(widget.tenantId, widget.iscritto!.id);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ro = !widget.canManage;
    return AlertDialog(
      title: Text(nuovo ? 'Nuovo iscritto' : widget.iscritto!.nomeCompleto),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              Expanded(child: TextField(controller: nome, enabled: !ro, decoration: const InputDecoration(labelText: 'Nome', isDense: true))),
              SizedBox(width: 12),
              Expanded(child: TextField(controller: cognome, enabled: !ro, decoration: const InputDecoration(labelText: 'Cognome', isDense: true))),
            ]),
            SizedBox(height: 8),
            Row(children: [
              Expanded(child: Text(nascita == null ? 'Data di nascita: non indicata' : 'Nato/a il ${formatDataBreve(nascita!)}')),
              if (!ro)
                TextButton(
                  onPressed: () async {
                    final d = await showDatePicker(context: context, initialDate: nascita ?? DateTime(2015), firstDate: DateTime(1920), lastDate: DateTime.now());
                    if (d != null) setState(() => nascita = d);
                  },
                  child: const Text('Scegli'),
                ),
            ]),
            SizedBox(height: 8),
            Testo.labelMedium('Gruppi'),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final g in widget.gruppi)
                FilterChip(label: Text(g.nome), selected: gruppiIds.contains(g.id), onSelected: ro ? null : (v) => setState(() => v ? gruppiIds.add(g.id) : gruppiIds.remove(g.id))),
            ]),
            SizedBox(height: 16),
            Row(children: [
              Expanded(child: Testo.labelMedium('Referenti')),
              if (!ro) TextButton.icon(onPressed: () => _referente(), icon: const Icon(LucideIcons.plus, size: 16), label: const Text('Aggiungi')),
            ]),
            if (referenti.isEmpty) Testo.bodySmall('Nessun referente: senza referenti l\'iscritto non riceve richieste.', tenue: true),
            for (final r in referenti)
              ListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                title: Text('${r.nomeCompleto}${r.relazione != null ? ' · ${r.relazione}' : ''}'),
                subtitle: Text(r.email == null || r.email!.isEmpty ? 'Manca l\'email: aggiungila per invitarlo' : '${r.email} · ${r.statoLabel}'),
                trailing: ro
                    ? null
                    : Row(mainAxisSize: MainAxisSize.min, children: [
                        IconButton(icon: const Icon(LucideIcons.pencil, size: 16), onPressed: () => _referente(r)),
                        IconButton(icon: const Icon(LucideIcons.trash, size: 16), onPressed: () => _togliReferente(r)),
                      ]),
              ),
            if (referenti.length > 1) Testo.bodySmall('Referenti in famiglie diverse ricevono ciascuno la propria richiesta; nessuno vede la risposta dell\'altro.', tenue: true),
            if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
          ]),
        ),
      ),
      actions: [
        if (!nuovo && !ro) TextButton(onPressed: busy ? null : _elimina, child: Text('Elimina', style: TextStyle(color: ColoreStato.errore))),
        TextButton(onPressed: () => Navigator.pop(context, changed), child: Text(ro ? 'Chiudi' : 'Annulla')),
        if (!ro) FilledButton(onPressed: busy || gruppiIds.isEmpty ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}
