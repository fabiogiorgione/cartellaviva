import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/layout/auth_layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Chiamate pubbliche delle procedure d'accesso (nessun token).
class ProcedureApi {
  static Future<String> _post(String path, Map<String, dynamic> body) async {
    try {
      final response = await ApiClient.dio.post(path, data: body, options: ApiClient.anonymous);
      return (response.data as Map)['messaggio'] as String? ?? '';
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  static Future<String> richiedi(String email, String motivo) => _post('/auth/recupero', {'email': email.trim(), 'motivo': motivo.trim()});

  static Future<String> conferma(String token) => _post('/auth/recupero/conferma', {'token': token});

  static Future<String> annulla(String token) => _post('/procedure/annulla', {'token': token});
}

/// Recupero dell'accesso quando si perdono telefono e codici di recupero.
/// Con ?token= (link dell'email) conferma la richiesta.
class RecuperoAccessoScreen extends StatefulWidget {
  const RecuperoAccessoScreen({super.key});

  @override
  State<RecuperoAccessoScreen> createState() => _RecuperoAccessoScreenState();
}

class _RecuperoAccessoScreenState extends State<RecuperoAccessoScreen> {
  final String? token = Get.parameters['token'];
  final email = TextEditingController();
  final motivo = TextEditingController();
  bool busy = false;
  String? esito;
  String? errore;

  @override
  void initState() {
    super.initState();
    if (token != null) _run(() => ProcedureApi.conferma(token!));
  }

  @override
  void dispose() {
    email.dispose();
    motivo.dispose();
    super.dispose();
  }

  Future<void> _run(Future<String> Function() call) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      esito = await call();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return AuthLayout(
      child: Padding(
        padding: EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Testo.titleMedium('Recupero dell\'accesso', fontWeight: FontWeight.w600),
          SizedBox(height: 12),
          if (esito != null) ...[
            Testo.bodyMedium(esito!),
            SizedBox(height: 16),
            TextButton(onPressed: () => Get.offAllNamed('/auth/login'), child: const Text('Torna al login')),
          ] else if (token != null) ...[
            if (busy) const Center(child: CircularProgressIndicator()),
            if (errore != null) Testo.bodyMedium(errore!, color: ColoreStato.errore),
          ] else ...[
            Testo.bodyMedium('Non hai più il telefono né i codici di recupero? Per proteggere i documenti della tua famiglia lo sblocco non è immediato:'),
            SizedBox(height: 8),
            for (final passo in const [
              'confermi la richiesta dal link che ti mandiamo via email;',
              'il supporto ti contatta e verifica la tua identità con un documento;',
              'passano 72 ore, durante le quali tu e i proprietari della famiglia ricevete un avviso;',
              'al nuovo accesso imposti di nuovo la verifica in due passaggi.',
            ])
              Padding(padding: EdgeInsets.only(bottom: 4), child: Testo.bodySmall('• $passo')),
            SizedBox(height: 16),
            TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Email dell\'account')),
            SizedBox(height: 12),
            TextField(controller: motivo, maxLength: 500, decoration: const InputDecoration(labelText: 'Cosa è successo? (facoltativo)')),
            if (errore != null) Testo.bodySmall(errore!, color: ColoreStato.errore),
            SizedBox(height: 8),
            FilledButton(onPressed: busy ? null : () => _run(() => ProcedureApi.richiedi(email.text, motivo.text)), child: const Text('Invia la richiesta')),
            SizedBox(height: 12),
            Testo.bodySmall('Il supporto non ti chiederà mai la password o i codici. Se ricevi un avviso di sblocco che non hai chiesto, annullalo dal link nell\'email.', tenue: true),
          ],
        ]),
      ),
    );
  }
}

/// "Non sono d'accordo": annulla una procedura dal link dell'avviso (?token=).
class AnnullaProceduraScreen extends StatefulWidget {
  const AnnullaProceduraScreen({super.key});

  @override
  State<AnnullaProceduraScreen> createState() => _AnnullaProceduraScreenState();
}

class _AnnullaProceduraScreenState extends State<AnnullaProceduraScreen> {
  final String token = Get.parameters['token'] ?? '';
  bool busy = false;
  String? esito;
  String? errore;

  Future<void> _annulla() async {
    setState(() => busy = true);
    try {
      esito = await ProcedureApi.annulla(token);
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return AuthLayout(
      child: Padding(
        padding: EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Testo.titleMedium('Annulla la procedura', fontWeight: FontWeight.w600),
          SizedBox(height: 12),
          if (esito != null)
            Testo.bodyMedium(esito!)
          else ...[
            Testo.bodyMedium('Hai ricevuto un avviso di sblocco dell\'accesso o di nuovo proprietario che non ti aspettavi? Annullalo: il supporto verrà avvisato.'),
            if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
            SizedBox(height: 16),
            FilledButton(style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore), onPressed: busy ? null : _annulla, child: const Text('Annulla la procedura')),
          ],
          SizedBox(height: 16),
          TextButton(onPressed: () => Get.offAllNamed('/auth/login'), child: const Text('Vai al login')),
        ]),
      ),
    );
  }
}
