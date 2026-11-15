import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/connettore_ai/connettori_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';

/// Permesso per collegare un Mac (solo il proprietario della famiglia).
const permessoConnettoreAi = 'connettore_ai:manage';

/// `/collega-mac?codice=WDJB-MJHT`: approva il Mac che mostra quel codice
/// (abbinamento del connettore AI, RFC 8628). Chi non ha il permesso vede il motivo.
class CollegaMacScreen extends StatefulWidget {
  const CollegaMacScreen({super.key});

  @override
  State<CollegaMacScreen> createState() => _CollegaMacScreenState();
}

class _CollegaMacScreenState extends State<CollegaMacScreen> {
  SessionService get session => SessionService.to;
  final _codice = TextEditingController(text: Get.parameters['codice'] ?? '');
  final _nome = TextEditingController();
  final _codice2fa = TextEditingController();
  ({String userCode, String nome})? _richiesta;
  String? _errore;
  bool _occupato = false;
  bool _fatto = false;

  @override
  void initState() {
    super.initState();
    if (_codice.text.isNotEmpty) _cerca();
  }

  @override
  void dispose() {
    _codice.dispose();
    _nome.dispose();
    _codice2fa.dispose();
    super.dispose();
  }

  String? get _tenant => session.currentTenantId;
  bool get _puo => session.can(permessoConnettoreAi);
  bool get _serve2fa => session.me?.twoFactorEnabled ?? false;

  Future<void> _cerca() async {
    final t = _tenant;
    if (t == null || !_puo) return;
    setState(() {
      _occupato = true;
      _errore = null;
    });
    try {
      final r = await ConnettoriApi.richiesta(t, _codice.text.trim());
      setState(() {
        _richiesta = r;
        _nome.text = r.nome;
      });
    } on ApiException catch (e) {
      setState(() => _errore = e.message);
    } finally {
      if (mounted) setState(() => _occupato = false);
    }
  }

  Future<void> _rispondi(bool approva) async {
    final t = _tenant;
    final r = _richiesta;
    if (t == null || r == null) return;
    setState(() => _occupato = true);
    try {
      if (approva) {
        await ConnettoriApi.approva(t, r.userCode, nome: _nome.text.trim().isEmpty ? null : _nome.text.trim(), codice2fa: _serve2fa ? _codice2fa.text.trim() : null);
      } else {
        await ConnettoriApi.nega(t, r.userCode);
      }
      setState(() => _fatto = true);
      if (mounted) mostraMessaggio(context, approva ? 'Mac collegato: tra pochi secondi inizia a lavorare.' : 'Collegamento rifiutato.');
    } on ApiException catch (e) {
      if (mounted) mostraMessaggio(context, e.message, errore: true);
    } finally {
      if (mounted) setState(() => _occupato = false);
    }
  }

  @override
  Widget build(BuildContext context) => Layout(
        indietro: '/account/security',
        titolo: 'Collega un Mac',
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: spazioPagina),
          child: Align(
            alignment: Alignment.topLeft,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Scheda(paddingAll: 20, child: _contenuto()),
            ),
          ),
        ),
      );

  Widget _contenuto() {
    final famiglia = session.currentMembership?.tenantName ?? 'la famiglia';
    if (!_puo) {
      return const Testo.bodyMedium('Solo il proprietario della famiglia può collegare un Mac per le analisi AI. Chiedi a lui di aprire questo link.');
    }
    if (_fatto) {
      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [Icon(LucideIcons.circle_check, color: context.colori.success), const SizedBox(width: 10), const Expanded(child: Testo.titleSmall('Fatto', fontWeight: FontWeight.w600))]),
        const SizedBox(height: 8),
        const Testo.bodyMedium('Puoi chiudere questa pagina. I Mac collegati sono in Sicurezza e accesso.'),
        const SizedBox(height: 16),
        FilledButton(onPressed: () => Get.offNamed('/account/security'), child: const Text('Vai a Sicurezza e accesso')),
      ]);
    }
    final r = _richiesta;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [Icon(LucideIcons.laptop, color: context.colori.primary), const SizedBox(width: 10), const Expanded(child: Testo.titleSmall('Collega un Mac', fontWeight: FontWeight.w600))]),
      const SizedBox(height: 8),
      const Testo.bodySmall(
          'Il Mac farà le analisi AI dei documenti al posto del server, con il suo modello. Riceve il testo dei documenti da analizzare e restituisce solo i valori letti, che il server ricontrolla. Lo puoi scollegare quando vuoi.',
          tenue: true),
      const SizedBox(height: 16),
      if (r == null) ...[
        TextField(
          controller: _codice,
          textCapitalization: TextCapitalization.characters,
          decoration: InputDecoration(labelText: 'Codice mostrato sul Mac', hintText: 'XXXX-XXXX', errorText: _errore),
          onSubmitted: (_) => _cerca(),
        ),
        const SizedBox(height: 16),
        Align(alignment: Alignment.centerLeft, child: FilledButton(onPressed: _occupato ? null : _cerca, child: const Text('Continua'))),
      ] else ...[
        Testo.bodyMedium('Collegare a $famiglia il Mac con il codice ${r.userCode}?', fontWeight: FontWeight.w500),
        const SizedBox(height: 12),
        TextField(controller: _nome, maxLength: 80, decoration: const InputDecoration(labelText: 'Nome del Mac')),
        if (_serve2fa) ...[
          const SizedBox(height: 8),
          TextField(controller: _codice2fa, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Codice della verifica in due passaggi')),
        ],
        const SizedBox(height: 16),
        Wrap(spacing: 8, runSpacing: 8, children: [
          FilledButton(onPressed: _occupato ? null : () => _rispondi(true), child: const Text('Approva')),
          OutlinedButton(onPressed: _occupato ? null : () => _rispondi(false), child: const Text('Rifiuta')),
        ]),
        const SizedBox(height: 8),
        const Testo.bodySmall('Se non hai avviato tu il collegamento su un tuo Mac, rifiuta.', tenue: true),
      ],
    ]);
  }
}
