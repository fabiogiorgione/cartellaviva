import 'package:cartellaviva/features/scadenze/model/scadenza.dart' show formatIsoDate;

/// "180.40" → "180,40 €"; "1287.4" → "1.287,40 €". Vuoto se non è un numero.
String formatEuro(String? importo) {
  final v = double.tryParse(importo ?? '');
  if (v == null) return '';
  final parti = v.toStringAsFixed(2).split('.');
  final intero = parti[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => '.');
  return '$intero,${parti[1]} €';
}

/// "301200004567891234" → "3012 0000 4567 8912 34".
String raggruppaCodiceAvviso(String codice) {
  final cifre = codice.replaceAll(RegExp(r'\s'), '');
  return RegExp('.{1,4}').allMatches(cifre).map((m) => m.group(0)).join(' ');
}

/// Importo scritto dall'utente ("180,40", "1.234,56", "180.40") → "180.40" per l'API; null se non valido.
String? importoPerApi(String testo) {
  var t = testo.trim().replaceAll('€', '').replaceAll(' ', '');
  if (t.isEmpty) return null;
  if (t.contains(',')) {
    t = t.replaceAll('.', '').replaceAll(',', '.');
  } else if (RegExp(r'^\d{1,3}(\.\d{3})+$').hasMatch(t)) {
    t = t.replaceAll('.', '');
  }
  final v = double.tryParse(t);
  if (v == null || v.isNaN || v.isInfinite) return null;
  return v.toStringAsFixed(2);
}

/// Giorni da oggi alla [data] (negativi se passata), contando i giorni di calendario.
int giorniAl(DateTime data, {DateTime? oggi}) {
  final o = oggi ?? DateTime.now();
  return DateTime.utc(data.year, data.month, data.day).difference(DateTime.utc(o.year, o.month, o.day)).inDays;
}

/// "oggi", "domani", "tra 12 giorni", "scaduto ieri", "scaduto da 3 giorni".
String quantoMancaA(int giorni) {
  if (giorni == 0) return 'oggi';
  if (giorni == 1) return 'domani';
  if (giorni > 1) return 'tra $giorni giorni';
  if (giorni == -1) return 'scaduto ieri';
  return 'scaduto da ${-giorni} giorni';
}

DateTime? _data(dynamic v) => v is String && v.isNotEmpty ? DateTime.parse(v) : null;

/// Riferimento a un documento ("Avviso TARI").
class DocumentoRef {
  final String id;
  final String titolo;

  const DocumentoRef({required this.id, required this.titolo});

  static DocumentoRef? from(dynamic json) =>
      json is Map ? DocumentoRef(id: json['id'] as String, titolo: (json['titolo'] as String?) ?? 'Documento') : null;
}

/// Pagamento della famiglia (risposta di /api/tenants/{id}/pagamenti).
class Pagamento {
  final String id;
  final String titolo;
  final String? ente;

  /// Importo come stringa decimale con il punto ("180.40").
  final String importo;
  final DateTime? scadenza;
  final String? codiceAvviso;
  final String? codiceFiscaleEnte;
  final String stato;
  final DateTime? pagatoIl;
  final DocumentoRef? documento;
  final DocumentoRef? ricevuta;
  final String? scadenzaId;
  final String origine;
  final DateTime? createdAt;

  const Pagamento({
    required this.id,
    required this.titolo,
    this.ente,
    required this.importo,
    this.scadenza,
    this.codiceAvviso,
    this.codiceFiscaleEnte,
    this.stato = 'da_pagare',
    this.pagatoIl,
    this.documento,
    this.ricevuta,
    this.scadenzaId,
    this.origine = 'manuale',
    this.createdAt,
  });

  factory Pagamento.fromJson(Map<String, dynamic> json) => Pagamento(
        id: json['id'] as String,
        titolo: (json['titolo'] as String?) ?? 'Pagamento',
        ente: json['ente'] as String?,
        importo: json['importo'].toString(),
        scadenza: _data(json['scadenza']),
        codiceAvviso: json['codiceAvviso'] as String?,
        codiceFiscaleEnte: json['codiceFiscaleEnte'] as String?,
        stato: (json['stato'] as String?) ?? 'da_pagare',
        pagatoIl: _data(json['pagatoIl']),
        documento: DocumentoRef.from(json['documento']),
        ricevuta: DocumentoRef.from(json['ricevuta']),
        scadenzaId: json['scadenzaId'] as String?,
        origine: (json['origine'] as String?) ?? 'manuale',
        createdAt: _data(json['createdAt']),
      );

  bool get pagato => stato == 'pagato';

  String get importoLeggibile => formatEuro(importo);

  int? get giorniMancanti => scadenza == null ? null : giorniAl(scadenza!);
}

