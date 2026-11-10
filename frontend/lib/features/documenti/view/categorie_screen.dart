import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/api/documento_api.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/icone_categoria.dart';
import 'package:cartellaviva/features/documenti/view/documenti_screen.dart' show hexColor;
import 'package:cartellaviva/layout/layout.dart';
import 'package:cartellaviva/widgets/lista_crud.dart';
import 'package:cartellaviva/widgets/messaggio.dart';
import 'package:cartellaviva/helpers/theme/colore_stato.dart';

/// Documenti → Categorie: le predefinite (sola lettura) e quelle della famiglia,
/// con filtri, numero di documenti e gestione (chi ha categoria:manage).
/// Sono poche: filtri e ordinamento si fanno qui, senza paginazione.
class CategorieScreen extends StatefulWidget {
  const CategorieScreen({super.key});

  @override
  State<CategorieScreen> createState() => _CategorieScreenState();
}

class _CategorieScreenState extends State<CategorieScreen> {
  SessionService get session => SessionService.to;
  final nome = TextEditingController();
  String? tipo;
  String? sensibili;
  String ordina = 'nome';
  String verso = 'asc';
  List<Categoria> categorie = [];
  bool loading = true;
  String? errore;

  static const colori = ['#275ac5', '#0f5c4c', '#16a34a', '#dc2626', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#64748b'];

  bool get canManage => session.can('categoria:manage');

  @override
  void initState() {
    super.initState();
    _carica();
  }

  @override
  void dispose() {
    nome.dispose();
    super.dispose();
  }

  Future<void> _carica() async {
    setState(() => loading = true);
    try {
      categorie = await DocumentoApi.categorie(session.currentTenantId!);
      errore = null;
    } on ApiException catch (e) {
      errore = e.message;
    }
    if (mounted) setState(() => loading = false);
  }

  List<Categoria> get _filtrate {
    final q = nome.text.trim().toLowerCase();
    final righe = categorie.where((c) {
      if (q.isNotEmpty && !c.nome.toLowerCase().contains(q)) return false;
      if (tipo == 'proprie' && c.sistema || tipo == 'sistema' && !c.sistema) return false;
      if (sensibili == 'si' && !c.isSensibile || sensibili == 'no' && c.isSensibile) return false;
      return true;
    }).toList();
    righe.sort((a, b) {
      final r = ordina == 'documenti' ? (a.documenti ?? -1).compareTo(b.documenti ?? -1) : a.nome.toLowerCase().compareTo(b.nome.toLowerCase());
      return verso == 'asc' ? r : -r;
    });
    return righe;
  }

  void _reset() {
    nome.clear();
    setState(() {
      tipo = null;
      sensibili = null;
    });
  }

  void _ordina(String campo) => setState(() {
        verso = ordina == campo && verso == 'asc' ? 'desc' : 'asc';
        ordina = campo;
      });

  void _snack(String m) => mostraMessaggio(context, m);

  Future<void> _modifica([Categoria? c]) async {
    final salvata = await showDialog<bool>(context: context, builder: (_) => _CategoriaDialog(categoria: c, colori: colori));
    if (salvata == true) {
      if (mounted) _snack(c == null ? 'Categoria creata.' : 'Categoria aggiornata.');
      await _carica();
    }
  }

  Future<void> _elimina(Categoria c) async {
    final altre = categorie.where((x) => x.id != c.id).toList()..sort((a, b) => a.nome.compareTo(b.nome));
    final eliminata = await showDialog<bool>(context: context, builder: (_) => _EliminaDialog(categoria: c, altre: altre));
    if (eliminata == true) {
      if (mounted) _snack('Categoria eliminata.');
      await _carica();
    }
  }

  @override
  Widget build(BuildContext context) {
    final righe = _filtrate;
    return Layout(
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: spazioPagina),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Flexible(child: Testo.titleMedium('Categorie', fontSize: 18, fontWeight: FontWeight.w500)),
            Briciole(voci: [Briciola(nome: 'Famiglia'), Briciola(nome: 'Documenti', rotta: '/documenti'), Briciola(nome: 'Categorie', attiva: true)]),
          ]),
          SizedBox(height: spazioPagina),
          listaCarta(Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, children: [
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (canManage) FilledButton.icon(onPressed: () => _modifica(), icon: const Icon(LucideIcons.plus, size: 18), label: const Text('Nuova categoria')),
              OutlinedButton.icon(onPressed: () => Get.toNamed('/documenti'), icon: const Icon(LucideIcons.files, size: 18), label: const Text('Vai ai documenti')),
            ]),
            Testo.bodyMedium('Categorie: ${righe.length}${righe.length == categorie.length ? '' : ' di ${categorie.length}'}', fontWeight: FontWeight.w500),
          ])),
          SizedBox(height: 16),
          listaCarta(Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(width: 240, child: TextField(controller: nome, onChanged: (_) => setState(() {}), decoration: listaDecorazione('Nome'))),
            listaFiltroScelta('Tipo', tipo, const [(null, 'Tutte'), ('proprie', 'Della famiglia'), ('sistema', 'Predefinite')], (v) => setState(() => tipo = v)),
            listaFiltroScelta('Sensibili', sensibili, const [(null, 'Tutte'), ('si', 'Solo sensibili (cifrate)'), ('no', 'Non sensibili')], (v) => setState(() => sensibili = v)),
            OutlinedButton.icon(onPressed: _reset, icon: const Icon(LucideIcons.rotate_ccw, size: 16), label: const Text('Resetta filtri')),
          ])),
          SizedBox(height: 16),
          listaCarta(
            padding: EdgeInsets.symmetric(horizontal: 8, vertical: 8),
            loading
                ? const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()))
                : errore != null
                    ? Padding(padding: EdgeInsets.all(16), child: Testo.bodyMedium(errore!, color: context.colori.danger))
                    : righe.isEmpty
                        ? Padding(padding: EdgeInsets.all(24), child: Testo.bodyMedium('Nessuna categoria con questi filtri.', tenue: true))
                        : ListaTabella(
                            ordina: ordina,
                            verso: verso,
                            onOrdina: _ordina,
                            colonne: const [
                              ListaColonna('Nome', flex: 5, ordina: 'nome'),
                              ListaColonna('Tipo', flex: 2),
                              ListaColonna('Protezione', flex: 3),
                              ListaColonna('Documenti', flex: 2, ordina: 'documenti'),
                              ListaColonna('Azioni', flex: 2, destra: true),
                            ],
                            righe: [for (final c in righe) {'id': c.id, 'c': c}],
                            celle: (r) {
                              final c = r['c'] as Categoria;
                              final colore = hexColor(c.colore);
                              return [
                                Row(children: [
                                  CircleAvatar(radius: 15, backgroundColor: colore.withAlpha(36), child: Icon(iconaCategoria(c.icona), size: 16, color: colore)),
                                  SizedBox(width: 10),
                                  Flexible(child: Text(c.nome, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
                                ]),
                                Text(c.sistema ? 'Predefinita' : 'Della famiglia'),
                                c.isSensibile
                                    ? Row(children: [Icon(LucideIcons.lock, size: 14, color: context.colori.success), SizedBox(width: 6), const Flexible(child: Text('Cifrata'))])
                                    : Testo.bodySmall('Normale', tenue: true),
                                c.documenti == null
                                    ? Testo.bodySmall('—', tenue: true)
                                    : InkWell(
                                        onTap: c.documenti! > 0 ? () => Get.toNamed('/documenti?categoria=${c.id}') : null,
                                        child: Text('${c.documenti}', style: TextStyle(color: c.documenti! > 0 ? context.colori.primary : null, fontWeight: FontWeight.w600)),
                                      ),
                                listaAzioni([
                                  if (c.sistema)
                                    Tooltip(message: 'Le categorie predefinite non si modificano', child: Icon(LucideIcons.lock_keyhole, size: 16, color: context.colori.secondary))
                                  else if (canManage) ...[
                                    listaAzione(LucideIcons.pencil, 'Modifica', () => _modifica(c)),
                                    listaAzione(LucideIcons.trash, 'Elimina', () => _elimina(c), colore: context.colori.danger),
                                  ],
                                ]),
                              ];
                            },
                          ),
          ),
          SizedBox(height: 8),
          Testo.bodySmall('Le categorie sensibili cifrano i documenti caricati e registrano ogni accesso. Le predefinite sono uguali per tutte le famiglie.', tenue: true),
        ]),
      ),
    );
  }
}

