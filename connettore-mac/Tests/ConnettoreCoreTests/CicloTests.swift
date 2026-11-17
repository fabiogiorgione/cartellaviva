import Foundation
import Testing
@testable import ConnettoreCore

/// Rete finta per il ciclo: server e Ollama insieme, una risposta per percorso.
/// Una risposta può essere un errore di rete o arrivare in ritardo.
final class ReteFinta: URLProtocol, @unchecked Sendable {
    enum Risposta {
        case http(Int, String)
        case senzaRete
    }

    struct Regola {
        let percorso: String
        let risposta: @Sendable (Int) -> Risposta
        var ritardo: TimeInterval = 0
    }

    private static let lucchetto = NSLock()
    nonisolated(unsafe) private static var regole: [Regola] = []
    nonisolated(unsafe) private static var chiamate: [String: Int] = [:]
    nonisolated(unsafe) private static var corpi: [String] = []
    nonisolated(unsafe) private static var estranee = 0
    nonisolated(unsafe) private static var prova = 0

    static func prepara(_ r: [Regola]) {
        lucchetto.withLock {
            regole = r
            chiamate = [:]
            corpi = []
            estranee = 0
            prova += 1
        }
    }

    static func sostituisci(_ r: Regola) {
        lucchetto.withLock {
            regole.removeAll { $0.percorso == r.percorso }
            regole.append(r)
        }
    }

    /// Quante volte è stato chiamato un percorso (finale del path).
    static func volte(_ percorso: String) -> Int { lucchetto.withLock { chiamate[percorso] ?? 0 } }
    static var totale: Int { lucchetto.withLock { chiamate.values.reduce(0, +) } }
    static var corpiInviati: [String] { lucchetto.withLock { corpi } }
    static var richiesteEstranee: Int { lucchetto.withLock { estranee } }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        let path = request.url?.path ?? ""
        var corpo = request.httpBody ?? Data()
        if corpo.isEmpty, let s = request.httpBodyStream {
            s.open()
            let buf = UnsafeMutablePointer<UInt8>.allocate(capacity: 4096)
            while s.hasBytesAvailable { let n = s.read(buf, maxLength: 4096); if n <= 0 { break }; corpo.append(buf, count: n) }
            buf.deallocate()
            s.close()
        }
        // Una richiesta rimasta da una prova di prima non conta e non riceve risposta.
        let estranea: Bool = Self.lucchetto.withLock {
            guard request.value(forHTTPHeaderField: "X-Prova") == String(Self.prova) else {
                Self.estranee += 1
                return true
            }
            return false
        }
        if estranea {
            client?.urlProtocol(self, didFailWithError: URLError(.cancelled))
            return
        }
        let (regola, n): (Regola?, Int) = Self.lucchetto.withLock {
            let r = Self.regole.first { path.hasSuffix($0.percorso) }
            let chiave = r?.percorso ?? path
            Self.chiamate[chiave, default: 0] += 1
            Self.corpi.append(String(decoding: corpo, as: UTF8.self))
            return (r, Self.chiamate[chiave]!)
        }
        let risposta = regola?.risposta(n) ?? .http(404, #"{"error":"not found"}"#)
        let consegna = { [weak self] in
            guard let self else { return }
            switch risposta {
            case .senzaRete:
                self.client?.urlProtocol(self, didFailWithError: URLError(.notConnectedToInternet))
            case let .http(stato, testo):
                self.client?.urlProtocol(self, didReceive: HTTPURLResponse(url: self.request.url!, statusCode: stato, httpVersion: nil, headerFields: ["Content-Type": "application/json"])!, cacheStoragePolicy: .notAllowed)
                self.client?.urlProtocol(self, didLoad: Data(testo.utf8))
                self.client?.urlProtocolDidFinishLoading(self)
            }
        }
        if let r = regola, r.ritardo > 0 {
            DispatchQueue.global().asyncAfter(deadline: .now() + r.ritardo, execute: consegna)
        } else {
            consegna()
        }
    }

    override func stopLoading() {}

    static func sessione() -> URLSession {
        let c = URLSessionConfiguration.ephemeral
        c.protocolClasses = [ReteFinta.self]
        c.httpAdditionalHeaders = ["X-Prova": String(lucchetto.withLock { prova })]
        return URLSession(configuration: c)
    }
}

