export 'richiesta_ente.dart';

/// Profilo e stato di verifica di un ente.
class EnteProfilo {
  final String tenantId;
  final String denominazione;
  final String tipo;
  final String codiceFiscale;
  final String? codiceMeccanografico;
  final String? numeroRunts;
  final String emailIstituzionale;
  final String? sedeLegale;
  final String stato;
  final bool verificato;
  final String? notaPortale;
  final Map<String, bool> controlli;
  final String? referenteNome;
  final String? referenteEmail;
  final DateTime? createdAt;

  EnteProfilo({
    required this.tenantId,
    required this.denominazione,
    required this.tipo,
    required this.codiceFiscale,
    this.codiceMeccanografico,
    this.numeroRunts,
    required this.emailIstituzionale,
    this.sedeLegale,
    required this.stato,
    this.verificato = false,
    this.notaPortale,
    this.controlli = const {},
    this.referenteNome,
    this.referenteEmail,
    this.createdAt,
  });

  factory EnteProfilo.fromJson(Map<String, dynamic> json) => EnteProfilo(
        tenantId: json['tenantId'] as String,
        denominazione: json['denominazione'] as String,
        tipo: json['tipo'] as String,
        codiceFiscale: json['codiceFiscale'] as String,
        codiceMeccanografico: json['codiceMeccanografico'] as String?,
        numeroRunts: json['numeroRunts'] as String?,
        emailIstituzionale: json['emailIstituzionale'] as String,
        sedeLegale: json['sedeLegale'] as String?,
        stato: json['stato'] as String,
        verificato: json['verificato'] == true,
        notaPortale: json['notaPortale'] as String?,
        controlli: (json['controlli'] as Map? ?? {}).map((k, v) => MapEntry(k.toString(), v == true)),
        referenteNome: (json['referente'] as Map?)?['nome'] as String?,
        referenteEmail: (json['referente'] as Map?)?['email'] as String?,
        createdAt: json['createdAt'] is String ? DateTime.tryParse(json['createdAt'] as String)?.toLocal() : null,
      );

  String get tipoLabel => tipo == 'scuola' ? 'Scuola' : 'Associazione';

  String get statoLabel => switch (stato) {
        'email_da_confermare' => 'Email da confermare',
        'in_verifica' => 'In verifica',
        'integrazioni' => 'Integrazioni richieste',
        'verificato' => 'Verificato',
        'respinto' => 'Respinto',
        _ => stato,
      };
}

/// "2026/27" -> "2027/28" (vuoto se il formato non è riconosciuto).
String annoSuccessivo(String nome) {
  final m = RegExp(r'^(\d{4})/\d{2}$').firstMatch(nome);
  if (m == null) return '';
  final inizio = int.parse(m.group(1)!) + 1;
  return '$inizio/${((inizio + 1) % 100).toString().padLeft(2, '0')}';
}

/// Nome del gruppo nell'anno successivo: "3ªB" -> "4ªB"; le quinte escono (""),
/// i nomi senza numero (corsi, squadre) restano uguali.
String gruppoSuccessivo(String nome) {
  final m = RegExp(r'^(\d)(.*)$').firstMatch(nome);
  if (m == null) return nome;
  final n = int.parse(m.group(1)!);
  return n >= 5 ? '' : '${n + 1}${m.group(2)}';
}

class AnnoEnte {
  final String id;
  final String nome;
  final bool archiviato;

  AnnoEnte({required this.id, required this.nome, this.archiviato = false});

  factory AnnoEnte.fromJson(Map<String, dynamic> json) => AnnoEnte(id: json['id'] as String, nome: json['nome'] as String, archiviato: json['archiviato'] == true);
}

class Sede {
  final String id;
  final String nome;
  final String? indirizzo;

  Sede({required this.id, required this.nome, this.indirizzo});

  factory Sede.fromJson(Map<String, dynamic> json) => Sede(id: json['id'] as String, nome: json['nome'] as String, indirizzo: json['indirizzo'] as String?);
}

class Gruppo {
  final String id;
  final String nome;
  final String tipo;
  final String anno;
  final bool archiviato;
  final Sede? sede;
  final List<({String membershipId, String nome})> operatori;
  final int iscritti;

