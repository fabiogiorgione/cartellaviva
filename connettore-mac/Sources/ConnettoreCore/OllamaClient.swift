import Foundation

public enum ErroreOllama: Error, Equatable, LocalizedError {
    case nonRaggiungibile
    case modelloMancante(String)
    case timeout
    case memoria
    case risposta(String)

    public var errorDescription: String? {
        switch self {
        case .nonRaggiungibile: "Ollama non risponde su questo Mac."
        case let .modelloMancante(m): "Manca il modello \(m)."
        case .timeout: "Il modello ha impiegato troppo."
        case .memoria: "Memoria insufficiente per il modello."
        case let .risposta(m): m
        }
    }

    /// Il codice da mandare al server (mai il testo).
    public var codice: String {
        switch self {
        case .modelloMancante: "modello_mancante"
        case .timeout: "timeout"
        case .memoria: "memoria"
        case .nonRaggiungibile: "interrotto"
        case .risposta: "risposta_non_valida"
        }
    }
}

/// Ollama sul Mac, solo su 127.0.0.1.
public struct OllamaClient: Sendable {
    public static let locale = URL(string: "http://127.0.0.1:11434")!
    public let base: URL
    let sessione: URLSession

    public init(base: URL = OllamaClient.locale, sessione: URLSession = .shared) {
        self.base = base
        self.sessione = sessione
    }

    public func versione() async -> String? {
        struct V: Decodable { let version: String }
        guard let (d, s) = try? await richiesta("GET", "api/version", corpo: nil, timeout: 3), s == 200 else { return nil }
        return (try? JSONDecoder().decode(V.self, from: d))?.version
    }

    /// Modelli installati con il loro digest.
    public func modelli() async throws -> [(nome: String, digest: String)] {
        struct M: Decodable { let name: String; let digest: String }
        struct T: Decodable { let models: [M] }
        let (d, s) = try await richiesta("GET", "api/tags", corpo: nil, timeout: 5)
        guard s == 200 else { throw ErroreOllama.nonRaggiungibile }
        return try JSONDecoder().decode(T.self, from: d).models.map { ($0.name, $0.digest) }
    }

    /// Il modello sa "ragionare" (qwen3 8B…)? Allora si spegne con think: false.
    public func ragiona(_ modello: String) async -> Bool {
        struct S: Decodable { let capabilities: [String]? }
        guard let (d, s) = try? await richiesta("POST", "api/show", corpo: try? JSONSerialization.data(withJSONObject: ["model": modello]), timeout: 10), s == 200 else { return false }
        return (try? JSONDecoder().decode(S.self, from: d))?.capabilities?.contains("thinking") ?? false
    }

    /// Scarica un modello, riportando l'avanzamento (0…1).
    public func scarica(_ modello: String, avanzamento: @Sendable (Double, String) -> Void) async throws {
        var r = URLRequest(url: base.appendingPathComponent("api/pull"), timeoutInterval: 3600)
        r.httpMethod = "POST"
        r.httpBody = try JSONSerialization.data(withJSONObject: ["model": modello, "stream": true])
        let (righe, risposta) = try await sessione.bytes(for: r)
        guard (risposta as? HTTPURLResponse)?.statusCode == 200 else { throw ErroreOllama.risposta("Download non riuscito.") }
        for try await riga in righe.lines {
            guard let j = try? JSONSerialization.jsonObject(with: Data(riga.utf8)) as? [String: Any] else { continue }
            if let e = j["error"] as? String { throw ErroreOllama.risposta(e) }
            let stato = j["status"] as? String ?? ""
            if let tot = j["total"] as? Double, tot > 0, let fatto = j["completed"] as? Double {
                avanzamento(fatto / tot, stato)
            } else {
                avanzamento(stato == "success" ? 1 : 0, stato)
            }
        }
    }

    public struct Risposta: Sendable {
        public let contenuto: String
        public let tokenPrompt: Int?
        public let tokenRisposta: Int?
    }

    /// Una chiamata come quella del server: schema imposto, temperatura 0.
    public func analizza(modello: String, istruzioni: String, prompt: String, schema: String, contesto: Int, senzaRagionamento: Bool, timeout: TimeInterval = 240) async throws -> Risposta {
        guard let formato = try? JSONSerialization.jsonObject(with: Data(schema.utf8)) else { throw ErroreOllama.risposta("Schema non valido.") }
        var corpo: [String: Any] = [
            "model": modello,
            "stream": false,
            "format": formato,
            "options": ["temperature": 0, "num_ctx": contesto],
            "keep_alive": "10m",
            "messages": [["role": "system", "content": istruzioni], ["role": "user", "content": prompt]],
        ]
        if senzaRagionamento { corpo["think"] = false }
        let d: Data
        let s: Int
        do {
            (d, s) = try await richiesta("POST", "api/chat", corpo: try JSONSerialization.data(withJSONObject: corpo), timeout: timeout)
        } catch let e as URLError where e.code == .timedOut {
            throw ErroreOllama.timeout
        } catch {
            throw ErroreOllama.nonRaggiungibile
        }
        let j = (try? JSONSerialization.jsonObject(with: d)) as? [String: Any]
        guard s == 200 else {
            let e = (j?["error"] as? String ?? "").lowercased()
            if s == 404 || e.contains("not found") { throw ErroreOllama.modelloMancante(modello) }
            if e.contains("memory") { throw ErroreOllama.memoria }
            throw ErroreOllama.risposta("Ollama ha risposto \(s).")
        }
        guard let contenuto = (j?["message"] as? [String: Any])?["content"] as? String else { throw ErroreOllama.risposta("Risposta vuota.") }
        return Risposta(contenuto: contenuto, tokenPrompt: j?["prompt_eval_count"] as? Int, tokenRisposta: j?["eval_count"] as? Int)
    }

    private func richiesta(_ metodo: String, _ percorso: String, corpo: Data?, timeout: TimeInterval) async throws -> (Data, Int) {
        var r = URLRequest(url: base.appendingPathComponent(percorso), timeoutInterval: timeout)
        r.httpMethod = metodo
        if let corpo {
            r.httpBody = corpo
            r.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }
        let (d, risposta) = try await sessione.data(for: r)
        return (d, (risposta as? HTTPURLResponse)?.statusCode ?? 0)
    }
}
