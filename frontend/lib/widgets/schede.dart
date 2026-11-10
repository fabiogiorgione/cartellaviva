import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

/// Riquadro di errore con "Riprova".
Widget erroreCard(String messaggio, VoidCallback riprova) => Scheda(paddingAll: 24, child: Row(children: [
        Builder(builder: (context) => Icon(LucideIcons.triangle_alert, color: context.colori.danger)),
        SizedBox(width: 12),
        Expanded(child: Testo.bodyMedium(messaggio)),
        TextButton(onPressed: riprova, child: const Text('Riprova')),
      ]));

/// Numero grande con etichetta (cruscotti).
Widget numeroCard(String etichetta, String valore, {String? nota}) => SizedBox(
      width: 210,
      child: Scheda(paddingAll: 16, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.labelSmall(etichetta.toUpperCase(), tenue: true, fontWeight: FontWeight.w600),
          SizedBox(height: 6),
          Testo.titleLarge(valore, fontWeight: FontWeight.w600),
          if (nota != null) Testo.bodySmall(nota, tenue: true),
        ])),
    );
