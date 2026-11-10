import 'package:flutter/material.dart';

/// Larghezza da cui le celle si affiancano; sotto, ognuna occupa tutta la riga.
const larghezzaAffiancata = 1200.0;

/// Una cella della [Griglia]: [colonne] su 12 sugli schermi larghi.
class CellaGriglia {
  final int colonne;
  final Widget child;

  const CellaGriglia({this.colonne = 12, required this.child});
}

/// Celle affiancate su una griglia di 12 colonne, a capo quando la riga è piena.
class Griglia extends StatelessWidget {
  final List<CellaGriglia> celle;
  final double spaziatura;

  const Griglia({super.key, required this.celle, required this.spaziatura});

  @override
  Widget build(BuildContext context) {
    final larga = MediaQuery.of(context).size.width >= larghezzaAffiancata;
    // Mezzo spazio anche ai bordi: le celle si allineano al contenuto delle pagine.
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: spaziatura / 2),
      child: LayoutBuilder(builder: (context, vincoli) {
        double larghezza(int colonne) => colonne >= 12 ? vincoli.maxWidth : (vincoli.maxWidth + spaziatura) * colonne / 12 - spaziatura;
        return Wrap(spacing: spaziatura, runSpacing: spaziatura, children: [
          for (final c in celle) SizedBox(width: larghezza(larga ? c.colonne : 12), child: c.child),
        ]);
      }),
    );
  }
}
