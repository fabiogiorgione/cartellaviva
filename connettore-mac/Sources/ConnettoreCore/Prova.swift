import Foundation

/// L'analisi di prova del primo avvio: uno scontrino finto, per vedere che il
/// modello funziona e quanto ci mette. Nessun dato vero.
public enum Prova {
    static let istruzioni = "Leggi il documento e restituisci le voci in JSON secondo lo schema. Usa solo valori scritti nel testo."
    static let testo = "FARMACIA CENTRALE SNC\nVia Roma 1, Milano\nScontrino parlante\nCodice fiscale del destinatario RSSMRA80A01F205X\nFarmaco 12,40\nTicket 2,00\nTOTALE EURO 14,40\nPagamento elettronico\n12/03/2025 10:41"
    static let schema = #"{"type":"object","properties":{"voci":{"type":"array","items":{"type":"object","properties":{"emittente":{"type":"string"},"data":{"type":"string"},"importo":{"type":"string"}},"required":["emittente","data","importo"]}}},"required":["voci"]}"#

    /// Durata e importo letto (dovrebbe essere 14,40).
    public static func esegui(con ollama: OllamaClient, modello: String) async throws -> (durata: TimeInterval, importo: String?) {
        let inizio = Date()
        let r = try await ollama.analizza(modello: modello, istruzioni: istruzioni, prompt: testo, schema: schema, contesto: 4096, senzaRagionamento: await ollama.ragiona(modello), timeout: 180)
        let j = try? JSONSerialization.jsonObject(with: Data(r.contenuto.utf8)) as? [String: Any]
        let importo = ((j?["voci"] as? [[String: Any]])?.first?["importo"]) as? String
        return (Date().timeIntervalSince(inizio), importo)
    }
}
