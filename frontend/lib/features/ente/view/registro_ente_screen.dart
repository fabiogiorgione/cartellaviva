import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/features/ente/view/ente_page.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Voce del registro (GET /api/tenants/{t}/audit_logs?attivita=1).
class VoceRegistro {
  final String id;
  final DateTime quando;
  final String? chi;
  final String azione;
  final String esito;
  final Map<String, dynamic> contesto;

  VoceRegistro({required this.id, required this.quando, this.chi, required this.azione, required this.esito, this.contesto = const {}});

  factory VoceRegistro.fromJson(Map<String, dynamic> j) => VoceRegistro(
        id: '${j['id']}',
        quando: DateTime.parse(j['occurredAt'] as String).toLocal(),
        chi: j['actorEmail'] as String?,
        azione: j['action'] as String,
        esito: (j['outcome'] as String?) ?? 'success',
        contesto: Map<String, dynamic>.from((j['context'] as Map?) ?? const {}),
      );

  String get descrizione => descrizioneAzione(azione);

  bool get negata => esito == 'denied' || esito == 'failure';
}

/// Azioni registrate, in italiano. Le altre si mostrano con il codice.
const Map<String, String> azioniRegistro = {
  'richiesta.inviata': 'Ha inviato una richiesta',
  'richiesta.ricorrente': 'Richiesta ricorrente inviata in automatico',
  'richiesta.rinnovo_automatico': 'Rinnovo alla scadenza inviato in automatico',
  'richiesta.vista': 'Una famiglia ha aperto una richiesta',
  'richiesta.link_aperto': 'Un referente ha aperto il link della richiesta',
  'richiesta.risposta': 'Una famiglia ha risposto a una richiesta',
  'richiesta.risposta_link': 'Un referente ha risposto dal link',
  'richiesta.rifiutata': 'Una famiglia ha rifiutato una richiesta',
  'richiesta.rifiutata_link': 'Un referente ha rifiutato dal link',
  'richiesta.firma_codice': 'Un referente ha chiesto il codice per firmare',
  'richiesta.firmata_con_codice': 'Un referente ha firmato con codice',
  'richiesta.accettata': 'Ha accettato una risposta',
  'richiesta.da_rifirmare': 'Ha chiesto a una famiglia di correggere una risposta',
  'richiesta.sollecitata': 'Ha sollecitato le famiglie',
  'richiesta.annullata': 'Ha annullato una richiesta',
  'richiesta.allegato_scaricato': 'Ha scaricato il PDF della richiesta',
  'richiesta.risposta_scaricata': 'Ha aperto una risposta consegnata',
  'richiesta.zip_scaricato': 'Ha scaricato lo ZIP delle risposte',
  'richiesta.copia_eliminata': 'Copia eliminata a fine conservazione',
  'richiesta.link_collegato': 'Un referente dal link ha collegato la sua famiglia',
  'collegamento.invitato': 'Ha invitato dei referenti a collegarsi',
  'collegamento.accettato': 'Una famiglia si è collegata',
  'collegamento.rifiutato': 'Un referente ha rifiutato il collegamento',
  'collegamento.non_sono_io': 'Un destinatario ha risposto "non sono io"',
  'collegamento.revocato': 'Una famiglia ha interrotto il collegamento',
  'iscritto.creato': 'Ha aggiunto un iscritto',
  'iscritto.eliminato': 'Ha eliminato un iscritto',
  'iscritti.importati': 'Ha importato iscritti da file',
  'ente.registrato': 'Ha registrato l\'ente',
  'ente.email_confermata': 'Ha confermato l\'email istituzionale',
  'ente.aggiornato': 'Ha aggiornato i dati dell\'ente',
  'ente.gruppo_operatori': 'Ha cambiato gli operatori di un gruppo',
  'ente.passaggio_anno': 'Ha fatto il passaggio all\'anno successivo',
  'invitation.created': 'Ha invitato una persona nell\'ente',
  'invitation.accepted': 'Una persona ha accettato l\'invito',
  'invitation.revoked': 'Ha revocato un invito',
  'membership.removed': 'Ha tolto una persona dall\'ente',
  'membership.left': 'Una persona ha lasciato l\'ente',
  'membership.role_changed': 'Ha cambiato il ruolo di una persona',
  'membership.status_changed': 'Ha sospeso o riattivato una persona',
  'membership.ownership_transferred': 'Proprietà passata a un\'altra persona',
};

