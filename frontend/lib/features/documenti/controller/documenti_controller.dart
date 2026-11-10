import 'dart:async';

import 'package:get/get.dart';
import 'package:cartellaviva/helpers/ultima_richiesta.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/faccette.dart';
import 'package:cartellaviva/features/documenti/view/vista_gruppi.dart';

/// Come si guarda l'archivio: tabella a pagine o gruppi richiudibili.
enum VistaDocumenti { lista, gruppi }

/// Archivio dei documenti della famiglia: ricerca (titolo e testo letto), filtri
/// con i conteggi, ordinamento per colonna, selezione per le operazioni di gruppo.
/// Si guarda come lista a pagine (10/25/50) o a gruppi (per mittente, anno...).
class DocumentiController extends GetxController {
  SessionService get session => SessionService.to;

  static const perPaginaPossibili = [10, 25, 50];
  static const maxPerGruppo = 100;

  bool loading = true;
  String? error;
  List<Documento> documenti = [];
  List<Categoria> categorie = [];
  List<MembroFamiglia> persone = [];

  String query = '';

  /// Filtri attivi: uno per criterio, si combinano tra loro.
  final Map<DimensioneFiltro, String> filtri = {};

  /// Etichette dei filtri scelti, per mostrarli anche se escono dai conteggi.
  final Map<DimensioneFiltro, String> _etichetteScelte = {};
  Faccette? faccette;
  String? erroreFaccette;

  VistaDocumenti vista = VistaDocumenti.lista;
  DimensioneFiltro raggruppaPer = DimensioneFiltro.emittente;

  /// Gruppi aperti, per valore: gli altri non hanno ancora caricato i documenti.
  final Map<String, GruppoDocumenti> gruppiAperti = {};
  int _generazioneGruppi = 0;

  int pagina = 1;
  int perPagina = 25;
  int totale = 0;
  String ordina = 'creato';
  String verso = 'desc';

  /// Ricerca per significato ("bolletta della luce" trova la fattura ENEL):
  /// con una ricerca attiva i risultati vanno in ordine di pertinenza.
  bool perSignificato = false;

  /// Id dei documenti selezionati nella pagina corrente.
  final Set<String> selezionati = {};

  final _ultima = UltimaRichiesta();
  Timer? _debounce;

  String? get tenantId => session.currentTenantId;

  bool get canUpload => session.can('documento:create');

  String? get categoriaId => filtri[DimensioneFiltro.categoria];

  bool get hasFilters => query.trim().isNotEmpty || filtri.isNotEmpty;

  bool get aGruppi => vista == VistaDocumenti.gruppi;

  /// Nessun documento da mostrare con la ricerca e i filtri correnti.
  bool get vuoto => aGruppi ? (faccette?.totale ?? 0) == 0 : documenti.isEmpty;

  Map<String, String> get _parametriFiltri => {for (final e in filtri.entries) e.key.parametro: e.value};

  int get pagine => totale == 0 ? 1 : (totale / perPagina).ceil();

  List<Documento> get documentiSelezionati {
    final visti = <String, Documento>{for (final d in documenti) d.id: d, for (final g in gruppiAperti.values) for (final d in g.righe) d.id: d};
    return visti.values.where((d) => selezionati.contains(d.id)).toList();
  }

  /// Voci di un filtro, con i nomi delle persone; quella scelta c'è sempre.
  List<VoceFaccetta> vociDi(DimensioneFiltro d) {
    final voci = [
      for (final v in faccette?.voci[d] ?? const <VoceFaccetta>[])
        d == DimensioneFiltro.persona ? VoceFaccetta(valore: v.valore, etichetta: persone.where((p) => p.id == v.valore).firstOrNull?.nome ?? 'Persona', n: v.n) : v,
    ];
    final scelto = filtri[d];
    if (scelto != null && voci.every((v) => v.valore != scelto)) voci.insert(0, VoceFaccetta(valore: scelto, etichetta: etichettaFiltro(d), n: 0));
    return voci;
  }