/// Il Portachiavi finto: in memoria, ricorda se è stato svuotato e quante volte
/// è stato usato dal thread principale (quello che disegna icona e menu).
final class DepositoFinto: DepositoToken, @unchecked Sendable {
    private let lucchetto = NSLock()
    private var valore: String?
    private var _eliminato = false
    private var _sulThreadPrincipale = 0
    var eliminato: Bool { lucchetto.withLock { _eliminato } }
    var sulThreadPrincipale: Int { lucchetto.withLock { _sulThreadPrincipale } }

    init(_ valore: String?) { self.valore = valore }

    private func conta() {
        if Thread.isMainThread { lucchetto.withLock { _sulThreadPrincipale += 1 } }
    }

    func leggi(per server: URL) -> String? {
        conta()
        return lucchetto.withLock { valore }
    }

    func salva(_ v: String, per server: URL) {
        conta()
        lucchetto.withLock { valore = v }
    }

    func elimina(per server: URL) {
        conta()
        lucchetto.withLock {
            valore = nil
            _eliminato = true
        }
    }
}

/// Un Portachiavi che, come quando macOS chiede il permesso, risponde solo
/// quando il test lo sblocca (al massimo dopo 5 secondi).
final class DepositoInAttesa: DepositoToken, @unchecked Sendable {
    let interno: DepositoFinto
    private let permesso = DispatchSemaphore(value: 0)
    private let lucchetto = NSLock()
    private var _letture = 0
    var letture: Int { lucchetto.withLock { _letture } }

    init(_ valore: String?) { interno = DepositoFinto(valore) }

    func consenti() { permesso.signal() }

    func leggi(per server: URL) -> String? {
        lucchetto.withLock { _letture += 1 }
        _ = permesso.wait(timeout: .now() + 5)
        return interno.leggi(per: server)
    }

    func salva(_ v: String, per server: URL) { interno.salva(v, per: server) }
    func elimina(per server: URL) { interno.elimina(per: server) }
}

/// Le attese chieste dal connettore, per controllarne la durata.
final class Attese: @unchecked Sendable {
    private let lucchetto = NSLock()
    private var elenco: [TimeInterval] = []
    var tutte: [TimeInterval] { lucchetto.withLock { elenco } }

    /// Attesa corta al posto di quella vera, che resta annullabile.
    func attendi(_ secondi: TimeInterval) async {
        lucchetto.withLock { elenco.append(secondi) }
        try? await Task.sleep(nanoseconds: 5_000_000)
    }
}

/// Le risposte preparate del server e di Ollama.
enum Finti {
    static let impostazioni = #"{"famiglia":{"nome":"Rossi"},"intervalloSecondi":20,"caratteriMax":12000,"modelliVerificati":["qwen3:4b-instruct-2507-q4_K_M"],"sperimentaliAmmessi":false}"#
    static let unLavoro = #"{"lavori":[{"id":"l1","tipo":"pratica_voci","pratica":"isee 2026","documento":"Estratto","istruzioni":"i","prompt":"p","schema":"{}","scadeIl":null}],"restano":0}"#
    static let nessunLavoro = #"{"lavori":[],"restano":0}"#

    static func token(_ n: Int) -> String {
        #"{"access_token":"a\#(n)","token_type":"Bearer","expires_in":3600,"refresh_token":"r\#(n)","scope":"ai-connector"}"#
    }

