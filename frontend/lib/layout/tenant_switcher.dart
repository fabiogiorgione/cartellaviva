import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/account/model/me.dart';

/// Famiglia o ente su cui si sta lavorando, in cima al menu laterale.
/// Con una sola appartenenza mostra solo il nome; con più apre l'elenco.
class TenantSwitcher extends StatelessWidget {
  const TenantSwitcher({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SessionService>(builder: (session) {
      final current = session.currentMembership;
      final memberships = session.me?.memberships ?? const <TenantMembership>[];
      if (current == null) {
        return const SizedBox.shrink();
      }
      final tile = Container(
        margin: EdgeInsets.fromLTRB(16, 0, 16, 12),
        padding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: context.colori.barraAttiva.withAlpha(20),
          borderRadius: BorderRadius.circular(6),
        ),
        child: Row(children: [
          Icon(_icon(current), size: 18, color: context.colori.barraAttiva),
          SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Testo.labelMedium(current.tenantName, fontWeight: FontWeight.w600, maxLines: 1, overflow: TextOverflow.ellipsis, color: context.colori.barraTesto),
              Testo.labelSmall(current.roleName, tenue: true, color: context.colori.barraTesto),
            ]),
          ),
          if (memberships.length > 1) Icon(LucideIcons.chevrons_up_down, size: 16, color: context.colori.barraTesto),
        ]),
      );
      if (memberships.length < 2) {
        return tile;
      }
      return PopupMenuButton<String>(
        tooltip: 'Cambia famiglia o ente',
        position: PopupMenuPosition.under,
        onSelected: (id) async {
          if (id == current.tenantId) return;
          await session.selectTenant(id);
          Get.offAllNamed(session.homeRoute());
        },
        itemBuilder: (_) => [
          for (final m in memberships)
            PopupMenuItem<String>(
              value: m.tenantId,
              child: Row(children: [
                Icon(_icon(m), size: 16),
                SizedBox(width: 10),
                Expanded(child: Testo.bodyMedium(m.tenantName, fontWeight: m.tenantId == current.tenantId ? FontWeight.w600 : FontWeight.w400)),
                if (m.tenantId == current.tenantId) Icon(LucideIcons.check, size: 16),
              ]),
            ),
        ],
        child: tile,
      );
    });
  }

  IconData _icon(TenantMembership m) => m.tenantType == 'azienda' ? LucideIcons.building : LucideIcons.house;
}
