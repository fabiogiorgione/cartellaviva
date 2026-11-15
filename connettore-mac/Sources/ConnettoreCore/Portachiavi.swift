import Foundation
import Security

/// Il refresh token nel Portachiavi: solo su questo Mac, mai su iCloud,
/// leggibile dopo il primo sblocco. Il token di accesso resta in memoria.
public enum Portachiavi {
    static let servizio = "it.cartellaviva.connettore"

    public static func salva(_ valore: String, per server: URL) {
        elimina(per: server)
        let q: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: servizio,
            kSecAttrAccount as String: server.absoluteString,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
            kSecAttrSynchronizable as String: false,
            kSecValueData as String: Data(valore.utf8),
        ]
        SecItemAdd(q as CFDictionary, nil)
    }

    public static func leggi(per server: URL) -> String? {
        let q: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: servizio,
            kSecAttrAccount as String: server.absoluteString,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
        var risultato: AnyObject?
        guard SecItemCopyMatching(q as CFDictionary, &risultato) == errSecSuccess, let d = risultato as? Data else { return nil }
        return String(data: d, encoding: .utf8)
    }

    public static func elimina(per server: URL) {
        let q: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: servizio, kSecAttrAccount as String: server.absoluteString]
        SecItemDelete(q as CFDictionary)
    }
}
