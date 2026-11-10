import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/documenti/model/anteprima.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';

/// API dell'ente (registrazione, struttura, iscritti, richieste) e del portale.
class EnteApi {
  static Dio get _dio => ApiClient.dio;

  // ---- Registrazione e profilo ----------------------------------------------

  /// Dati: tipo, denominazione, codiceFiscale, codiceMeccanografico | numeroRunts + emailIstituzionale, sedeLegale.
  static Future<EnteProfilo> registra(Map<String, dynamic> dati) => chiamaApi(() async => EnteProfilo.fromJson((await _dio.post('/enti', data: dati)).data as Map<String, dynamic>));

  static Future<EnteProfilo> profilo(String t) => chiamaApi(() async => EnteProfilo.fromJson((await _dio.get('/enti/$t/profilo')).data as Map<String, dynamic>));

  static Future<EnteProfilo> aggiornaProfilo(String t, Map<String, dynamic> dati) =>
      chiamaApi(() async => EnteProfilo.fromJson((await _dio.put('/enti/$t/profilo', data: dati)).data as Map<String, dynamic>));

  static Future<void> nuovoCodice(String t) => chiamaApi(() async => _dio.post('/enti/$t/email/codice'));

  static Future<EnteProfilo> confermaEmail(String t, String codice) =>
      chiamaApi(() async => EnteProfilo.fromJson((await _dio.post('/enti/$t/email/conferma', data: {'codice': codice.trim()})).data as Map<String, dynamic>));

  // ---- Struttura ------------------------------------------------------------

  static Future<List<AnnoEnte>> anni(String t) => chiamaApi(() async => ((await _dio.get('/enti/$t/anni')).data as List).map((j) => AnnoEnte.fromJson(j as Map<String, dynamic>)).toList());

