part of 'documenti_screen.dart';

/// Operazioni sui documenti selezionati: sposta, proponi categoria con l'AI, condividi, elimina.
extension _OperazioniDiGruppo on _DocumentiScreenState {
  List<(String, String)> get _azioniGruppo => [
        if (session.can('documento:write', resourceOwnerId: session.me?.id)) ('sposta', 'Sposta in categoria…'),
        if (session.can('documento:write', resourceOwnerId: session.me?.id)) ('ai_categoria', 'Proponi categoria con l\'AI…'),
        if (_puoCondividere) ('condividi', 'Condividi insieme'),
        if (session.can('documento:delete', resourceOwnerId: session.me?.id)) ('elimina', 'Elimina'),
      ];

  Future<void> _eseguiGruppo(DocumentiController c) async {
    final scelti = c.documentiSelezionati;
    if (scelti.isEmpty || azioneGruppo == null) return;
    switch (azioneGruppo) {
      case 'condividi':
        await showCondividiDialog(context, documenti: [for (final d in scelti) (id: d.id, titolo: d.titolo)]);
        return;
      case 'ai_categoria':
        if (scelti.length > ProposteCategorie.max) {
          _snack('L\'AI analizza al massimo ${ProposteCategorie.max} documenti per volta: ne hai selezionati ${scelti.length}.', errore: true);
          return;
        }
        final cambiato = await showPropostaCategorieWizard(
          context,
          tenantId: c.tenantId!,
          documenti: scelti,
          categorie: c.categorie,
          puoCreareCategorie: session.can('categoria:manage'),
        );
        if (cambiato) {
          c.selezionati.clear();
          c.categorie = []; // ricaricate: possono essercene di nuove
          await c.load();
        }
      case 'sposta':
        final categoriaId = await _scegliCategoria(c, scelti.length);
        if (categoriaId == null) return;
        await _gruppo(c, 'sposta', scelti, categoriaId: categoriaId);
      case 'elimina':
        if (!await _conferma('Eliminare ${scelti.length} documenti?', 'Saranno cancellati definitivamente, con i loro file. Chi non puoi eliminare viene saltato.')) return;
        await _gruppo(c, 'elimina', scelti);
    }
  }

  Future<void> _gruppo(DocumentiController c, String azione, List<Documento> scelti, {String? categoriaId}) async {
    try {
      final esito = await DocumentoApi.gruppo(c.tenantId!, azione, [for (final d in scelti) d.id], categoriaId: categoriaId);
      c.selezionati.clear();
      await c.load();
      if (!mounted) return;
      final fatto = azione == 'sposta' ? 'Spostati' : 'Eliminati';
      if (esito.saltati.isEmpty) {
        _snack('$fatto ${esito.fatti} documenti.');
        return;
      }
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text('$fatto ${esito.fatti} documenti'),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Saltati (${esito.saltati.length}):'),
              SizedBox(height: 8),
              for (final s in esito.saltati) Text('• ${s.titolo ?? 'documento'}: ${s.motivo}'),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi'))],
        ),
      );
    } on ApiException catch (e) {
      _snack(e.message, errore: true);
    }
  }

  Future<String?> _scegliCategoria(DocumentiController c, int quanti) async {
    String? scelta;
    return showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) {
          final cat = c.categorie.where((x) => x.id == scelta).firstOrNull;
          return AlertDialog(
            title: Text('Sposta $quanti documenti'),
            content: SizedBox(
              width: 440,
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                DropdownButtonFormField<String>(
                  initialValue: scelta,
                  isExpanded: true,
                  decoration: listaDecorazione('Nuova categoria'),
                  items: [
                    for (final x in c.categorie)
                      DropdownMenuItem(
                        value: x.id,
                        child: Row(children: [
                          Icon(iconaCategoria(x.icona), size: 16, color: hexColor(x.colore)),
                          SizedBox(width: 8),
                          Flexible(child: Text('${x.nome}${x.isSensibile ? ' (cifrata)' : ''}', overflow: TextOverflow.ellipsis)),
                        ]),
                      ),
                  ],
                  onChanged: (v) => setLocal(() => scelta = v),
                ),
                if (cat?.isSensibile == true) ...[
                  SizedBox(height: 8),
                  Testo.bodySmall('Categoria sensibile: i documenti spostati qui vengono cifrati.', tenue: true),
                ],
              ]),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Annulla')),
              FilledButton(onPressed: scelta == null ? null : () => Navigator.pop(ctx, scelta), child: const Text('Sposta')),
            ],
          );
        },
      ),
    );
  }
}
