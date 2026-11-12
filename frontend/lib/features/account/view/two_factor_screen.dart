import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/controller/two_factor_controller.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/widgets/logo_cartella_viva.dart';

class TwoFactorScreen extends StatefulWidget {
  const TwoFactorScreen({super.key});

  @override
  State<TwoFactorScreen> createState() => _TwoFactorScreenState();
}

class _TwoFactorScreenState extends State<TwoFactorScreen> {
  final TwoFactorController controller = Get.put(TwoFactorController());
  final OutlineInputBorder border = OutlineInputBorder(borderSide: BorderSide(color: Color(0x3d6c757d)));

  @override
  Widget build(BuildContext context) {
    return AuthLayout(
      child: GetBuilder(
        init: controller,
        global: false,
        // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
        dispose: (_) => controller.onDelete(),
        builder: (controller) {
          return Padding(
            padding: EdgeInsets.all(20),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Riquadro(paddingAll: 0, height: 28, child: const LogoCartellaViva(altezza: 28)),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Testo.titleMedium("Verifica in due passaggi", fontWeight: FontWeight.w500),
                    SizedBox(height: 8),
                    Testo.bodySmall(controller.useBackupCode
                          ? 'Inserisci uno dei codici di recupero che hai salvato. Ogni codice vale una sola volta.'
                          : 'Apri Google Authenticator (o un\'app compatibile) e inserisci il codice a 6 cifre di CartellaViva.', tenue: true),
                    SizedBox(height: 20),
                    TextField(
                      controller: controller.code,
                      autofocus: true,
                      keyboardType: controller.useBackupCode ? TextInputType.text : TextInputType.number,
                      inputFormatters: controller.useBackupCode
                          ? [LengthLimitingTextInputFormatter(11)]
                          : [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
                      autofillHints: const [AutofillHints.oneTimeCode],
                      textAlign: TextAlign.center,
                      style: stileTesto(context, TipoTesto.titleLarge, fontWeight: FontWeight.w500, letterSpacing: controller.useBackupCode ? 2 : 8),
                      onSubmitted: (_) => controller.submit(),
                      decoration: InputDecoration(
                        hintText: controller.useBackupCode ? 'xxxxx-xxxxx' : '000000',
                        errorText: controller.error,
                        errorMaxLines: 3,
                        border: border,
                        enabledBorder: border,
                      ),
                    ),
                    SizedBox(height: 20),
                    SizedBox(width: double.infinity, child: FilledButton(onPressed: controller.loading ? null : controller.submit, child: Testo.bodyMedium(controller.loading ? 'Verifica...' : 'Verifica', color: Theme.of(context).colorScheme.onPrimary))),
                    SizedBox(height: 12),
                    Center(
                      child: TextButton(
                        onPressed: controller.toggleBackupCode,
                        child: Text(controller.useBackupCode ? 'Usa il codice dell\'app' : 'Non hai il telefono? Usa un codice di recupero'),
                      ),
                    ),
                    if (controller.useBackupCode)
                      Center(
                        child: TextButton(
                          onPressed: () => Get.toNamed('/auth/recupero'),
                          child: const Text('Non hai più nemmeno i codici? Recupera l\'accesso'),
                        ),
                      ),
                  ],
                ),
                Center(
                  child: InkWell(
                    onTap: controller.backToLogin,
                    child: Testo.bodyMedium('Torna al login', fontWeight: FontWeight.w500, decoration: TextDecoration.underline),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