String descrizioneAzione(String azione) => azioniRegistro[azione] ?? azione;

/// Filtri per argomento: prefisso dell'azione.
const Map<String, String> filtriRegistro = {
  'richiesta.': 'Richieste',
  'collegamento.': 'Collegamenti',
  'iscritt': 'Iscritti',
  'ente.': 'Ente e anni',
  'membership.': 'Persone dell\'ente',
  'invitation.': 'Inviti',
};

/// Registro attività dell'ente: chi ha fatto cosa e quando (segreteria e proprietari).
class RegistroEnteScreen extends StatefulWidget {
  const RegistroEnteScreen({super.key});

  @override
  State<RegistroEnteScreen> createState() => _RegistroEnteScreenState();
}

class _RegistroEnteScreenState extends State<RegistroEnteScreen> {
  String get t => SessionService.to.currentTenantId ?? '';
  final attore = TextEditingController();
  String? filtro;
  final List<VoceRegistro> voci = [];
  bool busy = false;
  bool altri = true;
  String? errore;

  @override
  void initState() {
    super.initState();
    _carica(reset: true);
  }

  @override
  void dispose() {
    attore.dispose();
    super.dispose();
  }

  Future<void> _carica({bool reset = false}) async {
    setState(() {
      busy = true;
      errore = null;
      if (reset) {
        voci.clear();
        altri = true;
      }
    });
    try {
      final r = await ApiClient.dio.get('/tenants/$t/audit_logs', queryParameters: {
        'attivita': 1,
        'limit': 50,
        if (filtro != null) 'action': filtro,
        if (attore.text.trim().isNotEmpty) 'attore': attore.text.trim(),
        if (!reset && voci.isNotEmpty) 'before': voci.last.id,
      });
      final nuove = (r.data as List).map((e) => VoceRegistro.fromJson(Map<String, dynamic>.from(e as Map))).toList();
      voci.addAll(nuove);
      altri = nuove.length == 50;
    } on DioException catch (e) {
      errore = ApiException.fromDio(e).message;
    }
    if (mounted) setState(() => busy = false);
  }

  String _ora(DateTime d) => '${formatDataBreve(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    return PaginaEnte(
      titolo: 'Registro attività',
      sezione: 'Registro',
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Testo.bodySmall('Chi ha fatto cosa e quando: richieste, risposte, verifiche, iscritti, collegamenti, persone dell\'ente. Il registro non si può modificare.', tenue: true),
        SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          ChoiceChip(label: const Text('Tutto'), selected: filtro == null, onSelected: (_) {
            filtro = null;
            _carica(reset: true);
          }),
          for (final e in filtriRegistro.entries)
            ChoiceChip(label: Text(e.value), selected: filtro == e.key, onSelected: (_) {
              filtro = e.key;
              _carica(reset: true);
            }),
          SizedBox(
            width: 240,
            child: TextField(
              controller: attore,
              onSubmitted: (_) => _carica(reset: true),
              decoration: const InputDecoration(hintText: 'Persona (email)', prefixIcon: Icon(LucideIcons.user_search, size: 16), isDense: true),
            ),
          ),
        ]),
        SizedBox(height: 12),
        if (errore != null) erroreCard(errore!, () => _carica(reset: true)),
        if (voci.isEmpty && !busy && errore == null) Scheda(paddingAll: 24, child: Testo.bodyMedium('Nessuna attività con questi filtri.')),
        if (voci.isNotEmpty)
          Scheda(paddingAll: 0, child: Column(children: [
              for (final v in voci)
                ListTile(
                  dense: true,
                  leading: Icon(v.negata ? LucideIcons.shield_x : LucideIcons.scroll_text, color: v.negata ? ColoreStato.errore : null, size: 20),
                  title: Text(v.descrizione + (v.negata ? ' (non consentito)' : '')),
                  subtitle: Text([_ora(v.quando), v.chi ?? 'sistema'].join(' · ')),
                ),
            ])),
        SizedBox(height: 12),
        if (busy) const Center(child: CircularProgressIndicator()) else if (altri && voci.isNotEmpty) Center(child: OutlinedButton(onPressed: _carica, child: const Text('Carica altre'))),
      ]),
    );
  }
}
