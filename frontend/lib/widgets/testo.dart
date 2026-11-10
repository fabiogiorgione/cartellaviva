import 'package:flutter/material.dart';

/// Stili di testo dell'app: le misure della scala tipografica di Material 3.
enum TipoTesto {
  titleLarge(22),
  titleMedium(16),
  titleSmall(14),
  bodyLarge(16),
  bodyMedium(14),
  bodySmall(12),
  labelLarge(14),
  labelMedium(12),
  labelSmall(11);

  final double dimensione;

  const TipoTesto(this.dimensione);
}

/// Carattere dei testi dell'app e del marchio (inclusi nell'app, vedi pubspec.yaml).
const famigliaTesti = 'Poppins';
const famigliaMarchio = 'PlusJakartaSans';

/// Stile di un testo dell'app (carattere Poppins). [tenue]: colore attenuato,
/// per le informazioni secondarie. Senza [color] si usa il colore del testo del tema.
TextStyle stileTesto(
  BuildContext context,
  TipoTesto tipo, {
  FontWeight fontWeight = FontWeight.w400,
  bool tenue = false,
  Color? color,
  double? fontSize,
  double? letterSpacing,
  TextDecoration? decoration,
}) {
  final base = color ?? Theme.of(context).colorScheme.onSurface;
  return TextStyle(
    fontFamily: famigliaTesti,
    fontSize: fontSize ?? tipo.dimensione,
    fontWeight: fontWeight,
    color: tenue ? base.withAlpha(200) : base,
    letterSpacing: letterSpacing,
    decoration: decoration,
  );
}

/// Testo con uno degli stili dell'app ([TipoTesto]).
class Testo extends StatelessWidget {
  final String testo;
  final TipoTesto tipo;
  final FontWeight fontWeight;
  final bool tenue;
  final Color? color;
  final double? fontSize;
  final double? letterSpacing;
  final TextDecoration? decoration;
  final TextAlign? textAlign;
  final int? maxLines;
  final TextOverflow? overflow;
  final bool? softWrap;

  const Testo(
    this.testo, {
    super.key,
    this.tipo = TipoTesto.bodyMedium,
    this.fontWeight = FontWeight.w400,
    this.tenue = false,
    this.color,
    this.fontSize,
    this.letterSpacing,
    this.decoration,
    this.textAlign,
    this.maxLines,
    this.overflow,
    this.softWrap,
  });

  const Testo.titleLarge(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.titleLarge;
  const Testo.titleMedium(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.titleMedium;
  const Testo.titleSmall(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.titleSmall;
  const Testo.bodyLarge(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.bodyLarge;
  const Testo.bodyMedium(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.bodyMedium;
  const Testo.bodySmall(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.bodySmall;
  const Testo.labelLarge(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.labelLarge;
  const Testo.labelMedium(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.labelMedium;
  const Testo.labelSmall(this.testo, {super.key, this.fontWeight = FontWeight.w400, this.tenue = false, this.color, this.fontSize, this.letterSpacing, this.decoration, this.textAlign, this.maxLines, this.overflow, this.softWrap})
      : tipo = TipoTesto.labelSmall;

  @override
  Widget build(BuildContext context) => Text(
        testo,
        style: stileTesto(context, tipo, fontWeight: fontWeight, tenue: tenue, color: color, fontSize: fontSize, letterSpacing: letterSpacing, decoration: decoration),
        textAlign: textAlign,
        maxLines: maxLines,
        overflow: overflow,
        softWrap: softWrap,
      );
}
