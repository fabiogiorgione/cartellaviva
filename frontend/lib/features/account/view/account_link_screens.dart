import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Pagine aperte dai link delle email dell'account: nuova password
/// (/auth/reset-password), prima password di un account creato dal portale
/// (/auth/attiva), verifica dell'indirizzo (/auth/verifica-email) e
/// conferma del nuovo indirizzo (/auth/cambio-email). Tutte con ?token=.

Widget _pagina(String titolo, List<Widget> children) => AuthLayout(
      child: Padding(
        padding: EdgeInsets.all(20),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium(titolo, fontWeight: FontWeight.w500),
          SizedBox(height: 12),
          ...children,
        ]),
      ),
    );

Widget _esito(bool ok, String testo) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(ok ? LucideIcons.circle_check : LucideIcons.circle_x, color: ok ? ColoreStato.successo : ColoreStato.errore, size: 20),
      SizedBox(width: 8),
      Expanded(child: Testo.bodyMedium(testo)),
    ]);

// ---------------------------------------------------------------------------
// Nuova password
// ---------------------------------------------------------------------------

class ResetPasswordScreen extends StatefulWidget {
  /// true: primo accesso di un account creato dal portale ("Attiva il tuo account").
  final bool attivazione;

  const ResetPasswordScreen({super.key, this.attivazione = false});

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final String token = Get.parameters['token'] ?? '';
  late final bool attivazione = widget.attivazione;
  final password = TextEditingController();
  final conferma = TextEditingController();
  String? email;
  String? errore;
  String? fatto;
  bool busy = true;
  bool mostra = false;

  @override
  void initState() {
    super.initState();
    (attivazione ? AccountApi.anteprimaAttivazione(token) : AccountApi.anteprimaReset(token)).then((e) => email = e, onError: (e) => errore = e is ApiException ? e.message : '$e').whenComplete(() {
      if (mounted) setState(() => busy = false);
    });
  }

  @override
  void dispose() {
    password.dispose();
    conferma.dispose();
    super.dispose();
  }

  String? get _problema {
    if (password.text.length < 10) return 'Almeno 10 caratteri.';
    if (password.text != conferma.text) return 'Le due password non coincidono.';
    return null;
  }

