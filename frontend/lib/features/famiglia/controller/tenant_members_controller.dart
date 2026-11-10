import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';

/// Gestione tenant e membri.
///
/// Le regole qui sotto (canXxx) servono SOLO ad abilitare/disabilitare i
/// controlli: replicano quelle del backend per non mostrare azioni che
/// verrebbero comunque rifiutate. Ogni endpoint ricontrolla i permessi e
/// risponde 403/409 se la UI viene aggirata.
class TenantMembersController extends GetxController {
  final SessionService session = SessionService.to;

  bool loading = true;
  String? error;
  List<TenantMember> members = [];
  List<TenantRole> roles = [];
  List<TenantInvitation> invitations = [];

  final TextEditingController inviteEmail = TextEditingController();
  final GlobalKey<FormState> inviteFormKey = GlobalKey<FormState>();
  String? inviteRoleId;
  bool inviting = false;

  /// Membri/inviti con un'operazione in corso (per disabilitarne i controlli).
  final Set<String> busy = {};

  String? get tenantId => session.currentTenantId;

  String get tenantName => session.currentMembership?.tenantName ?? '';

  // ---- Permessi (cosmetici) -------------------------------------------------

  bool get canInvite => session.can('membro:invite');

  bool get canManageRoles => session.can('membro:manage_role');

  bool get canRemoveOthers => session.can('membro:remove');

  bool get isOwner => (session.currentMembership?.isOwner ?? false) || (session.me?.isSuperAdmin ?? false);

  int get activeOwners => members.where((m) => m.isOwner && m.isActive).length;

  bool isLastOwner(TenantMember member) => member.isOwner && member.isActive && activeOwners <= 1;

  /// Anti-escalation: chi non è owner può assegnare solo ruoli non-owner i cui
  /// permessi possiede già tutti.
  List<TenantRole> get assignableRoles {
    final mine = session.currentMembership?.permissions ?? <String>{};
    return roles.where((role) => isOwner || (!role.isOwnerRole && role.permissions.every(mine.contains))).toList();
  }

  bool canEditRoleOf(TenantMember member) =>
      canManageRoles && !busy.contains(member.id) && !isLastOwner(member) && (!member.isOwner || isOwner || member.isCurrentUser);

  bool canToggleStatusOf(TenantMember member) =>
      canRemoveOthers && !member.isCurrentUser && !busy.contains(member.id) && !isLastOwner(member) && (!member.isOwner || isOwner);

  bool canRemove(TenantMember member) =>
      !busy.contains(member.id) &&
      !isLastOwner(member) &&
      (member.isCurrentUser || (canRemoveOthers && (!member.isOwner || isOwner)));

  String? disabledReason(TenantMember member, String action) {
    if (isLastOwner(member)) {
      return 'È l\'unico proprietario: nomina prima un altro proprietario.';
    }
    return switch (action) {
      'role' when !canManageRoles => 'Richiede il permesso "membro:manage_role".',
      'status' when member.isCurrentUser => 'Non puoi sospendere il tuo stesso accesso.',
      'status' || 'remove' when !canRemoveOthers && !member.isCurrentUser => 'Richiede il permesso "membro:remove".',
      _ when member.isOwner && !isOwner && !member.isCurrentUser => 'Solo un proprietario può modificare un altro proprietario.',
      _ => null,
    };
  }

  // ---- Caricamento ----------------------------------------------------------

  @override
  void onInit() {
    super.onInit();
    load();
  }

  @override
  void onClose() {
    inviteEmail.dispose();
    super.onClose();
  }

  Future<void> load() async {
    final id = tenantId;
    if (id == null) {
      loading = false;
      error = 'Non fai parte di nessun tenant.';
      update();
      return;
    }
    loading = true;
    error = null;
    update();
    try {
      final results = await Future.wait([TenantApi.members(id), TenantApi.roles(id)]);
      members = results[0] as List<TenantMember>;
      roles = results[1] as List<TenantRole>;
      invitations = canInvite ? await TenantApi.invitations(id) : [];
      final assignable = assignableRoles;
      if (inviteRoleId == null || !assignable.any((r) => r.id == inviteRoleId)) {
        inviteRoleId = assignable.firstWhereOrNull((r) => r.slug == 'member')?.id ??
            assignable.firstWhereOrNull((r) => !r.isOwnerRole)?.id;
      }
    } on ApiException catch (e) {
      error = e.message;
    }
    loading = false;
    update();
  }

  Future<void> switchTenant(String? id) async {
    if (id == null || id == tenantId) {
      return;
    }
    await session.selectTenant(id);
    inviteRoleId = null;
    await load();
  }

  // ---- Azioni ---------------------------------------------------------------

  Future<void> changeRole(TenantMember member, String? roleId) async {
    if (roleId == null || roleId == member.roleId) {
      return;
    }
    await _run(member.id, () => TenantApi.changeRole(tenantId!, member.id, roleId), 'Ruolo aggiornato.',
        refreshSession: member.isCurrentUser);
  }

  Future<void> toggleStatus(TenantMember member) async {
    final next = member.isActive ? 'suspended' : 'active';
    await _run(member.id, () => TenantApi.changeStatus(tenantId!, member.id, next),
        member.isActive ? 'Accesso sospeso.' : 'Accesso riattivato.');
  }

  Future<void> remove(TenantMember member) async {
    final ok = await _run(member.id, () => TenantApi.removeMember(tenantId!, member.id),
        member.isCurrentUser ? 'Sei uscito dal tenant.' : 'Accesso rimosso.',
        refreshSession: member.isCurrentUser);
    if (ok && member.isCurrentUser) {
      Get.offAllNamed('/dashboard');
    }
  }

  Future<void> invite() async {
    if (inviting || !(inviteFormKey.currentState?.validate() ?? false) || inviteRoleId == null) {
      return;
    }
    inviting = true;
    update();
    try {
      await TenantApi.invite(tenantId!, inviteEmail.text, inviteRoleId!);
      inviteEmail.clear();
      _notify('Invito inviato', 'Riceverà un\'email con il link per entrare.');
      invitations = await TenantApi.invitations(tenantId!);
    } on ApiException catch (e) {
      _notify('Invito non inviato', e.message, isError: true);
    }
    inviting = false;
    update();
  }

  Future<void> revoke(TenantInvitation invitation) async {
    await _run(invitation.id, () => TenantApi.revokeInvitation(tenantId!, invitation.id), 'Invito revocato.');
  }

  /// Esegue un'azione, mostra l'esito (anche il 403/409 del server) e ricarica.
  Future<bool> _run(String busyKey, Future<Object?> Function() action, String successMessage,
      {bool refreshSession = false}) async {
    busy.add(busyKey);
    update();
    var ok = false;
    try {
      await action();
      ok = true;
      _notify('Fatto', successMessage);
      if (refreshSession) {
        await session.loadMe();
      }
    } on ApiException catch (e) {
      _notify('Operazione non consentita', e.message, isError: true);
    }
    busy.remove(busyKey);
    if (session.currentTenantId != null) {
      await load();
    } else {
      update();
    }
    return ok;
  }

  void _notify(String title, String message, {bool isError = false}) {
    Get.snackbar(
      title,
      message,
      snackPosition: SnackPosition.BOTTOM,
      margin: EdgeInsets.all(16),
      maxWidth: 480,
      backgroundColor: isError ? Colors.red.shade50 : Colors.green.shade50,
      colorText: isError ? Colors.red.shade900 : Colors.green.shade900,
    );
  }
}
