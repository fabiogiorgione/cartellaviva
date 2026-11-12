import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/emergenza/api/emergenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

String _quando(DateTime d) => '${formatDataBreve(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

Widget _paginaSemplice(String titolo, List<Widget> children) => Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.all(16),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: Scheda(paddingAll: 24, child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [const Icon(LucideIcons.life_buoy), SizedBox(width: 8), Expanded(child: Testo.titleMedium(titolo, fontWeight: FontWeight.w600))]),
                  SizedBox(height: 12),
                  ...children,
                ])),
            ),
          ),
        ),
      ),
    );

// ---------------------------------------------------------------------------
// Invito: /emergenza/accetta?token=
// ---------------------------------------------------------------------------

class EmergenzaInvitoScreen extends StatefulWidget {
  const EmergenzaInvitoScreen({super.key});

  @override
  State<EmergenzaInvitoScreen> createState() => _EmergenzaInvitoScreenState();
}

class _EmergenzaInvitoScreenState extends State<EmergenzaInvitoScreen> {
  final String token = Get.parameters['token'] ?? '';
  ({String famiglia, String chi, String email})? invito;
  String? errore;
  String? esito;
  bool busy = true;

  @override
  void initState() {
    super.initState();
    EmergenzaApi.invito(token).then((i) => invito = i, onError: (e) => errore = e is ApiException ? e.message : '$e').whenComplete(() {
      if (mounted) setState(() => busy = false);
    });
  }

  Future<void> _run(Future<void> Function() f, String ok) async {
    setState(() => busy = true);
    try {
      await f();
      esito = ok;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final i = invito;
    final loggato = SessionService.to.isLoggedIn;
    final next = Uri.encodeComponent('/emergenza/accetta?token=$token');
    return _paginaSemplice('Contatto d\'emergenza', [
      if (busy) const Center(child: CircularProgressIndicator()),
      if (esito != null) ...[
        Testo.bodyMedium(esito!),
        SizedBox(height: 12),
        FilledButton(onPressed: () => Get.offAllNamed('/emergenza'), child: const Text('Vai ai tuoi contatti d\'emergenza')),
      ] else if (i != null) ...[
        Testo.bodyMedium('${i.chi} ti indica come contatto d\'emergenza della famiglia "${i.famiglia}".'),
        SizedBox(height: 8),
        Testo.bodySmall('Se un giorno servisse, potrai chiedere di consultare i loro documenti: la famiglia riceve un avviso e può opporsi; '
          'senza opposizione, dopo l\'attesa, li vedrai in sola lettura. Finché non lo chiedi non vedi nulla.', tenue: true),
        SizedBox(height: 16),
        if (!loggato) ...[
          Testo.bodySmall('Per accettare entra (o registrati) con l\'indirizzo ${i.email}.', fontWeight: FontWeight.w500),
          SizedBox(height: 8),
          FilledButton(onPressed: () => Get.toNamed('/auth/login?next=$next'), child: const Text('Accedi')),
          TextButton(onPressed: () => Get.toNamed('/auth/register_account?next=$next'), child: const Text('Crea l\'account')),
        ] else
          Wrap(spacing: 12, children: [
            FilledButton(onPressed: busy ? null : () => _run(() => EmergenzaApi.accetta(token), 'Fatto: ora sei contatto d\'emergenza della famiglia "${i.famiglia}".'), child: const Text('Accetto')),
            TextButton(onPressed: busy ? null : () => _run(() => EmergenzaApi.rifiuta(token), 'Hai rifiutato l\'invito.'), child: const Text('Rifiuto')),
          ]),
      ],
      if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
    ]);
  }
}

// ---------------------------------------------------------------------------
// Opposizione dal link dell'email: /emergenza/annulla?token=
// ---------------------------------------------------------------------------

class EmergenzaAnnullaScreen extends StatefulWidget {
  const EmergenzaAnnullaScreen({super.key});

  @override
  State<EmergenzaAnnullaScreen> createState() => _EmergenzaAnnullaScreenState();
}

class _EmergenzaAnnullaScreenState extends State<EmergenzaAnnullaScreen> {
  String? esito;
  String? errore;
  bool busy = false;

