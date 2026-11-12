import 'package:cartellaviva/features/pratiche/model/pagamento.dart' show PropostaPagamento, PagamentoCollegato;

/// Categoria dei documenti (di sistema o del tenant).
class Categoria {
  final String id;
  final String nome;
  final String icona;
  final String colore;
  final bool isSensibile;
  final bool sistema;

  /// Documenti della famiglia in questa categoria (solo nell'elenco; null se non visibile).
  final int? documenti;

  Categoria({required this.id, required this.nome, this.icona = 'file-text', this.colore = '#64748b', this.isSensibile = false, this.sistema = false, this.documenti});

  factory Categoria.fromJson(Map<String, dynamic> json) => Categoria(
        id: json['id'] as String,
        nome: json['nome'] as String,
        icona: (json['icona'] as String?) ?? 'file-text',
        colore: (json['colore'] as String?) ?? '#64748b',
        isSensibile: json['isSensibile'] == true,
        sistema: json['sistema'] == true,
        documenti: (json['documenti'] as num?)?.toInt(),
      );
}

/// Membro della famiglia (anche senza account), a cui si può associare un documento.
class MembroFamiglia {
  final String id;
  final String nome;
  final String? relazione;

  MembroFamiglia({required this.id, required this.nome, this.relazione});

  factory MembroFamiglia.fromJson(Map<String, dynamic> json) {
    final nome = [json['nome'], json['cognome']].whereType<String>().where((s) => s.isNotEmpty).join(' ');
    return MembroFamiglia(id: json['id'] as String, nome: nome.isEmpty ? 'Senza nome' : nome, relazione: json['relazione'] as String?);
  }
}

/// Documento come restituito dall'API (dettaglio o risposta al caricamento).
class Documento {
  final String id;
  final String titolo;
  final Categoria? categoria;
  final List<MembroFamiglia> membri;
  final String? nomeFile;
  final String? mimeType;
  final int dimensioneBytes;
  final bool cifrato;
  final String? ocrStatus;
  final String? ownerId;
  final String? ownerName;
  final DateTime? createdAt;
  final DateTime? dataDocumento;

  /// Solo nel dettaglio: testo letto dall'OCR (decifrato dal server se serve).
  final String? ocrText;

  /// Solo nella risposta al caricamento: documenti con lo stesso contenuto.
  final List<String> possibiliDuplicati;

  /// Solo nel dettaglio: scadenza trovata nel testo, ancora da confermare o ignorare.
  final ScadenzaProposta? scadenzaProposta;

  /// Solo nel dettaglio e per chi gestisce i pagamenti: avviso di pagamento trovato, da confermare.
  final PropostaPagamento? pagamentoProposta;

  /// Solo nel dettaglio: pagamenti in cui il documento è l'avviso o la ricevuta.
  final List<PagamentoCollegato> pagamenti;

  /// Scheda Anteprima: non_serve (si mostra l'originale), in_coda, pronta, fallita.
  final String? anteprimaStato;

  /// Tipo di ciò che si vede (PDF o immagine), anche quando l'originale è un DOCX.
  final String? anteprimaMime;
  final String? anteprimaErrore;

  /// Solo nel dettaglio e solo per i documenti non cifrati: dati trovati nel
  /// testo, tag e riferimenti. Null = non ancora analizzato (o cifrato).
  final IndiceDocumento? indice;

  Documento({
    required this.id,
    required this.titolo,
    this.categoria,
    this.membri = const [],
    this.nomeFile,
    this.mimeType,
    this.dimensioneBytes = 0,
    this.cifrato = false,
    this.ocrStatus,
    this.ownerId,
    this.ownerName,
    this.createdAt,
    this.dataDocumento,
    this.ocrText,
    this.possibiliDuplicati = const [],
    this.scadenzaProposta,
    this.pagamentoProposta,
    this.pagamenti = const [],
    this.anteprimaStato,
    this.anteprimaMime,
    this.anteprimaErrore,
    this.indice,
  });

