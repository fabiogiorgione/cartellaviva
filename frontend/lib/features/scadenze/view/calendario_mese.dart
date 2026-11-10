import 'package:flutter/material.dart';
import 'package:flutter_lucide/flutter_lucide.dart';

/// Un evento nel calendario: giorno, titolo e colore del pallino.
class EventoCalendario<T> {
  final DateTime giorno;
  final String titolo;
  final Color colore;
  final T valore;

  const EventoCalendario({required this.giorno, required this.titolo, required this.colore, required this.valore});
}

/// Calendario di un mese (settimana da lunedì) con un pallino per ogni evento
/// e, sotto, gli eventi del giorno scelto.
class CalendarioMese<T> extends StatefulWidget {
  final List<EventoCalendario<T>> eventi;
  final ValueChanged<T>? onEvento;

  /// Mese mostrato all'apertura (predefinito: quello di oggi).
  final DateTime? mese;

  const CalendarioMese({super.key, required this.eventi, this.onEvento, this.mese});

  @override
  State<CalendarioMese<T>> createState() => _CalendarioMeseState<T>();
}

class _CalendarioMeseState<T> extends State<CalendarioMese<T>> {
  static const _mesi = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
  static const _giorni = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];

  late DateTime _mese;
  late DateTime _scelto;

  @override
  void initState() {
    super.initState();
    final oggi = DateTime.now();
    final base = widget.mese ?? oggi;
    _mese = DateTime(base.year, base.month);
    _scelto = widget.mese == null ? DateTime(oggi.year, oggi.month, oggi.day) : _mese;
  }

  static bool _stessoGiorno(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;

  List<EventoCalendario<T>> _del(DateTime giorno) => widget.eventi.where((e) => _stessoGiorno(e.giorno, giorno)).toList();

  void _sposta(int mesi) => setState(() => _mese = DateTime(_mese.year, _mese.month + mesi));

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    // Celle dal lunedì della prima settimana: i giorni prima del 1° restano vuoti.
    final vuoteIniziali = _mese.weekday - 1;
    final giorniNelMese = DateTime(_mese.year, _mese.month + 1, 0).day;
    final celle = ((vuoteIniziali + giorniNelMese) / 7).ceil() * 7;
    final delGiorno = _del(_scelto);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Row(children: [
        IconButton(tooltip: 'Mese precedente', icon: const Icon(LucideIcons.chevron_left), onPressed: () => _sposta(-1)),
        Expanded(child: Text('${_mesi[_mese.month - 1]} ${_mese.year}', textAlign: TextAlign.center, style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600))),
        IconButton(tooltip: 'Mese successivo', icon: const Icon(LucideIcons.chevron_right), onPressed: () => _sposta(1)),
      ]),
      const SizedBox(height: 4),
      Row(children: [for (final g in _giorni) Expanded(child: Text(g, textAlign: TextAlign.center, style: tema.textTheme.labelSmall?.copyWith(color: tema.hintColor)))]),
      const SizedBox(height: 4),
      GridView.count(
        crossAxisCount: 7,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        childAspectRatio: 1.3,
        children: [
          for (var i = 0; i < celle; i++)
            if (i < vuoteIniziali || i >= vuoteIniziali + giorniNelMese) const SizedBox.shrink() else _cella(tema, DateTime(_mese.year, _mese.month, i - vuoteIniziali + 1)),
        ],
      ),
      const Divider(height: 24),
      if (delGiorno.isEmpty)
        Padding(padding: const EdgeInsets.all(8), child: Text('Nessuna scadenza il ${_scelto.day} ${_mesi[_scelto.month - 1].toLowerCase()}.', style: TextStyle(color: tema.hintColor)))
      else
        for (final e in delGiorno)
          ListTile(
            dense: true,
            leading: CircleAvatar(radius: 5, backgroundColor: e.colore),
            title: Text(e.titolo),
            onTap: widget.onEvento == null ? null : () => widget.onEvento!(e.valore),
          ),
    ]);
  }

  Widget _cella(ThemeData tema, DateTime giorno) {
    final eventi = _del(giorno);
    final scelto = _stessoGiorno(giorno, _scelto);
    final oggi = _stessoGiorno(giorno, DateTime.now());
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () => setState(() => _scelto = giorno),
      child: Container(
        margin: const EdgeInsets.all(2),
        decoration: BoxDecoration(
          color: scelto ? tema.colorScheme.primary.withAlpha(30) : null,
          border: oggi ? Border.all(color: tema.colorScheme.primary) : null,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Text('${giorno.day}', style: TextStyle(fontWeight: scelto || oggi ? FontWeight.w700 : null)),
          const SizedBox(height: 3),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (final e in eventi.take(4)) Container(width: 6, height: 6, margin: const EdgeInsets.symmetric(horizontal: 1), decoration: BoxDecoration(color: e.colore, shape: BoxShape.circle)),
          ]),
        ]),
      ),
    );
  }
}