  /// Nome leggibile del filtro attivo.
  String etichettaFiltro(DimensioneFiltro d) {
    final valore = filtri[d];
    final dai = faccette?.voci[d]?.where((v) => v.valore == valore).firstOrNull?.etichetta;
    return switch (d) {
      DimensioneFiltro.persona => persone.where((p) => p.id == valore).firstOrNull?.nome ?? 'Persona',
      DimensioneFiltro.categoria => dai ?? categorie.where((c) => c.id == valore).firstOrNull?.nome ?? 'Categoria',
      _ => dai ?? _etichetteScelte[d] ?? valore ?? '',
    };
  }

  /// I gruppi da mostrare: con un filtro sullo stesso criterio, solo quello.
  List<VoceFaccetta> get gruppi {
    final scelto = filtri[raggruppaPer];
    return vociDi(raggruppaPer).where((v) => scelto == null || v.valore == scelto).toList();
  }

  @override
  void onInit() {
    super.onInit();
    // Da Documenti → Categorie: /documenti?categoria=<id> apre la lista già filtrata.
    final categoria = Get.parameters['categoria'];
    if (categoria != null) filtri[DimensioneFiltro.categoria] = categoria;
    load();
  }

  @override
  void onClose() {
    _debounce?.cancel();
    super.onClose();
  }

  Future<void> load() async {
    final id = tenantId;
    if (id == null) {
      loading = false;
      error = 'Seleziona una famiglia.';
      update();
      return;
    }
    loading = true;
    error = null;
    update();
    try {
      final r = await _ultima.esegui(() async {
        if (categorie.isEmpty) {
          final opzioni = await Future.wait([DocumentoApi.categorie(id), DocumentoApi.membriFamiglia(id)]);
          categorie = opzioni[0] as List<Categoria>;
          persone = opzioni[1] as List<MembroFamiglia>;
        }
        // Senza i conteggi la lista funziona lo stesso; la vista a gruppi no.
        Object? problemaFaccette;
        final conteggi = DocumentoApi.faccette(id, q: query, perSignificato: perSignificato, filtri: _parametriFiltri).then<Faccette?>((f) => f).catchError((Object e) {
          problemaFaccette = e;
          return null;
        }, test: (e) => e is ApiException);
        final lista = aGruppi
            ? null
            : await DocumentoApi.pagina(id, q: query, filtri: _parametriFiltri, page: pagina, perPage: perPagina, ordina: ordina, verso: verso, perSignificato: perSignificato);
        return (lista: lista, faccette: await conteggi, problema: problemaFaccette);
      });
      // Una ricerca più recente è già partita: questo risultato è vecchio.
      if (r == null) return;
      faccette = r.faccette ?? faccette;
      erroreFaccette = r.problema is ApiException ? (r.problema as ApiException).message : null;
      if (r.lista case final lista?) {
        documenti = lista.righe;
        totale = lista.totale;
        // Dopo un'eliminazione l'ultima pagina può essere sparita.
        if (documenti.isEmpty && pagina > 1 && totale > 0) {
          pagina = pagine;
          return load();
        }
      } else {
        if (r.problema != null) throw r.problema!;
        documenti = [];
        totale = r.faccette?.totale ?? 0;
        _aggiornaGruppiAperti();
      }
      if (!aGruppi) selezionati.retainAll(documenti.map((d) => d.id));
    } on ApiException catch (e) {
      error = e.message;
    }
    loading = false;
    update();
  }

  /// Nuova ricerca o nuovi filtri: si riparte dalla prima pagina.
  void _daCapo() {
    pagina = 1;
    selezionati.clear();
    load();
  }

