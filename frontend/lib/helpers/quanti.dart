/// "1 iscritto", "3 iscritti": il numero con la parola al singolare o al plurale.
String quanti(int n, String uno, String tanti) => '$n ${n == 1 ? uno : tanti}';
