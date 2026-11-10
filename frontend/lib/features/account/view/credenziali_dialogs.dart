import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Cambia (o imposta, per gli account Google) la password. true se salvata.
Future<bool> showCambiaPasswordDialog(BuildContext context, {required bool haPassword}) async =>
    await showDialog<bool>(context: context, builder: (_) => _CambiaPasswordDialog(haPassword: haPassword)) ?? false;

/// Chiede il cambio di email: il link va al nuovo indirizzo. true se inviato.
Future<bool> showCambiaEmailDialog(BuildContext context) async => await showDialog<bool>(context: context, builder: (_) => const _CambiaEmailDialog()) ?? false;

class _CambiaPasswordDialog extends StatefulWidget {
  final bool haPassword;

  const _CambiaPasswordDialog({required this.haPassword});

  @override
  State<_CambiaPasswordDialog> createState() => _CambiaPasswordDialogState();
}

class _CambiaPasswordDialogState extends State<_CambiaPasswordDialog> {
  final attuale = TextEditingController();
  final nuova = TextEditingController();
  final conferma = TextEditingController();
  bool mostra = false;
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    for (final c in [attuale, nuova, conferma]) {
      c.dispose();
    }
    super.dispose();
  }

  String? get _problema {
    if (widget.haPassword && attuale.text.isEmpty) return 'Inserisci la password attuale.';
    if (nuova.text.length < 10) return 'La nuova password deve avere almeno 10 caratteri.';
    if (nuova.text != conferma.text) return 'Le due password non coincidono.';
    return null;
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await SessionService.to.cambiaPassword(attuale: widget.haPassword ? attuale.text : null, nuova: nuova.text);
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    InputDecoration deco(String label) => InputDecoration(labelText: label, isDense: true);
    final problema = nuova.text.isEmpty ? null : _problema;
    return AlertDialog(
      title: Text(widget.haPassword ? 'Cambia password' : 'Imposta una password'),
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (!widget.haPassword) ...[
            Testo.bodySmall('Hai creato l\'account con Google: con una password potrai accedere anche senza.', tenue: true),
            SizedBox(height: 12),
          ],
          if (widget.haPassword) ...[
            TextField(controller: attuale, obscureText: !mostra, autofillHints: const [AutofillHints.password], onChanged: (_) => setState(() {}), decoration: deco('Password attuale')),
            SizedBox(height: 12),
          ],
          TextField(
            controller: nuova,
            obscureText: !mostra,
            autofillHints: const [AutofillHints.newPassword],
            onChanged: (_) => setState(() {}),
            decoration: deco('Nuova password').copyWith(
              suffixIcon: IconButton(icon: Icon(mostra ? LucideIcons.eye_off : LucideIcons.eye, size: 18), onPressed: () => setState(() => mostra = !mostra)),
            ),
          ),
          SizedBox(height: 12),
          TextField(controller: conferma, obscureText: !mostra, onChanged: (_) => setState(() {}), decoration: deco('Ripeti la nuova password')),
          SizedBox(height: 8),
          Testo.bodySmall(problema ?? 'Le altre sessioni (altri dispositivi e browser) verranno chiuse; questa resta aperta.', color: problema != null ? ColoreStato.errore : null, tenue: problema == null),
          if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
        ]),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy || _problema != null ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}

class _CambiaEmailDialog extends StatefulWidget {
  const _CambiaEmailDialog();

  @override
  State<_CambiaEmailDialog> createState() => _CambiaEmailDialogState();
}

class _CambiaEmailDialogState extends State<_CambiaEmailDialog> {
  final email = TextEditingController();
  final password = TextEditingController();
  bool busy = false;
  String? errore;
  String? inviato;

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> _invia() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      inviato = await AccountApi.cambiaEmail(email.text, password.text);
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final valido = email.text.contains('@') && password.text.isNotEmpty;
    return AlertDialog(
      title: const Text('Cambia indirizzo email'),
      content: SizedBox(
        width: 420,
        child: inviato != null
            ? Testo.bodyMedium(inviato!)
            : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Testo.bodySmall('Attuale: ${SessionService.to.me?.email ?? ''}. Ti mandiamo un link al nuovo indirizzo; all\'attuale arriva un avviso.', tenue: true),
                SizedBox(height: 12),
                TextField(
                  controller: email,
                  keyboardType: TextInputType.emailAddress,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(labelText: 'Nuovo indirizzo', isDense: true),
                ),
                SizedBox(height: 12),
                TextField(
                  controller: password,
                  obscureText: true,
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(labelText: 'Password attuale (per conferma)', isDense: true),
                ),
                if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
              ]),
      ),
      actions: [
        if (inviato != null)
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Ok'))
        else ...[
          TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
          FilledButton(onPressed: busy || !valido ? null : _invia, child: const Text('Invia il link')),
        ],
      ],
    );
  }
}