/// Nuova categoria o modifica: nome, icona, colore, sensibile.
class _CategoriaDialog extends StatefulWidget {
  final Categoria? categoria;
  final List<String> colori;

  const _CategoriaDialog({this.categoria, required this.colori});

  @override
  State<_CategoriaDialog> createState() => _CategoriaDialogState();
}

class _CategoriaDialogState extends State<_CategoriaDialog> {
  late final nome = TextEditingController(text: widget.categoria?.nome ?? '');
  late String icona = widget.categoria?.icona ?? 'folder';
  late String colore = widget.categoria?.colore ?? widget.colori.first;
  late bool sensibile = widget.categoria?.isSensibile ?? false;
  bool busy = false;
  String? errore;

  @override
  void dispose() {
    nome.dispose();
    super.dispose();
  }

  Future<void> _salva() async {
    if (nome.text.trim().isEmpty) {
      setState(() => errore = 'Scrivi il nome della categoria.');
      return;
    }
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await DocumentoApi.saveCategoria(SessionService.to.currentTenantId!, id: widget.categoria?.id, nome: nome.text, icona: icona, colore: colore, isSensibile: sensibile);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.categoria;
    final tinta = hexColor(colore);
    final cambiaSensibile = c != null && c.isSensibile != sensibile && (c.documenti ?? 0) > 0;
    return AlertDialog(
      title: Text(c == null ? 'Nuova categoria' : 'Modifica categoria'),
      content: SizedBox(
        width: 460,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              CircleAvatar(radius: 20, backgroundColor: tinta.withAlpha(36), child: Icon(iconaCategoria(icona), color: tinta)),
              SizedBox(width: 12),
              Expanded(child: TextField(controller: nome, maxLength: 80, autofocus: c == null, decoration: listaDecorazione('Nome'))),
            ]),
            SizedBox(height: 8),
            Testo.labelMedium('Icona', fontWeight: FontWeight.w500),
            SizedBox(height: 8),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final nomeIcona in iconeProposte)
                InkWell(
                  borderRadius: BorderRadius.circular(8),
                  onTap: () => setState(() => icona = nomeIcona),
                  child: Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(
                      color: icona == nomeIcona ? tinta.withAlpha(36) : null,
                      border: Border.all(color: icona == nomeIcona ? tinta : Theme.of(context).dividerColor),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Icon(iconaCategoria(nomeIcona), size: 18, color: icona == nomeIcona ? tinta : null),
                  ),
                ),
            ]),
            SizedBox(height: 16),
            Testo.labelMedium('Colore', fontWeight: FontWeight.w500),
            SizedBox(height: 8),
            Wrap(spacing: 8, children: [
              for (final hex in widget.colori)
                InkWell(
                  customBorder: const CircleBorder(),
                  onTap: () => setState(() => colore = hex),
                  child: CircleAvatar(radius: 14, backgroundColor: hexColor(hex), child: colore == hex ? const Icon(LucideIcons.check, size: 14, color: Colors.white) : null),
                ),
            ]),
            SizedBox(height: 8),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: sensibile,
              onChanged: (v) => setState(() => sensibile = v),
              title: const Text('Dati sensibili'),
              subtitle: const Text('I documenti caricati in questa categoria vengono cifrati sul server e ogni accesso è registrato.'),
            ),
            if (cambiaSensibile)
              Testo.bodySmall(sensibile
                    ? 'Vale per i nuovi caricamenti: i ${c.documenti} documenti già presenti restano come sono stati caricati.'
                    : 'I ${c.documenti} documenti già cifrati restano cifrati; i nuovi non lo saranno.', color: ColoreStato.avviso),
            if (errore != null) Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall(errore!, color: ColoreStato.errore)),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(onPressed: busy ? null : _salva, child: const Text('Salva')),
      ],
    );
  }
}