  /// [gruppi]: id gruppo -> nuovo nome (null = esce).
  static Future<AnnoEnte> passaggioAnno(String t, String annoId, String nome, Map<String, String?> gruppi) => chiamaApi(() async {
        final response = await _dio.post('/enti/$t/anni/$annoId/passaggio', data: {
          'nome': nome.trim(),
          'gruppi': [for (final e in gruppi.entries) {'id': e.key, 'nuovoNome': e.value}],
        });
        return AnnoEnte.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<List<Sede>> sedi(String t) => chiamaApi(() async => ((await _dio.get('/enti/$t/sedi')).data as List).map((j) => Sede.fromJson(j as Map<String, dynamic>)).toList());

  static Future<void> salvaSede(String t, {String? id, required String nome, String? indirizzo}) => chiamaApi(() async {
        final body = {'nome': nome.trim(), 'indirizzo': indirizzo};
        id == null ? await _dio.post('/enti/$t/sedi', data: body) : await _dio.put('/enti/$t/sedi/$id', data: body);
      });

  static Future<void> eliminaSede(String t, String id) => chiamaApi(() async => _dio.delete('/enti/$t/sedi/$id'));

  static Future<List<Gruppo>> gruppi(String t, {String? annoId}) =>
      chiamaApi(() async => ((await _dio.get('/enti/$t/gruppi', queryParameters: {if (annoId != null) 'anno': annoId})).data as List).map((j) => Gruppo.fromJson(j as Map<String, dynamic>)).toList());

  static Future<void> salvaGruppo(String t, {String? id, required String nome, required String tipo, String? sedeId}) => chiamaApi(() async {
        final body = {'nome': nome.trim(), 'tipo': tipo, 'sedeId': sedeId};
        id == null ? await _dio.post('/enti/$t/gruppi', data: body) : await _dio.put('/enti/$t/gruppi/$id', data: body);
      });

  static Future<void> eliminaGruppo(String t, String id) => chiamaApi(() async => _dio.delete('/enti/$t/gruppi/$id'));

  static Future<void> operatoriGruppo(String t, String id, List<String> membershipIds) =>
      chiamaApi(() async => _dio.put('/enti/$t/gruppi/$id/operatori', data: {'membershipIds': membershipIds}));

  // ---- Iscritti --------------------------------------------------------------

  static Future<({List<Iscritto> iscritti, TotaliIscritti totali})> iscritti(String t, {String? gruppoId, String? q, String? filtro}) => chiamaApi(() async {
        final response = await _dio.get('/enti/$t/iscritti', queryParameters: {
          if (gruppoId != null) 'gruppo': gruppoId,
          if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
          if (filtro != null) 'filtro': filtro,
        });
        final data = response.data as Map<String, dynamic>;
        return (
          iscritti: (data['iscritti'] as List).map((j) => Iscritto.fromJson(j as Map<String, dynamic>)).toList(),
          totali: TotaliIscritti.fromJson(Map<String, dynamic>.from(data['totali'] as Map)),
        );
      });

  static Future<Iscritto> salvaIscritto(String t, {String? id, required String nome, required String cognome, String? dataNascita, required List<String> gruppiIds, List<Referente> referenti = const []}) =>
      chiamaApi(() async {
        final body = {
          'nome': nome.trim(),
          'cognome': cognome.trim(),
          'dataNascita': dataNascita,
          'gruppiIds': gruppiIds,
          if (id == null) 'referenti': referenti.map((r) => r.toJson()).toList(),
        };
        final response = id == null ? await _dio.post('/enti/$t/iscritti', data: body) : await _dio.put('/enti/$t/iscritti/$id', data: body);
        return Iscritto.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> eliminaIscritto(String t, String id) => chiamaApi(() async => _dio.delete('/enti/$t/iscritti/$id'));

  static Future<void> salvaReferente(String t, {required String iscrittoId, required Referente referente}) => chiamaApi(() async {
        referente.id == null
            ? await _dio.post('/enti/$t/iscritti/$iscrittoId/referenti', data: referente.toJson())
            : await _dio.put('/enti/$t/referenti/${referente.id}', data: referente.toJson());
      });

  static Future<void> eliminaReferente(String t, String id) => chiamaApi(() async => _dio.delete('/enti/$t/referenti/$id'));

  /// Anteprima (applica=false) o import delle righe valide.
  static Future<Map<String, dynamic>> importaCsv(String t, String csv, {required bool applica}) =>
      chiamaApi(() async => (await _dio.post('/enti/$t/iscritti/import', data: {'csv': csv, 'applica': applica})).data as Map<String, dynamic>);

  static Future<({int inviati, int saltati})> invita(String t, {List<String>? referenteIds, String? gruppoId}) => chiamaApi(() async {
        final response = await _dio.post('/enti/$t/inviti', data: {if (referenteIds != null) 'referenteIds': referenteIds, if (gruppoId != null) 'gruppoId': gruppoId});
        return (inviati: (response.data['inviati'] as num).toInt(), saltati: (response.data['saltati'] as num).toInt());
      });

  // ---- Richieste --------------------------------------------------------------

  static Future<AnteprimaInvio> anteprima(String t, {String? gruppoId, List<String>? iscrittoIds}) => chiamaApi(() async {
        final response = await _dio.post('/enti/$t/richieste/anteprima', data: {if (gruppoId != null) 'gruppoId': gruppoId, if (iscrittoIds != null) 'iscrittoIds': iscrittoIds});
        return AnteprimaInvio.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<RichiestaEnte> inviaRichiesta(
    String t, {
    required String tipo,
    required String titolo,
    String? istruzioni,
    String? entroIl,
    required String firmatari,
    String? gruppoId,
    List<String>? iscrittoIds,
    Uint8List? pdf,
    String? pdfNome,
    String? modelloId,
    bool chiediValidita = false,
    int? rinnovoGiorni,
    int? conservazioneMesi,
    String? modalitaFirma,
  }) =>
      chiamaApi(() async {
        final form = FormData()
          ..fields.addAll([
            MapEntry('tipo', tipo),
            if (modalitaFirma != null) MapEntry('modalitaFirma', modalitaFirma),
            MapEntry('titolo', titolo.trim()),
            MapEntry('firmatari', firmatari),
            if (modelloId != null) MapEntry('modelloId', modelloId),
            if (chiediValidita) const MapEntry('chiediValidita', '1'),
            if (rinnovoGiorni != null) MapEntry('rinnovoGiorni', '$rinnovoGiorni'),
            if (conservazioneMesi != null) MapEntry('conservazioneMesi', '$conservazioneMesi'),
            if (istruzioni != null && istruzioni.trim().isNotEmpty) MapEntry('istruzioni', istruzioni.trim()),
            if (entroIl != null) MapEntry('entroIl', entroIl),
            if (gruppoId != null) MapEntry('gruppoId', gruppoId),
          ])
          ..fields.addAll((iscrittoIds ?? []).map((id) => MapEntry('iscrittoIds[]', id)));
        if (pdf != null) {
          form.files.add(MapEntry('file', MultipartFile.fromBytes(pdf, filename: pdfNome ?? 'documento.pdf', contentType: DioMediaType('application', 'pdf'))));
        }
        final response = await _dio.post('/enti/$t/richieste', data: form, options: Options(contentType: Headers.multipartFormDataContentType, extra: {'retried': true}));
        return RichiestaEnte.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<List<RichiestaEnte>> richieste(String t, {bool soloAperte = false}) => chiamaApi(() async =>
      ((await _dio.get('/enti/$t/richieste', queryParameters: {if (soloAperte) 'aperte': 1})).data as List).map((j) => RichiestaEnte.fromJson(j as Map<String, dynamic>)).toList());

  static Future<RichiestaEnte> richiesta(String t, String id) => chiamaApi(() async => RichiestaEnte.fromJson((await _dio.get('/enti/$t/richieste/$id')).data as Map<String, dynamic>));

  static Future<Uint8List> allegato(String t, String id) => _bytes('/enti/$t/richieste/$id/allegato');

  /// File consegnato con il suo nome e il suo tipo veri (può essere un PDF, una foto o un DOCX).
  static Future<({Uint8List bytes, String nome, String mime})> fileRispostaConNome(String t, String id, String destinatarioId) => chiamaApi(() async {
        final r = await _dio.get<List<int>>('/enti/$t/richieste/$id/destinatari/$destinatarioId/file',
            options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(minutes: 5)));
        final disp = r.headers.value('content-disposition') ?? '';
        final utf8Nome = RegExp(r"filename\*=utf-8''([^;]+)", caseSensitive: false).firstMatch(disp)?.group(1);
        final nome = utf8Nome != null ? Uri.decodeComponent(utf8Nome) : RegExp(r'filename="?([^";]+)"?').firstMatch(disp)?.group(1);
        return (
          bytes: Uint8List.fromList(r.data ?? const []),
          nome: nome ?? 'consegna',
          mime: (r.headers.value('content-type') ?? 'application/octet-stream').split(';').first.trim(),
        );
      });

  /// Anteprima della risposta per la verifica: PDF/immagine, o il PDF ricavato da un documento Office.
  static Future<EsitoAnteprima> anteprimaRisposta(String t, String id, String destinatarioId) =>
      leggiAnteprima(_dio, '/enti/$t/richieste/$id/destinatari/$destinatarioId/anteprima');

  static Future<Uint8List> zip(String t, String id) => _bytes('/enti/$t/richieste/$id/zip');

  static Future<void> verifica(String t, String id, String destinatarioId, {required bool accetta, String? motivo}) =>
      chiamaApi(() async => _dio.post('/enti/$t/richieste/$id/destinatari/$destinatarioId/verifica', data: {'esito': accetta ? 'accetta' : 'rifirma', if (motivo != null) 'motivo': motivo}));

  static Future<int> sollecita(String t, String id, {List<String>? destinatarioIds}) => chiamaApi(() async {
        final response = await _dio.post('/enti/$t/richieste/$id/sollecito', data: {if (destinatarioIds != null) 'destinatarioIds': destinatarioIds});
        return (response.data['sollecitati'] as num).toInt();
      });

  static Future<void> annulla(String t, String id) => chiamaApi(() async => _dio.post('/enti/$t/richieste/$id/annulla'));

  // ---- Modelli e scadenze -------------------------------------------------------

  static Future<List<ModelloRichiesta>> modelli(String t) =>
      chiamaApi(() async => ((await _dio.get('/enti/$t/modelli')).data as List).map((j) => ModelloRichiesta.fromJson(j as Map<String, dynamic>)).toList());

  static Future<ModelloRichiesta> salvaModello(String t, {String? id, required Map<String, String?> campi, Uint8List? pdf, String? pdfNome}) => chiamaApi(() async {
        final form = FormData()..fields.addAll(campi.entries.where((e) => e.value != null && e.value!.isNotEmpty).map((e) => MapEntry(e.key, e.value!)));
        if (pdf != null) {
          form.files.add(MapEntry('file', MultipartFile.fromBytes(pdf, filename: pdfNome ?? 'modello.pdf', contentType: DioMediaType('application', 'pdf'))));
        }
        final response = await _dio.post(id == null ? '/enti/$t/modelli' : '/enti/$t/modelli/$id', data: form, options: Options(contentType: Headers.multipartFormDataContentType, extra: {'retried': true}));
        return ModelloRichiesta.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> eliminaModello(String t, String id) => chiamaApi(() async => _dio.delete('/enti/$t/modelli/$id'));

  static Future<List<DocumentoInScadenza>> documentiInScadenza(String t, {int giorni = 30}) => chiamaApi(() async =>
      ((await _dio.get('/enti/$t/documenti-in-scadenza', queryParameters: {'giorni': giorni})).data as List).map((j) => DocumentoInScadenza.fromJson(j as Map<String, dynamic>)).toList());

  // ---- Portale -----------------------------------------------------------------

  static Future<List<EnteProfilo>> portaleEnti({String? stato}) =>
      chiamaApi(() async => ((await _dio.get('/admin/enti', queryParameters: {if (stato != null) 'stato': stato})).data as List).map((j) => EnteProfilo.fromJson(j as Map<String, dynamic>)).toList());

  static Future<EnteProfilo> decidi(String t, {required String azione, String? nota, required bool registro, required bool telefonata}) => chiamaApi(() async {
        final response = await _dio.post('/admin/enti/$t/decisione', data: {'azione': azione, 'nota': nota, 'controlli': {'registro': registro, 'telefonata': telefonata}});
        return EnteProfilo.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<Uint8List> _bytes(String path) => chiamaApi(() async {
        final response = await _dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes, receiveTimeout: const Duration(minutes: 5)));
        return Uint8List.fromList(response.data ?? const []);
      });
}
