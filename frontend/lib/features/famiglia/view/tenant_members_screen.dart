import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/modulo_validato.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/griglia.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/app_constant.dart';
import 'package:cartellaviva/features/famiglia/controller/tenant_members_controller.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/foglio.dart';

part 'tenant_members_telefono.dart';

/// "Gestione tenant e membri": chi fa parte del tenant, ruoli, sospensioni,
/// rimozioni e inviti via email.
class TenantMembersScreen extends StatefulWidget {
  const TenantMembersScreen({super.key});

  @override
  State<TenantMembersScreen> createState() => _TenantMembersScreenState();
}

class _TenantMembersScreenState extends State<TenantMembersScreen> {
  final TenantMembersController controller = Get.put(TenantMembersController());

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder(
        init: controller,
        global: false,
        // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
        dispose: (_) => controller.onDelete(),
        builder: (controller) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Testo.titleMedium("Membri e accessi", fontSize: 18, fontWeight: FontWeight.w500),
                    Briciole(voci: [
                        Briciola(nome: controller.tenantName.isEmpty ? 'Famiglia' : controller.tenantName),
                        Briciola(nome: 'Membri', attiva: true),
                      ],
                    ),
                  ],
                ),
              ),
              SizedBox(height: spazioPagina),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina / 2),
                child: controller.loading
                    ? Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
                    : controller.error != null
                        ? errorCard(controller.error!)
                        : Griglia(spaziatura: spazioPagina, celle: [
                              CellaGriglia(child: tenantHeader()),
                              CellaGriglia(colonne: 8, child: membersCard()),
                              CellaGriglia(colonne: 4, child: inviteCard()),
                            ]),
              ),
            ],
          );
        },
      ),
    );
  }

  // ---- Intestazione con scelta del tenant ------------------------------------

  Widget tenantHeader() {
    final memberships = controller.session.me?.memberships ?? [];
    final membership = controller.session.currentMembership;
    return Scheda(elevazione: .7, paddingAll: 20, child: Wrap(
        spacing: 24,
        runSpacing: 12,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Riquadro.cerchio(paddingAll: 10, color: context.colori.primary.withAlpha(30), child: Icon(LucideIcons.house, size: 20, color: context.colori.primary)),
              SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (memberships.length > 1)
                    DropdownButton<String>(
                      value: controller.tenantId,
                      isDense: true,
                      underline: SizedBox(),
                      items: memberships
                          .map((m) => DropdownMenuItem(value: m.tenantId, child: Testo.bodyMedium(m.tenantName, fontWeight: FontWeight.w500)))
                          .toList(),
                      onChanged: controller.switchTenant,
                    )
                  else
                    Testo.bodyMedium(controller.tenantName, fontWeight: FontWeight.w500),
                  SizedBox(height: 2),
                  Testo.bodySmall('Il tuo ruolo: ${membership?.roleName ?? 'amministratore piattaforma'}', tenue: true),
                ],
              ),
            ],
          ),
          Testo.bodySmall('${controller.members.length} membri · ${controller.activeOwners} proprietari attivi', tenue: true),
        ],
      ));
  }

  // ---- Membri ---------------------------------------------------------------

  Widget membersCard() {
    if (MediaQuery.of(context).size.width < larghezzaTelefono) return membriTelefono();
    return Scheda(elevazione: .7, paddingAll: 20, child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Testo.bodyMedium("Membri", fontWeight: FontWeight.w500),
              IconButton(
                tooltip: 'Aggiorna',
                onPressed: controller.load,
                icon: Icon(LucideIcons.refresh_cw, size: 16),
              ),
            ],
          ),
          SizedBox(height: 12),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              columnSpacing: 24,
              columns: [
                DataColumn(label: Testo.titleSmall('Nome', fontWeight: FontWeight.w500)),
                DataColumn(label: Testo.titleSmall('Ruolo', fontWeight: FontWeight.w500)),
                DataColumn(label: Testo.titleSmall('Stato', fontWeight: FontWeight.w500)),
                DataColumn(label: Testo.titleSmall('Azioni', fontWeight: FontWeight.w500)),
              ],
              rows: controller.members.map(memberRow).toList(),
            ),
          ),
        ],
      ));
  }

  DataRow memberRow(TenantMember member) {
    return DataRow(cells: [
      DataCell(Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Testo.bodySmall(member.isCurrentUser ? '${member.fullName} (tu)' : member.fullName, fontWeight: FontWeight.w500),
          Testo.bodySmall(member.email, tenue: true, fontSize: 11),
        ],
      )),
      DataCell(roleSelector(member)),
      DataCell(statusBadge(member)),
      DataCell(Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          actionButton(
            icon: member.isActive ? LucideIcons.circle_pause : LucideIcons.circle_play,
            tooltip: member.isActive ? 'Sospendi accesso' : 'Riattiva accesso',
            enabled: controller.canToggleStatusOf(member),
            disabledReason: controller.disabledReason(member, 'status'),
            color: context.colori.warning,
            onPressed: () => controller.toggleStatus(member),
          ),
          actionButton(
            icon: member.isCurrentUser ? LucideIcons.log_out : LucideIcons.user_x,
            tooltip: member.isCurrentUser ? 'Esci dal tenant' : 'Rimuovi accesso',
            enabled: controller.canRemove(member),
            disabledReason: controller.disabledReason(member, 'remove'),
            color: context.colori.danger,
            onPressed: () => confirmRemove(member),
          ),
        ],
      )),
    ]);
  }

  Widget roleSelector(TenantMember member) {
    final enabled = controller.canEditRoleOf(member);
    final options = [...controller.assignableRoles];
    if (!options.any((r) => r.id == member.roleId)) {
      // Il ruolo attuale va mostrato anche se non assegnabile da chi guarda.
      options.insert(0, TenantRole(id: member.roleId, slug: member.roleSlug, name: member.roleName, system: true, permissions: const []));
    }
    return Tooltip(
      message: enabled ? '' : (controller.disabledReason(member, 'role') ?? ''),
      child: DropdownButton<String>(
        value: member.roleId,
        isDense: true,
        underline: SizedBox(),
        style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500),
        items: options.map((r) => DropdownMenuItem(value: r.id, child: Testo.bodySmall(r.name, fontWeight: FontWeight.w500))).toList(),
        onChanged: enabled ? (roleId) => controller.changeRole(member, roleId) : null,
      ),
    );
  }

  Widget statusBadge(TenantMember member) {
    final color = member.isActive ? context.colori.success : context.colori.warning;
    return Riquadro(padding: EdgeInsets.symmetric(horizontal: 8, vertical: 4), borderRadiusAll: 4, color: color.withAlpha(36), child: Testo.labelSmall(member.isActive ? 'Attivo' : 'Sospeso', color: color, fontWeight: FontWeight.w500));
  }

  Widget actionButton({
    required IconData icon,
    required String tooltip,
    required bool enabled,
    required String? disabledReason,
    required Color color,
    required VoidCallback onPressed,
  }) {
    return Tooltip(
      message: enabled ? tooltip : (disabledReason ?? tooltip),
      child: IconButton(
        onPressed: enabled ? onPressed : null,
        icon: Icon(icon, size: 18, color: enabled ? color : color.withAlpha(90)),
      ),
    );
  }

  Future<void> confirmRemove(TenantMember member) async {
    final confirmed = await Get.dialog<bool>(AlertDialog(
      title: Testo.titleMedium(member.isCurrentUser ? 'Uscire dal tenant?' : 'Rimuovere l\'accesso?', fontWeight: FontWeight.w500),
      content: Testo.bodySmall(member.isCurrentUser
          ? 'Non potrai più vedere i dati di "${controller.tenantName}" finché non verrai invitato di nuovo.'
          : '${member.fullName} non potrà più accedere ai dati di "${controller.tenantName}". I documenti che ha caricato restano nel tenant.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: Text('Annulla')),
        TextButton(
          onPressed: () => Get.back(result: true),
          child: Text(member.isCurrentUser ? 'Esci' : 'Rimuovi', style: TextStyle(color: context.colori.danger)),
        ),
      ],
    ));
    if (confirmed == true) {
      await controller.remove(member);
    }
  }

  // ---- Inviti ---------------------------------------------------------------

  Widget inviteCard() {
    final enabled = controller.canInvite;
    return Scheda(elevazione: .7, paddingAll: 20, child: Form(
        key: controller.inviteFormKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Testo.bodyMedium("Invita un familiare", fontWeight: FontWeight.w500),
            SizedBox(height: 4),
            Testo.bodySmall(enabled
                  ? 'Riceverà un link valido 72 ore per registrarsi o accedere.'
                  : 'Il tuo ruolo non permette di invitare nuovi membri.', tenue: true),
            SizedBox(height: 16),
            TextFormField(
              controller: controller.inviteEmail,
              enabled: enabled,
              keyboardType: TextInputType.emailAddress,
              validator: (value) => (value == null || !emailValida(value.trim())) ? 'Inserisci un indirizzo email valido' : null,
              style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500),
              decoration: InputDecoration(
                labelText: 'Email',
                isDense: true,
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
              ),
            ),
            SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: ValueKey(controller.inviteRoleId),
              initialValue: controller.inviteRoleId,
              isDense: true,
              decoration: InputDecoration(
                labelText: 'Ruolo',
                isDense: true,
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 12),
              ),
              items: controller.assignableRoles
                  .map((r) => DropdownMenuItem(value: r.id, child: Testo.bodySmall(r.name, fontWeight: FontWeight.w500)))
                  .toList(),
              onChanged: enabled ? (value) => controller.inviteRoleId = value : null,
            ),
            SizedBox(height: 16),
            SizedBox(width: double.infinity, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: context.colori.primary, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: enabled && !controller.inviting ? controller.invite : null, child: Testo.bodyMedium(controller.inviting ? 'Invio...' : 'Invia invito', color: context.colori.onPrimary))),
            if (controller.invitations.isNotEmpty) ...[
              SizedBox(height: 24),
              Testo.bodyMedium("Inviti", fontWeight: FontWeight.w500),
              SizedBox(height: 8),
              ...controller.invitations.map(invitationTile),
            ],
          ],
        ),
      ));
  }

  Widget invitationTile(TenantInvitation invitation) {
    final statusLabel = switch (invitation.status) {
      'pending' => 'In attesa',
      'accepted' => 'Accettato',
      'revoked' => 'Revocato',
      'expired' => 'Scaduto',
      _ => invitation.status,
    };
    return Padding(
      padding: EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Testo.bodySmall(invitation.email, fontWeight: FontWeight.w500),
                Testo.bodySmall('${invitation.roleName} · $statusLabel'
                  '${invitation.isPending && invitation.expiresAt != null ? ' fino al ${dateFormatter.format(invitation.expiresAt!.toLocal())}' : ''}', tenue: true, fontSize: 11),
              ],
            ),
          ),
          if (invitation.isPending)
            Tooltip(
              message: 'Revoca invito',
              child: IconButton(
                onPressed: controller.busy.contains(invitation.id) ? null : () => controller.revoke(invitation),
                icon: Icon(LucideIcons.x, size: 16, color: context.colori.danger),
              ),
            ),
        ],
      ),
    );
  }

  Widget errorCard(String message) {
    return Scheda(paddingAll: 24, child: Row(
        children: [
          Icon(LucideIcons.triangle_alert, color: context.colori.danger),
          SizedBox(width: 12),
          Expanded(child: Testo.bodyMedium(message)),
          TextButton(onPressed: controller.load, child: Testo.bodySmall('Riprova', color: context.colori.primary)),
        ],
      ));
  }
}
