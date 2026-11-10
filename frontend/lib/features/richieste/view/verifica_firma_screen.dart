import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/richieste/api/link_api.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Verifica pubblica di un'attestazione di firma con codice (/verifica-firma?codice=).
class VerificaFirmaScreen extends StatefulWidget {
  const VerificaFirmaScreen({super.key});

  @override
  State<VerificaFirmaScreen> createState() => _VerificaFirmaScreenState();
}

class _VerificaFirmaScreenState extends State<VerificaFirmaScreen> {
  late final codice = TextEditingController(text: Get.parameters['codice'] ?? '');
  Map<String, dynamic>? esito;
  String? errore;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    if (codice.text.isNotEmpty) _verifica();
  }

  @override
  void dispose() {
    codice.dispose();
    super.dispose();
  }

  Future<void> _verifica() async {
    setState(() {
      busy = true;
      errore = null;
      esito = null;
    });
    try {
      esito = await LinkApi.verificaFirma(codice.text);
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final e = esito;
    final data = e?['firmataIl'] is String ? DateTime.tryParse(e!['firmataIl'] as String)?.toLocal() : null;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                  Testo.titleMedium('Verifica una firma CartellaViva', fontWeight: FontWeight.w600),
                  SizedBox(height: 8),
                  Testo.bodySmall('Inserisci il codice di verifica stampato nella pagina di attestazione del documento firmato.', tenue: true),
                  SizedBox(height: 12),
                  Row(children: [
                    Expanded(
                      child: TextField(
                        controller: codice,
                        textCapitalization: TextCapitalization.characters,
                        decoration: const InputDecoration(labelText: 'Codice di verifica', isDense: true),
                        onSubmitted: (_) => _verifica(),
                      ),
                    ),
                    SizedBox(width: 8),
                    FilledButton(onPressed: busy ? null : _verifica, child: const Text('Verifica')),
                  ]),
                  if (errore != null) ...[SizedBox(height: 12), Testo.bodyMedium(errore!, color: ColoreStato.errore)],
                  if (e != null) ...[
                    SizedBox(height: 16),
                    Row(children: [Icon(LucideIcons.badge_check, color: ColoreStato.successo), SizedBox(width: 8), Testo.bodyMedium('Firma registrata', fontWeight: FontWeight.w600)]),
                    SizedBox(height: 8),
                    for (final riga in [
                      ('Ente', e['ente']),
                      ('Documento', e['documento']),
                      ('Firmatario', '${e['firmatario']} (${e['email']})'),
                      ('Firmato il', data == null ? '-' : '${formatDataBreve(data)} ${data.hour.toString().padLeft(2, '0')}:${data.minute.toString().padLeft(2, '0')}'),
                      ('Metodo', e['metodo']),
                      ('SHA-256 documento', e['documentoSha256']),
                      ('SHA-256 PDF firmato', e['documentoFirmatoSha256'] ?? '-'),
                    ])
                      Padding(
                        padding: EdgeInsets.only(bottom: 6),
                        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          SizedBox(width: 150, child: Testo.bodySmall(riga.$1, tenue: true)),
                          Expanded(child: SelectableText('${riga.$2}', style: const TextStyle(fontSize: 13))),
                        ]),
                      ),
                    Testo.bodySmall('Il PDF che hai è quello firmato se la sua impronta SHA-256 coincide con "SHA-256 PDF firmato".', tenue: true),
                  ],
                ])),
            ),
          ),
        ),
      ),
    );
  }
}
