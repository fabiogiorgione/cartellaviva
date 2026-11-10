// pdf.js per l'anteprima dei PDF (pacchetto printing): la copia locale in web/pdfjs/,
// non quella che printing scaricherebbe da unpkg.com a ogni avvio. Vedi web/pdfjs/README.md.
window.dartPdfJsBaseUrl = new URL('pdfjs/', document.baseURI).href;
