part of 'categorie_screen.dart';

/// Telefono: un elenco semplice, con ricerca per nome e i filtri in un foglio.
extension _CategorieTelefono on _CategorieScreenState {
  // setState sta nello State: da un'estensione serve passare di qui.
  // ignore: invalid_use_of_protected_member
  void _aggiorna(VoidCallback f) => setState(f);

  Widget _paginaTelefono(List<Categoria> righe) {
    final nFiltri = (tipo == null ? 0 : 1) + (sensibili == null ? 0 : 1);
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: spazioPagina),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Testo.titleMedium(loading ? 'Categorie' : 'Categorie (${righe.length})', fontSize: 18, fontWeight: FontWeight.w500)),
          if (canManage) FilledButton.icon(onPressed: () => _modifica(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuova')),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: TextField(
              controller: nome,
              onChanged: (_) => _aggiorna(() {}),
              decoration: listaDecorazione('Cerca per nome').copyWith(prefixIcon: const Icon(LucideIcons.search, size: 18)),
            ),
          ),
          const SizedBox(width: 8),
          OutlinedButton.icon(
            onPressed: _apriFoglioFiltri,
            icon: const Icon(LucideIcons.sliders_horizontal, size: 16),
            label: Text(nFiltri == 0 ? 'Filtri' : 'Filtri ($nFiltri)'),
          ),
        ]),
        const SizedBox(height: 12),
        if (loading)
          const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
        else if (errore != null)
          Padding(padding: const EdgeInsets.all(16), child: Testo.bodyMedium(errore!, color: context.colori.danger))
        else if (righe.isEmpty)
          Padding(padding: const EdgeInsets.all(24), child: Testo.bodyMedium('Nessuna categoria con questi filtri.', tenue: true))
        else
          listaCarta(
            padding: EdgeInsets.zero,
            Column(children: [
              for (final (i, c) in righe.indexed) ...[
                if (i > 0) const Divider(height: 1),
                _rigaTelefono(c),
              ],
            ]),
          ),
        const SizedBox(height: 12),
        Testo.bodySmall('Le categorie sensibili cifrano i documenti caricati e registrano ogni accesso. Le predefinite sono uguali per tutte le famiglie.', tenue: true),
        const SizedBox(height: 16),
      ]),
    );
  }

  Widget _rigaTelefono(Categoria c) {
    final colore = hexColor(c.colore);
    final dettagli = [
      c.sistema ? 'Predefinita' : 'Della famiglia',
      if (c.isSensibile) 'cifrata',
      if (c.documenti != null) c.documenti == 1 ? '1 documento' : '${c.documenti} documenti',
    ].join(' · ');
    final modificabile = canManage && !c.sistema;
    final conDocumenti = (c.documenti ?? 0) > 0;
    return ListTile(
      leading: CircleAvatar(radius: 18, backgroundColor: colore.withAlpha(36), child: Icon(iconaCategoria(c.icona), size: 18, color: colore)),
      title: Text(c.nome, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(dettagli),
      onTap: conDocumenti ? () => Get.toNamed('/documenti?categoria=${c.id}') : null,
      trailing: (modificabile || conDocumenti)
          ? PopupMenuButton<String>(
              tooltip: 'Azioni',
              onSelected: (v) => switch (v) {
                'documenti' => Get.toNamed('/documenti?categoria=${c.id}'),
                'modifica' => _modifica(c),
                _ => _elimina(c),
              },
              itemBuilder: (_) => [
                if (conDocumenti) const PopupMenuItem(value: 'documenti', child: Text('Mostra i documenti')),
                if (modificabile) const PopupMenuItem(value: 'modifica', child: Text('Modifica')),
                if (modificabile) const PopupMenuItem(value: 'elimina', child: Text('Elimina')),
              ],
            )
          : (c.sistema ? Icon(LucideIcons.lock_keyhole, size: 16, color: context.colori.secondary) : null),
    );
  }

  Future<void> _apriFoglioFiltri() => apriFoglio<void>(
        context,
        titolo: 'Filtri',
        contenuto: (_) => StatefulBuilder(
          builder: (_, aggiornaFoglio) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            DropdownButtonFormField<String?>(
              initialValue: tipo,
              isExpanded: true,
              decoration: listaDecorazione('Tipo'),
              items: const [
                DropdownMenuItem(value: null, child: Text('Tutte')),
                DropdownMenuItem(value: 'proprie', child: Text('Della famiglia')),
                DropdownMenuItem(value: 'sistema', child: Text('Predefinite')),
              ],
              onChanged: (v) => _aggiorna(() => tipo = v),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String?>(
              initialValue: sensibili,
              isExpanded: true,
              decoration: listaDecorazione('Sensibili'),
              items: const [
                DropdownMenuItem(value: null, child: Text('Tutte')),
                DropdownMenuItem(value: 'si', child: Text('Solo sensibili (cifrate)')),
                DropdownMenuItem(value: 'no', child: Text('Non sensibili')),
              ],
              onChanged: (v) => _aggiorna(() => sensibili = v),
            ),
            const SizedBox(height: 12),
            Testo.labelMedium('Ordina per', fontWeight: FontWeight.w500),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              showSelectedIcon: false,
              segments: const [ButtonSegment(value: 'nome', label: Text('Nome')), ButtonSegment(value: 'documenti', label: Text('N. documenti'))],
              selected: {ordina},
              onSelectionChanged: (v) {
                _aggiorna(() {
                  ordina = v.single;
                  verso = ordina == 'nome' ? 'asc' : 'desc';
                });
                aggiornaFoglio(() {});
              },
            ),
          ]),
        ),
        azione: (ctx) => Row(children: [
          OutlinedButton(
            onPressed: () {
              _reset();
              Navigator.of(ctx).pop();
            },
            child: const Text('Resetta'),
          ),
          const SizedBox(width: 12),
          Expanded(child: FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: Text('Mostra ${_filtrate.length} categorie'))),
        ]),
      );
}
