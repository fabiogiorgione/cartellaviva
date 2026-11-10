{{flutter_js}}
{{flutter_build_config}}

// Motore grafico (CanvasKit) e caratteri di riserva serviti dall'app stessa,
// non dai server di Google: l'app web non contatta Google per disegnare.
_flutter.loader.load({
  serviceWorkerSettings: {
    serviceWorkerVersion: {{flutter_service_worker_version}},
  },
  config: {
    canvasKitBaseUrl: "canvaskit/",
    fontFallbackBaseUrl: "font-fallback/",
  },
});
