part of 'portale_screens.dart';

// ---------------------------------------------------------------------------
// Portale delle idee
// ---------------------------------------------------------------------------

class PortaleIdeeScreen extends StatefulWidget {
  const PortaleIdeeScreen({super.key});

  @override
  State<PortaleIdeeScreen> createState() => _PortaleIdeeScreenState();
}

class _PortaleIdeeScreenState extends State<PortaleIdeeScreen> {
  String? stato;
  String? tipo;
  String? area;
  final cerca = TextEditingController();
  late Future<List<IdeaPortale>> _future = _carica();

  bool get supporto => SessionService.to.me?.isSupporto ?? false;
  bool get superadmin => SessionService.to.me?.platformRole == 'superadmin';

  Future<List<IdeaPortale>> _carica() => IdeeApi.elenco(stato: stato, tipo: tipo, area: area, q: cerca.text);

  void _reload() => setState(() => _future = _carica());

  @override
  void dispose() {
    cerca.dispose();
    super.dispose();
  }

  Future<void> _apri(IdeaPortale i) async {
    final cambiata = await showDialog<bool>(context: context, builder: (_) => _IdeaDialog(id: i.id, supporto: supporto, superadmin: superadmin));
    if (cambiata == true) _reload();
  }

  Future<void> _esporta() async {
    try {
      final now = DateTime.now();
      await saveOrOpenFile(await IdeeApi.esporta(), 'idee-${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}.csv', 'text/csv');
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    DropdownButton<String?> filtro(String? value, String tutti, Map<String, String> voci, ValueChanged<String?> set) => DropdownButton<String?>(
          value: value,
          items: [DropdownMenuItem(value: null, child: Text(tutti)), for (final e in voci.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
          onChanged: (v) {
            set(v);
            _reload();
          },
        );
    return _PaginaPortale(
      titolo: 'Idee degli utenti',
      azioni: [if (superadmin) OutlinedButton.icon(onPressed: _esporta, icon: const Icon(LucideIcons.download, size: 16), label: const Text('Esporta CSV'))],
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 16, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          filtro(stato, 'Tutti gli stati', statiIdea, (v) => stato = v),
          filtro(tipo, 'Tutti i tipi', tipiIdea, (v) => tipo = v),
          filtro(area, 'Tutte le aree', areeIdea, (v) => area = v),
          SizedBox(
            width: 260,
            child: TextField(
              controller: cerca,
              onSubmitted: (_) => _reload(),
              decoration: const InputDecoration(hintText: 'Cerca nel testo', prefixIcon: Icon(LucideIcons.search, size: 16), isDense: true),
            ),
          ),
        ]),
        SizedBox(height: 12),
        FutureBuilder<List<IdeaPortale>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
            final idee = snap.data!;
            if (idee.isEmpty) return Scheda(paddingAll: 24, child: Testo.bodyMedium('Nessuna idea.'));
            return Scheda(paddingAll: 0, child: Column(children: [
                for (final i in idee)
                  ListTile(
                    onTap: () => _apri(i),
                    leading: Icon(i.tipo == 'funzionalita' ? LucideIcons.sparkles : LucideIcons.wrench),
                    title: Text(i.testo, maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text([statoIdeaLabel(i.stato), i.contesto, _data(i.createdAt?.toIso8601String())].join(' · ')),
                    trailing: i.richieste > 1 ? Chip(label: Text('${i.richieste} utenti'), visualDensity: VisualDensity.compact) : null,
                  ),
              ]));
          },
        ),
        SizedBox(height: 8),
        Testo.bodySmall('Le idee sono private: le vedono solo il loro autore e il team del portale. Il contatto dell\'autore compare solo se l\'ha concesso.', tenue: true),
      ]),
    );
  }
}

class _IdeaDialog extends StatefulWidget {
  final String id;
  final bool supporto;
  final bool superadmin;

  const _IdeaDialog({required this.id, required this.supporto, required this.superadmin});

  @override
  State<_IdeaDialog> createState() => _IdeaDialogState();
}

