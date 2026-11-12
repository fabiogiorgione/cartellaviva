part of 'scadenze_screen.dart';

/// Telefono: vista e "Nuova scadenza" su una riga, poi le scadenze trovate nei
/// documenti e l'elenco (o il calendario).
extension _ScadenzeTelefono on _ScadenzeScreenState {
  Widget _bodyTelefono(ScadenzeController c) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            Expanded(
              child: SegmentedButton<VistaScadenze>(
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: VistaScadenze.elenco, label: Text('Elenco'), icon: Icon(LucideIcons.list, size: 16)),
                  ButtonSegment(value: VistaScadenze.calendario, label: Text('Calendario'), icon: Icon(LucideIcons.calendar, size: 16)),
                ],
                selected: {c.vista},
                onSelectionChanged: (s) => c.setVista(s.first),
              ),
            ),
            if (c.canCreate) ...[
              const SizedBox(width: 8),
              FilledButton.icon(onPressed: () => _apri(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuova')),
            ],
          ]),
          const SizedBox(height: 16),
          // Nel calendario le proposte vanno sotto, per non allontanarlo dalla vista.
          if (c.canCreate && c.vista == VistaScadenze.elenco) ScadenzeProposteCard(onCambiate: c.load),
          c.vista == VistaScadenze.elenco ? _elenco(c) : _calendario(c),
          if (c.canCreate && c.vista == VistaScadenze.calendario) ...[const SizedBox(height: 16), ScadenzeProposteCard(onCambiate: c.load)],
          const SizedBox(height: 16),
        ],
      );
}
