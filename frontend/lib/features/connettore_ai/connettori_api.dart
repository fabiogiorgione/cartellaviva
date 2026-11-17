import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

/// Un Mac collegato alla famiglia che fa le analisi AI (docs/connettore-ai.md).
class ConnettoreAi {
  final String id, nome;
  final bool online;
  final DateTime? ultimoContatto;
  final String? approvatoDa, sistema, chip, motore, modello;
  final int? memoriaGb, contesto, durataMediaMs;
  final int lavoriFatti;

  const ConnettoreAi({
    required this.id,
    required this.nome,
    this.online = false,
    this.ultimoContatto,
    this.approvatoDa,
    this.sistema,
    this.chip,
    this.motore,
    this.modello,
    this.memoriaGb,
    this.contesto,
    this.durataMediaMs,
    this.lavoriFatti = 0,
  });

  factory ConnettoreAi.fromJson(Map<String, dynamic> j) => ConnettoreAi(
        id: j['id'] as String,
        nome: j['nome'] as String,
        online: j['online'] == true,
        ultimoContatto: DateTime.tryParse(j['ultimoContatto'] as String? ?? '')?.toLocal(),
        approvatoDa: j['approvatoDa'] as String?,
        sistema: j['sistema'] as String?,
        chip: j['chip'] as String?,
        motore: j['motore'] as String?,
        modello: j['modello'] as String?,
        memoriaGb: (j['memoriaGb'] as num?)?.toInt(),
        contesto: (j['contesto'] as num?)?.toInt(),
        durataMediaMs: (j['durataMediaMs'] as num?)?.toInt(),
        lavoriFatti: (j['lavoriFatti'] as num? ?? 0).toInt(),
      );

  /// "Apple M2 · 16 GB · qwen3 4B · 12 s a documento".
  String get descrizione => [
        if (chip != null) chip!,
        if (memoriaGb != null) '$memoriaGb GB',
        if (modello != null) modello!,
        if (durataMediaMs != null) '${(durataMediaMs! / 1000).toStringAsFixed(durataMediaMs! < 10000 ? 1 : 0).replaceAll('.', ',')} s a documento',
      ].join(' · ');
}

/// Abbinamento e gestione dei Mac della famiglia (permesso connettore_ai:manage).
class ConnettoriApi {
  static Dio get _dio => ApiClient.dio;

  static Future<List<ConnettoreAi>> elenco(String t) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/connettori-ai');
        return [for (final c in r.data['connettori'] as List) ConnettoreAi.fromJson(Map<String, dynamic>.from(c as Map))];
      });

  /// Una richiesta vale 10 minuti dal momento in cui il Mac la fa.
  static const durataRichiesta = Duration(minutes: 10);

  /// La richiesta da approvare con quel codice (404 se scaduta o sbagliata).
  static Future<({String userCode, String nome, DateTime? fattaIl})> richiesta(String t, String codice) => chiamaApi(() async {
        final r = await _dio.get('/tenants/$t/connettori-ai/richiesta', queryParameters: {'codice': codice});
        final scade = DateTime.tryParse(r.data['scadeIl'] as String? ?? '');
        return (userCode: r.data['userCode'] as String, nome: r.data['nome'] as String, fattaIl: scade?.subtract(durataRichiesta));
      });

  /// Il corpo dell'approvazione: il codice 2FA o la password, mai vuoti.
  static Map<String, dynamic> corpoApprovazione(String codice, {String? nome, String? codice2fa, String? password}) => {
        'userCode': codice,
        if (nome != null && nome.isNotEmpty) 'nome': nome,
        if (codice2fa != null && codice2fa.isNotEmpty) 'codice2fa': codice2fa,
        if (password != null && password.isNotEmpty) 'password': password,
      };

  static Future<void> approva(String t, String codice, {String? nome, String? codice2fa, String? password}) => chiamaApi(() async {
        await _dio.post('/tenants/$t/connettori-ai/approva', data: corpoApprovazione(codice, nome: nome, codice2fa: codice2fa, password: password));
      });

  static Future<void> nega(String t, String codice) => chiamaApi(() async {
        await _dio.post('/tenants/$t/connettori-ai/nega', data: {'userCode': codice});
      });

  static Future<void> rinomina(String t, String id, String nome) => chiamaApi(() async {
        await _dio.patch('/tenants/$t/connettori-ai/$id', data: {'nome': nome});
      });

  static Future<void> scollega(String t, String id) => chiamaApi(() async {
        await _dio.delete('/tenants/$t/connettori-ai/$id');
      });
}
