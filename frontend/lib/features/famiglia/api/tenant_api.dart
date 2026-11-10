import 'package:dio/dio.dart';
import 'package:cartellaviva/helpers/services/api/api_client.dart';
import 'package:cartellaviva/helpers/services/api/api_config.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';
import 'package:cartellaviva/features/famiglia/model/tenant.dart';
import 'package:cartellaviva/features/famiglia/model/tenant_member.dart';

/// Endpoint di gestione tenant/membri/inviti. Ogni errore diventa ApiException.
class TenantApi {
  static final Dio _dio = ApiClient.dio;

  /// Crea una famiglia con l'utente corrente come proprietario; restituisce l'id.
  static Future<String> createFamily(String name) => chiamaApi(() async {
        final response = await _dio.post('/tenants', data: {'name': name.trim(), 'type': 'privato'});
        return response.data['id'] as String;
      });

  static Future<TenantDettaglio> tenant(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId');
        return TenantDettaglio.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<TenantDettaglio> updateTenant(String tenantId, {String? name, bool? modalitaAvanzata}) => chiamaApi(() async {
        final response = await _dio.patch(
          '/tenants/$tenantId',
          data: {if (name != null) 'name': name.trim(), if (modalitaAvanzata != null) 'modalitaAvanzata': modalitaAvanzata},
          options: Options(contentType: 'application/merge-patch+json'),
        );
        return TenantDettaglio.fromJson(response.data as Map<String, dynamic>);
      });

  /// Link di download dello ZIP (assoluto, monouso, valido 24 ore).
  static Future<({Uri url, DateTime scadeIl})> createExport(String tenantId) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/esportazioni');
        final data = response.data as Map<String, dynamic>;
        return (
          url: Uri.parse(ApiConfig.baseUrl).resolve(data['downloadPath'] as String),
          scadeIl: DateTime.parse(data['scadeIl'] as String).toLocal(),
        );
      });

  static Future<void> requestDeletion(String tenantId, {required String conferma, required String codice}) => chiamaApi(() async {
        await _dio.post('/tenants/$tenantId/eliminazione', data: {'conferma': conferma.trim(), 'codice': codice.trim()});
      });

  static Future<void> cancelDeletion(String tenantId) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/eliminazione');
      });

  static Future<List<PermessoCatalogo>> permissionCatalog() => chiamaApi(() async {
        final response = await _dio.get('/permissions');
        return (response.data as List).map((j) => PermessoCatalogo.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<TenantRole> saveRole(String tenantId, {String? roleId, required String name, String? description, required List<String> permissions}) =>
      chiamaApi(() async {
        final body = {'name': name.trim(), 'description': (description ?? '').trim().isEmpty ? null : description!.trim(), 'permissions': permissions};
        final response = roleId == null
            ? await _dio.post('/tenants/$tenantId/roles', data: body)
            : await _dio.patch('/tenants/$tenantId/roles/$roleId', data: body, options: Options(contentType: 'application/merge-patch+json'));
        return TenantRole.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> deleteRole(String tenantId, String roleId) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/roles/$roleId');
      });

  static Future<List<TenantMember>> members(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/members');
        return (response.data as List).map((j) => TenantMember.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<List<TenantRole>> roles(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/roles');
        return (response.data as List).map((j) => TenantRole.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<TenantMember> changeRole(String tenantId, String memberId, String roleId) =>
      _patchMember(tenantId, memberId, {'roleId': roleId});

  static Future<TenantMember> changeStatus(String tenantId, String memberId, String status) =>
      _patchMember(tenantId, memberId, {'status': status});

  static Future<void> removeMember(String tenantId, String memberId) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/members/$memberId');
      });

  static Future<List<TenantInvitation>> invitations(String tenantId) => chiamaApi(() async {
        final response = await _dio.get('/tenants/$tenantId/invitations');
        return (response.data as List).map((j) => TenantInvitation.fromJson(j as Map<String, dynamic>)).toList();
      });

  static Future<TenantInvitation> invite(String tenantId, String email, String roleId) => chiamaApi(() async {
        final response = await _dio.post('/tenants/$tenantId/invitations', data: {'email': email.trim(), 'roleId': roleId});
        return TenantInvitation.fromJson(response.data as Map<String, dynamic>);
      });

  static Future<void> revokeInvitation(String tenantId, String invitationId) => chiamaApi(() async {
        await _dio.delete('/tenants/$tenantId/invitations/$invitationId');
      });

  static Future<InvitationPreview> previewInvitation(String token) => chiamaApi(() async {
        final response = await _dio.get('/invitations/preview', queryParameters: {'token': token}, options: ApiClient.anonymous);
        return InvitationPreview.fromJson(response.data as Map<String, dynamic>);
      });

  /// Se l'utente è autenticato basta il token; altrimenti crea l'account.
  /// Restituisce l'id del tenant in cui si è entrati.
  static Future<String> acceptInvitation(String token, {String? firstName, String? lastName, String? password, bool authenticated = false}) =>
      chiamaApi(() async {
        final response = await _dio.post(
          '/invitations/accept',
          data: {
            'token': token,
            if (firstName != null) 'firstName': firstName.trim(),
            if (lastName != null) 'lastName': lastName.trim(),
            if (password != null) 'password': password,
          },
          options: authenticated ? null : ApiClient.anonymous,
        );
        return response.data['tenantId'] as String;
      });

  static Future<TenantMember> _patchMember(String tenantId, String memberId, Map<String, dynamic> body) => chiamaApi(() async {
        final response = await _dio.patch(
          '/tenants/$tenantId/members/$memberId',
          data: body,
          options: Options(contentType: 'application/merge-patch+json'),
        );
        return TenantMember.fromJson(response.data as Map<String, dynamic>);
      });
}
