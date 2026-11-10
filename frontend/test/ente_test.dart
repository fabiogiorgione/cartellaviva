import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/ente/model/ente.dart';
import 'package:cartellaviva/features/richieste/model/richiesta.dart';

void main() {
  test('passaggio d\'anno: nome dell\'anno e dei gruppi', () {
    expect(annoSuccessivo('2026/27'), '2027/28');
    expect(annoSuccessivo('2099/00'), '2100/01');
    expect(annoSuccessivo('anno 1'), '');
    expect(gruppoSuccessivo('3ªB'), '4ªB');
    expect(gruppoSuccessivo('5ªA'), '', reason: 'le quinte escono');
    expect(gruppoSuccessivo('Minivolley'), 'Minivolley');
  });

  test('richiesta della famiglia: stato e testi', () {
    final r = RichiestaFamiglia.fromJson({
      'id': 'd1',
      'ente': {'id': 'e1', 'nome': 'Scuola Verdi'},
      'tipo': 'firma',
      'titolo': 'Uscita al museo',
      'iscritto': 'Leo Rossi',
      'entroIl': '2026-10-09',
      'stato': 'da_rifirmare',
      'scaduta': false,
      'firmatari': 'tutti',
      'referenti': [
        {'id': 'r1', 'nome': 'Mario Rossi', 'firmato': true},
        {'id': 'r2', 'nome': 'Giulia Rossi', 'firmato': false},
      ],
      'motivoEnte': 'Manca una firma',
      'createdAt': '2026-10-01T10:00:00+00:00',
      'allegato': {'nome': 'autorizzazione.pdf', 'dimensione': 1024},
    });
    expect(r.daFare, isTrue);
    expect(r.statoLabel, 'Da rifirmare');
    expect(r.referenti.where((x) => x.firmato).map((x) => x.nome), ['Mario Rossi']);
    expect(r.allegatoNome, 'autorizzazione.pdf');
    expect(tipoRichiestaLabel('presa_visione'), 'Presa visione');
  });

  test('avanzamento dell\'ente: raggiunti esclude chi non ha referenti collegati', () {
    final r = RichiestaEnte.fromJson({
      'id': 'r1',
      'tipo': 'firma',
      'titolo': 'Uscita',
      'firmatari': 'tutti',
      'annullata': false,
      'totali': {'iscritti': 25, 'completate': 19, 'daVerificare': 2, 'rifiutate': 1, 'mancanti': 2, 'nonRaggiunti': 1},
      'avanzamento': [
        {
          'iscritto': {'id': 'i1', 'nome': 'Sara Bianchi'},
          'stato': 'mancante',
          'destinatari': [
            {'id': 'd1', 'stato': 'vista', 'referenti': [{'id': 'x', 'nome': 'Elena Conti', 'firmato': false}], 'haFile': false},
          ],
        },
      ],
    });
    expect(r.totali.raggiunti, 24);
    expect(r.avanzamento.single.destinatari.single.statoLabel, 'aperta, non risposta');
  });

  test('modello: regole leggibili e anteprima con il link sicuro', () {
    final m = ModelloRichiesta.fromJson({
      'id': 'm1',
      'nome': 'Certificato medico',
      'tipo': 'invio',
      'titolo': 'Certificato medico sportivo',
      'firmatari': 'uno',
      'chiediValidita': true,
      'rinnovoGiorni': 30,
      'conservazioneMesi': 12,
      'giorniRisposta': 14,
      'ricorrenzaGiorno': 15,
      'ricorrenzaMese': 9,
      'prossimoInvio': '2027-09-15',
    });
    expect(m.regole, 'chiede la validità · rinnovo 30 gg prima della scadenza · conservazione 12 mesi · ogni anno il 15/9 a tutto l\'ente');
    expect(m.prossimoInvio, DateTime(2027, 9, 15));

    final a = AnteprimaInvio.fromJson({'iscritti': 25, 'referenti': 41, 'referentiCollegati': 35, 'famiglie': 24, 'viaLink': 6, 'nonRaggiunti': ['Marco Ferri']});
    expect(a.viaLink, 6);
    final d = DestinatarioEnte.fromJson({'id': 'd', 'stato': 'completata', 'viaLink': true, 'validoFino': '2027-06-30', 'fileRimossoIl': '2028-07-01T00:00:00+00:00'});
    expect([d.viaLink, d.validoFino, d.fileRimosso], [true, DateTime(2027, 6, 30), true]);
  });

  test('referente: legenda dello stato', () {
    expect(Referente(nome: 'A', cognome: 'B').statoLabel, 'manca l\'email');
    expect(Referente(nome: 'A', cognome: 'B', email: 'a@b.it').statoLabel, 'da invitare');
    expect(Referente(nome: 'A', cognome: 'B', email: 'a@b.it', stato: 'non_sono_io').statoLabel, contains('non sono io'));
    expect(Referente(nome: ' A ', cognome: 'B', email: ' ').toJson()['email'], isNull);
  });

  test('firma con codice: modalità e firmatario di turno', () {
    Map<String, dynamic> json(String modalita, List<Map<String, dynamic>> referenti) => {
          'id': 'd1',
          'ente': {'id': 'e1', 'nome': 'Scuola Verdi'},
          'tipo': 'firma',
          'titolo': 'Uscita al museo',
          'iscritto': 'Leo Rossi',
          'stato': 'vista',
          'scaduta': false,
          'firmatari': 'tutti',
          'modalitaFirma': modalita,
          'referenti': referenti,
        };
    final r = RichiestaFamiglia.fromJson(json('codice', [
      {'id': 'r1', 'nome': 'Mario Rossi', 'firmato': true, 'tuo': false},
      {'id': 'r2', 'nome': 'Giulia Rossi', 'firmato': false, 'tuo': true},
    ]));
    expect(r.firmaConCodice, isTrue);
    expect(r.daFirmareIo?.nome, 'Giulia Rossi');

    final giaFirmato = RichiestaFamiglia.fromJson(json('codice', [
      {'id': 'r2', 'nome': 'Giulia Rossi', 'firmato': true, 'tuo': true},
    ]));
    expect(giaFirmato.daFirmareIo, isNull);
    expect(RichiestaFamiglia.fromJson(json('cartacea', [])).firmaConCodice, isFalse);
    expect(RichiestaFamiglia.fromJson({...json('codice', []), 'tipo': 'invio'}).firmaConCodice, isFalse);

    final m = ModelloRichiesta.fromJson({'id': 'm', 'nome': 'Uscite', 'tipo': 'firma', 'titolo': 'Uscita', 'modalitaFirma': 'codice'});
    expect(m.regole, 'firma con codice email');
    expect(RichiestaEnte.fromJson({'id': 'x', 'tipo': 'firma', 'titolo': 'T'}).modalitaFirma, 'cartacea');
  });
}
