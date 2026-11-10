import 'package:flutter/material.dart';
import 'package:cartellaviva/helpers/theme/tema_app.dart';
import 'package:cartellaviva/widgets/griglia.dart';
import 'package:cartellaviva/widgets/briciole.dart';
import 'package:cartellaviva/widgets/scheda.dart';
import 'package:cartellaviva/widgets/testo.dart';
import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/app_constant.dart';
import 'package:cartellaviva/features/account/controller/anagrafica_controller.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/layout/layout.dart';

/// Dati anagrafici (privato / azienda). In onboarding è la prima pagina dopo
/// il primo accesso (es. con Google) e non si può saltare.
class AnagraficaScreen extends StatefulWidget {
  const AnagraficaScreen({super.key});

  @override
  State<AnagraficaScreen> createState() => _AnagraficaScreenState();
}

class _AnagraficaScreenState extends State<AnagraficaScreen> {
  final AnagraficaController controller = Get.put(AnagraficaController());

  @override
  Widget build(BuildContext context) {
    return Layout(
      child: GetBuilder(
        init: controller,
        global: false,
        // Controller della pagina: tornando qui si riparte da uno nuovo, mai da quello della pagina precedente.
        dispose: (_) => controller.onDelete(),
        builder: (c) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Testo.titleMedium(c.onboarding ? "Completa il tuo profilo" : "Dati anagrafici", fontSize: 18, fontWeight: FontWeight.w500),
                    Briciole(voci: [Briciola(nome: 'Account'), Briciola(nome: 'Anagrafica', attiva: true)]),
                  ],
                ),
              ),
              SizedBox(height: spazioPagina),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: spazioPagina / 2),
                child: c.loading
                    ? Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()))
                    : c.loadError != null
                        ? Scheda(paddingAll: 24, child: Testo.bodyMedium(c.loadError!))
                        : Griglia(spaziatura: spazioPagina, celle: [
                            if (c.onboarding) CellaGriglia(child: onboardingBanner()),
                            CellaGriglia(child: typeSelector()),
                            if (c.isAzienda) CellaGriglia(colonne: 6, child: aziendaCard()),
                            CellaGriglia(colonne: 6, child: personaCard()),
                            CellaGriglia(colonne: 6, child: nascitaCard()),
                            CellaGriglia(colonne: 6, child: residenzaCard()),
                            CellaGriglia(colonne: 6, child: contattiCard()),
                            CellaGriglia(colonne: 6, child: documentoCard()),
                            CellaGriglia(child: saveBar()),
                          ]),
              ),
            ],
          );
        },
      ),
    );
  }

  // ---- Sezioni ---------------------------------------------------------------------

  Widget onboardingBanner() {
    return Riquadro(color: context.colori.primary.withAlpha(25), paddingAll: 16, child: Row(children: [
        Icon(LucideIcons.user_round_check, color: context.colori.primary),
        SizedBox(width: 12),
        Expanded(
          child: Testo.bodyMedium('Benvenuto! Prima di iniziare completa i tuoi dati anagrafici: servono per intestare correttamente i documenti della famiglia.'),
        ),
      ]));
  }

  Widget typeSelector() {
    return section('Tipo di anagrafica', LucideIcons.id_card, [
      SegmentedButton<String>(
        segments: [
          ButtonSegment(value: 'privato', label: Text('Privato'), icon: Icon(LucideIcons.user)),
          ButtonSegment(value: 'azienda', label: Text('Azienda'), icon: Icon(LucideIcons.building)),
        ],
        selected: {controller.type},
        onSelectionChanged: (s) => controller.setType(s.first),
      ),
    ]);
  }

  Widget aziendaCard() {
    return section('Dati aziendali', LucideIcons.building, [
      field('denominazione', 'Denominazione / ragione sociale'),
      row([field('vat', 'Partita IVA'), field('sdi', 'Codice SDI')]),
      row([field('capitaleSociale', 'Capitale sociale'), field('sitoweb', 'Sito web')]),
    ]);
  }

  Widget personaCard() {
    final c = controller;
    return section(c.isAzienda ? 'Legale rappresentante' : 'Dati personali', LucideIcons.user, [
      row([field('nome', 'Nome'), field('cognome', 'Cognome')]),
      if (!c.isAzienda) ...[
        row([
          select('sesso', 'Sesso', c.options['sesso'] ?? [], c.sesso, (v) => c.set(() => c.sesso = v)),
          select('statoCivile', 'Stato civile', c.options['statoCivile'] ?? [], c.statoCivile, (v) => c.set(() => c.statoCivile = v)),
        ]),
        row([field('titolo', 'Titolo (es. Dott.)'), field('soprannome', 'Soprannome')]),
      ],
    ]);
  }

  Widget nascitaCard() {
    final c = controller;
    if (c.isAzienda) {
      return section('Codice fiscale', LucideIcons.hash, [field('codiceFiscale', 'Codice fiscale dell\'azienda (se diverso dalla P.IVA)')]);
    }
    return section('Nascita e codice fiscale', LucideIcons.baby, [
      dateField('dataDiNascita', 'Data di nascita', c.dataDiNascita, (d) => c.set(() => c.dataDiNascita = d)),
      countryField('nazioneDiNascita', 'Stato di nascita', c.nazioneDiNascita, (o) => c.set(() {
            c.nazioneDiNascita = o;
            c.comuneDiNascita = null;
          })),
      if (c.natoInItalia)
        comuneField('comuneDiNascita', 'Comune di nascita', c.comuneDiNascita, (o) => c.set(() => c.comuneDiNascita = o), storici: true)
      else
        dateField('inItaliaDal', 'In Italia dal', c.inItaliaDal, (d) => c.set(() => c.inItaliaDal = d)),
      countryField('cittadinanza', 'Cittadinanza', c.cittadinanza, (o) => c.set(() => c.cittadinanza = o)),
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(child: field('codiceFiscale', 'Codice fiscale', uppercase: true)),
        SizedBox(width: 8),
        Padding(
          padding: EdgeInsets.only(top: 4),
          child: TextButton.icon(onPressed: c.calcolaCodiceFiscale, icon: Icon(LucideIcons.calculator, size: 16), label: Text('Calcola')),
        ),
      ]),
      CheckboxListTile(
        value: c.codiceFiscaleManuale,
        onChanged: (v) => c.set(() => c.codiceFiscaleManuale = v ?? false),
        dense: true,
        contentPadding: EdgeInsets.zero,
        controlAffinity: ListTileControlAffinity.leading,
        title: Testo.bodySmall('Codice fiscale inserito manualmente (omocodia): controlla solo il formato'),
      ),
    ]);
  }

  Widget residenzaCard() {
    final c = controller;
    return section(c.isAzienda ? 'Sede legale' : 'Residenza', LucideIcons.house, [
      countryField('residenza.nazione', 'Stato', c.residenzaNazione, (o) => c.set(() {
            c.residenzaNazione = o;
            c.residenzaComune = null;
          })),
      if (c.residenzaInItalia)
        comuneField('residenza.comune', 'Comune', c.residenzaComune, (o) => c.set(() => c.residenzaComune = o))
      else
        field('comuneEstero', 'Località', errorKey: 'residenza.comuneEstero'),
      field('indirizzo', 'Indirizzo e numero civico', errorKey: 'residenza.indirizzo'),
      SizedBox(width: 160, child: field('cap', 'CAP', errorKey: 'residenza.cap')),
    ]);
  }

  Widget contattiCard() {
    return section('Contatti', LucideIcons.phone, [
      field('telefono', 'Telefono', keyboard: TextInputType.phone),
      field('email', 'Email', keyboard: TextInputType.emailAddress),
      field('pec', 'PEC', keyboard: TextInputType.emailAddress),
    ]);
  }

  Widget documentoCard() {
    final c = controller;
    return section('Documento d\'identità (facoltativo)', LucideIcons.file_badge, [
      select('tipoDocumentoIdentita', 'Tipo di documento', c.options['tipoDocumentoIdentita'] ?? [], c.tipoDocumentoIdentita,
          (v) => c.set(() => c.tipoDocumentoIdentita = v),
          allowEmpty: true),
      row([field('numeroDocumentoIdentita', 'Numero'), field('rilasciatoDaDocumentoIdentita', 'Rilasciato da')]),
      row([
        dateField('dataRilascioDocumentoIdentita', 'Data di rilascio', c.dataRilascioDocumentoIdentita,
            (d) => c.set(() => c.dataRilascioDocumentoIdentita = d)),
        dateField('dataScadenzaDocumentoIdentita', 'Data di scadenza', c.dataScadenzaDocumentoIdentita,
            (d) => c.set(() => c.dataScadenzaDocumentoIdentita = d),
            future: true),
      ]),
    ]);
  }

  Widget saveBar() {
    final formError = controller.error('_form');
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        if (formError != null) Expanded(child: Testo.bodySmall(formError, color: context.colori.danger)),
        FilledButton(style: FilledButton.styleFrom(backgroundColor: context.colori.primary, padding: EdgeInsets.symmetric(horizontal: 24, vertical: 16), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6))), onPressed: controller.saving ? null : controller.save, child: Testo.bodyMedium(controller.saving ? 'Salvataggio...' : (controller.onboarding ? 'Salva e continua' : 'Salva'), color: context.colori.onPrimary)),
      ],
    );
  }

  // ---- Campi ------------------------------------------------------------------------

  Widget section(String title, IconData icon, List<Widget> children) {
    return Scheda(elevazione: .7, paddingAll: 20, child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [Icon(icon, size: 18, color: context.colori.primary), SizedBox(width: 8), Testo.bodyMedium(title, fontWeight: FontWeight.w500)]),
          SizedBox(height: 16),
          ...children.expand((w) => [w, SizedBox(height: 12)]),
        ],
      ));
  }

  Widget row(List<Widget> children) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < children.length; i++) ...[
          if (i > 0) SizedBox(width: 12),
          Expanded(child: children[i]),
        ],
      ],
    );
  }

  InputDecoration decoration(String label, String errorKey, {Widget? suffix}) {
    return InputDecoration(
      labelText: label,
      errorText: controller.error(errorKey),
      errorMaxLines: 3,
      isDense: true,
      suffixIcon: suffix,
      contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 14),
    );
  }

  Widget field(String key, String label, {String? errorKey, TextInputType? keyboard, bool uppercase = false}) {
    return TextField(
      controller: controller.text[key],
      keyboardType: keyboard,
      textCapitalization: uppercase ? TextCapitalization.characters : TextCapitalization.none,
      style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500),
      decoration: decoration(label, errorKey ?? key),
    );
  }

  Widget select(String key, String label, List<Option> items, String? value, ValueChanged<String?> onChanged, {bool allowEmpty = false}) {
    return DropdownButtonFormField<String>(
      key: ValueKey('$key-$value'),
      initialValue: items.any((o) => o.value == value) ? value : null,
      isExpanded: true,
      decoration: decoration(label, key),
      items: [
        if (allowEmpty) DropdownMenuItem<String>(value: null, child: Testo.bodySmall('-')),
        ...items.map((o) => DropdownMenuItem(value: o.value, child: Testo.bodySmall(o.label, fontWeight: FontWeight.w500))),
      ],
      onChanged: onChanged,
    );
  }

  Widget dateField(String key, String label, DateTime? value, ValueChanged<DateTime?> onChanged, {bool future = false}) {
    final now = DateTime.now();
    return TextField(
      readOnly: true,
      controller: TextEditingController(text: value == null ? '' : dateFormatter.format(value)),
      style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500),
      decoration: decoration(label, key, suffix: Icon(LucideIcons.calendar, size: 16)),
      onTap: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: value ?? (future ? now : DateTime(now.year - 30)),
          firstDate: DateTime(1900),
          lastDate: future ? DateTime(now.year + 30) : now,
          locale: const Locale('it'),
        );
        if (picked != null) {
          onChanged(picked);
        }
      },
    );
  }

  Widget countryField(String key, String label, Option? value, ValueChanged<Option?> onChanged) {
    return _GeoAutocomplete(
      key: ValueKey('$key-${value?.value}'),
      initial: value,
      decoration: decoration(label, key),
      search: (q) async => controller.countries.where((c) => c.label.toLowerCase().startsWith(q.toLowerCase())).take(20),
      onSelected: onChanged,
    );
  }

  Widget comuneField(String key, String label, Option? value, ValueChanged<Option?> onChanged, {bool storici = false}) {
    return _GeoAutocomplete(
      key: ValueKey('$key-${value?.value}'),
      initial: value,
      decoration: decoration(label, key),
      search: (q) => AccountApi.comuni(q, storici: storici),
      onSelected: onChanged,
    );
  }
}

