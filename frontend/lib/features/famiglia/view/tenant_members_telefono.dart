part of 'tenant_members_screen.dart';

/// Telefono: un elenco di righe al posto della tabella, con le azioni nel menu ⋯.
extension _MembriTelefono on _TenantMembersScreenState {
  Widget membriTelefono() => Scheda(
        elevazione: .7,
        paddingAll: 0,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 4, 0),
            child: Row(children: [
              Expanded(child: Testo.bodyMedium('Membri', fontWeight: FontWeight.w500)),
              IconButton(tooltip: 'Aggiorna', onPressed: controller.load, icon: const Icon(LucideIcons.refresh_cw, size: 16)),
            ]),
          ),
          for (final m in controller.members) ...[const Divider(height: 1), _rigaMembro(m)],
        ]),
      );

  Widget _rigaMembro(TenantMember m) {
    final occupato = controller.busy.contains(m.id);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 4, 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Testo.bodyMedium(m.isCurrentUser ? '${m.fullName} (tu)' : m.fullName, fontWeight: FontWeight.w500),
            Testo.bodySmall(m.email, tenue: true),
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Testo.bodySmall(m.roleName, fontWeight: FontWeight.w500),
              statusBadge(m),
            ]),
          ]),
        ),
        if (occupato)
          const Padding(padding: EdgeInsets.all(14), child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)))
        else
          PopupMenuButton<String>(
            tooltip: 'Azioni',
            onSelected: (v) => switch (v) {
              'ruolo' => _scegliRuolo(m),
              'stato' => controller.toggleStatus(m),
              _ => confirmRemove(m),
            },
            itemBuilder: (_) => [
              _voce('ruolo', LucideIcons.user_cog, 'Cambia ruolo', controller.canEditRoleOf(m), controller.disabledReason(m, 'role')),
              _voce('stato', m.isActive ? LucideIcons.circle_pause : LucideIcons.circle_play, m.isActive ? 'Sospendi accesso' : 'Riattiva accesso',
                  controller.canToggleStatusOf(m), controller.disabledReason(m, 'status')),
              _voce('rimuovi', m.isCurrentUser ? LucideIcons.log_out : LucideIcons.user_x, m.isCurrentUser ? 'Esci dal tenant' : 'Rimuovi accesso', controller.canRemove(m),
                  controller.disabledReason(m, 'remove'),
                  pericolo: true),
            ],
          ),
      ]),
    );
  }

  PopupMenuItem<String> _voce(String valore, IconData icona, String testo, bool attiva, String? motivo, {bool pericolo = false}) {
    final colore = pericolo ? context.colori.danger : null;
    return PopupMenuItem(
      value: valore,
      enabled: attiva,
      child: Row(children: [
        Icon(icona, size: 18, color: attiva ? colore : null),
        const SizedBox(width: 12),
        Flexible(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(testo, style: TextStyle(color: attiva ? colore : null)),
            if (!attiva && motivo != null) Testo.bodySmall(motivo, tenue: true),
          ]),
        ),
      ]),
    );
  }

  Future<void> _scegliRuolo(TenantMember m) {
    final ruoli = [...controller.assignableRoles];
    if (!ruoli.any((r) => r.id == m.roleId)) {
      ruoli.insert(0, TenantRole(id: m.roleId, slug: m.roleSlug, name: m.roleName, system: true, permissions: const []));
    }
    return apriFoglio<void>(
      context,
      titolo: 'Ruolo di ${m.fullName}',
      contenuto: (ctx) => RadioGroup<String>(
        groupValue: m.roleId,
        onChanged: (id) {
          Navigator.of(ctx).pop();
          if (id != null && id != m.roleId) controller.changeRole(m, id);
        },
        child: Column(children: [for (final r in ruoli) RadioListTile<String>(value: r.id, title: Text(r.name), contentPadding: EdgeInsets.zero)]),
      ),
    );
  }
}
