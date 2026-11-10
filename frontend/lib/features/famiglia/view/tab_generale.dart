import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Nome, proprietari, spazio usato e modalità avanzata.
class TabGenerale extends StatefulWidget {
  const TabGenerale({super.key});

  @override
  State<TabGenerale> createState() => _TabGeneraleState();
}

class _TabGeneraleState extends State<TabGenerale> {
  SessionService get session => SessionService.to;
  final TextEditingController nome = TextEditingController();
  List<TenantMember> owners = [];
  bool saving = false;
  String? error;

  @override
  void initState() {
    super.initState();
    nome.text = session.currentTenant?.name ?? session.currentMembership?.tenantName ?? '';
    _loadOwners();
  }

  @override
  void dispose() {
    nome.dispose();
    super.dispose();
  }

  Future<void> _loadOwners() async {
    if (!session.can('membro:read')) return;
    try {
      final members = await TenantApi.members(session.currentTenantId!);
      if (mounted) setState(() => owners = members.where((m) => m.isOwner && m.isActive).toList());
    } on ApiException {
      // non essenziale
    }
  }

  Future<void> _save({String? name, bool? avanzata}) async {
    setState(() {
      saving = true;
      error = null;
    });
    try {
      await TenantApi.updateTenant(session.currentTenantId!, name: name, modalitaAvanzata: avanzata);
      await session.loadMe(); // il nome compare anche nel selettore e nelle membership
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Salvato.')));
    } on ApiException catch (e) {
      error = e.message;
    }
    if (mounted) setState(() => saving = false);
  }

  @override
  Widget build(BuildContext context) {
    final tenant = session.currentTenant;
    final canManage = session.can('tenant:manage');
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Testo.titleSmall('Famiglia', fontWeight: FontWeight.w600),
      SizedBox(height: 12),
      Row(children: [
        Expanded(
          child: TextField(
            controller: nome,
            enabled: canManage && !saving,
            maxLength: 150,
            decoration: const InputDecoration(labelText: 'Nome', isDense: true),
          ),
        ),
        if (canManage) ...[
          SizedBox(width: 12),
          FilledButton(onPressed: saving ? null : () => _save(name: nome.text), child: const Text('Salva')),
        ],
      ]),
      if (owners.isNotEmpty) ...[
        Testo.bodyMedium('Proprietari: ${owners.map((o) => o.fullName.isEmpty ? o.email : o.fullName).join(', ')}'),
        if (owners.length < 2) ...[
          SizedBox(height: 6),
          Row(children: [
            Icon(LucideIcons.info, size: 16, color: ColoreStato.avviso),
            SizedBox(width: 8),
            Expanded(
              child: Testo.bodySmall('Consigliato: almeno due proprietari. Se l\'unico proprietario perde l\'accesso, la famiglia resta bloccata fino al recupero.', color: ColoreStato.avviso),
            ),
            if (session.can('membro:invite')) TextButton(onPressed: () => Get.toNamed('/tenant/members'), child: const Text('Invita')),
          ]),
        ],
      ],
      if (tenant?.spazioUsatoBytes != null) ...[
        SizedBox(height: 16),
        Testo.bodyMedium('Spazio usato: ${formatBytes(tenant!.spazioUsatoBytes!)}'),
      ],
      const Divider(height: 40),
      Testo.titleSmall('Modalità avanzata', fontWeight: FontWeight.w600),
      SizedBox(height: 8),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        value: tenant?.modalitaAvanzata ?? false,
        onChanged: canManage && !saving && tenant != null ? (v) => _save(avanzata: v) : null,
        title: const Text('Ruoli personalizzati con permessi uno per uno'),
        subtitle: const Text(
          'Spenta: Proprietario, Membro e Lettore bastano alla maggior parte delle famiglie. '
          'Accesa: compare la scheda "Ruoli" (es. "Nonni: legge i documenti e gestisce le scadenze"). '
          'Per spegnerla nessuno deve avere un ruolo personalizzato.',
        ),
      ),
      if (error != null) ...[SizedBox(height: 8), Testo.bodySmall(error!, color: ColoreStato.errore)],
    ]);
  }
}
