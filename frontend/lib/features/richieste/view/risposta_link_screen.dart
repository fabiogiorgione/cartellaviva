import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/operazione_con_errore.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/files/file_output.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/richieste/api/link_api.dart';
import 'package:cartellaviva/helpers/services/auth_service.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/model/me.dart';
import 'package:cartellaviva/features/famiglia/model/persona.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart' show tipoRichiestaLabel;
import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatDataBreve, formatIsoDate;
import 'package:cartellaviva/features/documenti/view/caricamento_dialog.dart' show scegliFile;
import 'package:cartellaviva/features/richieste/view/firma_codice_dialog.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Contenitore semplice per le pagine pubbliche della risposta (niente menu).
class _Pagina extends StatelessWidget {
  final Widget child;

  const _Pagina({required this.child});

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: EdgeInsets.all(16),
              child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 560), child: Scheda(paddingAll: 24, child: child)),
            ),
          ),
        ),
      );
}

/// Risposta senza account (/risposta?token=...): codice via email, poi firma,
/// invio o presa visione dal browser; alla fine la proposta di creare l'account.
class RispostaLinkScreen extends StatefulWidget {
  const RispostaLinkScreen({super.key});

  @override
  State<RispostaLinkScreen> createState() => _RispostaLinkScreenState();
}

class _RispostaLinkScreenState extends State<RispostaLinkScreen> with OperazioneConErrore<RispostaLinkScreen> {
  final String token = Get.parameters['token'] ?? '';
  late final Future<({String ente, String email})> _intro = LinkApi.apri(token);
  final codice = TextEditingController();
  bool codiceInviato = false;
  String? sessione;
  Map<String, dynamic>? richiesta;
  DateTime? validoFino;
  bool inviata = false;

  @override
  void dispose() {
    codice.dispose();
    super.dispose();
  }


  Future<void> _verifica() => esegui(() async {
        final s = await LinkApi.verifica(token, codice.text);
        sessione = s;
        LinkSessione.token = token;
        LinkSessione.sessione = s;
        richiesta = await LinkApi.richiesta(token, s);
      });

  bool get _serveValidita => richiesta?['chiediValidita'] == true && richiesta?['tipo'] != 'presa_visione';

  Future<void> _rispondi({Uint8List? bytes, String? nome}) => esegui(() async {
        if (_serveValidita && validoFino == null) {
          throw ApiException('Indica fino a quando è valido il documento.');
        }
        richiesta = await LinkApi.rispondi(token, sessione!,
            bytes: bytes, fileName: nome, mimeType: nome == null ? null : mimeTypeFor(nome), validoFino: validoFino == null ? null : formatIsoDate(validoFino!));
        inviata = true;
      });

  Future<void> _carica() async {
    final files = await scegliFile();
    if (files.isEmpty) return;
    await _rispondi(bytes: files.first.bytes, nome: files.first.nome);
  }

