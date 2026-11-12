import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';
import 'package:cartellaviva/features/scadenze/view/scadenza_form.dart';
import 'package:cartellaviva/features/scadenze/view/proposta_telefono.dart';
import 'package:cartellaviva/layout/layout.dart' show larghezzaTelefono;
import 'package:cartellaviva/layout/navigazione.dart';

/// Conferma di una scadenza trovata in un documento: apre il modulo già
/// compilato (titolo, data, documento, persona, categoria, promemoria a 30
/// giorni). Restituisce true se la scadenza è stata creata.
Future<bool> confermaProposta(BuildContext context, ScadenzaProposta p) async {
  final tenantId = SessionService.to.currentTenantId;
  if (tenantId == null) return false;
  final saved = await showScadenzaForm(
    context,
    tenantId: tenantId,
    documentoId: p.documentoId,
    documentoTitolo: p.documentoTitolo,
    iniziale: ScadenzaInput(
      titolo: p.titoloSuggerito,
      data: p.data,
      promemoriaGiorni: 30,
      documentoId: p.documentoId,
      membroId: p.membri.length == 1 ? p.membri.first.id : null,
    ),
  );
  return saved != null;
}

Future<bool> ignoraProposta(ScadenzaProposta p) async {
  final tenantId = SessionService.to.currentTenantId;
  if (tenantId == null) return false;
  try {
    await ScadenzaApi.ignoraProposta(tenantId, p.documentoId);
    return true;
  } on ApiException catch (e) {
    Get.snackbar('Scadenza', e.message, snackPosition: SnackPosition.BOTTOM, margin: const EdgeInsets.all(16), maxWidth: 480);
    return false;
  }
}

/// Riga di una proposta: "Carta d'identità · scade il 12/03/2036" con Crea/Ignora.
class PropostaTile extends StatelessWidget {
  final ScadenzaProposta proposta;
  final bool mostraDocumento;
  final VoidCallback onDecisa;

  const PropostaTile({super.key, required this.proposta, required this.onDecisa, this.mostraDocumento = true});

  @override
  Widget build(BuildContext context) {
    final p = proposta;
    if (MediaQuery.of(context).size.width < larghezzaTelefono) {
      return PropostaTelefono(
        proposta: p,
        mostraDocumento: mostraDocumento,
        onIgnora: () async {
          if (await ignoraProposta(p)) onDecisa();
        },
        onCrea: () async {
          if (await confermaProposta(context, p)) onDecisa();
        },
      );
    }
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(LucideIcons.calendar_search),
      title: Text('${p.tipo ?? p.documentoTitolo} · scade il ${formatDataBreve(p.data)}'),
      subtitle: mostraDocumento
          ? InkWell(onTap: () => Get.toNamed('/documento/${p.documentoId}'), child: Text('Dal documento "${p.documentoTitolo}"', style: const TextStyle(decoration: TextDecoration.underline)))
          : const Text('Trovata nel testo del documento: controlla che sia giusta.'),
      trailing: Wrap(spacing: 4, children: [
        TextButton(
          onPressed: () async {
            if (await ignoraProposta(p)) onDecisa();
          },
          child: const Text('Ignora'),
        ),
        FilledButton(
          onPressed: () async {
            if (await confermaProposta(context, p)) onDecisa();
          },
          child: const Text('Crea scadenza'),
        ),
      ]),
    );
  }
}

/// Rimando alla coda "Da confermare" quando ci sono scadenze trovate nei
/// documenti (dashboard e scadenziario). Le proposte si decidono in un posto
/// solo, con le stesse parole; qui resta una riga. Invisibile se non ce ne sono.
class ScadenzeProposteCard extends StatefulWidget {
  final VoidCallback? onCambiate;

  const ScadenzeProposteCard({super.key, this.onCambiate});

  @override
  State<ScadenzeProposteCard> createState() => _ScadenzeProposteCardState();
}

class _ScadenzeProposteCardState extends State<ScadenzeProposteCard> {
  List<ScadenzaProposta> proposte = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final tenantId = SessionService.to.currentTenantId;
    if (tenantId == null) return;
    try {
      final p = await ScadenzaApi.proposte(tenantId);
      if (mounted) setState(() => proposte = p);
    } on ApiException {
      // Non bloccante: è solo un suggerimento.
    }
  }

  @override
  Widget build(BuildContext context) {
    if (proposte.isEmpty) return const SizedBox.shrink();
    final n = proposte.length;
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Scheda(
        paddingAll: 0,
        child: InkWell(
          onTap: () => apriDalMenu('/da-confermare'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              Icon(LucideIcons.sparkles, size: 20, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Testo.bodyMedium('$n ${n == 1 ? 'scadenza trovata' : 'scadenze trovate'} nei documenti', fontWeight: FontWeight.w600),
                  const Testo.bodySmall('Da confermare: diventano scadenze solo se le confermi.', tenue: true),
                ]),
              ),
              const Icon(LucideIcons.chevron_right, size: 18),
            ]),
          ),
        ),
      ),
    );
  }
}
