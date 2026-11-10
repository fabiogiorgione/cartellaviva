import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/ente/view/registro_ente_screen.dart';
import 'package:cartellaviva/features/ente/view/verifica_affiancata_screen.dart';

Map<String, dynamic> _voce({String tipo = 'firma', String firmatari = 'tutti', bool validita = false, String? validoFino}) => {
      'richiesta': {'id': 'r1', 'tipo': tipo, 'titolo': 'Uscita al museo', 'firmatari': firmatari, 'chiediValidita': validita, 'haAllegato': true, 'gruppo': '3ªB'},
      'destinatario': {
        'id': 'd1',
        'iscritto': 'Leo Rossi',
        'referenti': [
          {'nome': 'Mario Rossi', 'firmato': true},
          {'nome': 'Giulia Rossi', 'firmato': false},
        ],
        'rispostaIl': '2026-10-02T09:00:00+00:00',
        'validoFino': validoFino,
        'viaLink': false,
        'file': {'nome': 'firmato.pdf', 'mimeType': 'application/pdf', 'dimensione': 1000},
      },
    };

void main() {
  test('controlli della verifica: una firma per ogni referente se servono tutte', () {
    final v = DaVerificare.fromJson(_voce());
    expect(v.controlli, [
      'È il documento richiesto ("Uscita al museo")',
      'È leggibile e completo (tutte le pagine)',
      'Firma di Mario Rossi presente',
      'Firma di Giulia Rossi presente (non dichiarata dalla famiglia)',
    ]);
    expect(v.file?.mimeType, 'application/pdf');
  });

  test('controlli: almeno una firma, validità, invio documento', () {
    expect(DaVerificare.fromJson(_voce(firmatari: 'uno')).controlli.last, 'È presente almeno una firma di un referente');
    final cert = DaVerificare.fromJson(_voce(tipo: 'invio', validita: true, validoFino: '2027-06-30'));
    expect(cert.controlli.length, 3);
    expect(cert.controlli.last, contains('30/06/2027'));
  });

  test('registro: azioni in italiano, sconosciute con il codice', () {
    expect(descrizioneAzione('richiesta.accettata'), 'Ha accettato una risposta');
    expect(descrizioneAzione('azione.nuova'), 'azione.nuova');
    final v = VoceRegistro.fromJson({'id': 12, 'occurredAt': '2026-10-02T10:00:00+00:00', 'actorEmail': 'seg@scuola.it', 'action': 'richiesta.inviata', 'outcome': 'denied', 'context': {}});
    expect([v.id, v.negata, v.descrizione], ['12', true, 'Ha inviato una richiesta']);
  });
}
