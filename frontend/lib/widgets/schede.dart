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
Widget numeroCard(String etichetta, String valore, {String? nota, VoidCallback? onTap}) => Builder(builder: (context) {
      final schermo = MediaQuery.sizeOf(context).width;
      if (schermo >= 769) {
        return SizedBox(
          width: 210,
          child: Scheda(paddingAll: 16, onTap: onTap, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.labelSmall(etichetta.toUpperCase(), tenue: true, fontWeight: FontWeight.w600),
              SizedBox(height: 6),
              Testo.titleLarge(valore, fontWeight: FontWeight.w600),
              if (nota != null) Testo.bodySmall(nota, tenue: true),
            ])),
        );
      }
      // Sul telefono tre per riga e compatte: prima i numeri occupavano metà
      // schermo e il contenuto della pagina (elenchi, richieste) finiva sotto.
      return SizedBox(
        width: ((schermo - 68) / 3).clamp(96.0, 160.0),
        child: Scheda(paddingAll: 10, onTap: onTap, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.labelSmall(etichetta, tenue: true, fontSize: 11, maxLines: 2, overflow: TextOverflow.ellipsis),
            SizedBox(height: 4),
            Testo.titleMedium(valore, fontWeight: FontWeight.w600),
            if (nota != null) Testo.bodySmall(nota, tenue: true, fontSize: 11, maxLines: 1, overflow: TextOverflow.ellipsis),
          ])),
      );
    });
