import 'dart:async';

import 'package:dio/dio.dart';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:cartellaviva/features/documenti/model/documento.dart';
import 'package:cartellaviva/features/documenti/model/proposte_categoria.dart';
import 'package:cartellaviva/features/documenti/view/proposta_categorie_wizard.dart';
import 'package:cartellaviva/helpers/services/api/api_exception.dart';

final casa = Categoria(id: 'casa', nome: 'Casa');
final bollette = Categoria(id: 'bollette', nome: 'Bollette');
final salute = Categoria(id: 'salute', nome: 'Salute', isSensibile: true);

Documento doc(String id, {Categoria? in_, bool cifrato = false}) => Documento(id: id, titolo: 'Documento $id', categoria: in_ ?? casa, cifrato: cifrato);

ProposteCategorie stato(List<Documento> docs, {bool puoCreare = true}) => ProposteCategorie(docs, [casa, bollette, salute], puoCreareCategorie: puoCreare);

void main() {
  test('le proposte diventano un piano: categorie nuove raggruppate, spostamenti per destinazione', () {
    final s = stato([doc('a'), doc('b'), doc('c'), doc('d'), doc('e')]);
    s.registra(s.righe[0], const PropostaCategoria(tipo: 'esistente', categoriaId: 'bollette', nome: 'Bollette'));
    s.registra(s.righe[1], const PropostaCategoria(tipo: 'nuova', nome: 'Veterinario', sensibile: false, icona: 'paw-print'));
    s.registra(s.righe[2], const PropostaCategoria(tipo: 'nuova', nome: 'veterinario ', sensibile: null));
    s.registra(s.righe[3], const PropostaCategoria(tipo: 'esistente', categoriaId: 'casa', nome: 'Casa'));
    s.errore(s.righe[4], 'Il modello AI non risponde');

    expect(s.nomiNuove, ['Veterinario']);
    expect(s.nuove.values.single.icona, 'paw-print');
    expect(s.documentiPer(s.nuove.values.single), 2, reason: 'lo stesso nome, scritto diverso, è una sola categoria');
    expect(s.righe[3].destinazione, isNull, reason: 'già nella categoria giusta: niente da fare');
    expect(s.spostamenti, {'c:bollette': ['a'], 'n:veterinario': ['b', 'c']});
    expect(s.riepilogo, 'Crea 1 categoria, sposta 3 documenti.');

    // Senza la spunta "Crea" i documenti di quella categoria restano dove sono.
    s.cambiaCrea(s.nuove.values.single, false);
    expect(s.daCreare, isEmpty);
    expect(s.spostamenti, {'c:bollette': ['a']});
    expect(s.riepilogo, 'Sposta 1 documento.');
  });

  test('il riepilogo avvisa dei documenti che verranno cifrati', () {
    final s = stato([doc('a'), doc('b', cifrato: true), doc('c')]);
    for (final r in s.righe) {
      s.registra(r, const PropostaCategoria(tipo: 'esistente', categoriaId: 'salute', nome: 'Salute'));
    }
    s.registra(s.righe[2], const PropostaCategoria(tipo: 'nuova', nome: 'Analisi', sensibile: true));
    expect(s.daCifrare, 2, reason: 'il documento già cifrato non conta');
    expect(s.riepilogo, 'Crea 1 categoria, sposta 3 documenti (2 verranno cifrati perché finiscono in una categoria sensibile).');
  });

  test('nomi delle categorie nuove: niente doppioni con le esistenti', () {
    final s = stato([doc('a')]);
    s.registra(s.righe[0], const PropostaCategoria(tipo: 'nuova', nome: 'Auto'));
    expect(s.problemaNomi, isNull);
    s.nuove.values.single.nome = 'bollette';
    expect(s.problemaNomi, contains('già usato'));

    // Una proposta "nuova" con il nome di una esistente va su quella.
    final t = stato([doc('b')]);
    t.registra(t.righe[0], const PropostaCategoria(tipo: 'nuova', nome: 'SALUTE'));
    expect(t.nuove, isEmpty);
    expect(t.righe[0].destinazione, 'c:salute');
  });

  test('chi non può creare categorie non riceve categorie nuove', () {
    final s = stato([doc('a')], puoCreare: false);
    s.registra(s.righe[0], const PropostaCategoria(tipo: 'nuova', nome: 'Viaggi'));
    expect(s.nuove, isEmpty);
    expect(s.righe[0].destinazione, isNull);
  });

  testWidgets('wizard: analisi in fila, revisione, poi crea e sposta', (tester) async {
    tester.view.physicalSize = const Size(1200, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final chiamate = <String>[];
    final create = <String>[];
    final spostati = <String>[];
    final risposte = {
      'a': const PropostaCategoria(tipo: 'nuova', nome: 'Veterinario', sensibile: false, icona: 'paw-print'),
      'b': const PropostaCategoria(tipo: 'nuova', nome: 'Veterinario'),
      'c': const PropostaCategoria(tipo: 'esistente', categoriaId: 'bollette', nome: 'Bollette', fonte: 'nome'),
    };
    final s = stato([doc('a'), doc('b'), doc('c')]);

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PropostaCategorieWizard(
          stato: s,
          proponi: (id, nuove) async {
            chiamate.add('$id:${nuove.join(',')}');
            return risposte[id]!;
          },
          creaCategoria: (n) async {
            create.add('${n.nome}|${n.icona}|${n.sensibile}');
            return Categoria(id: 'vet', nome: n.nome);
          },
          sposta: (ids, categoriaId) async {
            spostati.add('${ids.join(',')}→$categoriaId');
            return (fatti: ids.length, saltati: <({String? titolo, String motivo})>[]);
          },
        ),
      ),
    ));
    await tester.pumpAndSettle();

    // Un documento per volta, ognuno con le categorie nuove proposte prima.
    expect(chiamate, ['a:', 'b:Veterinario', 'c:Veterinario']);
    expect(find.text('Controlla le proposte'), findsOneWidget);
    expect(find.text('Nuove categorie suggerite'), findsOneWidget);
    expect(find.textContaining('dedotta solo dal nome'), findsOneWidget);
    expect(find.text('Crea 1 categoria, sposta 3 documenti.'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'Nome'), 'Animali');
    await tester.pump();
    await tester.tap(find.text('Applica'));
    await tester.pumpAndSettle();

    expect(create, ['Animali|paw-print|false']);
    expect(spostati, ['a,b→vet', 'c→bollette']);
    expect(find.text('Create 1 categoria. Spostati 3 documenti.'), findsOneWidget);
  });

  testWidgets('wizard: AI non configurata, si ferma subito con il messaggio', (tester) async {
    var chiamate = 0;
    final s = stato([doc('a'), doc('b')]);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PropostaCategorieWizard(
          stato: s,
          proponi: (id, nuove) async {
            chiamate++;
            throw ApiException('L\'AI non è configurata su questo server (OLLAMA_MODEL).', statusCode: 409);
          },
          creaCategoria: (n) => Completer<Categoria>().future,
          sposta: (ids, c) => Completer<({int fatti, List<({String? titolo, String motivo})> saltati})>().future,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(chiamate, 1);
    expect(find.textContaining('non è configurata'), findsWidgets);
    expect(find.text('Controlla le proposte'), findsNothing, reason: 'nessuna proposta: resta sull\'analisi');
  });

  testWidgets('wizard: AI occupata (429 breve), aspetta e riprova lo stesso documento', (tester) async {
    final attese = <Duration>[];
    var tentativi = 0;
    final s = stato([doc('a')]);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PropostaCategorieWizard(
          stato: s,
          proponi: (id, nuove) async {
            if (++tentativi < 3) throw ApiException('L\'AI sta già lavorando per altri: riprova tra qualche secondo.', statusCode: 429, riprovaTra: 5);
            return const PropostaCategoria(tipo: 'esistente', categoriaId: 'bollette', nome: 'Bollette');
          },
          attendi: (d) async => attese.add(d),
          creaCategoria: (n) => Completer<Categoria>().future,
          sposta: (ids, c) => Completer<({int fatti, List<({String? titolo, String motivo})> saltati})>().future,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tentativi, 3);
    expect(attese, [const Duration(seconds: 5), const Duration(seconds: 5)]);
    expect(s.righe.single.errore, isNull, reason: 'l\'attesa non è un errore');
    expect(s.righe.single.destinazione, 'c:bollette');
    expect(find.text('Controlla le proposte'), findsOneWidget);
  });

  testWidgets('wizard: limite orario (429 lungo), si ferma senza riprovare', (tester) async {
    var tentativi = 0;
    final s = stato([doc('a'), doc('b')]);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PropostaCategorieWizard(
          stato: s,
          proponi: (id, nuove) async {
            tentativi++;
            throw ApiException('Troppe proposte nell\'ultima ora: riprova più tardi.', statusCode: 429, riprovaTra: 1800);
          },
          attendi: (d) async => fail('non deve aspettare'),
          creaCategoria: (n) => Completer<Categoria>().future,
          sposta: (ids, c) => Completer<({int fatti, List<({String? titolo, String motivo})> saltati})>().future,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tentativi, 1);
    expect(find.textContaining('ultima ora'), findsWidgets);
  });

  test('ApiException legge Retry-After', () {
    final opzioni = RequestOptions(path: '/x');
    final risposta = Response(
      requestOptions: opzioni,
      statusCode: 429,
      data: {'detail': 'Occupato'},
      headers: Headers.fromMap({'retry-after': ['5']}),
    );
    final e = ApiException.fromDio(DioException(requestOptions: opzioni, response: risposta));
    expect(e.statusCode, 429);
    expect(e.riprovaTra, 5);
    expect(e.message, 'Occupato');
  });
}
