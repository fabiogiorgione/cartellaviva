import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';

void main() {
  test('documento con scadenza proposta dal testo', () {
    final d = Documento.fromJson({
      'id': 'd1',
      'titolo': 'Carta identità Mario',
      'scadenzaProposta': {
        'documentoId': 'd1',
        'documentoTitolo': 'Carta identità Mario',
        'data': '2036-03-12',
        'tipo': "Carta d'identità",
        'titoloSuggerito': "Scadenza Carta d'identità",
        'membri': [
          {'id': 'm1', 'nome': 'Mario'},
        ],
        'categoriaId': 'c1',
      },
    });
    final p = d.scadenzaProposta!;
    expect([p.data, p.tipo, p.titoloSuggerito, p.membri.single.id], [DateTime(2036, 3, 12), "Carta d'identità", "Scadenza Carta d'identità", 'm1']);
    expect(Documento.fromJson({'id': 'd2', 'titolo': 'x'}).scadenzaProposta, isNull);
  });
}
