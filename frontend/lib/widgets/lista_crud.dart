import 'package:flutter/material.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

// Le liste caricano i dati con UltimaRichiesta.
export 'package:cartellaviva/helpers/ultima_richiesta.dart';

/// Pezzi comuni delle liste "CRUD" (portale, categorie...): carta, filtri,
/// tabella a righe alternate con selezione e ordinamento, paginazione,
/// azioni di riga e chip di stato.

InputDecoration listaDecorazione(String label, {String? hint}) =>
    InputDecoration(labelText: label, hintText: hint, isDense: true);

/// Una colonna: flex della larghezza e, se ordinabile, la chiave per il backend.

class ListaColonna {
  final String titolo;
  final int flex;
  final String? ordina;
  final bool destra;

  const ListaColonna(this.titolo, {this.flex = 2, this.ordina, this.destra = false});
}

Widget listaCarta(Widget child, {EdgeInsets? padding}) => Scheda(paddingAll: 0, child: Padding(padding: padding ?? EdgeInsets.all(16), child: child));

Widget listaFiltroTesto(TextEditingController c, String label, VoidCallback onCerca) => SizedBox(
      width: 240,
      child: TextField(controller: c, onSubmitted: (_) => onCerca(), decoration: listaDecorazione(label)),
    );

Widget listaFiltroScelta(String label, String? valore, List<(String?, String)> opzioni, ValueChanged<String?> onChanged) => SizedBox(
      width: 240,
      child: DropdownButtonFormField<String?>(
        // initialValue vale solo alla creazione: la chiave lo ricrea quando il valore
        // cambia da fuori (es. "Resetta filtri").
        key: ValueKey('$label=$valore'),
        initialValue: valore,
        isExpanded: true,
        decoration: listaDecorazione(label),
        items: [for (final o in opzioni) DropdownMenuItem(value: o.$1, child: Text(o.$2, overflow: TextOverflow.ellipsis))],
        onChanged: onChanged,
      ),
    );

/// Pagine numerate attorno a quella corrente, con prima/ultima e avanti/indietro.
class ListaPaginazione extends StatelessWidget {
  final int pagina;
  final int pagine;
  final ValueChanged<int> onPagina;

  const ListaPaginazione({required this.pagina, required this.pagine, required this.onPagina});

  @override
  Widget build(BuildContext context) {
    if (pagine <= 1) return const SizedBox.shrink();
    final primo = (pagina - 2).clamp(1, (pagine - 4).clamp(1, pagine));
    final ultimo = (primo + 4).clamp(1, pagine);
    final colore = Theme.of(context).colorScheme.primary;
    Widget numero(int n) => InkWell(
          onTap: n == pagina ? null : () => onPagina(n),
          customBorder: const CircleBorder(),
          child: Container(
            width: 34,
            height: 34,
            alignment: Alignment.center,
            decoration: n == pagina ? BoxDecoration(color: colore, shape: BoxShape.circle) : null,
            child: Text('$n', style: TextStyle(color: n == pagina ? Theme.of(context).colorScheme.onPrimary : null, fontWeight: n == pagina ? FontWeight.w700 : null)),
          ),
        );
    return Row(mainAxisSize: MainAxisSize.min, children: [
      IconButton(tooltip: 'Prima pagina', onPressed: pagina > 1 ? () => onPagina(1) : null, icon: const Icon(LucideIcons.chevrons_left, size: 18)),
      IconButton(tooltip: 'Indietro', onPressed: pagina > 1 ? () => onPagina(pagina - 1) : null, icon: const Icon(LucideIcons.chevron_left, size: 18)),
      for (var n = primo; n <= ultimo; n++) numero(n),
      IconButton(tooltip: 'Avanti', onPressed: pagina < pagine ? () => onPagina(pagina + 1) : null, icon: const Icon(LucideIcons.chevron_right, size: 18)),
      IconButton(tooltip: 'Ultima pagina', onPressed: pagina < pagine ? () => onPagina(pagine) : null, icon: const Icon(LucideIcons.chevrons_right, size: 18)),
    ]);
  }
}

/// Tabella a righe alternate, con selezione facoltativa e intestazioni ordinabili.
/// Su schermi stretti scorre in orizzontale.
class ListaTabella extends StatelessWidget {
  final List<ListaColonna> colonne;
  final List<Map<String, dynamic>> righe;
  final List<Widget> Function(Map<String, dynamic> riga) celle;
  final Set<String>? selezionati;
  final bool Function(Map<String, dynamic> riga)? selezionabile;
  final VoidCallback? onCambiaSelezione;
  final String ordina;
  final String verso;
  final ValueChanged<String>? onOrdina;

