import Combine
import Foundation
import IOKit.ps
import os

/// Stato del connettore, per il menu.
public enum Stato: Equatable, Sendable {
    case scollegato
    case abbinamento(codice: String, link: String)
    case pronto
    case analizza(String)
    case inPausa(fino: Date)
    case batteriaBassa
    case ollamaNonRisponde
    case errore(String)

    public var descrizione: String {
        switch self {
        case .scollegato: "Scollegato"
        case let .abbinamento(codice, _): "In attesa di approvazione (\(codice))"
        case .pronto: "Pronto"
        case let .analizza(doc): "Analizza «\(doc)»"
        case let .inPausa(fino): "In pausa fino alle \(fino.formatted(date: .omitted, time: .shortened))"
        case .batteriaBassa: "In pausa: batteria sotto il 20%"
        case .ollamaNonRisponde: "Ollama non risponde"
        case let .errore(m): m
        }
    }
}

/// Il connettore: collega il Mac a una famiglia, poi prende i lavori dal server,
/// li fa analizzare a Ollama e rimanda solo la risposta del modello.
/// Il testo dei documenti resta in memoria; nei log solo id e tempi.
@MainActor
public final class Connettore: ObservableObject {
    public static let versione = "0.1.0"
    public static let modelloConsigliato = "qwen3:4b-instruct-2507-q4_K_M"
    /// Ogni quanto si proroga un lavoro in corso. Il server considera spento un Mac
    /// che tace da 90 secondi (ConnettoreAi::ONLINE_SECONDI) e il lavoro scade dopo 300.
    public static let prorogaSecondi: TimeInterval = 60
    /// Attesa prima di riprovare un rinnovo finito con un errore di rete.
    static let riprovaRinnovoSecondi: TimeInterval = 3
    /// L'attesa vera; nei test se ne passa una più corta.
    public static let dorme: @Sendable (TimeInterval) async -> Void = { secondi in
        try? await Task.sleep(nanoseconds: UInt64(max(secondi, 0) * 1_000_000_000))
    }
    static let log = Logger(subsystem: "it.cartellaviva.connettore", category: "lavori")

    @Published public private(set) var stato: Stato = .scollegato
    @Published public private(set) var famiglia: String?
    @Published public private(set) var lavoriOggi = 0
    @Published public private(set) var ultimaDurata: TimeInterval?
    @Published public private(set) var modelliVerificati: [String] = [modelloConsigliato]
    @Published public var pausaConBatteria = true

    public var server: URL { didSet { impostazioni.set(server.absoluteString, forKey: "server") } }
    public var modello: String { didSet { impostazioni.set(modello, forKey: "modello") } }
    public let contesto = 8192
    public let ollama: OllamaClient
    let sessione: URLSession
    let deposito: any DepositoToken
    let impostazioni: UserDefaults
    let attendi: @Sendable (TimeInterval) async -> Void

    private var token: String?
    private var tokenScadeIl = Date.distantPast
    private var ciclo: Task<Void, Never>?
    private var pausaFino: Date?
    private var giornoContato = Calendar.current.startOfDay(for: Date())
    private var attesa: Task<Void, Never>?
    private var rinnovoInCorso: Task<Void, Error>?

    public init(ollama: OllamaClient = OllamaClient(), sessione: URLSession = .shared, deposito: any DepositoToken = DepositoPortachiavi(), impostazioni: UserDefaults = .standard, attendi: @escaping @Sendable (TimeInterval) async -> Void = Connettore.dorme) {
        self.ollama = ollama
        self.sessione = sessione
        self.deposito = deposito
        self.impostazioni = impostazioni
        self.attendi = attendi
        server = impostazioni.string(forKey: "server").flatMap(URL.init(string:)) ?? URL(string: "https://app.cartellaviva.it")!
        modello = impostazioni.string(forKey: "modello") ?? Self.modelloConsigliato
    }

    var client: ServerClient { ServerClient(base: server, sessione: sessione) }

    /// C'è un collegamento salvato su questo Mac?
    public var collegato: Bool { deposito.leggi(per: server) != nil }

