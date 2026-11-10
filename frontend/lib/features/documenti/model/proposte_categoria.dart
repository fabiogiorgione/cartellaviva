import 'package:cartellaviva/features/documenti/model/documento.dart';

/// Risposta del backend per un documento (POST .../proposta-categoria).
class PropostaCategoria {
  /// 'esistente' | 'nuova' | 'nessuna'.
  final String tipo;
  final String? categoriaId;
  final String? nome;

  /// Solo per 'nuova', alla prima proposta di quel nome (null se riusata).
  final bool? sensibile;
  final String? icona;
  final String? motivo;

  /// 'testo' (dal contenuto) o 'nome' (solo da titolo e nome file: meno affidabile).
  final String fonte;

  const PropostaCategoria({required this.tipo, this.categoriaId, this.nome, this.sensibile, this.icona, this.motivo, this.fonte = 'testo'});

  factory PropostaCategoria.fromJson(Map<String, dynamic> j) => PropostaCategoria(
        tipo: j['tipo'] as String? ?? 'nessuna',
        categoriaId: j['categoriaId'] as String?,
        nome: j['nome'] as String?,
        sensibile: j['sensibile'] as bool?,
        icona: j['icona'] as String?,
        motivo: j['motivo'] as String?,
        fonte: j['fonte'] as String? ?? 'testo',
      );

  bool get dalNome => fonte == 'nome';
}

/// Categoria nuova suggerita dall'AI: si crea solo se [crea] resta spuntato.
/// Nome, icona, colore e sensibilità si possono cambiare prima di crearla.
class NuovaCategoriaSuggerita {
  /// Chiave stabile (nome normalizzato alla prima proposta): non cambia se il nome si modifica.
  final String chiave;
  String nome;
  String icona;
  String colore;
  bool sensibile;
  bool crea = true;

  NuovaCategoriaSuggerita({required this.chiave, required this.nome, required this.icona, required this.colore, required this.sensibile});
}

/// Una riga del wizard: il documento, la proposta (quando arriva) e la destinazione scelta.
class RigaProposta {
  final Documento documento;
  PropostaCategoria? proposta;
  String? errore;

  /// `c:` + id della categoria, `n:` + chiave della categoria nuova, null = non spostare.
  String? destinazione;

  RigaProposta(this.documento);

  bool get analizzata => proposta != null || errore != null;
}

