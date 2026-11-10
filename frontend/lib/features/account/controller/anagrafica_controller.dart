import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:cartellaviva/features/account/api/account_api.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/helpers/services/session_service.dart';

/// Anagrafica dell'utente (privato o azienda).
/// In modalità onboarding (profilo non completato, es. dopo il primo accesso con
/// Google) il salvataggio completa il profilo e porta all'app.
class AnagraficaController extends GetxController {
  static const String italia = '100';

  bool loading = true;
  bool saving = false;
  String? loadError;
  late bool onboarding;

  String type = 'privato';
  Map<String, List<Option>> options = {};
  List<Option> countries = [];

  /// Errori del server per campo (chiavi normalizzate, vedi fieldKey).
  Map<String, String> errors = {};

  final Map<String, TextEditingController> text = {
    for (final k in [
      'nome', 'cognome', 'soprannome', 'titolo', 'codiceFiscale',
      'denominazione', 'vat', 'sdi', 'capitaleSociale', 'sitoweb',
      'numeroDocumentoIdentita', 'rilasciatoDaDocumentoIdentita',
      'indirizzo', 'cap', 'comuneEstero', 'telefono', 'email', 'pec',
    ])
      k: TextEditingController(),
  };

  String? sesso;
  String? statoCivile;
  String? tipoDocumentoIdentita;
  bool codiceFiscaleManuale = false;

  DateTime? dataDiNascita;
  DateTime? inItaliaDal;
  DateTime? dataRilascioDocumentoIdentita;
  DateTime? dataScadenzaDocumentoIdentita;

  Option? nazioneDiNascita;
  Option? comuneDiNascita;
  Option? cittadinanza;
  Option? residenzaNazione;
  Option? residenzaComune;

  bool get isAzienda => type == 'azienda';

  bool get natoInItalia => nazioneDiNascita?.value == italia;

  bool get residenzaInItalia => residenzaNazione?.value == italia;

  @override
  void onInit() {
    super.onInit();
    onboarding = !(SessionService.to.me?.profileCompleted ?? true);
    load();
  }

  @override
  void onClose() {
    for (final c in text.values) {
      c.dispose();
    }
    super.onClose();
  }

  String? error(String key) => errors[key];

  Future<void> load() async {
    loading = true;
    update();
    try {
      final results = await Future.wait([AccountApi.anagraficaOptions(), AccountApi.countries(), AccountApi.anagrafica()]);
      options = results[0] as Map<String, List<Option>>;
      countries = results[1] as List<Option>;
      final data = (results[2] as Map<String, dynamic>)['anagrafica'] as Map<String, dynamic>?;
      final italy = countries.firstWhereOrNull((c) => c.value == italia);
      if (data == null) {
        // Precompilazione per il primo accesso (es. nome e cognome da Google).
        final me = SessionService.to.me;
        text['nome']!.text = me?.firstName ?? '';
        text['cognome']!.text = me?.lastName ?? '';
        text['email']!.text = me?.email ?? '';
        nazioneDiNascita = italy;
        cittadinanza = italy;
        residenzaNazione = italy;
      } else {
        _fill(data);
      }
      loadError = null;
    } on ApiException catch (e) {
      loadError = e.message;
    }
    loading = false;
    update();
  }

  void _fill(Map<String, dynamic> d) {
    type = d['type'] as String? ?? 'privato';
    for (final key in text.keys) {
      if (d[key] is String) {
        text[key]!.text = d[key] as String;
      }
    }
    sesso = d['sesso'] as String?;
    statoCivile = d['statoCivile'] as String?;
    tipoDocumentoIdentita = d['tipoDocumentoIdentita'] as String?;
    codiceFiscaleManuale = d['codiceFiscaleManuale'] as bool? ?? false;
    dataDiNascita = _date(d['dataDiNascita']);
    inItaliaDal = _date(d['inItaliaDal']);
    dataRilascioDocumentoIdentita = _date(d['dataRilascioDocumentoIdentita']);
    dataScadenzaDocumentoIdentita = _date(d['dataScadenzaDocumentoIdentita']);
    nazioneDiNascita = _option(d['nazioneDiNascita'], d['nazioneDiNascitaLabel']);
    comuneDiNascita = _option(d['comuneDiNascita'], d['comuneDiNascitaLabel']);
    cittadinanza = _option(d['cittadinanza'], d['cittadinanzaLabel']);

    final addresses = (d['addresses'] as List? ?? []).cast<Map<String, dynamic>>();
    final residenza = addresses.firstWhereOrNull((a) => a['type'] == 'residenza');
    if (residenza != null) {
      residenzaNazione = _option(residenza['nazione'], residenza['nazioneLabel']);
      residenzaComune = _option(residenza['comune'], residenza['comuneLabel']);
      text['indirizzo']!.text = residenza['indirizzo'] as String? ?? '';
      text['cap']!.text = residenza['cap'] as String? ?? '';
      text['comuneEstero']!.text = residenza['comuneEstero'] as String? ?? '';
    }
    text['telefono']!.text = _first(d['telefono'], 'phoneNumber');
    text['email']!.text = _first(d['email'], 'email');
    text['pec']!.text = _first(d['pec'], 'email');
  }

