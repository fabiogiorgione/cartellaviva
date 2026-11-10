/// Referente dell'iscritto (come l'ha inserito l'ente) e se risulta firmatario.
class RichiestaReferente {
  final String id;
  final String nome;
  final bool firmato;

  /// È il referente dell'utente corrente (può firmare con codice per sé).
  final bool tuo;

  RichiestaReferente({required this.id, required this.nome, this.firmato = false, this.tuo = false});

  factory RichiestaReferente.fromJson(Map<String, dynamic> json) =>
      RichiestaReferente(id: json['id'] as String, nome: (json['nome'] as String?) ?? '', firmato: json['firmato'] == true, tuo: json['tuo'] == true);
}

String tipoRichiestaLabel(String tipo) => switch (tipo) {
      'firma' => 'Firma',
      'invio' => 'Invio documento',
      'presa_visione' => 'Presa visione',
      _ => tipo,
    };

/// Richiesta di un ente come la vede la famiglia (/api/tenants/{id}/richieste).
class RichiestaFamiglia {
  final String id;
  final String enteId;
  final String enteNome;
  final String tipo;
  final String titolo;
  final String iscritto;
  final DateTime? entroIl;
  final String stato;
  final bool scaduta;
  final String firmatari;
  final List<RichiestaReferente> referenti;
  final String? motivoEnte;
  final DateTime? createdAt;

  /// L'ente chiede fino a quando vale il documento (es. certificato medico).
  final bool chiediValidita;

  /// "cartacea" (stampa e scansiona) o "codice" (firma elettronica con codice email).
  final String modalitaFirma;

  bool get firmaConCodice => tipo == 'firma' && modalitaFirma == 'codice';

  /// Il mio referente, se devo ancora firmare con codice.
  RichiestaReferente? get daFirmareIo => referenti.where((r) => r.tuo && !r.firmato).firstOrNull;
  final DateTime? validoFino;
  // Solo nel dettaglio
  final String? istruzioni;
  final String? allegatoNome;
  final int? allegatoDimensione;
  final String? messaggioFamiglia;
  final DateTime? rispostaIl;
  final String? rispostaDa;
  final String? documentoId;

  RichiestaFamiglia({
    required this.id,
    required this.enteId,
    required this.enteNome,
    required this.tipo,
    required this.titolo,
    required this.iscritto,
    this.entroIl,
    required this.stato,
    this.scaduta = false,
    this.firmatari = 'tutti',
    this.referenti = const [],
    this.motivoEnte,
    this.createdAt,
    this.chiediValidita = false,
    this.modalitaFirma = 'cartacea',
    this.validoFino,
    this.istruzioni,
    this.allegatoNome,
    this.allegatoDimensione,
    this.messaggioFamiglia,
    this.rispostaIl,
    this.rispostaDa,
    this.documentoId,
  });

  factory RichiestaFamiglia.fromJson(Map<String, dynamic> json) {
    final ente = json['ente'] as Map<String, dynamic>;
    final allegato = json['allegato'] as Map<String, dynamic>?;
    DateTime? date(String key) => json[key] is String ? DateTime.tryParse(json[key] as String)?.toLocal() : null;
    return RichiestaFamiglia(
      id: json['id'] as String,
      enteId: ente['id'] as String,
      enteNome: ente['nome'] as String,
      tipo: json['tipo'] as String,
      titolo: json['titolo'] as String,
      iscritto: (json['iscritto'] as String?) ?? '',
      entroIl: json['entroIl'] is String ? DateTime.tryParse(json['entroIl'] as String) : null,
      stato: json['stato'] as String,
      scaduta: json['scaduta'] == true,
      firmatari: (json['firmatari'] as String?) ?? 'tutti',
      referenti: (json['referenti'] as List? ?? []).map((r) => RichiestaReferente.fromJson(r as Map<String, dynamic>)).toList(),
      motivoEnte: json['motivoEnte'] as String?,
      createdAt: date('createdAt'),
      chiediValidita: json['chiediValidita'] == true,
      modalitaFirma: (json['modalitaFirma'] as String?) ?? 'cartacea',
      validoFino: json['validoFino'] is String ? DateTime.tryParse(json['validoFino'] as String) : null,
      istruzioni: json['istruzioni'] as String?,
      allegatoNome: allegato?['nome'] as String?,
      allegatoDimensione: (allegato?['dimensione'] as num?)?.toInt(),
      messaggioFamiglia: json['messaggioFamiglia'] as String?,
      rispostaIl: date('rispostaIl'),
      rispostaDa: json['rispostaDa'] as String?,
      documentoId: json['documentoId'] as String?,
    );
  }

