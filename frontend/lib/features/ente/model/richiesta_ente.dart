/// Richieste dell'ente: avanzamento, destinatari, modelli, documenti in scadenza.
class TotaliRichiesta {
  final int iscritti;
  final int completate;
  final int daVerificare;
  final int rifiutate;
  final int mancanti;
  final int nonRaggiunti;

  const TotaliRichiesta({this.iscritti = 0, this.completate = 0, this.daVerificare = 0, this.rifiutate = 0, this.mancanti = 0, this.nonRaggiunti = 0});

  factory TotaliRichiesta.fromJson(Map<String, dynamic> json) => TotaliRichiesta(
        iscritti: (json['iscritti'] as num?)?.toInt() ?? 0,
        completate: (json['completate'] as num?)?.toInt() ?? 0,
        daVerificare: (json['daVerificare'] as num?)?.toInt() ?? 0,
        rifiutate: (json['rifiutate'] as num?)?.toInt() ?? 0,
        mancanti: (json['mancanti'] as num?)?.toInt() ?? 0,
        nonRaggiunti: (json['nonRaggiunti'] as num?)?.toInt() ?? 0,
      );

  /// Iscritti raggiunti dalla richiesta (esclusi quelli senza referenti collegati).
  int get raggiunti => iscritti - nonRaggiunti;
}

/// Una famiglia (destinatario) per un iscritto, nell'avanzamento.
class DestinatarioEnte {
  final String id;
  final String stato;
  final bool scaduta;
  final List<({String id, String nome, bool firmato})> referenti;
  final String? messaggioFamiglia;
  final String? motivoEnte;
  final DateTime? vistaIl;
  final DateTime? rispostaIl;
  final bool haFile;
  final bool viaLink;
  final DateTime? validoFino;
  final bool fileRimosso;

  DestinatarioEnte({
    required this.id,
    required this.stato,
    this.scaduta = false,
    this.referenti = const [],
    this.messaggioFamiglia,
    this.motivoEnte,
    this.vistaIl,
    this.rispostaIl,
    this.haFile = false,
    this.viaLink = false,
    this.validoFino,
    this.fileRimosso = false,
  });

  factory DestinatarioEnte.fromJson(Map<String, dynamic> json) => DestinatarioEnte(
        id: json['id'] as String,
        stato: json['stato'] as String,
        scaduta: json['scaduta'] == true,
        referenti: (json['referenti'] as List? ?? []).map((r) => (id: r['id'] as String, nome: r['nome'] as String, firmato: r['firmato'] == true)).toList(),
        messaggioFamiglia: json['messaggioFamiglia'] as String?,
        motivoEnte: json['motivoEnte'] as String?,
        vistaIl: json['vistaIl'] is String ? DateTime.tryParse(json['vistaIl'] as String)?.toLocal() : null,
        rispostaIl: json['rispostaIl'] is String ? DateTime.tryParse(json['rispostaIl'] as String)?.toLocal() : null,
        haFile: json['haFile'] == true,
        viaLink: json['viaLink'] == true,
        validoFino: json['validoFino'] is String ? DateTime.tryParse(json['validoFino'] as String) : null,
        fileRimosso: json['fileRimossoIl'] != null,
      );

  String get statoLabel => switch (stato) {
        'inviata' => 'non aperta',
        'vista' => 'aperta, non risposta',
        'da_verificare' => 'da verificare',
        'completata' => 'completata',
        'da_rifirmare' => 'da rifirmare',
        'rifiutata' => 'non firma',
        'annullata' => 'non necessaria',
        _ => stato,
      };
}

class RigaAvanzamento {
  final String iscrittoId;
  final String iscrittoNome;
  final String stato;
  final List<DestinatarioEnte> destinatari;

  RigaAvanzamento({required this.iscrittoId, required this.iscrittoNome, required this.stato, this.destinatari = const []});

