import AppKit
import ConnettoreCore
import ServiceManagement
import SystemConfiguration
import SwiftUI
import os

/// CartellaViva Connettore: vive nella barra dei menu (LSUIElement), con una
/// finestra per il primo avvio e le impostazioni.
@main
struct CartellaVivaConnettoreApp: App {
    @NSApplicationDelegateAdaptor(Avvio.self) private var avvio
    @StateObject private var connettore = Avvio.connettore

    var body: some Scene {
        MenuBarExtra {
            MenuConnettore().environmentObject(connettore)
        } label: {
            Image(systemName: icona)
        }
    }

    private var icona: String {
        switch connettore.stato {
        case .analizza: "brain.head.profile.fill"
        case .pronto: "brain.head.profile"
        case .scollegato, .abbinamento: "link.badge.plus"
        default: "exclamationmark.triangle"
        }
    }
}

/// All'avvio riparte il collegamento salvato, anche senza aprire il menu; se il
/// Mac non è collegato si apre la finestra di configurazione.
final class Avvio: NSObject, NSApplicationDelegate {
    @MainActor static let connettore = Connettore()
    @MainActor private static var finestra: NSWindow?

    @MainActor func applicationDidFinishLaunching(_ notification: Notification) {
        Self.connettore.avvia()
        if !Self.connettore.collegato { Self.mostraImpostazioni() }
        // Al risveglio un giro subito, senza aspettare l'intervallo.
        NSWorkspace.shared.notificationCenter.addObserver(forName: NSWorkspace.didWakeNotification, object: nil, queue: .main) { _ in
            Task { @MainActor in Self.connettore.risvegliato() }
        }
        // Per le prove (sviluppo): --collega "Nome" fa come il pulsante "Collega alla famiglia".
        let arg = ProcessInfo.processInfo.arguments
        if let i = arg.firstIndex(of: "--collega"), i + 1 < arg.count {
            Task { await Self.connettore.collega(nome: arg[i + 1]) }
        }
    }

    @MainActor static func mostraImpostazioni() {
        if finestra == nil {
            let w = NSWindow(contentRect: NSRect(x: 0, y: 0, width: 560, height: 640), styleMask: [.titled, .closable, .miniaturizable, .resizable], backing: .buffered, defer: false)
            w.title = "CartellaViva Connettore"
            w.isReleasedWhenClosed = false
            let contenuto = NSHostingController(rootView: Impostazioni().environmentObject(connettore).frame(minWidth: 520, minHeight: 560))
            // La dimensione la decide la finestra, non la vista (un Form non ha una dimensione ideale).
            contenuto.sizingOptions = []
            w.contentViewController = contenuto
            w.setContentSize(NSSize(width: 560, height: 640))
            w.center()
            finestra = w
        }
        if #available(macOS 14, *) { NSApp.activate() } else { NSApp.activate(ignoringOtherApps: true) }
        finestra?.makeKeyAndOrderFront(nil)
        finestra?.orderFrontRegardless()
    }
}

struct MenuConnettore: View {
    @EnvironmentObject var c: Connettore

    var body: some View {
        Text(c.stato.descrizione)
        if let f = c.famiglia { Text("Famiglia: \(f)") }
        if c.lavoriOggi > 0 {
            Text("Oggi: \(c.lavoriOggi) \(c.lavoriOggi == 1 ? "documento" : "documenti")\(c.ultimaDurata.map { String(format: " · ultimo in %.0f s", $0) } ?? "")")
        }
        Divider()
        if case .inPausa = c.stato {
            Button("Riprendi") { c.riprendi() }
        } else if c.famiglia != nil {
            Button("Pausa per 1 ora") { c.pausa(3600) }
        }
        Button("Impostazioni…") { Avvio.mostraImpostazioni() }
        Divider()
        Button("Esci") { NSApp.terminate(nil) }
    }
}

