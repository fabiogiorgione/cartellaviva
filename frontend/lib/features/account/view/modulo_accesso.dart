import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Bordo leggero dei campi delle pagine di accesso.
const bordoCampoAccesso = OutlineInputBorder(borderSide: BorderSide(color: Color(0x3d6c757d)));

/// Modulo delle pagine di accesso: logo in alto, poi il contenuto distribuito in altezza.
class ModuloAccesso extends StatelessWidget {
  final GlobalKey<FormState> formKey;
  final List<Widget> children;

  const ModuloAccesso({super.key, required this.formKey, required this.children});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(20),
        child: Form(
          key: formKey,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [const SizedBox(height: 28, child: LogoCartellaViva(altezza: 28)), ...children],
          ),
        ),
      );
}

/// Campo di testo delle pagine di accesso (email, password, nome...).
class CampoAccesso extends StatelessWidget {
  final TextEditingController? controller;
  final FormFieldValidator<String>? validator;
  final String hint;
  final TextInputType? keyboardType;
  final Iterable<String>? autofillHints;
  final bool obscureText;
  final ValueChanged<String>? onFieldSubmitted;

  const CampoAccesso({
    super.key,
    required this.controller,
    required this.validator,
    required this.hint,
    this.keyboardType,
    this.autofillHints,
    this.obscureText = false,
    this.onFieldSubmitted,
  });

  @override
  Widget build(BuildContext context) {
    final stile = stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500, tenue: true);
    return TextFormField(
      controller: controller,
      validator: validator,
      keyboardType: keyboardType,
      autofillHints: autofillHints,
      obscureText: obscureText,
      onFieldSubmitted: onFieldSubmitted,
      style: stile,
      decoration: InputDecoration(
        hintText: hint,
        hintStyle: stile,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        border: bordoCampoAccesso,
        disabledBorder: bordoCampoAccesso,
        errorBorder: bordoCampoAccesso,
        focusedBorder: bordoCampoAccesso,
        enabledBorder: bordoCampoAccesso,
      ),
    );
  }
}
