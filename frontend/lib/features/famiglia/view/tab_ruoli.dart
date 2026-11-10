import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/api/tenant_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Ruoli personalizzati (modalità avanzata): permessi scelti uno per uno.
/// Si assegnano poi dalla pagina Membri e accessi.
class TabRuoli extends StatefulWidget {
  const TabRuoli({super.key});

  @override
  State<TabRuoli> createState() => _TabRuoliState();
}

class _TabRuoliState extends State<TabRuoli> {
  SessionService get session => SessionService.to;
  List<TenantRole> ruoli = [];
  List<PermessoCatalogo> catalogo = [];
  bool loading = true;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => loading = true);
    try {
      final results = await Future.wait([TenantApi.roles(session.currentTenantId!), TenantApi.permissionCatalog()]);
      ruoli = results[0] as List<TenantRole>;
      catalogo = results[1] as List<PermessoCatalogo>;
      error = null;
    } on ApiException catch (e) {
      error = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  /// Permessi che posso concedere: solo quelli che ho (stessa regola di RoleGrantPolicy).
  bool _grantable(String code) {
    final mine = session.currentMembership?.permissions ?? const <String>{};
    return session.currentMembership?.isOwner == true || mine.contains(code);
  }

  Future<void> _edit([TenantRole? role]) async {
    final nome = TextEditingController(text: role?.name ?? '');
    final descr = TextEditingController(text: role?.description ?? '');
    final scelti = {...?role?.permissions};
    String? err;
    final perRisorsa = <String, List<PermessoCatalogo>>{};
    for (final p in catalogo) {
      perRisorsa.putIfAbsent(p.resourceLabel, () => []).add(p);
    }
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: Text(role == null ? 'Nuovo ruolo' : 'Modifica ruolo'),
          content: SizedBox(
            width: 560,
            height: 520,
            child: ListView(children: [
              TextField(controller: nome, decoration: const InputDecoration(labelText: 'Nome (es. Nonni)', isDense: true)),
              SizedBox(height: 12),
              TextField(controller: descr, decoration: const InputDecoration(labelText: 'Descrizione (facoltativa)', isDense: true)),
              SizedBox(height: 12),
              for (final entry in perRisorsa.entries) ...[
                Padding(padding: EdgeInsets.only(top: 8), child: Testo.labelLarge(entry.key, fontWeight: FontWeight.w600)),
                for (final p in entry.value)
                  CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    value: scelti.contains(p.code),
                    title: Text(p.label),
                    subtitle: _grantable(p.code) ? null : const Text('Non puoi concederlo: non ce l\'hai'),
                    onChanged: _grantable(p.code) ? (v) => setLocal(() => v == true ? scelti.add(p.code) : scelti.remove(p.code)) : null,
                  ),
              ],
              if (err != null) Testo.bodySmall(err!, color: ColoreStato.errore),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Annulla')),
            FilledButton(
              onPressed: () async {
                try {
                  await TenantApi.saveRole(session.currentTenantId!, roleId: role?.id, name: nome.text, description: descr.text, permissions: scelti.toList());
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } on ApiException catch (e) {
                  setLocal(() => err = e.message);
                }
              },
              child: const Text('Salva'),
            ),
          ],
        ),
      ),
    );
    nome.dispose();
    descr.dispose();
    if (saved == true) await _load();
  }

  Future<void> _delete(TenantRole r) async {
    try {
      await TenantApi.deleteRole(session.currentTenantId!, r.id);
      await _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (loading) return const Center(child: CircularProgressIndicator());
    final custom = ruoli.where((r) => !r.system).toList();
    final system = ruoli.where((r) => r.system).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (error != null) Testo.bodySmall(error!, color: ColoreStato.errore),
      Row(children: [
        Expanded(child: Testo.bodySmall('I ruoli si assegnano dalla pagina "Membri e accessi".', tenue: true)),
        if (!session.isReadOnly) FilledButton.icon(onPressed: () => _edit(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuovo ruolo')),
      ]),
      SizedBox(height: 8),
      if (custom.isEmpty) Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Testo.bodyMedium('Nessun ruolo personalizzato.')),
      for (final r in custom)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(LucideIcons.shield),
          title: Text(r.name),
          subtitle: Text('${r.permissions.length} permessi${(r.description ?? '').isEmpty ? '' : ' · ${r.description}'}'),
          trailing: session.isReadOnly
              ? null
              : Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(icon: const Icon(LucideIcons.pencil, size: 18), onPressed: () => _edit(r)),
                  IconButton(icon: const Icon(LucideIcons.trash, size: 18), onPressed: () => _delete(r)),
                ]),
        ),
      const Divider(height: 32),
      Testo.titleSmall('Ruoli standard', fontWeight: FontWeight.w600),
      for (final r in system)
        ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(LucideIcons.shield_check), title: Text(r.name), subtitle: Text(r.description ?? '')),
    ]);
  }
}