/// Autocompletamento su stati/comuni (ricerca lato server per i comuni).
class _GeoAutocomplete extends StatelessWidget {
  final Option? initial;
  final InputDecoration decoration;
  final Future<Iterable<Option>> Function(String query) search;
  final ValueChanged<Option?> onSelected;

  const _GeoAutocomplete({super.key, this.initial, required this.decoration, required this.search, required this.onSelected});

  @override
  Widget build(BuildContext context) {
    return Autocomplete<Option>(
      initialValue: TextEditingValue(text: initial?.label ?? ''),
      displayStringForOption: (o) => o.label,
      optionsBuilder: (value) async {
        if (value.text.trim().length < 2 || value.text == initial?.label) {
          return const Iterable<Option>.empty();
        }
        try {
          return await search(value.text);
        } catch (_) {
          return const Iterable<Option>.empty();
        }
      },
      onSelected: onSelected,
      fieldViewBuilder: (context, textController, focusNode, onSubmit) => TextField(
        controller: textController,
        focusNode: focusNode,
        style: stileTesto(context, TipoTesto.bodySmall, fontWeight: FontWeight.w500),
        decoration: decoration.copyWith(suffixIcon: Icon(LucideIcons.search, size: 16)),
        onChanged: (text) {
          if (text.isEmpty) {
            onSelected(null);
          }
        },
      ),
    );
  }
}
