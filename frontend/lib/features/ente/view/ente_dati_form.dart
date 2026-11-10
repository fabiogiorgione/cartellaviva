import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Dati identificativi dell'ente (registrazione e profilo).
class EnteDatiForm extends StatefulWidget {
  final EnteProfilo? iniziale;
  final bool bloccaTipo;
  final String azione;
  final bool busy;
  final String? errore;
  final void Function(Map<String, dynamic> dati) onSalva;

  const EnteDatiForm({super.key, this.iniziale, this.bloccaTipo = false, required this.azione, this.busy = false, this.errore, required this.onSalva});

  @override
  State<EnteDatiForm> createState() => _EnteDatiFormState();
}

class _EnteDatiFormState extends State<EnteDatiForm> {
  late String tipo = widget.iniziale?.tipo ?? 'scuola';
  late final denominazione = TextEditingController(text: widget.iniziale?.denominazione ?? '');
  late final cf = TextEditingController(text: widget.iniziale?.codiceFiscale ?? '');
  late final mecc = TextEditingController(text: widget.iniziale?.codiceMeccanografico ?? '');
  late final runts = TextEditingController(text: widget.iniziale?.numeroRunts ?? '');
  late final email = TextEditingController(text: widget.iniziale?.tipo == 'associazione' ? widget.iniziale?.emailIstituzionale : '');
  late final sede = TextEditingController(text: widget.iniziale?.sedeLegale ?? '');

  @override
  void dispose() {
    for (final c in [denominazione, cf, mecc, runts, email, sede]) {
      c.dispose();
    }
    super.dispose();
  }

  InputDecoration _deco(String label, {String? hint, String? helper}) =>
      InputDecoration(labelText: label, hintText: hint, helperText: helper, isDense: true);

  @override
  Widget build(BuildContext context) {
    final scuola = tipo == 'scuola';
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (!widget.bloccaTipo) ...[
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'scuola', label: Text('Scuola')), ButtonSegment(value: 'associazione', label: Text('Associazione'))],
          selected: {tipo},
          onSelectionChanged: (s) => setState(() => tipo = s.first),
        ),
        SizedBox(height: 16),
      ],
      TextField(controller: denominazione, decoration: _deco('Denominazione', hint: scuola ? 'Scuola Primaria Verdi' : 'Associazione Volontari…')),
      SizedBox(height: 12),
      TextField(controller: cf, keyboardType: TextInputType.number, maxLength: 11, decoration: _deco('Codice fiscale dell\'ente', hint: '11 cifre')),
      SizedBox(height: 4),
      if (scuola)
        TextField(
          controller: mecc,
          textCapitalization: TextCapitalization.characters,
          maxLength: 10,
          decoration: _deco('Codice meccanografico', hint: 'RMEE000000', helper: 'Il codice di conferma arriva a <codice>@istruzione.it'),
        )
      else ...[
        TextField(controller: runts, decoration: _deco('Numero di iscrizione al RUNTS')),
        SizedBox(height: 12),
        TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: _deco('Email o PEC registrata nel RUNTS', helper: 'Il codice di conferma arriva qui')),
      ],
      SizedBox(height: 12),
      TextField(controller: sede, decoration: _deco('Sede legale (facoltativa)')),
      if (widget.errore != null) ...[SizedBox(height: 12), Testo.bodySmall(widget.errore!, color: ColoreStato.errore)],
      SizedBox(height: 16),
      FilledButton(
        onPressed: widget.busy
            ? null
            : () => widget.onSalva({
                  'tipo': tipo,
                  'denominazione': denominazione.text,
                  'codiceFiscale': cf.text,
                  if (scuola) 'codiceMeccanografico': mecc.text,
                  if (!scuola) 'numeroRunts': runts.text,
                  if (!scuola) 'emailIstituzionale': email.text,
                  'sedeLegale': sede.text,
                }),
        child: Text(widget.azione),
      ),
    ]);
  }
}