  bool get daFare => const ['inviata', 'vista', 'da_rifirmare'].contains(stato);

  bool get inVerifica => stato == 'da_verificare';

  String get tipoLabel => tipoRichiestaLabel(tipo);

  /// "Da firmare", "Da rifirmare", "Da inviare", "In verifica"...
  String get statoLabel => switch (stato) {
        'da_rifirmare' => 'Da rifirmare',
        'da_verificare' => 'In verifica',
        'completata' => 'Completata',
        'rifiutata' => 'Non firmata',
        'annullata' => 'Annullata',
        _ => switch (tipo) {
            'firma' => 'Da firmare',
            'invio' => 'Da inviare',
            _ => 'Da leggere',
          },
      };
}

/// Collegamento della famiglia con un ente.
class Collegamento {
  final String id;
  final String enteId;
  final String enteNome;
  final String iscritto;
  final String referente;
  final String? personaNome;
  final bool attivo;
  final DateTime? accettatoIl;

  Collegamento({required this.id, required this.enteId, required this.enteNome, required this.iscritto, required this.referente, this.personaNome, this.attivo = true, this.accettatoIl});

  factory Collegamento.fromJson(Map<String, dynamic> json) => Collegamento(
        id: json['id'] as String,
        enteId: (json['ente'] as Map)['id'] as String,
        enteNome: (json['ente'] as Map)['nome'] as String,
        iscritto: (json['iscritto'] as String?) ?? '',
        referente: (json['referente'] as String?) ?? '',
        personaNome: (json['persona'] as Map?)?['nome'] as String?,
        attivo: json['attivo'] == true,
        accettatoIl: json['accettatoIl'] is String ? DateTime.tryParse(json['accettatoIl'] as String)?.toLocal() : null,
      );
}

/// Ciò che mostra il link d'invito di un ente.
class InvitoEnte {
  final String enteNome;
  final String? enteTipo;
  final bool verificato;
  final String iscrittoNome;
  final String iscrittoCognome;
  final List<String> gruppi;
  final String? anno;
  final String referenteNome;
  final String? referenteEmail;

  InvitoEnte({
    required this.enteNome,
    this.enteTipo,
    this.verificato = false,
    required this.iscrittoNome,
    required this.iscrittoCognome,
    this.gruppi = const [],
    this.anno,
    required this.referenteNome,
    this.referenteEmail,
  });

  factory InvitoEnte.fromJson(Map<String, dynamic> json) {
    final ente = json['ente'] as Map<String, dynamic>;
    final iscritto = json['iscritto'] as Map<String, dynamic>;
    final ref = json['referente'] as Map<String, dynamic>;
    return InvitoEnte(
      enteNome: ente['nome'] as String,
      enteTipo: ente['tipo'] as String?,
      verificato: ente['verificato'] == true,
      iscrittoNome: iscritto['nome'] as String,
      iscrittoCognome: iscritto['cognome'] as String,
      gruppi: (json['gruppi'] as List? ?? []).cast<String>(),
      anno: json['anno'] as String?,
      referenteNome: '${ref['nome']} ${ref['cognome']}'.trim(),
      referenteEmail: ref['email'] as String?,
    );
  }
}