/// Elimina: se la categoria ha documenti, si sceglie dove spostarli.
class _EliminaDialog extends StatefulWidget {
  final Categoria categoria;
  final List<Categoria> altre;

  const _EliminaDialog({required this.categoria, required this.altre});

  @override
  State<_EliminaDialog> createState() => _EliminaDialogState();
}

class _EliminaDialogState extends State<_EliminaDialog> {
  String? destinazione;
  bool busy = false;
  String? errore;

  int get documenti => widget.categoria.documenti ?? 0;

  Future<void> _elimina() async {
    setState(() {
      busy = true;
      errore = null;
    });
    try {
      await DocumentoApi.deleteCategoria(SessionService.to.currentTenantId!, widget.categoria.id, spostaIn: documenti > 0 ? destinazione : null);
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      setState(() => errore = e.message);
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.categoria;
    final scelta = widget.altre.where((x) => x.id == destinazione).firstOrNull;
    return AlertDialog(
      title: Text('Eliminare "${c.nome}"?'),
      content: SizedBox(
        width: 460,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (documenti == 0)
            const Text('La categoria è vuota: si elimina subito.')
          else ...[
            Text('Contiene $documenti ${documenti == 1 ? 'documento' : 'documenti'}: scegli dove spostarli prima di eliminarla. Le scadenze collegate seguono i documenti.'),
            SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: destinazione,
              isExpanded: true,
              decoration: listaDecorazione('Sposta i documenti in'),
              items: [
                for (final a in widget.altre)
                  DropdownMenuItem(
                    value: a.id,
                    child: Row(children: [
                      Icon(iconaCategoria(a.icona), size: 16, color: hexColor(a.colore)),
                      SizedBox(width: 8),
                      Flexible(child: Text('${a.nome}${a.isSensibile ? ' (cifrata)' : ''}', overflow: TextOverflow.ellipsis)),
                    ]),
                  ),
              ],
              onChanged: (v) => setState(() => destinazione = v),
            ),
            if (scelta != null && c.isSensibile && !scelta.isSensibile) ...[
              SizedBox(height: 8),
              Testo.bodySmall('I documenti restano cifrati anche nella nuova categoria.', tenue: true),
            ],
            if (scelta != null && !c.isSensibile && scelta.isSensibile) ...[
              SizedBox(height: 8),
              Testo.bodySmall('Attenzione: i documenti già caricati non vengono cifrati spostandoli; lo saranno solo i nuovi.', color: ColoreStato.avviso),
            ],
          ],
          SizedBox(height: 8),
          Testo.bodySmall('Se un contatto d\'emergenza poteva vedere solo alcune categorie, questa viene tolta dal suo elenco.', tenue: true),
          if (errore != null) Padding(padding: EdgeInsets.only(top: 8), child: Testo.bodySmall(errore!, color: ColoreStato.errore)),
        ]),
      ),
      actions: [
        TextButton(onPressed: busy ? null : () => Navigator.pop(context, false), child: const Text('Annulla')),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: ColoreStato.errore),
          onPressed: busy || (documenti > 0 && destinazione == null) ? null : _elimina,
          child: Text(documenti > 0 ? 'Sposta ed elimina' : 'Elimina'),
        ),
      ],
    );
  }
}