    /// All'avvio: se c'è un collegamento salvato, si riparte da lì.
    public func avvia() {
        guard collegato else {
            stato = .scollegato
            return
        }
        avviaCiclo()
    }

    // MARK: Abbinamento

    /// Chiede un codice al server e aspetta che l'utente lo approvi nell'app.
    public func collega(nome: String) async {
        guard ServerClient.indirizzoValido(server) else {
            stato = .errore("Indirizzo del server non valido: serve https://")
            return
        }
        do {
            let r = try await client.richiediAbbinamento(nome: nome)
            stato = .abbinamento(codice: r.userCode, link: r.verificationUriComplete)
            var intervallo = UInt64(max(r.interval, 5))
            let fine = Date().addingTimeInterval(TimeInterval(r.expiresIn))
            while Date() < fine {
                try await Task.sleep(nanoseconds: intervallo * 1_000_000_000)
                guard case .abbinamento = stato else { return }
                do {
                    let t = try await client.token(deviceCode: r.deviceCode)
                    salva(t)
                    avviaCiclo()
                    return
                } catch let e as ErroreOAuth {
                    switch e.error {
                    case "authorization_pending": continue
                    case "slow_down": intervallo += 5
                    case "access_denied": stato = .errore("Collegamento rifiutato nell'app."); return
                    default: stato = .errore(e.errorDescription ?? "Collegamento non riuscito."); return
                    }
                }
            }
            stato = .errore("Il codice è scaduto: riprova.")
        } catch {
            stato = .errore(error.localizedDescription)
        }
    }

    public func annullaAbbinamento() {
        if case .abbinamento = stato { stato = .scollegato }
    }

    /// Dimentica il collegamento su questo Mac (per scollegarlo dalla famiglia, usare l'app).
    public func scollega() {
        ciclo?.cancel()
        deposito.elimina(per: server)
        token = nil
        famiglia = nil
        stato = .scollegato
    }

    public func pausa(_ durata: TimeInterval) {
        pausaFino = Date().addingTimeInterval(durata)
        stato = .inPausa(fino: pausaFino!)
    }

    public func riprendi() {
        pausaFino = nil
        if case .inPausa = stato { stato = .pronto }
    }

    // MARK: Ciclo dei lavori

    /// Il ciclo finisce solo se viene annullato o se il server rifiuta il collegamento.
    /// Ogni altro errore (rete, server, Ollama) si mostra e si riprova dopo l'intervallo.
    private func avviaCiclo() {
        ciclo?.cancel()
        stato = .pronto
        ciclo = Task { [weak self] in
            var ultimaPresentazione = Date.distantPast
            var intervallo: TimeInterval = 20
            var rifiutatoPrima = false
            while !Task.isCancelled {
                guard let self else { return }
                do {
                    if Date().timeIntervalSince(ultimaPresentazione) >= 60 {
                        let imp = try await self.presentati()
                        intervallo = TimeInterval(imp.intervalloSecondi)
                        ultimaPresentazione = Date()
                    }
                    let lavorato = try await self.passo()
                    rifiutatoPrima = false
                    if lavorato { continue }
                } catch ErroreServer.scollegato {
                    return
                } catch ErroreServer.nonAutorizzato {
                    // Il token di accesso non vale più: al giro dopo si rinnova.
                    // Subito la prima volta, poi solo dopo l'intervallo.
                    self.dimenticaAccesso()
                    if !rifiutatoPrima {
                        rifiutatoPrima = true
                        continue
                    }
                    self.stato = .errore(ErroreServer.nonAutorizzato.localizedDescription)
                } catch ErroreServer.troppoPresto {
                    // Il server vuole al massimo una richiesta ogni 5 secondi.
                    await self.aspetta(5)
                    continue
                } catch {
                    self.stato = .errore(error.localizedDescription)
                }
                await self.aspetta(intervallo)
            }
        }
    }