  Future<void> _nonPosso() async {
    final testo = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Non autorizzo / non posso firmare'),
        content: TextField(controller: testo, maxLines: 4, decoration: const InputDecoration(labelText: 'Messaggio per l\'ente')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Invia')),
        ],
      ),
    );
    if (ok == true) {
      await esegui(() async {
        richiesta = await LinkApi.rifiuta(token, sessione!, testo.text);
        inviata = true;
      });
    }
    testo.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _Pagina(
      child: FutureBuilder<({String ente, String email})>(
        future: _intro,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) return Testo.bodyMedium(snap.error.toString());
          final intro = snap.data!;
          return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Testo.labelMedium('CartellaViva · risposta sicura', tenue: true),
            SizedBox(height: 8),
            Row(children: [
              const Icon(LucideIcons.building),
              SizedBox(width: 10),
              Expanded(child: Testo.titleMedium(intro.ente, fontWeight: FontWeight.w600)),
            ]),
            SizedBox(height: 16),
            if (richiesta == null) ..._codice(intro.email) else if (inviata) ..._fatto() else ..._richiesta(),
            if (errore != null) ...[SizedBox(height: 12), Testo.bodySmall(errore!, color: ColoreStato.errore)],
          ]);
        },
      ),
    );
  }

  List<Widget> _codice(String email) => [
        Testo.bodyMedium('Per vedere la richiesta e rispondere ti mandiamo un codice a $email.'),
        SizedBox(height: 12),
        if (!codiceInviato)
          FilledButton(
            onPressed: busy
                ? null
                : () => esegui(() async {
                      await LinkApi.inviaCodice(token);
                      codiceInviato = true;
                    }),
            child: const Text('Inviami il codice'),
          )
        else ...[
          TextField(controller: codice, keyboardType: TextInputType.number, maxLength: 6, decoration: const InputDecoration(labelText: 'Codice ricevuto via email')),
          Wrap(spacing: 12, children: [
            FilledButton(onPressed: busy ? null : _verifica, child: const Text('Continua')),
            TextButton(onPressed: busy ? null : () => esegui(() => LinkApi.inviaCodice(token)), child: const Text('Invia un nuovo codice')),
          ]),
        ],
      ];

  List<Widget> _richiesta() {
    final r = richiesta!;
    final tipo = r['tipo'] as String;
    final entro = r['entroIl'] is String ? DateTime.tryParse(r['entroIl'] as String) : null;
    return [
      Row(children: [const Icon(LucideIcons.mail_check, size: 16), SizedBox(width: 6), Testo.bodySmall('Email verificata · ${r['email']}', tenue: true)]),
      SizedBox(height: 12),
      Testo.titleSmall(r['titolo'] as String, fontWeight: FontWeight.w600),
      Testo.bodySmall([
        tipoRichiestaLabel(tipo),
        'per ${r['iscritto']}${r['gruppo'] != null ? ', ${r['gruppo']}' : ''}',
        if (entro != null) 'entro il ${formatDataBreve(entro)}',
      ].join(' · ')),
      if ((r['istruzioni'] as String?)?.isNotEmpty == true) ...[SizedBox(height: 8), Testo.bodyMedium('"${r['istruzioni']}"')],
      if (r['stato'] == 'da_rifirmare' && r['motivoEnte'] != null) ...[SizedBox(height: 8), Testo.bodyMedium('L\'ente chiede di correggere: "${r['motivoEnte']}"', color: ColoreStato.errore)],
      if (r['allegato'] != null) ...[
        SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: busy ? null : () => esegui(() async => saveOrOpenFile(await LinkApi.allegato(token, sessione!), (r['allegato'] as Map)['nome'] as String, 'application/pdf')),
          icon: const Icon(LucideIcons.file_text, size: 16),
          label: Text('Apri ${(r['allegato'] as Map)['nome']}'),
        ),
      ],
      SizedBox(height: 16),
      if (const ['inviata', 'vista', 'da_rifirmare'].contains(r['stato'])) ...[
        if (tipo == 'firma') Testo.bodySmall('Stampa, firma e fotografa il foglio firmato (o carica il PDF già pronto).', tenue: true),
        if (_serveValidita) ...[
          Row(children: [
            Expanded(child: Text(validoFino == null ? 'Valido fino al: da indicare *' : 'Valido fino al ${formatDataBreve(validoFino!)}')),
            TextButton(
              onPressed: () async {
                final d = await showDatePicker(context: context, initialDate: DateTime.now().add(const Duration(days: 365)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 3650)));
                if (d != null) setState(() => validoFino = d);
              },
              child: const Text('Scegli'),
            ),
          ]),
        ],
        SizedBox(height: 8),
        if (tipo == 'firma' && r['modalitaFirma'] == 'codice') ...[
          Testo.bodySmall('Puoi firmare online con un codice via email, senza stampare.', tenue: true),
          SizedBox(height: 6),
          FilledButton.icon(
            onPressed: busy
                ? null
                : () async {
                    final ok = await showFirmaCodiceDialog(
                      context,
                      titolo: r['titolo'] as String,
                      firmatario: r['email'] as String,
                      apriDocumento: () async => saveOrOpenFile(await LinkApi.allegato(token, sessione!), ((r['allegato'] as Map?)?['nome'] as String?) ?? 'documento.pdf', 'application/pdf'),
                      chiediCodice: () => LinkApi.codiceFirma(token, sessione!),
                      firma: (codice) async {
                        richiesta = await LinkApi.firma(token, sessione!, codice);
                      },
                    );
                    if (ok) setState(() => inviata = true);
                  },
            icon: const Icon(LucideIcons.pen_line, size: 18),
            label: const Text('Firma con codice'),
          ),
          SizedBox(height: 8),
          Testo.bodySmall('Oppure firma su carta:', tenue: true),
        ],
        Wrap(spacing: 12, runSpacing: 8, children: [
          if (tipo == 'presa_visione')
            FilledButton(onPressed: busy ? null : () => _rispondi(), child: const Text('Confermo la presa visione'))
          else
            FilledButton.icon(onPressed: busy ? null : _carica, icon: const Icon(LucideIcons.camera, size: 18), label: const Text('Fotografa o carica il file')),
          TextButton(onPressed: busy ? null : _nonPosso, child: const Text('Non autorizzo / non posso')),
        ]),
      ] else
        Testo.bodyMedium('Hai già risposto a questa richiesta.'),
    ];
  }

  List<Widget> _fatto() {
    final scade = richiesta?['linkScadenza'] is String ? DateTime.tryParse(richiesta!['linkScadenza'] as String) : null;
    return [
      Row(children: [Icon(LucideIcons.circle_check, color: ColoreStato.successo), SizedBox(width: 8), Testo.bodyMedium('Inviato all\'ente.', fontWeight: FontWeight.w500)]),
      SizedBox(height: 16),
      Testo.titleSmall('Vuoi tenerne una copia?', fontWeight: FontWeight.w600),
      SizedBox(height: 6),
      Testo.bodySmall('Con un account gratuito ritrovi le richieste dell\'ente, le scadenze e i tuoi documenti in un posto sicuro. '
        'Senza account, questo link vale solo per questa richiesta${scade != null ? ' e scade il ${formatDataBreve(scade.toLocal())}' : ''}.', tenue: true),
      SizedBox(height: 12),
      Wrap(spacing: 12, children: [
        FilledButton(onPressed: () => Get.toNamed('/auth/register_account?next=${Uri.encodeComponent('/risposta/collega')}'), child: const Text('Crea l\'account')),
        TextButton(onPressed: () => Get.toNamed('/auth/login?next=${Uri.encodeComponent('/risposta/collega')}'), child: const Text('Ho già un account')),
      ]),
      Testo.bodySmall('Usa la stessa email a cui è arrivata la richiesta.', tenue: true),
    ];
  }
}

