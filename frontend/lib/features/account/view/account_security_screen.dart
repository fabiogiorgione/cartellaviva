import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/griglia.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter/services.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/account_security_controller.dart';
import 'package:cartellaviva/helpers/services/ai_locale.dart';
import 'package:cartellaviva/helpers/services/google_auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/view/credenziali_dialogs.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/google_sign_in_button.dart';
import 'package:qr_flutter/qr_flutter.dart';

class AccountSecurityScreen extends StatefulWidget {
  const AccountSecurityScreen({super.key});

  @override
  State<AccountSecurityScreen> createState() => _AccountSecurityScreenState();
}

class _AccountSecurityScreenState extends State<AccountSecurityScreen> {
  final AccountSecurityController controller = Get.put(AccountSecurityController());

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder(
        init: controller,
        global: false,
        // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
        dispose: (_) => controller.onDelete(),
        builder: (controller) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Flexible(child: Testo.titleMedium("Sicurezza e accesso", fontSize: 18, fontWeight: FontWeight.w500)),
                    Briciole(voci: [Briciola(nome: 'Account'), Briciola(nome: 'Sicurezza', attiva: true)]),
                  ],
                ),
              ),
              SizedBox(height: spazioPagina),
              if (SessionService.to.me?.twoFactorObbligatoria == true)
                Padding(
                  padding: EdgeInsets.fromLTRB(spazioPagina, 0, spazioPagina, spazioPagina),
                  child: Scheda(paddingAll: 16, child: Testo.bodyMedium('Il tuo accesso è stato recuperato con l\'aiuto del supporto: per continuare ad usare CartellaViva attiva di nuovo la verifica in due passaggi.', fontWeight: FontWeight.w500)),
                ),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina / 2),
                child: controller.loading
                    ? Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
                    : Griglia(spaziatura: spazioPagina, celle: [
                        CellaGriglia(colonne: 7, child: twoFactorCard()),
                        CellaGriglia(colonne: 5, child: googleCard()),
                        CellaGriglia(colonne: 7, child: credenzialiCard()),
                        if (AiLocale.supportata) CellaGriglia(colonne: 5, child: aiLocaleCard()),
                      ]),
              ),
            ],
          );
        },
      ),
    );
  }

  // ---- Verifica in due passaggi --------------------------------------------------

  Widget twoFactorCard() {
    final enabled = controller.status?.twoFactorEnabled ?? false;
    return card(
      icon: LucideIcons.shield_check,
      title: 'Verifica in due passaggi',
      subtitle: 'Oltre alla password (o a Google) serve un codice generato da Google Authenticator, Microsoft Authenticator o app compatibili.',
      children: [
        Row(children: [
          badge(enabled ? 'Attiva' : 'Non attiva', enabled ? context.colori.success : context.colori.warning),
          if (enabled) ...[
            SizedBox(width: 12),
            Testo.bodySmall('${controller.status!.backupCodesRemaining} codici di recupero rimasti', tenue: true),
          ],
        ]),
        SizedBox(height: 16),
        if (controller.freshBackupCodes != null) backupCodesBox(),
        if (!enabled && controller.setup == null)
          FilledButton(onPressed: controller.busy ? null : controller.startSetup, child: Testo.bodyMedium('Attiva', color: Theme.of(context).colorScheme.onPrimary)),
        if (controller.setup != null) setupSteps(),
        if (enabled) manageEnabled(),
      ],
    );
  }

  Widget setupSteps() {
    final setup = controller.setup!;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodyMedium('1. Scansiona il QR code con l\'app di autenticazione', fontWeight: FontWeight.w500),
        SizedBox(height: 12),
        Riquadro(color: Colors.white, paddingAll: 12, child: QrImageView(data: setup['otpauthUri']!, size: 180, backgroundColor: Colors.white)),
        SizedBox(height: 8),
        Testo.bodySmall('Non riesci a scansionarlo? Inserisci a mano questa chiave:', tenue: true),
        Row(children: [
          Flexible(child: SelectableText(setup['secret']!, style: stileTesto(context, TipoTesto.bodyMedium, fontWeight: FontWeight.w500, letterSpacing: 1.5))),
          IconButton(
            tooltip: 'Copia',
            onPressed: () => Clipboard.setData(ClipboardData(text: setup['secret']!)),
            icon: Icon(LucideIcons.copy, size: 16),
          ),
        ]),
        SizedBox(height: 16),
        Testo.bodyMedium('2. Inserisci il codice a 6 cifre mostrato dall\'app', fontWeight: FontWeight.w500),
        SizedBox(height: 8),
        codeField(),
        SizedBox(height: 12),
        Row(children: [
          FilledButton(onPressed: controller.busy ? null : controller.confirmSetup, child: Testo.bodyMedium('Conferma e attiva', color: Theme.of(context).colorScheme.onPrimary)),
          SizedBox(width: 12),
          TextButton(onPressed: controller.cancelSetup, child: Text('Annulla')),
        ]),
      ],
    );
  }

  Widget manageEnabled() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Testo.bodySmall('Per rigenerare i codici o disattivare la protezione inserisci un codice dell\'app (o di recupero).', tenue: true),
        SizedBox(height: 8),
        codeField(allowBackup: true),
        SizedBox(height: 12),
        Wrap(spacing: 12, runSpacing: 8, children: [
          OutlinedButton(onPressed: controller.busy ? null : controller.regenerateBackupCodes, child: Testo.bodySmall('Rigenera codici di recupero', color: Theme.of(context).colorScheme.primary)),
          OutlinedButton(style: OutlinedButton.styleFrom(side: BorderSide(color: context.colori.danger), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: controller.busy ? null : () => confirmDisable(), child: Testo.bodySmall('Disattiva', color: context.colori.danger)),
        ]),
      ],
    );
  }

  Widget backupCodesBox() {
    return Riquadro(color: context.colori.warning.withAlpha(30), paddingAll: 16, margin: EdgeInsets.only(bottom: 16), child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Testo.bodyMedium('Codici di recupero', fontWeight: FontWeight.w500),
          SizedBox(height: 4),
          Testo.bodySmall('Salvali adesso: non verranno più mostrati. Ognuno permette un accesso se perdi il telefono.', tenue: true),
          SizedBox(height: 12),
          Wrap(
            spacing: 16,
            runSpacing: 8,
            children: controller.freshBackupCodes!
                .map((c) => SelectableText(c, style: stileTesto(context, TipoTesto.bodyMedium, fontWeight: FontWeight.w500, letterSpacing: 1)))
                .toList(),
          ),
          SizedBox(height: 12),
          TextButton.icon(onPressed: controller.copyBackupCodes, icon: Icon(LucideIcons.copy, size: 16), label: Text('Copia tutti')),
        ],
      ));
  }

  Widget codeField({bool allowBackup = false}) {
    return SizedBox(
      width: 260,
      child: TextField(
        controller: controller.code,
        keyboardType: allowBackup ? TextInputType.text : TextInputType.number,
        inputFormatters: allowBackup ? [LengthLimitingTextInputFormatter(11)] : [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
        style: stileTesto(context, TipoTesto.bodyLarge, fontWeight: FontWeight.w500, letterSpacing: 3),
        decoration: InputDecoration(
          hintText: allowBackup ? '123456 o xxxxx-xxxxx' : '123456',
          errorText: controller.codeError,
          errorMaxLines: 3,
          isDense: true,
          contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    );
  }

  Future<void> confirmDisable() async {
    final ok = await Get.dialog<bool>(AlertDialog(
      title: Testo.titleMedium('Disattivare la verifica in due passaggi?', fontWeight: FontWeight.w500),
      content: Testo.bodySmall('Il tuo account sarà protetto solo dalla password (o da Google).'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: Text('Annulla')),
        TextButton(onPressed: () => Get.back(result: true), child: Text('Disattiva', style: TextStyle(color: context.colori.danger))),
      ],
    ));
    if (ok == true) {
      await controller.disable();
    }
  }

  // ---- Google -------------------------------------------------------------------

  Widget googleCard() {
    final linked = controller.status?.googleLinked ?? false;
    final hasPassword = controller.status?.hasPassword ?? true;
    return card(
      icon: LucideIcons.circle_user,
      title: 'Accesso con Google',
      subtitle: 'Accedi con il tuo account Google invece che con la password.',
      children: [
        badge(linked ? 'Collegato' : 'Non collegato', linked ? context.colori.success : context.colori.secondary),
        SizedBox(height: 16),
        if (!GoogleAuthService.isAvailable)
          Testo.bodySmall('L\'accesso con Google non è configurato su questa installazione.', tenue: true)
        else if (!linked)
          GoogleSignInButton(onPressed: controller.busy ? null : controller.linkGoogleNative, label: 'Collega account Google')
        else
          Tooltip(
            message: hasPassword ? '' : 'Imposta prima una password, altrimenti non potresti più accedere',
            child: OutlinedButton(style: OutlinedButton.styleFrom(side: BorderSide(color: context.colori.danger), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: controller.busy || !hasPassword ? null : controller.unlinkGoogle, child: Testo.bodySmall('Scollega', color: context.colori.danger)),
          ),
      ],
    );
  }

  // ---- Password ed email -----------------------------------------------------------

  Widget credenzialiCard() {
    final me = SessionService.to.me;
    final hasPassword = controller.status?.hasPassword ?? true;
    return card(
      icon: LucideIcons.key_round,
      title: 'Password ed email',
      subtitle: 'Cambiando la password o l\'email, le sessioni aperte sugli altri dispositivi vengono chiuse.',
      children: [
        Row(children: [
          Expanded(child: Testo.bodyMedium(me?.email ?? '', fontWeight: FontWeight.w500)),
          badge(me?.emailVerificata == false ? 'Da verificare' : 'Verificata', me?.emailVerificata == false ? context.colori.warning : context.colori.success),
        ]),
        SizedBox(height: 16),
        Wrap(spacing: 12, runSpacing: 8, children: [
          OutlinedButton(onPressed: controller.busy
                ? null
                : () async {
                    if (await showCambiaPasswordDialog(context, haPassword: hasPassword)) {
                      await controller.load();
                      Get.snackbar('Password', hasPassword ? 'Password cambiata.' : 'Password impostata.', snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16), maxWidth: 480);
                    }
                  }, child: Testo.bodySmall(hasPassword ? 'Cambia password' : 'Imposta una password', color: context.colori.primary)),
          if (hasPassword)
            OutlinedButton(onPressed: controller.busy ? null : () => showCambiaEmailDialog(context), child: Testo.bodySmall('Cambia email', color: Theme.of(context).colorScheme.primary)),
        ]),
      ],
    );
  }

  // ---- Analisi sul telefono (modello Apple) ----------------------------------------

  late final Future<StatoAiLocale> _statoAi = AiLocale.carica().then((_) => AiLocale.stato());

  /// Spunta per dispositivo: le pratiche avviate da questo iPhone si analizzano qui.
  Widget aiLocaleCard() {
    return card(
      icon: LucideIcons.smartphone,
      title: 'Analisi sul telefono',
      subtitle: 'Le pratiche (730, ISEE) avviate da questo iPhone leggono i documenti con il modello di Apple, sul telefono: l\'AI del server non li vede. '
          'Il testo resta in memoria; al server tornano solo i valori, che ricontrolla. Funziona con l\'app aperta.',
      children: [
        FutureBuilder<StatoAiLocale>(
          future: _statoAi,
          builder: (context, snap) {
            if (!snap.hasData) return const LinearProgressIndicator();
            final stato = snap.data!;
            return ValueListenableBuilder<bool>(
              valueListenable: AiLocale.attiva,
              builder: (context, attiva, _) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: attiva && stato.disponibile,
                  onChanged: stato.disponibile ? (v) => AiLocale.imposta(v ?? false) : null,
                  title: Testo.bodyMedium('Analizza i documenti su questo iPhone quando possibile'),
                ),
                Row(children: [
                  badge(stato.disponibile ? 'Disponibile' : 'Non disponibile', stato.disponibile ? context.colori.success : context.colori.warning),
                  SizedBox(width: 10),
                  Expanded(child: Testo.bodySmall(stato.spiegazione, tenue: true)),
                ]),
              ]),
            );
          },
        ),
      ],
    );
  }

  // ---- Helper ---------------------------------------------------------------------

  Widget card({required IconData icon, required String title, required String subtitle, required List<Widget> children}) {
    return Scheda(elevazione: .7, paddingAll: 20, child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(icon, size: 20, color: context.colori.primary),
            SizedBox(width: 10),
            Testo.bodyMedium(title, fontWeight: FontWeight.w500),
          ]),
          SizedBox(height: 6),
          Testo.bodySmall(subtitle, tenue: true),
          SizedBox(height: 16),
          ...children,
        ],
      ));
  }

  Widget badge(String label, Color color) {
    return Riquadro(padding: EdgeInsets.symmetric(horizontal: 8, vertical: 4), borderRadiusAll: 4, color: color.withAlpha(36), child: Testo.labelSmall(label, color: color, fontWeight: FontWeight.w500));
  }
}
