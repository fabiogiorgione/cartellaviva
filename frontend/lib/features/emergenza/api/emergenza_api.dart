import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

DateTime? _d(dynamic v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;

/// Contatto d'emergenza visto dalla famiglia.
class ContattoEmergenza {
  final String id;
  final String email;
  final String? nome;
  final String stato;
  final List<String>? categorie;
  final int giorniAttesa;
  final String? richiestaStato;
  final DateTime? richiestaIl;
  final DateTime? eseguibileIl;
  final String? motivo;

  ContattoEmergenza({
    required this.id,
    required this.email,
    this.nome,
    required this.stato,
    this.categorie,
    this.giorniAttesa = 7,
    this.richiestaStato,
    this.richiestaIl,
    this.eseguibileIl,
    this.motivo,
  });

  factory ContattoEmergenza.fromJson(Map<String, dynamic> j) => ContattoEmergenza(
        id: j['id'] as String,
        email: j['email'] as String,
        nome: j['nome'] as String?,
        stato: j['stato'] as String,
        categorie: (j['categorie'] as List?)?.cast<String>(),
        giorniAttesa: (j['giorniAttesa'] as num?)?.toInt() ?? 7,
        richiestaStato: j['richiestaStato'] as String?,
        richiestaIl: _d(j['richiestaIl']),
        eseguibileIl: _d(j['eseguibileIl']),
        motivo: j['motivo'] as String?,
      );

  bool get inAttesa => richiestaStato == 'in_attesa';
  bool get concesso => richiestaStato == 'concessa';
  bool get gestibile => stato == 'invitato' || stato == 'attivo';

  String get statoLabel => switch (stato) {
        'invitato' => 'Invito inviato',
        'rifiutato' => 'Ha rifiutato',
        'revocato' => 'Revocato',
        _ => switch (richiestaStato) {
            'in_attesa' => 'Ha chiesto l\'accesso',
            'concessa' => 'Accesso concesso',
            'negata' => 'Contatto attivo (ultima richiesta respinta)',
            _ => 'Contatto attivo',
          },
      };
}

/// Famiglia di cui sono contatto d'emergenza.
class MioContattoEmergenza {
  final String id;
  final String famiglia;
  final String tenantId;
  final int giorniAttesa;
  final String? richiestaStato;
  final DateTime? eseguibileIl;
  final bool puoChiedere;

  MioContattoEmergenza({required this.id, required this.famiglia, required this.tenantId, this.giorniAttesa = 7, this.richiestaStato, this.eseguibileIl, this.puoChiedere = false});

  factory MioContattoEmergenza.fromJson(Map<String, dynamic> j) => MioContattoEmergenza(
        id: j['id'] as String,
        famiglia: j['famiglia'] as String,
        tenantId: j['tenantId'] as String,
        giorniAttesa: (j['giorniAttesa'] as num?)?.toInt() ?? 7,
        richiestaStato: j['richiestaStato'] as String?,
        eseguibileIl: _d(j['eseguibileIl']),
        puoChiedere: j['puoChiedere'] == true,
      );
}

class EmergenzaApi {
  static Dio get _dio => ApiClient.dio;

  static Future<List<ContattoEmergenza>> contatti(String tenantId) => chiamaApi(() async =>
      ((await _dio.get('/tenants/$tenantId/emergenza/contatti')).data as List).map((e) => ContattoEmergenza.fromJson(Map<String, dynamic>.from(e as Map))).toList());

  /// [categorieIds] null = tutte le categorie.
  static Future<void> invita(String tenantId, {required String email, required int giorniAttesa, List<String>? categorieIds}) => chiamaApi(() => _dio.post(
        '/tenants/$tenantId/emergenza/contatti',
        data: {'email': email.trim(), 'giorniAttesa': giorniAttesa, if (categorieIds != null) 'categorieIds': categorieIds},
      ));

  static Future<void> revoca(String tenantId, String id) => chiamaApi(() => _dio.post('/tenants/$tenantId/emergenza/contatti/$id/revoca'));

  static Future<void> nega(String tenantId, String id) => chiamaApi(() => _dio.post('/tenants/$tenantId/emergenza/contatti/$id/nega'));

  static Future<({String famiglia, String chi, String email})> invito(String token) => chiamaApi(() async {
        final d = (await _dio.get('/emergenza/invito', queryParameters: {'token': token}, options: ApiClient.anonymous)).data as Map;
        return (famiglia: d['famiglia'] as String, chi: d['chi'] as String, email: d['email'] as String);
      });

  static Future<void> accetta(String token) => chiamaApi(() => _dio.post('/emergenza/accetta', data: {'token': token}));

  static Future<void> rifiuta(String token) => chiamaApi(() => _dio.post('/emergenza/rifiuta', data: {'token': token}));

  static Future<List<MioContattoEmergenza>> miei() =>
      chiamaApi(() async => ((await _dio.get('/emergenza')).data as List).map((e) => MioContattoEmergenza.fromJson(Map<String, dynamic>.from(e as Map))).toList());

  static Future<void> richiedi(String id, String? motivo) => chiamaApi(() => _dio.post('/emergenza/$id/richiedi', data: {if (motivo != null && motivo.trim().isNotEmpty) 'motivo': motivo.trim()}));

  /// Opposizione dal link dell'email (pubblica).
  static Future<String> annulla(String token) =>
      chiamaApi(() async => (await _dio.post('/emergenza/annulla', data: {'token': token}, options: ApiClient.anonymous)).data['messaggio'] as String);
}