  Future<void> _oppongo() async {
    setState(() => busy = true);
    try {
      esito = await EmergenzaApi.annulla(Get.parameters['token'] ?? '');
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return _paginaSemplice('Accesso d\'emergenza', [
      if (esito != null)
        Testo.bodyMedium(esito!)
      else ...[
        Testo.bodyMedium('Confermi di opporti alla richiesta di accesso d\'emergenza ai documenti della tua famiglia?'),
        SizedBox(height: 12),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore),
          onPressed: busy ? null : _oppongo,
          child: const Text('Mi oppongo'),
        ),
      ],
      if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
    ]);
  }
}

// ---------------------------------------------------------------------------
// Le famiglie di cui sono contatto: /emergenza
// ---------------------------------------------------------------------------

class EmergenzaMieScreen extends StatefulWidget {
  const EmergenzaMieScreen({super.key});

  @override
  State<EmergenzaMieScreen> createState() => _EmergenzaMieScreenState();
}

class _EmergenzaMieScreenState extends State<EmergenzaMieScreen> {
  late Future<List<MioContattoEmergenza>> _future = EmergenzaApi.miei();

  void _reload() => setState(() => _future = EmergenzaApi.miei());

  Future<void> _richiedi(MioContattoEmergenza c) async {
    final motivo = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Chiedere l\'accesso a "${c.famiglia}"?'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('La famiglia riceve subito un avviso e può opporsi. Se nessuno si oppone, tra ${c.giorniAttesa} giorni potrai consultare i documenti in sola lettura.'),
          SizedBox(height: 12),
          TextField(controller: motivo, maxLength: 500, maxLines: 2, decoration: const InputDecoration(labelText: 'Motivo (facoltativo, lo leggono loro)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Chiedi l\'accesso')),
        ],
      ),
    );
    if (ok == true) {
      try {
        await EmergenzaApi.richiedi(c.id, motivo.text);
        _reload();
      } on ApiException catch (e) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
    motivo.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Contatto d\'emergenza', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: 8),
          Testo.bodySmall('Qui trovi le famiglie che ti hanno scelto come contatto d\'emergenza.', tenue: true),
          if (SessionService.to.can('membro:read') && SessionService.to.currentMembership?.roleSlug != 'emergenza') ...[
            SizedBox(height: 12),
            Scheda(paddingAll: 16, child: Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Testo.bodyMedium('Per scegliere chi è il contatto d\'emergenza della tua famiglia:'),
                OutlinedButton.icon(
                  onPressed: () => Get.toNamed('/famiglia/impostazioni?scheda=emergenza'),
                  icon: const Icon(LucideIcons.settings, size: 16),
                  label: const Text('Impostazioni famiglia › Emergenza'),
                ),
              ])),
          ],
          SizedBox(height: spazioPagina),
          FutureBuilder<List<MioContattoEmergenza>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snap.hasError) return Scheda(paddingAll: 20, child: Testo.bodyMedium(snap.error.toString()));
              final righe = snap.data!;
              if (righe.isEmpty) return Scheda(paddingAll: 24, child: Testo.bodyMedium('Non sei contatto d\'emergenza di nessuna famiglia.'));
              return Column(children: [
                for (final c in righe)
                  Scheda(paddingAll: 16, child: Row(children: [
                      const Icon(LucideIcons.life_buoy),
                      SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Testo.bodyMedium(c.famiglia, fontWeight: FontWeight.w500),
                          Testo.bodySmall(switch (c.richiestaStato) {
                            'in_attesa' => 'Richiesta inviata: accesso il ${c.eseguibileIl != null ? _quando(c.eseguibileIl!) : '-'} se nessuno si oppone.',
                            'concessa' => 'Accesso concesso in sola lettura: scegli la famiglia dal selettore in alto.',
                            'negata' => 'La famiglia si è opposta all\'ultima richiesta.',
                            _ => 'Nessuna richiesta. Attesa prevista: ${c.giorniAttesa} giorni.',
                          }, tenue: true),
                        ]),
                      ),
                      if (c.puoChiedere) FilledButton(onPressed: () => _richiedi(c), child: const Text('Chiedi l\'accesso')),
                    ])),
              ]);
            },
          ),
        ]),
      ),
    );
  }
}
