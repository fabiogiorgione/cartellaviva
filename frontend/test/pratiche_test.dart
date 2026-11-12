import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/pratiche/model/pagamento.dart' show formatEuro;
import 'package:cartellaviva/features/pratiche/model/pratica.dart';

void main() {
  final dettaglio = {
    'id': 'p1',
    'modello': '730',
    'anno': 2025,
    'titolo': '730/2026 · spese 2025',
    'stato': 'in_corso',
    'provvisorio': false,
    'persone': [
      {'id': 'm1', 'nome': 'Mario Rossi'},
      {'id': 'm2', 'nome': 'Luca Rossi'},
    ],
    'confermate': 2,
    'proposte': 1,
    'mancanti': 1,
    'totaleConfermato': '134.40',
    'puoGestire': true,
    'modelloInfo': {
      'modello': '730',
      'anno': 2025,
      'titolo': '730/2026 · spese 2025',
      'descrizione': 'Spese',
      'verificato': '2026-11-12',
      'provvisorio': false,
      'fonti': [
        {'titolo': 'Agenzia delle Entrate', 'url': 'https://www.agenziaentrate.gov.it'},
      ],
      'requisiti': [
        {
          'codice': 'spesa_sanitaria',
          'etichetta': 'Spese sanitarie',
          'descrizione': '',
          'per': 'persona',
          'obbligatorio': false,
          'somma': true,
          'tipi': [
            {'codice': 'farmaci', 'etichetta': 'Farmaci', 'tracciabile': 'non_richiesto', 'nota': null},
          ],
          'campi': [
            {'codice': 'importo', 'tipo': 'importo', 'etichetta': 'Importo', 'obbligatorio': true},
          ],
        },
      ],
    },
    'consensoCifrati': null,
    'nonServe': [
      {'requisito': 'cu', 'personaId': 'm2'},
    ],
    'voci': [
      {
        'id': 'v1',
        'requisito': 'spesa_sanitaria',
        'personaId': 'm1',
        'persona': 'Mario Rossi',
        'documento': {'id': 'd1', 'titolo': 'scontrino', 'cifrato': true},
        'valori': {'importo': '14.40', 'data': '2025-03-12', 'tipo': 'farmaci'},
        'stato': 'proposta',
        'origine': 'ai_server',
        'controlli': [
          {'codice': 'tracciabile', 'livello': 'avviso', 'testo': 'Serve un pagamento tracciabile'},
        ],
      },
    ],
    'cosaManca': [
      {'requisito': 'cu', 'etichetta': 'Certificazione Unica', 'personaId': 'm1', 'persona': 'Mario Rossi'},
    ],
    'annoPrima': [],
    'riepilogo': {
      'totale': '134.40',
      'requisiti': [
        {
          'requisito': 'spesa_sanitaria',
          'etichetta': 'Spese sanitarie',
          'totale': '134.40',
          'tipi': [
            {'tipo': 'farmaci', 'etichetta': 'Farmaci', 'totale': '134.40'},
          ],
        },
      ],
      'persone': [
        {'personaId': 'm1', 'persona': 'Mario Rossi', 'totale': '134.40'},
      ],
    },
    'analisi': {'inCoda': 2, 'analizzati': 5, 'cifratiInAttesa': 3},
  };

  test('il dettaglio della pratica si legge tutto', () {
    final p = Pratica.fromJson(dettaglio);
    expect(p.inCorso, isTrue);
    expect(p.info!.requisito('spesa_sanitaria')!.tipo('farmaci')!.etichetta, 'Farmaci');
    expect(p.voci.single.valori['importo'], '14.40');
    expect(p.voci.single.documento!.cifrato, isTrue);
    expect(p.voci.single.data, DateTime(2025, 3, 12));
    expect(p.voci.single.controlli.single.avviso, isTrue);
    expect(p.nonServePer('cu', 'm2'), isTrue);
    expect(p.nonServePer('cu', 'm1'), isFalse);
    expect(p.analisi.cifratiInAttesa, 3);
    expect(p.riepilogo.persone.single.totale, '134.40');
    expect(p.cosaManca.single.persona, 'Mario Rossi');
  });

  test('riassunto della scheda in Pratiche', () {
    final p = Pratica.fromJson(dettaglio);
    expect(riepilogoPratica(p, formatEuro), '${formatEuro('134.40')} confermati, 1 da vedere, 1 mancante');
    final vuota = Pratica.fromJson({'id': 'x', 'modello': '730', 'anno': 2026, 'titolo': 't', 'stato': 'in_corso'});
    expect(riepilogoPratica(vuota, formatEuro), 'nessuna voce confermata');
  });

  test('ISEE: codici scoperti, campi della precompilata, rinnovo', () {
    final p = Pratica.fromJson({
      'id': 'i1',
      'modello': 'isee',
      'anno': 2026,
      'annoRiferimento': 2024,
      'titolo': 'ISEE 2026 · redditi e patrimonio 2024',
      'stato': 'inviata',
      'modelloInfo': {
        'modello': 'isee',
        'anno': 2026,
        'annoRiferimento': 2024,
        'titolo': 'ISEE 2026',
        'requisiti': [
          {
            'codice': 'rapporti',
            'etichetta': 'Conti',
            'per': 'persona',
            'scopri': 'iban',
            'tipi': [],
            'campi': [
              {'codice': 'importo', 'tipo': 'importo', 'etichetta': 'Saldo al 31/12', 'obbligatorio': true},
              {'codice': 'saldo_precompilata', 'tipo': 'importo', 'etichetta': 'Saldo nella DSU precompilata', 'obbligatorio': false, 'confronta': 'importo', 'soloUtente': true},
            ],
          },
        ],
      },
      'cosaManca': [
        {'requisito': 'rapporti', 'etichetta': 'Conti', 'personaId': null, 'persona': null, 'dettaglio': 'IT74L1234512345123456789018', 'valori': {'iban': 'IT74L1234512345123456789018'}},
      ],
      'nonServe': [
        {'requisito': 'rapporti', 'personaId': null, 'dettaglio': 'IT60X0542811101000000123456'},
      ],
      'scadenzaRinnovo': {'id': 's1', 'data': '2026-12-31'},
    });
    expect(p.isee, isTrue);
    expect(p.annoRiferimento, 2024);
    expect(p.info!.requisito('rapporti')!.scopri, 'iban');
    final c = p.info!.requisito('rapporti')!.campi.last;
    expect([c.confronta, c.soloUtente], ['importo', true]);
    expect(p.cosaManca.single.valori['iban'], 'IT74L1234512345123456789018');
    expect(p.nonServe.single.dettaglio, 'IT60X0542811101000000123456');
    expect(p.nonServePer('rapporti', null), isFalse);
    expect(p.scadenzaRinnovo!.data, DateTime(2026, 12, 31));
  });
}
