import Flutter
import UIKit

#if canImport(FoundationModels)
import FoundationModels
#endif

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "AiLocale") {
      AiLocale.registra(registrar.messenger())
    }
  }
}

/// Analisi dei documenti sul telefono con il modello di Apple (Foundation
/// Models, iOS 26+ con Apple Intelligence). Canale "cartellaviva/ai_locale":
/// - "stato" -> {disponibile, motivo?, contesto?}
/// - "estrai" {istruzioni, prompt, schema (JSON Schema ridotto)} -> JSON
/// Il testo dei documenti resta in memoria: niente viene scritto su disco.
enum AiLocale {
  static func registra(_ messenger: FlutterBinaryMessenger) {
    let canale = FlutterMethodChannel(name: "cartellaviva/ai_locale", binaryMessenger: messenger)
    canale.setMethodCallHandler { call, result in
      switch call.method {
      case "stato":
        result(stato())
      case "estrai":
        guard let a = call.arguments as? [String: Any],
              let istruzioni = a["istruzioni"] as? String,
              let prompt = a["prompt"] as? String,
              let schema = a["schema"] as? String
        else {
          result(FlutterError(code: "argomenti", message: "Servono istruzioni, prompt e schema.", details: nil))
          return
        }
        estrai(istruzioni: istruzioni, prompt: prompt, schema: schema, result: result)
      default:
        result(FlutterMethodNotImplemented)
      }
    }
  }

  static func stato() -> [String: Any] {
    #if canImport(FoundationModels)
    if #available(iOS 26.0, *) {
      let modello = SystemLanguageModel.default
      switch modello.availability {
      case .available:
        guard modello.supportsLocale(Locale.current) else {
          return ["disponibile": false, "motivo": "lingua"]
        }
        return ["disponibile": true, "contesto": modello.contextSize]
      case .unavailable(let motivo):
        switch motivo {
        case .deviceNotEligible: return ["disponibile": false, "motivo": "dispositivo"]
        case .appleIntelligenceNotEnabled: return ["disponibile": false, "motivo": "apple_intelligence"]
        case .modelNotReady: return ["disponibile": false, "motivo": "modello"]
        @unknown default: return ["disponibile": false, "motivo": "altro"]
        }
      }
    }
    #endif
    return ["disponibile": false, "motivo": "ios"]
  }

  static func estrai(istruzioni: String, prompt: String, schema: String, result: @escaping FlutterResult) {
    #if canImport(FoundationModels)
    if #available(iOS 26.0, *) {
      Task {
        do {
          guard let dati = schema.data(using: .utf8),
                let json = try JSONSerialization.jsonObject(with: dati) as? [String: Any]
          else {
            throw NSError(domain: "AiLocale", code: 1, userInfo: [NSLocalizedDescriptionKey: "Schema non valido."])
          }
          let radice = try GenerationSchema(root: dinamico(json, nome: "Risultato"), dependencies: [])
          let sessione = LanguageModelSession(instructions: istruzioni)
          let risposta = try await sessione.respond(to: prompt, schema: radice, options: GenerationOptions(temperature: 0))
          let testo = risposta.content.jsonString
          await MainActor.run { result(testo) }
        } catch {
          let messaggio = error.localizedDescription
          await MainActor.run { result(FlutterError(code: "generazione", message: messaggio, details: nil)) }
        }
      }
      return
    }
    #endif
    result(FlutterError(code: "non_disponibile", message: "Il modello sul telefono non è disponibile.", details: nil))
  }

  #if canImport(FoundationModels)
  /// JSON Schema ridotto (oggetti, stringhe, stringhe da un elenco, liste) -> schema dinamico.
  @available(iOS 26.0, *)
  static func dinamico(_ s: [String: Any], nome: String) throws -> DynamicGenerationSchema {
    switch s["type"] as? String {
    case "object":
      let proprieta = (s["properties"] as? [String: Any]) ?? [:]
      let richieste = Set((s["required"] as? [String]) ?? [])
      let ordine = (s["x-order"] as? [String]) ?? proprieta.keys.sorted()
      var props: [DynamicGenerationSchema.Property] = []
      for chiave in ordine {
        guard let figlio = proprieta[chiave] as? [String: Any] else { continue }
        props.append(.init(name: chiave, description: figlio["description"] as? String, schema: try dinamico(figlio, nome: "\(nome)_\(chiave)"), isOptional: !richieste.contains(chiave)))
      }
      return DynamicGenerationSchema(name: nome, description: s["description"] as? String, properties: props)
    case "array":
      guard let elementi = s["items"] as? [String: Any] else {
        throw NSError(domain: "AiLocale", code: 2, userInfo: [NSLocalizedDescriptionKey: "Lista senza items."])
      }
      return DynamicGenerationSchema(arrayOf: try dinamico(elementi, nome: "\(nome)_elemento"), minimumElements: s["minItems"] as? Int, maximumElements: s["maxItems"] as? Int)
    default:
      if let scelte = s["enum"] as? [String], !scelte.isEmpty {
        return DynamicGenerationSchema(name: nome, description: s["description"] as? String, anyOf: scelte)
      }
      return DynamicGenerationSchema(type: String.self)
    }
  }
  #endif
}