  factory RigaAvanzamento.fromJson(Map<String, dynamic> json) => RigaAvanzamento(
        iscrittoId: (json['iscritto'] as Map)['id'] as String,
        iscrittoNome: (json['iscritto'] as Map)['nome'] as String,
        stato: json['stato'] as String,
        destinatari: (json['destinatari'] as List? ?? []).map((d) => DestinatarioEnte.fromJson(d as Map<String, dynamic>)).toList(),
      );
}

/// Richiesta dell'ente (elenco e dettaglio).
class RichiestaEnte {
  final String id;
  final String tipo;
  final String titolo;
  final String? istruzioni;
  final DateTime? entroIl;
  final String firmatari;
  final String? gruppoNome;
  final String? allegatoNome;
  final String? creataDa;
  final DateTime? createdAt;
  final bool annullata;
  final String? modelloNome;
  final bool rinnovo;
  final bool chiediValidita;
  /// 'cartacea' o 'codice' (firma con codice email, FES).
  final String modalitaFirma;
  final TotaliRichiesta totali;
  final List<RigaAvanzamento> avanzamento;
  final List<String> nonRaggiunti;

  RichiestaEnte({
    required this.id,
    required this.tipo,
    required this.titolo,
    this.istruzioni,
    this.entroIl,
    this.firmatari = 'tutti',
    this.gruppoNome,
    this.allegatoNome,
    this.creataDa,
    this.createdAt,
    this.annullata = false,
    this.modelloNome,
    this.rinnovo = false,
    this.chiediValidita = false,
    this.modalitaFirma = 'cartacea',
    this.totali = const TotaliRichiesta(),
    this.avanzamento = const [],
    this.nonRaggiunti = const [],
  });

  factory RichiestaEnte.fromJson(Map<String, dynamic> json) => RichiestaEnte(
        id: json['id'] as String,
        tipo: json['tipo'] as String,
        titolo: json['titolo'] as String,
        istruzioni: json['istruzioni'] as String?,
        entroIl: json['entroIl'] is String ? DateTime.tryParse(json['entroIl'] as String) : null,
        firmatari: (json['firmatari'] as String?) ?? 'tutti',
        gruppoNome: (json['gruppo'] as Map?)?['nome'] as String?,
        allegatoNome: (json['allegato'] as Map?)?['nome'] as String?,
        creataDa: json['creataDa'] as String?,
        createdAt: json['createdAt'] is String ? DateTime.tryParse(json['createdAt'] as String)?.toLocal() : null,
        annullata: json['annullata'] == true,
        modelloNome: (json['modello'] as Map?)?['nome'] as String?,
        rinnovo: json['rinnovo'] == true,
        chiediValidita: json['chiediValidita'] == true,
        modalitaFirma: (json['modalitaFirma'] as String?) ?? 'cartacea',
        totali: TotaliRichiesta.fromJson(Map<String, dynamic>.from(json['totali'] as Map? ?? {})),
        avanzamento: (json['avanzamento'] as List? ?? []).map((r) => RigaAvanzamento.fromJson(r as Map<String, dynamic>)).toList(),
        nonRaggiunti: (json['nonRaggiunti'] as List? ?? []).cast<String>(),
      );
}

/// Modello riusabile ("Modelli e regole").
class ModelloRichiesta {
  final String id;
  final String nome;
  final String tipo;
  final String titolo;
  final String? istruzioni;
  final String firmatari;
  final String? allegatoNome;
  final bool chiediValidita;
  final String modalitaFirma;
  final int? rinnovoGiorni;
  final int? conservazioneMesi;
  final int giorniRisposta;
  final int? ricorrenzaGiorno;
  final int? ricorrenzaMese;
  final DateTime? prossimoInvio;

  ModelloRichiesta({
    required this.id,
    required this.nome,
    required this.tipo,
    required this.titolo,
    this.istruzioni,
    this.firmatari = 'tutti',
    this.allegatoNome,
    this.chiediValidita = false,
    this.modalitaFirma = 'cartacea',
    this.rinnovoGiorni,
    this.conservazioneMesi,
    this.giorniRisposta = 14,
    this.ricorrenzaGiorno,
    this.ricorrenzaMese,
    this.prossimoInvio,
  });