/// Dopo la registrazione o l'accesso: collega il link alla famiglia (stessa
/// email) e copia nella famiglia le risposte già date all'ente.
class CollegaLinkScreen extends StatefulWidget {
  const CollegaLinkScreen({super.key});

  @override
  State<CollegaLinkScreen> createState() => _CollegaLinkScreenState();
}

class _CollegaLinkScreenState extends State<CollegaLinkScreen> {
  String? famigliaId;
  List<Persona> persone = [];
  String personaScelta = '_nuova';
  final nome = TextEditingController();
  final cognome = TextEditingController();
  bool busy = false;
  String? errore;
  String? esito;

  List<TenantMembership> get famiglie =>
      (SessionService.to.me?.memberships ?? []).where((m) => m.tenantType == 'privato' && m.permissions.contains('collegamento:manage')).toList();

  @override
  void initState() {
    super.initState();
    if (famiglie.isNotEmpty) {
      famigliaId = famiglie.first.tenantId;
      _persone();
    }
  }

  @override
  void dispose() {
    nome.dispose();
    cognome.dispose();
    super.dispose();
  }

  Future<void> _persone() async {
    try {
      final p = await DocumentoApi.persone(famigliaId!);
      if (mounted) setState(() => persone = p);
    } on ApiException {
      // si potrà solo aggiungere una nuova persona
    }
  }