  Future<void> _salva() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      fatto = await (attivazione ? AccountApi.attiva(token, password.text) : AccountApi.reimpostaPassword(token, password.text));
      // Eventuali sessioni su questo dispositivo sono state chiuse dal server.
      if (SessionService.to.isLoggedIn) await SessionService.to.clear();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final titolo = attivazione ? 'Attiva il tuo account' : 'Nuova password';
    if (fatto != null) {
      return _pagina(attivazione ? 'Account attivato' : 'Password aggiornata', [
        _esito(true, fatto!),
        SizedBox(height: 16),
        FilledButton(onPressed: () => Get.offAllNamed('/auth/login'), child: const Text('Vai all\'accesso')),
      ]);
    }
    if (email == null) {
      return _pagina(titolo, [
        if (busy) const Center(child: CircularProgressIndicator()) else _esito(false, errore ?? 'Link non valido.'),
        SizedBox(height: 16),
        if (attivazione && !busy)
          Testo.bodySmall('Puoi comunque scegliere la password con "Password dimenticata", usando l\'indirizzo email a cui è arrivato l\'invito.', tenue: true),
        TextButton(onPressed: () => Get.offAllNamed('/auth/forgot_password'), child: Text(attivazione ? 'Password dimenticata' : 'Richiedi un nuovo link')),
      ]);
    }
    final problema = password.text.isEmpty ? null : _problema;
    return _pagina(titolo, [
      Testo.bodySmall(attivazione
              ? 'Il team di CartellaViva ha creato un account per $email. Scegli la password con cui entrerai.'
              : 'Scegli una nuova password per $email. Dopo il cambio, tutte le sessioni aperte verranno chiuse.', tenue: true),
      SizedBox(height: 16),
      TextField(
        controller: password,
        obscureText: !mostra,
        autofillHints: const [AutofillHints.newPassword],
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(
          labelText: 'Nuova password',
          isDense: true,
          suffixIcon: IconButton(icon: Icon(mostra ? LucideIcons.eye_off : LucideIcons.eye, size: 18), onPressed: () => setState(() => mostra = !mostra)),
        ),
      ),
      SizedBox(height: 12),
      TextField(
        controller: conferma,
        obscureText: !mostra,
        onChanged: (_) => setState(() {}),
        onSubmitted: (_) => _problema == null && !busy ? _salva() : null,
        decoration: const InputDecoration(labelText: 'Ripeti la password', isDense: true),
      ),
      SizedBox(height: 6),
      Testo.bodySmall(problema ?? 'Almeno 10 caratteri. Meglio una frase lunga che una parola complicata.', color: problema != null ? ColoreStato.errore : null, tenue: problema == null),
      if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
      SizedBox(height: 16),
      FilledButton(onPressed: busy || _problema != null ? null : _salva, child: Text(attivazione ? 'Attiva l\'account' : 'Salva la nuova password')),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Verifica dell'email
// ---------------------------------------------------------------------------

class VerificaEmailScreen extends StatefulWidget {
  const VerificaEmailScreen({super.key});

  @override
  State<VerificaEmailScreen> createState() => _VerificaEmailScreenState();
}

class _VerificaEmailScreenState extends State<VerificaEmailScreen> {
  String? messaggio;
  String? errore;

  @override
  void initState() {
    super.initState();
    _verifica();
  }

  Future<void> _verifica() async {
    try {
      messaggio = await AccountApi.verificaEmail(Get.parameters['token'] ?? '');
      // Se è già aperta una sessione, l'avviso in cima sparisce subito.
      if (SessionService.to.isLoggedIn) await SessionService.to.loadMe();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final loggato = SessionService.to.isLoggedIn;
    return _pagina('Verifica dell\'email', [
      if (messaggio == null && errore == null) const Center(child: CircularProgressIndicator()) else _esito(messaggio != null, messaggio ?? errore!),
      if (errore != null && loggato) ...[
        SizedBox(height: 8),
        Testo.bodySmall('Puoi farti mandare un nuovo link dall\'avviso in cima all\'app.', tenue: true),
      ],
      SizedBox(height: 16),
      FilledButton(
        onPressed: messaggio == null && errore == null ? null : () => Get.offAllNamed(loggato ? SessionService.to.homeRoute() : '/auth/login'),
        child: Text(loggato ? 'Vai all\'app' : 'Vai all\'accesso'),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Conferma del nuovo indirizzo
// ---------------------------------------------------------------------------

class CambioEmailScreen extends StatefulWidget {
  const CambioEmailScreen({super.key});

  @override
  State<CambioEmailScreen> createState() => _CambioEmailScreenState();
}

class _CambioEmailScreenState extends State<CambioEmailScreen> {
  String? messaggio;
  String? errore;

  @override
  void initState() {
    super.initState();
    _conferma();
  }

  Future<void> _conferma() async {
    try {
      messaggio = (await AccountApi.confermaCambioEmail(Get.parameters['token'] ?? '')).messaggio;
      // Le sessioni sono state chiuse: si rientra con il nuovo indirizzo.
      if (SessionService.to.isLoggedIn) await SessionService.to.clear();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return _pagina('Nuovo indirizzo email', [
      if (messaggio == null && errore == null) const Center(child: CircularProgressIndicator()) else _esito(messaggio != null, messaggio ?? errore!),
      SizedBox(height: 16),
      FilledButton(onPressed: messaggio == null && errore == null ? null : () => Get.offAllNamed('/auth/login'), child: const Text('Vai all\'accesso')),
    ]);
  }
}
