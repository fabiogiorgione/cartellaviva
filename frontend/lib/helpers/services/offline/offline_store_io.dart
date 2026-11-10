import 'dart:convert';
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

/// Documento salvato sul telefono.
class DocumentoOffline {
  final String id;
  final String titolo;
  final String nomeFile;
  final String mimeType;
  final String? categoria;
  final int dimensione;
  final DateTime salvatoIl;

  DocumentoOffline({required this.id, required this.titolo, required this.nomeFile, required this.mimeType, this.categoria, this.dimensione = 0, required this.salvatoIl});

  Map<String, dynamic> toJson() =>
      {'id': id, 'titolo': titolo, 'nomeFile': nomeFile, 'mimeType': mimeType, 'categoria': categoria, 'dimensione': dimensione, 'salvatoIl': salvatoIl.toIso8601String()};

  factory DocumentoOffline.fromJson(Map<String, dynamic> j) => DocumentoOffline(
        id: j['id'] as String,
        titolo: j['titolo'] as String,
        nomeFile: j['nomeFile'] as String,
        mimeType: j['mimeType'] as String,
        categoria: j['categoria'] as String?,
        dimensione: (j['dimensione'] as num?)?.toInt() ?? 0,
        salvatoIl: DateTime.parse(j['salvatoIl'] as String),
      );
}

/// Documenti disponibili senza rete (pronto soccorso, estero), solo Android/iOS.
///
/// - file ed elenco (anche i titoli) cifrati con AES-256-GCM; la chiave è
///   generata sul telefono e resta nel Keystore/Keychain (flutter_secure_storage),
///   mai sul server né nei backup del server;
/// - per aprirli serve lo sblocco del telefono (impronta, volto o PIN), anche
///   senza connessione e anche se la sessione è scaduta;
/// - al logout esplicito l'archivio offline di quell'utente viene cancellato.
class OfflineStore {
  OfflineStore._();

  static const _storage = FlutterSecureStorage();
  static const _ultimoUtente = 'offline_ultimo_utente';
  static final _aes = AesGcm.with256bits();
  static DateTime? _sbloccatoFino;

  /// Dopo lo sblocco, per 5 minuti non lo si richiede.
  static const durataSblocco = Duration(minutes: 5);

  static bool get supportato => !kIsWeb && (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS);

  static bool get sbloccato => _sbloccatoFino != null && DateTime.now().isBefore(_sbloccatoFino!);

  /// Impronta, volto o PIN del telefono. True se sbloccato (o ancora sbloccato).
  static Future<bool> sblocca() async {
    if (sbloccato) return true;
    final auth = LocalAuthentication();
    if (!await auth.isDeviceSupported()) {
      // Telefono senza blocco schermo: i documenti offline non si aprono.
      return false;
    }
    final ok = await auth.authenticate(localizedReason: 'Sblocca per aprire i documenti salvati sul telefono', persistAcrossBackgrounding: true);
    if (ok) _sbloccatoFino = DateTime.now().add(durataSblocco);
    return ok;
  }

  static void blocca() => _sbloccatoFino = null;

  /// Utente dell'archivio: quello connesso o, offline/sessione scaduta, l'ultimo.
  static Future<String?> utente([String? corrente]) async {
    if (corrente != null) {
      await _storage.write(key: _ultimoUtente, value: corrente);
      return corrente;
    }
    return _storage.read(key: _ultimoUtente);
  }

  static Future<Directory> _cartella(String userId) async {
    final base = await getApplicationSupportDirectory();
    final dir = Directory('${base.path}/offline/${userId.replaceAll(RegExp(r'[^A-Za-z0-9-]'), '')}');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  static Future<SecretKey> _chiave(String userId) async {
    final nome = 'offline_key_$userId';
    final salvata = await _storage.read(key: nome);
    if (salvata != null) return SecretKey(base64Decode(salvata));
    final r = Random.secure();
    final bytes = List<int>.generate(32, (_) => r.nextInt(256));
    await _storage.write(key: nome, value: base64Encode(bytes));
    return SecretKey(bytes);
  }

  static Future<Uint8List> cifra(List<int> dati, SecretKey chiave) async => (await _aes.encrypt(dati, secretKey: chiave)).concatenation();

  static Future<List<int>> decifra(List<int> blob, SecretKey chiave) =>
      _aes.decrypt(SecretBox.fromConcatenation(blob, nonceLength: _aes.nonceLength, macLength: _aes.macAlgorithm.macLength), secretKey: chiave);

  static Future<List<DocumentoOffline>> elenco(String userId) async {
    final f = File('${(await _cartella(userId)).path}/indice.bin');
    if (!await f.exists()) return [];
    final json = utf8.decode(await decifra(await f.readAsBytes(), await _chiave(userId)));
    return (jsonDecode(json) as List).map((e) => DocumentoOffline.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  }

  static Future<void> _scriviElenco(String userId, List<DocumentoOffline> voci) async {
    final dir = await _cartella(userId);
    final blob = await cifra(utf8.encode(jsonEncode(voci.map((v) => v.toJson()).toList())), await _chiave(userId));
    await File('${dir.path}/indice.bin').writeAsBytes(blob, flush: true);
  }

  static Future<bool> contiene(String userId, String docId) async => (await elenco(userId)).any((d) => d.id == docId);

  static Future<void> salva(String userId, DocumentoOffline meta, List<int> contenuto) async {
    final dir = await _cartella(userId);
    await File('${dir.path}/${meta.id}.bin').writeAsBytes(await cifra(contenuto, await _chiave(userId)), flush: true);
    final voci = (await elenco(userId)).where((d) => d.id != meta.id).toList()..add(meta);
    await _scriviElenco(userId, voci);
  }

  static Future<List<int>> leggi(String userId, String docId) async {
    final f = File('${(await _cartella(userId)).path}/$docId.bin');
    return decifra(await f.readAsBytes(), await _chiave(userId));
  }

  static Future<void> rimuovi(String userId, String docId) async {
    final f = File('${(await _cartella(userId)).path}/$docId.bin');
    if (await f.exists()) await f.delete();
    await _scriviElenco(userId, (await elenco(userId)).where((d) => d.id != docId).toList());
  }

  /// Logout: file, elenco e chiave di quell'utente spariscono dal telefono.
  static Future<void> svuota(String userId) async {
    if (!supportato) return;
    final dir = await _cartella(userId);
    if (await dir.exists()) await dir.delete(recursive: true);
    await _storage.delete(key: 'offline_key_$userId');
    if (await _storage.read(key: _ultimoUtente) == userId) await _storage.delete(key: _ultimoUtente);
    blocca();
  }

  /// Apre il file con l'app del telefono: serve una copia in chiaro temporanea
  /// in cache, eliminata alla successiva apertura della pagina offline.
  static Future<void> apri(String nomeFile, String mimeType, List<int> contenuto) async {
    final dir = Directory('${(await getTemporaryDirectory()).path}/offline_aperti');
    if (!await dir.exists()) await dir.create(recursive: true);
    final f = File('${dir.path}/${nomeFile.replaceAll(RegExp(r'[/\\]'), '_')}');
    await f.writeAsBytes(contenuto, flush: true);
    await OpenFilex.open(f.path, type: mimeType);
  }

  static Future<void> pulisciTemporanei() async {
    if (!supportato) return;
    final dir = Directory('${(await getTemporaryDirectory()).path}/offline_aperti');
    if (await dir.exists()) await dir.delete(recursive: true);
  }
}