class _IdeaDialogState extends State<_IdeaDialog> {
  IdeaPortale? idea;
  String? stato;
  final risposta = TextEditingController();
  final nota = TextEditingController();
  final unisciA = TextEditingController();
  bool busy = false;
  bool cambiata = false;
  String? errore;

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    for (final c in [risposta, nota, unisciA]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _carica() async {
    try {
      final i = await IdeeApi.dettaglio(widget.id);
      if (!mounted) return;
      setState(() {
        idea = i;
        stato = i.stato;
        risposta.text = i.rispostaPubblica ?? '';
        nota.text = i.notaInterna ?? '';
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => errore = e.message);
    }
  }

  Future<void> _run(Future<void> Function() action, {bool chiudi = false}) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await action();
      cambiata = true;
      if (chiudi) {
        if (mounted) Navigator.pop(context, true);
        return;
      }
      await _carica();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  Widget _contenuto(IdeaPortale i) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.bodySmall('${i.contesto} · ${_dataOra(i.createdAt?.toIso8601String())}', tenue: true),
        SizedBox(height: 8),
        SelectableText(i.testo),
        SizedBox(height: 8),
        Testo.bodySmall(i.anonima
              ? 'Autore: account eliminato.'
              : (i.contatto != null ? 'Contatto: ${i.contatto!.nome} · ${i.contatto!.email}' : (widget.supporto ? 'L\'autore non vuole essere contattato.' : '')), tenue: true),
        if (i.duplicatoDi != null) ...[
          SizedBox(height: 8),
          Testo.bodySmall('È un duplicato: stato e risposta si gestiscono sull\'idea principale.', color: ColoreStato.avviso),
          if (widget.supporto) Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: busy ? null : () => _run(() => IdeeApi.unisci(i.id, null)), child: const Text('Separa'))),
        ],
        if (i.duplicati.isNotEmpty) ...[
          SizedBox(height: 12),
          Testo.labelMedium(i.duplicati.length == 1 ? 'Proposta anche da un altro utente' : 'Proposta anche da altri ${i.duplicati.length} utenti'),
          for (final d in i.duplicati)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(d.testo, maxLines: 3, overflow: TextOverflow.ellipsis),
              subtitle: Text([_data(d.createdAt?.toIso8601String()), if (d.contatto != null) d.contatto!.email].join(' · ')),
              trailing: widget.supporto ? TextButton(onPressed: busy ? null : () => _run(() => IdeeApi.unisci(d.id, null)), child: const Text('Separa')) : null,
            ),
        ],
        if (i.duplicatoDi == null) ...[
          const Divider(height: 24),
          DropdownButtonFormField<String>(
            initialValue: stato,
            decoration: const InputDecoration(labelText: 'Stato', isDense: true),
            items: [for (final e in statiIdea.entries) DropdownMenuItem(value: e.key, child: Text(e.key == 'nuova' ? 'Nuova' : e.value))],
            onChanged: widget.supporto ? (v) => setState(() => stato = v) : null,
          ),
          SizedBox(height: 12),
          TextField(
            controller: risposta,
            enabled: widget.supporto,
            maxLines: 3,
            maxLength: 2000,
            decoration: const InputDecoration(labelText: 'Risposta pubblica (la legge l\'autore)', isDense: true),
          ),
        ],
        TextField(
          controller: nota,
          enabled: widget.supporto,
          maxLines: 2,
          maxLength: 2000,
          decoration: const InputDecoration(labelText: 'Nota interna (solo il team)', isDense: true),
        ),
        if (widget.supporto && i.duplicatoDi == null) ...[
          SizedBox(height: 4),
          Row(children: [
            Expanded(
              child: TextField(
                controller: unisciA,
                decoration: const InputDecoration(labelText: 'Unisci a un\'altra idea (ID)', helperText: 'Copia l\'ID dall\'idea principale', isDense: true),
              ),
            ),
            SizedBox(width: 8),
            OutlinedButton(
              onPressed: busy ? null : () => _run(() => IdeeApi.unisci(i.id, unisciA.text.trim()), chiudi: true),
              child: const Text('Unisci'),
            ),
          ]),
        ],
        SizedBox(height: 4),
        Row(children: [
          Testo.bodySmall('ID: ', tenue: true),
          Expanded(child: SelectableText(i.id, style: const TextStyle(fontSize: 12))),
        ]),
        Testo.bodySmall('Cambiare lo stato (tranne "letta") o la risposta avvisa l\'autore via email, senza il testo.', tenue: true),
        if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
      ]);

  Future<void> _salva(IdeaPortale i) => _run(
        () => IdeeApi.aggiorna(
          i.id,
          stato: i.duplicatoDi == null && stato != i.stato ? stato : null,
          rispostaPubblica: i.duplicatoDi == null && risposta.text.trim() != (i.rispostaPubblica ?? '') ? risposta.text.trim() : null,
          notaInterna: nota.text.trim() != (i.notaInterna ?? '') ? nota.text.trim() : null,
        ),
        chiudi: true,
      );

  @override
  Widget build(BuildContext context) {
    final i = idea;
    return AlertDialog(
      title: const Text('Idea'),
      content: SizedBox(
        width: 620,
        child: i == null
            ? (errore != null ? Testo.bodyMedium(errore!) : const SizedBox(height: 80, child: Center(child: CircularProgressIndicator())))
            : SingleChildScrollView(child: _contenuto(i)),
      ),
      actions: [
        if (i != null && widget.superadmin)
          TextButton(
            onPressed: busy ? null : () => _run(() => IdeeApi.elimina(i.id), chiudi: true),
            child: Text('Elimina', style: TextStyle(color: ColoreStato.errore)),
          ),
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, cambiata), child: const Text('Chiudi')),
        if (i != null && widget.supporto) FilledButton(onPressed: busy ? null : () => _salva(i), child: const Text('Salva')),
      ],
    );
  }
}