    /// Server e Ollama che funzionano; il primo GET dei lavori ne dà uno, poi nessuno.
    static func regole(token: @escaping @Sendable (Int) -> ReteFinta.Risposta, ritardoAnalisi: TimeInterval = 0) -> [ReteFinta.Regola] {
        [
            .init(percorso: "/oauth/token", risposta: token),
            .init(percorso: "/me", risposta: { _ in .http(200, impostazioni) }),
            .init(percorso: "/lavori", risposta: { n in .http(200, n == 1 ? unLavoro : nessunLavoro) }),
            .init(percorso: "/lavori/l1/proroga", risposta: { _ in .http(200, "{}") }),
            .init(percorso: "/lavori/l1/risultato", risposta: { _ in .http(200, #"{"voci":1}"#) }),
            .init(percorso: "/lavori/l1/errore", risposta: { _ in .http(200, "{}") }),
            .init(percorso: "/api/version", risposta: { _ in .http(200, #"{"version":"0.12.0"}"#) }),
            .init(percorso: "/api/tags", risposta: { _ in .http(200, #"{"models":[{"name":"qwen3:4b-instruct-2507-q4_K_M","digest":"abc"}]}"#) }),
            .init(percorso: "/api/show", risposta: { _ in .http(200, #"{"capabilities":["completion"]}"#) }),
            .init(percorso: "/api/chat", risposta: { _ in .http(200, #"{"message":{"content":"{\"voci\":[]}"},"prompt_eval_count":10,"eval_count":5}"#) }, ritardo: ritardoAnalisi),
        ]
    }

}

@MainActor
@Suite(.serialized)
struct CicloTests {
    func connettore(_ deposito: DepositoFinto, attese: Attese) -> Connettore {
        let sessione = ReteFinta.sessione()
        let c = Connettore(
            ollama: OllamaClient(sessione: sessione),
            sessione: sessione,
            deposito: deposito,
            impostazioni: UserDefaults(suiteName: "prova-\(UUID().uuidString)")!,
            attendi: { await attese.attendi($0) }
        )
        c.server = URL(string: "https://app.example.org")!
        c.pausaConBatteria = false
        return c
    }

    /// Aspetta (al massimo 5 secondi) che la condizione diventi vera.
    func finche(_ condizione: () -> Bool) async throws {
        for _ in 0 ..< 500 {
            if condizione() { return }
            try await Task.sleep(nanoseconds: 10_000_000)
        }
        Issue.record("Condizione mai vera entro 5 secondi")
    }

    @Test func reteCheCadeAlRisveglioNonFermaIlCiclo() async throws {
        // Token scaduto (non in memoria) e due rinnovi falliti per la rete, poi la rete torna.
        ReteFinta.prepara(Finti.regole(token: { n in n <= 2 ? .senzaRete : .http(200, Finti.token(1)) }))
        let deposito = DepositoFinto("r0")
        let c = connettore(deposito, attese: Attese())
        await c.avvia()
        try await finche { c.lavoriOggi == 1 }
        #expect(ReteFinta.volte("/oauth/token") == 3)
        #expect(ReteFinta.volte("/lavori/l1/risultato") == 1)
        #expect(deposito.leggi(per: c.server) == "r1")
        #expect(!deposito.eliminato)
        #expect(c.famiglia == "Rossi")
        c.scollega()
    }

    @Test func erroreDiReteNelRinnovoNonToccaIlPortachiavi() async throws {
        ReteFinta.prepara(Finti.regole(token: { _ in .senzaRete }))
        let deposito = DepositoFinto("r0")
        let attese = Attese()
        let c = connettore(deposito, attese: attese)
        await c.avvia()
        try await finche { ReteFinta.volte("/oauth/token") >= 4 }
        #expect(deposito.leggi(per: c.server) == "r0")
        #expect(!deposito.eliminato)
        #expect(c.stato != .scollegato)
        if case let .errore(m) = c.stato { #expect(m.hasPrefix("Server non raggiungibile")) } else { Issue.record("stato \(c.stato)") }
        // Prima di riprovare il rinnovo si aspetta qualche secondo.
        #expect(attese.tutte.contains(Connettore.riprovaRinnovoSecondi))
        // Torna la rete: il ciclo è ancora vivo e prende il lavoro.
        ReteFinta.sostituisci(.init(percorso: "/oauth/token", risposta: { _ in .http(200, Finti.token(1)) }))
        try await finche { c.lavoriOggi == 1 }
        #expect(deposito.leggi(per: c.server) == "r1")
        c.scollega()
    }

    @Test func invalidGrantScollegaIlMac() async throws {
        ReteFinta.prepara(Finti.regole(token: { _ in .http(400, #"{"error":"invalid_grant","error_description":"Collegamento revocato"}"#) }))
        let deposito = DepositoFinto("r0")
        let c = connettore(deposito, attese: Attese())
        await c.avvia()
        try await finche { deposito.eliminato }
        #expect(deposito.leggi(per: c.server) == nil)
        #expect(c.stato == .errore(ErroreServer.scollegato.localizedDescription))
        // Il ciclo è finito: nessuna altra richiesta.
        let fatte = ReteFinta.totale
        try await Task.sleep(nanoseconds: 200_000_000)
        #expect(ReteFinta.totale == fatte)
        #expect(ReteFinta.volte("/oauth/token") == 1)
    }

    @Test func tokenDiAccessoRifiutatoSiRinnova() async throws {
        // Il primo PUT /me risponde 401: il connettore rinnova e va avanti.
        var r = Finti.regole(token: { n in .http(200, Finti.token(n)) })
        r.removeAll { $0.percorso == "/me" }
        r.append(.init(percorso: "/me", risposta: { n in n == 1 ? .http(401, "{}") : .http(200, Finti.impostazioni) }))
        ReteFinta.prepara(r)
        let deposito = DepositoFinto("r0")
        let c = connettore(deposito, attese: Attese())
        await c.avvia()
        try await finche { c.lavoriOggi == 1 }
        #expect(ReteFinta.volte("/oauth/token") == 2)
        #expect(deposito.leggi(per: c.server) == "r2")
        c.scollega()
    }

    @Test func analisiLungaProrogataOgniMinuto() async throws {
        // L'analisi dura più delle attese finte: la proroga parte più volte, ogni 60 secondi.
        ReteFinta.prepara(Finti.regole(token: { _ in .http(200, Finti.token(1)) }, ritardoAnalisi: 0.3))
        let attese = Attese()
        let c = connettore(DepositoFinto("r0"), attese: attese)
        await c.avvia()
        try await finche { c.lavoriOggi == 1 }
        #expect(ReteFinta.volte("/lavori/l1/proroga") >= 1)
        #expect(attese.tutte.contains(60))
        #expect(Connettore.prorogaSecondi < 90)
        c.scollega()
    }

    @Test func alRisveglioUnGiroSubito() async throws {
        // Attese vere: dopo un giro senza lavori il ciclo aspetterebbe 20 secondi.
        ReteFinta.prepara(Finti.regole(token: { _ in .http(200, Finti.token(1)) }))
        ReteFinta.sostituisci(.init(percorso: "/lavori", risposta: { _ in .http(200, Finti.nessunLavoro) }))
        let sessione = ReteFinta.sessione()
        let c = Connettore(ollama: OllamaClient(sessione: sessione), sessione: sessione, deposito: DepositoFinto("r0"), impostazioni: UserDefaults(suiteName: "prova-\(UUID().uuidString)")!)
        c.server = URL(string: "https://app.example.org")!
        c.pausaConBatteria = false
        await c.avvia()
        try await finche { ReteFinta.volte("/lavori") == 1 }
        try await Task.sleep(nanoseconds: 100_000_000)
        #expect(ReteFinta.volte("/lavori") == 1)
        c.risvegliato()
        try await finche { ReteFinta.volte("/lavori") == 2 }
        c.scollega()
    }

    @Test func ilPortachiaviNonSiUsaSulThreadPrincipale() async throws {
        // macOS può fermare una lettura del Portachiavi finché l'utente non dà il permesso:
        // sul thread principale l'icona e il menu non comparirebbero.
        ReteFinta.prepara(Finti.regole(token: { n in .http(200, Finti.token(n)) }))
        let deposito = DepositoFinto("r0")
        let c = connettore(deposito, attese: Attese())
        await c.avvia()
        try await finche { c.lavoriOggi == 1 }
        c.scollega()
        try await finche { deposito.eliminato }
        #expect(deposito.sulThreadPrincipale == 0)
    }

    @Test func mentreIlPortachiaviAspettaIlConnettoreRisponde() async throws {
        ReteFinta.prepara(Finti.regole(token: { n in .http(200, Finti.token(n)) }))
        let deposito = DepositoInAttesa("r0")
        let sessione = ReteFinta.sessione()
        let c = Connettore(ollama: OllamaClient(sessione: sessione), sessione: sessione, deposito: deposito, impostazioni: UserDefaults(suiteName: "prova-\(UUID().uuidString)")!, attendi: { await Attese().attendi($0) })
        c.server = URL(string: "https://app.example.org")!
        c.pausaConBatteria = false
        let avvio = Task { await c.avvia() }
        // Il thread principale non è fermo: lo stato si legge e dice "Avvio".
        try await finche { c.stato == .avvio }
        try await Task.sleep(nanoseconds: 100_000_000)
        #expect(c.stato == .avvio)
        #expect(ReteFinta.totale == 0)
        deposito.consenti()
        #expect(await avvio.value)
        try await finche { c.lavoriOggi == 1 }
        // Il refresh token si legge una volta sola, poi si scrive soltanto.
        #expect(deposito.letture == 1)
        #expect(deposito.interno.leggi(per: c.server) == "r1")
        c.scollega()
    }

    @Test func senzaCollegamentoSalvatoAvviaDiceDiNo() async throws {
        ReteFinta.prepara(Finti.regole(token: { n in .http(200, Finti.token(n)) }))
        let c = connettore(DepositoFinto(nil), attese: Attese())
        #expect(await c.avvia() == false)
        #expect(c.stato == .scollegato)
        #expect(ReteFinta.totale == 0)
    }
}
