import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/layout/layout.dart';

/// L'utente non fa parte di nessuna famiglia né ente (es. è stato rimosso
/// dall'unica famiglia, o è entrato solo come operatore e poi è uscito):
/// può creare la propria famiglia oppure attendere un invito.
class BenvenutoScreen extends StatefulWidget {
  const BenvenutoScreen({super.key});

  @override
  State<BenvenutoScreen> createState() => _BenvenutoScreenState();
}

class _BenvenutoScreenState extends State<BenvenutoScreen> {
  final TextEditingController nome = TextEditingController();
  bool saving = false;
  String? error;

  @override
  void initState() {
    super.initState();
    final cognome = SessionService.to.me?.lastName ?? '';
    nome.text = cognome.isEmpty ? '' : 'Famiglia $cognome';
  }

  @override
  void dispose() {
    nome.dispose();
    super.dispose();
  }

  Future<void> _crea() async {
    if (nome.text.trim().isEmpty || saving) {
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final id = await TenantApi.createFamily(nome.text);
      final session = SessionService.to;
      await session.loadMe();
      await session.selectTenant(id);
      Get.offAllNamed('/dashboard');
    } on ApiException catch (e) {
      setState(() {
        error = e.message;
        saving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Testo.titleMedium('Benvenuto in CartellaViva', fontSize: 18, fontWeight: FontWeight.w500),
            SizedBox(height: 6),
            Testo.bodyMedium('Non fai ancora parte di una famiglia.', tenue: true),
            SizedBox(height: spazioPagina),
            Wrap(
              spacing: 20,
              runSpacing: 20,
              children: [
                SizedBox(
                  width: 420,
                  child: Scheda(paddingAll: 20, child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(children: [Icon(LucideIcons.house, color: context.colori.primary), SizedBox(width: 10), Testo.bodyLarge('Crea la tua famiglia', fontWeight: FontWeight.w500)]),
                        SizedBox(height: 8),
                        Testo.bodySmall('Diventi proprietario: potrai invitare gli altri e caricare documenti.', tenue: true),
                        SizedBox(height: 16),
                        TextField(
                          controller: nome,
                          decoration: InputDecoration(labelText: 'Nome della famiglia'),
                          onSubmitted: (_) => _crea(),
                        ),
                        if (error != null) ...[SizedBox(height: 8), Testo.bodySmall(error!, color: context.colori.danger)],
                        SizedBox(height: 16),
                        FilledButton(onPressed: saving ? null : _crea, child: Text(saving ? 'Creazione…' : 'Crea la famiglia')),
                      ],
                    )),
                ),
                SizedBox(
                  width: 420,
                  child: Scheda(paddingAll: 20, child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [Icon(LucideIcons.mail, color: context.colori.primary), SizedBox(width: 10), Testo.bodyLarge('Aspetti un invito?', fontWeight: FontWeight.w500)]),
                        SizedBox(height: 8),
                        Testo.bodySmall('Se un familiare, una scuola o un\'associazione ti ha invitato, apri il link che hai ricevuto via email: entrerai direttamente.', tenue: true),
                      ],
                    )),
                ),
                SizedBox(
                  width: 420,
                  child: Scheda(paddingAll: 20, child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [Icon(LucideIcons.building, color: context.colori.primary), SizedBox(width: 10), Testo.bodyLarge('Lavori per una scuola o un\'associazione?', fontWeight: FontWeight.w500)]),
                        SizedBox(height: 8),
                        Testo.bodySmall('Registra l\'ente: confermi l\'email istituzionale e, dopo la verifica, potrai collegare le famiglie e chiedere firme e documenti.', tenue: true),
                        SizedBox(height: 16),
                        OutlinedButton(onPressed: () => Get.toNamed('/ente/registrazione'), child: const Text('Registra l\'ente')),
                      ],
                    )),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
