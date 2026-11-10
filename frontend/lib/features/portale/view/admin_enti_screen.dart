import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Portale (minimo): verifica degli enti registrati. Solo superadmin.
class AdminEntiScreen extends StatefulWidget {
  const AdminEntiScreen({super.key});

  @override
  State<AdminEntiScreen> createState() => _AdminEntiScreenState();
}

class _AdminEntiScreenState extends State<AdminEntiScreen> {
  String stato = 'in_verifica';
  late Future<List<EnteProfilo>> _future = EnteApi.portaleEnti(stato: stato);

  void _reload() => setState(() => _future = EnteApi.portaleEnti(stato: stato.isEmpty ? null : stato));

  Future<void> _decidi(EnteProfilo p) async {
    var registro = p.controlli['registro'] ?? false;
    var telefonata = p.controlli['telefonata'] ?? false;
    final nota = TextEditingController(text: p.notaPortale ?? '');
    String? errore;
    final fatto = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        Future<void> invia(String azione) async {
          try {
            await EnteApi.decidi(p.tenantId, azione: azione, nota: nota.text, registro: registro, telefonata: telefonata);
            if (ctx.mounted) Navigator.pop(ctx, true);
          } on ApiException catch (e) {
            setLocal(() => errore = e.message);
          }
        }

        return AlertDialog(
          title: Text(p.denominazione),
          content: SizedBox(
            width: 520,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.bodyMedium('${p.tipoLabel} · referente ${p.referenteNome ?? '-'} (${p.referenteEmail ?? '-'})'),
              SizedBox(height: 8),
              Testo.bodySmall('Codice fiscale ${p.codiceFiscale}'),
              if (p.codiceMeccanografico != null) Testo.bodySmall('Codice meccanografico ${p.codiceMeccanografico}'),
              if (p.numeroRunts != null) Testo.bodySmall('RUNTS ${p.numeroRunts}'),
              Testo.bodySmall('Email istituzionale ${p.emailIstituzionale} · ${p.stato == 'email_da_confermare' ? 'NON confermata' : 'confermata'}'),
              if (p.sedeLegale != null) Testo.bodySmall('Sede ${p.sedeLegale}'),
              const Divider(height: 24),
              Testo.labelMedium('Controlli'),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: registro,
                onChanged: (v) => setLocal(() => registro = v == true),
                title: Text(p.tipo == 'scuola' ? 'Codice presente nell\'anagrafe delle scuole' : 'Iscrizione al RUNTS verificata'),
              ),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: telefonata,
                onChanged: (v) => setLocal(() => telefonata = v == true),
                title: const Text('Telefonata al numero pubblico (facoltativa)'),
              ),
              TextField(controller: nota, maxLines: 3, decoration: const InputDecoration(labelText: 'Nota per l\'ente (integrazioni o rifiuto)')),
              if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
            ]),
          ),
          actions: [
            if (SessionService.to.me?.platformRole == 'superadmin')
              TextButton(onPressed: () => invia('respingi'), child: Text('Respingi', style: TextStyle(color: ColoreStato.errore))),
            OutlinedButton(onPressed: () => invia('integrazioni'), child: const Text('Chiedi integrazioni')),
            if (SessionService.to.me?.platformRole == 'superadmin')
              FilledButton(onPressed: registro ? () => invia('approva') : null, child: const Text('Approva')),
          ],
        );
      }),
    );
    nota.dispose();
    if (fatto == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    if (SessionService.to.me?.isSupporto != true) {
      return Layout(child: Padding(padding: EdgeInsets.symmetric(horizontal: spazioPagina), child: Scheda(paddingAll: 24, child: Testo.bodyMedium('Riservato all\'amministrazione della piattaforma.'))));
    }
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Portale · Enti', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: spazioPagina),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'in_verifica', label: Text('Da verificare')),
                ButtonSegment(value: 'email_da_confermare', label: Text('Email da confermare')),
                ButtonSegment(value: 'integrazioni', label: Text('Integrazioni')),
                ButtonSegment(value: '', label: Text('Tutti')),
              ],
              selected: {stato},
              onSelectionChanged: (s) {
                stato = s.first;
                _reload();
              },
            ),
          ),
          SizedBox(height: 16),
          FutureBuilder<List<EnteProfilo>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
              if (snap.hasError) return Scheda(paddingAll: 24, child: Testo.bodyMedium(snap.error.toString()));
              final enti = snap.data!;
              if (enti.isEmpty) return Scheda(paddingAll: 24, child: Testo.bodyMedium('Nessun ente in questo stato.'));
              return Scheda(paddingAll: 0, child: Column(children: [
                  for (final p in enti)
                    ListTile(
                      onTap: () => _decidi(p),
                      title: Text(p.denominazione),
                      subtitle: Text([p.tipoLabel, p.statoLabel, if (p.createdAt != null) 'registrato il ${formatDataBreve(p.createdAt!)}'].join(' · ')),
                      trailing: p.verificato ? const Chip(label: Text('verificato')) : const Icon(Icons.chevron_right),
                    ),
                ]));
            },
          ),
          SizedBox(height: 8),
          Testo.bodySmall('Approva si abilita dopo il controllo sul registro pubblico; la telefonata non blocca.', tenue: true),
        ]),
      ),
    );
  }
}
