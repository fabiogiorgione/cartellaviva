/// Portale delle idee: etichette e modelli.
library;

const Map<String, String> tipiIdea = {'funzionalita': 'Nuova funzionalità', 'miglioramento': 'Miglioramento'};

const Map<String, String> areeIdea = {
  'documenti': 'Documenti',
  'scadenze': 'Scadenze',
  'scansione': 'Scansione',
  'import': 'Import da Drive',
  'enti': 'Enti e richieste',
  'altro': 'Altro',
};

const Map<String, String> statiIdea = {
  'nuova': 'Inviata',
  'letta': 'Letta',
  'in_valutazione': 'In valutazione',
  'pianificata': 'Pianificata',
  'realizzata': 'Realizzata',
  'non_prevista': 'Non prevista',
};

const Map<String, String> spaziIdea = {'famiglia': 'Famiglia', 'ente': 'Ente', 'azienda': 'Azienda'};

const int ideaTestoMin = 20;
const int ideaTestoMax = 2000;

String statoIdeaLabel(String stato) => statiIdea[stato] ?? stato;

DateTime? _data(dynamic v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

/// Un'idea vista dal suo autore: stato e risposta sono quelli della principale se è stata unita.
class IdeaMia {
  final String id;
  final String tipo;
  final String? area;
  final String testo;
  final bool contattabile;
  final String stato;
  final String? rispostaPubblica;
  final bool unita;
  final DateTime? createdAt;
  final DateTime? aggiornataIl;

  IdeaMia({
    required this.id,
    required this.tipo,
    this.area,
    required this.testo,
    this.contattabile = true,
    this.stato = 'nuova',
    this.rispostaPubblica,
    this.unita = false,
    this.createdAt,
    this.aggiornataIl,
  });

  factory IdeaMia.fromJson(Map<String, dynamic> json) => IdeaMia(
        id: json['id'] as String,
        tipo: json['tipo'] as String,
        area: json['area'] as String?,
        testo: json['testo'] as String,
        contattabile: json['contattabile'] != false,
        stato: (json['stato'] as String?) ?? 'nuova',
        rispostaPubblica: json['rispostaPubblica'] as String?,
        unita: json['unita'] == true,
        createdAt: _data(json['createdAt']),
        aggiornataIl: _data(json['aggiornataIl']),
      );

  String get statoLabel => statoIdeaLabel(stato);
}

/// Un'idea vista dal portale.
class IdeaPortale {
  final String id;
  final String tipo;
  final String? area;
  final String testo;
  final String stato;
  final String? rispostaPubblica;
  final String? notaInterna;
  final String? tipoSpazio;
  final String? piattaforma;
  final String? versioneApp;
  final int richieste;
  final String? duplicatoDi;
  final bool anonima;
  final ({String nome, String email})? contatto;
  final DateTime? createdAt;
  final List<IdeaPortale> duplicati;

  IdeaPortale({
    required this.id,
    required this.tipo,
    this.area,
    required this.testo,
    this.stato = 'nuova',
    this.rispostaPubblica,
    this.notaInterna,
    this.tipoSpazio,
    this.piattaforma,
    this.versioneApp,
    this.richieste = 1,
    this.duplicatoDi,
    this.anonima = false,
    this.contatto,
    this.createdAt,
    this.duplicati = const [],
  });

  factory IdeaPortale.fromJson(Map<String, dynamic> json) {
    final c = json['contatto'] as Map?;
    return IdeaPortale(
      id: json['id'] as String,
      tipo: json['tipo'] as String,
      area: json['area'] as String?,
      testo: json['testo'] as String,
      stato: (json['stato'] as String?) ?? 'nuova',
      rispostaPubblica: json['rispostaPubblica'] as String?,
      notaInterna: json['notaInterna'] as String?,
      tipoSpazio: json['tipoSpazio'] as String?,
      piattaforma: json['piattaforma'] as String?,
      versioneApp: json['versioneApp'] as String?,
      richieste: (json['richieste'] as num?)?.toInt() ?? 1,
      duplicatoDi: json['duplicatoDi'] as String?,
      anonima: json['anonima'] == true,
      contatto: c == null ? null : (nome: c['nome'] as String, email: c['email'] as String),
      createdAt: _data(json['createdAt']),
      duplicati: (json['duplicati'] as List? ?? []).map((e) => IdeaPortale.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
    );
  }

  /// "Nuova funzionalità · Documenti · famiglia · android 1.4.0"
  String get contesto => [
        tipiIdea[tipo] ?? tipo,
        if (area != null) areeIdea[area] ?? area!,
        if (tipoSpazio != null) spaziIdea[tipoSpazio] ?? tipoSpazio!,
        if (piattaforma != null) [piattaforma!, if (versioneApp != null) versioneApp!].join(' '),
      ].join(' · ');
}