  Gruppo({required this.id, required this.nome, this.tipo = 'classe', this.anno = '', this.archiviato = false, this.sede, this.operatori = const [], this.iscritti = 0});

  factory Gruppo.fromJson(Map<String, dynamic> json) => Gruppo(
        id: json['id'] as String,
        nome: json['nome'] as String,
        tipo: (json['tipo'] as String?) ?? 'classe',
        anno: (json['anno'] as String?) ?? '',
        archiviato: json['archiviato'] == true,
        sede: json['sede'] is Map ? Sede.fromJson(Map<String, dynamic>.from(json['sede'] as Map)) : null,
        operatori: (json['operatori'] as List? ?? []).map((o) => (membershipId: o['membershipId'] as String, nome: o['nome'] as String)).toList(),
        iscritti: (json['iscritti'] as num?)?.toInt() ?? 0,
      );
}

class Referente {
  final String? id;
  String nome;
  String cognome;
  String? email;
  String? relazione;
  final String stato;

  Referente({this.id, this.nome = '', this.cognome = '', this.email, this.relazione, this.stato = 'da_invitare'});

  factory Referente.fromJson(Map<String, dynamic> json) => Referente(
        id: json['id'] as String?,
        nome: (json['nome'] as String?) ?? '',
        cognome: (json['cognome'] as String?) ?? '',
        email: json['email'] as String?,
        relazione: json['relazione'] as String?,
        stato: (json['stato'] as String?) ?? 'da_invitare',
      );

  Map<String, dynamic> toJson() => {'nome': nome.trim(), 'cognome': cognome.trim(), 'email': (email ?? '').trim().isEmpty ? null : email!.trim(), 'relazione': (relazione ?? '').trim().isEmpty ? null : relazione!.trim()};

  String get nomeCompleto => '$nome $cognome'.trim();

  /// Legenda della scheda iscritti: ● collegato, ◐ invitato, ○ da invitare, ! senza email.
  String get statoLabel => switch (stato) {
        'collegato' => 'collegato',
        'invitato' => 'invito inviato',
        'rifiutato' => 'ha rifiutato',
        'non_sono_io' => 'dice "non sono io": controlla l\'email',
        'revocato' => 'collegamento interrotto',
        _ => email == null ? 'manca l\'email' : 'da invitare',
      };
}

class Iscritto {
  final String id;
  final String nome;
  final String cognome;
  final DateTime? dataNascita;
  final List<({String id, String nome, bool archiviato})> gruppi;
  final List<Referente> referenti;

  Iscritto({required this.id, required this.nome, required this.cognome, this.dataNascita, this.gruppi = const [], this.referenti = const []});

  factory Iscritto.fromJson(Map<String, dynamic> json) => Iscritto(
        id: json['id'] as String,
        nome: json['nome'] as String,
        cognome: json['cognome'] as String,
        dataNascita: json['dataNascita'] is String ? DateTime.tryParse(json['dataNascita'] as String) : null,
        gruppi: (json['gruppi'] as List? ?? []).map((g) => (id: g['id'] as String, nome: g['nome'] as String, archiviato: g['archiviato'] == true)).toList(),
        referenti: (json['referenti'] as List? ?? []).map((r) => Referente.fromJson(r as Map<String, dynamic>)).toList(),
      );

  String get nomeCompleto => '$nome $cognome';
}

class TotaliIscritti {
  final int iscritti;
  final int referenti;
  final int referentiCollegati;
  final int iscrittiSenzaEmail;

  const TotaliIscritti({this.iscritti = 0, this.referenti = 0, this.referentiCollegati = 0, this.iscrittiSenzaEmail = 0});

  factory TotaliIscritti.fromJson(Map<String, dynamic> json) => TotaliIscritti(
        iscritti: (json['iscritti'] as num?)?.toInt() ?? 0,
        referenti: (json['referenti'] as num?)?.toInt() ?? 0,
        referentiCollegati: (json['referentiCollegati'] as num?)?.toInt() ?? 0,
        iscrittiSenzaEmail: (json['iscrittiSenzaEmail'] as num?)?.toInt() ?? 0,
      );
}
