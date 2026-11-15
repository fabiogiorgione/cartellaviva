// swift-tools-version:6.0
// CartellaViva Connettore: l'app per Mac che fa le analisi AI dei documenti
// al posto del server (docs/connettore-ai.md). AGPL-3.0, come il resto.
import PackageDescription

let package = Package(
    name: "CartellaVivaConnettore",
    defaultLocalization: "it",
    platforms: [.macOS(.v13)],
    products: [
        .executable(name: "CartellaVivaConnettore", targets: ["CartellaVivaConnettore"]),
    ],
    targets: [
        // Logica senza interfaccia: server, Ollama, Portachiavi, ciclo dei lavori.
        .target(name: "ConnettoreCore"),
        // App nella barra dei menu (SwiftUI).
        .executableTarget(name: "CartellaVivaConnettore", dependencies: ["ConnettoreCore"]),
        .testTarget(name: "ConnettoreCoreTests", dependencies: ["ConnettoreCore"]),
    ]
)
