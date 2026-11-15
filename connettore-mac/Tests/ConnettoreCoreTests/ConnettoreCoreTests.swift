import Foundation
import Testing
@testable import ConnettoreCore

/// Server finto: risponde in ordine con le risposte preparate e ricorda le richieste.
final class ServerFinto: URLProtocol, @unchecked Sendable {
    nonisolated(unsafe) static var risposte: [(Int, String)] = []
    nonisolated(unsafe) static var richieste: [URLRequest] = []

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        var r = request
        if r.httpBody == nil, let s = r.httpBodyStream {
            s.open()
            var d = Data()
            let buf = UnsafeMutablePointer<UInt8>.allocate(capacity: 4096)
            while s.hasBytesAvailable { let n = s.read(buf, maxLength: 4096); if n <= 0 { break }; d.append(buf, count: n) }
            buf.deallocate()
            r.httpBody = d
        }
        Self.richieste.append(r)
        let (stato, corpo) = Self.risposte.isEmpty ? (500, "{}") : Self.risposte.removeFirst()
        client?.urlProtocol(self, didReceive: HTTPURLResponse(url: request.url!, statusCode: stato, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!, cacheStoragePolicy: .notAllowed)
        client?.urlProtocol(self, didLoad: Data(corpo.utf8))
        client?.urlProtocolDidFinishLoading(self)
    }

    override func stopLoading() {}

    static func sessione() -> URLSession {
        let c = URLSessionConfiguration.ephemeral
        c.protocolClasses = [ServerFinto.self]
        return URLSession(configuration: c)
    }
}

@Suite(.serialized)
struct ConnettoreCoreTests {
    let server = ServerClient(base: URL(string: "https://app.example.org")!, sessione: ServerFinto.sessione())

    init() {
        ServerFinto.risposte = []
        ServerFinto.richieste = []
    }

    @Test func caratteriDalContesto() {
        #expect(Formula.caratteri(contesto: 8192) == 12000)
        #expect(Formula.caratteri(contesto: 4096) == 7788)
        #expect(Formula.caratteri(contesto: 2000) == 1500)
        #expect(Formula.caratteri(contesto: 8192, massimo: 6000) == 6000)
    }

    @Test func soloHttpsTranneQuestoComputer() {
        #expect(ServerClient.indirizzoValido(URL(string: "https://app.cartellaviva.it")!))
        #expect(ServerClient.indirizzoValido(URL(string: "http://localhost:8090")!))
        #expect(!ServerClient.indirizzoValido(URL(string: "http://app.cartellaviva.it")!))
        #expect(!ServerClient.indirizzoValido(URL(string: "ftp://x")!))
    }

    @Test func abbinamentoInAttesaPoiToken() async throws {
        ServerFinto.risposte = [
            (200, #"{"device_code":"dc","user_code":"WDJB-MJHT","verification_uri":"https://app.example.org/collega-mac","verification_uri_complete":"https://app.example.org/collega-mac?codice=WDJB-MJHT","expires_in":600,"interval":5}"#),
            (400, #"{"error":"authorization_pending","error_description":"In attesa"}"#),
            (200, #"{"access_token":"a","token_type":"Bearer","expires_in":3600,"refresh_token":"r","scope":"ai-connector"}"#),
        ]
        let r = try await server.richiediAbbinamento(nome: "MacBook di prova")
        #expect(r.userCode == "WDJB-MJHT")
        #expect(r.interval == 5)
        await #expect(throws: ErroreOAuth(error: "authorization_pending", errorDescription: "In attesa")) { try await server.token(deviceCode: r.deviceCode) }
        let t = try await server.token(deviceCode: r.deviceCode)
        #expect(t == Token(accessToken: "a", refreshToken: "r", expiresIn: 3600))
        let corpo = try #require(ServerFinto.richieste.last?.httpBody)
        let j = try #require(try JSONSerialization.jsonObject(with: corpo) as? [String: String])
        #expect(j["grant_type"] == "urn:ietf:params:oauth:grant-type:device_code")
        #expect(j["client_id"] == "cartellaviva-mac")
        #expect(ServerFinto.richieste.last?.url?.path == "/api/ai-connector-plugin/oauth/token")
    }

    @Test func erroriDelServer() async throws {
        ServerFinto.risposte = [
            (401, "{}"),
            (409, #"{"detail":"Questo lavoro non è più tuo"}"#),
            (429, "{}"),
            (200, #"{"lavori":[{"id":"l1","tipo":"pratica_voci","pratica":"isee 2026","documento":"Estratto","istruzioni":"i","prompt":"p","schema":"{}","scadeIl":null}],"restano":3}"#),
        ]
        await #expect(throws: ErroreServer.nonAutorizzato) { try await server.lavori(caratteri: 6000, token: "x") }
        await #expect(throws: ErroreServer.conflitto("Questo lavoro non è più tuo")) { try await server.lavori(caratteri: 6000, token: "x") }
        await #expect(throws: ErroreServer.troppoPresto) { try await server.lavori(caratteri: 6000, token: "x") }
        let r = try await server.lavori(caratteri: 6000, token: "x")
        #expect(r.lavori.first?.documento == "Estratto")
        #expect(r.restano == 3)
        #expect(ServerFinto.richieste.last?.value(forHTTPHeaderField: "Authorization") == "Bearer x")
        #expect(ServerFinto.richieste.last?.url?.query == "limite=1&caratteri=6000")
    }

    @Test func alServerSoloIlCodiceDellErrore() {
        #expect(ErroreOllama.timeout.codice == "timeout")
        #expect(ErroreOllama.modelloMancante("x").codice == "modello_mancante")
        #expect(ErroreOllama.risposta("testo qualsiasi").codice == "risposta_non_valida")
    }
}