    /// Un'attesa del ciclo, che il risveglio del Mac interrompe.
    private func aspetta(_ secondi: TimeInterval) async {
        let t = Task { [attendi] in await attendi(secondi) }
        attesa = t
        await withTaskCancellationHandler { await t.value } onCancel: { t.cancel() }
        attesa = nil
    }

    /// Il Mac si è appena svegliato: niente attesa, si fa subito un giro.
    public func risvegliato() {
        attesa?.cancel()
    }

    private func presentati() async throws -> Impostazioni {
        let t = try await tokenValido()
        let digest = try? await ollama.modelli().first(where: { $0.nome == modello })?.digest
        let p = Presentazione(versione: Self.versione, sistema: ProcessInfo.processInfo.operatingSystemVersionString, chip: Self.chip(), memoriaGb: Int(ProcessInfo.processInfo.physicalMemory / 1_073_741_824), modello: modello, digest: digest.map { "sha256:" + $0 }, contesto: contesto)
        let imp = try await client.presentati(p, token: t)
        famiglia = imp.famiglia.nome
        modelliVerificati = imp.modelliVerificati
        return imp
    }

    /// Un giro: se ci sono le condizioni, prende un lavoro e lo fa. True se ha lavorato.
    private func passo() async throws -> Bool {
        if let fino = pausaFino {
            if Date() < fino { stato = .inPausa(fino: fino); return false }
            pausaFino = nil
        }
        if pausaConBatteria, let livello = Self.batteria(), livello < 20 {
            stato = .batteriaBassa
            return false
        }
        guard await ollama.versione() != nil else {
            stato = .ollamaNonRisponde
            return false
        }
        if case .analizza = stato {} else { stato = .pronto }
        let t = try await tokenValido()
        let r = try await client.lavori(caratteri: Formula.caratteri(contesto: contesto), token: t)
        guard let lavoro = r.lavori.first else { return false }
        await esegui(lavoro)
        return true
    }

    private func esegui(_ lavoro: Lavoro) async {
        stato = .analizza(lavoro.documento)
        Self.log.info("Lavoro \(lavoro.id, privacy: .public) iniziato")
        // Niente App Nap né sospensione per inattività durante l'analisi.
        let attivita = ProcessInfo.processInfo.beginActivity(options: [.userInitiated, .idleSystemSleepDisabled], reason: "Analisi di un documento di CartellaViva")
        defer { ProcessInfo.processInfo.endActivity(attivita) }
        let inizio = Date()
        let proroga = Task { [client, attendi, weak self] in
            while !Task.isCancelled {
                await attendi(Self.prorogaSecondi)
                guard !Task.isCancelled, let self, let t = try? await self.tokenValido() else { return }
                try? await client.proroga(lavoro.id, token: t)
            }
        }
        defer { proroga.cancel() }
        do {
            let ragiona = await ollama.ragiona(modello)
            let risposta = try await ollama.analizza(modello: modello, istruzioni: lavoro.istruzioni, prompt: lavoro.prompt, schema: lavoro.schema, contesto: contesto, senzaRagionamento: ragiona)
            let durata = Date().timeIntervalSince(inizio)
            let digest = try? await ollama.modelli().first(where: { $0.nome == modello })?.digest
            let t = try await tokenValido()
            let voci = try await client.risultato(lavoro.id, Risultato(risposta: risposta.contenuto, modello: modello, digest: digest.map { "sha256:" + $0 }, durataMs: Int(durata * 1000), tokenPrompt: risposta.tokenPrompt, tokenRisposta: risposta.tokenRisposta), token: t)
            contaLavoro(durata)
            Self.log.info("Lavoro \(lavoro.id, privacy: .public) fatto in \(Int(durata * 1000)) ms, \(voci) voci")
            stato = .pronto
        } catch let e as ErroreOllama {
            Self.log.error("Lavoro \(lavoro.id, privacy: .public): errore \(e.codice, privacy: .public)")
            if let t = token { try? await client.errore(lavoro.id, codice: e.codice, token: t) }
            stato = e == .nonRaggiungibile ? .ollamaNonRisponde : .errore(e.localizedDescription)
        } catch let ErroreServer.conflitto(m) {
            // Lavoro non più nostro o modello non verificato: lo dice il server.
            stato = .errore(m)
        } catch {
            if let t = token { try? await client.errore(lavoro.id, codice: "interrotto", token: t) }
            stato = .errore(error.localizedDescription)
        }
    }

