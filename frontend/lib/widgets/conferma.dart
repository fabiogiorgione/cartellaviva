import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';

/// Dialogo di conferma (titolo, spiegazione, "Annulla" e l'azione): true solo
/// se si sceglie l'azione. Con [distruttiva] il pulsante è rosso.
Future<bool> chiediConferma(
  BuildContext context, {
  required String titolo,
  required String testo,
  required String azione,
  String annulla = 'Annulla',
  bool distruttiva = false,
}) async =>
    await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(titolo),
        content: SizedBox(width: 440, child: Text(testo)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(annulla)),
          FilledButton(
            style: distruttiva ? FilledButton.styleFrom(backgroundColor: context.colori.danger) : null,
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(azione),
          ),
        ],
      ),
    ) ??
    false;
