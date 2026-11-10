import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/operazione_con_errore.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Firma con codice (FES), uguale nell'app e dal link senza account:
/// 1. si apre il documento; 2. si chiede il codice (va alla propria email);
/// 3. si dichiara di averlo letto e si conferma con il codice.
/// Restituisce true se la firma è stata registrata.
Future<bool> showFirmaCodiceDialog(
  BuildContext context, {
  required String titolo,
  required String firmatario,
  required Future<void> Function() apriDocumento,
  required Future<({String sha256, String dichiarazione})> Function() chiediCodice,
  required Future<void> Function(String codice) firma,
}) async {
  final ok = await showDialog<bool>(
    context: context,
    barrierDismissible: false,
    builder: (_) => _FirmaCodiceDialog(titolo: titolo, firmatario: firmatario, apriDocumento: apriDocumento, chiediCodice: chiediCodice, firma: firma),
  );
  return ok ?? false;
}

class _FirmaCodiceDialog extends StatefulWidget {
  final String titolo;
  final String firmatario;
  final Future<void> Function() apriDocumento;
  final Future<({String sha256, String dichiarazione})> Function() chiediCodice;
  final Future<void> Function(String codice) firma;

  const _FirmaCodiceDialog({required this.titolo, required this.firmatario, required this.apriDocumento, required this.chiediCodice, required this.firma});

  @override
  State<_FirmaCodiceDialog> createState() => _FirmaCodiceDialogState();
}

class _FirmaCodiceDialogState extends State<_FirmaCodiceDialog> with OperazioneConErrore<_FirmaCodiceDialog> {
  final codice = TextEditingController();
  bool letto = false;
  bool aperto = false;
  ({String sha256, String dichiarazione})? avvio;

  @override
  void dispose() {
    codice.dispose();
    super.dispose();
  }


  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Firma con codice'),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.bodyMedium(widget.titolo, fontWeight: FontWeight.w500),
            Testo.bodySmall('Firmi come: ${widget.firmatario}', tenue: true),
            SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: busy
                  ? null
                  : () => esegui(() async {
                        await widget.apriDocumento();
                        aperto = true;
                      }),
              icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
              label: Text(aperto ? 'Riapri il documento' : '1. Apri e leggi il documento'),
            ),
            SizedBox(height: 8),
            if (avvio == null)
              FilledButton(
                onPressed: busy || !aperto
                    ? null
                    : () => esegui(() async {
                          avvio = await widget.chiediCodice();
                        }),
                child: const Text('2. Mandami il codice via email'),
              )
            else ...[
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: letto,
                onChanged: busy ? null : (v) => setState(() => letto = v == true),
                title: Text(avvio!.dichiarazione),
              ),
              TextField(
                controller: codice,
                keyboardType: TextInputType.number,
                maxLength: 6,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(labelText: 'Codice ricevuto via email', isDense: true),
              ),
              Testo.bodySmall('Impronta del documento (SHA-256): ${avvio!.sha256.substring(0, 16)}…', tenue: true),
            ],
            if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
            SizedBox(height: 8),
            Testo.bodySmall('È una firma elettronica: il documento firmato avrà una pagina di attestazione con data, il tuo nome e un codice di verifica. '
              'Nessuno ti chiederà mai il codice: non comunicarlo.', tenue: true),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        if (avvio != null)
          TextButton(onPressed: busy ? null : () => esegui(() async => avvio = await widget.chiediCodice()), child: const Text('Nuovo codice')),
        if (avvio != null)
          FilledButton(
            onPressed: busy || !letto || codice.text.trim().length != 6
                ? null
                : () => esegui(() async {
                      await widget.firma(codice.text);
                      if (mounted) Navigator.pop(context, true);
                    }),
            child: const Text('3. Firma'),
          ),
      ],
    );
  }
}
