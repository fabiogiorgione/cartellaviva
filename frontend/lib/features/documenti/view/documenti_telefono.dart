part of 'documenti_screen.dart';

/// Telefono: barra compatta (ricerca, Filtri, ⋯) e lista di schede; filtri, ordine
/// e vista stanno in fogli (`apriFoglio`), la selezione solo dopo "Seleziona".
extension _DocumentiTelefono on _DocumentiScreenState {
  bool get _telefono => MediaQuery.of(context).size.width < larghezzaTelefono;

  // setState sta nello State: da un'estensione serve passare di qui.
  // ignore: invalid_use_of_protected_member
  void _aggiorna(VoidCallback f) => setState(f);

  Widget _paginaTelefono(DocumentiController c) {
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: spazioPagina),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Testo.titleMedium(c.loading ? 'Documenti' : 'Documenti (${c.totale})', fontSize: 18, fontWeight: FontWeight.w500)),
          if (c.canUpload) ...[
            IconButton.outlined(tooltip: 'Scansiona', onPressed: () => Get.toNamed('/documenti/nuovo'), icon: const Icon(LucideIcons.scan_line, size: 18)),
            const SizedBox(width: 8),
            _menuCarica(),
          ],
        ]),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: TextField(
              controller: searchField,
              onChanged: c.search,
              textInputAction: TextInputAction.search,
              decoration: listaDecorazione(c.perSignificato ? 'Descrivi cosa cerchi' : 'Cerca nei documenti').copyWith(
                prefixIcon: const Icon(LucideIcons.search, size: 18),
                suffixIcon: c.query.isEmpty
                    ? null
                    : IconButton(
                        tooltip: 'Cancella la ricerca',
                        icon: const Icon(LucideIcons.x, size: 16),
                        onPressed: () {
                          searchField.clear();
                          c.search('');
                        }),
              ),
            ),
          ),
          const SizedBox(width: 8),
          OutlinedButton.icon(
            onPressed: () => _apriFoglioFiltri(c),
            icon: const Icon(LucideIcons.sliders_horizontal, size: 16),
            label: Text(c.filtri.isEmpty ? 'Filtri' : 'Filtri (${c.filtri.length})'),
          ),
          PopupMenuButton<String>(
            tooltip: 'Altro',
            icon: const Icon(LucideIcons.ellipsis_vertical),
            onSelected: (v) => switch (v) {
              'seleziona' => _aggiorna(() {
                  selezioneTelefono = !selezioneTelefono;
                  if (!selezioneTelefono) c.selezionati.clear();
                  c.cambiaSelezione();
                }),
              'vista' => _apriFoglioVista(c),
              _ => () {
                  searchField.clear();
                  c.azzeraFiltri();
                }(),
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'vista', child: Text('Ordine e vista')),
              if (_azioniGruppo.isNotEmpty) PopupMenuItem(value: 'seleziona', child: Text(selezioneTelefono ? 'Fine selezione' : 'Seleziona documenti')),
              if (c.hasFilters) const PopupMenuItem(value: 'resetta', child: Text('Resetta filtri')),
            ],
          ),
        ]),
        if (c.filtri.isNotEmpty)
          SizedBox(
            height: 44,
            child: ListView(scrollDirection: Axis.horizontal, children: [
              for (final d in c.filtri.keys.toList())
                Padding(
                  padding: const EdgeInsets.only(right: 8, top: 6),
                  child: InputChip(label: Text('${d.titolo}: ${c.etichettaFiltro(d)}', overflow: TextOverflow.ellipsis), onDeleted: () => c.togliFiltro(d)),
                ),
            ]),
          ),
        const SizedBox(height: 12),
        _corpo(c),
      ]),
    );
  }

  Widget _corpoTelefono(DocumentiController c) {
    final selezione = selezioneTelefono && _azioniGruppo.isNotEmpty;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (selezione) _barraSelezione(c),
      if (c.aGruppi && c.faccette != null)
        listaCarta(
          padding: const EdgeInsets.symmetric(vertical: 4),
          VistaGruppi(
            voci: c.gruppi,
            aperti: c.gruppiAperti,
            selezionati: selezione ? c.selezionati : null,
            onCambiaSelezione: c.cambiaSelezione,
            onApriChiudi: c.apriChiudiGruppo,
            onAltri: (v) => c.caricaGruppo(v, altri: true),
            onRiprova: c.caricaGruppo,
            riga: (d) => _scheda(c, d),
          ),
        )
      else if (c.loading)
        const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
      else
        _schede(c),
      if (!c.aGruppi && !c.loading && c.pagine > 1)
        PaginazioneTelefono(
          pagina: c.pagina,
          pagine: c.pagine,
          onPagina: c.vaiAPagina,
          dettaglio: '${(c.pagina - 1) * c.perPagina + 1}–${(c.pagina - 1) * c.perPagina + c.documenti.length} di ${c.totale}',
        ),
      const SizedBox(height: 16),
    ]);
  }

  Widget _barraSelezione(DocumentiController c) {
    final n = c.selezionati.length;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(children: [
        Expanded(child: Testo.bodyMedium(n == 0 ? 'Tocca i documenti da scegliere' : (n == 1 ? '1 selezionato' : '$n selezionati'), fontWeight: FontWeight.w500)),
        MenuAnchor(
          menuChildren: [
            for (final a in _azioniGruppo)
              MenuItemButton(
                onPressed: () {
                  azioneGruppo = a.$1;
                  _eseguiGruppo(c);
                },
                child: Text(a.$2),
              ),
          ],
          builder: (_, menu, __) => FilledButton.icon(
            onPressed: n > 0 ? () => menu.isOpen ? menu.close() : menu.open() : null,
            icon: const Icon(LucideIcons.chevron_down, size: 16),
            iconAlignment: IconAlignment.end,
            label: const Text('Azioni'),
          ),
        ),
        TextButton(
          onPressed: () => _aggiorna(() {
            selezioneTelefono = false;
            c.selezionati.clear();
            c.cambiaSelezione();
          }),
          child: const Text('Fine'),
        ),
      ]),
    );
  }

  /// Ordine, vista, raggruppamento e documenti per pagina: si applicano subito.
  Future<void> _apriFoglioVista(DocumentiController c) => apriFoglio<void>(
        context,
        titolo: 'Ordine e vista',
        contenuto: (_) => GetBuilder<DocumentiController>(
          init: c,
          builder: (c) {
            const ordini = [('creato-desc', 'Caricati di recente'), ('data-desc', 'Data del documento, recenti'), ('titolo-asc', 'Titolo A-Z')];
            final ordine = '${c.ordina}-${c.verso}';
            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              SegmentedButton<VistaDocumenti>(
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: VistaDocumenti.lista, icon: Icon(LucideIcons.list, size: 16), label: Text('Lista')),
                  ButtonSegment(value: VistaDocumenti.gruppi, icon: Icon(LucideIcons.layers, size: 16), label: Text('Gruppi')),
                ],
                selected: {c.vista},
                onSelectionChanged: (v) => c.cambiaVista(v.single),
              ),
              if (c.aGruppi) ...[
                const SizedBox(height: 12),
                DropdownButtonFormField<String?>(
                  initialValue: c.raggruppaPer.name,
                  isExpanded: true,
                  decoration: listaDecorazione('Raggruppa per'),
                  items: [for (final d in DimensioneFiltro.raggruppabili) DropdownMenuItem(value: d.name, child: Text(d.titolo))],
                  onChanged: (v) => c.raggruppaSecondo(DimensioneFiltro.values.byName(v!)),
                ),
              ],
              const SizedBox(height: 8),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Cerca per significato'),
                subtitle: const Text('Trova i documenti anche senza le parole esatte, per esempio "bolletta della luce". I documenti cifrati si trovano solo per titolo.'),
                value: c.perSignificato,
                onChanged: c.cambiaPerSignificato,
              ),
              const SizedBox(height: 8),
              Testo.labelMedium('Ordina per', fontWeight: FontWeight.w500),
              RadioGroup<String>(
                groupValue: ordini.any((o) => o.$1 == ordine) ? ordine : null,
                onChanged: (v) {
                  final [campo, verso] = v!.split('-');
                  c.ordinaCome(campo, verso);
                },
                child: Column(children: [for (final o in ordini) RadioListTile<String>(value: o.$1, title: Text(o.$2), contentPadding: EdgeInsets.zero, dense: true)]),
              ),
              if (!c.aGruppi) ...[
                const SizedBox(height: 8),
                Testo.labelMedium('Documenti per pagina', fontWeight: FontWeight.w500),
                const SizedBox(height: 8),
                SegmentedButton<int>(
                  showSelectedIcon: false,
                  segments: [for (final n in DocumentiController.perPaginaPossibili) ButtonSegment(value: n, label: Text('$n'))],
                  selected: {c.perPagina},
                  onSelectionChanged: (v) => c.cambiaPerPagina(v.single),
                ),
              ],
            ]);
          },
        ),
        azione: (ctx) => FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Fatto')),
      );
}
