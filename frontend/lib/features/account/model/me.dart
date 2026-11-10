/// Risorse che il superadmin amministra anche senza Membership
/// (specchio di TenantPermissionVoter::PLATFORM_ADMIN_RESOURCES).
const Set<String> platformAdminResources = {'tenant', 'membro', 'ruolo', 'audit'};

/// Appartenenza dell'utente corrente a un tenant, con i permessi effettivi del ruolo.
class TenantMembership {
  final String membershipId;
  final String tenantId;
  final String tenantName;
  final String tenantType;
  final String roleId;
  final String roleSlug;
  final String roleName;
  final Set<String> permissions;

  TenantMembership({
    required this.membershipId,
    required this.tenantId,
    required this.tenantName,
    required this.tenantType,
    required this.roleId,
    required this.roleSlug,
    required this.roleName,
    required this.permissions,
  });

  bool get isOwner => roleSlug == 'owner';

  /// Stessa regola del voter backend, usata SOLO per abilitare/disabilitare la UI:
  /// il permesso vale se il ruolo ha il codice esatto o la variante ":all";
  /// con la sola variante ":own" serve essere il proprietario della risorsa.
  bool can(String permission, {String? resourceOwnerId, String? currentUserId}) {
    if (permissions.contains(permission) || permissions.contains('$permission:all')) {
      return true;
    }
    if (permissions.contains('$permission:own')) {
      return resourceOwnerId != null && resourceOwnerId == currentUserId;
    }
    return false;
  }

  factory TenantMembership.fromJson(Map<String, dynamic> json) {
    final tenant = json['tenant'] as Map<String, dynamic>;
    final role = json['role'] as Map<String, dynamic>;
    return TenantMembership(
      membershipId: json['membershipId'] as String,
      tenantId: tenant['id'] as String,
      tenantName: tenant['name'] as String,
      tenantType: tenant['type'] as String,
      roleId: role['id'] as String,
      roleSlug: role['slug'] as String,
      roleName: role['name'] as String,
      permissions: (json['permissions'] as List).cast<String>().toSet(),
    );
  }
}

/// Risposta di GET /api/me.
class Me {
  final String id;
  final String email;
  final String firstName;
  final String lastName;
  final bool isSuperAdmin;
  final List<TenantMembership> memberships;

  /// Falso finché l'anagrafica non è stata compilata (onboarding).
  final bool profileCompleted;
  final bool twoFactorEnabled;
  final bool googleLinked;
  final bool hasPassword;

  /// Falso dopo la registrazione finché non si apre il link: niente inviti né collegamenti.
  final bool emailVerificata;

  /// Falso al primo accesso: la home mostra una breve introduzione.
  final bool introVista;

  /// Ruolo nel portale: superadmin | supporto | sola_lettura | null.
  final String? platformRole;

  /// Dopo un recupero dell'accesso la 2FA va riattivata prima di tutto.
  final bool twoFactorObbligatoria;

  bool get isPortale => platformRole != null;

  bool get isSupporto => platformRole == 'superadmin' || platformRole == 'supporto';

  Me({
    this.platformRole,
    this.twoFactorObbligatoria = false,
    required this.id,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.isSuperAdmin,
    required this.memberships,
    this.profileCompleted = true,
    this.twoFactorEnabled = false,
    this.googleLinked = false,
    this.hasPassword = true,
    this.emailVerificata = true,
    this.introVista = true,
  });

  String get fullName => '$firstName $lastName'.trim();

  String get initials =>
      '${firstName.isNotEmpty ? firstName[0] : ''}${lastName.isNotEmpty ? lastName[0] : ''}'.toUpperCase();

  TenantMembership? membershipFor(String? tenantId) {
    for (final membership in memberships) {
      if (membership.tenantId == tenantId) {
        return membership;
      }
    }
    return null;
  }

  factory Me.fromJson(Map<String, dynamic> json) {
    return Me(
      id: json['id'] as String,
      email: json['email'] as String,
      firstName: json['firstName'] as String,
      lastName: json['lastName'] as String,
      isSuperAdmin: json['isSuperAdmin'] as bool? ?? false,
      profileCompleted: json['profileCompleted'] as bool? ?? true,
      twoFactorEnabled: json['twoFactorEnabled'] as bool? ?? false,
      googleLinked: json['googleLinked'] as bool? ?? false,
      hasPassword: json['hasPassword'] as bool? ?? true,
      emailVerificata: json['emailVerificata'] != false,
      introVista: json['introVista'] != false,
      platformRole: json['platformRole'] as String?,
      twoFactorObbligatoria: json['twoFactorObbligatoria'] == true,
      memberships: (json['memberships'] as List)
          .map((m) => TenantMembership.fromJson(m as Map<String, dynamic>))
          .toList(),
    );
  }
}
