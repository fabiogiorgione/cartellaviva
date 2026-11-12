import 'package:get/get.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart' show ScadenzaProposta;
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/api/pratiche_api.dart';
import 'package:cartellaviva/features/pratiche/model/pratica.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Una proposta della coda "Da confermare": scadenza, pagamento o voce di una pratica (730).
class VoceDaConfermare {
  final ScadenzaProposta? scadenza;
  final PropostaPagamento? pagamento;
  final VocePratica? pratica;

  const VoceDaConfermare.scadenza(ScadenzaProposta this.scadenza)
      : pagamento = null,
        pratica = null;
  const VoceDaConfermare.pagamento(PropostaPagamento this.pagamento)
      : scadenza = null,
        pratica = null;
  const VoceDaConfermare.pratica(VocePratica this.pratica)
      : scadenza = null,
        pagamento = null;

  String? get documentoId => scadenza?.documentoId ?? pagamento?.documentoId ?? pratica?.documento?.id;

  /// Data usata per ordinare la coda (le più vicine prima, senza data in fondo).
  /// Le voci delle pratiche non scadono: vanno dopo scadenze e pagamenti.
  DateTime? get quando => scadenza?.data ?? pagamento?.scadenza;
}

/// Proposte dell'AI da confermare (scadenze e pagamenti), condivise tra menu,
/// pagina "Da confermare" e Home: il numero nel menu si aggiorna quando si
/// conferma o si ignora.
class DaConfermareService extends GetxController {
  /// Servizio condiviso; si ricarica da solo se cambiano utente o famiglia.
  static DaConfermareService get to {
    final s = Get.isRegistered<DaConfermareService>() ? Get.find<DaConfermareService>() : Get.put(DaConfermareService(), permanent: true);
    s._assicuraCaricato();
    return s;
  }

  bool loading = false;
  bool caricato = false;
  List<ScadenzaProposta> scadenze = [];
  List<PropostaPagamento> pagamenti = [];
  List<VocePratica> pratiche = [];
  String? _chiave;

  int get totale => scadenze.length + pagamenti.length + pratiche.length;

  /// Coda unica: per data crescente, quelle senza data in fondo.
  List<VoceDaConfermare> get voci {
    final tutte = [for (final s in scadenze) VoceDaConfermare.scadenza(s), for (final p in pagamenti) VoceDaConfermare.pagamento(p), for (final v in pratiche) VoceDaConfermare.pratica(v)];
    tutte.sort((a, b) {
      final x = a.quando, y = b.quando;
      if (x == null && y == null) return 0;
      if (x == null) return 1;
      if (y == null) return -1;
      return x.compareTo(y);
    });
    return tutte;
  }

  void _assicuraCaricato() {
    final session = SessionService.to;
    final chiave = '${session.me?.id}/${session.currentTenantId}';
    if (chiave == _chiave) return;
    _chiave = chiave;
    scadenze = [];
    pagamenti = [];
    pratiche = [];
    caricato = false;
    // Si può essere nel mezzo di una build del menu: si parte dopo.
    Future.microtask(ricarica);
  }

  Future<void> ricarica() async {
    final session = SessionService.to;
    final tenantId = session.currentTenantId;
    if (tenantId == null || session.me == null) return;
    loading = true;
    final risultati = await Future.wait([
      if (session.can('scadenza:manage', resourceOwnerId: session.me?.id)) _leggi(() => ScadenzaApi.proposte(tenantId)) else Future.value(<ScadenzaProposta>[]),
      if (session.can('pagamento:manage')) _leggi(() => PagamentiApi.proposte(tenantId)) else Future.value(<PropostaPagamento>[]),
      if (session.can('pratica:manage')) _leggi(() => PraticheApi.proposte(tenantId)) else Future.value(<VocePratica>[]),
    ]);
    if (_chiave != '${session.me?.id}/${session.currentTenantId}') return;
    scadenze = risultati[0] as List<ScadenzaProposta>;
    pagamenti = risultati[1] as List<PropostaPagamento>;
    pratiche = risultati[2] as List<VocePratica>;
    loading = false;
    caricato = true;
    update();
  }

  /// Le proposte sono solo un suggerimento: un errore (o una rotta non ancora
  /// disponibile) non blocca nulla, la coda resta vuota.
  Future<List<T>> _leggi<T>(Future<List<T>> Function() chiamata) async {
    try {
      return await chiamata();
    } on ApiException {
      return <T>[];
    }
  }
}
