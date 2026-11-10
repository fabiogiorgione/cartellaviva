import 'package:get/get.dart';
import 'package:cartellaviva/features/ente/api/ente_api.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Gruppi (classi, corsi, squadre) di un anno dell'ente, sedi e passaggio d'anno.
/// Le letture si possono sostituire nei test.
class EnteGruppiController extends GetxController {
  final String tenantId;
  final Future<List<AnnoEnte>> Function() _anni;
  final Future<List<Gruppo>> Function(String? annoId) _gruppi;
  final Future<List<Sede>> Function() _sedi;

  EnteGruppiController({
    required this.tenantId,
    Future<List<AnnoEnte>> Function()? anni,
    Future<List<Gruppo>> Function(String? annoId)? gruppi,
    Future<List<Sede>> Function()? sedi,
  })  : _anni = anni ?? (() => EnteApi.anni(tenantId)),
        _gruppi = gruppi ?? ((annoId) => EnteApi.gruppi(tenantId, annoId: annoId)),
        _sedi = sedi ?? (() => EnteApi.sedi(tenantId));

  List<AnnoEnte> anni = [];
  String? annoId;
  List<Gruppo> gruppi = [];
  List<Sede> sedi = [];
  bool loading = true;
  String? errore;

  AnnoEnte? get anno => anni.where((a) => a.id == annoId).firstOrNull;

  bool get archiviato => anno?.archiviato ?? false;

  @override
  void onInit() {
    super.onInit();
    load();
  }

  /// Anni, poi gruppi dell'anno scelto (predefinito: quello in corso) e sedi.
  Future<void> load() async {
    loading = true;
    update();
    try {
      anni = await _anni();
      annoId ??= anni.where((a) => !a.archiviato).firstOrNull?.id ?? anni.firstOrNull?.id;
      final risultati = await Future.wait([_gruppi(annoId), _sedi()]);
      gruppi = risultati[0] as List<Gruppo>;
      sedi = risultati[1] as List<Sede>;
      errore = null;
    } on ApiException catch (e) {
      errore = e.message;
    }
    loading = false;
    update();
  }

  void scegliAnno(String? id) {
    annoId = id;
    load();
  }

  /// Esegue un'azione e ricarica. Restituisce il messaggio d'errore, o null se è andata.
  Future<String?> esegui(Future<void> Function() azione) async {
    try {
      await azione();
      await load();
      return null;
    } on ApiException catch (e) {
      return e.message;
    }
  }

  Future<String?> salvaGruppo({String? id, required String nome, required String tipo, String? sedeId}) =>
      esegui(() => EnteApi.salvaGruppo(tenantId, id: id, nome: nome, tipo: tipo, sedeId: sedeId));

  Future<String?> eliminaGruppo(Gruppo g) => esegui(() => EnteApi.eliminaGruppo(tenantId, g.id));

  Future<String?> assegnaOperatori(Gruppo g, List<String> membershipIds) => esegui(() => EnteApi.operatoriGruppo(tenantId, g.id, membershipIds));

  Future<String?> salvaSede({String? id, required String nome, String? indirizzo}) => esegui(() => EnteApi.salvaSede(tenantId, id: id, nome: nome, indirizzo: indirizzo));

  Future<String?> eliminaSede(Sede s) => esegui(() => EnteApi.eliminaSede(tenantId, s.id));

  /// Passaggio d'anno: per ogni gruppo il nome nel nuovo anno (null = esce). Poi si mostra il nuovo anno.
  Future<String?> passaggio(AnnoEnte da, String nuovoAnno, Map<String, String?> nomi) => esegui(() async {
        await EnteApi.passaggioAnno(tenantId, da.id, nuovoAnno, nomi);
        annoId = null;
      });

  /// Membri attivi dell'ente, tra cui scegliere gli operatori.
  Future<List<TenantMember>> membriAttivi() async => (await TenantApi.members(tenantId)).where((m) => m.isActive).toList();
}
