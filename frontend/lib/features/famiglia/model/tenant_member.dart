class TenantMember {
  final String id;
  final String userId;
  final String email;
  final String firstName;
  final String lastName;
  final String roleId;
  final String roleSlug;
  final String roleName;
  final String status;
  final bool isCurrentUser;
  final DateTime? joinedAt;

  TenantMember({
    required this.id,
    required this.userId,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.roleId,
    required this.roleSlug,
    required this.roleName,
    required this.status,
    required this.isCurrentUser,
    this.joinedAt,
  });

  String get fullName => '$firstName $lastName'.trim();

  bool get isActive => status == 'active';

  bool get isOwner => roleSlug == 'owner';

  factory TenantMember.fromJson(Map<String, dynamic> json) {
    return TenantMember(
      id: json['id'] as String,
      userId: json['userId'] as String,
      email: json['email'] as String,
      firstName: json['firstName'] as String? ?? '',
      lastName: json['lastName'] as String? ?? '',
      roleId: json['roleId'] as String,
      roleSlug: json['roleSlug'] as String,
      roleName: json['roleName'] as String,
      status: json['status'] as String,
      isCurrentUser: json['isCurrentUser'] as bool? ?? false,
      joinedAt: DateTime.tryParse(json['joinedAt']?.toString() ?? ''),
    );
  }
}

class TenantRole {
  final String id;
  final String slug;
  final String name;
  final String? description;
  final bool system;
  final List<String> permissions;

  TenantRole({
    required this.id,
    required this.slug,
    required this.name,
    this.description,
    required this.system,
    required this.permissions,
  });

  bool get isOwnerRole => system && slug == 'owner';

  factory TenantRole.fromJson(Map<String, dynamic> json) {
    return TenantRole(
      id: json['id'] as String,
      slug: json['slug'] as String,
      name: json['name'] as String,
      description: json['description'] as String?,
      system: json['system'] as bool? ?? false,
      permissions: (json['permissions'] as List? ?? const []).cast<String>(),
    );
  }
}

class TenantInvitation {
  final String id;
  final String email;
  final String roleId;
  final String roleName;
  final String status;
  final String? invitedBy;
  final DateTime? expiresAt;

  TenantInvitation({
    required this.id,
    required this.email,
    required this.roleId,
    required this.roleName,
    required this.status,
    this.invitedBy,
    this.expiresAt,
  });

  bool get isPending => status == 'pending';

  factory TenantInvitation.fromJson(Map<String, dynamic> json) {
    return TenantInvitation(
      id: json['id'] as String,
      email: json['email'] as String,
      roleId: json['roleId'] as String,
      roleName: json['roleName'] as String? ?? '',
      status: json['status'] as String,
      invitedBy: json['invitedBy'] as String?,
      expiresAt: DateTime.tryParse(json['expiresAt']?.toString() ?? ''),
    );
  }
}

/// Risposta di GET /api/invitations/preview (endpoint pubblico).
class InvitationPreview {
  final String tenantName;
  final String email;
  final String roleName;
  final String? invitedBy;
  final String status;
  final bool accountExists;

  InvitationPreview({
    required this.tenantName,
    required this.email,
    required this.roleName,
    this.invitedBy,
    required this.status,
    required this.accountExists,
  });

  bool get isPending => status == 'pending';

  factory InvitationPreview.fromJson(Map<String, dynamic> json) {
    return InvitationPreview(
      tenantName: json['tenantName'] as String,
      email: json['email'] as String,
      roleName: json['roleName'] as String,
      invitedBy: json['invitedBy'] as String?,
      status: json['status'] as String,
      accountExists: json['accountExists'] as bool? ?? false,
    );
  }
}