/// Stato del wizard "Proponi categoria con l'AI": proposte arrivate, categorie
/// nuove raggruppate per nome, scelte dell'utente e piano da applicare.
class ProposteCategorie {
  static const max = 50;
  static const colori = ['#275ac5', '#0f5c4c', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#64748b'];

  final List<Categoria> categorie;
  final bool puoCreareCategorie;
  final List<RigaProposta> righe;

  /// Per chiave, nell'ordine in cui sono state proposte.
  final Map<String, NuovaCategoriaSuggerita> nuove = {};

  ProposteCategorie(List<Documento> documenti, this.categorie, {required this.puoCreareCategorie}) : righe = [for (final d in documenti) RigaProposta(d)];

  static String normalizza(String nome) => nome.trim().toLowerCase().replaceAll(RegExp(r'\s+'), ' ');

  /// Nomi da mandare al backend con la richiesta successiva: documenti simili
  /// finiscono nella stessa categoria nuova.
  List<String> get nomiNuove => [for (final n in nuove.values) n.nome];

  int get analizzate => righe.where((r) => r.analizzata).length;

  Categoria? categoria(String? id) => categorie.where((c) => c.id == id).firstOrNull;

  void registra(RigaProposta riga, PropostaCategoria p) {
    riga.proposta = p;
    riga.destinazione = null;
    switch (p.tipo) {
      case 'esistente':
        if (categoria(p.categoriaId) != null && p.categoriaId != riga.documento.categoria?.id) riga.destinazione = 'c:${p.categoriaId}';
      case 'nuova':
        final nome = p.nome?.trim() ?? '';
        if (nome.isEmpty || !puoCreareCategorie) break;
        // Il backend non ripropone le categorie esistenti; per sicurezza, a parità di nome, si usa quella.
        final esistente = categorie.where((c) => normalizza(c.nome) == normalizza(nome)).firstOrNull;
        if (esistente != null) {
          if (esistente.id != riga.documento.categoria?.id) riga.destinazione = 'c:${esistente.id}';
          break;
        }
        final chiave = normalizza(nome);
        final gia = nuove.values.where((n) => n.chiave == chiave || normalizza(n.nome) == chiave).firstOrNull;
        if (gia != null) {
          riga.destinazione = 'n:${gia.chiave}';
          break;
        }
        nuove[chiave] = NuovaCategoriaSuggerita(
          chiave: chiave,
          nome: nome,
          icona: p.icona ?? 'folder',
          colore: colori[nuove.length % colori.length],
          sensibile: p.sensibile ?? false,
        );
        riga.destinazione = 'n:$chiave';
    }
  }

  void errore(RigaProposta riga, String messaggio) => riga.errore = messaggio;

  /// Documenti che finirebbero in questa categoria nuova.
  int documentiPer(NuovaCategoriaSuggerita n) => righe.where((r) => r.destinazione == 'n:${n.chiave}').length;

  /// Togliere "Crea" a una categoria nuova: i suoi documenti restano dove sono.
  void cambiaCrea(NuovaCategoriaSuggerita n, bool crea) {
    n.crea = crea;
    if (!crea) {
      for (final r in righe.where((r) => r.destinazione == 'n:${n.chiave}')) {
        r.destinazione = null;
      }
    }
  }

  /// La destinazione è sensibile (esistente o nuova)?
  bool sensibile(String? destinazione) {
    if (destinazione == null) return false;
    if (destinazione.startsWith('c:')) return categoria(destinazione.substring(2))?.isSensibile ?? false;
    return nuove[destinazione.substring(2)]?.sensibile ?? false;
  }

  /// Categorie nuove da creare davvero: spuntate e con almeno un documento.
  List<NuovaCategoriaSuggerita> get daCreare => [for (final n in nuove.values) if (n.crea && n.nome.trim().isNotEmpty && documentiPer(n) > 0) n];

  /// Documenti da spostare, raggruppati per destinazione.
  Map<String, List<String>> get spostamenti {
    final creare = {for (final n in daCreare) 'n:${n.chiave}'};
    final gruppi = <String, List<String>>{};
    for (final r in righe) {
      final d = r.destinazione;
      if (d == null || (d.startsWith('n:') && !creare.contains(d))) continue;
      if (d == 'c:${r.documento.categoria?.id}') continue;
      (gruppi[d] ??= []).add(r.documento.id);
    }
    return gruppi;
  }

  int get daSpostare => spostamenti.values.fold(0, (n, ids) => n + ids.length);

  /// Documenti oggi in chiaro che verranno cifrati perché vanno in una categoria sensibile.
  int get daCifrare {
    final ids = {for (final e in spostamenti.entries) if (sensibile(e.key)) ...e.value};
    return righe.where((r) => ids.contains(r.documento.id) && !r.documento.cifrato).length;
  }

  /// Due categorie nuove con lo stesso nome, o con il nome di una esistente, non si possono creare.
  String? get problemaNomi {
    final visti = {for (final c in categorie) normalizza(c.nome)};
    for (final n in daCreare) {
      if (!visti.add(normalizza(n.nome))) return 'Il nome "${n.nome.trim()}" è già usato da un\'altra categoria.';
    }
    return null;
  }

  String get riepilogo {
    final parti = <String>[
      if (daCreare.isNotEmpty) 'crea ${daCreare.length} ${daCreare.length == 1 ? 'categoria' : 'categorie'}',
      'sposta $daSpostare ${daSpostare == 1 ? 'documento' : 'documenti'}',
    ];
    final testo = parti.join(', ');
    final cifra = daCifrare;
    return '${testo[0].toUpperCase()}${testo.substring(1)}'
        '${cifra == 0 ? '' : ' ($cifra ${cifra == 1 ? 'verrà cifrato perché finisce' : 'verranno cifrati perché finiscono'} in una categoria sensibile)'}.';
  }
}