  void search(String value) {
    query = value;
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _daCapo);
  }

  void cambiaPerSignificato(bool valore) {
    perSignificato = valore;
    // Senza una ricerca non cambia nulla: niente richiesta inutile.
    if (query.trim().isEmpty) {
      update();
      return;
    }
    _daCapo();
  }

  /// Tocco su una voce: la sceglie, o la toglie se era già scelta.
  void scegliFiltro(DimensioneFiltro d, VoceFaccetta v) {
    if (filtri[d] == v.valore) {
      togliFiltro(d);
      return;
    }
    filtri[d] = v.valore;
    _etichetteScelte[d] = v.etichetta;
    _daCapo();
  }

  void togliFiltro(DimensioneFiltro d) {
    filtri.remove(d);
    _daCapo();
  }

  void azzeraFiltri() {
    query = '';
    filtri.clear();
    _daCapo();
  }

  void cambiaVista(VistaDocumenti v) {
    if (vista == v) return;
    vista = v;
    gruppiAperti.clear();
    _daCapo();
  }

  void raggruppaSecondo(DimensioneFiltro d) {
    raggruppaPer = d;
    gruppiAperti.clear();
    selezionati.clear();
    update();
  }

  void apriChiudiGruppo(String valore) {
    if (gruppiAperti.remove(valore) case final chiuso?) {
      // I selezionati devono restare visibili: chi era solo in questo gruppo si deseleziona.
      final ancoraVisibili = {for (final g in gruppiAperti.values) for (final d in g.righe) d.id};
      selezionati.removeAll(chiuso.righe.map((d) => d.id).where((id) => !ancoraVisibili.contains(id)));
      update();
      return;
    }
    gruppiAperti[valore] = GruppoDocumenti();
    update();
    caricaGruppo(valore);
  }

  /// Carica i documenti del gruppo: i primi [quanti], o il blocco successivo con [altri].
  Future<void> caricaGruppo(String valore, {bool altri = false, int? quanti}) async {
    final id = tenantId;
    final gruppo = gruppiAperti[valore];
    if (id == null || gruppo == null) return;
    final generazione = _generazioneGruppi;
    final perPage = (quanti ?? perPagina).clamp(1, maxPerGruppo);
    final pagina = altri ? (gruppo.righe.length ~/ perPage) + 1 : 1;
    gruppo
      ..loading = true
      ..errore = null;
    update();
    try {
      final r = await DocumentoApi.pagina(id, q: query, filtri: {..._parametriFiltri, raggruppaPer.parametro: valore}, page: pagina, perPage: perPage, ordina: ordina, verso: verso, perSignificato: perSignificato);
      if (generazione != _generazioneGruppi || gruppiAperti[valore] != gruppo) return;
      gruppo
        ..righe = altri ? [...gruppo.righe, ...r.righe] : r.righe
        ..totale = r.totale;
    } on ApiException catch (e) {
      if (gruppiAperti[valore] != gruppo) return;
      gruppo.errore = e.message;
    }
    gruppo.loading = false;
    update();
  }

  /// Dopo nuovi filtri o un'azione: restano aperti i gruppi che esistono ancora, ricaricati.
  void _aggiornaGruppiAperti() {
    ++_generazioneGruppi;
    final esistenti = gruppi.map((v) => v.valore).toSet();
    gruppiAperti.removeWhere((valore, _) => !esistenti.contains(valore));
    for (final e in gruppiAperti.entries) {
      caricaGruppo(e.key, quanti: e.value.righe.length < perPagina ? perPagina : e.value.righe.length);
    }
    final visibili = {for (final g in gruppiAperti.values) for (final d in g.righe) d.id};
    selezionati.retainAll(visibili);
  }

  void vaiAPagina(int p) {
    pagina = p.clamp(1, pagine);
    selezionati.clear();
    load();
  }

  void cambiaPerPagina(int n) {
    perPagina = n;
    _daCapo();
  }

  /// Clic sull'intestazione: stessa colonna = verso opposto; nuova colonna =
  /// dal più recente per le date, dalla A per il titolo.
  void ordinaPer(String campo) {
    if (ordina == campo) {
      verso = verso == 'asc' ? 'desc' : 'asc';
    } else {
      ordina = campo;
      verso = campo == 'titolo' ? 'asc' : 'desc';
    }
    _daCapo();
  }

  /// Ordinamento scelto da menu (telefono, dove non ci sono intestazioni).
  void ordinaCome(String campo, String nuovoVerso) {
    ordina = campo;
    verso = nuovoVerso;
    _daCapo();
  }

  void cambiaSelezione() => update();
}