    private func contaLavoro(_ durata: TimeInterval) {
        let oggi = Calendar.current.startOfDay(for: Date())
        if oggi != giornoContato {
            giornoContato = oggi
            lavoriOggi = 0
        }
        lavoriOggi += 1
        ultimaDurata = durata
    }

    // MARK: Token

    private func tokenValido() async throws -> String {
        if let token, tokenScadeIl > Date().addingTimeInterval(60) { return token }
        try await rinnova()
        guard let token else { throw ErroreServer.scollegato }
        return token
    }

    private func dimenticaAccesso() {
        token = nil
        tokenScadeIl = .distantPast
    }

    /// Un rinnovo alla volta: il refresh token vale una volta sola, e due rinnovi
    /// insieme (il ciclo e la proroga) si contenderebbero la stessa coppia di token.
    private func rinnova() async throws {
        if let r = rinnovoInCorso { return try await r.value }
        let r = Task { try await self.rinnovaOra() }
        rinnovoInCorso = r
        defer { rinnovoInCorso = nil }
        try await r.value
    }

    /// Rinnovo con il refresh token salvato.
    /// - Il server rifiuta il collegamento (ErroreOAuth): il Mac si scollega, ErroreServer.scollegato.
    /// - Rete o server che non rispondono: il refresh token resta dov'è, si riprova
    ///   una volta dopo qualche secondo e poi si lascia l'errore al ciclo.
    private func rinnovaOra() async throws {
        guard let refresh = deposito.leggi(per: server) else {
            scollega()
            throw ErroreServer.scollegato
        }
        var tentativi = 0
        while true {
            do {
                salva(try await client.rinnova(refreshToken: refresh))
                return
            } catch is ErroreOAuth {
                scollega()
                stato = .errore(ErroreServer.scollegato.localizedDescription)
                throw ErroreServer.scollegato
            } catch {
                tentativi += 1
                let e = error as? ErroreServer ?? .rete(error.localizedDescription)
                if tentativi >= 2 || Task.isCancelled { throw e }
                // Se la risposta si è persa dopo la rotazione, il server accetta
                // ancora per 60 secondi il refresh token di prima.
                await attendi(Self.riprovaRinnovoSecondi)
            }
        }
    }

    private func salva(_ t: Token) {
        token = t.accessToken
        tokenScadeIl = Date().addingTimeInterval(TimeInterval(t.expiresIn))
        deposito.salva(t.refreshToken, per: server)
    }

    // MARK: Il Mac

    static func chip() -> String {
        var size = 0
        sysctlbyname("machdep.cpu.brand_string", nil, &size, nil, 0)
        var c = [CChar](repeating: 0, count: max(size, 1))
        sysctlbyname("machdep.cpu.brand_string", &c, &size, nil, 0)
        return String(decoding: c.prefix(while: { $0 != 0 }).map { UInt8(bitPattern: $0) }, as: UTF8.self)
    }

    /// Livello della batteria in percentuale, o nil se il Mac è collegato alla corrente o non ha batteria.
    static func batteria() -> Int? {
        guard let info = IOPSCopyPowerSourcesInfo()?.takeRetainedValue(),
              let fonti = IOPSCopyPowerSourcesList(info)?.takeRetainedValue() as? [CFTypeRef] else { return nil }
        for fonte in fonti {
            guard let d = IOPSGetPowerSourceDescription(info, fonte)?.takeUnretainedValue() as? [String: Any] else { continue }
            if d[kIOPSPowerSourceStateKey] as? String == kIOPSACPowerValue { return nil }
            if let ora = d[kIOPSCurrentCapacityKey] as? Int, let max = d[kIOPSMaxCapacityKey] as? Int, max > 0 { return ora * 100 / max }
        }
        return nil
    }
}
