part of 'portale_screens.dart';

// ---------------------------------------------------------------------------
// Liste del portale (famiglie ed enti, utenti): intestazione con le azioni e
// il totale, pannello dei filtri, tabella paginata con azioni per riga.
// Filtri, ordinamento e paginazione li applica il backend.
// ---------------------------------------------------------------------------

Widget _link(String testo, VoidCallback onTap) => InkWell(
      onTap: onTap,
      child: Builder(builder: (context) => Text(testo, style: TextStyle(color: context.colori.primary, fontWeight: FontWeight.w600))),
    );

Future<void> _scaricaCsv(BuildContext context, String cosa, Map<String, String?> filtri) async {
  try {
    final bytes = await PortaleApi.esporta(cosa, filtri);
    final oggi = DateTime.now().toIso8601String().substring(0, 10);
    await saveOrOpenFile(bytes, '${cosa == 'utenti' ? 'utenti' : 'famiglie-enti'}-$oggi.csv', 'text/csv');
  } on ApiException catch (e) {
    if (context.mounted) _snack(context, e.message);
  }
}

String _statoTenantLabel(String s) => switch (s) {
      'attivo' => 'attivo',
      'senza_proprietario' => 'senza proprietario',
      'in_eliminazione' => 'in eliminazione',
      'ente_verificato' => 'verificato',
      'ente_in_verifica' => 'in verifica',
      'ente_email_da_confermare' => 'email da confermare',
      'ente_integrazioni' => 'integrazioni',
      'ente_respinto' => 'respinto',
      _ => s.replaceAll('_', ' '),
    };

Color _coloreStato(String s) {
  // Colori di stato uguali nel tema chiaro e in quello scuro.
  const t = ColoriApp.chiaro;
  return switch (s) {
    'attivo' || 'ente_verificato' => t.success,
    'in_eliminazione' || 'bloccato' || 'ente_respinto' || 'eliminato' => t.danger,
    'senza_proprietario' || 'in_attesa' || 'ente_integrazioni' || 'ente_in_verifica' || 'ente_email_da_confermare' => t.warning,
    _ => t.secondary,
  };
}

// ===========================================================================
// Famiglie ed enti
// ===========================================================================

/// "Resetta filtri" e "Cerca" sotto i filtri delle liste del portale.
Widget _pulsantiFiltri({required VoidCallback onReset, required VoidCallback onCerca}) => Row(mainAxisAlignment: MainAxisAlignment.end, children: [
      OutlinedButton.icon(onPressed: onReset, icon: const Icon(LucideIcons.rotate_ccw, size: 16), label: const Text('Resetta filtri')),
      const SizedBox(width: 8),
      FilledButton.icon(onPressed: onCerca, icon: const Icon(LucideIcons.search, size: 16), label: const Text('Cerca')),
    ]);

/// Righe per pagina delle liste del portale.
Widget _selettorePerPagina(int valore, ValueChanged<int> onChanged) => DropdownButton<int>(
      value: valore,
      underline: const SizedBox.shrink(),
      items: [for (final n in const [10, 25, 50, 100]) DropdownMenuItem(value: n, child: Text('$n per pagina'))],
      onChanged: (v) => onChanged(v ?? 25),
    );
