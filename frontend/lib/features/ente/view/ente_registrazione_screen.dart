import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/view/ente_dati_form.dart';
import 'package:cartellaviva/layout/layout.dart';

/// Registrazione di una scuola o di un'associazione: chi registra ne diventa
/// proprietario; poi conferma l'email istituzionale e attende la verifica.
class EnteRegistrazioneScreen extends StatefulWidget {
  const EnteRegistrazioneScreen({super.key});

  @override
  State<EnteRegistrazioneScreen> createState() => _EnteRegistrazioneScreenState();
}

class _EnteRegistrazioneScreenState extends State<EnteRegistrazioneScreen> {
  bool busy = false;
  String? errore;

  Future<void> _registra(Map<String, dynamic> dati) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final profilo = await EnteApi.registra(dati);
      final session = SessionService.to;
      await session.loadMe();
      await session.selectTenant(profilo.tenantId);
      Get.offAllNamed('/ente');
      return;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Testo.titleMedium('Registra una scuola o un\'associazione', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: spazioPagina),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Testo.bodySmall('Dopo la registrazione inserisci il codice che arriva all\'email istituzionale. '
                  'L\'ente resta "in verifica" finché non controlliamo i dati sul registro pubblico: '
                  'intanto puoi preparare gruppi, iscritti e operatori.', tenue: true),
                SizedBox(height: 16),
                EnteDatiForm(azione: 'Registra l\'ente', busy: busy, errore: errore, onSalva: _registra),
              ])),
          ),
        ]),
      ),
    );
  }
}