  bool get isImage => mimeType?.startsWith('image/') == true;

  bool get isPdf => mimeType == 'application/pdf';

  /// Tipo di file in parole (per la lista).
  String get tipoFile => switch (mimeType) {
        'application/pdf' => 'PDF',
        final m? when m.startsWith('image/') => 'Foto',
        final m? when m.contains('spreadsheet') || m.contains('excel') || m.endsWith('apple.numbers') => 'Foglio di calcolo',
        final m? when m.contains('presentation') || m.contains('powerpoint') || m.endsWith('apple.keynote') => 'Presentazione',
        final m? when m.contains('word') || m.contains('opendocument.text') || m.contains('rtf') || m.endsWith('apple.pages') => 'Documento di testo',
        'text/plain' => 'Testo',
        'application/zip' => 'Archivio ZIP',
        _ => 'File',
      };

  factory Documento.fromJson(Map<String, dynamic> json) => Documento(
        id: json['id'] as String,
        titolo: (json['titolo'] as String?) ?? '',
        categoria: json['categoria'] is Map ? Categoria.fromJson(Map<String, dynamic>.from(json['categoria'] as Map)) : null,
        ownerId: json['ownerId'] as String?,
        dataDocumento: json['dataDocumento'] is String ? DateTime.tryParse(json['dataDocumento'] as String) : null,
        ocrText: json['ocrText'] as String?,
        membri: (json['membri'] as List? ?? [])
            .whereType<Map>()
            .map((m) => MembroFamiglia(id: m['id'] as String, nome: (m['nome'] as String?) ?? ''))
            .toList(),
        nomeFile: json['nomeFile'] as String?,
        mimeType: json['mimeType'] as String?,
        dimensioneBytes: (json['dimensioneBytes'] as num?)?.toInt() ?? 0,
        cifrato: json['cifrato'] == true,
        ocrStatus: json['ocrStatus'] as String?,
        ownerName: json['ownerName'] as String?,
        createdAt: json['createdAt'] is String ? DateTime.tryParse(json['createdAt'] as String) : null,
        possibiliDuplicati: (json['possibiliDuplicati'] as List? ?? []).whereType<Map>().map((d) => d['id'] as String).toList(),
        pagamentoProposta: json['pagamentoProposta'] is Map ? PropostaPagamento.fromJson(Map<String, dynamic>.from(json['pagamentoProposta'] as Map)) : null,
        pagamenti: (json['pagamenti'] as List? ?? []).whereType<Map>().map((j) => PagamentoCollegato.fromJson(Map<String, dynamic>.from(j))).toList(),
        scadenzaProposta: json['scadenzaProposta'] is Map ? ScadenzaProposta.fromJson(Map<String, dynamic>.from(json['scadenzaProposta'] as Map)) : null,
        anteprimaStato: json['anteprimaStato'] as String?,
        anteprimaMime: json['anteprimaMime'] as String?,
        anteprimaErrore: json['anteprimaErrore'] as String?,
        indice: json['indice'] is Map ? IndiceDocumento.fromJson(Map<String, dynamic>.from(json['indice'] as Map)) : null,
      );

  String get ocrStatusLabel => switch (ocrStatus) {
        'in_coda' => 'Lettura del testo in corso',
        'completato' => 'Testo letto',
        'fallito' => 'Lettura del testo non riuscita',
        'non_applicabile' => 'Lettura del testo non prevista',
        _ => '-',
      };
}

/// Scadenza trovata nel testo di un documento (OCR + regole o AI locale):
/// diventa una scadenza solo se l'utente la conferma.
class ScadenzaProposta {
  final String documentoId;
  final String documentoTitolo;
  final DateTime data;
  final String? tipo;
  final String titoloSuggerito;
  final List<MembroFamiglia> membri;
  final String? categoriaId;

