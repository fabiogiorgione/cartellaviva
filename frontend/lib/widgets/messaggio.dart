import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';

/// Messaggio breve in fondo alla pagina (esito di un'operazione); gli errori
/// sono in rosso.
void mostraMessaggio(BuildContext context, String testo, {bool errore = false}) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(testo),
      backgroundColor: errore ? context.colori.danger : null,
    ));
