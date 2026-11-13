import 'package:flutter/material.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/widgets/foglio.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// "Dove cercare" di una pratica: tutti i documenti, oppure alcune categorie ed
/// etichette (con quanti documenti hanno), e se analizzarli tutti. Chiama
/// [onChanged] con l'ambito (null = tutti i documenti) e se è valido (scelta
/// la seconda opzione serve almeno una categoria o un'etichetta).
class SceltaAmbito extends StatefulWidget {
  final String tenantId;
  final AmbitoPratica? iniziale;
  final void Function(AmbitoPratica? ambito, bool valido) onChanged;

  const SceltaAmbito({super.key, required this.tenantId, this.iniziale, required this.onChanged});

  @override
  State<SceltaAmbito> createState() => _SceltaAmbitoState();
}

class _SceltaAmbitoState extends State<SceltaAmbito> {
  /// Quante etichette mostrare prima di "Mostra tutte" (le più usate).
  static const _primeEtichette = 24;

  late bool _soloAlcuni = widget.iniziale != null;
  late final Set<String> _categorie = {...?widget.iniziale?.categorie.map((c) => c.id)};
  late final Set<String> _etichette = {...?widget.iniziale?.etichette};
  late bool _tutti = widget.iniziale?.tutti ?? false;
  bool _tutteEtichette = false;
  late final Future<Faccette> _faccette = DocumentoApi.faccette(widget.tenantId);
  List<VoceFaccetta> _vociCategorie = const [];

  void _cambia(VoidCallback f) {
    setState(f);
    if (!_soloAlcuni) {
      widget.onChanged(null, true);
      return;
    }
    final nomi = {for (final c in _vociCategorie) c.valore: c.etichetta};
    final ambito = AmbitoPratica(
      categorie: [for (final id in _categorie) (id: id, nome: nomi[id] ?? '')],
      etichette: _etichette.toList(),
      tutti: _tutti,
    );
    widget.onChanged(ambito, !ambito.vuoto);
  }

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Testo.titleSmall('Dove cercare', fontWeight: FontWeight.w600),
        RadioGroup<bool>(
          groupValue: _soloAlcuni,
          onChanged: (v) => _cambia(() => _soloAlcuni = v ?? false),
          child: const Column(children: [
            RadioListTile<bool>(contentPadding: EdgeInsets.zero, value: false, title: Text('Tutti i documenti della famiglia'), subtitle: Text('Si guardano quelli che parlano dei requisiti della pratica.')),
            RadioListTile<bool>(contentPadding: EdgeInsets.zero, value: true, title: Text('Solo alcune categorie o etichette'), subtitle: Text('Per esempio la cartella in cui hai messo i documenti per questa pratica.')),
          ]),
        ),
        if (_soloAlcuni)
          FutureBuilder<Faccette>(
            future: _faccette,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) return const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: LinearProgressIndicator());
              if (snap.hasError) return Testo.bodySmall(snap.error is ApiException ? (snap.error as ApiException).message : 'Non riesco a caricare categorie ed etichette.');
              final f = snap.data!;
              _vociCategorie = f.voci[DimensioneFiltro.categoria] ?? const [];
              final etichette = (f.voci[DimensioneFiltro.tag] ?? const []).where((v) => v.valore != filtroNessuno).toList();
              final visibili = _tutteEtichette ? etichette : etichette.take(_primeEtichette).toList();
              // Le etichette già scelte restano visibili anche se non sono tra le più usate.
              for (final e in _etichette) {
                if (!visibili.any((v) => v.valore == e)) visibili.add(VoceFaccetta(valore: e, etichetta: e, n: 0));
              }
              return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const SizedBox(height: 4),
                const Testo.bodySmall('Categorie', fontWeight: FontWeight.w600),
                const SizedBox(height: 6),
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final c in _vociCategorie)
                    FilterChip(
                      label: Text('${c.etichetta} (${c.n})'),
                      selected: _categorie.contains(c.valore),
                      onSelected: (s) => _cambia(() => s ? _categorie.add(c.valore) : _categorie.remove(c.valore)),
                    ),
                ]),
                const SizedBox(height: 12),
                const Testo.bodySmall('Etichette', fontWeight: FontWeight.w600),
                const Testo.bodySmall('Le aggiunge l\'indice dei documenti; i documenti cifrati non le hanno.', tenue: true),
                const SizedBox(height: 6),
                if (etichette.isEmpty && _etichette.isEmpty)
                  const Testo.bodySmall('Nessuna etichetta ancora.', tenue: true)
                else
                  Wrap(spacing: 6, runSpacing: 6, children: [
                    for (final e in visibili)
                      FilterChip(
                        label: Text(e.n > 0 ? '${e.etichetta} (${e.n})' : e.etichetta),
                        selected: _etichette.contains(e.valore),
                        onSelected: (s) => _cambia(() => s ? _etichette.add(e.valore) : _etichette.remove(e.valore)),
                      ),
                    if (!_tutteEtichette && etichette.length > _primeEtichette)
                      ActionChip(label: Text('Mostra tutte (${etichette.length})'), onPressed: () => setState(() => _tutteEtichette = true)),
                  ]),
                const SizedBox(height: 4),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _tutti,
                  onChanged: (v) => _cambia(() => _tutti = v ?? false),
                  title: const Text('Analizza tutti i documenti scelti'),
                  subtitle: const Text('Anche senza parole chiave e di qualsiasi anno: l\'AI decide a cosa servono. Più lento, ogni documento passa dall\'AI.'),
                ),
                if (_categorie.isEmpty && _etichette.isEmpty) const Testo.bodySmall('Scegli almeno una categoria o un\'etichetta.', tenue: true),
              ]);
            },
          ),
      ]);
}

/// Foglio "Dove cercare" dalla pagina della pratica: restituisce l'ambito scelto
/// (null = tutti i documenti), oppure niente se si chiude senza salvare.
Future<({AmbitoPratica? ambito})?> scegliAmbito(BuildContext context, String tenantId, AmbitoPratica? attuale) {
  AmbitoPratica? scelto = attuale;
  final valido = ValueNotifier(true);
  return apriFoglio<({AmbitoPratica? ambito})>(
    context,
    titolo: 'Dove cercare',
    contenuto: (ctx) => Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
      child: SceltaAmbito(
        tenantId: tenantId,
        iniziale: attuale,
        onChanged: (a, ok) {
          scelto = a;
          valido.value = ok;
        },
      ),
    ),
    azione: (ctx) => ValueListenableBuilder<bool>(
      valueListenable: valido,
      builder: (ctx, ok, _) => FilledButton(onPressed: ok ? () => Navigator.of(ctx).pop((ambito: scelto)) : null, child: const Text('Salva e cerca di nuovo')),
    ),
  ).whenComplete(valido.dispose);
}
