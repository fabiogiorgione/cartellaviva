import Foundation

public enum ErroreServer: Error, Equatable, LocalizedError {
    /// 401: token scaduto o connettore scollegato.
    case nonAutorizzato
    /// 409: il lavoro non è più nostro, o il modello non è tra i verificati.
    case conflitto(String)
    /// 429: troppo presto.
    case troppoPresto
    case http(Int, String)
    case rete(String)

    public var errorDescription: String? {
        switch self {
        case .nonAutorizzato: "Collegamento non più valido: ricollega il Mac."
        case let .conflitto(m): m
        case .troppoPresto: "Il server chiede di rallentare."
        case let .http(c, m): "Il server ha risposto \(c): \(m)"
        case let .rete(m): "Server non raggiungibile: \(m)"
        }
    }
}

/// Le chiamate al server di CartellaViva (prefisso /api/ai-connector-plugin).
/// Solo HTTPS, tranne localhost per lo sviluppo.
public struct ServerClient: Sendable {
    public static let clientId = "cartellaviva-mac"
    public let base: URL
    let sessione: URLSession

    public init(base: URL, sessione: URLSession = .shared) {
        self.base = base
        self.sessione = sessione
    }

    /// L'indirizzo è accettabile? HTTPS, oppure http solo verso questo computer.
    public static func indirizzoValido(_ url: URL) -> Bool {
        guard let host = url.host else { return false }
        return url.scheme == "https" || (url.scheme == "http" && ["localhost", "127.0.0.1"].contains(host))
    }

    // MARK: Abbinamento (RFC 8628)

    public func richiediAbbinamento(nome: String) async throws -> RichiestaDispositivo {
        try await json("POST", "oauth/device", corpo: ["client_id": Self.clientId, "nome": nome])
    }

    /// Scambia il device_code: lancia ErroreOAuth finché l'utente non approva.
    public func token(deviceCode: String) async throws -> Token {
        try await oauth(["client_id": Self.clientId, "grant_type": "urn:ietf:params:oauth:grant-type:device_code", "device_code": deviceCode])
    }

    public func rinnova(refreshToken: String) async throws -> Token {
        try await oauth(["client_id": Self.clientId, "grant_type": "refresh_token", "refresh_token": refreshToken])
    }

    // MARK: Lavori

    public func presentati(_ p: Presentazione, token: String) async throws -> Impostazioni {
        try await json("PUT", "me", corpo: p, token: token)
    }

    public func lavori(caratteri: Int, token: String) async throws -> RispostaLavori {
        try await json("GET", "lavori?limite=1&caratteri=\(caratteri)", token: token)
    }

    public func proroga(_ id: String, token: String) async throws {
        let _: Vuoto = try await json("POST", "lavori/\(id)/proroga", corpo: [String: String](), token: token)
    }

    public func risultato(_ id: String, _ r: Risultato, token: String) async throws -> Int {
        struct Voci: Decodable { let voci: Int }
        let v: Voci = try await json("POST", "lavori/\(id)/risultato", corpo: r, token: token)
        return v.voci
    }

    /// Solo il codice dell'errore, mai testo del documento.
    public func errore(_ id: String, codice: String, token: String) async throws {
        let _: Vuoto = try await json("POST", "lavori/\(id)/errore", corpo: ["codice": codice], token: token)
    }

    // MARK: -

    struct Vuoto: Decodable {}

    private func oauth(_ corpo: [String: String]) async throws -> Token {
        let (dati, stato) = try await invia("POST", "oauth/token", corpo: try JSONEncoder().encode(corpo), token: nil)
        if stato == 200 { return try JSONDecoder().decode(Token.self, from: dati) }
        if let e = try? JSONDecoder().decode(ErroreOAuth.self, from: dati) { throw e }
        throw ErroreServer.http(stato, String(decoding: dati.prefix(200), as: UTF8.self))
    }

    private func json<T: Decodable>(_ metodo: String, _ percorso: String, corpo: (some Encodable)? = String?.none, token: String? = nil) async throws -> T {
        let dati = try corpo.map { try JSONEncoder().encode($0) }
        let (risposta, stato) = try await invia(metodo, percorso, corpo: dati, token: token)
        switch stato {
        case 200 ..< 300: return try JSONDecoder().decode(T.self, from: risposta.isEmpty ? Data("{}".utf8) : risposta)
        case 401: throw ErroreServer.nonAutorizzato
        case 409: throw ErroreServer.conflitto(Self.messaggio(risposta))
        case 429: throw ErroreServer.troppoPresto
        default: throw ErroreServer.http(stato, Self.messaggio(risposta))
        }
    }

    private func invia(_ metodo: String, _ percorso: String, corpo: Data?, token: String?) async throws -> (Data, Int) {
        guard let url = URL(string: "api/ai-connector-plugin/" + percorso, relativeTo: base.appendingPathComponent("/")) else { throw ErroreServer.rete("indirizzo non valido") }
        var r = URLRequest(url: url, timeoutInterval: 30)
        r.httpMethod = metodo
        r.setValue("application/json", forHTTPHeaderField: "Accept")
        if let corpo {
            r.httpBody = corpo
            r.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        if let token { r.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        do {
            let (d, risposta) = try await sessione.data(for: r)
            return (d, (risposta as? HTTPURLResponse)?.statusCode ?? 0)
        } catch {
            throw ErroreServer.rete(error.localizedDescription)
        }
    }

    /// Il messaggio d'errore del server ("detail" o "title"), senza il resto.
    static func messaggio(_ d: Data) -> String {
        let j = (try? JSONSerialization.jsonObject(with: d)) as? [String: Any]
        return (j?["detail"] as? String) ?? (j?["title"] as? String) ?? (j?["message"] as? String) ?? "errore"
    }
}