/// Risposta di GET /pagamenti.
class ElencoPagamenti {
  final List<Pagamento> pagamenti;
  final String totaleDaPagare;

  const ElencoPagamenti({required this.pagamenti, required this.totaleDaPagare});

  factory ElencoPagamenti.fromJson(Map<String, dynamic> json) => ElencoPagamenti(
        pagamenti: (json['pagamenti'] as List? ?? []).map((j) => Pagamento.fromJson(Map<String, dynamic>.from(j as Map))).toList(),
        totaleDaPagare: (json['totaleDaPagare'] ?? '0.00').toString(),
      );
}

/// Avviso di pagamento letto in un documento, ancora da confermare.
class PropostaPagamento {
  final String documentoId;
  final String documentoTitolo;
  final String? categoriaNome;
  final String titoloSuggerito;
  final String? ente;
  final String? importo;
  final DateTime? scadenza;
  final String? codiceAvviso;
  final String? codiceFiscaleEnte;

  const PropostaPagamento({
    required this.documentoId,
    required this.documentoTitolo,
    this.categoriaNome,
    required this.titoloSuggerito,
    this.ente,
    this.importo,
    this.scadenza,
    this.codiceAvviso,
    this.codiceFiscaleEnte,
  });

  factory PropostaPagamento.fromJson(Map<String, dynamic> json) => PropostaPagamento(
        documentoId: json['documentoId'] as String,
        documentoTitolo: (json['documentoTitolo'] as String?) ?? '',
        categoriaNome: json['categoria'] is Map ? (json['categoria'] as Map)['nome'] as String? : null,
        titoloSuggerito: (json['titoloSuggerito'] as String?) ?? 'Avviso di pagamento',
        ente: json['ente'] as String?,
        importo: json['importo']?.toString(),
        scadenza: _data(json['scadenza']),
        codiceAvviso: json['codiceAvviso'] as String?,
        codiceFiscaleEnte: json['codiceFiscaleEnte'] as String?,
      );

  /// Si può creare subito solo con un importo valido (l'API lo richiede > 0).
  bool get completa => (double.tryParse(importo ?? '') ?? 0) > 0;

  PagamentoInput toInput() => PagamentoInput(
        titolo: titoloSuggerito,
        importo: importo,
        ente: ente,
        scadenza: scadenza,
        codiceAvviso: codiceAvviso,
        codiceFiscaleEnte: codiceFiscaleEnte,
        documentoId: documentoId,
      );
}

/// Dati del modulo (creazione e modifica).
class PagamentoInput {
  String titolo;
  String? importo;
  String? ente;
  DateTime? scadenza;
  String? codiceAvviso;
  String? codiceFiscaleEnte;
  String? documentoId;
  bool promemoria;

  PagamentoInput({
    this.titolo = '',
    this.importo,
    this.ente,
    this.scadenza,
    this.codiceAvviso,
    this.codiceFiscaleEnte,
    this.documentoId,
    this.promemoria = true,
  });

  factory PagamentoInput.from(Pagamento p) => PagamentoInput(
        titolo: p.titolo,
        importo: p.importo,
        ente: p.ente,
        scadenza: p.scadenza,
        codiceAvviso: p.codiceAvviso,
        codiceFiscaleEnte: p.codiceFiscaleEnte,
        documentoId: p.documento?.id,
        promemoria: p.scadenzaId != null,
      );

  static String? _vuotoNull(String? s) => (s ?? '').trim().isEmpty ? null : s!.trim();

  Map<String, dynamic> toJson({bool perModifica = false}) => {
        'titolo': titolo.trim(),
        'importo': importo,
        'ente': _vuotoNull(ente),
        'scadenza': scadenza == null ? null : formatIsoDate(scadenza!),
        'codiceAvviso': _vuotoNull(codiceAvviso)?.replaceAll(RegExp(r'\s'), ''),
        'codiceFiscaleEnte': _vuotoNull(codiceFiscaleEnte)?.replaceAll(RegExp(r'\s'), ''),
        if (!perModifica) 'documentoId': documentoId,
        if (!perModifica) 'promemoria': promemoria,
      };
}

/// Pagamento collegato a un documento, come appare nel dettaglio del documento.
class PagamentoCollegato {
  final String id;
  final String titolo;
  final String stato;

  /// `avviso` (documento d'origine) o `ricevuta`.
  final String ruolo;

  const PagamentoCollegato({required this.id, required this.titolo, required this.stato, required this.ruolo});

  factory PagamentoCollegato.fromJson(Map<String, dynamic> json) => PagamentoCollegato(
        id: json['id'] as String,
        titolo: (json['titolo'] as String?) ?? 'Pagamento',
        stato: (json['stato'] as String?) ?? 'da_pagare',
        ruolo: (json['ruolo'] as String?) ?? 'avviso',
      );

  bool get pagato => stato == 'pagato';
}