  void setType(String value) {
    type = value;
    errors = {};
    update();
  }

  void set(void Function() change) {
    change();
    update();
  }

  Map<String, dynamic> payload() {
    String? t(String key) => text[key]!.text.trim().isEmpty ? null : text[key]!.text.trim();
    return {
      'type': type,
      for (final key in ['nome', 'cognome', 'soprannome', 'titolo', 'codiceFiscale', 'numeroDocumentoIdentita', 'rilasciatoDaDocumentoIdentita']) key: t(key),
      for (final key in ['denominazione', 'vat', 'sdi', 'capitaleSociale', 'sitoweb']) key: isAzienda ? t(key) : null,
      'sesso': sesso,
      'statoCivile': statoCivile,
      'tipoDocumentoIdentita': tipoDocumentoIdentita,
      'codiceFiscaleManuale': codiceFiscaleManuale,
      'dataDiNascita': _iso(dataDiNascita),
      'inItaliaDal': natoInItalia ? null : _iso(inItaliaDal),
      'dataRilascioDocumentoIdentita': _iso(dataRilascioDocumentoIdentita),
      'dataScadenzaDocumentoIdentita': _iso(dataScadenzaDocumentoIdentita),
      'nazioneDiNascita': _int(nazioneDiNascita),
      'comuneDiNascita': natoInItalia ? _int(comuneDiNascita) : null,
      'cittadinanza': _int(cittadinanza),
      'addresses': [
        {
          'type': 'residenza',
          'nazione': _int(residenzaNazione),
          'indirizzo': t('indirizzo'),
          'comune': residenzaInItalia ? _int(residenzaComune) : null,
          'comuneEstero': residenzaInItalia ? null : t('comuneEstero'),
          'cap': t('cap'),
        }
      ],
      'telefono': [if (t('telefono') != null) {'phoneNumber': t('telefono'), 'main': true}],
      'email': [if (t('email') != null) {'email': t('email'), 'main': true}],
      'pec': [if (t('pec') != null) {'email': t('pec'), 'main': true}],
    };
  }

  Future<void> calcolaCodiceFiscale() async {
    try {
      text['codiceFiscale']!.text = await AccountApi.calcolaCodiceFiscale(payload());
      errors.remove('codiceFiscale');
    } on ApiException catch (e) {
      errors['codiceFiscale'] = e.message;
    }
    update();
  }

  Future<void> save() async {
    if (saving) {
      return;
    }
    saving = true;
    errors = {};
    update();
    try {
      final result = await AccountApi.saveAnagrafica(payload());
      final session = SessionService.to;
      await session.loadMe();
      final createdTenant = result['createdTenantId'] as String?;
      if (createdTenant != null) {
        await session.selectTenant(createdTenant);
      }
      if (onboarding) {
        Get.offAllNamed('/dashboard');
        Get.snackbar('Benvenuto!', 'Profilo completato.', snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
      } else {
        Get.snackbar('Salvato', 'Dati anagrafici aggiornati.', snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
      }
    } on ApiException catch (e) {
      errors = {for (final entry in e.fieldErrors.entries) fieldKey(entry.key): entry.value};
      if (errors.isEmpty) {
        errors['_form'] = e.message;
      }
      Get.snackbar('Dati da correggere', errors.length == 1 && errors.containsKey('_form') ? e.message : 'Controlla i campi evidenziati.',
          snackPosition: SnackPosition.BOTTOM, margin: EdgeInsets.all(16), maxWidth: 480);
    }
    saving = false;
    update();
  }

  /// propertyPath del backend -> chiave del campo nel form.
  static String fieldKey(String path) {
    if (path.startsWith('addresses[0].')) {
      return 'residenza.${path.substring('addresses[0].'.length)}';
    }
    if (path == 'addresses') {
      return 'residenza.indirizzo';
    }
    for (final list in ['telefono', 'email', 'pec']) {
      if (path.startsWith('$list[')) {
        return list;
      }
    }
    return path;
  }

  static DateTime? _date(dynamic v) => v is String ? DateTime.tryParse(v) : null;

  static String? _iso(DateTime? d) => d == null
      ? null
      : '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  static int? _int(Option? o) => o == null ? null : int.tryParse(o.value);

  static Option? _option(dynamic value, dynamic label) => value == null ? null : Option(value.toString(), (label ?? value).toString());

  static String _first(dynamic list, String field) =>
      list is List && list.isNotEmpty ? ((list.first as Map)[field] as String? ?? '') : '';
}
