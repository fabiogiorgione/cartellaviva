import 'package:flutter/material.dart';

/// Ombra leggera sotto i riquadri: [elevazione] ne regola distanza e sfocatura.
List<BoxShadow> ombraLeggera(BuildContext context, double elevazione) => [
      BoxShadow(
        color: Theme.of(context).shadowColor.withAlpha(25),
        spreadRadius: elevazione / 8,
        blurRadius: elevazione * 2,
        offset: Offset(0, elevazione),
      ),
    ];

/// Scheda dell'app: sfondo delle card del tema, angoli arrotondati, ombra
/// leggera. I ListTile e i pulsanti all'interno mostrano l'effetto al tocco.
class Scheda extends StatelessWidget {
  final Widget child;
  final double paddingAll;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final Color? color;
  final double? height;
  final double borderRadiusAll;
  final double elevazione;
  final GestureTapCallback? onTap;

  const Scheda({
    super.key,
    required this.child,
    this.paddingAll = 16,
    this.padding,
    this.margin,
    this.color,
    this.height,
    this.borderRadiusAll = 6,
    this.elevazione = 3,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final raggio = BorderRadius.circular(borderRadiusAll);
    final contenuto = Container(
      height: height,
      margin: margin,
      padding: padding ?? EdgeInsets.all(paddingAll),
      decoration: BoxDecoration(color: color ?? Theme.of(context).cardTheme.color, borderRadius: raggio, boxShadow: ombraLeggera(context, elevazione)),
      child: Material(type: MaterialType.transparency, child: child),
    );
    if (onTap == null) return contenuto;
    return InkWell(borderRadius: raggio, splashColor: Colors.transparent, highlightColor: Colors.transparent, onTap: onTap, child: contenuto);
  }
}

/// Riquadro semplice: sfondo (predefinito quello delle card), angoli
/// arrotondati, bordo o forma circolare a richiesta.
class Riquadro extends StatelessWidget {
  final Widget? child;
  final double paddingAll;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final Color? color;
  final double? width;
  final double? height;
  final double borderRadiusAll;
  final BorderRadius? borderRadius;
  final bool bordo;
  final BoxShape forma;

  const Riquadro({
    super.key,
    this.child,
    this.paddingAll = 16,
    this.padding,
    this.margin,
    this.color,
    this.width,
    this.height,
    this.borderRadiusAll = 6,
    this.borderRadius,
    this.bordo = false,
  }) : forma = BoxShape.rectangle;

  /// Cerchio (avatar, icone su sfondo colorato).
  const Riquadro.cerchio({super.key, this.child, this.paddingAll = 16, this.padding, this.margin, this.color, this.width, this.height})
      : forma = BoxShape.circle,
        borderRadiusAll = 0,
        borderRadius = null,
        bordo = false;

  /// Senza sfondo.
  const Riquadro.trasparente({super.key, this.child, this.paddingAll = 16, this.padding, this.margin, this.color = Colors.transparent, this.width, this.height, this.borderRadiusAll = 6, this.borderRadius})
      : bordo = false,
        forma = BoxShape.rectangle;

  /// Con il bordo del tema.
  const Riquadro.bordato({super.key, this.child, this.paddingAll = 16, this.padding, this.margin, this.color, this.width, this.height, this.borderRadiusAll = 6, this.borderRadius})
      : bordo = true,
        forma = BoxShape.rectangle;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Container(
      width: width,
      height: height,
      margin: margin,
      padding: padding ?? EdgeInsets.all(paddingAll),
      decoration: BoxDecoration(
        color: color ?? tema.cardTheme.color,
        shape: forma,
        borderRadius: forma == BoxShape.rectangle ? borderRadius ?? BorderRadius.circular(borderRadiusAll) : null,
        border: bordo ? Border.all(color: tema.dividerColor) : null,
      ),
      child: child,
    );
  }
}