  ScadenzaProposta({
    required this.documentoId,
    required this.documentoTitolo,
    required this.data,
    this.tipo,
    required this.titoloSuggerito,
    this.membri = const [],
    this.categoriaId,
  });

  factory ScadenzaProposta.fromJson(Map<String, dynamic> json) => ScadenzaProposta(
        documentoId: json['documentoId'] as String,
        documentoTitolo: (json['documentoTitolo'] as String?) ?? '',
        data: DateTime.parse(json['data'] as String),
        tipo: json['tipo'] as String?,
        titoloSuggerito: (json['titoloSuggerito'] as String?) ?? 'Scadenza',
        membri: (json['membri'] as List? ?? []).whereType<Map>().map((m) => MembroFamiglia(id: m['id'] as String, nome: (m['nome'] as String?) ?? '')).toList(),
        categoriaId: json['categoriaId'] as String?,
      );
}
/// Dati trovati automaticamente nel testo di un documento non cifrato (AI
/// locale): ogni valore compare nel testo, ma va comunque controllato.
class IndiceDocumento {
  final String? emittente;
  final DateTime? data;

  /// Importo in euro come scritto dal server ("1287.40").
  final String? importo;
  final String? numero;
  final String? intestatario;
  final List<String> tag;

  /// Codici che legano documenti diversi: targa, POD, polizza, IBAN...
  final List<String> riferimenti;

  IndiceDocumento({this.emittente, this.data, this.importo, this.numero, this.intestatario, this.tag = const [], this.riferimenti = const []});

  factory IndiceDocumento.fromJson(Map<String, dynamic> json) {
    final dati = json['dati'] is Map ? Map<String, dynamic>.from(json['dati'] as Map) : const <String, dynamic>{};
    String? testo(String k) => dati[k] is String && (dati[k] as String).trim().isNotEmpty ? dati[k] as String : null;
    return IndiceDocumento(
      emittente: testo('emittente'),
      data: testo('data') == null ? null : DateTime.tryParse(testo('data')!),
      importo: testo('importo'),
      numero: testo('numero'),
      intestatario: testo('intestatario'),
      tag: (json['tag'] as List? ?? []).whereType<String>().toList(),
      riferimenti: (json['riferimenti'] as List? ?? []).whereType<String>().toList(),
    );
  }

  bool get vuoto => emittente == null && data == null && importo == null && numero == null && intestatario == null && tag.isEmpty && riferimenti.isEmpty;

  /// "1287.40" → "1.287,40 €".
  String? get importoLeggibile {
    final v = double.tryParse(importo ?? '');
    if (v == null) return null;
    final parti = v.toStringAsFixed(2).split('.');
    final intero = parti[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => '.');
    return '$intero,${parti[1]} €';
  }
}

/// Documento simile a un altro: stesso riferimento (targa, POD...) o contenuto vicino.
class DocumentoSimile {
  final String id;
  final String titolo;
  final Categoria? categoria;
  final DateTime? dataDocumento;

  /// riferimento | contenuto
  final String motivo;
  final String? riferimento;

  DocumentoSimile({required this.id, required this.titolo, this.categoria, this.dataDocumento, required this.motivo, this.riferimento});

  factory DocumentoSimile.fromJson(Map<String, dynamic> json) => DocumentoSimile(
        id: json['id'] as String,
        titolo: (json['titolo'] as String?) ?? '',
        categoria: json['categoria'] is Map ? Categoria.fromJson(Map<String, dynamic>.from(json['categoria'] as Map)) : null,
        dataDocumento: json['dataDocumento'] is String ? DateTime.tryParse(json['dataDocumento'] as String) : null,
        motivo: (json['motivo'] as String?) ?? 'contenuto',
        riferimento: json['riferimento'] is String && (json['riferimento'] as String).isNotEmpty ? json['riferimento'] as String : null,
      );

  String get motivoLeggibile => motivo == 'riferimento' && riferimento != null ? 'Stesso codice $riferimento' : 'Contenuto simile';
}