  /// Sotto questa larghezza la tabella scorre di lato.
  final double larghezzaMinima;

  const ListaTabella({
    required this.colonne,
    required this.righe,
    required this.celle,
    this.selezionati,
    this.selezionabile,
    this.onCambiaSelezione,
    this.ordina = '',
    this.verso = 'asc',
    this.onOrdina,
    this.larghezzaMinima = 1100,
  });

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final selezione = selezionati;
    final candidati = selezione == null ? const <String>[] : [for (final r in righe) if (selezionabile?.call(r) ?? true) r['id'] as String];
    final tutti = candidati.isNotEmpty && candidati.every(selezione!.contains);

    Widget riga(List<Widget> contenuto, {Color? sfondo, bool intestazione = false}) => Container(
          constraints: const BoxConstraints(minHeight: 46),
          padding: EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(color: sfondo, border: Border(bottom: BorderSide(color: tema.dividerColor.withAlpha(intestazione ? 160 : 90)))),
          child: Row(children: contenuto),
        );

    return LayoutBuilder(builder: (context, vincoli) {
      final larghezza = vincoli.maxWidth < larghezzaMinima ? larghezzaMinima : vincoli.maxWidth;
      return SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: SizedBox(
          width: larghezza,
          child: Column(children: [
            riga(intestazione: true, [
              if (selezione != null)
                SizedBox(
                  width: 40,
                  child: Checkbox(
                    value: tutti,
                    onChanged: candidati.isEmpty
                        ? null
                        : (v) {
                            v == true ? selezione.addAll(candidati) : selezione.removeAll(candidati);
                            onCambiaSelezione?.call();
                          },
                  ),
                ),
              for (final c in colonne)
                Expanded(
                  flex: c.flex,
                  child: InkWell(
                    onTap: c.ordina == null || onOrdina == null ? null : () => onOrdina!(c.ordina!),
                    child: Padding(
                      padding: EdgeInsets.symmetric(horizontal: 6, vertical: 12),
                      child: Row(mainAxisAlignment: c.destra ? MainAxisAlignment.end : MainAxisAlignment.start, children: [
                        Flexible(child: Testo.labelMedium(c.titolo, fontWeight: FontWeight.w600)),
                        if (c.ordina != null && c.ordina == ordina) Icon(verso == 'asc' ? LucideIcons.arrow_up : LucideIcons.arrow_down, size: 14),
                      ]),
                    ),
                  ),
                ),
            ]),
            for (final (i, r) in righe.indexed)
              riga(sfondo: i.isOdd ? tema.colorScheme.onSurface.withAlpha(8) : null, [
                if (selezione != null)
                  SizedBox(
                    width: 40,
                    child: (selezionabile?.call(r) ?? true)
                        ? Checkbox(
                            value: selezione.contains(r['id']),
                            onChanged: (v) {
                              v == true ? selezione.add(r['id'] as String) : selezione.remove(r['id']);
                              onCambiaSelezione?.call();
                            },
                          )
                        : null,
                  ),
                for (final (j, cella) in celle(r).indexed)
                  Expanded(
                    flex: colonne[j].flex,
                    child: Padding(padding: EdgeInsets.symmetric(horizontal: 6, vertical: 8), child: Align(alignment: colonne[j].destra ? Alignment.centerRight : Alignment.centerLeft, child: cella)),
                  ),
              ]),
          ]),
        ),
      );
    });
  }
}

/// Icona d'azione compatta per le righe.
Widget listaAzione(IconData icona, String tooltip, VoidCallback? onPressed, {Color? colore}) => IconButton(
      tooltip: tooltip,
      visualDensity: VisualDensity.compact,
      onPressed: onPressed,
      icon: Icon(icona, size: 18, color: onPressed == null ? null : colore),
    );

/// Le icone d'azione di una riga, allineate a destra. Se la colonna è troppo
/// stretta vanno a capo invece di uscire dalla cella (errore "overflowed by").
Widget listaAzioni(List<Widget> azioni) => Wrap(alignment: WrapAlignment.end, runAlignment: WrapAlignment.end, children: azioni);

Widget listaChip(String testo, Color colore) => Container(
      padding: EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: colore.withAlpha(30), borderRadius: BorderRadius.circular(12)),
      child: Text(testo, style: TextStyle(color: colore, fontSize: 12, fontWeight: FontWeight.w600)),
    );
