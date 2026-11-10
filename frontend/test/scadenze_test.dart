import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/scadenze/model/scadenza.dart';

Scadenza _s(String id, {String stato = 'aperta', int giorni = 0, DateTime? data}) => Scadenza(
      id: id,
      titolo: 'Scadenza $id',
      data: data ?? DateTime(2026, 10, 1),
      stato: stato,
      giorniMancanti: giorni,
      ownerId: 'u1',
    );

void main() {
  test('fromJson legge i collegamenti annidati', () {
    final s = Scadenza.fromJson({
      'id': 'a',
      'titolo': 'Bollo auto',
      'data': '2026-12-31',
      'ricorrenza': 'annuale',
      'promemoriaGiorni': 7,
      'documentoId': 'd1',
      'documento': {'id': 'd1', 'titolo': 'Libretto'},
      'membro': {'id': 'm1', 'nome': 'Anna'},
      'membroId': 'm1',
      'categoria': {'id': 'c1', 'nome': 'Veicoli', 'colore': '#ff0000'},
      'categoriaId': 'c1',
      'stato': 'aperta',
      'origine': 'manuale',
      'scaduta': false,
      'giorniMancanti': 91,
      'ownerId': 'u1',
    });
    expect(s.data, DateTime(2026, 12, 31));
    expect(s.documentoTitolo, 'Libretto');
    expect(s.membroNome, 'Anna');
    expect(s.categoriaColore, '#ff0000');
    expect(s.ricorrenzaLabel, 'ogni anno');
    expect(s.quando, 'tra 91 giorni');
    expect(s.daRichiesta, isFalse);
  });

  test('quando descrive i giorni mancanti e gli stati', () {
    expect(_s('a', giorni: 0).quando, 'oggi');
    expect(_s('a', giorni: 1).quando, 'domani');
    expect(_s('a', giorni: -1).quando, 'scaduta ieri');
    expect(_s('a', giorni: -4).quando, 'scaduta da 4 giorni');
    expect(_s('a', stato: 'fatta').quando, 'fatta');
    expect(_s('a', stato: 'in_verifica', giorni: -2).quando, 'inviata, in verifica');
  });

  test('raggruppaScadenze divide per stato e distanza', () {
    final gruppi = raggruppaScadenze([
      _s('scaduta', giorni: -2),
      _s('oggi', giorni: 0),
      _s('trenta', giorni: 30),
      _s('lontana', giorni: 31),
      _s('verifica', stato: 'in_verifica', giorni: -5),
      _s('fatta1', stato: 'fatta', data: DateTime(2026, 9, 1)),
      _s('fatta2', stato: 'fatta', data: DateTime(2026, 9, 20)),
    ]);
    List<String> ids(GruppoScadenze g) => gruppi[g]!.map((s) => s.id).toList();
    expect(ids(GruppoScadenze.scadute), ['scaduta']);
    expect(ids(GruppoScadenze.prossimi30), ['oggi', 'trenta']);
    expect(ids(GruppoScadenze.piuAvanti), ['lontana']);
    expect(ids(GruppoScadenze.inVerifica), ['verifica']);
    // Le fatte dalla più recente.
    expect(ids(GruppoScadenze.fatte), ['fatta2', 'fatta1']);
  });

  test('ScadenzaInput.toJson normalizza testo e data', () {
    final json = ScadenzaInput(titolo: '  Assicurazione ', note: '   ', data: DateTime(2027, 3, 5), ricorrenza: 'mensile').toJson();
    expect(json['titolo'], 'Assicurazione');
    expect(json['note'], isNull);
    expect(json['data'], '2027-03-05');
    expect(json['ricorrenza'], 'mensile');
    expect(json['promemoriaGiorni'], 3);
  });
}