  factory ModelloRichiesta.fromJson(Map<String, dynamic> json) => ModelloRichiesta(
        id: json['id'] as String,
        nome: json['nome'] as String,
        tipo: json['tipo'] as String,
        titolo: json['titolo'] as String,
        istruzioni: json['istruzioni'] as String?,
        firmatari: (json['firmatari'] as String?) ?? 'tutti',
        allegatoNome: (json['allegato'] as Map?)?['nome'] as String?,
        chiediValidita: json['chiediValidita'] == true,
        modalitaFirma: (json['modalitaFirma'] as String?) ?? 'cartacea',
        rinnovoGiorni: (json['rinnovoGiorni'] as num?)?.toInt(),
        conservazioneMesi: (json['conservazioneMesi'] as num?)?.toInt(),
        giorniRisposta: (json['giorniRisposta'] as num?)?.toInt() ?? 14,
        ricorrenzaGiorno: (json['ricorrenzaGiorno'] as num?)?.toInt(),
        ricorrenzaMese: (json['ricorrenzaMese'] as num?)?.toInt(),
        prossimoInvio: json['prossimoInvio'] is String ? DateTime.tryParse(json['prossimoInvio'] as String) : null,
      );

  /// "Rinnovo 30 gg prima · conservazione 12 mesi · ogni anno il 15/9".
  String get regole => [
        if (tipo == 'firma' && modalitaFirma == 'codice') 'firma con codice email',
        if (chiediValidita) 'chiede la validità',
        if (rinnovoGiorni != null) 'rinnovo $rinnovoGiorni gg prima della scadenza',
        if (conservazioneMesi != null) 'conservazione $conservazioneMesi mesi',
        if (ricorrenzaGiorno != null && ricorrenzaMese != null) 'ogni anno il $ricorrenzaGiorno/$ricorrenzaMese a tutto l\'ente',
      ].join(' · ');
}

/// Documento consegnato che sta per scadere (o è scaduto).
class DocumentoInScadenza {
  final String iscrittoNome;
  final String richiestaId;
  final String titolo;
  final DateTime? validoFino;
  final bool scaduto;
  final bool rinnovoAutomatico;
  final bool rinnovato;

  DocumentoInScadenza({required this.iscrittoNome, required this.richiestaId, required this.titolo, this.validoFino, this.scaduto = false, this.rinnovoAutomatico = false, this.rinnovato = false});

  factory DocumentoInScadenza.fromJson(Map<String, dynamic> json) => DocumentoInScadenza(
        iscrittoNome: (json['iscritto'] as Map)['nome'] as String,
        richiestaId: json['richiestaId'] as String,
        titolo: json['titolo'] as String,
        validoFino: json['validoFino'] is String ? DateTime.tryParse(json['validoFino'] as String) : null,
        scaduto: json['scaduto'] == true,
        rinnovoAutomatico: json['rinnovoAutomatico'] == true,
        rinnovato: json['rinnovataIl'] != null,
      );
}

/// Anteprima dell'invio (chi verrebbe raggiunto).
class AnteprimaInvio {
  final int iscritti;
  final int referenti;
  final int referentiCollegati;
  final int famiglie;

  /// Referenti senza collegamento che riceveranno il link sicuro.
  final int viaLink;
  final List<String> nonRaggiunti;

  AnteprimaInvio({this.iscritti = 0, this.referenti = 0, this.referentiCollegati = 0, this.famiglie = 0, this.viaLink = 0, this.nonRaggiunti = const []});

  factory AnteprimaInvio.fromJson(Map<String, dynamic> json) => AnteprimaInvio(
        iscritti: (json['iscritti'] as num?)?.toInt() ?? 0,
        referenti: (json['referenti'] as num?)?.toInt() ?? 0,
        referentiCollegati: (json['referentiCollegati'] as num?)?.toInt() ?? 0,
        famiglie: (json['famiglie'] as num?)?.toInt() ?? 0,
        viaLink: (json['viaLink'] as num?)?.toInt() ?? 0,
        nonRaggiunti: (json['nonRaggiunti'] as List? ?? []).cast<String>(),
      );
}