/// Primo avvio e impostazioni, in una finestra sola, dall'alto in basso:
/// server, Ollama, modello, prova, collegamento, avvio al login.
struct Impostazioni: View {
    @EnvironmentObject var c: Connettore
    @State private var indirizzo = ""
    @State private var ollama: String?
    @State private var controllato = false
    @State private var modelli: [String] = []
    @State private var scaricamento: Double?
    @State private var faseScaricamento = ""
    @State private var prova: String?
    // Il nome del computer dalle impostazioni di sistema (ProcessInfo.hostName interroga la rete e può bloccare).
    @State private var nomeMac = (SCDynamicStoreCopyComputerName(nil, nil) as String?) ?? "Mac"
    @State private var avvioAlLogin = SMAppService.mainApp.status == .enabled
    @State private var avvisoRete: String?

    var body: some View {
        Form {
            Section("Server") {
                TextField("Indirizzo", text: $indirizzo)
                    .onSubmit { salvaServer() }
                Text("Predefinito: app.cartellaviva.it. Cambialo solo se la tua famiglia usa un altro server.").font(.caption).foregroundStyle(.secondary)
            }
            Section("Ollama") {
                if !controllato {
                    ProgressView()
                } else if let v = ollama {
                    Label("Ollama \(v) è in funzione", systemImage: "checkmark.circle.fill").foregroundStyle(.green)
                } else {
                    Label("Ollama non risponde su questo Mac", systemImage: "xmark.circle.fill").foregroundStyle(.red)
                    Text("Ollama fa girare il modello sul Mac. Installalo dal sito ufficiale, aprilo, poi torna qui.").font(.caption)
                    Link("Scarica Ollama", destination: URL(string: "https://ollama.com/download/mac")!)
                }
                if let a = avvisoRete { Text(a).font(.caption).foregroundStyle(.orange) }
            }
            if ollama != nil {
                Section("Modello") {
                    Picker("Modello", selection: Binding(get: { c.modello }, set: { c.modello = $0 })) {
                        ForEach(Array(Set(modelli + [c.modello])).sorted(), id: \.self) { m in
                            Text(m + (c.modelliVerificati.contains(m) ? "  · verificato" : "  · sperimentale")).tag(m)
                        }
                    }
                    if !modelli.contains(c.modello) {
                        if let s = scaricamento {
                            ProgressView(value: s) { Text("Scarico \(c.modello)… \(faseScaricamento)") }
                        } else {
                            Button("Scarica \(c.modello) (circa 2,5 GB)") { Task { await scarica() } }
                        }
                    } else {
                        HStack {
                            Button("Prova il modello") { Task { await provaModello() } }
                            if let p = prova { Text(p).font(.caption) }
                        }
                    }
                    if !c.modelliVerificati.contains(c.modello) {
                        Text("Il server accetta solo i modelli verificati: con uno sperimentale i lavori vengono rifiutati.").font(.caption).foregroundStyle(.orange)
                    }
                }
            }
            Section("Famiglia") {
                switch c.stato {
                case .scollegato, .errore:
                    TextField("Nome di questo Mac", text: $nomeMac)
                    Button("Collega alla famiglia") { salvaServer(); Task { await c.collega(nome: nomeMac) } }
                        .disabled(ollama == nil || !modelli.contains(c.modello))
                    if case let .errore(m) = c.stato { Text(m).font(.caption).foregroundStyle(.red) }
                case let .abbinamento(codice, link):
                    Abbinamento(codice: codice, link: link)
                    Button("Annulla") { c.annullaAbbinamento() }
                default:
                    Label("Collegato a \(c.famiglia ?? "…") · \(c.stato.descrizione)", systemImage: "checkmark.seal.fill")
                    Text("Per scollegarlo dalla famiglia: nell'app, Sicurezza e accesso → AI sul tuo Mac.").font(.caption).foregroundStyle(.secondary)
                    Button("Dimentica il collegamento su questo Mac", role: .destructive) { c.scollega() }
                }
            }
            Section("Avvio") {
                Toggle("Apri all'accesso al Mac", isOn: $avvioAlLogin)
                    .onChange(of: avvioAlLogin) { attivo in
                        try? attivo ? SMAppService.mainApp.register() : SMAppService.mainApp.unregister()
                    }
                Toggle("Pausa quando la batteria è sotto il 20%", isOn: $c.pausaConBatteria)
            }
        }
        .formStyle(.grouped)
        .task { await controlla() }
        .onAppear {
            indirizzo = c.server.absoluteString
        }
    }

    private func salvaServer() {
        if let u = URL(string: indirizzo.trimmingCharacters(in: .whitespaces)), u != c.server { c.server = u }
    }

