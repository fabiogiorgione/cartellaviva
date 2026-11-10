/// Persona della famiglia (anche senza account): /api/tenants/{id}/membri_famiglia.
class Persona {
  final String? id;
  String nome;
  String cognome;
  String? sesso;
  DateTime? dataNascita;
  String? relazione;
  String? codiceFiscale;
  final String? userId;

  Persona({this.id, this.nome = '', this.cognome = '', this.sesso, this.dataNascita, this.relazione, this.codiceFiscale, this.userId});

  String get nomeCompleto => [nome, cognome].where((s) => s.trim().isNotEmpty).join(' ');

  factory Persona.fromJson(Map<String, dynamic> json) => Persona(
        id: json['id'] as String?,
        nome: (json['nome'] as String?) ?? '',
        cognome: (json['cognome'] as String?) ?? '',
        sesso: json['sesso'] as String?,
        dataNascita: json['dataNascita'] is String ? DateTime.tryParse(json['dataNascita'] as String) : null,
        relazione: json['relazione'] as String?,
        codiceFiscale: json['codiceFiscale'] as String?,
        userId: json['userId'] as String?,
      );

  Map<String, dynamic> toJson() {
    String? clean(String? s) => (s == null || s.trim().isEmpty) ? null : s.trim();
    final d = dataNascita;
    return {
      'nome': nome.trim(),
      'cognome': cognome.trim(),
      'sesso': sesso,
      'dataNascita': d == null ? null : '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}',
      'relazione': clean(relazione),
      'codiceFiscale': clean(codiceFiscale)?.toUpperCase(),
    };
  }
}
