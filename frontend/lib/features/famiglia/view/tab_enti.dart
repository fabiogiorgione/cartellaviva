import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/richieste/api/richieste_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/widgets/conferma.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Enti collegati alla famiglia; si può interrompere il collegamento.
class TabEnti extends StatefulWidget {
  const TabEnti({super.key});

  @override
  State<TabEnti> createState() => _TabEntiState();
}

class _TabEntiState extends State<TabEnti> {
  SessionService get session => SessionService.to;
  List<Collegamento> collegamenti = [];
  bool loading = true;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      collegamenti = await RichiesteApi.collegamenti(session.currentTenantId!);
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> _revoca(Collegamento c) async {
    final ok = await chiediConferma(
      context,
      titolo: 'Interrompere il collegamento con ${c.enteNome}?',
      testo: 'Non riceverai più richieste per questo iscritto. I documenti già consegnati restano all\'ente; la tua copia resta in "Condivisi con enti".',
      azione: 'Interrompi',
    );
    if (!ok) return;
    try {
      await RichiesteApi.revocaCollegamento(session.currentTenantId!, c.id);
      await _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    final canManage = session.can('collegamento:manage');
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Testo.bodySmall('Scuole e associazioni a cui hai accettato di collegarti. Non vedono il tuo archivio: ricevono solo ciò che consegni.', tenue: true),
      SizedBox(height: 12),
      if (error != null) Testo.bodySmall(error!, color: ColoreStato.errore),
      if (collegamenti.isEmpty && error == null) Testo.bodyMedium('Nessun ente collegato. Gli inviti arrivano per email dalla segreteria.'),
      for (final c in collegamenti)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(LucideIcons.building, color: c.attivo ? null : Colors.grey),
          title: Text(c.enteNome),
          subtitle: Text([
            '${c.iscritto}${c.personaNome != null ? ' (${c.personaNome})' : ''}',
            'referente: ${c.referente}',
            if (c.accettatoIl != null) c.attivo ? 'dal ${formatDataBreve(c.accettatoIl!)}' : 'interrotto',
          ].join(' · ')),
          trailing: c.attivo && canManage ? TextButton(onPressed: () => _revoca(c), child: const Text('Interrompi')) : null,
        ),
    ]);
  }
}
