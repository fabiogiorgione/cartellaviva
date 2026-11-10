import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

/// Scelta della modalità di firma per le richieste di tipo "firma":
/// su carta (stampa e scansione) o con codice via email (FES, senza stampare).
class ModalitaFirmaSelector extends StatelessWidget {
  final String value;
  final ValueChanged<String> onChanged;

  const ModalitaFirmaSelector({super.key, required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'cartacea', label: Text('Su carta'), icon: Icon(LucideIcons.printer)),
            ButtonSegment(value: 'codice', label: Text('Con codice email'), icon: Icon(LucideIcons.mail_check)),
          ],
          selected: {value},
          onSelectionChanged: (s) => onChanged(s.first),
        ),
        SizedBox(height: 4),
        Testo.bodySmall(value == 'codice'
              ? 'Ogni referente firma online con un codice inviato alla sua email: firma elettronica semplice, '
                  'il PDF firmato riceve una pagina di attestazione verificabile. Resta possibile firmare su carta.'
              : 'Il referente stampa, firma e carica la scansione (o la foto) del documento.', tenue: true),
      ]);
}
