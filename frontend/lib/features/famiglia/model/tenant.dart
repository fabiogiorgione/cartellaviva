/// Dettaglio del tenant (GET /api/tenants/{id}).
class TenantDettaglio {
  final String id;
  final String name;
  final String type;
  final String? myRole;
  final bool modalitaAvanzata;
  final DateTime? eliminazioneProgrammataIl;
  final bool solaLettura;

  /// Eliminazione decisa dal portale: il proprietario non può annullarla.
  final bool eliminazioneDalPortale;
  final int? spazioUsatoBytes;

  TenantDettaglio({
    required this.id,
    required this.name,
    required this.type,
    this.myRole,
    this.modalitaAvanzata = false,
    this.eliminazioneProgrammataIl,
    this.solaLettura = false,
    this.eliminazioneDalPortale = false,
    this.spazioUsatoBytes,
  });

  bool get isEnte => type == 'azienda';

  factory TenantDettaglio.fromJson(Map<String, dynamic> json) => TenantDettaglio(
        id: json['id'] as String,
        name: json['name'] as String,
        type: json['type'] as String,
        myRole: json['myRole'] as String?,
        modalitaAvanzata: json['modalitaAvanzata'] == true,
        eliminazioneProgrammataIl: json['eliminazioneProgrammataIl'] is String ? DateTime.tryParse(json['eliminazioneProgrammataIl'] as String)?.toLocal() : null,
        solaLettura: json['solaLettura'] == true,
        eliminazioneDalPortale: json['eliminazioneDalPortale'] == true,
        spazioUsatoBytes: (json['spazioUsatoBytes'] as num?)?.toInt(),
      );
}

/// Voce del catalogo permessi (GET /api/permissions).
class PermessoCatalogo {
  final String code;
  final String resource;
  final String action;
  final String label;
  final String resourceLabel;

  PermessoCatalogo({required this.code, required this.resource, required this.action, required this.label, required this.resourceLabel});

  factory PermessoCatalogo.fromJson(Map<String, dynamic> json) => PermessoCatalogo(
        code: json['code'] as String,
        resource: json['resource'] as String,
        action: json['action'] as String,
        label: json['label'] as String,
        resourceLabel: json['resourceLabel'] as String,
      );
}

/// "1,2 MB", "830 KB".
String formatBytes(int bytes) {
  if (bytes < 1024) return '$bytes B';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
  if (bytes < 1024 * 1024 * 1024) return '${(bytes / (1024 * 1024)).toStringAsFixed(1).replaceAll('.', ',')} MB';
  return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(2).replaceAll('.', ',')} GB';
}
