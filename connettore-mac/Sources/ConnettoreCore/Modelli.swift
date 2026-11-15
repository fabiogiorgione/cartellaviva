import Foundation

/// Risposta di PUT /me: impostazioni decise dal server.
public struct Impostazioni: Codable, Sendable, Equatable {
    public struct Famiglia: Codable, Sendable, Equatable { public let nome: String }
    public let famiglia: Famiglia
    public let intervalloSecondi: Int
    public let caratteriMax: Int
    public let modelliVerificati: [String]
    public let sperimentaliAmmessi: Bool
}

/// Un lavoro da fare: istruzioni, prompt e schema arrivano dal server e restano in memoria.
public struct Lavoro: Codable, Sendable, Equatable {
    public let id: String
    public let tipo: String
    public let pratica: String
    public let documento: String
    public let istruzioni: String
    public let prompt: String
    public let schema: String
    public let scadeIl: String?
}

public struct RispostaLavori: Codable, Sendable {
    public let lavori: [Lavoro]
    public let restano: Int
}

/// Abbinamento (RFC 8628, passo 1).
public struct RichiestaDispositivo: Codable, Sendable, Equatable {
    public let deviceCode: String
    public let userCode: String
    public let verificationUri: String
    public let verificationUriComplete: String
    public let expiresIn: Int
    public let interval: Int

    enum CodingKeys: String, CodingKey {
        case deviceCode = "device_code", userCode = "user_code", verificationUri = "verification_uri"
        case verificationUriComplete = "verification_uri_complete", expiresIn = "expires_in", interval
    }
}

public struct Token: Codable, Sendable, Equatable {
    public let accessToken: String
    public let refreshToken: String
    public let expiresIn: Int

    enum CodingKeys: String, CodingKey { case accessToken = "access_token", refreshToken = "refresh_token", expiresIn = "expires_in" }
}

/// Errore del token endpoint (RFC 6749 §5.2): "authorization_pending", "slow_down"…
public struct ErroreOAuth: Codable, Sendable, Error, Equatable {
    public let error: String
    public let errorDescription: String?

    enum CodingKeys: String, CodingKey { case error, errorDescription = "error_description" }
}

/// Descrizione del Mac per PUT /me.
public struct Presentazione: Codable, Sendable, Equatable {
    public var versione: String
    public var sistema: String
    public var chip: String
    public var memoriaGb: Int
    public var motore: String = "ollama"
    public var modello: String
    public var digest: String?
    public var contesto: Int
}

/// Il risultato di un'analisi: solo la risposta del modello e le misure.
public struct Risultato: Codable, Sendable, Equatable {
    public let risposta: String
    public let modello: String
    public let digest: String?
    public let durataMs: Int
    public let tokenPrompt: Int?
    public let tokenRisposta: Int?
}

public enum Formula {
    /// Caratteri di testo da chiedere al server dato il contesto del modello: la stessa
    /// formula dell'iPhone, (contesto − 1500) × 3, tra 1.500 e il massimo del server.
    public static func caratteri(contesto: Int, massimo: Int = 12000) -> Int {
        max(1500, min(massimo, (contesto - 1500) * 3))
    }
}
