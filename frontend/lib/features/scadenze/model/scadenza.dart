/// Scadenza dello scadenziario della famiglia (risposta di /api/tenants/{id}/scadenze).
class Scadenza {
  final String id;
  final String titolo;
  final String? note;
  final DateTime data;
  final String ricorrenza;
  final int? promemoriaGiorni;
  final String? documentoId;
  final String? documentoTitolo;
  final bool documentoRimosso;
  final String? membroId;
  final String? membroNome;
  final String? categoriaId;
  final String? categoriaNome;
  final String? categoriaColore;
  final String stato;
  final String origine;
  final bool scaduta;
  final int giorniMancanti;
  final String ownerId;
  final String? ownerName;

  /// Origine "richiesta": id della richiesta dell'ente (pagina Richieste).
  final String? richiestaId;

  Scadenza({
    this.richiestaId,
    required this.id,
    required this.titolo,
    this.note,
    required this.data,
    this.ricorrenza = 'nessuna',
    this.promemoriaGiorni,
    this.documentoId,
    this.documentoTitolo,
    this.documentoRimosso = false,
    this.membroId,
    this.membroNome,
    this.categoriaId,
    this.categoriaNome,
    this.categoriaColore,
    this.stato = 'aperta',
    this.origine = 'manuale',
    this.scaduta = false,
    this.giorniMancanti = 0,
    required this.ownerId,
    this.ownerName,
  });

  bool get aperta => stato == 'aperta';

  bool get daRichiesta => origine == 'richiesta';

  factory Scadenza.fromJson(Map<String, dynamic> json) {
    final documento = json['documento'] as Map?;
    final membro = json['membro'] as Map?;
    final categoria = json['categoria'] as Map?;
    return Scadenza(
      id: json['id'] as String,
      titolo: json['titolo'] as String,
      note: json['note'] as String?,
      data: DateTime.parse(json['data'] as String),
      ricorrenza: (json['ricorrenza'] as String?) ?? 'nessuna',
      promemoriaGiorni: (json['promemoriaGiorni'] as num?)?.toInt(),
      documentoId: json['documentoId'] as String?,
      documentoTitolo: documento?['titolo'] as String?,
      documentoRimosso: json['documentoRimosso'] == true,
      membroId: json['membroId'] as String?,
      membroNome: membro?['nome'] as String?,
      categoriaId: json['categoriaId'] as String?,
      categoriaNome: categoria?['nome'] as String?,
      categoriaColore: categoria?['colore'] as String?,
      stato: (json['stato'] as String?) ?? 'aperta',
      origine: (json['origine'] as String?) ?? 'manuale',
      scaduta: json['scaduta'] == true,
      giorniMancanti: (json['giorniMancanti'] as num?)?.toInt() ?? 0,
      ownerId: json['ownerId'] as String,
      ownerName: json['ownerName'] as String?,
      richiestaId: json['richiestaId'] as String?,
    );
  }

  /// "oggi", "domani", "tra 12 giorni", "scaduta da 3 giorni".
  String get quando {
    if (stato == 'fatta') return 'fatta';
    if (stato == 'in_verifica') return 'inviata, in verifica';
    final g = giorniMancanti;
    if (g == 0) return 'oggi';
    if (g == 1) return 'domani';
    if (g > 1) return 'tra $g giorni';
    if (g == -1) return 'scaduta ieri';
    return 'scaduta da ${-g} giorni';
  }

  String get ricorrenzaLabel => switch (ricorrenza) {
        'mensile' => 'ogni mese',
        'annuale' => 'ogni anno',
        _ => '',
      };
}

/// Dati del modulo (creazione e modifica).
class ScadenzaInput {
  String titolo;
  String? note;
  DateTime data;
  String ricorrenza;
  int? promemoriaGiorni;
  String? documentoId;
  String? membroId;
  String? categoriaId;

  ScadenzaInput({
    this.titolo = '',
    this.note,
    required this.data,
    this.ricorrenza = 'nessuna',
    this.promemoriaGiorni = 3,
    this.documentoId,
    this.membroId,
    this.categoriaId,
  });

  factory ScadenzaInput.from(Scadenza s) => ScadenzaInput(
        titolo: s.titolo,
        note: s.note,
        data: s.data,
        ricorrenza: s.ricorrenza,
        promemoriaGiorni: s.promemoriaGiorni,
        documentoId: s.documentoId,
        membroId: s.membroId,
        categoriaId: s.categoriaId,
      );

  Map<String, dynamic> toJson() => {
        'titolo': titolo.trim(),
        'note': (note ?? '').trim().isEmpty ? null : note!.trim(),
        'data': formatIsoDate(data),
        'ricorrenza': ricorrenza,
        'promemoriaGiorni': promemoriaGiorni,
        'documentoId': documentoId,
        'membroId': membroId,
        'categoriaId': categoriaId,
      };
}

/// "05/03/2027".
String formatDataBreve(DateTime d) => '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

String formatIsoDate(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Gruppi dell'elenco, nell'ordine in cui si mostrano.
enum GruppoScadenze { scadute, prossimi30, piuAvanti, inVerifica, fatte }

/// Raggruppa le scadenze per l'elenco (logica pura, testata).
Map<GruppoScadenze, List<Scadenza>> raggruppaScadenze(List<Scadenza> scadenze) {
  final gruppi = {for (final g in GruppoScadenze.values) g: <Scadenza>[]};
  for (final s in scadenze) {
    final gruppo = switch (s.stato) {
      'fatta' => GruppoScadenze.fatte,
      'in_verifica' => GruppoScadenze.inVerifica,
      _ when s.giorniMancanti < 0 => GruppoScadenze.scadute,
      _ when s.giorniMancanti <= 30 => GruppoScadenze.prossimi30,
      _ => GruppoScadenze.piuAvanti,
    };
    gruppi[gruppo]!.add(s);
  }
  gruppi[GruppoScadenze.fatte]!.sort((a, b) => b.data.compareTo(a.data));
  return gruppi;
}
