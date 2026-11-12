import 'package:get/get.dart';
import 'package:cartellaviva/features/pratiche/api/pagamenti_api.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

enum VistaPagamenti { daPagare, pagati }

/// Pagamenti della famiglia: da pagare (per scadenza) e pagati (più recenti prima).
class PagamentiController extends GetxController {
  SessionService get session => SessionService.to;

  bool loading = true;
  String? error;
  VistaPagamenti vista = VistaPagamenti.daPagare;
  List<Pagamento> daPagare = [];
  List<Pagamento> pagati = [];
  String totaleDaPagare = '0.00';
  final Set<String> busy = {};

  String? get tenantId => session.currentTenantId;

  bool get canManage => session.can('pagamento:manage');

  List<Pagamento> get visibili => vista == VistaPagamenti.daPagare ? daPagare : pagati;

  @override
  void onInit() {
    super.onInit();
    load();
  }

  Future<void> load({bool silenzioso = false}) async {
    final id = tenantId;
    if (id == null) {
      loading = false;
      error = 'Seleziona una famiglia.';
      update();
      return;
    }
    if (!silenzioso) {
      loading = true;
      error = null;
      update();
    }
    try {
      final r = await Future.wait([PagamentiApi.list(id, stato: 'da_pagare'), PagamentiApi.list(id, stato: 'pagato')]);
      daPagare = r[0].pagamenti;
      totaleDaPagare = r[0].totaleDaPagare;
      pagati = r[1].pagamenti;
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    loading = false;
    update();
  }

  void setVista(VistaPagamenti v) {
    vista = v;
    update();
  }

  /// Esegue [azione] sul pagamento [p] mostrando il suo stato "in corso".
  Future<void> esegui(Pagamento p, Future<void> Function(String tenantId) azione) async {
    busy.add(p.id);
    update();
    try {
      await azione(tenantId!);
      await load(silenzioso: true);
    } finally {
      busy.remove(p.id);
      update();
    }
  }
}
