part of 'documenti_screen.dart';

/// Righe della lista: tabella su schermo largo, schede sul telefono, azioni per riga.
extension _RigheDocumenti on _DocumentiScreenState {
  String _testo(Documento d) => switch (d.ocrStatus) {
        'completato' => 'letto',
        'in_coda' => 'in lettura',
        'fallito' => 'non riuscito',
        _ => '—',
      };

  /// [compatta]: accanto al pannello dei filtri non ci stanno tutte le colonne.
  Widget _tabella(DocumentiController c, {required bool compatta}) => ListaTabella(
        larghezzaMinima: compatta ? 720 : 1100,
        ordina: c.ordina,
        verso: c.verso,
        onOrdina: c.ordinaPer,
        selezionati: _azioniGruppo.isEmpty ? null : c.selezionati,
        onCambiaSelezione: c.cambiaSelezione,
        colonne: [
          const ListaColonna('Titolo', flex: 5, ordina: 'titolo'),
          const ListaColonna('Categoria', flex: 3),
          const ListaColonna('Riguarda', flex: 3),
          const ListaColonna('Data', flex: 2, ordina: 'data'),
          if (!compatta) const ListaColonna('Caricato', flex: 2, ordina: 'creato'),
          if (!compatta) const ListaColonna('Protezione e testo', flex: 3),
          const ListaColonna('Azioni', flex: 4, destra: true),
        ],
        righe: [
          for (final d in c.documenti) {'id': d.id, 'd': d}
        ],
        celle: (r) {
          final d = r['d'] as Documento;
          return [
            InkWell(
              onTap: () => Get.toNamed('/documento/${d.id}'),
              child: Row(children: [
                Tooltip(message: d.tipoFile, child: Icon(iconaTipoFile(d), size: 18, color: context.colori.primary)),
                SizedBox(width: 10),
                Flexible(child: Text(d.titolo, overflow: TextOverflow.ellipsis, maxLines: 2, style: TextStyle(color: context.colori.primary, fontWeight: FontWeight.w600))),
                if (compatta && d.cifrato) ...[SizedBox(width: 6), Tooltip(message: 'Cifrato', child: Icon(LucideIcons.lock, size: 14, color: context.colori.success))],
              ]),
            ),
            _categoria(d),
            Text(d.membri.isEmpty ? 'Tutta la famiglia' : d.membri.map((m) => m.nome).join(', '), overflow: TextOverflow.ellipsis, maxLines: 2),
            Text(d.dataDocumento == null ? '—' : formatDataBreve(d.dataDocumento!)),
            if (!compatta) Text(d.createdAt == null ? '—' : formatDataBreve(d.createdAt!.toLocal())),
            if (!compatta)
              Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                d.cifrato
                    ? Row(children: [Icon(LucideIcons.lock, size: 14, color: context.colori.success), SizedBox(width: 6), const Text('Cifrato')])
                    : Testo.bodySmall('Non cifrato', tenue: true),
                Testo.bodySmall('Testo: ${_testo(d)}', tenue: true, color: d.ocrStatus == 'fallito' ? context.colori.danger : null),
              ]),
            listaAzioni(_azioniRiga(d)),
          ];
        },
      );

  Widget _categoria(Documento d) {
    final cat = d.categoria;
    if (cat == null) return const Text('—');
    final colore = hexColor(cat.colore);
    return Row(children: [
      CircleAvatar(radius: 12, backgroundColor: colore.withAlpha(36), child: Icon(iconaCategoria(cat.icona), size: 13, color: colore)),
      SizedBox(width: 8),
      Flexible(child: Text(cat.nome, overflow: TextOverflow.ellipsis)),
    ]);
  }

  List<Widget> _azioniRiga(Documento d) => [
        listaAzione(LucideIcons.external_link, 'Apri il file', () => _apri(d)),
        if (_puoModificare(d)) listaAzione(LucideIcons.pencil, 'Modifica', () => _modifica(d)),
        if (_puoCondividere) listaAzione(LucideIcons.share_2, 'Condividi', () => showCondividiDialog(context, documenti: [(id: d.id, titolo: d.titolo)])),
        if (_puoEliminare(d)) listaAzione(LucideIcons.trash, 'Elimina', () => _elimina(d), colore: context.colori.danger),
      ];

  /// Telefono: una scheda per documento, con selezione e menu delle azioni.
  Widget _schede(DocumentiController c) => Column(children: [for (final d in c.documenti) _scheda(c, d)]);

  Widget _scheda(DocumentiController c, Documento d) => Card(
        margin: EdgeInsets.symmetric(horizontal: 4, vertical: 4),
        elevation: 0,
        shape: RoundedRectangleBorder(side: BorderSide(color: Theme.of(context).dividerColor), borderRadius: BorderRadius.circular(8)),
        child: InkWell(
          onTap: () => Get.toNamed('/documento/${d.id}'),
          child: Padding(
            padding: EdgeInsets.fromLTRB(4, 8, 4, 8),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (_azioniGruppo.isNotEmpty)
                Checkbox(
                  value: c.selezionati.contains(d.id),
                  onChanged: (v) {
                    v == true ? c.selezionati.add(d.id) : c.selezionati.remove(d.id);
                    c.cambiaSelezione();
                  },
                )
              else
                SizedBox(width: 8),
              Padding(padding: EdgeInsets.only(top: 10), child: Icon(iconaTipoFile(d), size: 20, color: context.colori.primary)),
              SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SizedBox(height: 6),
                  Testo.bodyMedium(d.titolo, fontWeight: FontWeight.w500, maxLines: 2, overflow: TextOverflow.ellipsis),
                  SizedBox(height: 6),
                  _categoria(d),
                  SizedBox(height: 6),
                  Testo.bodySmall([
                      if (d.dataDocumento != null) formatDataBreve(d.dataDocumento!) else if (d.createdAt != null) 'caricato il ${formatDataBreve(d.createdAt!.toLocal())}',
                      if (d.membri.isNotEmpty) d.membri.map((m) => m.nome).join(', '),
                      if (d.cifrato) 'cifrato',
                      'testo ${_testo(d)}',
                    ].join(' · '), tenue: true),
                ]),
              ),
              PopupMenuButton<String>(
                tooltip: 'Azioni',
                onSelected: (v) => switch (v) {
                  'apri' => _apri(d),
                  'modifica' => _modifica(d),
                  'condividi' => showCondividiDialog(context, documenti: [(id: d.id, titolo: d.titolo)]),
                  _ => _elimina(d),
                },
                itemBuilder: (_) => [
                  const PopupMenuItem(value: 'apri', child: Text('Apri il file')),
                  if (_puoModificare(d)) const PopupMenuItem(value: 'modifica', child: Text('Modifica')),
                  if (_puoCondividere) const PopupMenuItem(value: 'condividi', child: Text('Condividi')),
                  if (_puoEliminare(d)) const PopupMenuItem(value: 'elimina', child: Text('Elimina')),
                ],
              ),
            ]),
          ),
        ),
      );
}
