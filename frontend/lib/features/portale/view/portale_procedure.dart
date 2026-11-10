part of 'portale_screens.dart';

// ---------------------------------------------------------------------------
// Recuperi d'accesso e nuovi proprietari
// ---------------------------------------------------------------------------

class PortaleProcedureScreen extends StatefulWidget {
  const PortaleProcedureScreen({super.key});

  @override
  State<PortaleProcedureScreen> createState() => _PortaleProcedureScreenState();
}

class _PortaleProcedureScreenState extends State<PortaleProcedureScreen> {
  String? stato;
  late Future<List<Map<String, dynamic>>> _future = PortaleApi.procedure(stato: stato);

  void _reload() => setState(() => _future = PortaleApi.procedure(stato: stato));

  static String _statoLabel(String s) => switch (s) {
        'email_da_confermare' => 'email da confermare',
        'identita_da_verificare' => 'identità da verificare',
        'in_attesa' => 'in attesa (72 ore)',
        _ => s,
      };

  Future<void> _verifica(Map<String, dynamic> p) async {
    final documento = TextEditingController();
    final come = TextEditingController(text: 'videochiamata');
    final nota = TextEditingController();
    String? errore;
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        Future<void> invia(bool verificata) async {
          try {
            await PortaleApi.verificaIdentita(p['id'] as String, verificata: verificata, documento: documento.text, come: come.text, nota: nota.text);
            if (ctx.mounted) Navigator.pop(ctx);
          } on ApiException catch (e) {
            setLocal(() => errore = e.message);
          }
        }

        final utente = p['utente'] as Map;
        return AlertDialog(
          title: Text('Recupero d\'accesso · ${utente['nome']}'),
          content: SizedBox(
            width: 520,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodyMedium('✓ Richiesta il ${_dataOra(p['createdAt'])}${p['motivo'] != null ? ' · motivo: "${p['motivo']}"' : ''}'),
              Testo.bodyMedium('✓ Email confermata dal link (stessa casella dell\'account)'),
              SizedBox(height: 12),
              Testo.bodySmall('Verifica l\'identità: videochiamata con documento e confronto con l\'anagrafica (nome, codice fiscale). Non chiedere mai password o codici. '
                'Se la confermi partono 72 ore con avviso a lei/lui e ai proprietari delle sue famiglie; poi la 2FA viene disattivata e le sessioni chiuse.', tenue: true),
              SizedBox(height: 12),
              TextField(controller: documento, decoration: const InputDecoration(labelText: 'Documento verificato (tipo e ultime cifre)', isDense: true)),
              SizedBox(height: 8),
              TextField(controller: come, decoration: const InputDecoration(labelText: 'Come', isDense: true)),
              SizedBox(height: 8),
              TextField(controller: nota, decoration: const InputDecoration(labelText: 'Nota (obbligatoria se respingi)', isDense: true)),
              if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Chiudi')),
            OutlinedButton(onPressed: () => invia(false), child: const Text('Respingi')),
            FilledButton(onPressed: () => invia(true), child: const Text('Identità verificata: avvia le 72 ore')),
          ],
        );
      }),
    );
    for (final c in [documento, come, nota]) {
      c.dispose();
    }
    _reload();
  }

  Future<void> _annulla(Map<String, dynamic> p) async {
    try {
      await PortaleApi.annullaProcedura(p['id'] as String, nota: 'Annullata dal supporto.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) _snack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _PaginaPortale(
      titolo: 'Recuperi d\'accesso',
      soloSupporto: true,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Align(
          alignment: Alignment.centerLeft,
          child: DropdownButton<String?>(
            value: stato,
            items: const [
              DropdownMenuItem(value: null, child: Text('Aperte')),
              DropdownMenuItem(value: 'completata', child: Text('Completate')),
              DropdownMenuItem(value: 'annullata', child: Text('Annullate')),
              DropdownMenuItem(value: 'respinta', child: Text('Respinte')),
            ],
            onChanged: (v) {
              stato = v;
              _reload();
            },
          ),
        ),
        SizedBox(height: 12),
        FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
            if (snap.hasError) return erroreCard(snap.error.toString(), _reload);
            final righe = snap.data!;
            if (righe.isEmpty) return Scheda(paddingAll: 24, child: Testo.bodyMedium('Nessuna procedura.'));
            return Scheda(paddingAll: 0, child: Column(children: [
                for (final p in righe)
                  ListTile(
                    leading: Icon(p['tipo'] == 'recupero_2fa' ? LucideIcons.key_round : LucideIcons.crown),
                    title: Text('${p['tipo'] == 'recupero_2fa' ? 'Recupero 2FA' : 'Nuovo proprietario'} · ${(p['utente'] as Map)['nome']} (${(p['utente'] as Map)['email']})'),
                    subtitle: Text([
                      _statoLabel(p['stato'] as String),
                      if (p['famiglia'] != null) (p['famiglia'] as Map)['nome'],
                      'richiesta il ${_dataOra(p['createdAt'])}',
                      if (p['eseguibileIl'] != null && p['stato'] == 'in_attesa') 'si esegue il ${_dataOra(p['eseguibileIl'])}',
                      if (p['verificataDa'] != null) 'verificata da ${p['verificataDa']} (${p['verificaDocumento'] ?? '-'}, ${p['verificaCome'] ?? '-'})',
                    ].join(' · ')),
                    trailing: Wrap(spacing: 8, children: [
                      if (p['stato'] == 'identita_da_verificare') FilledButton(onPressed: () => _verifica(p), child: const Text('Verifica')),
                      if (const ['email_da_confermare', 'identita_da_verificare', 'in_attesa'].contains(p['stato'])) TextButton(onPressed: () => _annulla(p), child: const Text('Annulla')),
                    ]),
                  ),
              ]));
          },
        ),
        SizedBox(height: 8),
        Testo.bodySmall('Ogni passo va nel registro globale e in quello delle famiglie. Il documento d\'identità non viene caricato né conservato.', tenue: true),
      ]),
    );
  }
}