    private func controlla() async {
        while !Task.isCancelled {
            ollama = await c.ollama.versione()
            modelli = (try? await c.ollama.modelli().map(\.nome)) ?? []
            controllato = true
            avvisoRete = await Self.ollamaEspostoInRete()
            try? await Task.sleep(nanoseconds: 3_000_000_000)
        }
    }

    private func scarica() async {
        scaricamento = 0
        do {
            try await c.ollama.scarica(c.modello) { quota, fase in
                Task { @MainActor in
                    scaricamento = quota
                    faseScaricamento = fase
                }
            }
        } catch {
            prova = error.localizedDescription
        }
        scaricamento = nil
    }

    private func provaModello() async {
        prova = "Analisi di uno scontrino di prova…"
        do {
            let r = try await Prova.esegui(con: c.ollama, modello: c.modello)
            prova = String(format: "Fatto in %.1f s: importo letto %@", r.durata, r.importo ?? "nessuno")
        } catch {
            prova = error.localizedDescription
        }
    }

    /// Ollama deve ascoltare solo su questo Mac: se risponde anche dall'indirizzo di rete, avvisa.
    static func ollamaEspostoInRete() async -> String? {
        guard let ip = Rete.indirizzoLocale(), let url = URL(string: "http://\(ip):11434") else { return nil }
        guard await OllamaClient(base: url).versione() != nil else { return nil }
        return "Ollama risponde anche dalla rete (\(ip)): chiunque sulla stessa rete può usarlo. Togli OLLAMA_HOST=0.0.0.0 dalle impostazioni di Ollama."
    }
}

/// Codice grande, QR del link e pulsante per aprirlo nel browser.
struct Abbinamento: View {
    let codice: String
    let link: String

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Apri CartellaViva → Sicurezza e accesso → AI sul tuo Mac → Collega un Mac, e scrivi:").font(.callout)
            Text(codice).font(.system(size: 34, weight: .semibold, design: .monospaced)).textSelection(.enabled)
            HStack(alignment: .top, spacing: 16) {
                if let qr = QR.immagine(link) {
                    Image(nsImage: qr).interpolation(.none).resizable().frame(width: 140, height: 140)
                }
                VStack(alignment: .leading, spacing: 8) {
                    Text("Oppure inquadra il codice con l'iPhone su cui usi CartellaViva.").font(.caption)
                    Link("Apri nel browser", destination: URL(string: link)!)
                    ProgressView().controlSize(.small)
                    Text("Aspetto l'approvazione…").font(.caption).foregroundStyle(.secondary)
                }
            }
        }
    }
}

enum QR {
    static func immagine(_ testo: String) -> NSImage? {
        guard let f = CIFilter(name: "CIQRCodeGenerator") else { return nil }
        f.setValue(Data(testo.utf8), forKey: "inputMessage")
        f.setValue("M", forKey: "inputCorrectionLevel")
        guard let out = f.outputImage?.transformed(by: CGAffineTransform(scaleX: 8, y: 8)) else { return nil }
        let rep = NSCIImageRep(ciImage: out)
        let img = NSImage(size: rep.size)
        img.addRepresentation(rep)
        return img
    }
}

/// Il primo indirizzo IPv4 di rete locale del Mac (senza interrogare la rete).
enum Rete {
    static func indirizzoLocale() -> String? {
        var lista: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&lista) == 0, let primo = lista else { return nil }
        defer { freeifaddrs(lista) }
        for p in sequence(first: primo, next: { $0.pointee.ifa_next }) {
            guard let a = p.pointee.ifa_addr, a.pointee.sa_family == UInt8(AF_INET) else { continue }
            var host = [CChar](repeating: 0, count: Int(NI_MAXHOST))
            guard getnameinfo(a, socklen_t(a.pointee.sa_len), &host, socklen_t(host.count), nil, 0, NI_NUMERICHOST) == 0 else { continue }
            let ip = String(decoding: host.prefix(while: { $0 != 0 }).map { UInt8(bitPattern: $0) }, as: UTF8.self)
            if ip.hasPrefix("192.168.") || ip.hasPrefix("10.") || ip.hasPrefix("172.") { return ip }
        }
        return nil
    }
}
