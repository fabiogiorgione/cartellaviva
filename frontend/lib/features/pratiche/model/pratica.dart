/// Pratiche (fase 2a): modelli (730), pratiche, voci. Contratto in docs/pratiche.md.
library;

T? _s<T>(Object? v) => v is T ? v : null;
/// Mappa di stringhe; l'API manda `[]` per un oggetto vuoto (serializzatore di Symfony).
Map<String, String> _valori(Object? v) =>
    v is Map ? {for (final e in v.entries) if (e.value != null) e.key.toString(): e.value.toString()} : const {};
Map<String, dynamic>? _mappa(Object? v) => v is Map ? Map<String, dynamic>.from(v) : null;
List<Map<String, dynamic>> _lista(Object? v) => (v as List? ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

/// Un campo di una voce: importo, data, testo, scelta (tipo), tracciabile.
class CampoVoce {
  final String codice, tipo, etichetta;
  final bool obbligatorio;

  /// Campo con cui si confronta (es. il saldo della DSU precompilata con quello letto).
  final String? confronta;

  /// Lo compila solo la persona (es. i valori della precompilata), mai l'AI.
  final bool soloUtente;
  const CampoVoce({required this.codice, required this.tipo, required this.etichetta, required this.obbligatorio, this.confronta, this.soloUtente = false});
  factory CampoVoce.fromJson(Map<String, dynamic> j) => CampoVoce(
        codice: j['codice'] as String,
        tipo: j['tipo'] as String,
        etichetta: j['etichetta'] as String,
        obbligatorio: j['obbligatorio'] == true,
        confronta: _s<String>(j['confronta']),
        soloUtente: j['soloUtente'] == true,
      );
}

class TipoVoce {
  final String codice, etichetta, tracciabile;
  final String? nota;
  const TipoVoce({required this.codice, required this.etichetta, required this.tracciabile, this.nota});
  factory TipoVoce.fromJson(Map<String, dynamic> j) =>
      TipoVoce(codice: j['codice'] as String, etichetta: j['etichetta'] as String, tracciabile: j['tracciabile'] as String, nota: _s<String>(j['nota']));
}

class RequisitoPratica {
  final String codice, etichetta, descrizione;
  final bool perPersona, obbligatorio, somma;

  /// 'iban' o 'targa': codici trovati nei documenti, ognuno da completare.
  final String? scopri;
  final List<TipoVoce> tipi;
  final List<CampoVoce> campi;
  const RequisitoPratica({
    required this.codice,
    required this.etichetta,
    required this.descrizione,
    required this.perPersona,
    required this.obbligatorio,
    required this.somma,
    this.scopri,
    required this.tipi,
    required this.campi,
  });
  factory RequisitoPratica.fromJson(Map<String, dynamic> j) => RequisitoPratica(
        codice: j['codice'] as String,
        etichetta: j['etichetta'] as String,
        descrizione: j['descrizione'] as String? ?? '',
        perPersona: j['per'] == 'persona',
        obbligatorio: j['obbligatorio'] == true,
        somma: j['somma'] != false,
        scopri: _s<String>(j['scopri']),
        tipi: _lista(j['tipi']).map(TipoVoce.fromJson).toList(),
        campi: _lista(j['campi']).map(CampoVoce.fromJson).toList(),
      );

  TipoVoce? tipo(String? codice) => tipi.where((t) => t.codice == codice).firstOrNull;
}

/// Modello di pratica per un anno (es. "730/2026 · spese 2025").
class ModelloPratica {
  final String modello, titolo, descrizione;
  final int anno, annoRiferimento;
  final bool provvisorio, proposto;
  final String? verificato, note;
  final List<({String titolo, String url})> fonti;
  final List<RequisitoPratica> requisiti;
  const ModelloPratica({
    required this.modello,
    required this.anno,
    int? annoRiferimento,
    required this.titolo,
    required this.descrizione,
    required this.provvisorio,
    this.proposto = false,
    this.verificato,
    this.note,
    this.fonti = const [],
    this.requisiti = const [],
  }) : annoRiferimento = annoRiferimento ?? anno;
  factory ModelloPratica.fromJson(Map<String, dynamic> j) => ModelloPratica(
        modello: j['modello'] as String,
        anno: (j['anno'] as num).toInt(),
        annoRiferimento: (j['annoRiferimento'] as num?)?.toInt(),
        titolo: j['titolo'] as String,
        descrizione: j['descrizione'] as String? ?? '',
        provvisorio: j['provvisorio'] == true,
        proposto: j['proposto'] == true,
        verificato: _s<String>(j['verificato']),
        note: _s<String>(j['note']),
        fonti: _lista(j['fonti']).map((f) => (titolo: f['titolo'] as String, url: f['url'] as String)).toList(),
        requisiti: _lista(j['requisiti']).map(RequisitoPratica.fromJson).toList(),
      );

  RequisitoPratica? requisito(String codice) => requisiti.where((r) => r.codice == codice).firstOrNull;
}

class PersonaPratica {
  final String id, nome;
  const PersonaPratica(this.id, this.nome);
}

class ControlloVoce {
  final String codice, livello, testo;
  const ControlloVoce(this.codice, this.livello, this.testo);
  bool get avviso => livello == 'avviso';
}

class VocePratica {
  final String id, requisito, stato, origine;
  final String? personaId, persona;
  final ({String id, String titolo, bool cifrato})? documento;
  final Map<String, String> valori;
  final List<ControlloVoce> controlli;

  /// Solo nella coda "Da confermare".
  final String? praticaId, praticaTitolo, requisitoEtichetta, tipoEtichetta;

  const VocePratica({
    required this.id,
    required this.requisito,
    required this.stato,
    required this.origine,
    this.personaId,
    this.persona,
    this.documento,
    this.valori = const {},
    this.controlli = const [],
    this.praticaId,
    this.praticaTitolo,
    this.requisitoEtichetta,
    this.tipoEtichetta,
  });

  factory VocePratica.fromJson(Map<String, dynamic> j) {
    final d = _mappa(j['documento']);
    return VocePratica(
      id: j['id'] as String,
      requisito: j['requisito'] as String,
      stato: j['stato'] as String,
      origine: j['origine'] as String,
      personaId: _s<String>(j['personaId']),
      persona: _s<String>(j['persona']),
      documento: d == null ? null : (id: d['id'] as String, titolo: d['titolo'] as String, cifrato: d['cifrato'] == true),
      valori: _valori(j['valori']),
      controlli: _lista(j['controlli']).map((c) => ControlloVoce(c['codice'] as String, c['livello'] as String, c['testo'] as String)).toList(),
      praticaId: _s<String>(j['praticaId']),
      praticaTitolo: _s<String>(j['praticaTitolo']),
      requisitoEtichetta: _s<String>(j['requisitoEtichetta']),
      tipoEtichetta: _s<String>(j['tipoEtichetta']),
    );
  }

  bool get proposta => stato == 'proposta';
  bool get confermata => stato == 'confermata';
  bool get esclusa => stato == 'esclusa';
  DateTime? get data => DateTime.tryParse(valori['data'] ?? '');
}

class Mancante {
  final String requisito, etichetta;
  final String? personaId, persona;

  /// Codice trovato nei documenti (un IBAN, una targa) ancora senza voce.
  final String? dettaglio;

  /// Valori con cui precompilare la voce (es. l'IBAN).
  final Map<String, String> valori;
  const Mancante({required this.requisito, required this.etichetta, this.personaId, this.persona, this.dettaglio, this.valori = const {}});
  factory Mancante.fromJson(Map<String, dynamic> j) => Mancante(
        requisito: j['requisito'] as String,
        etichetta: j['etichetta'] as String,
        personaId: _s<String>(j['personaId']),
        persona: _s<String>(j['persona']),
        dettaglio: _s<String>(j['dettaglio']),
        valori: _valori(j['valori']),
      );
}

class AnnoPrima {
  final String requisito, etichetta;
  final String? emittente, persona;
  const AnnoPrima({required this.requisito, required this.etichetta, this.emittente, this.persona});
}

class RiepilogoPratica {
  final String totale;
  final List<({String etichetta, String totale, List<({String etichetta, String totale})> tipi})> requisiti;
  final List<({String? personaId, String persona, String totale})> persone;
  const RiepilogoPratica({this.totale = '0.00', this.requisiti = const [], this.persone = const []});
  factory RiepilogoPratica.fromJson(Map<String, dynamic> j) => RiepilogoPratica(
        totale: j['totale'] as String? ?? '0.00',
        requisiti: _lista(j['requisiti'])
            .map((r) => (
                  etichetta: r['etichetta'] as String,
                  totale: r['totale'] as String,
                  tipi: _lista(r['tipi']).map((t) => (etichetta: t['etichetta'] as String, totale: t['totale'] as String)).toList(),
                ))
            .toList(),
        persone: _lista(j['persone']).map((p) => (personaId: _s<String>(p['personaId']), persona: p['persona'] as String, totale: p['totale'] as String)).toList(),
      );
}

/// Una pratica: nell'elenco solo il riassunto; nel dettaglio anche voci, controlli e somme.
class Pratica {
  final String id, modello, titolo, stato;
  final int anno, annoRiferimento, confermate, proposte, mancanti;
  final bool provvisorio, puoGestire;
  final String totaleConfermato;
  final List<PersonaPratica> persone;
  final ModelloPratica? info;
  final ({String? da, DateTime? il})? consensoCifrati;
  final List<({String requisito, String? personaId, String? dettaglio})> nonServe;
  final ({String id, DateTime data})? scadenzaRinnovo;

  /// 'server' (Ollama) o 'telefono' (modello Apple sull'iPhone).
  final String analisiSu;
  final List<VocePratica> voci;
  final List<Mancante> cosaManca;
  final List<AnnoPrima> annoPrima;
  final RiepilogoPratica riepilogo;
  final ({int inCoda, int analizzati, int cifratiInAttesa}) analisi;

  const Pratica({
    required this.id,
    required this.modello,
    required this.anno,
    int? annoRiferimento,
    required this.titolo,
    required this.stato,
    this.confermate = 0,
    this.proposte = 0,
    this.mancanti = 0,
    this.provvisorio = false,
    this.puoGestire = false,
    this.totaleConfermato = '0.00',
    this.persone = const [],
    this.info,
    this.consensoCifrati,
    this.nonServe = const [],
    this.voci = const [],
    this.cosaManca = const [],
    this.annoPrima = const [],
    this.riepilogo = const RiepilogoPratica(),
    this.analisi = (inCoda: 0, analizzati: 0, cifratiInAttesa: 0),
    this.scadenzaRinnovo,
    this.analisiSu = 'server',
  }) : annoRiferimento = annoRiferimento ?? anno;

  factory Pratica.fromJson(Map<String, dynamic> j) {
    final c = _mappa(j['consensoCifrati']);
    final a = _mappa(j['analisi']) ?? const {};
    final r = _mappa(j['scadenzaRinnovo']);
    return Pratica(
      id: j['id'] as String,
      modello: j['modello'] as String,
      anno: (j['anno'] as num).toInt(),
      annoRiferimento: (j['annoRiferimento'] as num?)?.toInt(),
      titolo: j['titolo'] as String,
      stato: j['stato'] as String,
      confermate: (j['confermate'] as num? ?? 0).toInt(),
      proposte: (j['proposte'] as num? ?? 0).toInt(),
      mancanti: (j['mancanti'] as num? ?? 0).toInt(),
      provvisorio: j['provvisorio'] == true,
      puoGestire: j['puoGestire'] == true,
      totaleConfermato: j['totaleConfermato'] as String? ?? '0.00',
      persone: _lista(j['persone']).map((p) => PersonaPratica(p['id'] as String, p['nome'] as String)).toList(),
      info: j['modelloInfo'] is Map ? ModelloPratica.fromJson(Map<String, dynamic>.from(j['modelloInfo'] as Map)) : null,
      consensoCifrati: c == null ? null : (da: _s<String>(c['da']), il: DateTime.tryParse(c['il'] as String? ?? '')),
      nonServe: _lista(j['nonServe']).map((n) => (requisito: n['requisito'] as String, personaId: _s<String>(n['personaId']), dettaglio: _s<String>(n['dettaglio']))).toList(),
      scadenzaRinnovo: r == null ? null : (id: r['id'] as String, data: DateTime.parse(r['data'] as String)),
      analisiSu: j['analisiSu'] as String? ?? 'server',
      voci: _lista(j['voci']).map(VocePratica.fromJson).toList(),
      cosaManca: _lista(j['cosaManca']).map(Mancante.fromJson).toList(),
      annoPrima: _lista(j['annoPrima'])
          .map((x) => AnnoPrima(requisito: x['requisito'] as String, etichetta: x['etichetta'] as String, emittente: _s<String>(x['emittente']), persona: _s<String>(x['persona'])))
          .toList(),
      riepilogo: j['riepilogo'] is Map ? RiepilogoPratica.fromJson(Map<String, dynamic>.from(j['riepilogo'] as Map)) : const RiepilogoPratica(),
      analisi: (inCoda: (a['inCoda'] as num? ?? 0).toInt(), analizzati: (a['analizzati'] as num? ?? 0).toInt(), cifratiInAttesa: (a['cifratiInAttesa'] as num? ?? 0).toInt()),
    );
  }

  bool get inCorso => stato == 'in_corso';

  bool nonServePer(String requisito, String? personaId) => nonServe.any((n) => n.requisito == requisito && n.personaId == personaId && n.dettaglio == null);

  bool get isee => modello == 'isee';
  bool get sulTelefono => analisiSu == 'telefono';
}

/// "730 · spese 2025: 1.240 € confermati, 3 da vedere, 2 mancanti".
String riepilogoPratica(Pratica p, String Function(String) euro) {
  final parti = <String>[
    if (p.confermate > 0) '${euro(p.totaleConfermato)} confermati' else 'nessuna voce confermata',
    if (p.proposte > 0) '${p.proposte} da vedere',
    if (p.mancanti > 0) '${p.mancanti} ${p.mancanti == 1 ? 'mancante' : 'mancanti'}',
  ];
  return parti.join(', ');
}

const statiPratica = {'in_corso': 'In corso', 'pronta': 'Pronta', 'inviata': 'Inviata', 'archiviata': 'Archiviata'};
const valoriTracciabile = {'si': 'Sì', 'no': 'No, in contanti', 'non_risulta': 'Non risulta'};

/// Un documento da analizzare sull'iPhone. Il testo (nel prompt) vive solo in memoria.
class LavoroTelefono {
  final String documentoId, titolo, istruzioni, prompt, schema;
  const LavoroTelefono({required this.documentoId, required this.titolo, required this.istruzioni, required this.prompt, required this.schema});
  factory LavoroTelefono.fromJson(Map<String, dynamic> j) => LavoroTelefono(
        documentoId: j['documentoId'] as String,
        titolo: j['titolo'] as String,
        istruzioni: j['istruzioni'] as String,
        prompt: j['prompt'] as String,
        schema: j['schema'] as String,
      );
}
