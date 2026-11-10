/// Valore dei filtri per "senza data", "senza mittente", "senza tag".
const filtroNessuno = '__nessuno__';

/// Criteri con cui filtrare o raggruppare i documenti.
enum DimensioneFiltro {
  anno('Anno', 'anno'),
  emittente('Mittente', 'emittente'),
  tag('Tag', 'tag'),
  categoria('Categoria', 'categoriaId'),
  persona('Riguarda', 'membroId'),
  tipo('Tipo di file', 'tipo');

  final String titolo;

  /// Nome del parametro nelle richieste all'API.
  final String parametro;

  const DimensioneFiltro(this.titolo, this.parametro);

  /// Quelle con cui si può raggruppare, nell'ordine del menu.
  static const raggruppabili = [emittente, anno, categoria, tag];
}

/// Un valore di un filtro con il numero di documenti ("Enel Energia", 12).
class VoceFaccetta {
  final String valore;
  final String etichetta;
  final int n;

  const VoceFaccetta({required this.valore, required this.etichetta, required this.n});

  factory VoceFaccetta.fromJson(Map<String, dynamic> j) => VoceFaccetta(valore: j['valore'] as String, etichetta: j['etichetta'] as String, n: (j['n'] as num).toInt());
}

/// Conteggi per ogni filtro (GET .../documenti/faccette).
class Faccette {
  final int totale;
  final Map<DimensioneFiltro, List<VoceFaccetta>> voci;

  const Faccette({required this.totale, required this.voci});

  factory Faccette.fromJson(Map<String, dynamic> j) {
    List<VoceFaccetta> elenco(String chiave) => [for (final v in (j[chiave] as List? ?? const [])) VoceFaccetta.fromJson(Map<String, dynamic>.from(v as Map))];
    return Faccette(totale: (j['totale'] as num?)?.toInt() ?? 0, voci: {
      DimensioneFiltro.anno: elenco('anni'),
      DimensioneFiltro.emittente: elenco('emittenti'),
      DimensioneFiltro.tag: elenco('tag'),
      DimensioneFiltro.categoria: elenco('categorie'),
      DimensioneFiltro.persona: elenco('persone'),
      DimensioneFiltro.tipo: elenco('tipi'),
    });
  }
}
