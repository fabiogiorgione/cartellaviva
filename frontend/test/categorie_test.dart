import 'package:flutter_lucide/flutter_lucide.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/icone_categoria.dart';

void main() {
  test('ogni icona proposta e quelle delle categorie di sistema hanno un\'icona vera', () {
    // Nomi in backend/config/packages/categorie.yaml.
    const sistema = ['receipt', 'id-card', 'heart-pulse', 'pill', 'car', 'house', 'graduation-cap', 'inbox', 'building'];
    for (final nome in [...iconeProposte, ...sistema]) {
      expect(iconeCategoria.containsKey(nome), isTrue, reason: nome);
    }
    expect(iconaCategoria('nome-sconosciuto'), LucideIcons.folder);
    expect(iconeProposte.toSet().length, iconeProposte.length, reason: 'nessun doppione');
  });

  test('tipo di file in parole, per la lista dei documenti', () {
    String tipo(String mime) => Documento(id: 'd', titolo: 't', mimeType: mime).tipoFile;
    expect(tipo('application/pdf'), 'PDF');
    expect(tipo('image/heic'), 'Foto');
    expect(tipo('application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'Documento di testo');
    expect(tipo('application/vnd.oasis.opendocument.spreadsheet'), 'Foglio di calcolo');
    expect(tipo('application/vnd.ms-powerpoint'), 'Presentazione');
    expect(tipo('text/rtf'), 'Documento di testo');
  });

  test('il numero di documenti arriva dall\'elenco e manca se non visibile', () {
    final conNumero = Categoria.fromJson({'id': 'c1', 'nome': 'Sport', 'icona': 'trophy', 'documenti': 3});
    expect(conNumero.documenti, 3);
    expect(Categoria.fromJson({'id': 'c2', 'nome': 'Salute', 'documenti': null}).documenti, isNull);
  });
}
