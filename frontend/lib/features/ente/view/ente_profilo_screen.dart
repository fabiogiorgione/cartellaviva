import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/ente/view/ente_dati_form.dart';
import 'package:cartellaviva/features/ente/view/ente_page.dart';

/// Profilo dell'ente. Cambiare codici o email istituzionale rimanda alla conferma
/// (e alla verifica): nel frattempo inviti e richieste si sospendono.
class EnteProfiloScreen extends StatefulWidget {
  const EnteProfiloScreen({super.key});

  @override
  State<EnteProfiloScreen> createState() => _EnteProfiloScreenState();
}

class _EnteProfiloScreenState extends State<EnteProfiloScreen> {
  String get t => SessionService.to.currentTenantId ?? '';
  late Future<EnteProfilo> _future = EnteApi.profilo(t);
  bool busy = false;
  String? errore;

  Future<void> _salva(Map<String, dynamic> dati) async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final p = await EnteApi.aggiornaProfilo(t, dati);
      await SessionService.to.loadMe();
      setState(() => _future = Future.value(p));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(p.stato == 'email_da_confermare' ? 'Salvato: conferma di nuovo l\'email dal cruscotto.' : 'Salvato.')));
      }
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final canManage = SessionService.to.can('ente:manage');
    return PaginaEnte(
      titolo: 'Profilo e verifica',
      sezione: 'Profilo',
      child: FutureBuilder<EnteProfilo>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) return erroreCard(snap.error.toString(), () => setState(() => _future = EnteApi.profilo(t)));
          final p = snap.data!;
          return ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 600),
            child: Scheda(paddingAll: 24, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Testo.bodyMedium('${p.tipoLabel} · ${p.statoLabel}', fontWeight: FontWeight.w500),
                Testo.bodySmall('Email istituzionale: ${p.emailIstituzionale}', tenue: true),
                if (p.notaPortale != null) ...[SizedBox(height: 8), Testo.bodyMedium('Nota della verifica: ${p.notaPortale}')],
                SizedBox(height: 16),
                if (canManage)
                  EnteDatiForm(key: ValueKey(p.codiceFiscale + p.stato), iniziale: p, bloccaTipo: true, azione: 'Salva', busy: busy, errore: errore, onSalva: _salva)
                else
                  Testo.bodyMedium('Codice fiscale ${p.codiceFiscale}${p.codiceMeccanografico != null ? ' · codice meccanografico ${p.codiceMeccanografico}' : ''}'),
              ])),
          );
        },
      ),
    );
  }
}