  Future<void> _collega() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      final n = await LinkApi.collega(LinkSessione.token!, LinkSessione.sessione!,
          tenantId: famigliaId!, personaId: personaScelta == '_nuova' ? null : personaScelta, nuovoNome: nome.text, nuovoCognome: cognome.text);
      LinkSessione.clear();
      await SessionService.to.selectTenant(famigliaId!);
      esito = 'Fatto: $n ${n == 1 ? 'risposta è stata copiata' : 'risposte sono state copiate'} nella tua famiglia, in "Condivisi con enti". Le prossime richieste arriveranno nell\'app.';
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final pronto = LinkSessione.token != null && LinkSessione.sessione != null && AuthService.isLoggedIn;
    return _Pagina(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Testo.titleMedium('Tieni una copia delle tue risposte', fontWeight: FontWeight.w600),
        SizedBox(height: 12),
        if (esito != null) ...[
          Testo.bodyMedium(esito!),
          SizedBox(height: 16),
          FilledButton(onPressed: () => Get.offAllNamed('/richieste'), child: const Text('Vai alle richieste')),
        ] else if (!pronto)
          Testo.bodyMedium('La sessione della risposta è scaduta: riapri il link dall\'email e inserisci di nuovo il codice.')
        else if (famiglie.isEmpty) ...[
          Testo.bodyMedium('Per conservare le risposte crea prima la tua famiglia.'),
          SizedBox(height: 12),
          FilledButton(onPressed: () => Get.toNamed('/benvenuto'), child: const Text('Crea la famiglia')),
        ] else ...[
          if (famiglie.length > 1)
            DropdownButtonFormField<String>(
              initialValue: famigliaId,
              decoration: const InputDecoration(labelText: 'Famiglia', isDense: true),
              items: [for (final f in famiglie) DropdownMenuItem(value: f.tenantId, child: Text(f.tenantName))],
              onChanged: (v) {
                setState(() {
                  famigliaId = v;
                  persone = [];
                  personaScelta = '_nuova';
                });
                _persone();
              },
            ),
          SizedBox(height: 8),
          Testo.labelMedium('A chi si riferiscono le richieste?'),
          RadioGroup<String>(
            groupValue: personaScelta,
            onChanged: (v) => setState(() => personaScelta = v ?? '_nuova'),
            child: Column(children: [
              for (final p in persone) RadioListTile<String>(contentPadding: EdgeInsets.zero, value: p.id!, title: Text(p.nomeCompleto)),
              const RadioListTile<String>(contentPadding: EdgeInsets.zero, value: '_nuova', title: Text('Una nuova persona')),
            ]),
          ),
          if (personaScelta == '_nuova')
            Row(children: [
              Expanded(child: TextField(controller: nome, decoration: const InputDecoration(labelText: 'Nome', isDense: true))),
              SizedBox(width: 8),
              Expanded(child: TextField(controller: cognome, decoration: const InputDecoration(labelText: 'Cognome', isDense: true))),
            ]),
          SizedBox(height: 8),
          Testo.bodySmall('L\'ente non vede questa scelta né il tuo archivio.', tenue: true),
          if (errore != null) ...[SizedBox(height: 8), Testo.bodySmall(errore!, color: ColoreStato.errore)],
          SizedBox(height: 16),
          FilledButton(onPressed: busy || famigliaId == null ? null : _collega, child: const Text('Collega e copia le risposte')),
        ],
      ]),
    );
  }
}
