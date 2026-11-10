import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/idee/api/idee_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/idee/model/idea.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// "Suggerisci un'idea": modulo di invio e le proprie idee con stato e risposta.
/// Le idee sono private: le legge solo il team della piattaforma.
class IdeeScreen extends StatefulWidget {
  const IdeeScreen({super.key});

  @override
  State<IdeeScreen> createState() => _IdeeScreenState();
}

class _IdeeScreenState extends State<IdeeScreen> {
  late Future<List<IdeaMia>> _future = IdeeApi.mie();

  void _reload() => setState(() => _future = IdeeApi.mie());

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Testo.titleMedium('Suggerisci un\'idea', fontSize: 18, fontWeight: FontWeight.w500),
          SizedBox(height: spazioPagina),
          Wrap(spacing: 20, runSpacing: 20, crossAxisAlignment: WrapCrossAlignment.start, children: [
            ConstrainedBox(constraints: const BoxConstraints(maxWidth: 560), child: IdeaForm(onInviata: _reload)),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: FutureBuilder<List<IdeaMia>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
                  if (snap.hasError) return Scheda(paddingAll: 20, child: Testo.bodyMedium(snap.error.toString()));
                  final idee = snap.data!;
                  return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Testo.titleSmall('Le mie idee', fontWeight: FontWeight.w600),
                      if (idee.isEmpty) Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall('Non hai ancora inviato idee.', tenue: true)),
                      for (final i in idee) _IdeaTile(idea: i),
                    ]));
                },
              ),
            ),
          ]),
        ]),
      ),
    );
  }
}

class _IdeaTile extends StatelessWidget {
  final IdeaMia idea;

  const _IdeaTile({required this.idea});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(top: 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Chip(label: Text(idea.statoLabel), visualDensity: VisualDensity.compact),
          SizedBox(width: 8),
          Expanded(
            child: Testo.bodySmall([
              tipiIdea[idea.tipo] ?? idea.tipo,
              if (idea.area != null) areeIdea[idea.area] ?? idea.area!,
              if (idea.createdAt != null) formatDataBreve(idea.createdAt!),
            ].join(' · '), tenue: true),
          ),
        ]),
        Testo.bodyMedium(idea.testo),
        if (idea.unita) Testo.bodySmall('Altri utenti hanno proposto la stessa idea: l\'abbiamo unita alle loro.', tenue: true),
        if (idea.rispostaPubblica != null) ...[
          SizedBox(height: 6),
          Container(
            width: double.infinity,
            padding: EdgeInsets.all(10),
            decoration: BoxDecoration(color: Theme.of(context).colorScheme.primary.withAlpha(20), borderRadius: BorderRadius.circular(6)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.labelSmall('Risposta del team', fontWeight: FontWeight.w600),
              Testo.bodySmall(idea.rispostaPubblica!),
            ]),
          ),
        ],
        const Divider(),
      ]),
    );
  }
}

/// Modulo d'invio, riusabile anche altrove (es. in un dialog).
class IdeaForm extends StatefulWidget {
  final VoidCallback? onInviata;

  const IdeaForm({super.key, this.onInviata});

  @override
  State<IdeaForm> createState() => _IdeaFormState();
}

class _IdeaFormState extends State<IdeaForm> {
  final testo = TextEditingController();
  String tipo = 'funzionalita';
  String? area;
  bool contattabile = true;
  bool busy = false;
  String? errore;
  bool inviata = false;

  @override
  void dispose() {
    testo.dispose();
    super.dispose();
  }

  bool get _valida => testo.text.trim().length >= ideaTestoMin;

  Future<void> _invia() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await IdeeApi.invia(tipo: tipo, area: area, testo: testo.text, contattabile: contattabile, tenantId: SessionService.to.currentTenantId);
      testo.clear();
      area = null;
      inviata = true;
      widget.onInviata?.call();
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scheda(paddingAll: 20, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [const Icon(LucideIcons.lightbulb, size: 18), SizedBox(width: 8), Testo.titleSmall('Come possiamo migliorare CartellaViva?', fontWeight: FontWeight.w600)]),
        SizedBox(height: 6),
        Testo.bodySmall('Raccontaci cosa vorresti fare con l\'app o cosa renderesti più semplice. Leggiamo tutte le proposte.', tenue: true),
        SizedBox(height: 12),
        SegmentedButton<String>(
          segments: [for (final e in tipiIdea.entries) ButtonSegment(value: e.key, label: Text(e.value))],
          selected: {tipo},
          onSelectionChanged: (s) => setState(() => tipo = s.first),
        ),
        SizedBox(height: 12),
        DropdownButtonFormField<String?>(
          initialValue: area,
          decoration: const InputDecoration(labelText: 'Riguarda (facoltativo)', isDense: true),
          items: [
            const DropdownMenuItem<String?>(value: null, child: Text('—')),
            for (final e in areeIdea.entries) DropdownMenuItem<String?>(value: e.key, child: Text(e.value)),
          ],
          onChanged: (v) => setState(() => area = v),
        ),
        SizedBox(height: 12),
        TextField(
          controller: testo,
          minLines: 4,
          maxLines: 8,
          maxLength: ideaTestoMax,
          onChanged: (_) => setState(() => inviata = false),
          decoration: const InputDecoration(labelText: 'La tua idea', hintText: 'Es. vorrei poter condividere una cartella con i nonni in sola lettura.', alignLabelWithHint: true),
        ),
        Row(children: [
          Icon(LucideIcons.shield_alert, size: 14, color: ColoreStato.avviso),
          SizedBox(width: 6),
          Expanded(child: Testo.bodySmall('Non inserire dati personali né contenuti dei tuoi documenti.', color: ColoreStato.avviso)),
        ]),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          value: contattabile,
          onChanged: (v) => setState(() => contattabile = v ?? true),
          title: const Text('Potete contattarmi via email per approfondire'),
        ),
        if (errore != null) Testo.bodySmall(errore!, color: ColoreStato.errore),
        if (inviata) Testo.bodySmall('Grazie! Abbiamo ricevuto la tua idea: ti scriveremo quando ci sono novità.', color: ColoreStato.successo),
        SizedBox(height: 8),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton.icon(
            onPressed: busy || !_valida ? null : _invia,
            icon: const Icon(LucideIcons.send, size: 16),
            label: const Text('Invia l\'idea'),
          ),
        ),
        if (!_valida && testo.text.isNotEmpty) Testo.bodySmall('Almeno $ideaTestoMin caratteri.', tenue: true),
      ]));
  }
}
