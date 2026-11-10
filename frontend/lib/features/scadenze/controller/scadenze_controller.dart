import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/features/scadenze/api/scadenza_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';

enum VistaScadenze { elenco, calendario }

/// Scadenziario: scadenze aperte (anche vecchie), in verifica e fatte negli
/// ultimi 90 giorni. I permessi qui servono solo ad abilitare i comandi.
class ScadenzeController extends GetxController {
  SessionService get session => SessionService.to;

  bool loading = true;
  String? error;
  VistaScadenze vista = VistaScadenze.elenco;
  List<Scadenza> scadenze = [];
  List<Categoria> categorie = [];
  List<MembroFamiglia> persone = [];
  final Set<String> busy = {};

  String? get tenantId => session.currentTenantId;

  Map<GruppoScadenze, List<Scadenza>> get gruppi => raggruppaScadenze(scadenze);

  /// Con scadenza:manage:own si possono creare scadenze (diventano proprie).
  bool get canCreate => session.can('scadenza:manage', resourceOwnerId: session.me?.id);

  bool canManage(Scadenza s) => !s.daRichiesta && session.can('scadenza:manage', resourceOwnerId: s.ownerId);

  @override
  void onInit() {
    super.onInit();
    load();
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
      final oggi = DateTime.now();
      final results = await Future.wait([
        ScadenzaApi.list(id, stato: 'aperta'),
        ScadenzaApi.list(id, stato: 'in_verifica'),
        ScadenzaApi.list(id, stato: 'fatta', da: oggi.subtract(Duration(days: 90))),
      ]);
      scadenze = [...results[0], ...results[1], ...results[2]];
      if (categorie.isEmpty) {
        final opzioni = await Future.wait([DocumentoApi.categorie(id), DocumentoApi.membriFamiglia(id)]);
        categorie = opzioni[0] as List<Categoria>;
        persone = opzioni[1] as List<MembroFamiglia>;
      }
    } on ApiException catch (e) {
      error = e.message;
    }
    loading = false;
    update();
  }

  void setVista(VistaScadenze v) {
    vista = v;
    update();
  }

  Future<void> fatta(Scadenza s) async {
    busy.add(s.id);
    update();
    try {
      final prossima = await ScadenzaApi.fatta(tenantId!, s.id);
      _notify(prossima == null ? 'Scadenza completata.' : 'Fatta. La prossima è il ${_data(prossima.data)}.');
      await load();
    } on ApiException catch (e) {
      _notify(e.message, isError: true);
    }
    busy.remove(s.id);
    update();
  }

  Future<void> elimina(Scadenza s) async {
    try {
      await ScadenzaApi.delete(tenantId!, s.id);
      scadenze.removeWhere((x) => x.id == s.id);
      update();
    } on ApiException catch (e) {
      _notify(e.message, isError: true);
    }
  }

  String _data(DateTime d) => '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

  void _notify(String message, {bool isError = false}) {
    Get.snackbar(isError ? 'Operazione non riuscita' : 'Fatto', message,
        snackPosition: SnackPosition.BOTTOM,
        margin: EdgeInsets.all(16),
        maxWidth: 480,
        backgroundColor: isError ? Colors.red.shade50 : Colors.green.shade50,
        colorText: isError ? Colors.red.shade900 : Colors.green.shade900);
  }
}
